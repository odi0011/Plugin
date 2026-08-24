<?php
/**
 * 可视化编辑器（visual-editor）
 *
 * 拖拽式页面搭建器。页面不是一段 HTML，而是「区块 → 栏 → 控件」的结构化文档；
 * 样式是受白名单约束的键值对，由服务端编译成 CSS。这条取向决定了一件事：
 * **没有任何用户路径能把原始 CSS 或 JS 存进页面**，最坏情况是元素不渲染。
 *
 * 接线说明：
 *   - app.before_dispatch：已发布文档接管根级 URL（核心内容同名时核心优先）；
 *   - routes.frontend.register：草稿预览（要登录 + 查看权限）；
 *   - routes.admin.register：列表页、编辑器、以及编辑器自己的保存端点；
 *   - admin.menu.register：后台菜单入口；
 *   - plugin.activated：写入设置默认值。
 *
 * 三面对等（AGENTS.md）：公开 API 与 Agent 动作由 plugin.json 的 api 段声明，
 * 一条声明同时派生路由、ApiDoc 契约与 Agent 动作，执行器统一是 VisualEditorApi。
 * 整棵树的批量保存只在后台编辑器这一面，原因写在 src/Api.php 顶部。
 */
if (!defined('CODE_SCHEMA_VERSION')) exit;

require_once __DIR__ . '/src/Schema.php';
require_once __DIR__ . '/src/Value.php';
require_once __DIR__ . '/src/Settings.php';
require_once __DIR__ . '/src/StyleCompiler.php';
require_once __DIR__ . '/src/Renderer.php';
require_once __DIR__ . '/src/Document.php';
require_once __DIR__ . '/src/Routing.php';
require_once __DIR__ . '/src/Frontend.php';
require_once __DIR__ . '/src/Admin.php';
require_once __DIR__ . '/src/Api.php';

add_action('plugin.activated', static function ($slug) {
    if ($slug !== 'visual-editor') return;
    register_plugin_setting('visual-editor', 'url_prefix', '');
    register_plugin_setting('visual-editor', 'revision_limit', '20');
    register_plugin_setting('visual-editor', 'breakpoint_tablet', '1024');
    register_plugin_setting('visual-editor', 'breakpoint_mobile', '767');
    register_plugin_setting('visual-editor', 'container_max', '1200');
});

/**
 * 前台接管。优先级放在维护模式（1）之后：站点在维护中时，
 * 维护页应当先赢，而不是被可视化文档顶掉。
 */
add_action('app.before_dispatch', static function ($request) {
    try {
        $method = is_object($request) && method_exists($request, 'method')
            ? (string)$request->method()
            : (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = is_object($request) && method_exists($request, 'uri')
            ? (string)$request->uri()
            : (string)($_SERVER['REQUEST_URI'] ?? '/');

        $row = VisualEditorRouting::documentForRequest($method, $uri);
        if ($row === null) return;
        VisualEditorFrontend::takeOver($row);
    } catch (\Throwable $error) {
        // 判定逻辑本身出错也必须放行：接管是增量能力，不能成为单点故障。
        if (function_exists('logger')) {
            \logger('[visual-editor] 接管判定异常，已放行本次请求：' . $error->getMessage(), 'error');
        }
    }
}, 20);

add_action('routes.frontend.register', static function ($router) {
    $router->get('/visual-editor-preview/{id}', static fn (string $id): string => VisualEditorAdmin::preview($id));
});

add_action('routes.admin.register', static function ($router) {
    $router->get('/admin/visual-editor', static fn (): string => VisualEditorAdmin::index());
    $router->get('/admin/visual-editor/edit/{id}', static fn (string $id): string => VisualEditorAdmin::edit($id));
    $router->post('/admin/visual-editor/create', static function (): void { VisualEditorAdmin::store(); });
    $router->post('/admin/visual-editor/render/{id}', static function (string $id): void { VisualEditorAdmin::renderPreview($id); });
    $router->post('/admin/visual-editor/save/{id}', static function (string $id): void { VisualEditorAdmin::save($id); });
    $router->post('/admin/visual-editor/meta/{id}', static function (string $id): void { VisualEditorAdmin::meta($id); });
    $router->post('/admin/visual-editor/status/{id}', static function (string $id): void { VisualEditorAdmin::status($id); });
    $router->post('/admin/visual-editor/rollback/{id}', static function (string $id): void { VisualEditorAdmin::rollback($id); });
    $router->post('/admin/visual-editor/delete/{id}', static function (string $id): void { VisualEditorAdmin::destroy($id); });
});

add_filter('admin.menu.register', static function ($items) {
    if (!\App\Core\Auth::can('visual_editor.view')) return $items;
    $items[] = [
        'url' => admin_url('/visual-editor'),
        'label' => '可视化编辑器',
        'icon' => 'bi-columns-gap',
        'perm' => 'visual_editor.view',
    ];
    return $items;
});
