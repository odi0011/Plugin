<?php
/** Read-only, pageable internal-link audit executor and resource loader. */
final class AgentLinkAuditorActions
{
    private const MAX_ROWS_PER_PAGE = 200;
    private const MAX_PAGE = 10000;

    private const TYPE_TABLES = [
        'page' => ['table' => 'pages', 'content' => 'html'],
        'article' => ['table' => 'articles', 'content' => 'content'],
    ];

    public static function scan(array $input, array $context): array
    {
        $limit = max(1, min(200, (int)($input['limit'] ?? 50)));
        $page = max(1, min(self::MAX_PAGE, (int)($input['page'] ?? 1)));
        $perPage = max(1, min(self::MAX_ROWS_PER_PAGE, (int)($input['per_page'] ?? 100)));
        $types = self::normalizeTypes($input['types'] ?? null);
        if ($types === []) {
            return self::fail('types 只接受 page / article，可传数组或逗号分隔字符串', 'link_audit_bad_types', 422);
        }

        try {
            $report = self::collect($types, $limit, $page, $perPage);
        } catch (\Throwable $error) {
            error_log('Agent link audit failed [' . get_class($error) . ']');
            return self::fail('站内链接扫描暂时不可用', 'link_audit_scan_failed', 500);
        }

        $count = count($report['broken']);
        return [
            'ok' => true,
            'message' => $count === 0
                ? '已扫描本页 ' . $report['scanned'] . ' 条内容，未发现站内死链'
                : '已扫描本页 ' . $report['scanned'] . ' 条内容，发现 ' . $count . ' 条站内死链',
            'data' => [
                'scanned' => $report['scanned'],
                'checked_links' => $report['checked'],
                'broken_count' => $count,
                'truncated' => $report['truncated'],
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'next_page' => ($report['has_more'] || $report['truncated']) ? $page + 1 : null,
                ],
                'broken' => $report['broken'],
            ],
        ];
    }

    public static function loadBrokenLinks(array $request)
    {
        $mode = (string)($request['mode'] ?? 'search');
        $page = max(1, min(self::MAX_PAGE, (int)($request['page'] ?? 1)));
        if ($mode === 'load') {
            $loaded = self::loadByStableId((string)($request['id'] ?? ''));
            return $loaded;
        }
        try {
            $report = self::collect(array_keys(self::TYPE_TABLES), 200, $page, self::MAX_ROWS_PER_PAGE);
        } catch (\Throwable $_) {
            return $mode === 'load' ? null : [];
        }
        $resourceType = 'agent-link-auditor.broken_links';
        $rows = array_map(static function (array $row) use ($resourceType): array {
            return [
                'type' => $resourceType,
                'id' => self::rowId($row),
                'title' => (string)$row['source_title'] . ' -> ' . (string)$row['target'],
                'field' => 'reason',
                'fields' => [
                    'source_type' => (string)$row['source_type'],
                    'source_id' => (string)$row['source_id'],
                    'source_title' => (string)$row['source_title'],
                    'target' => (string)$row['target'],
                    'reason' => (string)$row['reason'],
                ],
            ] + $row;
        }, $report['broken']);

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

    private static function loadByStableId(string $id): ?array
    {
        if (preg_match('/^(page|article):(\d+):([a-f0-9]{32})$/D', trim($id), $match) !== 1) return null;
        $type = (string)$match[1];
        $objectId = (int)$match[2];
        $definition = self::TYPE_TABLES[$type] ?? null;
        if ($objectId < 1 || !is_array($definition)) return null;
        try {
            $query = \App\Core\Database::table((string)$definition['table'])
                ->select('id', 'title', 'slug', (string)$definition['content'])
                ->where('id', $objectId)->where('status', 1);
            if (class_exists(\App\Core\ContentWorkflow::class)) $query = \App\Core\ContentWorkflow::applyPublicScope($query);
            $row = $query->first();
            if (!is_array($row)) return null;
            foreach (self::internalTargets((string)($row[$definition['content']] ?? '')) as $target => $targetSlug) {
                if (!hash_equals((string)$match[3], md5($target))) continue;
                if (self::isKnownPublicTarget($target)) return null;
                $cache = [];
                if (self::resolveSlug($targetSlug, $cache) !== null) return null;
                $base = [
                    'source_type' => $type,
                    'source_id' => (string)($row['id'] ?? ''),
                    'source_title' => (string)($row['title'] ?? ''),
                    'target' => (string)$target,
                    'reason' => '公开内容中找不到目标 slug「' . $targetSlug . '」',
                ];
                return [
                    'type' => 'agent-link-auditor.broken_links',
                    'id' => self::rowId($base),
                    'title' => $base['source_title'] . ' -> ' . $base['target'],
                    'field' => 'reason',
                    'fields' => $base,
                ] + $base;
            }
        } catch (\Throwable $_) {
        }
        return null;
    }

    /** @param string[] $types */
    private static function collect(array $types, int $limit, int $page, int $perPage): array
    {
        $slugCache = [];
        $broken = [];
        $scanned = 0;
        $checked = 0;
        $hasMore = false;
        $resultLimited = false;
        $offset = ($page - 1) * $perPage;

        foreach ($types as $type) {
            $definition = self::TYPE_TABLES[$type] ?? null;
            if (!is_array($definition)) continue;
            $contentField = (string)$definition['content'];
            $query = \App\Core\Database::table((string)$definition['table'])
                ->select('id', 'title', 'slug', $contentField)
                ->where('status', 1);
            if (class_exists(\App\Core\ContentWorkflow::class)) {
                $query = \App\Core\ContentWorkflow::applyPublicScope($query);
            }
            $rows = $query
                ->orderBy('id', 'desc')
                ->limit($perPage + 1)
                ->offset($offset)
                ->get();
            if (count($rows) > $perPage) {
                $hasMore = true;
                $rows = array_slice($rows, 0, $perPage);
            }

            foreach ($rows as $row) {
                $scanned++;
                foreach (self::internalTargets((string)($row[$contentField] ?? '')) as $target => $targetSlug) {
                    $checked++;
                    if (self::isKnownPublicTarget($target)) continue;
                    if (self::resolveSlug($targetSlug, $slugCache) !== null) continue;
                    $broken[] = [
                        'source_type' => $type,
                        'source_id' => (string)($row['id'] ?? ''),
                        'source_title' => (string)($row['title'] ?? ''),
                        'target' => (string)$target,
                        'reason' => '公开内容中找不到目标 slug「' . $targetSlug . '」',
                    ];
                    if (count($broken) >= $limit) {
                        $resultLimited = true;
                        break 2;
                    }
                }
            }
            if ($resultLimited) break;
        }

        return [
            'scanned' => $scanned,
            'checked' => $checked,
            'truncated' => $hasMore || $resultLimited,
            'has_more' => $hasMore,
            'broken' => $broken,
        ];
    }

    /** @return array<string,string> original target => terminal slug */
    private static function internalTargets(string $html): array
    {
        if ($html === '' || !preg_match_all('#href\s*=\s*["\'](/[^"\'>\s]*)["\']#i', $html, $matches)) {
            return [];
        }
        $out = [];
        foreach ($matches[1] as $target) {
            $path = parse_url((string)$target, PHP_URL_PATH);
            if (!is_string($path)) continue;
            $path = trim($path, '/');
            if ($path === '') continue;
            $segments = array_values(array_filter(explode('/', $path), 'strlen'));
            $last = rawurldecode((string)end($segments));
            if ($last === '' || !preg_match('/^[\p{L}\p{N}_%.\-]+$/u', $last)) continue;
            if (preg_match('/\.(?:css|js|png|jpe?g|gif|webp|svg|ico|pdf|zip|mp4|mp3|xml|txt)$/i', $last)) continue;
            $first = strtolower(rawurldecode((string)($segments[0] ?? '')));
            if (in_array($first, ['admin', 'api', 'assets', 'uploads', 'plugin-asset', 'storage'], true)) continue;
            $out[(string)$target] = $last;
        }
        return $out;
    }

    private static function resolveSlug(string $slug, array &$cache): ?string
    {
        if (array_key_exists($slug, $cache)) return $cache[$slug];
        foreach (['pages' => 'page', 'articles' => 'article', 'products' => 'product', 'content_entries' => 'content_entry'] as $table => $type) {
            try {
                $query = \App\Core\Database::table($table)
                    ->select('id')
                    ->where('slug', $slug)
                    ->where('status', 1);
                if (class_exists(\App\Core\ContentWorkflow::class)) {
                    $query = \App\Core\ContentWorkflow::applyPublicScope($query);
                }
                $row = $query->first();
            } catch (\Throwable $_) {
                $row = null;
            }
            if ($row) return $cache[$slug] = $type;
        }
        return $cache[$slug] = null;
    }

    private static array $publicTargetCache = [];

    private static function isKnownPublicTarget(string $target): bool
    {
        if (array_key_exists($target, self::$publicTargetCache)) return self::$publicTargetCache[$target];
        $path = parse_url($target, PHP_URL_PATH);
        if (!is_string($path)) return self::$publicTargetCache[$target] = false;
        $path = '/' . trim(rawurldecode($path), '/');
        $basePath = function_exists('base_url') ? trim((string)parse_url(base_url(), PHP_URL_PATH), '/') : '';
        if ($basePath !== '' && ($path === '/' . $basePath || str_starts_with($path, '/' . $basePath . '/'))) {
            $path = substr($path, strlen('/' . $basePath)) ?: '/';
        }
        if ($path === '/') return self::$publicTargetCache[$target] = true;
        try {
            $redirect = \App\Core\RedirectRuleService::resolveFromDatabase($path);
            if (($redirect['status_code'] ?? null) !== null) return self::$publicTargetCache[$target] = true;
        } catch (\Throwable $_) {
        }
        $segments = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));
        $prefixes = [];
        if (function_exists('article_archive_prefix')) $prefixes[(string)article_archive_prefix()] = ['article_categories', 'tags'];
        if (function_exists('product_archive_prefix')) $prefixes[(string)product_archive_prefix()] = ['product_categories', 'product_tags'];
        $first = strtolower((string)($segments[0] ?? ''));
        if ($first === '' || !isset($prefixes[$first])) return self::$publicTargetCache[$target] = false;
        if (count($segments) === 1) return self::$publicTargetCache[$target] = true;
        if (!in_array(strtolower((string)($segments[1] ?? '')), ['category', 'tag'], true)
            || count($segments) !== 3) return self::$publicTargetCache[$target] = false;
        $table = $prefixes[$first][strtolower($segments[1]) === 'category' ? 0 : 1] ?? '';
        if ($table === '') return self::$publicTargetCache[$target] = false;
        try {
            $exists = \App\Core\Database::table($table)->where('slug', $segments[2])->first();
            return self::$publicTargetCache[$target] = is_array($exists);
        } catch (\Throwable $_) {
            return self::$publicTargetCache[$target] = false;
        }
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

    private static function fail(string $message, string $code, int $status): array
    {
        return ['ok' => false, 'message' => $message, 'error_code' => $code, 'http_status' => $status];
    }
}
