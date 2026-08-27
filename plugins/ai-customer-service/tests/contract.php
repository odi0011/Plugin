<?php
declare(strict_types=1);

/**
 * AI客服插件的本地契约测试。
 *
 * 额外盯住两个有意不适用的三面对等例外：
 * - 访客聊天只能走同源 CSRF 会话通道，不能变成需要后台 token 的 /api/v1 端点；
 * - 独立接口密钥不能进入 settings/API/Agent 的可读取契约，必须只经管理员页面加密保存。
 *
 * 同时锁住 1.1.0 的四页面后台形态与前台窗口默认收起的行为契约：
 * - 四个独立子页面路由 + 逐页保存的服务端合并（acs_save_keys）；
 * - 前台面板必须带 [hidden] 显示兜底，防止加载即展开且无法关闭；
 * - 实时预览必须存在并覆盖新配置项。
 */
$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$manifestRaw = (string)file_get_contents($root . '/plugin.json');
$manifest = json_decode($manifestRaw, true);
$assert(is_array($manifest), 'plugin.json 必须是合法 JSON');
$assert((string)($manifest['slug'] ?? '') === 'ai-customer-service', 'slug 必须与插件目录一致');
$assert((string)($manifest['name'] ?? '') === 'AI客服', '市场名称必须是 AI客服');

$sections = is_array($manifest['settings']['sections'] ?? null) ? $manifest['settings']['sections'] : [];
$sectionKeys = array_map(static fn (array $section): string => (string)($section['key'] ?? ''), $sections);
$assert($sectionKeys === ['conversation', 'trigger', 'appearance', 'ai'], 'settings 必须是四个子页面：conversation/trigger/appearance/ai');
$totalFields = 0;
foreach ($sections as $section) {
    foreach ((array)($section['fields'] ?? []) as $field) {
        if (!empty($field['key'])) $totalFields += 1;
    }
}
$assert($totalFields > 40 && $totalFields <= 100, "细粒度配置项数量应在 41~100 之间，当前 {$totalFields}");
foreach (['initial_open', 'attention_effect', 'teaser_enabled', 'badge_enabled', 'show_launcher'] as $triggerFieldKey) {
    $found = false;
    foreach ((array)($manifest['settings']['sections'] ?? []) as $section) {
        foreach ((array)($section['fields'] ?? []) as $field) {
            if (($field['key'] ?? '') === $triggerFieldKey) $found = true;
        }
    }
    $assert($found, '缺少触发类配置项：' . $triggerFieldKey);
}
foreach (['header_color', 'visitor_bubble_color', 'bot_bubble_color', 'panel_shadow', 'font_size'] as $appearanceFieldKey) {
    $found = false;
    foreach ((array)($manifest['settings']['sections'] ?? []) as $section) {
        foreach ((array)($section['fields'] ?? []) as $field) {
            if (($field['key'] ?? '') === $appearanceFieldKey) $found = true;
        }
    }
    $assert($found, '缺少外观颗粒度配置项：' . $appearanceFieldKey);
}
$triggerSectionKey = '';
foreach ((array)($manifest['settings']['sections'] ?? []) as $section) {
    foreach ((array)($section['fields'] ?? []) as $field) {
        if (($field['key'] ?? '') === 'initial_open') $triggerSectionKey = (string)($section['key'] ?? '');
    }
}
$assert($triggerSectionKey === 'trigger', 'initial_open 应属于 trigger 子页面');

$api = is_array($manifest['api'] ?? null) ? $manifest['api'] : [];
$assert(count($api) === 1, '公开插件 API 只应声明无密钥的状态端点');
$status = $api[0] ?? [];
$assert((string)($status['endpoint'] ?? '') === 'status' && (string)($status['method'] ?? '') === 'GET', '状态端点契约错误');
$assert((string)($status['permission'] ?? '') === 'ai_customer_service.view', '状态端点权限错误');
$assert(!str_contains($manifestRaw, 'custom_api_key'), '独立接口密钥不得出现在声明式 settings/API/Agent 清单');

foreach ((array)($manifest['assets'] ?? []) as $asset) {
    $path = is_array($asset) ? (string)($asset['src'] ?? '') : '';
    $assert($path !== '' && is_file($root . '/' . $path), '声明资源必须存在：' . $path);
}

