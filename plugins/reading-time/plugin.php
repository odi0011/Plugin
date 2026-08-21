<?php
/**
 * 阅读时长与字数
 *
 * 前台在文章/页面正文后附一行「约 N 字，预计阅读 M 分钟」；
 * 后台提供每分钟字数配置、按类型开关，以及按字数排序的内容报表。
 */
if (!defined('CODE_SCHEMA_VERSION')) exit;

add_action('plugin.activated', static function ($slug) {
    if ($slug !== 'reading-time') return;
    register_plugin_setting('reading-time', 'wpm', '300');
    register_plugin_setting('reading-time', 'show_article', '1');
    register_plugin_setting('reading-time', 'show_page', '0');
    register_plugin_setting('reading-time', 'min_words', '80');
});

if (!function_exists('reading_time_count_words')) {
    /**
     * 中英文混排计数：CJK 按字计，西文按词计。
     * 先剥 script/style，再去标签，避免把代码和样式算进正文。
     */
    function reading_time_count_words(string $html): int
    {
        if ($html === '') return 0;
        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $cjk = preg_match_all('/[\x{4E00}-\x{9FFF}\x{3040}-\x{30FF}\x{AC00}-\x{D7AF}]/u', $text) ?: 0;
        $latin = preg_replace('/[\x{4E00}-\x{9FFF}\x{3040}-\x{30FF}\x{AC00}-\x{D7AF}]/u', ' ', $text) ?? '';
        $latinWords = preg_match_all('/[A-Za-z0-9\x{00C0}-\x{024F}][A-Za-z0-9\x{00C0}-\x{024F}\'’-]*/u', $latin) ?: 0;

        return (int)$cjk + (int)$latinWords;
    }
}

if (!function_exists('reading_time_minutes')) {
    function reading_time_minutes(int $words): int
    {
        $wpm = (int)get_plugin_setting('reading-time', 'wpm', '300');
        $wpm = max(60, min(1200, $wpm));
        return max(1, (int)ceil($words / $wpm));
    }
}

if (!function_exists('reading_time_render')) {
    function reading_time_render(string $type, array $record): void
    {
        if ((string)get_plugin_setting('reading-time', 'show_' . $type, '0') !== '1') return;
        $words = reading_time_count_words((string)($record['content'] ?? ''));
        $minWords = max(0, (int)get_plugin_setting('reading-time', 'min_words', '80'));
        if ($words < $minWords) return;   // 太短的内容标阅读时长没有意义

        echo '<p class="reading-time-note" style="margin:1.5rem 0 0;color:#6b7280;font-size:.9rem;">'
            . '约 ' . number_format($words) . ' 字 · 预计阅读 ' . reading_time_minutes($words) . ' 分钟'
            . '</p>';
    }
}

add_action('article.show.after', static function ($article) {
    if (is_array($article)) reading_time_render('article', $article);
});

add_action('page.show.after', static function ($page) {
    if (is_array($page)) reading_time_render('page', $page);
});

add_filter('admin.menu.register', static function ($items) {
    if (!\App\Core\Auth::can('reading_time.config')) return $items;
    $items[] = [
        'url' => admin_url('/reading-time/settings'),
        'label' => '阅读时长',
        'icon' => 'bi-hourglass-split',
        'perm' => 'reading_time.config',
    ];
    return $items;
});

add_action('routes.admin.register', static function ($router) {
    $router->get('/admin/reading-time/settings', static function () {
        \App\Core\Auth::requirePermission('reading_time.config');
        echo plugin_view('reading-time', 'settings', [
            'wpm' => (string)get_plugin_setting('reading-time', 'wpm', '300'),
            'showArticle' => (string)get_plugin_setting('reading-time', 'show_article', '1') === '1',
            'showPage' => (string)get_plugin_setting('reading-time', 'show_page', '0') === '1',
            'minWords' => (string)get_plugin_setting('reading-time', 'min_words', '80'),
            'report' => reading_time_report(),
        ]);
    });

    $router->post('/admin/reading-time/settings', static function () {
        \App\Core\Auth::requirePermission('reading_time.config');
        $wpm = (int)($_POST['wpm'] ?? 300);
        $minWords = (int)($_POST['min_words'] ?? 80);
        if ($wpm < 60 || $wpm > 1200) {
            flash('error', '每分钟字数只接受 60-1200');
        } elseif ($minWords < 0 || $minWords > 100000) {
            flash('error', '起始字数只接受 0-100000');
        } else {
            set_plugin_setting('reading-time', 'wpm', (string)$wpm);
            set_plugin_setting('reading-time', 'min_words', (string)$minWords);
            set_plugin_setting('reading-time', 'show_article', ($_POST['show_article'] ?? '') === '1' ? '1' : '0');
            set_plugin_setting('reading-time', 'show_page', ($_POST['show_page'] ?? '') === '1' ? '1' : '0');
            flash('success', '已保存');
        }
        header('Location: ' . admin_url('/reading-time/settings'));
        exit;
    });
});

if (!function_exists('reading_time_report')) {
    /**
     * 字数报表：文章与页面各取最近 200 条，按字数升序（最短的最需要关注）。
     * @return array<int,array<string,mixed>>
     */
    function reading_time_report(): array
    {
        $out = [];
        foreach (['article' => 'articles', 'page' => 'pages'] as $type => $table) {
            try {
                $rows = \App\Core\Database::table($table)
                    ->select('id', 'title', 'slug', 'status', 'content')
                    ->orderBy('id', 'desc')
                    ->limit(200)
                    ->get();
            } catch (\Throwable $_) {
                continue;
            }
            foreach ($rows as $row) {
                $words = reading_time_count_words((string)($row['content'] ?? ''));
                $out[] = [
                    'type' => $type,
                    'id' => (int)($row['id'] ?? 0),
                    'title' => (string)($row['title'] ?? ''),
                    'slug' => (string)($row['slug'] ?? ''),
                    'status' => (string)($row['status'] ?? ''),
                    'words' => $words,
                    'minutes' => reading_time_minutes($words),
                ];
            }
        }
        usort($out, static fn (array $a, array $b): int => $a['words'] <=> $b['words']);
        return array_slice($out, 0, 50);
    }
}
