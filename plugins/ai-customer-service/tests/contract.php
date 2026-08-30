<?php
declare(strict_types=1);

/**
 * AI客服插件的本地契约测试（无 DB、无网络、不加载核心）。
 *
 * 盯的是那些"能编译但会悄悄退化"的约定：
 * - 声明式设置的字段数必须留在核心上限（100）以内，八个子页面一一对齐；
 * - 描述词与资料必须是两套字段，不能又合回一个 textarea；
 * - 访客聊天只能走同源 CSRF 会话通道，独立接口密钥不得进任何声明式契约；
 * - 每个 JSON 型字段的 max_length 在 UTF-8 最坏情况下不能撑爆 settings.value(TEXT)；
 * - 三个已修的真 bug 各留一条回归断言（引流气泡塌条、正文色不可配、逐页保存互锁）；
 * - 卡片一律由前台用 DOM 渲染，服务端不得拼 HTML 片段。
 */
$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$manifestRaw = (string)file_get_contents($root . '/plugin.json');
$manifest = json_decode($manifestRaw, true);
$assert(is_array($manifest), 'plugin.json 必须是合法 JSON');
$manifest = is_array($manifest) ? $manifest : [];
$assert((string)($manifest['slug'] ?? '') === 'ai-customer-service', 'slug 必须与插件目录一致');
$assert((string)($manifest['name'] ?? '') === 'AI客服', '市场名称必须是 AI客服');
$assert((string)($manifest['version'] ?? '') === '1.2.2', '版本号应为 1.2.2');

// ------------------------------------------------------------------ 声明式设置
$sections = is_array($manifest['settings']['sections'] ?? null) ? $manifest['settings']['sections'] : [];
$sectionKeys = array_map(static fn (array $s): string => (string)($s['key'] ?? ''), $sections);
$expectedPages = ['conversation', 'trigger', 'appearance', 'ai', 'knowledge', 'tools', 'guardrails', 'composer'];
$assert($sectionKeys === $expectedPages, 'settings 必须是这八个子页面：' . implode(' / ', $expectedPages));
$assert(count($sections) <= 20, '核心的分组上限是 20');

$fields = [];
foreach ($sections as $section) {
    foreach ((array)($section['fields'] ?? []) as $field) {
        $key = (string)($field['key'] ?? '');
        if ($key === '') continue;
        $assert(!isset($fields[$key]), '字段重名：' . $key);
        $fields[$key] = ['field' => $field, 'section' => (string)($section['key'] ?? '')];
    }
}
$total = count($fields);
$assert($total > 60 && $total <= 100, "声明字段总数必须在 61~100（核心硬上限 100），当前 {$total}");
$assert($total <= 96, "字段数已到 {$total}，离核心上限 100 只剩 " . (100 - $total) . " 个，加字段前先把成组配置并进 JSON 字段");

// 分页归属：这几个字段挪页会让后台逐页保存的范围出错
$expectPage = [
    'enabled' => 'conversation', 'url_rules' => 'trigger', 'greeting_json' => 'trigger',
    'theme_json' => 'appearance', 'layout_json' => 'appearance', 'text_color' => 'appearance',
    'muted_color' => 'appearance', 'font_family' => 'appearance',
    'system_prompt' => 'ai', 'knowledge_json' => 'knowledge', 'knowledge_mode' => 'knowledge',
    'tools_json' => 'tools', 'cards_json' => 'tools', 'owner_json' => 'tools',
    'guardrails_json' => 'guardrails', 'events_json' => 'guardrails', 'stickers_json' => 'composer',
];
foreach ($expectPage as $key => $page) {
    $assert(isset($fields[$key]), '缺少字段：' . $key);
    if (isset($fields[$key])) {
        $assert($fields[$key]['section'] === $page, $key . ' 应属于 ' . $page . ' 子页面');
    }
}

// 描述词与资料必须分开
$assert(($fields['system_prompt']['section'] ?? '') === 'ai' && ($fields['knowledge_base']['section'] ?? '') === 'knowledge',
    '描述词（system_prompt）与手工补充资料（knowledge_base）必须分属不同子页面，1.1.0 的合并形态是要修的问题');

