<?php
/**
 * 卸载清理。
 *
 * 由 PluginManager::uninstall() require，此时插件代码尚未加载，
 * 因此这里不能依赖 src/ 里的类，表名与目录名要写字面量。
 *
 * 刻意**不碰**任何内容字段：1.2.0 起字段里只有普通 HTML 与一段作用域 CSS
 * （外层有 <!-- ve:managed --> 标记），插件卸载后页面照常渲染。
 * 要清的是插件自己的东西：1.0.0 的两张历史表、设置项，以及
 * STORAGE_PATH/visual-editor 下的编辑树与原文备份。
 *
 * 删表与删目录都不可逆，但「卸载」这个动作本身就是用户明确要求清干净；
 * 只想停用请用「停用」，那不会碰任何数据。
 */
try {
    \App\Core\Database::pdo()->exec('DROP TABLE IF EXISTS `plugin_visual_revisions`');
    \App\Core\Database::pdo()->exec('DROP TABLE IF EXISTS `plugin_visual_documents`');
} catch (\Throwable $_) {
}

// 编辑树与原文备份。原文备份也一并删除：卸载前用户随时可以「还原原文」，
// 卸载之后留着一份内容副本在磁盘上反而是意料之外的残留。
if (defined('STORAGE_PATH')) {
    $dir = rtrim(STORAGE_PATH, '/\\') . DIRECTORY_SEPARATOR . 'visual-editor';
    if (is_dir($dir)) {
        foreach ((array)glob($dir . DIRECTORY_SEPARATOR . '*.{json,tmp}', GLOB_BRACE) as $file) {
            if (is_file($file)) @unlink($file);
        }
        foreach (['.htaccess', 'index.html'] as $guard) {
            $path = $dir . DIRECTORY_SEPARATOR . $guard;
            if (is_file($path)) @unlink($path);
        }
        @rmdir($dir);
    }
}

foreach (['breakpoint_tablet', 'breakpoint_mobile', 'container_max', 'url_prefix', 'revision_limit'] as $key) {
    try {
        \App\Core\Setting::forget('plugin.visual-editor.' . $key);
    } catch (\Throwable $_) {
    }
}
