# Plugin（ODCMS 插件市场源）

ODCMS 引擎的公开插件仓库。站点端「插件市场」从这里拉取**签名索引**（`index.json` + `index.json.sig`）浏览与安装插件。

- 协议：见引擎仓库 `docs/plugin-marketplace-spec.md`
- 站点接入：引擎已内置市场地址与验签公钥，无需逐站点或逐插件配置密钥。发布者保管签名私钥；`index.json.pub` 必须与引擎内置公钥一致。

## 目录

```
plugins/{slug}/            每个插件一个文件夹（开发主源）
dist/{slug}-{version}.zip  发布包（构建产物，由 GitHub Releases 分发）
tools/build-index.php      扫描 plugins/ 聚合 index.json
tools/sign.php             对 index.json / revoked.json 签名
tools/verify-signatures.php 用真实 ODCMS 验签实现检查发布工件
tools/verify-signatures-standalone.php 不依赖 ODCMS 的仓库门禁验签
index.json / index.json.sig
revoked.json / revoked.json.sig
index.json.pub             公钥（公开）
```

## 插件列表

| slug | 说明 |
|---|---|
| hello-world | 最小插件示例 |
| analytics-snippet | 前台统计代码片段 |
| search-insights | Google Search Console、GA4、Merchant Center、PageSpeed 与 GEO 数据中心 |
| sitemap-ping | 加密 IndexNow 异步提交与队列状态 |
| redirect-rules | 重定向规则管理 |
| preset-components | 预设组件（内容工作流） |
| visual-editor | 可视化编辑器（拖拽搭页面 + 白名单样式编译 + 三面对等 API） |
| ai-customer-service | 前台 AI 客服浮窗（规则、样式、知识库与模型来源可配置） |
| agent-hello | Agent 扩展示例（动作/资源/角色/事件） |
| agent-task-ledger | Agent 写动作示例（插件迁移 + 台账） |
| agent-content-integrity | Agent 资源类型 + 委派角色示例 |
| agent-run-notifier | Agent 生命周期事件 + 安全外发通知示例 |
| agent-link-auditor | Agent 只读站内死链审查与可检索资源 |
| agent-seo-snapshot | Agent 只读 SEO 有效输出快照 |

## 发布流程

1. 修改 `plugins/{slug}/`，更新 plugin.json 版本号
2. `php tools/build-index.php`（需要 PHP 8.0+ 与 zip 扩展）→ 产出 dist zip 与 index.json
3. 创建 GitHub Release，标题 `{slug}@{version}`，上传对应 dist zip，并把真实下载 URL 回填 index.json 的 `download.url`
4. `php tools/sign.php <私钥路径>` 生成 `.sig`
5. `php tools/verify-signatures-standalone.php`，确认索引、吊销名单及公钥全部通过检查；`.sig` 必须是 Base64 文本，不能直接提交 OpenSSL 产生的二进制签名。
6. 有本地 ODCMS 源码时再运行 `php tools/verify-signatures.php <ODCMS源码目录>` 做核心实现对齐检查；原子提交 JSON 与对应 `.sig`。默认站点索引跟随本仓库 `main`，发布包下载地址使用不可变版本。

每次 PR 与 push 的签名检查只使用本仓库的 `index.json.pub` 和 PHP OpenSSL，不需要私钥或访问私有 CMS 仓库。可运行 `php tools/test-signatures.php` 复核二进制签名、正文篡改、缺失签名与错钥均被拒绝；追加 `<ODCMS源码目录>` 参数还会执行核心实现对齐检查。
