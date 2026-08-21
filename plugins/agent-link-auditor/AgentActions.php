<?php
/**
 * agent-link-auditor 的执行器与资源 loader。
 *
 * 约束（PluginManager::agentActionExecutor 会用反射强制）：
 *   - 类必须由 plugin.php 显式 require_once（插件类没有自动加载）
 *   - 方法必须是 public static
 *   - 类文件必须位于本插件目录内
 */
final class AgentLinkAuditorActions
{
    /** 单次扫描的内容行数上限，避免在大站上把一次工具调用拖成全表扫描。 */
    private const MAX_ROWS_PER_TYPE = 200;

    /** 支持体检的内容类型 → 表名 */
    private const TYPE_TABLES = [
        'page' => 'pages',
        'article' => 'articles',
    ];

    /**
     * 只读动作：扫描死链。
     * @return array{ok:bool,message:string,data?:array,error_code?:string,http_status?:int}
     */
    public static function scan(array $input, array $context): array
    {
        $limit = (int)($input['limit'] ?? 50);
        $limit = max(1, min(200, $limit));

        $types = self::normalizeTypes($input['types'] ?? null);
        if ($types === []) {
            return [
                'ok' => false,
                'message' => 'types 只接受 page / article，可传数组或逗号分隔字符串',
                'error_code' => 'link_audit_bad_types',
                'http_status' => 422,
            ];
        }

        try {
            $report = self::collect($types, $limit);
        } catch (\Throwable $error) {
            return [
                'ok' => false,
                'message' => '扫描失败：' . $error->getMessage(),
                'error_code' => 'link_audit_scan_failed',
                'http_status' => 500,
            ];
        }

        $count = count($report['broken']);
        return [
            'ok' => true,
            'message' => $count === 0
                ? '已扫描 ' . $report['scanned'] . ' 条内容，未发现站内死链'
                : '已扫描 ' . $report['scanned'] . ' 条内容，发现 ' . $count . ' 条站内死链',
            'data' => [
                'scanned' => $report['scanned'],
                'checked_links' => $report['checked'],
                'broken_count' => $count,
                'truncated' => $report['truncated'],
                'broken' => $report['broken'],
            ],
        ];
    }

    /**
     * 资源 loader。AiResourceCatalog 只传一个数组参数：
     *   search 模式 => ['mode'=>'search','type','keyword','limit','user_id']
     *   load   模式 => ['mode'=>'load','type','id','field','user_id']
     */
    public static function loadBrokenLinks(array $request)
    {
        $mode = (string)($request['mode'] ?? 'search');
        try {
            $report = self::collect(array_keys(self::TYPE_TABLES), 200);
        } catch (\Throwable $_) {
            return $mode === 'load' ? null : [];
        }
        $rows = $report['broken'];

        if ($mode === 'load') {
            $id = (string)($request['id'] ?? '');
            foreach ($rows as $row) {
                if (self::rowId($row) === $id) return $row;
            }
            return null;
        }

        $keyword = mb_strtolower(trim((string)($request['keyword'] ?? '')), 'UTF-8');
        if ($keyword !== '') {
            $rows = array_values(array_filter($rows, static function (array $row) use ($keyword): bool {
                $haystack = mb_strtolower(
                    $row['source_title'] . ' ' . $row['target'] . ' ' . $row['source_type'],
                    'UTF-8'
                );
                return mb_strpos($haystack, $keyword) !== false;
            }));
        }
        $limit = max(1, min(100, (int)($request['limit'] ?? 20)));
        return array_slice($rows, 0, $limit);
    }

    // ----------------------------------------------------------------
    // 内部
    // ----------------------------------------------------------------

