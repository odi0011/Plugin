# Agent SEO 体检快照（agent-seo-snapshot）

只读统计页面 / 文章 / 产品的 SEO 字段完整度，并注册一个「SEO 体检」委派角色。

## 提供什么

- Agent 动作 `plugin.agent-seo-snapshot.snapshot`（只读，R0）
  - `type`：`page` / `article` / `product`，数组或逗号分隔，默认全部
  - `limit`：返回「缺得最狠」的条目数，1–50，默认 10
- 委派角色 `agent-seo-snapshot.seo_snapshot`，主 Agent 可用它派子智能体做只读分析
- 权限点 `agent_seo_snapshot.view`

## 体检哪些字段

`seo_title`、`seo_description`、`seo_keywords`、`og_title`、`og_description`、
`og_image`、`canonical_url`。字段为空串或纯空白都算缺失。

返回三层结果：

- `by_type`：每种类型的行数、完整度百分比、按字段的缺失计数、有缺口的行数
- `worst`：按缺失权重倒序的条目清单（标题、slug、状态、缺哪些字段）
- `truncated`：是否碰到单类型 500 行的取数上限

权重只用于排序：`seo_title` 与 `seo_description` 各 3 分，其余各 1 分，所以
「标题和描述都没写」会排在「只差一个 og_image」前面。

## 演示了哪些插件 API

- `agent_register_action()` 只读动作（`mutates: false` → 不需要审批、不落审计）
- `agent_register_role()` 委派角色注册，与内置的 research / docs / seo / review 合并
- 单类型取数上限 + `truncated` 标记：大列表要么分页、要么明确告诉模型结果被截断，
  不能让一次工具调用变成全表扫描
