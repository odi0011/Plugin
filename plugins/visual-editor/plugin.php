<?php
/**
 * 可视化编辑器（visual-editor）1.2.0
 *
 * 给核心内容编辑器（文章 / 产品 / 页面 / 自定义内容）追加一种全屏可视化编辑模式，
 * 与原有的富文本 / 代码两个标签并存——那两个标签的行为一个字节都不改。
 *
 * 存储分两处，这是 1.2.0 的核心变化（见 src/Content.php 与 src/Store.php 顶部说明）：
 *   - 核心内容字段：只写渲染产物（HTML + 作用域 CSS），停用 / 卸载后页面照常显示；
 *   - 插件存储（STORAGE_PATH/visual-editor/*.json）：编辑树 + 首次接管前的原文备份。
 * 插件不建任何数据表。
 *
 * 接线（全部是核心 7i 的内容编辑器钩子，核心代码零改动）：
 *   - admin.content_editor.modes：声明「可视化」模式（按钮由前端移到「AI 编辑」之后）；
 *   - admin.content_editor.panel：输出编辑面板；
 *   - admin.head / admin.footer：仅在四个内容表单页注入面板资产；
 *   - routes.admin.register：convert / save / persist / restore 四个后台端点（会话 + CSRF）；
 *   - plugin.activated：注册断点与宽度设置默认值。
 *
 * 三面对等（AGENTS.md）：公开 API 与 Agent 动作由 plugin.json 的 api 段声明，
 * 一条声明同时派生路由、ApiDoc 契约与 Agent 动作。api 段刻意**只读**——
 * 写入内容字段的路径只有后台的 persist 端点，且它随核心 ContentWorkflow 入库
 * （权限、行锁、修订、审计一条不少）。
 */
if (!defined('CODE_SCHEMA_VERSION')) exit;

require_once __DIR__ . '/src/Schema.php';
require_once __DIR__ . '/src/Value.php';
require_once __DIR__ . '/src/Settings.php';
require_once __DIR__ . '/src/StyleCompiler.php';
require_once __DIR__ . '/src/Renderer.php';
require_once __DIR__ . '/src/Content.php';
require_once __DIR__ . '/src/Store.php';
require_once __DIR__ . '/src/Panel.php';
require_once __DIR__ . '/src/Ai.php';
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
    $router->post('/admin/visual-editor/save', static function (): void {
        VisualEditorAdminTransport::save();
    });
    $router->post('/admin/visual-editor/persist', static function (): void {
        VisualEditorAdminTransport::persist();
    });
    $router->post('/admin/visual-editor/restore', static function (): void {
        VisualEditorAdminTransport::restore();
    });
    // 打开编辑台之前的自动预检：只有确实会退化成大块原样 HTML 才弹 AI 那一问。
    $router->post('/admin/visual-editor/inspect', static function (): void {
        VisualEditorAdminTransport::inspect();
    });
    // 两条 AI 路都是 SSE，都只回预览、不入库。
    $router->post('/admin/visual-editor/ai-convert', static function (): void {
        VisualEditorAdminTransport::aiConvert();
    });
    $router->post('/admin/visual-editor/ai-arrange', static function (): void {
        VisualEditorAdminTransport::aiArrange();
    });
});
