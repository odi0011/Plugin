<?php
declare(strict_types=1);

/**
 * 插件市场索引构建器（在仓库根目录运行：php tools/build-index.php）
 * 产出：index.json、revoked.json（签名由 tools/sign.php 完成）
 *
 * 依赖：PHP 8.0+ CLI、zip 扩展。
 */
$root = dirname(__DIR__);
$pluginsDir = $root . '/plugins';
$distDir = $root . '/dist';

$failures = [];
$entries = [];
foreach (glob($pluginsDir . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
    $slug = basename($dir);
    if (!preg_match('/^[a-z0-9][a-z0-9\-_]{0,49}$/', $slug)) {
        $failures[] = "slug 不合法：$slug";
        continue;
    }
    $manifestPath = $dir . '/plugin.json';
    if (!is_file($manifestPath)) {
        $failures[] = "缺少 plugin.json：$slug";
        continue;
    }
    $manifest = json_decode((string)file_get_contents($manifestPath), true);
    if (!is_array($manifest)
        || ($manifest['slug'] ?? '') !== $slug
        || empty($manifest['name'])
        || empty($manifest['version'])) {
        $failures[] = "plugin.json 缺 slug/name/version 或 slug 不一致：$slug";
        continue;
    }
    if (!is_file($dir . '/plugin.php')) {
        $failures[] = "缺少 plugin.php：$slug";
        continue;
    }

    $version = (string)$manifest['version'];
    if (!is_dir($distDir) && !@mkdir($distDir, 0755, true)) {
        $failures[] = "无法创建 dist 目录";
        continue;
    }
    $zipPath = $distDir . '/' . $slug . '-' . $version . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        $failures[] = "无法创建 zip：$slug";
        continue;
    }
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    ) as $file) {
        if (!$file->isFile()) continue;
        $local = $slug . '/' . substr($file->getPathname(), strlen($dir) + 1);
        $local = str_replace('\\', '/', $local);
        $zip->addFile($file->getPathname(), $local);
    }
    $zip->close();

    $entries[] = [
        'slug' => $slug,
        'namespace' => (string)($manifest['namespace'] ?? ''),
        'name' => (string)$manifest['name'],
        'version' => $version,
        'description' => (string)($manifest['description'] ?? ''),
        'category' => (string)($manifest['category'] ?? 'other'),
        'tags' => array_values(array_filter(array_map('strval', (array)($manifest['tags'] ?? [])))),
        'icon' => (string)($manifest['icon'] ?? 'icon.svg'),
        'author' => is_array($manifest['author'] ?? null) ? $manifest['author'] : ['name' => (string)($manifest['author'] ?? '')],
        'homepage' => (string)($manifest['homepage'] ?? ''),
        'requires_core' => (string)($manifest['requires'] ?? ''),
        'requires_php' => (string)($manifest['requires_php'] ?? ''),
        'api_version' => 1,
        'dependencies' => (array)($manifest['dependencies'] ?? []),
        'conflicts' => (array)($manifest['conflicts'] ?? []),
        'permissions' => (array)($manifest['permissions'] ?? []),
        'agent' => (array)($manifest['agent'] ?? []),
        'published_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'changelog' => (string)($manifest['changelog'] ?? ''),
        'download' => [
            'url' => '', // 由发布流程回填（Release 地址或 jsDelivr dist 直链）
            'sha256' => (string)hash_file('sha256', $zipPath),
            'size_bytes' => (int)filesize($zipPath),
        ],
        'license' => ['type' => (string)($manifest['license_type'] ?? 'free')],
    ];
}

if ($failures !== []) {
    fwrite(STDERR, "构建失败：\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

$index = [
    'schema_version' => 1,
    'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'source' => [
        'type' => 'github-static',
        'repo' => 'odi0011/Plugin',
        'commit' => trim((string)@shell_exec('git rev-parse HEAD')),
    ],
    'core_api' => ['min' => 1, 'max' => 1],
    'plugins' => $entries,
];
file_put_contents($root . '/index.json', json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

if (is_file($root . '/revoked-source.json')) {
    $revoked = json_decode((string)file_get_contents($root . '/revoked-source.json'), true);
    if (is_array($revoked)) {
        $revoked['schema_version'] = 1;
        file_put_contents($root . '/revoked.json', json_encode($revoked, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
}

fwrite(STDOUT, "OK: index.json 已生成，共 " . count($entries) . " 个插件。\n");
