<?php
/**
 * 可视化编辑器：内容表单接入层（1.2.0）。
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

    /** 资源 URL 的缓存指纹：随插件版本走，升级后浏览器一定重新取 CSS/JS。 */
    public const VERSION = '1.3.2';

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

    /**
     * admin.content_editor.panel 动作入口：输出面板骨架与初始状态。
     *
     * 刻意**不**在这里渲染画布：树可能要从整段 HTML 解析出来，那是首次编辑时
     * 最慢的一步，放在表单渲染里会拖慢每一次打开内容页。改成用户点「可视化」时
     * 由 convert 端点按需转换，界面在蒙版加载动画上如实告知首次会久一些。
     */
    public static function renderPanel(array $context): void
    {
        if (!self::supportsContext($context)) return;
        self::$panelRendered = true;
        // head 钩子可能没认出这个页面（后台前缀被改等），这里兜一份样式，
        // 保证面板永远不会裸奔；已经输出过就不会重复。
        self::emitCss();
        $type = (string)$context['type'];
        $id = (int)$context['id'];
        $field = (string)($context['html_field'] ?? 'content');
        $record = is_array($context['record'] ?? null) ? $context['record'] : [];
        $currentHtml = is_string($record[$field] ?? null) ? (string)$record[$field] : '';
        $sourceKey = $type . '-' . $id;

        $managed = VisualEditorContent::extract($currentHtml);
        $stored = VisualEditorStore::load($sourceKey);
        $stale = false;
        if ($managed !== null) {
            $withoutMarkers = trim(str_replace(
                [VisualEditorContent::MARKER_START, VisualEditorContent::MARKER_END],
                '',
                $currentHtml
            ));
            $stale = !str_starts_with($withoutMarkers, $managed['rendered']);
        }
        // 「首次」的判断只看插件存储：没有记录就意味着这次要把整段 HTML 解析成树。
        $firstRun = $stored === null;

        echo \plugin_view('visual-editor', 'admin/panel', [
            'modeKey' => self::MODE_KEY,
            'sourceType' => $type,
            'sourceId' => $id,
            'sourceKey' => $sourceKey,
            'fieldName' => $field,
            'canUse' => \App\Core\Auth::isAdmin() && \App\Core\Auth::can('visual_editor.edit'),
            'canUseCodeWidget' => \App\Core\Auth::can('visual_editor.code'),
            'managed' => $managed !== null,
            'stale' => $stale,
            'firstRun' => $firstRun,
            'hasOriginal' => VisualEditorStore::original($sourceKey) !== null,
            // 「保存」直接由 persist 端点走核心 ContentWorkflow 入库，不改状态；
            // status 只用于界面提示（草稿 / 已发布）。
            'status' => (int)($record['status'] ?? 0),
            'convertUrl' => \admin_url('/visual-editor/convert'),
            'saveUrl' => \admin_url('/visual-editor/save'),
            'persistUrl' => \admin_url('/visual-editor/persist'),
            'restoreUrl' => \admin_url('/visual-editor/restore'),
            'csrfToken' => \App\Core\Csrf::token(),
            'widgets' => VisualEditorSchema::widgets(),
            'widgetGroups' => VisualEditorSchema::widgetGroups(),
            'styleProperties' => VisualEditorSchema::styleProperties(),
            'styleLabels' => VisualEditorSchema::styleLabels(),
            'fieldLabels' => VisualEditorSchema::fieldLabels(),
            'presets' => VisualEditorSchema::sectionPresets(),
            'breakpoints' => VisualEditorSettings::breakpoints(),
            'containerMax' => VisualEditorSettings::containerMax(),
            // 结构基础样式由服务端下发一份：JS 编译产物与服务端逐字节同源。
            'baseCss' => VisualEditorStyleCompiler::baseCss(),
        ]);
    }

    /**
     * 面板确实渲染过吗？
     *
     * 路径匹配只是「省一点」的优化，真正的判据是面板有没有输出。
     * 路径判断一旦看漏（后台前缀被改、路由多一层、将来核心换 URL 形状），
     * 面板就会裸奔在页面上——1.2.1 之前正是这个下场。所以 footer 里
     * 只要面板渲染过就一定注入脚本，不再只信路径。
     */
    private static bool $panelRendered = false;

    /** CSS 只输出一次：head 与面板两条路都可能先到。 */
    private static bool $cssEmitted = false;

    private static function emitCss(): void
    {
        if (self::$cssEmitted) return;
        self::$cssEmitted = true;
        // /plugin-asset/{slug}/{path} 里的 path 是相对插件根目录的，必须带 assets/ 段。
        echo '<link rel="stylesheet" href="' . e(\plugin_url('visual-editor', 'assets/editor.css'))
            . '?v=' . self::VERSION . '">';
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
        //
        // 定界符用 ~ 而不是 #：$relative 已经是 parse_url 取出的纯路径，
        // 查询串与锚点都不在里面，只需要收尾允许一个多余的斜杠。
        // （用 # 定界时模式里的 # 会提前结束模式，preg_match 直接报错返回
        //  false，assets 一次都不会注入——1.2.1 之前就是这么坏的。）
        return (bool)preg_match(
            '~^(?:(?:pages|articles|products)/(?:create|edit/\d+)|content-entries/[a-z0-9_-]+/(?:create|edit/\d+))/?$~',
            $relative
        );
    }

    public static function headAssets(): void
    {
        if (!self::shouldEnqueueAssets()) return;
        self::emitCss();
    }

    public static function footerAssets(): void
    {
        // 面板渲染过就必须注入，哪怕路径判断没认出这个页面。
        if (!self::$panelRendered && !self::shouldEnqueueAssets()) return;
        // head 没赶上（路径没匹配）时在这里补一份：<link> 放在 body 里同样生效。
        self::emitCss();
        echo '<script src="' . e(\plugin_url('visual-editor', 'assets/editor.js')) . '?v=' . self::VERSION . '" defer></script>';
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
