# 阅读时长与字数（reading-time）

在前台文章 / 页面正文末尾附一行「约 N 字 · 预计阅读 M 分钟」，后台附带一份
按字数排序的内容报表。

## 提供什么

- 前台注入：`article.show.after`、`page.show.after`（可分别开关）
- 后台页面 `/admin/reading-time/settings`：每分钟字数、起始字数、按类型开关，
  以及「最短的 50 条」字数报表
- 权限点 `reading_time.config`
- `uninstall.php` 卸载时清掉自己的设置键

## 计数规则

先用正则剥掉 `<script>` / `<style>` 整块，再 `strip_tags()` 并解码实体——不这么做
会把代码和样式算进正文字数。然后：

- 中日韩字符（CJK 统一表意文字、假名、谚文）按**字**计
- 其余按**词**计（支持带撇号和连字符的西文单词）
- 两者相加

每分钟字数默认 300（中文习惯值），英文站点建议调到 200 左右。低于「起始字数」
的内容不显示——给一篇 20 字的公告标「预计阅读 1 分钟」没有意义。

## 报表读什么

文章与页面各取最近 200 条，按字数**升序**排——最短的排最前，因为需要关注的
通常是「内容过于单薄」的那些。报表只读，不改任何内容。

## 演示了哪些插件 API

- 内容详情页钩子 `article.show.after` / `page.show.after`（收到完整记录数组）
- 用 `if (!function_exists(...))` 包裹全局函数并统一前缀，避免与其它插件撞名
  （`plugin.php` 在闭包里执行，但 `function` 声明是全局的）
- `plugin_view()` + `extend('admin/views/layouts/main')` 复用后台布局
- 表单校验失败走 `flash('error')` 并原样返回，成功才落 `set_plugin_setting()`
