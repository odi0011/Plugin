<?php
/**
 * agent-run-notifier 执行器。
 *
 * 约定（由 PluginManager::agentActionExecutor 强制校验）：
 *   - 本文件必须位于插件目录内；
 *   - 方法必须是 public static；
 *   - 动作类执行器 (array $input, array $context)。
 */
final class AgentNotifierActions
{
    public static function status(array $input, array $context): array
    {
        $webhookUrl = trim((string)\App\Core\Setting::get('agent_notifier_webhook_url', ''));
        return [
            'ok' => true,
            'message' => $webhookUrl !== ''
                ? '运行完成通知已配置，run.completed 事件会向目标地址投递通知。'
                : '尚未配置 agent_notifier_webhook_url，当前仅记录插件日志。',
            'data' => [
                'webhook_configured' => $webhookUrl !== '',
                // 安全：绝不回显 webhook 地址本身，只暴露是否配置与目标主机名。
                'webhook_host' => $webhookUrl !== '' ? (string)(parse_url($webhookUrl, PHP_URL_HOST) ?? '') : '',
                'note' => '外发经核心 OutboundHttpClient（HTTPS + 公私网 IP 校验），插件无法绕过 SSRF 防护。',
            ],
        ];
    }
}
