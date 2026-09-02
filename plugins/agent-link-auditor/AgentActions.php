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
                if (self::isKnownPublicTarget($target) || self::publicRouteState($target) !== false) return null;
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
                    if (self::isKnownPublicTarget($target) || self::publicRouteState($target) !== false) continue;
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
            $normalized = self::normalizeRoutePath((string)$target);
            if ($normalized === null || $normalized === '/') continue;
            $segments = array_values(array_filter(explode('/', trim($normalized, '/')), 'strlen'));
            $last = (string)end($segments);
            if ($last === '' || !preg_match('/^[\p{L}\p{N}_%\-.]+$/u', $last)) continue;
            if (preg_match('/\.(?:css|js|png|jpe?g|gif|webp|svg|ico|pdf|zip|mp4|mp3|xml|txt)$/i', $last)) continue;
            $first = strtolower(rawurldecode((string)($segments[0] ?? '')));
            if (in_array($first, ['admin', 'api', 'assets', 'uploads', 'plugin-asset', 'storage'], true)) continue;
            $out[(string)$target] = $last;
        }
        return $out;
    }

    /**
     * Normalize a root-relative target to the path seen by the frontend router.
     * The core may mount the site below a subdirectory and may prepend a
     * language segment; both are transport details, not content route segments.
     */
    private static function normalizeRoutePath(string $target): ?string
    {
        $target = trim($target);
        if ($target === '' || strlen($target) > 2048 || preg_match('/[\x00-\x1f\x7f]/', $target)) return null;
        $parts = parse_url($target);
        $path = is_array($parts) ? ($parts['path'] ?? null) : null;
        if (!is_string($path) || $path === '' || !str_starts_with($path, '/')) return null;
        $path = rawurldecode($path);
        if ($path === '' || str_contains($path, '\\') || str_contains($path, '//')) return null;
        $path = '/' . trim($path, '/');
        if ($path === '//') $path = '/';

        $basePath = '';
        try {
            if (function_exists('base_url')) {
                $basePath = trim((string)parse_url((string)base_url(), PHP_URL_PATH), '/');
            }
        } catch (\Throwable $_) {
        }
        if ($basePath !== '' && ($path === '/' . $basePath || str_starts_with($path, '/' . $basePath . '/'))) {
            $path = substr($path, strlen('/' . $basePath)) ?: '/';
        }

        $segments = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));
        if ($segments !== []) {
            try {
                if (class_exists(\App\Core\LanguageService::class)
                    && \App\Core\LanguageService::enabledLanguageByCode((string)$segments[0]) !== null) {
                    array_shift($segments);
                    $path = $segments === [] ? '/' : '/' . implode('/', $segments);
                }
            } catch (\Throwable $_) {
            }
        }

        if ($path !== '/' && function_exists('permalink_html_suffix_enabled')) {
            try {
                if (permalink_html_suffix_enabled() && preg_match('/\.html$/i', $path) === 1) {
                    $path = substr($path, 0, -5) ?: '/';
                }
            } catch (\Throwable $_) {
            }
        }
        return $path === '' ? '/' : $path;
    }

    /**
     * Return true when a target matches an actual public content route and the
     * target record exists, false when that route is known but missing, and null
     * when the path belongs to no declared content route.
     */
    private static function publicRouteState(string $target): ?bool
    {
        static $cache = [];
        $path = self::normalizeRoutePath($target);
        if ($path === null) return null;
        if (array_key_exists($path, $cache)) return $cache[$path];
        if ($path === '/') return $cache[$path] = true;

        $matched = false;
        foreach (self::routeDefinitions() as $definition) {
            $pattern = (string)($definition['pattern'] ?? '');
            if ($pattern === '' || preg_match($pattern, $path, $matches) !== 1) continue;
            $matched = true;
            if (self::routeRecordExists($definition, $matches)) return $cache[$path] = true;
        }
        return $cache[$path] = ($matched ? false : null);
    }

    /** @return array<int,array{type:string,table:string,content_type:string,pattern:string}> */
    private static function routeDefinitions(): array
    {
        static $definitions = null;
        if (is_array($definitions)) return $definitions;
        $definitions = [];
        $add = static function (string $type, string $table, string $contentType, string $structure) use (&$definitions): void {
            $pattern = self::routePattern($structure);
            if ($pattern !== null) {
                $definitions[] = [
                    'type' => $type,
                    'table' => $table,
                    'content_type' => $contentType,
                    'pattern' => $pattern,
                ];
            }
        };

        foreach ([
            ['page', 'pages', '', '{slug}'],
            ['article', 'articles', '', 'post/{slug}'],
            ['product', 'products', '', 'product/{slug}'],
        ] as $fallback) {
            $structure = (string)$fallback[3];
            try {
                if (function_exists('permalink_structure_for_type')) {
                    $structure = (string)permalink_structure_for_type((string)$fallback[0]);
                }
            } catch (\Throwable $_) {
            }
            $add((string)$fallback[0], (string)$fallback[1], '', $structure);
        }

        // Read custom type definitions directly. ContentType::allOrdered() also
        // seeds builtins, which would violate this plugin's read-only contract.
        try {
            $rows = \App\Core\Database::table('content_types')->where('status', 1)->get();
            foreach ($rows as $row) {
                $slug = strtolower(trim((string)($row['slug'] ?? '')));
                if ($slug === '' || in_array($slug, ['page', 'article', 'product'], true)) continue;
                $settings = json_decode((string)($row['settings_json'] ?? '{}'), true);
                if (is_array($settings) && array_key_exists('public', $settings) && empty($settings['public'])) continue;
                try {
                    $structure = function_exists('permalink_structure_for_type')
                        ? (string)permalink_structure_for_type($slug)
                        : $slug . '/{slug}';
                } catch (\Throwable $_) {
                    $structure = $slug . '/{slug}';
                }
                $add('content_entry', 'content_entries', $slug, $structure);
            }
        } catch (\Throwable $_) {
        }
        return $definitions;
    }

    /** Convert a core permalink structure into a strict router-compatible regex. */
    private static function routePattern(string $structure): ?string
    {
        $structure = trim($structure, '/');
        if ($structure === '') return null;
        $segments = explode('/', $structure);
        $regex = [];
        $seenCaptures = [];
        foreach ($segments as $segment) {
            if ($segment === '') return null;
            $offset = 0;
            $part = '';
            if (preg_match_all('/\{(slug|id|year|month|day)\}/', $segment, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[1] as $index => $marker) {
                    $name = (string)$marker[0];
                    $position = (int)$matches[0][$index][1];
                    $part .= preg_quote(substr($segment, $offset, $position - $offset), '#');
                    if (in_array($name, ['slug', 'id'], true)) {
                        if (isset($seenCaptures[$name])) return null;
                        $seenCaptures[$name] = true;
                    }
                    $part .= match ($name) {
                        'slug' => '(?<slug>[\\p{L}\\p{N}_-]+)',
                        'id' => '(?<id>[0-9]+)',
                        'year' => '[0-9]{4}',
                        'month' => '(?:0[1-9]|1[0-2])',
                        'day' => '(?:0[1-9]|[12][0-9]|3[01])',
                        default => '',
                    };
                    $offset = $position + strlen((string)$matches[0][$index][0]);
                }
                $part .= preg_quote(substr($segment, $offset), '#');
            } else {
                if (str_contains($segment, '{') || str_contains($segment, '}')) return null;
                $part = preg_quote($segment, '#');
            }
            $regex[] = $part;
        }
        return '#^/' . implode('/', $regex) . '/?$#u';
    }

    private static function routeRecordExists(array $definition, array $matches): bool
    {
        $table = (string)($definition['table'] ?? '');
        if ($table === '') return false;
        try {
            $query = \App\Core\Database::table($table);
            $id = (string)($matches['id'] ?? '');
            $slug = rawurldecode((string)($matches['slug'] ?? ''));
            if ($id !== '') {
                $query->where('id', (int)$id);
            } else {
                if ($slug === '' || !preg_match('/^[\p{L}\p{N}_-]+$/u', $slug)) return false;
                $query->where('slug', $slug);
            }
            if ((string)($definition['type'] ?? '') === 'content_entry') {
                $contentType = (string)($definition['content_type'] ?? '');
                if ($contentType === '') return false;
                $query->where('content_type', $contentType);
            }
            if (class_exists(\App\Core\ContentWorkflow::class)) {
                $query = \App\Core\ContentWorkflow::applyPublicScope($query);
            } else {
                $query->where('status', 1);
            }
            return is_array($query->first());
        } catch (\Throwable $_) {
            return false;
        }
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
