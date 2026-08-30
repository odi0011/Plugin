<?php
declare(strict_types=1);

/**
 * AI客服的配置中枢：一次性读取声明式设置、解析 JSON 型复合字段、渲染前台挂件。
 *
 * 设计要点：
 * - 读配置只调 PluginSettingsService::values() 一次。逐 key 调 value() 会让核心把
 *   plugin.json 解析并全量校验 93 遍，实测一次前台渲染要 29ms；改成一次后是 0.4ms。
 * - 复合配置（主题/布局/资料/工具/卡片/约束/事件/表情）都落成一个 textarea 型 JSON
 *   字段。核心的声明式设置有 100 字段上限，且这些结构本身是数组，拆成扁平字段既超限
 *   也表达不了。JSON 的形状校验在插件这一侧做。
 * - 每个后台子页面只提交自己名下的字段，其余字段以库中现值回填后整体校验；回填前会
 *   把越界/空的数字字段修回合法值，否则在 A 页清空一个数字框会让 B/C/D 页全部存不进去。
 */
final class AiCustomerService
{
    public const SLUG = 'ai-customer-service';
    public const VERSION = '1.2.2';

    private const SESSION_KEY = '_ai_customer_service';
    private const CONVERSATION_TTL = 21600;
    private const MAX_CONVERSATIONS = 8;
    private const CUSTOM_KEY_SETTING = 'plugin.ai-customer-service.custom_api_key';
    private const SAVE_KEYS_FIELD = 'acs_save_keys';

    /** 后台子页面：key => [标签, 图标]，与 plugin.json 的 settings.sections 一一对应。 */
    public const ADMIN_PAGES = [
        'conversation' => ['会话内容', 'bi-chat-square-text'],
        'trigger' => ['显示与触发', 'bi-sliders'],
        'appearance' => ['外观与位置', 'bi-palette'],
        'ai' => ['AI 与模型', 'bi-cpu'],
        'knowledge' => ['知识与资料', 'bi-journal-richtext'],
        'tools' => ['工具与卡片', 'bi-tools'],
        'guardrails' => ['边界与事件', 'bi-shield-check'],
        'composer' => ['输入与表情', 'bi-emoji-smile'],
    ];

    /** JSON 型字段 => 默认结构提供者。 */
    private const JSON_FIELDS = [
        'theme_json' => 'defaultTheme',
        'layout_json' => 'defaultLayout',
        'greeting_json' => 'defaultGreeting',
        'knowledge_json' => 'defaultKnowledge',
        'tools_json' => 'defaultTools',
        'cards_json' => 'defaultCards',
        'owner_json' => 'defaultOwner',
        'guardrails_json' => 'defaultGuardrails',
        'events_json' => 'defaultEvents',
        'stickers_json' => 'defaultStickers',
    ];

    /** @var array<string,mixed>|null 单请求缓存：前台一次渲染只解析一次。 */
    private static ?array $configCache = null;
    /** @var array<string,mixed>|null */
    private static ?array $declarationCache = null;

    /** 归一化后的声明（分组 + 字段），单请求缓存。 */
    public static function declaration(): ?array
    {
        if (self::$declarationCache === null) {
            try {
                self::$declarationCache = \App\Core\PluginSettingsService::declaration(self::SLUG) ?? [];
            } catch (\Throwable $_) {
                self::$declarationCache = [];
            }
        }
        return self::$declarationCache === [] ? null : self::$declarationCache;
    }

    /** 库中现值（含 JSON 字段原始字符串），单请求缓存。 */
    private static function storedValues(): array
    {
        try {
            return \App\Core\PluginSettingsService::values(self::SLUG)['values'];
        } catch (\Throwable $_) {
            return [];
        }
    }

    /**
     * 归一化后的完整配置。所有取值都在这里夹到合法区间，因此下游（渲染、聊天、工具）
     * 拿到的一律是可直接用的值，不需要再各自兜底。
     *
     * @return array<string,mixed>
     */
    public static function config(): array
    {
        if (self::$configCache !== null) return self::$configCache;

        $raw = self::storedValues();
        $get = static function (string $key, $default) use ($raw) {
            $value = $raw[$key] ?? null;
            return $value === null ? $default : $value;
        };

        $theme = self::json($get('theme_json', ''), self::defaultTheme());
        $layout = self::json($get('layout_json', ''), self::defaultLayout());

        $config = [
            'enabled' => self::bool($get('enabled', true)),
            'brand_name' => self::text($get('brand_name', ''), 80, 'AI客服'),
            'team_label' => self::text($get('team_label', ''), 120, '智能在线服务'),
            'welcome_message' => self::text($get('welcome_message', ''), 1000, '您好，我是您的 AI 客服。有什么可以帮您？'),
            'input_placeholder' => self::text($get('input_placeholder', ''), 160, '输入您的问题...'),
            'quick_replies_title' => self::text($get('quick_replies_title', ''), 60, ''),
            'quick_replies' => self::lines((string)$get('quick_replies', ''), 8, 180),
            'unavailable_message' => self::text($get('unavailable_message', ''), 500, '当前客服暂时不可用，请稍后再试。'),
            'handoff_label' => self::text($get('handoff_label', ''), 80, '联系人工客服'),
            'handoff_url' => self::httpUrl((string)$get('handoff_url', '')),
            'history_limit' => self::int($get('history_limit', 8), 2, 20, 8),
            'rate_limit_per_minute' => self::int($get('rate_limit_per_minute', 8), 1, 60, 8),

            'device_mode' => self::choice($get('device_mode', ''), ['all', 'desktop', 'mobile'], 'all'),
            'url_mode' => self::choice($get('url_mode', ''), ['all', 'include', 'exclude'], 'all'),
            'url_rules' => self::urlRules((string)$get('url_rules', '')),
            'delay_seconds' => self::int($get('delay_seconds', 0), 0, 300, 0),
            'scroll_percent' => self::int($get('scroll_percent', 0), 0, 100, 0),
            'exit_intent' => self::bool($get('exit_intent', false)),
            'initial_open' => self::bool($get('initial_open', false)),
            'initial_open_delay' => self::int($get('initial_open_delay', 0), 0, 120, 0),
            'once_per_session' => self::bool($get('once_per_session', false)),
            'show_launcher' => self::bool($get('show_launcher', true)),
            'tooltip_text' => self::text($get('tooltip_text', ''), 80, ''),
            'teaser_enabled' => self::bool($get('teaser_enabled', false)),
            'teaser_text' => self::text($get('teaser_text', ''), 120, '有问题？随时咨询'),
            'badge_enabled' => self::bool($get('badge_enabled', false)),
            'attention_effect' => self::choice($get('attention_effect', ''), ['none', 'wiggle', 'bounce', 'pulse'], 'none'),
            'ribbon_enabled' => self::bool($get('ribbon_enabled', false)),
            'ribbon_text' => self::text($get('ribbon_text', ''), 60, ''),
            'greeting' => self::normalizeGreeting(self::json($get('greeting_json', ''), self::defaultGreeting())),
            'schedule_enabled' => self::bool($get('schedule_enabled', false)),
            'schedule_days' => self::scheduleDays((string)$get('schedule_days', '')),
            'schedule_start' => self::timeValue((string)$get('schedule_start', ''), '00:00'),
            'schedule_end' => self::timeValue((string)$get('schedule_end', ''), '23:59'),
    // ACS_MARKER_CONFIG2
            'theme' => self::normalizeTheme($theme),
            'layout' => self::normalizeLayout($layout),
            'font_family' => self::choice($get('font_family', ''), array_keys(self::FONT_STACKS), 'system'),
            'launcher_style' => self::choice($get('launcher_style', ''), ['bubble', 'pill'], 'bubble'),
            'launcher_icon' => self::choice($get('launcher_icon', ''), ['chat', 'sparkles', 'headset', 'question'], 'chat'),
            'launcher_image_url' => self::httpUrl((string)$get('launcher_image_url', '')),
            'launcher_corner' => self::int($get('launcher_corner', 10), 0, 30, 10),
            'position' => self::choice($get('position', ''), ['right', 'left'], 'right'),
            'widget_size' => self::int($get('widget_size', 56), 40, 96, 56),
            'panel_width' => self::int($get('panel_width', 396), 300, 560, 396),
            'panel_height' => self::int($get('panel_height', 600), 380, 800, 600),
            'panel_radius' => self::int($get('panel_radius', 16), 0, 32, 16),
            'panel_shadow' => self::choice($get('panel_shadow', ''), ['none', 'sm', 'md', 'lg'], 'none'),
            'font_size' => self::int($get('font_size', 14), 12, 18, 14),
            'desktop_offset_x' => self::int($get('desktop_offset_x', 24), 0, 120, 24),
            'desktop_offset_y' => self::int($get('desktop_offset_y', 24), 0, 120, 24),
            'mobile_offset_x' => self::int($get('mobile_offset_x', 16), 4, 48, 16),
            'mobile_offset_y' => self::int($get('mobile_offset_y', 16), 4, 80, 16),
            'accent_color' => self::color((string)$get('accent_color', ''), '#4F46E5'),
            'surface_color' => self::color((string)$get('surface_color', ''), '#FFFFFF'),
            'text_color' => self::color((string)$get('text_color', ''), '#111827'),
            'muted_color' => self::color((string)$get('muted_color', ''), '#6B7280'),
            'header_color' => self::color((string)$get('header_color', ''), '#FFFFFF'),
            'header_text_color' => self::color((string)$get('header_text_color', ''), '#111827'),
            'bot_bubble_color' => self::color((string)$get('bot_bubble_color', ''), '#F3F4F6'),
            'bot_bubble_text_color' => self::color((string)$get('bot_bubble_text_color', ''), '#111827'),
            'visitor_bubble_color' => self::color((string)$get('visitor_bubble_color', ''), '#4F46E5'),
            'visitor_bubble_text_color' => self::color((string)$get('visitor_bubble_text_color', ''), '#FFFFFF'),
            'avatar_url' => self::httpUrl((string)$get('avatar_url', '')),
            'show_avatar' => self::bool($get('show_avatar', true)),
            'show_powered_by' => self::bool($get('show_powered_by', false)),

            'provider_mode' => self::choice($get('provider_mode', ''), ['system', 'custom'], 'system'),
            'system_model_id' => self::int($get('system_model_id', 0), 0, 99999999, 0),
            'custom_api_endpoint' => self::customEndpoint((string)$get('custom_api_endpoint', '')),
            'custom_model' => self::text($get('custom_model', ''), 160, ''),
            'system_prompt' => self::text($get('system_prompt', ''), 6000, ''),
            'temperature' => self::float($get('temperature', 0.3), 0.0, 2.0, 0.3),
            'max_tokens' => self::int($get('max_tokens', 800), 128, 4096, 800),

            'knowledge_mode' => self::choice($get('knowledge_mode', ''), ['off', 'sources', 'files', 'both'], 'both'),
            'knowledge' => self::normalizeKnowledge(self::json($get('knowledge_json', ''), self::defaultKnowledge())),
            'knowledge_base' => self::text($get('knowledge_base', ''), 8000, ''),
            'knowledge_budget' => self::int($get('knowledge_budget', 4000), 500, 12000, 4000),
            'knowledge_strategy' => self::choice($get('knowledge_strategy', ''), ['relevance', 'order'], 'relevance'),

            'tools_enabled' => self::bool($get('tools_enabled', true)),
            'tools' => self::normalizeTools(self::json($get('tools_json', ''), self::defaultTools())),
            'tool_max_rounds' => self::int($get('tool_max_rounds', 2), 1, 3, 2),
            'cards' => self::normalizeCards(self::json($get('cards_json', ''), self::defaultCards())),
            'owner' => self::normalizeOwner(self::json($get('owner_json', ''), self::defaultOwner())),

            'scope_mode' => self::choice($get('scope_mode', ''), ['open', 'restricted'], 'open'),
            'guardrails' => self::normalizeGuardrails(self::json($get('guardrails_json', ''), self::defaultGuardrails())),
            'refusal_message' => self::text($get('refusal_message', ''), 500, '这个问题超出了我能回答的范围，我帮您转人工客服好吗？'),
            'events' => self::normalizeEvents(self::json($get('events_json', ''), self::defaultEvents())),
            'event_log_enabled' => self::bool($get('event_log_enabled', false)),
            'inquiry_notify_email' => self::email((string)$get('inquiry_notify_email', '')),

            'message_max_chars' => self::int($get('message_max_chars', 2000), 100, 4000, 2000),
            'send_on_enter' => self::bool($get('send_on_enter', true)),
            'emoji_enabled' => self::bool($get('emoji_enabled', true)),
            'sticker_enabled' => self::bool($get('sticker_enabled', false)),
            'stickers' => self::normalizeStickers(self::json($get('stickers_json', ''), self::defaultStickers())),
        ];

        self::$configCache = $config;
        return $config;
    }

