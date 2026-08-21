<?php
/**
 * Agent 内链体检
 *
 * 注册一个只读 Agent 动作与一个资源类型：扫描已发布内容正文里的站内链接，
 * 找出指向不存在 slug 的死链。全程只读，不写任何表。
 */
if (!defined('CODE_SCHEMA_VERSION')) exit;

require_once __DIR__ . '/AgentActions.php';

agent_register_action('agent-link-auditor', [
    'id' => 'scan',
    'label' => '扫描站内死链',
    'description' => '只读扫描已发布页面与文章正文中的站内链接，返回指向不存在 slug 的死链清单。',
    'module' => 'link_audit',
    'operation' => 'read',
    'risk' => 'low',
    'mutates' => false,
    'params' => ['limit', 'types'],
    'required_permissions' => ['agent_link_audit.view'],
    'executor' => 'AgentLinkAuditorActions::scan',
]);

agent_register_resource('agent-link-auditor', 'broken_links', [
    'label' => '站内死链',
    'icon' => 'bi-link-45deg',
    'fields' => ['source_type', 'source_id', 'source_title', 'target', 'reason'],
    'permission' => 'agent_link_audit.view',
    'loader' => 'AgentLinkAuditorActions::loadBrokenLinks',
]);
