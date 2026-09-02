# IndexNow 提交

这是原 `sitemap-ping` 的替代实现。Google 的 sitemap ping 已停用，Bing 的匿名
`/ping?sitemap=` 也已移除；插件不再调用这些端点。

## 工作流

1. 内容发布、更新、下线或自定义内容变更时，先把 URL 放入本地队列。
2. 分类定义变更和没有专用删除钩子的旧路径会触发异步 run。
3. worker 重建 sitemap，比较每个 URL 块的指纹（含 `lastmod` / hreflang），补入
   新增、更新和删除 URL；因此分类项改名或删除也会被周期 diff 捕获。
4. worker 以最多 1,000 条为一批 POST 到 `https://api.indexnow.org/indexnow`。
5. 200/202 标记成功；429、5xx 和传输错误按指数退避重试，永久错误保留为失败记录。

## 安全与隐私

- IndexNow key 在激活时随机生成，使用核心加密信封保存；设置/API/Agent 只返回
  `key_configured` 与公开 `key_location`，不回显 key。
- key 文件路径为 `/indexnow-key.txt`，仅用于搜索引擎所有权验证，并带 `noindex`。
- URL 只接受 HTTPS 且必须与站点 origin 相同；请求均经过核心 SSRF 防护。
- 队列和日志只记录 URL、状态码、计数及固定错误代码，不记录请求 body、key 或响应正文。
- 队列迁移使用核心 `{{prefix}}` 表前缀占位符，fencing token 防止过期 worker 覆盖新结果；
  已激活插件原地更新会先执行新迁移，失败则回滚插件目录与库存。

## 普通后台

后台菜单「IndexNow 提交」展示开关、keyLocation、排队/执行/失败/完成计数，并提供
“立即加入队列”命令；设置页继续管理启用状态和每批上限。API/Agent 与该后台状态使用
同一队列，不在 HTTP 请求内发送外部网络请求。

## API / Agent

- `GET /api/v1/ext/sitemap-ping/status`：查看队列状态。
- `POST /api/v1/ext/sitemap-ping/submit`：排队一次重建与提交；网络工作由 worker 执行。
- 周期处理器 `plugin.sitemap-ping.submit` 与上述权限边界一致。
