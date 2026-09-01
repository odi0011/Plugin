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
/* 版本号只有 plugin.json 这一处真源。这里只校验格式，跨文件一致性在"源码约定"里统一比对：
 * 1.2.3 就是只改了这一行，PHP 常量 / 前台 JS / 五个 assets[].version 全留在 1.2.2，
 * 于是浏览器拿的还是旧缓存。写死字面量的断言只会逼着每次发版再改一次测试，换成交叉核对。 */
$version = (string)($manifest['version'] ?? '');
$assert(preg_match('/^\d+\.\d+\.\d+$/', $version) === 1, '版本号必须是 x.y.z 形式，当前：' . $version);

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
    'experience_json' => 'trigger', 'targeting_json' => 'trigger', 'consent_json' => 'conversation',
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
    'cards_json', 'owner_json', 'guardrails_json', 'events_json', 'stickers_json', 'experience_json',
    'targeting_json', 'consent_json'];
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

/* 版本号跨文件一致性。1.2.3 的教训：只改 plugin.json 会让代码侧的常量、注入前台的
 * version、以及五个 assets[].version 一起过期，浏览器继续用旧缓存，而测试全绿。 */
$assert(str_contains($service, "public const VERSION = '" . $version . "';"),
    "AiCustomerService::VERSION 必须与 plugin.json 的 {$version} 一致（版本号只在 plugin.json 写一处）");
$assert(!preg_match("/\bversion: '\d+\.\d+\.\d+'/", $widgetJs),
    '前台 JS 不得写死版本号，必须从注入配置读 config.version');
$assert(str_contains($service, "'version' => self::VERSION"), '注入前台的配置必须带上版本号，供公开 API 回报');
$assert(!preg_match('/\d+\.\d+\.\d+/', $plugin), 'plugin.php 不得写死版本号（含注释），一律读 AiCustomerService::VERSION');
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

/* 上面那轮只认 ^acs-，不带前缀的状态类（is-*、has-*）会整批漏过去 —— has-error 就是
 * 这么在询盘表单里挂了很久却没有任何样式的：校验失败时字段根本不变红。这里补一轮。
 * bi 是 Bootstrap Icons 的基类，active 是宿主后台左侧菜单自己的高亮类，都由外部提供样式。 */
$stateStyles = $widgetCss . $presetCss . $adminCss;
$stateOrphans = [];
foreach ([$widgetJs, $adminJs] as $source) {
    foreach ([
        "/classList\.(?:add|toggle|remove)\('([^']+)'/",
        "/className = '([^']+)'/",
        "/el\('[a-zA-Z0-9]+',\s*'([^']+)'/",
    ] as $pattern) {
        if (!preg_match_all($pattern, $source, $matches)) continue;
        foreach ($matches[count($matches) - 1] as $blob) {
            foreach (preg_split('/\s+/', $blob) ?: [] as $name) {
                if ($name === '' || str_starts_with($name, 'acs-')) continue;
                if (in_array($name, ['bi', 'active'], true)) continue;
                if (!preg_match('/^[a-z][a-z0-9-]*$/', $name)) continue;
                if (!str_contains($stateStyles, '.' . $name)) $stateOrphans[$name] = true;
            }
        }
    }
}
$assert($stateOrphans === [], '这些状态类被 JS 挂上却没有任何 CSS 规则：'
    . implode('、', array_slice(array_keys($stateOrphans), 0, 8)));
$assert(str_contains($widgetCss, '.acs-field.has-error input'), '询盘表单校验失败必须真的看得出来');

/* 询盘：服务端有「邮箱和电话至少留一个」的硬规则，两个字段都配上时谁都不是 required，
 * 前台不认这条就会放过一次注定被拒的提交。 */
$assert(str_contains($cards, "'eitherContact'") && str_contains($widgetJs, 'card.eitherContact'),
    '「邮箱和电话至少留一个」必须在前台也拦一道，别让访客白跑一趟服务端');
