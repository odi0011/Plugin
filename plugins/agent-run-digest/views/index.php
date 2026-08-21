<?php $this->extend('admin/views/layouts/main'); ?>
<?php $this->section('title', 'Agent 运行摘要'); ?>
<?php $this->startSection('content'); ?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi bi-activity"></i> Agent 运行摘要</h4>
    <form method="get" action="<?= admin_url('/agent-run-digest') ?>" class="d-flex gap-2">
        <select name="days" class="form-select form-select-sm" style="width: auto;">
            <?php foreach ([7, 14, 30, 90] as $option): ?>
                <option value="<?= $option ?>" <?= $days === $option ? 'selected' : '' ?>>最近 <?= $option ?> 天</option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-sm btn-outline-primary">查看</button>
    </form>
</div>

<?php if ($summary === null): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle"></i>
        运行摘要表尚未就绪。请在「插件列表」里重新启用本插件以执行其数据库迁移。
    </div>
<?php else: ?>
    <div class="row g-3 mb-3">
        <?php
        $cards = [
            ['label' => '总运行次数', 'value' => $summary['total'], 'icon' => 'bi-play-circle'],
            ['label' => '成功', 'value' => $summary['completed'], 'icon' => 'bi-check-circle'],
            ['label' => '失败', 'value' => $summary['failed'], 'icon' => 'bi-x-circle'],
            ['label' => '成功率', 'value' => $summary['success_rate_percent'] . '%', 'icon' => 'bi-percent'],
            ['label' => '涉及会话', 'value' => $summary['sessions'], 'icon' => 'bi-chat-dots'],
        ];
        ?>
        <?php foreach ($cards as $card): ?>
            <div class="col-6 col-lg">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="text-muted small"><i class="bi <?= e($card['icon']) ?>"></i> <?= e($card['label']) ?></div>
                        <div class="fs-4 fw-semibold"><?= e((string)$card['value']) ?></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <div class="card-header">按天分布</div>
        <?php if (($summary['by_day'] ?? []) === []): ?>
            <div class="card-body text-center text-muted py-5">
                <i class="bi bi-inbox display-6"></i>
                <p class="mt-3 mb-0">该区间内还没有运行记录。插件启用后，Agent 每次运行结束都会记一条。</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead>
                        <tr><th>日期</th><th class="text-end">成功</th><th class="text-end">失败</th><th class="text-end">合计</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($summary['by_day'] as $row): ?>
                            <?php $rowTotal = (int)$row['completed'] + (int)$row['failed']; ?>
                            <tr>
                                <td><?= e((string)$row['day']) ?></td>
                                <td class="text-end"><?= (int)$row['completed'] ?></td>
                                <td class="text-end"><?= (int)$row['failed'] ?></td>
                                <td class="text-end fw-semibold"><?= $rowTotal ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <p class="text-muted small mt-3 mb-0">
        记录保留 90 天，超期数据在事件写入时按 1% 采样清理。同一次运行的同类事件只会记一条
        （<code>UNIQUE(run_id, event_type)</code>），事件重复投递不会重复计数。
    </p>
<?php endif; ?>

<?php $this->endSection(); ?>
