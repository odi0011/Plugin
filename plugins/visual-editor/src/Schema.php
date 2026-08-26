<?php
/**
 * 可视化编辑器：能力白名单。
 *
 * 这个文件是插件里**唯一**回答「什么是合法内容」的地方：控件有哪些、每个控件收哪些
 * 字段、字段值长什么样、样式能设哪些属性、每个属性接受什么值。渲染器、样式编译器、
 * 后台保存接口、公开 API 与 Agent 动作全部从这里取规则，因此不存在「界面拦住了但
 * API 放进去了」这种缺口。
 *
 * 一条贯穿全局的取向：**没有任何路径能把原始 CSS 或 JS 存进页面。**
 * 样式是「属性 => 受校验的值」的键值对，编译成选择器已经定死的声明；
 * HTML 只有走白名单消毒后的富文本片段。这样即使文档 JSON 被写坏，
 * 最坏结果是元素不渲染，而不是给访客注入脚本。
 */
if (!defined('CODE_SCHEMA_VERSION')) exit;

final class VisualEditorSchema
{
    /** 文档树结构版本。改变树的形状时递增，渲染前按它决定要不要升级。 */
    public const DOC_VERSION = 1;

    /** 三个断点的键。desktop 是基准，其余两个进 max-width 媒体查询。 */
    public const BREAKPOINTS = ['desktop', 'tablet', 'mobile'];

    /** 结构规模上限。文档树进 JSON 列存字段，不能无界。 */
    public const MAX_SECTIONS = 60;
    public const MAX_COLUMNS_PER_SECTION = 6;
    public const MAX_WIDGETS_PER_COLUMN = 60;
    public const MAX_WIDGETS_TOTAL = 400;
    public const MAX_DOC_BYTES = 1048576;

    /** 富文本片段上限。够写一段正文，不够塞整页。 */
    public const MAX_RICH_BYTES = 20000;
    public const MAX_TEXT_LENGTH = 2000;

