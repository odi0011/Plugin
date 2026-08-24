<?php
/**
 * 卸载清理。
 *
 * 由 PluginManager::uninstall() require，此时插件代码尚未加载，
 * 因此这里不能依赖 src/ 里的类，表名要写字面量。
 *
 * 刻意**不碰**任何内容字段：可视化托管的内容本来就是普通 HTML +
 * 一段作用域 CSS + 一段不执行的 JSON（外层有 <!-- ve:managed --> 标记），
 * 插件卸载后页面照常渲染。1.0.0 的两张文档表在这里一并清掉——
 * 那是新版本不再读取的历史遗留。
 *
 * 删表是不可逆的，但「卸载」这个动作本身就是用户明确要求清干净；
 * 只想停用请用「停用」，那不会碰任何数据。
 */
try {
    \App\Core\Database::pdo()->exec('DROP TABLE IF EXISTS `plugin_visual_revisions`');
    \App\Core\Database::pdo()->exec('DROP TABLE IF EXISTS `plugin_visual_documents`');
} catch (\Throwable $_) {
}

foreach (['breakpoint_tablet', 'breakpoint_mobile', 'container_max', 'url_prefix', 'revision_limit'] as $key) {
    try {
        \App\Core\Setting::forget('plugin.visual-editor.' . $key);
    } catch (\Throwable $_) {
    }
}
