<?php
/**
 * 可视化编辑器：HTML 渲染器。
 *
 * 树 → HTML。前台与后台画布走**同一个**渲染函数，只差一个 $editing 开关：
 * 开关只添加 data-ve-kind 之类的挂钩属性，不改变元素结构与样式落点。
 * 这是「所见即所得」的前提——如果编辑器和前台各写一遍 HTML，两边必然走形。
 *
 * 所有文本一律 e() 转义；唯一直出的是 text / html 两个控件的内容，
 * 而它们在写入时已经过 VisualEditorValue::sanitizeHtml() 白名单消毒。
 */
if (!defined('CODE_SCHEMA_VERSION')) exit;

final class VisualEditorRenderer
{
    public static function render(string $sourceKey, array $tree, bool $editing = false): string
    {
        $out = '<div class="ve-doc ' . e(VisualEditorStyleCompiler::rootClass($sourceKey)) . '"'
            . ($editing ? ' data-ve-kind="document"' : '') . '>';
        foreach (($tree['sections'] ?? []) as $section) {
            $out .= self::section($section, $editing);
        }
        $out .= '</div>';
        return $out;
    }

    private static function section(array $section, bool $editing): string
    {
        $id = (string)($section['id'] ?? '');
        $layout = (string)($section['layout'] ?? 'boxed') === 'full' ? 'full' : 'boxed';
        $attributes = ' data-ve="' . e($id) . '" class="ve-section ve-section-' . $layout . '"';
        if ($editing) $attributes .= ' data-ve-kind="section"';

        $inner = '<div class="ve-section-inner">';
        foreach (($section['columns'] ?? []) as $column) {
            $inner .= self::column($column, $editing);
        }
        $inner .= '</div>';

        return '<section' . $attributes . '>' . $inner . self::editorHandle($editing, '区块') . '</section>';
    }

    private static function column(array $column, bool $editing): string
    {
        $id = (string)($column['id'] ?? '');
        $attributes = ' data-ve="' . e($id) . '" class="ve-column"';
        if ($editing) $attributes .= ' data-ve-kind="column"';

        $inner = '';
        foreach (($column['widgets'] ?? []) as $widget) {
            $inner .= self::widget($widget, $editing);
        }
        if ($editing && $inner === '') {
            $inner = '<div class="ve-column-empty">拖入或点击左侧控件</div>';
        }
        return '<div' . $attributes . '>' . $inner . self::editorHandle($editing, '栏') . '</div>';
    }

    private static function widget(array $widget, bool $editing): string
    {
        $type = (string)($widget['type'] ?? '');
        $definition = VisualEditorSchema::widget($type);
        if ($definition === null) return '';
        $id = (string)($widget['id'] ?? '');
        $content = is_array($widget['content'] ?? null) ? $widget['content'] : [];

        $attributes = ' data-ve="' . e($id) . '" class="ve-widget ve-widget-' . e($type) . '"';
        if ($editing) $attributes .= ' data-ve-kind="widget" data-ve-type="' . e($type) . '"';

        return '<div' . $attributes . '>'
            . self::widgetBody($type, $content)
            . self::editorHandle($editing, (string)$definition['label'])
            . '</div>';
    }

    /** @param array<string,mixed> $content */
    private static function widgetBody(string $type, array $content): string
    {
        switch ($type) {
            case 'heading':
                $level = in_array((string)($content['level'] ?? 'h2'), ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)
                    ? (string)$content['level'] : 'h2';
                return '<' . $level . ' class="ve-heading ve-align-' . self::align($content) . '">'
                    . e((string)($content['text'] ?? '')) . '</' . $level . '>';

            case 'text':
                // 已消毒的富文本片段，直出。
                return '<div class="ve-text ve-align-' . self::align($content) . '">'
                    . (string)($content['html'] ?? '') . '</div>';

            case 'html':
                return '<div class="ve-html">' . (string)($content['html'] ?? '') . '</div>';

            case 'image':
                return self::image($content);

            case 'button':
                return self::button($content);

            case 'list':
                return self::listBody($content);

            case 'quote':
                $cite = (string)($content['cite'] ?? '');
                return '<blockquote class="ve-quote ve-align-' . self::align($content) . '"><p>'
                    . e((string)($content['text'] ?? '')) . '</p>'
                    . ($cite !== '' ? '<cite>' . e($cite) . '</cite>' : '')
                    . '</blockquote>';

            case 'divider':
                $style = in_array((string)($content['style'] ?? 'solid'), ['solid', 'dashed', 'dotted'], true)
                    ? (string)$content['style'] : 'solid';
                $thickness = max(1, min(12, (int)($content['thickness'] ?? 1)));
                $color = VisualEditorValue::style('border_color', $content['color'] ?? '');
                $inline = 'border-top-style:' . $style . ';border-top-width:' . $thickness . 'px;'
                    . ($color !== null ? 'border-top-color:' . $color . ';' : '');
                return '<hr class="ve-divider" style="' . e($inline) . '">';

            case 'spacer':
                $height = max(4, min(400, (int)($content['height'] ?? 40)));
                return '<div class="ve-spacer" style="height:' . $height . 'px"></div>';

            case 'embed':
                return self::embed($content);
        }
        return '';
    }

