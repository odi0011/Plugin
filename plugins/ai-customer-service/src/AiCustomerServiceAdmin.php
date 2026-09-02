<?php
declare(strict_types=1);

/**
 * 后台的异步动作：知识库文件上传/删除、站内内容检索、表情包上传。
 *
 * 这些都走插件自己的后台 POST 路由（核心 Router 统一校验 _csrf），并且每个入口都
 * 重新要一次 ai_customer_service.manage —— 不依赖"能打开页面就等于能上传"。
 *
 * 上传会**立即**把条目写进对应的 JSON 设置，不等用户点保存：文件已经落盘了，
 * 让它只存在于未提交的表单里，一次刷新就会变成孤儿文件。
 */
final class AiCustomerServiceAdmin
{
    private const KNOWLEDGE_SETTING = 'plugin.ai-customer-service.knowledge_json';
    private const STICKERS_SETTING = 'plugin.ai-customer-service.stickers_json';
    /* 两个字段的字数上限，与 plugin.json 里 knowledge_json / stickers_json 的 max_length 一致。
     * 上传的顺序是"先落盘、再写目录"，所以余量必须在动手之前算出来：撞上限时文件已经在
     * 磁盘上了，只能再删一遍，而站长看到的是一句"内容超出上限"——没人能从这句话推出
     * "我该删几个"。单条最坏字数按结构估：资料条目约 320 字（180 字文件名 +
     * id/ext/bytes/chars/added_at），表情条目约 160 字（媒体库 URL + 标签）。 */
    private const KNOWLEDGE_LIMIT = 16000;
    private const STICKERS_LIMIT = 12000;
    private const KNOWLEDGE_ENTRY = 320;
    private const STICKER_ENTRY = 160;
    private const MAX_STICKER_BYTES = 2097152;
    private const STICKER_EXT = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'];

    public static function handle(string $action): void
    {
        \App\Core\Auth::requirePermission('ai_customer_service.manage');
        AiCustomerService::flushCache();
        match ($action) {
            'knowledge-upload' => self::knowledgeUpload(),
            'knowledge-delete' => self::knowledgeDelete(),
            'content-search' => self::contentSearch(),
            'sticker-upload' => self::stickerUpload(),
            'sticker-delete' => self::stickerDelete(),
            'preset' => self::presetPreview(),
            default => self::json(false, ['message' => '不支持的动作'], 404),
        };
    }

    // ---------------------------------------------------------------- 知识库文件

    private static function knowledgeUpload(): void
    {
        $files = $_FILES['file'] ?? null;
        if (!is_array($files)) self::json(false, ['message' => '没有收到文件'], 422);

        $knowledge = self::readKnowledge();
        if (count($knowledge['files']) >= 60) {
            self::json(false, ['message' => '资料柜最多 60 个文件，请先删掉一些'], 422);
        }
        // 还能塞几条。算不出余量就别开始存文件——见 KNOWLEDGE_ENTRY 那段注释。
        $used = self::jsonLength($knowledge);
        $room = intdiv(max(0, self::KNOWLEDGE_LIMIT - $used), self::KNOWLEDGE_ENTRY);
        if ($room < 1) {
            self::json(false, ['message' => '资料清单已经占到 ' . $used . ' 字，离上限 ' . self::KNOWLEDGE_LIMIT
                . ' 字不够再放一个文件了（一条约 ' . self::KNOWLEDGE_ENTRY . ' 字）；请先删掉一些文件再上传'], 422);
        }

        // 单次可以多选，$_FILES['file'] 会变成列状数组。
        $batch = is_array($files['name'] ?? null) ? self::explodeFiles($files) : [$files];
        // 三个上限取最小：单次 10 个、字段余量、资料柜 60 个
        $take = min(10, $room, 60 - count($knowledge['files']));
        // 静默少收几个文件，站长只会以为上传坏了，所以下面要把这个数说出来
        $skipped = max(0, count($batch) - $take);
        $added = [];
        $failed = [];
        foreach (array_slice($batch, 0, $take) as $one) {
            $result = AiCustomerServiceKnowledge::storeUpload($one);
            if (empty($result['ok'])) {
                $failed[] = ($one['name'] ?? '文件') . '：' . (string)$result['message'];
                continue;
            }
            $knowledge['files'][] = $result['file'];
            $added[] = $result['file'];
        }
        if ($added === []) {
            self::json(false, ['message' => implode('；', $failed) ?: '全部文件都没能加入'], 422);
        }
        $write = self::writeKnowledge($knowledge);
        if (!$write['ok']) {
            /* 目录没写进去，磁盘上刚落地的那几个 .txt 就再也没人引用得到：后台列表里看不见、
             * 也点不到删除，只有卸载时的 purge() 会顺手扫掉。所以这一批要原路撤回。 */
            foreach ($added as $file) AiCustomerServiceKnowledge::deleteFile((string)$file['id']);
            self::json(false, ['message' => $write['message']], $write['status']);
        }
        self::json(true, [
            'message' => '已加入 ' . count($added) . ' 个文件'
                . ($failed !== [] ? '，' . count($failed) . ' 个失败' : '')
                . ($skipped > 0 ? '，另有 ' . $skipped . ' 个没处理（单次最多 10 个，资料柜最多 60 个，'
                    . '且资料清单余量只够 ' . $room . ' 条）' : ''),
            'files' => $knowledge['files'],
            'failed' => $failed,
        ]);
    }

