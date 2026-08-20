<?php
/**
 * agent-content-integrity：资源类型 + 委派角色示例插件。
 *
 * 演示：
 *   1. agent.resources.register 注册可搜索/读取的资源类型（只读）；
 *   2. agent.delegation.roles.register 注册委派角色；
 *   3. agent.actions.register 注册一个只读动作。
 *
 * 安全边界：loader 必须是本插件目录内的 public static 方法；
 * 所有数据读取都只统计当前用户有权读取的资源，绝不写库。
 */
if (!defined('CODE_SCHEMA_VERSION')) exit;

require_once __DIR__ . '/AgentActions.php';

agent_register_resource('agent-content-integrity', 'inventory', [
    'label' => '内容清单',
    'icon' => 'bi-clipboard-check',
    'fields' => ['type', 'id', 'title', 'count'],
    'permission' => 'agent_integrity.view',
    'loader' => 'AgentIntegrityActions::loadInventory',
]);

agent_register_role('agent-content-integrity', 'integrity_auditor', [
    'label' => '完整性审计',
    'description' => '审计站点内容清单的完整性与一致性（只读分析）。',
]);

agent_register_action('agent-content-integrity', [
    'id' => 'summary',
    'label' => '内容清单摘要',
    'description' => '只读统计当前账号可读的内容资源数量，用于完整性核对。',
    'module' => 'integrity',
    'operation' => 'read',
    'risk' => 'low',
    'mutates' => false,
    'params' => [],
    'required_permissions' => ['agent_integrity.view'],
    'executor' => 'AgentIntegrityActions::summary',
]);
