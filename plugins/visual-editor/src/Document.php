<?php
/**
 * 可视化编辑器：文档模型。
 *
 * 表名是**字面量**，不经 Database::table()——插件迁移没有站点表前缀替换，
 * 建出来的就是这个名字，运行时也必须用同一个名字，否则装在带前缀的站点上会查空表。
 *
 * 三条不变量，写入路径全部经过这里：
 *   1. 存进 doc_json 的树一定是 normalize() 的产物：未知键、越界值、超限结构都已被丢掉；
 *   2. 每次改树先写一条修订，因此任何一步都能回滚；
 *   3. 改动带 lock_version 乐观锁，并发保存会 409 而不是互相覆盖。
 */
if (!defined('CODE_SCHEMA_VERSION')) exit;

final class VisualEditorDocument
{
    public const TABLE_DOCUMENTS = 'plugin_visual_documents';
    public const TABLE_REVISIONS = 'plugin_visual_revisions';

    public const STATUSES = ['draft', 'published'];

    /** 生成元素 id。前缀固定，便于在 HTML / CSS 里一眼认出是谁的。 */
    public static function newElementId(): string
    {
        return 've' . bin2hex(random_bytes(5));
    }

    public static function isElementId(string $id): bool
    {
        return (bool)preg_match('/^ve[0-9a-f]{10}$/', $id);
    }

