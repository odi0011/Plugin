<?php
$this->extend('admin/views/layouts/main');
$this->section('title', 'IndexNow 提交');
$this->startSection('content');
$payload = is_array($status['data'] ?? null) ? $status['data'] : [];
$counts = is_array($payload['counts'] ?? null) ? $payload['counts'] : [];
?>
<div class="d-flex align-items-center justify-content-between gap-3 mb-3 flex-wrap">
    <div><h4 class="mb-1">IndexNow 提交</h4><div class="text-muted small">Sitemap 变化会由后台 worker 异步提交，访客请求不执行外部网络调用。</div></div>
    <form method="post" action="<?= admin_url('/sitemap-ping/submit') ?>"><?= csrf_field() ?><button class="btn btn-primary" type="submit"><i class="bi bi-send"></i> 立即加入队列</button></form>
</div>
<section class="border rounded-2 p-3 mb-3" aria-label="IndexNow 状态">
    <div class="row g-3">
        <div class="col-sm-4"><div class="text-muted small">功能开关</div><strong><?= !empty($payload['enabled']) ? '已启用' : '未启用' ?></strong></div>
        <div class="col-sm-4"><div class="text-muted small">密钥</div><strong><?= !empty($payload['key_configured']) ? '已配置' : '待修复' ?></strong></div>
        <div class="col-sm-4"><div class="text-muted small">公开 keyLocation</div><a href="<?= e((string)($payload['key_location'] ?? '')) ?>" target="_blank" rel="noopener noreferrer"><?= e((string)($payload['key_location'] ?? '-')) ?></a></div>
    </div>
</section>
<section class="border rounded-2 p-3" aria-label="提交队列计数">
    <div class="row g-3">
        <?php foreach (['queued' => '排队中', 'running' => '执行中', 'failed' => '失败待重试', 'succeeded' => '已完成'] as $key => $label): ?>
        <div class="col-6 col-md-3"><div class="text-muted small"><?= e($label) ?></div><strong><?= (int)($counts[$key] ?? 0) ?></strong></div>
        <?php endforeach; ?>
    </div>
</section>
<?php $this->endSection(); ?>
