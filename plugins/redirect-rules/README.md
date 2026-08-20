# Redirect Rules

管理 301/302 重定向规则。

## 演示能力

最全的示例插件，演示：

| 能力 | 实现方式 |
|---|---|
| 自带数据表 | `migrations/001_create.sql`（激活时跑） |
| 自带菜单 | `add_filter('admin.menu.register', ...)` |
| 自带路由 | `add_action('routes.admin.register', ...)` 闭包风格 |
| 自带视图 | `plugin_view('redirect-rules', 'index', ...)` |
| 自带权限点 | `plugin.json` 中 `permissions` 字段声明 |
| 请求拦截 | `add_action('app.before_dispatch', ...)` |
| 卸载清理 | `uninstall.php` 删除自己的表 |

## 使用

1. 激活后进「侧边栏 → 重定向规则」
2. 点「新建规则」：填 `/old-url` → `/new-url`，选 301/302
3. 保存后访问 `/old-url` 立即跳转到 `/new-url`
4. 列表显示命中次数

## 技术细节

- 仅匹配精确路径（不支持通配 / 正则）—— MVP 简化
- 后台路径 `/admin/*` 不参与重定向（避免循环）
- 状态码支持 301 / 302 / 307 / 308
