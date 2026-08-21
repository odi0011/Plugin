<?php
/**
 * 站点地图推送
 *
 * 内容发布后把 sitemap 地址推送给搜索引擎，并记录每次推送结果。
 * 外发一律走 PluginManager::httpFetchRaw()：强制 HTTPS、禁重定向、公网 IP 校验。
 */
if (!defined('CODE_SCHEMA_VERSION')) exit;

add_action('plugin.activated', static function ($slug) {
    if ($slug !== 'sitemap-ping') return;
    register_plugin_setting('sitemap-ping', 'enabled', '0');
    register_plugin_setting('sitemap-ping', 'sitemap_url', '');
    register_plugin_setting('sitemap-ping', 'endpoints', "https://www.bing.com/ping?sitemap={SITEMAP}");
    register_plugin_setting('sitemap-ping', 'throttle_minutes', '30');
    register_plugin_setting('sitemap-ping', 'last_ping_at', '0');
});

if (!function_exists('sitemap_ping_sitemap_url')) {
    function sitemap_ping_sitemap_url(): string
    {
        $configured = trim((string)get_plugin_setting('sitemap-ping', 'sitemap_url', ''));
        if ($configured !== '') return $configured;
        return function_exists('url') ? (string)url('/sitemap.xml') : '';
    }
}

if (!function_exists('sitemap_ping_endpoints')) {
    /** @return string[] 已把 {SITEMAP} 占位符替换成实际地址的 HTTPS 端点 */
    function sitemap_ping_endpoints(): array
    {
        $sitemap = sitemap_ping_sitemap_url();
        if ($sitemap === '' || !preg_match('#^https://#i', $sitemap)) return [];

        $raw = (string)get_plugin_setting('sitemap-ping', 'endpoints', '');
        $out = [];
        foreach (preg_split('/[\r\n]+/', $raw) ?: [] as $line) {
            $line = trim((string)$line);
            if ($line === '' || !preg_match('#^https://#i', $line)) continue;
            $out[] = str_replace('{SITEMAP}', rawurlencode($sitemap), $line);
        }
        return array_slice(array_unique($out), 0, 5);
    }
}

if (!function_exists('sitemap_ping_dispatch')) {
    /**
     * 执行一轮推送。$force=true 时忽略节流（后台「立即推送」用）。
     * @return array{sent:int,skipped:string}
     */
    function sitemap_ping_dispatch(string $triggerType, int $triggerId, bool $force = false): array
    {
        if (!$force && (string)get_plugin_setting('sitemap-ping', 'enabled', '0') !== '1') {
            return ['sent' => 0, 'skipped' => 'disabled'];
        }
        $throttle = max(0, min(1440, (int)get_plugin_setting('sitemap-ping', 'throttle_minutes', '30')));
        $last = (int)get_plugin_setting('sitemap-ping', 'last_ping_at', '0');
        if (!$force && $throttle > 0 && $last > 0 && time() - $last < $throttle * 60) {
            return ['sent' => 0, 'skipped' => 'throttled'];
        }

        $endpoints = sitemap_ping_endpoints();
        if ($endpoints === []) return ['sent' => 0, 'skipped' => 'no_endpoint'];

        set_plugin_setting('sitemap-ping', 'last_ping_at', (string)time());
        $sent = 0;
        foreach ($endpoints as $endpoint) {
            $ok = false;
            $message = '';
            try {
                // 只用核心的安全外发通道：HTTPS + 禁重定向 + 公网 IP 校验（防 SSRF）。
                $result = \App\Core\PluginManager::httpFetchRaw($endpoint, 64 * 1024);
                $ok = !empty($result['ok']);
                $message = $ok ? 'HTTP 200' : (string)($result['error'] ?? '请求失败');
            } catch (\Throwable $error) {
                $message = $error->getMessage();
            }
            if ($ok) $sent++;
            sitemap_ping_log($endpoint, $triggerType, $triggerId, $ok, $message);
        }
        return ['sent' => $sent, 'skipped' => ''];
    }
}

