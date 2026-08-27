# AI客服

为 ODCMS 前台提供一个可配置的 AI 客服浮窗。激活后，后台左侧菜单会出现“AI客服”；前台符合显示规则的页面会出现浮标，访客点击后可在悬浮窗口中进行对话。

## 配置范围

后台配置页拆分为四个独立子页面（不再是单页滚动锚点），每页可单独保存：

- **会话内容**（`/admin/ai-customer-service/conversation`）：客服名称、副标题、欢迎语、快捷问题及其分组标题、输入提示、人工客服跳转、会话历史与访客频率限制。
- **显示与触发**（`/admin/ai-customer-service/trigger`）：桌面/移动端、包含或排除的页面路径、延迟、滚动、离开意图、服务日与服务时间；另有浮标悬停提示、引流气泡、未读红点角标、提醒动画（摆动/弹跳/脉冲）、自动打开及其延迟、“每次会话只打扰一次”的记忆开关，以及隐藏浮标仅保留 `window.AiCustomerService` API 的模式。
- **外观与位置**（`/admin/ai-customer-service/appearance`）：右下或左下、圆形或胶囊浮标、自定义浮标图片、胶囊圆角、尺寸与边距、窗口大小/圆角/投影档位、界面字号；顶栏背景与文字、客服消息气泡、访客消息气泡共 10 个颜色独立可调；头像或 Logo。
- **AI 与知识库**（`/admin/ai-customer-service/ai`）：使用系统默认对话模型或指定系统模型；也可使用公网 HTTPS 的 OpenAI Chat Completions 兼容接口，并配置角色规则、知识库、随机性与 Token 上限。

右侧的前台预览会随左侧任何修改实时刷新（含桌面/移动端切换），所见即所得。

显示和触发项覆盖了 Chaty / LiveChat 常见的页面定向、设备定向、延迟、滚动、离开意图、时段、提醒方式与位置配置，但不依赖第三方脚本或账号。

## 使用

1. 从插件市场安装并激活 `AI客服`。
2. 在后台左侧进入“AI客服”，按四个子页面分别配置。
3. 默认选择“使用系统 AI 配置”，系统需有一个可用的对话模型。
4. 若选择“使用插件独立接口”，填写 HTTPS Chat Completions 地址、模型名称与独立接口密钥。
5. 每个页面单独保存；保存后停留在当前子页面并在右侧核对预览。
6. 前台打开符合页面规则的页面验证挂件。

独立接口密钥只会由“AI客服”后台“AI 与知识库”页接收。它在写入前通过系统的 `Security::encryptApiKey()` 以 AES-GCM 密封保存，永不回显。

## 数据与安全

- 访客聊天记录仅保存在当前 PHP session，最长保留 6 小时，不建立聊天记录表。
- 访客请求走同源 `POST /ai-customer-service/chat`，需要现有 session 的 CSRF token，并同时按客户端 IP 与会话限流。
- 独立接口只允许公网 HTTPS，调用走核心 `OutboundHttpClient` 的 DNS/IP 校验与响应大小限制。
- 前台脚本用 `textContent` 写入消息，不解释模型或访客文本中的 HTML。
- 自动展开等“自动动作”的状态只记在访客自己的 sessionStorage，不上报、不入库。
- 系统模型调用复用 `AiService::chat()`，因此继续受系统模型、厂商、超时、重试、限流和请求日志控制。

## API 与 Agent 契约

插件清单声明以下端点：

| Method | Path | Scope | Permission | Agent |
| --- | --- | --- | --- | --- |
| GET | `/api/v1/ext/ai-customer-service/status` | `plugin.ext.read` | `ai_customer_service.view` | 自动派生为 `ext.ai-customer-service.status` |

常规配置字段走核心的声明式插件设置 API：`GET/POST /api/v1/plugin-settings/ai-customer-service`。这些端点同样由核心 API 文档和 Agent 注册表自动发现，并在写入时按 `ai_customer_service.manage` 权限、审批和幂等策略执行。四个后台子页面只是同一份声明式设置的不同呈现切面：字段仍全部来自 `plugin.json`，逐页保存时由服务端合并成一次完整校验落库，因此 API/Agent 视角的行为不变。

访客聊天传输与独立接口密钥是有意不进入公开 API / Agent 的两项能力：前者是无 Bearer token 的浏览器会话通道，若开放为 API 会把访客对话错误地绑定到后台账号；后者属于密钥材料，进入 API 返回或 Agent 上下文会违反密钥不外泄边界。`tests/contract.php` 对这两个例外做了机器校验。

## 卸载

停用不会删除配置。卸载会删除 `plugin.ai-customer-service.*` 设置（包括已加密的独立接口密钥）；访客会话本来不落库。