$assert(str_contains($widgetJs, "status.setAttribute('aria-live'"), '询盘表单的校验/提交结果必须能被读屏播报');
$assert(str_contains($widgetJs, "setAttribute('aria-invalid'"), '出错字段要写 aria-invalid，不能只靠红框');
$assert(str_contains($widgetJs, 'retry_after'), '限流必须把服务端算好的等待时间写给访客，不能只说"稍后再试"');
/* 表情/表情包按钮各自展开下面那块 picker，状态要能被读屏感知 */
$assert(str_contains($service, 'aria-expanded="false" aria-label="插入表情"')
    && str_contains($widgetJs, "setAttribute('aria-expanded', 'true')"), 'picker 触发按钮必须同步 aria-expanded');
/* 保存失败：字段名只进消息文本的话，站长得在几十个控件里自己找 */
$assert(str_contains($service, 'function stashSaveErrors(') && str_contains($service, 'function takeSaveErrors(')
    && str_contains($service, "'saveErrors' => self::takeSaveErrors()")
    && str_contains($adminJs, 'function paintSaveErrors('),
    '保存校验失败要把出错控件标出来，不能只在顶部丢一句话');
$assert(!str_contains($service, "'save_values'") && !str_contains($service, "\$_SESSION[self::SESSION_KEY]['save_input']"),
    '出错字段只存 key：提交里可能带独立接口密钥，值不能落会话');
$assert(str_contains($adminCss, '.acs-a-item.has-error input'), '后台出错字段要有可见样式');

/* adminPageData() 备好了值、视图的 $boot 却忘了转发，是这套引导数据特有的一类静默故障：
 * JS 侧一律写成 Array.isArray(BOOT.x) ? ... : [] 这种安全兜底，所以读到 undefined 时
 * 功能直接变成空操作，页面不报错、契约也不响。warnModelUnset / paintSaveErrors 两个
 * 都这么哑过一轮。这里把 admin.js 实际读的 BOOT 键与 $boot 声明的键做交叉核对。 */
preg_match('/\$boot = \[(.*?)\n\];/s', $view, $bootMatch);
$assert(!empty($bootMatch[1]), '找不到 views/admin.php 里的 $boot 定义');
$bootKeys = [];
// 只认行首缩进四格的顶层键，否则 presets 那行 array_map 闭包里的 label/note/values
// 会混进来，真漏了同名键反而查不出
if (preg_match_all("/^    '([a-zA-Z][a-zA-Z0-9_]*)'\s*=>/m", $bootMatch[1], $km)) {
    foreach ($km[1] as $k) $bootKeys[$k] = true;
}
$readKeys = [];
if (preg_match_all('/\bBOOT\.([a-zA-Z][a-zA-Z0-9_]*)/', $adminJs, $rm)) {
    foreach ($rm[1] as $k) $readKeys[$k] = true;
}
$assert(count($bootKeys) > 10 && $readKeys !== [], '$boot / BOOT 键提取失败，检查测试自身的正则');
$missingBoot = array_diff(array_keys($readKeys), array_keys($bootKeys));
$assert($missingBoot === [], 'admin.js 读了这些 BOOT 键但 $boot 没有转发（功能会静默失效）：'
    . implode('、', $missingBoot));
$assert(isset($bootKeys['models']) && isset($bootKeys['saveErrors']),
    '$boot 必须转发 models 与 saveErrors：前者判断「一个对话模型都没配」，后者标红出错字段');

/* 服务时段：判定必须在客户端。服务端判的话，整页缓存会把渲染那一刻的答案冻住 ——
 * 上午缓存的页面到晚上还带挂件，晚上缓存的第二天上午一整天都没有。Vary 救不了「时间」
 * 这个维度（没有对应的请求头），所以下发规则让浏览器按站点时区现算。 */
$assert(!preg_match('/!self::scheduleAllowed\(\$config\)/', $service),
    'renderWidget 不得在服务端判服务时段：结果会被整页缓存冻住');
$assert(str_contains($widgetJs, 'function withinSchedule(') && str_contains($widgetJs, 'if (!withinSchedule())'),
    '服务时段必须由客户端判定并接进显隐规则');
