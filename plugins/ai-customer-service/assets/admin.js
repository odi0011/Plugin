(function () {
    'use strict';

    var root = document.querySelector('[data-acs-admin]');
    if (!root) return;

    var panel = root.querySelector('[data-acs-preview-panel]');
    var launcher = root.querySelector('.acs-preview-launcher');
    var previews = root.querySelectorAll('[data-acs-preview]');
    var customFields = root.querySelectorAll('.acs-provider-custom');
    var systemFields = root.querySelectorAll('.acs-provider-system');

    function field(key) {
        return root.querySelector('[name="setting_' + key + '"]');
    }

    function textValue(key, fallback) {
        var control = field(key);
        return control && String(control.value || '').trim() ? String(control.value).trim() : fallback;
    }

    function setPreviewText(key, value) {
        previews.forEach(function (node) {
            if (node.getAttribute('data-acs-preview') === key) node.textContent = value;
        });
    }

    function applyTheme() {
        if (!panel || !launcher) return;
        var accent = textValue('accent_color', '#0D6EFD');
        var surface = textValue('surface_color', '#FFFFFF');
        var text = textValue('text_color', '#1F2937');
        var muted = textValue('muted_color', '#667085');
        panel.style.background = surface;
        panel.style.color = text;
        launcher.style.background = accent;
        panel.querySelector('.acs-preview-header').style.color = text;
        panel.querySelector('.acs-preview-input').style.color = muted;
        panel.querySelector('.acs-preview-message.is-visitor').style.background = accent;
        panel.querySelector('.acs-preview-avatar').style.background = accent;
    }

    function syncProviderFields() {
        var mode = textValue('provider_mode', 'system');
        customFields.forEach(function (node) { node.hidden = mode !== 'custom'; });
        systemFields.forEach(function (node) { node.hidden = mode !== 'system'; });
    }

    function sync() {
        setPreviewText('brand_name', textValue('brand_name', 'AI客服'));
        setPreviewText('team_label', textValue('team_label', '智能在线服务'));
        setPreviewText('welcome_message', textValue('welcome_message', '您好，我是您的 AI 客服。有什么可以帮您？'));
        setPreviewText('input_placeholder', textValue('input_placeholder', '输入您的问题...'));
        applyTheme();
        syncProviderFields();
    }

    root.querySelectorAll('input, textarea, select').forEach(function (control) {
        control.addEventListener('input', sync);
        control.addEventListener('change', sync);
    });
    root.querySelectorAll('.acs-admin-nav a').forEach(function (link) {
        link.addEventListener('click', function () {
            root.querySelectorAll('.acs-admin-nav a').forEach(function (item) { item.classList.remove('is-current'); });
            link.classList.add('is-current');
        });
    });
    sync();
}());
