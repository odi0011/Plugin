<?php
/**
 * Hello World 插件
 *
 * 最简插件示例：演示如何用 add_action 注册钩子回调。
 */
if (!defined('CODE_SCHEMA_VERSION')) exit;

add_action('article.show.after', function ($article) {
    echo '<div class="alert alert-info my-4" style="border-left:4px solid #0d6efd;">'
       . '<i class="bi bi-stars"></i> <strong>Hello from plugin!</strong> '
       . '本横幅由 <code>hello-world</code> 插件注入到 <code>article.show.after</code> 钩子。'
       . '</div>';
});
