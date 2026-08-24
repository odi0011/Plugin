<?php
/**
 * 可视化编辑器：声明式扩展 API 的执行器。
 *
 * plugin.json 的 api 段一条声明派生三面（路由 / ApiDoc 契约 / Agent 动作），
 * 三面共用这里的方法，HTTP 与 Agent 网关也共用同一个核心控制器
 * （PluginExtApiController），因此校验、权限求交、审计只发生一次。
 *
 * 两条本文件自己承担的责任：
 *   1. **执行前再查一次授权。** 控制器已经按声明查过权限点，这里仍然复查一遍
 *      账号状态与权限——AGENTS.md 要求写操作紧邻执行处重新求交，
 *      而不是依赖上游某一层查过。
 *   2. **参数只按声明收。** 传输层给的都是字符串标量（每个 ≤4KB），
 *      这里把它们翻译成模型字段，未声明的字段不可能到达这里。
 *
 * 传输边界说明（刻意如此）：扩展 API 的参数是标量且有 4KB 上限，
 * 因此**整棵文档树的批量保存不在这一面**——那是后台编辑器的 CSRF 保护端点。
 * 公开 API 与 Agent 拿到的是等价的细粒度操作（建文档、加区块、加控件、
 * 改内容、移动、删除、发布、回滚），组合起来同样能从零搭出一整页。
 */
if (!defined('CODE_SCHEMA_VERSION')) exit;

final class VisualEditorApi
{
    // ============================================================
    // 只读
    // ============================================================

    /**
     * @param array<string,string> $input
     * @param array<string,mixed>  $context
     */
    public static function listDocuments(array $input, array $context): array
    {
        $actor = self::actor($context, 'visual_editor.view');
        if (is_string($actor)) return self::fail($actor, 403, 'permission_denied');

        $status = strtolower(trim((string)($input['status'] ?? '')));
        if ($status !== '' && !in_array($status, VisualEditorDocument::STATUSES, true)) {
            return self::fail('status 只能是 draft 或 published', 422, 'validation_error');
        }
        $page = max(1, (int)($input['page'] ?? 1));
        $perPage = (int)($input['per_page'] ?? 20);
        $perPage = $perPage > 0 ? min(50, $perPage) : 20;

        $result = VisualEditorDocument::paginate($status, (string)($input['keyword'] ?? ''), $page, $perPage);
        $items = [];
        foreach ($result['rows'] as $row) {
            $items[] = [
                'id' => (int)$row['id'],
                'slug' => (string)$row['slug'],
                'title' => (string)$row['title'],
                'status' => (string)$row['status'],
                'lock_version' => (int)$row['lock_version'],
                'url' => VisualEditorRouting::publicUrl((string)$row['slug']),
                'updated_at' => (string)$row['updated_at'],
            ];
        }
        return self::ok('共 ' . $result['total'] . ' 个文档', [
            'items' => $items,
            'total' => $result['total'],
            'page' => $page,
            'per_page' => $perPage,
            'truncated' => $result['total'] > ($page * $perPage),
        ]);
    }

    /**
     * @param array<string,string> $input
     * @param array<string,mixed>  $context
     */
    public static function getDocument(array $input, array $context): array
    {
        $actor = self::actor($context, 'visual_editor.view');
        if (is_string($actor)) return self::fail($actor, 403, 'permission_denied');

        $row = self::resolveDocument($input);
        if ($row === null) return self::fail('文档不存在', 404, 'not_found');

        $tree = VisualEditorDocument::tree($row);
        $outline = VisualEditorDocument::flatOutline($tree, 100);
        return self::ok('已读取文档轮廓', [
            'id' => (int)$row['id'],
            'slug' => (string)$row['slug'],
            'title' => (string)$row['title'],
            'status' => (string)$row['status'],
            'lock_version' => (int)$row['lock_version'],
            'seo_title' => (string)$row['seo_title'],
            'seo_description' => (string)$row['seo_description'],
            'url' => VisualEditorRouting::publicUrl((string)$row['slug']),
            'widget_count' => VisualEditorDocument::countWidgets($tree),
            'elements' => $outline['items'],
            'elements_total' => $outline['total'],
            'truncated' => $outline['truncated'],
        ]);
    }