$assert(str_contains($service, "'tz' => date_default_timezone_get()"),
    '下发的必须是时区名而不是算好的偏移量：偏移量含夏令时，缓存久了会差一小时');
$assert(str_contains($widgetJs, 'timeZone: String(sched.tz)'),
    '客户端必须按站点时区算：站长说的 9:00 是他自己的 9:00，不是访客那边的 9:00');
$assert(str_contains($widgetJs, 'start <= end ? (now.hm >= start && now.hm <= start') === false
    && str_contains($widgetJs, 'start <= end ? (now.hm >= start && now.hm <= end) : (now.hm >= start || now.hm <= end)'),
    '客户端的时段判定必须和服务端一致，含跨零点的 start > end');

/* 非服务时段：原来整个挂件消失，访客连留个联系方式的地方都没有（Chaty / Tidio 都是
 * 给一条 away 说明）。默认必须仍是 hide —— 已经在用这个功能的站点本意就是别出现。 */
$assert(str_contains($service, "'away' => ['mode' => 'hide'"), '非服务时段的默认行为必须仍是隐藏');
$assert(str_contains($service, "self::choice(\$away['mode'] ?? '', ['hide', 'notice'], 'hide')"),
    'away.mode 只能是 hide / notice');
$assert(str_contains($service, 'data-acs-away') && str_contains($widgetCss, '.acs-away'),
    '说明条要有标记与样式');
$assert(str_contains($adminJs, "show('[data-acs-away]'") && str_contains($adminJs, 'function syncAway('),
    '说明条必须接进后台预览，否则站长写完那句话没地方看效果');
$assert(str_contains($adminJs, 'BOOT.schedule'), '服务时段面板要能自证：把服务端此刻的读数摆出来');

/* 三处默认文案必须一字不差：PHP 与 JS 都会在清空时回填它 */
$awayPhp = preg_match("/'away' => \['mode' => 'hide', 'text' => '([^']+)'\]/", $service, $ap) ? $ap[1] : '';
$awayJs = preg_match("/var AWAY_TEXT_DEFAULT = '([^']+)'/", $adminJs, $aj) ? $aj[1] : '';
$awayJson = '';
if (isset($fields['experience_json'])) {
    $decoded = json_decode((string)($fields['experience_json']['field']['default'] ?? ''), true);
    $awayJson = (string)($decoded['away']['text'] ?? '');
}
$assert($awayPhp !== '' && $awayPhp === $awayJs && $awayPhp === $awayJson,
    'away 默认文案在 PHP / admin.js / plugin.json 三处必须一致，当前：'
    . $awayPhp . ' | ' . $awayJs . ' | ' . $awayJson);

/* var(--x) 在 --x 没定义又没 fallback 时，整条声明在计算值阶段失效：页面不报错、
 * 样式静默丢掉。--acs-soft 就是这么写进去过一次的。 */
$cssDefined = [];
foreach ([$widgetCss, $presetCss, $adminCss, $service, $cards, $widgetJs, $adminJs] as $src) {
    if (preg_match_all('/(--[a-zA-Z0-9-]+)\s*:/', $src, $dm)) {
        foreach ($dm[1] as $name) $cssDefined[$name] = true;
    }
    if (preg_match_all("/setProperty\(\s*['\"](--[a-zA-Z0-9-]+)['\"]/", $src, $sm)) {
        foreach ($sm[1] as $name) $cssDefined[$name] = true;
    }
}
$undefinedVars = [];
foreach ([$widgetCss, $presetCss, $adminCss] as $src) {
    if (!preg_match_all('/var\(\s*(--[a-zA-Z0-9-]+)\s*([,)])/', $src, $vm, PREG_SET_ORDER)) continue;
    foreach ($vm as $one) {
        if ($one[2] === ',') continue;                    // 有 fallback，失效也有后备值
        if (!isset($cssDefined[$one[1]])) $undefinedVars[$one[1]] = true;
    }
}
$assert($cssDefined !== [], 'CSS 变量提取失败，检查测试自身的正则');
$assert($undefinedVars === [], '这些自定义属性没有定义又没有 fallback（整条声明会静默失效）：'
    . implode('、', array_slice(array_keys($undefinedVars), 0, 8)));

