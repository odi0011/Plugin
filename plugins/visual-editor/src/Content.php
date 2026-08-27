<?php
/**
 * 可视化编辑器：内容挂接。
 *
 * 1.0.0 把文档存自己的表、接管前台 URL；1.1.0 改成把整个文档（含编辑树）塞进核心
 * 内容字段；1.2.0 在两者之间落点：**渲染产物进核心字段，编辑树与原文备份进插件存储**。
 *
 * 核心内容字段（文章 content / 产品 content / 页面 html / 自定义内容 content）里是：
 *
 *   <!-- ve:managed -->            起始标记
 *   {Renderer 的结构 HTML}
 *   <style data-ve-css>…</style>   StyleCompiler 的作用域 CSS
 *   <!-- /ve:managed -->           结束标记
 *
 * 编辑树与「首次接管前的原始内容」在 VisualEditorStore（STORAGE_PATH 下的 JSON）。
 * 这样安排的直接后果，正是这次重做要满足的几条需求：
 *   - **不影响用户原内容**：原文有一份完整备份，随时可还原；
 *   - **插件停用 / 卸载后页面不出问题**：字段里只是普通 HTML + 一段 CSS，
 *     没有插件私有数据，剥掉两个注释标记就能照常渲染；
 *   - **再次打开还能继续编辑**：树按内容源键（page-12）在存储里一一对应；
 *   - 核心的修订 / 排期 / 审批天然覆盖可视化修改，因为写回走的是原表单提交。
 *
 * 写入路径只有一条：后台表单提交时由编辑器 JS 把编译产物写进核心字段，随核心控制器
 * 入库；同一次提交前编辑器先把树 POST 到 /admin/visual-editor/save 落进插件存储。
 * 插件没有面向外的写入端点（见 plugin.json api 段）。
 */
if (!defined('CODE_SCHEMA_VERSION')) exit;

final class VisualEditorContent
{
    public const MARKER_START = '<!-- ve:managed -->';
    public const MARKER_END = '<!-- /ve:managed -->';

    /**
     * 源类型 => [表, 内容字段]。
     *
     * 与核心 ContentWorkflow::TABLES 同一张映射：page/article/product 各有专表，
     * 其余一律是自定义内容类型，落在 content_entries 并用 content_type 区分。
     *
     * 公开是因为公开 API（Api.php）要按类型取表名；键是类型，值是
     * ['table'=>…, 'field'=>…]，别按位置解构。
     */
    public const SOURCES = [
        'page'    => ['table' => 'pages', 'field' => 'html'],
        'article' => ['table' => 'articles', 'field' => 'content'],
        'product' => ['table' => 'products', 'field' => 'content'],
    ];

    /**
     * 解析源描述。返回 null 表示调用方给的 type 不合法。
     *
     * @return array{type:string,id:int,table:string,field:string,content_type:?string,key:string}|null
     */
    public static function source(string $type, int $id): ?array
    {
        $type = strtolower(trim($type));
        if (!preg_match('/^[a-z][a-z0-9_-]{1,29}$/', $type) || $id < 1) {
            return null;
        }
        if (isset(self::SOURCES[$type])) {
            ['table' => $table, 'field' => $field] = self::SOURCES[$type];
            $contentType = null;
        } else {
            $table = 'content_entries';
            $field = 'content';
            $contentType = $type;
        }
        return [
            'type' => $type,
            'id' => $id,
            'table' => $table,
            'field' => $field,
            'content_type' => $contentType,
            // 形如 page-12 / news-3：CSS 根类名与树脚本标记都用它。
            'key' => $type . '-' . $id,
        ];
    }

    /** 读取核心内容字段的当前值。记录不存在或查询失败返回 null。 */
    public static function loadField(array $source): ?string
    {
        try {
            $query = \App\Core\Database::table($source['table'])->where('id', $source['id']);
            if ($source['content_type'] !== null) {
                $query->where('content_type', $source['content_type']);
            }
            $row = $query->first();
        } catch (\Throwable $_) {
            return null;
        }
        if (!is_array($row)) return null;
        $value = $row[$source['field']] ?? '';
        // pages.content 是历史 NOT NULL 镜像列；两列都在时以新列为准。
        return is_string($value) ? $value : (string)$value;
    }

    // ============================================================
    // 自包含块的拆装
    // ============================================================

    /**
     * 从核心字段值里取出托管块。返回 null 表示这段内容不是可视化托管的。
     *
     * tree 只有 1.1.0 写出的内容才有（那时树内联在块里）；1.2.0 起树在插件存储里，
     * 块内只有 HTML + CSS，此时 tree 为 null。两种都要能读——用户升级插件不该丢文档。
     *
     * @return array{rendered:string,css:string,key:string,tree:?array}|null
     */
    public static function extract(string $html): ?array
    {
        $start = strpos($html, self::MARKER_START);
        $end = strpos($html, self::MARKER_END);
        if ($start === false || $end === false || $end < $start) return null;
        $body = substr($html, $start + strlen(self::MARKER_START), $end - $start - strlen(self::MARKER_START));

        // 结构顺序由 wrap() 保证：结构 HTML、可选的 style、（仅 1.1.0）树脚本。
        // 按位置切片而不是 str_replace，避免内容里碰巧出现相同子串时切错。
        $scriptTag = '<script type="application/json" data-ve-tree="';
        $scriptAt = strpos($body, $scriptTag);
        $limit = $scriptAt === false ? strlen($body) : $scriptAt;

        $styleTag = '<style data-ve-css>';
        $styleAt = strpos($body, $styleTag);
        $css = '';
        if ($styleAt !== false && $styleAt < $limit) {
            $styleEndAt = strpos($body, '</style>', $styleAt);
            if ($styleEndAt === false || $styleEndAt > $limit) return null;
            $css = substr($body, $styleAt + strlen($styleTag), $styleEndAt - $styleAt - strlen($styleTag));
            $rendered = substr($body, 0, $styleAt);
        } else {
            $rendered = substr($body, 0, $limit);
        }

        $key = '';
        $tree = null;
        if ($scriptAt !== false
            && preg_match('/' . preg_quote($scriptTag, '/') . '([^"]*)">(.*?)<\/script>/s', $body, $m)) {
            $decoded = json_decode((string)$m[2], true);
            // 树脚本被人为改坏时当作没有树：让上层退回导入，而不是拿半棵树渲染。
            if (is_array($decoded)) {
                $key = (string)$m[1];
                $tree = $decoded;
            }
        }
        return ['rendered' => trim($rendered), 'css' => trim($css), 'key' => $key, 'tree' => $tree];
    }