    /**
     * @param array<string,string> $input
     * @param array<string,mixed>  $context
     */
    public static function listRevisions(array $input, array $context): array
    {
        $actor = self::actor($context, 'visual_editor.view');
        if (is_string($actor)) return self::fail($actor, 403, 'permission_denied');

        $row = self::resolveDocument($input);
        if ($row === null) return self::fail('文档不存在', 404, 'not_found');

        $limit = (int)($input['limit'] ?? 20);
        $items = [];
        foreach (VisualEditorDocument::revisions((int)$row['id'], $limit > 0 ? $limit : 20) as $revision) {
            $items[] = [
                'id' => (int)$revision['id'],
                'note' => (string)$revision['note'],
                'created_by' => (int)$revision['created_by'],
                'created_at' => (string)$revision['created_at'],
            ];
        }
        return self::ok('共 ' . count($items) . ' 条修订', ['items' => $items, 'document_id' => (int)$row['id']]);
    }

    // ============================================================
    // 写：文档元信息
    // ============================================================

    /**
     * @param array<string,string> $input
     * @param array<string,mixed>  $context
     */
    public static function createDocument(array $input, array $context): array
    {
        $actor = self::actor($context, 'visual_editor.edit');
        if (is_string($actor)) return self::fail($actor, 403, 'permission_denied');

        $result = VisualEditorDocument::create(
            (string)($input['title'] ?? ''),
            (string)($input['slug'] ?? ''),
            (string)($input['seo_title'] ?? ''),
            (string)($input['seo_description'] ?? ''),
            (int)$actor['id']
        );
        if (empty($result['ok'])) {
            return self::fail((string)$result['message'], (int)$result['status'], 'validation_error');
        }
        $row = VisualEditorDocument::find((int)$result['id']);
        return self::ok('已创建草稿文档', [
            'id' => (int)$result['id'],
            'slug' => (string)$result['slug'],
            'status' => 'draft',
            'lock_version' => (int)($row['lock_version'] ?? 1),
            'column_id' => self::firstColumnId($row ?? []),
            'core_slug_conflict' => VisualEditorDocument::coreSlugConflict((string)$result['slug']),
            'admin_url' => \admin_url('/visual-editor/edit/' . (int)$result['id']),
        ]);
    }

    /**
     * @param array<string,string> $input
     * @param array<string,mixed>  $context
     */
    public static function updateDocument(array $input, array $context): array
    {
        $actor = self::actor($context, 'visual_editor.edit');
        if (is_string($actor)) return self::fail($actor, 403, 'permission_denied');

        $row = self::resolveDocument($input);
        if ($row === null) return self::fail('文档不存在', 404, 'not_found');

        $fields = [];
        foreach (['title', 'slug', 'seo_title', 'seo_description'] as $field) {
            if (array_key_exists($field, $input)) $fields[$field] = (string)$input[$field];
        }
        $result = VisualEditorDocument::updateMeta(
            (int)$row['id'],
            $fields,
            (int)($input['lock_version'] ?? 0),
            (int)$actor['id']
        );
        if (empty($result['ok'])) {
            return self::fail((string)$result['message'], (int)$result['status'], self::codeForStatus((int)$result['status']));
        }
        $fresh = VisualEditorDocument::find((int)$row['id']);
        return self::ok('已保存文档元信息', [
            'id' => (int)$row['id'],
            'slug' => (string)($fresh['slug'] ?? ''),
            'lock_version' => (int)($fresh['lock_version'] ?? 0),
        ]);
    }

    // ============================================================
    // 写：结构
    // ============================================================

    /**
     * @param array<string,string> $input
     * @param array<string,mixed>  $context
     */
    public static function addSection(array $input, array $context): array
    {
        return self::mutateTree($input, $context, '追加区块', static function (array $tree) use ($input): array {
            $result = VisualEditorDocument::insertSection(
                $tree,
                (int)($input['columns'] ?? 1),
                strtolower(trim((string)($input['layout'] ?? 'boxed'))),
                array_key_exists('position', $input) ? (int)$input['position'] : -1
            );
            return [
                'ok' => (bool)$result['ok'],
                'message' => (string)$result['message'],
                'tree' => $result['tree'],
                'data' => $result['ids'],
            ];
        });
    }

