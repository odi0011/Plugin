/*
 * 可视化编辑器：后台前端（1.2.0）。
 *
 * 职责边界：
 *   - 树的**持有者**是这里：任何结构修改先改树，再整体重渲染画布。整体重渲染
 *     比局部补丁慢，但换来「画布上看到的一定是树的样子」——不会出现两者不同步。
 *   - 画布结构与 CSS 的生成规则镜像 src/Renderer.php 与 src/StyleCompiler.php；
 *     基础样式（config.baseCss）由服务端原样下发，两端产物同源。镜像只服务预览，
 *     **真正入库的 HTML/CSS 由服务端在 /save 端点重新渲染**，客户端说了不算。
 *   - 交互外壳：模式条上那颗独立入口按钮、全页蒙版加载动画、全屏编辑台的开合。
 *     动画走站点自带的 GSAP（window.gsap），拿不到时退化成瞬时显隐而不是报错。
 *
 * 与核心的关系：只读核心的模式切换 API（window.ContentEditorModes）与内容字段
 * 输入框。核心的代码 / 富文本面板一个字节都不碰。
 */
(function () {
    'use strict';

    var BREAKPOINTS = ['desktop', 'tablet', 'mobile'];
    var BP_LABELS = { desktop: '桌面', tablet: '平板', mobile: '手机' };

    function boot() {
        var panel = document.getElementById('ve-panel');
        var configEl = document.getElementById('ve-panel-config');
        if (!panel || !configEl) return;

        var config;
        try { config = JSON.parse(configEl.textContent); } catch (e) { return; }
        if (!config.canUse || !window.ContentEditorModes) return;

        var veil = document.getElementById('ve-veil');
        var stage = document.getElementById('ve-stage');
        if (!veil || !stage) return;
        // 浮层必须挂在 body 下：留在表单里会被带 transform / overflow 的祖先裁掉。
        document.body.appendChild(veil);
        document.body.appendChild(stage);

        var canvas = stage.querySelector('[data-ve-canvas]');
        var canvasStyle = stage.querySelector('[data-ve-canvas-style]');
        var frame = stage.querySelector('[data-ve-frame]');
        var statusEl = stage.querySelector('[data-ve-status]');
        var inspector = stage.querySelector('[data-ve-inspector]');
        var inspectorBody = stage.querySelector('[data-ve-inspector-body]');
        var inspectorTarget = stage.querySelector('[data-ve-inspection-target]');
        var applyButton = stage.querySelector('[data-ve-action="apply"]');
        var form = panel.closest('form');
        if (!canvas || !inspector || !inspectorBody) return;

        var gsap = window.gsap || null;
        var reduceMotion = window.matchMedia
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        var tree = null;
        var selection = null;      // {kind:'section'|'column'|'widget', si, ci, wi}
        var breakpoint = 'desktop';
        var dirty = false;         // 树相对「已写回表单的产物」是否有变化
        var loaded = false;        // 是否已经从服务端取到树
        var busy = false;

        // ================= 基础工具 =================

        function esc(value) {
            return String(value == null ? '' : value)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        function el(tag, className, text) {
            var node = document.createElement(tag);
            if (className) node.className = className;
            if (text != null) node.textContent = text;
            return node;
        }

        function emptyStyle() {
            return { desktop: {}, tablet: {}, mobile: {} };
        }

        function newId() {
            var bytes = new Uint8Array(5);
            if (window.crypto && window.crypto.getRandomValues) {
                window.crypto.getRandomValues(bytes);
            } else {
                for (var i = 0; i < bytes.length; i++) bytes[i] = Math.floor(Math.random() * 256);
            }
            return 've' + Array.prototype.map.call(bytes, function (b) {
                return (b < 16 ? '0' : '') + b.toString(16);
            }).join('');
        }

        function newColumn(percent) {
            var width = {};
            BREAKPOINTS.forEach(function (bp) {
                // 平板 / 手机默认整宽：多栏在小屏上并排几乎总是错的排布。
                width[bp] = bp === 'desktop' ? (percent || 100) : 100;
            });
            return { id: newId(), width: width, style: emptyStyle(), widgets: [] };
        }

        function newSection(percents) {
            var columns = (percents && percents.length ? percents : [100]).map(newColumn);
            return { id: newId(), layout: 'boxed', style: emptyStyle(), columns: columns };
        }

        function blankTree() {
            return { version: 1, style: emptyStyle(), sections: [newSection([100])] };
        }

        function normalizeWidget(type, content) {
            var definition = config.widgets[type] || config.widgets.html;
            type = config.widgets[type] ? type : 'html';
            var merged = {};
            Object.keys(definition.defaults).forEach(function (field) {
                merged[field] = (content && content[field] != null && content[field] !== '')
                    ? content[field] : definition.defaults[field];
            });
            return { id: newId(), type: type, content: merged, style: emptyStyle() };
        }

        function findNode(sel) {
            if (!tree || !sel) return null;
            var section = tree.sections[sel.si];
            if (!section) return null;
            if (sel.kind === 'section') return section;
            var column = (section.columns || [])[sel.ci];
            if (!column) return null;
            if (sel.kind === 'column') return column;
            return (column.widgets || [])[sel.wi] || null;
        }

        function pathOf(sel) {
            if (!sel) return '';
            if (sel.kind === 'section') return String(sel.si);
            if (sel.kind === 'column') return sel.si + '.' + sel.ci;
            return sel.si + '.' + sel.ci + '.' + sel.wi;
        }

        // ================= 渲染（镜像 Renderer.php）=================

        function alignOf(content) {
            return ['left', 'center', 'right', 'justify'].indexOf(content.align) >= 0 ? content.align : 'left';
        }

        function isMediaUrl(url) {
            return /^(https?:\/\/|\/)[^\s"'<>]+\.(?:png|jpe?g|gif|webp|avif|svg)(\?[^\s"'<>]*)?$/i.test(String(url || ''));
        }

        function isSafeUrl(url) {
            url = String(url || '');
            if (!url || /[\s<>"']/.test(url)) return false;
            if (/^#[A-Za-z0-9_-]{1,80}$/.test(url)) return true;
            if (url.indexOf('/') === 0 && url.indexOf('//') !== 0) return url.indexOf('..') < 0;
            if (/^mailto:[^@\s]+@[^@\s]+$/i.test(url)) return true;
            if (/^tel:\+?[0-9 ()-]{3,32}$/i.test(url)) return true;
            return /^https?:\/\/[^/]/i.test(url);
        }

        function clampInt(value, min, max, fallback) {
            var n = parseInt(value, 10);
            if (isNaN(n)) n = fallback;
            return Math.max(min, Math.min(max, n));
        }

        function iconClass(name) {
            name = String(name || '').toLowerCase().trim();
            return /^[a-z0-9-]{1,40}$/.test(name) ? 'bi bi-' + name : 'bi bi-square';
        }

        /** 控件里的颜色字段：过一遍与样式同源的校验，不合法就当没填。 */
        function colorOrEmpty(value) {
            var validated = validateStyleValue('text_color', value);
            return validated === null ? '' : validated;
        }

        function iconTagHtml(content, size) {
            var px = clampInt(size, 12, 160, 40);
            var color = colorOrEmpty(content.color);
            return '<i class="' + esc(iconClass(content.name)) + '" style="font-size:' + px + 'px;line-height:1;'
                + (color ? 'color:' + esc(color) + ';' : '') + '" aria-hidden="true"></i>';
        }

        function widgetBodyHtml(widget) {
            var c = widget.content || {};
            switch (widget.type) {
                case 'heading': {
                    var level = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'].indexOf(c.level) >= 0 ? c.level : 'h2';
                    return '<' + level + ' class="ve-heading ve-align-' + alignOf(c) + '">' + esc(c.text) + '</' + level + '>';
                }
                case 'text':
                    return '<div class="ve-text ve-align-' + alignOf(c) + '">' + String(c.html || '') + '</div>';
                case 'html':
                    return '<div class="ve-html">' + String(c.html || '') + '</div>';
                case 'image': {
                    if (!c.src || !isMediaUrl(c.src)) return '<div class="ve-image-placeholder">未选择图片</div>';
                    var width = clampInt(c.width, 5, 100, 100);
                    var img = '<img src="' + esc(c.src) + '" alt="' + esc(c.alt) + '" loading="lazy" style="width:' + width + '%">';
                    if (c.url && isSafeUrl(c.url)) img = '<a href="' + esc(c.url) + '">' + img + '</a>';
                    return '<figure class="ve-image ve-align-' + alignOf(c) + '">' + img + '</figure>';
                }
                case 'button': {
                    if (!c.text) return '';
                    var variant = ['primary', 'outline', 'ghost'].indexOf(c.variant) >= 0 ? c.variant : 'primary';
                    var size = ['sm', 'md', 'lg'].indexOf(c.size) >= 0 ? c.size : 'md';
                    var classes = 've-button ve-button-' + variant + ' ve-button-' + size;
                    if (c.url && isSafeUrl(c.url)) {
                        var target = c.target === '_blank' ? '_blank' : '_self';
                        return '<div class="ve-button-wrap ve-align-' + alignOf(c) + '">'
                            + '<a class="' + classes + '" href="' + esc(c.url) + '" target="' + target + '"'
                            + (target === '_blank' ? ' rel="noopener noreferrer"' : '') + '>' + esc(c.text) + '</a></div>';
                    }
                    return '<div class="ve-button-wrap ve-align-' + alignOf(c) + '">'
                        + '<span class="' + classes + '">' + esc(c.text) + '</span></div>';
                }
                case 'list': {
                    var marker = ['disc', 'decimal', 'check', 'none'].indexOf(c.marker) >= 0 ? c.marker : 'disc';
                    var tag = marker === 'decimal' ? 'ol' : 'ul';
                    var items = String(c.items || '').split('\n').map(function (line) { return line.trim(); })
                        .filter(Boolean).map(function (line) { return '<li>' + esc(line) + '</li>'; }).join('');
                    if (!items) return '';
                    return '<' + tag + ' class="ve-list ve-list-' + marker + ' ve-align-' + alignOf(c) + '">' + items + '</' + tag + '>';
                }
                case 'quote': {
                    var cite = String(c.cite || '');
                    return '<blockquote class="ve-quote ve-align-' + alignOf(c) + '"><p>' + esc(c.text) + '</p>'
                        + (cite ? '<cite>' + esc(cite) + '</cite>' : '') + '</blockquote>';
                }
                case 'divider': {
                    var lineStyle = ['solid', 'dashed', 'dotted'].indexOf(c.style) >= 0 ? c.style : 'solid';
                    var borderColor = colorOrEmpty(c.color);
                    return '<hr class="ve-divider" style="border-top-style:' + lineStyle
                        + ';border-top-width:' + clampInt(c.thickness, 1, 12, 1) + 'px;'
                        + (borderColor ? 'border-top-color:' + esc(borderColor) + ';' : '') + '">';
                }
                case 'spacer':
                    return '<div class="ve-spacer" style="height:' + clampInt(c.height, 4, 400, 40) + 'px"></div>';
                case 'embed': {
                    if (!/^[A-Za-z0-9_-]{1,64}$/.test(String(c.video_id || ''))) {
                        return '<div class="ve-image-placeholder">未填写视频 ID</div>';
                    }
                    var providers = {
                        vimeo: 'https://player.vimeo.com/video/',
                        bilibili: 'https://player.bilibili.com/player.html?bvid=',
                        youtube: 'https://www.youtube-nocookie.com/embed/'
                    };
                    var src = (providers[c.provider] || providers.youtube) + c.video_id;
                    var ratio = ['16-9', '4-3', '1-1'].indexOf(c.ratio) >= 0 ? c.ratio : '16-9';
                    return '<div class="ve-embed ve-embed-' + ratio + '"><iframe src="' + esc(src) + '"'
                        + ' title="' + esc(c.title || '嵌入视频') + '" loading="lazy" allowfullscreen'
                        + ' referrerpolicy="strict-origin-when-cross-origin" frameborder="0"></iframe></div>';
                }
                case 'icon':
                    return '<div class="ve-icon ve-align-' + alignOf(c) + '">' + iconTagHtml(c, c.size) + '</div>';
                case 'iconbox': {
                    var layout = c.layout === 'left' ? 'left' : 'top';
                    return '<div class="ve-iconbox ve-iconbox-' + layout + ' ve-align-' + alignOf(c) + '">'
                        + '<div class="ve-iconbox-icon">' + iconTagHtml(c, c.size) + '</div>'
                        + '<div class="ve-iconbox-body">'
                        + (c.title ? '<h3 class="ve-iconbox-title">' + esc(c.title) + '</h3>' : '')
                        + (c.text ? '<p class="ve-iconbox-text">' + esc(c.text) + '</p>' : '')
                        + '</div></div>';
                }
                case 'imagebox': {
                    var media = (c.src && isMediaUrl(c.src))
                        ? '<img src="' + esc(c.src) + '" alt="' + esc(c.alt) + '" loading="lazy">'
                        : '<div class="ve-image-placeholder">未选择图片</div>';
                    if (c.url && isSafeUrl(c.url)) media = '<a href="' + esc(c.url) + '">' + media + '</a>';
                    return '<div class="ve-imagebox ve-align-' + alignOf(c) + '">'
                        + '<div class="ve-imagebox-media">' + media + '</div>'
                        + '<div class="ve-imagebox-body">'
                        + (c.title ? '<h3 class="ve-imagebox-title">' + esc(c.title) + '</h3>' : '')
                        + (c.text ? '<p class="ve-imagebox-text">' + esc(c.text) + '</p>' : '')
                        + '</div></div>';
                }
                case 'alert': {
                    if (!c.title && !c.text) return '';
                    var tone = ['info', 'success', 'warning', 'danger'].indexOf(c.tone) >= 0 ? c.tone : 'info';
                    return '<div class="ve-alert ve-alert-' + tone + '" role="note">'
                        + (c.title ? '<strong class="ve-alert-title">' + esc(c.title) + '</strong>' : '')
                        + (c.text ? '<span class="ve-alert-text">' + esc(c.text) + '</span>' : '')
                        + '</div>';
                }
                case 'progress': {
                    var value = clampInt(c.value, 0, 100, 0);
                    var barColor = validateStyleValue('background_color', c.color);
                    var showValue = String(c.showvalue || 'yes') !== 'no';
                    return '<div class="ve-progress">'
                        + ((c.label || showValue)
                            ? '<div class="ve-progress-head">'
                                + (c.label ? '<span class="ve-progress-label">' + esc(c.label) + '</span>' : '')
                                + (showValue ? '<span class="ve-progress-value">' + value + '%</span>' : '')
                                + '</div>'
                            : '')
                        + '<div class="ve-progress-track" role="progressbar" aria-valuenow="' + value + '"'
                        + ' aria-valuemin="0" aria-valuemax="100"'
                        + (c.label ? ' aria-label="' + esc(c.label) + '"' : '') + '>'
                        + '<div class="ve-progress-bar" style="width:' + value + '%;'
                        + (barColor ? 'background-color:' + esc(barColor) + ';' : '') + '"></div>'
                        + '</div></div>';
                }
            }
            return '';
        }

        /** 画布结构。editing=true 时额外挂上选择 / 拖放需要的 data-* 与把手。 */
        function renderTree(editing) {
            if (!tree) return '';
            var html = '<div class="ve-doc ve-doc-' + esc(config.sourceKey) + '">';
            tree.sections.forEach(function (section, si) {
                html += '<section data-ve="' + esc(section.id) + '" class="ve-section ve-section-'
                    + (section.layout === 'full' ? 'full' : 'boxed') + '"'
                    + (editing ? ' data-ve-kind="section" data-ve-path="' + si + '"' : '') + '>'
                    + '<div class="ve-section-inner">';
                (section.columns || []).forEach(function (column, ci) {
                    html += '<div data-ve="' + esc(column.id) + '" class="ve-column"'
                        + (editing ? ' data-ve-kind="column" data-ve-path="' + si + '.' + ci + '"' : '') + '>';
                    (column.widgets || []).forEach(function (widget, wi) {
                        var definition = config.widgets[widget.type];
                        if (!definition) return;
                        html += '<div data-ve="' + esc(widget.id) + '" class="ve-widget ve-widget-' + esc(widget.type) + '"'
                            + (editing ? ' data-ve-kind="widget" data-ve-path="' + si + '.' + ci + '.' + wi + '"' : '')
                            + '>' + widgetBodyHtml(widget)
                            + (editing ? '<span class="ve-handle">' + esc(definition.label) + '</span>' : '')
                            + '</div>';
                    });
                    if (editing && !(column.widgets || []).length) {
                        html += '<div class="ve-column-empty">把左侧控件拖进来，或选中这一栏后点左侧控件</div>';
                    }
                    html += (editing ? '<span class="ve-handle">栏</span>' : '') + '</div>';
                });
                html += '</div>' + (editing ? '<span class="ve-handle">区块</span>' : '') + '</section>';
            });
            return html + '</div>';
        }
        // ============ 样式编译（镜像 StyleCompiler.php 与 Value::style）============

        // 与服务端同一套形态：length 的单位可省（省略即 px），ratio 是 0-9 的小数，
        // 颜色除十六进制 / rgba 外还认站点 CSS 变量。
        var LENGTH_RE = /^-?\d{1,4}(?:\.\d{1,2})?(?:px|rem|em|%|vh|vw)?$/;
        var RATIO_RE = /^\d(?:\.\d{1,2})?$/;
        var COLOR_RES = [
            /^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/,
            /^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(?:,\s*(?:0|1|0?\.\d{1,3})\s*)?\)$/,
            /^var\(--[a-z0-9-]{1,60}\)$/i
        ];
        var SHADOWS = {
            none: 'none',
            sm: '0 1px 2px rgba(0,0,0,.08)',
            md: '0 4px 16px rgba(0,0,0,.10)',
            lg: '0 12px 40px rgba(0,0,0,.16)'
        };

        function enumOf(valueType) {
            return valueType.indexOf('enum:') === 0
                ? valueType.slice(5).split(',').map(function (one) { return one.trim(); }).filter(Boolean)
                : [];
        }

        function isColorValue(value) {
            for (var i = 0; i < COLOR_RES.length; i++) {
                if (COLOR_RES[i].test(value)) return true;
            }
            return false;
        }

        /**
         * 值校验。返回 null 表示这条声明整体丢弃——与服务端同一个态度：
         * 宁可少一条样式，也不放一个没校验过的值进 CSS。
         */
        function validateStyleValue(property, raw) {
            var definition = config.styleProperties[property];
            if (!definition) return null;
            var value = String(raw == null ? '' : raw).trim();
            if (value === '') return null;
            // CSS 里没有「安全的转义形态」，含结构字符的值一律丢弃。
            if (/[<>{};@\\]/.test(value) || value.indexOf('/*') >= 0) return null;
            var type = definition[1];
            if (type === 'length') return LENGTH_RE.test(value) ? value : null;
            if (type === 'ratio') return RATIO_RE.test(value) ? value : null;
            if (type === 'color') return isColorValue(value) ? value : null;
            if (type === 'image') return isMediaUrl(value) ? value : null;
            if (type === 'shadow') return SHADOWS[value.toLowerCase()] != null ? value.toLowerCase() : null;
            return enumOf(type).indexOf(value) >= 0 ? value : null;
        }

        function declarationLines(values) {
            var out = [];
            Object.keys(values || {}).forEach(function (property) {
                var definition = config.styleProperties[property];
                if (!definition) return;
                var value = validateStyleValue(property, values[property]);
                if (value === null) return;
                if (definition[1] === 'image') value = 'url("' + value.replace(/["\\]/g, '') + '")';
                if (definition[1] === 'shadow') value = SHADOWS[value] || 'none';
                out.push(definition[0] + ': ' + value + ';');
            });
            return out;
        }

        function collectBuckets(buckets, selector, style) {
            if (!style) return;
            BREAKPOINTS.forEach(function (bp) {
                var lines = declarationLines(style[bp]);
                if (lines.length) buckets[bp].push(selector + ' { ' + lines.join(' ') + ' }');
            });
        }

        function collectWidth(buckets, selector, width) {
            if (!width) return;
            BREAKPOINTS.forEach(function (bp) {
                var value = parseFloat(width[bp]);
                if (isNaN(value)) return;
                var percent = Math.max(5, Math.min(100, Math.round(value)));
                buckets[bp].push(selector + ' { flex: 0 0 ' + percent + '%; max-width: ' + percent + '%; }');
            });
        }

        function selectorFor(id) {
            return /^ve[0-9a-f]{10}$/.test(String(id || '')) ? '[data-ve="' + id + '"]' : ':not(*)';
        }

        /** 文档个性化声明。断点顺序与服务端一致：小屏用 max-width 覆盖大屏。 */
        function compileAllCss(root) {
            var buckets = { desktop: [], tablet: [], mobile: [] };
            collectBuckets(buckets, root, tree.style);
            buckets.desktop.push(root + ' { --ve-container: ' + parseInt(config.containerMax, 10) + 'px; }');
            tree.sections.forEach(function (section) {
                collectBuckets(buckets, root + ' ' + selectorFor(section.id), section.style);
                (section.columns || []).forEach(function (column) {
                    var columnSelector = root + ' ' + selectorFor(column.id);
                    collectBuckets(buckets, columnSelector, column.style);
                    collectWidth(buckets, columnSelector, column.width);
                    (column.widgets || []).forEach(function (widget) {
                        collectBuckets(buckets, root + ' ' + selectorFor(widget.id), widget.style);
                    });
                });
            });
            var css = buckets.desktop.join('\n');
            if (buckets.tablet.length) {
                css += '\n@media (max-width: ' + parseInt(config.breakpoints.tablet, 10) + 'px) {\n'
                    + buckets.tablet.join('\n') + '\n}';
            }
            if (buckets.mobile.length) {
                css += '\n@media (max-width: ' + parseInt(config.breakpoints.mobile, 10) + 'px) {\n'
                    + buckets.mobile.join('\n') + '\n}';
            }
            return css.trim();
        }

        function buildCss() {
            return (config.baseCss + '\n' + compileAllCss('.ve-doc-' + config.sourceKey)).trim();
        }

        // ================= 画布刷新与选中 =================

        function setStatus(text) {
            if (statusEl) statusEl.textContent = text || '';
        }

        function setDirty(flag) {
            dirty = flag;
            setStatus(flag ? '有未应用的修改' : '');
        }

        function refreshCanvas() {
            if (!tree) return;
            canvas.innerHTML = renderTree(true);
            if (canvasStyle) canvasStyle.textContent = buildCss();
            markSelected();
            bindCanvas();
        }

        function markSelected() {
            Array.prototype.forEach.call(canvas.querySelectorAll('[data-ve-selected]'), function (node) {
                node.removeAttribute('data-ve-selected');
            });
            var path = pathOf(selection);
            if (!path) return;
            var node = canvas.querySelector('[data-ve-path="' + path + '"]');
            if (node) node.setAttribute('data-ve-selected', '1');
        }

        function select(sel) {
            selection = sel;
            markSelected();
            renderInspector();
        }

        function selectionFromPath(path) {
            var parts = String(path).split('.');
            return {
                kind: parts.length === 1 ? 'section' : (parts.length === 2 ? 'column' : 'widget'),
                si: parseInt(parts[0], 10),
                ci: parts.length > 1 ? parseInt(parts[1], 10) : 0,
                wi: parts.length > 2 ? parseInt(parts[2], 10) : 0
            };
        }

        /** 点选取最内层：事件在命中的节点上停止冒泡，父级不会抢走选中。 */
        function bindCanvas() {
            Array.prototype.forEach.call(canvas.querySelectorAll('[data-ve-path]'), function (node) {
                node.addEventListener('click', function (event) {
                    event.stopPropagation();
                    select(selectionFromPath(node.getAttribute('data-ve-path')));
                });
            });
            // 画布里的链接在编辑期不该真的跳走。
            Array.prototype.forEach.call(canvas.querySelectorAll('a'), function (link) {
                link.addEventListener('click', function (event) { event.preventDefault(); });
            });
            bindDropTargets();
        }
        // ================= 检查器 =================

        var styleBreakpoint = 'desktop';

        function labelOf(key, fallback) {
            return config.fieldLabels[key] || config.styleLabels[key] || fallback || key;
        }

        function group(title) {
            var node = el('div', 've-group-title', title);
            inspectorBody.appendChild(node);
            var grid = el('div', 've-field-grid');
            inspectorBody.appendChild(grid);
            return grid;
        }

        function field(grid, labelText, control, wide, hint) {
            var wrap = el('div', 've-field' + (wide ? ' ve-field-wide' : ''));
            var label = el('label', null, labelText);
            wrap.appendChild(label);
            wrap.appendChild(control);
            if (hint) wrap.appendChild(el('div', 've-field-hint', hint));
            grid.appendChild(wrap);
            return wrap;
        }

        function renderInspector() {
            inspectorBody.innerHTML = '';
            var node = findNode(selection);
            if (!node) {
                inspectorTarget.textContent = '未选中';
                inspectorBody.appendChild(el('p', 've-inspector-empty',
                    '在画布里点选区块、栏或控件，这里出现它的设置。'));
                return;
            }
            if (selection.kind === 'widget') {
                inspectorTarget.textContent = (config.widgets[node.type] || {}).label || node.type;
                renderContentFields(node);
            } else if (selection.kind === 'column') {
                inspectorTarget.textContent = '栏 ' + (selection.ci + 1);
                renderColumnFields(node);
            } else {
                inspectorTarget.textContent = '区块 ' + (selection.si + 1);
                renderSectionFields(node);
            }
            renderStyleEditor(node);
        }

        function renderSectionFields(section) {
            var grid = group('区块');
            var select_ = el('select');
            [['boxed', '定宽容器'], ['full', '通栏']].forEach(function (pair) {
                var option = el('option', null, pair[1]);
                option.value = pair[0];
                if ((section.layout || 'boxed') === pair[0]) option.selected = true;
                select_.appendChild(option);
            });
            select_.addEventListener('change', function () {
                section.layout = select_.value === 'full' ? 'full' : 'boxed';
                setDirty(true);
                refreshCanvas();
            });
            field(grid, '宽度模式', select_, true);

            var addColumn = el('button', 've-btn', '＋ 添加一栏');
            addColumn.type = 'button';
            addColumn.addEventListener('click', function () {
                if (section.columns.length >= 6) return;
                section.columns.push(newColumn(100));
                rebalance(section);
                setDirty(true);
                refreshCanvas();
                renderInspector();
            });
            field(grid, '栏', addColumn, true, '共 ' + section.columns.length + ' 栏；宽度在每栏里按断点设置');
        }

        /** 平均分配桌面宽度：加栏 / 删栏后不留下一堆 100% 把版面挤爆。 */
        function rebalance(section) {
            var count = section.columns.length || 1;
            var percent = Math.max(5, Math.floor(100 / count));
            section.columns.forEach(function (column) { column.width.desktop = percent; });
        }

        function renderColumnFields(column) {
            var grid = group('栏宽（%）');
            BREAKPOINTS.forEach(function (bp) {
                var input = el('input');
                input.type = 'number';
                input.min = '5';
                input.max = '100';
                input.value = column.width[bp] == null ? '' : column.width[bp];
                input.addEventListener('input', function () {
                    var value = parseInt(input.value, 10);
                    column.width[bp] = isNaN(value) ? 100 : Math.max(5, Math.min(100, value));
                    setDirty(true);
                    refreshCanvas();
                });
                field(grid, BP_LABELS[bp], input);
            });
        }

        function renderContentFields(widget) {
            var definition = config.widgets[widget.type];
            if (!definition) return;
            var grid = group('内容');
            Object.keys(definition.fields).forEach(function (key) {
                buildContentField(grid, widget, key, definition.fields[key]);
            });
        }

        /**
         * 一个内容字段。字段规格由服务端下发（[类型, 选项…]），这里只负责
         * 挑一个合适的输入控件——校验仍在服务端 /save 时重做一遍。
         */
        function buildContentField(grid, widget, key, spec) {
            var parts = String(spec).split(':');
            var type = parts[0];
            var constraint = parts.length > 1 ? parts.slice(1).join(':') : '';
            var current = widget.content[key] == null ? '' : widget.content[key];
            var control;
            var wide = false;

            if (type === 'enum') {
                control = el('select');
                constraint.split(',').forEach(function (raw) {
                    var value = raw.trim();
                    if (!value) return;
                    var option = el('option', null, labelOf(value, value));
                    option.value = value;
                    if (String(current) === value) option.selected = true;
                    control.appendChild(option);
                });
            } else if (type === 'number') {
                var bounds = constraint.split(',');
                control = el('input');
                control.type = 'number';
                if (bounds[0]) control.min = bounds[0].trim();
                if (bounds[1]) control.max = bounds[1].trim();
                control.value = current;
            } else if (type === 'rich' || type === 'html_block' || type === 'lines') {
                control = el('textarea');
                control.rows = type === 'lines' ? 4 : 6;
                control.value = current;
                wide = true;
                if (type === 'lines') control.placeholder = '每行一项';
            } else {
                control = el('input');
                control.type = 'text';
                control.value = current;
                wide = (type === 'link' || type === 'media' || type === 'text');
                if (type === 'link') control.placeholder = 'https:// 或 /about';
                if (type === 'media') control.placeholder = '图片地址';
                if (type === 'color') control.placeholder = '#2563eb / rgba(…) / var(--ui-primary)';
                if (type === 'token') control.placeholder = '字母数字与 - _';
            }

            var commit = function () {
                widget.content[key] = control.value;
                setDirty(true);
                refreshCanvas();
            };
            control.addEventListener(control.tagName === 'SELECT' ? 'change' : 'input', commit);

            var hint = type === 'html_block' ? '会经服务端消毒后再入库：script / style / iframe 一律剥掉。' : '';
            var wrap = field(grid, labelOf(key), control, wide, hint);

            // 媒体字段：能挂上站点自带的选择器就挂，拿不到时手填地址依然可用。
            if (type === 'media') {
                var pick = el('button', 've-btn', '从媒体库选择');
                pick.type = 'button';
                pick.addEventListener('click', function () {
                    if (window.MediaPicker && typeof window.MediaPicker.open === 'function') {
                        window.MediaPicker.open({
                            onSelect: function (item) {
                                control.value = (item && (item.url || item.path)) || control.value;
                                commit();
                            }
                        });
                    } else {
                        control.focus();
                    }
                });
                wrap.appendChild(pick);
            }
        }

        function renderStyleEditor(node) {
            var head = el('div', 've-group-title', '样式');
            inspectorBody.appendChild(head);

            var tabs = el('div', 've-breakpoint-tabs');
            BREAKPOINTS.forEach(function (bp) {
                var button = el('button', null, BP_LABELS[bp]);
                button.type = 'button';
                if (styleBreakpoint === bp) button.setAttribute('data-ve-active', '1');
                button.addEventListener('click', function () {
                    styleBreakpoint = bp;
                    setBreakpoint(bp);
                    renderInspector();
                });
                tabs.appendChild(button);
            });
            inspectorBody.appendChild(tabs);
            inspectorBody.appendChild(el('div', 've-field-hint',
                styleBreakpoint === 'desktop'
                    ? '桌面是基准：只在这里填，平板与手机自动继承。'
                    : BP_LABELS[styleBreakpoint] + '只写覆盖值，留空表示沿用桌面。'));

            var grid = el('div', 've-field-grid');
            inspectorBody.appendChild(grid);
            if (!node.style) node.style = emptyStyle();
            if (!node.style[styleBreakpoint]) node.style[styleBreakpoint] = {};
            Object.keys(config.styleProperties).forEach(function (property) {
                buildStyleField(grid, node, property);
            });
        }

        function buildStyleField(grid, node, property) {
            var definition = config.styleProperties[property];
            var type = definition[1];
            var values = node.style[styleBreakpoint];
            var options = type === 'shadow' ? Object.keys(SHADOWS) : enumOf(type);
            var control;

            if (options.length) {
                control = el('select');
                var blank = el('option', null, '（不设置）');
                blank.value = '';
                control.appendChild(blank);
                options.forEach(function (value) {
                    var option = el('option', null, labelOf(value, value));
                    option.value = value;
                    if (String(values[property] || '') === value) option.selected = true;
                    control.appendChild(option);
                });
            } else {
                control = el('input');
                control.type = 'text';
                control.value = values[property] || '';
                if (type === 'color') control.placeholder = '#2563eb / rgba(…) / var(--ui-primary)';
                else if (type === 'ratio') control.placeholder = '如 1.6';
                else if (type === 'image') control.placeholder = '图片地址';
                else control.placeholder = '如 16px（不写单位按 px）';
            }

            control.addEventListener(control.tagName === 'SELECT' ? 'change' : 'input', function () {
                var raw = control.value.trim();
                if (raw === '') {
                    delete values[property];
                } else {
                    values[property] = raw;
                }
                setDirty(true);
                refreshCanvas();
            });

            field(grid, labelOf(property), control, type === 'image');
        }

        // ================= 结构操作 =================

        /** 复制出来的节点必须换 id：id 进 CSS 选择器，撞 id 会让两处共享样式。 */
        function reidentify(node) {
            var clone = JSON.parse(JSON.stringify(node));
            (function walk(one) {
                if (!one || typeof one !== 'object') return;
                if (one.id) one.id = newId();
                (one.columns || []).forEach(walk);
                (one.widgets || []).forEach(walk);
            })(clone);
            return clone;
        }

        function applyStructureAction(action) {
            var sel = selection;
            if (!sel || !tree) return;
            var section = tree.sections[sel.si];
            if (!section) return;
            var list, index, next;

            if (sel.kind === 'section') {
                list = tree.sections;
                index = sel.si;
            } else if (sel.kind === 'column') {
                list = section.columns;
                index = sel.ci;
            } else {
                var column = section.columns[sel.ci];
                if (!column) return;
                list = column.widgets;
                index = sel.wi;
            }
            if (!list || !list[index]) return;

            if (action === 'move-up' || action === 'move-down') {
                var target = index + (action === 'move-up' ? -1 : 1);
                if (target < 0 || target >= list.length) return;
                var moved = list.splice(index, 1)[0];
                list.splice(target, 0, moved);
                next = { kind: sel.kind, si: sel.si, ci: sel.ci, wi: sel.wi };
                if (sel.kind === 'section') next.si = target;
                else if (sel.kind === 'column') next.ci = target;
                else next.wi = target;
            } else if (action === 'duplicate') {
                if (sel.kind === 'section' && tree.sections.length >= 60) return;
                list.splice(index + 1, 0, reidentify(list[index]));
                if (sel.kind === 'column') rebalance(section);
                next = { kind: sel.kind, si: sel.si, ci: sel.ci, wi: sel.wi };
                if (sel.kind === 'section') next.si = index + 1;
                else if (sel.kind === 'column') next.ci = index + 1;
                else next.wi = index + 1;
            } else if (action === 'remove') {
                // 至少留一个区块 / 一栏：空树没有可点选的落点，用户会卡住。
                if (sel.kind === 'section' && tree.sections.length <= 1) {
                    tree.sections = [newSection([100])];
                    next = null;
                } else if (sel.kind === 'column' && section.columns.length <= 1) {
                    section.columns = [newColumn(100)];
                    next = null;
                } else {
                    list.splice(index, 1);
                    if (sel.kind === 'column') rebalance(section);
                    next = null;
                }
            } else {
                return;
            }

            setDirty(true);
            refreshCanvas();
            select(next);
        }

        /** 新控件落在当前选中的栏里；没选中就落在最后一个区块的第一栏。 */
        function targetColumn() {
            if (!tree.sections.length) tree.sections.push(newSection([100]));
            if (selection && selection.kind !== 'section') {
                var section = tree.sections[selection.si];
                var column = section && (section.columns || [])[selection.ci];
                if (column) return { column: column, si: selection.si, ci: selection.ci };
            }
            var lastIndex = tree.sections.length - 1;
            var last = tree.sections[lastIndex];
            if (!last.columns.length) last.columns.push(newColumn(100));
            return { column: last.columns[0], si: lastIndex, ci: 0 };
        }

        function countWidgets() {
            var total = 0;
            tree.sections.forEach(function (section) {
                (section.columns || []).forEach(function (column) {
                    total += (column.widgets || []).length;
                });
            });
            return total;
        }

        function addWidget(type, at) {
            if (countWidgets() >= 400) {
                setStatus('控件数量已达上限');
                return;
            }
            var slot = at || targetColumn();
            var widget = normalizeWidget(type, null);
            var index = at && typeof at.wi === 'number' ? at.wi : slot.column.widgets.length;
            slot.column.widgets.splice(index, 0, widget);
            setDirty(true);
            refreshCanvas();
            select({ kind: 'widget', si: slot.si, ci: slot.ci, wi: index });
        }

        function addSection(presetKey) {
            if (tree.sections.length >= 60) {
                setStatus('区块数量已达上限');
                return;
            }
            var preset = config.presets[presetKey];
            var at = selection ? selection.si + 1 : tree.sections.length;
            tree.sections.splice(at, 0, newSection((preset && preset.columns) || [100]));
            setDirty(true);
            refreshCanvas();
            select({ kind: 'section', si: at, ci: 0, wi: 0 });
        }

        // ---- 拖放：从左侧控件轨拖到某一栏 ----

        var dragType = null;

        function bindPalette() {
            Array.prototype.forEach.call(stage.querySelectorAll('[data-ve-add]'), function (chip) {
                var type = chip.getAttribute('data-ve-add');
                chip.addEventListener('click', function () { addWidget(type); });
                chip.addEventListener('dragstart', function (event) {
                    dragType = type;
                    if (event.dataTransfer) {
                        event.dataTransfer.effectAllowed = 'copy';
                        event.dataTransfer.setData('text/plain', type);
                    }
                });
                chip.addEventListener('dragend', function () { dragType = null; });
            });
            Array.prototype.forEach.call(stage.querySelectorAll('[data-ve-add-section]'), function (button) {
                button.addEventListener('click', function () {
                    addSection(button.getAttribute('data-ve-add-section'));
                });
            });
        }

        function bindDropTargets() {
            Array.prototype.forEach.call(canvas.querySelectorAll('[data-ve-kind="column"]'), function (node) {
                node.addEventListener('dragover', function (event) {
                    if (!dragType) return;
                    event.preventDefault();
                    event.stopPropagation();
                    if (event.dataTransfer) event.dataTransfer.dropEffect = 'copy';
                    node.setAttribute('data-ve-drop', '1');
                });
                node.addEventListener('dragleave', function () { node.removeAttribute('data-ve-drop'); });
                node.addEventListener('drop', function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    node.removeAttribute('data-ve-drop');
                    var type = dragType || (event.dataTransfer && event.dataTransfer.getData('text/plain'));
                    dragType = null;
                    if (!type || !config.widgets[type]) return;
                    var parts = node.getAttribute('data-ve-path').split('.');
                    var si = parseInt(parts[0], 10);
                    var ci = parseInt(parts[1], 10);
                    var column = tree.sections[si] && tree.sections[si].columns[ci];
                    if (!column) return;
                    addWidget(type, { column: column, si: si, ci: ci });
                });
            });
        }
        // ================= 断点切换（Notch 分段控件）=================

        var notch = stage.querySelector('[data-ve-notch]');
        var notchThumb = stage.querySelector('[data-ve-notch-thumb]');

        function moveThumb() {
            if (!notch || !notchThumb) return;
            var active = notch.querySelector('[data-ve-breakpoint][data-ve-active="1"]');
            if (!active) return;
            var track = notchThumb.parentNode;
            var left = active.offsetLeft - track.clientLeft;
            var width = active.offsetWidth;
            // 弹性补间正是 Notch 的手感来源；没有 GSAP 时直接落位，不留空滑块。
            if (gsap && !reduceMotion) {
                gsap.to(notchThumb, { left: left, width: width, duration: .45, ease: 'elastic.out(1, .72)' });
            } else {
                notchThumb.style.left = left + 'px';
                notchThumb.style.width = width + 'px';
            }
        }

        function setBreakpoint(bp) {
            if (BREAKPOINTS.indexOf(bp) < 0) return;
            breakpoint = bp;
            styleBreakpoint = bp;
            if (frame) frame.setAttribute('data-ve-bp', bp);
            if (notch) {
                Array.prototype.forEach.call(notch.querySelectorAll('[data-ve-breakpoint]'), function (button) {
                    if (button.getAttribute('data-ve-breakpoint') === bp) button.setAttribute('data-ve-active', '1');
                    else button.removeAttribute('data-ve-active');
                });
            }
            moveThumb();
        }

        function bindNotch() {
            if (!notch) return;
            Array.prototype.forEach.call(notch.querySelectorAll('[data-ve-breakpoint]'), function (button) {
                button.addEventListener('click', function () {
                    setBreakpoint(button.getAttribute('data-ve-breakpoint'));
                    renderInspector();
                });
            });
            window.addEventListener('resize', moveThumb);
        }

        // ================= 蒙版与编辑台的开合 =================

        var veilError = veil.querySelector('[data-ve-veil-error]');
        var veilLines = veil.querySelectorAll('[data-ve-veil-line]');

        function showVeil() {
            if (veilError) { veilError.hidden = true; veilError.textContent = ''; }
            veil.hidden = false;
            veil.setAttribute('aria-hidden', 'false');
            if (!gsap || reduceMotion) {
                veil.classList.add('ve-veil-static');
                return;
            }
            veil.classList.remove('ve-veil-static');
            gsap.set(veil, { opacity: 0 });
            gsap.set(veilLines, { clipPath: 'inset(0 100% 0 0)' });
            var timeline = gsap.timeline();
            timeline.to(veil, { opacity: 1, duration: .32, ease: 'power2.out' });
            // 打字机：逐行把 clip-path 从右往左收，长中文句子不会跳字。
            Array.prototype.forEach.call(veilLines, function (line) {
                timeline.to(line, { clipPath: 'inset(0 0% 0 0)', duration: .5, ease: 'power2.inOut' }, '>-0.05');
            });
        }

        function hideVeil(done) {
            var finish = function () {
                veil.hidden = true;
                veil.setAttribute('aria-hidden', 'true');
                if (done) done();
            };
            if (!gsap || reduceMotion) { finish(); return; }
            gsap.to(veil, { opacity: 0, duration: .28, ease: 'power2.in', onComplete: finish });
        }

        function veilFail(message) {
            if (!veilError) return;
            veilError.textContent = message;
            veilError.hidden = false;
        }

        function openStage() {
            stage.hidden = false;
            document.body.classList.add('ve-stage-open');
            setBreakpoint(breakpoint);
            if (gsap && !reduceMotion) {
                gsap.fromTo(stage, { opacity: 0, y: 14 }, { opacity: 1, y: 0, duration: .38, ease: 'power3.out' });
            }
            moveThumb();
        }

        function closeStage() {
            var finish = function () {
                stage.hidden = true;
                document.body.classList.remove('ve-stage-open');
            };
            if (gsap && !reduceMotion) {
                gsap.to(stage, { opacity: 0, y: 10, duration: .24, ease: 'power2.in', onComplete: finish });
            } else {
                finish();
            }
        }

        // ================= 与服务端通信 =================

        function post(url, payload) {
            var body = new FormData();
            body.append('csrf_token', config.csrfToken);
            body.append('source_type', config.sourceType);
            body.append('source_id', String(config.sourceId));
            Object.keys(payload || {}).forEach(function (key) { body.append(key, payload[key]); });
            return fetch(url, {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (response) {
                return response.json().catch(function () {
                    throw new Error('服务端返回了无法解析的内容（HTTP ' + response.status + '）');
                }).then(function (data) {
                    if (!response.ok || data.ok === false) {
                        throw new Error((data && data.message) || ('请求失败（HTTP ' + response.status + '）'));
                    }
                    // 端点把负载放在 data 里；这里摊平成一层，调用处不必层层取。
                    var flat = { message: data.message || '' };
                    Object.keys(data.data || {}).forEach(function (key) { flat[key] = data.data[key]; });
                    return flat;
                });
            });
        }

        function loadTree(force) {
            return post(config.convertUrl, force ? { reimport: '1' } : {}).then(function (data) {
                tree = data.tree;
                loaded = true;
                selection = null;
                setDirty(false);
                refreshCanvas();
                renderInspector();
                setStatus(data.message || '');
                return data;
            });
        }

        /** 内容字段输入框。核心可能渲染多个同名控件，取最后一个（真正提交的那个）。 */
        function contentInput() {
            if (!form) return null;
            var nodes = form.querySelectorAll('[name="' + config.fieldName + '"]');
            return nodes.length ? nodes[nodes.length - 1] : null;
        }

        function applyToField() {
            if (busy || !tree) return;
            busy = true;
            if (applyButton) applyButton.disabled = true;
            setStatus('正在应用…');
            post(config.saveUrl, { tree: JSON.stringify(tree) }).then(function (data) {
                var input = contentInput();
                if (!input) throw new Error('找不到内容字段，无法写回');
                input.value = data.block;
                if (window.jQuery) window.jQuery(input).trigger('change');
                else input.dispatchEvent(new Event('change', { bubbles: true }));
                // 服务端重新渲染过一遍，画布跟着换成入库产物，避免两边不一致。
                if (data.canvas_css && canvasStyle) canvasStyle.textContent = data.canvas_css;
                setDirty(false);
                setStatus('已应用到内容字段，记得保存这条内容');
            }).catch(function (error) {
                setStatus(error.message || '应用失败');
            }).then(function () {
                busy = false;
                if (applyButton) applyButton.disabled = false;
            });
        }

        function restoreOriginal() {
            if (busy) return;
            if (!window.confirm('用首次接管前的原始内容替换内容字段？画布里的编辑不会丢，但需要重新应用。')) return;
            busy = true;
            setStatus('正在取回原文…');
            post(config.restoreUrl, {}).then(function (data) {
                var input = contentInput();
                if (!input) throw new Error('找不到内容字段，无法写回');
                input.value = data.content;
                setStatus('已写回原文，记得保存这条内容');
            }).catch(function (error) {
                setStatus(error.message || '取回失败');
            }).then(function () { busy = false; });
        }

        // ================= 入口按钮与事件绑定 =================

        /**
         * 核心渲染的「可视化」radio/label 留在表单里（保持 name="content_mode" 语义）
         * 但视觉上藏起来，另插一颗独立按钮排在「AI 编辑」之后——核心的代码 /
         * 富文本两个标签一个像素都不动。
         */
        function installLauncher() {
            var radio = document.querySelector('[data-content-mode-key="' + config.modeKey + '"]');
            var label = radio ? (radio.closest('label') || document.querySelector('label[for="' + radio.id + '"]')) : null;
            var anchor = label || radio;
            if (!anchor || !anchor.parentNode) return null;

            if (radio) radio.classList.add('ve-mode-hidden');
            if (label) label.classList.add('ve-mode-hidden');

            var button = el('button', 've-launch');
            button.type = 'button';
            button.innerHTML = '<i class="bi bi-magic"></i><span>可视化</span>'
                + (config.firstRun ? '<span class="ve-launch-badge">首次较慢</span>' : '');
            button.addEventListener('click', function () { launch(); });

            var group = anchor.closest('.btn-group, .content-mode-switch') || anchor.parentNode;
            if (group && group.parentNode) group.parentNode.insertBefore(button, group.nextSibling);
            else anchor.parentNode.insertBefore(button, anchor.nextSibling);
            return button;
        }

        var launcher = null;

        function launch() {
            if (busy) return;
            busy = true;
            if (launcher) launcher.setAttribute('data-ve-active', '1');
            // 切到本插件的模式：核心的面板显隐由它自己接管。
            try { window.ContentEditorModes.select(config.modeKey); } catch (e) {}
            showVeil();

            var ready = loaded ? Promise.resolve(null) : loadTree(false);
            ready.then(function () {
                // 首次转换可能很快，但蒙版太短会像闪一下；给动画一个最小停留。
                var wait = (gsap && !reduceMotion) ? 900 : 0;
                return new Promise(function (resolve) { setTimeout(resolve, wait); });
            }).then(function () {
                hideVeil(openStage);
            }).catch(function (error) {
                veilFail(error.message || '加载失败，请稍后重试');
                setTimeout(function () { hideVeil(); }, 2600);
            }).then(function () {
                busy = false;
            });
        }

        function bindStageActions() {
            Array.prototype.forEach.call(stage.querySelectorAll('[data-ve-action]'), function (button) {
                var action = button.getAttribute('data-ve-action');
                button.addEventListener('click', function () {
                    if (action === 'close') {
                        if (dirty && !window.confirm('有未应用的修改，直接关闭会丢掉它们。确定关闭？')) return;
                        if (launcher) launcher.removeAttribute('data-ve-active');
                        closeStage();
                    } else if (action === 'apply') {
                        applyToField();
                    } else if (action === 'restore') {
                        restoreOriginal();
                    } else if (action === 'reimport') {
                        if (dirty && !window.confirm('重新导入会用内容字段的当前内容覆盖画布。继续？')) return;
                        setStatus('正在重新解析…');
                        loadTree(true).catch(function (error) { setStatus(error.message || '重新导入失败'); });
                    } else {
                        applyStructureAction(action);
                    }
                });
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && !stage.hidden && !dirty) {
                    if (launcher) launcher.removeAttribute('data-ve-active');
                    closeStage();
                }
            });

            // 点空白处取消选中：比强迫用户去找「取消」按钮自然。
            var viewport = stage.querySelector('[data-ve-viewport]');
            if (viewport) {
                viewport.addEventListener('click', function (event) {
                    if (event.target === viewport || event.target === frame) select(null);
                });
            }
        }

        /** 当前模式是否还是「可视化」。切回富文本 / 代码后我们一个字节都不该写。 */
        function isActiveMode() {
            try {
                return window.ContentEditorModes.current() === config.modeKey;
            } catch (e) {
                return false;
            }
        }

        /**
         * 表单提交前的兜底：画布里改了但没点「应用到内容」时，先同步一次。
         * 这里不阻塞提交——同步是异步的，所以拦下这次提交，成功后再交回表单。
         */
        function bindFormSync() {
            if (!form) return;
            form.addEventListener('submit', function (event) {
                // 用户可能已经切回富文本 / 代码模式：那时字段归那个模式所有。
                if (!dirty || !tree || busy || !isActiveMode()) return;
                event.preventDefault();
                busy = true;
                post(config.saveUrl, { tree: JSON.stringify(tree) }).then(function (data) {
                    var input = contentInput();
                    if (input) input.value = data.block;
                    setDirty(false);
                }).catch(function () {
                    // 同步失败也让用户能保存：字段里仍是上次应用的产物。
                }).then(function () {
                    busy = false;
                    form.submit();
                });
            });
        }

        launcher = installLauncher();
        bindPalette();
        bindNotch();
        bindStageActions();
        bindFormSync();
        setBreakpoint('desktop');
        renderInspector();

        // 面板里的那颗大按钮与模式条上的入口是同一个动作。
        Array.prototype.forEach.call(panel.querySelectorAll('[data-ve-open]'), function (button) {
            button.addEventListener('click', function () { launch(); });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
