<?php
/**
 * 可视化编辑器：编辑器界面自己的传输层。
 *
 * 两个端点都走后台 POST + 会话 + CSRF，而不是公开扩展 API：一棵编辑树远超扩展 API
 * 的单参数 4KB / 响应字符串 4KB 上限（见 PluginApiRouteService::boundedData）。
 * 公开 API 面因此保持只读摘要（plugin.json api 段）。
 *
 *   POST /admin/visual-editor/convert  打开编辑器：拿到树 + 首屏画布
 *   POST /admin/visual-editor/save     保存前：把树落进插件存储
 *
 * 「保存」为什么要单独一个端点：核心字段由原表单提交写入（这样修订 / 排期 / 审批
 * 全都照常生效），但字段里现在只有渲染产物，树得有地方去。编辑器在提交表单之前
 * 先打这个端点把树存好，再让表单照常提交。
 */
if (!defined('CODE_SCHEMA_VERSION')) exit;

final class VisualEditorAdminTransport
{
    /**
     * POST /admin/visual-editor/convert
     *
     * 把一个内容条目的当前状态变成可编辑的树并**只读地**返回——不写内容表。
     * 首次编辑时这里最慢（要把整段 HTML 解析成树），所以界面在加载动画上给了提示。
     *
     * 唯一的写入是首次导入时把树与原文备份落进插件存储：那不是用户内容，
     * 而是插件自己的工作副本，早写晚写都不影响核心数据。
     */
    public static function convert(): void
    {
        $source = self::guard('visual_editor.edit');

        $field = VisualEditorContent::loadField($source);
        if ($field === null) {
            self::json(['ok' => false, 'message' => '内容不存在或已被删除'], 404);
        }

        $resolved = VisualEditorContent::resolveTree($source, $field, (string)($_POST['reimport'] ?? '') === '1');
        $tree = $resolved['tree'];
        if ($resolved['imported']) {
            // 首次接管：把原文整段留档，用户之后随时可以还原回来。
            VisualEditorStore::save($source['key'], $tree, VisualEditorRenderer::render($source['key'], $tree, false), $field);
        }

        $sections = is_array($tree['sections'] ?? null) ? count($tree['sections']) : 0;
        self::json([
            'ok' => true,
            'message' => ($resolved['imported'] ? '已导入 ' : '已载入 ') . $sections . ' 个区块',
            'data' => [
                'source_key' => $source['key'],
                'tree' => $tree,
                'imported' => $resolved['imported'],
                'stale' => $resolved['stale'],
                'has_original' => VisualEditorStore::original($source['key']) !== null,
                // 画布初始 HTML/CSS 一并由服务端渲染，客户端不重复实现首屏渲染。
                'canvas_html' => VisualEditorRenderer::render($source['key'], $tree, true),
                'canvas_css' => VisualEditorStyleCompiler::compile($source['key'], $tree),
            ],
        ], 200);
    }

    /**
     * POST /admin/visual-editor/save
     *
     * 落存编辑树，并把**服务端重新渲染**的产物回给编辑器写进表单字段。
     *
     * 关键在「服务端重新渲染」：客户端发上来的只有树，HTML 与 CSS 一律由 PHP 侧的
     * Renderer / StyleCompiler 生成。这样即使有人绕过界面直接构造请求，最终进入
     * 内容字段的也只有白名单允许的标签与声明——normalize() 先把树本身收拾干净。
     */
    public static function save(): void
    {
        $source = self::guard('visual_editor.edit');
        $tree = self::readTree();
        $rendered = VisualEditorRenderer::render($source['key'], $tree, false);
        $css = VisualEditorStyleCompiler::compile($source['key'], $tree);

        // 原文备份只在存储里还没有备份时写入；此处传入当前字段值，
        // Store::save() 自己判断要不要收（已有备份就不动，避免用渲染产物覆盖原文）。
        $current = VisualEditorContent::loadField($source);
        if (!VisualEditorStore::save($source['key'], $tree, $rendered, $current)) {
            self::json(['ok' => false, 'message' => '插件存储写入失败，请检查 storage 目录权限'], 500);
        }

        self::json([
            'ok' => true,
            'message' => '文档已保存，提交表单后生效',
            'data' => [
                'source_key' => $source['key'],
                'block' => VisualEditorContent::wrap($rendered, $css),
                'canvas_html' => VisualEditorRenderer::render($source['key'], $tree, true),
                'canvas_css' => $css,
            ],
        ], 200);
    }

