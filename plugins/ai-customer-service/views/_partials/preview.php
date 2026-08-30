<?php
/**
 * 右侧实时预览 + 拖拽画布。
 *
 * 这里渲染的是 AiCustomerService::previewMarkup()——也就是**前台那一份标记**，
 * 用的也是前台那一份 customer-service.css / preset-cards.css（由 plugin.php 在本页注入）。
 * 所以预览里看到的形态、配色、圆角、气泡与前台是同一套实现，不存在"预览一个样、
 * 上线另一个样"。admin.js 只负责把表单值写成根节点上的 --acs-* 变量。
 *
 * @var array<string,mixed> $config
 * @var string $page
 */
?>
<aside class="acs-a-preview" aria-label="前台挂件实时预览">
    <div class="acs-a-preview-head">
        <div class="acs-a-preview-title"><i class="bi bi-eye" aria-hidden="true"></i> 实时预览</div>
        <div class="acs-a-segmented" role="group" aria-label="预览设备">
            <button type="button" class="is-active" data-acs-device="desktop" aria-pressed="true"><i class="bi bi-display" aria-hidden="true"></i> 桌面</button>
            <button type="button" data-acs-device="mobile" aria-pressed="false"><i class="bi bi-phone" aria-hidden="true"></i> 移动</button>
        </div>
    </div>

    <div class="acs-a-preview-toolbar">
        <div class="acs-a-segmented acs-a-segmented--sm" role="group" aria-label="窗口状态">
            <button type="button" class="is-active" data-acs-pv-state="open" aria-pressed="true">展开</button>
            <button type="button" data-acs-pv-state="closed" aria-pressed="false">收起</button>
        </div>
        <label class="acs-a-checkline">
            <input type="checkbox" data-acs-pv-drag>
            <span>拖拽定位</span>
        </label>
    </div>

    <div class="acs-a-stage" data-acs-preview-stage>
        <?= AiCustomerService::previewMarkup($config) ?>
    </div>
</aside>
