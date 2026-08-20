# 插件市场仓库搭建指南（odi0011/Plugin）

> 配套 `docs/plugin-marketplace-spec.md`。本指南说明如何从零搭建公开插件仓库、构建并签名索引、发布版本。
> 仓库已存在且为空（https://github.com/odi0011/Plugin），按本文档从空仓库开始即可。

---

## 1. 目标结构

```
odi0011/Plugin
├── README.md
├── index.json                  # 构建产物（tools/build-index.php 生成）
├── index.json.sig              # 构建产物（tools/sign.php 生成）
├── index.json.pub              # 公钥 PEM（手工提交一次）
├── revoked.json + revoked.json.sig
├── plugins/                    # 开发主源
│   └── {slug}/
│       ├── plugin.json
│       ├── plugin.php
│       └── …
├── tools/
│   ├── build-index.php
│   └── sign.php
└── .github/workflows/release.yml
```

---

## 2. 生成签名密钥（只做一次）

```bash
# 在本地（不是 CI）执行，私钥绝不提交到仓库
openssl genrsa -out plugin-marketplace.key 2048
openssl rsa -in plugin-marketplace.key -pubout -out index.json.pub

# 提交公钥；私钥保存在本机安全位置，或仅放入 GitHub Secrets（MARKET_SIGN_KEY）
```

要点：

- 私钥泄露的后果 = 攻击者可伪造整个市场索引，所有站点都可能被投毒。私钥只允许存在于：本机 + CI Secrets。
- 建议每 1~2 年轮换一次公钥；轮换时新版核心内置新公钥后再替换仓库公钥，过渡期内站点可后台手动粘贴新公钥。

---

## 3. tools/build-index.php（构建脚本草案）

要求：PHP 8.0 CLI、zip 扩展。从 `plugins/` 扫描每个插件，读取 plugin.json 校验，计算 zip 哈希，聚合出 index.json 与 revoked.json。

```php
<?php
declare(strict_types=1);

/**
 * 插件市场索引构建器（在仓库根目录运行：php tools/build-index.php）
 * 产出：index.json、revoked.json（签名由 tools/sign.php 完成）
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

    // 打包：dist/{slug}-{version}.zip（内层一个 {slug}/ 目录）
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
        'icon' => $root . '/plugins/' . $slug . '/' . ($manifest['icon'] ?? 'icon.svg'),
        'author' => (array)($manifest['author'] ?? []),
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
            // 注意：构建时尚未上传 Release，url 留占位符，发布脚本替换，或手工填
            'url' => 'https://github.com/odi0011/Plugin/releases/download/{slug}@{version}/{slug}-{version}.zip',
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
    'source' => ['type' => 'github-static', 'repo' => 'odi0011/Plugin', 'commit' => trim((string)@shell_exec('git rev-parse HEAD'))],
    'core_api' => ['min' => 1, 'max' => 1],
    'plugins' => $entries,
];
file_put_contents($root . '/index.json', json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

// revoked.json 由手工维护源文件 revoked-source.json（如需吊销），这里原样规范化输出
if (is_file($root . '/revoked-source.json')) {
    $revoked = json_decode((string)file_get_contents($root . '/revoked-source.json'), true);
    if (is_array($revoked)) {
        $revoked['schema_version'] = 1;
        file_put_contents($root . '/revoked.json', json_encode($revoked, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
}

fwrite(STDOUT, "OK: index.json 已生成，共 " . count($entries) . " 个插件。\n");
```

说明：`download.url` 依赖 Release 已发布。实际流程是「先 build → 发布 Release 上传 dist/*.zip → 再补 URL 跑一次 build（或发布脚本自动替换占位符）」，见 §5 发布流程。

---

## 4. tools/sign.php（签名脚本草案）

```php
<?php
declare(strict_types=1);

/**
 * 对 index.json / revoked.json 做 RSA-SHA256 签名。
 * 用法：php tools/sign.php /path/to/private.key
 * 产出：index.json.sig、revoked.json.sig（base64）
 */
if ($argc < 2) { fwrite(STDERR, "用法：php tools/sign.php <私钥路径>\n"); exit(1); }
$keyPath = $argv[1];
$root = dirname(__DIR__);

$privateKey = openssl_pkey_get_private((string)file_get_contents($keyPath));
if ($privateKey === false) { fwrite(STDERR, "无法读取私钥\n"); exit(1); }

foreach (['index.json', 'revoked.json'] as $file) {
    $path = $root . '/' . $file;
    if (!is_file($path)) continue;
    $signature = '';
    if (!openssl_sign((string)file_get_contents($path), $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        fwrite(STDERR, "签名失败：$file\n");
        exit(1);
    }
    file_put_contents($path . '.sig', base64_encode($signature));
    fwrite(STDOUT, "已签名：$file.sig\n");
}
```

---

## 5. 发布流程（手工版）

1. 修改 `plugins/{slug}/` 内容并更新 plugin.json 版本号；
2. `php tools/build-index.php` → 生成 dist zip + index.json（download.url 为占位符）；
3. 在 GitHub 上创建 Release，标题 `{slug}@{version}`，上传 `dist/{slug}-{version}.zip`；
4. 把 index.json 中该插件的 `download.url` 替换为真实 Release 地址（或让 build 脚本读 `release-urls.json` 映射表自动替换——推荐后者，映射表也提交仓库）；
5. 再跑一次 build（保持 sha256 不变，仅 URL 变）→ `php tools/sign.php <私钥路径>`；
6. 提交 index.json / index.json.sig / dist 产物外的源文件（dist 与私钥不入库，dist 建议 .gitignore）；
7. 记录新 commit SHA，站点端的索引 URL 更新为 jsDelivr 新 commit 地址（或推进 `latest` 分支）。

## 6. GitHub Actions 自动发布（可选草案）

`.github/workflows/release.yml`：打 `{slug}@{version}` 形式 tag 时自动 build、创建 Release、上传 zip、签名并回写 index.json（私钥放 Secrets.MARKET_SIGN_KEY）。注意：CI 内签名意味着私钥进 Secrets，安全性低于纯本机签名，团队小建议先用 §5 手工流程。

```yaml
name: build-release
on:
  push:
    tags: ['*@*']
jobs:
  release:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.2', extensions: zip }
      - name: Build index
        run: php tools/build-index.php
      - name: Sign index
        env: { MARKET_SIGN_KEY: '${{ secrets.MARKET_SIGN_KEY }}' }
        run: |
          echo "$MARKET_SIGN_KEY" > /tmp/key.pem
          php tools/sign.php /tmp/key.pem
      - name: Upload release
        uses: softprops/action-gh-release@v2
        with:
          files: dist/*.zip
      - name: Commit index
        run: |
          git config user.name ci && git config user.email ci@local
          git add index.json index.json.sig revoked.json revoked.json.sig
          git commit -m "chore: rebuild market index" && git push || true
```

---

## 7. 站点端接入（对照本仓库实现）

1. 后台「设置 → 更新」里填 `plugin_marketplace_index_url` = `https://cdn.jsdelivr.net/gh/odi0011/Plugin@<commit>/index.json`，粘贴 `index.json.pub` 内容到公钥设置（或直接用核心内置公钥）。
2. 后台「插件 → 推荐」即市场页，可浏览/搜索/一键安装；「检查更新」批量比对索引版本。
3. 验证要点：索引签名失败时市场必须只读降级且提示"市场源不可信"；安装必须走 sha256 校验。
