<?php $this->extend('admin/views/layouts/main'); ?>
<?php $this->section('title', '重定向规则'); ?>
<?php $this->startSection('content'); ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-arrow-return-right"></i> 重定向规则</h4>
    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#newRuleModal">
        <i class="bi bi-plus-lg"></i> 新建规则
    </button>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th style="width:60px;">#</th>
                    <th>源路径</th>
                    <th>目标 URL</th>
                    <th style="width:80px;">状态码</th>
                    <th style="width:80px;">启用</th>
                    <th style="width:80px;">命中</th>
                    <th class="text-end" style="width:160px;">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rules)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-5">还没有规则，点击「新建规则」开始添加</td></tr>
                <?php else: ?>
                    <?php foreach ($rules as $r): ?>
                        <tr>
                            <td><?= e($r['id']) ?></td>
                            <td><code><?= e($r['from_url']) ?></code></td>
                            <td><code><?= e($r['to_url']) ?></code></td>
                            <td><span class="badge bg-<?= $r['status_code'] == 301 ? 'success' : 'secondary' ?>"><?= e($r['status_code']) ?></span></td>
                            <td>
                                <?php if ((int)$r['enabled'] === 1): ?>
                                    <span class="badge bg-success">是</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">否</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($r['hits']) ?></td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-outline-primary edit-rule" data-rule='<?= e(json_encode($r)) ?>'><i class="bi bi-pencil"></i></button>
                                <form method="post" action="<?= admin_url('/redirect-rules/delete/' . $r['id']) ?>" class="d-inline">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="确认删除规则 <?= e($r['from_url']) ?>？"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- 新建 / 编辑 Modal -->
<div class="modal fade" id="newRuleModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" action="<?= admin_url('/redirect-rules/save') ?>" class="modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="id" id="ruleId" value="0">
            <div class="modal-header">
                <h5 class="modal-title" id="ruleModalTitle">新建重定向规则</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">源路径 <span class="text-danger">*</span></label>
                    <input type="text" name="from_url" id="fromUrl" class="form-control" required placeholder="/old-page">
                    <div class="form-text">前台访问的精确路径（含开头 /），例如 <code>/old-product</code></div>
                </div>
                <div class="mb-3">
                    <label class="form-label">目标 URL <span class="text-danger">*</span></label>
                    <input type="text" name="to_url" id="toUrl" class="form-control" required placeholder="/new-page 或 https://...">
                </div>
                <div class="mb-3">
                    <label class="form-label">状态码</label>
                    <select name="status_code" id="statusCode" class="form-select">
                        <option value="301">301 永久重定向（推荐 SEO 友好）</option>
                        <option value="302" selected>302 临时重定向（默认）</option>
                        <option value="307">307 临时（保留请求方法）</option>
                        <option value="308">308 永久（保留请求方法）</option>
                    </select>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="enabled" id="enabled" value="1" checked>
                    <label class="form-check-label" for="enabled">启用</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary">保存</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    document.querySelectorAll('.edit-rule').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var r = JSON.parse(btn.dataset.rule);
            document.getElementById('ruleId').value = r.id;
            document.getElementById('fromUrl').value = r.from_url;
            document.getElementById('toUrl').value = r.to_url;
            document.getElementById('statusCode').value = r.status_code;
            document.getElementById('enabled').checked = String(r.enabled) === '1';
            document.getElementById('ruleModalTitle').textContent = '编辑重定向规则';
            bootstrap.Modal.getOrCreateInstance(document.getElementById('newRuleModal')).show();
        });
    });
    // 新建按钮：重置表单
    document.querySelector('[data-bs-target="#newRuleModal"]').addEventListener('click', function () {
        document.getElementById('ruleId').value = '0';
        document.getElementById('fromUrl').value = '';
        document.getElementById('toUrl').value = '';
        document.getElementById('statusCode').value = '302';
        document.getElementById('enabled').checked = true;
        document.getElementById('ruleModalTitle').textContent = '新建重定向规则';
    });
})();
</script>

<?php $this->endSection(); ?>
