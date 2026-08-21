<?php $this->extend('admin/views/layouts/main'); ?>
<?php $this->section('title', '站点地图推送'); ?>
<?php $this->startSection('content'); ?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi bi-send"></i> 站点地图推送</h4>
    <form method="post" action="<?= admin_url('/sitemap-ping/test') ?>">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-lightning-charge"></i> 立即推送（忽略节流）
        </button>
    </form>
</div>

<form method="post" action="<?= admin_url('/sitemap-ping/settings') ?>" class="mb-4">
    <?= csrf_field() ?>
    <div class="card">
        <div class="card-body">
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" role="switch" id="sp-enabled" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
                <label class="form-check-label" for="sp-enabled">内容发布后自动推送</label>
            </div>

            <div class="mb-3">
                <label class="form-label" for="sp-sitemap">sitemap 地址</label>
                <input type="url" class="form-control" id="sp-sitemap" name="sitemap_url" value="<?= e($sitemapUrl) ?>" placeholder="留空则自动使用 <?= e($resolvedSitemapUrl) ?>">
                <div class="form-text">
                    当前实际使用：<code><?= e($resolvedSitemapUrl !== '' ? $resolvedSitemapUrl : '（无法推断，请手动填写）') ?></code>
                    。必须是 <code>https://</code>。
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label" for="sp-endpoints">推送端点（每行一个，最多 5 个）</label>
                <textarea class="form-control font-monospace" id="sp-endpoints" name="endpoints" rows="4"><?= e($endpoints) ?></textarea>
                <div class="form-text">
                    用 <code>{SITEMAP}</code> 占位 sitemap 地址（会做 URL 编码）。只接受 <code>https://</code>，
                    且外发请求会经过公网 IP 校验，指向内网地址的端点会被拒绝。
                </div>
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="sp-throttle">节流（分钟）</label>
                    <input type="number" class="form-control" id="sp-throttle" name="throttle_minutes" min="0" max="1440" value="<?= e($throttleMinutes) ?>">
                    <div class="form-text">同一时间窗内只推一次；批量发布不会把搜索引擎刷爆。0 表示不节流。</div>
                </div>
                <div class="col-md-6">
                    <span class="form-label d-block">上次推送</span>
                    <p class="form-control-plaintext mb-0">
                        <?= $lastPingAt > 0 ? e(date('Y-m-d H:i:s', $lastPingAt)) : '尚未推送' ?>
                    </p>
                </div>
            </div>
        </div>
        <div class="card-footer bg-white">
            <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> 保存</button>
        </div>
    </div>
</form>

<div class="card">
    <div class="card-header">最近推送记录</div>
    <?php if ($logs === null): ?>
        <div class="card-body">
            <div class="alert alert-warning mb-0">
                <i class="bi bi-exclamation-triangle"></i>
                日志表尚未就绪。请在「插件列表」里重新启用本插件以执行其数据库迁移。
            </div>
        </div>
    <?php elseif ($logs === []): ?>
        <div class="card-body text-center text-muted py-5">
            <i class="bi bi-inbox display-6"></i>
            <p class="mt-3 mb-0">还没有推送记录。发布一篇内容，或点上方「立即推送」试一次。</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead>
                    <tr><th>时间</th><th>触发</th><th>结果</th><th>端点 / 说明</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td class="text-nowrap"><?= e((string)($log['created_at'] ?? '')) ?></td>
                            <td class="text-nowrap">
                                <?= e((string)($log['trigger_type'] ?? '')) ?>
                                <?php if ((int)($log['trigger_id'] ?? 0) > 0): ?>
                                    #<?= (int)$log['trigger_id'] ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($log['ok'])): ?>
                                    <span class="badge bg-success">成功</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">失败</span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-break">
                                <div class="text-muted"><?= e((string)($log['endpoint'] ?? '')) ?></div>
                                <?php if ((string)($log['message'] ?? '') !== ''): ?>
                                    <div><?= e((string)$log['message']) ?></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<p class="text-muted small mt-3 mb-0">日志只保留最近 200 条，按 5% 采样清理。</p>

<?php $this->endSection(); ?>
