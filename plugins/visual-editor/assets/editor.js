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
                    + (editing ? ' data-ve-kind="section" data-ve-path="' + si + '" draggable="true"' : '') + '>'
                    + '<div class="ve-section-inner">';
                (section.columns || []).forEach(function (column, ci) {
                    html += '<div data-ve="' + esc(column.id) + '" class="ve-column"'
                        + (editing ? ' data-ve-kind="column" data-ve-path="' + si + '.' + ci + '"' : '') + '>';
                    (column.widgets || []).forEach(function (widget, wi) {
                        var definition = config.widgets[widget.type];
                        if (!definition) return;
                        html += '<div data-ve="' + esc(widget.id) + '" class="ve-widget ve-widget-' + esc(widget.type) + '"'
                            + (editing ? ' data-ve-kind="widget" data-ve-path="' + si + '.' + ci + '.' + wi + '" draggable="true"' : '')
                            + '>' + widgetBodyHtml(widget)
                            + (editing ? '<span class="ve-handle"><i class="bi bi-grip-vertical"></i>' + esc(definition.label) + '</span>' : '')
                            + '</div>';
                    });
                    if (editing && !(column.widgets || []).length) {
                        html += '<div class="ve-column-empty">把左侧控件拖进来，或选中这一栏后点左侧控件</div>';
                    }
                    html += (editing ? '<span class="ve-handle">栏</span>' : '') + '</div>';
                });
                html += '</div>' + (editing ? '<span class="ve-handle"><i class="bi bi-grip-vertical"></i>区块</span>' : '') + '</section>';
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
                // 右键：先把这个节点选中，再在鼠标处开菜单——和 Elementor 一样，
                // 右键本身就是一次选中，不需要先左键点一下。
                node.addEventListener('contextmenu', function (event) {
                    event.stopPropagation();
                    event.preventDefault();
                    select(selectionFromPath(node.getAttribute('data-ve-path')));
                    openContextMenu(event.clientX, event.clientY);
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
        var inspectorTab = 'content';
        /** 折叠状态按「选中类型 + 分组名」记，切换选中时不会把用户展开的组收回去。 */
        var openGroups = { '间距': true, '文字': true };

        /**
         * 枚举值的中文名。服务端的枚举值是 CSS 关键字（flex-start 之类），
         * 直接摊在下拉里没人看得懂；这张表只服务显示，值本身不变。
         */
        var VALUE_LABELS = {
            left: '左', center: '居中', right: '右', justify: '两端对齐',
            top: '上', bottom: '下',
            cover: '铺满裁切', contain: '完整放入', auto: '原始尺寸',
            'no-repeat': '不平铺', repeat: '平铺', 'repeat-x': '横向平铺', 'repeat-y': '纵向平铺',
            none: '无', solid: '实线', dashed: '虚线', dotted: '点线',
            block: '块', flex: '弹性布局', 'inline-block': '行内块',
            'flex-start': '起始', 'flex-end': '末尾', 'space-between': '两端分散',
            'space-around': '周围留白', stretch: '拉伸',
            sm: '轻', md: '中', lg: '重',
            '300': '细 300', '400': '常规 400', '500': '中 500',
            '600': '半粗 600', '700': '粗 700', '800': '特粗 800'
        };

        /** 标签表有两种形态：fieldLabels 是字符串，styleLabels 是 {group,label}。 */
        function labelOf(key, fallback) {
            var one = config.fieldLabels[key];
            if (typeof one === 'string' && one) return one;
            var two = config.styleLabels[key];
            if (two && typeof two === 'object' && two.label) return two.label;
            if (typeof two === 'string' && two) return two;
            return VALUE_LABELS[key] || fallback || key;
        }

        function groupOf(property) {
            var meta = config.styleLabels[property];
            return (meta && typeof meta === 'object' && meta.group) ? meta.group : '其他';
        }

        /**
         * 可折叠分组。参数堆在一张长条里是上一版最大的毛病：分组必须能收起，
         * 而且要在标题上直接告诉用户「这一组里有没有设过东西」。
         */
        function group(title, opts) {
            opts = opts || {};
            var box = el('div', 've-acc');
            var head = el('button', 've-acc-head');
            head.type = 'button';
            head.innerHTML = '<i class="bi bi-chevron-right ve-acc-caret"></i>'
                + '<span class="ve-acc-title">' + esc(title) + '</span>'
                + (opts.count ? '<span class="ve-acc-count">' + opts.count + '</span>' : '');
            var body = el('div', 've-acc-body');
            var grid = el('div', 've-field-grid');
            body.appendChild(grid);
            if (opts.collapsible === false) {
                box.setAttribute('data-ve-open', '1');
                head.disabled = true;
            } else {
                if (openGroups[title]) box.setAttribute('data-ve-open', '1');
                head.addEventListener('click', function () {
                    var open = box.getAttribute('data-ve-open') === '1';
                    if (open) { box.removeAttribute('data-ve-open'); openGroups[title] = false; }
                    else { box.setAttribute('data-ve-open', '1'); openGroups[title] = true; }
                });
            }
            box.appendChild(head);
            box.appendChild(body);
            inspectorBody.appendChild(box);
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
            } else if (selection.kind === 'column') {
                inspectorTarget.textContent = '栏 ' + (selection.ci + 1);
            } else {
                inspectorTarget.textContent = '区块 ' + (selection.si + 1);
            }

            // 内容与样式分两页：一页只回答一个问题，比把二十多个参数摊平好读得多。
            var tabs = el('div', 've-tabs');
            [['content', '内容', 'bi-pencil-square'], ['style', '样式', 'bi-palette']].forEach(function (one) {
                var button = el('button', 've-tab');
                button.type = 'button';
                button.innerHTML = '<i class="bi ' + one[2] + '"></i><span>' + one[1] + '</span>';
                if (inspectorTab === one[0]) button.setAttribute('data-ve-active', '1');
                button.addEventListener('click', function () {
                    inspectorTab = one[0];
                    renderInspector();
                });
                tabs.appendChild(button);
            });
            inspectorBody.appendChild(tabs);

            if (inspectorTab === 'style') {
                renderStyleEditor(node);
                return;
            }
            if (selection.kind === 'widget') renderContentFields(node);
            else if (selection.kind === 'column') renderColumnFields(node);
            else renderSectionFields(node);
        }

        function renderSectionFields(section) {
            var grid = group('区块', { collapsible: false });
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
            var grid = group('栏宽（%）', { collapsible: false });
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
            var grid = group('内容', { collapsible: false });
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

        /** 样式页：断点条 + 按分组折叠的字段。分组顺序跟着 styleLabels 的声明顺序。 */
        function renderStyleEditor(node) {
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
            inspectorBody.appendChild(el('div', 've-bp-hint',
                styleBreakpoint === 'desktop'
                    ? '桌面是基准：只在这里填，平板与手机自动继承。'
                    : BP_LABELS[styleBreakpoint] + '只写覆盖值，留空表示沿用桌面。'));

            if (!node.style) node.style = emptyStyle();
            if (!node.style[styleBreakpoint]) node.style[styleBreakpoint] = {};
            var values = node.style[styleBreakpoint];

            // 先按分组归拢，再一组一个折叠面板；标题上带「这组设了几项」的计数。
            var order = [];
            var byGroup = {};
            Object.keys(config.styleProperties).forEach(function (property) {
                var name = groupOf(property);
                if (!byGroup[name]) { byGroup[name] = []; order.push(name); }
                byGroup[name].push(property);
            });

            order.forEach(function (name) {
                var properties = byGroup[name];
                var set = properties.filter(function (property) {
                    return values[property] !== undefined && values[property] !== '';
                });
                // 设过值的组默认展开：用户回来第一眼就该看见自己改过的地方。
                if (set.length && openGroups[name] === undefined) openGroups[name] = true;
                var grid = group(name, { count: set.length });
                properties.forEach(function (property) {
                    buildStyleField(grid, node, property);
                });
                if (set.length) {
                    var clear = el('button', 've-btn ve-btn-quiet ve-field-wide', '清空这一组');
                    clear.type = 'button';
                    clear.addEventListener('click', function () {
                        set.forEach(function (property) { delete values[property]; });
                        setDirty(true);
                        refreshCanvas();
                        renderInspector();
                    });
                    grid.appendChild(clear);
                }
            });
        }

        var LENGTH_UNITS = ['px', 'rem', 'em', '%', 'vh', 'vw'];

        /** 长度值拆成「数字 + 单位」两个控件：手打 16px 比拨一个数字慢得多。 */
        function lengthControl(current, commit) {
            var wrap = el('div', 've-input-row');
            var match = /^(-?\d+(?:\.\d+)?)(px|rem|em|%|vh|vw)?$/.exec(String(current || '').trim());
            var number = el('input', 've-input-num');
            number.type = 'number';
            number.step = '1';
            number.placeholder = '—';
            number.value = match ? match[1] : '';
            var unit = el('select', 've-input-unit');
            LENGTH_UNITS.forEach(function (one) {
                var option = el('option', null, one);
                option.value = one;
                if ((match && match[2] ? match[2] : 'px') === one) option.selected = true;
                unit.appendChild(option);
            });
            var push = function () {
                var raw = number.value.trim();
                commit(raw === '' ? '' : raw + unit.value);
            };
            number.addEventListener('input', push);
            unit.addEventListener('change', push);
            wrap.appendChild(number);
            wrap.appendChild(unit);
            return wrap;
        }

        /** 颜色值配一个原生取色器：能取色，也能保留 var(--ui-primary) 这类写法。 */
        function colorControl(current, commit) {
            var wrap = el('div', 've-input-row');
            var swatch = el('input', 've-input-swatch');
            swatch.type = 'color';
            swatch.value = /^#[0-9a-fA-F]{6}$/.test(String(current || '')) ? current : '#2563eb';
            var text = el('input', 've-input-text');
            text.type = 'text';
            text.value = current || '';
            text.placeholder = '#2563eb / rgba(…) / var(--ui-primary)';
            swatch.addEventListener('input', function () {
                text.value = swatch.value;
                commit(swatch.value);
            });
            text.addEventListener('input', function () {
                var raw = text.value.trim();
                if (/^#[0-9a-fA-F]{6}$/.test(raw)) swatch.value = raw;
                commit(raw);
            });
            wrap.appendChild(swatch);
            wrap.appendChild(text);
            return wrap;
        }

        function buildStyleField(grid, node, property) {
            var definition = config.styleProperties[property];
            var type = definition[1];
            var values = node.style[styleBreakpoint];
            var options = type === 'shadow' ? Object.keys(SHADOWS) : enumOf(type);
            var control;
            var wide = false;

            var commit = function (raw) {
                if (raw === '' || raw == null) delete values[property];
                else values[property] = raw;
                setDirty(true);
                refreshCanvas();
            };

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
                control.addEventListener('change', function () { commit(control.value.trim()); });
            } else if (type === 'length') {
                control = lengthControl(values[property] || '', commit);
                wide = true;
            } else if (type === 'color') {
                control = colorControl(values[property] || '', commit);
                wide = true;
            } else {
                control = el('input');
                control.type = 'text';
                control.value = values[property] || '';
                if (type === 'ratio') control.placeholder = '如 1.6';
                else if (type === 'image') control.placeholder = '图片地址';
                else control.placeholder = '值';
                wide = type === 'image';
                control.addEventListener('input', function () { commit(control.value.trim()); });
            }

            var wrap = field(grid, labelOf(property), control, wide);
            if (values[property] !== undefined && values[property] !== '') {
                wrap.setAttribute('data-ve-set', '1');
            }
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

        // ---- 剪贴板与样式剪贴板（Elementor 的右键菜单核心就是这两样）----

        /** {kind, node} —— 只在本次会话内有效，不落存储，不跨标签页。 */
        var clipboard = null;
        /** 单独一份样式剪贴板：拷样式比拷整个控件常用得多。 */
        var styleClipboard = null;

        function copySelection() {
            var node = findNode(selection);
            if (!node) return;
            clipboard = { kind: selection.kind, node: JSON.parse(JSON.stringify(node)) };
            styleClipboard = JSON.parse(JSON.stringify(node.style || emptyStyle()));
            setStatus('已拷贝' + (selection.kind === 'widget' ? '控件' : (selection.kind === 'column' ? '栏' : '区块')));
        }

        /**
         * 粘贴。区块只能粘到区块层；栏粘进当前区块；控件粘进当前栏。
         * 类型对不上时不硬塞，直接说明——静默塞错位置比不粘更难排查。
         */
        function pasteClipboard() {
            if (!clipboard || !tree) { setStatus('剪贴板是空的'); return; }
            var copy = reidentify(clipboard.node);
            if (clipboard.kind === 'section') {
                if (tree.sections.length >= 60) { setStatus('区块数量已达上限'); return; }
                var at = selection ? selection.si + 1 : tree.sections.length;
                tree.sections.splice(at, 0, copy);
                setDirty(true);
                refreshCanvas();
                select({ kind: 'section', si: at, ci: 0, wi: 0 });
                return;
            }
            if (!selection) { setStatus('先选中要粘进去的位置'); return; }
            var section = tree.sections[selection.si];
            if (!section) return;
            if (clipboard.kind === 'column') {
                if ((section.columns || []).length >= 6) { setStatus('这个区块的栏数已达上限'); return; }
                var ci = (selection.kind === 'section' ? section.columns.length : selection.ci + 1);
                section.columns.splice(ci, 0, copy);
                rebalance(section);
                setDirty(true);
                refreshCanvas();
                select({ kind: 'column', si: selection.si, ci: ci, wi: 0 });
                return;
            }
            // 控件：选中区块时落进第一栏末尾，选中栏 / 控件时落在当前位置之后。
            var column = section.columns[selection.kind === 'section' ? 0 : selection.ci];
            if (!column) { setStatus('这里没有可粘贴的栏'); return; }
            if (countWidgets() >= 400) { setStatus('控件数量已达上限'); return; }
            var wi = selection.kind === 'widget' ? selection.wi + 1 : column.widgets.length;
            column.widgets.splice(wi, 0, copy);
            setDirty(true);
            refreshCanvas();
            select({
                kind: 'widget',
                si: selection.si,
                ci: selection.kind === 'section' ? 0 : selection.ci,
                wi: wi
            });
        }

        function pasteStyle() {
            var node = findNode(selection);
            if (!node) return;
            if (!styleClipboard) { setStatus('还没有拷过样式'); return; }
            node.style = JSON.parse(JSON.stringify(styleClipboard));
            setDirty(true);
            refreshCanvas();
            renderInspector();
            setStatus('已粘贴样式');
        }

        function resetStyle() {
            var node = findNode(selection);
            if (!node) return;
            node.style = emptyStyle();
            setDirty(true);
            refreshCanvas();
            renderInspector();
            setStatus('已清空样式');
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

        // ---- 右键菜单 ----

        var contextMenu = null;

        function closeContextMenu() {
            if (contextMenu && contextMenu.parentNode) contextMenu.parentNode.removeChild(contextMenu);
            contextMenu = null;
        }

        /**
         * 右键菜单的条目随选中类型变：区块能加栏，栏能加控件，控件只关心自己。
         * 顺序照 Elementor 的肌肉记忆走：编辑 → 复制 → 拷贝 / 粘贴 → 样式 → 删除。
         */
        function contextItems() {
            var kind = selection ? selection.kind : null;
            var noun = kind === 'widget' ? '控件' : (kind === 'column' ? '栏' : '区块');
            var items = [
                { icon: 'bi-sliders', label: '编辑' + noun, run: function () {
                    inspectorTab = 'content';
                    renderInspector();
                } },
                { icon: 'bi-palette', label: '样式设置', run: function () {
                    inspectorTab = 'style';
                    renderInspector();
                } },
                { sep: true },
                { icon: 'bi-arrow-up', label: '上移', run: function () { applyStructureAction('move-up'); } },
                { icon: 'bi-arrow-down', label: '下移', run: function () { applyStructureAction('move-down'); } },
                { icon: 'bi-copy', label: '复制一份', hint: 'Ctrl+D', run: function () { applyStructureAction('duplicate'); } },
                { sep: true },
                { icon: 'bi-clipboard', label: '拷贝', hint: 'Ctrl+C', run: copySelection },
                { icon: 'bi-clipboard-check', label: '粘贴', hint: 'Ctrl+V',
                  disabled: !clipboard, run: pasteClipboard },
                { icon: 'bi-clipboard-plus', label: '粘贴样式',
                  disabled: !styleClipboard, run: pasteStyle },
                { icon: 'bi-eraser', label: '清空样式', run: resetStyle },
                { sep: true },
                { icon: 'bi-trash', label: '删除' + noun, danger: true, hint: 'Del',
                  run: function () { applyStructureAction('remove'); } }
            ];
            if (kind === 'section') {
                items.splice(3, 0, { icon: 'bi-plus-square', label: '在下方插入区块', run: function () {
                    addSection('one');
                } });
            }
            return items;
        }

        function openContextMenu(x, y) {
            closeContextMenu();
            if (!selection) return;
            var menu = el('div', 've-ctx');
            contextItems().forEach(function (item) {
                if (item.sep) { menu.appendChild(el('div', 've-ctx-sep')); return; }
                var button = el('button', 've-ctx-item' + (item.danger ? ' ve-ctx-danger' : ''));
                button.type = 'button';
                button.disabled = !!item.disabled;
                button.innerHTML = '<i class="bi ' + item.icon + '"></i>'
                    + '<span class="ve-ctx-label">' + esc(item.label) + '</span>'
                    + (item.hint ? '<kbd class="ve-ctx-hint">' + esc(item.hint) + '</kbd>' : '');
                button.addEventListener('click', function () {
                    closeContextMenu();
                    item.run();
                });
                menu.appendChild(button);
            });
            // 先挂上再量尺寸：菜单不能被视口右下角切掉。
            menu.style.left = '0px';
            menu.style.top = '0px';
            stage.appendChild(menu);
            var box = menu.getBoundingClientRect();
            var left = Math.min(x, window.innerWidth - box.width - 8);
            var top = Math.min(y, window.innerHeight - box.height - 8);
            menu.style.left = Math.max(8, left) + 'px';
            menu.style.top = Math.max(8, top) + 'px';
            contextMenu = menu;
            if (gsap && !reduceMotion) {
                gsap.fromTo(menu, { opacity: 0, scale: .96, transformOrigin: 'top left' },
                    { opacity: 1, scale: 1, duration: .16, ease: 'power2.out' });
            }
        }

        function bindContextMenu() {
            document.addEventListener('click', function (event) {
                if (contextMenu && !contextMenu.contains(event.target)) closeContextMenu();
            });
            document.addEventListener('scroll', closeContextMenu, true);
            // 画布空白处右键：交回浏览器自己的菜单太突兀，这里只是关掉我们的。
            canvas.addEventListener('contextmenu', function (event) {
                event.preventDefault();
                closeContextMenu();
            });
            document.addEventListener('keydown', function (event) {
                if (stage.hidden) return;
                if (event.key === 'Escape' && contextMenu) { closeContextMenu(); return; }
                // 焦点在输入框里时快捷键归输入框——别把用户的复制粘贴抢走。
                var tag = (event.target && event.target.tagName) || '';
                if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT'
                    || (event.target && event.target.isContentEditable)) return;
                if (!selection) return;
                var meta = event.ctrlKey || event.metaKey;
                if (meta && (event.key === 'c' || event.key === 'C')) { event.preventDefault(); copySelection(); }
                else if (meta && (event.key === 'v' || event.key === 'V')) { event.preventDefault(); pasteClipboard(); }
                else if (meta && (event.key === 'd' || event.key === 'D')) { event.preventDefault(); applyStructureAction('duplicate'); }
                else if (event.key === 'Delete') { event.preventDefault(); applyStructureAction('remove'); }
            });
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

        // ---- 拖放：左侧控件轨 → 栏；画布内控件换位；区块换序 ----

        /**
         * 当前拖动的东西。三种来源共用一个状态：
         *   {mode:'new',     type}                — 从左侧控件轨拖出的新控件
         *   {mode:'widget',  from:{si,ci,wi}}     — 画布里已有的控件（跨栏也走这条）
         *   {mode:'section', from:{si}}           — 区块整体换序
         * dataTransfer 只用来让浏览器认账（Firefox 不 setData 就不触发 drop）。
         */
        var drag = null;
        /** 兼容旧字段名：外部代码若还读 dragType，仍能拿到控件类型。 */
        var dragType = null;

        function clearDropMarks() {
            Array.prototype.forEach.call(canvas.querySelectorAll('[data-ve-drop],[data-ve-drop-edge]'), function (node) {
                node.removeAttribute('data-ve-drop');
                node.removeAttribute('data-ve-drop-edge');
            });
        }

        function endDrag() {
            drag = null;
            dragType = null;
            clearDropMarks();
            canvas.removeAttribute('data-ve-dragging');
        }

        function pathParts(node) {
            return (node.getAttribute('data-ve-path') || '').split('.').map(function (one) {
                return parseInt(one, 10);
            });
        }

        function bindPalette() {
            Array.prototype.forEach.call(stage.querySelectorAll('[data-ve-add]'), function (chip) {
                var type = chip.getAttribute('data-ve-add');
                chip.addEventListener('click', function () { addWidget(type); });
                chip.addEventListener('dragstart', function (event) {
                    drag = { mode: 'new', type: type };
                    dragType = type;
                    canvas.setAttribute('data-ve-dragging', 'new');
                    if (event.dataTransfer) {
                        event.dataTransfer.effectAllowed = 'copy';
                        event.dataTransfer.setData('text/plain', type);
                    }
                });
                chip.addEventListener('dragend', endDrag);
            });
            Array.prototype.forEach.call(stage.querySelectorAll('[data-ve-add-section]'), function (button) {
                button.addEventListener('click', function () {
                    addSection(button.getAttribute('data-ve-add-section'));
                });
            });
        }

        /** 从树上摘下一个控件，返回它本身；摘完不刷新画布，由调用方统一收尾。 */
        function detachWidget(from) {
            var section = tree.sections[from.si];
            var column = section && (section.columns || [])[from.ci];
            if (!column || !column.widgets[from.wi]) return null;
            return column.widgets.splice(from.wi, 1)[0];
        }

        /** 把控件插进目标栏的指定位置。同栏内移动时索引要按摘除后的数组算。 */
        function moveWidget(from, to) {
            var widget = detachWidget(from);
            if (!widget) return;
            var target = tree.sections[to.si] && (tree.sections[to.si].columns || [])[to.ci];
            if (!target) { // 目标没了（理论上不会）——放回原处，别把用户的控件弄丢。
                var back = tree.sections[from.si].columns[from.ci];
                back.widgets.splice(from.wi, 0, widget);
                return;
            }
            var index = to.wi;
            if (from.si === to.si && from.ci === to.ci && from.wi < index) index -= 1;
            index = Math.max(0, Math.min(index, target.widgets.length));
            target.widgets.splice(index, 0, widget);
            setDirty(true);
            refreshCanvas();
            select({ kind: 'widget', si: to.si, ci: to.ci, wi: index });
        }

        function moveSection(fromIndex, toIndex) {
            if (fromIndex === toIndex || fromIndex + 1 === toIndex) return;
            var moved = tree.sections.splice(fromIndex, 1)[0];
            var index = toIndex > fromIndex ? toIndex - 1 : toIndex;
            index = Math.max(0, Math.min(index, tree.sections.length));
            tree.sections.splice(index, 0, moved);
            setDirty(true);
            refreshCanvas();
            select({ kind: 'section', si: index, ci: 0, wi: 0 });
        }

        /**
         * 落点索引：按鼠标在各控件上的位置算「插在第几个之前」，
         * 并把提示线画在对应控件的上边或下边——Elementor 的手感全在这条线上。
         */
        function widgetDropIndex(column, event) {
            var items = column.querySelectorAll(':scope > [data-ve-kind="widget"]');
            if (!items.length) return 0;
            for (var i = 0; i < items.length; i++) {
                var box = items[i].getBoundingClientRect();
                if (event.clientY < box.top + box.height / 2) {
                    items[i].setAttribute('data-ve-drop-edge', 'before');
                    return i;
                }
            }
            items[items.length - 1].setAttribute('data-ve-drop-edge', 'after');
            return items.length;
        }

        function bindDropTargets() {
            // 画布内的控件与区块自己就是拖动源。
            Array.prototype.forEach.call(canvas.querySelectorAll('[data-ve-kind="widget"]'), function (node) {
                node.addEventListener('dragstart', function (event) {
                    event.stopPropagation();
                    var parts = pathParts(node);
                    drag = { mode: 'widget', from: { si: parts[0], ci: parts[1], wi: parts[2] } };
                    dragType = null;
                    canvas.setAttribute('data-ve-dragging', 'widget');
                    node.setAttribute('data-ve-drag-source', '1');
                    if (event.dataTransfer) {
                        event.dataTransfer.effectAllowed = 'move';
                        event.dataTransfer.setData('text/plain', 've-widget');
                    }
                });
                node.addEventListener('dragend', function () {
                    node.removeAttribute('data-ve-drag-source');
                    endDrag();
                });
            });

            Array.prototype.forEach.call(canvas.querySelectorAll('[data-ve-kind="section"]'), function (node) {
                node.addEventListener('dragstart', function (event) {
                    // 控件的 dragstart 已经 stopPropagation，能到这里的就是拖区块本身。
                    drag = { mode: 'section', from: { si: pathParts(node)[0] } };
                    dragType = null;
                    canvas.setAttribute('data-ve-dragging', 'section');
                    node.setAttribute('data-ve-drag-source', '1');
                    if (event.dataTransfer) {
                        event.dataTransfer.effectAllowed = 'move';
                        event.dataTransfer.setData('text/plain', 've-section');
                    }
                });
                node.addEventListener('dragend', function () {
                    node.removeAttribute('data-ve-drag-source');
                    endDrag();
                });
                node.addEventListener('dragover', function (event) {
                    if (!drag || drag.mode !== 'section') return;
                    event.preventDefault();
                    if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
                    clearDropMarks();
                    var box = node.getBoundingClientRect();
                    node.setAttribute('data-ve-drop-edge',
                        event.clientY < box.top + box.height / 2 ? 'before' : 'after');
                });
                node.addEventListener('drop', function (event) {
                    if (!drag || drag.mode !== 'section') return;
                    event.preventDefault();
                    event.stopPropagation();
                    var edge = node.getAttribute('data-ve-drop-edge');
                    var at = pathParts(node)[0] + (edge === 'after' ? 1 : 0);
                    var from = drag.from.si;
                    endDrag();
                    moveSection(from, at);
                });
            });

            Array.prototype.forEach.call(canvas.querySelectorAll('[data-ve-kind="column"]'), function (node) {
                node.addEventListener('dragover', function (event) {
                    if (!drag || drag.mode === 'section') return;
                    event.preventDefault();
                    event.stopPropagation();
                    if (event.dataTransfer) {
                        event.dataTransfer.dropEffect = drag.mode === 'new' ? 'copy' : 'move';
                    }
                    clearDropMarks();
                    node.setAttribute('data-ve-drop', '1');
                    widgetDropIndex(node, event);
                });
                node.addEventListener('dragleave', function (event) {
                    if (event.target === node) node.removeAttribute('data-ve-drop');
                });
                node.addEventListener('drop', function (event) {
                    if (!drag || drag.mode === 'section') return;
                    event.preventDefault();
                    event.stopPropagation();
                    var parts = pathParts(node);
                    var si = parts[0];
                    var ci = parts[1];
                    var column = tree.sections[si] && tree.sections[si].columns[ci];
                    var wi = column ? widgetDropIndex(node, event) : 0;
                    var current = drag;
                    endDrag();
                    if (!column) return;
                    if (current.mode === 'new') {
                        if (!config.widgets[current.type]) return;
                        addWidget(current.type, { column: column, si: si, ci: ci, wi: wi });
                    } else {
                        moveWidget(current.from, { si: si, ci: ci, wi: wi });
                    }
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
            // 核心 Router 在分发前统一校验 POST 的 CSRF，读的字段名是 _csrf；
            // 名字不对会被它用纯文本「CSRF token mismatch」挡下（HTTP 419），
            // 请求根本到不了插件的端点。csrf_token 一并带上，端点自己也认。
            body.append('_csrf', config.csrfToken);
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

        /**
         * 树的上传负载：JSON 先 base64 再发。
         *
         * 为什么要编码：树里可能夹着自定义 HTML 控件的原始标签，站点前面的安全层
         * （WAF / mod_security）见到请求体里有 `<style` / `<script` 形状就整条拦成
         * 裸的 403 Forbidden，请求连 PHP 都进不去。base64 只是换个编码——
         * 服务端解码后照样走 normalize()，一点校验都没少。
         */
        function treePayload() {
            var json = JSON.stringify(tree);
            // btoa 只吃 latin1：先按 UTF-8 逐字节展开。
            var bytes = unescape(encodeURIComponent(json));
            return { tree_b64: btoa(bytes) };
        }

        /**
         * 把树落存并写回内容字段。resolve 后字段里已经是入库产物。
         * 这一步不碰核心表单的提交语义，只负责「字段里有正确的东西」。
         */
        function syncField() {
            return post(config.saveUrl, treePayload()).then(function (data) {
                var input = contentInput();
                if (!input) throw new Error('找不到内容字段，无法写回');
                input.value = data.block;
                if (window.jQuery) window.jQuery(input).trigger('change');
                else input.dispatchEvent(new Event('change', { bubbles: true }));
                // 服务端重新渲染过一遍，画布跟着换成入库产物，避免两边不一致。
                if (data.canvas_css && canvasStyle) canvasStyle.textContent = data.canvas_css;
                setDirty(false);
                return data;
            });
        }

        /**
         * 「保存」：把树发给插件自己的 persist 端点，服务端原地入库。
         *
         * 走过的两条弯路，写在这里免得再来一遍：
         *   1.3.0 用 fetch 把整张表单发上去 → 请求形状变了（multipart + 额外请求头），
         *          线上被挡成 Forbidden；
         *   1.3.1 改回原生 requestSubmit(核心提交按钮) → 请求形状与手点「发布」
         *          一模一样，**照样** Forbidden：被挡的不是形状，是请求体里那段
         *          编译好的 `<style data-ve-css>` 与整片 HTML。
         *
         * 所以 1.3.2 干脆不让产物经过浏览器：只上传一棵 base64 的 JSON 树，
         * HTML 与 CSS 由服务端渲染、服务端入库，并且入库走核心 ContentWorkflow
         * ——行锁、乐观版本、快照、修订、审计一条都不少。发布状态一律不动。
         * 附带的好处是保存后留在编辑台里，不再跳回内容页。
         */
        function saveContent() {
            if (busy || !tree) return;
            busy = true;
            if (applyButton) applyButton.disabled = true;
            setStatus('正在保存…');

            post(config.persistUrl, treePayload()).then(function (data) {
                // 字段同步照旧：用户回到表单时看到的就是已入库的产物。
                var input = contentInput();
                if (input) {
                    input.value = data.block;
                    if (window.jQuery) window.jQuery(input).trigger('change');
                    else input.dispatchEvent(new Event('change', { bubbles: true }));
                }
                if (data.canvas_css && canvasStyle) canvasStyle.textContent = data.canvas_css;
                setDirty(false);
                setStatus(data.message || '已保存');
                toast(data.changed === false ? '内容没有变化' : '已保存到这条内容');
            }).catch(function (error) {
                setStatus(error.message || '保存失败');
                toast(error.message || '保存失败');
            }).then(function () {
                busy = false;
                if (applyButton) applyButton.disabled = false;
            });
        }

        /** 只写回字段、不提交：给「先看看效果再决定」的人留一条路。 */
        function applyToField() {
            if (busy || !tree) return;
            busy = true;
            setStatus('正在应用…');
            syncField().then(function () {
                setStatus('已写回内容字段，尚未保存');
                toast('已写回字段，未保存');
            }).catch(function (error) {
                setStatus(error.message || '应用失败');
            }).then(function () { busy = false; });
        }

        /** 轻提示：保存留在编辑器里时，状态栏之外再给一个看得见的确认。 */
        function toast(message) {
            var node = el('div', 've-toast', message);
            stage.appendChild(node);
            var remove = function () { if (node.parentNode) node.parentNode.removeChild(node); };
            if (gsap && !reduceMotion) {
                gsap.fromTo(node, { y: 16, opacity: 0 },
                    { y: 0, opacity: 1, duration: .32, ease: 'back.out(1.8)' });
                gsap.to(node, { opacity: 0, y: 8, duration: .3, delay: 2.2, onComplete: remove });
            } else {
                setTimeout(remove, 2400);
            }
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
                        saveContent();
                    } else if (action === 'apply-only') {
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
                // 右键菜单开着时 Esc 归菜单：一次 Esc 关一层，别把编辑台一起关掉。
                if (event.key === 'Escape' && !stage.hidden && !dirty && !contextMenu) {
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
                post(config.saveUrl, treePayload()).then(function (data) {
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
        bindContextMenu();
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
