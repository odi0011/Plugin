<?php
/**
 * 可视化编辑器：后台端点。
 *
 * 这一面是**编辑器界面自己的传输层**，不是公开 API：
 * 整棵文档树一次提交会远超扩展 API 的 4KB/参数上限，因此它走后台
 * POST + CSRF，权限用 Auth::requirePermission() 逐个端点判。
 * 公开 API 与 Agent 拿到的是等价的细粒度操作（见 src/Api.php 顶部说明），
 * 两面最终都汇到 VisualEditorDocument 的同一批写入方法上。
 */
if (!defined('CODE_SCHEMA_VERSION')) exit;

final class VisualEditorAdmin
{
    /** 文档列表。 */
    public static function index(): string
    {
        \App\Core\Auth::requirePermission('visual_editor.view');
        $status = strtolower(trim((string)($_GET['status'] ?? '')));
        $keyword = trim((string)($_GET['q'] ?? ''));
        $page = max(1, (int)($_GET['page'] ?? 1));
        $result = VisualEditorDocument::paginate($status, $keyword, $page, 20);

        return \plugin_view('visual-editor', 'admin/index', [
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'perPage' => 20,
            'status' => $status,
            'keyword' => $keyword,
            'canEdit' => \App\Core\Auth::can('visual_editor.edit'),
            'canPublish' => \App\Core\Auth::can('visual_editor.publish'),
        ]);
    }

    /** 编辑器。 */
    public static function edit(string $id): string
    {
        \App\Core\Auth::requirePermission('visual_editor.edit');
        $row = VisualEditorDocument::find((int)$id);
        if ($row === null) {
            \flash('error', '文档不存在');
            self::redirect('/visual-editor');
        }
        $tree = VisualEditorDocument::tree($row);

        return \plugin_view('visual-editor', 'admin/editor', [
            'doc' => $row,
            'tree' => $tree,
            'canvasHtml' => VisualEditorRenderer::render((int)$row['id'], $tree, true),
            'documentCss' => VisualEditorStyleCompiler::compile((int)$row['id'], $tree),
            'rootClass' => VisualEditorStyleCompiler::rootClass((int)$row['id']),
            'widgets' => VisualEditorSchema::widgets(),
            'styleProperties' => VisualEditorSchema::styleProperties(),
            'breakpoints' => VisualEditorSettings::breakpoints(),
            'revisions' => VisualEditorDocument::revisions((int)$row['id'], 20),
            'canPublish' => \App\Core\Auth::can('visual_editor.publish'),
            'canUseCode' => \App\Core\Auth::can('visual_editor.code'),
            'publicUrl' => VisualEditorRouting::publicUrl((string)$row['slug']),
            'previewUrl' => VisualEditorRouting::previewUrl((int)$row['id']),
            'coreConflict' => VisualEditorDocument::coreSlugConflict((string)$row['slug']),
        ]);
    }

    /** 新建：建完直接进编辑器，少一次跳转。 */
    public static function store(): void
    {
        \App\Core\Auth::requirePermission('visual_editor.edit');
        $result = VisualEditorDocument::create(
            (string)($_POST['title'] ?? ''),
            (string)($_POST['slug'] ?? ''),
            '',
            '',
            (int)(\App\Core\Auth::id() ?? 0)
        );
        if (empty($result['ok'])) {
            \flash('error', (string)$result['message']);
            self::redirect('/visual-editor');
        }
        \flash('success', '已创建，开始搭页面吧');
        self::redirect('/visual-editor/edit/' . (int)$result['id']);
    }

    /**
     * 实时预览：按提交的树渲染 HTML + CSS，**不落库**。
     *
     * 编辑器每次改动都调它，因此画布上看到的就是前台会渲染的东西——
     * 客户端不需要第二套渲染器，也不会因为「客户端预览和服务端渲染实现不同」走形。
     * 不落库是刻意的：否则每挪一个控件就写一条修订，修订列表会被噪声淹没。
     */
    public static function renderPreview(string $id): void
    {
        \App\Core\Auth::requirePermission('visual_editor.edit');
        $documentId = (int)$id;
        if (VisualEditorDocument::find($documentId) === null) {
            self::json(['ok' => false, 'message' => '文档不存在'], 404);
        }

        $raw = (string)($_POST['tree'] ?? '');
        $decoded = \App\Core\JsonGuard::decodeArray($raw, VisualEditorSchema::MAX_DOC_BYTES, 20000, 12);
        if ($decoded === null) {
            self::json(['ok' => false, 'message' => '文档数据不合法或超出体积上限'], 422);
        }

        $normalized = VisualEditorDocument::normalize($decoded);
        $tree = $normalized['tree'];
        self::json([
            'ok' => true,
            'message' => '已渲染',
            'tree' => $tree,
            'css' => VisualEditorStyleCompiler::compile($documentId, $tree),
            'html' => VisualEditorRenderer::render($documentId, $tree, true),
            'warnings' => array_slice($normalized['warnings'], 0, 8),
        ], 200);
    }

