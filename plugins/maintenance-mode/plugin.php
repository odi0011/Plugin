<?php
/**
 * 维护模式
 *
 * 在路由分发前拦截前台请求，渲染维护页并返回 503。
 * 刻意不拦：后台（含自定义后台前缀）、/api、已登录且有配置权限的人、白名单 IP。
 * 这样即使 IP 填错、也不会把自己关在门外。
 */
if (!defined('CODE_SCHEMA_VERSION')) exit;

add_action('plugin.activated', static function ($slug) {
    if ($slug !== 'maintenance-mode') return;
    register_plugin_setting('maintenance-mode', 'enabled', '0');
    register_plugin_setting('maintenance-mode', 'title', '站点维护中');
    register_plugin_setting('maintenance-mode', 'message', '我们正在进行例行维护，请稍后再访问。');
    register_plugin_setting('maintenance-mode', 'eta', '');
    register_plugin_setting('maintenance-mode', 'allow_ips', '');
    register_plugin_setting('maintenance-mode', 'retry_after', '3600');
});

if (!function_exists('maintenance_mode_allowed_ips')) {
    /** @return string[] */
    function maintenance_mode_allowed_ips(): array
    {
        $raw = (string)get_plugin_setting('maintenance-mode', 'allow_ips', '');
        $out = [];
        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $ip) {
            $ip = trim((string)$ip);
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) $out[] = $ip;
        }
        return $out;
    }
}

if (!function_exists('maintenance_mode_should_block')) {
    function maintenance_mode_should_block(string $uri, string $clientIp): bool
    {
        if ((string)get_plugin_setting('maintenance-mode', 'enabled', '0') !== '1') return false;

        $path = '/' . ltrim((string)parse_url($uri, PHP_URL_PATH), '/');
        // 后台前缀可能被站点改过，用运行时的实际前缀判断。
        $adminPrefix = function_exists('admin_prefix') ? trim(admin_prefix(), '/') : 'admin';
        if ($adminPrefix !== '' && ($path === '/' . $adminPrefix || str_starts_with($path, '/' . $adminPrefix . '/'))) {
            return false;
        }
        // API 与健康检查不能被维护页顶掉，否则监控与集成方会误判成故障。
        if ($path === '/api' || str_starts_with($path, '/api/')) return false;
        if (str_starts_with($path, '/healthz') || str_starts_with($path, '/health')) return false;
        // 插件静态资源要放行，否则维护页自己的样式也加载不出来。
        if (str_starts_with($path, '/plugin-asset/')) return false;

        // 能配置维护模式的人自己访问前台时不拦，方便边维护边验收。
        try {
            if (\App\Core\Auth::check() && \App\Core\Auth::can('maintenance.config')) return false;
        } catch (\Throwable $_) {
        }

        return !in_array($clientIp, maintenance_mode_allowed_ips(), true);
    }
}

add_action('app.before_dispatch', static function ($request) {
    try {
        $uri = is_object($request) && method_exists($request, 'uri') ? (string)$request->uri() : (string)($_SERVER['REQUEST_URI'] ?? '/');
        $ip = is_object($request) && method_exists($request, 'ip') ? (string)$request->ip() : (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if (!maintenance_mode_should_block($uri, $ip)) return;

        $retryAfter = max(60, min(86400, (int)get_plugin_setting('maintenance-mode', 'retry_after', '3600')));
        if (!headers_sent()) {
            http_response_code(503);
            header('Retry-After: ' . $retryAfter);
            header('Cache-Control: no-store');
            header('Content-Type: text/html; charset=UTF-8');
        }
        echo plugin_view('maintenance-mode', 'maintenance', [
            'title' => (string)get_plugin_setting('maintenance-mode', 'title', '站点维护中'),
            'message' => (string)get_plugin_setting('maintenance-mode', 'message', ''),
            'eta' => (string)get_plugin_setting('maintenance-mode', 'eta', ''),
        ]);
        exit;
    } catch (\Throwable $error) {
        // 维护模式自己出错绝不能把站点带下去：记日志后放行。
        if (function_exists('logger')) {
            \logger('[maintenance-mode] 拦截逻辑异常，已放行本次请求：' . $error->getMessage(), 'error');
        }
    }
}, 1);

add_filter('admin.menu.register', static function ($items) {
    if (!\App\Core\Auth::can('maintenance.config')) return $items;
    $enabled = (string)get_plugin_setting('maintenance-mode', 'enabled', '0') === '1';
    $items[] = [
        'url' => admin_url('/maintenance-mode/settings'),
        'label' => $enabled ? '维护模式（已开启）' : '维护模式',
        'icon' => 'bi-cone-striped',
        'perm' => 'maintenance.config',
    ];
    return $items;
});

add_action('routes.admin.register', static function ($router) {
    $router->get('/admin/maintenance-mode/settings', static function () {
        \App\Core\Auth::requirePermission('maintenance.config');
        echo plugin_view('maintenance-mode', 'settings', [
            'enabled' => (string)get_plugin_setting('maintenance-mode', 'enabled', '0') === '1',
            'title' => (string)get_plugin_setting('maintenance-mode', 'title', '站点维护中'),
            'message' => (string)get_plugin_setting('maintenance-mode', 'message', ''),
            'eta' => (string)get_plugin_setting('maintenance-mode', 'eta', ''),
            'allowIps' => (string)get_plugin_setting('maintenance-mode', 'allow_ips', ''),
            'retryAfter' => (string)get_plugin_setting('maintenance-mode', 'retry_after', '3600'),
            'currentIp' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        ]);
    });

    $router->post('/admin/maintenance-mode/settings', static function () {
        \App\Core\Auth::requirePermission('maintenance.config');

        $title = trim((string)($_POST['title'] ?? ''));
        $message = trim((string)($_POST['message'] ?? ''));
        $eta = trim((string)($_POST['eta'] ?? ''));
        $allowIpsRaw = trim((string)($_POST['allow_ips'] ?? ''));
        $retryAfter = (int)($_POST['retry_after'] ?? 3600);

        $invalid = [];
        foreach (preg_split('/[\s,;]+/', $allowIpsRaw) ?: [] as $ip) {
            $ip = trim((string)$ip);
            if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP)) $invalid[] = $ip;
        }

        if (mb_strlen($title) > 120 || mb_strlen($message) > 2000 || mb_strlen($eta) > 120) {
            flash('error', '标题 / 说明 / 预计恢复时间超长');
        } elseif ($invalid !== []) {
            flash('error', '以下放行 IP 不合法：' . implode('、', array_slice($invalid, 0, 5)));
        } else {
            set_plugin_setting('maintenance-mode', 'enabled', ($_POST['enabled'] ?? '') === '1' ? '1' : '0');
            set_plugin_setting('maintenance-mode', 'title', $title !== '' ? $title : '站点维护中');
            set_plugin_setting('maintenance-mode', 'message', $message);
            set_plugin_setting('maintenance-mode', 'eta', $eta);
            set_plugin_setting('maintenance-mode', 'allow_ips', $allowIpsRaw);
            set_plugin_setting('maintenance-mode', 'retry_after', (string)max(60, min(86400, $retryAfter)));
            flash('success', '已保存');
        }

        header('Location: ' . admin_url('/maintenance-mode/settings'));
        exit;
    });
});
