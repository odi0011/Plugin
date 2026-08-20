# 预设组件

内置常用前台组件库。激活后在后台侧边栏出现「预设组件」，可浏览、搜索、筛选、预览组件源码，并一键写入系统的 `frontend_components` 表。

## 功能

- 主页：查看组件总量、已使用、已启用、可应用目标概览
- 全部组件：支持分类、状态、目标和关键词筛选
- 详情页：查看 HTML / CSS / JS、默认参数、实时预览
- 使用 / 禁用：把预设组件创建为系统组件，或把对应系统组件停用
- 实质应用：把 `<{brand.tag_prefix}-xxx />` 调用标签追加或前置到页面、文章、产品、系统模板 HTML 中

插件不会在停用时删除已经创建的系统组件，方便继续在「系统模板 → 组件管理」中维护。

## 钩子

- `preset_components.enabled`：预设组件被启用并同步到系统组件后触发，参数为 `$preset, $componentId`
- `preset_components.disabled`：预设组件对应的系统组件被禁用后触发，参数为 `$preset`
- `preset_components.applied`：调用标签被写入页面、文章、产品或系统模板后触发，参数为 `$preset, $result`