// 1.1.0 的 bug：这两个颜色被读取却没声明，导致深色背景下正文不可读
foreach (['text_color', 'muted_color'] as $colorKey) {
    $assert((string)($fields[$colorKey]['field']['type'] ?? '') === 'color', $colorKey . ' 必须是可配置的 color 字段（回归：1.1.0 只读不声明）');
}
$colorCount = 0;
foreach ($fields as $entry) {
    if ((string)($entry['field']['type'] ?? '') === 'color') $colorCount++;
}
$assert($colorCount === 10, "README 承诺十个独立颜色，声明里实际 {$colorCount} 个");

// ACS_MARKER_TEST_1

// ------------------------------------------------------------------ TEXT 容量
// settings.value 是 MySQL TEXT = 65535 字节；中文按 UTF-8 3 字节算，声明的
// max_length 最坏情况不能溢出，否则会静默截断成坏 JSON。
foreach ($fields as $key => $entry) {
    $max = $entry['field']['max_length'] ?? null;
    if (!is_int($max)) continue;
    $assert($max * 3 <= 65535, $key . ' 的 max_length=' . $max . '，UTF-8 最坏 ' . ($max * 3) . ' 字节，会撑爆 settings.value(TEXT)');
}
$jsonKeys = ['theme_json', 'layout_json', 'greeting_json', 'knowledge_json', 'tools_json',
    'cards_json', 'owner_json', 'guardrails_json', 'events_json', 'stickers_json'];
foreach ($jsonKeys as $key) {
    $assert(isset($fields[$key]), '缺少 JSON 型字段：' . $key);
    if (!isset($fields[$key])) continue;
    $field = $fields[$key]['field'];
    $assert((string)($field['type'] ?? '') === 'textarea', $key . ' 必须是 textarea 型');
    $default = (string)($field['default'] ?? '');
    $assert(is_array(json_decode($default, true)), $key . ' 的默认值必须是合法 JSON 对象');
    $assert(mb_strlen($default) <= (int)($field['max_length'] ?? 0), $key . ' 的默认值超过了自己的 max_length');
}

// ------------------------------------------------------------------ API / Agent 边界
$api = is_array($manifest['api'] ?? null) ? $manifest['api'] : [];
$assert(count($api) === 1, '公开插件 API 只应声明无密钥的状态端点');
$status = $api[0] ?? [];
$assert((string)($status['endpoint'] ?? '') === 'status' && (string)($status['method'] ?? '') === 'GET', '状态端点契约错误');
$assert((string)($status['permission'] ?? '') === 'ai_customer_service.view', '状态端点权限错误');
$assert(!str_contains($manifestRaw, 'custom_api_key'), '独立接口密钥不得出现在声明式 settings / API / Agent 清单里');

foreach ((array)($manifest['assets'] ?? []) as $asset) {
    $path = is_array($asset) ? (string)($asset['src'] ?? '') : '';
    $assert($path !== '' && is_file($root . '/' . $path), '声明的资源必须存在：' . $path);
    $assert((string)($asset['version'] ?? '') === (string)($manifest['version'] ?? ''), $path . ' 的 version 应与插件版本一致，否则缓存不会失效');
}

// ------------------------------------------------------------------ 源码约定
$read = static fn (string $rel): string => (string)file_get_contents($root . '/' . $rel);
$plugin = $read('plugin.php');
$service = $read('src/AiCustomerService.php');
$chat = $read('src/AiCustomerServiceChat.php');
$tools = $read('src/AiCustomerServiceTools.php');
$cards = $read('src/AiCustomerServiceCards.php');
$guards = $read('src/AiCustomerServiceGuardrails.php');
$knowledge = $read('src/AiCustomerServiceKnowledge.php');
$adminSrc = $read('src/AiCustomerServiceAdmin.php');
$view = $read('views/admin.php');
$panels = $read('views/_partials/panels.php');
$adminJs = $read('assets/admin.js');
$adminCss = $read('assets/admin.css');
$widgetCss = $read('assets/customer-service.css');
$widgetJs = $read('assets/customer-service.js');
$presetCss = $read('assets/preset-cards.css');
$preview = $read('views/_partials/preview.php');
$readme = $read('README.md');

