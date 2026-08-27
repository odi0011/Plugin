<?php
/** @var array<string,mixed> $form */
/** @var string $page */
/** @var array<string,string> $pages */
/** @var array<string,mixed> $section */
/** @var list<array{id:int,label:string}> $models */
/** @var bool $customKeySet */
/** @var bool $active */
/** @var array<string,mixed> $status */
$this->extend('admin/views/layouts/main');
$this->section('title', 'AI客服');
$this->startSection('content');

$values = (array)($form['values'] ?? []);
$current = static function (array $field) use ($values) {
    $key = (string)($field['key'] ?? '');
    return $values[$key] ?? ($field['default'] ?? '');
};
$providerMode = (string)($values['provider_mode'] ?? 'system');

$pageIcons = [
    'conversation' => 'bi-chat-square-text',
    'trigger' => 'bi-sliders',
    'appearance' => 'bi-palette',
    'ai' => 'bi-cpu',
];
$fields = (array)($section['fields'] ?? []);
$saveKeys = [];
foreach ($fields as $field) {
    $key = (string)($field['key'] ?? '');
    if ($key !== '') $saveKeys[] = $key;
}
$pageUrl = static function (string $page): string {
    return admin_url('/ai-customer-service/' . $page);
};
?>

<div class="acs-admin" data-acs-admin data-acs-page="<?= e($page) ?>">
    <div class="acs-admin-head">
        <div>
            <h4><i class="bi bi-chat-square-dots" aria-hidden="true"></i> AI客服</h4>
            <p>前台悬浮客服与模型知识配置 · <?= e((string)($section['label'] ?? '')) ?></p>
        </div>
        <div class="acs-admin-state <?= !empty($status['enabled']) ? 'is-on' : 'is-off' ?>">
            <i class="bi <?= !empty($status['enabled']) ? 'bi-check-circle-fill' : 'bi-pause-circle-fill' ?>" aria-hidden="true"></i>
            <span><?= !empty($status['enabled']) ? '前台已启用' : '前台未启用' ?></span>
        </div>
    </div>

    <?php if (empty($active)): ?>
        <div class="alert alert-secondary py-2"><i class="bi bi-info-circle" aria-hidden="true"></i> 插件尚未启用。配置可先保存，启用后生效。</div>
    <?php endif; ?>

    <nav class="acs-admin-pages" aria-label="AI客服设置页面">
        <?php foreach ((array)$pages as $pageKey => $pageLabel): ?>
            <a href="<?= $pageUrl((string)$pageKey) ?>"<?= $pageKey === $page ? ' class="is-current" aria-current="page"' : '' ?>>
                <i class="bi <?= e((string)($pageIcons[$pageKey] ?? 'bi-gear')) ?>" aria-hidden="true"></i>
                <?= e((string)$pageLabel) ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <form method="post" action="<?= admin_url('/ai-customer-service') ?>" class="acs-admin-layout">
        <?= csrf_field() ?>
        <input type="hidden" name="acs_return_page" value="<?= e($page) ?>">
        <input type="hidden" name="acs_save_keys" value="<?= e(implode(',', $saveKeys)) ?>">
        <div class="acs-admin-form">
            <section class="acs-config-section">
                <header>
                    <h5><?= e((string)($section['label'] ?? '')) ?></h5>
                    <?php if (trim((string)($section['description'] ?? '')) !== ''): ?>
                        <p><?= e((string)$section['description']) ?></p>
                    <?php endif; ?>
                </header>
                <div class="acs-field-grid">
                    <?php foreach ($fields as $field): ?>
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
                                    <textarea class="form-control" id="<?= e($name) ?>" name="<?= e($name) ?>" rows="<?= in_array($key, ['knowledge_base', 'system_prompt'], true) ? 10 : 4 ?>"<?php if ($field['max_length'] !== null): ?> maxlength="<?= (int)$field['max_length'] ?>"<?php endif; ?><?php if ((string)($field['placeholder'] ?? '') !== ''): ?> placeholder="<?= e((string)$field['placeholder']) ?>"<?php endif; ?>><?= e((string)$value) ?></textarea>
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

                    <?php if ($page === 'ai'): ?>
                        <div class="acs-secret-field acs-provider-custom" data-acs-field="custom_api_key">
                            <label class="form-label" for="custom_api_key">独立接口密钥</label>
                            <input type="password" class="form-control" id="custom_api_key" name="custom_api_key" value="" autocomplete="new-password" maxlength="4096" placeholder="留空时保持当前密钥">
                            <div class="form-text"><?= $customKeySet ? '已设置并加密保存；留空保存表示保持原值。' : '尚未设置。' ?></div>
                            <?php if ($customKeySet): ?>
                                <div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="clear_custom_api_key" name="clear_custom_api_key" value="1"><label class="form-check-label" for="clear_custom_api_key">清除当前独立接口密钥</label></div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <div class="acs-savebar">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i> 保存本页配置</button>
                <a class="btn btn-outline-secondary" href="<?= admin_url('/plugins') ?>">返回插件列表</a>
            </div>
        </div>

        <aside class="acs-admin-preview" aria-label="前台挂件实时预览">
            <div class="acs-preview-head">
                <span class="acs-preview-label">前台实时预览</span>
                <div class="acs-preview-devices" role="group" aria-label="预览设备切换">
                    <button type="button" class="is-active" data-acs-device="desktop" aria-pressed="true"><i class="bi bi-display" aria-hidden="true"></i> 桌面</button>
                    <button type="button" data-acs-device="mobile" aria-pressed="false"><i class="bi bi-phone" aria-hidden="true"></i> 移动</button>
                </div>
            </div>
            <div class="acs-preview-stage" data-acs-preview-stage>
                <div class="acs-pv-widget acs-pv--right" data-acs-pv-widget>
                    <button type="button" class="acs-pv-launcher has-badge" tabindex="-1">
                        <img class="acs-pv-launcher-img" src="" alt="" hidden>
                        <i class="bi bi-chat-dots-fill acs-pv-launcher-icon" aria-hidden="true"></i>
                        <span class="acs-pv-launcher-label" hidden>AI客服</span>
                        <i class="bi bi-x-lg acs-pv-launcher-close" aria-hidden="true"></i>
                    </button>
                    <span class="acs-pv-tooltip" hidden>有疑问？点我咨询</span>
                    <div class="acs-pv-teaser" data-acs-pv-teaser>
                        <button type="button" class="acs-pv-teaser-close" tabindex="-1" aria-label="收起引导消息"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                        <span class="acs-pv-teaser-text">有问题？随时咨询</span>
                    </div>
                    <div class="acs-pv-panel" data-acs-preview-panel>
                        <div class="acs-pv-header">
                            <span class="acs-pv-avatar"><i class="bi bi-stars" aria-hidden="true"></i><img src="" alt="" hidden></span>
                            <span class="acs-pv-agent-copy"><strong data-acs-preview="brand_name">AI客服</strong><small data-acs-preview="team_label">智能在线服务</small></span>
                            <i class="bi bi-x-lg acs-pv-header-close" aria-hidden="true"></i>
                        </div>
                        <div class="acs-pv-body">
                            <div class="acs-pv-message acs-pv-message--bot" data-acs-preview="welcome_message">您好，我是您的 AI 客服。有什么可以帮您？</div>
                            <div class="acs-pv-message acs-pv-message--visitor">我想了解产品</div>
                        </div>
                        <div class="acs-pv-quick" data-acs-pv-quick>
                            <span class="acs-pv-quick-title" data-acs-pv-quick-title hidden>猜你想问</span>
                            <div class="acs-pv-quick-row"></div>
                        </div>
                        <div class="acs-pv-handoff" data-acs-pv-handoff hidden><i class="bi bi-person-workspace" aria-hidden="true"></i><span>联系人工客服</span></div>
                        <div class="acs-pv-powered" data-acs-pv-powered hidden>AI客服</div>
                        <div class="acs-pv-composer"><span data-acs-preview="input_placeholder">输入您的问题...</span><i class="bi bi-arrow-up" aria-hidden="true"></i></div>
                    </div>
                </div>
            </div>
        </aside>
    </form>
</div>

<?php $this->endSection(); ?>