if (!function_exists('sitemap_ping_log')) {
    function sitemap_ping_log(string $endpoint, string $triggerType, int $triggerId, bool $ok, string $message): void
    {
        try {
            \App\Core\Database::table('plugin_sitemap_pings')->insert([
                'endpoint' => mb_substr($endpoint, 0, 500),
                'trigger_type' => mb_substr($triggerType, 0, 40),
                'trigger_id' => max(0, $triggerId),
                'ok' => $ok ? 1 : 0,
                'message' => mb_substr($message, 0, 500),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            // 只留最近 200 条，5% 采样清理，避免日志无界增长。
            if (random_int(1, 20) === 1) {
                $keepFrom = \App\Core\Database::table('plugin_sitemap_pings')
                    ->select('id')->orderBy('id', 'desc')->limit(1)->offset(200)->get();
                $cutoff = (int)($keepFrom[0]['id'] ?? 0);
                if ($cutoff > 0) {
                    \App\Core\Database::table('plugin_sitemap_pings')->where('id', '<=', $cutoff)->delete();
                }
            }
        } catch (\Throwable $error) {
            if (function_exists('logger')) {
                \logger('[sitemap-ping] 日志写入失败：' . $error->getMessage(), 'error');
            }
        }
    }
}

foreach (['page', 'article', 'product'] as $sitemapPingType) {
    add_action($sitemapPingType . '.after_save', static function ($id, $input, $isCreate) use ($sitemapPingType) {
        // 只有真正发布出去的内容才值得通知搜索引擎。
        if (!is_array($input) || (string)($input['status'] ?? '') !== 'published') return;
        sitemap_ping_dispatch($sitemapPingType, (int)$id);
    });
}
unset($sitemapPingType);

add_filter('admin.menu.register', static function ($items) {
    if (!\App\Core\Auth::can('sitemap_ping.manage')) return $items;
    $items[] = [
        'url' => admin_url('/sitemap-ping'),
        'label' => '站点地图推送',
        'icon' => 'bi-send',
        'perm' => 'sitemap_ping.manage',
    ];
    return $items;
});

add_action('routes.admin.register', static function ($router) {
    $router->get('/admin/sitemap-ping', static function () {
        \App\Core\Auth::requirePermission('sitemap_ping.manage');
        $logs = [];
        try {
            $logs = \App\Core\Database::table('plugin_sitemap_pings')
                ->orderBy('id', 'desc')->limit(30)->get();
        } catch (\Throwable $_) {
            $logs = null;   // 表未迁移
        }
        echo plugin_view('sitemap-ping', 'index', [
            'enabled' => (string)get_plugin_setting('sitemap-ping', 'enabled', '0') === '1',
            'sitemapUrl' => (string)get_plugin_setting('sitemap-ping', 'sitemap_url', ''),
            'resolvedSitemapUrl' => sitemap_ping_sitemap_url(),
            'endpoints' => (string)get_plugin_setting('sitemap-ping', 'endpoints', ''),
            'throttleMinutes' => (string)get_plugin_setting('sitemap-ping', 'throttle_minutes', '30'),
            'lastPingAt' => (int)get_plugin_setting('sitemap-ping', 'last_ping_at', '0'),
            'logs' => $logs,
        ]);
    });

    $router->post('/admin/sitemap-ping/settings', static function () {
        \App\Core\Auth::requirePermission('sitemap_ping.manage');
        $sitemapUrl = trim((string)($_POST['sitemap_url'] ?? ''));
        $endpointsRaw = trim((string)($_POST['endpoints'] ?? ''));
        $throttle = (int)($_POST['throttle_minutes'] ?? 30);

        $invalid = [];
        if ($sitemapUrl !== '' && !preg_match('#^https://#i', $sitemapUrl)) $invalid[] = 'sitemap 地址必须是 https://';
        foreach (preg_split('/[\r\n]+/', $endpointsRaw) ?: [] as $line) {
            $line = trim((string)$line);
            if ($line !== '' && !preg_match('#^https://#i', $line)) $invalid[] = '端点必须是 https://：' . mb_substr($line, 0, 60);
        }
        if ($throttle < 0 || $throttle > 1440) $invalid[] = '节流分钟数只接受 0-1440';

        if ($invalid !== []) {
            flash('error', implode('；', array_slice($invalid, 0, 3)));
        } else {
            set_plugin_setting('sitemap-ping', 'enabled', ($_POST['enabled'] ?? '') === '1' ? '1' : '0');
            set_plugin_setting('sitemap-ping', 'sitemap_url', $sitemapUrl);
            set_plugin_setting('sitemap-ping', 'endpoints', $endpointsRaw);
            set_plugin_setting('sitemap-ping', 'throttle_minutes', (string)$throttle);
            flash('success', '已保存');
        }
        header('Location: ' . admin_url('/sitemap-ping'));
        exit;
    });

    $router->post('/admin/sitemap-ping/test', static function () {
        \App\Core\Auth::requirePermission('sitemap_ping.manage');
        $result = sitemap_ping_dispatch('manual', 0, true);
        if ($result['sent'] > 0) {
            flash('success', '已成功推送 ' . $result['sent'] . ' 个端点');
        } elseif ($result['skipped'] === 'no_endpoint') {
            flash('error', '没有可用端点：请确认 sitemap 地址与端点都是 https://');
        } else {
            flash('error', '推送未成功，详见下方日志');
        }
        header('Location: ' . admin_url('/sitemap-ping'));
        exit;
    });
});
