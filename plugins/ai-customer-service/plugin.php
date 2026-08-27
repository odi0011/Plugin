<?php
declare(strict_types=1);

/**
 * AI客服插件。
 *
 * 前台对话经过同源会话 POST 通道，避免把管理员 API token 暴露给访客；
 * 后台配置则复用插件声明式 settings，因此仍有系统生成的 API 与 Agent 动作。
 */
if (!defined('CODE_SCHEMA_VERSION')) exit;

require_once __DIR__ . '/src/AiCustomerService.php';
require_once __DIR__ . '/src/AiCustomerServiceChat.php';

add_filter('admin.menu.register', static function (array $items): array {
    if (!\App\Core\Auth::can('ai_customer_service.manage')) {
        return $items;
    }
    $items[] = [
        'url' => admin_url('/ai-customer-service'),
        'label' => 'AI客服',
        'icon' => 'bi-chat-square-dots',
        'perm' => 'ai_customer_service.manage',
        'group' => 'plugin',
    ];
    return $items;
});

add_action('routes.admin.register', static function ($router): void {
    $router->get('/admin/ai-customer-service', static function (): void {
        \App\Core\Auth::requirePermission('ai_customer_service.manage');
        echo plugin_view('ai-customer-service', 'admin', AiCustomerService::adminPageData());
    });

    $router->post('/admin/ai-customer-service', static function (): void {
        \App\Core\Auth::requirePermission('ai_customer_service.manage');
        $result = AiCustomerService::saveAdminConfiguration();
        flash(!empty($result['ok']) ? 'success' : 'error', (string)($result['message'] ?? '保存失败'));
        header('Location: ' . admin_url('/ai-customer-service'));
        exit;
    });
});

add_action('routes.frontend.register', static function ($router): void {
    $router->post('/ai-customer-service/chat', static function (): void {
        AiCustomerServiceChat::dispatch();
    });
});

add_action('frontend.body_end', static function (): void {
    AiCustomerService::renderWidget();
});
