<?php
/**
 * 维护页。刻意不 extend 前台布局：布局本身可能依赖数据库或模板，
 * 而维护往往正是数据库/模板出问题的时候。这里保持完全自包含。
 */
$safeTitle = htmlspecialchars($title !== '' ? $title : '站点维护中', ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= $safeTitle ?></title>
<style>
    :root { color-scheme: light dark; }
    body {
        margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Microsoft YaHei", sans-serif;
        background: #f6f7f9; color: #1f2933; padding: 24px;
    }
    .box { max-width: 560px; text-align: center; }
    .badge {
        display: inline-block; padding: 4px 12px; border-radius: 999px; font-size: 13px;
        background: #fef3c7; color: #92400e; margin-bottom: 20px;
    }
    h1 { font-size: 26px; margin: 0 0 12px; }
    p { margin: 0 0 10px; line-height: 1.7; color: #52606d; white-space: pre-line; }
    .eta { margin-top: 18px; font-size: 14px; color: #7b8794; }
    @media (prefers-color-scheme: dark) {
        body { background: #16181d; color: #e4e7eb; }
        p { color: #9aa5b1; }
        .badge { background: #3f3115; color: #fbbf24; }
    }
</style>
</head>
<body>
<div class="box">
    <div class="badge">维护中 · HTTP 503</div>
    <h1><?= $safeTitle ?></h1>
    <?php if (trim((string)$message) !== ''): ?>
        <p><?= htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
    <?php if (trim((string)$eta) !== ''): ?>
        <div class="eta">预计恢复时间：<?= htmlspecialchars((string)$eta, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
</div>
</body>
</html>