    // ACS_MARKER_CONFIG

    /** 界面字体候选。值是 CSS font-family，前台按 --acs-font-family 注入。 */
    public const FONT_STACKS = [
        'system' => '-apple-system, BlinkMacSystemFont, "Segoe UI", "Microsoft YaHei", sans-serif',
        'inter' => 'Inter, "Helvetica Neue", Arial, "Microsoft YaHei", sans-serif',
        'pingfang' => '"PingFang SC", "Hiragino Sans GB", "Source Han Sans SC", "Microsoft YaHei", sans-serif',
        'serif' => '"Source Han Serif SC", "Songti SC", Georgia, serif',
        'rounded' => '"Quicksand", "Nunito", "PingFang SC", "Microsoft YaHei", sans-serif',
        'mono' => 'ui-monospace, SFMono-Regular, Consolas, "Liberation Mono", monospace',
    ];

    /**
     * 预设主题。切换预设 = 一次性覆盖一组具体字段值（不是运行时的第二套配置），
     * 所以预设改完之后用户仍然能逐项微调，预览与前台看到的永远是同一份字段。
     *
     * 六套都是**平色**：不用渐变顶栏、默认不打投影，分层只靠 1px 描边。
     * 渐变和大面积投影在真实站点上几乎总是显脏，也压不住站点自己的配色。
     *
     * @return array<string,array{label:string,note:string,values:array<string,mixed>}>
     */
    public static function themePresets(): array
    {
        return [
            'plain' => ['label' => '素白 Plain', 'note' => '白底 + 靛蓝重点色，最不抢站点风格', 'values' => [
                'accent_color' => '#4F46E5', 'header_color' => '#FFFFFF', 'header_text_color' => '#111827',
                'surface_color' => '#FFFFFF', 'text_color' => '#111827', 'muted_color' => '#6B7280',
                'bot_bubble_color' => '#F4F4F5', 'bot_bubble_text_color' => '#111827',
                'visitor_bubble_color' => '#4F46E5', 'visitor_bubble_text_color' => '#FFFFFF',
                'panel_radius' => 14, 'panel_shadow' => 'none', 'font_family' => 'system',
                'theme' => ['bubble_style' => 'soft', 'bubble_anim' => 'rise', 'header_style' => 'light', 'quick_style' => 'capsule', 'density' => 'cozy', 'typing' => 'dots'],
            ]],
            'ink' => ['label' => '墨黑 Ink', 'note' => '近黑顶栏 + 中性灰气泡，克制', 'values' => [
                'accent_color' => '#18181B', 'header_color' => '#18181B', 'header_text_color' => '#FAFAFA',
                'surface_color' => '#FFFFFF', 'text_color' => '#18181B', 'muted_color' => '#71717A',
                'bot_bubble_color' => '#F4F4F5', 'bot_bubble_text_color' => '#18181B',
                'visitor_bubble_color' => '#18181B', 'visitor_bubble_text_color' => '#FAFAFA',
                'panel_radius' => 12, 'panel_shadow' => 'none', 'font_family' => 'inter',
                'theme' => ['bubble_style' => 'flat', 'bubble_anim' => 'fade', 'header_style' => 'solid', 'quick_style' => 'ghost', 'density' => 'compact', 'typing' => 'dots'],
            ]],
            'midnight' => ['label' => '午夜 Midnight', 'note' => '深色面板，配深色站点', 'values' => [
                'accent_color' => '#6366F1', 'header_color' => '#0F172A', 'header_text_color' => '#F1F5F9',
                'surface_color' => '#0F172A', 'text_color' => '#E2E8F0', 'muted_color' => '#94A3B8',
                'bot_bubble_color' => '#1E293B', 'bot_bubble_text_color' => '#E2E8F0',
                'visitor_bubble_color' => '#6366F1', 'visitor_bubble_text_color' => '#FFFFFF',
                'panel_radius' => 14, 'panel_shadow' => 'none', 'font_family' => 'inter',
                'theme' => ['bubble_style' => 'flat', 'bubble_anim' => 'fade', 'header_style' => 'solid', 'quick_style' => 'ghost', 'density' => 'cozy', 'typing' => 'wave'],
            ]],
            'mint' => ['label' => '薄荷 Mint', 'note' => '浅底描边气泡，B2B 常用', 'values' => [
                'accent_color' => '#0E9F8F', 'header_color' => '#FFFFFF', 'header_text_color' => '#0F172A',
                'surface_color' => '#FFFFFF', 'text_color' => '#0F172A', 'muted_color' => '#64748B',
                'bot_bubble_color' => '#F0FDFA', 'bot_bubble_text_color' => '#0F172A',
                'visitor_bubble_color' => '#0E9F8F', 'visitor_bubble_text_color' => '#FFFFFF',
                'panel_radius' => 10, 'panel_shadow' => 'none', 'font_family' => 'pingfang',
                'theme' => ['bubble_style' => 'outline', 'bubble_anim' => 'rise', 'header_style' => 'light', 'quick_style' => 'ghost', 'density' => 'compact', 'typing' => 'dots'],
            ]],
            'clay' => ['label' => '陶土 Clay', 'note' => '暖米底 + 砖红重点色，零售气质', 'values' => [
                'accent_color' => '#C2410C', 'header_color' => '#FFFBF7', 'header_text_color' => '#431407',
                'surface_color' => '#FFFBF7', 'text_color' => '#431407', 'muted_color' => '#92766A',
                'bot_bubble_color' => '#FBF0E8', 'bot_bubble_text_color' => '#431407',
                'visitor_bubble_color' => '#C2410C', 'visitor_bubble_text_color' => '#FFFFFF',
                'panel_radius' => 16, 'panel_shadow' => 'none', 'font_family' => 'rounded',
                'theme' => ['bubble_style' => 'soft', 'bubble_anim' => 'pop', 'header_style' => 'light', 'quick_style' => 'capsule', 'density' => 'cozy', 'typing' => 'dots'],
            ]],
            'paper' => ['label' => '手账 Paper', 'note' => '手绘描边，配涂鸦名片', 'values' => [
                'accent_color' => '#E86A8A', 'header_color' => '#FDFBF7', 'header_text_color' => '#2C2C2C',
                'surface_color' => '#FDFBF7', 'text_color' => '#2C2C2C', 'muted_color' => '#8A8378',
                'bot_bubble_color' => '#FFFFFF', 'bot_bubble_text_color' => '#2C2C2C',
                'visitor_bubble_color' => '#C0BBFE', 'visitor_bubble_text_color' => '#20203A',
                'panel_radius' => 12, 'panel_shadow' => 'none', 'font_family' => 'rounded',
                'theme' => ['bubble_style' => 'sketch', 'bubble_anim' => 'pop', 'header_style' => 'light', 'quick_style' => 'sketch', 'density' => 'cozy', 'typing' => 'text'],
            ]],
        ];
    }

    // ACS_MARKER_DEFAULTS

    // ---------------------------------------------------------------- JSON 默认结构

    public static function defaultTheme(): array
    {
        return ['preset' => 'plain', 'bubble_style' => 'soft', 'bubble_anim' => 'rise',
            'typing' => 'dots', 'density' => 'cozy', 'header_style' => 'light', 'quick_style' => 'capsule'];
    }

    public static function defaultLayout(): array
    {
        // ribbon.dy 默认必须是 0：飘带是面板里的第一个节点，面板又是 overflow:hidden，
        // 任何负偏移都会被顶边裁掉一截（-10px 时文字直接被切半行）。留给用户微调，不做默认。
        return ['panel_align' => 'right', 'panel_gap' => 12, 'teaser' => ['dx' => -12, 'dy' => 0],
            'ribbon' => ['dx' => 0, 'dy' => 0], 'badge' => ['dx' => 2, 'dy' => 2],
            'launcher_nudge' => ['dx' => 0, 'dy' => 0], 'z_index' => 2147480000, 'locked' => false];
    }

    public static function defaultGreeting(): array
    {
        return ['enabled' => false, 'once_per_session' => true, 'stop_after_reply' => true,
            'steps' => [['after' => 20, 'text' => '需要我帮您找型号或报价吗？']]];
    }