$plugin = (string)file_get_contents($root . '/plugin.php');
$service = (string)file_get_contents($root . '/src/AiCustomerService.php');
$chat = (string)file_get_contents($root . '/src/AiCustomerServiceChat.php');
$view = (string)file_get_contents($root . '/views/admin.php');
$adminJs = (string)file_get_contents($root . '/assets/admin.js');
$adminCss = (string)file_get_contents($root . '/assets/admin.css');
$widgetCss = (string)file_get_contents($root . '/assets/customer-service.css');
$widgetJs = (string)file_get_contents($root . '/assets/customer-service.js');
$readme = (string)file_get_contents($root . '/README.md');

// 后台：四个独立子页面，而不是锚点滚动单页。
$adminPages = ['conversation', 'trigger', 'appearance', 'ai'];
foreach ($adminPages as $adminPage) {
    $assert(str_contains($service, "'{$adminPage}'"), 'ADMIN_PAGES 缺少子页面：' . $adminPage);
}
$assert(str_contains($plugin, "'/admin/ai-customer-service/{page}'"), '缺少四个子页面的统一 {page} 路由');
$assert(str_contains($plugin, "'/admin/ai-customer-service'"), '缺少后台左侧菜单配置路由');
$assert(str_contains($view, 'acs_return_page'), '每个子页面保存后必须跳回当前页');
$assert(str_contains($view, 'acs_save_keys'), '表单必须声明本页字段范围（acs_save_keys）');
$assert(str_contains($service, "SAVE_KEYS_FIELD") && str_contains($service, "'acs_save_keys'"), '服务端必须按 acs_save_keys 合并本页字段');
$assert(str_contains($service, 'values(self::SLUG)[\'values\']'), '未提交的声明字段必须以库中现值回填后再整体校验');
// 左侧菜单高亮由插件侧补齐（核心 sidebar 用完整 URL 做 strpos 匹配永远不亮）。
$assert(str_contains($adminJs, '.admin-sidebar-nav a'), 'admin.js 需要为左侧菜单补高亮');

// 前台：窗口默认收起、可关闭。
$assert(str_contains($widgetCss, '.acs-panel[hidden]'), '前台 CSS 必须有 [hidden] 兜底，否则窗口会加载即展开且关不掉');
$assert(str_contains($widgetCss, 'display: none !important'), '[hidden] 兜底必须是 display:none 级别');
$assert(str_contains($widgetJs, "panel.hidden = !state.open"), '开关状态必须落到 hidden 属性');
$assert(str_contains($widgetCss, 'acs-teaser') && str_contains($widgetJs, 'data-acs-teaser'), '引流气泡需要样式与行为配套');
$assert(str_contains($widgetCss, 'has-badge') && str_contains($widgetJs, 'clearAttention'), '红点角标需要在打开后清除');

// 实时预览。
$assert(str_contains($view, 'data-acs-preview-stage'), '预览舞台缺失');
$assert(str_contains($view, 'data-acs-device'), '预览必须支持桌面/移动端切换');
$assert(str_contains($adminJs, "addEventListener('input', apply)") || str_contains($adminJs, "controlNode.addEventListener('input', apply)"), '输入必须实时驱动预览');
$assert(str_contains($adminJs, 'function apply()'), '预览需要统一的 apply 渲染入口');
$assert(str_contains($adminCss, '.acs-pv-panel'), '预览窗口样式缺失');

$assert(str_contains($plugin, "'/ai-customer-service/chat'"), '缺少访客同源聊天路由');
$assert(str_contains($service, 'Security::encryptApiKey'), '独立接口密钥必须在保存前加密');
$assert(str_contains($service, 'Security::decryptApiKey'), '独立接口密钥必须在服务端调用前解密');
$assert(str_contains($chat, 'OutboundHttpClient::postJson'), '独立接口必须走受限出站客户端');
$assert(!str_contains($chat, '/api/v1/'), '访客聊天不得伪装成后台 Bearer token API');
$assert(str_contains($readme, '有意不进入公开 API / Agent'), 'README 必须记录访客聊天与密钥的 API/Agent 例外');
$assert(str_contains($readme, '四个独立子页面'), 'README 必须描述四子页面结构');

if ($failures !== []) {
    echo "AI客服插件契约 FAILED:\n";
    foreach ($failures as $failure) echo ' - ' . $failure . "\n";
    exit(1);
}

echo "AI客服插件契约通过（配置/API/Agent/访客会话/密钥边界/四页面后台/实时预览）。\n";