foreach ($expectedPages as $page) {
    $assert(str_contains($service, "'{$page}' => ["), 'ADMIN_PAGES 缺少子页面：' . $page);
    $assert(str_contains($panels, "'{$page}' => ["), '后台面板分组缺少：' . $page);
}
$assert(str_contains($plugin, "'/admin/ai-customer-service/{page}'"), '缺少子页面统一 {page} 路由');
$assert(str_contains($plugin, "'/admin/ai-customer-service/x/{action}'"), '缺少后台异步动作路由');
$assert(str_contains($plugin, "'/ai-customer-service/chat'"), '缺少访客同源聊天路由');
$assert(str_contains($plugin, "'/ai-customer-service/action'"), '缺少访客动作路由（询盘提交 / 重开会话）');
$assert(str_contains($view, 'acs_save_keys') && str_contains($service, 'SAVE_KEYS_FIELD'), '逐页保存必须声明本页字段范围');

// 性能回归：1.1.0 的 config() 逐 key 调 value()，一次前台渲染要把 plugin.json 解析 67 遍
$assert(!preg_match('/private static function value\(/', $service),
    'config() 不应再有逐 key 的 value() 包装（那会让核心把清单解析并全量校验 N 遍）');
$assert(str_contains($service, "PluginSettingsService::values(self::SLUG)['values']"), 'config() 必须一次性读全部值');
$assert(str_contains($service, 'private static ?array $configCache'), '缺少单请求配置缓存');

// ACS_MARKER_TEST_2

// 逐页保存互锁回归：核心对留空的 number 字段会写空串且不查 min，回读又变成 0.0，
// 于是在 A 页清空一个 min>0 的数字框会让另外几页全部存不进去。修法是回填前先自愈。
$assert(str_contains($service, 'private static function repairValue('), '缺少回填前的值自愈（否则历史脏值会锁死其他子页面）');
$assert(str_contains($service, 'describeErrors'), '保存失败时必须把出错字段名带给用户，核心只给"有 N 个设置项校验失败"');

// 密钥边界
$assert(str_contains($service, 'Security::encryptApiKey'), '独立接口密钥必须在保存前加密');
$assert(str_contains($service, 'Security::decryptApiKey'), '独立接口密钥必须在服务端调用前解密');
$assert(!str_contains($service, "'custom_api_key' =>"), 'publicWidgetConfig 不得下发密钥');
// 精确取出 publicWidgetConfig 的返回数组再查，避免把后面 statusSummary 的字段名算进来
$publicBody = '';
if (preg_match('/function publicWidgetConfig\(array \$config\): array\s*\{(.*?)\n    \}/s', $service, $m) === 1) {
    $publicBody = $m[1];
}
$assert($publicBody !== '', '找不到 publicWidgetConfig 的函数体');
foreach (['system_prompt', 'knowledge', 'guardrails', 'refusal', 'custom_api', 'tools'] as $leak) {
    $assert(!str_contains($publicBody, $leak), '注入前台的配置里不得出现 ' . $leak . '（描述词/资料/约束/工具定义只在服务端参与请求构造）');
}

// 出站与工具
$assert(str_contains($chat, 'OutboundHttpClient::postJson'), '独立接口必须走受限出站客户端');
$assert(str_contains($chat, 'self::CUSTOM_TIMEOUT') && str_contains($chat, 'CUSTOM_TIMEOUT = 30'),
    '出站超时应写 30 秒——核心会把它夹在 1..30，写 45 是误导');
