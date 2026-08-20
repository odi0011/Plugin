<?php
/**
 * Redirect Rules 插件
 *
 * 演示完整 CRUD 插件能力：
 *   - 自带 migration（建 plugin_redirects 表）
 *   - 注册菜单（admin.menu.register filter）
 *   - 注册路由（routes.admin.register action，闭包风格）
 *   - 渲染插件自己的视图（plugin_view）
 *   - 在 app.before_dispatch 钩子拦截请求做重定向
 *   - 注册自己的权限点（manifest.permissions）
 *   - uninstall.php 卸载时清理表
 */
if (!defined('CODE_SCHEMA_VERSION')) exit;

// ============ 注入菜单 ============
add_filter('admin.menu.register', function ($items) {
    if (!\App\Core\Auth::can('redirect.manage')) return $items;
    $items[] = [
        'url'   => admin_url('/redirect-rules'),
        'label' => '重定向规则',
        'icon'  => 'bi-arrow-return-right',
        'perm'  => 'redirect.manage',
    ];
    return $items;
});

// ============ 注册后台路由（闭包风格）============
add_action('routes.admin.register', function ($router) {

    // 列表
    $router->get('/admin/redirect-rules', function () {
        \App\Core\Auth::requirePermission('redirect.manage');
        $rules = \App\Core\Database::table('plugin_redirects')
            ->orderBy('id', 'desc')->get();
        echo plugin_view('redirect-rules', 'index', ['rules' => $rules]);
    });

    // 保存（新增 / 更新）
    $router->post('/admin/redirect-rules/save', function () {
        \App\Core\Auth::requirePermission('redirect.manage');
        $id = (int)($_POST['id'] ?? 0);
        try {
            \App\Core\RedirectRuleService::saveLegacy([
                'from_url' => (string)($_POST['from_url'] ?? ''),
                'to_url' => (string)($_POST['to_url'] ?? ''),
                'status_code' => (int)($_POST['status_code'] ?? 302),
                'enabled' => !empty($_POST['enabled']),
            ], $id > 0 ? $id : null);
            flash('success', $id > 0 ? '规则已更新' : '规则已创建');
        } catch (\Throwable $error) {
            flash('error', 'Redirect rule rejected: ' . $error->getMessage());
        }
        header('Location: ' . admin_url('/redirect-rules'));
        exit;
    });

    // 删除
    $router->post('/admin/redirect-rules/delete/{id}', function ($id) {
        \App\Core\Auth::requirePermission('redirect.manage');
        \App\Core\Database::table('plugin_redirects')->where('id', (int)$id)->delete();
        flash('success', '规则已删除');
        header('Location: ' . admin_url('/redirect-rules'));
        exit;
    });
});

// Runtime interception is centralized in RedirectRuleService::applyToRequest().
// That boundary protects both the configured admin path and /api before it
// consults core or legacy rows, while preserving existing plugin rules.
