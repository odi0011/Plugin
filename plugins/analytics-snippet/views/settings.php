<?php $this->extend('admin/views/layouts/main'); ?>
<?php $this->section('title', '站点统计 - Analytics Snippet'); ?>
<?php $this->startSection('content'); ?>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0"><i class="bi bi-graph-up"></i> Google Analytics 配置</h5>
    </div>
    <div class="card-body">
        <form method="post" action="<?= admin_url('/analytics-snippet/settings') ?>">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label">Tracking ID</label>
                <input type="text" name="ga_id" class="form-control" value="<?= e($gaId) ?>" placeholder="G-XXXXXXXXXX 或 UA-XXXXX-Y" pattern="[A-Za-z0-9_\-]*">
                <div class="form-text">留空则不注入。GA4 用 <code>G-XXX</code>，旧版 UA 用 <code>UA-XXX-Y</code>。</div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check2"></i> 保存</button>
            <a href="<?= admin_url('/plugins') ?>" class="btn btn-link">← 返回插件列表</a>
        </form>

        <?php if ($gaId !== ''): ?>
            <hr>
            <div class="alert alert-success small mb-0">
                <i class="bi bi-check-circle"></i> 已配置。所有前台页面会自动注入 GA 跟踪代码（ID: <code><?= e($gaId) ?></code>）。
            </div>
        <?php endif; ?>
    </div>
</div>

<?php $this->endSection(); ?>
