<?php $this->extend('admin/views/layouts/main'); ?>
<?php $this->section('title', '阅读时长与字数'); ?>
<?php $this->startSection('content'); ?>

<h4 class="mb-3"><i class="bi bi-hourglass-split"></i> 阅读时长与字数</h4>

<form method="post" action="<?= admin_url('/reading-time/settings') ?>" class="mb-4">
    <?= csrf_field() ?>
    <div class="card">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="rt-wpm">每分钟字数</label>
                    <input type="number" class="form-control" id="rt-wpm" name="wpm" min="60" max="1200" value="<?= e($wpm) ?>">
                    <div class="form-text">中文默认 300，英文读者可调到 200 左右。</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="rt-min">起始字数</label>
                    <input type="number" class="form-control" id="rt-min" name="min_words" min="0" max="100000" value="<?= e($minWords) ?>">
                    <div class="form-text">低于该字数的内容不显示阅读时长。</div>
                </div>
                <div class="col-md-4">
                    <span class="form-label d-block">显示位置</span>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="rt-article" name="show_article" value="1" <?= $showArticle ? 'checked' : '' ?>>
                        <label class="form-check-label" for="rt-article">文章详情</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="rt-page" name="show_page" value="1" <?= $showPage ? 'checked' : '' ?>>
                        <label class="form-check-label" for="rt-page">页面详情</label>
                    </div>
                </div>
            </div>
        </div>
        <div class="card-footer bg-white">
            <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> 保存</button>
        </div>
    </div>
</form>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>字数报表（最短的 50 条）</span>
        <span class="text-muted small">文章与页面各取最近 200 条计算</span>
    </div>
    <?php if ($report === []): ?>
        <div class="card-body text-center text-muted py-5">
            <i class="bi bi-inbox display-6"></i>
            <p class="mt-3 mb-0">还没有可统计的内容。</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th>类型</th><th>标题</th><th>状态</th>
                        <th class="text-end">字数</th><th class="text-end">预计阅读</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report as $row): ?>
                        <tr>
                            <td><span class="badge bg-secondary"><?= $row['type'] === 'article' ? '文章' : '页面' ?></span></td>
                            <td class="text-truncate" style="max-width: 380px;" title="<?= e($row['title']) ?>"><?= e($row['title']) ?></td>
                            <td>
                                <span class="badge <?= $row['status'] === 'published' ? 'bg-success' : 'bg-secondary' ?>">
                                    <?= e($row['status']) ?>
                                </span>
                            </td>
                            <td class="text-end"><?= number_format((int)$row['words']) ?></td>
                            <td class="text-end"><?= (int)$row['minutes'] ?> 分钟</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<p class="text-muted small mt-3 mb-0">
    计数规则：先剥掉 <code>&lt;script&gt;</code> / <code>&lt;style&gt;</code> 再去标签，
    中日韩字符按字计、西文按词计，两者相加。
</p>

<?php $this->endSection(); ?>
