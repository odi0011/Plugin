<?php
/**
 * Agent 运行摘要
 *
 * 监听 agent.events 生命周期事件把每次运行落库，提供只读摘要动作与后台页面。
 * 事件监听器里的异常会被 Hooks 吞掉，所以这里自己兜住并只写日志，绝不影响 Run。
 */
if (!defined('CODE_SCHEMA_VERSION')) exit;

require_once __DIR__ . '/AgentActions.php';

const AGENT_RUN_DIGEST_RETENTION_DAYS = 90;

add_action('agent.events', static function (string $eventType, array $payload): void {
    if ($eventType !== 'agent.run.completed' && $eventType !== 'agent.run.failed') return;
    $runId = (int)($payload['run_id'] ?? 0);
    if ($runId <= 0) return;

    try {
        // UNIQUE(run_id, event_type) 保证同一事件重复投递不会重复计数。
        $existing = \App\Core\Database::table('plugin_agent_run_events')
            ->where('run_id', $runId)
            ->where('event_type', $eventType)
            ->first();
        if ($existing) return;

        \App\Core\Database::table('plugin_agent_run_events')->insert([
            'run_id' => $runId,
            'session_id' => max(0, (int)($payload['session_id'] ?? 0)),
            'event_type' => $eventType,
            'task_status' => mb_substr((string)($payload['task_status'] ?? ''), 0, 40),
            'occurred_on' => date('Y-m-d'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // 顺带清理超期记录，避免无界增长。1% 采样即可，不必每次都删。
        if (random_int(1, 100) === 1) {
            \App\Core\Database::table('plugin_agent_run_events')
                ->where('occurred_on', '<', date('Y-m-d', time() - AGENT_RUN_DIGEST_RETENTION_DAYS * 86400))
                ->delete();
        }
    } catch (\Throwable $error) {
        if (function_exists('logger')) {
            \logger('[agent-run-digest] 事件落库失败：' . $error->getMessage(), 'error');
        }
    }
});

agent_register_action('agent-run-digest', [
    'id' => 'digest',
    'label' => 'Agent 运行摘要',
    'description' => '只读返回最近若干天的 Agent 运行次数、成功/失败分布与按天趋势。',
    'module' => 'run_digest',
    'operation' => 'read',
    'risk' => 'low',
    'mutates' => false,
    'params' => ['days'],
    'required_permissions' => ['agent_run_digest.view'],
    'executor' => 'AgentRunDigestActions::digest',
]);

add_filter('admin.menu.register', static function ($items) {
    if (!\App\Core\Auth::can('agent_run_digest.view')) return $items;
    $items[] = [
        'url' => admin_url('/agent-run-digest'),
        'label' => 'Agent 运行摘要',
        'icon' => 'bi-activity',
        'perm' => 'agent_run_digest.view',
    ];
    return $items;
});

add_action('routes.admin.register', static function ($router) {
    // 闭包路由没有 beforeAction，鉴权必须自己做。
    $router->get('/admin/agent-run-digest', static function () {
        \App\Core\Auth::requirePermission('agent_run_digest.view');
        $days = (int)($_GET['days'] ?? 14);
        $days = max(1, min(90, $days));
        $summary = AgentRunDigestActions::summarize($days);
        echo plugin_view('agent-run-digest', 'index', [
            'days' => $days,
            'summary' => $summary,
        ]);
    });
});
