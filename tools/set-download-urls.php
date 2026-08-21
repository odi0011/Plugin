<?php
declare(strict_types=1);

/**
 * 回填 index.json 里各条目的 download.url（在仓库根目录运行）。
 *
 * 为什么单独一步：download.url 必须指向「已经包含这些 dist zip 的那个 commit」，
 * 而那个 commit 只有在 zip 提交之后才存在——build-index.php 生成 index.json 时
 * 拿不到它。所以发布流程是：
 *
 *   1. php tools/build-index.php                 # 产出 dist/*.zip 与 index.json（URL 为空）
 *   2. git add dist index.json && git commit     # 得到 commit C1
 *   3. php tools/set-download-urls.php <C1>      # 只改 URL，不重建 zip（sha256 因此不变）
 *   4. php tools/sign.php <私钥路径>              # 签 index.json / revoked.json
 *   5. git commit                                # 得到 commit C2
 *   6. 站点端把索引地址钉到 C2
 *
 * 本脚本不会重建 zip：重建可能改变字节从而改变 sha256，让索引与 C1 里的产物不一致。
 * 它反过来核对每个 dist zip 的 sha256 是否仍与索引一致，不一致就拒绝写入。
 *
 * 用法：
 *   php tools/set-download-urls.php <commit-sha> [--base=https://cdn.jsdelivr.net/gh/owner/repo]
 */

$root = dirname(__DIR__);
$indexPath = $root . '/index.json';

$args = array_slice($argv, 1);
$commit = '';
$base = '';
foreach ($args as $arg) {
    if (str_starts_with($arg, '--base=')) {
        $base = rtrim(substr($arg, 7), '/');
    } elseif ($commit === '') {
        $commit = trim($arg);
    }
}
if (!preg_match('/^[0-9a-f]{7,40}$/', $commit)) {
    fwrite(STDERR, "用法：php tools/set-download-urls.php <commit-sha> [--base=<cdn 前缀>]\n");
    exit(1);
}
if (!is_file($indexPath)) {
    fwrite(STDERR, "index.json 不存在，请先运行 tools/build-index.php\n");
    exit(1);
}

$index = json_decode((string)file_get_contents($indexPath), true);
if (!is_array($index) || !is_array($index['plugins'] ?? null)) {
    fwrite(STDERR, "index.json 不是合法索引\n");
    exit(1);
}

if ($base === '') {
    $repo = (string)($index['source']['repo'] ?? '');
    if (!preg_match('#^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$#', $repo)) {
        fwrite(STDERR, "无法从 index.json 的 source.repo 推断 CDN 前缀，请显式传 --base=\n");
        exit(1);
    }
    $base = 'https://cdn.jsdelivr.net/gh/' . $repo;
}

$failures = [];
foreach ($index['plugins'] as $i => $entry) {
    if (!is_array($entry)) continue;
    $slug = (string)($entry['slug'] ?? '');
    $version = (string)($entry['version'] ?? '');
    $expected = strtolower((string)($entry['download']['sha256'] ?? ''));
    $zipPath = $root . '/dist/' . $slug . '-' . $version . '.zip';

    if (!is_file($zipPath)) {
        $failures[] = "$slug@$version：dist 包缺失（$zipPath）";
        continue;
    }
    $actual = strtolower((string)hash_file('sha256', $zipPath));
    if (!hash_equals($expected, $actual)) {
        $failures[] = "$slug@$version：dist 包 sha256 与索引不一致，请重新运行 build-index.php";
        continue;
    }
    $index['plugins'][$i]['download']['url'] = $base . '@' . $commit . '/dist/' . $slug . '-' . $version . '.zip';
    // 图标同样指向已提交的 commit，避免指向浮动分支。
    if (($entry['icon'] ?? '') !== '' && !preg_match('#^https?://#i', (string)$entry['icon'])) {
        $index['plugins'][$i]['icon'] = $base . '@' . $commit . '/plugins/' . $slug . '/' . ltrim((string)$entry['icon'], '/');
    }
}

if ($failures !== []) {
    fwrite(STDERR, "回填失败：\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

// source.commit 记的是「索引对应的源 commit」。build 时只能拿到提交前的 HEAD，
// 这里顺手更正为真正包含这批源码与 dist 的 commit，省掉一处长期的困惑点。
$index['source']['commit'] = $commit;

$json = json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if (!is_string($json)) {
    fwrite(STDERR, "索引序列化失败\n");
    exit(1);
}
file_put_contents($indexPath, $json);
fwrite(STDOUT, 'OK: 已把 ' . count($index['plugins']) . " 个条目的 download.url / icon 指向 commit {$commit}。\n");
fwrite(STDOUT, "下一步：php tools/sign.php <私钥路径>，然后提交 index.json 与 .sig。\n");
