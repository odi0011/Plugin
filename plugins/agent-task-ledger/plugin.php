<?php
/**
 * agent-task-ledger：写动作示例插件。
 *
 * 演示：
 *   1. agent.actions.register 注册写动作（record）与只读动作（list）；
 *   2. 插件级数据库迁移 migrations/（激活时执行）；
 *   3. 写动作与内置动作同管线：权限求交 / 审批 / 审计 / 幂等由核心完成。
 *
 * 安全边界（核心强制）：
 *   - 动作 id 必须与 plugin.json 的 "agent"."actions" 双写一致；
 *   - 执行器必须是本插件目录内的 public static 方法；
 *   - 插件代码不得绕过权限：本文件不执行任何 SQL，仅在执行器被核心调用后落库。
 */
if (!defined('CODE_SCHEMA_VERSION')) exit;

require_once __DIR__ . '/AgentActions.php';

agent_register_action('agent-task-ledger', [
    'id' => 'record',
    'label' => '记录台账条目',
    'description' => '把一条任务结论写入插件自建台账（真实写动作，需审批）。',
    'module' => 'ledger',
    'operation' => 'create',
    'risk' => 'medium',
    'mutates' => true,
    'params' => ['note', 'tag'],
    'required_permissions' => ['agent_ledger.write'],
    'executor' => 'AgentTaskLedgerActions::record',
]);

agent_register_action('agent-task-ledger', [
    'id' => 'list',
    'label' => '查看台账条目',
    'description' => '只读列出当前会话写入的台账条目（按时间倒序，最多 20 条）。',
    'module' => 'ledger',
    'operation' => 'read',
    'risk' => 'low',
    'mutates' => false,
    'params' => ['limit'],
    'required_permissions' => ['agent_ledger.view'],
    'executor' => 'AgentTaskLedgerActions::listEntries',
]);
