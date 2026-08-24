<?php
/**
 * 可视化文档的前台页面。
 *
 * extend 的是**核心前台布局**：页头页脚、SEO、CSS 变量、全局 CSS/JS、
 * frontend.* 钩子、隐私同意条都由它负责，插件只提供内容与本文档的作用域 CSS。
 * 这样站点改了页头，可视化页面跟着变，不会走形成两套外观。
 */
?>
<?php $this->extend('frontend/views/layouts/main'); ?>
<?php $this->section('title', (string)$title); ?>
<?php if (trim((string)$description) !== ''): ?>
<?php $this->section('description', (string)$description); ?>
<?php endif; ?>
<?php if (trim((string)$canonical) !== ''): ?>
<?php $this->section('canonical', (string)$canonical); ?>
<?php endif; ?>
<?php $this->section('page_css', (string)$documentCss); ?>

<?php $this->startSection('content'); ?>
<?php if (!empty($isPreview)): ?>
<div class="site-notice site-notice-warning ve-preview-notice">
    <i class="bi bi-eye"></i> <strong>草稿预览</strong> — 该页面尚未发布，只有具备查看权限的账号能看到。
</div>
<?php endif; ?>
<link rel="stylesheet" href="<?= e(plugin_url('visual-editor', 'assets/frontend.css')) ?>">
<?= $bodyHtml ?>
<?php do_action('visual_editor.document.after', $doc); ?>
<?php $this->endSection(); ?>
