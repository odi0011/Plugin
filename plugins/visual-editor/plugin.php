<?php
/**
 * 可视化编辑器（visual-editor）1.1.0
 *
 * 给核心内容编辑器（文章 / 产品 / 页面 / 自定义内容）追加第三种模式：
 * 富文本、代码之外的可视化拖拽编辑。没有自己的菜单、列表页、前台接管，
 * 也没有任何数据表——托管文档以自包含 HTML 的形态住在核心内容字段里
 * （见 src/Content.php 顶部说明），插件停用或卸载后内容不受影响。
 *
 * 接线（全部是核心 7i 的内容编辑器钩子）：
 *   - admin.content_editor.modes：声明「可视化」模式按钮；
 *   - admin.content_editor.panel：输出编辑面板；
 *   - admin.head / admin.footer：仅在四个内容表单页注入面板资产；
 *   - routes.admin.register：导入转换端点（会话 + CSRF，见 src/AdminTransport.php）；
 *   - plugin.activated：注册断点与宽度设置默认值。
 *
 * 三面对等（AGENTS.md）：公开 API 与 Agent 动作由 plugin.json 的 api 段声明，
 * 一条声明同时派生路由、ApiDoc 契约与 Agent 动作。api 段刻意**只读**——
 * 插件的写入路径只有内容表单提交本身，随核心 ContentWorkflow 入库。
 */
if (!defined('CODE_SCHEMA_VERSION')) exit;

require_once __DIR__ . '/src/Schema.php';
require_once __DIR__ . '/src/Value.php';
require_once __DIR__ . '/src/Settings.php';
require_once __DIR__ . '/src/StyleCompiler.php';
require_once __DIR__ . '/src/Renderer.php';
require_once __DIR__ . '/src/Content.php';
require_once __DIR__ . '/src/Panel.php';
require_once __DIR__ . '/src/AdminTransport.php';
require_once __DIR__ . '/src/Api.php';

add_action('plugin.activated', static function ($slug) {
    if ($slug !== 'visual-editor') return;
    register_plugin_setting('visual-editor', 'breakpoint_tablet', '1024');
    register_plugin_setting('visual-editor', 'breakpoint_mobile', '767');
    register_plugin_setting('visual-editor', 'container_max', '1200');
});

add_filter('admin.content_editor.modes', ['VisualEditorPanel', 'declareMode'], 10, 2);
add_action('admin.content_editor.panel', ['VisualEditorPanel', 'renderPanel']);
add_action('admin.head', ['VisualEditorPanel', 'headAssets']);
add_action('admin.footer', ['VisualEditorPanel', 'footerAssets']);

add_action('routes.admin.register', static function ($router) {
    $router->post('/admin/visual-editor/convert', static function (): void {
        VisualEditorAdminTransport::convert();
    });
});
