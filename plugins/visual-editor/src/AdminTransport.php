<?php
/**
 * 可视化编辑器：编辑器界面自己的传输层（1.1.0 只剩一个端点）。
 *
 * 导入要回传整棵编辑树，远超扩展 API 单参数 4KB / 响应字符串 4KB 的上限
 * （见 PluginApiRouteService::boundedData），所以走后台 POST + 会话 + CSRF。
 * 公开 API 面保持只读摘要（plugin.json api 段），写入路径只有表单提交本身。
 */
if (!defined('CODE_SCHEMA_VERSION')) exit;

final class VisualEditorAdminTransport
{
    /**
     * POST /admin/visual-editor/convert
     *
     * 把一个内容条目当前的核心字段值转换成编辑树并**只读地**返回——
     * 不写任何表。真正的落库发生在用户点保存时，由核心控制器完成。
     */
    public static function convert(): void
    {
        if (!\App\Core\Auth::isAdmin()) {
            self::json(['ok' => false, 'message' => '只有管理员能使用可视化编辑'], 403);
        }
        if (!\App\Core\Csrf::verify((string)($_POST['csrf_token'] ?? ''))) {
            self::json(['ok' => false, 'message' => 'CSRF 校验失败，请刷新页面重试'], 419);
        }
        if (!\App\Core\Auth::can('visual_editor.edit')) {
            self::json(['ok' => false, 'message' => '缺少 visual_editor.edit 权限'], 403);
        }

        $source = VisualEditorContent::source(
            (string)($_POST['source_type'] ?? ''),
            (int)($_POST['source_id'] ?? 0)
        );
        if ($source === null) {
            self::json(['ok' => false, 'message' => '源类型或 ID 不合法'], 422);
        }

        $field = VisualEditorContent::loadField($source);
        if ($field === null) {
            self::json(['ok' => false, 'message' => '内容不存在或已被删除'], 404);
        }

        // importField 而不是 importTree：字段已是托管块时沿用原树、只补外围
        // 新增内容——「重新导入」绝不能把已托管的区块扔掉。
        $tree = VisualEditorContent::importField($field);
        $sections = is_array($tree['sections'] ?? null) ? count($tree['sections']) : 0;
        self::json([
            'ok' => true,
            'message' => '已导入 ' . $sections . ' 个区块',
            'data' => [
                'source_key' => $source['key'],
                'tree' => $tree,
                // 画布初始 HTML/CSS 一并由服务端渲染，客户端不重复实现首屏渲染。
                'canvas_html' => VisualEditorRenderer::render($source['key'], $tree, true),
                'canvas_css' => VisualEditorStyleCompiler::compile($source['key'], $tree),
            ],
        ], 200);
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
