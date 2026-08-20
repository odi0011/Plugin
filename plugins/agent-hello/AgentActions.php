<?php
/**
 * agent-hello 插件动作执行器。
 *
 * 约定（由 PluginManager::agentActionExecutor 强制校验）：
 *   - 本文件必须位于插件目录内；
 *   - 方法必须是 public static；
 *   - 方法入参：动作类执行器 (array $input, array $context)；
 *             资源类执行器 (array $request)，其中 mode=load|search。
 * 返回值：动作类返回 ['ok'=>bool, 'message'=>string, ...data]；
 *         资源类 load 返回资源数组或 null；search 返回资源数组列表。
 */
final class AgentHelloActions
{
    public static function stats(array $input, array $context): array
    {
        $period = trim((string)($input['period'] ?? 'day'));
        if (!in_array($period, ['day', 'week', 'month'], true)) {
            return [
                'ok' => false,
                'message' => 'period 仅支持 day/week/month',
                'error_code' => 'agent_hello_bad_period',
                'http_status' => 422,
            ];
        }
        return [
            'ok' => true,
            'message' => '已读取 ' . $period . ' 示例统计',
            'data' => [
                'period' => $period,
                'notices' => 2,
                'note' => '这是只读示例动作，不产生任何副作用。',
            ],
        ];
    }

    public static function loadNotice(array $request): ?array
    {
        $mode = (string)($request['mode'] ?? 'load');
        $notices = [
            ['type' => 'agent_hello.notices', 'id' => 'welcome', 'title' => '欢迎使用 Agent 扩展示例', 'body' => '插件动作与内置动作走同一条权限/审批/审计管线。'],
            ['type' => 'agent_hello.notices', 'id' => 'security', 'title' => '安全边界', 'body' => '执行器必须位于插件目录内，且为 public static 方法。'],
        ];
        if ($mode === 'search') {
            $keyword = trim((string)($request['keyword'] ?? ''));
            if ($keyword !== '') {
                $notices = array_values(array_filter($notices, static function (array $notice) use ($keyword): bool {
                    return str_contains((string)$notice['title'], $keyword)
                        || str_contains((string)$notice['body'], $keyword);
                }));
            }
            $limit = max(1, min(50, (int)($request['limit'] ?? 20)));
            return array_slice($notices, 0, $limit);
        }
        $id = (string)($request['id'] ?? '');
        foreach ($notices as $notice) {
            if ($notice['id'] === $id) return $notice;
        }
        return null;
    }
}
