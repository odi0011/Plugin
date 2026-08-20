<?php
/**
 * agent-content-integrity 执行器。
 *
 * 约定（由 PluginManager::agentActionExecutor 强制校验）：
 *   - 动作类执行器 (array $input, array $context)；
 *   - 资源类执行器 (array $request)，其中 mode=load|search。
 */
final class AgentIntegrityActions
{
    public static function summary(array $input, array $context): array
    {
        $counts = self::counts();
        return [
            'ok' => true,
            'message' => '已统计内容清单：页面 ' . $counts['pages']
                . ' / 文章 ' . $counts['articles']
                . ' / 产品 ' . $counts['products'],
            'data' => ['counts' => $counts, 'note' => '只读统计，不产生任何副作用。'],
        ];
    }

    public static function loadInventory(array $request): ?array
    {
        $counts = self::counts();
        $rows = [];
        foreach ($counts as $type => $count) {
            $rows[] = ['type' => 'agent_content_integrity.inventory', 'id' => $type, 'title' => self::label($type), 'count' => $count];
        }
        $mode = (string)($request['mode'] ?? 'load');
        if ($mode === 'search') {
            $keyword = trim((string)($request['keyword'] ?? ''));
            if ($keyword !== '') {
                $rows = array_values(array_filter($rows, static fn (array $row): bool => str_contains((string)$row['title'], $keyword)));
            }
            return $rows;
        }
        $id = (string)($request['id'] ?? '');
        foreach ($rows as $row) {
            if ($row['id'] === $id) return $row;
        }
        return null;
    }

    private static function counts(): array
    {
        $count = static function (string $table): int {
            try {
                return (int)\App\Core\Database::table($table)->count();
            } catch (\Throwable $_) {
                return 0;
            }
        };
        return [
            'pages' => $count('pages'),
            'articles' => $count('articles'),
            'products' => $count('products'),
        ];
    }

    private static function label(string $type): string
    {
        return ['pages' => '页面', 'articles' => '文章', 'products' => '产品'][$type] ?? $type;
    }
}
