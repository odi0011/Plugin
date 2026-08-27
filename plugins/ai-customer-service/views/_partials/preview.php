<?php
/**
 * 右侧实时预览 + 拖拽画布。
 *
 * 结构刻意和前台挂件同形（acs-pv-* 对应 acs-*），这样一处配置改动只需要在 admin.js
 * 里映射一次。拖动浮标/引流气泡/飘带/角标会把偏移写回对应的表单字段与 layout_json，
 * 所以"拖完就是保存前的真实值"，不存在预览与前台两套坐标。
 *
 * @var array<string,mixed> $config
 * @var string $page
 */
?>
<aside class="acs-a-preview" aria-label="前台挂件实时预览">
    <div class="acs-a-preview-head">
        <div class="acs-a-preview-title">
            <i class="bi bi-eye" aria-hidden="true"></i> 实时预览
        </div>
        <div class="acs-a-segmented" role="group" aria-label="预览设备切换">
            <button type="button" class="is-active" data-acs-device="desktop" aria-pressed="true"><i class="bi bi-display" aria-hidden="true"></i> 桌面</button>
            <button type="button" data-acs-device="mobile" aria-pressed="false"><i class="bi bi-phone" aria-hidden="true"></i> 移动</button>
        </div>
    </div>

    <div class="acs-a-preview-toolbar">
        <div class="acs-a-segmented acs-a-segmented--sm" role="group" aria-label="预览状态">
            <button type="button" class="is-active" data-acs-pv-state="open" aria-pressed="true">展开</button>
            <button type="button" data-acs-pv-state="closed" aria-pressed="false">收起</button>
        </div>
        <label class="acs-a-checkline">
            <input type="checkbox" data-acs-pv-showcards checked>
            <span>示例卡片</span>
        </label>
        <label class="acs-a-checkline">
            <input type="checkbox" data-acs-pv-drag>
            <span title="打开后可以直接拖动浮标、引流气泡、飘带与角标">拖拽定位</span>
        </label>
    </div>

    <div class="acs-a-stage" data-acs-preview-stage>
        <div class="acs-a-stage-hint" data-acs-drag-hint hidden>拖动元素调整位置，松手即写回表单</div>

        <div class="acs-pv-widget acs-pv--right" data-acs-pv-widget>
            <div class="acs-pv-panel" data-acs-pv-panel>
                <div class="acs-pv-ribbon" data-acs-pv-drag-target="ribbon" data-acs-pv-ribbon hidden>
                    <span data-acs-pv-ribbon-text>新客首单立减 5%</span>
                    <i class="bi bi-x" aria-hidden="true"></i>
                </div>

                <div class="acs-pv-header">
                    <span class="acs-pv-avatar" data-acs-pv-avatar><i class="bi bi-stars" aria-hidden="true"></i><img src="" alt="" hidden></span>
                    <span class="acs-pv-agent">
                        <strong data-acs-pv="brand_name">AI客服</strong>
                        <small><i class="acs-pv-dot" aria-hidden="true"></i><span data-acs-pv="team_label">智能在线服务</span></small>
                    </span>
                    <span class="acs-pv-header-actions"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i><i class="bi bi-x-lg" aria-hidden="true"></i></span>
                </div>

                <div class="acs-pv-body" data-acs-pv-body>
                    <div class="acs-pv-row acs-pv-row--bot">
                        <span class="acs-pv-mini-avatar" data-acs-pv-mini-avatar aria-hidden="true"><i class="bi bi-stars"></i></span>
                        <div class="acs-pv-bubble acs-pv-bubble--bot" data-acs-pv="welcome_message">您好，我是您的 AI 客服。有什么可以帮您？</div>
                    </div>
                    <div class="acs-pv-row acs-pv-row--visitor">
                        <div class="acs-pv-bubble acs-pv-bubble--visitor">有没有防水的型号？大概什么价位</div>
                    </div>
                    <div class="acs-pv-row acs-pv-row--bot" data-acs-pv-toolrow>
                        <span class="acs-pv-mini-avatar" data-acs-pv-mini-avatar aria-hidden="true"><i class="bi bi-stars"></i></span>
                        <div class="acs-pv-stack">
                            <div class="acs-pv-toolchip"><i class="bi bi-box-seam" aria-hidden="true"></i> 已查站内产品 · 3 条</div>
                            <div class="acs-pv-bubble acs-pv-bubble--bot">这三款都是 IP68 防水，价格区间如下，点卡片可以直接看详情。</div>
                            <div class="acs-pv-cards" data-acs-pv-cards></div>
                        </div>
                    </div>
                    <div class="acs-pv-row acs-pv-row--bot" data-acs-pv-typingrow>
                        <span class="acs-pv-mini-avatar" data-acs-pv-mini-avatar aria-hidden="true"><i class="bi bi-stars"></i></span>
                        <div class="acs-pv-bubble acs-pv-bubble--bot acs-pv-typing" data-acs-pv-typing><i></i><i></i><i></i></div>
                    </div>
                </div>

                <div class="acs-pv-quick" data-acs-pv-quick>
                    <span class="acs-pv-quick-title" data-acs-pv-quick-title hidden>猜你想问</span>
                    <div class="acs-pv-quick-row"></div>
                </div>
                <div class="acs-pv-handoff" data-acs-pv-handoff hidden><i class="bi bi-person-workspace" aria-hidden="true"></i><span>联系人工客服</span></div>

                <div class="acs-pv-composer">
                    <span class="acs-pv-composer-text" data-acs-pv="input_placeholder">输入您的问题...</span>
                    <div class="acs-pv-composer-bar">
                        <span class="acs-pv-composer-tools" data-acs-pv-tools></span>
                        <span class="acs-pv-composer-right">
                            <span class="acs-pv-counter">0/2000</span>
                            <i class="bi bi-arrow-up acs-pv-send" aria-hidden="true"></i>
                        </span>
                    </div>
                </div>
                <div class="acs-pv-powered" data-acs-pv-powered hidden>AI客服</div>
            </div>

            <button type="button" class="acs-pv-launcher" data-acs-pv-drag-target="launcher" tabindex="-1">
                <img class="acs-pv-launcher-img" src="" alt="" hidden>
                <i class="bi bi-chat-dots-fill acs-pv-launcher-icon" aria-hidden="true"></i>
                <span class="acs-pv-launcher-label" hidden>AI客服</span>
                <span class="acs-pv-badge" data-acs-pv-drag-target="badge" hidden></span>
            </button>
            <span class="acs-pv-tooltip" data-acs-pv-tooltip hidden>有疑问？点我咨询</span>
            <div class="acs-pv-teaser" data-acs-pv-drag-target="teaser" data-acs-pv-teaser hidden>
                <i class="bi bi-x-lg acs-pv-teaser-close" aria-hidden="true"></i>
                <span data-acs-pv-teaser-text>有问题？随时咨询</span>
            </div>
        </div>
    </div>

    <p class="acs-a-preview-note" data-acs-preview-note>
        预览用的是真实的字段值与真实的 CSS 变量；卡片与消息是示例内容。
    </p>
</aside>