/* 自动弹出不是访客点的：抢焦点会把光标从站点自己的搜索框/结账表单里挪走，
 * 圈 Tab 会把键盘用户锁在一块他没要求打开的面板里。两件事都只在 !silent 时做。 */
$assert(str_contains($widgetJs, 'function setOpen(open, silent)') && str_contains($widgetJs, 'setOpen(true, true)'),
    '自动弹出必须走静默通道，不能和访客点开走同一条路');
$assert(str_contains($widgetJs, 'if (silent) return;'), '自动弹出不得把焦点挪进输入框');
$assert(str_contains($widgetJs, '!state.open || !state.modal) return;'), '自动弹出的面板不得圈住 Tab');
$assert(str_contains($widgetJs, "panel.setAttribute('aria-modal', 'true')")
    && str_contains($widgetJs, "panel.removeAttribute('aria-modal')"),
    '圈 Tab 与 aria-modal 必须成对：只圈不声明，读屏会说页面还能去而键盘出不去');

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
    && str_contains($adminJs, 'PANELS.greeting') && str_contains($adminJs, 'PANELS.experience')
    && str_contains($adminJs, 'PANELS.targeting') && str_contains($adminJs, 'PANELS.consent'),
    '缺少工具/卡片/名片/约束/事件/表情/问候/体验/定向/同意面板');
// 后台给出的选项必须是 normalizeTheme() 真收的值，否则保存后被静默改回去，像是保存失败
$assert(!str_contains($adminJs, "gradient: '渐变'"), '顶栏没有渐变档，后台不该提供这个选项');
$assert(str_contains($service, "['solid', 'light'], 'light')"), '顶栏形态只有纯色与浅底两档');
$assert(str_contains($adminJs, '.admin-sidebar-nav a'), 'admin.js 需要为左侧菜单补高亮');
$assert(str_contains($adminSrc, 'Auth::requirePermission'), '异步动作必须各自重新校验权限');
$assert(str_contains($adminSrc, 'Storage::put'), '表情包上传应复用核心 Storage 的白名单与消毒');
$assert(str_contains($knowledge, 'STORAGE_PATH'), '知识库文本必须落在 webroot 之外');
$assert(!str_contains($knowledge, 'Storage::put'), '知识库原文不应进公开上传目录');

/* 1.4.0 的定向 / 同意 / 二维码：这三件都是"要么两处同时在、要么就是静默故障"的接线。
 * 只在后台能配、前台不生效，是最难被发现的一类回归，所以逐处钉住。 */
$assert(str_contains($service, 'private static function targetingAllowed(')
    && str_contains($service, '!self::targetingAllowed($config)'),
    '定向判定必须真的串进 renderWidget() 的渲染门槛');
$assert(str_contains($service, 'HTTP_CF_IPCOUNTRY'), '国家只从 CDN 的地区请求头读，插件不自带 IP 库');
$assert(!preg_match('#https?://[^\s\'"]*(ipapi|ip-api|geoip|ipinfo|ipstack)#i', $service . $widgetJs . $adminJs),
    '定向不得回源第三方 IP 库：那会把访客地址送出站（注释里提到 GeoIP 没问题，出现请求地址才是问题）');
$assert(str_contains($service, "'geo' => ['country' => self::visitorCountry()") && str_contains($view, "'geo' => \$geo"),
    '后台必须能自证这次读到的国家/语言，否则"浮标不见了"分不清是规则还是缺请求头');
$assert(str_contains($service, 'function consentGiven(') && str_contains($chat, "'consent_required'"),
    '同意门槛必须在服务端拦一道，前台被 CDN 缓存也要拦得住');