$assert(!str_contains($chat, '/api/v1/'), '访客聊天不得伪装成后台 Bearer token API');
$assert(str_contains($chat, "'tool_choice'] = 'auto'"), '工具调用必须交给模型自行决定（tool_choice=auto）');
$assert(str_contains($chat, 'tool_call_id'), '工具结果必须按 OpenAI 约定带 tool_call_id 回灌');
$assert(str_contains($tools, 'function definitions(') && str_contains($tools, 'function execute('), '工具注册表缺少声明或执行入口');
foreach (['recommend_products', 'recommend_articles', 'search_site', 'lookup_knowledge',
          'show_inquiry_form', 'request_handoff', 'show_owner_card', 'show_social_links'] as $tool) {
    $assert(str_contains($tools, "'{$tool}'"), '缺少内置工具：' . $tool);
    $assert(str_contains($service, "'{$tool}' =>"), 'BUILTIN_TOOL_DEFAULTS 缺少开关：' . $tool);
}
$assert(str_contains($service, 'TOOL_FILTERS'), '自定义工具的过滤条件必须走白名单常量');
$assert(!preg_match('/whereRaw\([^)]*\$(filter|column|field)/', $knowledge), '内容查询不得把用户输入拼进 whereRaw');
$assert(str_contains($knowledge, 'ContentWorkflow::applyPublicScope'), '客服只能引用前台可见的内容');

// 卡片必须是数据，不是 HTML
$assert(!preg_match('/<(div|span|a|button|img)\b/i', $cards), '卡片服务端不得拼 HTML 片段，必须只产出结构化数据');
foreach (['content', 'inquiry', 'handoff', 'owner', 'social'] as $kind) {
    $assert(str_contains($widgetJs, $kind . ': render'), '前台缺少卡片渲染器：' . $kind);
}
$assert(str_contains($widgetJs, 'CARD_RENDERERS'), '前台必须有统一的卡片渲染表');
$assert(str_contains($widgetJs, "el('div', 'acs-message-bubble', content)")
    || str_contains($widgetJs, "el('div', 'acs-message-bubble', String(data.reply"),
    '消息必须用 textContent 写入（el() 内部用的是 textContent），不解释任何 HTML');
$assert(!str_contains($widgetJs, 'innerHTML = data') && !str_contains($widgetJs, 'insertAdjacentHTML'),
    '前台不得把服务端/模型返回的内容当 HTML 插入');

// 引流气泡塌成竖条的回归：绝对定位只给 right 时 shrink-to-fit 可用宽是负数
$assert(preg_match('/\.acs-teaser\s*\{[^}]*width:\s*max-content/s', $widgetCss) === 1,
    '.acs-teaser 必须显式给宽度（回归：只写 right: calc(100% + N) 会塌成一列单字）');
$assert(str_contains($widgetCss, '.acs-panel[hidden]') && str_contains($widgetCss, 'display: none !important'),
    '面板必须有 [hidden] 的 display:none 兜底，否则加载即展开且关不掉');
// 断行为不断字面量：只要 .acs-widget--bare 把浮标尺寸（以及它带来的间距）归零就算过，
// 不关心声明里还顺手写了什么。之前写死整行字符串，改一处格式就误报。
$assert(preg_match('/\.acs-widget--bare\s*\{[^}]*--acs-size:\s*0(?:px)?\s*;/', $widgetCss) === 1,
    '没有浮标时必须把浮标高度视作 0，否则面板凭空悬空（1.1.0 的 acs-widget--bare 是个没人用的死类）');
$assert(str_contains($widgetJs, 'panel.hidden = !state.open'), '开关状态必须落到 hidden 属性');
$assert(str_contains($widgetCss, 'var(--acs-text)') && str_contains($widgetCss, 'var(--acs-muted)'),
    '正文与次要文字必须走可配置变量');

/* 面板被压塌的回归。
 * 1.2.0 之前面板用 grid-template-rows 按「位置」分配行，而飘带 / 人工入口 / AI 标识
 * 都是可选节点：少一个，聊天区就从 1fr 那行掉到 auto 行上，被压成内容高度，
 * 卡片被腰斩、快捷问题压在产品卡上面。改成 flex 后与子节点数量无关。 */
$assert(preg_match('/\.acs-panel\s*\{[^}]*display:\s*flex/s', $widgetCss) === 1,
    '.acs-panel 必须用 flex：grid 的定位行会随可选节点出现/消失整体错位');
$assert(preg_match('/\.acs-panel\s*>\s*\.acs-chat\s*\{[^}]*min-height:\s*0/s', $widgetCss) === 1,
    '聊天区必须 flex:1 + min-height:0，否则内容一多就把面板顶破而不是内部滚动');
