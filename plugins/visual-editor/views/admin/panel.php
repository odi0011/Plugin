<?php
/**
 * 可视化编辑器：内容表单内的编辑入口 + 全屏编辑台骨架。
 *
 * 由 admin.content_editor.panel 钩子触发渲染（src/Panel.php）。这里输出三块：
 *
 *   1. #ve-panel —— 核心模式面板。外层带 data-content-mode-panel="<模式键>"，
 *      显隐完全由核心的共用切换条接管，本视图不写一行显隐逻辑。里面只有一张
 *      说明卡与「打开可视化编辑器」按钮：真正的编辑发生在全屏编辑台里。
 *   2. #ve-veil —— 渐隐渐现的全页蒙版与加载动画（打字机文案 + 光标选框动效）。
 *      首次编辑要把整段 HTML 解析成编辑树，这个等待必须如实告诉用户。
 *   3. #ve-stage —— 全屏编辑台：顶栏 + 控件面板 + 画布 + 检查器。
 *
 * 2 与 3 在 JS 启动时被移到 <body> 末尾——它们是 position:fixed 的浮层，
 * 留在表单里会被任何带 transform / overflow 的祖先裁掉。
 *
 * 画布内容不在服务端预渲染：树可能要现解析，那是首次编辑最慢的一步，
 * 放进表单渲染会拖慢每次打开内容页。用户点按钮时才请求 convert 端点。
 */
if (!defined('CODE_SCHEMA_VERSION')) exit;

/** @var string $modeKey */
/** @var string $sourceType */
/** @var int $sourceId */
/** @var string $sourceKey */
/** @var string $fieldName */
/** @var bool $canUse */
/** @var bool $canUseCodeWidget */
/** @var bool $managed */
/** @var bool $stale */
/** @var bool $firstRun */
/** @var bool $hasOriginal */
/** @var string $convertUrl */
/** @var string $saveUrl */
/** @var string $persistUrl */
/** @var string $restoreUrl */
/** @var string $csrfToken */
/** @var array $widgets */
/** @var array $widgetGroups */
/** @var array $styleProperties */
/** @var array $styleLabels */
/** @var array $fieldLabels */
/** @var array $presets */
/** @var array $breakpoints */
/** @var int $containerMax */
/** @var string $baseCss */
/** @var int $status */

$config = [
    'modeKey' => $modeKey,
    'sourceType' => $sourceType,
    'sourceId' => $sourceId,
    'sourceKey' => $sourceKey,
    'fieldName' => $fieldName,
    'canUse' => $canUse,
    'canUseCodeWidget' => $canUseCodeWidget,
    'managed' => $managed,
    'stale' => $stale,
    'firstRun' => $firstRun,
    'hasOriginal' => $hasOriginal,
    // 这条内容当前的发布状态：编辑台只据此提示，保存不会改变它。
    'status' => $status,
    'convertUrl' => $convertUrl,
    'saveUrl' => $saveUrl,
    'persistUrl' => $persistUrl,
    'restoreUrl' => $restoreUrl,
    'csrfToken' => $csrfToken,
    'widgets' => $widgets,
    'widgetGroups' => $widgetGroups,
    'styleProperties' => $styleProperties,
    'styleLabels' => $styleLabels,
    'fieldLabels' => $fieldLabels,
    'presets' => $presets,
    'breakpoints' => $breakpoints,
    'containerMax' => $containerMax,
    'baseCss' => $baseCss,
];

/** 加载动画的文案。首次与回访分开：首次要解释「为什么慢」。 */
$loaderLines = $firstRun
    ? ['正在把这篇内容', '翻译成可视化区块']
    : ['正在打开', '可视化编辑台'];
?>
<div id="ve-panel" data-content-mode-panel="<?= e($modeKey) ?>" style="display:none;">
<?php if (!$canUse): ?>
    <div class="ve-panel-notice">
        <i class="bi bi-shield-lock"></i>
        可视化编辑需要管理员账号与 visual_editor.edit 权限：编译产物包含作用域样式，
        与代码模式一样只对能使用代码的人开放。
    </div>