    /**
     * 组装自包含块：结构 HTML + 作用域 CSS，就这两样。
     *
     * 1.2.0 起**不再**内联编辑树。核心字段里因此没有任何插件私有数据——
     * 停用或卸载插件后，剥掉两个注释标记就是一段普通的静态 HTML。
     * 树由 VisualEditorStore 另存，见 src/Store.php 的说明。
     */
    public static function wrap(string $renderedHtml, string $css): string
    {
        return self::MARKER_START . "\n"
            . rtrim($renderedHtml) . "\n"
            . ($css !== '' ? '<style data-ve-css>' . $css . '</style>' . "\n" : '')
            . self::MARKER_END;
    }

    /**
     * 打开编辑器时决定「用哪棵树」。优先级依次是：
     *
     *   1. 插件存储里的树，且它记下的渲染哈希与字段当前内容一致——最常见的回访路径；
     *   2. 存储里的树但字段被外部改过：仍然给这棵树，同时把 stale 标出来让界面提示；
     *   3. 字段里内联着 1.1.0 的树：就地迁移进存储，之后走路径 1；
     *   4. 什么都没有：从字段 HTML 导入（首次编辑，耗时的一次）。
     *
     * @return array{tree:array,stale:bool,managed:bool,imported:bool}
     */
    public static function resolveTree(array $source, string $field, bool $force = false): array
    {
        $managed = self::extract($field);
        $stored = VisualEditorStore::load($source['key']);

        // 「重新导入」：明确要求按内容字段的当前样子重新解析，已存的树让位。
        if ($force) {
            return [
                'tree' => VisualEditorDocumentShape::normalize(self::importField($field)),
                'stale' => false,
                'managed' => $managed !== null,
                'imported' => true,
            ];
        }

        if ($stored !== null) {
            $stale = $managed === null
                || !VisualEditorStore::matchesRendered($source['key'], $managed['rendered']);
            return ['tree' => $stored['tree'], 'stale' => $stale, 'managed' => $managed !== null, 'imported' => false];
        }

        if ($managed !== null && is_array($managed['tree'])) {
            // 1.1.0 内联树的迁移：写进存储，并把块内结构 HTML 当作原始内容备份的替身
            // （真正的原文在 1.1.0 那次接管时已经被覆盖，能救的只有这一份）。
            $tree = VisualEditorDocumentShape::normalize($managed['tree']);
            VisualEditorStore::save($source['key'], $tree, $managed['rendered'], $field);
            return ['tree' => $tree, 'stale' => false, 'managed' => true, 'imported' => false];
        }

        return [
            'tree' => VisualEditorDocumentShape::normalize(self::importField($field)),
            'stale' => false,
            'managed' => $managed !== null,
            'imported' => true,
        ];
    }

    /**
     * 一个源当前的托管状态摘要。这是公开 API document-state 的数据来源，
     * 因此刻意全是小整数与布尔值——扩展 API 的响应有界，塞不下树本身。
     *
     * @return array{managed:bool,stale:bool,sections:int,widgets:int,bytes:int}|null
     */
    public static function stateOf(string $type, int $id): ?array
    {
        $source = self::source($type, $id);
        if ($source === null) return null;
        $field = self::loadField($source);
        if ($field === null) return null;

        $managed = self::extract($field);
        if ($managed === null) {
            return ['managed' => false, 'stale' => false, 'sections' => 0, 'widgets' => 0, 'bytes' => strlen($field)];
        }
        // stale 的含义：托管块里的**结构 HTML**与字段当前内容对不上——
        // 说明有人在可视化之外（代码 / 富文本模式）改过这段内容。
        $withoutMarkers = trim(str_replace([self::MARKER_START, self::MARKER_END], '', $field));
        $stale = !str_starts_with($withoutMarkers, $managed['rendered']);

        // 树优先取插件存储；1.1.0 的内联树作为兜底（此时还没迁移过）。
        $stored = VisualEditorStore::tree($source['key']);
        $tree = $stored ?? (is_array($managed['tree']) ? $managed['tree'] : []);
        return [
            'managed' => true,
            'stale' => $stale,
            'sections' => count(is_array($tree['sections'] ?? null) ? $tree['sections'] : []),
            'widgets' => self::countWidgets($tree),
            'bytes' => strlen($field),
        ];
    }

    private static function countWidgets(array $tree): int
    {
        $count = 0;
        foreach ((is_array($tree['sections'] ?? null) ? $tree['sections'] : []) as $section) {
            if (!is_array($section)) continue;
            foreach ((is_array($section['columns'] ?? null) ? $section['columns'] : []) as $column) {
                if (!is_array($column)) continue;
                foreach ((is_array($column['widgets'] ?? null) ? $column['widgets'] : []) as $widget) {
                    if (is_array($widget)) $count++;
                }
            }
        }
        return $count;
    }