$assert(preg_match('/\.acs-chat\s*\{[^}]*overflow-x:\s*hidden/s', $widgetCss) === 1,
    '聊天区必须显式关掉横轴溢出（只设 overflow-y 时另一轴会计算成 auto，带旋转的叠卡会被裁边）');

/* 「新标记 + 旧样式」的回归：1.2.0 第一版换了整套 DOM 却忘了同步前台 CSS，
 * 契约测试全绿、页面全花。这里把 JS/PHP 实际输出的类名与 CSS 规则做交叉核对。 */
$emitted = [];
foreach ([$widgetJs, $service] as $source) {
    foreach ([
        "/el\('[a-z]+',\s*'([^']+)'/",
        "/className = '([^']+)'/",
        "/classList\.(?:add|toggle)\('([^']+)'/",
        '/class="([^"]*acs-[^"]*)"/',
    ] as $pattern) {
        if (!preg_match_all($pattern, $source, $matches)) continue;
        foreach ($matches[count($matches) - 1] as $blob) {
            foreach (preg_split('/\s+/', $blob) ?: [] as $name) {
                if (preg_match('/^acs-[a-zA-Z0-9-]+$/', $name)) $emitted[$name] = true;
            }
        }
    }
}
$styles = $widgetCss . $presetCss;
$orphans = [];
foreach (array_keys($emitted) as $name) {
    // 纯 JS 钩子类不需要样式，它们靠同节点上的通用类取观感
    if (in_array($name, ['acs-close', 'acs-restart'], true)) continue;
    if (!str_contains($styles, '.' . $name)) $orphans[] = $name;
}
$assert($emitted !== [], '类名提取失败，检查测试自身的正则');
$assert($orphans === [], '这些类名前台会输出但没有任何 CSS 规则：' . implode('、', array_slice($orphans, 0, 8)));

/* 站长指定「原样照搬」的三段设计稿：留住签名值，防止后人当成魔法数字优化掉。 */
foreach ([
    'perspective(905px)' => '叠卡的 905px 透视',
    'rotateZ(-8deg)' => '叠卡的 -8deg 倾角',
    'linear-gradient(180deg, #FF0055 0%, #000066 100%)' => '叠卡第一张的渐变',
    'perspective(2000px) rotateY(-90deg)' => '叠卡详情面的翻转',
    '255px 15px 225px 15px / 15px 225px 15px 255px' => '涂鸦名片的手绘圆角',
    '--accent-lavender: #c0bbfe' => '涂鸦名片的薰衣草点缀色',
    'acsAvatarBlink' => '涂鸦名片的眨眼动画',
] as $needle => $what) {
    $assert(str_contains($presetCss, $needle), '照搬的设计稿被改动了：缺少' . $what);
}
$assert(str_contains($widgetJs, 'renderStackCard') && str_contains($widgetJs, 'renderDoodleCard'),
    '叠卡与涂鸦名片必须各有独立渲染器，输出与照搬样式同形的 DOM');
foreach (['acs-file-1', 'acs-folder-front-wrapper', 'acs-folder-label', 'acs-file-tag', 'acs-counter-number'] as $folderClass)
{
    $assert(str_contains($adminCss, '.' . $folderClass), '后台资料柜缺少照搬的文件夹卡类：' . $folderClass);
}
// 五张纸的类名是拼出来的（'acs-file-' + i），所以只核对前缀与其余静态类名
$assert(str_contains($adminJs, "'acs-file acs-file-'"), '资料柜的五张纸必须输出 acs-file-1..5');
foreach (['acs-folder-front-wrapper', 'acs-folder-label', 'acs-file-tag', 'acs-counter-number', 'acs-status-dot', 'acs-search-input'] as $folderClass) {
    $assert(str_contains($adminJs, $folderClass), '资料柜 DOM 没有输出：' . $folderClass);
}

// 约束与事件
$assert(str_contains($guards, 'function promptSection(') && str_contains($guards, 'function screenReply('),
    '约束必须同时进提示词并在出站再筛一遍（提示词是软约束）');