$assert(str_contains($widgetJs, "'consent_required'"), '前台必须认得服务端的 consent_required 并重新升起门槛');
$actionBody = '';
if (preg_match('/function dispatchAction\(\): void\s*\{(.+?)\n    \}/s', $chat, $actionMatch)) {
    $actionBody = $actionMatch[1];
}
$assert($actionBody !== '', '找不到 dispatchAction() 的定义');
if ($actionBody !== '') {
    $historyAt = strpos($actionBody, "'history'");
    $gateAt = strpos($actionBody, 'consentGiven()');
    $assert($historyAt !== false && $gateAt !== false && $historyAt < $gateAt,
        'history 动作要排在同意门槛之前：刚打开面板还没勾同意的访客不该先吃一条错误');
}
/* 定向 + 整页缓存：不声明 Vary，第一个访客的那一份会被发给所有人，
 * 规则看起来配好了其实随机生效。挂 frontend.head 是因为视图还在 ob 缓冲里、头还没发出去。 */
$assert(str_contains($service, 'function sendTargetingVary(') && str_contains($plugin, "add_action('frontend.head'")
    && str_contains($plugin, 'sendTargetingVary'), '开了定向必须声明 Vary，否则整页缓存会把规则打乱');
$assert(str_contains($service, 'headers_sent()'), 'Vary 必须先问 headers_sent()，主题提前 flush 时不能抛 warning');
$assert(str_contains($service, "header('Vary: "), 'Vary 头必须真的发出去');

/* 二维码浮层是按触发元素的视口坐标摆的，聊天区一滚坐标就过期；
 * 钉住的那份不会自己收，必须显式收掉。 */
$assert(preg_match('/chat\.addEventListener\(\'scroll\'.{0,600}?hideQr\(true\)/s', $widgetJs) === 1,
    '聊天区滚动时必须收掉二维码浮层，否则它会停在跟自己无关的消息上面');
/* 同意门槛竖起来时输入框是 disabled 的，focus() 对它是空操作 —— 焦点必须交给勾选框 */
$assert(str_contains($widgetJs, 'if (input.disabled) { if (consentBox) consentBox.focus(); }'),
    '同意门槛重新升起后焦点要落到勾选框，不能 focus 一个 disabled 的输入框');
$assert(str_contains($service, 'aria-describedby="acs-consent-text"') && str_contains($service, 'id="acs-consent-text"'),
    '锁住的输入框要用 aria-describedby 指到同意正文，读屏才知道为什么不可用');
/* 12 个渠道 × 最大浮标叠起来比视口还高，顶上几个会点不着 */
$assert(preg_match('/\.acs-channels\s*\{[^}]*max-height:[^}]*overflow-y:\s*auto/s', $widgetCss) === 1,
    '浮标渠道竖排必须有高度上限与滚动，否则渠道一多就顶出屏幕');
$assert(preg_match('/\.acs-consent-text\s*\{[^}]*max-height:/s', $widgetCss) === 1,
    '同意正文要封顶，600 字的正文会把聊天区挤没');
/* 同意门槛是「会话内容」页唯一的新增可视元素，必须和飘带/引流气泡一样在预览里当场生效：
 * 预览节点常在（服务端 $preview 分支），显隐与文案由 admin.js 拨。 */
$assert(str_contains($adminJs, 'function syncConsent(') && str_contains($adminJs, "show('[data-acs-consent]'"),
    '同意门槛要接进预览：拨开关、改文案都得当场看到，不能保存刷新才知道长什么样');
$assert(str_contains($service, "empty(\$consent['enabled']) && !\$preview"),
    '预览里即使关着也要输出同意节点，前台仍然一个字节都不输出');

$assert(str_contains($service, 'data-acs-qr') && str_contains($widgetJs, 'function showQr('),
    '渠道二维码需要标记与悬浮层两处一起在');
$assert(str_contains($cards, "'qr' =>") && str_contains($adminJs, '二维码图（可选）'),
    '社媒条目要带二维码字段，后台也要能逐条上传');
foreach (['messenger', 'line', 'viber', 'sms', 'skype', 'discord'] as $network) {
    $assert(str_contains($service, "'" . $network . "' => ["), 'SOCIAL_NETWORKS 缺少渠道：' . $network);
    $assert(str_contains($adminJs, $network . ':'), '后台名片面板缺少该渠道的"值"字段文案：' . $network);
}

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
