<?php
/**
 * Asynchronous IndexNow submission for the generated sitemap.
 *
 * The former unauthenticated sitemap-ping endpoint was removed by Bing and
 * Google. This plugin now queues URL changes, rebuilds the local sitemap in a
 * worker, submits bounded batches to IndexNow, and records only redacted
 * operational metadata.
 */
if (!defined('CODE_SCHEMA_VERSION')) exit;

require_once __DIR__ . '/SitemapPingActions.php';
require_once __DIR__ . '/SitemapPingApi.php';

add_filter('admin.menu.register', static function (array $items): array {
    if (!\App\Core\Auth::can('sitemap_ping.manage')) return $items;
    $items[] = [
        'url' => admin_url('/sitemap-ping'),
        'label' => 'IndexNow 提交',
        'icon' => 'bi-send-check',
        'perm' => 'sitemap_ping.manage',
    ];
    return $items;
});

add_action('routes.admin.register', static function ($router): void {
    $router->get('/admin/sitemap-ping', static function (): void {
        \App\Core\Auth::requirePermission('sitemap_ping.manage');
        echo plugin_view('sitemap-ping', 'index', [
            'status' => SitemapPingActions::status([], []),
        ]);
    });
    $router->post('/admin/sitemap-ping/submit', static function (): void {
        \App\Core\Auth::requirePermission('sitemap_ping.manage');
        $result = SitemapPingActions::requestSubmit([], []);
        if (!empty($result['ok'])) flash('success', 'IndexNow 已加入异步队列');
        else flash('error', (string)($result['message'] ?? '无法加入 IndexNow 队列'));
        header('Location: ' . admin_url('/sitemap-ping'));
        exit;
    });
});