    public static function defaultKnowledge(): array
    {
        return ['sources' => [], 'auto' => ['products' => false, 'articles' => false, 'pages' => false, 'limit' => 12], 'files' => []];
    }

    public static function defaultTools(): array
    {
        return ['builtin' => self::BUILTIN_TOOL_DEFAULTS, 'custom' => []];
    }

    /** 内置工具的默认开关。键必须与 AiCustomerServiceTools 里的实现一一对应。 */
    public const BUILTIN_TOOL_DEFAULTS = [
        'recommend_products' => true,
        'recommend_articles' => true,
        'search_site' => true,
        'lookup_knowledge' => true,
        'show_inquiry_form' => true,
        'request_handoff' => true,
        'show_owner_card' => false,
        'show_social_links' => false,
    ];

    public const CARD_PRESETS = [
        'product' => ['stack' => '叠卡（悬停展开）', 'grid' => '网格卡', 'minimal' => '极简列表', 'glass' => '毛玻璃'],
        'article' => ['minimal' => '极简列表', 'grid' => '网格卡', 'stack' => '叠卡（悬停展开）', 'glass' => '毛玻璃'],
        'owner' => ['doodle' => '手账涂鸦名片', 'clean' => '简洁名片', 'glass' => '毛玻璃名片'],
        'inquiry' => ['panel' => '内嵌表单面板', 'compact' => '紧凑两行', 'link' => '仅按钮跳转'],
        'social' => ['chips' => '胶囊链接', 'grid' => '图标网格', 'list' => '列表'],
    ];

    public static function defaultCards(): array
    {
        return [
            'product' => ['preset' => 'stack', 'show_price' => true, 'show_summary' => true, 'cta' => '查看详情', 'max' => 3],
            'article' => ['preset' => 'minimal', 'cta' => '阅读全文', 'max' => 3],
            'owner' => ['preset' => 'doodle'],
            'inquiry' => ['preset' => 'panel', 'fields' => ['name', 'email', 'phone', 'message'],
                'submit' => '提交询盘', 'success' => '已收到，我们会尽快联系您。'],
            'social' => ['preset' => 'chips'],
        ];
    }

    public static function defaultOwner(): array
    {
        return ['name' => '', 'title' => '', 'avatar' => '', 'bio' => '', 'socials' => []];
    }

    public static function defaultGuardrails(): array
    {
        return ['allow_topics' => [], 'deny_topics' => [], 'must_do' => [],
            'never_do' => ['不要承诺交期、折扣或任何未在资料中写明的条件'],
            'blocked_words' => [], 'max_reply_chars' => 1200, 'language' => 'auto'];
    }

    public const EVENT_KINDS = ['inquiry' => '询盘转化', 'handoff' => '转人工', 'social' => '社媒名片', 'owner' => '站长名片'];

    public static function defaultEvents(): array
    {
        return [
            'inquiry' => ['enabled' => true, 'keywords' => ['报价', '价格', '多少钱', '采购', '下单', '询盘', 'quote', 'price'],
                'message' => '我帮您留个联系方式，销售会尽快回复。'],
            'handoff' => ['enabled' => true, 'keywords' => ['人工', '转人工', '真人', '投诉', 'human', 'agent'],
                'message' => '正在为您转接人工客服。'],
            'social' => ['enabled' => false, 'keywords' => ['微信', '联系方式', '社交', 'whatsapp', 'wechat'],
                'message' => '这是我们的联系方式。'],
            'owner' => ['enabled' => false, 'keywords' => ['站长', '负责人', '老板', 'owner'],
                'message' => '这是负责人的名片。'],
        ];
    }

    public static function defaultStickers(): array
    {
        return ['emoji' => true, 'gif' => false, 'packs' => [],
            'emoji_set' => ['😀', '😄', '😊', '🙂', '😉', '👍', '🙏', '🎉', '❤️', '🔥', '✨', '💡', '📦', '🚚', '💰', '📞']];
    }

    // ACS_MARKER_NORMALIZE

    // ---------------------------------------------------------------- JSON 归一化

    /** 解析 JSON 字段；坏数据一律回落默认结构，绝不半套生效。 */
    private static function json(mixed $raw, array $fallback): array
    {
        if (is_array($raw)) return $raw;
        $raw = trim((string)$raw);
        if ($raw === '' || strlen($raw) > 262144) return $fallback;
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : $fallback;
    }

    private static function normalizeTheme(array $theme): array
    {
        $presets = self::themePresets();
        return [
            'preset' => isset($presets[(string)($theme['preset'] ?? '')]) ? (string)$theme['preset'] : 'plain',
            'bubble_style' => self::choice($theme['bubble_style'] ?? '', ['soft', 'flat', 'outline', 'glass', 'sketch'], 'soft'),
            'bubble_anim' => self::choice($theme['bubble_anim'] ?? '', ['none', 'rise', 'pop', 'fade'], 'rise'),
            'typing' => self::choice($theme['typing'] ?? '', ['dots', 'wave', 'text'], 'dots'),
            'density' => self::choice($theme['density'] ?? '', ['cozy', 'compact'], 'cozy'),
            'header_style' => self::choice($theme['header_style'] ?? '', ['solid', 'light'], 'light'),
            'quick_style' => self::choice($theme['quick_style'] ?? '', ['capsule', 'ghost', 'sketch'], 'capsule'),
        ];
    }

    private static function normalizeLayout(array $layout): array
    {
        $nudge = static function (mixed $value, int $range): array {
            $value = is_array($value) ? $value : [];
            return ['dx' => self::int($value['dx'] ?? 0, -$range, $range, 0), 'dy' => self::int($value['dy'] ?? 0, -$range, $range, 0)];
        };
        return [
            'panel_align' => self::choice($layout['panel_align'] ?? '', ['right', 'left', 'center'], 'right'),
            'panel_gap' => self::int($layout['panel_gap'] ?? 12, 0, 48, 12),
            'teaser' => $nudge($layout['teaser'] ?? null, 200),
            'ribbon' => $nudge($layout['ribbon'] ?? null, 120),
            'badge' => $nudge($layout['badge'] ?? null, 40),
            'launcher_nudge' => $nudge($layout['launcher_nudge'] ?? null, 80),
            'z_index' => self::int($layout['z_index'] ?? 2147480000, 1000, 2147480000, 2147480000),
            'locked' => self::bool($layout['locked'] ?? false),
        ];
    }

    private static function normalizeGreeting(array $greeting): array
    {
        $steps = [];
        foreach (self::listOf($greeting['steps'] ?? null, 6) as $step) {
            if (!is_array($step)) continue;
            $text = self::text($step['text'] ?? '', 200, '');
            if ($text === '') continue;
            $steps[] = ['after' => self::int($step['after'] ?? 20, 3, 900, 20), 'text' => $text];
        }
        usort($steps, static fn (array $a, array $b): int => $a['after'] <=> $b['after']);
        return [
            'enabled' => self::bool($greeting['enabled'] ?? false) && $steps !== [],
            'once_per_session' => self::bool($greeting['once_per_session'] ?? true),
            'stop_after_reply' => self::bool($greeting['stop_after_reply'] ?? true),
            'steps' => $steps,
        ];
    }

    public const SOURCE_KINDS = ['product' => '产品', 'article' => '文章', 'page' => '页面', 'entry' => '自定义内容'];

    private static function normalizeKnowledge(array $knowledge): array
    {
        $sources = [];
        $seen = [];
        foreach (self::listOf($knowledge['sources'] ?? null, 200) as $source) {
            if (!is_array($source)) continue;
            $kind = self::choice($source['kind'] ?? '', array_keys(self::SOURCE_KINDS), '');
            $id = self::int($source['id'] ?? 0, 1, 99999999, 0);
            if ($kind === '' || $id === 0) continue;
            $type = $kind === 'entry' ? self::slugValue((string)($source['type'] ?? ''), 80) : '';
            if ($kind === 'entry' && $type === '') continue;
            $token = $kind . ':' . $type . ':' . $id;
            if (isset($seen[$token])) continue;
            $seen[$token] = true;
            $sources[] = ['kind' => $kind, 'type' => $type, 'id' => $id, 'title' => self::text($source['title'] ?? '', 200, '')];
        }
        $auto = is_array($knowledge['auto'] ?? null) ? $knowledge['auto'] : [];
        $files = [];
        foreach (self::listOf($knowledge['files'] ?? null, 60) as $file) {
            if (!is_array($file)) continue;
            $id = self::slugValue((string)($file['id'] ?? ''), 32);
            $name = self::text($file['name'] ?? '', 180, '');
            if ($id === '' || $name === '') continue;
            $files[] = [
                'id' => $id,
                'name' => $name,
                'ext' => self::slugValue((string)($file['ext'] ?? ''), 10),
                'bytes' => self::int($file['bytes'] ?? 0, 0, 268435456, 0),
                'chars' => self::int($file['chars'] ?? 0, 0, 99999999, 0),
                'added_at' => self::text($file['added_at'] ?? '', 40, ''),
            ];
        }
        return [
            'sources' => $sources,
            'auto' => [
                'products' => self::bool($auto['products'] ?? false),
                'articles' => self::bool($auto['articles'] ?? false),
                'pages' => self::bool($auto['pages'] ?? false),
                'limit' => self::int($auto['limit'] ?? 12, 1, 60, 12),
            ],
            'files' => $files,
        ];
    }

    // ACS_MARKER_NORMALIZE2

    private static function normalizeTools(array $tools): array
    {
        $builtinRaw = is_array($tools['builtin'] ?? null) ? $tools['builtin'] : [];
        $builtin = [];
        foreach (self::BUILTIN_TOOL_DEFAULTS as $name => $default) {
            $builtin[$name] = self::bool($builtinRaw[$name] ?? $default);
        }
        $custom = [];
        $seen = array_fill_keys(array_keys(self::BUILTIN_TOOL_DEFAULTS), true);
        foreach (self::listOf($tools['custom'] ?? null, 16) as $tool) {
            if (!is_array($tool)) continue;
            // 工具名要进 OpenAI tools 声明，核心只接受 ^[A-Za-z0-9_]{1,64}$。
            $name = strtolower((string)preg_replace('/[^A-Za-z0-9_]/', '', (string)($tool['name'] ?? '')));
            $name = substr($name, 0, 48);
            if ($name === '' || isset($seen[$name]) || !preg_match('/^[a-z][a-z0-9_]*$/', $name)) continue;
            $seen[$name] = true;
            $source = self::choice($tool['source'] ?? '', array_keys(self::SOURCE_KINDS), 'product');
            $custom[] = [
                'name' => $name,
                'label' => self::text($tool['label'] ?? '', 60, $name),
                'description' => self::text($tool['description'] ?? '', 400, ''),
                'source' => $source,
                'entry_type' => $source === 'entry' ? self::slugValue((string)($tool['entry_type'] ?? ''), 80) : '',
                'filters' => self::normalizeToolFilters($tool['filters'] ?? null),
                'limit' => self::int($tool['limit'] ?? 3, 1, 8, 3),
                'card' => self::choice($tool['card'] ?? '', array_keys(self::CARD_PRESETS['product']), 'stack'),
                'enabled' => self::bool($tool['enabled'] ?? true),
            ];
        }
        return ['builtin' => $builtin, 'custom' => $custom];
    }

