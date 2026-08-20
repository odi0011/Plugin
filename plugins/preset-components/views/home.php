<?php $this->extend('admin/views/layouts/main'); ?>
<?php $this->section('title', '预设组件'); ?>
<?php $this->startSection('content'); ?>

<?php
$tagPrefix = function_exists('brand_tag_prefix') ? brand_tag_prefix() : (string)config('brand.tag_prefix', config('site_name', ''));
$recent = array_slice($catalog, 0, 6);
$categoryCounts = [];
foreach ($catalog as $item) {
    $key = $item['category'] ?? '';
    $categoryCounts[$key] = ($categoryCounts[$key] ?? 0) + 1;
}
?>

<div class="preset-components-page">
    <div class="preset-toolbar">
        <div>
            <h1>预设组件</h1>
            <p>激活后可将常用组件一键同步为系统组件，再插入到页面、文章、产品或系统模板。</p>
        </div>
        <div class="preset-toolbar__actions">
            <a class="btn btn-outline-secondary" href="<?= admin_url('/plugins') ?>"><i class="bi bi-plug"></i> 插件列表</a>
            <a class="btn btn-primary" href="<?= admin_url('/preset-components/components') ?>"><i class="bi bi-grid-3x3-gap"></i> 全部组件</a>
        </div>
    </div>

    <div class="preset-tabs">
        <a class="active" href="<?= admin_url('/preset-components') ?>"><i class="bi bi-house"></i> 主页</a>
        <a href="<?= admin_url('/preset-components/components') ?>"><i class="bi bi-layers"></i> 全部组件</a>
    </div>

    <div class="preset-stat-grid">
        <div class="preset-stat"><span>组件总数</span><strong><?= (int)$stats['total'] ?></strong></div>
        <div class="preset-stat"><span>已启用</span><strong><?= (int)$stats['enabled'] ?></strong></div>
        <div class="preset-stat"><span>已禁用</span><strong><?= (int)$stats['disabled'] ?></strong></div>
        <div class="preset-stat"><span>未使用</span><strong><?= (int)$stats['not_used'] ?></strong></div>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="preset-panel">
                <div class="preset-panel__header">
                    <div>
                        <h2>推荐组件</h2>
                        <p>从这里进入详情页，可以查看预览、源码、参数，并直接写入目标内容。</p>
                    </div>
                    <a href="<?= admin_url('/preset-components/components') ?>">查看全部</a>
                </div>

                <div class="preset-card-grid">
                    <?php foreach ($recent as $item): ?>
                        <?php $state = preset_components_state($installed[$item['slug']] ?? null); ?>
                        <article class="preset-component-card">
                            <div class="preset-component-card__top">
                                <span class="preset-tag"><?= e($item['tag']) ?></span>
                                <span class="badge bg-<?= e($state['class']) ?>"><?= e($state['label']) ?></span>
                            </div>
                            <h3><?= e($item['title']) ?></h3>
                            <p><?= e($item['summary']) ?></p>
                            <div class="preset-component-card__meta">
                                <span><?= e($categories[$item['category']] ?? $item['category']) ?></span>
                                <span><?= count($item['params'] ?? []) ?> 个参数</span>
                            </div>
                            <a class="btn btn-sm btn-outline-primary" href="<?= admin_url('/preset-components/components/' . rawurlencode($item['slug'])) ?>">
                                <i class="bi bi-eye"></i> 查看
                            </a>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="preset-panel">
                <div class="preset-panel__header">
                    <div>
                        <h2>分类</h2>
                        <p>按用途筛选组件。</p>
                    </div>
                </div>

                <div class="preset-category-list">
                    <?php foreach ($categories as $key => $label): ?>
                        <a href="<?= admin_url('/preset-components/components?category=' . rawurlencode($key)) ?>">
                            <span><?= e($label) ?></span>
                            <strong><?= (int)($categoryCounts[$key] ?? 0) ?></strong>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="preset-panel mt-3">
                <div class="preset-panel__header">
                    <div>
                        <h2>使用方式</h2>
                        <p>启用后在内容里写入组件标签即可渲染。</p>
                    </div>
                </div>
                <pre class="preset-code-line"><code>&lt;<?= e($tagPrefix) ?>-preset-slider id="home-slider" /&gt;</code></pre>
                <div class="preset-help-list">
                    <span><i class="bi bi-check2-circle"></i> 支持属性传参</span>
                    <span><i class="bi bi-check2-circle"></i> 支持系统组件二次编辑</span>
                    <span><i class="bi bi-check2-circle"></i> 支持写入页面、文章、产品、模板</span>
                </div>
            </div>
        </div>
    </div>
</div>

<?php $this->endSection(); ?>
