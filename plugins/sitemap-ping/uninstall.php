<?php
/**
 * sitemap-ping 卸载清理：DROP 日志表并删掉设置键。
 */
try {
    \App\Core\Database::pdo()->exec("DROP TABLE IF EXISTS `plugin_sitemap_pings`");
} catch (\Throwable $_) {}

foreach (['enabled', 'sitemap_url', 'endpoints', 'throttle_minutes', 'last_ping_at'] as $key) {
    try {
        \App\Core\Database::table('settings')->where('key', 'plugin.sitemap-ping.' . $key)->delete();
    } catch (\Throwable $_) {}
}