    /** 自定义工具只允许一组白名单过滤条件——绝不接受任意 SQL 片段或列名。 */
    public const TOOL_FILTERS = [
        'is_featured' => '仅推荐位', 'is_hot' => '仅热销', 'is_new' => '仅新品',
        'in_stock' => '仅有货', 'has_price' => '仅有价格', 'newest' => '按最新排序',
    ];

    private static function normalizeToolFilters(mixed $filters): array
    {
        $filters = is_array($filters) ? $filters : [];
        $out = [];
        foreach (array_keys(self::TOOL_FILTERS) as $key) {
            if (self::bool($filters[$key] ?? false)) $out[$key] = true;
        }
        return $out;
    }

    private static function normalizeCards(array $cards): array
    {
        $defaults = self::defaultCards();
        $pick = static fn (string $kind, mixed $value): string
            => self::choice($value ?? '', array_keys(self::CARD_PRESETS[$kind]), (string)$defaults[$kind]['preset']);
        $product = is_array($cards['product'] ?? null) ? $cards['product'] : [];
        $article = is_array($cards['article'] ?? null) ? $cards['article'] : [];
        $owner = is_array($cards['owner'] ?? null) ? $cards['owner'] : [];
        $inquiry = is_array($cards['inquiry'] ?? null) ? $cards['inquiry'] : [];
        $social = is_array($cards['social'] ?? null) ? $cards['social'] : [];

        $fields = [];
        foreach (self::listOf($inquiry['fields'] ?? null, 6) as $field) {
            $field = self::choice($field, ['name', 'email', 'phone', 'company', 'message'], '');
            if ($field !== '' && !in_array($field, $fields, true)) $fields[] = $field;
        }
        if (!in_array('message', $fields, true)) $fields[] = 'message';

        return [
            'product' => [
                'preset' => $pick('product', $product['preset'] ?? null),
                'show_price' => self::bool($product['show_price'] ?? true),
                'show_summary' => self::bool($product['show_summary'] ?? true),
                'cta' => self::text($product['cta'] ?? '', 24, '查看详情'),
                'max' => self::int($product['max'] ?? 3, 1, 6, 3),
            ],
            'article' => [
                'preset' => $pick('article', $article['preset'] ?? null),
                'cta' => self::text($article['cta'] ?? '', 24, '阅读全文'),
                'max' => self::int($article['max'] ?? 3, 1, 6, 3),
            ],
            'owner' => ['preset' => $pick('owner', $owner['preset'] ?? null)],
            'inquiry' => [
                'preset' => $pick('inquiry', $inquiry['preset'] ?? null),
                'fields' => $fields,
                'submit' => self::text($inquiry['submit'] ?? '', 24, '提交询盘'),
                'success' => self::text($inquiry['success'] ?? '', 200, '已收到，我们会尽快联系您。'),
            ],
            'social' => ['preset' => $pick('social', $social['preset'] ?? null)],
        ];
    }

    public const SOCIAL_NETWORKS = [
        'wechat' => ['微信', 'bi-wechat'], 'whatsapp' => ['WhatsApp', 'bi-whatsapp'],
        'telegram' => ['Telegram', 'bi-telegram'], 'facebook' => ['Facebook', 'bi-facebook'],
        'instagram' => ['Instagram', 'bi-instagram'], 'linkedin' => ['LinkedIn', 'bi-linkedin'],
        'x' => ['X / Twitter', 'bi-twitter-x'], 'youtube' => ['YouTube', 'bi-youtube'],
        'tiktok' => ['TikTok', 'bi-tiktok'], 'github' => ['GitHub', 'bi-github'],
        'email' => ['邮箱', 'bi-envelope-fill'], 'phone' => ['电话', 'bi-telephone-fill'],
        'website' => ['网站', 'bi-globe2'],
    ];

    private static function normalizeOwner(array $owner): array
    {
        $socials = [];
        foreach (self::listOf($owner['socials'] ?? null, 12) as $social) {
            if (!is_array($social)) continue;
            $network = self::choice($social['network'] ?? '', array_keys(self::SOCIAL_NETWORKS), '');
            if ($network === '') continue;
            // 微信/电话常见的是号码而不是链接，所以这两类允许纯文本。
            $url = in_array($network, ['wechat', 'phone'], true)
                ? self::text($social['url'] ?? '', 120, '')
                : ($network === 'email' ? self::email((string)($social['url'] ?? '')) : self::httpUrl((string)($social['url'] ?? '')));
            if ($url === '') continue;
            $socials[] = [
                'network' => $network,
                'label' => self::text($social['label'] ?? '', 40, self::SOCIAL_NETWORKS[$network][0]),
                'url' => $url,
            ];
        }
        return [
            'name' => self::text($owner['name'] ?? '', 60, ''),
            'title' => self::text($owner['title'] ?? '', 80, ''),
            'avatar' => self::httpUrl((string)($owner['avatar'] ?? '')),
            'bio' => self::text($owner['bio'] ?? '', 300, ''),
            'socials' => $socials,
        ];
    }

    // ACS_MARKER_NORMALIZE3

    private static function normalizeGuardrails(array $rules): array
    {
        $bucket = static fn (mixed $value, int $count, int $length): array => array_values(array_filter(
            array_map(static fn ($item): string => self::text($item, $length, ''), self::listOf($value, $count)),
            static fn (string $item): bool => $item !== ''
        ));
        return [
            'allow_topics' => $bucket($rules['allow_topics'] ?? null, 24, 80),
            'deny_topics' => $bucket($rules['deny_topics'] ?? null, 24, 80),
            'must_do' => $bucket($rules['must_do'] ?? null, 12, 200),
            'never_do' => $bucket($rules['never_do'] ?? null, 12, 200),
            'blocked_words' => $bucket($rules['blocked_words'] ?? null, 60, 40),
            'max_reply_chars' => self::int($rules['max_reply_chars'] ?? 1200, 100, 5000, 1200),
            'language' => self::choice($rules['language'] ?? '', ['auto', 'zh', 'en', 'visitor'], 'auto'),
        ];
    }

    private static function normalizeEvents(array $events): array
    {
        $defaults = self::defaultEvents();
        $out = [];
        foreach (array_keys(self::EVENT_KINDS) as $kind) {
            $raw = is_array($events[$kind] ?? null) ? $events[$kind] : [];
            $keywords = [];
            foreach (self::listOf($raw['keywords'] ?? null, 40) as $keyword) {
                $keyword = mb_strtolower(self::text($keyword, 40, ''));
                if ($keyword !== '' && !in_array($keyword, $keywords, true)) $keywords[] = $keyword;
            }
            $out[$kind] = [
                'enabled' => self::bool($raw['enabled'] ?? $defaults[$kind]['enabled']),
                'keywords' => $keywords !== [] ? $keywords : $defaults[$kind]['keywords'],
                'message' => self::text($raw['message'] ?? '', 200, (string)$defaults[$kind]['message']),
            ];
        }
        return $out;
    }

    private static function normalizeStickers(array $stickers): array
    {
        $emoji = [];
        foreach (self::listOf($stickers['emoji_set'] ?? null, 64) as $item) {
            $item = self::text($item, 8, '');
            if ($item !== '' && !in_array($item, $emoji, true)) $emoji[] = $item;
        }
        $packs = [];
        foreach (self::listOf($stickers['packs'] ?? null, 8) as $pack) {
            if (!is_array($pack)) continue;
            $items = [];
            foreach (self::listOf($pack['items'] ?? null, 48) as $item) {
                if (!is_array($item)) continue;
                $url = self::relativeOrHttpUrl((string)($item['url'] ?? ''));
                if ($url === '') continue;
                $items[] = ['url' => $url, 'label' => self::text($item['label'] ?? '', 40, '')];
            }
            if ($items === []) continue;
            $packs[] = ['name' => self::text($pack['name'] ?? '', 40, '表情包'), 'items' => $items];
        }
        return [
            'emoji' => self::bool($stickers['emoji'] ?? true),
            'emoji_set' => $emoji !== [] ? $emoji : self::defaultStickers()['emoji_set'],
            'gif' => self::bool($stickers['gif'] ?? false),
            'packs' => $packs,
        ];
    }

    /** @return list<mixed> */
    private static function listOf(mixed $value, int $max): array
    {
        if (!is_array($value)) return [];
        return array_slice(array_values($value), 0, $max);
    }

    // ACS_MARKER_SAVE

    // ---------------------------------------------------------------- 后台保存

