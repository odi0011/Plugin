<?php
/**
 * PageHeader + Tabs。
 *
 * @var string $page
 * @var array<string,array{0:string,1:string}> $pages
 * @var array<string,mixed> $section
 * @var array<string,mixed> $status
 * @var bool $active
 */
$knowledge = (array)($status['knowledge'] ?? []);
$tools = (array)($status['tools'] ?? []);
$chips = [
    ['icon' => 'bi-cpu', 'label' => ($status['provider_mode'] ?? '') === 'custom' ? '独立接口' : '系统模型'],
    ['icon' => 'bi-journal-richtext', 'label' => '资料 ' . (int)($knowledge['source_count'] ?? 0) . ' 项 / ' . (int)($knowledge['file_count'] ?? 0) . ' 文件'],
    ['icon' => 'bi-tools', 'label' => empty($tools['enabled']) ? '工具已关闭' : '工具 ' . count((array)($tools['active'] ?? [])) . ' 个'],
    ['icon' => 'bi-palette', 'label' => '主题 ' . (string)(($status['display'] ?? [])['theme_preset'] ?? 'aurora')],
];
?>
<header class="acs-a-header">
    <div class="acs-a-header-top">
        <div class="acs-a-title">
            <span class="acs-a-title-icon"><i class="bi bi-chat-square-dots" aria-hidden="true"></i></span>
            <div>
                <h1>AI客服</h1>
                <p><?= e((string)($section['description'] ?? '')) ?></p>
            </div>
        </div>
        <div class="acs-a-header-right">
            <span class="acs-a-badge <?= !empty($status['enabled']) ? 'is-on' : 'is-off' ?>">
                <i class="acs-a-dot" aria-hidden="true"></i><?= !empty($status['enabled']) ? '前台已启用' : '前台未启用' ?>
            </span>
            <span class="acs-a-tag">v<?= e((string)($status['version'] ?? '')) ?></span>
        </div>
    </div>

    <?php if (empty($active)): ?>
        <div class="acs-a-alert acs-a-alert--info">
            <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
            <div>插件尚未启用。配置可以先保存，启用后立即生效。</div>
        </div>
    <?php endif; ?>

    <div class="acs-a-chips">
        <?php foreach ($chips as $chip): ?>
            <span class="acs-a-chip"><i class="bi <?= e($chip['icon']) ?>" aria-hidden="true"></i><?= e($chip['label']) ?></span>
        <?php endforeach; ?>
    </div>

    <nav class="acs-a-tabs" aria-label="AI客服设置页面">
        <?php foreach ($pages as $key => $meta): ?>
            <a class="acs-a-tab<?= $key === $page ? ' is-active' : '' ?>"
               href="<?= admin_url('/ai-customer-service/' . rawurlencode((string)$key)) ?>"
               <?= $key === $page ? 'aria-current="page"' : '' ?>>
                <i class="bi <?= e($meta[1]) ?>" aria-hidden="true"></i><span><?= e($meta[0]) ?></span>
            </a>
        <?php endforeach; ?>
        <span class="acs-a-tab-ink" data-acs-tab-ink aria-hidden="true"></span>
    </nav>
</header>
