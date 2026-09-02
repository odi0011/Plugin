<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$plugin = (string)file_get_contents($root . '/plugin.php');
$actions = (string)file_get_contents($root . '/SitemapPingActions.php');
$migration = (string)file_get_contents($root . '/migrations/002_indexnow_queue.sql');
$fencingMigration = (string)file_get_contents($root . '/migrations/003_fencing.sql');
$manifest = json_decode((string)file_get_contents($root . '/plugin.json'), true, 32, JSON_THROW_ON_ERROR);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$assert(($manifest['version'] ?? '') === '2.0.0', 'major replacement version is missing');
$manifestText = (string)file_get_contents($root . '/plugin.json');
$assert(!str_contains($plugin . $actions . $manifestText, 'bing.com/ping')
    && !str_contains($plugin . $actions . $manifestText, 'google.com/ping'),
    'retired anonymous sitemap-ping endpoint remains');
$assert(($manifest['settings']['permission'] ?? '') === 'sitemap_ping.manage', 'declarative settings are missing');
$assert(count($manifest['api'] ?? []) === 2 && count($manifest['schedules'] ?? []) === 1,
    'API/Agent/schedule parity declarations are incomplete');
$assert(str_contains($plugin, "(int)\$id") && !str_contains($plugin, "=== 'published'"),
    'content hook still compares against the wrong workflow status type');
$assert(str_contains($plugin, "add_action(\$type . '.deleted'")
    && str_contains($plugin, "sitemap_ping_enqueue_run(\$type . '.delete')"),
    'core page/article/product deletion events are not queued');
$assert(str_contains($actions, "get_plugin_setting('sitemap-ping', 'batch_limit'"),
    'periodic worker ignores the current declarative batch limit setting');
$assert(str_contains($actions, '\\App\\Models\\Page::find($id)')
    && str_contains($actions, '\\App\\Models\\ContentEntry::find($id)'),
    'IndexNow derives URLs from partial hook payloads instead of re-reading the committed record');
$ui = (string)file_get_contents($root . '/views/index.php');
$assert(str_contains($plugin, "admin.menu.register")
    && str_contains($plugin, "routes.admin.register")
    && str_contains($plugin, "admin/sitemap-ping/submit")
    && str_contains($ui, '立即加入队列'),
    'IndexNow lacks a normal admin status and enqueue UI');
$assert(str_contains($actions, 'SitemapController') && str_contains($actions, 'queueDiff($before, $after)'),
    'worker does not rebuild sitemap before deriving URL submissions');
$assert(str_contains($actions, 'OutboundHttpClient::postJson')
    && str_contains($actions, "in_array(\$status, [200, 202], true)"),
    'IndexNow transport/status contract is incomplete');
$assert(str_contains($actions, 'Security::decryptApiKey')
    && str_contains($plugin, 'Security::encryptApiKey')
    && str_contains($plugin, 'Security::decryptApiKey($existing)')
    && str_contains($plugin, 'sitemap_ping_ensure_indexnow_key();')
    && !str_contains($manifestText, 'indexnow_key_envelope'),
    'IndexNow key is not encrypted or is exposed through declarative settings');
$assert(str_contains($actions, "'failed' => 0") && str_contains($actions, 'available_at'),
    'retryable queue state is missing');
$assert(str_contains($migration, '`claim_token` CHAR(64)')
    && str_contains($actions, "where('claim_token', \$claimToken)")
    && str_contains($actions, "'claim_token' => null"),
    'submission lease is missing a fencing token');
$assert(str_contains($fencingMigration, 'ADD COLUMN `claim_token`')
    && str_contains($actions, 'fitRowsToRequest')
    && str_contains($actions, 'MAX_REQUEST_BYTES = 900000')
    && str_contains($actions, 'MAX_BATCHES_PER_RUN = 10')
    && str_contains($actions, 'queue_overflow'),
    'IndexNow queue lacks an upgrade migration or bounded request/overflow diagnostics');
$assert(str_contains($migration, '`{{prefix}}plugin_sitemap_submission_urls`')
    && ($manifest['requires_core'] ?? '') === '7k',
    'IndexNow migration is not bound to the core table-prefix contract');
$assert(str_contains($actions, "`attempts` < ?")
    && str_contains($actions, "`attempts` >= ?"),
    'terminal failures can still occupy pending queue capacity');

echo "sitemap-ping contract checks passed.\n";
