<?php
declare(strict_types=1);

/**
 * 知识与资料：站内内容来源、上传的知识库文件、以及按提问相关度挑片段。
 *
 * 与「描述词」（system_prompt）刻意分开：描述词说的是怎么说话，这里管的是知道什么。
 *
 * 文件只保留**抽取后的纯文本**，不留原始二进制：
 * - 运行时不需要再解析 PDF/DOCX，省掉一整类解析器攻击面；
 * - 落在 STORAGE_PATH（webroot 之外），本来就不该被访客下载；
 * - 卸载时删目录即可，不需要建表。
 */
final class AiCustomerServiceKnowledge
{
    private const DIR = 'ai-customer-service/knowledge';
    private const MAX_FILE_BYTES = 4194304;
    private const MAX_TEXT_CHARS = 120000;
    private const MAX_FILES = 60;

    /** 可抽取纯文本的扩展名。二进制格式只收 docx（zip+xml，可靠）与尽力而为的 pdf。 */
    public const ALLOWED_EXT = ['txt', 'md', 'markdown', 'csv', 'tsv', 'json', 'html', 'htm', 'xml', 'docx', 'pdf'];

    // ---------------------------------------------------------------- 存储

    private static function root(): string
    {
        $base = defined('STORAGE_PATH') ? (string)constant('STORAGE_PATH') : sys_get_temp_dir();
        return rtrim(str_replace('\\', '/', $base), '/') . '/' . self::DIR;
    }

    private static function pathFor(string $id): string
    {
        $id = AiCustomerService::slugValue($id, 32);
        return $id === '' ? '' : self::root() . '/' . $id . '.txt';
    }

    private static function ensureRoot(): bool
    {
        $root = self::root();
        if (is_dir($root)) return true;
        return @mkdir($root, 0755, true) || is_dir($root);
    }

    // ---------------------------------------------------------------- 上传

