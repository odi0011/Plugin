<?php
/**
 * agent-seo-snapshot 的执行器。只读，不写任何表。
 */
final class AgentSeoSnapshotActions
{
    private const TYPE_TABLES = [
        'page' => 'pages',
        'article' => 'articles',
        'product' => 'products',
    ];

    /** 参与体检的 SEO 字段及其权重（权重只用于排序「缺得最狠」的条目）。 */
    private const SEO_FIELDS = [
        'seo_title' => 3,
        'seo_description' => 3,
        'seo_keywords' => 1,
        'og_title' => 1,
        'og_description' => 1,
        'og_image' => 1,
        'canonical_url' => 1,
    ];

    private const MAX_ROWS_PER_TYPE = 500;

    public static function snapshot(array $input, array $context): array
    {
        $types = self::normalizeTypes($input['type'] ?? null);
        if ($types === []) {
            return [
                'ok' => false,
                'message' => 'type 只接受 page / article / product，可传数组或逗号分隔字符串',
                'error_code' => 'seo_snapshot_bad_type',
                'http_status' => 422,
            ];
        }
        $limit = max(1, min(50, (int)($input['limit'] ?? 10)));

        $byType = [];
        $worst = [];
        $totalRows = 0;
        $truncated = false;

        foreach ($types as $type) {
            try {
                $rows = \App\Core\Database::table(self::TYPE_TABLES[$type])
                    ->select(array_merge(['id', 'title', 'slug', 'status'], array_keys(self::SEO_FIELDS)))
                    ->orderBy('id', 'desc')
                    ->limit(self::MAX_ROWS_PER_TYPE)
                    ->get();
            } catch (\Throwable $error) {
                return [
                    'ok' => false,
                    'message' => '读取 ' . $type . ' 失败：' . $error->getMessage(),
                    'error_code' => 'seo_snapshot_read_failed',
                    'http_status' => 500,
                ];
            }
            if (count($rows) >= self::MAX_ROWS_PER_TYPE) $truncated = true;

            $missingByField = array_fill_keys(array_keys(self::SEO_FIELDS), 0);
            $filledSlots = 0;
            $totalSlots = 0;
            foreach ($rows as $row) {
                $missing = [];
                $weight = 0;
                foreach (self::SEO_FIELDS as $field => $fieldWeight) {
                    $totalSlots++;
                    if (trim((string)($row[$field] ?? '')) !== '') {
                        $filledSlots++;
                        continue;
                    }
                    $missingByField[$field]++;
                    $missing[] = $field;
                    $weight += $fieldWeight;
                }
                if ($missing !== []) {
                    $worst[] = [
                        'type' => $type,
                        'id' => (int)($row['id'] ?? 0),
                        'title' => (string)($row['title'] ?? ''),
                        'slug' => (string)($row['slug'] ?? ''),
                        'status' => (string)($row['status'] ?? ''),
                        'missing' => $missing,
                        'weight' => $weight,
                    ];
                }
            }
            $totalRows += count($rows);
            $byType[$type] = [
                'rows' => count($rows),
                'completeness_percent' => $totalSlots > 0 ? (int)round($filledSlots / $totalSlots * 100) : 100,
                'missing_by_field' => $missingByField,
                'rows_with_gaps' => count(array_filter($worst, static fn (array $r): bool => $r['type'] === $type)),
            ];
        }

        usort($worst, static function (array $a, array $b): int {
            return $b['weight'] <=> $a['weight'] ?: strcmp($a['type'] . $a['id'], $b['type'] . $b['id']);
        });

        return [
            'ok' => true,
            'message' => '已体检 ' . $totalRows . ' 条内容，' . count($worst) . ' 条存在 SEO 字段缺失',
            'data' => [
                'scanned' => $totalRows,
                'truncated' => $truncated,
                'fields' => array_keys(self::SEO_FIELDS),
                'by_type' => $byType,
                'worst' => array_slice($worst, 0, $limit),
            ],
        ];
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
