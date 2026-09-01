(function () {
    'use strict';

    var root = document.getElementById('ai-customer-service-widget');
    var configNode = document.getElementById('acs-widget-config');
    if (!root || !configNode) return;

    /* 同一份脚本被加载两次时（主题把 footer 钩子渲染了两遍、或站长同时用了缓存插件的
     * 合并脚本），第二遍会在同一批节点上再挂一整套监听：点一次浮标开一次又关一次，
     * 发一条消息发两遍。DOM 上打一个标记，第二遍直接退出。
     * 用 dataset 而不是模块内变量，因为两次加载是两个独立的 IIFE 作用域。 */
    if (root.dataset.acsBound === '1') return;
    root.dataset.acsBound = '1';

    var config;
    try { config = JSON.parse(configNode.textContent || '{}'); } catch (error) { return; }
    /* csrf 与 conversationId 刻意不再是硬前提：整页缓存下它们可能是别人的，甚至（会话
     * 还没起来时）是空的。真正不能缺的只有端点地址 —— 有端点就能握手换一份回来。 */
    if (!config || !config.endpoint || !config.actionEndpoint) return;

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
    var consentBlock = root.querySelector('[data-acs-consent]');
    var consentBox = root.querySelector('[data-acs-consent-box]');
    var consentAccept = root.querySelector('[data-acs-consent-accept]');
    if (!panel || !chat || !quickReplies || !form || !input || !send || !feedback) return;

    // 体验增强开关。缺字段一律当关，老版本的注入配置也不会炸。
    var exp = (config.experience && typeof config.experience === 'object') ? config.experience : {};

    // 聊天前的同意确认。服务端 session 里已经同意过时 accepted=true；万一页面被整页缓存
    // 导致这里是过期的值也不会把人锁死：聊天接口会回 consent_required，前端据此重新拦。
    var consentCfg = (config.consent && typeof config.consent === 'object') ? config.consent : {};
    var consentRequired = !!consentCfg.enabled && !!consentBlock;

    // show_launcher=false 时不渲染浮标，但面板仍然保留，供 window.AiCustomerService 打开。
    var state = {
        open: false, visible: false, greeted: false, busy: false,
        userOpened: false, replied: false, conversationId: String(config.conversationId || ''),
        // csrf 与 conversationId 都是可变的：缓存页上要靠握手换成属于访客自己的那一份
        csrf: String(config.csrf || ''),
        // dead: 设备不匹配时整个节点已被移除，公开 API 不能再往一棵脱离文档的树上操作
        dead: false, nearBottom: true, unread: 0, lastFocus: null,
        restored: false, restoring: false, opened: false,
        // sent: 访客已经开口。历史恢复慢一步回来时不能再把聊天区推平重铺
        sent: false,
        // modal: 这次打开是访客自己点的。只有这时候才圈住 Tab 并对读屏声明 aria-modal ——
        // 自动弹出的面板是「环境里多了一块东西」，把键盘用户锁在里面出不去是纯粹的伤害
        modal: false,
        // away: 不在服务时段，但站长选了「照出 + 说明」而不是隐藏
        away: false,
        consented: !consentRequired || !!consentCfg.accepted
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

    /* ------------------------------------------------------------ 服务时段（客户端判定）
     *
     * 服务端不判这个：结果随时间变，而整页缓存会把渲染那一刻的答案冻住。判定必须用
     * 「站点」的本地时间 —— 站长说的 9:00 上班是他自己的 9:00，不是访客那边的 9:00，
     * 所以走 Intl + 时区名，而不是访客自己的 getDay()/getHours()。
     */
    var WEEKDAYS = { Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6, Sun: 7 };

    /** @return {{day:number,hm:string}|null} 站点本地的 ISO 周序号与 HH:MM */
    function siteClock() {
        var sched = config.schedule || {};
        if (sched.tz) {
            try {
                var parts = new Intl.DateTimeFormat('en-GB', {
                    timeZone: String(sched.tz), weekday: 'short',
                    hour: '2-digit', minute: '2-digit', hour12: false
                }).formatToParts(new Date());
                var got = {};
                parts.forEach(function (p) { got[p.type] = p.value; });
                var day = WEEKDAYS[got.weekday];
                if (day && got.hour && got.minute) {
                    // Intl 在部分实现里把午夜给成 24，规格化回 00
                    var hour = got.hour === '24' ? '00' : got.hour;
                    return { day: day, hm: hour + ':' + got.minute };
                }
            } catch (error) { /* 时区名不认或没有 Intl：落到下面的偏移量算法 */ }
        }
        // 退路：用服务端渲染时的 UTC 偏移量。含夏令时，缓存久了可能差一小时，但比不判好
        var offset = Number(sched.offset);
        if (!isFinite(offset)) return null;
        var shifted = new Date(Date.now() + offset * 60000);
        var iso = shifted.getUTCDay();
        return {
            day: iso === 0 ? 7 : iso,
            hm: pad2(shifted.getUTCHours()) + ':' + pad2(shifted.getUTCMinutes())
        };
    }

    function pad2(n) { return (n < 10 ? '0' : '') + n; }

    /** 与服务端 scheduleAllowed() 同一套判定，包括跨零点的 start > end。 */
    function withinSchedule() {
        var sched = config.schedule || {};
        if (!sched.enabled) return true;
        var days = Array.isArray(sched.days) ? sched.days : [];
        var start = String(sched.start || '');
        var end = String(sched.end || '');
        if (!days.length || !start || !end) return true;   // 配不全等于没配，别把挂件全天关掉
        var now = siteClock();
        if (!now) return true;                              // 算不出来就别擅自隐藏
        // 服务端给的是数字，但 indexOf 是严格比较：万一哪天变成 "1" 就会天天都算歇业
        var open = days.some(function (d) { return Number(d) === now.day; });
        if (!open) return false;
        return start <= end ? (now.hm >= start && now.hm <= end) : (now.hm >= start || now.hm <= end);
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

    /* ---------------------------------------------------------------- 同源请求
     *
     * 所有 POST 都走 postForm()。整页缓存 / CDN 会把渲染那一刻的 csrf 与 conversationId
     * 冻进 HTML 发给所有访客：token 不属于访客自己的 session，核心 Router 在进插件之前
     * 就回 419（纯文本，不是 JSON）；会话 id 也不在他的 session 里，接口恒回 422
     * conversation_expired。这两种失败都不该丢给访客一句「请刷新页面」—— 刷新拿回来的
     * 还是同一份缓存 HTML。所以撞上就去 GET 一次握手端点换新的 csrf + 会话 id，
     * 然后把原请求重放一次（只重放一次：真的坏了要让错误浮出来，不能悄悄打转）。
     */

    function handshake() {
        if (!config.sessionEndpoint) return Promise.resolve(false);
        return fetch(config.sessionEndpoint, {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            return response.json().catch(function () { return {}; });
        }).then(function (data) {
            var got = (data && data.ok && data.data) ? data.data : null;
            if (!got || !got.csrf || !got.conversation_id) return false;
            state.csrf = String(got.csrf);
            state.conversationId = String(got.conversation_id);
            return true;
        }).catch(function () { return false; });
    }

    /**
     * @param {Object} fields 除 _csrf / conversation_id 之外的表单字段
     * @param {number} [timeoutMs] 0 或不给表示不设上限
     * @return {Promise<{ok:boolean,error:string,code:string,data:Object,status:number}>}
     *   只有网络失败与超时才 reject（Error 上带 aborted 标记）；HTTP 与业务错误一律
     *   正常 resolve，由调用方决定怎么呈现。
     */
    function postForm(endpoint, fields, timeoutMs) {
        function fire(isRetry) {
            var body = new URLSearchParams();
            if (state.csrf) body.set('_csrf', state.csrf);
            if (state.conversationId) body.set('conversation_id', state.conversationId);
            Object.keys(fields || {}).forEach(function (key) {
                var value = fields[key];
                if (value !== undefined && value !== null) body.set(key, String(value));
            });
            var controller = (timeoutMs > 0 && window.AbortController) ? new window.AbortController() : null;
            var timer = null;
            var init = {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: body
            };
            if (controller) {
                init.signal = controller.signal;
                timer = window.setTimeout(function () { controller.abort(); }, timeoutMs);
            }
            return fetch(endpoint, init).then(function (response) {
                // 419 是核心在进插件之前拦下的 CSRF 失败，回的是纯文本；不特判就会被
                // 当成「响应不是 JSON」，最后显示成一句莫名的"网络似乎断开了"
                if (response.status === 419) {
                    return { ok: false, error: '', code: 'csrf_mismatch', data: {}, status: 419 };
                }
                return response.json().catch(function () { return {}; }).then(function (raw) {
                    var data = (raw && typeof raw === 'object') ? raw : {};
                    var payload = (data.data && typeof data.data === 'object') ? data.data : {};
                    return {
                        ok: response.ok && !!data.ok,
                        error: String(data.error || payload.message || ''),
                        code: String(data.code || ''),
                        data: payload,
                        status: response.status
                    };
                });
            }, function (error) {
                var aborted = !!(error && error.name === 'AbortError');
                var wrapped = new Error(aborted ? '请求超时' : '网络似乎断开了，请检查连接后重试。');
                wrapped.aborted = aborted;
                throw wrapped;
            }).finally(function () {
                if (timer !== null) window.clearTimeout(timer);
            }).then(function (result) {
                var stale = result.code === 'csrf_mismatch' || result.code === 'conversation_expired';
                if (isRetry || !stale) return result;
                return handshake().then(function (fresh) { return fresh ? fire(true) : result; });
            });
        }
        return fire(false);
    }

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

    /* silent=true 表示这次打开不是访客点的（目前只有延时自动弹出）。这种情况下绝不能抢焦点：
     * 访客可能正在填站点自己的搜索框或结账表单，几秒后光标被挪进聊天框，输入会打到别处去。 */
    /* 软键盘让面板抬起来 + 矮下去。iOS Safari 的 100dvh 只跟随浏览器工具条，
     * 软键盘是覆盖层、不改变布局视口，所以键盘弹起时面板既没变矮也没上移，
     * 输入框和发送键正好被压在键盘下面 —— 访客打字时看不见自己打了什么。
     * 只能自己量：visualViewport.height 是去掉键盘之后真正可见的那块。
     * 只在"我们的面板开着且是手机"时才跟：页面上别的输入框弹键盘时，
     * 没有理由去挪一个关着的浮标。 */
    var kbBound = false;
    function applyKeyboardInset() {
        var vv = window.visualViewport;
        if (!vv || !state.open || !isMobile()) {
            root.style.setProperty('--acs-kb', '0px');
            return;
        }
        var inset = Math.round(window.innerHeight - vv.height - vv.offsetTop);
        // 12px 以下当抖动丢掉：地址栏收放、橡皮筋滚动都会让这个差值出现个位数毛刺，
        // 照搬会让面板在滚动时一直轻微跳动。
        root.style.setProperty('--acs-kb', (inset > 12 ? inset : 0) + 'px');
    }
    function trackKeyboard() {
        var vv = window.visualViewport;
        if (vv && !kbBound) {
            kbBound = true;
            vv.addEventListener('resize', applyKeyboardInset);
            vv.addEventListener('scroll', applyKeyboardInset);
        }
        applyKeyboardInset();
    }

    function setOpen(open, silent) {
        if (state.dead) return;
        if (!state.visible && open) reveal();
        /* 只在「关 → 开」这一次记录来源焦点，而且不记面板内部的节点。面板已经开着时再调一次
         * setOpen(true)（站点自己的按钮走 window.AiCustomerService.ask()/open() 就是这条路）
         * 会把 lastFocus 覆盖成输入框；之后 Esc 关闭，focus() 落在已隐藏的子树上是空操作，
         * 焦点直接掉到 body —— 键盘用户要从整页开头重新 Tab 回来。 */
        if (open && !state.open) {
            var source = document.activeElement;
            state.lastFocus = (source && source !== document.body && !panel.contains(source))
                ? source : (launcher || null);
        }
        state.open = !!open;
        state.modal = state.open && !silent;
        panel.hidden = !state.open;
        panel.setAttribute('aria-hidden', state.open ? 'false' : 'true');
        // 圈 Tab 和 aria-modal 必须同时成立：只圈不声明，读屏会说页面还能去，键盘却出不去
        if (state.modal) panel.setAttribute('aria-modal', 'true');
        else panel.removeAttribute('aria-modal');
        // 供 CSS 用：打开时要收起引流气泡与悬停提示，否则它们和浮标叠在一起
        root.dataset.acsOpen = state.open ? 'true' : 'false';
        trackKeyboard();
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
            /* document.contains() 对隐藏子树里的节点仍然返回 true，而 focus() 对它是
             * 空操作 —— 焦点会停在 body 上。兜一道回浮标，那是唯一一定能重新打开的入口。 */
            if (launcher && (!document.activeElement || document.activeElement === document.body)) launcher.focus();
            return;
        }
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
        if (silent) return;
        window.setTimeout(function () {
            // 还没勾同意时焦点给勾选框：输入框此刻是 disabled，focus() 会落空
            if (consentRequired && !state.consented && consentBox) { consentBox.focus(); return; }
            /* 手机上不自动聚焦：focus() 会立刻弹起软键盘，占掉半屏，
             * 访客刚点开就只看得见输入框，问候语和快捷问题全被顶到视口外。
             * 桌面端相反 —— 打开即能打字是基本预期。 */
            if (!isMobile()) input.focus();
        }, 0);
    }

    function scheduleAutoOpen() {
        if (timers.auto !== null || !config.initialOpen) return;
        var delay = Math.max(0, Number(config.initialOpenDelay) || 0);
        timers.auto = window.setTimeout(function () {
            timers.auto = null;
            if (!state.userOpened) setOpen(true, true);
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
            /* 三个空 <i> 对读屏就是空的，而 ARIA 禁止给 generic 角色起名字 —— 挂在
             * div 上的 aria-label 会被浏览器忽略。塞一句真的文本进去，聊天区本身就是
             * role=log，会自然念出来：不然访客按下发送之后到回复到达之间毫无反馈。 */
            bubble.appendChild(el('span', 'acs-sr-only', '客服正在输入…'));
        }
        entry.stack.appendChild(bubble);
        scrollToEnd();
        return entry;
    }

    /**
     * 一条客服回复：工具 chip → 正文（含链接化）→ 时间戳 → 卡片。
     * 正常回复与「超时后补回来的回复」共用，免得两处各写一遍渲染顺序。
     */
    function appendReply(text, at, cards, chips) {
        var entry = messageRow('assistant');
        (chips || []).forEach(function (label) { appendToolChip(entry.stack, label); });
        var bubble = el('div', 'acs-message-bubble', text);
        entry.stack.appendChild(bubble);
        linkify(bubble, text);
        stampTime(entry.stack, at);
        renderCards(entry.stack, cards);
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
        /* 刚建出来的按钮默认可点。两种情况下必须跟着锁上：正在等回复（否则历史恢复或
         * 主动问候重铺快捷问题时，访客能在 busy 中间再点一条，submit 的 busy 闸会把它
         * 静默丢掉 —— 表现成"点了没反应"），以及同意还没勾。 */
        quickReplies.querySelectorAll('button').forEach(function (button) { button.disabled = state.busy; });
        applyConsent();
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
            // 与名片上的按钮共用 copyValue()：复制失败时必须说实话，不能只把文字换掉。
            button.addEventListener('click', function () { copyValue(item.value, button, label); });
            markQr(button, item);
            return button;
        }
        var link = el('a', 'acs-social-item');
        link.href = item.href || item.value;
        if (item.mode === 'link') { link.target = '_blank'; link.rel = 'noopener'; }
        link.appendChild(icon(item.icon || 'bi-link-45deg'));
        link.appendChild(el('span', '', item.label || item.value));
        markQr(link, item);
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
        markQr(node, item);
        return node;
    }

    /* ---------------------------------------------------------------- 二维码浮层 */

    /* 站长自己传的二维码图。整个挂件共用一个浮层节点，跟着触发元素定位：
     * root 是 position: fixed，所以它就是绝对定位的包含块，视口坐标减掉 root 的
     * rect 就能直接当 left/top 用（浮层可以越出 root 的盒子，root 没有 overflow 裁剪）。 */
    var qrPop = null;
    var qrSticky = null;

    function markQr(node, item) {
        if (!item || !item.qr) return node;
        node.setAttribute('data-acs-qr', item.qr);
        node.setAttribute('data-acs-qr-name', item.label || item.network || '');
        bindQr(node);
        return node;
    }

    function qrLayer() {
        if (qrPop) return qrPop;
        qrPop = el('div', 'acs-qr-pop');
        qrPop.hidden = true;
        var img = el('img', 'acs-qr-img');
        img.alt = '';
        img.loading = 'lazy';
        qrPop.appendChild(img);
        qrPop.appendChild(el('span', 'acs-qr-name', ''));
        root.appendChild(qrPop);
        return qrPop;
    }

    function showQr(node) {
        var src = node.getAttribute('data-acs-qr');
        if (!src) return;
        var pop = qrLayer();
        var img = pop.querySelector('.acs-qr-img');
        if (img.getAttribute('src') !== src) img.setAttribute('src', src);
        pop.querySelector('.acs-qr-name').textContent = node.getAttribute('data-acs-qr-name') || '扫码联系';
        pop.hidden = false;

        var rect = node.getBoundingClientRect();
        var base = root.getBoundingClientRect();
        var width = pop.offsetWidth;
        var height = pop.offsetHeight;
        var viewportW = window.innerWidth || document.documentElement.clientWidth || width;
        var viewportH = window.innerHeight || document.documentElement.clientHeight || height;
        // 默认摆在触发元素左侧（挂件通常贴右边）；左边放不下就翻到右侧
        var left = rect.left - width - 10;
        if (left < 8) left = Math.min(rect.right + 10, viewportW - width - 8);
        var top = rect.top + rect.height / 2 - height / 2;
        top = Math.max(8, Math.min(top, viewportH - height - 8));
        pop.style.left = (left - base.left) + 'px';
        pop.style.top = (top - base.top) + 'px';
    }

    function hideQr(force) {
        if (!qrPop || (qrSticky && !force)) return;
        qrSticky = null;
        qrPop.hidden = true;
    }

    function bindQr(node) {
        if (node.dataset.acsQrBound === '1') return;
        node.dataset.acsQrBound = '1';
        // 触屏没有 hover：链接类保持"点了就跳"，复制类（微信号）点一下把浮层钉住
        node.addEventListener('pointerenter', function (event) {
            if (event.pointerType === 'touch') return;
            showQr(node);
        });
        node.addEventListener('pointerleave', function () { hideQr(false); });
        node.addEventListener('focus', function () { showQr(node); });
        node.addEventListener('blur', function () { hideQr(false); });
        if (node.tagName === 'BUTTON') {
            node.addEventListener('click', function () {
                if (qrSticky === node) { hideQr(true); return; }
                qrSticky = node;
                showQr(node);
            });
        }
    }

    /* ACS_MARKER_FJS_QR */

    /** value 落剪贴板；node 用来闪 title/高亮，label 是可选的按钮内文字节点。 */
    function copyValue(value, node, label) {
        var restoreTitle = node.getAttribute('title') || '';
        var restoreLabel = label ? label.textContent : '';
        var reset = function () {
            node.classList.remove('acs-social-copied');
            node.setAttribute('title', restoreTitle);
            if (label) label.textContent = restoreLabel;
        };
        var done = function () {
            node.classList.add('acs-social-copied');
            node.setAttribute('title', '已复制：' + value);
            if (label) label.textContent = '已复制：' + value;
            window.setTimeout(reset, 2400);
        };
        // 复制可能被拒（非 HTTPS、无用户手势、权限策略）。之前失败也走 done()，
        // 于是明明没复制上还提示"已复制"，访客粘出来是上一次的剪贴板内容。
        var fail = function () {
            node.setAttribute('title', value);
            if (label) label.textContent = value;
            window.setTimeout(reset, 4000);
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

        // 「邮箱和电话至少留一个」是服务端的硬规则，两个都配上时谁都不是 required，
        // 不在这儿写一句，访客只会在提交被拒之后才知道。
        if (card.eitherContact && inputs.email && inputs.phone) {
            grid.appendChild(el('p', 'acs-form-hint', '邮箱和电话至少留一个，方便我们回复您。'));
        }

        var actions = el('div', 'acs-form-actions');
        var submitBtn = el('button', 'acs-btn', card.submit || '提交询盘');
        submitBtn.type = 'submit';
        actions.appendChild(submitBtn);
        var status = el('span', 'acs-form-status');
        // 校验失败与提交结果都只写在这里，不给 aria-live 读屏就完全不知道发生了什么
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        actions.appendChild(status);
        wrap.appendChild(actions);

        /** 标错一个字段：视觉类名 + aria-invalid，两者要一起动。 */
        function markInvalid(entry, bad) {
            entry.box.classList.toggle('has-error', bad);
            if (bad) entry.control.setAttribute('aria-invalid', 'true');
            else entry.control.removeAttribute('aria-invalid');
        }
        // 改动就把错误标记撤掉，别让访客改完了还盯着一个红框
        Object.keys(inputs).forEach(function (name) {
            inputs[name].control.addEventListener('input', function () { markInvalid(inputs[name], false); });
        });

        function fail(message, focusEntry) {
            status.textContent = message;
            status.className = 'acs-form-status is-error';
            // 焦点跟着错误走：只写一句"请把带 * 的字段填一下"，访客还得自己找是哪个
            if (focusEntry) focusEntry.control.focus();
        }

        wrap.addEventListener('submit', function (event) {
            event.preventDefault();
            var fields = {
                action: 'inquiry',
                page_url: window.location.href.slice(0, 500),
                page_title: String(document.title || '').slice(0, 255)
            };

            var firstBad = null;
            Object.keys(inputs).forEach(function (name) {
                var entry = inputs[name];
                var value = String(entry.control.value || '').trim();
                var bad = entry.required && value === '';
                markInvalid(entry, bad);
                if (bad && !firstBad) firstBad = entry;
                fields['field_' + name] = value;
            });
            if (firstBad) { fail('请把带 * 的字段填一下', firstBad); return; }

            // 邮箱格式：表单是 noValidate 的（要自己的文案），浏览器不会替我们拦
            var emailEntry = inputs.email;
            var emailValue = emailEntry ? String(emailEntry.control.value || '').trim() : '';
            if (emailValue !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(emailValue)) {
                markInvalid(emailEntry, true);
                fail('邮箱格式不正确', emailEntry);
                return;
            }
            // 服务端的"至少留一个"规则，前台先拦一道，省一次往返
            if (card.eitherContact && emailEntry && inputs.phone) {
                var phoneValue = String(inputs.phone.control.value || '').trim();
                if (emailValue === '' && phoneValue === '') {
                    markInvalid(emailEntry, true);
                    markInvalid(inputs.phone, true);
                    fail('邮箱和电话至少留一个', emailEntry);
                    return;
                }
            }

            submitBtn.disabled = true;
            status.textContent = '正在提交…';
            status.className = 'acs-form-status';
            postForm(config.actionEndpoint, fields, 25000).then(function (result) {
                if (!result.ok) {
                    var text = result.error || '提交失败，请稍后再试';
                    // 询盘限流是每 IP 每 10 分钟 3 条，不写出等待时间访客只能反复试
                    var wait = Math.round(Number(result.data.retry_after) || 0);
                    if (wait > 0) text += wait >= 60 ? '（约 ' + Math.ceil(wait / 60) + ' 分钟后可再提交）'
                        : '（约 ' + wait + ' 秒后可再提交）';
                    throw new Error(text);
                }
                return result.data;
            }).then(function (data) {
                grid.remove();
                actions.remove();
                // actions 里就有刚才那个提交按钮，移除它焦点会掉回文档开头。
                // 成功文案给 role=status + tabindex=-1，把焦点交给它，读屏也会念出来。
                var done = el('div', 'acs-form-status is-done', data.message || card.success || '已收到，我们会尽快联系您。');
                done.setAttribute('role', 'status');
                done.tabIndex = -1;
                wrap.appendChild(done);
                done.focus();
                track('acs_inquiry_submitted', {});
            }).catch(function (error) {
                // 服务端也会回"请填写需求描述"/"邮箱格式不正确"/"请至少留一个邮箱或电话"，
                // 尽量把它落到对应字段上，别只丢一句话让访客自己猜。
                var map = { 邮箱: inputs.email, 电话: inputs.phone, 需求: inputs.message };
                var target = null;
                Object.keys(map).forEach(function (word) {
                    if (!target && map[word] && error.message.indexOf(word) !== -1) target = map[word];
                });
                if (target) markInvalid(target, true);
                fail(error.message, target);
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
        /* 访客刚激活的正是发送键或某个快捷提问：直接禁用当前 activeElement，焦点会立刻
         * 掉到 body，读屏的虚拟焦点跟着丢，要等回复到达才回来（最长可达等待上限）。
         * 先把焦点搬到输入框 —— 它刻意不被禁用，也是访客接下来唯一要用的控件。 */
        var active = document.activeElement;
        if (busy && !input.disabled && active
            && (active === send || quickReplies.contains(active))) input.focus();
        send.disabled = busy;
        quickReplies.querySelectorAll('button').forEach(function (button) { button.disabled = busy; });
        // 忙完了也不能把发送解锁给一个还没勾同意的访客
        applyConsent();
    }
    function showFeedback(message) {
        feedback.textContent = message || '';
    }

    /* ---------------------------------------------------------------- 同意确认 */

    /** 没勾同意之前输入框、发送、快捷提问全锁住；同意块本身照常可交互。 */
    function applyConsent() {
        if (!consentRequired) return;
        var pending = !state.consented;
        if (consentBlock) consentBlock.hidden = !pending;
        input.disabled = pending;
        send.disabled = pending || state.busy;
        quickReplies.querySelectorAll('button').forEach(function (button) {
            button.disabled = pending || state.busy;
        });
    }

    /** 服务端说还没同意（比如页面是缓存的旧 HTML）：把门槛重新竖起来。 */
    function requireConsent() {
        if (!consentRequired) return;
        state.consented = false;
        if (consentBox) consentBox.checked = false;
        if (consentAccept) consentAccept.disabled = true;
        applyConsent();
    }

    function acceptConsent() {
        if (!consentAccept || !consentBox || !consentBox.checked) return;
        consentAccept.disabled = true;
        postForm(config.actionEndpoint, { action: 'consent' }, 15000).then(function (result) {
            if (!result.ok) {
                showFeedback(result.error || '提交失败，请稍后重试。');
                consentAccept.disabled = false;
                return;
            }
            state.consented = true;
            applyConsent();
            showFeedback('');
            input.focus();
            track('acs_consent', {});
        }).catch(function (error) {
            showFeedback(error && error.aborted ? '提交超时，请再试一次。' : error.message);
            consentAccept.disabled = false;
        });
    }

    /* 浏览器这边的等待上限由服务端算好下发（rounds × 出站超时 + 余量，见 chatTimeoutMs）：
     * 比服务端最坏耗时短，访客会先看到"超时"、而服务端随后照样把这轮存进会话；
     * 完全不设上限，遇到被中间设备挂住的连接就永远停在"正在输入"，只能刷新页面。 */
    var TIMEOUT_MS = Math.max(20000, Number(config.timeoutMs) || 40000);
    // 等得比这个久就先说一句"还在处理"，免得访客以为已经断了
    var SLOW_MS = Math.min(18000, Math.round(TIMEOUT_MS * 0.45));

    function request(message) {
        return postForm(config.endpoint, { message: message }, TIMEOUT_MS).then(function (result) {
            if (result.ok) return result.data;
            var text = result.error || config.unavailableMessage || '暂时无法回复，请稍后再试。';
            // 限流时服务端算好了还要等多久，不用上就等于让访客盲等——
            // "请稍后再试"是最没用的那种错误提示。
            var wait = Math.round(Number(result.data.retry_after) || 0);
            if (wait > 0) text += wait >= 60 ? '（约 ' + Math.ceil(wait / 60) + ' 分钟后可继续）'
                : '（约 ' + wait + ' 秒后可继续）';
            var error = new Error(text);
            error.payload = result.data;
            error.code = result.code;
            throw error;
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
        if (consentRequired && !state.consented) {
            showFeedback('请先勾选同意后再开始对话');
            applyConsent();
            if (consentBox) consentBox.focus();
            return;
        }
        if (message.length > max) {
            showFeedback('消息不能超过 ' + max + ' 个字符');
            return;
        }
        state.replied = true;
        // sent 只用来挡"历史记录回填"：这一轮已经开始对话，再把旧记录插到最前面就乱了
        state.sent = true;
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
        // 长回答（尤其开了工具轮次）能等上十几秒，中间一句话都不给就像是断了
        var slow = window.setTimeout(function () {
            if (state.busy) showFeedback('还在处理，稍等一下……');
        }, SLOW_MS);
        request(message).then(function (data) {
            typing.row.remove();
            var chips = [];
            if (data.event && EVENT_LABEL[data.event]) chips.push(EVENT_LABEL[data.event]);
            if (Array.isArray(data.cards) && data.cards.length) {
                chips.push('已查站内数据 · ' + data.cards.length + ' 张卡片');
            }
            appendReply(String(data.reply || config.unavailableMessage || ''), data.at, data.cards, chips);
            // 到这儿要么换成"往下滚动查看"，要么清掉上面那句"还在处理"
            showFeedback(state.nearBottom ? '' : '客服已回复，往下滚动查看');
            notifyReply();
            if (data.event) track('acs_intent_' + data.event, {});
        }).catch(function (error) {
            typing.row.remove();
            if (error && error.aborted) {
                /* 超时不等于没送到：服务端很可能已经答完、也写进了会话，只是回程没赶上。
                 * 这时候标"未发送"并把原文塞回输入框，等于骗访客把同一句问第二遍。 */
                showFeedback('等太久了，正在确认这条有没有送到……');
                mine.row.classList.add('is-pending');
                recoverTimedOut(message, mine);
                return;
            }
            showFeedback(error.message);
            mine.row.classList.add('is-failed');
            // 服务端说同意还没记上（页面很可能是缓存的旧 HTML），把门槛重新竖起来
            if (error.code === 'consent_required') requireConsent();
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
            window.clearTimeout(slow);
            setBusy(false);
            // 同意门槛刚被重新竖起来时输入框是 disabled 的，focus() 对它是空操作：
            // 焦点会留在原地（或掉回文档开头），键盘用户找不到"接下来该点哪"。
            // 这种情况下把焦点交给勾选框——那才是唯一能往下走的控件。
            if (input.disabled) { if (consentBox) consentBox.focus(); } else { input.focus(); }
        });
    }

    /**
     * 超时之后回头把答案捞回来。
     *
     * 浏览器这边的等待上限比服务端最坏耗时短，所以"超时"最常见的真相是：服务端答完了、
     * 也存进会话了，只是那一份响应没赶上。不捞的话访客只能重问一遍，同一个问题问两次、
     * 计一次限流额度，而屏幕上还留着一条永远解释不清的"未发送"。
     */
    function recoverTimedOut(message, mine) {
        if (!exp.resume) return;
        window.setTimeout(function () {
            postForm(config.actionEndpoint, { action: 'history' }, 8000).then(function (result) {
                var list = (result.ok && Array.isArray(result.data.messages)) ? result.data.messages : [];
                if (list.length < 2) return;
                var reply = list[list.length - 1];
                var asked = list[list.length - 2];
                if (!reply || reply.role !== 'assistant' || !asked || asked.role !== 'user') return;
                // 末尾那一问必须就是刚超时的这句，否则捞回来的是别的轮次
                if (String(asked.content || '') !== message) return;
                mine.row.classList.remove('is-pending');
                appendReply(String(reply.content || ''), reply.at, [], []);
                showFeedback('刚才那条回复已经补上了。');
                notifyReply();
            }).catch(function () { /* 补不回来就让访客自己重发，别再多一条错误提示 */ });
        }, 1500);
    }

    /**
     * 刷新页面后把本次会话的文字记录取回来重画。
     *
     * 上下文一直都存在服务端 session 里（每轮问答都要用），之前只是没人回传给浏览器，
     * 于是访客一刷新就看到空窗口、以为客服把话忘了。只在第一次打开面板时取一次：
     * 从不点开客服的访客不该为这个功能多付一次请求。卡片不存档，所以恢复的是纯文字。
     */
    var RESTORE_MS = 6000;

    function maybeRestore() {
        if (state.restored) return;
        state.restored = true;
        if (!exp.resume) return;
        state.restoring = true;
        /* 兜底闸门。restoring 卡住的代价不是"少一句欢迎语"，而是 ensureGreeting() 再也不跑、
         * 面板永远空着；AbortController 在老浏览器上可能不存在，那时 postForm 没有超时，
         * 请求被挂住就一直不 settle。所以这里再压一道一定会响的计时器。 */
        var gate = window.setTimeout(function () {
            if (!state.restoring) return;
            state.restoring = false;
            if (state.open) ensureGreeting();
        }, RESTORE_MS + 1500);
        postForm(config.actionEndpoint, { action: 'history' }, RESTORE_MS).then(function (result) {
            var list = (result.ok && Array.isArray(result.data.messages)) ? result.data.messages : [];
            // chat.firstChild / state.sent：请求还在路上时访客可能已经开口了，
            // 这时候把旧记录插进去会排在新消息后面，读起来像客服在自说自话。
            if (!list.length || state.greeted || state.sent || chat.firstChild) return;
            state.greeted = true;
            state.replied = true;
            /* 回填十几条历史会被 role="log" 逐条念出来，读屏用户要听完整段旧对话才能开口。
             * 插期间先关掉播报，插完下一帧再打开——之后的新回复照常播报。 */
            chat.setAttribute('aria-live', 'off');
            if (config.welcomeMessage) appendMessage('assistant', config.welcomeMessage);
            list.forEach(function (item) {
                if (!item || (item.role !== 'user' && item.role !== 'assistant')) return;
                appendMessage(item.role === 'user' ? 'visitor' : 'assistant', String(item.content || ''), item.at);
            });
            // 视觉上有时间戳能分辨新旧，读屏只有一串连续的对话，得明说一句
            chat.appendChild(el('div', 'acs-sr-only', '以上是本次会话的早前记录'));
            renderQuickReplies();
            scrollToEnd(true);
            window.requestAnimationFrame(function () { chat.setAttribute('aria-live', 'polite'); });
        }).catch(function () { /* 取不回来就按新会话走，不打扰访客 */ }).finally(function () {
            window.clearTimeout(gate);
            state.restoring = false;
            if (state.open) ensureGreeting();
        });
    }

    /* ---------------------------------------------------------------- 表情面板 */

    function closePicker() {
        if (!picker) return;
        /* 得在 hidden 之前问：一旦面板 display:none，浏览器已经把焦点甩回 <body> 了。
         * 焦点正落在面板里的按钮上时，innerHTML='' 会把它连根删掉，键盘用户下一次 Tab
         * 只能从文档开头重新走一遍。所以先交还给打开它的那个按钮。 */
        var hadFocus = !picker.hidden && picker.contains(document.activeElement);
        picker.hidden = true;
        if (hadFocus) {
            var back = root.querySelector('[data-acs-pick="' + (picker.dataset.kind || '') + '"]');
            if (back && typeof back.focus === 'function') back.focus(); else input.focus();
        }
        picker.innerHTML = '';
        root.querySelectorAll('[data-acs-pick]').forEach(function (button) {
            button.classList.remove('is-active');
            // is-active 只是观感；不同步 aria-expanded 读屏就不知道这个按钮展开了一块面板
            button.setAttribute('aria-expanded', 'false');
        });
    }

    function openPicker(kind, trigger) {
        if (!picker) return;
        if (!picker.hidden && picker.dataset.kind === kind) { closePicker(); return; }
        closePicker();
        picker.dataset.kind = kind;
        // 一个面板节点被两个按钮共用，标签只能在这里按 kind 给，写死在 HTML 里必有一半是错的
        picker.setAttribute('aria-label', kind === 'emoji' ? '表情' : '表情包');
        picker.hidden = false;
        trigger.classList.add('is-active');
        trigger.setAttribute('aria-expanded', 'true');

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
        } else {
            hideQr(true);
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
        /* 服务时段：hide 就整棵移除（和设备不匹配同一处理），notice 则照出、展开说明条。
         * 只在初始化时判一次 —— 访客正聊着到了下班点把挂件抽走，比状态略旧糟得多。 */
        if (!withinSchedule()) {
            var away = config.away || {};
            if (String(away.mode || 'hide') !== 'notice') {
                state.dead = true;
                root.remove();
                return;
            }
            state.away = true;
            var notice = root.querySelector('[data-acs-away]');
            if (notice) notice.hidden = false;
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
        // 服务端已经把二维码地址写在 data-acs-qr 上（卡片里的条目走 markQr()）
        channels.querySelectorAll('[data-acs-qr]').forEach(bindQr);
    }
    if (closeBtn) closeBtn.addEventListener('click', function () { setOpen(false); });
    if (restartBtn) {
        restartBtn.addEventListener('click', function () {
            // 静默 return 会让访客以为按钮坏了：正在等回复时说清楚为什么点不动
            if (state.busy) { showFeedback('正在等待回复，稍后再重新开始。'); return; }
            restartBtn.disabled = true;
            postForm(config.actionEndpoint, { action: 'reset' }, 15000).then(function (result) {
                /* 清空必须只在服务端确认换了会话之后做。失败也清的话，屏幕上空了、
                 * 服务端那份上下文还在，接着问下一句会得到一段访客看不见来由的回答。 */
                if (!result.ok || !result.data.conversation_id) {
                    showFeedback(result.error || '重新开始失败，请稍后再试。');
                    return;
                }
                state.conversationId = String(result.data.conversation_id);
                chat.innerHTML = '';
                quickReplies.innerHTML = '';
                state.greeted = false;
                state.replied = false;
                state.sent = false;
                // 刚清空的会话没有历史可恢复，别让下一次打开又把旧记录拉回来
                state.restored = true;
                state.nearBottom = true;
                showFeedback('');
                closePicker();
                ensureGreeting();
            }).catch(function (error) {
                showFeedback(error && error.aborted ? '重新开始超时，请再试一次。' : error.message);
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
    if (consentBox && consentAccept) {
        consentBox.addEventListener('change', function () { consentAccept.disabled = !consentBox.checked; });
        consentAccept.addEventListener('click', acceptConsent);
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
        // 二维码浮层是按触发元素的视口坐标摆的，聊天区一滚坐标就过期了。hover 触发的
        // 那份会因为 pointerleave 自己收掉，但点钉住的（微信这类复制按钮）不会——
        // 不强制收就会看见一张二维码浮在跟它无关的消息上面。
        hideQr(true);
    }, { passive: true });

    // 回到这个标签页就把标题上的未读计数擦掉，别让 "(3) 站点名" 一直挂着
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && state.open) {
            clearAttention();
            restoreTitle();
        }
    });

    /* 访客自己打开的面板把 Tab 圈在里面：不然一个 Tab 就跳到页面正文，键盘用户很难再回来，
     * 而 Esc 会关闭并把焦点还回浮标，出得去。自动弹出的不圈（见 state.modal）。 */
    panel.addEventListener('keydown', function (event) {
        if (event.key !== 'Tab' || !state.open || !state.modal) return;
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
        if (qrSticky) { hideQr(true); return; }
        if (channels && !channels.hidden) { setChannels(false); return; }
        if (picker && !picker.hidden) { closePicker(); return; }
        if (state.open) setOpen(false);
    });

    // 钉住的二维码：点到别处就收起（浮层自身与其它触发点除外）
    document.addEventListener('click', function (event) {
        if (!qrSticky) return;
        var node = event.target;
        while (node && node !== document) {
            if (node === qrPop || (node.getAttribute && node.getAttribute('data-acs-qr'))) return;
            node = node.parentNode;
        }
        hideQr(true);
    }, true);
    window.addEventListener('resize', function () { hideQr(true); });

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
    applyConsent();
    activateVisibilityRules();
}());