<?php else: ?>
    <div class="ve-entry">
        <div class="ve-entry-glow" aria-hidden="true"></div>
        <div class="ve-entry-body">
            <h3 class="ve-entry-title">可视化编辑台</h3>
            <p class="ve-entry-text">
                拖控件、调间距、按断点分别设样式——保存时只把渲染好的 HTML 与作用域 CSS
                写进内容字段，编辑树与你的原始内容分开存放。停用或卸载插件，页面照旧。
            </p>
            <ul class="ve-entry-facts">
                <li><i class="bi bi-hdd"></i> 编辑树存在插件目录，不进内容字段</li>
                <li><i class="bi bi-shield-check"></i> 首次接管前的原文自动留档，可一键还原</li>
                <li><i class="bi bi-phone"></i> 桌面 / 平板（<?= (int)$breakpoints['tablet'] ?>px） / 手机（<?= (int)$breakpoints['mobile'] ?>px）三档断点</li>
            </ul>
            <div class="ve-entry-actions">
                <button type="button" class="ve-open-btn" data-ve-open>
                    <i class="bi bi-magic"></i> 打开可视化编辑器
                </button>
                <?php if ($stale): ?>
                    <span class="ve-entry-warn"><i class="bi bi-exclamation-triangle"></i> 这段内容在可视化之外被改过，打开时会重新解析</span>
                <?php elseif ($firstRun): ?>
                    <span class="ve-entry-hint">首次打开需要转换格式，会比之后慢一些</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>
</div>

<?php if ($canUse): ?>
<!-- 全页蒙版 + 加载动画。渐隐渐现由 GSAP 驱动（assets/editor.js）。 -->
<div id="ve-veil" class="ve-veil" hidden aria-hidden="true">
    <div class="ve-veil-inner">
        <svg class="ve-veil-scene" viewBox="0 0 614 390" role="img" aria-label="可视化编辑加载动画">
            <g class="ve-veil-box">
                <rect x="42" y="40" width="530" height="310" rx="10" fill="none" stroke="#2563EB" stroke-width="2" stroke-dasharray="8 8"/>
                <rect x="34" y="32" width="16" height="16" rx="3" fill="#fff" stroke="#2563EB" stroke-width="2"/>
                <rect x="564" y="32" width="16" height="16" rx="3" fill="#fff" stroke="#2563EB" stroke-width="2"/>
                <rect x="34" y="342" width="16" height="16" rx="3" fill="#fff" stroke="#2563EB" stroke-width="2"/>
                <rect x="564" y="342" width="16" height="16" rx="3" fill="#fff" stroke="#2563EB" stroke-width="2"/>
                <rect class="ve-veil-bar" x="76" y="88" width="300" height="22" rx="6" fill="#2563EB" opacity=".16"/>
                <rect class="ve-veil-bar" x="76" y="132" width="440" height="14" rx="5" fill="#2563EB" opacity=".10"/>
                <rect class="ve-veil-bar" x="76" y="162" width="380" height="14" rx="5" fill="#2563EB" opacity=".10"/>
                <rect class="ve-veil-bar" x="76" y="212" width="150" height="96" rx="8" fill="#2563EB" opacity=".13"/>
                <rect class="ve-veil-bar" x="246" y="212" width="150" height="96" rx="8" fill="#2563EB" opacity=".13"/>
                <rect class="ve-veil-bar" x="416" y="212" width="150" height="96" rx="8" fill="#2563EB" opacity=".13"/>
            </g>
            <g class="ve-veil-cursor">
                <path d="M0 0 L0 20 L5.2 15.2 L8.6 22 L12.4 20.2 L9 13.6 L16 13.2 Z" fill="#111827" stroke="#fff" stroke-width="1.2"/>
                <g transform="translate(14 18)">
                    <rect width="96" height="24" rx="6" fill="#2563EB"/>
                    <text x="10" y="16" fill="#fff" font-size="12" font-family="system-ui, sans-serif">可视化编辑</text>
                </g>
            </g>
        </svg>
        <p class="ve-veil-copy">
            <?php foreach ($loaderLines as $index => $line): ?>
                <span class="ve-veil-line" data-ve-veil-line="<?= (int)$index ?>"><?= e($line) ?></span>
            <?php endforeach; ?>
            <span class="ve-veil-caret" aria-hidden="true"></span>
        </p>
        <p class="ve-veil-note" data-ve-veil-note>
            <?= $firstRun
                ? '这是第一次用可视化编辑这条内容：需要把现有 HTML 逐段解析成区块与控件，可能要等十几秒。转换结果会存在插件自己的目录里，下次打开就很快了，你的原始内容也会同时留档。'
                : '正在读取上次保存的编辑树。' ?>
        </p>
        <p class="ve-veil-error" data-ve-veil-error hidden></p>
    </div>
