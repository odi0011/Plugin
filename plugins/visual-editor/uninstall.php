<?php
/**
 * 卸载清理。
 *
 * 由 PluginManager::uninstall() require，此时插件代码尚未加载，
 * 因此这里不能依赖 src/ 里的类，表名要写字面量（与 migrations 一致）。
 *
 * 删表是不可逆的，但「卸载」这个动作本身就是用户明确要求清干净；
 * 只想停用请用「停用」，那不会碰数据。
 */
try {
    \App\Core\Database::pdo()->exec('DROP TABLE IF EXISTS `plugin_visual_revisions`');
    \App\Core\Database::pdo()->exec('DROP TABLE IF EXISTS `plugin_visual_documents`');
} catch (\Throwable $_) {
}

foreach (['url_prefix', 'revision_limit', 'breakpoint_tablet', 'breakpoint_mobile', 'container_max'] as $key) {
    try {
        \App\Core\Setting::forget('plugin.visual-editor.' . $key);
    } catch (\Throwable $_) {
    }
}
