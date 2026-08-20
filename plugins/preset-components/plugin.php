<?php
/**
 * Preset Components plugin.
 */
if (!defined('CODE_SCHEMA_VERSION')) exit;

add_action('plugin.activated', function ($slug) {
    if ($slug !== 'preset-components') return;
    register_plugin_setting('preset-components', 'last_activated_at', date('Y-m-d H:i:s'));
});

add_filter('admin.menu.register', function ($items) {
    if (!\App\Core\Auth::can('preset_components.manage')) return $items;
    $items[] = [
        'url' => admin_url('/preset-components'),
        'label' => '预设组件',
        'icon' => 'bi-grid-3x3-gap',
        'perm' => 'preset_components.manage',
    ];
    return $items;
});

add_action('admin.head', function () {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($uri, '/preset-components') === false) return;
    echo '<link rel="stylesheet" href="' . e(plugin_url('preset-components', 'assets/admin.css')) . '?v=1.0.0">' . "\n";
});

add_action('routes.admin.register', function ($router) {
    $router->get('/admin/preset-components', function () {
        \App\Core\Auth::requirePermission('preset_components.manage');

        $catalog = preset_components_catalog();
        $installed = preset_components_installed_map($catalog);

        return plugin_view('preset-components', 'home', [
            'catalog' => $catalog,
            'installed' => $installed,
            'stats' => preset_components_stats($catalog, $installed),
            'categories' => preset_components_categories(),
            'targets' => preset_components_targets(),
        ]);
    });

    $router->get('/admin/preset-components/components', function () {
        \App\Core\Auth::requirePermission('preset_components.manage');

        $catalog = preset_components_catalog();
        $installed = preset_components_installed_map($catalog);
        $filters = [
            'q' => trim((string)($_GET['q'] ?? '')),
            'category' => trim((string)($_GET['category'] ?? '')),
            'status' => trim((string)($_GET['status'] ?? '')),
            'target' => trim((string)($_GET['target'] ?? '')),
        ];

        return plugin_view('preset-components', 'index', [
            'catalog' => preset_components_filter_catalog($catalog, $installed, $filters),
            'allCount' => count($catalog),
            'installed' => $installed,
            'filters' => $filters,
            'categories' => preset_components_categories(),
            'targets' => preset_components_targets(),
            'stats' => preset_components_stats($catalog, $installed),
        ]);
    });

    $router->get('/admin/preset-components/components/{slug}', function ($slug) {
        \App\Core\Auth::requirePermission('preset_components.manage');

        $preset = preset_components_find($slug);
        if (!$preset) {
            http_response_code(404);
            return 'Preset component not found';
        }

        return plugin_view('preset-components', 'show', [
            'preset' => $preset,
            'state' => preset_components_state(\App\Models\FrontendComponent::byTag($preset['tag'])),
            'record' => \App\Models\FrontendComponent::byTag($preset['tag']),
            'categories' => preset_components_categories(),
            'targets' => preset_components_targets(),
            'targetOptions' => preset_components_target_options(),
            'snippet' => preset_components_usage_snippet($preset),
            'previewDoc' => preset_components_preview_document($preset),
        ]);
    });

    $router->post('/admin/preset-components/components/{slug}/enable', function ($slug) {
        \App\Core\Auth::requirePermission('preset_components.manage');

        $preset = preset_components_find($slug);
        if (!$preset) {
            flash('error', '预设组件不存在。');
            preset_components_redirect('/preset-components/components');
        }

        try {
            $id = preset_components_enable($preset);
            flash('success', '已启用并同步为系统组件：' . $preset['tag']);
            do_action('preset_components.enabled', $preset, $id);
        } catch (\Throwable $e) {
            flash('error', '启用失败：' . $e->getMessage());
        }

        preset_components_redirect('/preset-components/components/' . rawurlencode($preset['slug']));
    });

    $router->post('/admin/preset-components/components/{slug}/disable', function ($slug) {
        \App\Core\Auth::requirePermission('preset_components.manage');

        $preset = preset_components_find($slug);
        if (!$preset) {
            flash('error', '预设组件不存在。');
            preset_components_redirect('/preset-components/components');
        }

        try {
            preset_components_disable($preset);
            flash('success', '已禁用组件：' . $preset['tag']);
            do_action('preset_components.disabled', $preset);
        } catch (\Throwable $e) {
            flash('error', '禁用失败：' . $e->getMessage());
        }

        preset_components_redirect('/preset-components/components/' . rawurlencode($preset['slug']));
    });

    $router->post('/admin/preset-components/components/{slug}/apply', function ($slug) {
        \App\Core\Auth::requirePermission('preset_components.manage');

        $preset = preset_components_find($slug);
        if (!$preset) {
            flash('error', '预设组件不存在。');
            preset_components_redirect('/preset-components/components');
        }

        try {
            $applied = \App\Core\Database::transaction(function () use ($preset): array {
                $componentId = preset_components_enable($preset);
                $result = preset_components_apply($preset, $_POST);
                return ['component_id' => $componentId, 'result' => $result];
            });
            $result = $applied['result'];
            flash('success', '已写入到' . $result['target_label'] . '：' . $result['item_label']);
            do_action('preset_components.applied', $preset, $result);
        } catch (\Throwable $e) {
            flash('error', '应用失败：' . $e->getMessage());
        }

        preset_components_redirect('/preset-components/components/' . rawurlencode($preset['slug']));
    });
});

