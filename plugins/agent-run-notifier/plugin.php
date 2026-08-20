<?php
/**
 * agent-run-notifier：agent.events 生命周期 + 安全外发通知示例插件。
 *
 * 演示：
 *   1. agent.events 监听 run.completed 事件；
 *   2. 通过核心 OutboundHttpClient 做外发（HTTPS 强制 + 公私网 IP 校验，
 *      插件无法绕过 SSRF 防护）；
 *   3. webhook 地址只允许管理员在后台设置 agent_notifier_webhook_url，
 *      插件动作仅返回配置状态，绝不回显地址。
 */
if (!defined('CODE_SCHEMA_VERSION')) exit;

require_once __DIR__ . '/AgentActions.php';

agent_register_action('agent-run-notifier', [
    'id' => 'status',
    'label' => '通知状态',
    'description' => '只读查看运行完成通知的配置状态（不暴露 webhook 地址本身）。',
    'module' => 'notifier',
    'operation' => 'read',
    'risk' => 'low',
    'mutates' => false,
    'params' => [],
    'required_permissions' => ['agent_notifier.view'],
    'executor' => 'AgentNotifierActions::status',
]);

add_action('agent.events', static function (string $eventType, array $payload): void {
    if ($eventType !== 'agent.run.completed') return;
    $runId = (int)($payload['run_id'] ?? 0);
    $sessionId = (int)($payload['session_id'] ?? 0);
    if ($runId <= 0) return;

    if (function_exists('logger')) {
        \logger('[agent-run-notifier] run completed #' . $runId . ' (session #' . $sessionId . ')', 'info');
    }

    $webhookUrl = trim((string)\App\Core\Setting::get('agent_notifier_webhook_url', ''));
    if ($webhookUrl === '') return;

    // 外发失败只记日志，绝不影响 Agent 运行完成的事务。
    try {
        \App\Core\OutboundHttpClient::postJson($webhookUrl, json_encode([
            'event' => 'agent.run.completed',
            'run_id' => $runId,
            'session_id' => $sessionId,
            'task_status' => (string)($payload['task_status'] ?? 'completed'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
    } catch (\Throwable $error) {
        if (function_exists('logger')) {
            \logger('[agent-run-notifier] webhook delivery failed: ' . $error->getMessage(), 'error');
        }
    }
});
