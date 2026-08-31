<?php
/**
 * 表单主体。
 *
 * 每个子页面 = 若干张 Card。Card 里可以是「声明式字段网格」，也可以是一个由
 * admin.js 接管的挂载点（资料柜、工具构建器、约束标签、表情包等）。声明里没提到的
 * 字段不会被渲染，也不会进 acs_save_keys，所以加字段只改 plugin.json 就够。
 *
 * @var string $page
 * @var list<array<string,mixed>> $fields
 * @var array<string,mixed> $values
 * @var list<array{id:int,label:string}> $models
 * @var bool $customKeySet
 * @var list<string> $jsonKeys
 * @var array<string,mixed> $config
 * @var array<string,mixed> $presets
 * @var array{count:int,chars:int,missing:list<string>,limit:int} $knowledgeFiles
 */
$byKey = [];
foreach ($fields as $field) {
    if (($field['key'] ?? '') !== '') $byKey[(string)$field['key']] = $field;
}

/** 分组定义：[标题, 说明, 字段键, 挂载点(可空)]。留空的字段键表示"本组剩下的全部"。 */
$groups = [
    'conversation' => [
        ['身份', '访客在顶栏和第一条消息里看到的东西。', ['enabled', 'brand_name', 'team_label', 'welcome_message'], ''],
        ['引导', '快捷问题会渲染成一排可点的胶囊，点了等于替访客发这句话。', ['quick_replies_title', 'quick_replies', 'input_placeholder'], ''],
        ['兜底与转接', '模型不可用、或访客要真人时走这条路。', ['unavailable_message', 'handoff_label', 'handoff_url'], ''],
        ['会话额度', '上下文长度与单个访客的提问频率。', ['history_limit', 'rate_limit_per_minute'], ''],
    ],
    'trigger' => [
        ['出现条件', '设备、页面与触发时机。多个条件里先满足的生效。', ['device_mode', 'url_mode', 'url_rules', 'delay_seconds', 'scroll_percent', 'exit_intent'], ''],
        ['自动动作', '自动展开、提醒动画与角标属于同一组"自动打扰"，可以整组只做一次。', ['show_launcher', 'initial_open', 'initial_open_delay', 'once_per_session', 'badge_enabled', 'attention_effect'], ''],
        ['主动招呼', '浮标旁的引流气泡、悬停提示，以及窗口里的飘带与定时问候。', ['tooltip_text', 'teaser_enabled', 'teaser_text', 'ribbon_enabled', 'ribbon_text'], 'greeting'],
        ['服务时段', '不在服务时段时整个挂件不渲染。', ['schedule_enabled', 'schedule_days', 'schedule_start', 'schedule_end'], ''],
        ['体验增强', '时间戳、刷新后接续会话、未读提醒，以及浮标旁直接摊开联系方式。', [], 'experience'],
    ],
    'appearance' => [
        ['预设主题', '一键套一整套配色、圆角与气泡形态，之后仍可逐项微调。', [], 'presets'],
        ['浮标', '', ['launcher_style', 'launcher_icon', 'launcher_image_url', 'launcher_corner', 'widget_size', 'position'], ''],
        ['窗口', '', ['panel_width', 'panel_height', 'panel_radius', 'panel_shadow', 'font_size', 'font_family'], ''],
        ['配色', '十个颜色各自独立。深色窗口背景记得同时把"窗口正文颜色"调亮。', ['accent_color', 'surface_color', 'text_color', 'muted_color', 'header_color', 'header_text_color', 'bot_bubble_color', 'bot_bubble_text_color', 'visitor_bubble_color', 'visitor_bubble_text_color'], 'palette'],
        ['位置与微调', '右侧预览里可以直接拖浮标、引流气泡、飘带与角标，数值会同步写回这里。', ['desktop_offset_x', 'desktop_offset_y', 'mobile_offset_x', 'mobile_offset_y'], 'layout'],
        ['头像与标识', '', ['avatar_url', 'show_avatar', 'show_powered_by'], ''],
    ],
    'ai' => [
        ['模型来源', '默认复用系统已配置的对话模型；也可以填一个独立的 OpenAI 兼容接口。', ['provider_mode', 'system_model_id', 'custom_api_endpoint', 'custom_model'], 'secret'],
        ['描述词', '只写身份、语气与回答结构。产品资料请放到「知识与资料」页。', ['system_prompt'], ''],
        ['生成参数', '', ['temperature', 'max_tokens'], ''],
    ],
    'knowledge' => [
        ['资料柜', '点文件夹展开，可上传知识库文件；也可以从站内已有内容里挑。', ['knowledge_mode'], 'knowledge'],
        ['手工补充', '', ['knowledge_base'], ''],
        ['注入策略', '每次提问按相关度挑片段，直到用满额度。额度越大越准也越贵。', ['knowledge_budget', 'knowledge_strategy'], ''],
    ],
    'tools' => [
        ['工具开关', '关掉总开关后客服只会用文字回答，不再返回任何卡片。', ['tools_enabled', 'tool_max_rounds'], 'tools'],
        ['自定义工具', '按内容类型 + 白名单条件定义一个"查站内内容并回卡片"的工具。', [], 'customtools'],
        ['卡片样式', '每类卡片都有多套预设，可以随时换。', [], 'cards'],
        ['站长名片', '名片卡与社媒卡的数据源。', [], 'owner'],
    ],
    'guardrails' => [
        ['话题范围', '收敛模式下，允许清单之外的问题一律走越界回复。', ['scope_mode', 'refusal_message'], 'guardrails'],
        ['意图事件', '命中关键词时除了提示模型，还会直接把对应卡片推给访客。', ['event_log_enabled', 'inquiry_notify_email'], 'events'],
    ],
    'composer' => [
        ['输入框', '', ['message_max_chars', 'send_on_enter'], ''],
        ['表情', 'emoji 列表可增删；表情包支持上传 PNG / GIF / WebP。', ['emoji_enabled', 'sticker_enabled'], 'stickers'],
    ],
];
$pageGroups = $groups[$page] ?? [[$page, '', array_keys($byKey), '']];

