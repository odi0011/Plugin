<?php
/**
 * 可视化编辑器：内容表单接入层（1.1.0）。
 *
 * 插件不再有自己的菜单、列表页与独立编辑器。它只做一件事：给核心的
 * 内容编辑器（文章 / 产品 / 页面 / 自定义内容）**追加第三种模式**——
 * 在核心 7i 引入的两个钩子上声明自己：
 *
 *   - filter admin.content_editor.modes：追加「可视化」按钮。
 *     persist_as 固定为 code——可视化编译产物含作用域 <style>，
 *     只有管理员存得下，这与核心对代码模式的权限约束完全一致。
 *   - action admin.content_editor.panel：在表单里输出编辑面板。
 *
 * 模式键永不入库；「当前是否处于可视化」由插件自己判断：
 * 核心字段里存在托管块且结构未被外部改过 → active。
 */
if (!defined('CODE_SCHEMA_VERSION')) exit;

final class VisualEditorPanel
{
    public const MODE_KEY = 'visual-editor';

    /**
     * admin.content_editor.modes 过滤器入口。
     *
     * @param list<array<string,mixed>> $modes
     * @param array<string,mixed>       $context
     * @return list<array<string,mixed>>
     */
    public static function declareMode(array $modes, array $context): array
    {
        if (!self::supportsContext($context)) return $modes;

        // 只有托管且未失配的内容才声称自己是当前模式；其余情况打开表单时
        // 停在核心模式上，用户主动点「可视化」才进导入 / 编辑流程。
        $active = false;
        $sourceKey = self::sourceKeyOf($context);
        if ($sourceKey !== null && (string)($context['current_mode'] ?? '') === 'code') {
            $state = VisualEditorContent::stateOf((string)$context['type'], (int)$context['id']);
            $active = $state !== null && $state['managed'] && !$state['stale'];
        }

        $modes[] = [
            'key' => self::MODE_KEY,
            'label' => '可视化',
            'icon' => 'bi-layout-wtf',
            'persist_as' => 'code',
            'active' => $active,
            'plugin' => '可视化编辑器',
        ];
        return $modes;
    }

    /** admin.content_editor.panel 动作入口：输出面板与初始状态。 */
    public static function renderPanel(array $context): void
    {
        if (!self::supportsContext($context)) return;
        $type = (string)$context['type'];
        $id = (int)$context['id'];
        $field = (string)($context['html_field'] ?? 'content');
        $record = is_array($context['record'] ?? null) ? $context['record'] : [];
        $currentHtml = is_string($record[$field] ?? null) ? (string)$record[$field] : '';

        $managed = VisualEditorContent::extract($currentHtml);
        $stale = false;
        $tree = null;
        if ($managed !== null) {
            $tree = $managed['tree'];
            $withoutMarkers = trim(str_replace(
                [VisualEditorContent::MARKER_START, VisualEditorContent::MARKER_END],
                '',
                $currentHtml
            ));
            $stale = !str_starts_with($withoutMarkers, $managed['rendered']);
        }

        echo \plugin_view('visual-editor', 'admin/panel', [
            'modeKey' => self::MODE_KEY,
            'sourceType' => $type,
            'sourceId' => $id,
            'sourceKey' => $type . '-' . $id,
            'fieldName' => $field,
            'canUse' => \App\Core\Auth::isAdmin() && \App\Core\Auth::can('visual_editor.edit'),
            'canUseCodeWidget' => \App\Core\Auth::can('visual_editor.code'),
            'managedTree' => $tree === null ? null : json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
            'stale' => $stale,
            'convertUrl' => \admin_url('/visual-editor/convert'),
            'csrfToken' => \App\Core\Csrf::token(),
            'widgets' => VisualEditorSchema::widgets(),
            'styleProperties' => VisualEditorSchema::styleProperties(),
            'styleLabels' => VisualEditorSchema::styleLabels(),
            'fieldLabels' => VisualEditorSchema::fieldLabels(),
            'breakpoints' => VisualEditorSettings::breakpoints(),
            'containerMax' => VisualEditorSettings::containerMax(),
            // 结构基础样式由服务端下发一份：JS 编译产物与服务端逐字节同源。
            'baseCss' => VisualEditorStyleCompiler::baseCss(),
            'canvasHtml' => $tree !== null
                ? VisualEditorRenderer::render($type . '-' . $id, $tree, true)
                : '',
            'canvasCss' => $tree !== null
                ? VisualEditorStyleCompiler::compile($type . '-' . $id, $tree)
                : '',
        ]);
    }

    /**
     * 面板资产只在四个内容表单页注入。admin.head/footer 是全后台钩子，
     * 没有上下文参数，只能按路径识别；多注入无害但会让每个后台页面都
     * 背上编辑器的 JS，所以还是收窄。
     */
    public static function shouldEnqueueAssets(): bool
    {
        $path = '/' . ltrim((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
        $prefix = function_exists('admin_prefix') ? trim(admin_prefix(), '/') : 'admin';
        if ($prefix !== '' && !str_starts_with($path, '/' . $prefix . '/')) {
            return false;
        }
        $relative = ltrim(substr($path, strlen($prefix) + 1), '/');
        // 转换端点自己 echo JSON 后 exit，不经过 layout，天然不会触发本判断。
        return (bool)preg_match(
            '#^(?:(?:pages|articles|products)/(?:create|edit/\d+)|content-entries/[a-z0-9_-]+/(?:create|edit/\d+))(?:[?#]|$)#',
            $relative
        );
    }

    public static function headAssets(): void
    {
        if (!self::shouldEnqueueAssets()) return;
        echo '<link rel="stylesheet" href="' . e(\plugin_url('visual-editor', 'editor.css')) . '">';
    }

    public static function footerAssets(): void
    {
        if (!self::shouldEnqueueAssets()) return;
        echo '<script src="' . e(\plugin_url('visual-editor', 'editor.js')) . '" defer></script>';
    }

    /** @param array<string,mixed> $context */
    private static function supportsContext(array $context): bool
    {
        $type = (string)($context['type'] ?? '');
        $id = (int)($context['id'] ?? 0);
        if ($id < 1 || VisualEditorContent::source($type, $id) === null) {
            return false;
        }
        // 非管理员存不下编译产物里的 <style>，给他们看一个存不住的按钮是欺骗。
        return \App\Core\Auth::isAdmin();
    }

    /** @param array<string,mixed> $context */
    private static function sourceKeyOf(array $context): ?string
    {
        $source = VisualEditorContent::source((string)($context['type'] ?? ''), (int)($context['id'] ?? 0));
        return $source === null ? null : $source['key'];
    }
}
