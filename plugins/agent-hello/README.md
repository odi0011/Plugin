# agent-hello — Agent 扩展示例

演示插件如何把能力交给 AI Agent(与 harness 插件机制一致):

| 扩展面 | 钩子 | 本插件示例 |
|---|---|---|
| 可执行动作 | \`agent.actions.register\` | \`stats\`(只读统计) |
| 资源类型 | \`agent.resources.register\` | \`agent_hello.notices\`(可搜索/读取) |
| 委派角色 | \`agent.delegation.roles.register\` | \`hello_auditor\` |
| 生命周期事件 | \`agent.events\` | run 完成后写一条日志 |

## 安全边界(核心强制,插件无法绕过)

1. 动作 id 必须与 \`plugin.json\` 的 \`"agent"."actions"\` 双写一致;
2. 执行器必须是插件目录内的 public static 方法;
3. 权限求交、审批卡、审计、幂等由核心管线完成;
4. 插件停用后动作立即从目录消失,运行中的审批失败关闭。
