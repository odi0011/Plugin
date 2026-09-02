/* AI客服 · 后台交互
 *
 * 只用原生 DOM：后台是服务端渲染的 PHP 页面，为了几个控件引一个 React 运行时不划算。
 * 组织方式：
 *   - 一层薄工具（el / 取值 / JSON 字段读写）；
 *   - 每个"面板"挂到 [data-acs-mount] 上，自己渲染自己的 UI，并把结果写回 JSON 字段；
 *   - 一个 sync() 把当前表单值映射到右侧预览的 CSS 变量与文案。
 * 任何面板抛错都不该带着整页一起崩，所以 mount 循环里逐个 try。
 */
(function () {
    'use strict';

    /* ---------------------------------------------------------------- 侧栏高亮
     * 核心 sidebar 用完整 URL 做 strpos 匹配，插件菜单存的是带域名的绝对地址，
     * 天然匹配不上；在插件自己的页面上把对应链接补亮。 */
    try {
        var marker = '/ai-customer-service';
        var cut = window.location.pathname.indexOf(marker);
        if (cut !== -1) {
            var needle = window.location.pathname.slice(0, cut + marker.length);
            document.querySelectorAll('.admin-sidebar-nav a').forEach(function (link) {
                var path;
                try { path = new URL(link.href, window.location.origin).pathname; } catch (e) { return; }
                if (path === needle || path.indexOf(needle + '/') === 0) link.classList.add('active');
            });
        }
    } catch (e) { /* 高亮是增强，失败不影响页面 */ }

    var root = document.querySelector('[data-acs-admin]');
    if (!root) return;
    var bootNode = document.getElementById('acs-admin-boot');
    var BOOT;
    try { BOOT = JSON.parse(bootNode ? bootNode.textContent : '{}'); } catch (e) { return; }
    if (!BOOT || !BOOT.config) return;

    /* ---------------------------------------------------------------- 工具 */

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
    function ctrl(key) { return root.querySelector('[name="setting_' + key + '"]'); }

    /**
     * 取一个配置值。
     *
     * 本页没有这个控件时（比如在"会话内容"页要画预览，但配色字段只在"外观"页），
     * 必须回落到服务端归一化后的 BOOT.config，而不是回落到硬编码默认值——否则每一页
     * 的预览都会画成"默认主题"，跟站点真实长相不符。
     */
    function val(key, fallback) {
        var node = ctrl(key);
        if (!node) {
            if (BOOT.config && Object.prototype.hasOwnProperty.call(BOOT.config, key)) return BOOT.config[key];
            return fallback;
        }
        if (node.type === 'checkbox') return node.checked;
        var v = String(node.value == null ? '' : node.value).trim();
        return v !== '' ? v : fallback;
    }
    function num(key, fallback) {
        var v = parseFloat(val(key, fallback));
        return isNaN(v) ? fallback : v;
    }
    /** 直接给数（不是字段键）时的兜底转换。预设值里 0 是合法的（方角、无圆角浮标），
     * 所以只能判 undefined/NaN，不能用 `|| fallback`。 */
    function numOr(raw, fallback) {
        var v = parseFloat(raw);
        return isNaN(v) ? fallback : v;
    }
    function on(key) {
        var node = ctrl(key);
        if (node) return !!node.checked;
        return !!(BOOT.config && BOOT.config[key]);
    }
    function lines(key) {
        var node = ctrl(key);
        if (node) {
            return String(node.value || '').split(/\r?\n/).map(function (line) { return line.trim(); }).filter(Boolean);
        }
        var boot = BOOT.config && BOOT.config[key];
        return Array.isArray(boot) ? boot.slice() : [];
    }
    function setCtrl(key, value) {
        var node = ctrl(key);
        if (!node) return false;
        if (node.type === 'checkbox') {
            node.checked = !!value;
        } else {
            node.value = value == null ? '' : String(value);
            var hex = node.parentNode && node.parentNode.querySelector('[data-acs-color-hex]');
            if (hex) hex.value = node.value.toUpperCase();
            var slider = root.querySelector('[data-acs-slider-for="' + node.name + '"]');
            if (slider) slider.value = node.value;
        }
        node.dispatchEvent(new Event('input', { bubbles: true }));
        node.dispatchEvent(new Event('change', { bubbles: true }));
        return true;
    }

    /* ACS_MARKER_JS_1 */

    /* JSON 字段：隐藏 textarea 是唯一的真相来源，面板读它、改它。
     * 本页没有这个 textarea 时（比如在"会话内容"页画预览要用 theme_json），
     * 回落到服务端归一化后的同名结构，只读。 */
    var JSON_BOOT_KEY = {
        theme_json: 'theme', layout_json: 'layout', greeting_json: 'greeting',
        knowledge_json: 'knowledge', tools_json: 'tools', cards_json: 'cards',
        owner_json: 'owner', guardrails_json: 'guardrails', events_json: 'events',
        stickers_json: 'stickers', experience_json: 'experience',
        targeting_json: 'targeting', consent_json: 'consent'
    };
    var jsonCache = {};
    function readJson(key, fallback) {
        if (jsonCache[key]) return jsonCache[key];
        var node = root.querySelector('[data-acs-json="' + key + '"]');
        var parsed = null;
        if (node) {
            try {
                var decoded = JSON.parse(node.value || 'null');
                if (decoded && typeof decoded === 'object') parsed = decoded;
            } catch (e) { parsed = null; }
        }
        if (parsed === null) {
            var bootKey = JSON_BOOT_KEY[key];
            var boot = bootKey && BOOT.config ? BOOT.config[bootKey] : null;
            parsed = boot && typeof boot === 'object' ? JSON.parse(JSON.stringify(boot)) : fallback;
        }
        jsonCache[key] = parsed;
        return parsed;
    }
    function writeJson(key) {
        var node = root.querySelector('[data-acs-json="' + key + '"]');
        if (!node || !jsonCache[key]) return;
        node.value = JSON.stringify(jsonCache[key]);
        if (node.maxLength > 0 && node.value.length > node.maxLength) {
            toast('「' + key + '」内容超出该字段上限，请删掉一些条目再保存', true);
        }
        sync();
    }

    function toast(message, isError) {
        var bar = root.querySelector('[data-acs-toast]');
        if (!bar) {
            bar = el('div', 'acs-a-alert');
            bar.setAttribute('data-acs-toast', '');
            bar.style.marginTop = '12px';
            var main = root.querySelector('.acs-a-main');
            if (main) main.insertBefore(bar, main.firstChild);
        }
        bar.className = 'acs-a-alert ' + (isError ? 'acs-a-alert--warn' : 'acs-a-alert--info');
        bar.textContent = message;
        bar.hidden = false;
        window.clearTimeout(bar.__timer);
        bar.__timer = window.setTimeout(function () { bar.hidden = true; }, 6000);
    }

    /* 后台异步动作。所有请求带 _csrf（核心 Router 对非 /api/ 的 POST 一律校验）。 */
    function post(action, body) {
        var form = body instanceof FormData ? body : new FormData();
        if (!(body instanceof FormData) && body) {
            Object.keys(body).forEach(function (k) { form.append(k, body[k]); });
        }
        form.append('_csrf', BOOT.csrf);
        return fetch(BOOT.endpoint + '/' + action, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: form
        }).then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (data) {
                if (!res.ok || !data.ok) throw new Error((data && data.error) || '请求失败（' + res.status + '）');
                return data.data || {};
            });
        });
    }

    /* ---------------------------------------------------------------- 基础控件 */

    // Tabs 墨条
    (function tabs() {
        var nav = root.querySelector('.acs-a-tabs');
        var ink = root.querySelector('[data-acs-tab-ink]');
        var active = nav && nav.querySelector('.acs-a-tab.is-active');
        if (!nav || !ink || !active) return;
        function place() {
            ink.style.width = active.offsetWidth + 'px';
            ink.style.transform = 'translateX(' + (active.offsetLeft - nav.scrollLeft) + 'px)';
        }
        place();
        nav.addEventListener('scroll', place, { passive: true });
        window.addEventListener('resize', place);
    }());

    // 数字步进 + 滑块联动 + 字数统计
    root.querySelectorAll('[data-acs-step]').forEach(function (button) {
        button.addEventListener('click', function () {
            var input = button.parentNode.querySelector('input[type="number"]');
            if (!input) return;
            var step = parseFloat(input.step) || 1;
            var delta = parseInt(button.getAttribute('data-acs-step'), 10) * step;
            var next = (parseFloat(input.value) || 0) + delta;
            var min = input.min === '' ? -Infinity : parseFloat(input.min);
            var max = input.max === '' ? Infinity : parseFloat(input.max);
            next = Math.min(max, Math.max(min, next));
            // 0.1 步长会产生 0.30000000000000004，按步长精度收一下
            input.value = step < 1 ? String(Math.round(next * 100) / 100) : String(Math.round(next));
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        });
    });
    root.querySelectorAll('[data-acs-slider-for]').forEach(function (slider) {
        var target = root.querySelector('[name="' + slider.getAttribute('data-acs-slider-for') + '"]');
        if (!target) return;
        slider.addEventListener('input', function () {
            target.value = slider.value;
            target.dispatchEvent(new Event('input', { bubbles: true }));
        });
        target.addEventListener('input', function () { slider.value = target.value; });
    });
    root.querySelectorAll('[data-acs-count]').forEach(function (area) {
        var out = root.querySelector('[data-acs-count-for="' + area.name + '"]');
        if (!out) return;
        var render = function () { out.textContent = area.value.length + ' / ' + area.maxLength; };
        area.addEventListener('input', render);
        render();
    });

    /* ACS_MARKER_JS_2 */

    // 颜色：hex 输入框与色板
    var SWATCHES = [
        '#FFFFFF', '#F8FAFC', '#F3F4F6', '#E5E7EB', '#D1D5DB', '#9CA3AF', '#6B7280', '#374151', '#111827', '#000000',
        '#EEF2FF', '#C7D2FE', '#818CF8', '#4F46E5', '#3730A3', '#DBEAFE', '#60A5FA', '#2563EB', '#1D4ED8', '#0EA5A5',
        '#ECFDF5', '#6EE7B7', '#10B981', '#059669', '#065F46', '#FEF3C7', '#FCD34D', '#F59E0B', '#D97706', '#92400E',
        '#FFE4E6', '#FDA4AF', '#F43F5E', '#DC2626', '#7F1D1D', '#FAE8FF', '#E879F9', '#A855F7', '#7C3AED', '#4C1D95'
    ];
    root.querySelectorAll('[data-acs-color]').forEach(function (wrap) {
        var picker = wrap.querySelector('input[type="color"]');
        var hex = wrap.querySelector('[data-acs-color-hex]');
        var more = wrap.querySelector('[data-acs-color-more]');
        if (!picker || !hex) return;

        picker.addEventListener('input', function () {
            hex.value = picker.value.toUpperCase();
            sync();
        });
        hex.addEventListener('input', function () {
            var v = hex.value.trim();
            if (/^#[0-9a-f]{6}$/i.test(v)) {
                picker.value = v;
                picker.dispatchEvent(new Event('input', { bubbles: true }));
            }
        });
        hex.addEventListener('blur', function () { hex.value = picker.value.toUpperCase(); });

        if (!more) return;
        more.addEventListener('click', function () {
            var open = wrap.querySelector('.acs-a-swatches');
            if (open) { open.remove(); return; }
            var pop = el('div', 'acs-a-swatches');
            SWATCHES.forEach(function (color) {
                var swatch = el('button', 'acs-a-swatch' + (color.toLowerCase() === picker.value.toLowerCase() ? ' is-active' : ''));
                swatch.type = 'button';
                swatch.style.background = color;
                swatch.title = color;
                swatch.addEventListener('click', function () {
                    picker.value = color;
                    picker.dispatchEvent(new Event('input', { bubbles: true }));
                    pop.remove();
                });
                pop.appendChild(swatch);
            });
            wrap.appendChild(pop);
            window.setTimeout(function () {
                document.addEventListener('click', function close(event) {
                    if (pop.contains(event.target) || more.contains(event.target)) return;
                    pop.remove();
                    document.removeEventListener('click', close);
                });
            }, 0);
        });
    });

    // 密钥可见性
    var reveal = root.querySelector('[data-acs-reveal]');
    if (reveal) {
        reveal.addEventListener('click', function () {
            var input = root.querySelector('#custom_api_key');
            if (!input) return;
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            reveal.innerHTML = '';
            reveal.appendChild(icon(show ? 'bi-eye-slash' : 'bi-eye'));
        });
    }

    // 模型来源联动：独立接口相关字段只在 custom 模式下露出
    function syncProvider() {
        var mode = val('provider_mode', 'system');
        root.querySelectorAll('[data-acs-field="custom_api_endpoint"],[data-acs-field="custom_model"],[data-acs-field="custom_api_key"]')
            .forEach(function (node) { node.hidden = mode !== 'custom'; });
        var systemField = root.querySelector('[data-acs-field="system_model_id"]');
        if (systemField) systemField.hidden = mode !== 'system';
        warnModelUnset(mode);
    }

    /* 挂件开着但模型没配好，访客每一句都会撞到「暂时无法回复」，而后台原来一点提示都没有 ——
     * 站点没有启用的对话模型时那个下拉框直接是空的，看起来就像"没什么要选"。
     * 这条提示挂在模型字段下面，只在真的会出问题时出现。 */
    function warnModelUnset(mode) {
        var anchor = root.querySelector('[data-acs-field="provider_mode"]');
        if (!anchor) return;
        var old = root.querySelector('[data-acs-model-warn]');
        if (old) old.remove();

        var text = '';
        if (mode === 'system') {
            var models = Array.isArray(BOOT.models) ? BOOT.models : [];
            if (!models.length) {
                text = '这个站点还没有启用任何支持对话的 AI 模型，客服现在回答不了任何问题。'
                    + '请先去「AI 设置」里启用一个服务商与对话模型，或把上面的模型来源改成「独立接口」。';
            } else if (Math.round(num('system_model_id', 0)) <= 0) {
                text = '还没有选择系统模型，客服现在回答不了任何问题。';
            }
        } else if (mode === 'custom') {
            var missing = [];
            if (val('custom_api_endpoint', '') === '') missing.push('接口地址');
            if (val('custom_model', '') === '') missing.push('模型名');
            /* 密钥框永不回显，值恒为空——只看值就永远判断不出"没配过"，而它又不是声明式
             * 字段（val() 找的是 setting_*），只能直接按 id 取。服务端下发的 customKeySet
             * 才是"存过没有"的唯一依据；缺了这一条，独立接口少了密钥也一声不响，
             * 站长只会在前台看到「暂时无法回复」。勾了"清除"等于这次保存后就没有了。 */
            var keyInput = root.querySelector('#custom_api_key');
            var clearKey = root.querySelector('#clear_custom_api_key');
            var typedKey = keyInput ? String(keyInput.value || '').trim() !== '' : false;
            var keptKey = BOOT.customKeySet && !(clearKey && clearKey.checked);
            if (!keptKey && !typedKey) missing.push('密钥');
            if (missing.length) text = '独立接口还缺：' + missing.join('、') + '。填齐之前客服回答不了任何问题。';
        }
        if (text === '') return;

        var bar = el('div', 'acs-a-alert acs-a-alert--warn', text);
        bar.setAttribute('data-acs-model-warn', '');
        if (anchor.parentNode) anchor.parentNode.insertBefore(bar, anchor.nextSibling);
    }

    /* ---------------------------------------------------------------- 面板注册 */

    var PANELS = {};

    function mountAll() {
        root.querySelectorAll('[data-acs-mount]').forEach(function (host) {
            var name = host.getAttribute('data-acs-mount');
            if (!PANELS[name]) return;
            try {
                PANELS[name](host);
            } catch (error) {
                host.appendChild(el('div', 'acs-a-alert acs-a-alert--warn', '这个面板加载失败：' + error.message));
            }
        });
    }

    /* ACS_MARKER_JS_3 */

    /* ---- 预设主题：点一下把一整组字段值写进表单（保存才落库） ---- */

    /* 默认预设的键，必须与 PHP defaultTheme()['preset'] 一致（契约测试会比对）。
     * themePresets() 早先改过预设的键名，这里漏改了一处，于是取到 undefined
     * 再读它的 .values，「恢复浅色默认」直接抛 TypeError。 */
    var DEFAULT_PRESET = 'plain';

    /* 缩略图上要说人话的轴。之前缩略图只画了「一条顶栏 + 两个固定圆角的色块」，
     * 于是六套预设在后台看起来只有颜色不同 —— 而实际上圆角、投影、气泡形态、
     * 密度、字体全都不一样。差异不摆出来等于没做。 */
    var AXIS_LABEL = {
        bubble_style: { soft: '柔和气泡', flat: '直角气泡', outline: '描边气泡', glass: '毛玻璃气泡', sketch: '手绘气泡' },
        density: { compact: '紧凑', cozy: '舒适', roomy: '宽松' },
        header_style: { solid: '实色顶栏', light: '浅色顶栏', accent: '强调色顶栏', slim: '无顶栏' },
        quick_style: { capsule: '胶囊快捷', ghost: '幽灵快捷', sketch: '手绘快捷' },
        bubble_anim: { none: '无动效', rise: '上浮', pop: '弹入', fade: '淡入' },
        typing: { dots: '三点等待', wave: '波浪等待', text: '文字等待' },
        edge: { hairline: '细线边缘', flat: '全平', glass: '玻璃质感', glow: '强调色发光', offset: '硬偏移', bevel: '内斜面' },
        type: { neutral: '常规字面', tight: '紧凑字面', loose: '疏朗字面' }
    };
    var SHADOW_LABEL = { none: '无投影', sm: '微投影', md: '中投影', lg: '重投影' };
    var FONT_LABEL = { system: '系统字体', inter: 'Inter', pingfang: '苹方', serif: '宋体', rounded: '圆体', mono: '等宽' };
    var LAUNCHER_LABEL = { bubble: '圆浮标', pill: '胶囊浮标' };

    /** 从预设值里挑出「非颜色」的差异，做成一行芯片。
     * 排序刻意让**闭合态**（浮标形态、尺寸）排在最前：挂件九成时间只露那颗浮标，
     * 站长在画廊里第一眼要能判断"关着的时候长什么样"，而不是先看到聊天区细节。
     * 投影只在真的打了投影时才进芯片——六套预设一律不设投影（那是站长自己的开关），
     * 无条件推入等于六张卡共享一条无信息量的标签。 */
    function presetAxisChips(v) {
        var theme = v.theme || {};
        var chips = [];
        if (v.launcher_style) chips.push(LAUNCHER_LABEL[v.launcher_style] || v.launcher_style);
        if (v.widget_size !== undefined) chips.push('浮标 ' + v.widget_size);
        if (v.panel_radius !== undefined) chips.push('圆角 ' + v.panel_radius);
        if (v.panel_shadow && v.panel_shadow !== 'none') chips.push(SHADOW_LABEL[v.panel_shadow] || v.panel_shadow);
        if (v.font_family) chips.push(FONT_LABEL[v.font_family] || v.font_family);
        ['edge', 'type', 'density', 'header_style', 'bubble_style', 'quick_style', 'bubble_anim'].forEach(function (axis) {
            var value = theme[axis];
            if (value && AXIS_LABEL[axis] && AXIS_LABEL[axis][value]) chips.push(AXIS_LABEL[axis][value]);
        });
        return chips;
    }

    /** 预设缩略图：一个真的迷你挂件。圆角 / 边缘语言 / 字面 / 顶栏 / 气泡 / 快捷 / 密度 /
     * 字体，以及**闭合态那颗浮标**（形态、直径、圆角）都照预设自己的值画。
     * 浮标要单独喂一遍，是因为挂件九成时间只露它——画廊里如果六张卡的浮标一模一样，
     * 站长就永远看不出"这六套关着的时候不一样"。 */
    function presetThumb(v) {
        var theme = v.theme || {};
        var thumb = el('div', 'acs-a-preset-thumb');
        thumb.style.setProperty('--pv-surface', v.surface_color || '#fff');
        thumb.style.setProperty('--pv-header', v.header_color || '#1677ff');
        thumb.style.setProperty('--pv-header-fg', v.header_text_color || '#111827');
        thumb.style.setProperty('--pv-text', v.text_color || '#111827');
        thumb.style.setProperty('--pv-muted', v.muted_color || '#6B7280');
        thumb.style.setProperty('--pv-bot', v.bot_bubble_color || '#f3f4f6');
        thumb.style.setProperty('--pv-bot-fg', v.bot_bubble_text_color || '#111827');
        thumb.style.setProperty('--pv-vis', v.visitor_bubble_color || '#1677ff');
        thumb.style.setProperty('--pv-vis-fg', v.visitor_bubble_text_color || '#fff');
        thumb.style.setProperty('--pv-accent', v.accent_color || '#4F46E5');
        thumb.style.setProperty('--pv-radius', (v.panel_radius === undefined ? 14 : v.panel_radius) + 'px');
        thumb.style.setProperty('--pv-font', (BOOT.fonts && BOOT.fonts[v.font_family]) || 'inherit');
        // 浮标：缩略图宽度约是真实面板的 .38，直径同比缩，并夹在能看清的区间里
        var size = numOr(v.widget_size, 56);
        thumb.style.setProperty('--pv-launcher-size', Math.max(14, Math.min(26, Math.round(size * 0.34))) + 'px');
        // 浮标圆角在后台是 0–28（28 即"全圆"），缩略图直径只有 1/3，按同一比例折算
        thumb.style.setProperty('--pv-launcher-corner', Math.round(numOr(v.launcher_corner, 10) * 3.4) / 10 + 'px');
        thumb.setAttribute('data-pv-shadow', v.panel_shadow || 'none');
        thumb.setAttribute('data-pv-bubble', theme.bubble_style || 'soft');
        thumb.setAttribute('data-pv-head', theme.header_style || 'light');
        thumb.setAttribute('data-pv-quick', theme.quick_style || 'capsule');
        thumb.setAttribute('data-pv-density', theme.density || 'cozy');
        thumb.setAttribute('data-pv-edge', theme.edge || 'hairline');
        thumb.setAttribute('data-pv-type', theme.type || 'neutral');
        thumb.setAttribute('data-pv-launcher', v.launcher_style || 'bubble');

        var panel = el('div', 'acs-a-pv-panel');
        var head = el('div', 'acs-a-pv-head');
        head.appendChild(el('span', 'acs-a-pv-avatar'));
        var copy = el('div', 'acs-a-pv-copy');
        copy.appendChild(el('strong', '', '在线客服'));
        copy.appendChild(el('span', '', '通常几分钟内回复'));
        head.appendChild(copy);
        panel.appendChild(head);

        var body = el('div', 'acs-a-pv-body');
        body.appendChild(el('div', 'acs-a-pv-bot', '您好，需要帮您找什么？'));
        body.appendChild(el('div', 'acs-a-pv-vis', '看看报价'));
        body.appendChild(el('div', 'acs-a-pv-bot acs-a-pv-bot--short', '好的'));
        panel.appendChild(body);

        var quick = el('div', 'acs-a-pv-quick');
        quick.appendChild(el('b', '', '价格'));
        quick.appendChild(el('b', '', '交期'));
        panel.appendChild(quick);

        thumb.appendChild(panel);
        thumb.appendChild(el('span', 'acs-a-pv-launcher'));
        return thumb;
    }

    PANELS.presets = function (host) {
        var theme = readJson('theme_json', {});
        var gallery = el('div', 'acs-a-presets');
        Object.keys(BOOT.presets).forEach(function (id) {
            var preset = BOOT.presets[id];
            var v = preset.values || {};
            var card = el('button', 'acs-a-preset' + (theme.preset === id ? ' is-active' : ''));
            card.type = 'button';
            card.setAttribute('data-acs-preset', id);
            card.appendChild(presetThumb(v));
            card.appendChild(el('div', 'acs-a-preset-name', preset.label));
            card.appendChild(el('div', 'acs-a-preset-note', preset.note));
            var axes = el('div', 'acs-a-preset-axes');
            presetAxisChips(v).forEach(function (text) { axes.appendChild(el('span', '', text)); });
            card.appendChild(axes);
            card.addEventListener('click', function () {
                applyPreset(id, v);
                gallery.querySelectorAll('.acs-a-preset').forEach(function (n) { n.classList.remove('is-active'); });
                card.classList.add('is-active');
            });
            gallery.appendChild(card);
        });
        host.appendChild(gallery);
    };

    /** 按键套用预设。键不存在时什么都不做，不要让一个改名把整块面板带崩。 */
    function applyPresetById(id) {
        var preset = BOOT.presets[id];
        if (!preset) return;
        applyPreset(id, preset.values || {});
    }

    function applyPreset(id, values) {
        var theme = readJson('theme_json', {});
        Object.keys(values).forEach(function (key) {
            if (key === 'theme') {
                Object.keys(values.theme).forEach(function (sub) { theme[sub] = values.theme[sub]; });
                return;
            }
            setCtrl(key, values[key]);
        });
        theme.preset = id;
        jsonCache.theme_json = theme;
        writeJson('theme_json');
        renderThemeControls();
        toast('已套用「' + (BOOT.presets[id] ? BOOT.presets[id].label : id) + '」，记得点保存');
    }

    /* ---- 配色：分组快捷 + 一键取反（深色底自动配亮字） ---- */
    PANELS.palette = function (host) {
        var box = el('div', 'acs-a-palette');
        var row = el('div', 'acs-a-palette-row');
        row.appendChild(el('span', '', '快捷：'));
        [
            ['顶栏跟随主色', function () { setCtrl('header_color', val('accent_color', '#4F46E5')); }],
            ['访客气泡跟随主色', function () { setCtrl('visitor_bubble_color', val('accent_color', '#4F46E5')); }],
            ['按背景自动配文字色', autoContrast],
            ['恢复浅色默认', function () { applyPresetById(DEFAULT_PRESET); }]
        ].forEach(function (pair) {
            var button = el('button', 'acs-a-btn acs-a-btn--sm', pair[0]);
            button.type = 'button';
            button.addEventListener('click', pair[1]);
            row.appendChild(button);
        });
        box.appendChild(row);
        box.appendChild(el('p', 'acs-a-help', '「窗口正文颜色」和「次要文字颜色」是窗口里正文与提示的实际颜色。把窗口背景调深时一定要一起调亮，否则会出现深底深字。'));
        host.appendChild(box);
        renderThemeControls(host);
    };

    /* 深底判定阈值。必须与 PHP 的 AiCustomerService::TONE_DARK_MAX 相同，
     * 否则同一套配色在后台预览与前台会判成不同色调（契约测试会比对这两个数）。 */
    var TONE_DARK_MAX = 0.18;

    function luminance(hex) {
        var m = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(String(hex || '').trim());
        if (!m) return 1;
        var parts = [1, 2, 3].map(function (i) {
            var c = parseInt(m[i], 16) / 255;
            return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
        });
        return 0.2126 * parts[0] + 0.7152 * parts[1] + 0.0722 * parts[2];
    }
    function autoContrast() {
        var dark = luminance(val('surface_color', '#FFFFFF')) < 0.45;
        setCtrl('text_color', dark ? '#E5E7EB' : '#111827');
        setCtrl('muted_color', dark ? '#94A3B8' : '#6B7280');
        var botDark = luminance(val('bot_bubble_color', '#F4F4F5')) < 0.45;
        setCtrl('bot_bubble_text_color', botDark ? '#E5E7EB' : '#111827');
        setCtrl('visitor_bubble_text_color', luminance(val('visitor_bubble_color', '#4F46E5')) < 0.5 ? '#FFFFFF' : '#111827');
        setCtrl('header_text_color', luminance(val('header_color', '#FFFFFF')) < 0.5 ? '#FFFFFF' : '#111827');
        toast('已按背景亮度配好文字颜色');
    }

    /* ACS_MARKER_JS_4 */

    /* 通用：一组分段选择器，绑到某个 JSON 字段的某个键上 */
    function segmented(jsonKey, path, label, options, afterChange) {
        var data = readJson(jsonKey, {});
        var wrap = el('div', 'acs-a-sub');
        wrap.appendChild(el('h3', '', label));
        var group = el('div', 'acs-a-segmented');
        group.setAttribute('role', 'group');
        group.setAttribute('aria-label', label);
        Object.keys(options).forEach(function (value) {
            var button = el('button', data[path] === value ? 'is-active' : '', options[value]);
            button.type = 'button';
            button.setAttribute('aria-pressed', data[path] === value ? 'true' : 'false');
            button.addEventListener('click', function () {
                data[path] = value;
                jsonCache[jsonKey] = data;
                writeJson(jsonKey);
                group.querySelectorAll('button').forEach(function (n) {
                    n.classList.remove('is-active');
                    n.setAttribute('aria-pressed', 'false');
                });
                button.classList.add('is-active');
                button.setAttribute('aria-pressed', 'true');
                if (afterChange) afterChange();
            });
            group.appendChild(button);
        });
        wrap.appendChild(group);
        return wrap;
    }

    var themeHost = null;
    function renderThemeControls(host) {
        if (host) themeHost = host;
        if (!themeHost) return;
        var old = themeHost.querySelector('[data-acs-theme-controls]');
        if (old) old.remove();
        var grid = el('div', 'acs-a-subgrid');
        grid.setAttribute('data-acs-theme-controls', '');
        grid.appendChild(segmented('theme_json', 'bubble_style', '气泡形态', {
            soft: '柔和', flat: '直角', outline: '描边', glass: '毛玻璃', sketch: '手绘'
        }));
        grid.appendChild(segmented('theme_json', 'bubble_anim', '气泡动效', { none: '无', rise: '上浮', pop: '弹入', fade: '淡入' }));
        grid.appendChild(segmented('theme_json', 'typing', '打字指示', { dots: '三点', wave: '波浪', text: '文字' }));
        // 这几组的可选值必须与 normalizeTheme() 的白名单逐字对齐：多列一个选项，
        // 选了保存后会被静默改回默认值，读起来像"保存失败"。
        // （曾经这里列过"渐变"顶栏就是这个毛病。）
        grid.appendChild(segmented('theme_json', 'header_style', '顶栏', { solid: '纯色', light: '浅底', accent: '主色', slim: '极简' }));
        grid.appendChild(segmented('theme_json', 'quick_style', '快捷问题', { capsule: '胶囊', ghost: '幽灵', sketch: '手绘' }));
        grid.appendChild(segmented('theme_json', 'density', '密度', { compact: '紧凑', cozy: '舒适', roomy: '宽松' }));
        // 边缘语言：面板靠什么和站点分开。这是六套预设里差异带宽最大的一维，
        // 和"弹窗投影强度"是叠加关系而不是二选一。
        grid.appendChild(segmented('theme_json', 'edge', '边缘', {
            hairline: '细线', flat: '全平', glass: '玻璃', glow: '发光', offset: '硬边', bevel: '斜面'
        }));
        // 字面：只动字重/字距/行高。字族在没装 Inter 的中文站点上基本塌成同一种，
        // 这三个量在任何已装字体上都一定生效。
        grid.appendChild(segmented('theme_json', 'type', '字面', { neutral: '常规', tight: '紧凑', loose: '疏朗' }));
        themeHost.appendChild(grid);
    }

    /* ---- 拖拽布局：偏移数值 + 面板对齐 ---- */
    PANELS.layout = function (host) {
        var layout = readJson('layout_json', {});
        var grid = el('div', 'acs-a-subgrid');
        grid.appendChild(segmented('layout_json', 'panel_align', '窗口对齐', { right: '贴右', left: '贴左', center: '居中' }));

        [['teaser', '引流气泡'], ['ribbon', '飘带'], ['badge', '角标'], ['launcher_nudge', '浮标微调']].forEach(function (pair) {
            var key = pair[0];
            var box = el('div', 'acs-a-sub');
            box.appendChild(el('h3', '', pair[1] + ' 偏移'));
            var row = el('div', 'acs-a-palette-row');
            ['dx', 'dy'].forEach(function (axis) {
                var field = el('label', 'acs-a-checkline');
                field.appendChild(el('span', '', axis === 'dx' ? '横向' : '纵向'));
                var input = el('input', 'acs-a-input');
                input.type = 'number';
                input.style.width = '76px';
                input.value = String((layout[key] && layout[key][axis]) || 0);
                input.addEventListener('input', function () {
                    if (!layout[key]) layout[key] = { dx: 0, dy: 0 };
                    layout[key][axis] = parseInt(input.value, 10) || 0;
                    jsonCache.layout_json = layout;
                    writeJson('layout_json');
                });
                input.setAttribute('data-acs-nudge', key + '.' + axis);
                field.appendChild(input);
                row.appendChild(field);
            });
            box.appendChild(row);
            grid.appendChild(box);
        });
        host.appendChild(grid);
        host.appendChild(el('p', 'acs-a-help', '右侧预览打开「拖拽定位」即可直接拖动。'));
    };

    /* ACS_MARKER_JS_5 */

    /* 通用：可增删的行列表编辑器 */
    function listEditor(options) {
        var host = el('div', 'acs-a-list');
        function repaint() {
            host.innerHTML = '';
            var items = options.items();
            if (!items.length) {
                host.appendChild(el('div', 'acs-a-empty', options.empty));
            } else {
                items.forEach(function (item, index) {
                    host.appendChild(options.row(item, index, repaint));
                });
            }
            var add = el('button', 'acs-a-btn acs-a-btn--ghost', options.addLabel);
            add.type = 'button';
            add.prepend(icon('bi-plus-lg'));
            add.addEventListener('click', function () { options.add(); repaint(); });
            host.appendChild(add);
        }
        repaint();
        return host;
    }

    /**
     * 列表行 + 一个移除按钮。
     *
     * confirmText 给了就挂宿主后台的 data-confirm：它有一个捕获阶段的委托监听器，会先
     * 弹 popconfirm、确认后再把这次点击原样重放回来（运行时没起来时退回原生 confirm）。
     * 复用它而不是自己写一个，破坏性操作的观感才和后台其余部分一致。
     * 大多数行删掉就是少一条配置，随手能加回来，不值得多一步；真正会丢东西的（知识库
     * 文件：抽取出的文本在服务端删掉，原文件本来就没留）才传。
     */
    function rowShell(title, meta, iconName, onRemove, confirmText) {
        var row = el('div', 'acs-a-row');
        var badge = el('div', 'acs-a-row-icon');
        badge.appendChild(icon(iconName));
        row.appendChild(badge);
        var main = el('div', 'acs-a-row-main');
        main.appendChild(el('div', 'acs-a-row-title', title));
        if (meta) main.appendChild(el('div', 'acs-a-row-meta', meta));
        row.appendChild(main);
        var remove = el('button', 'acs-a-icon-btn');
        remove.type = 'button';
        remove.title = '移除';
        remove.setAttribute('aria-label', '移除 ' + title);
        if (confirmText) {
            remove.setAttribute('data-confirm', confirmText);
            remove.setAttribute('data-confirm-danger', '');
        }
        remove.appendChild(icon('bi-trash3'));
        remove.addEventListener('click', onRemove);
        row.appendChild(remove);
        return row;
    }

    /* ---- 定时主动问候 ---- */
    PANELS.greeting = function (host) {
        var greeting = readJson('greeting_json', { enabled: false, steps: [] });
        if (!Array.isArray(greeting.steps)) greeting.steps = [];
        var box = el('div', 'acs-a-sub');
        box.appendChild(el('h3', '', '定时主动问候'));
        box.appendChild(el('p', 'acs-a-sub-note', '窗口出现后按秒数依次发出；访客一回复就停。适合"看了半分钟还没问"的场景。'));

        var toggles = el('div', 'acs-a-palette-row');
        [['enabled', '启用'], ['once_per_session', '每会话只发一轮'], ['stop_after_reply', '访客回复后停止']].forEach(function (pair) {
            var label = el('label', 'acs-a-switch');
            var input = el('input');
            input.type = 'checkbox';
            input.checked = !!greeting[pair[0]];
            input.addEventListener('change', function () {
                greeting[pair[0]] = input.checked;
                jsonCache.greeting_json = greeting;
                writeJson('greeting_json');
            });
            label.appendChild(input);
            var track = el('span', 'acs-a-switch-track');
            track.setAttribute('aria-hidden', 'true');
            track.appendChild(el('span', 'acs-a-switch-thumb'));
            label.appendChild(track);
            label.appendChild(el('span', 'acs-a-switch-label', pair[1]));
            toggles.appendChild(label);
        });
        box.appendChild(toggles);

        box.appendChild(listEditor({
            items: function () { return greeting.steps; },
            empty: '还没有问候语。加一条，比如"看了一会儿了，需要我帮您对比一下吗？"',
            addLabel: '加一条问候',
            add: function () {
                if (greeting.steps.length >= 6) { toast('最多 6 条问候', true); return; }
                greeting.steps.push({ after: 20 + greeting.steps.length * 30, text: '' });
                jsonCache.greeting_json = greeting;
                writeJson('greeting_json');
            },
            row: function (step, index, repaint) {
                var row = el('div', 'acs-a-row');
                var badge = el('div', 'acs-a-row-icon');
                badge.appendChild(icon('bi-stopwatch'));
                row.appendChild(badge);
                var main = el('div', 'acs-a-row-main');
                var grid = el('div', 'acs-a-tool-grid');
                var after = el('input', 'acs-a-input');
                after.type = 'number';
                after.min = '3';
                after.max = '900';
                after.value = String(step.after || 20);
                after.setAttribute('aria-label', '第 ' + (index + 1) + ' 条的延迟秒数');
                after.addEventListener('input', function () {
                    step.after = Math.max(3, Math.min(900, parseInt(after.value, 10) || 20));
                    jsonCache.greeting_json = greeting;
                    writeJson('greeting_json');
                });
                var text = el('input', 'acs-a-input');
                text.maxLength = 200;
                text.placeholder = '第 ' + (index + 1) + ' 条问候内容';
                text.value = step.text || '';
                text.addEventListener('input', function () {
                    step.text = text.value;
                    jsonCache.greeting_json = greeting;
                    writeJson('greeting_json');
                });
                grid.appendChild(after);
                grid.appendChild(text);
                main.appendChild(grid);
                main.appendChild(el('div', 'acs-a-row-meta', '第 ' + (step.after || 20) + ' 秒发出'));
                row.appendChild(main);
                var remove = el('button', 'acs-a-icon-btn');
                remove.type = 'button';
                remove.setAttribute('aria-label', '删除第 ' + (index + 1) + ' 条问候');
                remove.appendChild(icon('bi-trash3'));
                remove.addEventListener('click', function () {
                    greeting.steps.splice(index, 1);
                    jsonCache.greeting_json = greeting;
                    writeJson('greeting_json');
                    repaint();
                });
                row.appendChild(remove);
                return row;
            }
        }));
        host.appendChild(box);
    };

    /* 与 AiCustomerService::defaultExperience() 里的那句必须一模一样：两边都会在
     * 文案被清空时回填它，对不上就会出现「后台显示的默认值和前台实际用的不是一句话」。 */
    var AWAY_TEXT_DEFAULT = '现在不在服务时段。AI 助手仍可先为您解答；留下联系方式，我们上班后回复您。';

    /* ---- 体验增强：时间戳 / 接续会话 / 未读提醒 / 转化事件 / 浮标多渠道 / 非服务时段 ---- */
    PANELS.experience = function (host) {
        var exp = readJson('experience_json', {});
        if (!exp.channels || typeof exp.channels !== 'object') {
            exp.channels = { enabled: false, style: 'fan', title: '其他联系方式', max: 6 };
        }
        if (!exp.away || typeof exp.away !== 'object') {
            exp.away = { mode: 'hide', text: AWAY_TEXT_DEFAULT };
        }
        if (exp.away.mode !== 'notice') exp.away.mode = 'hide';
        if (typeof exp.away.text !== 'string' || exp.away.text === '') exp.away.text = AWAY_TEXT_DEFAULT;
        function save() {
            jsonCache.experience_json = exp;
            writeJson('experience_json');
        }

        var box = el('div', 'acs-a-sub');
        box.appendChild(el('h3', '', '会话体验'));
        box.appendChild(el('p', 'acs-a-sub-note', '这几项只改前台观感，不影响模型行为，也不改动上下文。'));
        var toggles = el('div', 'acs-a-palette-row');
        [
            ['timestamps', '显示消息时间'],
            ['resume', '刷新后接续会话'],
            ['sound', '新回复提示音'],
            ['unread_title', '标签页未读提醒'],
            ['analytics', '上报转化事件']
        ].forEach(function (pair) {
            toggles.appendChild(switchField(pair[1], exp[pair[0]], function (checked) {
                exp[pair[0]] = checked;
                save();
            }));
        });
        box.appendChild(toggles);
        box.appendChild(el('p', 'acs-a-help', '接续会话：记录本来就存在服务端，打开后刷新页面不再是空窗口（换浏览器、或超过 6 小时仍然算新会话）。转化事件：只投给站点已经装好的 gtag / dataLayer，插件自己不加载任何统计脚本。'));
        host.appendChild(box);

        var chan = el('div', 'acs-a-sub');
        chan.appendChild(el('h3', '', '浮标多渠道'));
        chan.appendChild(el('p', 'acs-a-sub-note', '浮标上方多一颗按钮，展开就是微信 / WhatsApp / 电话——想直接联系的访客不用先跟机器人说话。条目取自「工具 → 站长名片」里的社媒，这里不重复维护一份。'));
        var row = el('div', 'acs-a-palette-row');
        row.appendChild(switchField('启用', exp.channels.enabled, function (checked) {
            exp.channels.enabled = checked;
            save();
        }));
        chan.appendChild(row);

        var grid = el('div', 'acs-a-subgrid');
        grid.appendChild(selectField('展开形态', exp.channels.style, { fan: '圆形图标竖排', list: '带文字的卡片' }, function (value) {
            exp.channels.style = value;
            save();
        }));
        grid.appendChild(textField('卡片标题', exp.channels.title, 40, function (value) {
            exp.channels.title = value;
            save();
        }, '其他联系方式'));
        grid.appendChild(numberField('最多显示几个', exp.channels.max, 1, 12, function (value) {
            exp.channels.max = value;
            save();
        }));
        chan.appendChild(grid);

        var owner = readJson('owner_json', {});
        var count = Array.isArray(owner.socials) ? owner.socials.length : 0;
        chan.appendChild(el('p', 'acs-a-help', count > 0
            ? ('「站长名片」里现有 ' + count + ' 个联系方式，展开时按上面的上限依次显示。')
            : '「站长名片」里还没填社媒，这里开了也没有东西可展开。'));
        host.appendChild(chan);

        /* ---- 非服务时段：原来是整个挂件消失，访客在那段时间一点门路都没有 ---- */
        var away = el('div', 'acs-a-sub');
        away.appendChild(el('h3', '', '非服务时段'));
        away.appendChild(el('p', 'acs-a-sub-note',
            '上面「按服务时段显示」开着时才起作用。原来的行为是整个挂件消失——访客在那段时间连留个联系方式的地方都没有。'));
        var awayRow = el('div', 'acs-a-subgrid');
        awayRow.appendChild(selectField('时段外怎么办', exp.away.mode, {
            hide: '整个挂件不出现',
            notice: '照常出现，顶上加一条说明'
        }, function (value) {
            exp.away.mode = value;
            save();
            syncAwayRow();
        }));
        away.appendChild(awayRow);
        var awayText = el('div', 'acs-a-subgrid');
        awayText.appendChild(textField('说明文案', exp.away.text, 200, function (value) {
            exp.away.text = value;
            save();
        }, AWAY_TEXT_DEFAULT));
        away.appendChild(awayText);
        away.appendChild(el('p', 'acs-a-help',
            'AI 仍然会照常回答——聊天接口不看服务时段，否则访客正聊着到了下班点就会被中途掐断。'
            + '这条说明的用处是让访客知道「人工要等到上班」，顺手把询盘留下来。'));

        /* 时段判定用的是站点时区，而它跟站长本人所在的时区可能不是一回事。
         * 把服务端此刻的读数摆出来，配完就能当场对一眼。 */
        var sched = BOOT.schedule && typeof BOOT.schedule === 'object' ? BOOT.schedule : null;
        if (sched) {
            var DAY_NAMES = ['', '周一', '周二', '周三', '周四', '周五', '周六', '周日'];
            away.appendChild(el('p', 'acs-a-help',
                '站点时区 ' + String(sched.tz || '未知') + '，服务端此刻是 '
                + (DAY_NAMES[Number(sched.day)] || '') + ' ' + String(sched.now || '')
                + '，按当前设置' + (sched.open ? '在' : '不在') + '服务时段内。'
                + '访客那边由浏览器按同一个时区现算，所以整页缓存不会把上午的结果留到晚上。'));
        }
        function syncAwayRow() { awayText.hidden = exp.away.mode !== 'notice'; }
        syncAwayRow();
        host.appendChild(away);
    };

    /* ---- 按国家 / 语言显示：只管浮标要不要出现在这个访客眼里 ---- */
    PANELS.targeting = function (host) {
        var t = readJson('targeting_json', {});
        if (!Array.isArray(t.countries)) t.countries = [];
        if (!Array.isArray(t.languages)) t.languages = [];
        if (t.country_mode !== 'allow' && t.country_mode !== 'deny') t.country_mode = 'off';
        if (t.language_mode !== 'allow' && t.language_mode !== 'deny') t.language_mode = 'off';

        /* 原地洗数组：tagInput 攥着这个数组的引用，换成新数组它就画不到了。
         * 规则和服务端 normalizeTargeting() 一模一样，敲的 cn 当场变成 CN，
         * 不用等保存完再回来疑惑自己填的东西去哪了。 */
        function scrub(values, limit, map) {
            var seen = {}, out = [], bad = 0;
            values.forEach(function (raw) {
                var v = map(String(raw));
                if (v === '') { bad += 1; return; }
                if (seen[v] || out.length >= limit) return;
                seen[v] = true;
                out.push(v);
            });
            values.length = 0;
            out.forEach(function (v) { values.push(v); });
            return bad;
        }
        function save() {
            var badC = scrub(t.countries, 60, function (raw) {
                var v = raw.trim().toUpperCase().replace(/[^A-Z]/g, '');
                return v.length === 2 ? v : '';
            });
            var badL = scrub(t.languages, 30, function (raw) {
                var v = raw.trim().toLowerCase().replace(/_/g, '-');
                return /^[a-z]{2,3}(-[a-z0-9]{2,8})*$/.test(v) ? v : '';
            });
            jsonCache.targeting_json = t;
            writeJson('targeting_json');
            if (badC) toast('国家要填两位字母代码（CN、US、DE），已忽略 ' + badC + ' 条', true);
            if (badL) toast('语言要填 BCP-47 标签（zh、zh-cn、en），已忽略 ' + badL + ' 条', true);
            paintHint();
        }
        /* 定向面板必须能自证：把服务端这一次读到的国家 / 语言摊开写出来，
         * 站长才分得清"浮标不见了"是规则生效还是根本没有地区请求头。 */
        function paintHint() {
            var geo = BOOT.geo || {};
            var langs = Array.isArray(geo.languages) ? geo.languages : [];
            var parts = [geo.country
                ? ('服务端在这次后台请求里读到的国家是 ' + geo.country + '；')
                : '服务端这次读不到国家（没有地区请求头）；'];
            parts.push(langs.length ? ('浏览器语言 ' + langs.slice(0, 5).join('、') + '。') : '也读不到浏览器语言。');
            if (t.country_mode === 'allow' && t.countries.length && !geo.country) {
                parts.push('⚠ 读不到国家时「只显示给名单里的」会对所有访客隐藏浮标，先确认 CDN 传了地区请求头再开。');
            }
            if (t.language_mode === 'allow' && t.languages.length && !langs.length) {
                parts.push('⚠ 读不到语言时「只显示给名单里的」会对所有访客隐藏浮标。');
            }
            if (t.country_mode !== 'off' && !t.countries.length) parts.push('⚠ 国家名单是空的，保存后这条规则会自动回到「不限制」。');
            if (t.language_mode !== 'off' && !t.languages.length) parts.push('⚠ 语言名单是空的，保存后这条规则会自动回到「不限制」。');
            hint.textContent = parts.join(' ');
        }

        var modes = { off: '不限制', allow: '只显示给名单里的', deny: '名单里的不显示' };
        var box = el('div', 'acs-a-sub');
        box.appendChild(el('h3', '', '按国家 / 语言显示'));
        box.appendChild(el('p', 'acs-a-sub-note', '两个维度各自独立，任一条判定「不显示」浮标就整个不渲染。这是展示层的定向，不是访问控制：请求头能伪造，聊天接口也故意不按地区拦——已经开着的会话不该因为 CDN 抖一下就断掉。'));
        var grid = el('div', 'acs-a-subgrid');
        grid.appendChild(selectField('国家 / 地区', t.country_mode, modes, function (v) { t.country_mode = v; save(); }));
        grid.appendChild(selectField('语言', t.language_mode, modes, function (v) { t.language_mode = v; save(); }));
        box.appendChild(grid);
        var hint = el('p', 'acs-a-help');
        box.appendChild(hint);
        host.appendChild(box);

        var lists = el('div', 'acs-a-subgrid');
        lists.appendChild(tagInput('国家 / 地区代码', '两位 ISO-3166 代码，最多 60 条。国家取自 CDN 传的地区请求头（Cloudflare、CloudFront、或自己配的 X-Country-Code），插件不带 IP 库、也不去查第三方接口。', t.countries, save, '例如 CN'));
        lists.appendChild(tagInput('语言标签', '取自浏览器的 Accept-Language。填 zh 能同时命中 zh-CN / zh-TW；要精确就填 zh-cn。最多 30 条。', t.languages, save, '例如 zh-cn'));
        host.appendChild(lists);
        paintHint();
    };

    /* ---- 聊天前的同意确认 ---- */
    PANELS.consent = function (host) {
        var c = readJson('consent_json', {});
        function save() { jsonCache.consent_json = c; writeJson('consent_json'); }

        var box = el('div', 'acs-a-sub');
        box.appendChild(el('h3', '', '聊天前的同意确认'));
        box.appendChild(el('p', 'acs-a-sub-note', '打开后访客第一次发消息前要先勾一次同意：输入框和快捷问题都锁着，勾完点按钮才解锁。同意记在服务端会话里，一个会话只问一次；聊天接口那边也拦一道，前台被 CDN 缓存住了也绕不过去。'));
        var row = el('div', 'acs-a-palette-row');
        row.appendChild(switchField('启用', c.enabled, function (v) { c.enabled = v; save(); }));
        box.appendChild(row);
        host.appendChild(box);

        var grid = el('div', 'acs-a-subgrid');
        grid.appendChild(textField('标题', c.title, 60, function (v) { c.title = v; save(); }, '开始对话前'));
        grid.appendChild(textField('按钮文案', c.button, 40, function (v) { c.button = v; save(); }, '同意并开始'));
        grid.appendChild(textField('链接文字', c.link_label, 60, function (v) { c.link_label = v; save(); }, '隐私政策'));
        grid.appendChild(textField('链接地址', c.link_url, 500, function (v) { c.link_url = v; save(); }, '/privacy'));
        host.appendChild(grid);

        var wrap = el('div', 'acs-a-sub');
        wrap.appendChild(textareaField('勾选框旁边的正文', c.text, 600, function (v) { c.text = v; save(); }, '我同意本站按隐私政策处理我在对话中提交的信息。'));
        wrap.appendChild(el('p', 'acs-a-help', '标题、正文、按钮清空了会各自回落到默认文案——开着开关又把文字删干净，得到的是一个点不动的空门槛。链接文字和地址要两个都填才会渲染链接，只填一个当没填。'));
        host.appendChild(wrap);
    };

    /* ACS_MARKER_JS_6 */

    /* ---- 资料柜：文件夹卡（点击展开）+ 上传 + 站内内容挑选 ---- */
    PANELS.knowledge = function (host) {
        var knowledge = readJson('knowledge_json', { sources: [], files: [], auto: {} });
        if (!Array.isArray(knowledge.sources)) knowledge.sources = [];
        if (!Array.isArray(knowledge.files)) knowledge.files = [];
        if (!knowledge.auto || typeof knowledge.auto !== 'object') knowledge.auto = {};

        var wrap = el('div', 'acs-a-cabinet');
        var side = el('div', 'acs-a-cabinet-side');
        side.appendChild(folderCard(knowledge));
        var dropzone = el('div', 'acs-a-dropzone', '把文件拖到这里，或点上面的文件夹选择');
        side.appendChild(dropzone);
        // 三条限制写在这里，不然只能靠撞上去才知道（单个 4MB 是服务端的硬上限）
        side.appendChild(el('p', 'acs-a-help', '支持 txt / md / csv / tsv / json / html / xml / docx / pdf，'
            + '单个文件 4 MB 以内，一次最多 10 个，资料柜共 60 个。只保留抽取出来的纯文本，原文件不留。'));
        side.appendChild(el('p', 'acs-a-help', '支持 txt / md / csv / json / html / xml / docx / pdf，单个 ≤ 4 MB。系统只保留抽取出的纯文本，原文件不入库、也不对外可下载。'));
        wrap.appendChild(side);

        var right = el('div');
        right.appendChild(fileList(knowledge));
        right.appendChild(sourcePicker(knowledge));
        wrap.appendChild(right);
        host.appendChild(wrap);

        // 拖放上传
        ['dragenter', 'dragover'].forEach(function (type) {
            dropzone.addEventListener(type, function (event) {
                event.preventDefault();
                dropzone.classList.add('is-over');
            });
        });
        ['dragleave', 'drop'].forEach(function (type) {
            dropzone.addEventListener(type, function (event) {
                event.preventDefault();
                dropzone.classList.remove('is-over');
            });
        });
        dropzone.addEventListener('drop', function (event) {
            if (event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files.length) {
                uploadKnowledge(event.dataTransfer.files, knowledge, host);
            }
        });
    };

    /* 资料柜文件夹卡：DOM 结构对齐站长给的 Uiverse 原文（back → counter → search →
     * file-1..5 → front-wrapper），类名加 acs- 前缀。展开/收起全靠 :checked，不是 hover。 */
    function folderCard(knowledge) {
        var card = el('label', 'acs-folder-card');
        card.title = '点击展开资料柜';

        var toggle = el('input', 'acs-folder-toggle');
        toggle.type = 'checkbox';
        card.appendChild(toggle);

        // hint
        var hint = el('span', 'acs-hint-wrapper');
        hint.appendChild(el('span', 'acs-hint-text', '点我展开'));
        hint.appendChild(svgNode('0 0 24 24', 'acs-hint-arrow', 'M5 5c6 1 10 5 12 12M17 17l1-6M17 17l-6 1', {
            fill: 'none', stroke: '#60a5fa', 'stroke-width': '2', 'stroke-linecap': 'round'
        }));
        card.appendChild(hint);

        var container = el('span', 'acs-folder-container');

        // 1) 文件夹背板
        var back = el('span', 'acs-folder-back');
        back.appendChild(folderBackSvg());
        container.appendChild(back);

        // 2) 计数气泡
        var counter = el('span', 'acs-counter');
        counter.title = '资料柜里的文件数';
        counter.appendChild(el('span', 'acs-status-dot'));
        counter.appendChild(el('span', 'acs-counter-label', 'files'));
        counter.appendChild(el('span', 'acs-counter-number', String(knowledge.files.length)));
        container.appendChild(counter);

        // 3) 搜索条
        var search = el('span', 'acs-folder-search');
        var searchIcon = icon('bi-search');
        searchIcon.classList.add('acs-search-icon');
        search.appendChild(searchIcon);
        var searchInput = el('input', 'acs-search-input');
        searchInput.type = 'search';
        searchInput.placeholder = '筛选文件…';
        searchInput.setAttribute('aria-label', '筛选资料柜文件');
        // 点搜索框不该被 label 解释成"开合文件夹"
        ['click', 'mousedown', 'keydown'].forEach(function (type) {
            searchInput.addEventListener(type, function (event) { event.stopPropagation(); });
        });
        searchInput.addEventListener('input', function () {
            var keyword = searchInput.value.trim().toLowerCase();
            root.querySelectorAll('[data-acs-file-row]').forEach(function (node) {
                node.hidden = keyword !== '' && node.getAttribute('data-acs-file-name').toLowerCase().indexOf(keyword) === -1;
            });
        });
        search.appendChild(searchInput);
        container.appendChild(search);

        // 4) 五张"纸"。原文是纯装饰的五层，这里让它显示最近上传的文件名与类型。
        var recent = knowledge.files.slice(-5).reverse();
        var EXT_ICON = { md: 'bi-markdown', txt: 'bi-file-text', csv: 'bi-file-spreadsheet', tsv: 'bi-file-spreadsheet',
            json: 'bi-braces', html: 'bi-code-slash', htm: 'bi-code-slash', xml: 'bi-code-slash',
            docx: 'bi-file-word', pdf: 'bi-file-pdf' };
        for (var i = 0; i < 5; i++) {
            var file = recent[i];
            var sheet = el('span', 'acs-file acs-file-' + (i + 1));
            sheet.appendChild(el('span', 'acs-shine'));
            sheet.appendChild(el('span', 'acs-file-text', file ? file.name : '空位'));
            var fileIcon = icon(file ? (EXT_ICON[file.ext] || 'bi-file-earmark') : 'bi-plus-lg');
            fileIcon.classList.add('acs-file-icon');
            sheet.appendChild(fileIcon);
            sheet.appendChild(el('span', 'acs-file-tag', file ? ((file.ext || '').toUpperCase() + ' · ' + humanBytes(file.bytes)) : '点击上传'));
            container.appendChild(sheet);
        }

        // 5) 前盖（会绕 X 轴翻开）
        var front = el('span', 'acs-folder-front-wrapper');
        front.appendChild(folderFrontSvg());
        front.appendChild(el('span', 'acs-folder-label'));
        container.appendChild(front);

        card.appendChild(container);

        var input = el('input');
        input.type = 'file';
        input.multiple = true;
        input.accept = '.txt,.md,.markdown,.csv,.tsv,.json,.html,.htm,.xml,.docx,.pdf';
        input.hidden = true;
        input.addEventListener('change', function () {
            if (input.files && input.files.length) uploadKnowledge(input.files, knowledge, card.closest('[data-acs-mount]'));
            input.value = '';
        });
        card.appendChild(input);

        // 已展开时再点文件夹本体才选文件；未展开时点击交给 label 去翻开
        container.addEventListener('click', function (event) {
            if (!toggle.checked) return;
            event.preventDefault();
            input.click();
        });
        return card;
    }

    /** 通用 svg 生成：一条 path + 若干属性。 */
    function svgNode(viewBox, cls, path, attrs) {
        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', viewBox);
        svg.setAttribute('aria-hidden', 'true');
        if (cls) svg.setAttribute('class', cls);
        var node = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        node.setAttribute('d', path);
        Object.keys(attrs || {}).forEach(function (name) { node.setAttribute(name, attrs[name]); });
        svg.appendChild(node);
        return svg;
    }

    /* 背板：带左上角标签页的文件夹轮廓 */
    function folderBackSvg() {
        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 170 130');
        svg.setAttribute('width', '170');
        svg.setAttribute('height', '130');
        svg.setAttribute('aria-hidden', 'true');
        var defs = document.createElementNS('http://www.w3.org/2000/svg', 'defs');
        var grad = document.createElementNS('http://www.w3.org/2000/svg', 'linearGradient');
        grad.setAttribute('id', 'acsFolderBack');
        grad.setAttribute('x1', '0'); grad.setAttribute('y1', '0');
        grad.setAttribute('x2', '0'); grad.setAttribute('y2', '1');
        [['0%', '#4a5170'], ['100%', '#2b3049']].forEach(function (pair) {
            var stop = document.createElementNS('http://www.w3.org/2000/svg', 'stop');
            stop.setAttribute('offset', pair[0]);
            stop.setAttribute('stop-color', pair[1]);
            grad.appendChild(stop);
        });
        defs.appendChild(grad);
        svg.appendChild(defs);
        var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        path.setAttribute('d', 'M4 22a9 9 0 0 1 9-9h40a9 9 0 0 1 6.4 2.6L67 22h90a9 9 0 0 1 9 9v86a9 9 0 0 1-9 9H13a9 9 0 0 1-9-9z');
        path.setAttribute('fill', 'url(#acsFolderBack)');
        svg.appendChild(path);
        return svg;
    }

    /* 前盖：圆角矩形，翻开时露出里面的纸 */
    function folderFrontSvg() {
        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 170 104');
        svg.setAttribute('width', '170');
        svg.setAttribute('height', '104');
        svg.setAttribute('aria-hidden', 'true');
        var defs = document.createElementNS('http://www.w3.org/2000/svg', 'defs');
        var grad = document.createElementNS('http://www.w3.org/2000/svg', 'linearGradient');
        grad.setAttribute('id', 'acsFolderFront');
        grad.setAttribute('x1', '0'); grad.setAttribute('y1', '0');
        grad.setAttribute('x2', '1'); grad.setAttribute('y2', '1');
        [['0%', '#7b8cff'], ['100%', '#5b6cff']].forEach(function (pair) {
            var stop = document.createElementNS('http://www.w3.org/2000/svg', 'stop');
            stop.setAttribute('offset', pair[0]);
            stop.setAttribute('stop-color', pair[1]);
            grad.appendChild(stop);
        });
        defs.appendChild(grad);
        svg.appendChild(defs);
        var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        path.setAttribute('d', 'M0 12A12 12 0 0 1 12 0h146a12 12 0 0 1 12 12v80a12 12 0 0 1-12 12H12A12 12 0 0 1 0 92z');
        path.setAttribute('fill', 'url(#acsFolderFront)');
        svg.appendChild(path);
        return svg;
    }

    /* ACS_MARKER_JS_7 */

    function humanBytes(bytes) {
        bytes = Number(bytes) || 0;
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(2) + ' MB';
    }

    /* 与 AiCustomerServiceKnowledge 的三条限制保持一致（单文件 4MB / 共 60 个 /
     * 白名单后缀）。文件选择框有 accept 挡着，但拖放完全绕过它——不在这儿先筛一遍，
     * 拖进来一个 200MB 的压缩包会整包传完才被服务端拒掉。 */
    var KNOWLEDGE_MAX_BYTES = 4 * 1024 * 1024;
    var KNOWLEDGE_EXT = ['txt', 'md', 'markdown', 'csv', 'tsv', 'json', 'html', 'htm', 'xml', 'docx', 'pdf'];

    function uploadKnowledge(files, knowledge, host) {
        var picked = Array.prototype.slice.call(files, 0, 10);
        var rejected = [];
        var accepted = picked.filter(function (file) {
            var name = String(file.name || '');
            var ext = name.indexOf('.') === -1 ? '' : name.split('.').pop().toLowerCase();
            if (KNOWLEDGE_EXT.indexOf(ext) === -1) { rejected.push(name + '：不支持的格式'); return false; }
            if (file.size > KNOWLEDGE_MAX_BYTES) { rejected.push(name + '：超过 4 MB'); return false; }
            return true;
        });
        var room = Math.max(0, 60 - (knowledge.files || []).length);
        if (accepted.length > room) {
            rejected.push('资料柜最多 60 个文件，这次只收前 ' + room + ' 个');
            accepted = accepted.slice(0, room);
        }
        if (!accepted.length) {
            toast(rejected.length ? rejected.join('；') : '没有可上传的文件', true);
            return;
        }
        if (rejected.length) toast(rejected.join('；'), true);

        var form = new FormData();
        accepted.forEach(function (file) { form.append('file[]', file); });
        toast('正在上传并抽取文本…');
        post('knowledge-upload', form).then(function (data) {
            knowledge.files = data.files || knowledge.files;
            jsonCache.knowledge_json = knowledge;
            writeJson('knowledge_json');
            toast(data.message + (data.failed && data.failed.length ? '（' + data.failed.join('；') + '）' : ''), !!(data.failed && data.failed.length));
            if (host) { host.innerHTML = ''; PANELS.knowledge(host); }
        }).catch(function (error) { toast(error.message, true); });
    }

    function fileList(knowledge) {
        var box = el('div', 'acs-a-sub');
        box.appendChild(el('h3', '', '已上传的知识库文件（' + knowledge.files.length + '）'));
        var list = el('div', 'acs-a-list');
        if (!knowledge.files.length) {
            list.appendChild(el('div', 'acs-a-empty', '还没有文件。左边的文件夹点开就能上传。'));
        }
        knowledge.files.forEach(function (file) {
            var row = rowShell(
                file.name,
                (file.ext || '').toUpperCase() + ' · ' + humanBytes(file.bytes) + ' · 抽出 ' + (file.chars || 0) + ' 字',
                'bi-file-earmark-text',
                function () {
                    post('knowledge-delete', { id: file.id }).then(function (data) {
                        knowledge.files = data.files || [];
                        jsonCache.knowledge_json = knowledge;
                        writeJson('knowledge_json');
                        row.remove();
                        toast('已删除 ' + file.name);
                    }).catch(function (error) { toast(error.message, true); });
                },
                // 抽出来的文本删了就没了，原文件插件从来不留，只能重新上传一次
                '删除「' + file.name + '」？插件只存抽取后的文本、不留原文件，删掉要重新上传。'
            );
            row.setAttribute('data-acs-file-row', '');
            row.setAttribute('data-acs-file-name', file.name);
            list.appendChild(row);
        });
        box.appendChild(list);
        if (BOOT.knowledgeFiles && BOOT.knowledgeFiles.missing && BOOT.knowledgeFiles.missing.length) {
            box.appendChild(el('div', 'acs-a-alert acs-a-alert--warn',
                '这些文件的文本已经不在磁盘上了，建议删掉重新上传：' + BOOT.knowledgeFiles.missing.join('、')));
        }
        return box;
    }

    function sourcePicker(knowledge) {
        var box = el('div', 'acs-a-sub');
        box.appendChild(el('h3', '', '站内内容（' + knowledge.sources.length + '）'));
        box.appendChild(el('p', 'acs-a-sub-note', '挑过来的内容会以标题 + 摘要 + 价格/链接的形式作为事实依据；只会用到前台本来就能看见的记录。'));

        var bar = el('div', 'acs-a-palette-row');
        var typeWrap = el('div', 'acs-a-select');
        typeWrap.style.maxWidth = '170px';
        var typeSelect = el('select');
        typeSelect.setAttribute('aria-label', '内容类型');
        (BOOT.contentTypes || []).forEach(function (item) {
            var option = el('option', '', item.label);
            option.value = item.kind + '|' + item.type;
            typeSelect.appendChild(option);
        });
        typeWrap.appendChild(typeSelect);
        typeWrap.appendChild(icon('bi-chevron-down'));
        var keyword = el('input', 'acs-a-input');
        keyword.type = 'search';
        keyword.placeholder = '按标题搜索…';
        keyword.style.maxWidth = '200px';
        keyword.setAttribute('aria-label', '搜索站内内容');
        var searchButton = el('button', 'acs-a-btn', '搜索');
        searchButton.type = 'button';
        searchButton.prepend(icon('bi-search'));
        bar.appendChild(typeWrap);
        bar.appendChild(keyword);
        bar.appendChild(searchButton);
        box.appendChild(bar);

        var results = el('div', 'acs-a-list');
        results.hidden = true;
        box.appendChild(results);

        function runSearch() {
            var parts = typeSelect.value.split('|');
            searchButton.disabled = true;
            post('content-search', { kind: parts[0], type: parts[1] || '', keyword: keyword.value }).then(function (data) {
                results.innerHTML = '';
                results.hidden = false;
                if (!data.items || !data.items.length) {
                    results.appendChild(el('div', 'acs-a-empty', '没有匹配的内容。'));
                    return;
                }
                data.items.forEach(function (item) {
                    var row = el('div', 'acs-a-row');
                    var badge = el('div', 'acs-a-row-icon');
                    badge.appendChild(icon(item.kind === 'product' ? 'bi-box-seam' : (item.kind === 'article' ? 'bi-journal-text' : 'bi-file-earmark')));
                    row.appendChild(badge);
                    var main = el('div', 'acs-a-row-main');
                    main.appendChild(el('div', 'acs-a-row-title', item.title));
                    main.appendChild(el('div', 'acs-a-row-meta', (item.price ? item.price + ' · ' : '') + (item.url || item.slug || '')));
                    row.appendChild(main);
                    var add = el('button', 'acs-a-btn acs-a-btn--sm', '加入');
                    add.type = 'button';
                    add.addEventListener('click', function () {
                        var token = item.kind + ':' + (item.type || '') + ':' + item.id;
                        var exists = knowledge.sources.some(function (s) {
                            return s.kind + ':' + (s.type || '') + ':' + s.id === token;
                        });
                        if (exists) { toast('已经在清单里了', true); return; }
                        if (knowledge.sources.length >= 200) { toast('最多 200 条站内内容', true); return; }
                        knowledge.sources.push({ kind: item.kind, type: item.type || '', id: item.id, title: item.title });
                        jsonCache.knowledge_json = knowledge;
                        writeJson('knowledge_json');
                        add.disabled = true;
                        add.textContent = '已加入';
                        renderChosen();
                    });
                    row.appendChild(add);
                    results.appendChild(row);
                });
            }).catch(function (error) { toast(error.message, true); })
              .finally(function () { searchButton.disabled = false; });
        }
        searchButton.addEventListener('click', runSearch);
        keyword.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') { event.preventDefault(); runSearch(); }
        });

        var chosen = el('div', 'acs-a-list');
        function renderChosen() {
            chosen.innerHTML = '';
            if (!knowledge.sources.length) {
                chosen.appendChild(el('div', 'acs-a-empty', '还没有挑选站内内容。'));
                return;
            }
            knowledge.sources.forEach(function (source, index) {
                chosen.appendChild(rowShell(
                    source.title || (source.kind + ' #' + source.id),
                    (BOOT.sourceKinds[source.kind] || source.kind) + (source.type ? ' / ' + source.type : '') + ' · #' + source.id,
                    'bi-link-45deg',
                    function () {
                        knowledge.sources.splice(index, 1);
                        jsonCache.knowledge_json = knowledge;
                        writeJson('knowledge_json');
                        renderChosen();
                    }
                ));
            });
        }
        renderChosen();
        box.appendChild(el('p', 'acs-a-help', '已选清单：'));
        box.appendChild(chosen);

        var autoRow = el('div', 'acs-a-palette-row');
        autoRow.appendChild(el('span', '', '或者自动带上最新：'));
        [['products', '产品'], ['articles', '文章'], ['pages', '页面']].forEach(function (pair) {
            var label = el('label', 'acs-a-checkline');
            var input = el('input');
            input.type = 'checkbox';
            input.checked = !!knowledge.auto[pair[0]];
            input.addEventListener('change', function () {
                knowledge.auto[pair[0]] = input.checked;
                jsonCache.knowledge_json = knowledge;
                writeJson('knowledge_json');
            });
            label.appendChild(input);
            label.appendChild(el('span', '', pair[1]));
            autoRow.appendChild(label);
        });
        box.appendChild(autoRow);
        return box;
    }

    /* ACS_MARKER_JS_8 */

    /* ---- 内置工具开关 ---- */
    PANELS.tools = function (host) {
        var tools = readJson('tools_json', { builtin: {}, custom: [] });
        if (!tools.builtin || typeof tools.builtin !== 'object') tools.builtin = {};
        var grid = el('div', 'acs-a-subgrid');
        Object.keys(BOOT.builtinTools).forEach(function (name) {
            var meta = BOOT.builtinTools[name];
            var box = el('div', 'acs-a-sub');
            var head = el('div', 'acs-a-tool-head');
            head.appendChild(el('h3', '', meta.label));
            var label = el('label', 'acs-a-switch');
            var input = el('input');
            input.type = 'checkbox';
            input.checked = tools.builtin[name] !== false;
            input.setAttribute('aria-label', meta.label);
            input.addEventListener('change', function () {
                tools.builtin[name] = input.checked;
                jsonCache.tools_json = tools;
                writeJson('tools_json');
            });
            label.appendChild(input);
            var track = el('span', 'acs-a-switch-track');
            track.setAttribute('aria-hidden', 'true');
            track.appendChild(el('span', 'acs-a-switch-thumb'));
            label.appendChild(track);
            head.appendChild(label);
            box.appendChild(head);
            box.appendChild(el('p', 'acs-a-sub-note', meta.description));
            box.appendChild(el('p', 'acs-a-help', meta.note));
            grid.appendChild(box);
        });
        host.appendChild(grid);
    };

    /* ---- 自定义工具：内容类型 + 白名单条件 + 卡片预设 ---- */
    PANELS.customtools = function (host) {
        var tools = readJson('tools_json', { builtin: {}, custom: [] });
        if (!Array.isArray(tools.custom)) tools.custom = [];
        var save = function () { jsonCache.tools_json = tools; writeJson('tools_json'); };

        host.appendChild(listEditor({
            items: function () { return tools.custom; },
            empty: '还没有自定义工具。例如加一个 find_hot_products：source=产品、条件勾"仅热销"，AI 就能在访客问"卖得最好的是哪个"时直接回一张热销卡。',
            addLabel: '新建工具',
            add: function () {
                if (tools.custom.length >= 16) { toast('最多 16 个自定义工具', true); return; }
                tools.custom.push({
                    name: 'find_items_' + (tools.custom.length + 1),
                    label: '新工具', description: '', source: 'product', entry_type: '',
                    filters: {}, limit: 3, card: 'stack', enabled: true
                });
                save();
            },
            row: function (tool, index, repaint) {
                var box = el('div', 'acs-a-tool');
                var head = el('div', 'acs-a-tool-head');
                var title = el('div', 'acs-a-tool-title');
                title.appendChild(icon('bi-tools'));
                title.appendChild(el('span', '', tool.label || tool.name));
                head.appendChild(title);
                var actions = el('div', 'acs-a-palette-row');
                var enable = el('label', 'acs-a-switch');
                var enableInput = el('input');
                enableInput.type = 'checkbox';
                enableInput.checked = tool.enabled !== false;
                enableInput.setAttribute('aria-label', '启用 ' + (tool.label || tool.name));
                enableInput.addEventListener('change', function () { tool.enabled = enableInput.checked; save(); });
                enable.appendChild(enableInput);
                var track = el('span', 'acs-a-switch-track');
                track.setAttribute('aria-hidden', 'true');
                track.appendChild(el('span', 'acs-a-switch-thumb'));
                enable.appendChild(track);
                actions.appendChild(enable);
                var remove = el('button', 'acs-a-icon-btn');
                remove.type = 'button';
                remove.setAttribute('aria-label', '删除工具 ' + (tool.label || tool.name));
                remove.appendChild(icon('bi-trash3'));
                remove.addEventListener('click', function () { tools.custom.splice(index, 1); save(); repaint(); });
                actions.appendChild(remove);
                head.appendChild(actions);
                box.appendChild(head);

                var grid = el('div', 'acs-a-tool-grid');
                grid.appendChild(textField('工具名（英文，模型看到的就是它）', tool.name, 48, function (v) {
                    tool.name = v.toLowerCase().replace(/[^a-z0-9_]/g, '');
                    save();
                }, 'find_hot_products'));
                grid.appendChild(textField('显示名', tool.label, 60, function (v) { tool.label = v; save(); title.lastChild.textContent = v || tool.name; }));
                grid.appendChild(selectField('内容来源', tool.source, BOOT.sourceKinds, function (v) { tool.source = v; save(); repaint(); }));
                if (tool.source === 'entry') {
                    var entryTypes = {};
                    (BOOT.contentTypes || []).forEach(function (item) {
                        if (item.kind === 'entry') entryTypes[item.type] = item.label;
                    });
                    grid.appendChild(selectField('自定义内容类型', tool.entry_type, entryTypes, function (v) { tool.entry_type = v; save(); }));
                }
                grid.appendChild(selectField('卡片预设', tool.card, BOOT.cardPresets.product, function (v) { tool.card = v; save(); }));
                grid.appendChild(numberField('返回条数', tool.limit, 1, 8, function (v) { tool.limit = v; save(); }));
                box.appendChild(grid);

                box.appendChild(textareaField('给模型的说明（什么时候该调它）', tool.description, 400, function (v) { tool.description = v; save(); },
                    '例如：访客问"卖得最好/最热门/推荐哪款"时调用。'));

                if (tool.source === 'product') {
                    var filters = el('div');
                    filters.appendChild(el('p', 'acs-a-help', '筛选条件（只有这些白名单条件，不接受任意查询）：'));
                    var row = el('div', 'acs-a-tool-filters');
                    Object.keys(BOOT.toolFilters).forEach(function (key) {
                        var label = el('label', 'acs-a-checkline');
                        var input = el('input');
                        input.type = 'checkbox';
                        input.checked = !!(tool.filters && tool.filters[key]);
                        input.addEventListener('change', function () {
                            if (!tool.filters) tool.filters = {};
                            if (input.checked) tool.filters[key] = true; else delete tool.filters[key];
                            save();
                        });
                        label.appendChild(input);
                        label.appendChild(el('span', '', BOOT.toolFilters[key]));
                        row.appendChild(label);
                    });
                    filters.appendChild(row);
                    box.appendChild(filters);
                }
                return box;
            }
        }));
    };

    /* ACS_MARKER_JS_9 */

    /* 通用小控件工厂（面板里的字段不是声明式字段，所以自己造） */
    function labelled(text, control) {
        var box = el('label', 'acs-a-item');
        box.appendChild(el('span', 'acs-a-label', text));
        box.appendChild(control);
        return box;
    }
    function textField(label, value, max, onChange, placeholder) {
        var input = el('input', 'acs-a-input');
        input.value = value == null ? '' : String(value);
        input.maxLength = max;
        if (placeholder) input.placeholder = placeholder;
        input.addEventListener('input', function () { onChange(input.value); });
        return labelled(label, input);
    }
    function textareaField(label, value, max, onChange, placeholder) {
        var area = el('textarea', 'acs-a-input acs-a-textarea');
        area.value = value == null ? '' : String(value);
        area.maxLength = max;
        area.rows = 3;
        if (placeholder) area.placeholder = placeholder;
        area.addEventListener('input', function () { onChange(area.value); });
        return labelled(label, area);
    }
    function numberField(label, value, min, max, onChange) {
        var input = el('input', 'acs-a-input');
        input.type = 'number';
        input.min = String(min);
        input.max = String(max);
        input.value = String(value == null ? min : value);
        input.addEventListener('input', function () {
            onChange(Math.max(min, Math.min(max, parseInt(input.value, 10) || min)));
        });
        return labelled(label, input);
    }
    function selectField(label, value, options, onChange) {
        var wrap = el('div', 'acs-a-select');
        var select = el('select');
        Object.keys(options || {}).forEach(function (key) {
            var option = el('option', '', options[key]);
            option.value = key;
            if (String(value) === key) option.selected = true;
            select.appendChild(option);
        });
        select.addEventListener('change', function () { onChange(select.value); });
        wrap.appendChild(select);
        wrap.appendChild(icon('bi-chevron-down'));
        return labelled(label, wrap);
    }
    function switchField(label, checked, onChange) {
        var wrap = el('label', 'acs-a-switch');
        var input = el('input');
        input.type = 'checkbox';
        input.checked = !!checked;
        input.addEventListener('change', function () { onChange(input.checked); });
        wrap.appendChild(input);
        var track = el('span', 'acs-a-switch-track');
        track.setAttribute('aria-hidden', 'true');
        track.appendChild(el('span', 'acs-a-switch-thumb'));
        wrap.appendChild(track);
        wrap.appendChild(el('span', 'acs-a-switch-label', label));
        return wrap;
    }

    /** 图片字段：输入框 + 媒体库选图 + 缩略图。面板里的 JSON 字段用这个，不要让人抄 URL。 */
    function mediaField(label, value, onChange, type) {
        var input = el('input', 'acs-a-input');
        input.value = value == null ? '' : String(value);
        input.maxLength = 2048;
        input.placeholder = '从媒体库选，或粘贴地址';
        var bar = el('div', 'acs-a-media-row');
        bar.appendChild(input);

        var thumb = el('span', 'acs-a-media-thumb');
        var paint = function () {
            var url = String(input.value || '').trim();
            thumb.innerHTML = '';
            thumb.hidden = url === '';
            if (url === '') return;
            var img = el('img');
            img.src = url;
            img.alt = '';
            thumb.appendChild(img);
        };
        var commit = function (url) {
            input.value = url;
            onChange(url);
            paint();
        };

        var pick = el('button', 'acs-a-btn acs-a-btn--sm');
        pick.type = 'button';
        pick.appendChild(icon('bi-images'));
        pick.appendChild(el('span', '', '选图'));
        pick.addEventListener('click', function () {
            if (!window.MediaPicker || typeof window.MediaPicker.open !== 'function') {
                toast('媒体选择框没加载出来，可以先手动填地址', true);
                return;
            }
            window.MediaPicker.open({
                type: type || 'image',
                onSelect: function (file) { if (file && file.url) commit(file.url); }
            });
        });
        bar.appendChild(pick);

        var clear = el('button', 'acs-a-icon-btn');
        clear.type = 'button';
        clear.title = '清空';
        clear.setAttribute('aria-label', '清空' + label);
        clear.appendChild(icon('bi-x-lg'));
        clear.addEventListener('click', function () { commit(''); });
        bar.appendChild(clear);
        bar.appendChild(thumb);

        input.addEventListener('input', function () { onChange(input.value); paint(); });
        paint();
        return labelled(label, bar);
    }

    /* ---- 卡片样式 ---- */
    PANELS.cards = function (host) {
        var cards = readJson('cards_json', {});
        var save = function () { jsonCache.cards_json = cards; writeJson('cards_json'); };
        var titles = { product: '产品卡', article: '文章卡', owner: '站长名片', inquiry: '询盘卡', social: '社媒卡' };
        var grid = el('div', 'acs-a-subgrid');

        Object.keys(titles).forEach(function (kind) {
            if (!cards[kind] || typeof cards[kind] !== 'object') cards[kind] = {};
            var box = el('div', 'acs-a-sub');
            box.appendChild(el('h3', '', titles[kind]));
            box.appendChild(selectField('预设', cards[kind].preset, BOOT.cardPresets[kind], function (v) { cards[kind].preset = v; save(); }));

            if (kind === 'product') {
                box.appendChild(textField('按钮文案', cards[kind].cta, 24, function (v) { cards[kind].cta = v; save(); }, '查看详情'));
                box.appendChild(numberField('最多几张', cards[kind].max, 1, 6, function (v) { cards[kind].max = v; save(); }));
                box.appendChild(switchField('显示价格', cards[kind].show_price !== false, function (v) { cards[kind].show_price = v; save(); }));
                box.appendChild(switchField('显示摘要', cards[kind].show_summary !== false, function (v) { cards[kind].show_summary = v; save(); }));
            }
            if (kind === 'article') {
                box.appendChild(textField('按钮文案', cards[kind].cta, 24, function (v) { cards[kind].cta = v; save(); }, '阅读全文'));
                box.appendChild(numberField('最多几张', cards[kind].max, 1, 6, function (v) { cards[kind].max = v; save(); }));
            }
            if (kind === 'inquiry') {
                if (!Array.isArray(cards[kind].fields)) cards[kind].fields = ['name', 'email', 'phone', 'message'];
                var fieldsRow = el('div', 'acs-a-tool-filters');
                [['name', '姓名'], ['email', '邮箱'], ['phone', '电话'], ['company', '公司'], ['message', '需求描述']].forEach(function (pair) {
                    var label = el('label', 'acs-a-checkline');
                    var input = el('input');
                    input.type = 'checkbox';
                    input.checked = cards[kind].fields.indexOf(pair[0]) !== -1;
                    input.disabled = pair[0] === 'message';
                    input.title = pair[0] === 'message' ? '需求描述是必填项，不能移除' : '';
                    input.addEventListener('change', function () {
                        var list = cards[kind].fields.filter(function (f) { return f !== pair[0]; });
                        if (input.checked) list.push(pair[0]);
                        cards[kind].fields = list;
                        save();
                    });
                    label.appendChild(input);
                    label.appendChild(el('span', '', pair[1]));
                    fieldsRow.appendChild(label);
                });
                box.appendChild(el('p', 'acs-a-help', '表单字段：'));
                box.appendChild(fieldsRow);
                box.appendChild(textField('提交按钮', cards[kind].submit, 24, function (v) { cards[kind].submit = v; save(); }, '提交询盘'));
                box.appendChild(textareaField('提交成功提示', cards[kind].success, 200, function (v) { cards[kind].success = v; save(); }));
            }
            grid.appendChild(box);
        });
        host.appendChild(grid);
    };

    /* ACS_MARKER_JS_10 */

    /* ---- 站长名片 ---- */
    // 每个平台的"值"字段叫什么、示例填什么。链接类走默认，只列需要区别对待的。
    var SOCIAL_VALUE_LABEL = {
        wechat: '微信号', phone: '电话号码', viber: 'Viber 号码', sms: '短信号码',
        email: '邮箱', messenger: 'm.me 链接', line: 'LINE 链接', skype: 'Skype 链接',
        discord: 'Discord 邀请链接', whatsapp: 'wa.me 链接'
    };
    var SOCIAL_VALUE_HINT = {
        wechat: 'my-wechat-id', phone: '+86 138…', viber: '+8613800138000', sms: '+8613800138000',
        email: 'sales@example.com', messenger: 'https://m.me/yourpage',
        line: 'https://line.me/ti/p/~yourid', skype: 'https://join.skype.com/invite/…',
        discord: 'https://discord.gg/…', whatsapp: 'https://wa.me/8613800138000'
    };
    PANELS.owner = function (host) {
        var owner = readJson('owner_json', { socials: [] });
        if (!Array.isArray(owner.socials)) owner.socials = [];
        var save = function () { jsonCache.owner_json = owner; writeJson('owner_json'); };

        var grid = el('div', 'acs-a-tool-grid');
        grid.appendChild(textField('姓名', owner.name, 60, function (v) { owner.name = v; save(); }, '例如：李工'));
        grid.appendChild(textField('头衔', owner.title, 80, function (v) { owner.title = v; save(); }, '例如：外贸负责人 / 技术支持'));
        grid.appendChild(mediaField('头像', owner.avatar, function (v) { owner.avatar = v; save(); }));
        host.appendChild(grid);
        host.appendChild(textareaField('一句话简介', owner.bio, 300, function (v) { owner.bio = v; save(); },
            '例如：负责工业阀门线的选型与报价，工作日 30 分钟内回复。'));

        var box = el('div', 'acs-a-sub');
        box.appendChild(el('h3', '', '社媒与联系方式'));
        box.appendChild(el('p', 'acs-a-sub-note', '微信按"点击复制"渲染，电话 / Viber / 短信按号码拼协议链接，其余按链接渲染。传了二维码图的渠道，访客在浮标旁把鼠标移上去（手机上点一下）就能看到扫码图。名片卡与社媒卡共用这份数据。'));
        box.appendChild(listEditor({
            items: function () { return owner.socials; },
            empty: '还没有联系方式。加一条微信或 WhatsApp，AI 就能在访客要联系方式时递过去。',
            addLabel: '加一条',
            add: function () {
                if (owner.socials.length >= 12) { toast('最多 12 条', true); return; }
                owner.socials.push({ network: 'wechat', label: '', url: '', qr: '' });
                save();
            },
            row: function (social, index, repaint) {
                var row = el('div', 'acs-a-tool');
                var grid2 = el('div', 'acs-a-tool-grid');
                var options = {};
                Object.keys(BOOT.socialNetworks).forEach(function (key) { options[key] = BOOT.socialNetworks[key][0]; });
                grid2.appendChild(selectField('平台', social.network, options, function (v) { social.network = v; save(); repaint(); }));
                grid2.appendChild(textField('显示名', social.label, 40, function (v) { social.label = v; save(); },
                    BOOT.socialNetworks[social.network] ? BOOT.socialNetworks[social.network][0] : ''));
                grid2.appendChild(textField(
                    SOCIAL_VALUE_LABEL[social.network] || '链接',
                    social.url, 200, function (v) { social.url = v; save(); },
                    SOCIAL_VALUE_HINT[social.network] || 'https://…'
                ));
                grid2.appendChild(mediaField('二维码图（可选）', social.qr, function (v) { social.qr = v; save(); }));
                row.appendChild(grid2);
                var remove = el('button', 'acs-a-btn acs-a-btn--sm acs-a-btn--danger', '移除这条');
                remove.type = 'button';
                remove.addEventListener('click', function () { owner.socials.splice(index, 1); save(); repaint(); });
                row.appendChild(remove);
                return row;
            }
        }));
        host.appendChild(box);
    };

    /* ---- 约束清单：标签输入 ---- */
    function tagInput(label, note, values, onChange, placeholder) {
        var box = el('div', 'acs-a-sub');
        box.appendChild(el('h3', '', label));
        if (note) box.appendChild(el('p', 'acs-a-sub-note', note));
        var field = el('div', 'acs-a-tags');
        var input = el('input');
        input.placeholder = placeholder || '输入后回车添加';
        input.setAttribute('aria-label', label);

        function repaint() {
            field.querySelectorAll('.acs-a-tagitem').forEach(function (n) { n.remove(); });
            values.forEach(function (value, index) {
                var tag = el('span', 'acs-a-tagitem');
                tag.appendChild(el('span', '', value));
                var close = el('button');
                close.type = 'button';
                close.textContent = '×';
                close.setAttribute('aria-label', '移除 ' + value);
                close.addEventListener('click', function () {
                    values.splice(index, 1);
                    onChange();
                    repaint();
                });
                tag.appendChild(close);
                field.insertBefore(tag, input);
            });
        }
        function commit() {
            var text = input.value.trim();
            if (text === '') return;
            if (values.indexOf(text) === -1) {
                if (values.length >= 60) { toast('这一项最多 60 条', true); return; }
                values.push(text);
                onChange();
                repaint();
            }
            input.value = '';
        }
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ',') { event.preventDefault(); commit(); }
            if (event.key === 'Backspace' && input.value === '' && values.length) {
                values.pop();
                onChange();
                repaint();
            }
        });
        input.addEventListener('blur', commit);
        field.appendChild(input);
        repaint();
        box.appendChild(field);
        return box;
    }

    PANELS.guardrails = function (host) {
        var rules = readJson('guardrails_json', {});
        ['allow_topics', 'deny_topics', 'must_do', 'never_do', 'blocked_words'].forEach(function (key) {
            if (!Array.isArray(rules[key])) rules[key] = [];
        });
        var save = function () { jsonCache.guardrails_json = rules; writeJson('guardrails_json'); };

        var grid = el('div', 'acs-a-subgrid');
        grid.appendChild(tagInput('可以聊的话题', '「收敛」模式下只有这些能聊；「开放」模式下这些是重点。', rules.allow_topics, save, '例如：产品选型'));
        grid.appendChild(tagInput('禁止的话题', '命中就走越界回复。', rules.deny_topics, save, '例如：竞品对比'));
        grid.appendChild(tagInput('必须做到', '写进提示词的硬要求。', rules.must_do, save, '例如：先问用途再推荐'));
        grid.appendChild(tagInput('绝不能做', '', rules.never_do, save, '例如：不要承诺交期'));
        grid.appendChild(tagInput('屏蔽词', '出站再筛一遍：回复里出现这些词就整条替换成越界回复。提示词是软约束，这一层是硬的。', rules.blocked_words, save, '例如：保证最低价'));
        host.appendChild(grid);

        var extra = el('div', 'acs-a-tool-grid');
        extra.appendChild(numberField('回复长度上限（字）', rules.max_reply_chars || 1200, 100, 5000, function (v) { rules.max_reply_chars = v; save(); }));
        extra.appendChild(selectField('回复语言', rules.language || 'auto', {
            auto: '自动（优先跟随访客）', visitor: '严格跟随访客语言', zh: '始终简体中文', en: '始终英文'
        }, function (v) { rules.language = v; save(); }));
        host.appendChild(extra);
    };

    /* ACS_MARKER_JS_11 */

    /* ---- 意图事件 ---- */
    PANELS.events = function (host) {
        var events = readJson('events_json', {});
        var save = function () { jsonCache.events_json = events; writeJson('events_json'); };
        var icons = { inquiry: 'bi-receipt', handoff: 'bi-person-workspace', social: 'bi-share', owner: 'bi-person-badge' };

        Object.keys(BOOT.eventKinds).forEach(function (kind) {
            if (!events[kind] || typeof events[kind] !== 'object') events[kind] = { enabled: false, keywords: [], message: '' };
            if (!Array.isArray(events[kind].keywords)) events[kind].keywords = [];
            var box = el('div', 'acs-a-tool');
            var head = el('div', 'acs-a-tool-head');
            var title = el('div', 'acs-a-tool-title');
            title.appendChild(icon(icons[kind] || 'bi-lightning'));
            title.appendChild(el('span', '', BOOT.eventKinds[kind]));
            head.appendChild(title);
            head.appendChild(switchField('启用', events[kind].enabled, function (v) { events[kind].enabled = v; save(); }));
            box.appendChild(head);
            box.appendChild(el('p', 'acs-a-tool-note',
                kind === 'handoff'
                    ? '优先级最高：访客同时说"转人工"和"报个价"时先转人工。'
                    : '命中任一关键词就把对应卡片直接推给访客，不依赖模型是否想调工具。'));
            box.appendChild(tagInput('关键词', '', events[kind].keywords, save, '输入后回车'));
            box.appendChild(textareaField('命中时的一句话', events[kind].message, 200, function (v) { events[kind].message = v; save(); }));
            host.appendChild(box);
        });
    };

    /* ---- 表情与表情包 ---- */
    PANELS.stickers = function (host) {
        var stickers = readJson('stickers_json', { emoji_set: [], packs: [] });
        if (!Array.isArray(stickers.emoji_set)) stickers.emoji_set = [];
        if (!Array.isArray(stickers.packs)) stickers.packs = [];
        var save = function () { jsonCache.stickers_json = stickers; writeJson('stickers_json'); };

        var emojiBox = el('div', 'acs-a-sub');
        emojiBox.appendChild(el('h3', '', 'emoji 面板（' + stickers.emoji_set.length + '）'));
        emojiBox.appendChild(el('p', 'acs-a-sub-note', '点一下删除。下面的输入框可以粘一串 emoji 批量加入。'));
        var emojiGrid = el('div', 'acs-a-emoji');
        function renderEmoji() {
            emojiGrid.innerHTML = '';
            stickers.emoji_set.forEach(function (item, index) {
                var button = el('button', '', item);
                button.type = 'button';
                button.title = '删除 ' + item;
                button.setAttribute('aria-label', '删除 ' + item);
                button.addEventListener('click', function () {
                    stickers.emoji_set.splice(index, 1);
                    save();
                    renderEmoji();
                });
                emojiGrid.appendChild(button);
            });
        }
        renderEmoji();
        emojiBox.appendChild(emojiGrid);
        var adder = el('div', 'acs-a-palette-row');
        var emojiInput = el('input', 'acs-a-input');
        emojiInput.placeholder = '粘贴 emoji，例如 🙌🚀🧡';
        emojiInput.style.maxWidth = '240px';
        emojiInput.setAttribute('aria-label', '批量加入 emoji');
        var emojiAdd = el('button', 'acs-a-btn acs-a-btn--sm', '加入');
        emojiAdd.type = 'button';
        emojiAdd.addEventListener('click', function () {
            // 用 Intl.Segmenter 才能正确切开带修饰符/ZWJ 的组合 emoji；不支持时退回按码点切。
            var pieces;
            if (typeof Intl !== 'undefined' && Intl.Segmenter) {
                pieces = Array.from(new Intl.Segmenter(undefined, { granularity: 'grapheme' }).segment(emojiInput.value))
                    .map(function (s) { return s.segment; });
            } else {
                pieces = Array.from(emojiInput.value);
            }
            pieces.forEach(function (piece) {
                piece = piece.trim();
                if (piece !== '' && stickers.emoji_set.indexOf(piece) === -1 && stickers.emoji_set.length < 64) {
                    stickers.emoji_set.push(piece);
                }
            });
            emojiInput.value = '';
            save();
            renderEmoji();
        });
        adder.appendChild(emojiInput);
        adder.appendChild(emojiAdd);
        emojiBox.appendChild(adder);
        host.appendChild(emojiBox);

        var packBox = el('div', 'acs-a-sub');
        packBox.appendChild(el('h3', '', '自定义表情包'));
        packBox.appendChild(el('p', 'acs-a-sub-note', '上传 PNG / JPG / GIF / WebP / SVG，单张 ≤ 2 MB。文件走站点媒体库，所以前台能直接引用。'));
        var packRow = el('div', 'acs-a-palette-row');
        var packName = el('input', 'acs-a-input');
        packName.placeholder = '分组名，例如 售前常用';
        packName.style.maxWidth = '180px';
        packName.setAttribute('aria-label', '表情包分组名');
        var packFile = el('input');
        packFile.type = 'file';
        packFile.multiple = true;
        packFile.accept = 'image/png,image/jpeg,image/gif,image/webp,image/svg+xml';
        packFile.hidden = true;
        var packButton = el('button', 'acs-a-btn acs-a-btn--sm', '选图上传');
        packButton.type = 'button';
        packButton.addEventListener('click', function () { packFile.click(); });
        packFile.addEventListener('change', function () {
            if (!packFile.files || !packFile.files.length) return;
            // accept 只挡类型不挡大小，服务端上限 2 MB；先筛掉省一次整包上传
            var tooBig = [];
            var ok = Array.prototype.slice.call(packFile.files, 0, 10).filter(function (file) {
                if (file.size > 2 * 1024 * 1024) { tooBig.push(String(file.name || '') + '：超过 2 MB'); return false; }
                return true;
            });
            if (tooBig.length) toast(tooBig.join('；'), true);
            if (!ok.length) { packFile.value = ''; return; }
            var form = new FormData();
            ok.forEach(function (file) { form.append('file[]', file); });
            form.append('pack', packName.value.trim() || '表情包');
            post('sticker-upload', form).then(function (data) {
                jsonCache.stickers_json = data.stickers || stickers;
                writeJson('stickers_json');
                toast(data.message, !!(data.failed && data.failed.length));
                host.innerHTML = '';
                PANELS.stickers(host);
            }).catch(function (error) { toast(error.message, true); });
            packFile.value = '';
        });
        packRow.appendChild(packName);
        packRow.appendChild(packButton);
        packRow.appendChild(packFile);

        // 表情图片通常已经在媒体库里了，多给一个"从媒体库选"的入口
        var fromLibrary = el('button', 'acs-a-btn acs-a-btn--sm', '从媒体库选');
        fromLibrary.type = 'button';
        fromLibrary.prepend(icon('bi-images'));
        fromLibrary.addEventListener('click', function () {
            if (!window.MediaPicker || typeof window.MediaPicker.open !== 'function') {
                toast('媒体选择框没加载出来', true);
                return;
            }
            window.MediaPicker.open({
                type: 'image',
                onSelect: function (file) {
                    if (!file || !file.url) return;
                    var name = packName.value.trim() || '表情包';
                    var index = null;
                    stickers.packs.forEach(function (pack, position) {
                        if (pack.name === name) index = position;
                    });
                    var item = { url: file.url, label: String(file.title || file.alt || '').slice(0, 40) };
                    if (index === null) {
                        if (stickers.packs.length >= 8) { toast('最多 8 个表情包分组', true); return; }
                        stickers.packs.push({ name: name, items: [item] });
                    } else {
                        stickers.packs[index].items = stickers.packs[index].items.concat([item]).slice(0, 48);
                    }
                    save();
                    host.innerHTML = '';
                    PANELS.stickers(host);
                }
            });
        });
        packRow.appendChild(fromLibrary);
        packBox.appendChild(packRow);

        stickers.packs.forEach(function (pack) {
            var group = el('div', 'acs-a-stickers');
            group.appendChild(el('p', 'acs-a-help', pack.name + '（' + pack.items.length + '）'));
            var grid = el('div', 'acs-a-sticker-grid');
            pack.items.forEach(function (item) {
                var cell = el('div', 'acs-a-sticker');
                var img = el('img');
                img.src = item.url;
                img.alt = item.label || '';
                img.loading = 'lazy';
                cell.appendChild(img);
                var remove = el('button');
                remove.type = 'button';
                remove.textContent = '×';
                remove.setAttribute('aria-label', '移除表情');
                remove.addEventListener('click', function () {
                    post('sticker-delete', { url: item.url }).then(function (data) {
                        jsonCache.stickers_json = data.stickers || stickers;
                        writeJson('stickers_json');
                        host.innerHTML = '';
                        PANELS.stickers(host);
                    }).catch(function (error) { toast(error.message, true); });
                });
                cell.appendChild(remove);
                grid.appendChild(cell);
            });
            group.appendChild(grid);
            packBox.appendChild(group);
        });
        host.appendChild(packBox);
    };

    /* ACS_MARKER_JS_12 */

    /* ---------------------------------------------------------------- 实时预览
     * 预览节点是 AiCustomerService::previewMarkup() 输出的**前台真实标记**，样式也是
     * 前台那两份 CSS。所以这里不"画"任何东西，只做三件事：
     *   1. 把表单值写成根节点上的 --acs-* 变量（与 renderWidget 的内联变量同名）；
     *   2. 切换真实的修饰类（acs-bubble--/acs-head--/acs-quick--/acs-density--/acs-edge--/acs-type--/acs-shadow--）；
     *   3. 更新真实节点里的文案，并按舞台尺寸算一个整体缩放比例。
     */

    var pv = {
        stage: root.querySelector('[data-acs-preview-stage]'),
        widget: root.querySelector('[data-acs-preview]'),
        device: 'desktop',
        state: 'open',
        drag: false
    };

    /* 表单字段 → CSS 变量。键名与 AiCustomerService::styleVars() 一一对应，
     * 少一个就会出现"改了没反应"，所以这张表是唯一的映射来源。 */
    var VAR_MAP = {
        '--acs-accent': ['accent_color', '#4F46E5'],
        '--acs-surface': ['surface_color', '#FFFFFF'],
        '--acs-text': ['text_color', '#111827'],
        '--acs-muted': ['muted_color', '#6B7280'],
        '--acs-header-bg': ['header_color', '#FFFFFF'],
        '--acs-header-fg': ['header_text_color', '#111827'],
        '--acs-bot-bubble': ['bot_bubble_color', '#F4F4F5'],
        '--acs-bot-text': ['bot_bubble_text_color', '#111827'],
        '--acs-vis-bubble': ['visitor_bubble_color', '#4F46E5'],
        '--acs-vis-text': ['visitor_bubble_text_color', '#FFFFFF']
    };
    var PX_MAP = {
        '--acs-font': ['font_size', 14],
        '--acs-size': ['widget_size', 56],
        '--acs-panel-width': ['panel_width', 396],
        '--acs-panel-height': ['panel_height', 600],
        '--acs-panel-radius': ['panel_radius', 16],
        '--acs-launcher-corner': ['launcher_corner', 10]
    };

    function sync() {
        var w = pv.widget;
        if (!w) return;
        var mobile = pv.device === 'mobile';

        Object.keys(VAR_MAP).forEach(function (name) {
            w.style.setProperty(name, val(VAR_MAP[name][0], VAR_MAP[name][1]));
        });
        Object.keys(PX_MAP).forEach(function (name) {
            w.style.setProperty(name, Math.round(num(PX_MAP[name][0], PX_MAP[name][1])) + 'px');
        });
        w.style.setProperty('--acs-font-family', (BOOT.fonts && BOOT.fonts[val('font_family', 'system')]) || 'inherit');
        w.style.setProperty('--acs-desktop-x', Math.round(num(mobile ? 'mobile_offset_x' : 'desktop_offset_x', 24)) + 'px');
        w.style.setProperty('--acs-desktop-y', Math.round(num(mobile ? 'mobile_offset_y' : 'desktop_offset_y', 24)) + 'px');

        var layout = readJson('layout_json', {});
        w.style.setProperty('--acs-panel-gap', Math.round(layout.panel_gap == null ? 12 : layout.panel_gap) + 'px');
        [['teaser', 'teaser'], ['ribbon', 'ribbon'], ['badge', 'badge'], ['launcher_nudge', 'launcher']].forEach(function (pair) {
            var v = layout[pair[0]] || { dx: 0, dy: 0 };
            w.style.setProperty('--acs-' + pair[1] + '-dx', (v.dx || 0) + 'px');
            w.style.setProperty('--acs-' + pair[1] + '-dy', (v.dy || 0) + 'px');
        });

        var theme = readJson('theme_json', {});
        swapClass(w, 'acs-bubble--', theme.bubble_style || 'soft');
        swapClass(w, 'acs-anim--', theme.bubble_anim || 'rise');
        swapClass(w, 'acs-head--', theme.header_style || 'light');
        swapClass(w, 'acs-quick--', theme.quick_style || 'capsule');
        swapClass(w, 'acs-density--', theme.density || 'cozy');
        swapClass(w, 'acs-edge--', theme.edge || 'hairline');
        swapClass(w, 'acs-type--', theme.type || 'neutral');
        swapClass(w, 'acs-theme--', theme.preset || 'plain');
        swapClass(w, 'acs-shadow--', val('panel_shadow', 'none'));
        // 深底/浅底由窗口背景色算出来，阈值必须与 PHP 的 TONE_DARK_MAX 一致
        swapClass(w, 'acs-tone--', luminance(val('surface_color', '#FFFFFF')) < TONE_DARK_MAX ? 'dark' : 'light');
        var left = val('position', 'right') === 'left';
        w.classList.toggle('acs-widget--left', left);
        w.classList.toggle('acs-widget--right', !left);
        var pill = val('launcher_style', 'bubble') === 'pill';
        w.classList.toggle('acs-widget--pill', pill);
        w.classList.toggle('acs-widget--bubble', !pill);
        w.classList.toggle('is-closed', pv.state === 'closed');
        w.classList.toggle('acs-preview-nolauncher', !on('show_launcher'));
        w.dataset.acsOpen = pv.state === 'open' ? 'true' : 'false';

        syncText(w);
        syncVisibility(w);
        fitStage(w);
    }

    /* ACS_MARKER_PV2 */

    /** 换掉同前缀的修饰类，只留一个。 */
    function swapClass(node, prefix, value) {
        Array.prototype.slice.call(node.classList).forEach(function (name) {
            if (name.indexOf(prefix) === 0) node.classList.remove(name);
        });
        if (value) node.classList.add(prefix + value);
    }

    function setTextIn(node, selector, text) {
        var target = node.querySelector(selector);
        if (target) target.textContent = text;
    }

    /* 同意门槛：文案与开关都要在预览里当场生效。服务端对空值有回落（清空标题会回到
     * 「开始对话前」），这里照抄同一组默认值，不然后台看到的是空的、保存后又冒出文字。
     * 链接用 DOM API 拼，不碰 innerHTML——预览里的文案同样来自用户输入。 */
    /* 非服务时段说明条的文案。清空回默认，和服务端 normalizeExperience 一致。 */
    function syncAway(w) {
        var node = w.querySelector('.acs-away-text');
        if (!node) return;
        var away = (readJson('experience_json', {}) || {}).away || {};
        node.textContent = String(away.text || '') || AWAY_TEXT_DEFAULT;
    }

    function syncConsent(w) {
        var block = w.querySelector('[data-acs-consent]');
        if (!block) return;
        var c = readJson('consent_json', {});
        var title = String(c.title || '') || '开始对话前';
        var body = String(c.text || '') || '我同意本站按隐私政策处理我在对话中提交的信息。';
        var titleNode = block.querySelector('.acs-consent-title');
        if (titleNode) {
            titleNode.textContent = title;
            titleNode.hidden = false;
        }
        var textNode = block.querySelector('.acs-consent-text');
        if (textNode) {
            textNode.textContent = body;
            var linkLabel = String(c.link_label || '');
            var linkUrl = String(c.link_url || '');
            // 两个都填才渲染链接，和服务端一致（只填一个当没填）
            if (linkLabel !== '' && linkUrl !== '') {
                textNode.appendChild(document.createTextNode(' '));
                var a = el('a', 'acs-consent-link', linkLabel);
                a.href = linkUrl;
                textNode.appendChild(a);
            }
        }
        setTextIn(w, '.acs-consent-accept', String(c.button || '') || '同意并开始');
    }

    function syncText(w) {
        setTextIn(w, '.acs-agent-copy strong', val('brand_name', 'AI客服'));
        var status = w.querySelector('.acs-agent-status');
        if (status) {
            // 第一个子节点是状态圆点，只替换后面的文字
            var dot = status.querySelector('.acs-dot');
            status.textContent = '';
            if (dot) status.appendChild(dot);
            status.appendChild(document.createTextNode(val('team_label', '智能在线服务')));
        }
        var welcome = w.querySelector('.acs-message--assistant .acs-message-bubble');
        if (welcome) welcome.textContent = val('welcome_message', '您好，我是您的 AI 客服。有什么可以帮您？');
        var placeholder = w.querySelector('#acs-message, .acs-composer textarea');
        if (placeholder) placeholder.placeholder = val('input_placeholder', '输入您的问题...');
        var counter = w.querySelector('[data-acs-counter]');
        if (counter) counter.textContent = '0/' + Math.round(num('message_max_chars', 2000));
        setTextIn(w, '.acs-ribbon-track', val('ribbon_text', ''));
        setTextIn(w, '.acs-teaser-text', val('teaser_text', ''));
        setTextIn(w, '.acs-tooltip', val('tooltip_text', ''));
        setTextIn(w, '.acs-handoff span', val('handoff_label', '联系人工客服'));
        setTextIn(w, '.acs-powered', val('brand_name', 'AI客服'));
        var label = w.querySelector('.acs-launcher-label');
        if (label) label.textContent = val('brand_name', 'AI客服');
        syncConsent(w);
        syncAway(w);

        // 快捷问题：整行重建，跟着输入框实时变
        var quickRow = w.querySelector('.acs-quick-row');
        if (quickRow) {
            quickRow.innerHTML = '';
            lines('quick_replies').slice(0, 6).forEach(function (text) {
                var chip = el('button', 'acs-quick-reply', text);
                chip.type = 'button';
                chip.tabIndex = -1;
                quickRow.appendChild(chip);
            });
        }

        var icons = { chat: 'bi-chat-dots-fill', sparkles: 'bi-stars', headset: 'bi-headset', question: 'bi-question-circle-fill' };
        var launcherIcon = w.querySelector('.acs-launcher-icon');
        var launcherImg = w.querySelector('.acs-launcher-img');
        var imageUrl = val('launcher_image_url', '');
        if (launcherIcon) launcherIcon.className = 'bi ' + (icons[val('launcher_icon', 'chat')] || icons.chat) + ' acs-launcher-icon';
        if (launcherImg) {
            if (imageUrl) launcherImg.src = imageUrl; else launcherImg.removeAttribute('src');
        }
        // 前台是"有图就只画图"，预览同构
        if (launcherIcon) launcherIcon.hidden = !!imageUrl;
        if (launcherImg) launcherImg.hidden = !imageUrl;

        var avatarUrl = val('avatar_url', '');
        w.querySelectorAll('.acs-avatar, .acs-message-avatar').forEach(function (slot) {
            var img = slot.querySelector('img');
            var glyph = slot.querySelector('i');
            if (avatarUrl) {
                if (!img) { img = el('img'); img.alt = ''; slot.appendChild(img); }
                img.src = avatarUrl;
                img.hidden = false;
                if (glyph) glyph.hidden = true;
            } else {
                if (img) img.hidden = true;
                if (glyph) glyph.hidden = false;
            }
        });
    }

    function syncVisibility(w) {
        var show = function (selector, visible) {
            var node = w.querySelector(selector);
            if (node) node.hidden = !visible;
        };
        show('.acs-ribbon', on('ribbon_enabled') && val('ribbon_text', '') !== '');
        show('.acs-teaser', on('teaser_enabled') && val('teaser_text', '') !== '' && pv.state === 'closed');
        show('.acs-handoff', val('handoff_url', '') !== '');
        // 多渠道展开在前台要点一下才摊开；预览里面板关着时直接摊开给人看效果
        var experience = readJson('experience_json', {});
        var chan = (experience && experience.channels) || {};
        show('[data-acs-channels]', !!chan.enabled && pv.state === 'closed');
        show('[data-acs-channels-toggle]', !!chan.enabled && pv.state === 'closed');
        // 同意门槛：拨开关就能看到效果（面板关着时整块面板都不可见，不必额外判断）
        show('[data-acs-consent]', !!(readJson('consent_json', {}) || {}).enabled);
        /* 非服务时段说明条：前台只在真的过了点才出现，预览里按「选了 notice + 开了时段」
         * 直接摊开——否则站长写完那句话没有任何地方能看见它长什么样。 */
        var awayCfg = (experience && experience.away) || {};
        show('[data-acs-away]', awayCfg.mode === 'notice' && on('schedule_enabled'));
        show('.acs-powered', on('show_powered_by'));
        show('.acs-avatar', on('show_avatar'));
        w.querySelectorAll('.acs-message-avatar').forEach(function (n) { n.hidden = !on('show_avatar'); });
        var tooltip = w.querySelector('.acs-tooltip');
        if (tooltip) {
            tooltip.hidden = val('tooltip_text', '') === '' || pv.state !== 'closed';
            tooltip.style.opacity = pv.state === 'closed' ? '1' : '';
        }
        var launcher = w.querySelector('.acs-launcher');
        if (launcher) {
            launcher.classList.toggle('has-badge', on('badge_enabled'));
            launcher.classList.toggle('is-open', pv.state === 'open');
            ['wiggle', 'bounce', 'pulse'].forEach(function (fx) { launcher.classList.remove('acs-fx--' + fx); });
            var fx = val('attention_effect', 'none');
            if (fx !== 'none' && pv.state === 'closed') launcher.classList.add('acs-fx--' + fx);
        }
        var tools = w.querySelectorAll('[data-acs-pick]');
        if (tools.length) {
            tools.forEach(function (button) {
                var kind = button.getAttribute('data-acs-pick');
                button.hidden = kind === 'emoji' ? !on('emoji_enabled') : !on('sticker_enabled');
            });
        }
    }

    /** 按舞台可用空间整体缩放。几何关系与真机一致，不另调一套尺寸。 */
    function fitStage(w) {
        if (!pv.stage) return;
        var pad = 28;
        var width = num('panel_width', 396) + num(pv.device === 'mobile' ? 'mobile_offset_x' : 'desktop_offset_x', 24);
        var height = num('panel_height', 600) + num('widget_size', 56) + 12
            + num(pv.device === 'mobile' ? 'mobile_offset_y' : 'desktop_offset_y', 24);
        var k = Math.min(1, (pv.stage.clientWidth - pad) / width, (pv.stage.clientHeight - pad) / height);
        pv.stage.style.setProperty('--acs-pv-k', String(Math.max(0.3, k)));
        pv.stage.classList.toggle('is-mobile', pv.device === 'mobile');
    }

    /* ACS_MARKER_PV3 */

    /* ---------------------------------------------------------------- 拖拽定位
     * 拖浮标改的是真实的边距字段；拖引流气泡/飘带/角标改 layout_json 的偏移。
     * 拖完就是保存前的真实值，不存在预览与前台两套坐标。 */

    function markDragTargets() {
        if (!pv.widget) return;
        [['.acs-launcher', 'launcher'], ['.acs-teaser', 'teaser'], ['.acs-ribbon', 'ribbon']].forEach(function (pair) {
            var node = pv.widget.querySelector(pair[0]);
            if (node) node.setAttribute('data-acs-drag', pair[1]);
        });
    }

    function initDrag() {
        if (!pv.stage) return;
        var active = null;
        var nudgeKey = function (target) { return target === 'launcher' ? 'launcher_nudge' : target; };

        pv.stage.addEventListener('pointerdown', function (event) {
            if (!pv.drag) return;
            var node = event.target.closest('[data-acs-drag]');
            if (!node) return;
            event.preventDefault();
            var target = node.getAttribute('data-acs-drag');
            // 缩放后 1px 屏幕位移对应 1/k 个配置单位，不换算的话拖起来会"变慢"
            var k = parseFloat(getComputedStyle(pv.stage).getPropertyValue('--acs-pv-k')) || 1;
            active = { node: node, target: target, x: event.clientX, y: event.clientY, k: k };
            if (target === 'launcher') {
                var mobile = pv.device === 'mobile';
                active.xKey = mobile ? 'mobile_offset_x' : 'desktop_offset_x';
                active.yKey = mobile ? 'mobile_offset_y' : 'desktop_offset_y';
                active.baseX = num(active.xKey, 24);
                active.baseY = num(active.yKey, 24);
            } else {
                var layout = readJson('layout_json', {});
                var current = layout[nudgeKey(target)] || { dx: 0, dy: 0 };
                active.baseX = current.dx || 0;
                active.baseY = current.dy || 0;
            }
            node.classList.add('is-grabbing');
            pv.stage.classList.add('is-dragging');
            try { node.setPointerCapture(event.pointerId); } catch (e) { /* 老浏览器忽略 */ }
        });

        pv.stage.addEventListener('pointermove', function (event) {
            if (!active) return;
            var dx = (event.clientX - active.x) / active.k;
            var dy = (event.clientY - active.y) / active.k;
            if (active.target === 'launcher') {
                // 右下角锚点：往左/往上拖等于边距变大
                var signX = val('position', 'right') === 'left' ? 1 : -1;
                setCtrl(active.xKey, Math.round(active.baseX + signX * dx));
                setCtrl(active.yKey, Math.round(active.baseY - dy));
            } else {
                var layout = readJson('layout_json', {});
                var key = nudgeKey(active.target);
                layout[key] = { dx: Math.round(active.baseX + dx), dy: Math.round(active.baseY + dy) };
                jsonCache.layout_json = layout;
                var dxInput = root.querySelector('[data-acs-nudge="' + key + '.dx"]');
                var dyInput = root.querySelector('[data-acs-nudge="' + key + '.dy"]');
                if (dxInput) dxInput.value = String(layout[key].dx);
                if (dyInput) dyInput.value = String(layout[key].dy);
                writeJson('layout_json');
            }
        });

        ['pointerup', 'pointercancel'].forEach(function (type) {
            pv.stage.addEventListener(type, function () {
                if (!active) return;
                active.node.classList.remove('is-grabbing');
                pv.stage.classList.remove('is-dragging');
                active = null;
            });
        });
    }

    /* ACS_MARKER_JS_14 */

    /* ---------------------------------------------------------------- 启动 */

    root.querySelectorAll('[data-acs-device]').forEach(function (button) {
        button.addEventListener('click', function () {
            pv.device = button.getAttribute('data-acs-device') === 'mobile' ? 'mobile' : 'desktop';
            root.querySelectorAll('[data-acs-device]').forEach(function (node) {
                var isActive = node === button;
                node.classList.toggle('is-active', isActive);
                node.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });
            sync();
        });
    });
    root.querySelectorAll('[data-acs-pv-state]').forEach(function (button) {
        button.addEventListener('click', function () {
            pv.state = button.getAttribute('data-acs-pv-state');
            root.querySelectorAll('[data-acs-pv-state]').forEach(function (node) {
                var isActive = node === button;
                node.classList.toggle('is-active', isActive);
                node.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });
            sync();
        });
    });
    var dragToggle = root.querySelector('[data-acs-pv-drag]');
    if (dragToggle) {
        dragToggle.addEventListener('change', function () {
            pv.drag = dragToggle.checked;
            if (pv.stage) pv.stage.classList.toggle('can-drag', pv.drag);
            var hint = pv.stage && pv.stage.querySelector('[data-acs-drag-hint]');
            if (pv.drag && pv.stage && !hint) {
                hint = el('div', 'acs-a-stage-hint', '拖动浮标 / 引流气泡 / 飘带，松手即写回表单');
                hint.setAttribute('data-acs-drag-hint', '');
                pv.stage.appendChild(hint);
            } else if (hint) {
                hint.hidden = !pv.drag;
            }
        });
    }

    /* ---------------------------------------------------------------- 媒体库
     * 所有"图片地址"字段都挂一个「选图」按钮，直接开核心的媒体选择框。
     * 让站长手抄 URL 是没道理的——图片本来就在站点媒体库里。 */
    function attachMediaPicker(fieldKey, type, label) {
        var input = ctrl(fieldKey);
        if (!input) return;
        var wrap = input.parentNode;
        if (!wrap || wrap.querySelector('[data-acs-media]')) return;
        var bar = el('div', 'acs-a-media-row');
        input.parentNode.insertBefore(bar, input);
        bar.appendChild(input);

        var pick = el('button', 'acs-a-btn acs-a-btn--sm');
        pick.type = 'button';
        pick.setAttribute('data-acs-media', fieldKey);
        pick.appendChild(icon('bi-images'));
        pick.appendChild(el('span', '', label || '选图'));
        pick.addEventListener('click', function () {
            if (!window.MediaPicker || typeof window.MediaPicker.open !== 'function') {
                toast('媒体选择框没加载出来，可以先手动填地址', true);
                return;
            }
            window.MediaPicker.open({
                type: type || 'image',
                onSelect: function (file) {
                    if (!file || !file.url) return;
                    setCtrl(fieldKey, file.url);
                    sync();
                }
            });
        });
        bar.appendChild(pick);

        var clear = el('button', 'acs-a-icon-btn');
        clear.type = 'button';
        clear.title = '清空';
        clear.setAttribute('aria-label', '清空' + (label || '图片'));
        clear.appendChild(icon('bi-x-lg'));
        clear.addEventListener('click', function () { setCtrl(fieldKey, ''); sync(); });
        bar.appendChild(clear);

        var preview = el('span', 'acs-a-media-thumb');
        preview.setAttribute('data-acs-media-thumb', fieldKey);
        bar.appendChild(preview);
        var paint = function () {
            var url = String(input.value || '').trim();
            preview.innerHTML = '';
            if (!url) { preview.hidden = true; return; }
            preview.hidden = false;
            var img = el('img');
            img.src = url;
            img.alt = '';
            preview.appendChild(img);
        };
        input.addEventListener('input', paint);
        paint();
    }

    ['avatar_url', 'launcher_image_url'].forEach(function (key) { attachMediaPicker(key, 'image', '选图'); });

    /* 会影响「模型没配好」那条提示的字段。只盯 provider_mode 的话，站长把接口地址填上了
     * 提示还挂在那儿，得去拨一下模型来源才消失。 */
    var PROVIDER_KEYS = ['setting_provider_mode', 'setting_system_model_id',
        'setting_custom_api_endpoint', 'setting_custom_model',
        // 密钥与"清除密钥"不是声明式 settings（所以没有 setting_ 前缀），
        // 不列进来，边填边看的提示就漏掉唯一一个能自己修好的缺项。
        'custom_api_key', 'clear_custom_api_key'];

    // 表单里任何变化都重算预览。用事件委托，面板动态插入的控件也能覆盖到。
    root.addEventListener('input', function (event) {
        if (event.target.closest('.acs-a-preview')) return;
        sync();
        if (PROVIDER_KEYS.indexOf(event.target.name) !== -1) syncProvider();
    });
    root.addEventListener('change', function (event) {
        if (event.target.closest('.acs-a-preview')) return;
        sync();
        if (PROVIDER_KEYS.indexOf(event.target.name) !== -1) syncProvider();
    });

    if (window.ResizeObserver && pv.stage) {
        new window.ResizeObserver(function () { sync(); }).observe(pv.stage);
    }
    window.addEventListener('resize', sync);

    // 保存前把 JSON 字段再落一次：面板里最后一次输入可能还没触发 writeJson
    var form = root.querySelector('form');
    if (form) {
        form.addEventListener('submit', function () {
            Object.keys(jsonCache).forEach(function (key) {
                var node = root.querySelector('[data-acs-json="' + key + '"]');
                if (node) node.value = JSON.stringify(jsonCache[key]);
            });
        });
    }

    /* 上一次保存校验失败的字段：把控件标红并滚到第一个。
     * 保存是 POST → flash → 302，字段名原本只能出现在那句话里，站长得自己在几十个
     * 控件里对着标签找。改动任意一个就把它的标记撤掉。 */
    function paintSaveErrors() {
        var keys = Array.isArray(BOOT.saveErrors) ? BOOT.saveErrors : [];
        if (!keys.length) return;
        var first = null;
        keys.forEach(function (key) {
            var node = ctrl(key);
            if (!node) return;                       // 出错字段不在本页（跨页回填），跳过
            var field = node.closest('[data-acs-field]') || node.parentNode;
            if (field && field.classList) field.classList.add('has-error');
            node.setAttribute('aria-invalid', 'true');
            if (!first) first = node;
            var clear = function () {
                if (field && field.classList) field.classList.remove('has-error');
                node.removeAttribute('aria-invalid');
                node.removeEventListener('input', clear);
                node.removeEventListener('change', clear);
            };
            node.addEventListener('input', clear);
            node.addEventListener('change', clear);
        });
        if (first) {
            // 不抢焦点：站长可能已经在改别的东西了，滚过去看得到就够
            if (first.scrollIntoView) first.scrollIntoView({ block: 'center', behavior: 'smooth' });
        }
    }

    mountAll();
    markDragTargets();
    initDrag();
    syncProvider();
    sync();
    paintSaveErrors();
}());
