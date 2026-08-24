/*
 * 可视化编辑器：后台前端逻辑。
 *
 * 一条贯穿全文件的取向：**客户端不渲染页面**。
 * 每次改动都把内存里的文档树 POST 给服务端的 render 端点，换回 HTML 与作用域 CSS
 * 再换进画布。因此画布里看到的与前台输出的由同一段 PHP 生成，不存在两套渲染器
 * 慢慢走形的问题；代价是每个动作一次往返，换来的是所见即所得真的成立。
 *
 * 客户端只负责三件事：维护树、生成表单、管理撤销栈。
 */
(function () {
    'use strict';

    var configNode = document.querySelector('[data-ve-config]');
    if (!configNode) return;

    var CONFIG;
    try {
        CONFIG = JSON.parse(configNode.textContent || '{}');
    } catch (error) {
        return;
    }

    var root = document.querySelector('[data-ve-editor]');
    if (!root) return;

    var dom = {
        canvas: root.querySelector('[data-ve-canvas]'),
        frame: root.querySelector('[data-ve-frame]'),
        style: root.querySelector('[data-ve-style]'),
        message: root.querySelector('[data-ve-message]'),
        lock: root.querySelector('[data-ve-lock]'),
        statusBadge: root.querySelector('[data-ve-status-badge]'),
        selectionLabel: root.querySelector('[data-ve-selection-label]'),
        contentForm: root.querySelector('[data-ve-content-form]'),
        styleForm: root.querySelector('[data-ve-style-form]'),
        styleBreakpoint: root.querySelector('[data-ve-style-breakpoint]'),
        outline: root.querySelector('[data-ve-outline]'),
        undo: root.querySelector('[data-ve-undo]'),
        redo: root.querySelector('[data-ve-redo]')
    };

    var BREAKPOINT_LABELS = { desktop: '桌面', tablet: '平板', mobile: '手机' };

    var state = {
        tree: CONFIG.tree,
        lockVersion: CONFIG.lockVersion,
        breakpoint: 'desktop',
        selection: '',
        dirty: false,
        pending: false,
        undo: [],
        redo: []
    };

    // ============================================================
    // 工具
    // ============================================================

    function clone(value) {
        return JSON.parse(JSON.stringify(value));
    }

    function say(text, kind) {
        if (!dom.message) return;
        dom.message.textContent = text;
        dom.message.className = 'small ' + (kind === 'error' ? 'text-danger' : (kind === 'ok' ? 'text-success' : 'text-muted'));
    }

    /** 定位一个元素，返回 { kind, section, column, widget, node, siblings }。 */
    function locate(elementId) {
        var sections = state.tree.sections || [];
        for (var s = 0; s < sections.length; s++) {
            if (sections[s].id === elementId) {
                return { kind: 'section', section: s, node: sections[s], siblings: sections };
            }
            var columns = sections[s].columns || [];
            for (var c = 0; c < columns.length; c++) {
                if (columns[c].id === elementId) {
                    return { kind: 'column', section: s, column: c, node: columns[c], siblings: columns };
                }
                var widgets = columns[c].widgets || [];
                for (var w = 0; w < widgets.length; w++) {
                    if (widgets[w].id === elementId) {
                        return {
                            kind: 'widget', section: s, column: c, widget: w,
                            node: widgets[w], siblings: widgets
                        };
                    }
                }
            }
        }
        return null;
    }

    function selectedNode() {
        return state.selection ? locate(state.selection) : null;
    }

    // ============================================================
    // 历史
    // ============================================================

    function pushHistory() {
        state.undo.push(clone(state.tree));
        // 上限是刻意的：撤销栈存的是整棵树的快照，无界会把内存吃光。
        if (state.undo.length > 40) state.undo.shift();
        state.redo.length = 0;
        refreshHistoryButtons();
    }

    function refreshHistoryButtons() {
        if (dom.undo) dom.undo.disabled = state.undo.length === 0;
        if (dom.redo) dom.redo.disabled = state.redo.length === 0;
    }

    function undo() {
        if (!state.undo.length) return;
        state.redo.push(clone(state.tree));
        state.tree = state.undo.pop();
        state.dirty = true;
        refreshHistoryButtons();
        repaint('已撤销');
    }

    function redo() {
        if (!state.redo.length) return;
        state.undo.push(clone(state.tree));
        state.tree = state.redo.pop();
        state.dirty = true;
        refreshHistoryButtons();
        repaint('已重做');
    }

    // ============================================================
    // 与服务端通信
    // ============================================================

    function post(url, fields) {
        var body = new FormData();
        body.append('_csrf', CONFIG.csrf);
        Object.keys(fields).forEach(function (key) {
            body.append(key, fields[key]);
        });
        return fetch(url, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            return response.json().catch(function () {
                // 会话过期时服务端会重定向到登录页，返回的是 HTML 而不是 JSON。
                return { ok: false, message: '服务端返回了非 JSON 响应（登录可能已过期，请刷新页面）' };
            }).then(function (payload) {
                payload.httpStatus = response.status;
                return payload;
            });
        });
    }

    /** 把当前树交给服务端渲染，换回画布 HTML 与 CSS。 */
    function repaint(okMessage) {
        if (state.pending) return Promise.resolve(false);
        state.pending = true;
        say('渲染中…');
        return post(CONFIG.urls.render, { tree: JSON.stringify(state.tree) })
            .then(function (payload) {
                state.pending = false;
                if (!payload.ok) {
                    say(payload.message || '渲染失败', 'error');
                    return false;
                }
                // 用服务端归一化后的树覆盖本地：被丢掉的非法值不会留在界面上，
                // 避免「我明明设了但保存后没了」。
                state.tree = payload.tree;
                if (dom.canvas) dom.canvas.innerHTML = payload.html;
                if (dom.style) dom.style.textContent = payload.css;
                paintSelection();
                buildOutline();
                buildInspector();
                var warnings = (payload.warnings || []).join('；');
                say(warnings !== '' ? warnings : (okMessage || (state.dirty ? '有未保存的改动' : '就绪')),
                    warnings !== '' ? 'error' : undefined);
                return true;
            })
            .catch(function (error) {
                state.pending = false;
                say('渲染请求失败：' + error.message, 'error');
                return false;
            });
    }

    function commit(okMessage) {
        state.dirty = true;
        return repaint(okMessage);
    }

    // ============================================================
    // 选中与画布交互
    // ============================================================

    function paintSelection() {
        if (!dom.canvas) return;
        Array.prototype.forEach.call(dom.canvas.querySelectorAll('.ve-selected'), function (node) {
            node.classList.remove('ve-selected');
        });
        if (!state.selection) return;
        var node = dom.canvas.querySelector('[data-ve="' + cssEscape(state.selection) + '"]');
        if (node) node.classList.add('ve-selected');
    }

    /** 元素 id 形如 ve + 10 位小写十六进制，本来就不需要转义；仍然过一遍以防将来放宽格式。 */
    function cssEscape(value) {
        return String(value).replace(/[^a-zA-Z0-9_-]/g, '');
    }

    function select(elementId) {
        state.selection = elementId || '';
        paintSelection();
        buildOutline();
        buildInspector();
    }

    if (dom.canvas) {
        dom.canvas.addEventListener('click', function (event) {
            var target = event.target.closest('[data-ve-kind]');
            if (!target || !dom.canvas.contains(target)) return;
            // 画布里的链接在编辑态不该真的跳走。
            event.preventDefault();
            var id = target.getAttribute('data-ve');
            if (id) select(id);
        });
    }

    // ============================================================
    // 结构树
    // ============================================================

    function buildOutline() {
        if (!dom.outline) return;
        var html = '';
        (state.tree.sections || []).forEach(function (section, sectionIndex) {
            html += outlineRow(section.id, 've-outline-section',
                '区块 ' + (sectionIndex + 1) + (section.layout === 'full' ? '（通栏）' : ''));
            (section.columns || []).forEach(function (column, columnIndex) {
                html += outlineRow(column.id, 've-outline-column',
                    '栏 ' + (columnIndex + 1) + ' · ' + (column.width && column.width.desktop ? column.width.desktop : 100) + '%');
                (column.widgets || []).forEach(function (widget) {
                    var definition = CONFIG.widgets[widget.type];
                    html += outlineRow(widget.id, 've-outline-widget',
                        (definition ? definition.label : widget.type) + summaryOf(widget));
                });
            });
        });
        dom.outline.innerHTML = html;
    }

    function outlineRow(id, className, label) {
        return '<button type="button" class="' + className + (state.selection === id ? ' active' : '')
            + '" data-ve-outline-select="' + escapeAttribute(id) + '">' + escapeHtml(label) + '</button>';
    }

    function summaryOf(widget) {
        var content = widget.content || {};
        var raw = content.text || content.html || content.items || content.alt || '';
        var plain = String(raw).replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
        return plain === '' ? '' : '：' + (plain.length > 18 ? plain.slice(0, 18) + '…' : plain);
    }

    if (dom.outline) {
        dom.outline.addEventListener('click', function (event) {
            var button = event.target.closest('[data-ve-outline-select]');
            if (button) select(button.getAttribute('data-ve-outline-select'));
        });
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (character) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[character];
        });
    }

    function escapeAttribute(value) {
        return escapeHtml(value);
    }

    // ============================================================
    // 检查器：内容表单
    // ============================================================

    function buildInspector() {
        var located = selectedNode();
        updateSelectionBar(located);
        buildContentForm(located);
        buildStyleForm(located);
    }

    function updateSelectionBar(located) {
        var label = '未选中元素';
        if (located) {
            if (located.kind === 'widget') {
                var definition = CONFIG.widgets[located.node.type];
                label = (definition ? definition.label : located.node.type) + ' 控件';
            } else {
                label = located.kind === 'section' ? '区块' : '栏';
            }
        }
        if (dom.selectionLabel) dom.selectionLabel.textContent = label;
        ['[data-ve-move="up"]', '[data-ve-move="down"]', '[data-ve-duplicate]', '[data-ve-remove]'].forEach(function (selector) {
            var button = root.querySelector(selector);
            if (!button) return;
            // 栏不支持复制：复制一栏会打乱同区块内的宽度分配，不如让用户改栏数。
            var disabled = !located || (selector === '[data-ve-duplicate]' && located.kind === 'column');
            button.disabled = disabled;
        });
    }

    function buildContentForm(located) {
        if (!dom.contentForm) return;
        if (!located) {
            dom.contentForm.innerHTML = '<p class="ve-hint">在画布上点一个控件、栏或区块开始编辑。</p>';
            return;
        }
        if (located.kind === 'section') {
            dom.contentForm.innerHTML = field('区块布局',
                select_('data-ve-section-layout', located.node.layout,
                    [['boxed', '定宽（居中，受内容区宽度约束）'], ['full', '通栏（撑满视口宽度）']]));
            return;
        }
        if (located.kind === 'column') {
            var width = (located.node.width || {})[state.breakpoint];
            dom.contentForm.innerHTML = field('本栏宽度（%）· ' + BREAKPOINT_LABELS[state.breakpoint],
                '<input class="form-control form-control-sm" type="number" min="5" max="100" step="1"'
                + ' data-ve-column-width value="' + escapeAttribute(width === undefined ? 100 : width) + '">')
                + '<p class="ve-hint">同一区块内各栏宽度之和建议不超过 100%，否则会自动换行。</p>';
            return;
        }

        var definition = CONFIG.widgets[located.node.type];
        if (!definition) {
            dom.contentForm.innerHTML = '<p class="ve-hint">该控件类型已不受支持。</p>';
            return;
        }
        var html = '';
        Object.keys(definition.fields).forEach(function (name) {
            html += contentField(name, definition.fields[name], (located.node.content || {})[name]);
        });
        dom.contentForm.innerHTML = html;
    }

    /**
     * 一个内容字段的输入控件。
     * spec 就是服务端 schema 里的「类型:约束」字符串，因此界面能出现的输入形态
     * 和后端能接受的值域是同一份定义推出来的。
     */
    function contentField(name, spec, value) {
        var parts = String(spec).split(':');
        var type = parts[0];
        var constraint = parts[1] || '';
        var label = CONFIG.fieldLabels[name] || name;
        var attribute = ' data-ve-content="' + escapeAttribute(name) + '"';
        var current = value === undefined || value === null ? '' : value;

        if (type === 'enum') {
            var options = constraint.split(',').map(function (option) {
                return [option, enumLabel(name, option)];
            });
            return field(label, select_(attribute, current, options));
        }
        if (type === 'rich' || type === 'html_block') {
            return field(label, '<textarea class="form-control form-control-sm" rows="6"' + attribute + '>'
                + escapeHtml(current) + '</textarea>')
                + (type === 'html_block'
                    ? '<p class="ve-hint">保存时会按白名单消毒：script / style / iframe / 事件属性一律移除。</p>'
                    : '<p class="ve-hint">支持 p、strong、em、a、ul/ol、code 等行内标签，其余会被解开。</p>');
        }
        if (type === 'lines') {
            return field(label, '<textarea class="form-control form-control-sm" rows="5"' + attribute + '>'
                + escapeHtml(current) + '</textarea>');
        }
        if (type === 'number') {
            var bounds = constraint.split(',');
            return field(label, '<input class="form-control form-control-sm" type="number"'
                + (bounds[0] ? ' min="' + escapeAttribute(bounds[0]) + '"' : '')
                + (bounds[1] ? ' max="' + escapeAttribute(bounds[1]) + '"' : '')
                + attribute + ' value="' + escapeAttribute(current) + '">');
        }
        if (type === 'color') {
            return field(label, '<div class="ve-field-row">'
                + '<input type="color" class="form-control form-control-sm" data-ve-color-proxy value="'
                + escapeAttribute(/^#[0-9a-fA-F]{6}$/.test(current) ? current : '#000000') + '">'
                + '<input class="form-control form-control-sm" placeholder="#112233 或 var(--ui-primary)"'
                + attribute + ' value="' + escapeAttribute(current) + '"></div>');
        }
        if (type === 'media') {
            return field(label, '<div class="ve-field-row">'
                + '<input class="form-control form-control-sm" placeholder="/uploads/…"' + attribute
                + ' value="' + escapeAttribute(current) + '">'
                + '<button type="button" class="btn btn-sm btn-outline-secondary" data-ve-pick-media>选图</button>'
                + '</div>');
        }
        var maxLength = type === 'text' && constraint ? ' maxlength="' + escapeAttribute(constraint) + '"' : '';
        return field(label, '<input class="form-control form-control-sm"' + attribute + maxLength
            + ' value="' + escapeAttribute(current) + '">');
    }

    function enumLabel(name, option) {
        var dictionary = {
            left: '左对齐', center: '居中', right: '右对齐', justify: '两端对齐',
            _self: '当前窗口', _blank: '新窗口',
            primary: '主色实心', outline: '描边', ghost: '文字按钮',
            sm: '小', md: '中', lg: '大',
            disc: '圆点', decimal: '数字', check: '勾选', none: '无',
            solid: '实线', dashed: '虚线', dotted: '点线',
            youtube: 'YouTube', vimeo: 'Vimeo', bilibili: '哔哩哔哩',
            '16-9': '16:9', '4-3': '4:3', '1-1': '1:1',
            cover: '覆盖', contain: '包含', auto: '原始尺寸',
            'no-repeat': '不平铺', repeat: '平铺', 'repeat-x': '横向平铺', 'repeat-y': '纵向平铺',
            top: '顶部', bottom: '底部', block: '块级', flex: '弹性盒', 'inline-block': '行内块',
            'flex-start': '起始', 'flex-end': '末尾', 'space-between': '两端分散',
            'space-around': '环绕分散', stretch: '拉伸'
        };
        if (name === 'level') return option.toUpperCase();
        return dictionary[option] || option;
    }

    function field(label, control) {
        return '<div class="ve-field"><label>' + escapeHtml(label) + '</label>' + control + '</div>';
    }

    function select_(attribute, current, options) {
        var html = '<select class="form-select form-select-sm"' + attribute + '>';
        options.forEach(function (option) {
            html += '<option value="' + escapeAttribute(option[0]) + '"'
                + (String(current) === String(option[0]) ? ' selected' : '') + '>'
                + escapeHtml(option[1]) + '</option>';
        });
        return html + '</select>';
    }

    // ============================================================
    // 检查器：样式表单
    // ============================================================

    function buildStyleForm(located) {
        if (!dom.styleForm) return;
        if (dom.styleBreakpoint) dom.styleBreakpoint.textContent = BREAKPOINT_LABELS[state.breakpoint];
        if (!located) {
            dom.styleForm.innerHTML = '<p class="ve-hint">在画布上点一个元素开始设置样式。</p>';
            return;
        }

        var current = (located.node.style || {})[state.breakpoint] || {};
        var groups = {};
        Object.keys(CONFIG.styleProperties).forEach(function (property) {
            var meta = CONFIG.styleLabels[property] || { group: '其他', label: property };
            if (!groups[meta.group]) groups[meta.group] = '';
            groups[meta.group] += styleField(property, meta.label, current[property]);
        });

        var html = '';
        Object.keys(groups).forEach(function (group) {
            html += '<div class="ve-style-group"><h6>' + escapeHtml(group) + '</h6>' + groups[group] + '</div>';
        });
        html += '<button type="button" class="btn btn-sm btn-outline-secondary mt-2" data-ve-clear-style>'
            + '清空本断点样式</button>';
        dom.styleForm.innerHTML = html;
    }

    function styleField(property, label, value) {
        var valueType = CONFIG.styleProperties[property][1];
        var attribute = ' data-ve-style="' + escapeAttribute(property) + '"';
        var current = value === undefined || value === null ? '' : value;

        if (valueType === 'color') {
            return field(label, '<div class="ve-field-row">'
                + '<input type="color" class="form-control form-control-sm" data-ve-color-proxy value="'
                + escapeAttribute(/^#[0-9a-fA-F]{6}$/.test(current) ? current : '#000000') + '">'
                + '<input class="form-control form-control-sm" placeholder="留空 = 不设置"' + attribute
                + ' value="' + escapeAttribute(current) + '"></div>');
        }
        if (valueType === 'image') {
            return field(label, '<div class="ve-field-row">'
                + '<input class="form-control form-control-sm" placeholder="/uploads/…"' + attribute
                + ' value="' + escapeAttribute(current) + '">'
                + '<button type="button" class="btn btn-sm btn-outline-secondary" data-ve-pick-media>选图</button>'
                + '</div>');
        }
        if (valueType === 'shadow') {
            return field(label, select_(attribute, current,
                [['', '不设置'], ['none', '无'], ['sm', '轻'], ['md', '中'], ['lg', '重']]));
        }
        if (valueType === 'ratio') {
            return field(label, '<input class="form-control form-control-sm" type="number" min="0" max="9" step="0.05"'
                + attribute + ' value="' + escapeAttribute(current) + '" placeholder="留空 = 不设置">');
        }
        if (valueType.indexOf('enum:') === 0) {
            var options = [['', '不设置']].concat(valueType.slice(5).split(',').map(function (option) {
                return [option, enumLabel(property, option)];
            }));
            return field(label, select_(attribute, current, options));
        }
        return field(label, '<input class="form-control form-control-sm" placeholder="如 24px / 1.5rem / 50%"'
            + attribute + ' value="' + escapeAttribute(current) + '">');
    }

    // ============================================================
    // 检查器：输入绑定
    // ============================================================

    var debounceTimer = null;
    function debounced(fn) {
        window.clearTimeout(debounceTimer);
        debounceTimer = window.setTimeout(fn, 500);
    }

    /** 内容与样式输入：改动先落到内存树，再防抖重绘。历史快照在**改动前**推入。 */
    function bindInspector(container) {
        if (!container) return;

        container.addEventListener('input', function (event) {
            var target = event.target;
            if (target.hasAttribute('data-ve-color-proxy')) {
                var paired = target.parentElement.querySelector('[data-ve-style],[data-ve-content]');
                if (paired) {
                    paired.value = target.value;
                    paired.dispatchEvent(new Event('input', { bubbles: true }));
                }
                return;
            }
            var located = selectedNode();
            if (!located) return;

            if (target.hasAttribute('data-ve-content')) {
                applyOnce(function () {
                    located.node.content = located.node.content || {};
                    located.node.content[target.getAttribute('data-ve-content')] = target.value;
                });
            } else if (target.hasAttribute('data-ve-style')) {
                applyOnce(function () {
                    located.node.style = located.node.style || {};
                    located.node.style[state.breakpoint] = located.node.style[state.breakpoint] || {};
                    var bucket = located.node.style[state.breakpoint];
                    if (String(target.value).trim() === '') {
                        delete bucket[target.getAttribute('data-ve-style')];
                    } else {
                        bucket[target.getAttribute('data-ve-style')] = target.value;
                    }
                });
            } else if (target.hasAttribute('data-ve-column-width')) {
                applyOnce(function () {
                    located.node.width = located.node.width || {};
                    located.node.width[state.breakpoint] = parseInt(target.value, 10) || 100;
                });
            } else if (target.hasAttribute('data-ve-section-layout')) {
                applyOnce(function () { located.node.layout = target.value; });
            }
        });

        container.addEventListener('change', function (event) {
            if (event.target.tagName === 'SELECT') {
                event.target.dispatchEvent(new Event('input', { bubbles: true }));
            }
        });
    }

    /**
     * 连续输入只推一次历史快照：不然打一行字就塞进去十几个快照，
     * 撤销一次只退一个字符，等于没有撤销。
     */
    var editingBatch = false;
    function applyOnce(mutate) {
        if (!editingBatch) {
            pushHistory();
            editingBatch = true;
        }
        mutate();
        state.dirty = true;
        say('有未保存的改动');
        debounced(function () {
            editingBatch = false;
            repaint();
        });
    }

    bindInspector(dom.contentForm);
    bindInspector(dom.styleForm);

    // ============================================================
    // 结构操作
    // ============================================================

    /** 目标栏：选中的是栏就用它，选中控件就用它所在的栏，否则用第一栏。 */
    function targetColumn() {
        var located = selectedNode();
        if (located) {
            if (located.kind === 'column') return located.node;
            if (located.kind === 'widget') {
                return state.tree.sections[located.section].columns[located.column];
            }
            if (located.kind === 'section' && (located.node.columns || []).length) {
                return located.node.columns[0];
            }
        }
        var sections = state.tree.sections || [];
        return sections.length && (sections[0].columns || []).length ? sections[0].columns[0] : null;
    }

    function newId() {
        // 服务端会重新校验并按需换掉，因此这里只要保证本地唯一即可。
        var hex = '';
        for (var i = 0; i < 10; i++) hex += '0123456789abcdef'[Math.floor(Math.random() * 16)];
        return 've' + hex;
    }

    function emptyStyle() {
        return { desktop: {}, tablet: {}, mobile: {} };
    }

    function addWidget(type, column, index) {
        var definition = CONFIG.widgets[type];
        if (!definition) return;
        var target = column || targetColumn();
        if (!target) {
            say('没有可放控件的栏，请先加一个区块', 'error');
            return;
        }
        pushHistory();
        var widget = { id: newId(), type: type, content: clone(definition.defaults || {}), style: emptyStyle() };
        target.widgets = target.widgets || [];
        if (typeof index === 'number' && index >= 0 && index <= target.widgets.length) {
            target.widgets.splice(index, 0, widget);
        } else {
            target.widgets.push(widget);
        }
        state.selection = widget.id;
        commit('已加入' + definition.label);
    }

    function addSection(columns) {
        pushHistory();
        var count = Math.max(1, Math.min(6, columns || 1));
        var base = Math.floor(100 / count);
        var section = { id: newId(), layout: 'boxed', style: emptyStyle(), columns: [] };
        for (var i = 0; i < count; i++) {
            var percent = i === count - 1 ? 100 - base * (count - 1) : base;
            section.columns.push({
                id: newId(),
                width: { desktop: percent, tablet: count > 2 ? 50 : percent, mobile: 100 },
                style: emptyStyle(),
                widgets: []
            });
        }
        state.tree.sections = state.tree.sections || [];
        state.tree.sections.push(section);
        state.selection = section.columns[0].id;
        commit('已加入 ' + count + ' 栏区块');
    }

    function moveSelection(direction) {
        var located = selectedNode();
        if (!located) return;
        var index = located.kind === 'section' ? located.section
            : (located.kind === 'column' ? located.column : located.widget);
        var target = index + (direction === 'up' ? -1 : 1);
        if (target < 0 || target >= located.siblings.length) {
            say('已经在边界，无法再移动');
            return;
        }
        pushHistory();
        var moved = located.siblings.splice(index, 1)[0];
        located.siblings.splice(target, 0, moved);
        commit('已移动');
    }

    function duplicateSelection() {
        var located = selectedNode();
        if (!located || located.kind === 'column') return;
        pushHistory();
        var copy = clone(located.node);
        reassignIds(copy);
        if (located.kind === 'section') {
            state.tree.sections.splice(located.section + 1, 0, copy);
        } else {
            located.siblings.splice(located.widget + 1, 0, copy);
        }
        state.selection = copy.id;
        commit('已复制');
    }

    /** 复制出来的子树必须换一套 id：id 是样式落点，重复 id 会让两份共享同一条 CSS。 */
    function reassignIds(node) {
        node.id = newId();
        (node.columns || []).forEach(reassignIds);
        (node.widgets || []).forEach(reassignIds);
    }

    function removeSelection() {
        var located = selectedNode();
        if (!located) return;
        if (located.kind === 'section' && (state.tree.sections || []).length <= 1) {
            say('文档至少要保留一个区块', 'error');
            return;
        }
        if (located.kind === 'column' && (state.tree.sections[located.section].columns || []).length <= 1) {
            say('区块至少要保留一栏（要整块删请选中区块）', 'error');
            return;
        }
        if (!window.confirm('删除后可以用撤销恢复，确定删除？')) return;
        pushHistory();
        var index = located.kind === 'section' ? located.section
            : (located.kind === 'column' ? located.column : located.widget);
        located.siblings.splice(index, 1);
        state.selection = '';
        commit('已删除');
    }

    // ============================================================
    // 保存 / 发布 / 回滚
    // ============================================================

    function save() {
        if (state.pending) return;
        state.pending = true;
        say('保存中…');
        post(CONFIG.urls.save, {
            tree: JSON.stringify(state.tree),
            lock_version: state.lockVersion,
            note: '编辑器保存'
        }).then(function (payload) {
            state.pending = false;
            if (!payload.ok) {
                say(payload.message || '保存失败', 'error');
                return;
            }
            state.lockVersion = payload.lock_version;
            state.tree = payload.tree;
            state.dirty = false;
            if (dom.lock) dom.lock.textContent = String(payload.lock_version);
            if (dom.canvas) dom.canvas.innerHTML = payload.html;
            if (dom.style) dom.style.textContent = payload.css;
            paintSelection();
            buildOutline();
            buildInspector();
            var warnings = (payload.warnings || []).join('；');
            say(warnings !== '' ? '已保存，但有调整：' + warnings : '已保存', warnings !== '' ? 'error' : 'ok');
        }).catch(function (error) {
            state.pending = false;
            say('保存请求失败：' + error.message, 'error');
        });
    }

    function saveMeta() {
        var fields = { lock_version: state.lockVersion };
        Array.prototype.forEach.call(root.querySelectorAll('[data-ve-meta]'), function (input) {
            fields[input.getAttribute('data-ve-meta')] = input.value;
        });
        post(CONFIG.urls.meta, fields).then(function (payload) {
            if (!payload.ok) {
                say(payload.message || '保存页面设置失败', 'error');
                return;
            }
            state.lockVersion = payload.lock_version;
            if (dom.lock) dom.lock.textContent = String(payload.lock_version);
            var link = root.querySelector('[data-ve-public-url]');
            if (link) {
                link.textContent = payload.public_url;
                link.setAttribute('href', payload.public_url);
            }
            var slugInput = root.querySelector('[data-ve-meta="slug"]');
            if (slugInput) slugInput.value = payload.slug;
            say('已保存页面设置', 'ok');
        });
    }

    function toggleStatus(button) {
        var next = button.getAttribute('data-ve-current') === 'published' ? 'draft' : 'published';
        if (state.dirty && !window.confirm('还有未保存的改动，发布/撤回只改状态，不会保存内容。继续？')) return;
        post(CONFIG.urls.status, { status: next, lock_version: state.lockVersion }).then(function (payload) {
            if (!payload.ok) {
                say(payload.message || '操作失败', 'error');
                return;
            }
            state.lockVersion = payload.lock_version;
            if (dom.lock) dom.lock.textContent = String(payload.lock_version);
            button.setAttribute('data-ve-current', payload.status);
            button.textContent = payload.status === 'published' ? '撤回' : '发布';
            if (dom.statusBadge) {
                dom.statusBadge.textContent = payload.status === 'published' ? '已发布' : '草稿';
                dom.statusBadge.className = 'badge ' + (payload.status === 'published'
                    ? 'bg-success-subtle text-success-emphasis'
                    : 'bg-secondary-subtle text-secondary-emphasis');
            }
            say(payload.message, 'ok');
        });
    }

    function rollback(revisionId) {
        if (!window.confirm('回滚会把内容换成该修订的版本（当前内容会先存成一条新修订）。继续？')) return;
        post(CONFIG.urls.rollback, { revision: revisionId, lock_version: state.lockVersion })
            .then(function (payload) {
                if (!payload.ok) {
                    say(payload.message || '回滚失败', 'error');
                    return;
                }
                state.lockVersion = payload.lock_version;
                state.tree = payload.tree;
                state.dirty = false;
                state.selection = '';
                if (dom.lock) dom.lock.textContent = String(payload.lock_version);
                if (dom.canvas) dom.canvas.innerHTML = payload.html;
                if (dom.style) dom.style.textContent = payload.css;
                buildOutline();
                buildInspector();
                say('已回滚', 'ok');
            });
    }

    // ============================================================
    // 拖拽：从控件库拖到栏里
    // ============================================================

    root.addEventListener('dragstart', function (event) {
        var card = event.target.closest('[data-ve-add-widget]');
        if (!card) return;
        event.dataTransfer.setData('text/plain', card.getAttribute('data-ve-add-widget'));
        event.dataTransfer.effectAllowed = 'copy';
    });

    if (dom.canvas) {
        dom.canvas.addEventListener('dragover', function (event) {
            var column = event.target.closest('[data-ve-kind="column"]');
            if (!column) return;
            event.preventDefault();
            event.dataTransfer.dropEffect = 'copy';
            highlightDropTarget(column);
        });

        dom.canvas.addEventListener('dragleave', function (event) {
            if (event.target.closest('[data-ve-kind="column"]')) highlightDropTarget(null);
        });

        dom.canvas.addEventListener('drop', function (event) {
            var column = event.target.closest('[data-ve-kind="column"]');
            if (!column) return;
            event.preventDefault();
            highlightDropTarget(null);
            var type = event.dataTransfer.getData('text/plain');
            if (!type || !CONFIG.widgets[type]) return;
            var located = locate(column.getAttribute('data-ve'));
            addWidget(type, located ? located.node : null, -1);
        });
    }

    function highlightDropTarget(node) {
        Array.prototype.forEach.call(root.querySelectorAll('.ve-drop-target'), function (previous) {
            previous.classList.remove('ve-drop-target');
        });
        if (node) node.classList.add('ve-drop-target');
    }

    // ============================================================
    // 工具栏与快捷键
    // ============================================================

    root.addEventListener('click', function (event) {
        var target = event.target;

        var widgetButton = target.closest('[data-ve-add-widget]');
        if (widgetButton) { addWidget(widgetButton.getAttribute('data-ve-add-widget')); return; }

        var sectionButton = target.closest('[data-ve-add-section]');
        if (sectionButton) { addSection(parseInt(sectionButton.getAttribute('data-ve-add-section'), 10)); return; }

        var moveButton = target.closest('[data-ve-move]');
        if (moveButton) { moveSelection(moveButton.getAttribute('data-ve-move')); return; }

        if (target.closest('[data-ve-duplicate]')) { duplicateSelection(); return; }
        if (target.closest('[data-ve-remove]')) { removeSelection(); return; }
        if (target.closest('[data-ve-undo]')) { undo(); return; }
        if (target.closest('[data-ve-redo]')) { redo(); return; }
        if (target.closest('[data-ve-save]')) { save(); return; }
        if (target.closest('[data-ve-save-meta]')) { saveMeta(); return; }

        var statusButton = target.closest('[data-ve-toggle-status]');
        if (statusButton) { toggleStatus(statusButton); return; }

        var rollbackButton = target.closest('[data-ve-rollback]');
        if (rollbackButton) { rollback(parseInt(rollbackButton.getAttribute('data-ve-rollback'), 10)); return; }

        var clearStyle = target.closest('[data-ve-clear-style]');
        if (clearStyle) {
            var located = selectedNode();
            if (!located) return;
            pushHistory();
            located.node.style = located.node.style || emptyStyle();
            located.node.style[state.breakpoint] = {};
            commit('已清空 ' + BREAKPOINT_LABELS[state.breakpoint] + ' 断点样式');
            return;
        }

        var mediaButton = target.closest('[data-ve-pick-media]');
        if (mediaButton) { pickMedia(mediaButton); return; }

        var breakpointButton = target.closest('[data-ve-breakpoint]');
        if (breakpointButton) { switchBreakpoint(breakpointButton); return; }

        var leftTab = target.closest('[data-ve-left-tab]');
        if (leftTab) { switchTab(leftTab, 'data-ve-left-tab', 'data-ve-left-panel'); return; }

        var rightTab = target.closest('[data-ve-right-tab]');
        if (rightTab) { switchTab(rightTab, 'data-ve-right-tab', 'data-ve-right-panel'); }
    });

    function switchBreakpoint(button) {
        state.breakpoint = button.getAttribute('data-ve-breakpoint');
        Array.prototype.forEach.call(root.querySelectorAll('[data-ve-breakpoint]'), function (other) {
            other.classList.toggle('active', other === button);
        });
        if (dom.frame) dom.frame.setAttribute('data-ve-width', state.breakpoint);
        // 换断点等于换一套值，内容面板里的「本栏宽度」也是按断点存的，两边都要重建。
        buildInspector();
    }

    function switchTab(button, tabAttribute, panelAttribute) {
        var key = button.getAttribute(tabAttribute);
        Array.prototype.forEach.call(root.querySelectorAll('[' + tabAttribute + ']'), function (other) {
            other.classList.toggle('active', other === button);
        });
        Array.prototype.forEach.call(root.querySelectorAll('[' + panelAttribute + ']'), function (panel) {
            panel.classList.toggle('d-none', panel.getAttribute(panelAttribute) !== key);
        });
    }

    /** 复用后台媒体选择器；它没加载时退回手填路径，不把功能堵死。 */
    function pickMedia(button) {
        var input = button.parentElement.querySelector('[data-ve-style],[data-ve-content]');
        if (!input) return;
        if (window.MediaPicker && typeof window.MediaPicker.open === 'function') {
            window.MediaPicker.open({
                type: 'image',
                onSelect: function (file) {
                    input.value = (file && (file.url || file.path)) || '';
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                }
            });
            return;
        }
        say('媒体选择器未加载，请直接填写图片路径');
        input.focus();
    }

    document.addEventListener('keydown', function (event) {
        var meta = event.ctrlKey || event.metaKey;
        if (meta && event.key.toLowerCase() === 's') {
            event.preventDefault();
            save();
            return;
        }
        if (meta && event.key.toLowerCase() === 'z') {
            event.preventDefault();
            if (event.shiftKey) redo(); else undo();
        }
    });

    window.addEventListener('beforeunload', function (event) {
        if (!state.dirty) return undefined;
        event.preventDefault();
        event.returnValue = '';
        return '';
    });

    // ============================================================
    // 启动
    // ============================================================

    buildOutline();
    buildInspector();
    refreshHistoryButtons();
    say('就绪');
})();