    private static function knowledgeDelete(): void
    {
        $id = AiCustomerService::slugValue((string)($_POST['id'] ?? ''), 32);
        if ($id === '') self::json(false, ['message' => '缺少文件标识'], 422);

        $knowledge = self::readKnowledge();
        $kept = [];
        $hit = false;
        foreach ($knowledge['files'] as $file) {
            if ((string)$file['id'] === $id) { $hit = true; continue; }
            $kept[] = $file;
        }
        if (!$hit) self::json(false, ['message' => '这个文件已经不在资料柜里了'], 404);
        $knowledge['files'] = $kept;
        /* 先写目录再删文件。反过来的话，写设置失败就变成"文件已经没了、清单里还挂着"，
         * 之后每次检索都会去读一个不存在的路径。少了这一条比多了一条难查得多。 */
        $write = self::writeKnowledge($knowledge);
        if (!$write['ok']) self::json(false, ['message' => $write['message']], $write['status']);
        AiCustomerServiceKnowledge::deleteFile($id);
        self::json(true, ['message' => '已删除', 'files' => $kept]);
    }

    /** @return array{sources:list<array<string,mixed>>,auto:array<string,mixed>,files:list<array<string,mixed>>} */
    private static function readKnowledge(): array
    {
        $config = AiCustomerService::config();
        return $config['knowledge'];
    }

    /** @return array{ok:bool,message:string,status:int} */
    private static function writeKnowledge(array $knowledge): array
    {
        $result = self::writeJsonSetting(self::KNOWLEDGE_SETTING, $knowledge, self::KNOWLEDGE_LIMIT);
        // 成功失败都要清：这一进程里 config() 的那份快照已经不能代表数据库了
        AiCustomerService::flushCache();
        return $result;
    }

    /** $_FILES 的列状结构拆成一条条常规结构。单次上限由调用方切（见 $take）：
     *  在这里先截到 10 的话，$skipped 永远算成 0，"少收了几个"就说不出口。 */
    private static function explodeFiles(array $files): array
    {
        $out = [];
        $count = count((array)$files['name']);
        for ($index = 0; $index < $count; $index++) {
            $out[] = [
                'name' => (string)($files['name'][$index] ?? ''),
                'tmp_name' => (string)($files['tmp_name'][$index] ?? ''),
                'size' => (int)($files['size'][$index] ?? 0),
                'error' => (int)($files['error'][$index] ?? UPLOAD_ERR_NO_FILE),
            ];
        }
        return $out;
    }

    // ---------------------------------------------------------------- 站内内容检索

    private static function contentSearch(): void
    {
        $kind = AiCustomerService::choice($_POST['kind'] ?? '', array_keys(AiCustomerService::SOURCE_KINDS), 'product');
        $type = AiCustomerService::slugValue((string)($_POST['type'] ?? ''), 80);
        $keyword = AiCustomerService::text($_POST['keyword'] ?? '', 80, '');
        $items = AiCustomerServiceKnowledge::search($kind, $type, $keyword, 24);
        self::json(true, ['items' => $items, 'kind' => $kind, 'type' => $type]);
    }

