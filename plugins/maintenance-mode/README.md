# 维护模式（maintenance-mode）

一键把前台切到维护页并返回 HTTP 503，后台与 API 照常可用。

## 提供什么

- 后台页面 `/admin/maintenance-mode/settings`：开关、标题、说明、预计恢复时间、
  `Retry-After`、放行 IP 列表
- 前台拦截：`app.before_dispatch`（优先级 1，尽量早）
- 权限点 `maintenance.config`
- `uninstall.php` 卸载时清掉自己的设置键

## 什么不会被拦

设计目标是「不会把自己关在门外」，以下一律放行：

- 后台路径（用运行时的 `admin_prefix()` 判断，站点改过后台前缀也有效）
- `/api` 与 `/healthz`、`/health`——否则监控和集成方会把维护误判成故障
- `/plugin-asset/`——否则维护页自己的静态资源也加载不出来
- 已登录且拥有 `maintenance.config` 权限的用户访问前台（方便边维护边验收）
- 放行 IP 列表命中的客户端

另外，拦截逻辑自身抛异常时会**放行本次请求**并写 `logger()`，绝不因为维护插件
自己出问题而把站点带下去。

## 维护页为什么不复用前台布局

维护往往正是数据库或模板出问题的时候，而前台布局依赖它们。所以
`views/maintenance.php` 完全自包含：内联样式、无外部资源、带
`<meta name="robots" content="noindex">` 与深色模式适配。

## 演示了哪些插件 API

- `app.before_dispatch` 在路由分发前短路请求（`echo` + `exit`）
- `plugin.activated` 里用 `register_plugin_setting()` 写默认值
- `get_plugin_setting()` / `set_plugin_setting()`（键自动命名空间成
  `plugin.{slug}.{key}`，不会和核心设置撞名）
- `admin.menu.register` 里根据当前状态改菜单文案
- POST 处理里的输入校验：长度上限 + `FILTER_VALIDATE_IP`，失败走 `flash('error')`
- 后台表单必须 `csrf_field()`——`Router` 对非 `/api` 的 POST 强制校验 CSRF
