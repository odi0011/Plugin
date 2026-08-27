<?php
/**
 * 单个声明式字段的 antd 风格控件。
 *
 * 归一化后的字段一定带 min/max/step/max_length/pattern/options 这些键（值可能是 null），
 * 所以这里可以直接取，不用到处 ??。
 *
 * @var array<string,mixed> $field
 * @var mixed $value
 * @var list<array{id:int,label:string}> $models
 */
$key = (string)$field['key'];
$name = 'setting_' . $key;
$type = (string)($field['type'] ?? 'text');
$label = (string)($field['label'] ?? $key);
$help = trim((string)($field['help'] ?? ''));
$placeholder = (string)($field['placeholder'] ?? '');
$maxLength = $field['max_length'] ?? null;
$wide = in_array($type, ['textarea'], true) || in_array($key, ['url_rules', 'system_prompt', 'knowledge_base'], true);
$isColor = $type === 'color';
$rows = in_array($key, ['system_prompt', 'knowledge_base'], true) ? 9 : 4;
?>
<div class="acs-a-item<?= $wide ? ' is-wide' : '' ?><?= $isColor ? ' is-color' : '' ?>"
     data-acs-field="<?= e($key) ?>" data-acs-type="<?= e($type) ?>">

    <?php if ($type === 'boolean'): ?>
        <label class="acs-a-switch">
            <input type="checkbox" id="<?= e($name) ?>" name="<?= e($name) ?>" value="1"<?= !empty($value) ? ' checked' : '' ?>>
            <span class="acs-a-switch-track" aria-hidden="true"><span class="acs-a-switch-thumb"></span></span>
            <span class="acs-a-switch-label"><?= e($label) ?></span>
        </label>
        <?php if ($help !== ''): ?><p class="acs-a-help"><?= e($help) ?></p><?php endif; ?>

    <?php else: ?>
        <label class="acs-a-label" for="<?= e($name) ?>">
            <?= e($label) ?>
            <?php if (!empty($field['required'])): ?><em class="acs-a-required">*</em><?php endif; ?>
        </label>

        <?php if ($key === 'system_model_id'): ?>
            <div class="acs-a-select">
                <select id="<?= e($name) ?>" name="<?= e($name) ?>">
                    <option value="0"<?= (int)$value === 0 ? ' selected' : '' ?>>使用系统默认对话模型</option>
                    <?php foreach ($models as $model): ?>
                        <option value="<?= (int)$model['id'] ?>"<?= (int)$value === (int)$model['id'] ? ' selected' : '' ?>><?= e((string)$model['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <i class="bi bi-chevron-down" aria-hidden="true"></i>
            </div>
            <?php if ($models === []): ?>
                <p class="acs-a-help acs-a-help--warn">系统里还没有启用的对话模型。可以先选「使用插件独立接口」。</p>
            <?php endif; ?>

        <?php elseif ($type === 'select'): ?>
            <div class="acs-a-select">
                <select id="<?= e($name) ?>" name="<?= e($name) ?>">
                    <?php foreach ((array)($field['options'] ?? []) as $option): ?>
                        <option value="<?= e((string)$option['value']) ?>"<?= (string)$value === (string)$option['value'] ? ' selected' : '' ?>><?= e((string)$option['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <i class="bi bi-chevron-down" aria-hidden="true"></i>
            </div>

        <?php elseif ($type === 'textarea'): ?>
            <textarea class="acs-a-input acs-a-textarea" id="<?= e($name) ?>" name="<?= e($name) ?>" rows="<?= (int)$rows ?>"
                      <?php if ($maxLength !== null): ?>maxlength="<?= (int)$maxLength ?>"<?php endif; ?>
                      <?php if ($placeholder !== ''): ?>placeholder="<?= e($placeholder) ?>"<?php endif; ?>
                      <?php if ($maxLength !== null): ?>data-acs-count="1"<?php endif; ?>><?= e((string)$value) ?></textarea>
            <?php if ($maxLength !== null): ?><span class="acs-a-count" data-acs-count-for="<?= e($name) ?>"></span><?php endif; ?>

        <?php elseif ($type === 'number'): ?>
            <div class="acs-a-number">
                <button type="button" class="acs-a-step" data-acs-step="-1" tabindex="-1" aria-label="减小"><i class="bi bi-dash" aria-hidden="true"></i></button>
                <input type="number" class="acs-a-input" id="<?= e($name) ?>" name="<?= e($name) ?>" value="<?= e((string)$value) ?>"
                       <?php if ($field['min'] !== null): ?>min="<?= e((string)$field['min']) ?>"<?php endif; ?>
                       <?php if ($field['max'] !== null): ?>max="<?= e((string)$field['max']) ?>"<?php endif; ?>
                       <?php if ($field['step'] !== null): ?>step="<?= e((string)$field['step']) ?>"<?php endif; ?>>
                <button type="button" class="acs-a-step" data-acs-step="1" tabindex="-1" aria-label="增大"><i class="bi bi-plus" aria-hidden="true"></i></button>
            </div>
            <?php if ($field['min'] !== null && $field['max'] !== null): ?>
                <input type="range" class="acs-a-slider" data-acs-slider-for="<?= e($name) ?>" aria-label="<?= e($label) ?>滑块"
                       min="<?= e((string)$field['min']) ?>" max="<?= e((string)$field['max']) ?>"
                       step="<?= e((string)($field['step'] ?? 1)) ?>" value="<?= e((string)$value) ?>">
            <?php endif; ?>

        <?php elseif ($isColor): ?>
            <div class="acs-a-color" data-acs-color>
                <input type="color" id="<?= e($name) ?>" name="<?= e($name) ?>"
                       value="<?= e((string)$value !== '' ? (string)$value : '#000000') ?>">
                <input type="text" class="acs-a-input acs-a-color-hex" data-acs-color-hex value="<?= e((string)$value) ?>"
                       maxlength="7" spellcheck="false" aria-label="<?= e($label) ?>色值">
                <button type="button" class="acs-a-color-more" data-acs-color-more aria-label="打开色板"><i class="bi bi-grid-3x3-gap-fill" aria-hidden="true"></i></button>
            </div>

        <?php else: ?>
            <input type="<?= $type === 'url' ? 'url' : ($type === 'email' ? 'email' : 'text') ?>"
                   class="acs-a-input" id="<?= e($name) ?>" name="<?= e($name) ?>" value="<?= e((string)$value) ?>" autocomplete="off"
                   <?php if ($maxLength !== null): ?>maxlength="<?= (int)$maxLength ?>"<?php endif; ?>
                   <?php if ($placeholder !== ''): ?>placeholder="<?= e($placeholder) ?>"<?php endif; ?>>
        <?php endif; ?>

        <?php if ($help !== ''): ?><p class="acs-a-help"><?= e($help) ?></p><?php endif; ?>
    <?php endif; ?>
</div>
