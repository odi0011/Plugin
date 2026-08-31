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
    var channels = root.querySelector('[data-acs-channels]');
    var channelsToggle = root.querySelector('[data-acs-channels-toggle]');
    if (!panel || !chat || !quickReplies || !form || !input || !send || !feedback) return;

    // 体验增强开关。缺字段一律当关，老版本的注入配置也不会炸。
    var exp = (config.experience && typeof config.experience === 'object') ? config.experience : {};

    // show_launcher=false 时不渲染浮标，但面板仍然保留，供 window.AiCustomerService 打开。
    var state = {
        open: false, visible: false, greeted: false, busy: false,
        userOpened: false, replied: false, conversationId: config.conversationId,
        // dead: 设备不匹配时整个节点已被移除，公开 API 不能再往一棵脱离文档的树上操作
        dead: false, nearBottom: true, unread: 0, lastFocus: null,
        restored: false, restoring: false, opened: false
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

    /* ---------------------------------------------------------------- 转化事件与未读提醒 */

    /**
     * 转化事件只投给站点已经装好的埋点：gtag（GA4）与 dataLayer（GTM）。
     * 插件自己不加载任何统计脚本、也不往第三方发一个字节，所以关掉开关就是彻底没有。
     */
    function track(name, params) {
        if (!exp.analytics) return;
        var payload = params || {};
        try {
            if (typeof window.gtag === 'function') window.gtag('event', name, payload);
            if (window.dataLayer && typeof window.dataLayer.push === 'function') {
                var entry = { event: name };
                Object.keys(payload).forEach(function (key) { entry[key] = payload[key]; });
                window.dataLayer.push(entry);
            }
        } catch (error) { /* 埋点失败不能影响会话 */ }
    }

    var originalTitle = document.title;

    function bumpTitle() {
        if (!exp.unread_title || state.unread < 1) return;
        document.title = '(' + state.unread + ') ' + originalTitle;
    }
    function restoreTitle() {
        state.unread = 0;
        if (document.title !== originalTitle) document.title = originalTitle;
    }

    /** 提示音用 WebAudio 现场合成，不为一声"叮"多下载一个音频文件。 */
    function beep() {
        if (!exp.sound) return;
        try {
            var Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return;
            var ctx = new Ctx();
            var osc = ctx.createOscillator();
            var gain = ctx.createGain();
            var now = ctx.currentTime;
            osc.type = 'sine';
            osc.frequency.value = 880;
            osc.connect(gain);
            gain.connect(ctx.destination);
            gain.gain.setValueAtTime(0.0001, now);
            gain.gain.exponentialRampToValueAtTime(0.07, now + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.24);
            osc.start(now);
            osc.stop(now + 0.26);
            osc.onended = function () { try { ctx.close(); } catch (error) { /* 已经关了 */ } };
        } catch (error) { /* 自动播放被拦或没有 AudioContext：静音降级 */ }
    }

    /** 回复到达时的提醒：响一声，并在"访客没在看"时加角标 + 标签页计数。 */
    function notifyReply() {
        beep();
        if (state.open && !document.hidden) return;
        state.unread += 1;
        if (launcher) launcher.classList.add('has-badge');
        bumpTitle();
    }

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
        if (state.dead) return;
        if (!state.visible && open) reveal();
        state.open = !!open;
        panel.hidden = !state.open;
        panel.setAttribute('aria-hidden', state.open ? 'false' : 'true');
        // 供 CSS 用：打开时要收起引流气泡与悬停提示，否则它们和浮标叠在一起
        root.dataset.acsOpen = state.open ? 'true' : 'false';
        if (launcher) {
            launcher.setAttribute('aria-expanded', state.open ? 'true' : 'false');
            launcher.classList.toggle('is-open', state.open);
        }
        cancelAutoOpen();
        setChannels(false);
        if (!state.open) {
            // 关闭后把焦点还回打开它的那个控件，键盘用户不会掉到文档开头
            var last = state.lastFocus;
            state.lastFocus = null;
            if (last && typeof last.focus === 'function' && document.contains(last)) last.focus();
            return;
        }
        state.lastFocus = document.activeElement;
        clearAttention();
        restoreTitle();
        dismissTeaser(false);
        showRibbon();
        maybeRestore();
        ensureGreeting();
        if (!state.opened) {
            state.opened = true;
            track('acs_open', {});
        }
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

    /* 只有"本来就贴着底"时才跟着滚。访客往上翻旧消息时把视图拽回去是最恼人的一种打断。 */
    var NEAR_BOTTOM_PX = 72;

    function atBottom() {
        return chat.scrollHeight - chat.scrollTop - chat.clientHeight <= NEAR_BOTTOM_PX;
    }
    function scrollToEnd(force) {
        if (force || state.nearBottom) {
            chat.scrollTop = chat.scrollHeight;
            state.nearBottom = true;
        }
    }

    function timeLabel(at) {
        var date = at ? new Date(Number(at) * 1000) : new Date();
        if (isNaN(date.getTime())) date = new Date();
        var h = date.getHours();
        var m = date.getMinutes();
        return (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m;
    }
    function stampTime(stack, at) {
        if (!exp.timestamps) return;
        stack.appendChild(el('div', 'acs-message-time', timeLabel(at)));
    }

    /* 正文里的网址变成可点链接。文本先经 el() 用 textContent 落地，这里只是把文本节点
     * 切开再包一层 <a> —— 全程不碰 innerHTML，模型输出永远不当 HTML 解释。 */
    var URL_RE = /https?:\/\/[^\s<>"'（）()【】]+/g;

    function linkify(bubble, content) {
        var text = String(content == null ? '' : content);
        URL_RE.lastIndex = 0;
        if (!URL_RE.test(text)) return;
        URL_RE.lastIndex = 0;
        var frag = document.createDocumentFragment();
        var last = 0;
        var match;
        while ((match = URL_RE.exec(text)) !== null) {
            var url = match[0].replace(/[.,;:!?，。！？、]+$/, '');
            if (url === '') continue;
            if (match.index > last) frag.appendChild(document.createTextNode(text.slice(last, match.index)));
            var link = el('a', 'acs-link', url);
            link.href = url;
            link.target = '_blank';
            link.rel = 'noopener nofollow ugc';
            frag.appendChild(link);
            last = match.index + url.length;
        }
        if (last < text.length) frag.appendChild(document.createTextNode(text.slice(last)));
        bubble.textContent = '';
        bubble.appendChild(frag);
    }

    function appendMessage(role, content, at) {
        var entry = messageRow(role);
        // 一律 textContent：模型输出与站内标题都当不可信文本，不解释任何 HTML。
        var bubble = el('div', 'acs-message-bubble', content);
        entry.stack.appendChild(bubble);
        linkify(bubble, content);
        stampTime(entry.stack, at);
        scrollToEnd(role === 'visitor');
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
        // 正在取回历史记录时先别铺欢迎语，否则等接口回来会出现两遍开场
        if (state.greeted || state.restoring) return;
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
        if ((card.preset || 'stack') === 'stack') return renderStackCard(card);
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

    /* 叠卡预设：结构与站长给的原始设计稿一致（.acs-cards > .acs-card-stack.acs-one|two|three
     * > .acs-cardDetails > header + button）。原文只定义三个位置，所以最多取三条。 */
    function renderStackCard(card) {
        var slots = ['acs-one', 'acs-two', 'acs-three'];
        var items = card.items.slice(0, 3);
        var stage = el('div', 'acs-stack-stage');
        var cards = el('div', 'acs-cards');
        items.forEach(function (item, index) {
            var node = item.url ? el('a', '') : el('div', '');
            node.className = 'acs-card-stack ' + slots[index];
            if (item.url) {
                node.href = item.url;
                node.target = '_blank';
                node.rel = 'noopener';
            }
            var details = el('div', 'acs-cardDetails');
            var head = el('div', 'acs-cardDetailsHaeder', item.title);
            if (item.price) head.appendChild(el('div', 'acs-stack-price', item.price));
            details.appendChild(head);
            details.appendChild(el('div', 'acs-cardDetailsButton', card.cta || '查看详情'));
            node.appendChild(details);
            cards.appendChild(node);
        });
        stage.appendChild(cards);
        return stage;
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
            link.addEventListener('click', function () { track('acs_handoff_click', {}); });
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
        return preset === 'doodle' ? renderDoodleCard(card) : renderCleanOwnerCard(card, preset);
    }

    /* 手账涂鸦名片：DOM 结构对齐站长给的原始设计稿（doodle 三件套 → 头像 → 标题+头衔
     * → 简介 → hover 才展开的社媒按钮），类名与 assets/preset-cards.css 里的照搬样式一致。 */
    function renderDoodleCard(card) {
        var stage = el('div', 'acs-doodle-stage');
        var wrap = el('div', 'acs-doodle-card');

        [['star', 'acs-star'], ['spark', 'acs-sparkle'], ['swirl', 'acs-swirl']].forEach(function (pair) {
            wrap.appendChild(svgNode(DOODLES[pair[0]][0], DOODLES[pair[0]][1], 'acs-doodle ' + pair[1]));
        });

        var photo = el('div', 'acs-doodle-photo');
        if (card.avatar) {
            photo.classList.add('has-image');
            var img = el('img');
            img.src = card.avatar;
            img.alt = '';
            img.loading = 'lazy';
            photo.appendChild(img);
        }
        wrap.appendChild(photo);

        // 原文是 NAME<br><span>ROLE</span>，保持同形以复用那条 .acs-doodle-title span 样式
        var title = el('div', 'acs-doodle-title', card.name || '');
        if (card.title) {
            title.appendChild(document.createElement('br'));
            title.appendChild(el('span', '', card.title));
        }
        wrap.appendChild(title);

        if (card.bio) wrap.appendChild(el('p', 'acs-doodle-bio', card.bio));

        var socials = Array.isArray(card.socials) ? card.socials : [];
        if (socials.length) {
            var row = el('div', 'acs-doodle-socials');
            socials.slice(0, 5).forEach(function (item) { row.appendChild(doodleSocial(item)); });
            wrap.appendChild(row);
        }
        stage.appendChild(wrap);
        return stage;
    }

    /** 名片上的社媒按钮：微信/电话仍然是"点一下复制"，其余是链接。 */
    function doodleSocial(item) {
        var isCopy = item.mode === 'copy';
        var node = isCopy ? el('button', '') : el('a', '');
        node.className = 'acs-doodle-btn acs-net-' + (item.network || 'website');
        node.title = (item.label || '') + ' ' + item.value;
        node.setAttribute('aria-label', (item.label || item.network || '联系方式') + '：' + item.value);
        node.appendChild(icon(item.icon || 'bi-link-45deg'));
        if (isCopy) {
            node.type = 'button';
            node.addEventListener('click', function () { copyValue(item.value, node); });
        } else {
            node.href = item.href || item.value;
            if (item.mode === 'link') { node.target = '_blank'; node.rel = 'noopener'; }
        }
        return node;
    }

    function copyValue(value, node) {
        var restore = node.getAttribute('title');
        var done = function () {
            node.classList.add('acs-social-copied');
            node.setAttribute('title', '已复制：' + value);
            window.setTimeout(function () {
                node.classList.remove('acs-social-copied');
                node.setAttribute('title', restore);
            }, 2400);
        };
        // 复制可能被拒（非 HTTPS、无用户手势、权限策略）。之前失败也走 done()，
        // 于是明明没复制上还提示"已复制"，访客粘出来是上一次的剪贴板内容。
        var fail = function () {
            node.setAttribute('title', value);
            window.setTimeout(function () { node.setAttribute('title', restore); }, 4000);
            showFeedback('浏览器不允许自动复制，请手动复制：' + value);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(value).then(done, fail);
        } else {
            fail();
        }
    }

    function renderCleanOwnerCard(card, preset) {
        var wrap = el('div', 'acs-owner is-' + preset);
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
            wrap.appendChild(socialList(card.socials, 'chips'));
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
                track('acs_inquiry_submitted', {});
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
        // 刻意不 disable 输入框：等回复时访客往往正在把下一句打完，禁用会清掉输入法候选、
        // 还会把焦点弹到页面开头。挡住重复提交只需要 disable 发送按钮（submit() 也有 busy 闸）。
        input.setAttribute('aria-busy', busy ? 'true' : 'false');
        send.disabled = busy;
        quickReplies.querySelectorAll('button').forEach(function (button) { button.disabled = busy; });
    }
    function showFeedback(message) {
        feedback.textContent = message || '';
    }

    /* 25 秒还没回来就当超时。模型侧最长 30 秒（CUSTOM_TIMEOUT），但浏览器这边如果不设
     * 上限，遇到连接被中间设备挂住的情况会永远停在"正在输入"，访客只能刷新页面。 */
    var TIMEOUT_MS = 25000;

    function request(message) {
        var body = new URLSearchParams();
        body.set('_csrf', config.csrf);
        body.set('conversation_id', state.conversationId);
        body.set('message', message);
        var controller = window.AbortController ? new window.AbortController() : null;
        var timer = null;
        var options = {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: body
        };
        if (controller) {
            options.signal = controller.signal;
            timer = window.setTimeout(function () { controller.abort(); }, TIMEOUT_MS);
        }
        return fetch(config.endpoint, options).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (data) {
                if (!response.ok || !data.ok) {
                    var error = new Error((data && (data.error || (data.data && data.data.message)))
                        || config.unavailableMessage || '暂时无法回复，请稍后再试。');
                    error.payload = (data && data.data) || {};
                    throw error;
                }
                return data.data || {};
            });
        }, function (error) {
            // 这一支只会是 fetch 本身失败：网络断了，或上面那个计时器 abort 了
            throw new Error(error && error.name === 'AbortError'
                ? '等待回复超时，消息还在输入框里，可以再发一次。'
                : '网络似乎断开了，请检查连接后重发。');
        }).finally(function () {
            if (timer !== null) window.clearTimeout(timer);
        });
    }

    var EVENT_LABEL = {
        inquiry: '识别到采购意图 · 已准备询盘表单',
        handoff: '识别到转人工请求',
        social: '已取出联系方式',
        owner: '已取出负责人名片'
    };

    function submit(rawMessage) {
        if (state.dead) return;
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
        var mine = appendMessage('visitor', message);
        // 只有这条消息就是输入框里的内容时才清空。点快捷提问不该顺手删掉访客已经打了一半的话。
        var typed = input.value;
        var fromInput = typed.trim() === message;
        if (fromInput) {
            input.value = '';
            resizeInput();
            renderCounter();
        }
        closePicker();
        setBusy(true);
        track('acs_message_sent', { length: message.length });

        var typing = appendTyping();
        request(message).then(function (data) {
            typing.row.remove();
            var reply = String(data.reply || config.unavailableMessage || '');
            var entry = messageRow('assistant');
            if (data.event && EVENT_LABEL[data.event]) appendToolChip(entry.stack, EVENT_LABEL[data.event]);
            if (Array.isArray(data.cards) && data.cards.length) {
                appendToolChip(entry.stack, '已查站内数据 · ' + data.cards.length + ' 张卡片');
            }
            var bubble = el('div', 'acs-message-bubble', reply);
            entry.stack.appendChild(bubble);
            linkify(bubble, reply);
            stampTime(entry.stack, data.at);
            renderCards(entry.stack, data.cards);
            scrollToEnd();
            if (!state.nearBottom) showFeedback('客服已回复，往下滚动查看');
            notifyReply();
            if (data.event) track('acs_intent_' + data.event, {});
        }).catch(function (error) {
            typing.row.remove();
            showFeedback(error.message);
            mine.row.classList.add('is-failed');
            // 别把访客打的字吞掉：原样放回输入框，重发只要再点一下发送。
            // 输入框里已经有新内容时不覆盖——那是访客正在打的下一句。
            if (input.value === '') {
                input.value = fromInput ? typed : message;
                resizeInput();
                renderCounter();
            }
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

    /**
     * 刷新页面后把本次会话的文字记录取回来重画。
     *
     * 上下文一直都存在服务端 session 里（每轮问答都要用），之前只是没人回传给浏览器，
     * 于是访客一刷新就看到空窗口、以为客服把话忘了。只在第一次打开面板时取一次：
     * 从不点开客服的访客不该为这个功能多付一次请求。卡片不存档，所以恢复的是纯文字。
     */
    function maybeRestore() {
        if (state.restored) return;
        state.restored = true;
        if (!exp.resume) return;
        state.restoring = true;
        var body = new URLSearchParams();
        body.set('_csrf', config.csrf);
        body.set('conversation_id', state.conversationId);
        body.set('action', 'history');
        fetch(config.actionEndpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: body
        }).then(function (response) {
            return response.json().catch(function () { return {}; });
        }).then(function (data) {
            var list = (data && data.ok && data.data && Array.isArray(data.data.messages)) ? data.data.messages : [];
            if (!list.length || state.greeted) return;
            state.greeted = true;
            state.replied = true;
            if (config.welcomeMessage) appendMessage('assistant', config.welcomeMessage);
            list.forEach(function (item) {
                if (!item || (item.role !== 'user' && item.role !== 'assistant')) return;
                appendMessage(item.role === 'user' ? 'visitor' : 'assistant', String(item.content || ''), item.at);
            });
            renderQuickReplies();
            scrollToEnd(true);
        }).catch(function () { /* 取不回来就按新会话走，不打扰访客 */ }).finally(function () {
            state.restoring = false;
            if (state.open) ensureGreeting();
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

    /* ---------------------------------------------------------------- 浮标多渠道展开 */

    /**
     * 浮标旁边的"其他联系方式"。渠道条目由服务端从负责人社媒里取，前台只管开合：
     * 想在微信里聊的访客不必先跟机器人说一句话才拿到二维码号。
     */
    function setChannels(open) {
        if (!channels) return;
        var next = !!open && !state.open;
        channels.hidden = !next;
        channels.classList.toggle('is-open', next);
        if (channelsToggle) {
            channelsToggle.setAttribute('aria-expanded', next ? 'true' : 'false');
            channelsToggle.classList.toggle('is-open', next);
        }
        if (next) {
            dismissTeaser(false);
            track('acs_channels_open', {});
        }
    }

    /* ACS_MARKER_FJS_6 */

    /* ---------------------------------------------------------------- 触发规则与接线 */

    function activateVisibilityRules() {
        if (!allowedDevice()) {
            // 节点整棵移除后，公开 API 再操作它就是往游离的 DOM 上写，标记成 dead 直接短路
            state.dead = true;
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
    if (channelsToggle) {
        channelsToggle.addEventListener('click', function (event) {
            event.stopPropagation();
            setChannels(channels ? channels.hidden : false);
        });
    }
    if (channels) {
        channels.querySelectorAll('[data-acs-copy]').forEach(function (node) {
            node.addEventListener('click', function () {
                copyValue(node.getAttribute('data-acs-copy') || '', node);
                track('acs_channel_click', { network: node.getAttribute('data-acs-network') || '' });
            });
        });
        channels.querySelectorAll('a[data-acs-network]').forEach(function (node) {
            node.addEventListener('click', function () {
                track('acs_channel_click', { network: node.getAttribute('data-acs-network') || '' });
            });
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
                // 刚清空的会话没有历史可恢复，别让下一次打开又把旧记录拉回来
                state.restored = true;
                state.nearBottom = true;
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
    chat.addEventListener('scroll', function () {
        state.nearBottom = atBottom();
        // 自己滚回底部就算把"有新回复"这条提示看过了
        if (state.nearBottom && feedback.textContent === '客服已回复，往下滚动查看') showFeedback('');
    }, { passive: true });

    // 回到这个标签页就把标题上的未读计数擦掉，别让 "(3) 站点名" 一直挂着
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && state.open) {
            clearAttention();
            restoreTitle();
        }
    });

    /* 面板打开时把 Tab 圈在面板里：不然一个 Tab 就跳到页面正文，键盘用户很难再回来。 */
    panel.addEventListener('keydown', function (event) {
        if (event.key !== 'Tab' || !state.open) return;
        var focusable = panel.querySelectorAll('a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])');
        var list = Array.prototype.filter.call(focusable, function (node) {
            return node.offsetWidth > 0 || node.offsetHeight > 0 || node === document.activeElement;
        });
        if (!list.length) return;
        var first = list[0];
        var last = list[list.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        if (channels && !channels.hidden) { setChannels(false); return; }
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
        // 版本号只有 plugin.json 一处真源，注入配置里带过来，别在这儿再抄一遍
        version: String(config.version || '')
    };

    renderCounter();
    activateVisibilityRules();
}());
