<?php
/**
 * 独立接口密钥。刻意不在声明式 settings 里：一旦声明，它就会出现在
 * /api/v1/plugin-settings 的可读契约与 Agent 上下文里。这里单独收、加密存、永不回显。
 *
 * @var bool $customKeySet
 */
?>
<div class="acs-a-secret acs-provider-custom" data-acs-field="custom_api_key">
    <label class="acs-a-label" for="custom_api_key">独立接口密钥</label>
    <div class="acs-a-secret-row">
        <input type="password" class="acs-a-input" id="custom_api_key" name="custom_api_key" value=""
               autocomplete="new-password" maxlength="4096" placeholder="留空表示保持当前密钥" spellcheck="false">
        <button type="button" class="acs-a-btn acs-a-btn--ghost" data-acs-reveal aria-label="显示或隐藏密钥">
            <i class="bi bi-eye" aria-hidden="true"></i>
        </button>
    </div>
    <p class="acs-a-help">
        <?= $customKeySet ? '已设置并以 AES-GCM 加密保存；留空保存表示保持原值。' : '尚未设置。' ?>
        密钥只在服务端参与请求构造，不会下发到前台，也不进 API / Agent 返回。
    </p>
    <?php if ($customKeySet): ?>
        <label class="acs-a-switch acs-a-switch--danger">
            <input type="checkbox" id="clear_custom_api_key" name="clear_custom_api_key" value="1">
            <span class="acs-a-switch-track" aria-hidden="true"><span class="acs-a-switch-thumb"></span></span>
            <span class="acs-a-switch-label">清除当前独立接口密钥</span>
        </label>
    <?php endif; ?>
</div>