    // ---------------------------------------------------------------- 表情包

    private static function stickerUpload(): void
    {
        $files = $_FILES['file'] ?? null;
        if (!is_array($files)) self::json(false, ['message' => '没有收到文件'], 422);
        $packName = AiCustomerService::text($_POST['pack'] ?? '', 40, '表情包');

        $stickers = AiCustomerService::config()['stickers'];
        $used = self::jsonLength($stickers);
        $room = intdiv(max(0, self::STICKERS_LIMIT - $used), self::STICKER_ENTRY);
        if ($room < 1) {
            self::json(false, ['message' => '表情配置已经占到 ' . $used . ' 字，离上限 ' . self::STICKERS_LIMIT
                . ' 字不够再放一张了（一张约 ' . self::STICKER_ENTRY . ' 字）；请先删掉一些表情再上传'], 422);
        }
        /* 分组数与分组容量都要在存文件之前判。等图片进了媒体库再回 422，站长得到的是
         * 一句"最多 8 个分组"，而媒体库里已经多了一批他没打算留下的图。 */
        $index = null;
        foreach ($stickers['packs'] as $position => $pack) {
            if ((string)$pack['name'] === $packName) { $index = $position; break; }
        }
        if ($index === null && count($stickers['packs']) >= 8) {
            self::json(false, ['message' => '最多 8 个表情包分组，请先删掉一个分组，或把表情放进已有分组'], 422);
        }
        $inPack = $index === null ? 0 : count($stickers['packs'][$index]['items']);
        if ($inPack >= 48) {
            self::json(false, ['message' => '「' . $packName . '」已经有 48 张，是单个分组的上限；请换一个分组'], 422);
        }
        $batch = is_array($files['name'] ?? null) ? self::explodeFiles($files) : [$files];
        // 三个上限取最小：单次 10 张、字段余量、这个分组还能放几张
        $take = min(10, $room, 48 - $inPack);
        $skipped = max(0, count($batch) - $take);
        $added = [];
        $failed = [];
        foreach (array_slice($batch, 0, $take) as $one) {
            $result = self::storeSticker($one);
            if (empty($result['ok'])) { $failed[] = ($one['name'] ?? '文件') . '：' . (string)$result['message']; continue; }
            $added[] = ['url' => (string)$result['url'], 'label' => (string)$result['label']];
        }
        if ($added === []) self::json(false, ['message' => implode('；', $failed) ?: '没有文件被接受'], 422);

        if ($index === null) {
            $stickers['packs'][] = ['name' => $packName, 'items' => $added];
        } else {
            $stickers['packs'][$index]['items'] = array_merge($stickers['packs'][$index]['items'], $added);
        }
        self::writeStickers($stickers, '图片已经存进媒体库，可以在「媒体」里删除');
        self::json(true, [
            'message' => '已加入 ' . count($added) . ' 个表情'
                . ($failed !== [] ? '，' . count($failed) . ' 个失败' : '')
                . ($skipped > 0 ? '，另有 ' . $skipped . ' 个没处理（单次最多 10 张，单个分组最多 48 张，'
                    . '且表情配置余量只够 ' . $room . ' 张）' : ''),
            'stickers' => AiCustomerService::config()['stickers'],
            'failed' => $failed,
        ]);
    }

    private static function stickerDelete(): void
    {
        $url = AiCustomerService::relativeOrHttpUrl((string)($_POST['url'] ?? ''));
        if ($url === '') self::json(false, ['message' => '缺少表情地址'], 422);
        $stickers = AiCustomerService::config()['stickers'];
        $packs = [];
        foreach ($stickers['packs'] as $pack) {
            $items = array_values(array_filter($pack['items'], static fn (array $item): bool => (string)$item['url'] !== $url));
            if ($items !== []) $packs[] = ['name' => $pack['name'], 'items' => $items];
        }
        $stickers['packs'] = $packs;
        self::writeStickers($stickers, '');
        // 刻意不删磁盘文件：表情走的是核心媒体库目录，可能被文章等其他地方引用。
        self::json(true, ['message' => '已从表情包移除', 'stickers' => AiCustomerService::config()['stickers']]);
    }

