<?php $this->extend('admin/views/layouts/main'); ?>
<?php $this->section('title', '可视化编辑器'); ?>
<?php $this->startSection('content'); ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-1"><i class="bi bi-columns-gap"></i> 可视化编辑器</h4>
        <div class="text-muted small">用区块、栏、控件搭页面；样式按桌面 / 平板 / 手机分别设置。</div>
    </div>
    <?php if (!empty($canEdit)): ?>
    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#veNewModal">
        <i class="bi bi-plus-lg"></i> 新建页面
    </button>
    <?php endif; ?>
</div>

<form class="card card-body mb-3 py-2" method="get" action="<?= e(admin_url('/visual-editor')) ?>">
    <div class="row g-2 align-items-center">
        <div class="col-auto">
            <select class="form-select form-select-sm" name="status" onchange="this.form.submit()">
                <option value=""<?= $status === '' ? ' selected' : '' ?>>全部状态</option>
                <option value="draft"<?= $status === 'draft' ? ' selected' : '' ?>>草稿</option>
                <option value="published"<?= $status === 'published' ? ' selected' : '' ?>>已发布</option>
            </select>
        </div>
        <div class="col-sm-4">
            <input type="search" class="form-control form-control-sm" name="q" value="<?= e($keyword) ?>" placeholder="搜索标题或 slug">
        </div>
        <div class="col-auto">
            <button class="btn btn-outline-secondary btn-sm" type="submit"><i class="bi bi-search"></i> 查询</button>
        </div>
        <div class="col text-end text-muted small">共 <?= (int)$total ?> 个文档</div>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width:70px;">#</th>
                    <th>标题</th>
                    <th style="width:220px;">前台路径</th>
                    <th style="width:90px;">状态</th>
                    <th style="width:160px;">更新时间</th>
                    <th class="text-end" style="width:190px;">操作</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="6" class="text-center text-muted py-5">还没有可视化页面，点右上角「新建页面」开始。</td></tr>
            <?php else: foreach ($rows as $row): ?>
                <tr>
                    <td class="text-muted"><?= (int)$row['id'] ?></td>
                    <td class="fw-medium"><?= e((string)$row['title']) ?></td>
                    <td><code><?= e('/' . (string)$row['slug']) ?></code></td>
                    <td>
                        <?php if ((string)$row['status'] === 'published'): ?>
                            <span class="badge bg-success-subtle text-success-emphasis">已发布</span>
                        <?php else: ?>
                            <span class="badge bg-secondary-subtle text-secondary-emphasis">草稿</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted small"><?= e((string)$row['updated_at']) ?></td>
                    <td class="text-end">
                        <?php if ((string)$row['status'] === 'published'): ?>
                        <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener"
                           href="<?= e(\VisualEditorRouting::publicUrl((string)$row['slug'])) ?>"><i class="bi bi-box-arrow-up-right"></i></a>
                        <?php else: ?>
                        <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener"
                           href="<?= e(\VisualEditorRouting::previewUrl((int)$row['id'])) ?>"><i class="bi bi-eye"></i></a>
                        <?php endif; ?>
                        <?php if (!empty($canEdit)): ?>
                        <a class="btn btn-sm btn-primary" href="<?= e(admin_url('/visual-editor/edit/' . (int)$row['id'])) ?>">
                            <i class="bi bi-pencil-square"></i> 编辑
                        </a>
                        <form method="post" action="<?= e(admin_url('/visual-editor/delete/' . (int)$row['id'])) ?>"
                              class="d-inline" data-ve-confirm="删除后不可恢复，确定删除「<?= e((string)$row['title']) ?>」？">
                            <?= csrf_field() ?>
                            <input type="hidden" name="lock_version" value="<?= (int)$row['lock_version'] ?>">
                            <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php $pages = (int)ceil(max(1, (int)$total) / max(1, (int)$perPage)); ?>
<?php if ($pages > 1): ?>
<nav class="mt-3"><ul class="pagination pagination-sm mb-0">
    <?php for ($p = 1; $p <= $pages; $p++): ?>
    <li class="page-item<?= $p === (int)$page ? ' active' : '' ?>">
        <a class="page-link" href="<?= e(admin_url('/visual-editor?page=' . $p . '&status=' . rawurlencode($status) . '&q=' . rawurlencode($keyword))) ?>"><?= $p ?></a>
    </li>
    <?php endfor; ?>
</ul></nav>
<?php endif; ?>

<?php if (!empty($canEdit)): ?>
<div class="modal fade" id="veNewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="<?= e(admin_url('/visual-editor/create')) ?>">
            <?= csrf_field() ?>
            <div class="modal-header">
                <h5 class="modal-title">新建可视化页面</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="关闭"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label" for="veNewTitle">标题</label>
                    <input class="form-control" id="veNewTitle" name="title" maxlength="200" required autocomplete="off">
                </div>
                <div class="mb-2">
                    <label class="form-label" for="veNewSlug">slug（可留空，按标题生成）</label>
                    <input class="form-control" id="veNewSlug" name="slug" maxlength="150" autocomplete="off" placeholder="about-us">
                    <div class="form-text">前台地址就是 <code>/slug</code>。与核心页面同名时核心页面优先，发布会被拦下并提示改名。</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">取消</button>
                <button type="submit" class="btn btn-primary">创建并开始编辑</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
// 删除是不可逆的，提交前要一次确认。用事件委托而不是内联 onclick：
// 内联处理器会被站点的 CSP 拦掉。
document.addEventListener('submit', function (event) {
    var form = event.target.closest('form[data-ve-confirm]');
    if (!form) return;
    if (!window.confirm(form.getAttribute('data-ve-confirm'))) event.preventDefault();
});
</script>
<?php $this->endSection(); ?>
