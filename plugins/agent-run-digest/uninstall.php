<?php
/**
 * agent-run-digest 卸载清理。
 * 本文件在插件自身代码未加载的情况下被 require，不能依赖 plugin.php 里定义的东西。
 */
try {
    $table = '`' . str_replace('`', '', \App\Core\Database::prefix() . 'plugin_agent_run_events') . '`';
    \App\Core\Database::pdo()->exec("DROP TABLE IF EXISTS {$table}");
} catch (\Throwable $_) {}
