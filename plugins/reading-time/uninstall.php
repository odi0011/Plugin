<?php
/**
 * reading-time 卸载清理：删掉本插件的设置键。
 */
foreach (['wpm', 'show_article', 'show_page', 'min_words'] as $key) {
    try {
        \App\Core\Database::table('settings')->where('key', 'plugin.reading-time.' . $key)->delete();
    } catch (\Throwable $_) {}
}
