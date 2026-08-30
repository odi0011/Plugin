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
        stickers_json: 'stickers'
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
    PANELS.presets = function (host) {
        var theme = readJson('theme_json', {});
        var gallery = el('div', 'acs-a-presets');
        Object.keys(BOOT.presets).forEach(function (id) {
            var preset = BOOT.presets[id];
            var v = preset.values || {};
            var card = el('button', 'acs-a-preset' + (theme.preset === id ? ' is-active' : ''));
            card.type = 'button';
            card.setAttribute('data-acs-preset', id);
            var thumb = el('div', 'acs-a-preset-thumb');
            thumb.style.setProperty('--pv-surface', v.surface_color || '#fff');
            thumb.style.setProperty('--pv-header', v.header_color || '#1677ff');
            thumb.style.setProperty('--pv-bot', v.bot_bubble_color || '#f3f4f6');
            thumb.style.setProperty('--pv-vis', v.visitor_bubble_color || '#1677ff');
            thumb.appendChild(el('div', 'bar'));
            thumb.appendChild(el('div', 'b1'));
            thumb.appendChild(el('div', 'b2'));
            card.appendChild(thumb);
            card.appendChild(el('div', 'acs-a-preset-name', preset.label));
            card.appendChild(el('div', 'acs-a-preset-note', preset.note));
            card.addEventListener('click', function () {
                applyPreset(id, v);
                gallery.querySelectorAll('.acs-a-preset').forEach(function (n) { n.classList.remove('is-active'); });
                card.classList.add('is-active');
            });
            gallery.appendChild(card);
        });
        host.appendChild(gallery);
        host.appendChild(el('p', 'acs-a-help', '套用预设只是把一组值写进下面的控件，还没保存；不满意可以直接改，或者换一个预设。'));
    };

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
            ['恢复浅色默认', function () { applyPreset('aurora', BOOT.presets.aurora.values); }]
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
        var botDark = luminance(val('bot_bubble_color', '#F3F4F6')) < 0.45;
        setCtrl('bot_bubble_text_color', botDark ? '#E5E7EB' : '#111827');
        setCtrl('visitor_bubble_text_color', luminance(val('visitor_bubble_color', '#4F46E5')) < 0.5 ? '#FFFFFF' : '#111827');
        setCtrl('header_text_color', luminance(val('header_color', '#4F46E5')) < 0.5 ? '#FFFFFF' : '#111827');
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
        grid.appendChild(segmented('theme_json', 'header_style', '顶栏', { solid: '纯色', gradient: '渐变', light: '浅底' }));
        grid.appendChild(segmented('theme_json', 'quick_style', '快捷问题', { capsule: '胶囊', ghost: '幽灵', sketch: '手绘' }));
        grid.appendChild(segmented('theme_json', 'density', '密度', { cozy: '舒适', compact: '紧凑' }));
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
        host.appendChild(el('p', 'acs-a-help', '打开右侧预览的「拖拽定位」后可以直接拖：浮标会写回桌面/移动端边距，其余三个写回这里的偏移。'));
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

    function rowShell(title, meta, iconName, onRemove) {
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

    function uploadKnowledge(files, knowledge, host) {
        var form = new FormData();
        Array.prototype.slice.call(files, 0, 10).forEach(function (file) { form.append('file[]', file); });
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
                }
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
    PANELS.owner = function (host) {
        var owner = readJson('owner_json', { socials: [] });
        if (!Array.isArray(owner.socials)) owner.socials = [];
        var save = function () { jsonCache.owner_json = owner; writeJson('owner_json'); };

        var grid = el('div', 'acs-a-tool-grid');
        grid.appendChild(textField('姓名', owner.name, 60, function (v) { owner.name = v; save(); }, '例如：李工'));
        grid.appendChild(textField('头衔', owner.title, 80, function (v) { owner.title = v; save(); }, '例如：外贸负责人 / 技术支持'));
        grid.appendChild(textField('头像地址', owner.avatar, 2048, function (v) { owner.avatar = v; save(); }, 'https://…'));
        host.appendChild(grid);
        host.appendChild(textareaField('一句话简介', owner.bio, 300, function (v) { owner.bio = v; save(); },
            '例如：负责工业阀门线的选型与报价，工作日 30 分钟内回复。'));

        var box = el('div', 'acs-a-sub');
        box.appendChild(el('h3', '', '社媒与联系方式'));
        box.appendChild(el('p', 'acs-a-sub-note', '微信与电话按"点击复制/拨号"渲染，其余按链接渲染。名片卡与社媒卡共用这份数据。'));
        box.appendChild(listEditor({
            items: function () { return owner.socials; },
            empty: '还没有联系方式。加一条微信或 WhatsApp，AI 就能在访客要联系方式时递过去。',
            addLabel: '加一条',
            add: function () {
                if (owner.socials.length >= 12) { toast('最多 12 条', true); return; }
                owner.socials.push({ network: 'wechat', label: '', url: '' });
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
                    social.network === 'wechat' ? '微信号' : (social.network === 'phone' ? '电话号码' : (social.network === 'email' ? '邮箱' : '链接')),
                    social.url, 200, function (v) { social.url = v; save(); },
                    social.network === 'wechat' ? 'my-wechat-id' : (social.network === 'phone' ? '+86 138…' : 'https://…')
                ));
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
            var form = new FormData();
            Array.prototype.slice.call(packFile.files, 0, 10).forEach(function (file) { form.append('file[]', file); });
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

    /* ---------------------------------------------------------------- 实时预览 */

    var pv = {
        stage: root.querySelector('[data-acs-preview-stage]'),
        widget: root.querySelector('[data-acs-pv-widget]'),
        panel: root.querySelector('[data-acs-pv-panel]'),
        launcher: root.querySelector('.acs-pv-launcher'),
        device: 'desktop',
        state: 'open',
        showCards: true,
        drag: false
    };

    function sync() {
        if (!pv.widget) return;
        var w = pv.widget;
        var mobile = pv.device === 'mobile';

        var pairs = {
            '--pv-accent': val('accent_color', '#4F46E5'),
            '--pv-surface': val('surface_color', '#FFFFFF'),
            '--pv-text': val('text_color', '#111827'),
            '--pv-muted': val('muted_color', '#6B7280'),
            '--pv-header-bg': val('header_color', '#4F46E5'),
            '--pv-header-fg': val('header_text_color', '#FFFFFF'),
            '--pv-bot': val('bot_bubble_color', '#F3F4F6'),
            '--pv-bot-text': val('bot_bubble_text_color', '#111827'),
            '--pv-vis': val('visitor_bubble_color', '#4F46E5'),
            '--pv-vis-text': val('visitor_bubble_text_color', '#FFFFFF'),
            '--pv-radius': Math.round(num('panel_radius', 16) * 0.8) + 'px',
            '--pv-size': Math.round(num('widget_size', 56) * 0.72) + 'px',
            '--pv-corner': Math.round(num('launcher_corner', 10) * 0.8) + 'px',
            '--pv-font': (BOOT.fonts && BOOT.fonts[val('font_family', 'system')]) || 'inherit'
        };
        Object.keys(pairs).forEach(function (name) { w.style.setProperty(name, pairs[name]); });

        var layout = readJson('layout_json', {});
        [['teaser', 'teaser'], ['ribbon', 'ribbon'], ['badge', 'badge'], ['launcher_nudge', 'launcher']].forEach(function (pair) {
            var value = layout[pair[0]] || { dx: 0, dy: 0 };
            w.style.setProperty('--pv-' + pair[1] + '-dx', (value.dx || 0) + 'px');
            w.style.setProperty('--pv-' + pair[1] + '-dy', (value.dy || 0) + 'px');
        });

        var left = val('position', 'right') === 'left';
        w.classList.toggle('acs-pv--left', left);
        w.classList.toggle('acs-pv--right', !left);
        w.style[left ? 'left' : 'right'] = Math.max(6, Math.round(num(mobile ? 'mobile_offset_x' : 'desktop_offset_x', 24) * 0.7)) + 'px';
        w.style[left ? 'right' : 'left'] = '';
        w.style.bottom = Math.max(8, Math.round(num(mobile ? 'mobile_offset_y' : 'desktop_offset_y', 24) * 0.7)) + 'px';

        // 面板尺寸按舞台等比缩，比例同时作用于字号，所以缩完的观感和真机一致
        var stageW = pv.stage ? pv.stage.clientWidth : 320;
        var stageH = pv.stage ? pv.stage.clientHeight : 460;
        var panelW = num('panel_width', 396);
        var panelH = num('panel_height', 600);
        var k = Math.max(0.34, Math.min(1, (stageW - 60) / panelW, (stageH - 110) / panelH));
        w.style.setProperty('--pv-k', String(k));
        if (pv.panel) {
            pv.panel.style.width = Math.round(panelW * k) + 'px';
            pv.panel.style.height = Math.round(panelH * k) + 'px';
        }
        w.style.fontSize = (num('font_size', 14) * k).toFixed(1) + 'px';

        var theme = readJson('theme_json', {});
        ['soft', 'flat', 'outline', 'glass', 'sketch'].forEach(function (style) {
            w.classList.toggle('is-' + style, theme.bubble_style === style);
        });
        w.classList.toggle('is-gradient', theme.header_style === 'gradient');
        ['capsule', 'ghost', 'sketch'].forEach(function (style) {
            w.classList.toggle('is-quick-' + style, theme.quick_style === style);
        });
        var body = root.querySelector('[data-acs-pv-body]');
        if (body) body.parentNode.classList.toggle('acs-density--compact', theme.density === 'compact');

        // 文案
        setText('[data-acs-pv="brand_name"]', val('brand_name', 'AI客服'));
        setText('[data-acs-pv="team_label"]', val('team_label', '智能在线服务'));
        setText('[data-acs-pv="welcome_message"]', val('welcome_message', '您好，我是您的 AI 客服。有什么可以帮您？'));
        setText('[data-acs-pv="input_placeholder"]', val('input_placeholder', '输入您的问题...'));
        setText('[data-acs-pv-ribbon-text]', val('ribbon_text', '新客首单立减 5%'));
        setText('[data-acs-pv-teaser-text]', val('teaser_text', '有问题？随时咨询'));
        var counter = root.querySelector('.acs-pv-counter');
        if (counter) counter.textContent = '0/' + Math.round(num('message_max_chars', 2000));

        // 显隐
        show('[data-acs-pv-ribbon]', on('ribbon_enabled') && val('ribbon_text', '') !== '');
        show('[data-acs-pv-teaser]', on('teaser_enabled') && val('teaser_text', '') !== '' && on('show_launcher'));
        show('[data-acs-pv-tooltip]', val('tooltip_text', '') !== '' && on('show_launcher'));
        show('[data-acs-pv-handoff]', val('handoff_url', '') !== '');
        show('[data-acs-pv-powered]', on('show_powered_by'));
        show('.acs-pv-badge', on('badge_enabled') && on('show_launcher'));
        show('[data-acs-pv-avatar]', on('show_avatar'));
        root.querySelectorAll('[data-acs-pv-mini-avatar]').forEach(function (node) { node.hidden = !on('show_avatar'); });
        if (pv.launcher) pv.launcher.hidden = !on('show_launcher');

        // 浮标形态
        if (pv.launcher) {
            var pill = val('launcher_style', 'bubble') === 'pill';
            pv.launcher.classList.toggle('is-pill', pill);
            var label = pv.launcher.querySelector('.acs-pv-launcher-label');
            if (label) { label.hidden = !pill; label.textContent = val('brand_name', 'AI客服'); }
            var img = pv.launcher.querySelector('.acs-pv-launcher-img');
            var ico = pv.launcher.querySelector('.acs-pv-launcher-icon');
            var url = val('launcher_image_url', '');
            if (img) { if (url) img.src = url; else img.removeAttribute('src'); img.hidden = !url; }
            if (ico) {
                var map = { chat: 'bi-chat-dots-fill', sparkles: 'bi-stars', headset: 'bi-headset', question: 'bi-question-circle-fill' };
                ico.className = 'bi ' + (map[val('launcher_icon', 'chat')] || map.chat) + ' acs-pv-launcher-icon';
                ico.hidden = !!url;
            }
        }
        var avatar = root.querySelector('[data-acs-pv-avatar]');
        if (avatar) {
            var avatarUrl = val('avatar_url', '');
            var avatarImg = avatar.querySelector('img');
            var avatarIcon = avatar.querySelector('i');
            if (avatarImg) { if (avatarUrl) avatarImg.src = avatarUrl; else avatarImg.removeAttribute('src'); avatarImg.hidden = !avatarUrl; }
            if (avatarIcon) avatarIcon.hidden = !!avatarUrl;
        }

        renderQuick();
        renderPreviewCards();
        w.classList.toggle('is-closed', pv.state === 'closed');
        if (pv.stage) pv.stage.classList.toggle('is-mobile', mobile);
        renderComposerTools();
    }

    function setText(selector, text) {
        var node = root.querySelector(selector);
        if (node) node.textContent = text;
    }
    function show(selector, visible) {
        var node = root.querySelector(selector);
        if (node) node.hidden = !visible;
    }

    /* ACS_MARKER_JS_13 */

    function renderQuick() {
        var row = root.querySelector('.acs-pv-quick-row');
        var title = root.querySelector('[data-acs-pv-quick-title]');
        if (!row) return;
        row.innerHTML = '';
        lines('quick_replies').slice(0, 3).forEach(function (line) {
            row.appendChild(el('span', 'acs-pv-chip', line));
        });
        if (title) {
            var text = val('quick_replies_title', '');
            title.textContent = text || '猜你想问';
            title.hidden = text === '';
        }
    }

    function renderComposerTools() {
        var host = root.querySelector('[data-acs-pv-tools]');
        if (!host) return;
        host.innerHTML = '';
        if (on('emoji_enabled')) host.appendChild(icon('bi-emoji-smile'));
        if (on('sticker_enabled')) host.appendChild(icon('bi-sticky'));
    }

    var SAMPLE = {
        product: [
            { title: 'IP68 防水传感器 M12', meta: 'USD 128.00 · 现货' },
            { title: '工业级防水连接器套件', meta: 'USD 76.50 · 可预订' },
            { title: '户外一体机 Pro（防水）', meta: 'USD 310.00 · 现货' }
        ],
        article: [
            { title: '防水等级怎么选：IP65 / IP67 / IP68', meta: '选型指南' },
            { title: '海运包装与防潮处理清单', meta: '常见问答' },
            { title: '客户案例：沿海项目三年零返修', meta: '案例' }
        ]
    };

    function renderPreviewCards() {
        var host = root.querySelector('[data-acs-pv-cards]');
        var toolRow = root.querySelector('[data-acs-pv-toolrow]');
        if (!host) return;
        if (toolRow) toolRow.hidden = !pv.showCards;
        host.innerHTML = '';
        if (!pv.showCards) return;

        var cards = readJson('cards_json', {});
        var kind = 'product';
        var preset = (cards[kind] && cards[kind].preset) || 'stack';
        host.className = 'acs-pv-cards is-' + preset;
        var cta = (cards[kind] && cards[kind].cta) || '查看详情';
        var max = Math.max(1, Math.min(3, (cards[kind] && cards[kind].max) || 3));

        SAMPLE[kind].slice(0, max).forEach(function (sample) {
            var card = el('div', 'acs-pv-card');
            var thumb = el('div', 'acs-pv-card-thumb');
            thumb.appendChild(icon(kind === 'product' ? 'bi-box-seam' : 'bi-journal-text'));
            card.appendChild(thumb);
            var main = el('div', 'acs-pv-card-main');
            main.appendChild(el('div', 'acs-pv-card-title', sample.title));
            if (cards[kind] && cards[kind].show_price === false) {
                main.appendChild(el('div', 'acs-pv-card-meta', '现货'));
            } else {
                main.appendChild(el('div', 'acs-pv-card-meta', sample.meta));
            }
            main.appendChild(el('div', 'acs-pv-card-cta', cta + ' ›'));
            card.appendChild(main);
            host.appendChild(card);
        });
    }

    /* ---------------------------------------------------------------- 拖拽定位 */

    function initDrag() {
        if (!pv.stage) return;
        var active = null;

        function nudgeKeyFor(target) {
            return target === 'launcher' ? 'launcher_nudge' : target;
        }

        pv.stage.addEventListener('pointerdown', function (event) {
            if (!pv.drag) return;
            var node = event.target.closest('[data-acs-pv-drag-target]');
            if (!node) return;
            event.preventDefault();
            var target = node.getAttribute('data-acs-pv-drag-target');
            active = { node: node, target: target, x: event.clientX, y: event.clientY };
            // 浮标拖动改的是真实边距字段，其余三个改 layout_json 的偏移
            if (target === 'launcher') {
                var mobile = pv.device === 'mobile';
                active.baseX = num(mobile ? 'mobile_offset_x' : 'desktop_offset_x', 24);
                active.baseY = num(mobile ? 'mobile_offset_y' : 'desktop_offset_y', 24);
                active.xKey = mobile ? 'mobile_offset_x' : 'desktop_offset_x';
                active.yKey = mobile ? 'mobile_offset_y' : 'desktop_offset_y';
            } else {
                var layout = readJson('layout_json', {});
                var current = layout[nudgeKeyFor(target)] || { dx: 0, dy: 0 };
                active.baseX = current.dx || 0;
                active.baseY = current.dy || 0;
            }
            node.classList.add('is-grabbing');
            pv.stage.classList.add('is-dragging');
            node.setPointerCapture(event.pointerId);
        });

        pv.stage.addEventListener('pointermove', function (event) {
            if (!active) return;
            var dx = event.clientX - active.x;
            var dy = event.clientY - active.y;
            if (active.target === 'launcher') {
                // 右下角锚点：往左/上拖等于边距变大
                var signX = val('position', 'right') === 'left' ? 1 : -1;
                setCtrl(active.xKey, Math.round(active.baseX + signX * dx));
                setCtrl(active.yKey, Math.round(active.baseY - dy));
            } else {
                var layout = readJson('layout_json', {});
                var key = nudgeKeyFor(active.target);
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
    var cardsToggle = root.querySelector('[data-acs-pv-showcards]');
    if (cardsToggle) {
        cardsToggle.addEventListener('change', function () { pv.showCards = cardsToggle.checked; sync(); });
    }
    var dragToggle = root.querySelector('[data-acs-pv-drag]');
    if (dragToggle) {
        dragToggle.addEventListener('change', function () {
            pv.drag = dragToggle.checked;
            if (pv.stage) pv.stage.classList.toggle('can-drag', pv.drag);
            var hint = root.querySelector('[data-acs-drag-hint]');
            if (hint) hint.hidden = !pv.drag;
        });
    }

    // 表单里任何变化都重算预览。用事件委托，面板动态插入的控件也能覆盖到。
    root.addEventListener('input', function (event) {
        if (event.target.closest('.acs-a-preview')) return;
        sync();
        if (event.target.name === 'setting_provider_mode') syncProvider();
    });
    root.addEventListener('change', function (event) {
        if (event.target.closest('.acs-a-preview')) return;
        sync();
        if (event.target.name === 'setting_provider_mode') syncProvider();
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

    mountAll();
    initDrag();
    syncProvider();
    sync();
}());