    /**
     * @param array<string,string> $input
     * @param array<string,mixed>  $context
     */
    public static function addWidget(array $input, array $context): array
    {
        $type = strtolower(trim((string)($input['type'] ?? '')));
        $definition = VisualEditorSchema::widget($type);
        if ($definition === null) {
            return self::fail(
                '未知控件类型：' . ($type !== '' ? $type : '(空)')
                . '（可用：' . implode(' / ', VisualEditorSchema::widgetTypes()) . '）',
                422,
                'validation_error'
            );
        }
        // 自定义 HTML 控件是唯一能带结构标签的入口，单独要一次授权。
        if ((string)$definition['needs_permission'] !== '') {
            $gate = self::actor($context, (string)$definition['needs_permission']);
            if (is_string($gate)) return self::fail($gate, 403, 'permission_denied');
        }
        $content = self::widgetContentFromInput($definition, $input);

        return self::mutateTree($input, $context, '追加控件', static function (array $tree) use ($input, $type, $content): array {
            $result = VisualEditorDocument::insertWidget(
                $tree,
                strtolower(trim((string)($input['column'] ?? ''))),
                $type,
                $content,
                array_key_exists('position', $input) ? (int)$input['position'] : -1
            );
            return [
                'ok' => (bool)$result['ok'],
                'message' => (string)$result['message'],
                'tree' => $result['tree'],
                'data' => ['widget_id' => (string)$result['id'], 'type' => $type],
            ];
        });
    }

    /**
     * @param array<string,string> $input
     * @param array<string,mixed>  $context
     */
    public static function updateElement(array $input, array $context): array
    {
        $elementId = strtolower(trim((string)($input['element'] ?? '')));
        $row = self::resolveDocument($input);
        if ($row === null) return self::fail('文档不存在', 404, 'not_found');
        $tree = VisualEditorDocument::tree($row);
        $located = VisualEditorDocument::locate($tree, $elementId);
        if ($located === null || $located[0] !== 'widget') {
            return self::fail('控件不存在：' . ($elementId !== '' ? $elementId : '(空)'), 404, 'not_found');
        }
        [, [$sectionIndex, $columnIndex, $widgetIndex]] = $located;
        $widget = $tree['sections'][$sectionIndex]['columns'][$columnIndex]['widgets'][$widgetIndex];
        $definition = VisualEditorSchema::widget((string)$widget['type']);
        if ($definition === null) {
            return self::fail('控件类型已不受支持', 409, 'validation_error');
        }
        if ((string)$definition['needs_permission'] !== '') {
            $gate = self::actor($context, (string)$definition['needs_permission']);
            if (is_string($gate)) return self::fail($gate, 403, 'permission_denied');
        }

        $fields = self::widgetContentFromInput($definition, $input, true);
        if ($fields === []) {
            return self::fail(
                (string)$definition['label'] . ' 控件可改字段：' . implode('、', array_keys((array)$definition['fields'])),
                422,
                'validation_error'
            );
        }

        return self::mutateTree($input, $context, '修改控件内容', static function (array $tree) use ($elementId, $fields): array {
            $result = VisualEditorDocument::updateWidgetContent($tree, $elementId, $fields);
            return [
                'ok' => (bool)$result['ok'],
                'message' => (string)$result['message'],
                'tree' => $result['tree'],
                'data' => ['element' => $elementId, 'fields' => implode(',', array_keys($fields))],
            ];
        });
    }

    /**
     * @param array<string,string> $input
     * @param array<string,mixed>  $context
     */
    public static function moveElement(array $input, array $context): array
    {
        $elementId = strtolower(trim((string)($input['element'] ?? '')));
        $direction = strtolower(trim((string)($input['direction'] ?? '')));
        return self::mutateTree($input, $context, '移动元素', static function (array $tree) use ($elementId, $direction): array {
            $result = VisualEditorDocument::moveElement($tree, $elementId, $direction);
            return [
                'ok' => (bool)$result['ok'],
                'message' => (string)$result['message'],
                'tree' => $result['tree'],
                'data' => ['element' => $elementId, 'direction' => $direction],
            ];
        });
    }

    /**
     * @param array<string,string> $input
     * @param array<string,mixed>  $context
     */
    public static function removeElement(array $input, array $context): array
    {
        $elementId = strtolower(trim((string)($input['element'] ?? '')));
        return self::mutateTree($input, $context, '删除元素', static function (array $tree) use ($elementId): array {
            $result = VisualEditorDocument::removeElement($tree, $elementId);
            return [
                'ok' => (bool)$result['ok'],
                'message' => (string)$result['message'],
                'tree' => $result['tree'],
                'data' => ['element' => $elementId],
            ];
        });
    }

