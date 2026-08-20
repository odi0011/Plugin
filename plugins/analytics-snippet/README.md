# Analytics Snippet

把 Google Analytics 跟踪代码注入到所有前台页面。

## 演示能力

- `register_plugin_setting` 注册默认值
- `admin.menu.register` filter 注入侧边栏菜单
- `routes.admin.register` action 注册自己的后台路由（闭包风格）
- `frontend.head` action 注入第三方 JS
- `plugin.activated` 在激活时初始化设置
- `uninstall.php` 卸载时清理设置

## 使用

1. 激活后进「侧边栏 → 站点统计」
2. 填入 GA4 Tracking ID（`G-XXXXXXXXXX`）或旧版 UA ID
3. 保存即可，所有前台页面 `<head>` 自动注入 GA snippet
4. 留空 → 不注入