$assert(str_contains($guards, 'function detectEvent('), '缺少意图识别');
foreach (['inquiry', 'handoff', 'social', 'owner'] as $event) {
    $assert(str_contains($service, "'{$event}' => ["), '缺少意图事件默认值：' . $event);
}
$assert(str_contains($chat, 'Inquiry::create'), '询盘必须真的落到核心询盘表，否则"询盘转化"没有意义');
$assert(str_contains($chat, "'ai_customer_service:inquiry:'"), '询盘提交必须有独立的更紧的限流');
$assert(str_contains($chat, 'field_'), '询盘字段必须由访客本人提交，模型不参与填写');

// 后台观感
$assert(str_contains($adminCss, '--acs-radius: 6px') && str_contains($adminCss, '--acs-h: 32px'),
    '后台必须按 antd 的几何 token（6px 圆角 / 32px 控件高）');
$assert(str_contains($adminCss, 'var(--ui-text'), '后台取色必须复用宿主的 --ui-* token，否则深色主题下会是一块浅色孤岛');
$assert(str_contains($adminCss, '.acs-folder-card') && str_contains($adminJs, 'acs-folder-toggle'),
    '资料柜必须是可展开的文件夹卡');
$assert(str_contains($adminJs, 'PANELS.knowledge') && str_contains($adminJs, 'content-search'),
    '资料柜必须能上传文件并检索站内内容');
$assert(str_contains($adminJs, 'PANELS.presets') && str_contains($adminJs, 'PANELS.palette'), '缺少预设主题与配色面板');
$assert(str_contains($adminJs, 'initDrag') && str_contains($adminJs, 'data-acs-drag'), '外观页必须支持拖拽定位');

/* 预览必须复用前台的标记与样式，不能再养一套 acs-pv-* 仿制品——那是"预览与实际不符"的根源。 */
$assert(str_contains($service, 'function previewMarkup(')
    && str_contains($service, 'self::panelMarkup($config, self::sampleConversation($config)'),
    '预览必须走 previewMarkup() 复用 panelMarkup/launcherMarkup，而不是另写一套标记');
$assert(str_contains($preview, 'AiCustomerService::previewMarkup'), '预览视图必须渲染 previewMarkup()');
$assert(!preg_match('/\.acs-pv-[a-z]/', $adminCss), 'admin.css 里不该再有 acs-pv-* 仿制样式');
// 只禁"仿制组件"的类名；--acs-pv-k 与 data-acs-pv-state 这类预览控件命名是正常的
foreach (['acs-pv-widget', 'acs-pv-panel', 'acs-pv-bubble', 'acs-pv-header', 'acs-pv-launcher', 'acs-pv-card'] as $imitation) {
    $assert(!str_contains($adminJs, $imitation), 'admin.js 里不该再引用仿制节点：' . $imitation);
    $assert(!str_contains($adminCss, $imitation), 'admin.css 里不该再有仿制样式：' . $imitation);
}
$assert(str_contains($plugin, "add_action('admin.head'") && str_contains($plugin, 'assets/customer-service.css'),
    '后台页必须注入前台样式，否则预览拿不到真实观感');
$assert(preg_match('/\.acs-widget\s*\{[^}]*--acs-accent/s', $widgetCss) === 1,
    '前台变量必须挂在类选择器上，否则后台预览（没有那个 id）拿不到默认值');

/* 图片类字段必须能开系统媒体库，不能只让人手抄 URL。 */
$assert(str_contains($adminJs, 'MediaPicker.open'), '图片字段必须能调起核心媒体选择框');
foreach (['avatar_url', 'launcher_image_url'] as $mediaField) {
    $assert(str_contains($adminJs, "'" . $mediaField . "'"), '缺少媒体选择接线：' . $mediaField);
}
$assert(str_contains($adminJs, 'function mediaField('), '面板里的图片字段（如站长头像）也要走媒体库');