    /**
     * 控件目录。
     *
     * fields 的值是「字段类型:约束」，由 validateFieldValue() 解释。
     * needs_permission 非空时，只有具备该权限点的账号能新增或修改这个控件——
     * 权限在写入侧检查，而不是渲染侧，因此降权不会让已有页面突然变形。
     *
     * @return array<string,array<string,mixed>>
     */
    public static function widgets(): array
    {
        return [
            'heading' => [
                'label' => '标题',
                'icon' => 'bi-type-h1',
                'fields' => [
                    'text' => 'text:200',
                    'level' => 'enum:h1,h2,h3,h4,h5,h6',
                    'align' => 'enum:left,center,right',
                ],
                'defaults' => ['text' => '标题文字', 'level' => 'h2', 'align' => 'left'],
                'needs_permission' => '',
            ],
            'text' => [
                'label' => '正文',
                'icon' => 'bi-text-paragraph',
                'fields' => [
                    'html' => 'rich',
                    'align' => 'enum:left,center,right,justify',
                ],
                'defaults' => ['html' => '<p>在这里写一段正文。</p>', 'align' => 'left'],
                'needs_permission' => '',
            ],
            'image' => [
                'label' => '图片',
                'icon' => 'bi-image',
                'fields' => [
                    'src' => 'media',
                    'alt' => 'text:200',
                    'url' => 'link',
                    'align' => 'enum:left,center,right',
                    'width' => 'number:5,100',
                ],
                'defaults' => ['src' => '', 'alt' => '', 'url' => '', 'align' => 'center', 'width' => 100],
                'needs_permission' => '',
            ],
            'button' => [
                'label' => '按钮',
                'icon' => 'bi-hand-index',
                'fields' => [
                    'text' => 'text:80',
                    'url' => 'link',
                    'target' => 'enum:_self,_blank',
                    'variant' => 'enum:primary,outline,ghost',
                    'size' => 'enum:sm,md,lg',
                    'align' => 'enum:left,center,right',
                ],
                'defaults' => [
                    'text' => '了解更多', 'url' => '', 'target' => '_self',
                    'variant' => 'primary', 'size' => 'md', 'align' => 'left',
                ],
                'needs_permission' => '',
            ],
            'list' => [
                'label' => '列表',
                'icon' => 'bi-list-ul',
                'fields' => [
                    'items' => 'lines:20,200',
                    'marker' => 'enum:disc,decimal,check,none',
                    'align' => 'enum:left,center,right',
                ],
                'defaults' => ['items' => "第一项\n第二项\n第三项", 'marker' => 'disc', 'align' => 'left'],
                'needs_permission' => '',
            ],
            'quote' => [
                'label' => '引用',
                'icon' => 'bi-quote',
                'fields' => [
                    'text' => 'text:600',
                    'cite' => 'text:120',
                    'align' => 'enum:left,center,right',
                ],
                'defaults' => ['text' => '一句值得引用的话。', 'cite' => '', 'align' => 'left'],
                'needs_permission' => '',
            ],
            'divider' => [
                'label' => '分隔线',
                'icon' => 'bi-dash-lg',
                'fields' => [
                    'style' => 'enum:solid,dashed,dotted',
                    'thickness' => 'number:1,12',
                    'color' => 'color',
                ],
                'defaults' => ['style' => 'solid', 'thickness' => 1, 'color' => ''],
                'needs_permission' => '',
            ],
            'spacer' => [
                'label' => '留白',
                'icon' => 'bi-arrows-expand',
                'fields' => ['height' => 'number:4,400'],
                'defaults' => ['height' => 40],
                'needs_permission' => '',
            ],
            'embed' => [
                'label' => '视频',
                'icon' => 'bi-play-btn',
                'fields' => [
                    'provider' => 'enum:youtube,vimeo,bilibili',
                    'video_id' => 'token:64',
                    'ratio' => 'enum:16-9,4-3,1-1',
                    'title' => 'text:120',
                ],
                'defaults' => ['provider' => 'youtube', 'video_id' => '', 'ratio' => '16-9', 'title' => ''],
                'needs_permission' => '',
            ],
            'html' => [
                'label' => '自定义 HTML',
                'icon' => 'bi-code-slash',
                'fields' => ['html' => 'html_block'],
                'defaults' => ['html' => ''],
                'needs_permission' => 'visual_editor.code',
            ],
            // ---- 1.2.0 新增：对齐 Elementor 常用控件 ----
            // 键名只用小写字母：Content::reconstructVeDoc() 靠 /ve-widget-([a-z]+)/
            // 从渲染产物里回读类型，带下划线的键会在「重新导入」时静默退化成 html。
            'icon' => [
                'label' => '图标',
                'icon' => 'bi-star',
                'fields' => [
                    'name' => 'token:40',
                    'size' => 'number:12,160',
                    'color' => 'color',
                    'align' => 'enum:left,center,right',
                ],
                'defaults' => ['name' => 'star-fill', 'size' => 40, 'color' => '', 'align' => 'center'],
                'needs_permission' => '',
            ],
            'iconbox' => [
                'label' => '图标框',
                'icon' => 'bi-bounding-box',
                'fields' => [
                    'name' => 'token:40',
                    'title' => 'text:120',
                    'text' => 'text:600',
                    'size' => 'number:12,120',
                    'color' => 'color',
                    'layout' => 'enum:top,left',
                    'align' => 'enum:left,center,right',
                ],
                'defaults' => [
                    'name' => 'lightning-charge-fill', 'title' => '特性标题',
                    'text' => '用一两句话说明这个特性解决了什么问题。',
                    'size' => 32, 'color' => '', 'layout' => 'top', 'align' => 'center',
                ],
                'needs_permission' => '',
            ],
            'imagebox' => [
                'label' => '图文框',
                'icon' => 'bi-card-image',
                'fields' => [
                    'src' => 'media',
                    'alt' => 'text:200',
                    'title' => 'text:120',
                    'text' => 'text:600',
                    'url' => 'link',
                    'align' => 'enum:left,center,right',
                ],
                'defaults' => [
                    'src' => '', 'alt' => '', 'title' => '图文标题',
                    'text' => '配图下方的一段说明文字。', 'url' => '', 'align' => 'center',
                ],
                'needs_permission' => '',
            ],
            'alert' => [
                'label' => '提示框',
                'icon' => 'bi-exclamation-square',
                'fields' => [
                    'tone' => 'enum:info,success,warning,danger',
                    'title' => 'text:120',
                    'text' => 'text:600',
                ],
                'defaults' => ['tone' => 'info', 'title' => '提示', 'text' => '这里是需要读者注意的一句话。'],
                'needs_permission' => '',
            ],
            'progress' => [
                'label' => '进度条',
                'icon' => 'bi-bar-chart',
                'fields' => [
                    'label' => 'text:120',
                    'value' => 'number:0,100',
                    'color' => 'color',
                    'showvalue' => 'enum:yes,no',
                ],
                'defaults' => ['label' => '完成度', 'value' => 70, 'color' => '', 'showvalue' => 'yes'],
                'needs_permission' => '',
            ],
        ];
    }