    /**
     * 保存整棵树。编辑器用 fetch 提交，因此返回 JSON 而不是重定向。
     *
     * 树从 POST 字段 tree 里取（JSON 字符串），先过 JsonGuard 的体积/深度/节点数
     * 上限，再交给 Document::saveTree() —— 归一化、乐观锁、修订、CSS 重编译
     * 都在那里发生，这里不做第二套判定。
     */
    public static function save(string $id): void
    {
        \App\Core\Auth::requirePermission('visual_editor.edit');
        $documentId = (int)$id;

        $raw = (string)($_POST['tree'] ?? '');
        $tree = \App\Core\JsonGuard::decodeArray($raw, VisualEditorSchema::MAX_DOC_BYTES, 20000, 12);
        if ($tree === null) {
            self::json(['ok' => false, 'message' => '文档数据不合法或超出体积上限'], 422);
        }

        $result = VisualEditorDocument::saveTree(
            $documentId,
            $tree,
            (int)($_POST['lock_version'] ?? 0),
            (int)(\App\Core\Auth::id() ?? 0),
            trim((string)($_POST['note'] ?? '编辑器保存')),
            \App\Core\Auth::can('visual_editor.code')
        );
        if (empty($result['ok'])) {
            self::json(['ok' => false, 'message' => (string)$result['message']], (int)$result['status']);
        }

        $fresh = VisualEditorDocument::find($documentId) ?? [];
        $freshTree = VisualEditorDocument::tree($fresh);
        self::json([
            'ok' => true,
            'message' => (string)$result['message'],
            'lock_version' => (int)($result['lock_version'] ?? 0),
            'warnings' => array_slice((array)($result['warnings'] ?? []), 0, 8),
            // 回传服务端归一化后的树与 CSS：客户端据此对齐，
            // 避免「界面上还留着一个被服务端丢掉的值」这种不一致。
            'tree' => $freshTree,
            'css' => VisualEditorStyleCompiler::compile($documentId, $freshTree),
            'html' => VisualEditorRenderer::render($documentId, $freshTree, true),
        ], 200);
    }

    /** 改元信息（标题 / slug / SEO）。 */
    public static function meta(string $id): void
    {
        \App\Core\Auth::requirePermission('visual_editor.edit');
        $fields = [];
        foreach (['title', 'slug', 'seo_title', 'seo_description'] as $field) {
            if (array_key_exists($field, $_POST)) $fields[$field] = (string)$_POST[$field];
        }
        $result = VisualEditorDocument::updateMeta(
            (int)$id,
            $fields,
            (int)($_POST['lock_version'] ?? 0),
            (int)(\App\Core\Auth::id() ?? 0)
        );
        $fresh = VisualEditorDocument::find((int)$id) ?? [];
        self::json([
            'ok' => !empty($result['ok']),
            'message' => (string)$result['message'],
            'lock_version' => (int)($fresh['lock_version'] ?? 0),
            'slug' => (string)($fresh['slug'] ?? ''),
            'public_url' => VisualEditorRouting::publicUrl((string)($fresh['slug'] ?? '')),
        ], (int)$result['status']);
    }

    /** 发布 / 撤回。 */
    public static function status(string $id): void
    {
        \App\Core\Auth::requirePermission('visual_editor.publish');
        $result = VisualEditorDocument::setStatus(
            (int)$id,
            (string)($_POST['status'] ?? ''),
            (int)($_POST['lock_version'] ?? 0),
            (int)(\App\Core\Auth::id() ?? 0)
        );
        $fresh = VisualEditorDocument::find((int)$id) ?? [];
        self::json([
            'ok' => !empty($result['ok']),
            'message' => (string)$result['message'],
            'status' => (string)($fresh['status'] ?? ''),
            'lock_version' => (int)($fresh['lock_version'] ?? 0),
        ], (int)$result['status']);
    }

    /** 回滚到某个修订。 */
    public static function rollback(string $id): void
    {
        \App\Core\Auth::requirePermission('visual_editor.edit');
        $documentId = (int)$id;
        $result = VisualEditorDocument::rollback(
            $documentId,
            (int)($_POST['revision'] ?? 0),
            (int)($_POST['lock_version'] ?? 0),
            (int)(\App\Core\Auth::id() ?? 0),
            \App\Core\Auth::can('visual_editor.code')
        );
        if (empty($result['ok'])) {
            self::json(['ok' => false, 'message' => (string)$result['message']], (int)$result['status']);
        }
        $freshTree = VisualEditorDocument::tree(VisualEditorDocument::find($documentId) ?? []);
        self::json([
            'ok' => true,
            'message' => '已回滚',
            'lock_version' => (int)($result['lock_version'] ?? 0),
            'tree' => $freshTree,
            'css' => VisualEditorStyleCompiler::compile($documentId, $freshTree),
            'html' => VisualEditorRenderer::render($documentId, $freshTree, true),
        ], 200);
    }

    /** 删除（表单提交，因此走重定向而不是 JSON）。 */
    public static function destroy(string $id): void
    {
        \App\Core\Auth::requirePermission('visual_editor.edit');
        $result = VisualEditorDocument::delete((int)$id, (int)($_POST['lock_version'] ?? 0));
        \flash(empty($result['ok']) ? 'error' : 'success', (string)$result['message']);
        self::redirect('/visual-editor');
    }

    /**
     * 草稿预览（前台路由）。
     *
     * 预览渲染的是**草稿**，因此它必须要求登录 + 查看权限——
     * 否则「草稿」这个状态就没有意义了。
     */
    public static function preview(string $id): string
    {
        \App\Core\Auth::requirePermission('visual_editor.view');
        $row = VisualEditorDocument::find((int)$id);
        if ($row === null) {
            \App\Core\Response::notFound('frontend');
            return '';
        }
        return VisualEditorFrontend::renderPage($row, true);
    }

    // ============================================================
    // 响应工具
    // ============================================================

    /** @param array<string,mixed> $payload */
    private static function json(array $payload, int $status): never
    {
        if (!headers_sent()) {
            http_response_code($status >= 100 && $status <= 599 ? $status : 200);
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-store');
        }
        echo (string)json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;
    }

    private static function redirect(string $path): never
    {
        if (!headers_sent()) {
            header('Location: ' . \admin_url($path));
        }
        exit;
    }
}
