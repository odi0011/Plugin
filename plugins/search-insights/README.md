# 搜索数据中心（search-insights）

统一连接和审查搜索、访问、页面体验与 GEO 引用数据。

## 能力

- Google OAuth 2.0 Authorization Code + PKCE；client secret、access token、refresh token 均以核心加密 envelope 保存。Merchant Center 需要 `https://www.googleapis.com/auth/content`，增加权限后必须重新授权。
- Search Console Search Analytics、URL Inspection 与 Sitemap submit。
- GA4 Data API `runReport`；按页面和日期保存 users、sessions、pageviews、key events。
- Merchant API `accounts.issues.list` 与 `products.list`；同步账户级和商品级问题、严重度、投放范围、国家及修复文档。
- PageSpeed Insights mobile/desktop 检查；保存性能、可访问性、最佳实践、SEO 与 LCP/CLS/INP/FCP/TBT。
- Bing Webmaster、Bing AI Performance 以及其他导出数据的规范化指标导入。2026-08-31 退役的 SOAP/POX 地址不会被调用；在新版 REST endpoint 合同可公开验证前不伪造直连状态。
- ChatGPT、Gemini、Perplexity、Copilot、Bing AI、Google AI Overview 的 GEO 引用观测与分页报告。
- Google/Bing 站点验证 meta 标签。
- 后台展示 URL Inspection 历史与 Merchant 账户/商品诊断，并支持分页、筛选和有界续跑。
- 每 6 小时通过核心 automation worker 同步 Google 数据。

## 三面对等

普通后台入口为 `/admin/search-insights`。`plugin.json` 的 `api` 段是公共 API、ApiDoc 和 Agent action 的同一声明源，生成的端点位于 `/api/v1/ext/search-insights/*`。

Google OAuth client secret、Bing key 与 PageSpeed key 只允许在管理员后台录入。它们不进入公共 API 或 Agent 参数，因为凭证配置不是模型上下文能力；`tests/contract.php` 固定这项不适用决定。连接状态、同步、报告、URL 检查、Sitemap、Merchant 诊断、PageSpeed、指标导入、GEO 与公开验证 token 均有 API/Agent 对等入口。

## 数据边界

- 不保存 Google/Bing 原始响应，只保存白名单规范化指标。
- 报告按 `page/per_page` 分页；GSC/GA4 单次最多处理 5,000 行并明确返回 `truncated`。服务端保存日期窗口与续跑游标，同一窗口再次同步会继续下一批，完成后清除游标。
- 定时同步默认处理两天前的单日最终数据；GSC/GA4 小分页、Merchant 与 PageSpeed partial response 让每次上游响应保持在核心 1 MiB 安全边界内。
- Merchant 每次最多扫描 100 个商品，并将 Google `nextPageToken` 仅保存在服务端配置中；大型目录由后续同步续跑，完成一轮后才清理不再出现的旧问题。
- 上游主机固定 allowlist，HTTP 走核心 `OutboundHttpClient` 的 HTTPS、公网 IP、DNS 固定、禁重定向和禁环境代理边界。
- URL Inspection、PageSpeed、Sitemap 和 GEO cited URL 只能指向当前站点 origin。

官方接口：

- https://developers.google.com/webmaster-tools/v1/api_reference_index
- https://developers.google.com/analytics/devguides/reporting/data/v1
- https://developers.google.com/speed/docs/insights/v5/get-started
- https://developers.google.com/merchant/api/guides/products/list-products-data-issues
- https://developers.google.com/merchant/api/guides/accounts/view-issues
- https://learn.microsoft.com/en-us/bingwebmaster/