// 已分组的键之外还有剩余字段时，兜一张"其他"卡，保证新增字段不会被静默藏起来。
$used = [];
foreach ($pageGroups as $group) {
    foreach ($group[2] as $key) $used[$key] = true;
}
$rest = [];
foreach ($byKey as $key => $field) {
    if (!isset($used[$key]) && !in_array($key, $jsonKeys, true)) $rest[] = $key;
}
if ($rest !== []) $pageGroups[] = ['其他', '', $rest, ''];
?>

<?php foreach ($pageGroups as [$title, $note, $keys, $mount]): ?>
    <section class="acs-a-card" data-acs-group="<?= e($mount !== '' ? $mount : $title) ?>">
        <header class="acs-a-card-head">
            <h2><?= e($title) ?></h2>
            <?php if ($note !== ''): ?><p><?= e($note) ?></p><?php endif; ?>
        </header>

        <?php if ($mount !== ''): ?>
            <div class="acs-a-mount" data-acs-mount="<?= e($mount) ?>"></div>
        <?php endif; ?>

        <?php
        $visible = array_values(array_filter($keys, static fn (string $k): bool => isset($byKey[$k])));
        if ($visible !== []):
        ?>
            <div class="acs-a-grid">
                <?php foreach ($visible as $key): ?>
                    <?= $this->include('_partials/field', [
                        'field' => $byKey[$key],
                        'value' => $values[$key] ?? ($byKey[$key]['default'] ?? ''),
                        'models' => $models,
                        'page' => $page,
                    ]) ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($mount === 'secret'): ?>
            <?= $this->include('_partials/secret', ['customKeySet' => $customKeySet]) ?>
        <?php endif; ?>
    </section>
<?php endforeach; ?>

<?php // JSON 型字段统一藏在最后：由各面板读写，用户不直接编辑。 ?>
<div class="acs-a-json" hidden>
    <?php foreach ($jsonKeys as $key): ?>
        <?php if (!isset($byKey[$key])) continue; ?>
        <textarea name="setting_<?= e($key) ?>" data-acs-json="<?= e($key) ?>"
                  maxlength="<?= (int)($byKey[$key]['max_length'] ?? 8000) ?>"><?= e((string)($values[$key] ?? ($byKey[$key]['default'] ?? ''))) ?></textarea>
    <?php endforeach; ?>
</div>
