# Agent 内链体检（agent-link-auditor）

只读 Agent 动作示例。扫描已发布页面与文章正文里的站内相对链接，找出指向不存在
slug 的死链。全程只读，不建表、不写库。

## 提供什么

- Agent 动作 `plugin.agent-link-auditor.scan`（只读，R0）
  - `limit`：返回的死链条数上限，1–200，默认 50
  - `types`：`page` / `article`，数组或逗号分隔字符串，默认两者都扫
  - `page` / `per_page`：内容游标页与页大小；返回 `next_page` 后可继续扫描
- Agent 资源类型 `agent-link-auditor.broken_links`，可被 `search_resources` /
  `get_resource` 检索
- 权限点 `agent_link_audit.view`

## 判定规则

只看正文里的 `href="/..."` 形式的相对链接。取路径末段作为 slug（兼容
`/post/example` 一类永久链接），依次在已发布的页面、文章、产品和自定义内容中
解析，都查不到才算死链。页面读取 `html`，文章读取 `content`。

以下一律跳过：外链、锚点、`mailto:` / `tel:`、带静态资源扩展名的路径，以及
`admin` / `api` / `assets` / `uploads` / `plugin-asset` / `storage` 前缀。

## 边界

每种内容类型每页最多取 200 行（按 id 倒序）。命中后返回 `next_page`，模型可以
继续读取而不是把截断结果当全量。同一个 slug 的解析结果在单次调用内缓存。

## 三面适用性

这是专门给 Agent 的只读诊断插件，普通后台 UI 和 `/api/v1` 公共路由不适用；资源
搜索/读取通过核心 Agent resource catalog 暴露。`tests/contract.php` 固定这一 N/A
决定，并验证 loader 的稳定 `type/id/title/fields` 合同。

## 演示了哪些插件 API

- `agent_register_action()` + `plugin.json` 的 `agent.actions` 双写校验
- `agent_register_resource()` 与 loader 的两种模式（`search` / `load`）
- 执行器类必须由 `plugin.php` 显式 `require_once`、方法必须 `public static`、
  文件必须位于插件目录内（`PluginManager::agentActionExecutor()` 用反射强制）
