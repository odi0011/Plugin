# AI客服

为 ODCMS 前台提供一个可配置的 AI 客服浮窗。激活后，后台左侧菜单会出现“AI客服”；前台符合显示规则的页面会出现浮标，访客点击后可在悬浮窗口中进行对话。

## 配置范围

- 会话：客服名称、副标题、欢迎语、快捷问题、输入提示、人工客服跳转、会话历史与访客频率限制。
- 显示：桌面/移动端、包含或排除的页面路径、延迟、滚动、离开意图、服务日与服务时间。
- 外观：右下或左下、圆形或胶囊浮标、尺寸、边距、窗口大小、主色、文字色、头像或 Logo。
- AI：使用系统默认对话模型或指定系统模型；也可使用公网 HTTPS 的 OpenAI Chat Completions 兼容接口。
- 知识库：可直接粘贴产品说明、报价规则、服务流程与常见问答；客服角色规则与知识库会作为服务端上下文发送给模型。

显示和触发项覆盖了 Chaty / LiveChat 常见的页面定向、设备定向、延迟、滚动、离开意图、时段与位置配置，但不依赖第三方脚本或账号。

## 使用

1. 从插件市场安装并激活 `AI客服`。
2. 在后台左侧进入“AI客服”。
3. 默认选择“使用系统 AI 配置”，系统需有一个可用的对话模型。
4. 若选择“使用插件独立接口”，填写 HTTPS Chat Completions 地址、模型名称与独立接口密钥。
5. 保存后在前台打开符合页面规则的页面验证挂件。

独立接口密钥只会由“AI客服”后台页面接收。它在写入前通过系统的 `Security::encryptApiKey()` 以 AES-GCM 密封保存，永不回显。

## 数据与安全

- 访客聊天记录仅保存在当前 PHP session，最长保留 6 小时，不建立聊天记录表。
- 访客请求走同源 `POST /ai-customer-service/chat`，需要现有 session 的 CSRF token，并同时按客户端 IP 与会话限流。
- 独立接口只允许公网 HTTPS，调用走核心 `OutboundHttpClient` 的 DNS/IP 校验与响应大小限制。
- 前台脚本用 `textContent` 写入消息，不解释模型或访客文本中的 HTML。
- 系统模型调用复用 `AiService::chat()`，因此继续受系统模型、厂商、超时、重试、限流和请求日志控制。

## API 与 Agent 契约

插件清单声明以下端点：

| Method | Path | Scope | Permission | Agent |
| --- | --- | --- | --- | --- |
| GET | `/api/v1/ext/ai-customer-service/status` | `plugin.ext.read` | `ai_customer_service.view` | 自动派生为 `ext.ai-customer-service.status` |

常规配置字段走核心的声明式插件设置 API：`GET/POST /api/v1/plugin-settings/ai-customer-service`。这些端点同样由核心 API 文档和 Agent 注册表自动发现，并在写入时按 `ai_customer_service.manage` 权限、审批和幂等策略执行。

访客聊天传输与独立接口密钥是有意不进入公开 API / Agent 的两项能力：前者是无 Bearer token 的浏览器会话通道，若开放为 API 会把访客对话错误地绑定到后台账号；后者属于密钥材料，进入 API 返回或 Agent 上下文会违反密钥不外泄边界。`tests/contract.php` 对这两个例外做了机器校验。

## 卸载

停用不会删除配置。卸载会删除 `plugin.ai-customer-service.*` 设置（包括已加密的独立接口密钥）；访客会话本来不落库。
