(function () {
    'use strict';

    var root = document.getElementById('ai-customer-service-widget');
    var configNode = document.getElementById('acs-widget-config');
    if (!root || !configNode) return;

    var config;
    try {
        config = JSON.parse(configNode.textContent || '{}');
    } catch (error) {
        return;
    }
    if (!config || !config.endpoint || !config.csrf || !config.conversationId) return;

    var launcher = root.querySelector('.acs-launcher');
    var panel = root.querySelector('.acs-panel');
    var close = root.querySelector('.acs-close');
    var chat = root.querySelector('.acs-chat');
    var quickReplies = root.querySelector('.acs-quick-replies');
    var teaser = root.querySelector('[data-acs-teaser]');
    var form = root.querySelector('.acs-composer');
    var input = root.querySelector('#acs-message');
    var send = root.querySelector('.acs-send');
    var feedback = root.querySelector('.acs-feedback');
    if (!panel || !chat || !quickReplies || !form || !input || !send || !feedback) return;

    // show_launcher=false 时不渲染浮标，但保留面板供 window.AiCustomerService 使用。
    var state = { open: false, visible: false, greeted: false, busy: false, userOpened: false };
    var AUTO_KEY = 'acs_auto_shown';
    var TEASER_KEY = 'acs_teaser_dismissed';
    var autoTimer = null;

    function sessionFlag(key) {
        try {
            return window.sessionStorage.getItem(key);
        } catch (error) {
            return null;
        }
    }

    function setSessionFlag(key, value) {
        try {
            window.sessionStorage.setItem(key, value);
        } catch (error) { /* 隐私模式等场景下静默降级为每次会话都执行 */ }
    }

    function isMobile() {
        return window.matchMedia && window.matchMedia('(max-width: 640px)').matches;
    }

    function allowedDevice() {
        if (config.deviceMode === 'desktop') return !isMobile();
        if (config.deviceMode === 'mobile') return isMobile();
        return true;
    }

    function clearAttention() {
        if (launcher) launcher.classList.remove('has-badge', 'acs-fx--wiggle', 'acs-fx--bounce', 'acs-fx--pulse');
    }

    function dismissTeaser(persist) {
        if (teaser) teaser.hidden = true;
        if (persist) setSessionFlag(TEASER_KEY, '1');
    }

    function reveal() {
        if (state.visible) return;
        state.visible = true;
        root.dataset.visible = 'true';
        // once_per_session 的语义：自动展开、提醒动画与角标这一组自动动作
        // 在同一浏览器会话里只出现一次；执行过的会话直接呈现静态浮标。
        var allowAutoActions = !(config.oncePerSession && Boolean(sessionFlag(AUTO_KEY)));
        if (allowAutoActions) {
            setSessionFlag(AUTO_KEY, '1');
        } else {
            clearAttention();
            cancelAutoOpen();
        }
        scheduleTeaser();
        if (allowAutoActions) scheduleAutoOpen();
    }

    function setOpen(open) {
        if (!state.visible && open) reveal();
        state.open = Boolean(open);
        panel.hidden = !state.open;
        panel.setAttribute('aria-hidden', state.open ? 'false' : 'true');
        if (launcher) {
            launcher.setAttribute('aria-expanded', state.open ? 'true' : 'false');
            launcher.classList.toggle('is-open', state.open);
        }
        if (state.open) {
            cancelAutoOpen();
            clearAttention();
            dismissTeaser(false);
            ensureGreeting();
            window.setTimeout(function () { input.focus(); }, 0);
        } else {
            // 访客主动关闭（含 Esc）时，不再执行排队中的自动展开。
            cancelAutoOpen();
        }
    }

    function scheduleAutoOpen() {
        if (autoTimer !== null || !config.initialOpen) return;
        var delaySeconds = Math.max(0, Number(config.initialOpenDelay) || 0);
        autoTimer = window.setTimeout(function () {
            autoTimer = null;
            if (!state.userOpened) setOpen(true);
        }, delaySeconds * 1000);
    }

    function cancelAutoOpen() {
        if (autoTimer !== null) {
            window.clearTimeout(autoTimer);
            autoTimer = null;
        }
    }

    function scheduleTeaser() {
        if (!teaser || !config.teaserEnabled) return;
        teaser.hidden = true;
        if (sessionFlag(TEASER_KEY)) return;
        window.setTimeout(function () {
            if (!state.open) teaser.hidden = false;
        }, 700);
    }

    function appendMessage(role, content, typing) {
        var message = document.createElement('div');
        message.className = 'acs-message acs-message--' + role + (typing ? ' acs-message--typing' : '');
        var bubble = document.createElement('div');
        bubble.className = 'acs-message-bubble';
        if (typing) {
            for (var index = 0; index < 3; index += 1) bubble.appendChild(document.createElement('i'));
        } else {
            bubble.textContent = content;
        }
        message.appendChild(bubble);
        chat.appendChild(message);
        chat.scrollTop = chat.scrollHeight;
        return message;
    }

    function ensureGreeting() {
        if (state.greeted) return;
        state.greeted = true;
        if (config.welcomeMessage) appendMessage('assistant', config.welcomeMessage, false);
        if (config.quickRepliesTitle) {
            var title = document.createElement('span');
            title.className = 'acs-quick-title';
            title.textContent = String(config.quickRepliesTitle).slice(0, 60);
            quickReplies.appendChild(title);
        }
        var row = document.createElement('div');
        row.className = 'acs-quick-row';
        quickReplies.appendChild(row);
        (Array.isArray(config.quickReplies) ? config.quickReplies : []).forEach(function (question) {
            if (typeof question !== 'string' || question.trim() === '') return;
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'acs-quick-reply';
            button.textContent = question;
            button.addEventListener('click', function () { submit(question); });
            row.appendChild(button);
        });
    }

    function resizeInput() {
        input.style.height = 'auto';
        input.style.height = Math.min(100, Math.max(38, input.scrollHeight)) + 'px';
    }

    function setBusy(busy) {
        state.busy = busy;
        input.disabled = busy;
        send.disabled = busy;
        quickReplies.querySelectorAll('button').forEach(function (button) { button.disabled = busy; });
    }

    function showFeedback(message) {
        feedback.textContent = message || '';
    }

    function request(message) {
        var body = new URLSearchParams();
        body.set('_csrf', config.csrf);
        body.set('conversation_id', config.conversationId);
        body.set('message', message);
        return fetch(config.endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: body
        }).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (data) {
                if (!response.ok || !data.ok) {
                    var error = data && (data.error || (data.data && data.data.message));
                    throw new Error(error || config.unavailableMessage || '暂时无法回复，请稍后再试。');
                }
                return data.data || {};
            });
        });
    }

    function submit(rawMessage) {
        var message = String(rawMessage || '').trim();
        if (state.busy || message === '') return;
        showFeedback('');
        appendMessage('visitor', message, false);
        input.value = '';
        resizeInput();
        setBusy(true);
        var typing = appendMessage('assistant', '', true);
        request(message).then(function (data) {
            typing.remove();
            appendMessage('assistant', String(data.reply || config.unavailableMessage || ''), false);
        }).catch(function (error) {
            typing.remove();
            showFeedback(error && error.message ? error.message : (config.unavailableMessage || '暂时无法回复，请稍后再试。'));
        }).finally(function () {
            setBusy(false);
            input.focus();
        });
    }

    function activateVisibilityRules() {
        if (!allowedDevice()) {
            root.remove();
            return;
        }
        var hasRule = false;
        var delay = Math.max(0, Number(config.delaySeconds) || 0);
        var scroll = Math.max(0, Math.min(100, Number(config.scrollPercent) || 0));
        if (delay > 0) {
            hasRule = true;
            window.setTimeout(reveal, delay * 1000);
        }
        if (scroll > 0) {
            hasRule = true;
            var onScroll = function () {
                var documentHeight = Math.max(document.documentElement.scrollHeight, document.body.scrollHeight);
                var viewport = window.innerHeight || document.documentElement.clientHeight || 1;
                var available = Math.max(1, documentHeight - viewport);
                if ((window.scrollY / available) * 100 >= scroll) {
                    reveal();
                    window.removeEventListener('scroll', onScroll);
                }
            };
            window.addEventListener('scroll', onScroll, { passive: true });
            onScroll();
        }
        if (config.exitIntent) {
            hasRule = true;
            document.addEventListener('mouseout', function onExit(event) {
                if (event.relatedTarget || event.clientY > 0) return;
                reveal();
                document.removeEventListener('mouseout', onExit);
            });
        }
        if (!hasRule) reveal();
    }

    if (launcher) {
        launcher.addEventListener('click', function () {
            state.userOpened = true;
            setOpen(!state.open);
        });
    }
    if (close) close.addEventListener('click', function () { setOpen(false); });
    if (teaser) {
        var teaserClose = teaser.querySelector('.acs-teaser-close');
        if (teaserClose) {
            teaserClose.addEventListener('click', function (event) {
                event.stopPropagation();
                dismissTeaser(true);
            });
        }
        teaser.addEventListener('click', function () {
            state.userOpened = true;
            setOpen(true);
        });
    }
    form.addEventListener('submit', function (event) {
        event.preventDefault();
        submit(input.value);
    });
    input.addEventListener('input', resizeInput);
    input.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            submit(input.value);
        }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && state.open) setOpen(false);
    });

    window.AiCustomerService = {
        open: function () { setOpen(true); },
        close: function () { setOpen(false); },
        toggle: function () { setOpen(!state.open); }
    };

    activateVisibilityRules();
}());