function preset_components_catalog(): array
{
    static $catalog = null;
    if ($catalog === null) {
        $catalog = require __DIR__ . '/catalog.php';
        usort($catalog, function ($a, $b) {
            return (int)($a['sort_order'] ?? 0) <=> (int)($b['sort_order'] ?? 0);
        });
    }
    return $catalog;
}

function preset_components_categories(): array
{
    return [
        'marketing' => '营销展示',
        'content' => '内容组织',
        'commerce' => '商业转化',
        'utility' => '工具增强',
        'motion' => '动态效果',
        'media' => '媒体展示',
        'conversion' => '线索转化',
    ];
}

function preset_components_targets(): array
{
    return [
        'page' => ['label' => '页面', 'table' => 'pages', 'field' => 'html', 'title' => 'title', 'meta' => 'slug', 'workflow_type' => 'page'],
        'article' => ['label' => '文章', 'table' => 'articles', 'field' => 'content', 'title' => 'title', 'meta' => 'slug', 'workflow_type' => 'article'],
        'product' => ['label' => '产品', 'table' => 'products', 'field' => 'content', 'title' => 'title', 'meta' => 'slug', 'workflow_type' => 'product'],
        'template' => ['label' => '系统模板', 'table' => 'frontend_templates', 'field' => 'html', 'title' => 'label', 'meta' => 'key'],
    ];
}

function preset_components_find(string $slug): ?array
{
    foreach (preset_components_catalog() as $preset) {
        if (($preset['slug'] ?? '') === $slug) {
            return $preset;
        }
    }
    return null;
}

function preset_components_installed_map(array $catalog): array
{
    $map = [];
    foreach ($catalog as $preset) {
        try {
            $map[$preset['slug']] = \App\Models\FrontendComponent::byTag($preset['tag']);
        } catch (\Throwable $e) {
            $map[$preset['slug']] = null;
        }
    }
    return $map;
}

function preset_components_state(?array $record): array
{
    if (!$record) {
        return ['key' => 'not_used', 'label' => '未使用', 'class' => 'secondary'];
    }
    if ((int)($record['status'] ?? 0) === 1) {
        return ['key' => 'enabled', 'label' => '已启用', 'class' => 'success'];
    }
    return ['key' => 'disabled', 'label' => '已禁用', 'class' => 'warning'];
}

function preset_components_stats(array $catalog, array $installed): array
{
    $stats = ['total' => count($catalog), 'enabled' => 0, 'disabled' => 0, 'not_used' => 0];
    foreach ($catalog as $preset) {
        $state = preset_components_state($installed[$preset['slug']] ?? null);
        $stats[$state['key']]++;
    }
    return $stats;
}

