# Agent 运行摘要（agent-run-digest）

监听 Agent 生命周期事件，把每次运行落进插件自建表，提供按天聚合的成功率摘要
——Agent 只读动作 + 后台页面两条读路径。

## 提供什么

- 事件监听 `agent.events`：只处理 `agent.run.completed` / `agent.run.failed`
- 自建表 `plugin_agent_run_events`（迁移 `migrations/001_create.sql`）
- Agent 动作 `plugin.agent-run-digest.digest`（只读，R0），参数 `days`（1–90，默认 7）
- 后台页面 `/admin/agent-run-digest`，支持 7 / 14 / 30 / 90 天切换
- 权限点 `agent_run_digest.view`
- `uninstall.php` 卸载时 DROP 自建表

## 几个刻意的设计

**重复投递不重复计数。** 表上有 `UNIQUE(run_id, event_type)`，写入前也先查一次，
所以同一次运行的同类事件只会留一条。

**监听器绝不影响 Run。** `Hooks` 会吞掉回调抛出的异常，但那样问题就无声无息了。
这里自己 try/catch 并写 `logger()`，既不影响主流程也留下痕迹。

**记录不会无界增长。** 保留 90 天，清理动作按 1% 采样挂在写入路径上——不需要
定时任务，也不会每次写入都跑一次 DELETE。

**表未迁移时明确降级。** 摘要读不到表时返回 `run_digest_table_missing` / 503，
后台页面显示「请重新启用插件以执行迁移」，而不是抛异常。

## 演示了哪些插件 API

- `add_action('agent.events', …)` 生命周期监听（事件在 Run 事务提交后触发，
  监听器不会跑在数据库事务里）
- 插件自建表迁移：只用 `CREATE TABLE IF NOT EXISTS`（插件迁移只接受
  `CREATE TABLE` / `CREATE INDEX` / `ALTER TABLE ADD` 与 DML，`SET NAMES`
  这类语句会被拒绝并导致激活失败）
- `admin.menu.register` 过滤器加菜单、`routes.admin.register` 加后台路由
- 闭包路由必须自己 `Auth::requirePermission()`——它没有 `beforeAction`
- `plugin_view()` 渲染视图并 `extend('admin/views/layouts/main')` 复用后台布局