    // ============================================================
    // 导入：任意 HTML → 编辑树
    // ============================================================

    /**
     * 把一段 HTML 变成编辑树。两条规则：
     *
     *   1. 认识的标记映射成对应控件（我们自己的托管块按结构还原；普通标题 /
     *      段落 / 图像 / 列表等按语义映射）；
     *   2. **不认识的整块原样放进 html 控件**——不做消毒、不改一个字节。
     *      这是「导入不丢内容」的兜底，也和核心代码模式同权：能进这一步的
     *      都是管理员，管理员本来就能在代码模式里存任意 HTML。
     *
     * @return array{version:int,style:array,sections:list<array>}
     */
    public static function importTree(string $html): array
    {
        $html = self::stripManagedBlock($html);

        $nodes = [];
        if (class_exists('\DOMDocument')) {
            $document = new \DOMDocument('1.0', 'UTF-8');
            $previous = libxml_use_internal_errors(true);
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8"?><div id="ve-import-root">' . $html . '</div>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
            );
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            $root = $loaded ? $document->getElementById('ve-import-root') : null;
            if ($root instanceof \DOMElement) {
                foreach (iterator_to_array($root->childNodes) as $child) {
                    $markup = trim($document->saveHTML($child));
                    if ($markup === '') continue;
                    $nodes[] = [$child, $markup];
                }
            }
        }
        if ($nodes === []) {
            // DOM 扩展缺失或内容为空：整段当一个 html 控件，绝不静默清空。
            $trimmed = trim($html);
            return $trimmed === ''
                ? VisualEditorDocumentShape::emptyTree()
                : VisualEditorDocumentShape::treeWithSections([
                    VisualEditorDocumentShape::singleWidgetSection('html', ['html' => $trimmed]),
                ]);
        }

        // 我们自己的旧托管块（无树脚本或树损坏）：按结构还原成 section/column/widget。
        $sections = [];
        $pendingRaw = '';
        $flushRaw = static function () use (&$pendingRaw, &$sections): void {
            $chunk = trim($pendingRaw);
            $pendingRaw = '';
            if ($chunk === '') return;
            $sections[] = VisualEditorDocumentShape::singleWidgetSection('html', ['html' => $chunk]);
        };

        foreach ($nodes as [$node, $markup]) {
            if (!$node instanceof \DOMElement) {
                $text = trim((string)$node->textContent);
                if ($text !== '') $pendingRaw .= "\n" . $markup;
                continue;
            }
            if (self::hasClass($node, 've-doc')) {
                $flushRaw();
                foreach (self::reconstructVeDoc($node) as $section) {
                    $sections[] = $section;
                }
                continue;
            }
            $mapped = self::mapForeignElement($node, $markup);
            if ($mapped === null) {
                $pendingRaw .= "\n" . $markup;
                continue;
            }
            $flushRaw();
            $sections[] = VisualEditorDocumentShape::singleWidgetSection($mapped['type'], $mapped['content']);
        }
        $flushRaw();