    /**
     * 控件分组，只服务左侧面板的折叠分区。
     *
     * 与 widgets() 分开的理由同 styleLabels()：这是展示结构，不是能力定义。
     * 未被任何分组收录的控件由面板兜到最后一组，新增控件忘了登记也不会消失。
     *
     * @return array<string,list<string>>
     */
    public static function widgetGroups(): array
    {
        return [
            '基础' => ['heading', 'text', 'image', 'button', 'list', 'quote'],
            '布局' => ['divider', 'spacer'],
            '进阶' => ['icon', 'iconbox', 'imagebox', 'alert', 'progress', 'embed'],
            '开发' => ['html'],
        ];
    }

    /**
     * 区块预设：新增区块时的分栏骨架。
     *
     * 值是各栏在桌面断点上的百分比宽度，栏数受 MAX_COLUMNS_PER_SECTION 约束。
     *
     * @return array<string,array{label:string,columns:list<int>}>
     */
    public static function sectionPresets(): array
    {
        return [
            'one' => ['label' => '单栏', 'columns' => [100]],
            'two' => ['label' => '两栏', 'columns' => [50, 50]],
            'three' => ['label' => '三栏', 'columns' => [34, 33, 33]],
            'four' => ['label' => '四栏', 'columns' => [25, 25, 25, 25]],
            'sidebar-right' => ['label' => '正文 + 右侧栏', 'columns' => [66, 34]],
            'sidebar-left' => ['label' => '左侧栏 + 正文', 'columns' => [34, 66]],
        ];
    }

    /** 图标名 => Bootstrap Icons 类名。名字过 token 校验后仍要落到这个前缀上。 */
    public static function iconClass(string $name): string
    {
        $name = strtolower(trim($name));
        return preg_match('/^[a-z0-9-]{1,40}$/', $name) === 1 ? 'bi bi-' . $name : 'bi bi-square';
    }

    /** @return list<string> */
    public static function widgetTypes(): array
    {
        return array_keys(self::widgets());
    }

    /** @return array<string,mixed>|null */
    public static function widget(string $type): ?array
    {
        return self::widgets()[strtolower(trim($type))] ?? null;
    }

    /**
     * 样式属性白名单：设置键 => [CSS 属性, 值类型]。
     *
     * 只有这张表里的键会被编译成 CSS 声明，值也必须过对应类型的校验。
     * 因此「用户能设的样式」和「页面上可能出现的 CSS 声明」是同一个有限集合，
     * 不存在自由文本进 <style> 的路径。
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function styleProperties(): array
    {
        return [
            'margin_top' => ['margin-top', 'length'],
            'margin_bottom' => ['margin-bottom', 'length'],
            'padding_top' => ['padding-top', 'length'],
            'padding_bottom' => ['padding-bottom', 'length'],
            'padding_left' => ['padding-left', 'length'],
            'padding_right' => ['padding-right', 'length'],
            'background_color' => ['background-color', 'color'],
            'background_image' => ['background-image', 'image'],
            'background_size' => ['background-size', 'enum:cover,contain,auto'],
            'background_position' => ['background-position', 'enum:center,top,bottom,left,right'],
            'background_repeat' => ['background-repeat', 'enum:no-repeat,repeat,repeat-x,repeat-y'],
            'text_color' => ['color', 'color'],
            'text_align' => ['text-align', 'enum:left,center,right,justify'],
            'font_size' => ['font-size', 'length'],
            'font_weight' => ['font-weight', 'enum:300,400,500,600,700,800'],
            'line_height' => ['line-height', 'ratio'],
            'letter_spacing' => ['letter-spacing', 'length'],
            'border_width' => ['border-width', 'length'],
            'border_style' => ['border-style', 'enum:none,solid,dashed,dotted'],
            'border_color' => ['border-color', 'color'],
            'border_radius' => ['border-radius', 'length'],
            'min_height' => ['min-height', 'length'],
            'max_width' => ['max-width', 'length'],
            'gap' => ['gap', 'length'],
            'display' => ['display', 'enum:block,flex,inline-block,none'],
            'justify_content' => ['justify-content', 'enum:flex-start,center,flex-end,space-between,space-around'],
            'align_items' => ['align-items', 'enum:stretch,flex-start,center,flex-end'],
            'opacity' => ['opacity', 'ratio'],
            'shadow' => ['box-shadow', 'shadow'],
        ];
    }

    /** 阴影是固定几档而不是自由值：自由 box-shadow 太容易写出括号与逗号的怪值。 */
    public static function shadowValue(string $key): string
    {
        return [
            'none' => 'none',
            'sm' => '0 1px 2px rgba(0,0,0,.08)',
            'md' => '0 4px 16px rgba(0,0,0,.10)',
            'lg' => '0 12px 40px rgba(0,0,0,.16)',
        ][strtolower(trim($key))] ?? '';
    }

