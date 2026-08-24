<?php
/**
 * 编辑器右栏：检查器。
 *
 * 面板内容全部由 assets/editor.js 按选中元素的类型从 schema 动态生成——
 * 表单字段和后端白名单同源，因此界面里不可能出现后端拒收的字段。
 */
?>
<aside class="ve-side-pane ve-side-right">
    <div class="ve-pane-tabs" role="tablist">
        <button type="button" class="ve-pane-tab active" data-ve-right-tab="content" role="tab">内容</button>
        <button type="button" class="ve-pane-tab" data-ve-right-tab="style" role="tab">样式</button>
    </div>

    <div class="ve-selection-bar">
        <span data-ve-selection-label class="text-muted small">未选中元素</span>
        <span class="ve-toolbar-spacer"></span>
        <div class="btn-group btn-group-sm">
            <button type="button" class="btn btn-outline-secondary" data-ve-move="up" title="上移" disabled><i class="bi bi-arrow-up"></i></button>
            <button type="button" class="btn btn-outline-secondary" data-ve-move="down" title="下移" disabled><i class="bi bi-arrow-down"></i></button>
            <button type="button" class="btn btn-outline-secondary" data-ve-duplicate title="复制" disabled><i class="bi bi-copy"></i></button>
            <button type="button" class="btn btn-outline-danger" data-ve-remove title="删除" disabled><i class="bi bi-trash"></i></button>
        </div>
    </div>

    <div class="ve-pane-body" data-ve-right-panel="content">
        <div data-ve-content-form>
            <p class="ve-hint">在画布上点一个控件、栏或区块开始编辑。</p>
        </div>
    </div>

    <div class="ve-pane-body d-none" data-ve-right-panel="style">
        <div class="ve-breakpoint-note">
            正在编辑 <strong data-ve-style-breakpoint>桌面</strong> 断点。留空表示继承上一级断点的值。
        </div>
        <div data-ve-style-form>
            <p class="ve-hint">在画布上点一个元素开始设置样式。</p>
        </div>
    </div>
</aside>
