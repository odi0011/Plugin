<?php
/**
 * 可视化编辑器：公开 API 与 Agent 动作（1.1.0 起全部只读）。
 *
 * 1.0.0 曾把建树 / 改树 / 发布 / 回滚都开放成扩展 API；1.1.0 重做后插件的
 * 写入路径只剩一条——后台内容表单提交（走核心 ContentWorkflow，天然带
 * 修订、审计与幂等）。因此公开 API 收窄成两个**只读**端点：
 *
 *   - visual-content：列出哪些内容条目正在被可视化托管；
 *   - document-state：一个条目的托管摘要（是否托管 / 是否失配 / 规模计数）。
 *
 * 树本身不进 API 响应：扩展 API 的返回有界（字符串 4KB），整棵树塞进去
 * 只会被静默截断。要读完整内容请用核心的内容读取端点。
 */
if (!defined('CODE_SCHEMA_VERSION')) exit;

final class VisualEditorApi
{
    /**
     * GET /api/v1/ext/visual-editor/visual-content
     *
     * @param array<string,string> $arguments
     * @param array<string,mixed>  $context
     * @return array{ok:bool,message:string,data:array<string,mixed>}
     */
    public static function listManaged(array $arguments, array $context): array
    {
        $sourceType = strtolower(trim((string)($arguments['source_type'] ?? '')));
        if (!preg_match('/^[a-z][a-z0-9_-]{1,29}$/', $sourceType)) {
            return self::fail('source_type 不合法', 422);
        }
        $page = max(1, min(100, (int)($arguments['page'] ?? 1) ?: 1));
        $perPage = max(1, min(50, (int)($arguments['per_page'] ?? 20) ?: 20));

        // SOURCES 是 类型 => ['table'=>…,'field'=>…] 的关联数组，按键取而不是位置解构。
        $mapping = VisualEditorContent::SOURCES[$sourceType] ?? ['table' => 'content_entries', 'field' => 'content'];
        $table = (string)$mapping['table'];
        $field = (string)$mapping['field'];

        // 先用 LIKE 粗筛出候选行，再对每行做精确解析：托管判定是字符串级的事，
        // LIKE 负责把它限制在少量候选上，精确解析负责不误报。
        try {
            $query = \App\Core\Database::table($table);
            if ($table === 'content_entries') {
                $query->where('content_type', $sourceType);
            }
            // 核心 QueryBuilder 没有 whereLike()，LIKE 走 where() 的三参形式。
            $like = '%' . VisualEditorContent::MARKER_START . '%';
            $total = (clone $query)->where($field, 'LIKE', $like)->count();
            $rows = (clone $query)
                ->where($field, 'LIKE', $like)
                ->orderBy('updated_at', 'desc')
                ->paginate($perPage, $page);
        } catch (\Throwable $error) {
            return self::fail('查询失败：' . mb_substr($error->getMessage(), 0, 120), 500);
        }

        $items = [];
        foreach ((array)($rows['data'] ?? []) as $row) {
            if (!is_array($row)) continue;
            $state = VisualEditorContent::stateOf($sourceType, (int)$row['id']);
            if ($state === null || !$state['managed']) continue;
            $items[] = [
                'id' => (int)$row['id'],
                'title' => mb_substr((string)($row['title'] ?? ''), 0, 120),
                'slug' => mb_substr((string)($row['slug'] ?? ''), 0, 140),
                'sections' => $state['sections'],
                'widgets' => $state['widgets'],
                'stale' => $state['stale'],
                'bytes' => $state['bytes'],
            ];
        }

        return [
            'ok' => true,
            'message' => '共 ' . $total . ' 条可视化托管内容',
            'data' => [
                'source_type' => $sourceType,
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'items' => $items,
            ],
        ];
    }

    /**
     * GET /api/v1/ext/visual-editor/document-state
     *
     * @param array<string,string> $arguments
     * @param array<string,mixed>  $context
     * @return array{ok:bool,message:string,data:array<string,mixed>}
     */
    public static function state(array $arguments, array $context): array
    {
        $state = VisualEditorContent::stateOf(
            (string)($arguments['source_type'] ?? ''),
            (int)($arguments['source_id'] ?? 0)
        );
        if ($state === null) {
            return self::fail('source_type 或 source_id 不合法', 422);
        }
        return [
            'ok' => true,
            'message' => $state['managed']
                ? ($state['stale'] ? '已托管，但在可视化之外被修改过' : '已由可视化编辑器托管')
                : '该内容未使用可视化编辑器',
            'data' => $state + ['source_type' => strtolower(trim((string)($arguments['source_type'] ?? '')))],
        ];
    }

    private static function fail(string $message, int $status): array
    {
        return ['ok' => false, 'message' => $message, 'http_status' => $status, 'data' => []];
    }
}
