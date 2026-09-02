<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$source = (string)file_get_contents($root . '/AgentActions.php');
$plugin = (string)file_get_contents($root . '/plugin.php');
$manifest = json_decode((string)file_get_contents($root . '/plugin.json'), true, 32, JSON_THROW_ON_ERROR);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$params = ['type', 'status', 'page', 'per_page', 'limit'];
$assert(($manifest['version'] ?? '') === '1.1.0', 'version was not advanced');
$assert(($manifest['requires_core'] ?? '') === '7k', 'effective SEO fallback requires the 7k core contract');
$assert(($manifest['agent']['actions'][0]['params'] ?? []) === $params, 'manifest pagination parameters drifted');
$assert(str_contains($plugin, "'params' => ['type', 'status', 'page', 'per_page', 'limit']"),
    'runtime action parameters drifted');
$assert(str_contains($source, "'published' => 1") && str_contains($source, "->where('status', self::STATUS_MAP[\$status])"),
    'status filtering is not bound to the numeric workflow contract');
$assert(str_contains($source, "->offset(\$offset)") && str_contains($source, "'next_page'"),
    'snapshot cannot continue after a bounded page');
$assert(str_contains($source, "SeoPresenter::canonical")
    && str_contains($source, "['seo_title', 'title']")
    && str_contains($source, "['og_image', 'cover_image']"),
    'rendered fallback semantics are incomplete');
$assert(str_contains($source, "(string)(\$row['template'] ?? 'system') !== 'fullwidth'")
    && str_contains($source, 'frontend_site_description()')
    && str_contains($source, '$recordDescription'),
    'system/fullwidth description fallback semantics are not distinguished');
$assert(!str_contains($source, "'seo_keywords' =>"), 'obsolete meta keywords still lower the score');
$assert(str_contains($source, 'foreach ($types as $type)')
    && str_contains($source, 'Permission::userCan($user, $permission)')
    && str_contains($source, 'seo_snapshot_permission_denied'),
    'draft/scheduled/archived metadata is not intersected with content read permission');
$readme = (string)file_get_contents($root . '/README.md');
$assert(str_contains($readme, '普通后台 UI 和 `/api/v1` 公共路由不适用'),
    'intentional Agent-only UI/API N/A decision is not documented');

echo "agent-seo-snapshot contract checks passed.\n";
