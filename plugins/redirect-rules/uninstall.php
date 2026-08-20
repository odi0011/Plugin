<?php
/**
 * uninstall.php - 卸载时清理自己的表
 */
try {
    \App\Core\Database::pdo()->exec("DROP TABLE IF EXISTS `plugin_redirects`");
} catch (\Throwable $_) {}
