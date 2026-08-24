<?php
/**
 * 可视化编辑器：前台页面装配。
 *
 * 刻意**复用核心前台布局**而不是自己拼一份 <html>：plugin_view() 会把 APP_PATH
 * 挂成回退路径，因此插件视图可以 extend('frontend/views/layouts/main')，
 * 于是页头页脚、SEO 标签、CSS 变量、全局 CSS/JS、frontend.* 钩子、隐私同意条
 * 全部原样生效。自己拼一份 HTML 的话，站点换了页头，插件页面就会和别的页面走形。
 */
if (!defined('CODE_SCHEMA_VERSION')) exit;

final class VisualEditorFrontend
{
    /**
     * 渲染一个文档为完整页面 HTML。
     *
     * @param array<string,mixed> $row
     * @param bool $isPreview 预览模式：渲染草稿并加不收录标记
     */
    public static function renderPage(array $row, bool $isPreview = false): string
    {
        $documentId = (int)($row['id'] ?? 0);
        $tree = VisualEditorDocument::tree($row);

        // css_cache 只是缓存：断点等设置改过之后它可能过期，因此渲染时重编译一次。
        // 编译是纯字符串拼接，成本远低于一次数据库往返，不值得为它做失效标记。
        $css = VisualEditorStyleCompiler::compile($documentId, $tree);

        return \plugin_view('visual-editor', 'frontend/document', [
            'doc' => $row,
            'bodyHtml' => VisualEditorRenderer::render($documentId, $tree, false),
            'documentCss' => $css,
            'isPreview' => $isPreview,
            'title' => self::title($row),
            'description' => (string)($row['seo_description'] ?? ''),
            'canonical' => $isPreview ? '' : VisualEditorRouting::publicUrl((string)($row['slug'] ?? '')),
        ]);
    }

    /** @param array<string,mixed> $row */
    private static function title(array $row): string
    {
        $seoTitle = trim((string)($row['seo_title'] ?? ''));
        return $seoTitle !== '' ? $seoTitle : trim((string)($row['title'] ?? ''));
    }

    /**
     * 接管一次请求：直接输出并结束。
     *
     * 出错时**放行**（记日志后 return），让核心继续走自己的 404 或其他路由——
     * 一个插件的渲染故障不该把站点整段吃掉。
     *
     * @param array<string,mixed> $row
     */
    public static function takeOver(array $row): void
    {
        try {
            $html = self::renderPage($row, false);
            if (trim($html) === '') return;
            if (!headers_sent()) {
                http_response_code(200);
                header('Content-Type: text/html; charset=UTF-8');
            }
            echo $html;
            exit;
        } catch (\Throwable $error) {
            if (\function_exists('logger')) {
                \logger('[visual-editor] 前台渲染失败，已放行本次请求：' . $error->getMessage(), 'error');
            }
        }
    }
}
