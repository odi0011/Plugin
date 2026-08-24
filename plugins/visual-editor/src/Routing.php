<?php
/**
 * 可视化编辑器：前台路由与接管判定。
 *
 * 为什么用 app.before_dispatch 而不是 routes.frontend.register：
 * 插件路由是在核心前台路由表**之后**注册的，而核心最后一条是页面的兜底
 * 捕获路由（/{slug}），先匹配先赢——如果靠注册路由，插件永远抢不到根级 URL，
 * 只能退到 /前缀/slug 这种两段式路径上。before_dispatch 在分发前触发，
 * 因此文档可以直接挂在站点根下。
 *
 * 接管是**保守**的，四条前提缺一不可：
 *   1. 请求是 GET；
 *   2. 路径不属于后台、API、插件资源、健康检查这些保留空间；
 *   3. 有一个同 slug 且已发布的可视化文档；
 *   4. 核心内容（页面/文章/产品）没有占用同一个 slug —— 核心永远优先。
 * 第 4 条让「装了插件之后原有页面打不开」在结构上不可能发生。
 */
if (!defined('CODE_SCHEMA_VERSION')) exit;

final class VisualEditorRouting
{
    /** 前台公开地址。前缀为空时就是 /slug。 */
    public static function publicUrl(string $slug): string
    {
        $slug = VisualEditorDocument::normalizeSlug($slug);
        if ($slug === '') return '';
        $prefix = VisualEditorSettings::urlPrefix();
        return \url('/' . ($prefix !== '' ? $prefix . '/' : '') . rawurlencode($slug));
    }

    /** 草稿预览地址。预览需要登录且有查看权限，因此它不是公开链接。 */
    public static function previewUrl(int $documentId): string
    {
        return \url('/visual-editor-preview/' . max(0, $documentId));
    }

    /**
     * 从请求路径解出候选 slug。不该接管的路径一律返回空串。
     */
    public static function slugFromPath(string $path): string
    {
        $path = '/' . trim((string)parse_url($path, PHP_URL_PATH), '/');

        // 后台前缀可能被站点改过，用运行时的实际值判断。
        $adminPrefix = \function_exists('admin_prefix') ? trim(\admin_prefix(), '/') : 'admin';
        foreach (array_filter([$adminPrefix, 'api', 'plugin-asset', 'healthz', 'health', 'assets', 'uploads', 'media']) as $reserved) {
            if ($path === '/' . $reserved || str_starts_with($path, '/' . $reserved . '/')) return '';
        }

        $prefix = VisualEditorSettings::urlPrefix();
        if ($prefix !== '') {
            if (!str_starts_with($path, '/' . $prefix . '/')) return '';
            $path = substr($path, strlen($prefix) + 1);
        }

        $segment = trim($path, '/');
        // 只接管单段路径：多段路径属于核心的归档 / 分类 / 详情空间。
        if ($segment === '' || str_contains($segment, '/')) return '';
        // 核心可能开着 .html 后缀，去掉再比。
        $segment = (string)preg_replace('/\.html$/i', '', rawurldecode($segment));
        return VisualEditorDocument::normalizeSlug($segment);
    }

    /**
     * 该请求要不要被接管。要接管则返回文档行，否则 null。
     *
     * @return array<string,mixed>|null
     */
    public static function documentForRequest(string $method, string $uri): ?array
    {
        if (strtoupper($method) !== 'GET') return null;
        $slug = self::slugFromPath($uri);
        if ($slug === '') return null;

        $row = VisualEditorDocument::findBySlug($slug, 'published');
        if ($row === null) return null;
        // 核心内容优先：同名时让核心自己渲染，避免两个来源抢一个 URL。
        if (VisualEditorDocument::coreSlugConflict($slug)) return null;
        return $row;
    }
}
