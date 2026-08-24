/*
 * 可视化编辑器：内容表单内的编辑面板逻辑（1.1.0）。
 *
 * 职责边界：
 *   - 树的**持有者**是这里：所有结构修改先改树，再整体重渲染画布；
 *   - 画布结构与 CSS 的生成规则镜像 src/Renderer.php 与 src/StyleCompiler.php，
 *     基础样式（config.baseCss）由服务端原样下发，两端产物同源；
 *   - 提交时把「渲染 HTML + 编译 CSS + 树 JSON」包成自包含块写进核心字段，
 *     之后的一切（校验、修订、审计）都是核心表单自己的事。
 */
(function () {
    'use strict';

    function boot() {
        var panel = document.getElementById('ve-panel');
        var configEl = document.getElementById('ve-panel-config');
        if (!panel || !configEl) return;
        var config;
        try { config = JSON.parse(configEl.textContent); } catch (e) { return; }
        if (!window.ContentEditorModes || !config.canUse) return;

        var canvas = panel.querySelector('[data-ve-canvas]');
        var canvasStyle = document.querySelector('[data-ve-canvas-style]');
        var statusEl = panel.querySelector('[data-ve-status]');
        var importLabel = panel.querySelector('[data-ve-import-label]');
        var inspector = panel.querySelector('[data-ve-inspector]');
        var inspectorBody = panel.querySelector('[data-ve-inspector-body]');
        var inspectorTarget = panel.querySelector('[data-ve-inspection-target]');
        if (!canvas || !statusEl || !inspector || !inspectorBody) return;

        var tree = null;
        try {
            var initial = document.getElementById('ve-initial-tree');
            tree = initial ? JSON.parse(initial.textContent) : null;
        } catch (e) { tree = null; }
        var selection = null;           // {kind:'section'|'column'|'widget', si, ci, wi}
        var breakpoint = 'desktop';
        var dirty = false;

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
            (window.crypto || {}).getRandomValues
                ? crypto.getRandomValues(bytes)
                : bytes.forEach(function (_, i) { bytes[i] = Math.floor(Math.random() * 256); });
            return 've' + Array.prototype.map.call(bytes, function (b) {
                return (b < 16 ? '0' : '') + b.toString(16);
            }).join('');
        }

        function blankTree() {
            return {
                version: 1,
                style: emptyStyle(),
                sections: [{
                    id: newId(), layout: 'boxed', style: emptyStyle(),
                    columns: [{ id: newId(), width: { desktop: 100, tablet: 100, mobile: 100 }, style: emptyStyle(), widgets: [] }],
                }],
            };
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

        function widgetBodyHtml(widget) {
            var c = widget.content || {};
            switch (widget.type) {
                case 'heading':
                    var level = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'].indexOf(c.level) >= 0 ? c.level : 'h2';
                    return '<' + level + ' class="ve-heading ve-align-' + alignOf(c) + '">' + esc(c.text) + '</' + level + '>';
                case 'text':
                    return '<div class="ve-text ve-align-' + alignOf(c) + '">' + String(c.html || '') + '</div>';
                case 'html':
                    return '<div class="ve-html">' + String(c.html || '') + '</div>';
                case 'image': {
                    if (!c.src || !isMediaUrl(c.src)) return '<div class="ve-image-placeholder">未选择图片</div>';
                    var width = Math.max(5, Math.min(100, parseInt(c.width, 10) || 100));
                    var img = '<img src="' + esc(c.src) + '" alt="' + esc(c.alt) + '" loading="lazy" style="width:' + width + '%">';
                    if (c.url && isSafeUrl(c.url)) img = '<a href="' + esc(c.url) + '">' + img + '</a>';
                    return '<figure class="ve-image ve-align-' + alignOf(c) + '">' + img + '</figure>';
                }
                case 'button': {
                    if (!c.text) return '';
                    var variant = ['primary', 'outline', 'ghost'].indexOf(c.variant) >= 0 ? c.variant : 'primary';
                    var size = ['sm', 'md', 'lg'].indexOf(c.size) >= 0 ? c.size : 'md';
                    var classes = 've-button ve-button-' + variant + ' ve-button-' + size;
                    var inner = '<span class="' + classes + '">' + esc(c.text) + '</span>';
                    if (c.url && isSafeUrl(c.url)) {
                        var target = c.target === '_blank' ? '_blank' : '_self';
                        inner = '<a class="' + classes + '" href="' + esc(c.url) + '" target="' + target + '"'
                            + (target === '_blank' ? ' rel="noopener noreferrer"' : '') + '>' + esc(c.text) + '</a>';
                    }
                    return '<div class="ve-button-wrap ve-align-' + alignOf(c) + '">' + inner + '</div>';
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
                    var style = ['solid', 'dashed', 'dotted'].indexOf(c.style) >= 0 ? c.style : 'solid';
                    var thickness = Math.max(1, Math.min(12, parseInt(c.thickness, 10) || 1));
                    return '<hr class="ve-divider" style="border-top-style:' + style + ';border-top-width:' + thickness + 'px">';
                }
                case 'spacer': {
                    var height = Math.max(4, Math.min(400, parseInt(c.height, 10) || 40));
                    return '<div class="ve-spacer" style="height:' + height + 'px"></div>';
                }
                case 'embed': {
                    if (!/^[A-Za-z0-9_-]{1,64}$/.test(String(c.video_id || ''))) {
                        return '<div class="ve-image-placeholder">未填写视频 ID</div>';
                    }
                    var providers = {
                        vimeo: 'https://player.vimeo.com/video/',
                        bilibili: 'https://player.bilibili.com/player.html?bvid=',
                        youtube: 'https://www.youtube-nocookie.com/embed/',
                    };
                    var src = (providers[c.provider] || providers.youtube) + c.video_id;
                    var ratio = ['16-9', '4-3', '1-1'].indexOf(c.ratio) >= 0 ? c.ratio : '16-9';
                    return '<div class="ve-embed ve-embed-' + ratio + '"><iframe src="' + esc(src) + '"'
                        + ' title="' + esc(c.title || '嵌入视频') + '" loading="lazy" allowfullscreen'
                        + ' referrerpolicy="strict-origin-when-cross-origin" frameborder="0"></iframe></div>';
                }
            }
            return '';
        }

        var WIDGET_LABELS = {};
        Object.keys(config.widgets).forEach(function (key) { WIDGET_LABELS[key] = config.widgets[key].label; });

        function renderTree(editing) {
            if (!tree) return '';
            var html = '<div class="ve-doc ve-doc-' + esc(config.sourceKey) + '"' + (editing ? ' data-ve-kind="document"' : '') + '>';
            tree.sections.forEach(function (section, si) {
                html += '<section data-ve="' + esc(section.id) + '" class="ve-section ve-section-' + (section.layout === 'full' ? 'full' : 'boxed') + '"'
                    + (editing ? ' data-ve-kind="section" data-ve-path="' + si + '"' : '') + '>'
                    + '<div class="ve-section-inner">';
                (section.columns || []).forEach(function (column, ci) {
                    html += '<div data-ve="' + esc(column.id) + '" class="ve-column"'
                        + (editing ? ' data-ve-kind="column" data-ve-path="' + si + '.' + ci + '"' : '') + '>';
                    (column.widgets || []).forEach(function (widget, wi) {
                        var definition = config.widgets[widget.type];
                        if (!definition) return;
                        html += '<div data-ve="' + esc(widget.id) + '" class="ve-widget ve-widget-' + esc(widget.type) + '"'
                            + (editing ? ' data-ve-kind="widget" data-ve-type="' + esc(widget.type) + '" data-ve-path="' + si + '.' + ci + '.' + wi + '"' : '')
                            + '>' + widgetBodyHtml(widget)
                            + (editing ? '<span class="ve-handle" data-ve-handle aria-hidden="true">' + esc(definition.label) + '</span>' : '')
                            + '</div>';
                    });
                    if (editing && !(column.widgets || []).length) {
                        html += '<div class="ve-column-empty">从左侧添加控件，或点选后用检查器编辑</div>';
                    }
                    html += '</div>';
                });
                html += '</div></section>';
            });
            return html + '</div>';
        }

        // ================= 样式编译（镜像 StyleCompiler.php）=================

        var STYLE_PROPERTIES = config.styleProperties;

        function validateStyleValue(property, raw) {
            raw = String(raw == null ? '' : raw).trim();
            if (!raw || /[<>{};@\\]/.test(raw) || raw.indexOf('/*') >= 0) return null;
            var definition = STYLE_PROPERTIES[property];
            if (!definition) return null;
            var valueType = definition[1];
            if (valueType === 'length') return /^-?(?:\d{1,4})(?:\.\d{1,2})?(?:px|rem|em|%|vh|vw)?$/.test(raw) ? raw : null;
            if (valueType === 'ratio') return /^\d(?:\.\d{1,2})?$/.test(raw) ? raw : null;
            if (valueType === 'color') return /^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/.test(raw)
                || /^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(?:,\s*(?:0|1|0?\.\d{1,3})\s*)?\)$/.test(raw)
                || /^var\(--[a-z0-9-]{1,60}\)$/i.test(raw) ? raw : null;
            if (valueType === 'image') return isMediaUrl(raw) ? raw : null;
            if (valueType === 'shadow') return ['none', 'sm', 'md', 'lg'].indexOf(String(raw).toLowerCase()) >= 0 ? String(raw).toLowerCase() : null;
            if (valueType.indexOf('enum:') === 0) {
                return valueType.slice(5).split(',').indexOf(raw) >= 0 ? raw : null;
            }
            return null;
        }

        function declarationLines(styleMap, bp) {
            var out = [];
            Object.keys(STYLE_PROPERTIES).forEach(function (property) {
                var value = validateStyleValue(property, (styleMap || {})[property]);
                if (value === null) return;
                var cssProp = STYLE_PROPERTIES[property][0];
                if (STYLE_PROPERTIES[property][1] === 'image') value = 'url("' + value.replace(/"/g, '%22').replace(/\\/g, '%5C') + '")';
                if (STYLE_PROPERTIES[property][1] === 'shadow') {
                    value = { none: 'none', sm: '0 1px 2px rgba(0,0,0,.08)', md: '0 4px 16px rgba(0,0,0,.10)', lg: '0 12px 40px rgba(0,0,0,.16)' }[value];
                }
                out.push(cssProp + ': ' + value + ';');
            });
            return out;
        }

        // 三个断点各自收集声明，顺序与 StyleCompiler::compile 一致：
        // 文档 → 区块 → 栏（含栏宽）→ 控件。提交必须用全量产物，
        // 只编译当前标签会让另外两个断点的设置在保存时静默丢失。
        function collectBuckets() {
            var buckets = { desktop: [], tablet: [], mobile: [] };
            var root = '.ve-doc-' + config.sourceKey;
            function push(map, selector) {
                ['desktop', 'tablet', 'mobile'].forEach(function (bp) {
                    var decls = declarationLines((map || {})[bp], bp);
                    if (decls.length) buckets[bp].push(selector + ' { ' + decls.join(' ') + ' }');
                });
            }
            push(tree.style, root);
            buckets.desktop.push(root + ' { --ve-container: ' + config.containerMax + 'px; }');
            tree.sections.forEach(function (section) {
                push(section.style, root + ' [data-ve="' + section.id + '"]');
                (section.columns || []).forEach(function (column) {
                    var columnSelector = root + ' [data-ve="' + column.id + '"]';
                    push(column.style, columnSelector);
                    ['desktop', 'tablet', 'mobile'].forEach(function (bp) {
                        var widthValue = parseFloat((column.width || {})[bp]);
                        if (!isNaN(widthValue)) {
                            var percent = Math.max(5, Math.min(100, Math.round(widthValue)));
                            buckets[bp].push(columnSelector + ' { flex: 0 0 ' + percent + '%; max-width: ' + percent + '%; }');
                        }
                    });
                    (column.widgets || []).forEach(function (widget) {
                        push(widget.style, root + ' [data-ve="' + widget.id + '"]');
                    });
                });
            });
            return buckets;
        }

        /** 提交用：镜像 StyleCompiler::compile 的完整三断点产物。 */
        function compileAllCss() {
            var buckets = collectBuckets();
            var breakpoints = config.breakpoints || { tablet: 1024, mobile: 767 };
            var css = config.baseCss + '\n' + buckets.desktop.join('\n');
            if (buckets.tablet.length) {
                css += '\n@media (max-width: ' + breakpoints.tablet + 'px) {\n' + buckets.tablet.join('\n') + '\n}';
            }
            if (buckets.mobile.length) {
                css += '\n@media (max-width: ' + breakpoints.mobile + 'px) {\n' + buckets.mobile.join('\n') + '\n}';
            }
            return css.trim();
        }

        /** 画布预览用：真实层叠是小屏继承大屏，预览按「大屏在前、当前断点覆盖」套用。 */
        function buildCss() {
            if (!tree) return '';
            var buckets = collectBuckets();
            var lines = breakpoint === 'desktop'
                ? buckets.desktop
                : buckets.desktop.concat(buckets[breakpoint]);
            return config.baseCss + '\n' + lines.join('\n');
        }

        // ================= 画布刷新与选中 =================

        function refreshCanvas() {
            canvas.innerHTML = renderTree(true);
            if (canvasStyle) canvasStyle.textContent = buildCss();
            updateStatus();
        }

        function updateStatus() {
            if (!tree) {
                statusEl.textContent = '';
                return;
            }
            var widgets = 0;
            tree.sections.forEach(function (section) {
                (section.columns || []).forEach(function (column) { widgets += (column.widgets || []).length; });
            });
            statusEl.textContent = tree.sections.length + ' 个区块 · ' + widgets + ' 个控件' + (dirty ? ' · 未保存' : '');
        }

        function select(pathString) {
            selection = null;
            canvas.querySelectorAll('[data-ve-selected]').forEach(function (node) {
                node.removeAttribute('data-ve-selected');
            });
            if (!pathString) {
                renderInspector();
                return;
            }
            var parts = pathString.split('.').map(Number);
            var kind = parts.length === 1 ? 'section' : parts.length === 2 ? 'column' : 'widget';
            selection = { kind: kind, si: parts[0], ci: parts[1], wi: parts[2] };
            var selector = '[data-ve-path="' + pathString + '"]';
            var target = canvas.querySelector(selector);
            if (target) target.setAttribute('data-ve-selected', '1');
            renderInspector();
        }

        canvas.addEventListener('click', function (event) {
            var handle = event.target.closest('[data-ve]');
            if (!handle) return;
            var path = handle.getAttribute('data-ve-path');
            if (!path) return;
            event.preventDefault();
            select(path === (selection && pathOf(selection)) ? '' : path);
        });

        function pathOf(sel) {
            if (sel.kind === 'section') return String(sel.si);
            if (sel.kind === 'column') return sel.si + '.' + sel.ci;
            return sel.si + '.' + sel.ci + '.' + sel.wi;
        }

        // ================= 检查器 =================

        function fieldSpec(specString) {
            var parts = String(specString).split(':');
            var constraints = (parts[1] || '').split(',');
            return { type: parts[0], a: constraints[0] || '', b: constraints[1] || '' };
        }

        var currentBreakpointTabs = null;

        function renderInspector() {
            inspectorBody.innerHTML = '';
            currentBreakpointTabs = null;
            var node = findNode(selection);
            if (!node) {
                inspector.hidden = true;
                return;
            }
            inspector.hidden = false;
            inspectorTarget.textContent = ({
                section: '区块 #' + (selection.si + 1),
                column: '区块 #' + (selection.si + 1) + ' · 栏 #' + (selection.ci + 1),
                widget: '区块 #' + (selection.si + 1) + ' · 控件：' + (WIDGET_LABELS[node.type] || node.type),
            })[selection.kind];

            if (selection.kind === 'section') {
                addGroup('区块布局');
                addSelectField('layout', '版式', [{ v: 'boxed', t: '定宽（受内容区宽度约束）' }, { v: 'full', t: '通栏' }], node.layout === 'full' ? 'full' : 'boxed', function (value) {
                    node.layout = value; markDirty();
                });
            }
            if (selection.kind === 'column') {
                addGroup('栏宽（%）');
                ['desktop', 'tablet', 'mobile'].forEach(function (bp) {
                    addNumberField('width.' + bp, ({ desktop: '桌面', tablet: '平板', mobile: '手机' })[bp], (node.width || {})[bp], 5, 100, function (value) {
                        node.width = node.width || {}; node.width[bp] = Math.max(5, Math.min(100, value)); markDirty();
                    });
                });
            }
            if (selection.kind === 'widget') {
                renderContentFields(node);
            }
            renderStyleEditor(node);
        }

        function renderContentFields(node) {
            var definition = config.widgets[node.type];
            if (!definition) return;
            addGroup('内容 — ' + definition.label);
            var grid = el('div', 've-field-grid');
            Object.keys(definition.fields).forEach(function (fieldName) {
                var spec = fieldSpec(definition.fields[fieldName]);
                if (spec.type === 'html_block' && !config.canUseCodeWidget) return;
                grid.appendChild(buildContentField(node, fieldName, spec));
            });
            inspectorBody.appendChild(grid);
        }

        function buildContentField(node, fieldName, spec) {
            var wrapEl = el('div', 've-field' + (spec.type === 'lines' || spec.type === 'rich' || spec.type === 'html_block' ? ' ve-field-wide' : ''));
            var label = el('label', null, config.fieldLabels[fieldName] || fieldName);
            wrapEl.appendChild(label);
            var value = (node.content || {})[fieldName];

            var commit = function () {};

            if (spec.type === 'enum') {
                var options = spec.a.split(',').filter(Boolean);
                var select = el('select');
                options.forEach(function (option) {
                    var opt = el('option', null, option); opt.value = option;
                    if (option === value) opt.selected = true;
                    select.appendChild(opt);
                });
                commit = function () { node.content[fieldName] = select.value; };
                wrapEl.appendChild(select);
            } else if (spec.type === 'number') {
                var input = el('input');
                input.type = 'number';
                if (spec.a) input.min = spec.a;
                if (spec.b) input.max = spec.b;
                input.value = value == null ? '' : value;
                commit = function () { var n = parseInt(input.value, 10); if (!isNaN(n)) node.content[fieldName] = n; };
                wrapEl.appendChild(input);
            } else if (spec.type === 'color') {
                var colorInput = el('input');
                colorInput.type = 'text';
                colorInput.placeholder = '#2563eb 或留空';
                colorInput.value = value == null ? '' : value;
                commit = function () { node.content[fieldName] = colorInput.value.trim(); };
                wrapEl.appendChild(colorInput);
            } else if (spec.type === 'lines') {
                var area = el('textarea');
                area.rows = 5;
                area.value = value == null ? '' : value;
                commit = function () { node.content[fieldName] = area.value.split('\n').map(function (l) { return l.trim(); }).filter(Boolean).join('\n'); };
                wrapEl.appendChild(area);
            } else if (spec.type === 'rich' || spec.type === 'html_block') {
                var source = el('textarea');
                source.rows = spec.type === 'rich' ? 5 : 8;
                source.className = 'font-monospace';
                source.value = value == null ? '' : value;
                commit = function () { node.content[fieldName] = source.value; };
                wrapEl.appendChild(source);
            } else {
                var textInput = el('input');
                textInput.type = 'text';
                if (spec.a && /^\d+$/.test(spec.a)) textInput.maxLength = parseInt(spec.a, 10);
                textInput.value = value == null ? '' : value;
                commit = function () { node.content[fieldName] = textInput.value.trim(); };
                wrapEl.appendChild(textInput);

                if ((fieldName === 'src' || fieldName === 'background_image')) {
                    var pick = el('button', 'btn btn-sm btn-outline-secondary mt-1', '选择媒体');
                    pick.type = 'button';
                    pick.addEventListener('click', function () {
                        if (window.MediaPicker) {
                            MediaPicker.open({ type: 'image', onSelect: function (file) { textInput.value = file.url; commit(); markDirty(); } });
                        } else {
                            window.alert('MediaPicker 未加载');
                        }
                    });
                    wrapEl.appendChild(pick);
                }
            }

            wrapEl.addEventListener('change', function () { commit(); markDirty(); });
            return wrapEl;
        }

        function renderStyleEditor(node) {
            var groups = {};
            Object.keys(config.styleLabels).forEach(function (property) {
                var group = config.styleLabels[property].group;
                (groups[group] = groups[group] || []).push(property);
            });

            addGroup('样式');
            var tabsRow = el('div', 'd-flex align-items-center gap-2 mb-2');
            var tabs = el('div', 've-breakpoint-tabs');
            [['desktop', '桌面'], ['tablet', '平板'], ['mobile', '手机']].forEach(function (pair) {
                var tabButton = el('button', null, pair[1]);
                tabButton.type = 'button';
                tabButton.setAttribute('data-bp', pair[0]);
                if (pair[0] === breakpoint) tabButton.setAttribute('data-ve-active', '1');
                tabButton.addEventListener('click', function () {
                    breakpoint = pair[0];
                    tabs.querySelectorAll('button').forEach(function (b) {
                        b.getAttribute('data-bp') === breakpoint ? b.setAttribute('data-ve-active', '1') : b.removeAttribute('data-ve-active');
                    });
                    renderInspector();
                });
                tabs.appendChild(tabButton);
            });
            tabsRow.appendChild(tabs);
            inspectorBody.appendChild(tabsRow);
            currentBreakpointTabs = tabs;

            Object.keys(groups).forEach(function (group) {
                addGroup(group);
                var grid = el('div', 've-field-grid');
                groups[group].forEach(function (property) {
                    grid.appendChild(buildStyleField(node, property));
                });
                inspectorBody.appendChild(grid);
            });
        }

        function buildStyleField(node, property) {
            var meta = config.styleLabels[property];
            var wrapEl = el('div', 've-field');
            wrapEl.appendChild(el('label', null, meta.label));
            var input = el('input');
            input.type = 'text';
            var values = (node.style || {})[breakpoint] || {};
            input.value = values[property] == null ? '' : values[property];
            input.placeholder = property === 'shadow' ? 'none/sm/md/lg' : '';
            input.addEventListener('change', function () {
                node.style = node.style || emptyStyle();
                node.style[breakpoint] = node.style[breakpoint] || {};
                var validated = validateStyleValue(property, input.value);
                if (validated === null) {
                    delete node.style[breakpoint][property];
                    if (input.value.trim() !== '') setStatusText('已忽略不合法的值：' + meta.label);
                } else {
                    node.style[breakpoint][property] = validated;
                }
                markDirty();
            });
            wrapEl.appendChild(input);
            return wrapEl;
        }

        function addGroup(title) {
            inspectorBody.appendChild(el('div', 've-group-title', title));
        }

        function addSelectField(key, label, options, value, onChange) {
            var wrapEl = el('div', 've-field');
            wrapEl.appendChild(el('label', null, label));
            var select = el('select');
            options.forEach(function (option) {
                var opt = el('option', null, option.t);
                opt.value = option.v;
                if (option.v === value) opt.selected = true;
                select.appendChild(opt);
            });
            select.addEventListener('change', function () { onChange(select.value); });
            wrapEl.appendChild(select);
            inspectorBody.appendChild(wrapEl);
        }

        function addNumberField(key, label, value, min, max, onChange) {
            var wrapEl = el('div', 've-field');
            wrapEl.appendChild(el('label', null, label));
            var input = el('input');
            input.type = 'number'; input.min = min; input.max = max;
            input.value = value == null ? max : value;
            input.addEventListener('change', function () {
                var n = parseInt(input.value, 10);
                if (!isNaN(n)) onChange(n);
            });
            wrapEl.appendChild(input);
            inspectorBody.appendChild(wrapEl);
        }

        function setStatusText(text) {
            statusEl.textContent = text;
            window.clearTimeout(setStatusText._t);
            setStatusText._t = window.setTimeout(updateStatus, 2500);
        }

        function markDirty() {
            dirty = true;
            refreshCanvas();
            renderInspectorKeepScroll();
        }

        function renderInspectorKeepScroll() {
            var scrollTop = inspectorBody.scrollTop;
            renderInspector();
            inspectorBody.scrollTop = scrollTop;
        }

        // ================= 结构操作 =================

        panel.querySelector('[data-ve-palette]').addEventListener('click', function (event) {
            var addButton = event.target.closest('[data-ve-add]');
            var sectionButton = event.target.closest('[data-ve-add-section]');
            if (addButton) {
                ensureTree();
                var targetColumn = selectedOrFirstColumn();
                targetColumn.widgets.push(normalizeWidget(addButton.getAttribute('data-ve-add'), {}));
                markDirty();
            } else if (sectionButton) {
                ensureTree();
                tree.sections.push({
                    id: newId(), layout: 'boxed', style: emptyStyle(),
                    columns: [{ id: newId(), width: { desktop: 100, tablet: 100, mobile: 100 }, style: emptyStyle(), widgets: [] }],
                });
                markDirty();
            }
        });

        function ensureTree() {
            if (!tree) tree = blankTree();
        }

        function selectedOrFirstColumn() {
            if (selection) {
                if (selection.kind === 'column') return tree.sections[selection.si].columns[selection.ci];
                if (selection.kind === 'widget') return tree.sections[selection.si].columns[selection.ci];
                if (selection.kind === 'section' && (tree.sections[selection.si].columns || []).length) {
                    return tree.sections[selection.si].columns[0];
                }
            }
            return tree.sections[0].columns[0];
        }

        panel.querySelectorAll('[data-ve-action]').forEach(function (button) {
            button.addEventListener('click', function () {
                var action = button.getAttribute('data-ve-action');
                if (action === 'import') return importCurrent();
                if (action === 'new-doc') {
                    if (tree && dirty && !window.confirm('当前可视化内容尚未保存，确定丢弃？')) return;
                    tree = blankTree();
                    dirty = true;
                    selection = null;
                    refreshCanvas();
                    renderInspector();
                    return;
                }
                applyStructureAction(action);
            });
        });

        function applyStructureAction(action) {
            if (!tree || !selection) return;
            var section = tree.sections[selection.si];
            if (!section) return;

            if (selection.kind === 'section') {
                if (action === 'move-up' && selection.si > 0) {
                    tree.sections.splice(selection.si - 1, 0, tree.sections.splice(selection.si, 1)[0]);
                    selection.si -= 1;
                } else if (action === 'move-down' && selection.si < tree.sections.length - 1) {
                    tree.sections.splice(selection.si + 1, 0, tree.sections.splice(selection.si, 1)[0]);
                    selection.si += 1;
                } else if (action === 'duplicate') {
                    var clone = JSON.parse(JSON.stringify(section));
                    reidentify(clone);
                    tree.sections.splice(selection.si + 1, 0, clone);
                } else if (action === 'remove') {
                    tree.sections.splice(selection.si, 1);
                    selection = null;
                }
            } else {
                var column = (section.columns || [])[selection.ci];
                if (!column) return;
                if (selection.kind === 'column') {
                    if (action === 'move-up' && selection.ci > 0) {
                        section.columns.splice(selection.ci - 1, 0, section.columns.splice(selection.ci, 1)[0]);
                        selection.ci -= 1;
                    } else if (action === 'move-down' && selection.ci < section.columns.length - 1) {
                        section.columns.splice(selection.ci + 1, 0, section.columns.splice(selection.ci, 1)[0]);
                        selection.ci += 1;
                    } else if (action === 'remove' && section.columns.length > 1) {
                        section.columns.splice(selection.ci, 1);
                        selection = null;
                    }
                } else {
                    var widget = (column.widgets || [])[selection.wi];
                    if (!widget) return;
                    if (action === 'move-up' && selection.wi > 0) {
                        column.widgets.splice(selection.wi - 1, 0, column.widgets.splice(selection.wi, 1)[0]);
                        selection.wi -= 1;
                    } else if (action === 'move-down' && selection.wi < column.widgets.length - 1) {
                        column.widgets.splice(selection.wi + 1, 0, column.widgets.splice(selection.wi, 1)[0]);
                        selection.wi += 1;
                    } else if (action === 'duplicate') {
                        var widgetClone = JSON.parse(JSON.stringify(widget));
                        widgetClone.id = newId();
                        column.widgets.splice(selection.wi + 1, 0, widgetClone);
                    } else if (action === 'remove') {
                        column.widgets.splice(selection.wi, 1);
                        selection = null;
                    }
                }
            }
            dirty = true;
            refreshCanvas();
            renderInspector();
        }

        function reidentify(node) {
            node.id = newId();
            (node.columns || []).forEach(function (column) {
                column.id = newId();
                (column.widgets || []).forEach(function (widget) { widget.id = newId(); });
            });
        }

        // ================= 导入 =================

        function importCurrent() {
            if (tree && dirty && !window.confirm('重新导入会以当前保存的内容替换正在编辑的可视化文档，继续？')) return;
            var body = new FormData();
            body.append('csrf_token', config.csrfToken);
            body.append('source_type', config.sourceType);
            body.append('source_id', String(config.sourceId));
            setStatusText('正在导入…');
            window.fetch(config.convertUrl, { method: 'POST', credentials: 'same-origin', body: body })
                .then(function (response) { return response.json(); })
                .then(function (payload) {
                    if (!payload.ok) throw new Error(payload.message || '导入失败');
                    tree = payload.data.tree;
                    dirty = false;
                    selection = null;
                    refreshCanvas();
                    renderInspector();
                    setStatusText(payload.message);
                    if (importLabel) importLabel.textContent = '重新导入';
                })
                .catch(function (error) { setStatusText(error.message || '导入失败'); });
        }

        if (tree) {
            refreshCanvas();
            if (importLabel) importLabel.textContent = '重新导入';
        } else {
            canvas.innerHTML = '<div class="ve-empty-hint">点「导入当前内容」把它变成可视化文档，或新建空白文档。</div>';
        }
        updateStatus();

        // ================= 提交：写回核心字段 =================

        function compileBlock() {
            var rendered = renderTree(false);
            var css = compileAllCss();
            // JSON 只需防 </script> 提前闭合：<\/ 是合法 JSON 转义，解析结果不变。
            var json = JSON.stringify(tree).replace(/<\//g, '<\\/');
            return '<!-- ve:managed -->\n'
                + rendered.replace(/\s+$/, '') + '\n'
                + (css ? '<style data-ve-css>' + css + '</style>\n' : '')
                + '<script type="application/json" data-ve-tree="' + esc(config.sourceKey) + '">' + json + '</scr' + 'ipt>\n'
                + '<!-- /ve:managed -->';
        }

        var form = panel.closest('form');
        if (form) {
            form.addEventListener('submit', function () {
                if (window.ContentEditorModes.current() !== config.modeKey) return;
                if (!tree) return;
                var targets = form.querySelectorAll('[name="' + config.fieldName.replace(/"/g, '\\"') + '"]');
                if (!targets.length) return;
                targets[targets.length - 1].value = compileBlock();
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
