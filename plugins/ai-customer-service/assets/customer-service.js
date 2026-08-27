(function () {
    'use strict';

    var root = document.getElementById('ai-customer-service-widget');
    var configNode = document.getElementById('acs-widget-config');
    if (!root || !configNode) return;

    var config;
    try { config = JSON.parse(configNode.textContent || '{}'); } catch (error) { return; }
    if (!config || !config.endpoint || !config.csrf || !config.conversationId) return;

    var launcher = root.querySelector('.acs-launcher');
    var panel = root.querySelector('.acs-panel');
    var closeBtn = root.querySelector('.acs-close');
    var restartBtn = root.querySelector('.acs-restart');
    var chat = root.querySelector('.acs-chat');
    var quickReplies = root.querySelector('.acs-quick-replies');
    var teaser = root.querySelector('[data-acs-teaser]');
    var ribbon = root.querySelector('[data-acs-ribbon]');
    var form = root.querySelector('.acs-composer');
    var input = root.querySelector('#acs-message');
    var send = root.querySelector('.acs-send');
    var counter = root.querySelector('[data-acs-counter]');
    var picker = root.querySelector('[data-acs-picker]');
    var feedback = root.querySelector('.acs-feedback');
    if (!panel || !chat || !quickReplies || !form || !input || !send || !feedback) return;

    // show_launcher=false 时不渲染浮标，但面板仍然保留，供 window.AiCustomerService 打开。
    var state = {
        open: false, visible: false, greeted: false, busy: false,
        userOpened: false, replied: false, conversationId: config.conversationId
    };
    var AUTO_KEY = 'acs_auto_shown';
    var TEASER_KEY = 'acs_teaser_dismissed';
    var RIBBON_KEY = 'acs_ribbon_dismissed';
    var GREET_KEY = 'acs_greeted';
    var timers = { auto: null, greet: [], teaser: null, ribbon: null };

    /* ---------------------------------------------------------------- 会话级标记 */

    function flag(key) {
        try { return window.sessionStorage.getItem(key); } catch (error) { return null; }
    }
    function setFlag(key, value) {
        try { window.sessionStorage.setItem(key, value); } catch (error) { /* 隐私模式下降级为每次都执行 */ }
    }

    function isMobile() {
        return !!(window.matchMedia && window.matchMedia('(max-width: 640px)').matches);
    }
    function allowedDevice() {
        if (config.deviceMode === 'desktop') return !isMobile();
        if (config.deviceMode === 'mobile') return isMobile();
        return true;
    }

    /* ---------------------------------------------------------------- DOM 小工具 */

    function el(tag, cls, text) {
        var node = document.createElement(tag);
        if (cls) node.className = cls;
        if (text !== undefined && text !== null) node.textContent = String(text);
        return node;
    }
    function icon(name) {
        var i = el('i', 'bi ' + name);
        i.setAttribute('aria-hidden', 'true');
        return i;
    }
    function svgNode(viewBox, d, cls) {
        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', viewBox);
        svg.setAttribute('aria-hidden', 'true');
        if (cls) svg.setAttribute('class', cls);
        var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        path.setAttribute('d', d);
        svg.appendChild(path);
        return svg;
    }

    /* ACS_MARKER_FJS_1 */

    /* ---------------------------------------------------------------- 显隐与自动动作 */

    function clearAttention() {
        if (!launcher) return;
        launcher.classList.remove('has-badge', 'acs-fx--wiggle', 'acs-fx--bounce', 'acs-fx--pulse');
    }
    function dismissTeaser(persist) {
        if (teaser) teaser.hidden = true;
        if (persist) setFlag(TEASER_KEY, '1');
    }
    function cancelAutoOpen() {
        if (timers.auto !== null) { window.clearTimeout(timers.auto); timers.auto = null; }
    }
    function cancelGreetings() {
        timers.greet.forEach(window.clearTimeout);
        timers.greet = [];
    }

    function reveal() {
        if (state.visible) return;
        state.visible = true;
        root.dataset.visible = 'true';
        // once_per_session 的语义：自动展开、提醒动画、角标、飘带、主动问候
        // 这一整组"自动打扰"在同一浏览器会话里只出现一次。
        var allowAuto = !(config.oncePerSession && flag(AUTO_KEY));
        if (allowAuto) {
            setFlag(AUTO_KEY, '1');
        } else {
            clearAttention();
            cancelAutoOpen();
        }
        scheduleTeaser();
        if (allowAuto) {
            scheduleAutoOpen();
            scheduleGreetings();
        }
    }

    function setOpen(open) {
        if (!state.visible && open) reveal();
        state.open = !!open;
        panel.hidden = !state.open;
        panel.setAttribute('aria-hidden', state.open ? 'false' : 'true');
        if (launcher) {
            launcher.setAttribute('aria-expanded', state.open ? 'true' : 'false');
            launcher.classList.toggle('is-open', state.open);
        }
        cancelAutoOpen();
        if (!state.open) return;
        clearAttention();
        dismissTeaser(false);
        showRibbon();
        ensureGreeting();
        window.setTimeout(function () { input.focus(); }, 0);
    }

    function scheduleAutoOpen() {
        if (timers.auto !== null || !config.initialOpen) return;
        var delay = Math.max(0, Number(config.initialOpenDelay) || 0);
        timers.auto = window.setTimeout(function () {
            timers.auto = null;
            if (!state.userOpened) setOpen(true);
        }, delay * 1000);
    }

    function scheduleTeaser() {
        if (!teaser || !config.teaserEnabled) return;
        teaser.hidden = true;
        if (flag(TEASER_KEY)) return;
        timers.teaser = window.setTimeout(function () {
            if (!state.open) teaser.hidden = false;
        }, 700);
    }

    function showRibbon() {
        if (!ribbon || !config.ribbonEnabled || flag(RIBBON_KEY)) return;
        timers.ribbon = window.setTimeout(function () { ribbon.hidden = false; }, 400);
    }

    /* 定时主动问候：窗口出现后按秒数依次投递；访客一开口就全部取消。 */
    function scheduleGreetings() {
        var greeting = config.greeting;
        if (!greeting || !greeting.enabled || !Array.isArray(greeting.steps) || !greeting.steps.length) return;
        if (greeting.once_per_session && flag(GREET_KEY)) return;
        greeting.steps.forEach(function (step) {
            var after = Math.max(3, Number(step.after) || 20);
            var text = String(step.text || '').trim();
            if (text === '') return;
            timers.greet.push(window.setTimeout(function () {
                if (greeting.stop_after_reply && state.replied) return;
                setFlag(GREET_KEY, '1');
                if (!state.open) {
                    // 窗口还没开：用角标 + 引流气泡把人叫过来，不强行弹窗
                    if (launcher && config.badgeEnabled) launcher.classList.add('has-badge');
                    if (teaser && config.teaserEnabled && !flag(TEASER_KEY)) {
                        var textNode = teaser.querySelector('.acs-teaser-text');
                        if (textNode) textNode.textContent = text;
                        teaser.hidden = false;
                    }
                    return;
                }
                ensureGreeting();
                appendMessage('assistant', text);
            }, after * 1000));
        });
    }

    /* ACS_MARKER_FJS_2 */

    /* ---------------------------------------------------------------- 消息渲染 */

    function avatarNode() {
        var wrap = el('span', 'acs-message-avatar');
        wrap.setAttribute('aria-hidden', 'true');
        if (config.avatarUrl) {
            var img = el('img');
            img.src = config.avatarUrl;
            img.alt = '';
            wrap.appendChild(img);
        } else {
            wrap.appendChild(icon('bi-stars'));
        }
        return wrap;
    }

    /** 一条消息 = 头像 + 一个可以继续往里塞 chip / 气泡 / 卡片的 stack。 */
    function messageRow(role) {
        var row = el('div', 'acs-message acs-message--' + role);
        if (role === 'assistant' && config.showAvatar) row.appendChild(avatarNode());
        var stack = el('div', 'acs-message-stack');
        row.appendChild(stack);
        chat.appendChild(row);
        return { row: row, stack: stack };
    }

    function scrollToEnd() {
        chat.scrollTop = chat.scrollHeight;
    }

    function appendMessage(role, content) {
        var entry = messageRow(role);
        // 一律 textContent：模型输出与站内标题都当不可信文本，不解释任何 HTML。
        entry.stack.appendChild(el('div', 'acs-message-bubble', content));
        scrollToEnd();
        return entry;
    }

    function appendTyping() {
        var entry = messageRow('assistant');
        var kind = ['dots', 'wave', 'text'].indexOf(config.typing) !== -1 ? config.typing : 'dots';
        var bubble = el('div', 'acs-message-bubble acs-typing acs-typing--' + kind);
        if (kind === 'text') {
            bubble.appendChild(el('span', '', '正在查资料…'));
        } else {
            for (var i = 0; i < 3; i++) bubble.appendChild(el('i'));
        }
        bubble.setAttribute('aria-label', '客服正在输入');
        entry.stack.appendChild(bubble);
        scrollToEnd();
        return entry;
    }

    function appendToolChip(stack, label) {
        var chip = el('div', 'acs-toolchip');
        chip.appendChild(icon('bi-lightning-charge-fill'));
        chip.appendChild(el('span', '', label));
        stack.appendChild(chip);
    }

    function ensureGreeting() {
        if (state.greeted) return;
        state.greeted = true;
        if (config.welcomeMessage) appendMessage('assistant', config.welcomeMessage);
        renderQuickReplies();
    }

    function renderQuickReplies() {
        quickReplies.innerHTML = '';
        var list = Array.isArray(config.quickReplies) ? config.quickReplies : [];
        if (!list.length) return;
        if (config.quickRepliesTitle) {
            quickReplies.appendChild(el('span', 'acs-quick-title', String(config.quickRepliesTitle).slice(0, 60)));
        }
        var row = el('div', 'acs-quick-row');
        list.forEach(function (question) {
            if (typeof question !== 'string' || question.trim() === '') return;
            var button = el('button', 'acs-quick-reply', question);
            button.type = 'button';
            button.addEventListener('click', function () { submit(question); });
            row.appendChild(button);
        });
        quickReplies.appendChild(row);
    }

    /* ACS_MARKER_FJS_3 */

    /* ---------------------------------------------------------------- 卡片 */

    var CARD_RENDERERS = {
        content: renderContentCard,
        inquiry: renderInquiryCard,
        handoff: renderHandoffCard,
        owner: renderOwnerCard,
        social: renderSocialCard
    };

    function renderCards(stack, cards) {
        if (!Array.isArray(cards)) return;
        cards.forEach(function (card) {
            if (!card || typeof card !== 'object') return;
            var renderer = CARD_RENDERERS[card.type];
            if (!renderer) return;
            try {
                var node = renderer(card);
                if (node) stack.appendChild(node);
            } catch (error) { /* 单张卡片渲染失败不影响这条回复 */ }
        });
        scrollToEnd();
    }

    function renderContentCard(card) {
        if (!Array.isArray(card.items) || !card.items.length) return null;
        var wrap = el('div', 'acs-card');
        var list = el('div', 'acs-card-items is-' + (card.preset || 'stack'));
        card.items.forEach(function (item) {
            var node = item.url ? el('a', 'acs-item') : el('div', 'acs-item');
            if (item.url) {
                node.href = item.url;
                node.target = '_blank';
                node.rel = 'noopener';
            }
            var thumb = el('span', 'acs-item-thumb');
            if (item.cover) {
                var img = el('img');
                img.src = item.cover;
                img.alt = '';
                img.loading = 'lazy';
                thumb.appendChild(img);
            } else {
                thumb.appendChild(icon(card.kind === 'product' ? 'bi-box-seam' : 'bi-journal-text'));
            }
            node.appendChild(thumb);

            var main = el('span', 'acs-item-main');
            main.appendChild(el('span', 'acs-item-title', item.title));
            if (item.summary) main.appendChild(el('span', 'acs-item-summary', item.summary));
            var foot = el('span', 'acs-item-foot');
            if (item.price) foot.appendChild(el('span', 'acs-item-price', item.price));
            if (item.badge) foot.appendChild(el('span', 'acs-item-badge', item.badge));
            if (item.url) foot.appendChild(el('span', 'acs-item-cta', (card.cta || '查看详情') + ' ›'));
            main.appendChild(foot);
            node.appendChild(main);
            list.appendChild(node);
        });
        wrap.appendChild(list);
        return wrap;
    }

    function renderHandoffCard(card) {
        var wrap = el('div', 'acs-form-card');
        var title = el('div', 'acs-form-card-title');
        title.appendChild(icon('bi-person-workspace'));
        title.appendChild(el('span', '', card.title || '转人工客服'));
        wrap.appendChild(title);
        if (card.note) wrap.appendChild(el('div', 'acs-form-card-note', card.note));
        if (card.url) {
            var link = el('a', 'acs-btn', card.label || '联系人工客服');
            link.href = card.url;
            link.target = '_blank';
            link.rel = 'noopener';
            wrap.appendChild(link);
        } else if (card.fallback) {
            wrap.appendChild(el('div', 'acs-form-card-note', card.fallback));
        }
        return wrap;
    }

    function renderSocialCard(card) {
        var items = Array.isArray(card.socials) ? card.socials : [];
        if (!items.length) return null;
        var wrap = el('div', 'acs-card');
        var title = el('div', 'acs-form-card-title');
        title.appendChild(icon('bi-share-fill'));
        title.appendChild(el('span', '', card.title || '联系方式'));
        wrap.appendChild(title);
        wrap.appendChild(socialList(items, card.preset));
        return wrap;
    }

    function socialList(items, preset) {
        var list = el('div', 'acs-social is-' + (preset || 'chips'));
        items.forEach(function (item) { list.appendChild(socialItem(item)); });
        return list;
    }

    /** 微信号这类不是链接，渲染成"点击复制"；其余走 <a>。 */
    function socialItem(item) {
        if (item.mode === 'copy') {
            var button = el('button', 'acs-social-item');
            button.type = 'button';
            button.title = item.value;
            button.appendChild(icon(item.icon || 'bi-link-45deg'));
            var label = el('span', '', item.label || item.value);
            button.appendChild(label);
            button.addEventListener('click', function () {
                var done = function () {
                    label.textContent = '已复制：' + item.value;
                    button.classList.add('acs-social-copied');
                    window.setTimeout(function () {
                        label.textContent = item.label || item.value;
                        button.classList.remove('acs-social-copied');
                    }, 2400);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(item.value).then(done, function () { label.textContent = item.value; });
                } else {
                    label.textContent = item.value;
                }
            });
            return button;
        }
        var link = el('a', 'acs-social-item');
        link.href = item.href || item.value;
        if (item.mode === 'link') { link.target = '_blank'; link.rel = 'noopener'; }
        link.appendChild(icon(item.icon || 'bi-link-45deg'));
        link.appendChild(el('span', '', item.label || item.value));
        return link;
    }

    /* ACS_MARKER_FJS_4 */

    var DOODLES = {
        star: ['0 0 24 24', 'M12 2L15 9L22 10L17 15L18.5 22L12 18.5L5.5 22L7 15L2 10L9 9L12 2Z'],
        spark: ['0 0 24 24', 'M12 0C12 6.6 17.4 12 24 12C17.4 12 12 17.4 12 24C12 17.4 6.6 12 0 12C6.6 12 12 6.6 12 0Z'],
        swirl: ['0 0 100 100', 'M50 10C27.9 10 10 27.9 10 50C10 72.1 27.9 90 50 90C72.1 90 90 72.1 90 50C90 32.3 75.7 18 58 18C44.3 18 33 29.3 33 43C33 53.5 41.5 62 52 62C59.7 62 66 55.7 66 48']
    };

    function renderOwnerCard(card) {
        var preset = card.preset || 'clean';
        var wrap = el('div', 'acs-owner is-' + preset);

        if (preset === 'doodle') {
            Object.keys(DOODLES).forEach(function (name) {
                var svg = svgNode(DOODLES[name][0], DOODLES[name][1], 'acs-owner-doodle acs-owner-doodle--' + name);
                wrap.appendChild(svg);
            });
        }

        var top = el('div', 'acs-owner-top');
        var photo = el('span', 'acs-owner-photo');
        if (card.avatar) {
            var img = el('img');
            img.src = card.avatar;
            img.alt = '';
            img.loading = 'lazy';
            photo.appendChild(img);
        } else {
            photo.appendChild(icon('bi-person-fill'));
        }
        top.appendChild(photo);
        var copy = el('div');
        if (card.name) copy.appendChild(el('div', 'acs-owner-name', card.name));
        if (card.title) copy.appendChild(el('div', 'acs-owner-title', card.title));
        top.appendChild(copy);
        wrap.appendChild(top);

        if (card.bio) wrap.appendChild(el('div', 'acs-owner-bio', card.bio));
        if (Array.isArray(card.socials) && card.socials.length) {
            wrap.appendChild(socialList(card.socials, preset === 'doodle' ? 'chips' : 'chips'));
        }
        return wrap;
    }

    /* 询盘卡：表单由访客自己填自己提交，模型只负责把它拉出来。 */
    function renderInquiryCard(card) {
        var wrap = el('form', 'acs-form-card' + (card.preset === 'compact' ? ' is-compact' : ''));
        wrap.noValidate = true;
        var title = el('div', 'acs-form-card-title');
        title.appendChild(icon('bi-receipt'));
        title.appendChild(el('span', '', card.title || '留个联系方式'));
        wrap.appendChild(title);
        if (card.note) wrap.appendChild(el('div', 'acs-form-card-note', card.note));

        if (card.preset === 'link') {
            var link = el('a', 'acs-btn', card.handoffLabel || '前往询盘表单');
            link.href = card.handoffUrl || '#';
            if (card.handoffUrl) { link.target = '_blank'; link.rel = 'noopener'; }
            wrap.appendChild(link);
            return wrap;
        }

        var grid = el('div', 'acs-form-grid');
        var inputs = {};
        (Array.isArray(card.fields) ? card.fields : []).forEach(function (field) {
            var box = el('label', 'acs-field' + (field.type === 'textarea' ? ' is-wide' : ''));
            box.appendChild(el('span', '', field.label + (field.required ? ' *' : '')));
            var control = field.type === 'textarea' ? el('textarea') : el('input');
            if (field.type !== 'textarea') control.type = field.type === 'email' ? 'email' : (field.type === 'tel' ? 'tel' : 'text');
            control.maxLength = Number(field.max) || 200;
            control.name = field.name;
            if (field.required) control.required = true;
            if (field.type === 'email') control.autocomplete = 'email';
            if (field.type === 'tel') control.autocomplete = 'tel';
            if (field.name === 'name') control.autocomplete = 'name';
            box.appendChild(control);
            grid.appendChild(box);
            inputs[field.name] = { box: box, control: control, required: !!field.required };
        });
        wrap.appendChild(grid);

        var actions = el('div', 'acs-form-actions');
        var submitBtn = el('button', 'acs-btn', card.submit || '提交询盘');
        submitBtn.type = 'submit';
        actions.appendChild(submitBtn);
        var status = el('span', 'acs-form-status');
        actions.appendChild(status);
        wrap.appendChild(actions);

        wrap.addEventListener('submit', function (event) {
            event.preventDefault();
            var body = new URLSearchParams();
            body.set('_csrf', config.csrf);
            body.set('conversation_id', state.conversationId);
            body.set('action', 'inquiry');
            body.set('page_url', window.location.href.slice(0, 500));
            body.set('page_title', String(document.title || '').slice(0, 255));

            var invalid = false;
            Object.keys(inputs).forEach(function (name) {
                var entry = inputs[name];
                var value = String(entry.control.value || '').trim();
                entry.box.classList.toggle('has-error', entry.required && value === '');
                if (entry.required && value === '') invalid = true;
                body.set('field_' + name, value);
            });
            if (invalid) {
                status.textContent = '请把带 * 的字段填一下';
                status.className = 'acs-form-status is-error';
                return;
            }

            submitBtn.disabled = true;
            status.textContent = '正在提交…';
            status.className = 'acs-form-status';
            fetch(config.actionEndpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: body
            }).then(function (response) {
                return response.json().catch(function () { return {}; }).then(function (data) {
                    if (!response.ok || !data.ok) throw new Error((data && data.error) || '提交失败，请稍后再试');
                    return data.data || {};
                });
            }).then(function (data) {
                grid.remove();
                actions.remove();
                var done = el('div', 'acs-form-status is-done', data.message || card.success || '已收到，我们会尽快联系您。');
                wrap.appendChild(done);
            }).catch(function (error) {
                status.textContent = error.message;
                status.className = 'acs-form-status is-error';
                submitBtn.disabled = false;
            });
        });
        return wrap;
    }

    /* ACS_MARKER_FJS_5 */

    /* ---------------------------------------------------------------- 输入与发送 */

    function resizeInput() {
        input.style.height = 'auto';
        input.style.height = Math.min(108, Math.max(24, input.scrollHeight)) + 'px';
    }
    function renderCounter() {
        if (!counter) return;
        var length = input.value.length;
        var max = Number(config.maxChars) || 2000;
        counter.textContent = length + '/' + max;
        counter.classList.toggle('is-near', length > max * 0.9 && length <= max);
        counter.classList.toggle('is-over', length > max);
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
        body.set('conversation_id', state.conversationId);
        body.set('message', message);
        return fetch(config.endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: body
        }).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (data) {
                if (!response.ok || !data.ok) {
                    var error = new Error((data && (data.error || (data.data && data.data.message)))
                        || config.unavailableMessage || '暂时无法回复，请稍后再试。');
                    error.payload = (data && data.data) || {};
                    throw error;
                }
                return data.data || {};
            });
        });
    }

    var EVENT_LABEL = {
        inquiry: '识别到采购意图 · 已准备询盘表单',
        handoff: '识别到转人工请求',
        social: '已取出联系方式',
        owner: '已取出负责人名片'
    };

    function submit(rawMessage) {
        var message = String(rawMessage || '').trim();
        var max = Number(config.maxChars) || 2000;
        if (state.busy || message === '') return;
        if (message.length > max) {
            showFeedback('消息不能超过 ' + max + ' 个字符');
            return;
        }
        state.replied = true;
        cancelGreetings();
        showFeedback('');
        ensureGreeting();
        appendMessage('visitor', message);
        input.value = '';
        resizeInput();
        renderCounter();
        closePicker();
        setBusy(true);

        var typing = appendTyping();
        request(message).then(function (data) {
            typing.row.remove();
            var entry = messageRow('assistant');
            if (data.event && EVENT_LABEL[data.event]) appendToolChip(entry.stack, EVENT_LABEL[data.event]);
            if (Array.isArray(data.cards) && data.cards.length) {
                appendToolChip(entry.stack, '已查站内数据 · ' + data.cards.length + ' 张卡片');
            }
            entry.stack.appendChild(el('div', 'acs-message-bubble', String(data.reply || config.unavailableMessage || '')));
            renderCards(entry.stack, data.cards);
            scrollToEnd();
        }).catch(function (error) {
            typing.row.remove();
            showFeedback(error.message);
            // 后端在失败时也可能带回一张卡片（比如已经识别到转人工），别丢掉
            if (error.payload && Array.isArray(error.payload.cards) && error.payload.cards.length) {
                var entry = messageRow('assistant');
                renderCards(entry.stack, error.payload.cards);
            }
        }).finally(function () {
            setBusy(false);
            input.focus();
        });
    }

    /* ---------------------------------------------------------------- 表情面板 */

    function closePicker() {
        if (!picker) return;
        picker.hidden = true;
        picker.innerHTML = '';
        root.querySelectorAll('[data-acs-pick]').forEach(function (button) { button.classList.remove('is-active'); });
    }

    function openPicker(kind, trigger) {
        if (!picker) return;
        if (!picker.hidden && picker.dataset.kind === kind) { closePicker(); return; }
        closePicker();
        picker.dataset.kind = kind;
        picker.hidden = false;
        trigger.classList.add('is-active');

        function insert(text) {
            var start = input.selectionStart == null ? input.value.length : input.selectionStart;
            var end = input.selectionEnd == null ? start : input.selectionEnd;
            input.value = input.value.slice(0, start) + text + input.value.slice(end);
            var caret = start + text.length;
            input.setSelectionRange(caret, caret);
            input.focus();
            resizeInput();
            renderCounter();
        }

        if (kind === 'emoji') {
            var grid = el('div', 'acs-picker-grid');
            (Array.isArray(config.emoji) ? config.emoji : []).forEach(function (item) {
                var button = el('button', '', item);
                button.type = 'button';
                button.setAttribute('aria-label', '插入 ' + item);
                button.addEventListener('click', function () { insert(item); });
                grid.appendChild(button);
            });
            picker.appendChild(grid);
            return;
        }

        (Array.isArray(config.stickerPacks) ? config.stickerPacks : []).forEach(function (pack) {
            picker.appendChild(el('div', 'acs-picker-group-title', pack.name || '表情包'));
            var grid = el('div', 'acs-picker-grid is-stickers');
            (Array.isArray(pack.items) ? pack.items : []).forEach(function (item) {
                var button = el('button');
                button.type = 'button';
                button.setAttribute('aria-label', item.label || '表情');
                var img = el('img');
                img.src = item.url;
                img.alt = item.label || '';
                img.loading = 'lazy';
                button.appendChild(img);
                // 表情包按"发一条只有这张图的消息"处理会牵扯图片消息协议，
                // 这一版走更朴素的路：把标签插进输入框，由访客决定要不要发。
                button.addEventListener('click', function () { insert(':' + (item.label || '表情') + ':'); });
                grid.appendChild(button);
            });
            picker.appendChild(grid);
        });
    }

    /* ACS_MARKER_FJS_6 */

    /* ---------------------------------------------------------------- 触发规则与接线 */

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
                var docHeight = Math.max(document.documentElement.scrollHeight, document.body.scrollHeight);
                var viewport = window.innerHeight || document.documentElement.clientHeight || 1;
                var available = Math.max(1, docHeight - viewport);
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
    if (closeBtn) closeBtn.addEventListener('click', function () { setOpen(false); });
    if (restartBtn) {
        restartBtn.addEventListener('click', function () {
            if (state.busy) return;
            var body = new URLSearchParams();
            body.set('_csrf', config.csrf);
            body.set('conversation_id', state.conversationId);
            body.set('action', 'reset');
            restartBtn.disabled = true;
            fetch(config.actionEndpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: body
            }).then(function (response) {
                return response.json().catch(function () { return {}; });
            }).then(function (data) {
                if (data && data.ok && data.data && data.data.conversation_id) {
                    state.conversationId = data.data.conversation_id;
                }
                chat.innerHTML = '';
                quickReplies.innerHTML = '';
                state.greeted = false;
                state.replied = false;
                showFeedback('');
                closePicker();
                ensureGreeting();
            }).finally(function () { restartBtn.disabled = false; });
        });
    }
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
    if (ribbon) {
        var ribbonClose = ribbon.querySelector('.acs-ribbon-close');
        if (ribbonClose) {
            ribbonClose.addEventListener('click', function () {
                ribbon.hidden = true;
                setFlag(RIBBON_KEY, '1');
            });
        }
    }
    root.querySelectorAll('[data-acs-pick]').forEach(function (button) {
        button.addEventListener('click', function () {
            openPicker(button.getAttribute('data-acs-pick'), button);
        });
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        submit(input.value);
    });
    input.addEventListener('input', function () {
        resizeInput();
        renderCounter();
    });
    input.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' || event.shiftKey || event.isComposing) return;
        // send_on_enter 关掉时回车就是换行，必须点发送
        if (config.sendOnEnter === false) return;
        event.preventDefault();
        submit(input.value);
    });
    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        if (picker && !picker.hidden) { closePicker(); return; }
        if (state.open) setOpen(false);
    });

    window.AiCustomerService = {
        open: function () { state.userOpened = true; setOpen(true); },
        close: function () { setOpen(false); },
        toggle: function () { state.userOpened = true; setOpen(!state.open); },
        ask: function (text) {
            state.userOpened = true;
            setOpen(true);
            submit(String(text || ''));
        },
        version: '1.2.0'
    };

    renderCounter();
    activateVisibilityRules();
}());
