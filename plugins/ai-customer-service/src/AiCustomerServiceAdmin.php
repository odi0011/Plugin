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

        // 单次可以多选，$_FILES['file'] 会变成列状数组。
        $batch = is_array($files['name'] ?? null) ? self::explodeFiles($files) : [$files];
        $added = [];
        $failed = [];
        foreach (array_slice($batch, 0, 10) as $one) {
            $result = AiCustomerServiceKnowledge::storeUpload($one);
            if (empty($result['ok'])) {
                $failed[] = ($one['name'] ?? '文件') . '：' . (string)$result['message'];
                continue;
            }
            $knowledge['files'][] = $result['file'];
            $added[] = $result['file'];
            if (count($knowledge['files']) >= 60) break;
        }
        if ($added === []) {
            self::json(false, ['message' => implode('；', $failed) ?: '全部文件都没能加入'], 422);
        }
        self::writeKnowledge($knowledge);
        self::json(true, [
            'message' => '已加入 ' . count($added) . ' 个文件' . ($failed !== [] ? '，' . count($failed) . ' 个失败' : ''),
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
        AiCustomerServiceKnowledge::deleteFile($id);
        self::writeKnowledge($knowledge);
        self::json(true, ['message' => '已删除', 'files' => $kept]);
    }

    /** @return array{sources:list<array<string,mixed>>,auto:array<string,mixed>,files:list<array<string,mixed>>} */
    private static function readKnowledge(): array
    {
        $config = AiCustomerService::config();
        return $config['knowledge'];
    }

    private static function writeKnowledge(array $knowledge): void
    {
        self::writeJsonSetting(self::KNOWLEDGE_SETTING, $knowledge, 16000);
        AiCustomerService::flushCache();
    }

    /** $_FILES 的列状结构拆成一条条常规结构。 */
    private static function explodeFiles(array $files): array
    {
        $out = [];
        $count = count((array)$files['name']);
        for ($index = 0; $index < min(10, $count); $index++) {
            $out[] = [
                'name' => (string)($files['name'][$index] ?? ''),
                'tmp_name' => (string)($files['tmp_name'][$index] ?? ''),
                'size' => (int)($files['size'][$index] ?? 0),
                'error' => (int)($files['error'][$index] ?? UPLOAD_ERR_NO_FILE),
            ];
        }
        return $out;
    }

    // ACS_MARKER_ADMIN2

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
        $batch = is_array($files['name'] ?? null) ? self::explodeFiles($files) : [$files];
        $added = [];
        $failed = [];
        foreach (array_slice($batch, 0, 10) as $one) {
            $result = self::storeSticker($one);
            if (empty($result['ok'])) { $failed[] = ($one['name'] ?? '文件') . '：' . (string)$result['message']; continue; }
            $added[] = ['url' => (string)$result['url'], 'label' => (string)$result['label']];
        }
        if ($added === []) self::json(false, ['message' => implode('；', $failed) ?: '没有文件被接受'], 422);

        $index = null;
        foreach ($stickers['packs'] as $position => $pack) {
            if ((string)$pack['name'] === $packName) { $index = $position; break; }
        }
        if ($index === null) {
            if (count($stickers['packs']) >= 8) self::json(false, ['message' => '最多 8 个表情包分组'], 422);
            $stickers['packs'][] = ['name' => $packName, 'items' => $added];
        } else {
            $stickers['packs'][$index]['items'] = array_slice(array_merge($stickers['packs'][$index]['items'], $added), 0, 48);
        }
        self::writeJsonSetting(self::STICKERS_SETTING, $stickers, 12000);
        AiCustomerService::flushCache();
        self::json(true, [
            'message' => '已加入 ' . count($added) . ' 个表情' . ($failed !== [] ? '，' . count($failed) . ' 个失败' : ''),
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
        self::writeJsonSetting(self::STICKERS_SETTING, $stickers, 12000);
        AiCustomerService::flushCache();
        // 刻意不删磁盘文件：表情走的是核心媒体库目录，可能被文章等其他地方引用。
        self::json(true, ['message' => '已从表情包移除', 'stickers' => AiCustomerService::config()['stickers']]);
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

    private static function writeJsonSetting(string $key, array $value, int $limit): void
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) self::json(false, ['message' => '结构无法编码'], 500);
        if (mb_strlen($encoded) > $limit) self::json(false, ['message' => '内容超出该字段上限（' . $limit . ' 字），请先删掉一些条目'], 422);
        try {
            \App\Core\Setting::set($key, $encoded);
        } catch (\Throwable $_) {
            self::json(false, ['message' => '写入设置失败'], 500);
        }
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
