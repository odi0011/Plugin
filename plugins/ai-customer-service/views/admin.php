<?php
/**
 * AI客服后台。
 *
 * 布局按 Ant Design 的语义拆：PageHeader（标题 + 状态）→ Tabs（八个子页面）→
 * 左侧 Form（Card 分块）+ 右侧固定的实时预览。所有控件的外观由 assets/admin.css
 * 用一套 antd token 统一供给，插件不引 antd 运行时（后台是 PHP 渲染的服务端页面，
 * 为了几个控件拖一个 React 运行时不值得）。
 *
 * 复合配置（主题/布局/资料/工具/卡片/约束/事件/表情/定向/同意）以隐藏 textarea 承载 JSON，
 * 由 admin.js 里的面板读写；这样字段仍然是声明式 settings 的一员，保存、校验、
 * API/Agent 契约都不用另开一条路。
 *
 * @var array<string,mixed> $form
 * @var string $page
 * @var array<string,array{0:string,1:string}> $pages
 * @var array<string,mixed> $section
 * @var list<array{id:int,label:string}> $models
 * @var bool $customKeySet
 * @var bool $active
 * @var array<string,mixed> $status
 * @var array<string,mixed> $config
 * @var array<string,mixed> $presets
 * @var array<string,string> $fonts
 * @var array<string,array<string,string>> $cardPresets
 * @var array<string,array{0:string,1:string}> $socialNetworks
 * @var array<string,string> $eventKinds
 * @var array<string,string> $sourceKinds
 * @var array<string,string> $toolFilters
 * @var array<string,array<string,string>> $builtinTools
 * @var list<array{kind:string,type:string,label:string}> $contentTypes
 * @var array{count:int,chars:int,missing:list<string>,limit:int} $knowledgeFiles
 * @var array{country:string,languages:list<string>} $geo
 * @var list<string> $saveErrors
 * @var string $version
 */
$this->extend('admin/views/layouts/main');
$this->section('title', 'AI客服');
$this->startSection('content');

$values = (array)($form['values'] ?? []);
$fields = (array)($section['fields'] ?? []);
$jsonKeys = ['theme_json', 'layout_json', 'greeting_json', 'knowledge_json', 'tools_json',
    'cards_json', 'owner_json', 'guardrails_json', 'events_json', 'stickers_json', 'experience_json',
    'targeting_json', 'consent_json'];

$saveKeys = [];
foreach ($fields as $field) {
    if (($field['key'] ?? '') !== '') $saveKeys[] = (string)$field['key'];
}

/** 传给 admin.js 的引导数据：面板要用的字典与当前归一化后的值。 */
$boot = [
    'page' => $page,
    'version' => $version,
    'endpoint' => admin_url('/ai-customer-service/x'),
    'csrf' => \App\Core\Csrf::token(),
    'presets' => array_map(static fn (array $p): array => ['label' => $p['label'], 'note' => $p['note'], 'values' => $p['values']], $presets),
    'fonts' => $fonts,
    'cardPresets' => $cardPresets,
    'socialNetworks' => $socialNetworks,
    'eventKinds' => $eventKinds,
    'sourceKinds' => $sourceKinds,
    'toolFilters' => $toolFilters,
    'builtinTools' => $builtinTools,
    'contentTypes' => $contentTypes,
    'knowledgeFiles' => $knowledgeFiles,
    'geo' => $geo,
    // 模型清单与上次保存的报错字段都只有 JS 用得上：前者判断「系统模型一个都没配」
    // 要不要出提示，后者把校验失败的控件标红。不进 $boot 这两个功能就是死的。
    'models' => $models,
    'saveErrors' => $saveErrors,
    'config' => $config,
];
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$withPreview = in_array($page, ['conversation', 'trigger', 'appearance', 'composer', 'tools'], true);
?>

<div class="acs-a" data-acs-admin data-acs-page="<?= e($page) ?>"<?= $withPreview ? ' data-acs-has-preview="1"' : '' ?>>
    <?= $this->include('_partials/header', ['page' => $page, 'pages' => $pages, 'section' => $section, 'status' => $status, 'active' => $active]) ?>

    <form method="post" action="<?= admin_url('/ai-customer-service') ?>" class="acs-a-layout<?= $withPreview ? '' : ' is-wide' ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="acs_return_page" value="<?= e($page) ?>">
        <input type="hidden" name="acs_save_keys" value="<?= e(implode(',', $saveKeys)) ?>">

        <div class="acs-a-main">
            <?= $this->include('_partials/panels', [
                'page' => $page, 'fields' => $fields, 'values' => $values, 'models' => $models,
                'customKeySet' => $customKeySet, 'jsonKeys' => $jsonKeys, 'config' => $config,
                'presets' => $presets, 'knowledgeFiles' => $knowledgeFiles, 'section' => $section,
            ]) ?>

            <div class="acs-a-savebar">
                <button type="submit" class="acs-a-btn acs-a-btn--primary"><i class="bi bi-check2" aria-hidden="true"></i> 保存本页</button>
                <a class="acs-a-btn" href="<?= admin_url('/plugins') ?>">返回插件列表</a>
            </div>
        </div>

        <?php if ($withPreview): ?>
            <?= $this->include('_partials/preview', ['config' => $config, 'page' => $page]) ?>
        <?php endif; ?>
    </form>

    <script id="acs-admin-boot" type="application/json"><?= json_encode($boot, $jsonFlags) ?></script>
</div>

<?php $this->endSection(); ?>
