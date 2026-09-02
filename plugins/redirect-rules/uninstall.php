<?php
/**
 * uninstall.php - 卸载时清理自己的表
 */
try {
    $table = '`' . str_replace('`', '', \App\Core\Database::prefix() . 'plugin_redirects') . '`';
    \App\Core\Database::pdo()->exec("DROP TABLE IF EXISTS {$table}");
} catch (\Throwable $_) {}