    /** 区块布局：定宽（受内容区最大宽度约束）或通栏。 */
    public const SECTION_LAYOUTS = ['boxed', 'full'];

    /**
     * 样式属性的中文名与分组，只服务后台面板。
     *
     * 刻意和 styleProperties() 分开：那张表是**能力**定义（编译器与校验器要用），
     * 这张表是**展示**定义。合成一张的话，改个文案就得动编译路径上的数据结构。
     *
     * @return array<string,array{group:string,label:string}>
     */
    public static function styleLabels(): array
    {
        return [
            'margin_top' => ['group' => '间距', 'label' => '上外边距'],
            'margin_bottom' => ['group' => '间距', 'label' => '下外边距'],
            'padding_top' => ['group' => '间距', 'label' => '上内边距'],
            'padding_bottom' => ['group' => '间距', 'label' => '下内边距'],
            'padding_left' => ['group' => '间距', 'label' => '左内边距'],
            'padding_right' => ['group' => '间距', 'label' => '右内边距'],
            'gap' => ['group' => '间距', 'label' => '子元素间隔'],
            'background_color' => ['group' => '背景', 'label' => '背景色'],
            'background_image' => ['group' => '背景', 'label' => '背景图'],
            'background_size' => ['group' => '背景', 'label' => '背景尺寸'],
            'background_position' => ['group' => '背景', 'label' => '背景位置'],
            'background_repeat' => ['group' => '背景', 'label' => '背景平铺'],
            'text_color' => ['group' => '文字', 'label' => '文字颜色'],
            'text_align' => ['group' => '文字', 'label' => '对齐'],
            'font_size' => ['group' => '文字', 'label' => '字号'],
            'font_weight' => ['group' => '文字', 'label' => '字重'],
            'line_height' => ['group' => '文字', 'label' => '行高'],
            'letter_spacing' => ['group' => '文字', 'label' => '字间距'],
            'border_width' => ['group' => '边框', 'label' => '边框粗细'],
            'border_style' => ['group' => '边框', 'label' => '边框样式'],
            'border_color' => ['group' => '边框', 'label' => '边框颜色'],
            'border_radius' => ['group' => '边框', 'label' => '圆角'],
            'shadow' => ['group' => '边框', 'label' => '阴影'],
            'min_height' => ['group' => '尺寸与排布', 'label' => '最小高度'],
            'max_width' => ['group' => '尺寸与排布', 'label' => '最大宽度'],
            'display' => ['group' => '尺寸与排布', 'label' => '显示方式'],
            'justify_content' => ['group' => '尺寸与排布', 'label' => '主轴对齐'],
            'align_items' => ['group' => '尺寸与排布', 'label' => '交叉轴对齐'],
            'opacity' => ['group' => '尺寸与排布', 'label' => '不透明度'],
        ];
    }

    /**
     * 控件字段的中文名，同样只服务后台面板。
     *
     * @return array<string,string>
     */
    public static function fieldLabels(): array
    {
        return [
            'text' => '文字',
            'html' => '内容',
            'level' => '标题级别',
            'align' => '对齐',
            'src' => '图片',
            'alt' => '替代文字',
            'url' => '链接',
            'width' => '宽度（%）',
            'target' => '打开方式',
            'variant' => '样式',
            'size' => '尺寸',
            'items' => '列表项（每行一项）',
            'marker' => '项目符号',
            'cite' => '出处',
            'style' => '线型',
            'thickness' => '粗细（px）',
            'color' => '颜色',
            'height' => '高度（px）',
            'provider' => '视频来源',
            'video_id' => '视频 ID',
            'ratio' => '比例',
            'title' => '标题',
            'name' => '图标名（Bootstrap Icons，不含 bi- 前缀）',
            'tone' => '语气',
            'layout' => '排布',
            'label' => '名称',
            'value' => '数值（%）',
            'showvalue' => '显示数值',
        ];
    }
}
