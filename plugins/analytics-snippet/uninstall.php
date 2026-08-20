<?php
/**
 * uninstall.php - 卸载时清理设置项
 */
try {
    \App\Core\Database::table('settings')->where('key', 'plugin.analytics-snippet.ga_id')->delete();
} catch (\Throwable $_) {}
