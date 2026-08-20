<?php
/**
 * agent-task-ledger 动作执行器。
 *
 * 约定（由 PluginManager::agentActionExecutor 强制校验）：
 *   - 本文件必须位于插件目录内；
 *   - 方法必须是 public static；
 *   - 入参：动作类执行器 (array $input, array $context)。
 *   - 返回值：['ok'=>bool, 'message'=>string, ...data]。
 */
final class AgentTaskLedgerActions
{
    private static function table(): string
    {
        return \App\Core\Database::prefix() . 'plugin_task_ledger';
    }

    public static function record(array $input, array $context): array
    {
        $note = trim((string)($input['note'] ?? ''));
        if ($note === '' || mb_strlen($note) > 2000) {
            return [
                'ok' => false,
                'message' => 'note 不能为空且不能超过 2000 字符',
                'error_code' => 'agent_ledger_bad_note',
                'http_status' => 422,
            ];
        }
        $tag = trim((string)($input['tag'] ?? ''));
        if (mb_strlen($tag) > 40) $tag = mb_substr($tag, 0, 40);
        $sessionId = max(0, (int)($context['session_id'] ?? 0));
        if ($sessionId <= 0) {
            return [
                'ok' => false,
                'message' => '缺少会话上下文，无法写入台账',
                'error_code' => 'agent_ledger_bad_session',
                'http_status' => 422,
            ];
        }
        try {
            $id = (int)\App\Core\Database::table('plugin_task_ledger')->insert([
                'session_id' => $sessionId,
                'note' => $note,
                'tag' => $tag,
                'created_by' => max(0, (int)($context['user']['id'] ?? 0)),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $error) {
            return [
                'ok' => false,
                'message' => '台账写入失败：' . $error->getMessage(),
                'error_code' => 'agent_ledger_write_failed',
                'http_status' => 500,
            ];
        }
        return [
            'ok' => true,
            'message' => '已写入台账条目 #' . $id,
            'data' => ['id' => $id, 'tag' => $tag],
        ];
    }

    public static function listEntries(array $input, array $context): array
    {
        $limit = max(1, min(20, (int)($input['limit'] ?? 10)));
        $sessionId = max(0, (int)($context['session_id'] ?? 0));
        try {
            $rows = \App\Core\Database::table('plugin_task_ledger')
                ->where('session_id', $sessionId)
                ->orderBy('id', 'desc')
                ->limit($limit)
                ->get();
        } catch (\Throwable $error) {
            return [
                'ok' => false,
                'message' => '台账读取失败（插件表可能未迁移，请先激活插件）',
                'error_code' => 'agent_ledger_read_failed',
                'http_status' => 500,
            ];
        }
        return [
            'ok' => true,
            'message' => '已读取 ' . count($rows) . ' 条台账',
            'data' => ['entries' => array_values($rows), 'limit' => $limit],
        ];
    }
}