    /** 写表情配置；失败就地回话（表情图片留在媒体库里，$note 用来把这件事说清楚）。 */
    private static function writeStickers(array $stickers, string $note): void
    {
        $write = self::writeJsonSetting(self::STICKERS_SETTING, $stickers, self::STICKERS_LIMIT);
        AiCustomerService::flushCache();
        if ($write['ok']) return;
        self::json(false, [
            'message' => $write['message'] . ($note !== '' ? '（' . $note . '）' : ''),
        ], $write['status']);
    }

    /** @return array{ok:bool,message?:string,url?:string,label?:string} */
    private static function storeSticker(array $file): array
    {
        if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => '上传未完成'];
        }
        $bytes = (int)($file['size'] ?? 0);
        if ($bytes <= 0 || $bytes > self::MAX_STICKER_BYTES) {
            return ['ok' => false, 'message' => '单张不能超过 ' . (int)(self::MAX_STICKER_BYTES / 1048576) . ' MB'];
        }
        $name = (string)($file['name'] ?? '');
        $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, self::STICKER_EXT, true)) {
            return ['ok' => false, 'message' => '只支持 ' . implode(' / ', self::STICKER_EXT)];
        }
        try {
            // 走核心 Storage：站点自己的扩展名白名单、SVG 消毒、目录结构都能复用。
            $stored = \App\Core\Storage::put((string)($file['tmp_name'] ?? ''), $name);
            if ($ext === 'svg' && method_exists('\App\Core\Storage', 'sanitizeSvgFile')) {
                \App\Core\Storage::sanitizeSvgFile((string)$stored['abs_path']);
            }
            $url = \App\Core\Storage::url((string)$stored['storage_path']);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        if ($url === '') return ['ok' => false, 'message' => '无法生成访问地址'];
        return ['ok' => true, 'url' => $url, 'label' => AiCustomerService::text(pathinfo($name, PATHINFO_FILENAME), 40, '表情')];
    }

    // ---------------------------------------------------------------- 预设

    /** 返回某个预设要覆盖的字段值，由前端直接写进表单控件（所见即所得，保存才落库）。 */
    private static function presetPreview(): void
    {
        $id = AiCustomerService::slugValue((string)($_POST['preset'] ?? ''), 40);
        $presets = AiCustomerService::themePresets();
        if (!isset($presets[$id])) self::json(false, ['message' => '没有这个预设'], 404);
        self::json(true, ['preset' => $id, 'values' => $presets[$id]['values']]);
    }

    // ---------------------------------------------------------------- 工具

    /** 编码后的字数。上限按字符数算，所以必须和 writeJsonSetting 用同一套编码参数。 */
    private static function jsonLength(array $value): int
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($encoded) ? mb_strlen($encoded) : PHP_INT_MAX;
    }

    /**
     * 写一个 JSON 字段。
     *
     * 刻意**不**在这里 exit：调用方常常已经把文件落到磁盘上了，超限时得先把那一批撤回来
     * 再回话。错误文案也要带上数字——"内容超出上限"说不出"我该删几条"。
     *
     * @return array{ok:bool,message:string,status:int}
     */
    private static function writeJsonSetting(string $key, array $value, int $limit): array
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) return ['ok' => false, 'message' => '结构无法编码', 'status' => 500];
        $length = mb_strlen($encoded);
        if ($length > $limit) {
            return ['ok' => false, 'status' => 422, 'message' => '这一项已经占到 ' . $length . ' 字，超过上限 '
                . $limit . ' 字（多了 ' . ($length - $limit) . ' 字），请先删掉一些条目再试'];
        }
        try {
            \App\Core\Setting::set($key, $encoded);
        } catch (\Throwable $_) {
            return ['ok' => false, 'message' => '写入设置失败', 'status' => 500];
        }
        return ['ok' => true, 'message' => '', 'status' => 200];
    }

    private static function json(bool $ok, array $data, int $status = 200): void
    {
        \App\Core\Response::json(
            $ok ? ['ok' => true, 'data' => $data] : ['ok' => false, 'error' => (string)($data['message'] ?? '请求失败'), 'data' => $data],
            $status
        );
        exit;
    }
}
