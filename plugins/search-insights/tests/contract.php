<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);

$manifest = json_decode($read('plugin.json'), true);
$plugin = $read('plugin.php');
$service = $read('src/SearchInsights.php');
$migration = $read('migrations/001_create.sql');
$view = $read('views/index.php');
$readme = $read('README.md');

$assert(is_array($manifest) && ($manifest['slug'] ?? '') === 'search-insights', 'manifest slug 无效');
$assert(count((array)($manifest['api'] ?? [])) === 13, '公共 API/Agent 端点数量漂移');
$assert(count((array)($manifest['schedules'] ?? [])) === 1, 'Google 周期同步声明缺失');
foreach (['search_insights.view', 'search_insights.manage', 'search_insights.sync'] as $permission) {
    $assert(in_array($permission, array_column((array)($manifest['permissions'] ?? []), 'key'), true), '权限缺失：' . $permission);
}
foreach (['connections', 'metrics', 'url_inspections', 'pagespeed', 'geo', 'merchant_issues'] as $table) {
    $assert(str_contains($migration, 'plugin_search_insights_' . $table), '迁移缺表：' . $table);
}
$assert(str_contains($migration, '`{{prefix}}plugin_search_insights_connections`'),
    '搜索数据迁移未使用核心表前缀占位符');
$assert(str_contains($service, 'Security::encryptApiKey') && str_contains($service, 'Security::decryptApiKey'), '凭证未使用加密 envelope');
$assert(str_contains($service, 'connectionStatus(bool $includePublicClientId = false)')
    && str_contains($plugin, 'connectionStatus(true)'), 'OAuth client ID 没有和公共状态响应隔离');
$assert(str_contains($service, 'OutboundHttpClient::request') && str_contains($service, 'GOOGLE_HOSTS'), '外发没有经过固定 host 的核心安全客户端');
$assert(str_contains($service, 'https://www.googleapis.com/auth/content')
    && str_contains($service, 'merchantapi.googleapis.com'), 'Merchant API OAuth scope 或固定 host 缺失');
$assert(str_contains($service, 'syncMerchantDiagnostics')
    && str_contains($service, 'merchant_cursor')
    && str_contains($service, 'MAX_MERCHANT_PRODUCTS = 100'), 'Merchant 诊断缺少有界续跑能力');
$assert(str_contains($service, "['connected', 'error']")
    && str_contains($service, 'refresh_token_envelope'),
    'temporary Google sync errors permanently disable later scheduled retries');
$assert(str_contains($service, "preg_split('/\\s+/'")
    && str_contains($service, 'array_intersect(self::GOOGLE_SCOPES, $scopeValues)')
    && str_contains($service, 'Google OAuth 未授予 Search Console 权限'),
    'OAuth connection stores requested scopes instead of the scopes actually granted');
$assert(str_contains($service, 'MAX_SYNC_ROWS = 5000')
    && str_contains($service, "'truncated'")
    && str_contains($service, "'sync_cursor'")
    && str_contains($service, "'next_sync_continues_cycle'"),
    '同步没有有界行数、截断或服务端续跑游标');
$assert(str_contains($service, "min(250, \$perDimensionLimit - \$dimensionScanned)")
    && str_contains($service, "min(500, self::MAX_SYNC_ROWS - \$scanned)")
    && str_contains($service, "'fields' => 'products(offerId,productStatus")
    && str_contains($service, "'fields' => 'lighthouseResult("),
    '上游 GSC/GA4 分页或 Merchant/PageSpeed partial response 边界缺失');
$assert(str_contains($service, "['name' => 'pagePath']")
    && !str_contains($service, 'pagePathPlusQueryString')
    && str_contains($service, 'private static function gaPagePath'),
    'GA4 查询参数可能被保存并进入 API/Agent 上下文');
$assert(str_contains($read('uninstall.php'), "'plugin_search_insights_merchant_issues'"),
    '卸载未清理 Merchant 诊断表');
$allApiParams = [];
foreach ((array)($manifest['api'] ?? []) as $endpoint) {
    $allApiParams = array_merge($allApiParams, (array)($endpoint['params'] ?? []));
}
$assert(array_intersect(['client_id', 'client_secret', 'api_key', 'access_token', 'refresh_token'], $allApiParams) === [], '凭证进入了公共 API');
$assert(str_contains($readme, '不进入公共 API 或 Agent 参数'), '凭证配置 N/A 决定未文档化');
$assert(str_contains($plugin, "add_action('frontend.head'") && str_contains($plugin, 'google-site-verification') && str_contains($plugin, 'msvalidate.01'), '站点验证输出缺失');
$assert(str_contains($view, "admin_url('/search-insights/google/connect')")
    && str_contains($view, 'search-insights-geo-grid')
    && str_contains($view, "admin_url('/search-insights/inspect-url')")
    && str_contains($view, "admin_url('/search-insights/submit-sitemap')")
    && str_contains($view, "admin_url('/search-insights/metric-import')")
    && str_contains($view, "admin_url('/search-insights/merchant-sync')")
    && str_contains($view, 'search-insights-merchant-grid')
    && str_contains($view, 'search-insights-inspections-grid'),
    '后台连接、索引、导入、Merchant 或 GEO UI 缺失');

if ($failures !== []) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}
fwrite(STDOUT, "Search insights contract passed.\n");
