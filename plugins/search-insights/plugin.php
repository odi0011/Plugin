<?php
declare(strict_types=1);

if (!defined('CODE_SCHEMA_VERSION')) exit;

require_once __DIR__ . '/src/SearchInsights.php';

add_action('plugin.activated', static function ($slug): void {
    if ($slug !== 'search-insights') return;
    register_plugin_setting('search-insights', 'google_verification', '');
    register_plugin_setting('search-insights', 'bing_verification', '');
});

add_filter('admin.menu.register', static function ($items) {
    if (!\App\Core\Auth::can('search_insights.view')) return $items;
    $items[] = [
        'url' => admin_url('/search-insights'),
        'label' => '搜索数据',
        'icon' => 'bi-bar-chart-line',
        'perm' => 'search_insights.view',
    ];
    return $items;
});

add_action('frontend.head', static function (): void {
    $google = trim((string)get_plugin_setting('search-insights', 'google_verification', ''));
    $bing = trim((string)get_plugin_setting('search-insights', 'bing_verification', ''));
    if ($google !== '') echo '<meta name="google-site-verification" content="' . e($google) . '">' . "\n";
    if ($bing !== '') echo '<meta name="msvalidate.01" content="' . e($bing) . '">' . "\n";
});

add_action('routes.admin.register', static function ($router): void {
    $router->get('/admin/search-insights', static function (): void {
        \App\Core\Auth::requirePermission('search_insights.view');
        $filters = [
            'provider' => is_scalar($_GET['provider'] ?? null) ? (string)$_GET['provider'] : '',
            'dimension' => is_scalar($_GET['dimension'] ?? null) ? (string)$_GET['dimension'] : '',
            'date_from' => is_scalar($_GET['date_from'] ?? null) ? (string)$_GET['date_from'] : '',
            'date_to' => is_scalar($_GET['date_to'] ?? null) ? (string)$_GET['date_to'] : '',
            'q' => is_scalar($_GET['q'] ?? null) ? (string)$_GET['q'] : '',
            'page' => is_scalar($_GET['page'] ?? null) ? (string)$_GET['page'] : '1',
            'per_page' => '20',
        ];
        $merchantFilters = [
            'scope' => is_scalar($_GET['merchant_scope'] ?? null) ? (string)$_GET['merchant_scope'] : '',
            'severity' => is_scalar($_GET['merchant_severity'] ?? null) ? (string)$_GET['merchant_severity'] : '',
            'q' => is_scalar($_GET['merchant_q'] ?? null) ? (string)$_GET['merchant_q'] : '',
            'page' => is_scalar($_GET['merchant_page'] ?? null) ? (string)$_GET['merchant_page'] : '1',
            'per_page' => '20',
        ];
        $inspectionFilters = [
            'q' => is_scalar($_GET['inspection_q'] ?? null) ? (string)$_GET['inspection_q'] : '',
            'verdict' => is_scalar($_GET['inspection_verdict'] ?? null) ? (string)$_GET['inspection_verdict'] : '',
            'page' => is_scalar($_GET['inspection_page'] ?? null) ? (string)$_GET['inspection_page'] : '1',
            'per_page' => '20',
        ];
        try {
            $report = SearchInsightsService::report($filters);
            $geo = SearchInsightsService::geoReport([
                'date_from' => $filters['date_from'], 'date_to' => $filters['date_to'],
                'page' => 1, 'per_page' => 10,
            ]);
            $status = SearchInsightsService::connectionStatus(true);
            $pageSpeed = SearchInsightsService::latestPageSpeed(10);
            $merchant = SearchInsightsService::merchantDiagnostics($merchantFilters);
            $inspections = SearchInsightsService::urlInspections($inspectionFilters);
        } catch (Throwable $error) {
            $report = ['data' => [], 'total' => 0, 'page' => 1, 'per_page' => 20, 'last_page' => 1];
            $geo = ['data' => [], 'summary' => [], 'total' => 0];
            $status = ['connections' => [], 'verification' => [], 'freshness' => []];
            $pageSpeed = [];
            $merchant = ['data' => [], 'summary' => [], 'total' => 0, 'page' => 1, 'last_page' => 1];
            $inspections = ['data' => [], 'total' => 0, 'page' => 1, 'last_page' => 1];
        }
        echo plugin_view('search-insights', 'index', [
            'status' => $status,
            'report' => $report,
            'geo' => $geo,
            'pageSpeed' => $pageSpeed,
            'merchant' => $merchant,
            'inspections' => $inspections,
            'filters' => $filters,
            'merchantFilters' => $merchantFilters,
            'inspectionFilters' => $inspectionFilters,
            'canManage' => \App\Core\Auth::can('search_insights.manage'),
            'canSync' => \App\Core\Auth::can('search_insights.sync'),
            'googleVerification' => (string)get_plugin_setting('search-insights', 'google_verification', ''),
            'bingVerification' => (string)get_plugin_setting('search-insights', 'bing_verification', ''),
        ]);
    });

    $router->post('/admin/search-insights/settings/{provider}', static function (string $provider): void {
        \App\Core\Auth::requirePermission('search_insights.manage');
        try {
            SearchInsightsService::saveConnection($provider, [
                'site_url' => search_insights_post('site_url'),
                'property_id' => search_insights_post('property_id'),
                'ga_property_id' => search_insights_post('ga_property_id'),
                'merchant_account_id' => search_insights_post('merchant_account_id'),
                'client_id' => search_insights_post('client_id'),
                'client_secret' => search_insights_post('client_secret'),
                'api_key' => search_insights_post('api_key'),
            ], (int)\App\Core\Auth::id());
            flash('success', '连接配置已保存');
        } catch (Throwable $error) {
            flash('error', $error instanceof InvalidArgumentException ? $error->getMessage() : '连接配置保存失败');
        }
        header('Location: ' . admin_url('/search-insights#connections'));
        exit;
    });

    $router->get('/admin/search-insights/google/connect', static function (): void {
        \App\Core\Auth::requirePermission('search_insights.manage');
        try {
            header('Location: ' . SearchInsightsService::googleAuthorizationUrl((int)\App\Core\Auth::id()));
        } catch (Throwable $error) {
            flash('error', $error->getMessage());
            header('Location: ' . admin_url('/search-insights#connections'));
        }
        exit;
    });

    $router->get('/admin/search-insights/google/callback', static function (): void {
        \App\Core\Auth::requirePermission('search_insights.manage');
        $error = is_scalar($_GET['error'] ?? null) ? trim((string)$_GET['error']) : '';
        try {
            if ($error !== '') throw new RuntimeException('Google 授权未完成');
            SearchInsightsService::completeGoogleAuthorization(
                is_scalar($_GET['code'] ?? null) ? (string)$_GET['code'] : '',
                is_scalar($_GET['state'] ?? null) ? (string)$_GET['state'] : '',
                (int)\App\Core\Auth::id()
            );
            flash('success', 'Google Search Console、GA4 与 Merchant Center 权限已连接');
        } catch (Throwable $exception) {
            flash('error', $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'Google OAuth 连接失败');
        }
        header('Location: ' . admin_url('/search-insights#connections'));
        exit;
    });

    $router->post('/admin/search-insights/google/disconnect', static function (): void {
        \App\Core\Auth::requirePermission('search_insights.manage');
        SearchInsightsService::disconnectGoogle((int)\App\Core\Auth::id());
        flash('success', 'Google OAuth token 已撤销本地使用');
        header('Location: ' . admin_url('/search-insights#connections'));
        exit;
    });

    $router->post('/admin/search-insights/sync', static function (): void {
        \App\Core\Auth::requirePermission('search_insights.sync');
        try {
            $result = SearchInsightsService::sync(
                search_insights_post('provider', 'google'),
                search_insights_post('date_from'),
                search_insights_post('date_to')
            );
            SearchInsightsService::audit('search_insights.metrics.sync', search_insights_post('provider', 'google'), (int)\App\Core\Auth::id(), $result, 'admin');
            $merchantResult = is_array($result['merchant'] ?? null) ? $result['merchant'] : [];
            $merchantText = isset($merchantResult['products_scanned'])
                ? '，Merchant 商品 ' . (int)$merchantResult['products_scanned'] . ' 个'
                : '';
            flash('success', '同步完成：GSC ' . (int)$result['gsc'] . ' 条，GA4 ' . (int)$result['ga4'] . ' 条' . $merchantText);
        } catch (Throwable $error) {
            flash('error', $error instanceof InvalidArgumentException ? $error->getMessage() : '同步失败，请检查连接和授权');
        }
        header('Location: ' . admin_url('/search-insights'));
        exit;
    });

    $router->post('/admin/search-insights/merchant-sync', static function (): void {
        \App\Core\Auth::requirePermission('search_insights.sync');
        try {
            $result = SearchInsightsService::syncMerchantDiagnostics(
                search_insights_post('language_code', 'zh-CN'),
                (int)search_insights_post('max_products', '100')
            );
            SearchInsightsService::audit(
                'search_insights.merchant.sync',
                (string)($result['account_id'] ?? 'merchant'),
                (int)\App\Core\Auth::id(),
                [
                    'account_issues' => (int)($result['account_issues'] ?? 0),
                    'products_scanned' => (int)($result['products_scanned'] ?? 0),
                    'product_issues' => (int)($result['product_issues'] ?? 0),
                    'cycle_complete' => !empty($result['cycle_complete']),
                    'truncated' => !empty($result['truncated']),
                ],
                'admin'
            );
            $suffix = !empty($result['next_sync_continues_cycle']) ? '；目录尚未完成，下次从游标续跑' : '';
            flash('success', 'Merchant 诊断已同步：扫描 ' . (int)$result['products_scanned']
                . ' 个商品，记录 ' . (int)$result['product_issues'] . ' 个商品问题' . $suffix);
        } catch (Throwable $error) {
            flash('error', $error instanceof InvalidArgumentException || $error instanceof RuntimeException
                ? $error->getMessage() : 'Merchant Center 诊断同步失败');
        }
        header('Location: ' . admin_url('/search-insights#merchant'));
        exit;
    });

    $router->post('/admin/search-insights/pagespeed', static function (): void {
        \App\Core\Auth::requirePermission('search_insights.sync');
        try {
            $result = SearchInsightsService::pageSpeed(search_insights_post('url'), search_insights_post('strategy', 'mobile'));
            SearchInsightsService::audit('search_insights.pagespeed.run', (string)($result['url_hash'] ?? 'url'), (int)\App\Core\Auth::id(), [
                'url' => (string)($result['url'] ?? ''), 'strategy' => (string)($result['strategy'] ?? ''),
            ], 'admin');
            flash('success', 'PageSpeed 检查完成');
        } catch (Throwable $error) {
            flash('error', $error instanceof InvalidArgumentException ? $error->getMessage() : 'PageSpeed 检查失败');
        }
        header('Location: ' . admin_url('/search-insights#pagespeed'));
        exit;
    });

    $router->post('/admin/search-insights/inspect-url', static function (): void {
        \App\Core\Auth::requirePermission('search_insights.sync');
        try {
            $result = SearchInsightsService::inspectUrl(search_insights_post('url'), search_insights_post('language_code', 'zh-CN'));
            SearchInsightsService::audit('search_insights.url.inspect', (string)($result['url_hash'] ?? 'url'), (int)\App\Core\Auth::id(), [
                'url' => (string)($result['url'] ?? ''), 'verdict' => (string)($result['verdict'] ?? ''),
            ], 'admin');
            flash('success', 'Google URL Inspection 已完成');
        } catch (Throwable $error) {
            flash('error', $error instanceof InvalidArgumentException ? $error->getMessage() : 'URL Inspection 失败');
        }
        header('Location: ' . admin_url('/search-insights#operations'));
        exit;
    });

    $router->post('/admin/search-insights/submit-sitemap', static function (): void {
        \App\Core\Auth::requirePermission('search_insights.sync');
        try {
            $result = SearchInsightsService::submitSitemap(search_insights_post('sitemap_url'));
            SearchInsightsService::audit('search_insights.sitemap.submit', hash('sha256', (string)$result['sitemap_url']), (int)\App\Core\Auth::id(), [
                'sitemap_url' => (string)$result['sitemap_url'], 'status' => (int)$result['status'],
            ], 'admin');
            flash('success', 'Sitemap 已提交到 Google Search Console');
        } catch (Throwable $error) {
            flash('error', $error instanceof InvalidArgumentException ? $error->getMessage() : 'Sitemap 提交失败');
        }
        header('Location: ' . admin_url('/search-insights#operations'));
        exit;
    });

    $router->post('/admin/search-insights/metric-import', static function (): void {
        \App\Core\Auth::requirePermission('search_insights.sync');
        try {
            $result = SearchInsightsService::importMetric([
                'provider' => search_insights_post('provider'),
                'metric_date' => search_insights_post('metric_date'),
                'dimension' => search_insights_post('dimension'),
                'dimension_key' => search_insights_post('dimension_key'),
                'clicks' => search_insights_post('clicks', '0'),
                'impressions' => search_insights_post('impressions', '0'),
                'ctr' => search_insights_post('ctr', '0'),
                'position' => search_insights_post('position', '0'),
                'users' => search_insights_post('users', '0'),
                'sessions' => search_insights_post('sessions', '0'),
                'pageviews' => search_insights_post('pageviews', '0'),
                'conversions' => search_insights_post('conversions', '0'),
                'citations' => search_insights_post('citations', '0'),
            ]);
            SearchInsightsService::audit('search_insights.metric.import', hash('sha256', (string)$result['provider'] . '|' . (string)$result['metric_date'] . '|' . (string)$result['dimension_key']), (int)\App\Core\Auth::id(), [
                'provider' => (string)$result['provider'], 'metric_date' => (string)$result['metric_date'], 'dimension' => (string)$result['dimension'],
            ], 'admin');
            flash('success', '搜索平台指标已导入');
        } catch (Throwable $error) {
            flash('error', $error instanceof InvalidArgumentException ? $error->getMessage() : '指标导入失败');
        }
        header('Location: ' . admin_url('/search-insights'));
        exit;
    });

    $router->post('/admin/search-insights/geo', static function (): void {
        \App\Core\Auth::requirePermission('search_insights.sync');
        try {
            $result = SearchInsightsService::recordGeoObservation([
                'engine' => search_insights_post('engine'),
                'observed_at' => search_insights_post('observed_at'),
                'prompt' => search_insights_post('prompt'),
                'cited_url' => search_insights_post('cited_url'),
                'citation_position' => search_insights_post('citation_position'),
                'sentiment' => search_insights_post('sentiment', 'neutral'),
                'answer_summary' => search_insights_post('answer_summary'),
            ], (int)\App\Core\Auth::id());
            SearchInsightsService::audit('search_insights.geo.observe', (string)($result['id'] ?? ''), (int)\App\Core\Auth::id(), [
                'engine' => (string)($result['engine'] ?? ''), 'cited' => !empty($result['cited_url']),
            ], 'admin');
            flash('success', 'GEO 引用观测已记录');
        } catch (Throwable $error) {
            flash('error', $error instanceof InvalidArgumentException ? $error->getMessage() : 'GEO 引用观测保存失败');
        }
        header('Location: ' . admin_url('/search-insights#geo'));
        exit;
    });

    $router->post('/admin/search-insights/verification', static function (): void {
        \App\Core\Auth::requirePermission('search_insights.manage');
        try {
            SearchInsightsService::updateVerification(
                search_insights_post('google_token'), search_insights_post('bing_token'),
                (int)\App\Core\Auth::id()
            );
            flash('success', '站点验证标签已更新');
        } catch (Throwable $error) {
            flash('error', $error instanceof InvalidArgumentException ? $error->getMessage() : '站点验证标签保存失败');
        }
        header('Location: ' . admin_url('/search-insights#verification'));
        exit;
    });
});

if (!function_exists('search_insights_post')) {
    function search_insights_post(string $key, string $default = ''): string
    {
        $value = $_POST[$key] ?? $default;
        return is_scalar($value) ? trim((string)$value) : $default;
    }
}
