<?php
declare(strict_types=1);

// 卸载意味着删除本插件的配置（包括已加密的独立接口密钥）。访客会话仅在 PHP session 中，
// 不写入数据库，因此没有独立业务表或聊天记录需要清理。
try {
    \App\Core\Database::table('settings')
        ->where('key', 'LIKE', 'plugin.ai-customer-service.%')
        ->delete();
} catch (\Throwable $_) {
}
