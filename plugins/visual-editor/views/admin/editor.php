<?php
/**
 * 可视化编辑器主界面。
 *
 * 三栏：左边控件库与结构树，中间画布，右边检查器（内容 + 样式）。
 * 画布是**服务端渲染**的：每次改动把树 POST 到 render 端点换回 HTML/CSS，
 * 因此画布里看到的就是前台会输出的东西，客户端不维护第二套渲染逻辑。
 */
$docId = (int)$doc['id'];
$editorConfig = [
    'documentId' => $docId,
    'lockVersion' => (int)$doc['lock_version'],
    'rootClass' => (string)$rootClass,
    'tree' => $tree,
    'widgets' => $widgets,
    'styleProperties' => $styleProperties,
    'styleLabels' => \VisualEditorSchema::styleLabels(),
    'fieldLabels' => \VisualEditorSchema::fieldLabels(),
    'breakpoints' => $breakpoints,
    'canUseCode' => !empty($canUseCode),
    'urls' => [
        'render' => admin_url('/visual-editor/render/' . $docId),
        'save' => admin_url('/visual-editor/save/' . $docId),
        'meta' => admin_url('/visual-editor/meta/' . $docId),
        'status' => admin_url('/visual-editor/status/' . $docId),
        'rollback' => admin_url('/visual-editor/rollback/' . $docId),
        'list' => admin_url('/visual-editor'),
    ],
    'csrf' => \App\Core\Csrf::token(),
];
?>
<?php $this->extend('admin/views/layouts/main'); ?>
<?php $this->section('title', '编辑：' . (string)$doc['title']); ?>
<?php $this->startSection('content'); ?>

<link rel="stylesheet" href="<?= e(plugin_url('visual-editor', 'assets/editor.css')) ?>">
<link rel="stylesheet" href="<?= e(plugin_url('visual-editor', 'assets/frontend.css')) ?>">

<div class="ve-editor" data-ve-editor>
    <div class="ve-toolbar">
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(admin_url('/visual-editor')) ?>">
            <i class="bi bi-arrow-left"></i> 返回
        </a>
        <span class="ve-toolbar-title"><?= e((string)$doc['title']) ?></span>
        <span class="badge <?= (string)$doc['status'] === 'published' ? 'bg-success-subtle text-success-emphasis' : 'bg-secondary-subtle text-secondary-emphasis' ?>"
              data-ve-status-badge><?= (string)$doc['status'] === 'published' ? '已发布' : '草稿' ?></span>

        <div class="ve-toolbar-spacer"></div>

        <div class="btn-group btn-group-sm" role="group" aria-label="断点">
            <button type="button" class="btn btn-outline-secondary active" data-ve-breakpoint="desktop" title="桌面"><i class="bi bi-display"></i></button>
            <button type="button" class="btn btn-outline-secondary" data-ve-breakpoint="tablet" title="平板（≤<?= (int)$breakpoints['tablet'] ?>px）"><i class="bi bi-tablet"></i></button>
            <button type="button" class="btn btn-outline-secondary" data-ve-breakpoint="mobile" title="手机（≤<?= (int)$breakpoints['mobile'] ?>px）"><i class="bi bi-phone"></i></button>
        </div>

        <div class="btn-group btn-group-sm ms-2" role="group" aria-label="历史">
            <button type="button" class="btn btn-outline-secondary" data-ve-undo disabled title="撤销"><i class="bi bi-arrow-counterclockwise"></i></button>
            <button type="button" class="btn btn-outline-secondary" data-ve-redo disabled title="重做"><i class="bi bi-arrow-clockwise"></i></button>
        </div>

        <a class="btn btn-sm btn-outline-secondary ms-2" target="_blank" rel="noopener"
           href="<?= e((string)$previewUrl) ?>"><i class="bi bi-eye"></i> 预览</a>

        <?php if (!empty($canPublish)): ?>
        <button type="button" class="btn btn-sm btn-outline-primary ms-2" data-ve-toggle-status
                data-ve-current="<?= e((string)$doc['status']) ?>">
            <?= (string)$doc['status'] === 'published' ? '撤回' : '发布' ?>
        </button>
        <?php endif; ?>

        <button type="button" class="btn btn-sm btn-primary ms-2" data-ve-save>
            <i class="bi bi-save"></i> 保存
        </button>
    </div>

    <?php if (!empty($coreConflict)): ?>
    <div class="alert alert-warning py-2 mb-0 rounded-0 small">
        <i class="bi bi-exclamation-triangle"></i>
        核心内容已占用 <code>/<?= e((string)$doc['slug']) ?></code>。前台会优先显示核心内容，请在「页面设置」里改 slug。
    </div>
    <?php endif; ?>

    <div class="ve-editor-body">
        <?php include __DIR__ . '/_editor-left.php'; ?>

        <main class="ve-canvas-pane">
            <div class="ve-canvas-frame" data-ve-frame>
                <style data-ve-style><?= $documentCss ?></style>
                <div data-ve-canvas><?= $canvasHtml ?></div>
            </div>
        </main>

        <?php include __DIR__ . '/_editor-right.php'; ?>
    </div>

    <div class="ve-statusbar">
        <span data-ve-message class="text-muted small">就绪</span>
        <span class="ve-toolbar-spacer"></span>
        <span class="text-muted small">版本 <span data-ve-lock><?= (int)$doc['lock_version'] ?></span></span>
    </div>
</div>

<script type="application/json" data-ve-config><?= json_encode(
    $editorConfig,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?></script>
<script src="<?= e(plugin_url('visual-editor', 'assets/editor.js')) ?>" defer></script>
<?php $this->endSection(); ?>
