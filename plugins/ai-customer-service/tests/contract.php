<?php
declare(strict_types=1);

/**
 * AI客服插件的本地契约测试。
 *
 * 额外盯住两个有意不适用的三面对等例外：
 * - 访客聊天只能走同源 CSRF 会话通道，不能变成需要后台 token 的 /api/v1 端点；
 * - 独立接口密钥不能进入 settings/API/Agent 的可读取契约，必须只经管理员页面加密保存。
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
$readme = (string)file_get_contents($root . '/README.md');

$assert(str_contains($plugin, "'/ai-customer-service/chat'"), '缺少访客同源聊天路由');
$assert(str_contains($plugin, "'/admin/ai-customer-service'"), '缺少后台左侧菜单配置路由');
$assert(str_contains($service, 'Security::encryptApiKey'), '独立接口密钥必须在保存前加密');
$assert(str_contains($service, 'Security::decryptApiKey'), '独立接口密钥必须在服务端调用前解密');
$assert(str_contains($chat, 'OutboundHttpClient::postJson'), '独立接口必须走受限出站客户端');
$assert(!str_contains($chat, '/api/v1/'), '访客聊天不得伪装成后台 Bearer token API');
$assert(str_contains($readme, '有意不进入公开 API / Agent'), 'README 必须记录访客聊天与密钥的 API/Agent 例外');

if ($failures !== []) {
    echo "AI客服插件契约 FAILED:\n";
    foreach ($failures as $failure) echo ' - ' . $failure . "\n";
    exit(1);
}

echo "AI客服插件契约通过（配置/API/Agent/访客会话/密钥边界）。\n";
