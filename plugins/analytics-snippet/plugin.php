<?php
/**
 * Analytics Snippet 插件
 *
 * 演示：
 *   - 用 register_plugin_setting 注册默认值
 *   - 用 admin.menu.register filter 注入侧边栏菜单
 *   - 用 routes.admin.register action 注册设置页路由（闭包风格）
 *   - 用 frontend.head action 注入第三方 JS
 */
if (!defined('CODE_SCHEMA_VERSION')) exit;

// 激活时注册默认值
add_action('plugin.activated', function ($slug) {
    if ($slug !== 'analytics-snippet') return;
    register_plugin_setting('analytics-snippet', 'ga_id', '');
});

// 注入侧边栏菜单（如果有权限）
add_filter('admin.menu.register', function ($items) {
    if (!\App\Core\Auth::can('analytics.config')) return $items;
    $items[] = [
        'url'   => admin_url('/analytics-snippet/settings'),
        'label' => '站点统计',
        'icon'  => 'bi-graph-up',
        'perm'  => 'analytics.config',
    ];
    return $items;
});

// 注册后台设置页（闭包路由）
add_action('routes.admin.register', function ($router) {
    $router->get('/admin/analytics-snippet/settings', function () {
        \App\Core\Auth::requirePermission('analytics.config');
        echo plugin_view('analytics-snippet', 'settings', [
            'gaId' => get_plugin_setting('analytics-snippet', 'ga_id', ''),
        ]);
    });
    $router->post('/admin/analytics-snippet/settings', function () {
        \App\Core\Auth::requirePermission('analytics.config');
        $id = trim((string)($_POST['ga_id'] ?? ''));
        // 简单格式校验：仅允许 G-XXX / UA-XXX / 字母数字 - _
        if ($id !== '' && !preg_match('/^[A-Za-z0-9_\-]+$/', $id)) {
            flash('error', 'Tracking ID 格式不合法');
        } else {
            set_plugin_setting('analytics-snippet', 'ga_id', $id);
            flash('success', '已保存');
        }
        header('Location: ' . admin_url('/analytics-snippet/settings'));
        exit;
    });
});

// 前台 head 注入 GA snippet（仅当配置了 ID）
add_action('frontend.head', function () {
    $gaId = get_plugin_setting('analytics-snippet', 'ga_id', '');
    if ($gaId === '') return;
    $gaIdSafe = htmlspecialchars($gaId, ENT_QUOTES, 'UTF-8');
    echo <<<HTML
    <!-- Google Analytics (analytics-snippet plugin) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id={$gaIdSafe}"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', '{$gaIdSafe}');
    </script>
    <!-- End Google Analytics -->

HTML;
});
