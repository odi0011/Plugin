<?php
/**
 * agent-run-digest 的执行器与聚合逻辑。只读。
 */
final class AgentRunDigestActions
{
    public static function digest(array $input, array $context): array
    {
        $days = (int)($input['days'] ?? 7);
        if ($days < 1 || $days > 90) {
            return [
                'ok' => false,
                'message' => 'days 只接受 1-90',
                'error_code' => 'run_digest_bad_days',
                'http_status' => 422,
            ];
        }
        $summary = self::summarize($days);
        if ($summary === null) {
            return [
                'ok' => false,
                'message' => '运行摘要表未就绪，请先激活插件以执行其数据库迁移',
                'error_code' => 'run_digest_table_missing',
                'http_status' => 503,
            ];
        }
        return [
            'ok' => true,
            'message' => '最近 ' . $days . ' 天共 ' . $summary['total'] . ' 次运行，成功 '
                . $summary['completed'] . ' 次，失败 ' . $summary['failed'] . ' 次',
            'data' => $summary,
        ];
    }

    /**
     * @return array{days:int,total:int,completed:int,failed:int,success_rate_percent:int,sessions:int,by_day:array}|null
     *         表未迁移时返回 null
     */
    public static function summarize(int $days): ?array
    {
        $since = date('Y-m-d', time() - max(0, $days - 1) * 86400);
        try {
            $rows = \App\Core\Database::table('plugin_agent_run_events')
                ->select('run_id', 'session_id', 'event_type', 'task_status', 'occurred_on')
                ->where('occurred_on', '>=', $since)
                ->orderBy('id', 'desc')
                ->limit(5000)
                ->get();
        } catch (\Throwable $_) {
            return null;
        }

        $byDay = [];
        $sessions = [];
        $completed = 0;
        $failed = 0;
        foreach ($rows as $row) {
            $day = (string)($row['occurred_on'] ?? '');
            if (!isset($byDay[$day])) $byDay[$day] = ['day' => $day, 'completed' => 0, 'failed' => 0];
            if ((string)($row['event_type'] ?? '') === 'agent.run.completed') {
                $completed++;
                $byDay[$day]['completed']++;
            } else {
                $failed++;
                $byDay[$day]['failed']++;
            }
            $sessionId = (int)($row['session_id'] ?? 0);
            if ($sessionId > 0) $sessions[$sessionId] = true;
        }
        krsort($byDay, SORT_STRING);

        $total = $completed + $failed;
        return [
            'days' => $days,
            'total' => $total,
            'completed' => $completed,
            'failed' => $failed,
            'success_rate_percent' => $total > 0 ? (int)round($completed / $total * 100) : 0,
            'sessions' => count($sessions),
            'by_day' => array_values($byDay),
        ];
    }
}