        return self::capSections($sections);
    }

    /**
     * 字段值 → 编辑树，是 convert 端点（重新导入）该用的入口，与 importTree()
     * 的差别在**托管块的命运**：importTree 会把整块剥掉再解析，直接喂字段值
     * 会把已托管的区块连同树一起扔掉——「在代码模式里追加了一段然后点重新导入」
     * 就变成只导入那一段。这里分三种情况：
     *
     *   - 树完好：托管部分沿用原树，只把块外的外围内容导入后拼到后面；
     *   - 树损坏但标记还在：保留块内的结构 HTML（剥掉样式 / 树脚本），按标记
     *     还原本来的区块——渲染层结构还在，就能救回来；
     *   - 没有托管块：等同 importTree()。
     */
    public static function importField(string $field): array
    {
        $managed = self::extract($field);
        if ($managed !== null && is_array($managed['tree'])) {
            $outer = trim(self::stripManagedBlock($field));
            if ($outer === '') return $managed['tree'];
            return self::capSections(array_merge(
                self::treeSections($managed['tree']),
                self::treeSections(self::importTree($outer))
            ));
        }
        if (str_contains($field, self::MARKER_START) && str_contains($field, self::MARKER_END)) {
            // 块内没有可用的树（1.2.0 的块本来就没有，或 1.1.0 的树脚本坏了）：
            // 块里的结构 HTML 仍是 Renderer 的产物，剥掉附属标签后 reconstructVeDoc 能还原成区块。
            $start = strpos($field, self::MARKER_START);
            $end = strpos($field, self::MARKER_END);
            $body = substr($field, $start + strlen(self::MARKER_START), $end - $start - strlen(self::MARKER_START));
            $body = (string)preg_replace('/<style data-ve-css>.*?<\/style>/s', '', $body);
            $body = (string)preg_replace('/<script type="application\/json" data-ve-tree=.*?<\/script>/s', '', $body);
            $field = substr($field, 0, $start) . $body . substr($field, $end + strlen(self::MARKER_END));
        }
        return self::importTree($field);
    }

    /** @param list<array> $sections */
    private static function capSections(array $sections): array
    {
        // 超出规模上限的部分合并回一个 html 控件：宁可多一块原始 HTML，也不丢内容。
        // 合并块用渲染器序列化——控件内容字段各不相同（text / html / items），
        // 挑单个字段拼回去必然漏。
        if (count($sections) > VisualEditorSchema::MAX_SECTIONS) {
            $overflow = array_slice($sections, VisualEditorSchema::MAX_SECTIONS - 1);
            $sections = array_slice($sections, 0, VisualEditorSchema::MAX_SECTIONS - 1);
            $raw = '';
            foreach ($overflow as $section) {
                $raw .= "\n" . trim(VisualEditorRenderer::render(
                    'import-overflow',
                    VisualEditorDocumentShape::treeWithSections([$section]),
                    false
                ));
            }
            $sections[] = VisualEditorDocumentShape::singleWidgetSection('html', ['html' => trim($raw)]);
        }
        if ($sections === []) return VisualEditorDocumentShape::emptyTree();
        return VisualEditorDocumentShape::treeWithSections($sections);
    }

    /** @return list<array> */
    private static function treeSections(array $tree): array
    {
        $sections = is_array($tree['sections'] ?? null) ? $tree['sections'] : [];
        return array_values(array_filter($sections, 'is_array'));
    }

    /** 去掉旧的托管块（含标记本身），只留外围内容。 */
    public static function stripManagedBlock(string $html): string
    {
        $start = strpos($html, self::MARKER_START);
        $end = strpos($html, self::MARKER_END);
        if ($start === false || $end === false || $end < $start) return $html;
        return substr($html, 0, $start) . substr($html, $end + strlen(self::MARKER_END));
    }

    /** 把 Renderer 生成的 ve-doc 结构还原为 section 列表。 */
    private static function reconstructVeDoc(\DOMElement $docNode): array
    {
        $sections = [];
        foreach (iterator_to_array($docNode->childNodes) as $sectionNode) {
            if (!$sectionNode instanceof \DOMElement || !self::hasClass($sectionNode, 've-section')) continue;
            $layout = self::hasClass($sectionNode, 've-section-full') ? 'full' : 'boxed';
            $sectionId = (string)$sectionNode->getAttribute('data-ve');
            $columns = [];
            foreach (iterator_to_array($sectionNode->childNodes) as $innerNode) {
                if (!$innerNode instanceof \DOMElement || !self::hasClass($innerNode, 've-section-inner')) continue;
                foreach (iterator_to_array($innerNode->childNodes) as $columnNode) {
                    if (!$columnNode instanceof \DOMElement || !self::hasClass($columnNode, 've-column')) continue;
                    $widgets = [];
                    foreach (iterator_to_array($columnNode->childNodes) as $widgetNode) {
                        if (!$widgetNode instanceof \DOMElement) continue;
                        $class = (string)$widgetNode->getAttribute('class');
                        if (!preg_match('/ve-widget-([a-z]+)/', $class, $m)) continue;
                        $parsed = self::parseWidgetBody(strtolower((string)$m[1]), $widgetNode);
                        if ($parsed === null) continue;
                        $widgets[] = VisualEditorDocumentShape::widget((string)$m[1], $parsed);
                    }
                    $widthStyle = (string)$columnNode->getAttribute('data-ve-width');
                    $columns[] = VisualEditorDocumentShape::column(
                        $widgets,
                        $widthStyle !== '' ? self::decodeJsonAttr($widthStyle) : []
                    );
                }
            }
            $sections[] = VisualEditorDocumentShape::section($columns, $layout, $sectionId);
        }
        return $sections;
    }

    /**
     * 外来顶层元素 → 控件。返回 null 表示不认识，交给调用方落进 html 兜底。
     *
     * @return array{type:string,content:array<string,mixed>}|null
     */
    private static function mapForeignElement(\DOMElement $node, string $markup): ?array
    {
        // 先问一句「转成控件会不会丢东西」。会丢就不转——原样落进 html 兜底控件。
        // 1.4.0 之前是先转再消毒，于是 class / id / style 与整段 <style> 全被吃掉，
        // 一篇有主题样式的页面进来就散成裸文本。宁可少一个可视化控件，不可少一个字节。
        if (self::losesFidelity($node)) return null;
        $tag = strtolower($node->tagName);
        switch ($tag) {
            case 'h1': case 'h2': case 'h3': case 'h4': case 'h5': case 'h6':
                return ['type' => 'heading', 'content' => ['text' => trim((string)$node->textContent), 'level' => $tag]];
            case 'p':
                return ['type' => 'text', 'content' => ['html' => $markup]];
            case 'img':
                return ['type' => 'image', 'content' => [
                    'src' => (string)$node->getAttribute('src'),
                    'alt' => (string)$node->getAttribute('alt'),
                ]];
            case 'figure':
                $img = $node->getElementsByTagName('img')->item(0);
                if ($img instanceof \DOMElement) {
                    return ['type' => 'image', 'content' => [
                        'src' => (string)$img->getAttribute('src'),
                        'alt' => (string)$img->getAttribute('alt'),
                    ]];
                }
                return null;
            case 'ul': case 'ol':
                $items = [];
                foreach (iterator_to_array($node->getElementsByTagName('li')) as $li) {
                    $line = trim((string)$li->textContent);
                    if ($line !== '') $items[] = $line;
                }
                if ($items === []) return null;
                return ['type' => 'list', 'content' => [
                    'items' => implode("\n", $items),
                    'marker' => $tag === 'ol' ? 'decimal' : 'disc',
                ]];
            case 'blockquote':
                return ['type' => 'quote', 'content' => ['text' => trim((string)$node->textContent)]];
            case 'hr':
                return ['type' => 'divider', 'content' => []];
            case 'iframe':
                return self::mapEmbed($node);
        }
        // 带内联内容的 div/section：整体当富文本控件，保留内部结构。
        if (in_array($tag, ['div', 'section', 'article'], true)
            && trim((string)$node->textContent) !== ''
            && !self::containsBlockLevelChild($node)) {
            return ['type' => 'text', 'content' => ['html' => $markup]];
        }
        return null;
    }

    /**
     * 转成控件会不会丢东西？
     *
     * 控件的字段与富文本白名单里没有 class / id / style，也没有 <style> / <script>，
     * 所以只要节点树上出现了这些，「映射成控件」就一定是有损的。这时返回 true，
     * 调用方改走原样 HTML 兜底——那条路一个字节都不改写。
     */
    private static function losesFidelity(\DOMElement $node): bool
    {
        // 这些属性在富文本 / 控件字段里都留得住，出现它们不算丢东西。
        static $harmless = ['src', 'alt', 'href', 'title', 'target', 'rel', 'width', 'height',
            'colspan', 'rowspan', 'scope', 'loading', 'cite'];
        // 这些标签在消毒时整棵删除或解开，一旦出现就必须走原样通道。
        static $fatal = ['style', 'script', 'svg', 'math', 'form', 'input', 'button', 'select',
            'textarea', 'video', 'audio', 'canvas', 'template', 'noscript', 'object', 'embed', 'picture', 'source'];

        foreach ($fatal as $tag) {
            if ($node->getElementsByTagName($tag)->length > 0) return true;
        }
        $document = $node->ownerDocument;
        if ($document === null) return false;
        $xpath = new \DOMXPath($document);
        $attributes = $xpath->query('.//@*|./@*', $node);
        if ($attributes === false) return false;
        foreach (iterator_to_array($attributes) as $attribute) {
            $name = strtolower((string)$attribute->nodeName);
            if (!in_array($name, $harmless, true)) return true;
        }
        return false;
    }

    /** iframe src 能认出服务商就转 embed 控件，认不出交给 html 兜底。 */
    private static function mapEmbed(\DOMElement $node): ?array
    {
        $src = (string)$node->getAttribute('src');
        if (preg_match('#youtube(?:-nocookie)?\.com/embed/([A-Za-z0-9_-]+)#', $src, $m)) {
            return ['type' => 'embed', 'content' => ['provider' => 'youtube', 'video_id' => (string)$m[1]]];
        }
        if (preg_match('#player\.vimeo\.com/video/(\d+)#', $src, $m)) {
            return ['type' => 'embed', 'content' => ['provider' => 'vimeo', 'video_id' => (string)$m[1]]];
        }
        if (preg_match('#player\.bilibili\.com/player\.html\?bvid=([A-Za-z0-9]+)#', $src, $m)) {
            return ['type' => 'embed', 'content' => ['provider' => 'bilibili', 'video_id' => (string)$m[1]]];
        }
        return null;
    }

    private static function containsBlockLevelChild(\DOMElement $node): bool
    {
        foreach (['p', 'div', 'ul', 'ol', 'table', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'figure'] as $tag) {
            if ($node->getElementsByTagName($tag)->length > 0) return true;
        }
        return false;
    }

    /**
     * 我们自己的控件标记 → 控件内容。只做温和的反向解析；解析不出来就返回 null，
     * 由调用方把整个元素落进 html 兜底控件。
     *
     * @return array<string,mixed>|null
     */
    private static function parseWidgetBody(string $type, \DOMElement $wrapper): ?array
    {
        switch ($type) {
            case 'heading':
                foreach (iterator_to_array($wrapper->childNodes) as $head) {
                    if ($head instanceof \DOMElement && preg_match('/^h[1-6]$/', strtolower($head->tagName))) {
                        return ['text' => trim((string)$head->textContent), 'level' => strtolower($head->tagName)];
                    }
                }
                return null;
            case 'text':
                foreach (iterator_to_array($wrapper->childNodes) as $body) {
                    if ($body instanceof \DOMElement && self::hasClass($body, 've-text')) {
                        return ['html' => self::innerHTML($body)];
                    }
                }
                return null;
            case 'image':
                $img = $wrapper->getElementsByTagName('img')->item(0);
                if (!$img instanceof \DOMElement) return null;
                $content = ['src' => (string)$img->getAttribute('src'), 'alt' => (string)$img->getAttribute('alt')];
                if (preg_match('/width:\s*(\d+)%/', (string)$img->getAttribute('style'), $m)) {
                    $content['width'] = (int)$m[1];
                }
                return $content;
            case 'button':
                foreach (iterator_to_array($wrapper->getElementsByTagName('a')) as $anchor) {
                    $class = (string)$anchor->getAttribute('class');
                    preg_match('/ve-button-(primary|outline|ghost)/', $class, $variant);
                    preg_match('/ve-button-(sm|md|lg)/', $class, $size);
                    return [
                        'text' => trim((string)$anchor->textContent),
                        'url' => (string)$anchor->getAttribute('href'),
                        'target' => (string)$anchor->getAttribute('target') === '_blank' ? '_blank' : '_self',
                        'variant' => (string)($variant[1] ?? 'primary'),
                        'size' => (string)($size[1] ?? 'md'),
                    ];
                }
                return null;
            case 'list':
                $list = $wrapper->getElementsByTagName('ul')->item(0)
                    ?? $wrapper->getElementsByTagName('ol')->item(0);
                if (!$list instanceof \DOMElement) return null;
                $items = [];
                foreach (iterator_to_array($list->getElementsByTagName('li')) as $li) {
                    $line = trim((string)$li->textContent);
                    if ($line !== '') $items[] = $line;
                }
                if ($items === []) return null;
                preg_match('/ve-list-([a-z]+)/', (string)$list->getAttribute('class'), $marker);
                return ['items' => implode("\n", $items), 'marker' => (string)($marker[1] ?? 'disc')];
            case 'quote':
                $quote = $wrapper->getElementsByTagName('blockquote')->item(0);
                if (!$quote instanceof \DOMElement) return null;
                $cite = $quote->getElementsByTagName('cite')->item(0);
                return [
                    'text' => trim((string)$quote->textContent),
                    'cite' => $cite instanceof \DOMElement ? trim((string)$cite->textContent) : '',
                ];
            case 'divider':
                $hr = $wrapper->getElementsByTagName('hr')->item(0);
                if (!$hr instanceof \DOMElement) return null;
                $style = (string)$hr->getAttribute('style');
                preg_match('/border-top-style:\s*([a-z]+)/', $style, $lineStyle);
                preg_match('/border-top-width:\s*(\d+)/', $style, $thickness);
                return [
                    'style' => (string)($lineStyle[1] ?? 'solid'),
                    'thickness' => (int)($thickness[1] ?? 1),
                ];
            case 'spacer':
                foreach (iterator_to_array($wrapper->childNodes) as $body) {
                    if ($body instanceof \DOMElement && self::hasClass($body, 've-spacer')
                        && preg_match('/height:\s*(\d+)/', (string)$body->getAttribute('style'), $m)) {
                        return ['height' => (int)$m[1]];
                    }
                }
                return null;
            case 'embed':
                $iframe = $wrapper->getElementsByTagName('iframe')->item(0);
                return $iframe instanceof \DOMElement ? self::mapEmbed($iframe) : null;
            case 'html':
                foreach (iterator_to_array($wrapper->childNodes) as $body) {
                    if ($body instanceof \DOMElement && self::hasClass($body, 've-html')) {
                        return ['html' => self::innerHTML($body)];
                    }
                }
                return null;

            // ---- 1.2.0 控件的反向解析 ----
            case 'icon':
                $icon = $wrapper->getElementsByTagName('i')->item(0);
                if (!$icon instanceof \DOMElement) return null;
                return ['name' => self::iconNameOf($icon)] + self::sizeFromStyle($icon, 'size');
            case 'iconbox':
                $icon = $wrapper->getElementsByTagName('i')->item(0);
                $content = [
                    'title' => self::textOfClass($wrapper, 've-iconbox-title'),
                    'text' => self::textOfClass($wrapper, 've-iconbox-text'),
                    'layout' => self::hasDescendantClass($wrapper, 've-iconbox-left') ? 'left' : 'top',
                ];
                if ($icon instanceof \DOMElement) {
                    $content['name'] = self::iconNameOf($icon);
                    $content += self::sizeFromStyle($icon, 'size');
                }
                return $content;
            case 'imagebox':
                $img = $wrapper->getElementsByTagName('img')->item(0);
                return [
                    'src' => $img instanceof \DOMElement ? (string)$img->getAttribute('src') : '',
                    'alt' => $img instanceof \DOMElement ? (string)$img->getAttribute('alt') : '',
                    'title' => self::textOfClass($wrapper, 've-imagebox-title'),
                    'text' => self::textOfClass($wrapper, 've-imagebox-text'),
                ];
            case 'alert':
                $tone = 'info';
                foreach (iterator_to_array($wrapper->getElementsByTagName('div')) as $box) {
                    if ($box instanceof \DOMElement
                        && preg_match('/ve-alert-(info|success|warning|danger)/', (string)$box->getAttribute('class'), $m)) {
                        $tone = (string)$m[1];
                        break;
                    }
                }
                return [
                    'tone' => $tone,
                    'title' => self::textOfClass($wrapper, 've-alert-title'),
                    'text' => self::textOfClass($wrapper, 've-alert-text'),
                ];
            case 'progress':
                $value = null;
                foreach (iterator_to_array($wrapper->getElementsByTagName('div')) as $track) {
                    if ($track instanceof \DOMElement && $track->hasAttribute('aria-valuenow')) {
                        $value = (int)$track->getAttribute('aria-valuenow');
                        break;
                    }
                }
                if ($value === null) return null;
                return [
                    'value' => max(0, min(100, $value)),
                    'label' => self::textOfClass($wrapper, 've-progress-label'),
                    'showvalue' => self::textOfClass($wrapper, 've-progress-value') === '' ? 'no' : 'yes',
                ];
        }
        return null;
    }

    /** 从 <i class="bi bi-star-fill"> 取回图标名（不含 bi- 前缀）。 */
    private static function iconNameOf(\DOMElement $icon): string
    {
        return preg_match('/\bbi-([a-z0-9-]+)/', (string)$icon->getAttribute('class'), $m)
            ? (string)$m[1]
            : '';
    }

    /** 内联 font-size 回读成控件字段。取不到就返回空数组，让默认值生效。 */
    private static function sizeFromStyle(\DOMElement $node, string $field): array
    {
        return preg_match('/font-size:\s*(\d+)/', (string)$node->getAttribute('style'), $m)
            ? [$field => (int)$m[1]]
            : [];
    }

    private static function textOfClass(\DOMElement $wrapper, string $class): string
    {
        foreach (iterator_to_array($wrapper->getElementsByTagName('*')) as $node) {
            if ($node instanceof \DOMElement && self::hasClass($node, $class)) {
                return trim((string)$node->textContent);
            }
        }
        return '';
    }

    private static function hasDescendantClass(\DOMElement $wrapper, string $class): bool
    {
        foreach (iterator_to_array($wrapper->getElementsByTagName('*')) as $node) {
            if ($node instanceof \DOMElement && self::hasClass($node, $class)) return true;
        }
        return false;
    }

    private static function hasClass(\DOMElement $node, string $class): bool
    {
        return in_array($class, explode(' ', (string)$node->getAttribute('class')), true);
    }

    private static function decodeJsonAttr(string $value): array
    {
        $decoded = json_decode(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function innerHTML(\DOMElement $node): string
    {
        $owner = $node->ownerDocument;
        if ($owner === null) return '';
        $out = '';
        foreach (iterator_to_array($node->childNodes) as $child) {
            $out .= $owner->saveHTML($child);
        }
        return trim($out);
    }
}

if (!class_exists('VisualEditorDocumentShape')) {
    /**
     * 树形状构造器：给导入与空文档一个统一的建树入口，保证 version /
     * 断点 style 骨架 / 元素 id 规则只在一处定义。
     */
    final class VisualEditorDocumentShape
    {
        /** @param list<array> $sections */
        public static function treeWithSections(array $sections): array
        {
            return ['version' => VisualEditorSchema::DOC_VERSION, 'style' => self::emptyStyle(), 'sections' => array_values($sections)];
        }

        public static function emptyTree(): array
        {
            return self::treeWithSections([self::section([])]);
        }

        public static function singleWidgetSection(string $type, array $content): array
        {
            return self::section([self::column([self::widget($type, $content)])]);
        }

        /** @param list<array> $columns */
        public static function section(array $columns, string $layout = 'boxed', string $id = ''): array
        {
            // 宽容一点：传入的既可以是已归一化的栏，也可以是裸控件列表。
            // 历史调用方两种都有，静默把后者包成合法栏比渲染出空区块强。
            $normalizedColumns = [];
            foreach ($columns as $column) {
                $normalizedColumns[] = (is_array($column) && array_key_exists('widgets', $column))
                    ? $column
                    : self::column(is_array($column) ? $column : []);
            }
            return [
                'id' => self::validElementId($id) ? $id : self::newId(),
                'layout' => $layout === 'full' ? 'full' : 'boxed',
                'style' => self::emptyStyle(),
                'columns' => $normalizedColumns,
            ];
        }

        /** @param list<array> $widgets */
        public static function column(array $widgets, array $width = []): array
        {
            $normalizedWidth = [];
            foreach (VisualEditorSchema::BREAKPOINTS as $breakpoint) {
                $value = $width[$breakpoint] ?? null;
                $normalizedWidth[$breakpoint] = is_numeric($value) ? max(5, min(100, (int)round((float)$value))) : 100;
            }
            return [
                'id' => self::newId(),
                'width' => $normalizedWidth,
                'style' => self::emptyStyle(),
                'widgets' => array_values(array_filter(array_map(
                    static fn ($widget): ?array => is_array($widget) ? $widget : null,
                    $widgets
                ))),
            ];
        }

        /** @param array<string,mixed> $content */
        public static function widget(string $type, array $content): array
        {
            $definition = VisualEditorSchema::widget($type);
            if ($definition === null) {
                $type = 'html';
                $definition = VisualEditorSchema::widget('html');
            }
            $defaults = is_array($definition['defaults'] ?? null) ? $definition['defaults'] : [];
            $merged = [];
            foreach ($defaults as $field => $defaultValue) {
                $merged[$field] = array_key_exists($field, $content) && $content[$field] !== '' ? $content[$field] : $defaultValue;
            }
            return [
                'id' => self::newId(),
                'type' => $type,
                'content' => $merged,
                'style' => self::emptyStyle(),
            ];
        }

        /** @return array<string,array<string,string>> */
        public static function emptyStyle(): array
        {
            $out = [];
            foreach (VisualEditorSchema::BREAKPOINTS as $breakpoint) {
                $out[$breakpoint] = [];
            }
            return $out;
        }

        public static function newId(): string
        {
            return 've' . bin2hex(random_bytes(5));
        }

        /**
         * 元素 id 规则，渲染 / 编译 / 还原三处共用。长度必须与 newId() 一致：
         * StyleCompiler 靠它决定「这个 id 能不能进选择器」，写错长度会把所有
         * 选择器退化成 :not(*)，整页样式静默消失。
         */
        public static function validElementId(string $id): bool
        {
            return $id !== '' && (bool)preg_match('/^ve[0-9a-f]{10}$/', $id);
        }

        // ============================================================
        // 归一化：不受信任的树 => 合法的树
        // ============================================================

        /**
         * 把任意来源的树（客户端 POST、磁盘上的旧记录）收拾成合法结构。
         *
         * 这是 1.2.0 保存路径的守门人：编辑器把整棵树发上来，服务端**不**信任其中任何
         * 一个字节。每个 id 重新校验、每条样式过白名单、每个控件字段过 Value::field，
         * 校验不过就退回控件默认值而不是原样收下——渲染器对 text / html 是直出的，
         * 消毒只能发生在入库之前。
         *
         * @param bool $allowCode 调用者是否具备 visual_editor.code；否则 html 控件降级为纯文本
         */
        public static function normalize(array $tree, bool $allowCode = true): array
        {
            $budget = VisualEditorSchema::MAX_WIDGETS_TOTAL;
            $sections = [];
            foreach ((is_array($tree['sections'] ?? null) ? $tree['sections'] : []) as $section) {
                if (!is_array($section)) continue;
                if (count($sections) >= VisualEditorSchema::MAX_SECTIONS) break;
                $sections[] = self::normalizeSection($section, $allowCode, $budget);
            }
            if ($sections === []) $sections = [self::section([])];
            return [
                'version' => VisualEditorSchema::DOC_VERSION,
                'style' => self::normalizeStyle($tree['style'] ?? null),
                'sections' => $sections,
            ];
        }

        private static function normalizeSection(array $section, bool $allowCode, int &$budget): array
        {
            $columns = [];
            foreach ((is_array($section['columns'] ?? null) ? $section['columns'] : []) as $column) {
                if (!is_array($column)) continue;
                if (count($columns) >= VisualEditorSchema::MAX_COLUMNS_PER_SECTION) break;
                $columns[] = self::normalizeColumn($column, $allowCode, $budget);
            }
            if ($columns === []) $columns = [self::column([])];
            $id = (string)($section['id'] ?? '');
            $layout = (string)($section['layout'] ?? 'boxed');
            return [
                'id' => self::validElementId($id) ? $id : self::newId(),
                'layout' => in_array($layout, VisualEditorSchema::SECTION_LAYOUTS, true) ? $layout : 'boxed',
                'style' => self::normalizeStyle($section['style'] ?? null),
                'columns' => $columns,
            ];
        }

        private static function normalizeColumn(array $column, bool $allowCode, int &$budget): array
        {
            $widgets = [];
            foreach ((is_array($column['widgets'] ?? null) ? $column['widgets'] : []) as $widget) {
                if (!is_array($widget)) continue;
                if ($budget <= 0 || count($widgets) >= VisualEditorSchema::MAX_WIDGETS_PER_COLUMN) break;
                $normalized = self::normalizeWidget($widget, $allowCode);
                if ($normalized === null) continue;
                $budget--;
                $widgets[] = $normalized;
            }
            $width = [];
            $rawWidth = is_array($column['width'] ?? null) ? $column['width'] : [];
            foreach (VisualEditorSchema::BREAKPOINTS as $breakpoint) {
                $value = $rawWidth[$breakpoint] ?? null;
                $width[$breakpoint] = is_numeric($value) ? max(5, min(100, (int)round((float)$value))) : 100;
            }
            $id = (string)($column['id'] ?? '');
            return [
                'id' => self::validElementId($id) ? $id : self::newId(),
                'width' => $width,
                'style' => self::normalizeStyle($column['style'] ?? null),
                'widgets' => $widgets,
            ];
        }

        private static function normalizeWidget(array $widget, bool $allowCode): ?array
        {
            $type = strtolower(trim((string)($widget['type'] ?? '')));
            $definition = VisualEditorSchema::widget($type);
            // 未知类型直接丢弃：渲染器对它返回空串，留着只是一块看不见的垃圾。
            if ($definition === null) return null;
            $needs = (string)($definition['needs_permission'] ?? '');
            if ($needs === 'visual_editor.code' && !$allowCode) {
                /*
                 * 没有 visual_editor.code 的人不能新增或修改原样 HTML 控件——但
                 * 「不能编辑」不等于「可以替他删掉」。1.4.0 之前这里直接 return null，
                 * 结果是：首次接管把整篇原文落进这个兜底控件，随后一个只有
                 * visual_editor.edit 的账号点一次保存，整篇内容就没了。
                 *
                 * 所以现在降级而不是丢弃：内容过一遍标签白名单（script / style
                 * 会被剥掉，这一步与该账号本来的权限一致），落成一个富文本控件。
                 * 排版可能变样，但一个字都不会少。
                 */
                $raw = '';
                foreach (['html', 'text'] as $candidate) {
                    if (is_string($widget['content'][$candidate] ?? null)) {
                        $raw = (string)$widget['content'][$candidate];
                        break;
                    }
                }
                $safe = VisualEditorValue::sanitizeHtml($raw, VisualEditorValue::richBlockTags());
                if (trim($safe) === '') return null;
                return self::normalizeWidget([
                    'id' => $widget['id'] ?? '',
                    'type' => 'text',
                    'content' => ['html' => $safe],
                    'style' => $widget['style'] ?? null,
                ], $allowCode);
            }

            $incoming = is_array($widget['content'] ?? null) ? $widget['content'] : [];
            $defaults = is_array($definition['defaults'] ?? null) ? $definition['defaults'] : [];
            $content = [];
            foreach ((is_array($definition['fields'] ?? null) ? $definition['fields'] : []) as $field => $spec) {
                $fallback = $defaults[$field] ?? '';
                if (!array_key_exists($field, $incoming)) {
                    $content[$field] = $fallback;
                    continue;
                }
                [$ok, $value] = VisualEditorValue::field((string)$spec, $incoming[$field]);
                $content[$field] = $ok ? $value : $fallback;
            }
            $id = (string)($widget['id'] ?? '');
            return [
                'id' => self::validElementId($id) ? $id : self::newId(),
                'type' => $type,
                'content' => $content,
                'style' => self::normalizeStyle($widget['style'] ?? null),
            ];
        }

        /** 样式骨架永远是三个断点齐全的；每个值都过一遍白名单校验，不合法的键直接不落。 */
        private static function normalizeStyle(mixed $style): array
        {
            $source = is_array($style) ? $style : [];
            $out = [];
            foreach (VisualEditorSchema::BREAKPOINTS as $breakpoint) {
                $out[$breakpoint] = [];
                $values = is_array($source[$breakpoint] ?? null) ? $source[$breakpoint] : [];
                foreach ($values as $property => $value) {
                    $key = strtolower(trim((string)$property));
                    $normalized = VisualEditorValue::style($key, $value);
                    if ($normalized !== null) $out[$breakpoint][$key] = $normalized;
                }
            }
            return $out;
        }
    };
}