    /**
     * 校验并保存一次后台提交。
     *
     * 表单带 acs_save_keys 说明本页归属哪些字段；未列出的字段用库中现值回填后再整体校验。
     * 回填前必须先「修复」现值：核心对留空的 number 字段会直接写入空串（不查 min），
     * 而回读时空串会被转成 0.0——于是在 A 页清空一个 min>0 的数字框，B/C/D 页就会一直
     * 报「有 1 个设置项校验失败」，且提示里既没有字段名、出错字段也不在当前页上。
     *
     * @return array{ok:bool,message:string,errors?:array<string,string>}
     */
    public static function saveAdminConfiguration(): array
    {
        $declaration = self::declaration();
        $userId = (int)\App\Core\Auth::id();
        if ($declaration === null || $userId <= 0) {
            return ['ok' => false, 'message' => 'AI客服设置声明不可用'];
        }
        $fields = (array)$declaration['fields'];

        $requestedRaw = $_POST[self::SAVE_KEYS_FIELD] ?? '';
        if (!is_string($requestedRaw)) {
            return ['ok' => false, 'message' => '保存范围标识不合法'];
        }
        $requested = array_values(array_intersect(array_map('trim', explode(',', $requestedRaw)), array_keys($fields)));
        if ($requested === []) {
            return ['ok' => false, 'message' => '本次提交没有任何本页字段'];
        }

        $stored = self::storedValues();
        $input = [];
        $repaired = [];
        foreach ($fields as $key => $field) {
            if (!in_array($key, $requested, true)) {
                $current = $stored[$key] ?? ($field['default'] ?? '');
                $fixed = self::repairValue($field, $current);
                if ($fixed !== $current) $repaired[] = $key;
                $input[$key] = $fixed;
                continue;
            }
            $name = 'setting_' . $key;
            if (array_key_exists($name, $_POST)) {
                $input[$key] = $_POST[$name];
            } elseif ((string)($field['type'] ?? '') === 'boolean') {
                $input[$key] = false;
            }
        }

        // 本页提交的字段里，JSON 型的要先过一遍形状校验，坏 JSON 不许落库。
        foreach ($requested as $key) {
            if (!isset(self::JSON_FIELDS[$key]) || !array_key_exists($key, $input)) continue;
            $decoded = json_decode(trim((string)$input[$key]), true);
            if (!is_array($decoded)) {
                return ['ok' => false, 'message' => self::fieldLabel($fields, $key) . '：不是合法的 JSON 结构'];
            }
            $normalizer = self::JSON_FIELDS[$key];
            $rebuilt = json_encode(
                self::renormalizeJson($key, $decoded),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            if (!is_string($rebuilt)) {
                return ['ok' => false, 'message' => self::fieldLabel($fields, $key) . '：结构无法编码'];
            }
            $limit = is_int($fields[$key]['max_length'] ?? null) ? (int)$fields[$key]['max_length'] : 8000;
            if (mb_strlen($rebuilt) > $limit) {
                return ['ok' => false, 'message' => self::fieldLabel($fields, $key) . '：内容过多（上限 ' . $limit . ' 字），请减少条目'];
            }
            $input[$key] = $rebuilt;
            unset($normalizer);
        }

        if (isset($fields['url_rules']) && array_key_exists('url_rules', $input)) {
            $ruleError = self::validateUrlRules((string)$input['url_rules']);
            if ($ruleError !== '') return ['ok' => false, 'message' => $ruleError];
        }

        $keyResult = self::stageCustomApiKey();
        if ($keyResult['ok'] === false) return ['ok' => false, 'message' => (string)$keyResult['message']];

        $result = \App\Core\PluginSettingsService::save(self::SLUG, $input, $userId);
        if (empty($result['ok'])) {
            $errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];
            return [
                'ok' => false,
                // 核心只给「有 N 个设置项校验失败」，不带字段名。后台看不到是哪一项就没法修，
                // 所以这里把字段标签补回去。
                'message' => $errors === []
                    ? (string)($result['message'] ?? '设置校验失败')
                    : self::describeErrors($fields, $errors),
                'errors' => $errors,
            ];
        }

        if (!self::commitCustomApiKey($keyResult)) {
            return ['ok' => false, 'message' => '常规设置已保存，但独立接口密钥未能写入'];
        }

        self::$configCache = null;
        $message = 'AI客服配置已保存';
        if ($repaired !== []) {
            $message .= '（顺带修回了 ' . count($repaired) . ' 个越界的历史值：'
                . implode('、', array_map(static fn (string $k): string => self::fieldLabel($fields, $k), array_slice($repaired, 0, 4)))
                . (count($repaired) > 4 ? ' 等' : '') . '）';
        }
        return ['ok' => true, 'message' => $message];
    }

    // ACS_MARKER_REPAIR

    /**
     * 把库中现值修回「一定能过核心校验」的值。
     *
     * 这不是重复核心的校验，而是回填前的一次自愈：核心的 save() 只校验**本次提交**的值，
     * 而逐页保存会把所有未提交字段整体带上，于是任何历史脏值都会卡住其他页面。
     */
    private static function repairValue(array $field, mixed $current): mixed
    {
        $type = (string)($field['type'] ?? 'text');
        $default = $field['default'] ?? '';

        if ($type === 'boolean') return self::bool($current);

        if ($type === 'number') {
            $min = $field['min'] !== null ? (float)$field['min'] : null;
            $max = $field['max'] !== null ? (float)$field['max'] : null;
            if (!is_numeric($current)) {
                $number = is_numeric($default) ? (float)$default : ($min ?? 0.0);
            } else {
                $number = (float)$current;
            }
            if ($min !== null && $number < $min) $number = $min;
            if ($max !== null && $number > $max) $number = $max;
            return $number === floor($number) && abs($number) < 1.0e15 ? (string)(int)$number : (string)$number;
        }

        $value = is_scalar($current) || $current === null ? trim((string)$current) : '';
        if ($value === '') {
            // 空值本身是合法的（非必填），保持空——但必填项要回落默认。
            return empty($field['required']) ? '' : (string)$default;
        }

        if ($type === 'select') {
            foreach ((array)($field['options'] ?? []) as $option) {
                if ((string)($option['value'] ?? '') === $value) return $value;
            }
            return (string)$default;
        }
        if ($type === 'color') {
            return preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? $value : (string)$default;
        }
        if ($type === 'url' && preg_match('#^https?://#i', $value) !== 1) {
            return (string)$default;
        }
        if ($type === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return (string)$default;
        }

        $limit = is_int($field['max_length'] ?? null) ? (int)$field['max_length'] : 2000;
        if (mb_strlen($value) > $limit) $value = mb_substr($value, 0, $limit);
        $forbidden = $type === 'textarea' ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/';
        $value = (string)preg_replace($forbidden, '', $value);
        if ($value === '') return empty($field['required']) ? '' : (string)$default;

        $pattern = $field['pattern'] ?? null;
        if (is_string($pattern) && $pattern !== '') {
            $wrapped = '/' . str_replace('/', '\/', $pattern) . '/u';
            if (@preg_match($wrapped, $value) !== 1) return (string)$default;
        }
        return $value;
    }

    /** 把 JSON 字段过一遍与运行时同一套归一化，保证落库形状 = 读取形状。 */
    private static function renormalizeJson(string $key, array $decoded): array
    {
        return match ($key) {
            'theme_json' => self::normalizeTheme($decoded),
            'layout_json' => self::normalizeLayout($decoded),
            'greeting_json' => self::normalizeGreeting($decoded),
            'knowledge_json' => self::normalizeKnowledge($decoded),
            'tools_json' => self::normalizeTools($decoded),
            'cards_json' => self::normalizeCards($decoded),
            'owner_json' => self::normalizeOwner($decoded),
            'guardrails_json' => self::normalizeGuardrails($decoded),
            'events_json' => self::normalizeEvents($decoded),
            'stickers_json' => self::normalizeStickers($decoded),
            default => $decoded,
        };
    }

    private static function fieldLabel(array $fields, string $key): string
    {
        $label = (string)($fields[$key]['label'] ?? '');
        return $label !== '' ? $label : $key;
    }

    /** @param array<string,string> $errors */
    private static function describeErrors(array $fields, array $errors): string
    {
        $parts = [];
        foreach (array_slice($errors, 0, 5, true) as $key => $reason) {
            $parts[] = self::fieldLabel($fields, (string)$key) . '（' . (string)$reason . '）';
        }
        $suffix = count($errors) > 5 ? ' 等 ' . count($errors) . ' 项' : '';
        return '保存失败：' . implode('；', $parts) . $suffix;
    }

    /** 后台保存后应跳回的子页面。 */
    public static function adminReturnPage(): string
    {
        $raw = isset($_POST['acs_return_page']) && is_string($_POST['acs_return_page']) ? trim($_POST['acs_return_page']) : '';
        return array_key_exists($raw, self::ADMIN_PAGES) ? $raw : 'conversation';
    }

    // ACS_MARKER_KEY

    // ---------------------------------------------------------------- 独立接口密钥

