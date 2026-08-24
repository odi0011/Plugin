<?php
/**
 * 可视化编辑器：内容表单内的编辑面板。
 *
 * 由 admin.content_editor.panel 钩子触发渲染（src/Panel.php）。外层元素带
 * data-content-mode-panel="<模式键>"，显隐由核心的共用切换条接管——
 * 本视图不写一行显隐逻辑。
 *
 * 初始状态（托管树 / 画布 HTML / 编译 CSS）全部由服务端算好嵌进来；
 * 之后的交互与提交编译在 assets/editor.js。
 */
if (!defined('CODE_SCHEMA_VERSION')) exit;

/** @var string $modeKey */
/** @var string $sourceType */
/** @var int $sourceId */
/** @var string $sourceKey */
/** @var string $fieldName */
/** @var bool $canUse */
/** @var bool $canUseCodeWidget */
/** @var string|null $managedTree */
/** @var bool $stale */
/** @var string $convertUrl */
/** @var string $csrfToken */
/** @var array $widgets */
/** @var array $styleProperties */
/** @var array $styleLabels */
/** @var array $fieldLabels */
/** @var array $breakpoints */
/** @var int $containerMax */
/** @var string $canvasHtml */
/** @var string $canvasCss */

$config = [
    'modeKey' => $modeKey,
    'sourceType' => $sourceType,
    'sourceId' => $sourceId,
    'sourceKey' => $sourceKey,
    'fieldName' => $fieldName,
    'canUse' => $canUse,
    'canUseCodeWidget' => $canUseCodeWidget,
    'convertUrl' => $convertUrl,
    'csrfToken' => $csrfToken,
    'widgets' => $widgets,
    'styleProperties' => $styleProperties,
    'styleLabels' => $styleLabels,
    'fieldLabels' => $fieldLabels,
    'breakpoints' => $breakpoints,
    'containerMax' => $containerMax,
];
?>
<div id="ve-panel" data-content-mode-panel="<?= e($modeKey) ?>" style="display:none;">
<?php if (!$canUse): ?>
    <div class="ve-panel-notice">
        <i class="bi bi-shield-lock"></i>
        可视化编辑需要管理员账号与 visual_editor.edit 权限：编译产物包含作用域样式，
        与代码模式一样只对能使用代码的人开放。
    </div>
<?php else: ?>
    <div class="ve-toolbar d-flex flex-wrap align-items-center gap-2 mb-2">
        <button type="button" class="btn btn-sm btn-outline-primary" data-ve-action="import">
            <i class="bi bi-download"></i> <span data-ve-import-label>导入当前内容</span>
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-ve-action="new-doc">
            <i class="bi bi-file-earmark-plus"></i> 空白文档
        </button>
        <span class="ve-status text-muted small" data-ve-status></span>
        <?php if ($stale): ?>
            <span class="badge text-bg-warning">内容已在可视化之外被修改过，建议重新导入</span>
        <?php endif; ?>
    </div>

    <div class="ve-workspace">
        <div class="ve-palette" data-ve-palette>
            <div class="ve-palette-title">控件</div>
            <?php foreach ($widgets as $widgetKey => $definition): ?>
                <?php
                $needsCode = (string)($definition['needs_permission'] ?? '') !== '';
                if ($needsCode && !$canUseCodeWidget) continue;
                ?>
                <button type="button" class="ve-palette-item" data-ve-add="<?= e((string)$widgetKey) ?>"
                        title="<?= e((string)($needsCode ? '自定义 HTML 控件（需 visual_editor.code 权限）' : '')) ?>">
                    <i class="bi <?= e((string)$definition['icon']) ?>"></i> <?= e((string)$definition['label']) ?>
                </button>
            <?php endforeach; ?>
            <div class="ve-palette-title mt-2">区块</div>
            <button type="button" class="ve-palette-item" data-ve-add-section>＋ 添加区块</button>
        </div>

        <div class="ve-canvas-wrap">
            <style data-ve-canvas-style><?= $canvasCss ?></style>
            <div class="ve-canvas" data-ve-canvas><?= $canvasHtml ?></div>
        </div>
    </div>

    <div class="ve-inspector" data-ve-inspector hidden>
        <div class="ve-inspector-head">
            <span data-ve-inspection-target>未选中</span>
            <span class="ms-auto d-flex gap-1">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-ve-action="move-up" title="上移"><i class="bi bi-arrow-up"></i></button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-ve-action="move-down" title="下移"><i class="bi bi-arrow-down"></i></button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-ve-action="duplicate" title="复制"><i class="bi bi-copy"></i></button>
                <button type="button" class="btn btn-sm btn-outline-danger" data-ve-action="remove" title="删除"><i class="bi bi-trash"></i></button>
            </span>
        </div>
        <div class="ve-inspector-body" data-ve-inspector-body></div>
    </div>
<?php endif; ?>
</div>

<?php if ($canUse): ?>
<script type="application/json" id="ve-panel-config"><?= json_encode(
    $config,
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?></script>
<script type="application/json" id="ve-initial-tree"><?= $managedTree ?? 'null' ?></script>
<?php endif; ?>
