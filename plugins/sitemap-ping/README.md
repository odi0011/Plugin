# 站点地图推送（sitemap-ping）

内容发布后自动把 sitemap 地址推送给搜索引擎，并把每次推送结果记入日志。

## 提供什么

- 内容钩子 `page.after_save` / `article.after_save` / `product.after_save`，
  仅当 `status === 'published'` 时触发
- 自建日志表 `plugin_sitemap_pings`（迁移 `migrations/001_create.sql`）
- 后台页面 `/admin/sitemap-ping`：开关、sitemap 地址、端点列表、节流分钟数、
  「立即推送」按钮、最近 30 条推送记录
- 权限点 `sitemap_ping.manage`
- `uninstall.php` 卸载时 DROP 日志表并清设置键

## 端点怎么配

每行一个，最多 5 个，必须是 `https://`。用 `{SITEMAP}` 占位 sitemap 地址
（会做 URL 编码）。默认给了一个：

```
https://www.bing.com/ping?sitemap={SITEMAP}
```

sitemap 地址留空时自动用 `url('/sitemap.xml')` 推断，页面上会显示实际使用的值。

## 三处刻意的约束

**外发只走核心安全通道。** 用 `PluginManager::httpFetchRaw()`：强制 HTTPS、
禁重定向、经 `OutboundHttpClient` 做公网 IP 校验——指向内网地址的端点会被直接
拒绝，插件无法被用来做 SSRF 跳板。

**节流。** 默认 30 分钟内只推一次，批量发布 20 篇文章不会把搜索引擎刷爆。
后台的「立即推送」会忽略节流，方便验证配置。

**日志不会无界增长。** 只留最近 200 条，按 5% 采样清理，不需要定时任务。

## 演示了哪些插件 API

- `{type}.after_save` 内容生命周期钩子（收到 `$id, $input, $isCreate`）
- 在循环里注册多个钩子时用 `use` 捕获类型，避免闭包共享变量
- `PluginManager::httpFetchRaw()` 作为插件唯一被认可的外发出口
- 自建表迁移 + `uninstall.php` 的成对清理
- POST 校验失败走 `flash('error')` 并原样返回，绝不半保存
