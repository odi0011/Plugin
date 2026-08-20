# 插件市场协议规范 v1（草案）

> 生成时间：2026-08。本规范定义"插件市场源"的仓库布局、索引 JSON 契约、签名与吊销机制，以及从 GitHub 静态仓库平滑迁移到自建注册表服务的演进路径。
> 站点端实现对应 `app/core/PluginMarketplaceService.php`，本规范是其唯一协议依据。

---

## 1. 总体思路

- **现阶段**：一个公开的 GitHub 仓库（`https://github.com/odi0011/Plugin`）作为市场源。仓库内每个插件一个文件夹（开发主源），构建脚本聚合出全量索引 `index.json`；版本包以 GitHub Releases 附件分发（`{slug}-{version}.zip`）。
- **将来**：自建注册表服务时，产出**同格式**的 `index.json`（`source.type` 从 `github-static` 改为 `registry`）。站点端只改设置里的索引 URL，代码零改动。
- **信任链（三层）**：
  1. `index.json` 整体用 **RSA-SHA256 签名**（openssl 是系统必装扩展，README 已确认；不依赖可选扩展 sodium）；
  2. 每个包的 `download.sha256` 校验下载完整性；
  3. 复用现有 `PluginManager::installFromZip`（扩展名黑名单、大小/文件数限制、备份回滚）。
- **索引 URL 可配置**：站点把索引 URL 改成自己的 fork 或自建服务，即可形成私有市场——这是本方案最关键的迁移能力。

---

## 2. 仓库布局（odi0011/Plugin）

```
├── index.json                  # 全量索引（构建产物，勿手改）
├── index.json.sig              # base64(RSA-SHA256 签名)
├── index.json.pub              # 公钥 PEM（站点首次配置用）
├── revoked.json                # 吊销/下架列表（构建产物）
├── revoked.json.sig            # 吊销列表签名
├── plugins/{slug}/…            # 开发主源：每插件一个文件夹
│   ├── plugin.json             # 与站点插件清单一致（slug/name/version/…）
│   ├── plugin.php              # 插件引导
│   └── icon.svg / README.md …
├── tools/build-index.php       # 扫描 plugins/ → 产出 index.json / revoked.json
├── tools/sign.php              # 本地持私钥签名（私钥绝不入库）
└── .github/workflows/release.yml  # 可选：打 tag 自动打 zip + 发 Release
```

### 版本分发

- 每个版本发一个 GitHub Release，标题 `{slug}@{version}`（例如 `crm-sync@1.2.0`），附件 `{slug}-{version}.zip`。
- `index.json` 中每个插件的 `download.url` 指向 Release 附件下载地址。
- 站点端通过 **jsDelivr 固定 commit** 拉索引以获得缓存与稳定性：
  `https://cdn.jsdelivr.net/gh/odi0011/Plugin@<commit-sha>/index.json`
  更新仓库后必须重新构建并签名 `index.json`，再让站点指向新 commit（或维护一个只推进不回退的 `latest` 分支）。

---

## 3. index.json 契约（v1）

所有 JSON 文件都带 `schema_version`。客户端只接受 `1 ≤ schema_version ≤ 当前核心支持上限`，超出范围直接报"请升级核心"，不允许瞎解析。

```jsonc
{
  "schema_version": 1,
  "generated_at": "2026-08-20T12:00:00Z",
  "source": {
    "type": "github-static",          // 现阶段；将来可为 "registry"
    "repo": "odi0011/Plugin",
    "commit": "abc123…"               // 构建时的 commit，审计溯源用
  },
  "core_api": { "min": 1, "max": 1 }, // 本索引面向的核心插件 API 版本范围
  "plugins": [
    {
      "slug": "crm-sync",             // 必须匹配 ^[a-z0-9][a-z0-9\-_]{0,49}$
      "namespace": "odi0011",         // 发布者命名空间（防抢注，v1 可留空）
      "name": "CRM 同步",
      "version": "1.2.0",             // 严格 semver
      "description": "…",
      "category": "integration",      // 枚举见 §3.2
      "tags": ["crm"],
      "icon": "https://…/plugins/crm-sync/icon.svg",
      "screenshots": [],
      "author": { "name": "odi0011", "url": "" },
      "homepage": "",
      "requires_core": ">=7e",        // 核心 schema 版本约束，见 §3.3
      "requires_php": ">=8.0",
      "api_version": 1,               // 插件 API 契约大版本
      "dependencies": {},             // {slug: 版本约束}，v1 允许为空
      "conflicts": {},                // {slug: 版本约束}，v1 允许为空
      "permissions": [],              // 与 plugin.json 同构的权限声明
      "agent": { "actions": [] },     // 与 plugin.json 同构的 Agent 动作声明
      "published_at": "2026-08-20T12:00:00Z",
      "changelog": "…",
      "download": {
        "url": "https://github.com/odi0011/Plugin/releases/download/crm-sync%401.2.0/crm-sync-1.2.0.zip",
        "sha256": "64 位 hex",
        "size_bytes": 0
      },
      "license": { "type": "free" }   // 预留商业："proprietary" + tiers/price
    }
  ]
}
```