    /** @param array<string,mixed> $content */
    private static function align(array $content): string
    {
        $align = (string)($content['align'] ?? 'left');
        return in_array($align, ['left', 'center', 'right', 'justify'], true) ? $align : 'left';
    }

    private static function editorHandle(bool $editing, string $label): string
    {
        return $editing
            ? '<span class="ve-handle" data-ve-handle aria-hidden="true">' . e($label) . '</span>'
            : '';
    }

    /** @param array<string,mixed> $content */
    private static function image(array $content): string
    {
        $src = (string)($content['src'] ?? '');
        if ($src === '' || !VisualEditorValue::isMediaUrl($src)) {
            return '<div class="ve-image-placeholder">未选择图片</div>';
        }
        $width = max(5, min(100, (int)($content['width'] ?? 100)));
        $img = '<img src="' . e($src) . '" alt="' . e((string)($content['alt'] ?? '')) . '"'
            . ' loading="lazy" style="width:' . $width . '%">';
        $url = (string)($content['url'] ?? '');
        if ($url !== '' && VisualEditorValue::isSafeUrl($url)) {
            $img = '<a href="' . e($url) . '">' . $img . '</a>';
        }
        return '<figure class="ve-image ve-align-' . self::align($content) . '">' . $img . '</figure>';
    }

    /** @param array<string,mixed> $content */
    private static function button(array $content): string
    {
        $text = (string)($content['text'] ?? '');
        if ($text === '') return '';
        $variant = in_array((string)($content['variant'] ?? 'primary'), ['primary', 'outline', 'ghost'], true)
            ? (string)$content['variant'] : 'primary';
        $size = in_array((string)($content['size'] ?? 'md'), ['sm', 'md', 'lg'], true)
            ? (string)$content['size'] : 'md';
        $url = (string)($content['url'] ?? '');
        $safeUrl = $url !== '' && VisualEditorValue::isSafeUrl($url) ? $url : '';
        $target = (string)($content['target'] ?? '_self') === '_blank' ? '_blank' : '_self';

        $classes = 've-button ve-button-' . $variant . ' ve-button-' . $size;
        $inner = '<span class="' . $classes . '">' . e($text) . '</span>';
        if ($safeUrl !== '') {
            $rel = $target === '_blank' ? ' rel="noopener noreferrer"' : '';
            $inner = '<a class="' . $classes . '" href="' . e($safeUrl) . '" target="' . $target . '"' . $rel . '>'
                . e($text) . '</a>';
        }
        return '<div class="ve-button-wrap ve-align-' . self::align($content) . '">' . $inner . '</div>';
    }

    /** @param array<string,mixed> $content */
    private static function listBody(array $content): string
    {
        $marker = in_array((string)($content['marker'] ?? 'disc'), ['disc', 'decimal', 'check', 'none'], true)
            ? (string)$content['marker'] : 'disc';
        $tag = $marker === 'decimal' ? 'ol' : 'ul';
        $items = '';
        foreach (preg_split('/\n/', (string)($content['items'] ?? '')) ?: [] as $line) {
            $line = trim((string)$line);
            if ($line === '') continue;
            $items .= '<li>' . e($line) . '</li>';
        }
        if ($items === '') return '';
        return '<' . $tag . ' class="ve-list ve-list-' . $marker . ' ve-align-' . self::align($content) . '">'
            . $items . '</' . $tag . '>';
    }

    /**
     * 视频：只接受「服务商 + 视频 id」，由这里拼出 iframe 地址。
     * 不接受用户直接填 iframe src——那等于把任意第三方页面嵌进站点。
     *
     * @param array<string,mixed> $content
     */
    private static function embed(array $content): string
    {
        $provider = (string)($content['provider'] ?? 'youtube');
        $videoId = (string)($content['video_id'] ?? '');
        if ($videoId === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $videoId)) {
            return '<div class="ve-image-placeholder">未填写视频 ID</div>';
        }
        $src = match ($provider) {
            'vimeo' => 'https://player.vimeo.com/video/' . $videoId,
            'bilibili' => 'https://player.bilibili.com/player.html?bvid=' . $videoId,
            default => 'https://www.youtube-nocookie.com/embed/' . $videoId,
        };
        $ratio = in_array((string)($content['ratio'] ?? '16-9'), ['16-9', '4-3', '1-1'], true)
            ? (string)$content['ratio'] : '16-9';
        $title = (string)($content['title'] ?? '');
        return '<div class="ve-embed ve-embed-' . $ratio . '">'
            . '<iframe src="' . e($src) . '" title="' . e($title !== '' ? $title : '嵌入视频') . '"'
            . ' loading="lazy" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"'
            . ' frameborder="0"></iframe></div>';
    }
}
