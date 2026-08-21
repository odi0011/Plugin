<?php $this->extend('admin/views/layouts/main'); ?>
<?php $this->section('title', '维护模式'); ?>
<?php $this->startSection('content'); ?>

<h4 class="mb-3"><i class="bi bi-cone-striped"></i> 维护模式</h4>

<?php if ($enabled): ?>
    <div class="alert alert-warning d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-triangle"></i>
        <div>维护模式<strong>已开启</strong>：前台正在返回 503 维护页。后台、<code>/api</code>、放行 IP 以及拥有「配置维护模式」权限的登录用户不受影响。</div>
    </div>
<?php endif; ?>

<form method="post" action="<?= admin_url('/maintenance-mode/settings') ?>">
    <?= csrf_field() ?>
    <div class="card mb-3">
        <div class="card-body">
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" role="switch" id="mm-enabled" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
                <label class="form-check-label" for="mm-enabled">开启维护模式</label>
            </div>

            <div class="mb-3">
                <label class="form-label" for="mm-title">维护页标题</label>
                <input type="text" class="form-control" id="mm-title" name="title" maxlength="120" value="<?= e($title) ?>">
            </div>

            <div class="mb-3">
                <label class="form-label" for="mm-message">说明文字</label>
                <textarea class="form-control" id="mm-message" name="message" rows="3" maxlength="2000"><?= e($message) ?></textarea>
                <div class="form-text">支持换行，按原样展示（已做 HTML 转义）。</div>
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="mm-eta">预计恢复时间</label>
                    <input type="text" class="form-control" id="mm-eta" name="eta" maxlength="120" value="<?= e($eta) ?>" placeholder="例：今天 22:00 前">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="mm-retry">Retry-After（秒）</label>
                    <input type="number" class="form-control" id="mm-retry" name="retry_after" min="60" max="86400" value="<?= e($retryAfter) ?>">
                    <div class="form-text">告诉搜索引擎多久后再来，避免把维护当成永久下线。</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">放行 IP</div>
        <div class="card-body">
            <label class="form-label visually-hidden" for="mm-ips">放行 IP 列表</label>
            <textarea class="form-control font-monospace" id="mm-ips" name="allow_ips" rows="3" placeholder="每行一个，或用逗号 / 空格分隔"><?= e($allowIps) ?></textarea>
            <div class="form-text">
                你当前的出口 IP 是 <code><?= e($currentIp) ?></code>。
                非法 IP 会在保存时被拒绝并提示。即使这里留空，拥有「配置维护模式」权限的登录用户访问前台也不会被拦。
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> 保存</button>
</form>

<?php $this->endSection(); ?>