    /**
     * @param string[] $types
     * @return array{scanned:int,checked:int,truncated:bool,broken:array<int,array<string,string>>}
     */
    private static function collect(array $types, int $limit): array
    {
        $slugCache = [];
        $broken = [];
        $scanned = 0;
        $checked = 0;
        $truncated = false;

        foreach ($types as $type) {
            $table = self::TYPE_TABLES[$type] ?? '';
            if ($table === '') continue;
            $rows = \App\Core\Database::table($table)
                ->select('id', 'title', 'slug', 'content')
                ->where('status', 'published')
                ->orderBy('id', 'desc')
                ->limit(self::MAX_ROWS_PER_TYPE)
                ->get();
            if (count($rows) >= self::MAX_ROWS_PER_TYPE) $truncated = true;

            foreach ($rows as $row) {
                $scanned++;
                foreach (self::internalTargets((string)($row['content'] ?? '')) as $target => $targetSlug) {
                    $checked++;
                    $resolvedType = self::resolveSlug($targetSlug, $slugCache);
                    if ($resolvedType !== null) continue;
                    $broken[] = [
                        'source_type' => $type,
                        'source_id' => (string)($row['id'] ?? ''),
                        'source_title' => (string)($row['title'] ?? ''),
                        'target' => (string)$target,
                        'reason' => 'slug「' . $targetSlug . '」在页面 / 文章 / 产品中都不存在',
                    ];
                    if (count($broken) >= $limit) {
                        return ['scanned' => $scanned, 'checked' => $checked, 'truncated' => true, 'broken' => $broken];
                    }
                }
            }
        }

        return ['scanned' => $scanned, 'checked' => $checked, 'truncated' => $truncated, 'broken' => $broken];
    }

    /**
     * 从正文里抽出站内相对链接：href="/foo" 或 href="/foo/bar"。
     * 忽略外链、锚点、mailto/tel、静态资源与后台路径。
     * @return array<string,string> 原始 target => 首段 slug
     */
    private static function internalTargets(string $html): array
    {
        if ($html === '' || !preg_match_all('#href\s*=\s*["\'](/[^"\'>\s]*)["\']#i', $html, $matches)) {
            return [];
        }
        $out = [];
        foreach ($matches[1] as $target) {
            $path = strtok($target, '?#');
            if (!is_string($path)) continue;
            $path = trim($path, '/');
            if ($path === '') continue;
            $first = explode('/', $path)[0];
            if ($first === '' || !preg_match('/^[A-Za-z0-9\-_%.]+$/', $first)) continue;
            // 静态资源与已知非内容前缀不参与体检
            if (preg_match('/\.(?:css|js|png|jpe?g|gif|webp|svg|ico|pdf|zip|mp4|mp3|xml|txt)$/i', $first)) continue;
            if (in_array(strtolower($first), ['admin', 'api', 'assets', 'uploads', 'plugin-asset', 'storage'], true)) continue;
            $out[$target] = rawurldecode($first);
        }
        return $out;
    }

    /** slug 是否能解析到任一内容类型；结果缓存，避免同一 slug 反复查库。 */
    private static function resolveSlug(string $slug, array &$cache): ?string
    {
        if (array_key_exists($slug, $cache)) return $cache[$slug];
        $found = null;
        foreach (['pages' => 'page', 'articles' => 'article', 'products' => 'product'] as $table => $type) {
            try {
                $row = \App\Core\Database::table($table)->select('id')->where('slug', $slug)->first();
            } catch (\Throwable $_) {
                $row = null;
            }
            if ($row) { $found = $type; break; }
        }
        return $cache[$slug] = $found;
    }

    private static function rowId(array $row): string
    {
        return $row['source_type'] . ':' . $row['source_id'] . ':' . md5($row['target']);
    }

    /** @return string[] */
    private static function normalizeTypes($raw): array
    {
        if ($raw === null || $raw === '' || $raw === []) return array_keys(self::TYPE_TABLES);
        $list = is_array($raw) ? $raw : explode(',', (string)$raw);
        $out = [];
        foreach ($list as $item) {
            $item = strtolower(trim((string)$item));
            if (isset(self::TYPE_TABLES[$item])) $out[$item] = true;
        }
        return array_keys($out);
    }
}
