<?php $this->extend('admin/views/layouts/main'); ?>
<?php $this->section('title', '全部组件 - 预设组件'); ?>
<?php $this->startSection('content'); ?>

<div class="preset-components-page">
    <div class="preset-toolbar">
        <div>
            <h1>全部组件</h1>
            <p>搜索、筛选、启用或禁用预设组件。启用后会同步到系统组件管理。</p>
        </div>
        <div class="preset-toolbar__actions">
            <a class="btn btn-outline-secondary" href="<?= admin_url('/preset-components') ?>"><i class="bi bi-house"></i> 主页</a>
        </div>
    </div>

    <div class="preset-tabs">
        <a href="<?= admin_url('/preset-components') ?>"><i class="bi bi-house"></i> 主页</a>
        <a class="active" href="<?= admin_url('/preset-components/components') ?>"><i class="bi bi-layers"></i> 全部组件</a>
    </div>

    <form class="preset-filter" method="get" action="<?= admin_url('/preset-components/components') ?>">
        <div>
            <label>搜索</label>
            <input class="form-control" type="search" name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="标题、标签或说明">
        </div>
        <div>
            <label>分类</label>
            <select class="form-select" name="category">
                <option value="">全部分类</option>
                <?php foreach ($categories as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= ($filters['category'] ?? '') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>状态</label>
            <select class="form-select" name="status">
                <option value="">全部状态</option>
                <option value="enabled" <?= ($filters['status'] ?? '') === 'enabled' ? 'selected' : '' ?>>已启用</option>
                <option value="disabled" <?= ($filters['status'] ?? '') === 'disabled' ? 'selected' : '' ?>>已禁用</option>
                <option value="not_used" <?= ($filters['status'] ?? '') === 'not_used' ? 'selected' : '' ?>>未使用</option>
            </select>
        </div>
        <div>
            <label>应用位置</label>
            <select class="form-select" name="target">
                <option value="">全部位置</option>
                <?php foreach ($targets as $key => $target): ?>
                    <option value="<?= e($key) ?>" <?= ($filters['target'] ?? '') === $key ? 'selected' : '' ?>><?= e($target['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i> 筛选</button>
        <a class="btn btn-outline-secondary" href="<?= admin_url('/preset-components/components') ?>">重置</a>
    </form>

    <div class="preset-list-summary">
        <span>当前显示 <?= count($catalog) ?> / <?= (int)$allCount ?> 个组件</span>
        <span>已启用 <?= (int)$stats['enabled'] ?> 个，未使用 <?= (int)$stats['not_used'] ?> 个</span>
    </div>

    <?php if (!$catalog): ?>
        <div class="preset-empty">
            <i class="bi bi-inboxes"></i>
            <h2>没有匹配的组件</h2>
            <p>换个关键词或筛选条件再试试。</p>
        </div>
    <?php else: ?>
        <div class="preset-table-wrap">
            <table class="table preset-table align-middle">
                <thead>
                <tr>
                    <th>组件</th>
                    <th>分类</th>
                    <th>可应用</th>
                    <th>参数</th>
                    <th>状态</th>
                    <th class="text-end">操作</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($catalog as $item): ?>
                    <?php $state = preset_components_state($installed[$item['slug']] ?? null); ?>
                    <tr>
                        <td>
                            <div class="preset-table__title">
                                <strong><?= e($item['title']) ?></strong>
                                <code><?= e($item['tag']) ?></code>
                                <p><?= e($item['summary']) ?></p>
                            </div>
                        </td>
                        <td><?= e($categories[$item['category']] ?? $item['category']) ?></td>
                        <td>
                            <div class="preset-chip-row">
                                <?php foreach ($item['targets'] as $target): ?>
                                    <span><?= e($targets[$target]['label'] ?? $target) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td><?= count($item['params'] ?? []) ?></td>
                        <td><span class="badge bg-<?= e($state['class']) ?>"><?= e($state['label']) ?></span></td>
                        <td class="text-end">
                            <div class="preset-actions">
                                <a class="btn btn-sm btn-outline-primary" href="<?= admin_url('/preset-components/components/' . rawurlencode($item['slug'])) ?>">
                                    <i class="bi bi-eye"></i> 查看
                                </a>
                                <?php if ($state['key'] === 'enabled'): ?>
                                    <form method="post" action="<?= admin_url('/preset-components/components/' . rawurlencode($item['slug']) . '/disable') ?>">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-outline-warning" type="submit"><i class="bi bi-pause-circle"></i> 禁用</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" action="<?= admin_url('/preset-components/components/' . rawurlencode($item['slug']) . '/enable') ?>">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-success" type="submit"><i class="bi bi-check2-circle"></i> 使用</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php $this->endSection(); ?>