/* 预设不再用渐变与大投影。契约测试不加载核心，所以只能断源码文本。 */
$assert(!str_contains($widgetCss, 'linear-gradient(135deg, var(--acs-header-bg)'), '顶栏不应再有渐变');
$assert(str_contains($service, "['none', 'sm', 'md', 'lg'], 'none')"), '默认不打投影');
$presetBlock = '';
if (preg_match('/function themePresets\(\): array\s*\{(.+?)\n    \}/s', $service, $presetMatch)) {
    $presetBlock = $presetMatch[1];
}
$assert($presetBlock !== '', '找不到 themePresets() 的定义');
$assert(!preg_match("/'panel_shadow' => '(sm|md|lg)'/", $presetBlock), '预设不该默认打投影');
$assert(!str_contains($presetBlock, "'header_style' => 'gradient'"), '预设不该用渐变顶栏');
$assert(substr_count($presetBlock, "'panel_shadow' => 'none'") >= 6, '六套预设都要显式写 panel_shadow = none');

/* plugin.json 的声明默认值与 config() 的兜底必须一致。
 * 两处各写一份是必然的（核心用前者渲染表单、插件用后者兜底），但不一致时
 * 「新装站点」和「读兜底的代码路径」会看到两套观感——这次就踩了：改了 config()
 * 没改 plugin.json，预览里顶栏还是紫的。 */
foreach ([
    'header_color' => '#FFFFFF',
    'header_text_color' => '#111827',
    'accent_color' => '#4F46E5',
    'surface_color' => '#FFFFFF',
    'text_color' => '#111827',
    'muted_color' => '#6B7280',
] as $colorKey => $expected) {
    $assert(($fields[$colorKey]['field']['default'] ?? '') === $expected,
        $colorKey . ' 的声明默认值应为 ' . $expected . '，当前 ' . (string)($fields[$colorKey]['field']['default'] ?? ''));
    $assert(str_contains($service, "'" . $colorKey . "' => self::color((string)\$get('" . $colorKey . "', ''), '" . $expected . "')"),
        $colorKey . ' 在 config() 里的兜底值必须与声明默认值一致');
}
$assert(($fields['panel_shadow']['field']['default'] ?? '') === 'none', '声明的默认投影应为 none');
$themeDefault = json_decode((string)($fields['theme_json']['field']['default'] ?? ''), true);
$assert(is_array($themeDefault), 'theme_json 默认必须是合法 JSON');
$assert(($themeDefault['header_style'] ?? '') !== 'gradient', 'theme_json 默认不该是渐变顶栏');
$assert(str_contains($presetBlock, "'" . (string)($themeDefault['preset'] ?? '') . "' => ["),
    'theme_json 默认引用的预设必须真的存在：' . (string)($themeDefault['preset'] ?? ''));
$assert(str_contains($adminJs, 'PANELS.customtools') && str_contains($adminJs, 'PANELS.cards')
    && str_contains($adminJs, 'PANELS.owner') && str_contains($adminJs, 'PANELS.guardrails')
    && str_contains($adminJs, 'PANELS.events') && str_contains($adminJs, 'PANELS.stickers')
    && str_contains($adminJs, 'PANELS.greeting'), '缺少工具/卡片/名片/约束/事件/表情/问候面板');
$assert(str_contains($adminJs, '.admin-sidebar-nav a'), 'admin.js 需要为左侧菜单补高亮');
$assert(str_contains($adminSrc, 'Auth::requirePermission'), '异步动作必须各自重新校验权限');
$assert(str_contains($adminSrc, 'Storage::put'), '表情包上传应复用核心 Storage 的白名单与消毒');
$assert(str_contains($knowledge, 'STORAGE_PATH'), '知识库文本必须落在 webroot 之外');
$assert(!str_contains($knowledge, 'Storage::put'), '知识库原文不应进公开上传目录');

// README 承诺
foreach (['描述词', '资料', '工具', '卡片', '边界', '表情'] as $topic) {
    $assert(str_contains($readme, $topic), 'README 必须说明「' . $topic . '」');
}
$assert(str_contains($readme, '有意不进入公开 API / Agent'), 'README 必须记录访客聊天与密钥的 API/Agent 例外');

if ($failures !== []) {
    echo "AI客服插件契约 FAILED:\n";
    foreach ($failures as $failure) echo ' - ' . $failure . "\n";
    exit(1);
}
echo "AI客服插件契约通过（设置声明 / 容量 / 密钥边界 / 工具与卡片 / 三条回归）。\n";