    // ============================================================
    // 写：状态与修订
    // ============================================================

    /**
     * @param array<string,string> $input
     * @param array<string,mixed>  $context
     */
    public static function publishDocument(array $input, array $context): array
    {
        $actor = self::actor($context, 'visual_editor.publish');
        if (is_string($actor)) return self::fail($actor, 403, 'permission_denied');

        $row = self::resolveDocument($input);
        if ($row === null) return self::fail('文档不存在', 404, 'not_found');

        $result = VisualEditorDocument::setStatus(
            (int)$row['id'],
            (string)($input['status'] ?? ''),
            (int)($input['lock_version'] ?? 0),
            (int)$actor['id']
        );
        if (empty($result['ok'])) {
            return self::fail((string)$result['message'], (int)$result['status'], self::codeForStatus((int)$result['status']));
        }
        $fresh = VisualEditorDocument::find((int)$row['id']);
        return self::ok((string)$result['message'], [
            'id' => (int)$row['id'],
            'status' => (string)($fresh['status'] ?? ''),
            'lock_version' => (int)($fresh['lock_version'] ?? 0),
            'url' => VisualEditorRouting::publicUrl((string)($fresh['slug'] ?? '')),
        ]);
    }

    /**
     * @param array<string,string> $input
     * @param array<string,mixed>  $context
     */
    public static function rollbackDocument(array $input, array $context): array
    {
        $actor = self::actor($context, 'visual_editor.edit');
        if (is_string($actor)) return self::fail($actor, 403, 'permission_denied');

        $row = self::resolveDocument($input);
        if ($row === null) return self::fail('文档不存在', 404, 'not_found');

        $result = VisualEditorDocument::rollback(
            (int)$row['id'],
            (int)($input['revision'] ?? 0),
            (int)($input['lock_version'] ?? 0),
            (int)$actor['id'],
            self::canUseCode($actor)
        );
        if (empty($result['ok'])) {
            return self::fail((string)$result['message'], (int)$result['status'], self::codeForStatus((int)$result['status']));
        }
        return self::ok('已回滚', [
            'id' => (int)$row['id'],
            'lock_version' => (int)($result['lock_version'] ?? 0),
            'revision' => (int)($input['revision'] ?? 0),
        ]);
    }

    /**
     * @param array<string,string> $input
     * @param array<string,mixed>  $context
     */
    public static function deleteDocument(array $input, array $context): array
    {
        $actor = self::actor($context, 'visual_editor.edit');
        if (is_string($actor)) return self::fail($actor, 403, 'permission_denied');

        $row = self::resolveDocument($input);
        if ($row === null) return self::fail('文档不存在', 404, 'not_found');

        $result = VisualEditorDocument::delete((int)$row['id'], (int)($input['lock_version'] ?? 0));
        if (empty($result['ok'])) {
            return self::fail((string)$result['message'], (int)$result['status'], self::codeForStatus((int)$result['status']));
        }
        return self::ok('已删除文档', ['id' => (int)$row['id'], 'slug' => (string)$row['slug']]);
    }

    // ============================================================
    // 共用
    // ============================================================

    /**
     * 所有改树的端点共用的外壳：定位文档 → 复查权限 → 跑纯函数改树 → 落盘。
     *
     * 把乐观锁、修订、CSS 重编译都收在 Document::saveTree() 里，
     * 因此每个端点只需要写「树怎么变」这一件事。
     *
     * @param array<string,string> $input
     * @param array<string,mixed>  $context
     * @param callable(array):array $mutator
     */
    private static function mutateTree(array $input, array $context, string $note, callable $mutator): array
    {
        $actor = self::actor($context, 'visual_editor.edit');
        if (is_string($actor)) return self::fail($actor, 403, 'permission_denied');

        $row = self::resolveDocument($input);
        if ($row === null) return self::fail('文档不存在', 404, 'not_found');

        $expectedVersion = (int)($input['lock_version'] ?? 0);
        if ($expectedVersion > 0 && (int)$row['lock_version'] !== $expectedVersion) {
            return self::fail('文档已被其他人修改（当前版本 ' . (int)$row['lock_version'] . '）', 409, 'conflict');
        }

        $outcome = $mutator(VisualEditorDocument::tree($row));
        if (empty($outcome['ok'])) {
            return self::fail((string)$outcome['message'], 422, 'validation_error');
        }

        $saved = VisualEditorDocument::saveTree(
            (int)$row['id'],
            (array)$outcome['tree'],
            $expectedVersion,
            (int)$actor['id'],
            $note,
            self::canUseCode($actor)
        );
        if (empty($saved['ok'])) {
            return self::fail((string)$saved['message'], (int)$saved['status'], self::codeForStatus((int)$saved['status']));
        }

        $data = is_array($outcome['data'] ?? null) ? $outcome['data'] : [];
        $data['id'] = (int)$row['id'];
        $data['lock_version'] = (int)($saved['lock_version'] ?? 0);
        if (!empty($saved['warnings'])) {
            $data['warnings'] = implode('；', array_slice((array)$saved['warnings'], 0, 5));
        }
        return self::ok((string)$outcome['message'], $data);
    }