    /**
     * POST /admin/visual-editor/persist
     *
     * 落存编辑树，并**直接把渲染产物写进内容字段**——走核心 ContentWorkflow，
     * 所以行锁、乐观版本、完整快照、修订与审计一条都不少。
     *
     * 为什么要有这条路：编辑器原本让核心表单自己提交（最保守的做法），但那意味着
     * 浏览器要把编译好的 HTML 与 `<style>` 塞在 POST 体里发一遍。站点前面的安全层
     * （WAF / mod_security 之类）见到请求体里的 `<style` / `<script` 形状就整条拦掉，
     * 返回裸的 403 Forbidden——请求连 PHP 都没进。产物本来就是服务端渲染的，
     * 让它在服务端原地入库，浏览器只上传一棵 JSON 树（还是 base64 的），
     * 这类规则就无从触发。
     *
     * 状态一律不动：这里只改内容字段，草稿仍是草稿，已发布仍是已发布。
     * 但核心「改非草稿内容要 *.publish 权限」的规矩必须照抄，
     * 否则这条路就成了绕过核心权限的后门。
     */
    public static function persist(): void
    {
        $source = self::guard('visual_editor.edit');
        // 核心的内容编辑权限必须另外过一遍：只有 visual_editor.edit 不足以改内容。
        $permissions = self::corePermissions($source['type']);
        if (!\App\Core\Auth::can($permissions['edit'])) {
            self::json(['ok' => false, 'message' => '缺少 ' . $permissions['edit'] . ' 权限'], 403);
        }

        $tree = self::readTree();
        $rendered = VisualEditorRenderer::render($source['key'], $tree, false);
        $css = VisualEditorStyleCompiler::compile($source['key'], $tree);
        $block = VisualEditorContent::wrap($rendered, $css);

        $current = VisualEditorContent::loadField($source);
        if ($current === null) {
            self::json(['ok' => false, 'message' => '内容不存在或已被删除'], 404);
        }
        if (!VisualEditorStore::save($source['key'], $tree, $rendered, $current)) {
            self::json(['ok' => false, 'message' => '插件存储写入失败，请检查 storage 目录权限'], 500);
        }
        // 产物与字段现值是否真的不同：ContentWorkflow 报「没有变化」时，
        // 这个标记能区分「本来就一样」和「写入没生效」——差别很大，
        // 前者是正常的空操作，后者是必须查的 bug。
        $identical = $current === $block;
        $written = 0;

        try {
            $result = \App\Core\ContentWorkflow::mutate(
                $source['type'],
                $source['id'],
                static function (array $locked) use ($source, $block, $permissions, &$written): void {
                    if ((int)($locked['status'] ?? 0) !== \App\Core\ContentWorkflow::DRAFT
                        && !\App\Core\Auth::can($permissions['publish'])) {
                        throw new \DomainException('修改非草稿内容需要 ' . $permissions['publish'] . ' 权限');
                    }
                    $written = \App\Core\Database::table($source['table'])
                        ->where('id', $source['id'])
                        ->update([
                            $source['field'] => $block,
                            'updated_at' => date('Y-m-d H:i:s'),
                        ]);
                },
                self::workflowContext($source['type'])
            );
        } catch (\DomainException $error) {
            self::json(['ok' => false, 'message' => $error->getMessage()], 403);
        } catch (\RuntimeException $error) {
            $status = (int)$error->getCode() === 409 ? 409 : 500;
            self::json([
                'ok' => false,
                'message' => $status === 409
                    ? '这条内容已被其他会话更新，请刷新页面后重新编辑'
                    : ('保存失败：' . mb_substr($error->getMessage(), 0, 120)),
            ], $status);
        } catch (\Throwable $error) {
            self::json(['ok' => false, 'message' => '保存失败：' . mb_substr($error->getMessage(), 0, 120)], 500);
        }

        $changed = !empty($result['changed']);
        if ($changed) {
            $message = '已保存到这条内容';
        } elseif ($identical) {
            $message = '内容与上次保存完全相同，无需入库';
        } else {
            // 产物变了却没入库：只可能是写入没落到这一行上，如实报出来，
            // 别用一句「内容没有变化」把真正的故障盖过去。
            $message = '写入没有生效（更新了 ' . $written . ' 行），请把这句话反馈给开发者';
        }

        self::json([
            'ok' => true,
            'message' => $message,
            'data' => [
                'source_key' => $source['key'],
                'changed' => $changed,
                'identical' => $identical,
                'written' => $written,
                'revision_id' => $result['revision_id'] ?? null,
                'block' => $block,
                'canvas_html' => VisualEditorRenderer::render($source['key'], $tree, true),
                'canvas_css' => $css,
            ],
        ], 200);
    }

