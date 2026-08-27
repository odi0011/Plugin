<?php
/** @var array<string,mixed> $form */
/** @var list<array{id:int,label:string}> $models */
/** @var bool $customKeySet */
/** @var bool $active */
/** @var array<string,mixed> $status */
$this->extend('admin/views/layouts/main');
$this->section('title', 'AI客服');
$this->startSection('content');

$sections = (array)($form['sections'] ?? []);
$values = (array)($form['values'] ?? []);
$current = static function (array $field) use ($values) {
    $key = (string)($field['key'] ?? '');
    return $values[$key] ?? ($field['default'] ?? '');
};
$providerMode = (string)($values['provider_mode'] ?? 'system');
?>

<div class="acs-admin" data-acs-admin>
    <div class="acs-admin-head">
        <div>
            <h4><i class="bi bi-chat-square-dots" aria-hidden="true"></i> AI客服</h4>
            <p>前台悬浮客服与模型知识配置</p>
        </div>
        <div class="acs-admin-state <?= !empty($status['enabled']) ? 'is-on' : 'is-off' ?>">
            <i class="bi <?= !empty($status['enabled']) ? 'bi-check-circle-fill' : 'bi-pause-circle-fill' ?>" aria-hidden="true"></i>
            <span><?= !empty($status['enabled']) ? '前台已启用' : '前台未启用' ?></span>
        </div>
    </div>

    <?php if (empty($active)): ?>
        <div class="alert alert-secondary py-2"><i class="bi bi-info-circle" aria-hidden="true"></i> 插件尚未启用。配置可先保存，启用后生效。</div>
    <?php endif; ?>

    <form method="post" action="<?= admin_url('/ai-customer-service') ?>" class="acs-admin-layout">
        <?= csrf_field() ?>
        <div class="acs-admin-form">
            <nav class="acs-admin-nav" aria-label="AI客服设置分组">
                <?php foreach ($sections as $index => $section): ?>
                    <a href="#acs-section-<?= e((string)$section['key']) ?>"<?= $index === 0 ? ' class="is-current"' : '' ?>><?= e((string)$section['label']) ?></a>
                <?php endforeach; ?>
            </nav>

            <?php foreach ($sections as $section): ?>
                <section class="acs-config-section" id="acs-section-<?= e((string)$section['key']) ?>">
                    <header>
                        <h5><?= e((string)$section['label']) ?></h5>
                        <?php if (trim((string)($section['description'] ?? '')) !== ''): ?>
                            <p><?= e((string)$section['description']) ?></p>
                        <?php endif; ?>
                    </header>
                    <div class="acs-field-grid">
                        <?php foreach ((array)($section['fields'] ?? []) as $field): ?>
                            <?php
                            $key = (string)($field['key'] ?? '');
                            $name = 'setting_' . $key;
                            $type = (string)($field['type'] ?? 'text');
                            $value = $current($field);
                            $providerClass = in_array($key, ['custom_api_endpoint', 'custom_model'], true)
                                ? ' acs-provider-custom'
                                : ($key === 'system_model_id' ? ' acs-provider-system' : '');
                            ?>
                            <div class="acs-field acs-field--<?= e($type) ?><?= $providerClass ?>" data-acs-field="<?= e($key) ?>">
                                <?php if ($type === 'boolean'): ?>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="<?= e($name) ?>" name="<?= e($name) ?>" value="1"<?= !empty($value) ? ' checked' : '' ?>>
                                        <label class="form-check-label" for="<?= e($name) ?>"><?= e((string)$field['label']) ?></label>
                                    </div>
                                <?php else: ?>
                                    <label class="form-label" for="<?= e($name) ?>"><?= e((string)$field['label']) ?><?php if (!empty($field['required'])): ?> <span class="text-danger">*</span><?php endif; ?></label>
                                    <?php if ($key === 'system_model_id'): ?>
                                        <select class="form-select" id="<?= e($name) ?>" name="<?= e($name) ?>">
                                            <option value="0"<?= (int)$value === 0 ? ' selected' : '' ?>>使用系统默认对话模型</option>
                                            <?php foreach ($models as $model): ?>
                                                <option value="<?= (int)$model['id'] ?>"<?= (int)$value === (int)$model['id'] ? ' selected' : '' ?>><?= e((string)$model['label']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php elseif ($type === 'textarea'): ?>
                                        <textarea class="form-control" id="<?= e($name) ?>" name="<?= e($name) ?>" rows="<?= in_array($key, ['knowledge_base', 'system_prompt'], true) ? 8 : 4 ?>"<?php if ($field['max_length'] !== null): ?> maxlength="<?= (int)$field['max_length'] ?>"<?php endif; ?><?php if ((string)($field['placeholder'] ?? '') !== ''): ?> placeholder="<?= e((string)$field['placeholder']) ?>"<?php endif; ?>><?= e((string)$value) ?></textarea>
                                    <?php elseif ($type === 'select'): ?>
                                        <select class="form-select" id="<?= e($name) ?>" name="<?= e($name) ?>">
                                            <?php foreach ((array)($field['options'] ?? []) as $option): ?>
                                                <option value="<?= e((string)$option['value']) ?>"<?= (string)$value === (string)$option['value'] ? ' selected' : '' ?>><?= e((string)$option['label']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php elseif ($type === 'number'): ?>
                                        <input type="number" class="form-control" id="<?= e($name) ?>" name="<?= e($name) ?>" value="<?= e((string)$value) ?>"<?php if ($field['min'] !== null): ?> min="<?= e((string)$field['min']) ?>"<?php endif; ?><?php if ($field['max'] !== null): ?> max="<?= e((string)$field['max']) ?>"<?php endif; ?><?php if ($field['step'] !== null): ?> step="<?= e((string)$field['step']) ?>"<?php endif; ?>>
                                    <?php elseif ($type === 'color'): ?>
                                        <input type="color" class="form-control form-control-color" id="<?= e($name) ?>" name="<?= e($name) ?>" value="<?= e((string)$value !== '' ? (string)$value : '#000000') ?>">
                                    <?php else: ?>
                                        <input type="<?= $type === 'url' ? 'url' : 'text' ?>" class="form-control" id="<?= e($name) ?>" name="<?= e($name) ?>" value="<?= e((string)$value) ?>" autocomplete="off"<?php if ($field['max_length'] !== null): ?> maxlength="<?= (int)$field['max_length'] ?>"<?php endif; ?><?php if ((string)($field['placeholder'] ?? '') !== ''): ?> placeholder="<?= e((string)$field['placeholder']) ?>"<?php endif; ?>>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if (trim((string)($field['help'] ?? '')) !== ''): ?><div class="form-text"><?= e((string)$field['help']) ?></div><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ((string)$section['key'] === 'ai'): ?>
                        <div class="acs-secret-field acs-provider-custom" data-acs-field="custom_api_key">
                            <label class="form-label" for="custom_api_key">独立接口密钥</label>
                            <input type="password" class="form-control" id="custom_api_key" name="custom_api_key" value="" autocomplete="new-password" maxlength="4096" placeholder="留空时保持当前密钥">
                            <div class="form-text"><?= $customKeySet ? '已设置并加密保存；留空保存表示保持原值。' : '尚未设置。' ?></div>
                            <?php if ($customKeySet): ?>
                                <div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="clear_custom_api_key" name="clear_custom_api_key" value="1"><label class="form-check-label" for="clear_custom_api_key">清除当前独立接口密钥</label></div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>

            <div class="acs-savebar">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i> 保存配置</button>
                <a class="btn btn-outline-secondary" href="<?= admin_url('/plugins') ?>">返回插件列表</a>
            </div>
        </div>

        <aside class="acs-admin-preview" aria-label="前台挂件预览">
            <div class="acs-preview-label">前台预览</div>
            <div class="acs-preview-stage">
                <div class="acs-preview-panel" data-acs-preview-panel>
                    <div class="acs-preview-header"><span class="acs-preview-avatar"><i class="bi bi-stars" aria-hidden="true"></i></span><span><strong data-acs-preview="brand_name">AI客服</strong><small data-acs-preview="team_label">智能在线服务</small></span></div>
                    <div class="acs-preview-body"><div class="acs-preview-message" data-acs-preview="welcome_message">您好，我是您的 AI 客服。有什么可以帮您？</div><div class="acs-preview-message is-visitor">我想了解产品</div></div>
                    <div class="acs-preview-input" data-acs-preview="input_placeholder">输入您的问题...</div>
                </div>
                <div class="acs-preview-launcher"><i class="bi bi-chat-dots-fill" aria-hidden="true"></i></div>
            </div>
        </aside>
    </form>
</div>

<?php $this->endSection(); ?>
