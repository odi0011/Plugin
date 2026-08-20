<?php
/**
 * Agent 扩展示例插件
 *
 * 演示四类 Agent 集成面（与 harness 插件机制一致）：
 *   1. agent.actions.register   —— 注册可执行动作（本文件底部）
 *   2. agent.resources.register —— 注册可搜索/读取的资源类型
 *   3. agent.delegation.roles.register —— 注册委派角色
 *   4. agent.events             —— 监听 Agent 生命周期事件
 *
 * 安全边界（核心强制）：
 *   - 动作 id 必须与 plugin.json 的 "agent"."actions" 双写一致，否则不会进入目录；
 *   - 执行器必须是本插件目录内的 public static 方法（AgentActions 类）；
 *   - 权限求交 / 审批 / 审计 / 幂等全部由核心管线完成，插件无法绕过。
 */
if (!defined('CODE_SCHEMA_VERSION')) exit;

require_once __DIR__ . '/AgentActions.php';

// 1) Agent 动作：只读统计
agent_register_action('agent-hello', [
    'id' => 'stats',
    'label' => '查看示例统计',
    'description' => '读取 agent-hello 插件维护的简单统计(只读示例动作)。',
    'module' => 'hello',
    'operation' => 'read',
    'risk' => 'low',
    'mutates' => false,
    'params' => ['period'],
    'required_permissions' => ['agent_hello.view'],
    'executor' => 'AgentHelloActions::stats',
]);

// 2) 资源类型：示例公告（只读，loader 提供 search/load 两种模式）
agent_register_resource('agent-hello', 'notices', [
    'label' => '示例公告',
    'icon' => 'bi-megaphone',
    'fields' => ['title', 'body'],
    'permission' => 'agent_hello.view',
    'loader' => 'AgentHelloActions::loadNotice',
]);

// 3) 委派角色
agent_register_role('agent-hello', 'hello_auditor', [
    'label' => '示例审计',
    'description' => '审计示例公告的完整性与一致性。',
]);

// 4) 生命周期事件监听：run 完成后记录一条插件日志
add_action('agent.events', static function (string $eventType, array $payload): void {
    if ($eventType !== 'agent.run.completed') return;
    if (function_exists('logger')) {
        \logger('[agent-hello] agent run completed #' . (int)($payload['run_id'] ?? 0), 'info');
    }
});
