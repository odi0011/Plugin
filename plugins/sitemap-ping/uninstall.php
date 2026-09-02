<?php
/** Remove queue/log tables and every setting owned by the IndexNow plugin. */
foreach (['plugin_sitemap_submission_urls', 'plugin_sitemap_pings'] as $table) {
    try {
        $quoted = '`' . str_replace('`', '', \App\Core\Database::prefix() . $table) . '`';
        \App\Core\Database::pdo()->exec('DROP TABLE IF EXISTS ' . $quoted);
    } catch (\Throwable $_) {
    }
}

foreach (['enabled', 'batch_limit', 'indexnow_key_envelope', 'sitemap_url', 'endpoints', 'throttle_minutes', 'last_ping_at'] as $key) {
    try {
        \App\Core\Setting::forget('plugin.sitemap-ping.' . $key);
    } catch (\Throwable $_) {
    }
}
