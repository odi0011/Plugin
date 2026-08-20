<?php $this->extend('admin/views/layouts/main'); ?>
<?php $this->section('title', $preset['title'] . ' - 预设组件'); ?>
<?php $this->startSection('content'); ?>

<div class="preset-components-page">
    <div class="preset-toolbar">
        <div>
            <div class="preset-breadcrumb">
                <a href="<?= admin_url('/preset-components') ?>">预设组件</a>
                <i class="bi bi-chevron-right"></i>
                <a href="<?= admin_url('/preset-components/components') ?>">全部组件</a>
                <i class="bi bi-chevron-right"></i>
                <span><?= e($preset['title']) ?></span>
            </div>
            <h1><?= e($preset['title']) ?></h1>
            <p><?= e($preset['summary']) ?></p>
        </div>
        <div class="preset-toolbar__actions">
            <span class="badge bg-<?= e($state['class']) ?>"><?= e($state['label']) ?></span>
            <?php if ($state['key'] === 'enabled'): ?>
                <form method="post" action="<?= admin_url('/preset-components/components/' . rawurlencode($preset['slug']) . '/disable') ?>">
                    <?= csrf_field() ?>
                    <button class="btn btn-outline-warning" type="submit"><i class="bi bi-pause-circle"></i> 禁用</button>
                </form>
            <?php else: ?>
                <form method="post" action="<?= admin_url('/preset-components/components/' . rawurlencode($preset['slug']) . '/enable') ?>">
                    <?= csrf_field() ?>
                    <button class="btn btn-success" type="submit"><i class="bi bi-check2-circle"></i> 使用</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="preset-tabs">
        <a href="<?= admin_url('/preset-components') ?>"><i class="bi bi-house"></i> 主页</a>
        <a class="active" href="<?= admin_url('/preset-components/components') ?>"><i class="bi bi-layers"></i> 全部组件</a>
    </div>

    <div class="preset-detail-layout">
        <div class="preset-detail-left">
            <section class="preset-panel">
                <div class="preset-panel__header">
                    <div>
                        <h2>实时预览</h2>
                        <p>使用默认参数渲染，启用后可在系统组件中继续调整。</p>
                    </div>
                    <code><?= e($preset['tag']) ?></code>
                </div>
                <iframe class="preset-preview" srcdoc="<?= e($previewDoc) ?>" loading="lazy"></iframe>
            </section>

            <section class="preset-panel">
                <div class="preset-panel__header">
                    <div>
                        <h2>调用与应用</h2>
                        <p>会先启用组件，再把调用标签写入选择的内容。</p>
                    </div>
                </div>

                <form method="post" action="<?= admin_url('/preset-components/components/' . rawurlencode($preset['slug']) . '/apply') ?>">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label">调用标签</label>
                        <textarea class="form-control preset-snippet" name="snippet" rows="5"><?= e($snippet) ?></textarea>
                        <div class="form-text">可在这里调整属性，例如 <code>id</code>、<code>title</code>、<code>url</code> 等。</div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">写入位置</label>
                            <select class="form-select" name="target_type" data-preset-target-type>
                                <?php foreach ($targets as $key => $target): ?>
                                    <?php if (!in_array($key, $preset['targets'], true)) continue; ?>
                                    <option value="<?= e($key) ?>"><?= e($target['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">目标内容</label>
                            <select class="form-select" name="target_id" data-preset-target-id>
                                <option value="" data-empty="1">请先选择目标内容</option>
                                <?php foreach ($targets as $key => $target): ?>
                                    <?php if (!in_array($key, $preset['targets'], true)) continue; ?>
                                    <?php foreach ($targetOptions[$key] ?? [] as $option): ?>
                                        <option value="<?= (int)$option['id'] ?>" data-type="<?= e($key) ?>" data-lock-version="<?= $option['lock_version'] !== null ? (int)$option['lock_version'] : '' ?>">
                                            <?= e($target['label'] . '：' . $option['label']) ?><?= $option['meta'] !== '' ? ' / ' . e($option['meta']) : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">写入方式</label>
                            <select class="form-select" name="placement">
                                <option value="append">追加到底部</option>
                                <option value="prepend">插入到顶部</option>
                            </select>
                        </div>
                    </div>
                    <input type="hidden" name="lock_version" value="" data-preset-lock-version>

                    <div class="preset-apply-footer">
                        <label class="form-check">
                            <input class="form-check-input" type="checkbox" name="skip_duplicate" value="1" checked>
                            <span class="form-check-label">目标中已有该组件时不重复写入</span>
                        </label>
                        <button class="btn btn-primary" type="submit"><i class="bi bi-box-arrow-in-down"></i> 应用到内容</button>
                    </div>
                </form>
            </section>

            <section class="preset-panel">
                <div class="preset-panel__header">
                    <div>
                        <h2>参数</h2>
                        <p>这些参数可作为组件标签属性传入。</p>
                    </div>
                </div>
                <div class="preset-param-list">
                    <?php foreach ($preset['params'] as $param): ?>
                        <div>
                            <code><?= e($param['key']) ?></code>
                            <span><?= e($param['label']) ?></span>
                            <small><?= e($param['type']) ?></small>
                            <p><?= e($param['default'] ?? '') ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>

        <div class="preset-detail-right">
            <section class="preset-panel">
                <div class="preset-panel__header">
                    <div>
                        <h2>源代码</h2>
                        <p>查看预设源码。若需编辑，请先使用为系统组件。</p>
                    </div>
                </div>

                <ul class="nav nav-tabs preset-source-tabs" role="tablist">
                    <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#preset-html" type="button">HTML</button></li>
                    <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#preset-css" type="button">CSS</button></li>
                    <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#preset-js" type="button">JS</button></li>
                </ul>
                <div class="tab-content preset-source-content">
                    <div class="tab-pane fade show active" id="preset-html">
                        <textarea readonly spellcheck="false"><?= e($preset['html']) ?></textarea>
                    </div>
                    <div class="tab-pane fade" id="preset-css">
                        <textarea readonly spellcheck="false"><?= e($preset['css'] ?? '') ?></textarea>
                    </div>
                    <div class="tab-pane fade" id="preset-js">
                        <textarea readonly spellcheck="false"><?= e($preset['js'] ?? '') ?></textarea>
                    </div>
                </div>
            </section>

            <section class="preset-panel">
                <div class="preset-panel__header">
                    <div>
                        <h2>信息</h2>
                        <p>组件当前同步状态。</p>
                    </div>
                </div>
                <dl class="preset-meta-list">
                    <div><dt>标签</dt><dd><code><?= e($preset['tag']) ?></code></dd></div>
                    <div><dt>分类</dt><dd><?= e($categories[$preset['category']] ?? $preset['category']) ?></dd></div>
                    <div><dt>参数</dt><dd><?= count($preset['params'] ?? []) ?> 个</dd></div>
                    <div><dt>系统组件 ID</dt><dd><?= $record ? (int)$record['id'] : '未创建' ?></dd></div>
                </dl>
            </section>
        </div>
    </div>
</div>

<script>
(function(){
    var typeSelect = document.querySelector('[data-preset-target-type]');
    var targetSelect = document.querySelector('[data-preset-target-id]');
    var lockVersionInput = document.querySelector('[data-preset-lock-version]');
    if (!typeSelect || !targetSelect || !lockVersionInput) return;
    function syncLockVersion(){
        var selected = targetSelect.options[targetSelect.selectedIndex];
        lockVersionInput.value = selected && !selected.dataset.empty
            ? (selected.dataset.lockVersion || '')
            : '';
    }
    function syncTargets(){
        var type = typeSelect.value;
        var first = null;
        Array.prototype.forEach.call(targetSelect.options, function(option){
            if (option.dataset.empty) {
                option.hidden = true;
                option.disabled = true;
                return;
            }
            var show = option.dataset.type === type;
            option.hidden = !show;
            option.disabled = !show;
            if (show && first === null) first = option;
        });
        if (first) {
            first.selected = true;
            targetSelect.disabled = false;
        } else {
            targetSelect.options[0].hidden = false;
            targetSelect.options[0].disabled = false;
            targetSelect.options[0].selected = true;
            targetSelect.disabled = true;
        }
        syncLockVersion();
    }
    typeSelect.addEventListener('change', syncTargets);
    targetSelect.addEventListener('change', syncLockVersion);
    syncTargets();
})();
</script>

<?php $this->endSection(); ?>