    /**
     * 预处理密钥提交。密钥刻意不进声明式 settings（那会让它出现在 API/Agent 的可读契约里），
     * 所以只能由这个页面接收，加密后单独落一个 setting 键。
     *
     * @return array{ok:bool,message?:string,encrypted?:string|null,clear?:bool}
     */
    private static function stageCustomApiKey(): array
    {
        $raw = $_POST['custom_api_key'] ?? '';
        if (is_array($raw) || is_object($raw)) {
            return ['ok' => false, 'message' => '独立接口密钥格式不合法'];
        }
        $key = trim((string)$raw);
        $clear = !empty($_POST['clear_custom_api_key']);
        if ($key === '') return ['ok' => true, 'encrypted' => null, 'clear' => $clear];
        if (strlen($key) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $key)) {
            return ['ok' => false, 'message' => '独立接口密钥格式不合法'];
        }
        try {
            return ['ok' => true, 'encrypted' => \App\Core\Security::encryptApiKey($key), 'clear' => false];
        } catch (\Throwable $_) {
            return ['ok' => false, 'message' => '无法安全保存独立接口密钥，请先完成系统加密密钥配置'];
        }
    }

    private static function commitCustomApiKey(array $staged): bool
    {
        try {
            if (($staged['encrypted'] ?? null) !== null) {
                \App\Core\Setting::set(self::CUSTOM_KEY_SETTING, (string)$staged['encrypted']);
            } elseif (!empty($staged['clear'])) {
                \App\Core\Setting::set(self::CUSTOM_KEY_SETTING, '');
            }
            return true;
        } catch (\Throwable $_) {
            return false;
        }
    }

    public static function customApiKeySet(): bool
    {
        try {
            return trim((string)\App\Core\Setting::get(self::CUSTOM_KEY_SETTING, '')) !== '';
        } catch (\Throwable $_) {
            return false;
        }
    }

    /** @throws \RuntimeException */
    public static function customApiKey(): string
    {
        $stored = '';
        try {
            $stored = trim((string)\App\Core\Setting::get(self::CUSTOM_KEY_SETTING, ''));
        } catch (\Throwable $_) {
            $stored = '';
        }
        if ($stored === '') throw new \RuntimeException('custom_api_key_missing');
        return \App\Core\Security::decryptApiKey($stored);
    }

    // ---------------------------------------------------------------- 会话

    /**
     * 取当前访客的会话 id。
     *
     * 1.1.0 是「每次页面渲染都新铸一个」，于是访客一翻页上下文就丢了，而且 session 里
     * 堆一堆空会话。这里改成同一个 session 复用未过期的最近会话。
     */
    public static function currentConversation(): string
    {
        self::pruneConversations();
        $conversations =& self::conversations();
        $latestId = '';
        $latestAt = 0;
        foreach ($conversations as $id => $conversation) {
            $at = (int)($conversation['updated_at'] ?? 0);
            if ($at > $latestAt) { $latestAt = $at; $latestId = (string)$id; }
        }
        if ($latestId !== '' && time() - $latestAt <= self::CONVERSATION_TTL) return $latestId;
        return self::newConversation();
    }

    public static function newConversation(): string
    {
        self::pruneConversations();
        $id = bin2hex(random_bytes(18));
        $conversations =& self::conversations();
        $conversations[$id] = ['messages' => [], 'created_at' => time(), 'updated_at' => time(), 'events' => []];
        return $id;
    }

    /** @return array<string,mixed>|null */
    public static function conversation(string $id): ?array
    {
        if (!preg_match('/^[a-f0-9]{36}$/', $id)) return null;
        self::pruneConversations();
        $conversations =& self::conversations();
        $conversation = $conversations[$id] ?? null;
        return is_array($conversation) ? $conversation : null;
    }

    /** @param array<string,mixed> $conversation */
    public static function saveConversation(string $id, array $conversation): void
    {
        if (!preg_match('/^[a-f0-9]{36}$/', $id)) return;
        $conversation['updated_at'] = time();
        $conversations =& self::conversations();
        $conversations[$id] = $conversation;
        self::pruneConversations();
    }

    /** @return array<string,mixed> */
    private static function &conversations(): array
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_array($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = [];
        }
        if (!isset($_SESSION[self::SESSION_KEY]['conversations']) || !is_array($_SESSION[self::SESSION_KEY]['conversations'])) {
            $_SESSION[self::SESSION_KEY]['conversations'] = [];
        }
        return $_SESSION[self::SESSION_KEY]['conversations'];
    }

    private static function pruneConversations(): void
    {
        $now = time();
        $conversations =& self::conversations();
        foreach ($conversations as $id => $conversation) {
            if (!is_array($conversation) || $now - (int)($conversation['updated_at'] ?? 0) > self::CONVERSATION_TTL) {
                unset($conversations[$id]);
            }
        }
        if (count($conversations) <= self::MAX_CONVERSATIONS) return;
        uasort($conversations, static fn (array $a, array $b): int => (int)($a['updated_at'] ?? 0) <=> (int)($b['updated_at'] ?? 0));
        while (count($conversations) > self::MAX_CONVERSATIONS) {
            array_shift($conversations);
        }
    }

    // ACS_MARKER_RENDER

    // ---------------------------------------------------------------- 前台渲染

    public static function renderWidget(): void
    {
        $config = self::config();
        if (!$config['enabled'] || !self::pathAllowed($config) || !self::scheduleAllowed($config)) return;

        $json = json_encode(
            self::publicWidgetConfig($config),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        if (!is_string($json)) return;

        echo '<div id="ai-customer-service-widget" class="' . self::escape(implode(' ', self::rootClasses($config)))
            . '" data-visible="false" style="' . self::escape(self::styleVars($config)) . '">';

        if (!empty($config['show_launcher'])) {
            echo self::launcherMarkup($config);
        }
        echo self::panelMarkup($config);
        echo '<script id="acs-widget-config" type="application/json">' . $json . '</script></div>';
    }

    /** @return list<string> */
    private static function rootClasses(array $config): array
    {
        $theme = $config['theme'];
        $classes = [
            'acs-widget',
            'acs-widget--' . $config['position'],
            'acs-widget--' . $config['launcher_style'],
            'acs-shadow--' . $config['panel_shadow'],
            'acs-bubble--' . $theme['bubble_style'],
            'acs-anim--' . $theme['bubble_anim'],
            'acs-head--' . $theme['header_style'],
            'acs-quick--' . $theme['quick_style'],
            'acs-density--' . $theme['density'],
            'acs-theme--' . $theme['preset'],
        ];
        if (empty($config['show_launcher'])) $classes[] = 'acs-widget--bare';
        return $classes;
    }

    private static function styleVars(array $config): string
    {
        $layout = $config['layout'];
        $pairs = [
            '--acs-accent' => $config['accent_color'],
            '--acs-surface' => $config['surface_color'],
            '--acs-text' => $config['text_color'],
            '--acs-muted' => $config['muted_color'],
            '--acs-header-bg' => $config['header_color'],
            '--acs-header-fg' => $config['header_text_color'],
            '--acs-bot-bubble' => $config['bot_bubble_color'],
            '--acs-bot-text' => $config['bot_bubble_text_color'],
            '--acs-vis-bubble' => $config['visitor_bubble_color'],
            '--acs-vis-text' => $config['visitor_bubble_text_color'],
            '--acs-font' => (int)$config['font_size'] . 'px',
            '--acs-font-family' => self::FONT_STACKS[$config['font_family']] ?? self::FONT_STACKS['system'],
            '--acs-size' => (int)$config['widget_size'] . 'px',
            '--acs-panel-width' => (int)$config['panel_width'] . 'px',
            '--acs-panel-height' => (int)$config['panel_height'] . 'px',
            '--acs-panel-radius' => (int)$config['panel_radius'] . 'px',
            '--acs-launcher-corner' => (int)$config['launcher_corner'] . 'px',
            '--acs-desktop-x' => (int)$config['desktop_offset_x'] . 'px',
            '--acs-desktop-y' => (int)$config['desktop_offset_y'] . 'px',
            '--acs-mobile-x' => (int)$config['mobile_offset_x'] . 'px',
            '--acs-mobile-y' => (int)$config['mobile_offset_y'] . 'px',
            '--acs-panel-gap' => (int)$layout['panel_gap'] . 'px',
            '--acs-teaser-dx' => (int)$layout['teaser']['dx'] . 'px',
            '--acs-teaser-dy' => (int)$layout['teaser']['dy'] . 'px',
            '--acs-ribbon-dx' => (int)$layout['ribbon']['dx'] . 'px',
            '--acs-ribbon-dy' => (int)$layout['ribbon']['dy'] . 'px',
            '--acs-badge-dx' => (int)$layout['badge']['dx'] . 'px',
            '--acs-badge-dy' => (int)$layout['badge']['dy'] . 'px',
            '--acs-launcher-dx' => (int)$layout['launcher_nudge']['dx'] . 'px',
            '--acs-launcher-dy' => (int)$layout['launcher_nudge']['dy'] . 'px',
            '--acs-z' => (string)(int)$layout['z_index'],
        ];
        $out = [];
        foreach ($pairs as $name => $value) $out[] = $name . ':' . $value;
        return implode(';', $out);
    }

    // ACS_MARKER_MARKUP

    private static function launcherMarkup(array $config): string
    {
        $classes = ['acs-launcher'];
        if (!empty($config['badge_enabled'])) $classes[] = 'has-badge';
        if ($config['attention_effect'] !== 'none') $classes[] = 'acs-fx--' . $config['attention_effect'];

        $inner = (string)$config['launcher_image_url'] !== ''
            ? '<img class="acs-launcher-img" src="' . self::escape((string)$config['launcher_image_url']) . '" alt="">'
            : '<i class="bi ' . self::escape(self::launcherIconClass((string)$config['launcher_icon'])) . ' acs-launcher-icon" aria-hidden="true"></i>';
        $inner .= '<span class="acs-launcher-label">' . self::escape((string)$config['brand_name']) . '</span>';
        $inner .= '<i class="bi bi-x-lg acs-launcher-close" aria-hidden="true"></i>';

        $html = '<button type="button" class="' . self::escape(implode(' ', $classes))
            . '" aria-expanded="false" aria-controls="acs-panel" aria-label="打开 ' . self::escape((string)$config['brand_name']) . '">'
            . $inner . '</button>';
        if ((string)$config['tooltip_text'] !== '') {
            $html .= '<span class="acs-tooltip" role="tooltip">' . self::escape((string)$config['tooltip_text']) . '</span>';
        }
        if (!empty($config['teaser_enabled']) && (string)$config['teaser_text'] !== '') {
            $html .= '<div class="acs-teaser" data-acs-teaser hidden>'
                . '<button type="button" class="acs-teaser-close" aria-label="收起引导消息"><i class="bi bi-x-lg" aria-hidden="true"></i></button>'
                . '<span class="acs-teaser-text">' . self::escape((string)$config['teaser_text']) . '</span></div>';
        }
        return $html;
    }

    /**
     * 面板标记。$chat / $quick 只有后台预览会传（服务端先把示例内容填进去）；
     * 前台留空，由 customer-service.js 填。$open=true 时不输出 hidden——预览要一上来就展开。
     */
    private static function panelMarkup(array $config, string $chat = '', string $quick = '', bool $open = false): string
    {
        $brand = self::escape((string)$config['brand_name']);
        $html = '<section ' . ($open ? '' : 'id="acs-panel" ') . 'class="acs-panel" aria-label="' . $brand . '对话"'
            . ($open ? '>' : ' aria-hidden="true" hidden>');

        if (!empty($config['ribbon_enabled']) && (string)$config['ribbon_text'] !== '') {
            $html .= '<div class="acs-ribbon" data-acs-ribbon' . ($open ? '' : ' hidden') . '><span class="acs-ribbon-track">'
                . self::escape((string)$config['ribbon_text']) . '</span>'
                . '<button type="button" class="acs-ribbon-close" aria-label="收起横幅"><i class="bi bi-x" aria-hidden="true"></i></button></div>';
        }

        $html .= '<header class="acs-header"><div class="acs-agent">' . self::avatarMarkup($config)
            . '<div class="acs-agent-copy"><strong>' . $brand . '</strong>'
            . '<span class="acs-agent-status"><i class="acs-dot" aria-hidden="true"></i>'
            . self::escape((string)$config['team_label']) . '</span></div></div>'
            . '<div class="acs-header-actions">'
            . '<button type="button" class="acs-icon-btn acs-restart" aria-label="重新开始对话" title="重新开始"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></button>'
            . '<button type="button" class="acs-icon-btn acs-close" aria-label="关闭对话" title="关闭"><i class="bi bi-x-lg" aria-hidden="true"></i></button>'
            . '</div></header>';

        $html .= '<div class="acs-chat" role="log" aria-live="polite" aria-relevant="additions text">' . $chat . '</div>';
        $html .= '<div class="acs-quick-replies" aria-label="快捷问题">' . $quick . '</div>';

        if ($config['handoff_url'] !== '') {
            $html .= '<a class="acs-handoff" href="' . self::escape((string)$config['handoff_url']) . '" target="_blank" rel="noopener">'
                . '<i class="bi bi-person-workspace" aria-hidden="true"></i><span>'
                . self::escape((string)$config['handoff_label']) . '</span></a>';
        }

        $html .= '<form class="acs-composer" novalidate>'
            . '<label class="acs-sr-only" for="acs-message">输入消息</label>'
            . '<div class="acs-composer-shell">'
            . '<textarea id="acs-message" name="message" rows="1" maxlength="' . (int)$config['message_max_chars']
            . '" placeholder="' . self::escape((string)$config['input_placeholder']) . '"></textarea>'
            . '<div class="acs-composer-bar">'
            . '<div class="acs-composer-tools">' . self::composerToolsMarkup($config) . '</div>'
            . '<div class="acs-composer-right">'
            . '<span class="acs-counter" data-acs-counter aria-hidden="true">0/' . (int)$config['message_max_chars'] . '</span>'
            . '<button type="submit" class="acs-send" aria-label="发送消息"><i class="bi bi-arrow-up" aria-hidden="true"></i></button>'
            . '</div></div></div>'
            . '<div class="acs-picker" data-acs-picker hidden></div>'
            . '</form>';

        if (!empty($config['show_powered_by'])) {
            $html .= '<div class="acs-powered">' . $brand . '</div>';
        }
        $html .= '<div class="acs-feedback" role="status" aria-live="polite"></div>';
        return $html . '</section>';
    }

    private static function composerToolsMarkup(array $config): string
    {
        $html = '';
        if (!empty($config['emoji_enabled'])) {
            $html .= '<button type="button" class="acs-icon-btn acs-tool-btn" data-acs-pick="emoji" aria-label="插入表情" title="表情">'
                . '<i class="bi bi-emoji-smile" aria-hidden="true"></i></button>';
        }
        if (!empty($config['sticker_enabled']) && ($config['stickers']['packs'] ?? []) !== []) {
            $html .= '<button type="button" class="acs-icon-btn acs-tool-btn" data-acs-pick="sticker" aria-label="插入表情包" title="表情包">'
                . '<i class="bi bi-sticky" aria-hidden="true"></i></button>';
        }
        return $html;
    }

    private static function avatarMarkup(array $config): string
    {
        if (empty($config['show_avatar'])) return '';
        if ((string)$config['avatar_url'] !== '') {
            return '<img class="acs-avatar" src="' . self::escape((string)$config['avatar_url']) . '" alt="">';
        }
        return '<span class="acs-avatar acs-avatar--fallback" aria-hidden="true"><i class="bi bi-stars"></i></span>';
    }

    private static function launcherIconClass(string $icon): string
    {
        return match ($icon) {
            'sparkles' => 'bi-stars',
            'headset' => 'bi-headset',
            'question' => 'bi-question-circle-fill',
            default => 'bi-chat-dots-fill',
        };
    }

    // ACS_MARKER_PUBLIC

    /**
     * 后台实时预览的标记。
     *
     * 刻意复用 rootClasses / styleVars / launcherMarkup / panelMarkup —— 也就是
     * **前台那一套**，只多塞一段示例对话。之前预览是另写的一套 acs-pv-* 仿制品，
     * 结果两边各自演进、越看越不像，用户看到的预览根本不是他将来看到的东西。
     */
    public static function previewMarkup(array $config): string
    {
        $classes = self::rootClasses($config);
        $classes[] = 'acs-widget--preview';
        // 预览里浮标一定要画出来（要能拖），用一个类标记"实际前台不显示"
        if (empty($config['show_launcher'])) $classes[] = 'acs-preview-nolauncher';

        $html = '<div class="' . self::escape(implode(' ', $classes)) . '" data-acs-preview'
            . ' data-visible="true" data-acs-open="true" style="' . self::escape(self::styleVars($config)) . '">';
        $html .= self::launcherMarkup($config);
        $html .= self::panelMarkup($config, self::sampleConversation($config), self::sampleQuickReplies($config), true);
        return $html . '</div>';
    }

    /** 预览里的快捷问题：前台由 JS 渲染，这里服务端先摆出来。 */
    private static function sampleQuickReplies(array $config): string
    {
        $replies = array_slice((array)$config['quick_replies'], 0, 6);
        if ($replies === []) return '';
        $html = '<div class="acs-quick-row">';
        foreach ($replies as $reply) {
            $html .= '<button type="button" class="acs-quick-reply" tabindex="-1">' . self::escape((string)$reply) . '</button>';
        }
        return $html . '</div>';
    }

    /** 预览用的示例对话。类名与前台 JS 渲染出来的完全一致。 */
    private static function sampleConversation(array $config): string
    {
        $avatar = !empty($config['show_avatar'])
            ? '<span class="acs-message-avatar" aria-hidden="true">' . ((string)$config['avatar_url'] !== ''
                ? '<img src="' . self::escape((string)$config['avatar_url']) . '" alt="">'
                : '<i class="bi bi-stars"></i>') . '</span>'
            : '';

        $html = '<div class="acs-message acs-message--assistant">' . $avatar
            . '<div class="acs-message-stack"><div class="acs-message-bubble">'
            . self::escape((string)$config['welcome_message']) . '</div></div></div>';

        $html .= '<div class="acs-message acs-message--visitor"><div class="acs-message-stack">'
            . '<div class="acs-message-bubble">有没有防水的型号？大概什么价位</div></div></div>';

        $chips = '<div class="acs-toolchip"><i class="bi bi-lightning-charge-fill" aria-hidden="true"></i>已查站内产品 · 3 条</div>';
        if (!empty($config['events']['inquiry']['enabled'])) {
            $chips .= '<div class="acs-toolchip"><i class="bi bi-lightning-charge-fill" aria-hidden="true"></i>识别到采购意图 · 已备询盘表单</div>';
        }

        $html .= '<div class="acs-message acs-message--assistant">' . $avatar
            . '<div class="acs-message-stack">' . $chips
            . '<div class="acs-message-bubble">这三款都是 IP68 全密封，价格区间如下，点卡片可以直接看详情。</div>'
            . self::sampleProductCard($config)
            . '</div></div>';

        return $html;
    }

    /** 示例产品卡：叠卡预设走照搬的设计稿结构，其余走行式推荐卡。 */
    private static function sampleProductCard(array $config): string
    {
        $settings = $config['cards']['product'];
        $cta = self::escape((string)$settings['cta']);
        $items = [
            ['IP68 防水传感器 M12', 'USD 128.00', '现货'],
            ['工业级防水连接器套件', 'USD 76.50', '可预订'],
            ['户外一体机 Pro', 'USD 310.00', '现货'],
        ];
        $items = array_slice($items, 0, max(1, (int)$settings['max']));

        if ((string)$settings['preset'] === 'stack') {
            $slots = ['acs-one', 'acs-two', 'acs-three'];
            $html = '<div class="acs-stack-stage"><div class="acs-cards">';
            foreach ($items as $index => [$title, $price, $stock]) {
                $html .= '<div class="acs-card-stack ' . $slots[$index] . '"><div class="acs-cardDetails">'
                    . '<div class="acs-cardDetailsHaeder">' . self::escape($title);
                if (!empty($settings['show_price'])) {
                    $html .= '<div class="acs-stack-price">' . self::escape($price) . '</div>';
                }
                $html .= '</div><div class="acs-cardDetailsButton">' . $cta . '</div></div></div>';
            }
            return $html . '</div></div>';
        }

        $html = '<div class="acs-card"><div class="acs-card-items is-' . self::escape((string)$settings['preset']) . '">';
        foreach ($items as [$title, $price, $stock]) {
            $html .= '<div class="acs-item"><span class="acs-item-thumb"><i class="bi bi-box-seam" aria-hidden="true"></i></span>'
                . '<span class="acs-item-main"><span class="acs-item-title">' . self::escape($title) . '</span>';
            if (!empty($settings['show_summary'])) {
                $html .= '<span class="acs-item-summary">全密封结构，-40~85℃，支持 Modbus。</span>';
            }
            $html .= '<span class="acs-item-foot">';
            if (!empty($settings['show_price'])) $html .= '<span class="acs-item-price">' . self::escape($price) . '</span>';
            $html .= '<span class="acs-item-badge">' . self::escape($stock) . '</span>'
                . '<span class="acs-item-cta">' . $cta . ' ›</span></span></span></div>';
        }
        return $html . '</div></div>';
    }


    /**
     * 注入前台的公开配置。
     *
     * 这里刻意只放「浏览器必须知道」的东西：描述词、资料正文、工具定义、约束清单、
     * 密钥一律不下发——它们只在服务端参与请求构造。
     *
     * @return array<string,mixed>
     */
    public static function publicWidgetConfig(array $config): array
    {
        return [
            'endpoint' => url('ai-customer-service/chat'),
            'actionEndpoint' => url('ai-customer-service/action'),
            'csrf' => \App\Core\Csrf::token(),
            'conversationId' => self::currentConversation(),
            'brandName' => $config['brand_name'],
            'welcomeMessage' => $config['welcome_message'],
            'quickRepliesTitle' => $config['quick_replies_title'],
            'quickReplies' => $config['quick_replies'],
            'unavailableMessage' => $config['unavailable_message'],
            'deviceMode' => $config['device_mode'],
            'delaySeconds' => $config['delay_seconds'],
            'scrollPercent' => $config['scroll_percent'],
            'exitIntent' => $config['exit_intent'],
            'initialOpen' => $config['initial_open'],
            'initialOpenDelay' => $config['initial_open_delay'],
            'oncePerSession' => $config['once_per_session'],
            'teaserEnabled' => $config['teaser_enabled'] && (string)$config['teaser_text'] !== '' && !empty($config['show_launcher']),
            'teaserText' => $config['teaser_text'],
            'badgeEnabled' => $config['badge_enabled'] && !empty($config['show_launcher']),
            'attentionEffect' => empty($config['show_launcher']) ? 'none' : $config['attention_effect'],
            'ribbonEnabled' => $config['ribbon_enabled'] && (string)$config['ribbon_text'] !== '',
            'greeting' => $config['greeting'],
            'typing' => $config['theme']['typing'],
            'maxChars' => $config['message_max_chars'],
            'sendOnEnter' => $config['send_on_enter'],
            'emoji' => !empty($config['emoji_enabled']) ? $config['stickers']['emoji_set'] : [],
            'stickerPacks' => !empty($config['sticker_enabled']) ? $config['stickers']['packs'] : [],
            'showAvatar' => $config['show_avatar'],
            'avatarUrl' => $config['avatar_url'],
        ];
    }

    /** @return array<string,mixed> 供 API / Agent 读取的摘要，不含任何机密。 */
    public static function statusSummary(): array
    {
        $config = self::config();
        $tools = [];
        foreach ($config['tools']['builtin'] as $name => $on) {
            if ($on) $tools[] = $name;
        }
        foreach ($config['tools']['custom'] as $tool) {
            if (!empty($tool['enabled'])) $tools[] = $tool['name'];
        }
        return [
            'enabled' => $config['enabled'],
            'version' => self::VERSION,
            'provider_mode' => $config['provider_mode'],
            'system_model_id' => $config['system_model_id'],
            'custom_api_key_set' => self::customApiKeySet(),
            'knowledge' => [
                'mode' => $config['knowledge_mode'],
                'source_count' => count($config['knowledge']['sources']),
                'file_count' => count($config['knowledge']['files']),
                'manual_characters' => mb_strlen((string)$config['knowledge_base']),
                'budget' => $config['knowledge_budget'],
            ],
            'tools' => ['enabled' => $config['tools_enabled'], 'active' => $tools],
            'guardrails' => [
                'scope_mode' => $config['scope_mode'],
                'allow_topics' => count($config['guardrails']['allow_topics']),
                'deny_topics' => count($config['guardrails']['deny_topics']),
                'blocked_words' => count($config['guardrails']['blocked_words']),
            ],
            'events' => array_map(static fn (array $e): bool => (bool)$e['enabled'], $config['events']),
            'display' => [
                'device_mode' => $config['device_mode'],
                'url_mode' => $config['url_mode'],
                'schedule_enabled' => $config['schedule_enabled'],
                'theme_preset' => $config['theme']['preset'],
            ],
        ];
    }

    // ---------------------------------------------------------------- 后台页面数据

    /** @return array<string,mixed> */
    public static function adminPageData(string $page = 'conversation'): array
    {
        $page = array_key_exists($page, self::ADMIN_PAGES) ? $page : 'conversation';
        $form = \App\Core\PluginSettingsService::form(self::SLUG) ?? [
            'sections' => [], 'values' => [], 'secret_set' => [], 'permission' => 'ai_customer_service.manage',
        ];

        return [
            'form' => $form,
            'page' => $page,
            'pages' => self::ADMIN_PAGES,
            'section' => self::sectionFor($form, $page),
            'models' => self::chatModelOptions(),
            'customKeySet' => self::customApiKeySet(),
            'active' => \App\Core\PluginManager::isActive(self::SLUG),
            'status' => self::statusSummary(),
            'config' => self::config(),
            'presets' => self::themePresets(),
            'fonts' => self::FONT_STACKS,
            'cardPresets' => self::CARD_PRESETS,
            'socialNetworks' => self::SOCIAL_NETWORKS,
            'eventKinds' => self::EVENT_KINDS,
            'sourceKinds' => self::SOURCE_KINDS,
            'toolFilters' => self::TOOL_FILTERS,
            'builtinTools' => AiCustomerServiceTools::builtinCatalog(),
            'contentTypes' => AiCustomerServiceKnowledge::contentTypeOptions(),
            'knowledgeFiles' => AiCustomerServiceKnowledge::fileSummary(self::config()),
            'version' => self::VERSION,
        ];
    }

    /** @return list<array{id:int,label:string}> */
    private static function chatModelOptions(): array
    {
        $models = [];
        try {
            foreach (\App\Models\AiModel::query()->where('enabled', 1)->orderBy('sort_order', 'asc')->get() as $model) {
                if (!\App\Core\AiService::modelSupportsChat($model)) continue;
                $provider = \App\Models\AiProvider::find((int)($model['provider_id'] ?? 0));
                if (!$provider || empty($provider['enabled'])) continue;
                $models[] = [
                    'id' => (int)($model['id'] ?? 0),
                    'label' => trim((string)($provider['name'] ?? 'AI') . ' · ' . (string)($model['name'] ?? '模型')),
                ];
            }
        } catch (\Throwable $_) {
            $models = [];
        }
        return $models;
    }

    /** @param array<string,mixed> $form @return array<string,mixed> */
    private static function sectionFor(array $form, string $page): array
    {
        foreach ((array)($form['sections'] ?? []) as $section) {
            if ((string)($section['key'] ?? '') === $page) return is_array($section) ? $section : [];
        }
        return ['key' => $page, 'label' => self::ADMIN_PAGES[$page][0] ?? $page, 'description' => '', 'fields' => []];
    }

    // ACS_MARKER_HELPERS

    // ---------------------------------------------------------------- 显示规则

    private static function pathAllowed(array $config): bool
    {
        $mode = (string)$config['url_mode'];
        if ($mode === 'all') return true;
        $rules = (array)$config['url_rules'];
        if ($rules === []) return $mode !== 'include';
        $path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
        foreach ($rules as $rule) {
            if (self::pathMatches($path, (string)$rule)) return $mode === 'include';
        }
        return $mode !== 'include';
    }

    private static function scheduleAllowed(array $config): bool
    {
        if (empty($config['schedule_enabled'])) return true;
        if (!in_array((int)date('N'), (array)$config['schedule_days'], true)) return false;
        $now = date('H:i');
        $start = (string)$config['schedule_start'];
        $end = (string)$config['schedule_end'];
        return $start <= $end ? ($now >= $start && $now <= $end) : ($now >= $start || $now <= $end);
    }

    private static function pathMatches(string $path, string $rule): bool
    {
        $rule = trim($rule);
        if ($rule === '') return false;
        $regex = '#^' . str_replace('\\*', '.*', preg_quote($rule, '#')) . '$#u';
        return @preg_match($regex, $path) === 1;
    }

    private static function validateUrlRules(string $raw): string
    {
        $rules = self::urlRules($raw);
        if (count($rules) > 80) return '页面路径规则最多 80 条';
        foreach ($rules as $rule) {
            if (mb_strlen($rule) > 250 || $rule[0] !== '/' || str_contains($rule, '?') || str_contains($rule, '#')) {
                return '页面路径规则必须是以 / 开头、不含查询参数的相对路径：' . mb_substr($rule, 0, 40);
            }
        }
        return '';
    }

    /** @return list<string> */
    private static function urlRules(string $raw): array
    {
        return self::lines($raw, 80, 250);
    }

    /** @return list<int> */
    private static function scheduleDays(string $raw): array
    {
        $out = [];
        foreach (explode(',', $raw) as $day) {
            $number = (int)trim($day);
            if ($number >= 1 && $number <= 7) $out[] = $number;
        }
        return array_values(array_unique($out)) ?: [1, 2, 3, 4, 5, 6, 7];
    }

    // ---------------------------------------------------------------- 标量清洗

    /** @return list<string> */
    public static function lines(string $raw, int $maxLines, int $maxChars): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') $out[] = mb_substr($line, 0, $maxChars);
        }
        return array_values(array_slice(array_unique($out), 0, $maxLines));
    }

    public static function bool(mixed $value): bool
    {
        return is_bool($value) ? $value : in_array(strtolower(trim((string)(is_scalar($value) ? $value : ''))), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(mixed $value, int $min, int $max, int $fallback): int
    {
        return max($min, min($max, is_numeric($value) ? (int)$value : $fallback));
    }

    public static function float(mixed $value, float $min, float $max, float $fallback): float
    {
        return max($min, min($max, is_numeric($value) ? (float)$value : $fallback));
    }

    public static function choice(mixed $value, array $allowed, string $fallback): string
    {
        $value = strtolower(trim((string)(is_scalar($value) ? $value : '')));
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    public static function text(mixed $value, int $limit, string $fallback): string
    {
        if (!is_scalar($value) && $value !== null) return $fallback;
        $value = trim(\App\Core\Security::sanitizeUserInput((string)$value));
        return $value === '' ? $fallback : mb_substr($value, 0, $limit);
    }

    public static function color(string $value, string $fallback): string
    {
        return preg_match('/^#[0-9a-f]{6}$/i', trim($value)) === 1 ? strtoupper(trim($value)) : $fallback;
    }

    public static function httpUrl(string $value): string
    {
        $value = trim($value);
        return preg_match('#^https?://#i', $value) === 1 && filter_var($value, FILTER_VALIDATE_URL) !== false
            ? mb_substr($value, 0, 2048) : '';
    }

    /** 表情包等站内资源允许相对路径（/uploads/...），外链仍然只允许 http(s)。 */
    public static function relativeOrHttpUrl(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 2048) return '';
        if (str_starts_with($value, '/') && !str_starts_with($value, '//') && !str_contains($value, '..')) {
            return preg_match('#^/[A-Za-z0-9._~!$&\'()*+,;=:@%/-]*$#', $value) === 1 ? $value : '';
        }
        return self::httpUrl($value);
    }

    public static function email(string $value): string
    {
        $value = trim($value);
        return $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? mb_substr($value, 0, 190) : '';
    }

    /**
     * 独立接口地址：只接受公网 HTTPS，且不允许带 user/pass/query/fragment。
     * 带凭证或查询串的地址会把密钥材料写进日志与出站 URL，不给这个机会。
     */
    public static function customEndpoint(string $value): string
    {
        $value = self::httpUrl($value);
        if ($value === '' || !preg_match('#^https://#i', $value)) return '';
        $parts = parse_url($value);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            return '';
        }
        return $value;
    }

    public static function slugValue(string $value, int $limit): string
    {
        $value = strtolower((string)preg_replace('/[^a-z0-9_-]/i', '', trim($value)));
        return substr($value, 0, $limit);
    }

    private static function timeValue(string $value, string $fallback): string
    {
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', trim($value)) === 1 ? trim($value) : $fallback;
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** 测试与后台预览用：丢掉单请求缓存。 */
    public static function flushCache(): void
    {
        self::$configCache = null;
        self::$declarationCache = null;
    }
}

/** 声明式插件 API 的处理器；plugin.json 里的 api 段据此派生文档与 Agent 动作。 */
final class AiCustomerServiceApi
{
    /** @return array<string,mixed> */
    public static function status(array $arguments, array $context): array
    {
        return ['ok' => true, 'message' => '已读取 AI 客服状态', 'data' => AiCustomerService::statusSummary()];
    }
}