    /**
     * 读取并规范化上传的编辑树。
     *
     * 优先认 tree_b64（base64 的 JSON）：树里可能夹着自定义 HTML 控件的原始标签，
     * 明文发上来会被前置安全层按「请求体里有 HTML」拦掉。base64 只是换个编码，
     * 校验一点没少——解码后照样走 normalize()。
     *
     * @return array<string,mixed>
     */
    private static function readTree(): array
    {
        $raw = (string)($_POST['tree'] ?? '');
        $encoded = (string)($_POST['tree_b64'] ?? '');
        if ($encoded !== '') {
            if (strlen($encoded) > VisualEditorSchema::MAX_DOC_BYTES * 2) {
                self::json(['ok' => false, 'message' => '文档过大，无法保存'], 413);
            }
            $decodedRaw = base64_decode(strtr($encoded, '-_', '+/'), true);
            if ($decodedRaw === false) {
                self::json(['ok' => false, 'message' => '文档编码不正确'], 422);
            }
            $raw = $decodedRaw;
        }
        if ($raw === '' || strlen($raw) > VisualEditorSchema::MAX_DOC_BYTES) {
            self::json(['ok' => false, 'message' => '文档过大或为空，无法保存'], 413);
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            self::json(['ok' => false, 'message' => '文档格式不正确'], 422);
        }
        return VisualEditorDocumentShape::normalize($decoded, \App\Core\Auth::can('visual_editor.code'));
    }

    /**
     * 源类型对应的核心内容权限。自定义内容类型统一走 content.*，
     * 与核心 ContentEntryController 一致。
     *
     * @return array{edit:string,publish:string}
     */
    private static function corePermissions(string $type): array
    {
        switch ($type) {
            case 'page':
                return ['edit' => 'page.edit', 'publish' => 'page.publish'];
            case 'article':
                return ['edit' => 'article.edit', 'publish' => 'article.publish'];
            case 'product':
                return ['edit' => 'product.edit', 'publish' => 'product.publish'];
            default:
                return ['edit' => 'content.edit', 'publish' => 'content.publish'];
        }
    }

    /** @return array<string,mixed> */
    private static function workflowContext(string $type): array
    {
        return [
            'operation' => 'update',
            'action' => $type . '.update',
            'actor_type' => 'user',
            'actor_id' => \App\Core\Auth::id(),
            // source 写插件自己：审计日志里能一眼看出这次改动来自可视化编辑器。
            'source' => 'plugin:visual-editor',
            'request_id' => defined('APP_REQUEST_ID') ? (string)constant('APP_REQUEST_ID') : '',
            'summary' => 'Update ' . $type . ' content via visual editor',
            'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ];
    }

    /**
     * POST /admin/visual-editor/restore
     *
     * 还原到首次接管前的原始内容。同样只读地返回，真正写字段由表单提交完成——
     * 「不影响原内容」这条承诺要能被用户亲手验证，否则只是句空话。
     */
    public static function restore(): void
    {
        $source = self::guard('visual_editor.edit');
        $original = VisualEditorStore::original($source['key']);
        if ($original === null) {
            self::json(['ok' => false, 'message' => '没有找到原始内容备份'], 404);
        }
        self::json([
            'ok' => true,
            'message' => '已取出原始内容，提交表单后生效',
            'data' => ['content' => $original],
        ], 200);
    }

    /**
     * 三道闸门 + 源解析，三个端点共用。任一不过就直接结束请求。
     *
     * @return array{type:string,id:int,table:string,field:string,content_type:?string,key:string}
     */
    private static function guard(string $permission): array
    {
        if (!\App\Core\Auth::isAdmin()) {
            self::json(['ok' => false, 'message' => '只有管理员能使用可视化编辑'], 403);
        }
        // 核心 Router 已经用 _csrf 校验过一遍（不通过连这里都到不了），
        // 这里再自查一次：端点将来若被别的入口调用，校验不能只靠上游。
        $token = (string)($_POST['_csrf'] ?? ($_POST['csrf_token'] ?? ''));
        if (!\App\Core\Csrf::verify($token)) {
            self::json(['ok' => false, 'message' => 'CSRF 校验失败，请刷新页面重试'], 419);
        }
        if (!\App\Core\Auth::can($permission)) {
            self::json(['ok' => false, 'message' => '缺少 ' . $permission . ' 权限'], 403);
        }
        $source = VisualEditorContent::source(
            (string)($_POST['source_type'] ?? ''),
            (int)($_POST['source_id'] ?? 0)
        );
        if ($source === null) {
            self::json(['ok' => false, 'message' => '源类型或 ID 不合法'], 422);
        }
        return $source;
    }

    private static function json(array $payload, int $status): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-store');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        exit;
    }
}
