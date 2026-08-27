(function () {
    'use strict';

    // 后台左侧菜单高亮：核心侧边栏按 REQUEST_URI 做子串匹配，插件菜单存的是
    // 带域名的完整 URL，天然匹配不上；这里在插件自己的页面上把对应链接补亮，
    // 覆盖 /ai-customer-service 的四个子页面（含自定义后台前缀与子目录部署）。
    try {
        var path = window.location.pathname;
        var marker = '/ai-customer-service';
        var cut = path.indexOf(marker);
        if (cut !== -1) {
            var needle = path.slice(0, cut + marker.length);
            document.querySelectorAll('.admin-sidebar-nav a').forEach(function (link) {
                var linkPath;
                try {
                    linkPath = new URL(link.href, window.location.origin).pathname;
                } catch (error) {
                    return;
                }
                if (linkPath === needle || linkPath.indexOf(needle + '/') === 0) {
                    link.classList.add('active');
                }
            });
        }
    } catch (error) { /* 高亮属于增强，失败不影响页面 */ }

    var root = document.querySelector('[data-acs-admin]');
    if (!root) return;

    var stage = root.querySelector('[data-acs-preview-stage]');
    var widget = root.querySelector('[data-acs-pv-widget]');
    var panel = root.querySelector('[data-acs-preview-panel]');
    var launcher = root.querySelector('.acs-pv-launcher');
    var tooltip = root.querySelector('.acs-pv-tooltip');
    var teaser = root.querySelector('[data-acs-pv-teaser]');
    var quickRow = root.querySelector('.acs-pv-quick-row');
    var customFields = root.querySelectorAll('.acs-provider-custom');
    var systemFields = root.querySelectorAll('.acs-provider-system');
    if (!widget || !panel || !launcher || !quickRow) return;

    var device = 'desktop';

    function control(key) {
        return root.querySelector('[name="setting_' + key + '"]');
    }

    function isEnabled(key) {
        var box = control(key);
        return Boolean(box && box.checked);
    }

    function val(key, fallback) {
        var field = control(key);
        if (!field) return fallback;
        var value = String(field.value == null ? '' : field.value).trim();
        return value !== '' ? value : fallback;
    }

    var ICONS = { chat: 'bi-chat-dots-fill', sparkles: 'bi-stars', headset: 'bi-headset', question: 'bi-question-circle-fill' };

    function setVisible(node, visible) {
        if (node) node.hidden = !visible;
    }

    function clearFx() {
        ['wiggle', 'bounce', 'pulse'].forEach(function (fx) {
            launcher.classList.remove('acs-fx--' + fx);
        });
    }

    function setText(selector, key, fallback) {
        var node = typeof selector === 'string' ? root.querySelector(selector) : selector;
        if (!node) return;
        node.textContent = val(key, fallback);
    }

    function rebuildQuickReplies() {
        var lines = String(control('quick_replies') ? control('quick_replies').value : '')
            .split(/\r?\n/)
            .map(function (line) { return line.trim(); })
            .filter(Boolean)
            .slice(0, 3);
        quickRow.textContent = '';
        lines.forEach(function (line) {
            var chip = document.createElement('span');
            chip.className = 'acs-pv-chip';
            chip.textContent = line;
            quickRow.appendChild(chip);
        });
    }

    function colors() {
        widget.style.setProperty('--pv-accent', val('accent_color', '#0D6EFD'));
        widget.style.setProperty('--pv-surface', val('surface_color', '#FFFFFF'));
        widget.style.setProperty('--pv-text', val('text_color', '#1F2937'));
        widget.style.setProperty('--pv-muted', val('muted_color', '#667085'));
        widget.style.setProperty('--pv-header-bg', val('header_color', '#0D6EFD'));
        widget.style.setProperty('--pv-header-fg', val('header_text_color', '#FFFFFF'));
        widget.style.setProperty('--pv-bot-bubble', val('bot_bubble_color', '#F2F4F7'));
        widget.style.setProperty('--pv-bot-text', val('bot_bubble_text_color', '#1F2937'));
        widget.style.setProperty('--pv-vis-bubble', val('visitor_bubble_color', '#0D6EFD'));
        widget.style.setProperty('--pv-vis-text', val('visitor_bubble_text_color', '#FFFFFF'));
    }

    // 让预览窗口按配置比例缩放进舞台，k 同时作用于字号、圆角与边距。
    function fitScale(panelWidth, panelHeight) {
        var stageWidth = stage ? stage.clientWidth : 320;
        var stageHeight = stage ? stage.clientHeight : 480;
        return Math.max(0.32, Math.min(
            1,
            (stageWidth - 44) / Math.max(1, panelWidth),
            (stageHeight - 84) / Math.max(1, panelHeight)
        ));
    }

    function positionAndSize(k) {
        var isMobile = device === 'mobile';
        var panelWidth = parseInt(val('panel_width', '388'), 10);
        var panelHeight = parseInt(val('panel_height', '580'), 10);
        var offsetX = parseInt(val(isMobile ? 'mobile_offset_x' : 'desktop_offset_x', isMobile ? '16' : '20'), 10);
        var offsetY = parseInt(val(isMobile ? 'mobile_offset_y' : 'desktop_offset_y', isMobile ? '16' : '20'), 10);
        var size = parseInt(val('widget_size', '56'), 10);

        panel.style.width = Math.round(panelWidth * k) + 'px';
        panel.style.height = Math.round(panelHeight * k) + 'px';
        panel.style.bottom = Math.round(size * k + 14 * k) + 'px';

        var leftSide = val('position', 'right') === 'left';
        widget.classList.toggle('is-left', leftSide);
        widget.classList.toggle('acs-pv--left', leftSide);
        widget.classList.toggle('acs-pv--right', !leftSide);
        widget.style[leftSide ? 'left' : 'right'] = Math.max(6, Math.round(offsetX * k)) + 'px';
        widget.style[leftSide ? 'right' : 'left'] = '';
        widget.style.bottom = Math.max(8, Math.round(offsetY * k)) + 'px';

        launcher.style.width = Math.round(size * k) + 'px';
        launcher.style.height = Math.round(size * k) + 'px';
        var style = val('launcher_style', 'bubble');
        launcher.style.borderRadius = style === 'pill'
            ? Math.round(parseInt(val('launcher_corner', '10'), 10) * k) + 'px'
            : '999px';
        setVisible(root.querySelector('.acs-pv-launcher-label'), style === 'pill');
        launcher.style.gap = style === 'pill' ? Math.round(9 * k) + 'px' : '';
    }

    function applyLauncherLook() {
        var iconUrl = val('launcher_image_url', '');
        var img = launcher.querySelector('.acs-pv-launcher-img');
        var icon = launcher.querySelector('.acs-pv-launcher-icon');
        if (img) {
            if (iconUrl) {
                if (img.getAttribute('src') !== iconUrl) img.setAttribute('src', iconUrl);
            } else {
                img.removeAttribute('src');
            }
            setVisible(img, Boolean(iconUrl));
        }
        if (icon) {
            icon.className = 'bi ' + (ICONS[val('launcher_icon', 'chat')] || ICONS.chat) + ' acs-pv-launcher-icon';
            setVisible(icon, !Boolean(iconUrl));
        }
        launcher.classList.toggle('has-badge', isEnabled('badge_enabled'));
        clearFx();
        var fx = val('attention_effect', 'none');
        if (fx !== 'none') launcher.classList.add('acs-fx--' + fx);
        setText(tooltip, 'tooltip_text', '');
        setVisible(tooltip, Boolean(String(control('tooltip_text') ? control('tooltip_text').value : '').trim()));
    }

    function applyHeaderAvatar() {
        var avatarWrap = panel.querySelector('.acs-pv-avatar');
        if (!avatarWrap) return;
        var avatarImg = avatarWrap.querySelector('img');
        var icon = avatarWrap.querySelector('i');
        var url = val('avatar_url', '');
        if (avatarImg) {
            if (url && avatarImg.getAttribute('src') !== url) avatarImg.setAttribute('src', url);
            if (!url) avatarImg.removeAttribute('src');
            setVisible(avatarImg, Boolean(url));
        }
        if (icon) setVisible(icon, !url);
        setVisible(avatarWrap, isEnabled('show_avatar'));
    }

    function apply() {
        var k = fitScale(parseInt(val('panel_width', '388'), 10), parseInt(val('panel_height', '580'), 10));
        widget.style.fontSize = (parseInt(val('font_size', '14'), 10) * k).toFixed(1) + 'px';
        var radius = parseInt(val('panel_radius', '10'), 10);
        panel.style.borderRadius = Math.round(radius * k) + 'px';
        panel.style.boxShadow = '0 20px 56px rgba(15, 23, 42, ' + ({
            none: '0',
            sm: '.14',
            md: '.25',
            lg: '.34'
        }[val('panel_shadow', 'md')] || '.25') + ')';

        colors();
        positionAndSize(k);
        applyLauncherLook();
        applyHeaderAvatar();

        setText('[data-acs-preview="brand_name"]', 'brand_name', 'AI客服');
        setText('.acs-pv-launcher-label', 'brand_name', 'AI客服');
        setText('[data-acs-preview="team_label"]', 'team_label', '智能在线服务');
        setText('[data-acs-preview="welcome_message"]', 'welcome_message', '您好，我是您的 AI 客服。有什么可以帮您？');
        setText('[data-acs-preview="input_placeholder"]', 'input_placeholder', '输入您的问题...');
        setText('.acs-pv-handoff span', 'handoff_label', '联系人工客服');

        var quickTitle = root.querySelector('[data-acs-pv-quick-title]');
        if (quickTitle) {
            var titleValue = String(control('quick_replies_title') ? control('quick_replies_title').value : '').trim();
            quickTitle.textContent = titleValue || '猜你想问';
            setVisible(quickTitle, Boolean(titleValue));
        }
        rebuildQuickReplies();

        setVisible(root.querySelector('[data-acs-pv-handoff]'), Boolean(val('handoff_url', '')));
        setVisible(root.querySelector('[data-acs-pv-powered]'), isEnabled('show_powered_by'));

        var teaserNode = teaser || root.querySelector('[data-acs-pv-teaser]');
        if (teaserNode) {
            var teaserOn = isEnabled('teaser_enabled') && isEnabled('show_launcher')
                && Boolean(String(control('teaser_text') ? control('teaser_text').value : '').trim());
            setText(teaserNode.querySelector ? teaserNode.querySelector('.acs-pv-teaser-text') : teaserNode, 'teaser_text', '有问题？随时咨询');
            setVisible(teaserNode, teaserOn && !teaserNode.dataset.closed);
        }

        setVisible(launcher, isEnabled('show_launcher'));
        syncProviderFields();
    }

    function syncProviderFields() {
        var mode = val('provider_mode', 'system');
        customFields.forEach(function (node) { node.hidden = mode !== 'custom'; });
        systemFields.forEach(function (node) { node.hidden = mode !== 'system'; });
    }

    root.querySelectorAll('input, textarea, select').forEach(function (controlNode) {
        controlNode.addEventListener('input', apply);
        controlNode.addEventListener('change', apply);
    });

    root.querySelectorAll('[data-acs-device]').forEach(function (button) {
        button.addEventListener('click', function () {
            device = button.getAttribute('data-acs-device') === 'mobile' ? 'mobile' : 'desktop';
            root.querySelectorAll('[data-acs-device]').forEach(function (item) {
                var active = item === button;
                item.classList.toggle('is-active', active);
                item.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
            if (stage) stage.classList.toggle('is-mobile', device === 'mobile');
            apply();
        });
    });

    var teaserClose = root.querySelector('.acs-pv-teaser-close');
    if (teaserClose) {
        teaserClose.addEventListener('click', function () {
            var teaserNode = root.querySelector('[data-acs-pv-teaser]');
            if (teaserNode) teaserNode.dataset.closed = '1';
            setVisible(teaserNode, false);
        });
    }

    if (window.ResizeObserver && stage) {
        new ResizeObserver(function () { apply(); }).observe(stage);
    }
    window.addEventListener('resize', apply);
    apply();
}());
