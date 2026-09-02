# Agent SEO 体检快照

只读、分页统计页面、文章和产品最终会呈现给搜索引擎的有效 SEO 信号。

## 输入

- `type`: `page` / `article` / `product`，可传数组或逗号分隔值。
- `status`: `published`（默认）、`draft`、`scheduled`、`archived` 或 `all`。
- `page` / `per_page`: 内容页码与每类型页大小，最大 200。
- `limit`: 当前页返回的优先处理条目数，最大 50。

响应中的 `pagination.next_page` 非空时可以继续读取，不再用无法推进的 500 行
截断代替分页。

## 口径

评分使用前台实际 fallback，而不是机械要求每个覆盖字段都单独填写：

- title: `seo_title` -> `title`
- description: `seo_description` -> `summary`
- Open Graph title/description: 显式 OG -> 上述有效 title/description
- Open Graph image: `og_image` -> `cover_image`
- canonical: 显式值 -> `SeoPresenter` 自动解析值

`seo_keywords` 不参与完整度，因为它不构成现代搜索引擎的有效排名或展示信号。

## 三面适用性

这是专门给 Agent 的只读快照插件；普通后台 UI 和 `/api/v1` 公共路由不适用，避免
为只读审查复制另一套控制面。`tests/contract.php` 固定这一 N/A 决定；非公开状态
仍要求对应内容 `*.view` 权限。