</div>

<!-- 全屏编辑台。结构与类名在 assets/editor.css 里对应。 -->
<div id="ve-stage" class="ve-stage" hidden>
    <header class="ve-stage-bar">
        <div class="ve-stage-brand">
            <span class="ve-stage-dot" aria-hidden="true"></span>
            <span class="ve-stage-name">可视化编辑</span>
            <span class="ve-stage-source"><?= e($sourceType) ?> #<?= (int)$sourceId ?></span>
        </div>
        <div class="ve-stage-notch" data-ve-notch>
            <div class="ve-stage-notch-track">
                <?php foreach ([['desktop', '桌面', 'bi-display'], ['tablet', '平板', 'bi-tablet-landscape'], ['mobile', '手机', 'bi-phone']] as $bp): ?>
                    <button type="button" class="ve-notch-btn" data-ve-breakpoint="<?= e($bp[0]) ?>"
                            title="<?= e($bp[1]) ?>视图"<?= $bp[0] === 'desktop' ? ' data-ve-active="1"' : '' ?>>
                        <i class="bi <?= e($bp[2]) ?>"></i><span><?= e($bp[1]) ?></span>
                    </button>
                <?php endforeach; ?>
                <span class="ve-notch-thumb" data-ve-notch-thumb aria-hidden="true"></span>
            </div>
        </div>
        <div class="ve-stage-actions">
            <span class="ve-stage-status" data-ve-status></span>
            <?php /*
              这三个动作不是日常操作（重新导入 / 还原原文 / 仅写回字段），
              直接摆在顶栏只会让人以为「保存」有四种，还得猜每一种是干什么。
              收进一颗「更多」里，每一条带一句说明——用得上的人找得到，
              用不上的人不会误点。
            */ ?>
            <div class="ve-more" data-ve-more>
                <button type="button" class="ve-btn ve-btn-ghost ve-more-toggle" data-ve-more-toggle
                        title="更多操作" aria-haspopup="true" aria-expanded="false">
                    <i class="bi bi-three-dots"></i>
                </button>
                <div class="ve-more-menu" data-ve-more-menu hidden>
                    <button type="button" class="ve-more-item" data-ve-action="apply-only">
                        <i class="bi bi-arrow-down-square"></i>
                        <span class="ve-more-text">
                            <span class="ve-more-label">仅写回字段</span>
                            <span class="ve-more-desc">把排版结果填进内容框，不入库；想回表单先看看时用。</span>
                        </span>
                    </button>
                    <button type="button" class="ve-more-item" data-ve-action="reimport">
                        <i class="bi bi-arrow-repeat"></i>
                        <span class="ve-more-text">
                            <span class="ve-more-label">重新导入</span>
                            <span class="ve-more-desc">丢弃当前编辑，按内容字段现在的样子重新解析一次。</span>
                        </span>
                    </button>
                    <?php if ($hasOriginal): ?>
                        <button type="button" class="ve-more-item ve-more-danger" data-ve-action="restore">
                            <i class="bi bi-clock-history"></i>
                            <span class="ve-more-text">
                                <span class="ve-more-label">还原原文</span>
                                <span class="ve-more-desc">取回第一次用可视化编辑之前的原始内容。</span>
                            </span>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <button type="button" class="ve-btn ve-btn-quiet" data-ve-action="close">
                <i class="bi bi-x-lg"></i> 关闭
            </button>
            <button type="button" class="ve-btn ve-btn-primary" data-ve-action="apply" title="直接保存到这条内容，走核心的修订与审计，发布状态不变">
                <i class="bi bi-check2"></i> 保存
            </button>
        </div>
    </header>

    <div class="ve-stage-body">
        <aside class="ve-rail" data-ve-palette>
            <div class="ve-rail-head">控件</div>
            <?php
            $grouped = [];
            foreach ($widgetGroups as $groupName => $keys) {
                foreach ($keys as $key) $grouped[$key] = $groupName;
            }
            $sections = [];
            foreach ($widgets as $widgetKey => $definition) {
                $sections[$grouped[$widgetKey] ?? '其他'][$widgetKey] = $definition;
            }
            ?>
            <?php foreach ($sections as $groupName => $groupWidgets): ?>
                <div class="ve-rail-group"><?= e((string)$groupName) ?></div>
                <div class="ve-rail-grid">
                    <?php foreach ($groupWidgets as $widgetKey => $definition): ?>
                        <?php
                        $needsCode = (string)($definition['needs_permission'] ?? '') !== '';
                        if ($needsCode && !$canUseCodeWidget) continue;
                        ?>
                        <button type="button" class="ve-chip" draggable="true"
                                data-ve-add="<?= e((string)$widgetKey) ?>"
                                title="<?= e($needsCode ? '需 visual_editor.code 权限' : (string)$definition['label']) ?>">
                            <i class="bi <?= e((string)$definition['icon']) ?>"></i>
                            <span><?= e((string)$definition['label']) ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>

            <div class="ve-rail-group">区块</div>
            <div class="ve-rail-list">
                <?php foreach ($presets as $presetKey => $preset): ?>
                    <button type="button" class="ve-preset" data-ve-add-section="<?= e((string)$presetKey) ?>">
                        <span class="ve-preset-bars" aria-hidden="true">
                            <?php foreach ($preset['columns'] as $percent): ?>
                                <span style="flex:0 0 <?= (int)$percent ?>%"></span>
                            <?php endforeach; ?>
                        </span>
                        <?= e((string)$preset['label']) ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </aside>

        <main class="ve-viewport" data-ve-viewport>
            <div class="ve-frame" data-ve-frame>
                <style data-ve-canvas-style></style>
                <div class="ve-canvas" data-ve-canvas></div>
            </div>
        </main>

        <aside class="ve-inspector" data-ve-inspector>
            <div class="ve-inspector-head">
                <span class="ve-inspector-target" data-ve-inspection-target>未选中</span>
                <span class="ve-inspector-tools">
                    <button type="button" class="ve-icon-btn" data-ve-action="move-up" title="上移"><i class="bi bi-arrow-up"></i></button>
                    <button type="button" class="ve-icon-btn" data-ve-action="move-down" title="下移"><i class="bi bi-arrow-down"></i></button>
                    <button type="button" class="ve-icon-btn" data-ve-action="duplicate" title="复制"><i class="bi bi-copy"></i></button>
                    <button type="button" class="ve-icon-btn ve-icon-btn-danger" data-ve-action="remove" title="删除"><i class="bi bi-trash"></i></button>
                </span>
            </div>
            <div class="ve-inspector-body" data-ve-inspector-body>
                <p class="ve-inspector-empty">在画布里点选区块、栏或控件，这里出现它的设置。</p>
            </div>
        </aside>
    </div>
</div>

<script type="application/json" id="ve-panel-config"><?= json_encode(
    $config,
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?></script>
<?php endif; ?>