    /**
     * 接收一个上传文件，抽取文本并落盘。返回可直接并入 knowledge_json.files 的元数据。
     *
     * @param array{name?:string,tmp_name?:string,size?:int,error?:int} $file
     * @return array{ok:bool,message:string,file?:array<string,mixed>}
     */
    public static function storeUpload(array $file): array
    {
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => self::uploadErrorText($error)];
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            return ['ok' => false, 'message' => '临时文件不存在，上传未完成'];
        }
        $bytes = (int)($file['size'] ?? filesize($tmp) ?: 0);
        if ($bytes <= 0) return ['ok' => false, 'message' => '文件是空的'];
        if ($bytes > self::MAX_FILE_BYTES) {
            return ['ok' => false, 'message' => '单个文件不能超过 ' . (int)(self::MAX_FILE_BYTES / 1048576) . ' MB'];
        }

        $original = (string)($file['name'] ?? '');
        $ext = strtolower((string)pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXT, true)) {
            return ['ok' => false, 'message' => '不支持 .' . ($ext !== '' ? $ext : '(无扩展名)')
                . '，可用：' . implode(' / ', self::ALLOWED_EXT)];
        }

        $raw = (string)@file_get_contents($tmp, false, null, 0, self::MAX_FILE_BYTES);
        if ($raw === '') return ['ok' => false, 'message' => '读不到文件内容'];

        $text = self::extractText($raw, $ext);
        if (mb_strlen($text) < 8) {
            return ['ok' => false, 'message' => $ext === 'pdf'
                ? '这个 PDF 抽不出文字（多半是扫描件或用了内嵌子集字体）。请改上传 .txt / .md / .docx，或把正文粘到“手工补充资料”。'
                : '抽取到的正文太短，确认文件里有可选中的文字。'];
        }
        $text = mb_substr($text, 0, self::MAX_TEXT_CHARS);

        if (!self::ensureRoot()) return ['ok' => false, 'message' => '知识库目录不可写：' . self::root()];
        $id = bin2hex(random_bytes(8));
        $path = self::pathFor($id);
        if ($path === '' || @file_put_contents($path, $text, LOCK_EX) === false) {
            return ['ok' => false, 'message' => '写入失败，请检查 storage 目录权限'];
        }

        return ['ok' => true, 'message' => '已加入资料柜', 'file' => [
            'id' => $id,
            'name' => AiCustomerService::text(self::safeName($original), 180, '未命名'),
            'ext' => $ext,
            'bytes' => $bytes,
            'chars' => mb_strlen($text),
            'added_at' => gmdate('c'),
        ]];
    }

    public static function deleteFile(string $id): bool
    {
        $path = self::pathFor($id);
        if ($path === '' || !is_file($path)) return false;
        return @unlink($path);
    }

    /** 卸载用：删掉整棵知识库目录。 */
    public static function purge(): void
    {
        $root = self::root();
        if (!is_dir($root)) return;
        foreach (glob($root . '/*.txt') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($root);
    }

    public static function readFileText(string $id): string
    {
        $path = self::pathFor($id);
        if ($path === '' || !is_file($path)) return '';
        return (string)@file_get_contents($path, false, null, 0, self::MAX_TEXT_CHARS * 4);
    }

    /** @return array{count:int,chars:int,missing:list<string>,limit:int} */
    public static function fileSummary(array $config): array
    {
        $chars = 0;
        $missing = [];
        foreach ($config['knowledge']['files'] as $file) {
            $chars += (int)$file['chars'];
            if (self::pathFor((string)$file['id']) === '' || !is_file(self::pathFor((string)$file['id']))) {
                $missing[] = (string)$file['name'];
            }
        }
        return ['count' => count($config['knowledge']['files']), 'chars' => $chars, 'missing' => $missing, 'limit' => self::MAX_FILES];
    }

    private static function safeName(string $name): string
    {
        $name = str_replace(["\\", '/'], '', $name);
        return trim((string)preg_replace('/[\x00-\x1F\x7F]/', '', $name));
    }

    private static function uploadErrorText(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => '文件超过了服务器允许的上传大小',
            UPLOAD_ERR_PARTIAL => '文件只传了一部分，请重试',
            UPLOAD_ERR_NO_FILE => '没有选择文件',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => '服务器临时目录不可写',
            UPLOAD_ERR_EXTENSION => '有 PHP 扩展拦下了这次上传',
            default => '上传失败（错误码 ' . $error . '）',
        };
    }

    // ---------------------------------------------------------------- 文本抽取

    private static function extractText(string $raw, string $ext): string
    {
        $text = match ($ext) {
            'docx' => self::extractDocx($raw),
            'pdf' => self::extractPdf($raw),
            'html', 'htm', 'xml' => self::stripMarkup($raw),
            'json' => self::flattenJson($raw),
            default => $raw,
        };
        return self::tidy($text);
    }

    /** docx 就是个 zip，正文在 word/document.xml 里。 */
    private static function extractDocx(string $raw): string
    {
        if (!class_exists('\ZipArchive')) return '';
        $tmp = tempnam(sys_get_temp_dir(), 'acsdocx');
        if ($tmp === false) return '';
        try {
            if (@file_put_contents($tmp, $raw) === false) return '';
            $zip = new \ZipArchive();
            if ($zip->open($tmp) !== true) return '';
            $xml = '';
            foreach (['word/document.xml', 'word/footnotes.xml'] as $entry) {
                $part = $zip->getFromName($entry);
                if (is_string($part)) $xml .= $part;
            }
            $zip->close();
            if ($xml === '') return '';
            // 段落与换行标记先转成真换行，再统一剥标签，否则整篇会挤成一行。
            $xml = preg_replace('#</w:p>#', "\n", $xml) ?? $xml;
            $xml = preg_replace('#<w:br\s*/?>#', "\n", $xml) ?? $xml;
            $xml = preg_replace('#<w:tab\s*/?>#', "\t", $xml) ?? $xml;
            return self::stripMarkup($xml);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * PDF 尽力而为：解开 FlateDecode 流，取文本操作符里的字面量。
     * 扫描件和子集字体抽不出东西——上层会检查长度并明确告诉管理员改用别的格式。
     */
    private static function extractPdf(string $raw): string
    {
        $chunks = [];
        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $matches)) {
            foreach ($matches[1] as $stream) {
                if (count($chunks) > 400) break;
                $decoded = @gzuncompress($stream);
                if (!is_string($decoded) || $decoded === '') $decoded = @gzinflate($stream);
                if (!is_string($decoded) || $decoded === '') $decoded = $stream;
                if (!str_contains($decoded, 'Tj') && !str_contains($decoded, 'TJ')) continue;
                $chunks[] = $decoded;
            }
        }
        $out = [];
        foreach ($chunks as $chunk) {
            if (preg_match_all('/\((?:\\\\.|[^\\\\()])*\)/s', $chunk, $literals)) {
                foreach ($literals[0] as $literal) {
                    $piece = substr($literal, 1, -1);
                    $piece = strtr($piece, ['\\(' => '(', '\\)' => ')', '\\\\' => '\\', '\\n' => "\n", '\\r' => "\n", '\\t' => "\t"]);
                    if (trim($piece) !== '') $out[] = $piece;
                }
            }
            if (str_contains($chunk, 'ET')) $out[] = "\n";
        }
        $text = implode(' ', $out);
        // 抽出来的常见是 Latin-1 字节序列，转成 UTF-8 否则后面 mb_* 会判成乱码。
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = (string)mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }
        return $text;
    }

    private static function stripMarkup(string $raw): string
    {
        $raw = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $raw) ?? $raw;
        $raw = preg_replace('#<(br|/p|/div|/li|/tr|/h[1-6])\s*/?>#i', "\n", $raw) ?? $raw;
        $raw = strip_tags($raw);
        return html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function flattenJson(string $raw): string
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) return $raw;
        $lines = [];
        $walk = static function ($node, string $prefix) use (&$walk, &$lines): void {
            if (count($lines) > 4000) return;
            if (is_array($node)) {
                foreach ($node as $key => $value) {
                    $walk($value, $prefix === '' ? (string)$key : $prefix . '.' . (string)$key);
                }
                return;
            }
            if (is_scalar($node) && trim((string)$node) !== '') {
                $lines[] = ($prefix !== '' ? $prefix . '：' : '') . (string)$node;
            }
        };
        $walk($decoded, '');
        return implode("\n", $lines);
    }

    private static function tidy(string $text): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = (string)mb_convert_encoding($text, 'UTF-8', 'UTF-8, GB18030, BIG-5, Windows-1252');
        }
        $text = str_replace(["\r\n", "\r", "\xEF\xBB\xBF"], ["\n", "\n", ''], $text);
        $text = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        $text = (string)preg_replace('/[ \t]{2,}/u', ' ', $text);
        $text = (string)preg_replace('/\n{3,}/u', "\n\n", $text);
        return trim($text);
    }

    // ---------------------------------------------------------------- 站内内容来源

    /** @return list<array{kind:string,type:string,label:string}> */
    public static function contentTypeOptions(): array
    {
        $out = [
            ['kind' => 'product', 'type' => '', 'label' => '产品'],
            ['kind' => 'article', 'type' => '', 'label' => '文章'],
            ['kind' => 'page', 'type' => '', 'label' => '页面'],
        ];
        try {
            foreach (\App\Models\ContentType::query()->orderBy('id', 'asc')->get() as $type) {
                $slug = AiCustomerService::slugValue((string)($type['slug'] ?? ''), 80);
                if ($slug === '' || in_array($slug, \App\Models\ContentType::BUILTIN_TYPES, true)) continue;
                $out[] = ['kind' => 'entry', 'type' => $slug, 'label' => (string)($type['name'] ?? $slug)];
            }
        } catch (\Throwable $_) {
            // 自定义内容类型不可用（表不存在等）时只提供内置三类。
        }
        return $out;
    }

    /**
     * 后台资料柜的内容检索。只读、只取公开可见的记录。
     *
     * @return list<array<string,mixed>>
     */
    public static function search(string $kind, string $type, string $keyword, int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));
        $keyword = trim(mb_substr($keyword, 0, 80));
        try {
            $query = self::baseQuery($kind, $type);
            if ($query === null) return [];
            if ($keyword !== '') {
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $keyword) . '%';
                $query->whereRaw('(title LIKE ? OR slug LIKE ? OR summary LIKE ?)', [$like, $like, $like]);
            }
            $rows = $query->orderBy('id', 'desc')->limit($limit)->get();
        } catch (\Throwable $_) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::describeRecord($kind, $type, is_array($row) ? $row : []);
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public static function loadRecords(string $kind, string $type, array $ids, int $limit = 12): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        if ($ids === []) return [];
        $ids = array_slice($ids, 0, max(1, min(60, $limit)));
        try {
            $query = self::baseQuery($kind, $type);
            if ($query === null) return [];
            $rows = $query->whereIn('id', $ids)->limit(count($ids))->get();
        } catch (\Throwable $_) {
            return [];
        }
        $byId = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $byId[(int)($row['id'] ?? 0)] = self::describeRecord($kind, $type, $row);
        }
        // 保持调用方给的顺序（推荐结果的顺序是有意义的）。
        $out = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) $out[] = $byId[$id];
        }
        return $out;
    }

    /**
     * 按过滤条件挑内容，给「推荐产品/文章」这类工具用。
     *
     * @param array<string,bool> $filters 只接受 AiCustomerService::TOOL_FILTERS 的白名单键
     * @return list<array<string,mixed>>
     */
    public static function pick(string $kind, string $type, string $keyword, array $filters, int $limit): array
    {
        $limit = max(1, min(8, $limit));
        try {
            $query = self::baseQuery($kind, $type);
            if ($query === null) return [];
            $keyword = trim(mb_substr($keyword, 0, 60));
            if ($keyword !== '') {
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $keyword) . '%';
                $query->whereRaw('(title LIKE ? OR summary LIKE ?)', [$like, $like]);
            }
            if ($kind === 'product') {
                if (!empty($filters['is_featured'])) $query->where('is_featured', 1);
                if (!empty($filters['is_hot'])) $query->where('is_hot', 1);
                if (!empty($filters['is_new'])) $query->where('is_new', 1);
                if (!empty($filters['in_stock'])) $query->where('stock_status', 'in_stock');
                if (!empty($filters['has_price'])) $query->whereRaw('price IS NOT NULL AND price > 0');
            }
            $query = !empty($filters['newest'])
                ? $query->orderBy('id', 'desc')
                : ($kind === 'product' ? $query->orderBy('sort_order', 'asc')->orderBy('id', 'desc') : $query->orderBy('id', 'desc'));
            $rows = $query->limit($limit)->get();
        } catch (\Throwable $_) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::describeRecord($kind, $type, is_array($row) ? $row : []);
        }
        return $out;
    }

    private static function baseQuery(string $kind, string $type)
    {
        $query = match ($kind) {
            'product' => \App\Models\Product::query(),
            'article' => \App\Models\Article::query(),
            'page' => \App\Models\Page::query(),
            'entry' => $type === '' ? null : \App\Models\ContentEntry::query()->where('content_type', $type),
            default => null,
        };
        if ($query === null) return null;
        // 客服只能引用前台本来就看得见的内容：草稿、定时未到、归档一律不进上下文。
        return \App\Core\ContentWorkflow::applyPublicScope($query);
    }

    /** @return array<string,mixed> */
    private static function describeRecord(string $kind, string $type, array $row): array
    {
        $title = AiCustomerService::text($row['title'] ?? '', 200, '未命名');
        $summary = AiCustomerService::text($row['summary'] ?? '', 500, '');
        $out = [
            'kind' => $kind,
            'type' => $type,
            'id' => (int)($row['id'] ?? 0),
            'title' => $title,
            'slug' => (string)($row['slug'] ?? ''),
            'summary' => $summary,
            'cover' => AiCustomerService::relativeOrHttpUrl((string)($row['cover_image'] ?? '')),
            'url' => self::permalink($kind, $row),
        ];
        if ($kind === 'product') {
            $price = $row['price'] ?? null;
            $out['price'] = $price === null || $price === '' ? '' : self::formatPrice((float)$price, (string)($row['currency_code'] ?? ''), (string)($row['price_text'] ?? ''));
            $out['sku'] = AiCustomerService::text($row['sku'] ?? '', 100, '');
            $out['stock'] = (string)($row['stock_status'] ?? '');
        }
        return $out;
    }

    private static function formatPrice(float $price, string $currency, string $suffix): string
    {
        $currency = strtoupper((string)preg_replace('/[^A-Za-z]/', '', $currency));
        $label = ($currency !== '' ? $currency . ' ' : '') . number_format($price, 2, '.', ',');
        $suffix = trim($suffix);
        return $suffix !== '' ? $label . ' ' . mb_substr($suffix, 0, 20) : $label;
    }

    private static function permalink(string $kind, array $row): string
    {
        try {
            return match ($kind) {
                'product' => function_exists('product_permalink') ? (string)product_permalink($row) : '',
                'article' => function_exists('article_permalink') ? (string)article_permalink($row) : '',
                'page' => function_exists('page_permalink') ? (string)page_permalink($row) : '',
                'entry' => function_exists('content_entry_permalink') ? (string)content_entry_permalink($row) : '',
                default => '',
            };
        } catch (\Throwable $_) {
            return '';
        }
    }

    // ---------------------------------------------------------------- 检索与拼装

    /**
     * 按提问挑资料片段，拼成注入提示词的那一段。
     *
     * 不做 embedding：没有向量库也不该为一个客服插件引一个。用的是「中文按 2-gram、
     * 西文按词」的重合度打分——对"哪个型号防水""怎么退货"这类具体问句已经够用，
     * 而且完全可离线、可解释。
     *
     * 「手工补充资料」是管理员自己写的小抄，语义上一直有效，所以单独占一档优先注入；
     * 但最多吃掉四成额度，否则一段长小抄会把真正命中问题的片段全挤掉。
     */
    public static function context(array $config, string $question): string
    {
        $mode = (string)$config['knowledge_mode'];
        if ($mode === 'off') return '';
        $budget = (int)$config['knowledge_budget'];
        $byRelevance = (string)$config['knowledge_strategy'] === 'relevance';
        $terms = $byRelevance ? self::terms($question) : [];

        $pinned = self::manualChunks($config);
        $ranked = self::libraryChunks($config, $mode);
        if ($pinned === [] && $ranked === []) return '';

        $rank = static function (array $chunks) use ($terms, $byRelevance): array {
            if (!$byRelevance || $chunks === []) return $chunks;
            foreach ($chunks as $index => $chunk) {
                $chunks[$index]['score'] = self::score($chunk['text'], $terms);
            }
            usort($chunks, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
            return $chunks;
        };

        $parts = [];
        $used = 0;
        $take = static function (array $chunks, int $allowance) use (&$parts, &$used): void {
            foreach ($chunks as $chunk) {
                $text = trim((string)$chunk['text']);
                if ($text === '') continue;
                $remaining = $allowance - $used;
                if ($remaining < 120) return;
                if (mb_strlen($text) > $remaining) $text = mb_substr($text, 0, $remaining - 3) . '...';
                $parts[] = '【' . $chunk['label'] . '】' . "\n" . $text;
                $used += mb_strlen($text) + mb_strlen((string)$chunk['label']) + 6;
            }
        };

        // 小抄先来，但只给四成额度；随后按相关度把剩下的填满。
        $take($rank($pinned), (int)round($budget * 0.4));
        $take($rank($ranked), $budget);
        return implode("\n\n", $parts);
    }

    /** @return list<array{label:string,text:string,score:float}> */
    private static function manualChunks(array $config): array
    {
        $manual = trim((string)$config['knowledge_base']);
        if ($manual === '') return [];
        $out = [];
        foreach (self::split($manual, 900) as $index => $piece) {
            $out[] = ['label' => '补充资料 ' . ($index + 1), 'text' => $piece, 'score' => 0.0];
        }
        return $out;
    }

    /**
     * 站内内容与上传文件的候选片段。
     *
     * @return list<array{label:string,text:string,score:float}>
     */
    private static function libraryChunks(array $config, string $mode): array
    {
        $chunks = [];
        if ($mode === 'sources' || $mode === 'both') {
            foreach (self::sourceRecords($config) as $record) {
                $extra = [];
                if (($record['price'] ?? '') !== '') $extra[] = '价格：' . $record['price'];
                if (($record['sku'] ?? '') !== '') $extra[] = 'SKU：' . $record['sku'];
                if (($record['stock'] ?? '') !== '') $extra[] = '库存：' . self::stockLabel((string)$record['stock']);
                if (($record['url'] ?? '') !== '') $extra[] = '链接：' . $record['url'];
                $text = trim($record['title'] . "\n" . trim((string)$record['summary'])
                    . ($extra !== [] ? "\n" . implode('｜', $extra) : ''));
                if ($text === '') continue;
                $chunks[] = [
                    'label' => (AiCustomerService::SOURCE_KINDS[$record['kind']] ?? '内容') . '：' . $record['title'],
                    'text' => $text,
                    'score' => 0.0,
                ];
            }
        }
        if ($mode === 'files' || $mode === 'both') {
            foreach ($config['knowledge']['files'] as $file) {
                $text = self::readFileText((string)$file['id']);
                if ($text === '') continue;
                foreach (self::split($text, 900) as $index => $piece) {
                    if ($index >= 80) break;
                    $chunks[] = [
                        'label' => (string)$file['name'] . ' · 第 ' . ($index + 1) . ' 段',
                        'text' => $piece,
                        'score' => 0.0,
                    ];
                }
            }
        }
        return $chunks;
    }

    /** @return list<array<string,mixed>> */
    public static function sourceRecords(array $config): array
    {
        $grouped = [];
        foreach ($config['knowledge']['sources'] as $source) {
            $grouped[$source['kind'] . '|' . $source['type']][] = (int)$source['id'];
        }
        $auto = $config['knowledge']['auto'];
        foreach (['products' => 'product', 'articles' => 'article', 'pages' => 'page'] as $flag => $kind) {
            if (empty($auto[$flag])) continue;
            foreach (self::pick($kind, '', '', ['newest' => true], min(8, (int)$auto['limit'])) as $record) {
                $grouped[$kind . '|'][] = (int)$record['id'];
            }
        }
        $out = [];
        foreach ($grouped as $token => $ids) {
            [$kind, $type] = array_pad(explode('|', $token, 2), 2, '');
            foreach (self::loadRecords($kind, $type, array_values(array_unique($ids)), 60) as $record) {
                $out[] = $record;
            }
        }
        return $out;
    }

    private static function stockLabel(string $status): string
    {
        return match ($status) {
            'out_of_stock' => '暂无库存',
            'on_backorder' => '可预订',
            default => '有货',
        };
    }

    /** @return list<string> 按空行/段落切片，尽量不在句子中间断开。 */
    private static function split(string $text, int $size): array
    {
        $paragraphs = preg_split('/\n{2,}/u', $text) ?: [$text];
        $out = [];
        $buffer = '';
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') continue;
            if (mb_strlen($paragraph) >= $size) {
                if ($buffer !== '') { $out[] = $buffer; $buffer = ''; }
                $length = mb_strlen($paragraph);
                for ($offset = 0; $offset < $length; $offset += $size) {
                    $out[] = mb_substr($paragraph, $offset, $size);
                }
                continue;
            }
            if (mb_strlen($buffer) + mb_strlen($paragraph) + 1 > $size) {
                $out[] = $buffer;
                $buffer = $paragraph;
            } else {
                $buffer = $buffer === '' ? $paragraph : $buffer . "\n" . $paragraph;
            }
        }
        if ($buffer !== '') $out[] = $buffer;
        return $out;
    }

    /** @return array<string,int> 词 => 权重 */
    private static function terms(string $question): array
    {
        $question = mb_strtolower(trim($question));
        if ($question === '') return [];
        $terms = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', $question) ?: [] as $token) {
            if ($token === '') continue;
            if (preg_match('/^[\x{4E00}-\x{9FFF}\x{3400}-\x{4DBF}]+$/u', $token) === 1) {
                $length = mb_strlen($token);
                if ($length === 1) { $terms[$token] = ($terms[$token] ?? 0) + 1; continue; }
                for ($i = 0; $i < $length - 1; $i++) {
                    $gram = mb_substr($token, $i, 2);
                    $terms[$gram] = ($terms[$gram] ?? 0) + 2;
                }
                continue;
            }
            if (mb_strlen($token) < 2) continue;
            $terms[$token] = ($terms[$token] ?? 0) + 2;
        }
        return array_slice($terms, 0, 64, true);
    }

    private static function score(string $text, array $terms): float
    {
        if ($terms === []) return 0.0;
        $haystack = mb_strtolower($text);
        $score = 0.0;
        foreach ($terms as $term => $weight) {
            $hits = substr_count($haystack, (string)$term);
            if ($hits > 0) $score += $weight * min(3, $hits);
        }
        // 除以长度的对数，避免长片段单靠体量霸榜。
        return $score / max(1.0, log(max(20, mb_strlen($text))));
    }
}
