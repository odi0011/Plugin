<?php
/**
 * Agent SEO 体检快照
 *
 * 只读统计 SEO 字段完整度，并注册一个委派角色，让主 Agent 可以把「SEO 体检」
 * 这类分析任务交给子智能体执行。
 */
if (!defined('CODE_SCHEMA_VERSION')) exit;

require_once __DIR__ . '/AgentActions.php';

agent_register_action('agent-seo-snapshot', [
    'id' => 'snapshot',
    'label' => 'SEO 字段完整度快照',
    'description' => '只读统计各内容类型的 SEO 字段完整度，返回缺失计数、完整度百分比与缺失最严重的条目清单。',
    'module' => 'seo_snapshot',
    'operation' => 'read',
    'risk' => 'low',
    'mutates' => false,
    'params' => ['type', 'limit'],
    'required_permissions' => ['agent_seo_snapshot.view'],
    'executor' => 'AgentSeoSnapshotActions::snapshot',
]);

agent_register_role('agent-seo-snapshot', 'seo_snapshot', [
    'label' => 'SEO 体检',
    'description' => '只读分析站点 SEO 字段完整度，产出缺失清单与优先级建议，不修改任何内容。',
]);
