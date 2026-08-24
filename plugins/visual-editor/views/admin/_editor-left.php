<?php
/**
 * 编辑器左栏：控件库 + 结构树。
 *
 * 控件库直接从 VisualEditorSchema::widgets() 渲染，因此新增一个控件类型
 * 只需要动那张表，界面自动多一项——不存在「后端支持了但界面里找不到」。
 */
?>
<aside class="ve-side-pane ve-side-left">
    <div class="ve-pane-tabs" role="tablist">
        <button type="button" class="ve-pane-tab active" data-ve-left-tab="widgets" role="tab">控件</button>
        <button type="button" class="ve-pane-tab" data-ve-left-tab="outline" role="tab">结构</button>
        <button type="button" class="ve-pane-tab" data-ve-left-tab="page" role="tab">页面</button>
    </div>

    <div class="ve-pane-body" data-ve-left-panel="widgets">
        <p class="ve-hint">先在画布上选中一栏，再点控件加入；也可以直接拖到栏里。</p>
        <div class="ve-widget-grid">
            <?php foreach ($widgets as $type => $definition): ?>
                <?php if ((string)$definition['needs_permission'] !== '' && empty($canUseCode)) continue; ?>
                <button type="button" class="ve-widget-card" draggable="true"
                        data-ve-add-widget="<?= e((string)$type) ?>">
                    <i class="bi <?= e((string)$definition['icon']) ?>"></i>
                    <span><?= e((string)$definition['label']) ?></span>
                </button>
            <?php endforeach; ?>
        </div>

        <hr>
        <div class="ve-stack">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-ve-add-section="1">
                <i class="bi bi-plus-square"></i> 加一栏区块
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-ve-add-section="2">
                <i class="bi bi-columns"></i> 加两栏区块
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-ve-add-section="3">
                <i class="bi bi-columns-gap"></i> 加三栏区块
            </button>
        </div>
    </div>

    <div class="ve-pane-body d-none" data-ve-left-panel="outline">
        <div data-ve-outline class="ve-outline"></div>
    </div>

    <div class="ve-pane-body d-none" data-ve-left-panel="page">
        <div class="mb-3">
            <label class="form-label" for="veMetaTitle">标题</label>
            <input class="form-control form-control-sm" id="veMetaTitle" data-ve-meta="title"
                   maxlength="200" value="<?= e((string)$doc['title']) ?>">
        </div>
        <div class="mb-3">
            <label class="form-label" for="veMetaSlug">slug</label>
            <input class="form-control form-control-sm" id="veMetaSlug" data-ve-meta="slug"
                   maxlength="150" value="<?= e((string)$doc['slug']) ?>">
            <div class="form-text">前台地址：<a href="<?= e((string)$publicUrl) ?>" target="_blank" rel="noopener" data-ve-public-url><?= e((string)$publicUrl) ?></a></div>
        </div>
        <div class="mb-3">
            <label class="form-label" for="veMetaSeoTitle">SEO 标题</label>
            <input class="form-control form-control-sm" id="veMetaSeoTitle" data-ve-meta="seo_title"
                   maxlength="255" value="<?= e((string)$doc['seo_title']) ?>">
            <div class="form-text">留空则用上面的标题。</div>
        </div>
        <div class="mb-3">
            <label class="form-label" for="veMetaSeoDesc">SEO 描述</label>
            <textarea class="form-control form-control-sm" id="veMetaSeoDesc" data-ve-meta="seo_description"
                      rows="3" maxlength="500"><?= e((string)$doc['seo_description']) ?></textarea>
        </div>
        <button type="button" class="btn btn-sm btn-outline-primary" data-ve-save-meta>保存页面设置</button>

        <hr>
        <h6>修订</h6>
        <?php if (empty($revisions)): ?>
            <p class="ve-hint mb-0">还没有修订。每次保存会自动写一条。</p>
        <?php else: ?>
        <ul class="ve-revision-list">
            <?php foreach ($revisions as $revision): ?>
            <li>
                <div>
                    <div class="small"><?= e((string)$revision['created_at']) ?></div>
                    <div class="text-muted small"><?= e((string)$revision['note']) ?></div>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary"
                        data-ve-rollback="<?= (int)$revision['id'] ?>">回滚</button>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</aside>
