<?php
declare(strict_types=1);

/**
 * AI客服插件。版本号只在 plugin.json 里写一处，代码侧一律读 AiCustomerService::VERSION。
 *
 * 结构：
 * - 后台 8 个子页面（会话/触发/外观/AI/资料/工具/边界/输入），逐页保存；
 * - 后台异步动作（上传资料文件、检索站内内容、上传表情包、取预设）走同一组 POST 路由；
 * - 前台两个同源端点：/ai-customer-service/chat 与 /ai-customer-service/action。
 *   两者都不是 /api/v1 端点——访客没有后台账号，做成 API 会把匿名会话绑到管理员身上。
 *   CSRF 由核心 Router 对所有非 /api/ 的 POST 统一校验。
 */
if (!defined('CODE_SCHEMA_VERSION')) exit;

require_once __DIR__ . '/src/AiCustomerService.php';
require_once __DIR__ . '/src/AiCustomerServiceCards.php';
require_once __DIR__ . '/src/AiCustomerServiceGuardrails.php';
require_once __DIR__ . '/src/AiCustomerServiceKnowledge.php';
require_once __DIR__ . '/src/AiCustomerServiceTools.php';
require_once __DIR__ . '/src/AiCustomerServiceChat.php';
require_once __DIR__ . '/src/AiCustomerServiceAdmin.php';

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

/**
 * 后台配置页要用**前台那一份**样式来渲染实时预览——预览不再维护一套仿制品，
 * 也就不可能和前台走样。只在本插件自己的页面注入，不污染其他后台页面
 * （所以没有把这两个资源声明成 area: both）。
 */
add_action('admin.head', static function (): void {
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    if (!str_contains((string)parse_url($uri, PHP_URL_PATH), '/ai-customer-service')) return;
    foreach (['assets/customer-service.css', 'assets/preset-cards.css'] as $asset) {
        printf(
            '<link rel="stylesheet" href="%s?v=%s">' . "\n",
            htmlspecialchars(plugin_url(AiCustomerService::SLUG, $asset), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars(AiCustomerService::VERSION, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
    }
}, 5);

add_action('routes.admin.register', static function ($router): void {
    $render = static function (string $page): void {
        \App\Core\Auth::requirePermission('ai_customer_service.manage');
        echo plugin_view('ai-customer-service', 'admin', AiCustomerService::adminPageData($page));
    };
    $redirect = static function (string $page): void {
        $page = array_key_exists($page, AiCustomerService::ADMIN_PAGES) ? $page : 'conversation';
        header('Location: ' . admin_url('/ai-customer-service/' . $page));
        exit;
    };

    $router->get('/admin/ai-customer-service', static function () use ($render): void {
        $render('conversation');
    });

    // 异步动作放在 /x/{action} 下，避免和 /{page} 抢同一段路径。
    $router->post('/admin/ai-customer-service/x/{action}', static function (string $action = '') : void {
        AiCustomerServiceAdmin::handle(AiCustomerService::slugValue($action, 40));
    });

    $router->get('/admin/ai-customer-service/{page}', static function (string $page = '') use ($render, $redirect): void {
        if (!array_key_exists($page, AiCustomerService::ADMIN_PAGES)) {
            $redirect('conversation');
        }
        $render($page);
    });

    $router->post('/admin/ai-customer-service', static function () use ($redirect): void {
        \App\Core\Auth::requirePermission('ai_customer_service.manage');
        $returnPage = AiCustomerService::adminReturnPage();
        $result = AiCustomerService::saveAdminConfiguration();
        flash(!empty($result['ok']) ? 'success' : 'error', (string)($result['message'] ?? '保存失败'));
        $redirect($returnPage);
    });
});

add_action('routes.frontend.register', static function ($router): void {
    $router->post('/ai-customer-service/chat', static function (): void {
        AiCustomerServiceChat::dispatch();
    });
    $router->post('/ai-customer-service/action', static function (): void {
        AiCustomerServiceChat::dispatchAction();
    });
});

add_action('frontend.body_end', static function (): void {
    AiCustomerService::renderWidget();
});