function preset_components_filter_catalog(array $catalog, array $installed, array $filters): array
{
    return array_values(array_filter($catalog, function ($preset) use ($installed, $filters) {
        $q = mb_strtolower((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $haystack = mb_strtolower(implode(' ', [
                $preset['title'] ?? '',
                $preset['tag'] ?? '',
                $preset['summary'] ?? '',
                $preset['category'] ?? '',
            ]));
            if (strpos($haystack, $q) === false) {
                return false;
            }
        }

        $category = (string)($filters['category'] ?? '');
        if ($category !== '' && ($preset['category'] ?? '') !== $category) {
            return false;
        }

        $target = (string)($filters['target'] ?? '');
        if ($target !== '' && !in_array($target, $preset['targets'] ?? [], true)) {
            return false;
        }

        $status = (string)($filters['status'] ?? '');
        if ($status !== '') {
            $state = preset_components_state($installed[$preset['slug']] ?? null);
            if ($state['key'] !== $status) {
                return false;
            }
        }

        return true;
    }));
}

function preset_components_enable(array $preset): int
{
    $tag = \App\Models\FrontendComponent::normalizeTag((string)$preset['tag']);
    if (!\App\Models\FrontendComponent::validTag($tag)) {
        throw new \InvalidArgumentException('组件标签格式不正确。');
    }

    $record = [
        'title' => (string)$preset['title'],
        'tag_name' => $tag,
        'description' => preset_components_description($preset),
        'html' => (string)$preset['html'],
        'css' => (string)($preset['css'] ?? ''),
        'js' => (string)($preset['js'] ?? ''),
        'status' => 1,
        'sort_order' => (int)($preset['sort_order'] ?? 0),
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    $existing = \App\Models\FrontendComponent::byTag($tag);
    if ($existing) {
        \App\Models\FrontendComponent::updateById((int)$existing['id'], $record);
        do_action('frontend.component.saved', (int)$existing['id'], $record, false);
        return (int)$existing['id'];
    }

    $record['created_at'] = date('Y-m-d H:i:s');
    $id = \App\Models\FrontendComponent::create($record);
    do_action('frontend.component.saved', $id, $record, true);
    return $id;
}

function preset_components_disable(array $preset): void
{
    $tag = \App\Models\FrontendComponent::normalizeTag((string)$preset['tag']);
    $existing = \App\Models\FrontendComponent::byTag($tag);
    if (!$existing) {
        return;
    }
    $record = [
        'status' => 0,
        'updated_at' => date('Y-m-d H:i:s'),
    ];
    \App\Models\FrontendComponent::updateById((int)$existing['id'], $record);
    do_action('frontend.component.saved', (int)$existing['id'], array_merge($existing, $record), false);
}

function preset_components_description(array $preset): string
{
    $categories = preset_components_categories();
    $category = $categories[$preset['category'] ?? ''] ?? '预设组件';
    return trim((string)$preset['summary']) . "\n\n来源：预设组件 / " . $category;
}

function preset_components_param_defaults(array $preset): array
{
    $attrs = [];
    foreach ($preset['params'] ?? [] as $param) {
        $key = (string)($param['key'] ?? '');
        if ($key === '') continue;
        $attrs[$key] = (string)($param['default'] ?? '');
    }
    return $attrs;
}

function preset_components_usage_snippet(array $preset): string
{
    $attrs = preset_components_param_defaults($preset);
    $parts = [];
    foreach ($attrs as $key => $value) {
        if ($value === '' || !preg_match('/^[a-zA-Z_:][-a-zA-Z0-9_:.]*$/', $key)) continue;
        $safeValue = str_replace(["\r", "\n", '"'], [' ', ' ', "'"], $value);
        $parts[] = $key . '="' . e($safeValue) . '"';
    }
    $suffix = $parts ? ' ' . implode(' ', $parts) : '';
    return '<' . $preset['tag'] . $suffix . ' />';
}

function preset_components_preview_document(array $preset): string
{
    $attrs = preset_components_param_defaults($preset);
    $context = array_merge($attrs, [
        'attrs' => $attrs,
        'attrs_json' => json_encode($attrs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
        'attrs_html' => frontend_component_attr_string($attrs),
        'slot' => '',
        'component_tag' => (string)$preset['tag'],
        'component_title' => (string)$preset['title'],
        'component_instance' => 'preset-preview-' . preg_replace('/[^a-z0-9\-]/', '', (string)$preset['slug']),
    ]);

    $html = frontend_render_dynamic_template((string)$preset['html'], $context, ['slot', 'attrs_json', 'attrs_html']);
    $css = (string)($preset['css'] ?? '');
    $js = str_replace('</script', '<\/script', (string)($preset['js'] ?? ''));

    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<style>html,body{margin:0;padding:0;background:#f8fafc;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#111827}body{padding:22px}.preview-wrap{max-width:1120px;margin:0 auto}</style>'
        . '<style>' . $css . '</style></head><body><div class="preview-wrap">' . $html . '</div><script>' . $js . '</script></body></html>';
}

function preset_components_target_options(): array
{
    $options = [];
    foreach (preset_components_targets() as $key => $config) {
        $workflowType = (string)($config['workflow_type'] ?? '');
        if ($workflowType !== '' && !\App\Core\Auth::can($workflowType . '.edit')) {
            $options[$key] = [];
            continue;
        }

        try {
            $columns = ['id', $config['title'], $config['meta'], 'updated_at'];
            if ($workflowType !== '') $columns[] = 'lock_version';
            $rows = \App\Core\Database::table($config['table'])
                ->select(...$columns)
                ->orderBy('updated_at', 'desc')
                ->limit(80)
                ->get();
        } catch (\Throwable $e) {
            $options[$key] = [];
            continue;
        }

        $options[$key] = array_map(function ($row) use ($config) {
            $title = (string)($row[$config['title']] ?? ('#' . ($row['id'] ?? '')));
            $meta = (string)($row[$config['meta']] ?? '');
            return [
                'id' => (int)($row['id'] ?? 0),
                'label' => $title,
                'meta' => $meta,
                'lock_version' => array_key_exists('lock_version', $row) ? (int)$row['lock_version'] : null,
            ];
        }, $rows);
    }
    return $options;
}

function preset_components_apply(array $preset, array $input): array
{
    $targets = preset_components_targets();
    $targetType = (string)($input['target_type'] ?? '');
    $targetId = (int)($input['target_id'] ?? 0);
    $placement = (string)($input['placement'] ?? 'append');
    $snippet = trim((string)($input['snippet'] ?? ''));
    $skipDuplicate = (string)($input['skip_duplicate'] ?? '1') === '1';

    if (!isset($targets[$targetType])) {
        throw new \InvalidArgumentException('请选择要应用到的位置。');
    }
    if (!in_array($targetType, $preset['targets'] ?? [], true)) {
        throw new \InvalidArgumentException('当前预设组件不支持写入该位置。');
    }
    if ($targetId <= 0) {
        throw new \InvalidArgumentException('请选择具体内容。');
    }
    if ($snippet === '') {
        $snippet = preset_components_usage_snippet($preset);
    }
    if (stripos($snippet, '<' . $preset['tag']) === false) {
        throw new \InvalidArgumentException('调用标签必须包含当前预设组件标签。');
    }

    $config = $targets[$targetType];
    $workflowType = (string)($config['workflow_type'] ?? '');
    if ($workflowType !== '') {
        if (!\App\Core\Auth::can($workflowType . '.edit')) {
            throw new \RuntimeException('当前账号缺少 ' . $workflowType . '.edit 权限', 403);
        }

        $rawExpectedVersion = $input['lock_version'] ?? null;
        if ($rawExpectedVersion === null || $rawExpectedVersion === '') {
            throw new \RuntimeException('缺少内容版本号，请重新加载页面后再应用组件', 409);
        }
        if (!((is_int($rawExpectedVersion) && $rawExpectedVersion >= 0)
            || (is_string($rawExpectedVersion) && ctype_digit($rawExpectedVersion)))) {
            throw new \InvalidArgumentException('内容版本号无效，请重新加载页面后再应用组件');
        }
        $expectedVersion = (int)$rawExpectedVersion;
        $actorId = (int)(\App\Core\Auth::id() ?? 0);

        if ($workflowType === 'product') {
            \App\Models\Product::registerWorkflowSnapshotExtension();
        }

        $workflow = \App\Core\ContentWorkflow::mutate(
            $workflowType,
            $targetId,
            function (array $locked, string $canonicalType) use (
                $workflowType,
                $targetId,
                $config,
                $preset,
                $snippet,
                $placement,
                $skipDuplicate
            ): array {
                if ($canonicalType !== $workflowType) {
                    throw new \RuntimeException('内容类型已变化，请重新加载页面后再应用组件', 409);
                }
                if ((int)($locked['status'] ?? \App\Core\ContentWorkflow::DRAFT) !== \App\Core\ContentWorkflow::DRAFT
                    && !\App\Core\Auth::can($workflowType . '.publish')) {
                    throw new \RuntimeException('修改非草稿内容需要 ' . $workflowType . '.publish 权限', 403);
                }

                $field = $config['field'];
                $current = (string)($locked[$field] ?? '');
                if ($skipDuplicate && stripos($current, '<' . $preset['tag']) !== false) {
                    throw new \RuntimeException('目标内容中已经包含该组件调用，未重复写入。');
                }

                $block = "\n\n" . $snippet . "\n";
                $next = $placement === 'prepend'
                    ? trim($block . "\n" . $current)
                    : trim($current . $block);

                if ($canonicalType === 'page') {
                    \App\Models\Page::updateById($targetId, [$field => $next]);
                } elseif ($canonicalType === 'article') {
                    \App\Models\Article::updateById($targetId, [$field => $next]);
                } elseif ($canonicalType === 'product') {
                    \App\Models\Product::updateById($targetId, [$field => $next]);
                } else {
                    throw new \RuntimeException('不支持的内容类型', 409);
                }

                return [
                    'item_label' => (string)($locked[$config['title']] ?? ('#' . $targetId)),
                ];
            },
            [
                'expected_version' => $expectedVersion,
                'operation' => 'update',
                'action' => 'preset_components.apply',
                'actor_type' => 'user',
                'actor_id' => $actorId > 0 ? $actorId : null,
                'source' => 'admin.preset_components',
                'request_id' => defined('APP_REQUEST_ID') ? (string)constant('APP_REQUEST_ID') : '',
                'summary' => 'Apply preset component ' . (string)$preset['tag'] . ' to ' . $workflowType,
                'ip' => \App\Core\Request::resolveClientIp($_SERVER),
                'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                'metadata' => [
                    'plugin' => 'preset-components',
                    'preset_slug' => (string)($preset['slug'] ?? ''),
                    'component_tag' => (string)$preset['tag'],
                    'placement' => $placement === 'prepend' ? 'prepend' : 'append',
                ],
            ]
        );
        $mutationResult = is_array($workflow['result'] ?? null) ? $workflow['result'] : [];

        return [
            'target_type' => $targetType,
            'target_label' => $config['label'],
            'target_id' => $targetId,
            'item_label' => (string)($mutationResult['item_label'] ?? ('#' . $targetId)),
            'snippet' => $snippet,
            'placement' => $placement,
            'lock_version' => (int)($workflow['lock_version'] ?? $expectedVersion),
            'revision_id' => !empty($workflow['revision_id']) ? (int)$workflow['revision_id'] : null,
        ];
    }

    $row = \App\Core\Database::table($config['table'])->where('id', $targetId)->first();
    if (!$row) {
        throw new \RuntimeException('目标内容不存在。');
    }

    $field = $config['field'];
    $current = (string)($row[$field] ?? '');
    if ($skipDuplicate && stripos($current, '<' . $preset['tag']) !== false) {
        throw new \RuntimeException('目标内容中已经包含该组件调用，未重复写入。');
    }

    $block = "\n\n" . $snippet . "\n";
    if ($placement === 'prepend') {
        $next = trim($block . "\n" . $current);
    } else {
        $next = trim($current . $block);
    }

    \App\Core\Database::table($config['table'])->where('id', $targetId)->update([
        $field => $next,
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

    return [
        'target_type' => $targetType,
        'target_label' => $config['label'],
        'target_id' => $targetId,
        'item_label' => (string)($row[$config['title']] ?? ('#' . $targetId)),
        'snippet' => $snippet,
        'placement' => $placement,
    ];
}

function preset_components_redirect(string $path): void
{
    redirect(admin_url($path));
}
