<?php
/**
 * 可视化编辑器：编辑器界面自己的传输层。
 *
 * 两个端点都走后台 POST + 会话 + CSRF，而不是公开扩展 API：一棵编辑树远超扩展 API
 * 的单参数 4KB / 响应字符串 4KB 上限（见 PluginApiRouteService::boundedData）。
 * 公开 API 面因此保持只读摘要（plugin.json api 段）。
 *
 *   POST /admin/visual-editor/convert  打开编辑器：拿到树 + 首屏画布
 *   POST /admin/visual-editor/save     保存前：把树落进插件存储
 *
 * 「保存」为什么要单独一个端点：核心字段由原表单提交写入（这样修订 / 排期 / 审批
 * 全都照常生效），但字段里现在只有渲染产物，树得有地方去。编辑器在提交表单之前
 * 先打这个端点把树存好，再让表单照常提交。
 */
if (!defined('CODE_SCHEMA_VERSION')) exit;

final class VisualEditorAdminTransport
{
    /**
     * POST /admin/visual-editor/convert
     *
     * 把一个内容条目的当前状态变成可编辑的树并**只读地**返回——不写内容表。
     * 首次编辑时这里最慢（要把整段 HTML 解析成树），所以界面在加载动画上给了提示。
     *
     * 唯一的写入是首次导入时把树与原文备份落进插件存储：那不是用户内容，
     * 而是插件自己的工作副本，早写晚写都不影响核心数据。
     */
    public static function convert(): void
    {
        $source = self::guard('visual_editor.edit');

        $field = VisualEditorContent::loadField($source);
        if ($field === null) {
            self::json(['ok' => false, 'message' => '内容不存在或已被删除'], 404);
        }

        $resolved = VisualEditorContent::resolveTree($source, $field, (string)($_POST['reimport'] ?? '') === '1');
        $tree = $resolved['tree'];
        if ($resolved['imported']) {
            // 首次接管：把原文整段留档，用户之后随时可以还原回来。
            VisualEditorStore::save($source['key'], $tree, VisualEditorRenderer::render($source['key'], $tree, false), $field);
        }

        $sections = is_array($tree['sections'] ?? null) ? count($tree['sections']) : 0;
        self::json([
            'ok' => true,
            'message' => ($resolved['imported'] ? '已导入 ' : '已载入 ') . $sections . ' 个区块',
            'data' => [
                'source_key' => $source['key'],
                'tree' => $tree,
                'imported' => $resolved['imported'],
                'stale' => $resolved['stale'],
                'has_original' => VisualEditorStore::original($source['key']) !== null,
                // 画布初始 HTML/CSS 一并由服务端渲染，客户端不重复实现首屏渲染。
                'canvas_html' => VisualEditorRenderer::render($source['key'], $tree, true),
                'canvas_css' => VisualEditorStyleCompiler::compile($source['key'], $tree),
            ],
        ], 200);
    }

    /**
     * POST /admin/visual-editor/save
     *
     * 落存编辑树，并把**服务端重新渲染**的产物回给编辑器写进表单字段。
     *
     * 关键在「服务端重新渲染」：客户端发上来的只有树，HTML 与 CSS 一律由 PHP 侧的
     * Renderer / StyleCompiler 生成。这样即使有人绕过界面直接构造请求，最终进入
     * 内容字段的也只有白名单允许的标签与声明——normalize() 先把树本身收拾干净。
     */
    public static function save(): void
    {
        $source = self::guard('visual_editor.edit');

        $raw = (string)($_POST['tree'] ?? '');
        if ($raw === '' || strlen($raw) > VisualEditorSchema::MAX_DOC_BYTES) {
            self::json(['ok' => false, 'message' => '文档过大或为空，无法保存'], 413);
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            self::json(['ok' => false, 'message' => '文档格式不正确'], 422);
        }

        $allowCode = \App\Core\Auth::can('visual_editor.code');
        $tree = VisualEditorDocumentShape::normalize($decoded, $allowCode);
        $rendered = VisualEditorRenderer::render($source['key'], $tree, false);
        $css = VisualEditorStyleCompiler::compile($source['key'], $tree);

        // 原文备份只在存储里还没有备份时写入；此处传入当前字段值，
        // Store::save() 自己判断要不要收（已有备份就不动，避免用渲染产物覆盖原文）。
        $current = VisualEditorContent::loadField($source);
        if (!VisualEditorStore::save($source['key'], $tree, $rendered, $current)) {
            self::json(['ok' => false, 'message' => '插件存储写入失败，请检查 storage 目录权限'], 500);
        }

        self::json([
            'ok' => true,
            'message' => '文档已保存，提交表单后生效',
            'data' => [
                'source_key' => $source['key'],
                'block' => VisualEditorContent::wrap($rendered, $css),
                'canvas_html' => VisualEditorRenderer::render($source['key'], $tree, true),
                'canvas_css' => $css,
            ],
        ], 200);
    }

    /**
     * POST /admin/visual-editor/restore
     *
     * 还原到首次接管前的原始内容。同样只读地返回，真正写字段由表单提交完成——
     * 「不影响原内容」这条承诺要能被用户亲手验证，否则只是句空话。
     */
    public static function restore(): void
    {
        $source = self::guard('visual_editor.edit');
        $original = VisualEditorStore::original($source['key']);
        if ($original === null) {
            self::json(['ok' => false, 'message' => '没有找到原始内容备份'], 404);
        }
        self::json([
            'ok' => true,
            'message' => '已取出原始内容，提交表单后生效',
            'data' => ['content' => $original],
        ], 200);
    }

    /**
     * 三道闸门 + 源解析，三个端点共用。任一不过就直接结束请求。
     *
     * @return array{type:string,id:int,table:string,field:string,content_type:?string,key:string}
     */
    private static function guard(string $permission): array
    {
        if (!\App\Core\Auth::isAdmin()) {
            self::json(['ok' => false, 'message' => '只有管理员能使用可视化编辑'], 403);
        }
        // 核心 Router 已经用 _csrf 校验过一遍（不通过连这里都到不了），
        // 这里再自查一次：端点将来若被别的入口调用，校验不能只靠上游。
        $token = (string)($_POST['_csrf'] ?? ($_POST['csrf_token'] ?? ''));
        if (!\App\Core\Csrf::verify($token)) {
            self::json(['ok' => false, 'message' => 'CSRF 校验失败，请刷新页面重试'], 419);
        }
        if (!\App\Core\Auth::can($permission)) {
            self::json(['ok' => false, 'message' => '缺少 ' . $permission . ' 权限'], 403);
        }
        $source = VisualEditorContent::source(
            (string)($_POST['source_type'] ?? ''),
            (int)($_POST['source_id'] ?? 0)
        );
        if ($source === null) {
            self::json(['ok' => false, 'message' => '源类型或 ID 不合法'], 422);
        }
        return $source;
    }

    private static function json(array $payload, int $status): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-store');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        exit;
    }
}