    /** 空文档：一个定宽区块 + 一栏，用户打开编辑器就能直接放控件。 */
    public static function emptyTree(): array
    {
        return [
            'version' => VisualEditorSchema::DOC_VERSION,
            'style' => self::emptyStyle(),
            'sections' => [[
                'id' => self::newElementId(),
                'layout' => 'boxed',
                'style' => self::emptyStyle(),
                'columns' => [[
                    'id' => self::newElementId(),
                    'width' => ['desktop' => 100, 'tablet' => 100, 'mobile' => 100],
                    'style' => self::emptyStyle(),
                    'widgets' => [],
                ]],
            ]],
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

    // ============================================================
    // 归一化：唯一的「什么能存进去」判定点
    // ============================================================

    /**
     * 把任意输入重建成合法文档树。
     *
     * 刻意「重建」而不是「校验后放行」：只要有一个字段是照抄进来的，
     * 就得逐个证明它无害。重建的话，出现在结果里的每个键都是这里显式写下的。
     *
     * @return array{tree:array<string,mixed>,warnings:list<string>}
     */
    public static function normalize(mixed $input): array
    {
        $warnings = [];
        $input = is_array($input) ? $input : [];
        $tree = [
            'version' => VisualEditorSchema::DOC_VERSION,
            'style' => self::normalizeStyle($input['style'] ?? null),
            'sections' => [],
        ];

        $sections = is_array($input['sections'] ?? null) ? $input['sections'] : [];
        $usedIds = [];
        $widgetTotal = 0;
        foreach ($sections as $section) {
            if (count($tree['sections']) >= VisualEditorSchema::MAX_SECTIONS) {
                $warnings[] = '区块数超过上限 ' . VisualEditorSchema::MAX_SECTIONS . '，多余的已丢弃';
                break;
            }
            if (!is_array($section)) continue;

            $layout = strtolower(trim((string)($section['layout'] ?? 'boxed')));
            $normalizedSection = [
                'id' => self::takeId($section['id'] ?? '', $usedIds),
                'layout' => in_array($layout, VisualEditorSchema::SECTION_LAYOUTS, true) ? $layout : 'boxed',
                'style' => self::normalizeStyle($section['style'] ?? null),
                'columns' => [],
            ];

            $columns = is_array($section['columns'] ?? null) ? $section['columns'] : [];
            foreach ($columns as $column) {
                if (count($normalizedSection['columns']) >= VisualEditorSchema::MAX_COLUMNS_PER_SECTION) {
                    $warnings[] = '单个区块的栏数超过上限，多余的已丢弃';
                    break;
                }
                if (!is_array($column)) continue;

                $normalizedColumn = [
                    'id' => self::takeId($column['id'] ?? '', $usedIds),
                    'width' => self::normalizeWidth($column['width'] ?? null),
                    'style' => self::normalizeStyle($column['style'] ?? null),
                    'widgets' => [],
                ];

                $widgets = is_array($column['widgets'] ?? null) ? $column['widgets'] : [];
                foreach ($widgets as $widget) {
                    if (count($normalizedColumn['widgets']) >= VisualEditorSchema::MAX_WIDGETS_PER_COLUMN
                        || $widgetTotal >= VisualEditorSchema::MAX_WIDGETS_TOTAL) {
                        $warnings[] = '控件数超过上限，多余的已丢弃';
                        break;
                    }
                    $normalizedWidget = self::normalizeWidget($widget, $usedIds, $warnings);
                    if ($normalizedWidget === null) continue;
                    $normalizedColumn['widgets'][] = $normalizedWidget;
                    $widgetTotal++;
                }
                $normalizedSection['columns'][] = $normalizedColumn;
            }

            // 没有栏的区块无法承载内容：补一栏而不是丢掉整个区块，
            // 否则用户会以为「保存把我的区块吃了」。
            if ($normalizedSection['columns'] === []) {
                $normalizedSection['columns'][] = [
                    'id' => self::takeId('', $usedIds),
                    'width' => ['desktop' => 100, 'tablet' => 100, 'mobile' => 100],
                    'style' => self::emptyStyle(),
                    'widgets' => [],
                ];
            }
            $tree['sections'][] = $normalizedSection;
        }

        if ($tree['sections'] === []) {
            $tree = self::emptyTree();
        }
        return ['tree' => $tree, 'warnings' => array_values(array_unique($warnings))];
    }

    /** @param array<string,bool> $usedIds */
    private static function normalizeWidget(mixed $widget, array &$usedIds, array &$warnings): ?array
    {
        if (!is_array($widget)) return null;
        $type = strtolower(trim((string)($widget['type'] ?? '')));
        $definition = VisualEditorSchema::widget($type);
        if ($definition === null) {
            $warnings[] = '未知控件类型已丢弃：' . ($type !== '' ? $type : '(空)');
            return null;
        }

        $content = is_array($widget['content'] ?? null) ? $widget['content'] : [];
        $normalizedContent = [];
        foreach ((array)$definition['fields'] as $field => $spec) {
            $raw = array_key_exists($field, $content) ? $content[$field] : ($definition['defaults'][$field] ?? '');
            [$ok, $value] = VisualEditorValue::field((string)$spec, $raw);
            if (!$ok) {
                // 单个字段非法时回落到默认值，而不是丢掉整个控件：
                // 丢控件会让一次误填变成结构性丢失。
                [$okDefault, $defaultValue] = VisualEditorValue::field((string)$spec, $definition['defaults'][$field] ?? '');
                $value = $okDefault ? $defaultValue : '';
                $warnings[] = $type . ' 控件的 ' . $field . ' 值不合法，已回落默认值';
            }
            $normalizedContent[$field] = $value;
        }

        return [
            'id' => self::takeId($widget['id'] ?? '', $usedIds),
            'type' => $type,
            'content' => $normalizedContent,
            'style' => self::normalizeStyle($widget['style'] ?? null),
        ];
    }

    /** @return array<string,array<string,string>> */
    public static function normalizeStyle(mixed $style): array
    {
        $out = self::emptyStyle();
        if (!is_array($style)) return $out;
        foreach (VisualEditorSchema::BREAKPOINTS as $breakpoint) {
            $values = is_array($style[$breakpoint] ?? null) ? $style[$breakpoint] : [];
            foreach ($values as $property => $value) {
                $property = strtolower(trim((string)$property));
                $normalized = VisualEditorValue::style($property, $value);
                if ($normalized !== null) {
                    $out[$breakpoint][$property] = $normalized;
                }
            }
        }
        return $out;
    }

    /** @return array<string,int> */
    private static function normalizeWidth(mixed $width): array
    {
        $out = [];
        $width = is_array($width) ? $width : [];
        foreach (VisualEditorSchema::BREAKPOINTS as $breakpoint) {
            $value = $width[$breakpoint] ?? null;
            $percent = is_numeric($value) ? (int)round((float)$value) : 100;
            $out[$breakpoint] = max(5, min(100, $percent));
        }
        return $out;
    }

    /**
     * 取一个元素 id：形态合法且未被占用就沿用，否则新发一个。
     * 沿用很重要——id 是样式与译文的落点，随手换 id 等于把样式全丢了。
     *
     * @param array<string,bool> $usedIds
     */
    private static function takeId(mixed $candidate, array &$usedIds): string
    {
        $id = is_scalar($candidate) ? strtolower(trim((string)$candidate)) : '';
        if ($id === '' || !self::isElementId($id) || isset($usedIds[$id])) {
            do {
                $id = self::newElementId();
            } while (isset($usedIds[$id]));
        }
        $usedIds[$id] = true;
        return $id;
    }

    // ============================================================
    // 读取
    // ============================================================

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        if ($id <= 0) return null;
        $row = \App\Core\Database::exec(
            'SELECT * FROM `' . self::TABLE_DOCUMENTS . '` WHERE `id` = ? LIMIT 1',
            [$id]
        )->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public static function findBySlug(string $slug, ?string $status = null): ?array
    {
        $slug = self::normalizeSlug($slug);
        if ($slug === '') return null;
        $sql = 'SELECT * FROM `' . self::TABLE_DOCUMENTS . '` WHERE `slug` = ?';
        $params = [$slug];
        if ($status !== null) {
            $sql .= ' AND `status` = ?';
            $params[] = $status;
        }
        $row = \App\Core\Database::exec($sql . ' LIMIT 1', $params)->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * 列表查询。返回的行不含 doc_json：列表页与 API 列表都不需要整棵树，
     * 把它带上只会让响应体无界膨胀。
     *
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public static function paginate(string $status, string $keyword, int $page, int $perPage): array
    {
        $where = [];
        $params = [];
        if (in_array($status, self::STATUSES, true)) {
            $where[] = '`status` = ?';
            $params[] = $status;
        }
        $keyword = trim($keyword);
        if ($keyword !== '') {
            $where[] = '(`title` LIKE ? OR `slug` LIKE ?)';
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $keyword) . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $total = (int)\App\Core\Database::exec(
            'SELECT COUNT(*) AS c FROM `' . self::TABLE_DOCUMENTS . '`' . $clause,
            $params
        )->fetchColumn();

        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);
        $rows = \App\Core\Database::exec(
            'SELECT `id`, `slug`, `title`, `status`, `lock_version`, `updated_by`, `created_at`, `updated_at`'
            . ' FROM `' . self::TABLE_DOCUMENTS . '`' . $clause
            . ' ORDER BY `updated_at` DESC, `id` DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
            $params
        )->fetchAll(\PDO::FETCH_ASSOC);

        return ['rows' => is_array($rows) ? $rows : [], 'total' => $total];
    }

    /** 读出并归一化文档树。存量数据坏掉时回落成空文档，而不是让前台 500。 */
    public static function tree(array $row): array
    {
        $decoded = json_decode((string)($row['doc_json'] ?? ''), true);
        return self::normalize(is_array($decoded) ? $decoded : [])['tree'];
    }

    // ============================================================
    // slug
    // ============================================================

    public static function normalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = (string)preg_replace('/[^a-z0-9\x{4e00}-\x{9fa5}-]+/u', '-', $slug);
        $slug = trim((string)preg_replace('/-{2,}/', '-', $slug), '-');
        return mb_substr($slug, 0, 150);
    }

    /** 从标题派生 slug；重名时追加短随机后缀而不是数字序号（避免并发下撞号重试）。 */
    public static function deriveSlug(string $title, int $excludeId = 0): string
    {
        $base = self::normalizeSlug($title);
        if ($base === '') $base = 'page-' . bin2hex(random_bytes(3));
        $slug = $base;
        for ($attempt = 0; $attempt < 6; $attempt++) {
            if (!self::slugTaken($slug, $excludeId)) return $slug;
            $slug = mb_substr($base, 0, 140) . '-' . bin2hex(random_bytes(2));
        }
        return $base . '-' . bin2hex(random_bytes(4));
    }

    public static function slugTaken(string $slug, int $excludeId = 0): bool
    {
        $slug = self::normalizeSlug($slug);
        if ($slug === '') return true;
        $row = \App\Core\Database::exec(
            'SELECT `id` FROM `' . self::TABLE_DOCUMENTS . '` WHERE `slug` = ? AND `id` <> ? LIMIT 1',
            [$slug, $excludeId]
        )->fetch(\PDO::FETCH_ASSOC);
        return is_array($row);
    }

    /**
     * 核心内容是否已经占用了这个 slug。
     *
     * 前台接管只在「核心没有同名内容」时发生，因此这里是接管的前置条件，
     * 也是新建时的一道提醒：让插件文档和核心页面抢同一个 URL 只会制造歧义。
     */
    public static function coreSlugConflict(string $slug): bool
    {
        $slug = self::normalizeSlug($slug);
        if ($slug === '') return true;
        foreach (['pages', 'articles', 'products'] as $table) {
            try {
                $row = \App\Core\Database::table($table)->where('slug', $slug)->first();
                if (is_array($row) && $row !== []) return true;
            } catch (\Throwable $_) {
                // 表不存在（例如产品模块未启用）不算冲突。
            }
        }
        return false;
    }

    // ============================================================
    // 写入
    // ============================================================

    /**
     * 新建文档。
     *
     * @return array{ok:bool,message:string,status:int,id:int,slug:string}
     */
    public static function create(string $title, string $slug, string $seoTitle, string $seoDescription, int $actorId): array
    {
        $title = VisualEditorValue::plainText($title);
        if ($title === '' || mb_strlen($title) > 200) {
            return self::fail('标题必填，且不超过 200 字', 422);
        }
        $slug = $slug === '' ? self::deriveSlug($title) : self::normalizeSlug($slug);
        if ($slug === '') {
            return self::fail('slug 不合法', 422);
        }
        if (self::slugTaken($slug)) {
            return self::fail('slug 已被其他可视化文档占用：' . $slug, 409);
        }

        $now = date('Y-m-d H:i:s');
        $tree = self::emptyTree();
        \App\Core\Database::exec(
            'INSERT INTO `' . self::TABLE_DOCUMENTS . '`'
            . ' (`slug`, `title`, `status`, `doc_json`, `css_cache`, `seo_title`, `seo_description`,'
            . ' `lock_version`, `created_by`, `updated_by`, `created_at`, `updated_at`)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)',
            [
                $slug,
                $title,
                'draft',
                self::encodeTree($tree),
                VisualEditorStyleCompiler::compile(0, $tree),
                VisualEditorValue::plainText($seoTitle),
                VisualEditorValue::plainText($seoDescription),
                $actorId,
                $actorId,
                $now,
                $now,
            ]
        );
        $id = (int)\App\Core\Database::pdo()->lastInsertId();
        // css_cache 里的选择器带文档 id，插入后才知道 id，因此立刻重编译一次。
        self::writeCssCache($id, $tree);
        return ['ok' => true, 'message' => '已创建', 'status' => 200, 'id' => $id, 'slug' => $slug];
    }

    /**
     * 改元信息（标题 / slug / SEO）。不动文档树。
     *
     * @param array<string,string> $fields 只认 title / slug / seo_title / seo_description
     * @return array{ok:bool,message:string,status:int}
     */
    public static function updateMeta(int $id, array $fields, int $expectedVersion, int $actorId): array
    {
        $row = self::find($id);
        if ($row === null) return self::fail('文档不存在', 404);
        if ($expectedVersion > 0 && (int)$row['lock_version'] !== $expectedVersion) {
            return self::fail('文档已被其他人修改（当前版本 ' . (int)$row['lock_version'] . '），请刷新后重试', 409);
        }

        $updates = [];
        $params = [];
        if (array_key_exists('title', $fields)) {
            $title = VisualEditorValue::plainText((string)$fields['title']);
            if ($title === '' || mb_strlen($title) > 200) return self::fail('标题必填，且不超过 200 字', 422);
            $updates[] = '`title` = ?';
            $params[] = $title;
        }
        if (array_key_exists('slug', $fields)) {
            $slug = self::normalizeSlug((string)$fields['slug']);
            if ($slug === '') return self::fail('slug 不合法', 422);
            if (self::slugTaken($slug, $id)) return self::fail('slug 已被其他可视化文档占用：' . $slug, 409);
            $updates[] = '`slug` = ?';
            $params[] = $slug;
        }
        foreach (['seo_title' => 255, 'seo_description' => 500] as $field => $max) {
            if (!array_key_exists($field, $fields)) continue;
            $value = VisualEditorValue::plainText((string)$fields[$field]);
            if (mb_strlen($value) > $max) return self::fail($field . ' 超过 ' . $max . ' 字', 422);
            $updates[] = '`' . $field . '` = ?';
            $params[] = $value;
        }
        if ($updates === []) return self::fail('没有要修改的字段', 422);

        $params[] = $actorId;
        $params[] = $id;
        \App\Core\Database::exec(
            'UPDATE `' . self::TABLE_DOCUMENTS . '` SET ' . implode(', ', $updates)
            . ', `lock_version` = `lock_version` + 1, `updated_by` = ?, `updated_at` = NOW() WHERE `id` = ?',
            $params
        );
        return ['ok' => true, 'message' => '已保存', 'status' => 200];
    }

    /**
     * 整棵树落盘。所有改结构的操作（编辑器保存、API 增删控件、回滚）最终都走这里。
     *
     * @param array<string,mixed> $tree 已经 normalize 过的树
     * @return array{ok:bool,message:string,status:int,lock_version?:int,warnings?:list<string>}
     */
    public static function saveTree(int $id, array $tree, int $expectedVersion, int $actorId, string $note, bool $canUseCode): array
    {
        $row = self::find($id);
        if ($row === null) return self::fail('文档不存在', 404);
        if ($expectedVersion > 0 && (int)$row['lock_version'] !== $expectedVersion) {
            return self::fail('文档已被其他人修改（当前版本 ' . (int)$row['lock_version'] . '），请刷新后重试', 409);
        }

        $normalized = self::normalize($tree);
        $tree = $normalized['tree'];
        $encoded = self::encodeTree($tree);
        if (strlen($encoded) > VisualEditorSchema::MAX_DOC_BYTES) {
            return self::fail('文档超过 ' . (int)(VisualEditorSchema::MAX_DOC_BYTES / 1024) . 'KB 上限', 422);
        }

        // 自定义 HTML 控件是唯一能带结构标签的入口，因此它的**变化**要单独授权。
        // 检查的是「变化」而不是「存在」：否则降权后连改标题都会被这条挡住。
        $codeError = self::codeWidgetChangeError(self::tree($row), $tree, $canUseCode);
        if ($codeError !== '') return self::fail($codeError, 403);

        self::writeRevision($id, (string)($row['doc_json'] ?? ''), $note, $actorId);
        \App\Core\Database::exec(
            'UPDATE `' . self::TABLE_DOCUMENTS . '` SET `doc_json` = ?, `css_cache` = ?,'
            . ' `lock_version` = `lock_version` + 1, `updated_by` = ?, `updated_at` = NOW() WHERE `id` = ?',
            [$encoded, VisualEditorStyleCompiler::compile($id, $tree), $actorId, $id]
        );
        self::pruneRevisions($id);

        return [
            'ok' => true,
            'message' => '已保存',
            'status' => 200,
            'lock_version' => (int)$row['lock_version'] + 1,
            'warnings' => $normalized['warnings'],
        ];
    }

    /**
     * 发布 / 撤回。
     *
     * @return array{ok:bool,message:string,status:int}
     */
    public static function setStatus(int $id, string $status, int $expectedVersion, int $actorId): array
    {
        $status = strtolower(trim($status));
        if (!in_array($status, self::STATUSES, true)) {
            return self::fail('状态只能是 draft 或 published', 422);
        }
        $row = self::find($id);
        if ($row === null) return self::fail('文档不存在', 404);
        if ($expectedVersion > 0 && (int)$row['lock_version'] !== $expectedVersion) {
            return self::fail('文档已被其他人修改，请刷新后重试', 409);
        }
        if ($status === (string)$row['status']) {
            return ['ok' => true, 'message' => '状态未变化', 'status' => 200];
        }

        if ($status === 'published') {
            $tree = self::tree($row);
            if (self::countWidgets($tree) === 0) {
                return self::fail('空文档不能发布：先放至少一个控件', 422);
            }
            if (self::coreSlugConflict((string)$row['slug'])) {
                return self::fail(
                    '核心内容已占用 /' . (string)$row['slug'] . '，发布后前台仍会显示核心内容。请先改 slug。',
                    409
                );
            }
        }

        \App\Core\Database::exec(
            'UPDATE `' . self::TABLE_DOCUMENTS . '` SET `status` = ?,'
            . ' `lock_version` = `lock_version` + 1, `updated_by` = ?, `updated_at` = NOW() WHERE `id` = ?',
            [$status, $actorId, $id]
        );
        return ['ok' => true, 'message' => $status === 'published' ? '已发布' : '已撤回', 'status' => 200];
    }

    /** @return array{ok:bool,message:string,status:int} */
    public static function delete(int $id, int $expectedVersion): array
    {
        $row = self::find($id);
        if ($row === null) return self::fail('文档不存在', 404);
        if ($expectedVersion > 0 && (int)$row['lock_version'] !== $expectedVersion) {
            return self::fail('文档已被其他人修改，请刷新后重试', 409);
        }
        // 已发布的文档直接删会让线上 URL 突然 404，要求先撤回是刻意的一道确认。
        if ((string)$row['status'] === 'published') {
            return self::fail('请先撤回发布再删除', 409);
        }
        \App\Core\Database::exec('DELETE FROM `' . self::TABLE_REVISIONS . '` WHERE `document_id` = ?', [$id]);
        \App\Core\Database::exec('DELETE FROM `' . self::TABLE_DOCUMENTS . '` WHERE `id` = ?', [$id]);
        return ['ok' => true, 'message' => '已删除', 'status' => 200];
    }

    // ============================================================
    // 修订
    // ============================================================

    /** @return list<array<string,mixed>> */
    public static function revisions(int $id, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $rows = \App\Core\Database::exec(
            'SELECT `id`, `note`, `created_by`, `created_at` FROM `' . self::TABLE_REVISIONS . '`'
            . ' WHERE `document_id` = ? ORDER BY `id` DESC LIMIT ' . $limit,
            [$id]
        )->fetchAll(\PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /** @return array{ok:bool,message:string,status:int,lock_version?:int,warnings?:list<string>} */
    public static function rollback(int $id, int $revisionId, int $expectedVersion, int $actorId, bool $canUseCode): array
    {
        $revision = \App\Core\Database::exec(
            'SELECT * FROM `' . self::TABLE_REVISIONS . '` WHERE `id` = ? AND `document_id` = ? LIMIT 1',
            [$revisionId, $id]
        )->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($revision)) return self::fail('修订不存在或不属于该文档', 404);

        $decoded = json_decode((string)($revision['doc_json'] ?? ''), true);
        $tree = self::normalize(is_array($decoded) ? $decoded : [])['tree'];
        // 回滚本身也写一条修订（写的是回滚前的内容），因此回滚可以再回滚。
        return self::saveTree($id, $tree, $expectedVersion, $actorId, '回滚到修订 #' . $revisionId, $canUseCode);
    }

    private static function writeRevision(int $id, string $docJson, string $note, int $actorId): void
    {
        \App\Core\Database::exec(
            'INSERT INTO `' . self::TABLE_REVISIONS . '` (`document_id`, `doc_json`, `note`, `created_by`, `created_at`)'
            . ' VALUES (?, ?, ?, ?, NOW())',
            [$id, $docJson, mb_substr(VisualEditorValue::plainText($note), 0, 200), $actorId]
        );
    }

    private static function pruneRevisions(int $id): void
    {
        $limit = VisualEditorSettings::revisionLimit();
        $rows = \App\Core\Database::exec(
            'SELECT `id` FROM `' . self::TABLE_REVISIONS . '` WHERE `document_id` = ? ORDER BY `id` DESC LIMIT 1 OFFSET ' . $limit,
            [$id]
        )->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($rows)) return;
        \App\Core\Database::exec(
            'DELETE FROM `' . self::TABLE_REVISIONS . '` WHERE `document_id` = ? AND `id` <= ?',
            [$id, (int)$rows['id']]
        );
    }

    // ============================================================
    // 树查询
    // ============================================================

    /**
     * 按元素 id 定位。返回 [种类, 路径]，找不到返回 null。
     * 路径是整数下标序列，形如 [2] / [2,0] / [2,0,3]，分别对应区块 / 栏 / 控件。
     *
     * @return array{0:string,1:list<int>}|null
     */
    public static function locate(array $tree, string $elementId): ?array
    {
        foreach (($tree['sections'] ?? []) as $sectionIndex => $section) {
            if ((string)($section['id'] ?? '') === $elementId) return ['section', [$sectionIndex]];
            foreach (($section['columns'] ?? []) as $columnIndex => $column) {
                if ((string)($column['id'] ?? '') === $elementId) return ['column', [$sectionIndex, $columnIndex]];
                foreach (($column['widgets'] ?? []) as $widgetIndex => $widget) {
                    if ((string)($widget['id'] ?? '') === $elementId) {
                        return ['widget', [$sectionIndex, $columnIndex, $widgetIndex]];
                    }
                }
            }
        }
        return null;
    }

    public static function countWidgets(array $tree): int
    {
        $count = 0;
        foreach (($tree['sections'] ?? []) as $section) {
            foreach (($section['columns'] ?? []) as $column) {
                $count += count($column['widgets'] ?? []);
            }
        }
        return $count;
    }

    /**
     * 结构轮廓：给 API 与 Agent 用的「这页有什么」。
     *
     * 刻意做成**扁平列表**而不是嵌套树：扩展 API 的响应被裁到深度 4，
     * 嵌套树的控件层正好会被裁掉，返回一个「有区块没控件」的假象。
     * 扁平列表每项都是纯标量，深度恒定，还更适合模型直接拿 id 来改。
     *
     * @return array{items:list<array<string,string|int>>,truncated:bool,total:int}
     */
    public static function flatOutline(array $tree, int $limit = 100): array
    {
        $limit = max(1, min(100, $limit));
        $items = [];
        $total = 0;
        foreach (($tree['sections'] ?? []) as $sectionIndex => $section) {
            $total++;
            if (count($items) < $limit) {
                $items[] = [
                    'id' => (string)($section['id'] ?? ''),
                    'kind' => 'section',
                    'type' => (string)($section['layout'] ?? 'boxed'),
                    'parent' => '',
                    'index' => (int)$sectionIndex,
                    'summary' => '',
                ];
            }
            foreach (($section['columns'] ?? []) as $columnIndex => $column) {
                $total++;
                if (count($items) < $limit) {
                    $items[] = [
                        'id' => (string)($column['id'] ?? ''),
                        'kind' => 'column',
                        'type' => 'w' . (int)($column['width']['desktop'] ?? 100),
                        'parent' => (string)($section['id'] ?? ''),
                        'index' => (int)$columnIndex,
                        'summary' => '',
                    ];
                }
                foreach (($column['widgets'] ?? []) as $widgetIndex => $widget) {
                    $total++;
                    if (count($items) >= $limit) continue;
                    $items[] = [
                        'id' => (string)($widget['id'] ?? ''),
                        'kind' => 'widget',
                        'type' => (string)($widget['type'] ?? ''),
                        'parent' => (string)($column['id'] ?? ''),
                        'index' => (int)$widgetIndex,
                        'summary' => self::widgetSummary($widget),
                    ];
                }
            }
        }
        return ['items' => $items, 'truncated' => $total > count($items), 'total' => $total];
    }

    private static function widgetSummary(array $widget): string
    {
        $content = is_array($widget['content'] ?? null) ? $widget['content'] : [];
        foreach (['text', 'html', 'items', 'alt', 'title', 'src', 'video_id'] as $field) {
            $value = (string)($content[$field] ?? '');
            if ($value === '') continue;
            $plain = trim((string)preg_replace('/\s+/u', ' ', strip_tags($value)));
            if ($plain !== '') return mb_substr($plain, 0, 80);
        }
        return '';
    }

    // ============================================================
    // 树的增删改移（纯函数：返回新树，不改入参）
    // ============================================================

    /**
     * 在树里插入一个区块。position 是插入下标，负数或越界都按追加到末尾处理。
     *
     * @return array{ok:bool,message:string,tree:array<string,mixed>,ids:array<string,string>}
     */
    public static function insertSection(array $tree, int $columns, string $layout, int $position): array
    {
        $sections = $tree['sections'] ?? [];
        if (count($sections) >= VisualEditorSchema::MAX_SECTIONS) {
            return ['ok' => false, 'message' => '区块数已达上限 ' . VisualEditorSchema::MAX_SECTIONS, 'tree' => $tree, 'ids' => []];
        }
        $columns = max(1, min(VisualEditorSchema::MAX_COLUMNS_PER_SECTION, $columns));
        $layout = in_array($layout, VisualEditorSchema::SECTION_LAYOUTS, true) ? $layout : 'boxed';
        $width = (int)floor(100 / $columns);

        $ids = [];
        $section = [
            'id' => self::newElementId(),
            'layout' => $layout,
            'style' => self::emptyStyle(),
            'columns' => [],
        ];
        $ids['section'] = $section['id'];
        for ($index = 0; $index < $columns; $index++) {
            $columnId = self::newElementId();
            // 最后一栏吃掉除不尽的余数，避免 3 栏时合计 99%。
            $percent = $index === $columns - 1 ? 100 - $width * ($columns - 1) : $width;
            $section['columns'][] = [
                'id' => $columnId,
                'width' => ['desktop' => $percent, 'tablet' => $columns > 2 ? 50 : $percent, 'mobile' => 100],
                'style' => self::emptyStyle(),
                'widgets' => [],
            ];
            $ids['column_' . $index] = $columnId;
        }

        if ($position < 0 || $position >= count($sections)) {
            $sections[] = $section;
        } else {
            array_splice($sections, $position, 0, [$section]);
        }
        $tree['sections'] = $sections;
        return ['ok' => true, 'message' => '已插入区块', 'tree' => $tree, 'ids' => $ids];
    }

    /**
     * 往指定栏插入一个控件。
     *
     * @param array<string,mixed> $content 只认该控件类型声明过的字段
     * @return array{ok:bool,message:string,tree:array<string,mixed>,id:string}
     */
    public static function insertWidget(array $tree, string $columnId, string $type, array $content, int $position): array
    {
        $definition = VisualEditorSchema::widget($type);
        if ($definition === null) {
            return ['ok' => false, 'message' => '未知控件类型：' . $type, 'tree' => $tree, 'id' => ''];
        }
        $located = self::locate($tree, $columnId);
        if ($located === null || $located[0] !== 'column') {
            return ['ok' => false, 'message' => '栏不存在：' . $columnId, 'tree' => $tree, 'id' => ''];
        }
        if (self::countWidgets($tree) >= VisualEditorSchema::MAX_WIDGETS_TOTAL) {
            return ['ok' => false, 'message' => '控件总数已达上限', 'tree' => $tree, 'id' => ''];
        }
        [, $path] = $located;
        [$sectionIndex, $columnIndex] = $path;
        $widgets = $tree['sections'][$sectionIndex]['columns'][$columnIndex]['widgets'] ?? [];
        if (count($widgets) >= VisualEditorSchema::MAX_WIDGETS_PER_COLUMN) {
            return ['ok' => false, 'message' => '该栏控件数已达上限', 'tree' => $tree, 'id' => ''];
        }

        $normalizedContent = [];
        foreach ((array)$definition['fields'] as $field => $spec) {
            $raw = array_key_exists($field, $content) ? $content[$field] : ($definition['defaults'][$field] ?? '');
            [$ok, $value] = VisualEditorValue::field((string)$spec, $raw);
            if (!$ok) {
                return ['ok' => false, 'message' => $field . ' 的值不合法', 'tree' => $tree, 'id' => ''];
            }
            $normalizedContent[$field] = $value;
        }

        $widget = [
            'id' => self::newElementId(),
            'type' => $type,
            'content' => $normalizedContent,
            'style' => self::emptyStyle(),
        ];
        if ($position < 0 || $position >= count($widgets)) {
            $widgets[] = $widget;
        } else {
            array_splice($widgets, $position, 0, [$widget]);
        }
        $tree['sections'][$sectionIndex]['columns'][$columnIndex]['widgets'] = $widgets;
        return ['ok' => true, 'message' => '已插入控件', 'tree' => $tree, 'id' => $widget['id']];
    }

    /**
     * 改一个控件的内容字段。只接受该控件类型声明过的字段，未声明的一律报错——
     * 静默忽略会让调用方以为改生效了。
     *
     * @param array<string,mixed> $fields
     * @return array{ok:bool,message:string,tree:array<string,mixed>}
     */
    public static function updateWidgetContent(array $tree, string $widgetId, array $fields): array
    {
        $located = self::locate($tree, $widgetId);
        if ($located === null || $located[0] !== 'widget') {
            return ['ok' => false, 'message' => '控件不存在：' . $widgetId, 'tree' => $tree];
        }
        [, [$sectionIndex, $columnIndex, $widgetIndex]] = $located;
        $widget = $tree['sections'][$sectionIndex]['columns'][$columnIndex]['widgets'][$widgetIndex];
        $definition = VisualEditorSchema::widget((string)$widget['type']);
        if ($definition === null) {
            return ['ok' => false, 'message' => '控件类型已不受支持：' . (string)$widget['type'], 'tree' => $tree];
        }

        $unknown = array_values(array_diff(array_keys($fields), array_keys((array)$definition['fields'])));
        if ($unknown !== []) {
            return [
                'ok' => false,
                'message' => (string)$definition['label'] . ' 控件不接受字段：' . implode('、', array_slice($unknown, 0, 5)),
                'tree' => $tree,
            ];
        }
        if ($fields === []) {
            return ['ok' => false, 'message' => '没有要修改的字段', 'tree' => $tree];
        }

        foreach ($fields as $field => $value) {
            [$ok, $normalized] = VisualEditorValue::field((string)$definition['fields'][$field], $value);
            if (!$ok) {
                return ['ok' => false, 'message' => $field . ' 的值不合法', 'tree' => $tree];
            }
            $widget['content'][$field] = $normalized;
        }
        $tree['sections'][$sectionIndex]['columns'][$columnIndex]['widgets'][$widgetIndex] = $widget;
        return ['ok' => true, 'message' => '已修改', 'tree' => $tree];
    }

    /**
     * 在同级序列里上移 / 下移一位。
     *
     * @return array{ok:bool,message:string,tree:array<string,mixed>}
     */
    public static function moveElement(array $tree, string $elementId, string $direction): array
    {
        $step = $direction === 'up' ? -1 : ($direction === 'down' ? 1 : 0);
        if ($step === 0) {
            return ['ok' => false, 'message' => 'direction 只能是 up 或 down', 'tree' => $tree];
        }
        $located = self::locate($tree, $elementId);
        if ($located === null) {
            return ['ok' => false, 'message' => '元素不存在：' . $elementId, 'tree' => $tree];
        }
        [$kind, $path] = $located;

        if ($kind === 'section') {
            $sections = $tree['sections'];
            $target = $path[0] + $step;
            if (!isset($sections[$target])) {
                return ['ok' => false, 'message' => '已经在边界，无法再移动', 'tree' => $tree];
            }
            [$sections[$path[0]], $sections[$target]] = [$sections[$target], $sections[$path[0]]];
            $tree['sections'] = $sections;
            return ['ok' => true, 'message' => '已移动区块', 'tree' => $tree];
        }

        if ($kind === 'column') {
            $columns = $tree['sections'][$path[0]]['columns'];
            $target = $path[1] + $step;
            if (!isset($columns[$target])) {
                return ['ok' => false, 'message' => '已经在边界，无法再移动', 'tree' => $tree];
            }
            [$columns[$path[1]], $columns[$target]] = [$columns[$target], $columns[$path[1]]];
            $tree['sections'][$path[0]]['columns'] = $columns;
            return ['ok' => true, 'message' => '已移动栏', 'tree' => $tree];
        }

        $widgets = $tree['sections'][$path[0]]['columns'][$path[1]]['widgets'];
        $target = $path[2] + $step;
        if (!isset($widgets[$target])) {
            return ['ok' => false, 'message' => '已经在边界，无法再移动', 'tree' => $tree];
        }
        [$widgets[$path[2]], $widgets[$target]] = [$widgets[$target], $widgets[$path[2]]];
        $tree['sections'][$path[0]]['columns'][$path[1]]['widgets'] = $widgets;
        return ['ok' => true, 'message' => '已移动控件', 'tree' => $tree];
    }

    /**
     * 删除一个区块 / 栏 / 控件。
     *
     * 最后一个区块与区块里最后一栏不允许删：删完之后文档没有可放内容的地方，
     * normalize() 又会补一个空区块回来，用户看到的是「删了但没删掉」。
     *
     * @return array{ok:bool,message:string,tree:array<string,mixed>}
     */
    public static function removeElement(array $tree, string $elementId): array
    {
        $located = self::locate($tree, $elementId);
        if ($located === null) {
            return ['ok' => false, 'message' => '元素不存在：' . $elementId, 'tree' => $tree];
        }
        [$kind, $path] = $located;

        if ($kind === 'section') {
            if (count($tree['sections']) <= 1) {
                return ['ok' => false, 'message' => '文档至少要保留一个区块', 'tree' => $tree];
            }
            array_splice($tree['sections'], $path[0], 1);
            return ['ok' => true, 'message' => '已删除区块', 'tree' => $tree];
        }

        if ($kind === 'column') {
            if (count($tree['sections'][$path[0]]['columns']) <= 1) {
                return ['ok' => false, 'message' => '区块至少要保留一栏（要整块删请删区块）', 'tree' => $tree];
            }
            array_splice($tree['sections'][$path[0]]['columns'], $path[1], 1);
            return ['ok' => true, 'message' => '已删除栏', 'tree' => $tree];
        }

        array_splice($tree['sections'][$path[0]]['columns'][$path[1]]['widgets'], $path[2], 1);
        return ['ok' => true, 'message' => '已删除控件', 'tree' => $tree];
    }

    /**
     * 自定义 HTML 控件的内容有没有变化。变化了但没有 visual_editor.code 权限就拒绝，
     * 返回错误文案；没变化返回空串。
     */
    private static function codeWidgetChangeError(array $oldTree, array $newTree, bool $canUseCode): string
    {
        if ($canUseCode) return '';
        $before = self::codeWidgetFingerprints($oldTree);
        $after = self::codeWidgetFingerprints($newTree);
        if ($before === $after) return '';
        return '新增或修改「自定义 HTML」控件需要 visual_editor.code 权限';
    }

    /** @return array<string,string> 元素 id => 内容哈希 */
    private static function codeWidgetFingerprints(array $tree): array
    {
        $out = [];
        foreach (($tree['sections'] ?? []) as $section) {
            foreach (($section['columns'] ?? []) as $column) {
                foreach (($column['widgets'] ?? []) as $widget) {
                    if ((string)($widget['type'] ?? '') !== 'html') continue;
                    $out[(string)($widget['id'] ?? '')] = hash('sha256', (string)($widget['content']['html'] ?? ''));
                }
            }
        }
        ksort($out);
        return $out;
    }

    public static function encodeTree(array $tree): string
    {
        return (string)json_encode(
            $tree,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    private static function writeCssCache(int $id, array $tree): void
    {
        \App\Core\Database::exec(
            'UPDATE `' . self::TABLE_DOCUMENTS . '` SET `css_cache` = ? WHERE `id` = ?',
            [VisualEditorStyleCompiler::compile($id, $tree), $id]
        );
    }

    /** @return array{ok:false,message:string,status:int} */
    private static function fail(string $message, int $status): array
    {
        return ['ok' => false, 'message' => $message, 'status' => $status];
    }
}
