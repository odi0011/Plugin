<?php
/** Read-only, pageable SEO presentation completeness snapshot. */
final class AgentSeoSnapshotActions
{
    private const TYPE_TABLES = [
        'page' => 'pages',
        'article' => 'articles',
        'product' => 'products',
    ];

    /** Effective rendered signals, not optional storage overrides. */
    private const EFFECTIVE_FIELDS = [
        'title' => 3,
        'description' => 3,
        'og_title' => 1,
        'og_description' => 1,
        'og_image' => 1,
        'canonical' => 2,
    ];

    private const MAX_ROWS_PER_PAGE = 200;
    private const MAX_PAGE = 10000;
    private const STATUS_MAP = [
        'draft' => 0,
        'published' => 1,
        'scheduled' => 2,
        'archived' => 3,
    ];

    public static function snapshot(array $input, array $context): array
    {
        $types = self::normalizeTypes($input['type'] ?? null);
        if ($types === []) return self::fail('type 只接受 page / article / product，可传数组或逗号分隔字符串', 'seo_snapshot_bad_type', 422);

        $status = strtolower(trim((string)($input['status'] ?? 'published')));
        if ($status === '') $status = 'published';
        if ($status !== 'all' && !array_key_exists($status, self::STATUS_MAP)) {
            return self::fail('status 只接受 published / draft / scheduled / archived / all', 'seo_snapshot_bad_status', 422);
        }
        $user = is_array($context['user'] ?? null) ? $context['user'] : [];
        foreach ($types as $type) {
            $permission = $type . '.view';
            if ($user === [] || !class_exists(\App\Core\Permission::class)
                || !\App\Core\Permission::userCan($user, $permission)) {
                return self::fail(
                    ($status === 'published' ? '读取' : '读取非公开 ') . $type
                        . ' SEO 快照需要 ' . $permission . ' 权限',
                    'seo_snapshot_permission_denied',
                    403
                );
            }
        }
        $page = max(1, min(self::MAX_PAGE, (int)($input['page'] ?? 1)));
        $perPage = max(1, min(self::MAX_ROWS_PER_PAGE, (int)($input['per_page'] ?? 100)));
        $limit = max(1, min(50, (int)($input['limit'] ?? 10)));
        $offset = ($page - 1) * $perPage;

        $byType = [];
        $worst = [];
        $totalRows = 0;
        $hasMore = false;

        foreach ($types as $type) {
            try {
                $query = \App\Core\Database::table(self::TYPE_TABLES[$type])
                    ->select([
                        'id', 'title', 'slug', 'status', 'template', 'summary', 'cover_image',
                        'seo_title', 'seo_description', 'og_title', 'og_description',
                        'og_image', 'canonical_url',
                    ]);
                if ($status !== 'all') $query->where('status', self::STATUS_MAP[$status]);
                if ($status === 'published' && class_exists(\App\Core\ContentWorkflow::class)) {
                    $query = \App\Core\ContentWorkflow::applyPublicScope($query);
                }
                $rows = $query->orderBy('id', 'desc')
                    ->limit($perPage + 1)
                    ->offset($offset)
                    ->get();
            } catch (\Throwable $error) {
                error_log('Agent SEO snapshot failed [' . get_class($error) . ']');
                return self::fail('SEO 快照数据暂时不可用', 'seo_snapshot_read_failed', 500);
            }
            if (count($rows) > $perPage) {
                $hasMore = true;
                $rows = array_slice($rows, 0, $perPage);
            }

            $missingByField = array_fill_keys(array_keys(self::EFFECTIVE_FIELDS), 0);
            $filledSlots = 0;
            $totalSlots = 0;
            $rowsWithGaps = 0;
            foreach ($rows as $row) {
                $effective = self::effectiveValues($type, $row);
                $missing = [];
                $weight = 0;
                foreach (self::EFFECTIVE_FIELDS as $field => $fieldWeight) {
                    $totalSlots++;
                    if (trim((string)($effective[$field] ?? '')) !== '') {
                        $filledSlots++;
                        continue;
                    }
                    $missingByField[$field]++;
                    $missing[] = $field;
                    $weight += $fieldWeight;
                }
                if ($missing === []) continue;
                $rowsWithGaps++;
                $worst[] = [
                    'type' => $type,
                    'id' => (int)($row['id'] ?? 0),
                    'title' => (string)($row['title'] ?? ''),
                    'slug' => (string)($row['slug'] ?? ''),
                    'status' => self::statusName((int)($row['status'] ?? 0)),
                    'missing' => $missing,
                    'weight' => $weight,
                ];
            }
            $totalRows += count($rows);
            $byType[$type] = [
                'rows' => count($rows),
                'completeness_percent' => $totalSlots > 0 ? (int)round($filledSlots / $totalSlots * 100) : 100,
                'missing_by_field' => $missingByField,
                'rows_with_gaps' => $rowsWithGaps,
            ];
        }

        usort($worst, static function (array $a, array $b): int {
            return $b['weight'] <=> $a['weight'] ?: strcmp($a['type'] . $a['id'], $b['type'] . $b['id']);
        });

        return [
            'ok' => true,
            'message' => '已体检本页 ' . $totalRows . ' 条内容，' . count($worst) . ' 条存在有效 SEO 输出缺口',
            'data' => [
                'scanned' => $totalRows,
                'status' => $status,
                'truncated' => $hasMore,
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'next_page' => $hasMore ? $page + 1 : null,
                ],
                'fields' => array_keys(self::EFFECTIVE_FIELDS),
                'by_type' => $byType,
                'worst' => array_slice($worst, 0, $limit),
            ],
        ];
    }

    private static function effectiveValues(string $type, array $row): array
    {
        $title = self::firstNonEmpty($row, ['seo_title', 'title']);
        $recordDescription = self::firstNonEmpty($row, ['seo_description', 'summary']);
        $description = $recordDescription;
        if ($description === '' && (string)($row['template'] ?? 'system') !== 'fullwidth'
            && function_exists('frontend_site_description')) {
            $description = trim((string)frontend_site_description());
        }
        $canonical = trim((string)($row['canonical_url'] ?? ''));
        if ($canonical === '' && class_exists(\App\Core\SeoPresenter::class)) {
            try {
                $canonical = \App\Core\SeoPresenter::canonical($type, $row);
            } catch (\Throwable $_) {
                $canonical = '';
            }
        }
        return [
            'title' => $title,
            'description' => $description,
            'og_title' => self::firstNonEmpty($row, ['og_title']) ?: $title,
            'og_description' => self::firstNonEmpty($row, ['og_description']) ?: $recordDescription,
            'og_image' => self::firstNonEmpty($row, ['og_image', 'cover_image']),
            'canonical' => $canonical,
        ];
    }

    private static function firstNonEmpty(array $row, array $fields): string
    {
        foreach ($fields as $field) {
            $value = trim((string)($row[$field] ?? ''));
            if ($value !== '') return $value;
        }
        return '';
    }

    private static function statusName(int $status): string
    {
        $name = array_search($status, self::STATUS_MAP, true);
        return is_string($name) ? $name : 'unknown';
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
