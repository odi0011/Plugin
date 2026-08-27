<?php
declare(strict_types=1);

// 卸载 = 删掉本插件的全部配置（含已加密的独立接口密钥）与上传的知识库文本。
// 访客会话只在 PHP session 里，不落库；表情包走的是核心媒体库目录，可能被其他内容
// 引用，所以刻意不动。
try {
    \App\Core\Database::table('settings')
        ->where('key', 'LIKE', 'plugin.ai-customer-service.%')
        ->delete();
} catch (\Throwable $_) {
}

try {
    require_once __DIR__ . '/src/AiCustomerService.php';
    require_once __DIR__ . '/src/AiCustomerServiceKnowledge.php';
    AiCustomerServiceKnowledge::purge();
} catch (\Throwable $_) {
}
