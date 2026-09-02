<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$source = (string)file_get_contents($root . '/AgentActions.php');
$plugin = (string)file_get_contents($root . '/plugin.php');
$manifest = json_decode((string)file_get_contents($root . '/plugin.json'), true, 32, JSON_THROW_ON_ERROR);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$assert(($manifest['version'] ?? '') === '1.1.0', 'version was not advanced');
$assert(($manifest['requires_core'] ?? '') === '7k', 'loadable plugin resources require the 7k core catalog contract');
$assert(($manifest['agent']['actions'][0]['params'] ?? []) === ['limit', 'types', 'page', 'per_page'],
    'manifest pagination parameters drifted');
$assert(str_contains($plugin, "'params' => ['limit', 'types', 'page', 'per_page']"),
    'runtime action parameters drifted');
$assert(str_contains($source, "'page' => ['table' => 'pages', 'content' => 'html']"),
    'page audit still reads the deprecated content column');
$assert(str_contains($source, "->where('status', 1)"), 'published status is not numeric');
$assert(str_contains($source, "->offset(\$offset)") && str_contains($source, "'next_page'"),
    'audit results cannot be paged');
$assert(str_contains($source, "'content_entries' => 'content_entry'"),
    'custom public content cannot satisfy an internal link');
$assert(str_contains($source, "'id' => self::rowId(\$row)")
    && str_contains($source, "'type' => \$resourceType")
    && str_contains($source, "'title' => (string)\$row['source_title']"),
    'resource search rows do not expose a stable loadable id/type/title contract');
$assert(str_contains($source, 'loadByStableId')
    && str_contains($source, "preg_match('/^(page|article):"),
    'resource loading still depends on the first search page instead of the stable id');
$assert(str_contains($source, "'slug' => '(?<slug>")
    && str_contains($source, "'id' => '(?<id>"),
    'route patterns do not expose identity captures for exact content lookup');
$assert(str_contains($source, "\$report['has_more'] || \$report['truncated']")
    && str_contains($source, 'isKnownPublicTarget')
    && str_contains($source, 'resolveFromDatabase'),
    'link audit truncation and known public routes are not represented in the continuation contract');
$readme = (string)file_get_contents($root . '/README.md');
$assert(str_contains($readme, '普通后台 UI 和 `/api/v1` 公共路由不适用'),
    'intentional Agent-only UI/API N/A decision is not documented');

echo "agent-link-auditor contract checks passed.\n";
