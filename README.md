# Plugin（ODCMS 插件市场源）

ODCMS 引擎的公开插件仓库。站点端「插件市场」从这里拉取**签名索引**（`index.json` + `index.json.sig`）浏览与安装插件。

- 协议：见引擎仓库 `docs/plugin-marketplace-spec.md`
- 站点接入：后台「设置 → 更新」填索引地址与本仓库 `index.json.pub` 公钥（引擎已内置默认公钥与默认地址时无需配置）

## 目录

```
plugins/{slug}/            每个插件一个文件夹（开发主源）
dist/{slug}-{version}.zip  发布包（构建产物，由 GitHub Releases 分发）
tools/build-index.php      扫描 plugins/ 聚合 index.json
tools/sign.php             对 index.json / revoked.json 签名
index.json / index.json.sig
revoked.json / revoked.json.sig
index.json.pub             公钥（公开）
```

## 插件列表

| slug | 说明 |
|---|---|
| hello-world | 最小插件示例 |
| analytics-snippet | 前台统计代码片段 |
| redirect-rules | 重定向规则管理 |
| preset-components | 预设组件（内容工作流） |
| visual-editor | 可视化编辑器（拖拽搭页面 + 白名单样式编译 + 三面对等 API） |
| ai-customer-service | 前台 AI 客服浮窗（规则、样式、知识库与模型来源可配置） |
| agent-hello | Agent 扩展示例（动作/资源/角色/事件） |
| agent-task-ledger | Agent 写动作示例（插件迁移 + 台账） |
| agent-content-integrity | Agent 资源类型 + 委派角色示例 |
| agent-run-notifier | Agent 生命周期事件 + 安全外发通知示例 |

## 发布流程

1. 修改 `plugins/{slug}/`，更新 plugin.json 版本号
2. `php tools/build-index.php`（需要 PHP 8.0+ 与 zip 扩展）→ 产出 dist zip 与 index.json
3. 创建 GitHub Release，标题 `{slug}@{version}`，上传对应 dist zip，并把真实下载 URL 回填 index.json 的 `download.url`
4. `php tools/sign.php <私钥路径>` 生成 `.sig`
5. 提交 index.json 与 `.sig`；站点端索引地址固定到新 commit（jsDelivr `@<commit>`）