    /**
     * 执行前复查账号与权限。
     *
     * 控制器已经按声明查过一次，这里再查是刻意的：一次写操作的授权判断必须
     * 紧邻真正落库的地方，不能依赖上游某层的结论——上游改了调用顺序也不会
     * 让这里失去保护。
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>|string 通过返回账号行，否则返回错误文案
     */
    private static function actor(array $context, string $permission): array|string
    {
        $userId = (int)($context['user']['id'] ?? 0);
        if ($userId <= 0) return '无法确认调用账号';
        $user = \App\Models\User::find($userId);
        if (!is_array($user) || (string)($user['status'] ?? '') !== 'active') {
            return '调用账号不可用';
        }
        if ((string)($user['role'] ?? '') === 'admin') return $user;
        if (!\App\Core\Permission::userCan($user, $permission)) {
            return '当前账号缺少权限：' . $permission;
        }
        return $user;
    }

    /** @param array<string,mixed> $actor */
    private static function canUseCode(array $actor): bool
    {
        if ((string)($actor['role'] ?? '') === 'admin') return true;
        return \App\Core\Permission::userCan($actor, 'visual_editor.code');
    }

    /**
     * 按 id 或 slug 定位文档。两个都没给就是参数错误，不猜「第一个文档」。
     *
     * @param array<string,string> $input
     * @return array<string,mixed>|null
     */
    private static function resolveDocument(array $input): ?array
    {
        $id = (int)($input['id'] ?? 0);
        if ($id > 0) return VisualEditorDocument::find($id);
        $slug = trim((string)($input['slug'] ?? ''));
        return $slug !== '' ? VisualEditorDocument::findBySlug($slug) : null;
    }

    /**
     * 从标量入参里挑出该控件声明过的字段。
     *
     * @param array<string,mixed>  $definition
     * @param array<string,string> $input
     * @param bool $onlyProvided true 时只取调用方真的传了的字段（改内容用），
     *                           false 时缺省字段回落控件默认值（新建用）
     * @return array<string,mixed>
     */
    private static function widgetContentFromInput(array $definition, array $input, bool $onlyProvided = false): array
    {
        $content = [];
        foreach (array_keys((array)$definition['fields']) as $field) {
            if (array_key_exists($field, $input)) {
                $content[$field] = $input[$field];
                continue;
            }
            if (!$onlyProvided && array_key_exists($field, (array)$definition['defaults'])) {
                $content[$field] = $definition['defaults'][$field];
            }
        }
        return $content;
    }

    /** 新建文档后把第一栏的 id 一并返回，调用方不必再拉一次轮廓才能加控件。 */
    private static function firstColumnId(array $row): string
    {
        if ($row === []) return '';
        $tree = VisualEditorDocument::tree($row);
        return (string)($tree['sections'][0]['columns'][0]['id'] ?? '');
    }

    private static function codeForStatus(int $status): string
    {
        return match ($status) {
            403 => 'permission_denied',
            404 => 'not_found',
            409 => 'conflict',
            default => 'validation_error',
        };
    }

    /** @param array<string,mixed> $data */
    private static function ok(string $message, array $data = []): array
    {
        return ['ok' => true, 'message' => $message, 'http_status' => 200, 'data' => $data];
    }

    private static function fail(string $message, int $status, string $code): array
    {
        return ['ok' => false, 'message' => $message, 'http_status' => $status, 'error_code' => $code, 'data' => []];
    }
}