if (!function_exists('sitemap_ping_log')) {
    function sitemap_ping_log(string $endpoint, string $triggerType, int $triggerId, bool $ok, string $message): void
    {
        try {
            \App\Core\Database::table('plugin_sitemap_pings')->insert([
                // Fixed provider origin only. No key or submitted URL is stored.
                'endpoint' => 'https://api.indexnow.org/indexnow',
                'trigger_type' => mb_substr($triggerType, 0, 40),
                'trigger_id' => max(0, $triggerId),
                'ok' => $ok ? 1 : 0,
                'message' => mb_substr((string)preg_replace('/[^A-Za-z0-9 ;=_-]+/', '_', $message), 0, 500),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $_) {
        }
    }
}

if (!function_exists('sitemap_ping_enqueue_run')) {
    function sitemap_ping_enqueue_run(string $reason = 'event'): ?int
    {
        try {
            $bucket = (int)floor(time() / 300);
            return \App\Core\AutomationScheduler::enqueue(
                'plugin.sitemap-ping.submit',
                'plugin.sitemap-ping.submit',
                ['batch_limit' => (int)get_plugin_setting('sitemap-ping', 'batch_limit', '100')],
                [
                    'dedupe_key' => 'indexnow:' . $bucket,
                    'max_attempts' => 5,
                ]
            );
        } catch (\Throwable $error) {
            if (function_exists('logger')) {
                \logger('[sitemap-ping] async run enqueue failed [' . $reason . ']', 'error');
            }
            return null;
        }
    }
}

if (!function_exists('sitemap_ping_ensure_indexnow_key')) {
    function sitemap_ping_ensure_indexnow_key(): void
    {
        try {
            $existing = (string)\App\Core\Setting::get('plugin.sitemap-ping.indexnow_key_envelope', '');
            if ($existing !== '' && class_exists(\App\Core\Security::class)) {
                try {
                    $decrypted = trim((string)\App\Core\Security::decryptApiKey($existing));
                    if (preg_match('/^[A-Za-z0-9-]{8,128}$/D', $decrypted) === 1) return;
                } catch (\Throwable $_) {
                    // Rotate an unreadable generated key; IndexNow accepts the
                    // new key as soon as the fixed key-location serves it.
                }
            }
            if (class_exists(\App\Core\Security::class)) {
                \App\Core\Setting::set(
                    'plugin.sitemap-ping.indexnow_key_envelope',
                    \App\Core\Security::encryptApiKey(bin2hex(random_bytes(16)))
                );
            }
        } catch (\Throwable $_) {
            // Status reports key_configured=false until a later load repairs it.
        }
    }
}

// Active in-place updates do not emit plugin.activated. Loading the updated
// plugin must therefore repair the generated key idempotently.
sitemap_ping_ensure_indexnow_key();

add_action('plugin.activated', static function ($slug): void {
    if ($slug !== 'sitemap-ping') return;
    register_plugin_setting('sitemap-ping', 'enabled', '0');
    register_plugin_setting('sitemap-ping', 'batch_limit', '100');
    register_plugin_setting('sitemap-ping', 'indexnow_key_envelope', '');
    foreach (['sitemap_url', 'endpoints', 'throttle_minutes', 'last_ping_at'] as $obsolete) {
        try {
            \App\Core\Setting::forget('plugin.sitemap-ping.' . $obsolete);
        } catch (\Throwable $_) {
        }
    }

    sitemap_ping_ensure_indexnow_key();
});

// IndexNow ownership proof. The key is intentionally served only at the
// fixed key-location path and is never included in logs, API results, or HTML.
add_action('app.before_dispatch', static function ($request): void {
    if (!is_object($request) || !method_exists($request, 'uri') || !method_exists($request, 'method')) return;
    if (!in_array(strtoupper((string)$request->method()), ['GET', 'HEAD'], true)
        || (string)$request->uri() !== '/indexnow-key.txt') return;
    $key = SitemapPingActions::indexNowKey();
    if ($key === '') return;
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: public, max-age=3600');
    header('X-Robots-Tag: noindex');
    if (strtoupper((string)$request->method()) !== 'HEAD') echo $key . "\n";
    exit;
});

// Content saves cover create/update and explicit unpublish transitions that
// pass through the existing controllers. Numeric workflow status is the
// canonical contract (1 = published); no string `published` comparison.
foreach (['page', 'article', 'product'] as $type) {
    add_action($type . '.after_save', static function ($id, $input, $isCreate) use ($type): void {
        if ((string)get_plugin_setting('sitemap-ping', 'enabled', '0') !== '1') return;
        SitemapPingActions::queueRecord($type, (int)$id, is_array($input) ? $input : []);
        sitemap_ping_enqueue_run($type . ($isCreate ? '.create' : '.update'));
    });
    add_action($type . '.deleted', static function ($record) use ($type): void {
        if ((string)get_plugin_setting('sitemap-ping', 'enabled', '0') !== '1') return;
        $record = is_array($record) ? $record : [];
        SitemapPingActions::queueRecord($type, (int)($record['id'] ?? 0), $record, true);
        sitemap_ping_enqueue_run($type . '.delete');
    });
}
unset($type);

// Custom content and taxonomy definition changes are covered explicitly;
// periodic sitemap diffing catches taxonomy term rename/delete and core delete
// paths that predate a dedicated delete hook.
add_action('content.entry.created', static function ($type, $id, $record): void {
    if ((string)get_plugin_setting('sitemap-ping', 'enabled', '0') !== '1') return;
    SitemapPingActions::queueRecord((string)$type, (int)$id, is_array($record) ? $record : []);
    sitemap_ping_enqueue_run('content.entry.created');
});
add_action('content.entry.updated', static function ($type, $id, $record): void {
    if ((string)get_plugin_setting('sitemap-ping', 'enabled', '0') !== '1') return;
    SitemapPingActions::queueRecord((string)$type, (int)$id, is_array($record) ? $record : []);
    sitemap_ping_enqueue_run('content.entry.updated');
});
add_action('content.entry.deleted', static function ($type, $record): void {
    if ((string)get_plugin_setting('sitemap-ping', 'enabled', '0') !== '1') return;
    SitemapPingActions::queueRecord((string)$type, (int)($record['id'] ?? 0), is_array($record) ? $record : [], true);
    sitemap_ping_enqueue_run('content.entry.deleted');
});
foreach (['content.category.created', 'content.category.updated', 'content.category.deleted'] as $taxonomyHook) {
    add_action($taxonomyHook, static function (): void {
        if ((string)get_plugin_setting('sitemap-ping', 'enabled', '0') === '1') sitemap_ping_enqueue_run('taxonomy');
    });
}
unset($taxonomyHook);

add_action('plugin.activated', static function ($slug): void {
    if ($slug !== 'sitemap-ping') return;
    // Declarative schedule synchronization is handled by PluginScheduleService;
    // this call makes a first run available without waiting for the next tick.
    if ((string)get_plugin_setting('sitemap-ping', 'enabled', '0') === '1') sitemap_ping_enqueue_run('activated');
});