### 3.1 签名

- `index.json.sig` = `base64(openssl_sign(index.json 原始字节, RSA-SHA256, 私钥))`。
- 验签用 `index.json.pub`（PEM 公钥，随仓库公开）或站点内置公钥常量。
- 站点验签失败即**整体弃用该索引**，回退到本地缓存；缓存也不可信时市场功能只读降级（列表清空 + 友好错误）。
- 私钥生成与保管见 `docs/plugin-marketplace-repo-setup.md`：私钥绝不入库，仅存本机或 CI Secrets。

### 3.2 category 枚举（v1）

`integration`（集成）、`content`（内容/编辑增强）、`seo`、`media`、`commerce`、`analytics`、`developer`（开发工具）、`other`。

### 3.3 requires_core 规则（v1 宽松匹配）

- 值为空或缺省 → 视为兼容。
- 值形如 `>=7e`、`7e` → 与站点当前 `CODE_SCHEMA_VERSION` 比较：约束内的版本前缀相等即兼容；站点版本落后于约束时标记"不兼容：需要核心 X"，置灰但可见。
- 严格 semver 化的核心版本管理留待核心版本体系升级后再收紧，协议字段已预留。

### 3.4 requires_php 规则

- 走 `version_compare(PHP_VERSION, 约束)` 标准比较；不满足的插件置灰并显示原因。

---

## 4. revoked.json 契约（v1）

```jsonc
{
  "schema_version": 1,
  "entries": [
    {
      "slug": "bad-plugin",
      "versions": ["<2.0.0"],          // 空数组 = 全部版本
      "reason": "安全公告：远程代码执行",
      "revoked_at": "2026-08-20T12:00:00Z"
    }
  ]
}
```

- 站点定期拉取（与索引同一拉取周期），验签后与本地已装插件比对：命中且已激活的插件**自动停用**并在后台告警。
- 下架（从市场移除但不禁用已装实例）与吊销（强制停用）在 v1 合并为 `revoked.json`，`reason` 区分性质；v2 可拆分为 `removed.json`。

---

## 5. 站点设置键（新增种子）

| 键 | 默认 | 说明 |
|---|---|---|
| `plugin_marketplace_enabled` | `0` | 市场功能总开关 |
| `plugin_marketplace_index_url` | 空 | 索引 URL（可指向 fork / 自建服务） |
| `plugin_marketplace_sig_url` | 空 | 签名 URL；空则取 index_url + `.sig` |
| `plugin_marketplace_public_key` | 空 | 空则用核心内置公钥常量 |
| `plugin_marketplace_auto_check_enabled` | `0` | 自动批量检查更新开关 |
| `plugin_marketplace_auto_check_interval` | `86400` | 间隔（秒），限制 3600~604800 |
| `plugin_marketplace_last_check_at` | 空 | 上次自动检查时间戳 |

---

## 6. 演进预留条款（迁移路径）

1. **schema_version 范围校验**：加字段必须升版本；客户端对未知版本明确报错，保证新旧双方永远能互相探测能力边界。
2. **source.type 抽象**：客户端按类型分支——`github-static`（静态文件 + 旁路签名）与 `registry`（自建 API，同 JSON 格式，可加分页 `next_cursor` 与鉴权头）。切换时站点只改 `plugin_marketplace_index_url` 与内置公钥，其余零改动。
3. **发布者级签名**：v2 起每包可带 `signature: {key_id, sig}` 实现发布者双签（市场签名 + 开发者签名），审计链更强。v1 的客户端应忽略未知字段。
4. **商业字段**：`license.type = proprietary` 时启用 `tiers`（版本/价格）、`require_license_key`；站点端预留 license 设置区，v1 不做。
5. **依赖解析**：v1 展示 `dependencies/conflicts`，安装前做版本约束检查；完整依赖求解器（自动连装依赖）留 v2。
6. **统计与评价**：`downloads/ratings/reviews` 字段预留给 registry 模式，`github-static` 模式不提供。

---

## 7. 客户端行为契约（PluginMarketplaceService 必须满足）

1. 拉索引：仅 HTTPS、禁重定向、SSL 校验开启、响应 ≤ 2MB、**IP 级 SSRF 防护**（解析出的全部 IP 必须为公网地址，参考 `OutboundHttpClient::validatePublicHttpsUrl`）。
2. 缓存：本地文件缓存 TTL 1 小时；强制刷新入口可绕过；拉取失败回退缓存；缓存不存在时市场只读降级。
3. 验签失败：丢弃响应与缓存，视为"市场源不可信"。
4. 安装：`download.url` 必须 HTTPS → 下载 → sha256 校验 → `PluginManager::installFromZip`（内部锁、黑名单、回滚全部复用）。
5. 吊销：命中本地插件即停用 + 记错误日志 + 后台告警；永不自动卸载或删除文件。
