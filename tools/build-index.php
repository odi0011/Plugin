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

// 已发布索引里的 published_at（slug@version → 时间戳），重建时沿用原发布时间。
$previousPublishedAt = [];
if (is_file($root . '/index.json')) {
    $previousIndex = json_decode((string)file_get_contents($root . '/index.json'), true);
    foreach ((array)($previousIndex['plugins'] ?? []) as $previousEntry) {
        if (!is_array($previousEntry)) continue;
        $key = (string)($previousEntry['slug'] ?? '') . '@' . (string)($previousEntry['version'] ?? '');
        $value = (string)($previousEntry['published_at'] ?? '');
        if ($key !== '@' && $value !== '') $previousPublishedAt[$key] = $value;
    }
}
$publishedAt = static function (string $slug, string $version) use ($previousPublishedAt): string {
    return $previousPublishedAt[$slug . '@' . $version] ?? gmdate('Y-m-d\TH:i:s\Z');
};

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

    $bootstrap = (string)file_get_contents($dir . '/plugin.php');

    // Agent 动作双写：plugin.php 注册的 id 与 plugin.json 的 agent.actions 必须一一对应。
    // 少一半的话 AiAgentActionRegistry 会静默丢弃该动作，装上也用不了。
    $declaredActions = [];
    foreach ((array)($manifest['agent']['actions'] ?? []) as $declared) {
        $id = (string)($declared['id'] ?? '');
        if ($id === '') continue;
        if (!preg_match('/^[a-z][a-z0-9_]{0,60}$/', $id)) {
            $failures[] = "$slug：动作 id「$id」不符合 ^[a-z][a-z0-9_]{0,60}$（不能有点号或大写）";
            continue;
        }
        $declaredActions[$id] = true;
    }
    preg_match_all("/'id'\s*=>\s*'([a-z0-9_]+)'/", $bootstrap, $registeredMatches);
    $registeredActions = array_unique($registeredMatches[1] ?? []);
    foreach ($registeredActions as $id) {
        if (!isset($declaredActions[$id])) {
            $failures[] = "$slug：plugin.php 注册的动作「$id」不在 plugin.json 的 agent.actions 里";
        }
    }
    foreach (array_keys($declaredActions) as $id) {
        if (!in_array($id, $registeredActions, true)) {
            $failures[] = "$slug：plugin.json 声明的动作「$id」未在 plugin.php 注册";
        }
    }

    // 迁移语句白名单：PluginManager 只接受可原子回滚的 DDL 与 DML。
    // 一条 SET NAMES 就会让激活直接失败，这种错误必须在发布前拦住。
    foreach (glob($dir . '/migrations/*.sql') ?: [] as $migrationFile) {
        $migrationSql = (string)preg_replace('/^\s*(--|#).*$/m', '', (string)file_get_contents($migrationFile));
        foreach (array_filter(array_map('trim', explode(';', $migrationSql))) as $statement) {
            if (preg_match(
                '/^(CREATE\s+TABLE|CREATE\s+(UNIQUE\s+|FULLTEXT\s+|SPATIAL\s+)?INDEX|ALTER\s+TABLE|INSERT|UPDATE|DELETE|REPLACE|SELECT|WITH)\b/i',
                $statement
            )) {
                continue;
            }
            $failures[] = "$slug：迁移 " . basename($migrationFile) . ' 含不被支持的语句「'
                . mb_substr((string)preg_replace('/\s+/', ' ', $statement), 0, 40) . '」';
        }
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

    // 与既有仓库形态保持一致：每个包旁边放一份 .sha256 便于人工核对
    // （协议本身以 index.json 里的 download.sha256 为准）。
    file_put_contents($zipPath . '.sha256', hash_file('sha256', $zipPath) . '  ' . basename($zipPath) . "\n");

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
        // 协议字段是 requires_core，plugin.json 历史上写的是 requires：两个都认，
        // 优先 requires_core。只读一个的话另一个会被静默置空成「无版本要求」。
        'requires_core' => (string)($manifest['requires_core'] ?? $manifest['requires'] ?? ''),
        'requires_php' => (string)($manifest['requires_php'] ?? ''),
        'api_version' => 1,
        'dependencies' => (array)($manifest['dependencies'] ?? []),
        'conflicts' => (array)($manifest['conflicts'] ?? []),
        'permissions' => (array)($manifest['permissions'] ?? []),
        'agent' => (array)($manifest['agent'] ?? []),
        // 同一 slug+version 已发布过就沿用原发布时间，避免重新构建把所有插件的
        // 发布日期刷成「今天」，市场页按 published_at 排序会整体乱掉。
        'published_at' => $publishedAt($slug, $version),
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
