# Hello World

最简插件示例。

## 功能

激活后，所有文章详情页底部会显示一行 "Hello from plugin!" 横幅。

## 代码

`plugin.php` 仅一行：

```php
add_action('article.show.after', function ($article) {
    echo '<div class="alert alert-info">Hello from plugin!</div>';
});
```

## 用途

- 学习插件最基本的钩子注册方式
- 验证插件系统是否工作正常
