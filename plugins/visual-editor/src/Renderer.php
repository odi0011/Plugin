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
            . self::widgetBody($type, $content, $id)
            . self::editorHandle($editing, (string)$definition['label'])
            . '</div>';
    }

    /**
     * 控件正文。
     *
     * $id 参与渲染是因为纯 CSS 交互需要唯一名：选项卡靠一组同名 radio、
     * 手风琴靠 <details name>，两者都必须在同一页面内互不串台。
     *
     * @param array<string,mixed> $content
     */
    private static function widgetBody(string $type, array $content, string $id = ''): string
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

            case 'icon':
                return '<div class="ve-icon ve-align-' . self::align($content) . '">'
                    . self::iconTag($content, (int)($content['size'] ?? 40)) . '</div>';

            case 'iconbox':
                return self::iconBox($content);

            case 'imagebox':
                return self::imageBox($content);

            case 'alert':
                return self::alert($content);

            case 'progress':
                return self::progress($content);

            case 'gallery':
                return self::gallery($content);

            case 'carousel':
                return self::carousel($content);

            case 'logos':
                return self::logos($content);

            case 'tabs':
                return self::tabs($content, $id);

            case 'accordion':
                return self::accordion($content, $id);

            case 'iconlist':
                return self::iconList($content);

            case 'counter':
                return self::counter($content);

            case 'rating':
                return self::rating($content);

            case 'social':
                return self::social($content);

            case 'pricing':
                return self::pricing($content);

            case 'cta':
                return self::cta($content);

            case 'testimonial':
                return self::testimonial($content);

            case 'timeline':
                return self::timeline($content);

            case 'flipbox':
                return self::flipBox($content);

            case 'table':
                return self::table($content);

            case 'map':
                return self::map($content);

            case 'anchor':
                $name = (string)($content['name'] ?? '');
                $anchor = preg_match('/^[A-Za-z0-9_-]{1,40}$/', $name) === 1 ? $name : '';
                return $anchor === ''
                    ? '<span class="ve-anchor" aria-hidden="true"></span>'
                    : '<span class="ve-anchor" id="' . e($anchor) . '" aria-hidden="true"></span>';
        }
        return '';
    }

    /**
     * 重复字段的行，永远拿到一个数组列表。
     *
     * @param array<string,mixed> $content
     * @return list<array<string,mixed>>
     */
    private static function rows(array $content, string $field = 'items'): array
    {
        $out = [];
        foreach ((is_array($content[$field] ?? null) ? $content[$field] : []) as $row) {
            if (is_array($row)) $out[] = $row;
        }
        return $out;
    }

    /** 行里的图片（可带链接），画廊 / 轮播 / Logo 墙共用。 @param array<string,mixed> $row */
    private static function rowImage(array $row, string $className): string
    {
        $src = (string)($row['src'] ?? '');
        $media = $src !== '' && VisualEditorValue::isMediaUrl($src)
            ? '<img src="' . e($src) . '" alt="' . e((string)($row['alt'] ?? '')) . '" loading="lazy">'
            : '<span class="ve-image-placeholder">未选择图片</span>';
        $url = (string)($row['url'] ?? '');
        if ($url !== '' && VisualEditorValue::isSafeUrl($url)) {
            return '<a class="' . $className . '" href="' . e($url) . '">' . $media . '</a>';
        }
        return '<div class="' . $className . '">' . $media . '</div>';
    }

    /** @param array<string,mixed> $content */
    private static function gallery(array $content): string
    {
        $columns = max(1, min(6, (int)($content['columns'] ?? 3)));
        $ratio = self::ratio($content, 'auto');
        $radius = max(0, min(40, (int)($content['radius'] ?? 8)));
        $cells = '';
        foreach (self::rows($content) as $row) {
            $cells .= self::rowImage($row, 've-gallery-cell');
        }
        if ($cells === '') return '';
        $inline = '--ve-cols:' . $columns . ';--ve-radius:' . $radius . 'px';
        return '<div class="ve-gallery ve-ratio-' . $ratio . '" style="' . e($inline) . '">' . $cells . '</div>';
    }

    /**
     * 轮播：横向 scroll-snap，**不带一行 JavaScript**。
     * 前台不注入脚本是这个插件的硬约束（停用插件页面要照旧），
     * 所以「轮播」这里的含义是可滑动、按张吸附，而不是自动播放。
     *
     * @param array<string,mixed> $content
     */
    private static function carousel(array $content): string
    {
        $perView = max(1, min(5, (int)($content['perview'] ?? 3)));
        $ratio = self::ratio($content, 'auto');
        $radius = max(0, min(40, (int)($content['radius'] ?? 8)));
        $cells = '';
        foreach (self::rows($content) as $row) {
            $cells .= self::rowImage($row, 've-carousel-cell');
        }
        if ($cells === '') return '';
        $inline = '--ve-per:' . $perView . ';--ve-radius:' . $radius . 'px';
        return '<div class="ve-carousel ve-ratio-' . $ratio . '" style="' . e($inline) . '" tabindex="0">'
            . $cells . '</div>';
    }

    /** @param array<string,mixed> $content */
    private static function logos(array $content): string
    {
        $columns = max(2, min(8, (int)($content['columns'] ?? 4)));
        $gray = (string)($content['grayscale'] ?? 'yes') !== 'no';
        $cells = '';
        foreach (self::rows($content) as $row) {
            $cells .= self::rowImage($row, 've-logos-cell');
        }
        if ($cells === '') return '';
        return '<div class="ve-logos' . ($gray ? ' ve-logos-gray' : '') . '"'
            . ' style="' . e('--ve-cols:' . $columns) . '">' . $cells . '</div>';
    }

    /**
     * 选项卡：一组同名 radio + `:checked ~` 兄弟选择器，纯 CSS 切换。
     * name 用控件 id，所以同一页面放多个选项卡不会互相抢选中。
     *
     * @param array<string,mixed> $content
     */
    private static function tabs(array $content, string $id): string
    {
        $rows = self::rows($content);
        if ($rows === []) return '';
        $group = 've-tabs-' . (VisualEditorDocumentShape::validElementId($id) ? $id : 'x');
        $inputs = '';
        $labels = '';
        $panels = '';
        foreach ($rows as $index => $row) {
            $inputId = $group . '-' . $index;
            $inputs .= '<input class="ve-tabs-radio" type="radio" name="' . e($group) . '"'
                . ' id="' . e($inputId) . '"' . ($index === 0 ? ' checked' : '') . '>';
            $labels .= '<label class="ve-tabs-label" for="' . e($inputId) . '">'
                . e((string)($row['title'] ?? ('第 ' . ($index + 1) . ' 项'))) . '</label>';
            // 面板内容是已消毒的富文本片段，直出。
            $panels .= '<div class="ve-tabs-panel">' . (string)($row['html'] ?? '') . '</div>';
        }
        return '<div class="ve-tabs">' . $inputs
            . '<div class="ve-tabs-nav">' . $labels . '</div>'
            . '<div class="ve-tabs-panels">' . $panels . '</div></div>';
    }

    /**
     * 手风琴：原生 <details>。single=yes 时给同一组 details 加 name，
     * 由浏览器自己保证互斥——比任何脚本实现都稳。
     *
     * @param array<string,mixed> $content
     */
    private static function accordion(array $content, string $id): string
    {
        $rows = self::rows($content);
        if ($rows === []) return '';
        $single = (string)($content['single'] ?? 'yes') !== 'no';
        $openFirst = (string)($content['openfirst'] ?? 'yes') !== 'no';
        $group = 've-acc-' . (VisualEditorDocumentShape::validElementId($id) ? $id : 'x');
        $out = '<div class="ve-accordion">';
        foreach ($rows as $index => $row) {
            $out .= '<details class="ve-accordion-item"'
                . ($single ? ' name="' . e($group) . '"' : '')
                . ($openFirst && $index === 0 ? ' open' : '') . '>'
                . '<summary class="ve-accordion-head">'
                . e((string)($row['title'] ?? ('第 ' . ($index + 1) . ' 项')))
                . '</summary>'
                . '<div class="ve-accordion-body">' . (string)($row['html'] ?? '') . '</div>'
                . '</details>';
        }
        return $out . '</div>';
    }

    /** @param array<string,mixed> $content */
    private static function iconList(array $content): string
    {
        $rows = self::rows($content);
        if ($rows === []) return '';
        $size = max(12, min(48, (int)($content['size'] ?? 18)));
        $color = VisualEditorValue::style('text_color', $content['color'] ?? '');
        $divider = (string)($content['divider'] ?? 'no') === 'yes';
        $items = '';
        foreach ($rows as $row) {
            $text = (string)($row['text'] ?? '');
            if ($text === '') continue;
            $icon = self::iconTag(['name' => $row['name'] ?? '', 'color' => $content['color'] ?? ''], $size);
            $body = e($text);
            $url = (string)($row['url'] ?? '');
            if ($url !== '' && VisualEditorValue::isSafeUrl($url)) {
                $body = '<a href="' . e($url) . '">' . $body . '</a>';
            }
            $items .= '<li class="ve-iconlist-item">'
                . '<span class="ve-iconlist-icon">' . $icon . '</span>'
                . '<span class="ve-iconlist-text">' . $body . '</span></li>';
        }
        if ($items === '') return '';
        $inline = $color !== null && $color !== '' ? ' style="' . e('--ve-icon-color:' . $color) . '"' : '';
        return '<ul class="ve-iconlist' . ($divider ? ' ve-iconlist-divided' : '') . '"' . $inline . '>'
            . $items . '</ul>';
    }

    /**
     * 计数器：渲染静态数字。
     * Elementor 的滚动到视口再动画数一遍是脚本行为，前台零 JS 的约束下
     * 与其塞一段脚本，不如老实显示最终值——信息一点没少。
     *
     * @param array<string,mixed> $content
     */
    private static function counter(array $content): string
    {
        $value = (string)($content['value'] ?? '');
        if ($value === '') return '';
        $label = (string)($content['label'] ?? '');
        return '<div class="ve-counter ve-align-' . self::align($content) . '">'
            . '<div class="ve-counter-number">'
            . '<span class="ve-counter-prefix">' . e((string)($content['prefix'] ?? '')) . '</span>'
            . '<span class="ve-counter-value">' . e($value) . '</span>'
            . '<span class="ve-counter-suffix">' . e((string)($content['suffix'] ?? '')) . '</span>'
            . '</div>'
            . ($label !== '' ? '<div class="ve-counter-label">' . e($label) . '</div>' : '')
            . '</div>';
    }

    /** 星级：value 是 0-10 的半星刻度，避免引入小数字段。 @param array<string,mixed> $content */
    private static function rating(array $content): string
    {
        $half = max(0, min(10, (int)($content['value'] ?? 0)));
        $size = max(12, min(48, (int)($content['size'] ?? 20)));
        $color = VisualEditorValue::style('text_color', $content['color'] ?? '');
        $stars = '';
        for ($i = 1; $i <= 5; $i++) {
            $name = $half >= $i * 2 ? 'star-fill' : ($half >= $i * 2 - 1 ? 'star-half' : 'star');
            $stars .= '<i class="' . e(VisualEditorSchema::iconClass($name)) . '" aria-hidden="true"></i>';
        }
        $inline = 'font-size:' . $size . 'px;'
            . ($color !== null && $color !== '' ? 'color:' . $color . ';' : '');
        $text = number_format($half / 2, 1) . ' / 5';
        return '<div class="ve-rating ve-align-' . self::align($content) . '"'
            . ' role="img" aria-label="' . e('评分 ' . $text) . '">'
            . '<span class="ve-rating-stars" style="' . e($inline) . '">' . $stars . '</span>'
            . ((string)($content['showvalue'] ?? 'no') === 'yes'
                ? '<span class="ve-rating-value">' . e($text) . '</span>' : '')
            . '</div>';
    }

    /** @param array<string,mixed> $content */
    private static function social(array $content): string
    {
        $rows = self::rows($content);
        if ($rows === []) return '';
        $size = max(12, min(48, (int)($content['size'] ?? 18)));
        $shape = in_array((string)($content['shape'] ?? 'circle'), ['circle', 'square', 'plain'], true)
            ? (string)$content['shape'] : 'circle';
        $items = '';
        foreach ($rows as $row) {
            $url = (string)($row['url'] ?? '');
            $icon = '<i class="' . e(VisualEditorSchema::iconClass((string)($row['name'] ?? '')))
                . '" aria-hidden="true"></i>';
            $label = (string)($row['text'] ?? '');
            $inner = $icon . ($label !== '' ? '<span class="ve-social-sr">' . e($label) . '</span>' : '');
            $items .= $url !== '' && VisualEditorValue::isSafeUrl($url)
                ? '<a class="ve-social-item" href="' . e($url) . '"'
                    . ($label !== '' ? ' title="' . e($label) . '"' : '') . '>' . $inner . '</a>'
                : '<span class="ve-social-item">' . $inner . '</span>';
        }
        return '<div class="ve-social ve-social-' . $shape . ' ve-align-' . self::align($content) . '"'
            . ' style="' . e('--ve-social-size:' . $size . 'px') . '">' . $items . '</div>';
    }

    /** @param array<string,mixed> $content */
    private static function pricing(array $content): string
    {
        $featured = (string)($content['featured'] ?? 'no') === 'yes';
        $ribbon = (string)($content['ribbon'] ?? '');
        $features = '';
        foreach (preg_split('/\n/', (string)($content['features'] ?? '')) ?: [] as $line) {
            $line = trim((string)$line);
            if ($line === '') continue;
            $features .= '<li>' . e($line) . '</li>';
        }
        $button = self::button([
            'text' => $content['btntext'] ?? '', 'url' => $content['btnurl'] ?? '',
            'variant' => $featured ? 'primary' : 'outline', 'size' => 'md', 'align' => 'center',
        ]);
        return '<div class="ve-pricing' . ($featured ? ' ve-pricing-featured' : '') . '">'
            . ($ribbon !== '' ? '<span class="ve-pricing-ribbon">' . e($ribbon) . '</span>' : '')
            . '<div class="ve-pricing-plan">' . e((string)($content['plan'] ?? '')) . '</div>'
            . '<div class="ve-pricing-price">'
            . '<span class="ve-pricing-currency">' . e((string)($content['currency'] ?? '')) . '</span>'
            . '<span class="ve-pricing-amount">' . e((string)($content['price'] ?? '')) . '</span>'
            . '<span class="ve-pricing-period">' . e((string)($content['period'] ?? '')) . '</span>'
            . '</div>'
            . ($features !== '' ? '<ul class="ve-pricing-features">' . $features . '</ul>' : '')
            . $button
            . '</div>';
    }

    /** @param array<string,mixed> $content */
    private static function cta(array $content): string
    {
        $src = (string)($content['src'] ?? '');
        $height = max(120, min(800, (int)($content['height'] ?? 320)));
        $overlay = in_array((string)($content['overlay'] ?? 'dark'), ['none', 'light', 'dark'], true)
            ? (string)$content['overlay'] : 'dark';
        $inline = 'min-height:' . $height . 'px;';
        if ($src !== '' && VisualEditorValue::isMediaUrl($src)) {
            $inline .= 'background-image:url(' . str_replace(['(', ')', '"', "'"], '', $src) . ');';
        }
        $title = (string)($content['title'] ?? '');
        $text = (string)($content['text'] ?? '');
        return '<div class="ve-cta ve-cta-' . $overlay . ' ve-align-' . self::align($content) . '"'
            . ' style="' . e($inline) . '">'
            . '<div class="ve-cta-body">'
            . ($title !== '' ? '<h3 class="ve-cta-title">' . e($title) . '</h3>' : '')
            . ($text !== '' ? '<p class="ve-cta-text">' . e($text) . '</p>' : '')
            . self::button([
                'text' => $content['btntext'] ?? '', 'url' => $content['btnurl'] ?? '',
                'variant' => 'primary', 'size' => 'lg', 'align' => self::align($content),
            ])
            . '</div></div>';
    }

    /** @param array<string,mixed> $content */
    private static function testimonial(array $content): string
    {
        $text = (string)($content['text'] ?? '');
        if ($text === '') return '';
        $src = (string)($content['src'] ?? '');
        $avatar = $src !== '' && VisualEditorValue::isMediaUrl($src)
            ? '<img class="ve-testimonial-avatar" src="' . e($src) . '" alt="" loading="lazy">'
            : '';
        $name = (string)($content['name'] ?? '');
        $role = (string)($content['role'] ?? '');
        return '<figure class="ve-testimonial ve-align-' . self::align($content) . '">'
            . '<blockquote class="ve-testimonial-text">' . e($text) . '</blockquote>'
            . '<figcaption class="ve-testimonial-meta">' . $avatar
            . '<span class="ve-testimonial-who">'
            . ($name !== '' ? '<span class="ve-testimonial-name">' . e($name) . '</span>' : '')
            . ($role !== '' ? '<span class="ve-testimonial-role">' . e($role) . '</span>' : '')
            . '</span></figcaption></figure>';
    }

    /** @param array<string,mixed> $content */
    private static function timeline(array $content): string
    {
        $rows = self::rows($content);
        if ($rows === []) return '';
        $color = VisualEditorValue::style('background_color', $content['color'] ?? '');
        $items = '';
        foreach ($rows as $row) {
            $date = (string)($row['date'] ?? '');
            $title = (string)($row['title'] ?? '');
            $text = (string)($row['text'] ?? '');
            if ($date === '' && $title === '' && $text === '') continue;
            $items .= '<li class="ve-timeline-item">'
                . '<span class="ve-timeline-dot" aria-hidden="true"></span>'
                . ($date !== '' ? '<span class="ve-timeline-date">' . e($date) . '</span>' : '')
                . ($title !== '' ? '<h4 class="ve-timeline-title">' . e($title) . '</h4>' : '')
                . ($text !== '' ? '<p class="ve-timeline-text">' . e($text) . '</p>' : '')
                . '</li>';
        }
        if ($items === '') return '';
        $inline = $color !== null && $color !== '' ? ' style="' . e('--ve-line:' . $color) . '"' : '';
        return '<ul class="ve-timeline"' . $inline . '>' . $items . '</ul>';
    }

    /** 翻转框：:hover / :focus-within + transform，同样零脚本。 @param array<string,mixed> $content */
    private static function flipBox(array $content): string
    {
        $height = max(160, min(600, (int)($content['height'] ?? 260)));
        $front = '<div class="ve-flip-face ve-flip-front">'
            . '<div class="ve-flip-icon">' . self::iconTag($content, 36) . '</div>'
            . '<h3 class="ve-flip-title">' . e((string)($content['title'] ?? '')) . '</h3>'
            . '<p class="ve-flip-text">' . e((string)($content['text'] ?? '')) . '</p></div>';
        $back = '<div class="ve-flip-face ve-flip-back">'
            . '<h3 class="ve-flip-title">' . e((string)($content['backtitle'] ?? '')) . '</h3>'
            . '<p class="ve-flip-text">' . e((string)($content['backtext'] ?? '')) . '</p>'
            . self::button([
                'text' => $content['btntext'] ?? '', 'url' => $content['btnurl'] ?? '',
                'variant' => 'outline', 'size' => 'sm', 'align' => 'center',
            ])
            . '</div>';
        return '<div class="ve-flip" style="' . e('min-height:' . $height . 'px') . '" tabindex="0">'
            . '<div class="ve-flip-inner">' . $front . $back . '</div></div>';
    }

    /** 表格：每行一条，用 | 分列。 @param array<string,mixed> $content */
    private static function table(array $content): string
    {
        $lines = preg_split('/\n/', (string)($content['rows'] ?? '')) ?: [];
        $header = (string)($content['header'] ?? 'yes') !== 'no';
        $classes = 've-table'
            . ((string)($content['striped'] ?? 'yes') !== 'no' ? ' ve-table-striped' : '')
            . ((string)($content['bordered'] ?? 'yes') !== 'no' ? ' ve-table-bordered' : '');
        $head = '';
        $body = '';
        $index = 0;
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '') continue;
            $cells = '';
            $tag = $header && $index === 0 ? 'th' : 'td';
            foreach (explode('|', $line) as $cell) {
                $cells .= '<' . $tag . '>' . e(trim($cell)) . '</' . $tag . '>';
            }
            if ($header && $index === 0) $head = '<thead><tr>' . $cells . '</tr></thead>';
            else $body .= '<tr>' . $cells . '</tr>';
            $index++;
        }
        if ($head === '' && $body === '') return '';
        return '<div class="ve-table-wrap"><table class="' . $classes . '">'
            . $head . ($body !== '' ? '<tbody>' . $body . '</tbody>' : '') . '</table></div>';
    }

    /**
     * 地图：只接受「地点关键词」，src 由这里拼。
     * 与视频控件同一条理由——不让用户直接填 iframe 地址。
     *
     * @param array<string,mixed> $content
     */
    private static function map(array $content): string
    {
        $query = trim((string)($content['query'] ?? ''));
        if ($query === '') return '<div class="ve-image-placeholder">未填写地点</div>';
        $zoom = max(1, min(20, (int)($content['zoom'] ?? 14)));
        $ratio = self::ratio($content, '16-9');
        $src = 'https://www.google.com/maps?q=' . rawurlencode($query) . '&z=' . $zoom . '&output=embed';
        return '<div class="ve-embed ve-embed-' . $ratio . '">'
            . '<iframe src="' . e($src) . '" title="' . e($query) . '" loading="lazy"'
            . ' referrerpolicy="strict-origin-when-cross-origin" frameborder="0"></iframe></div>';
    }

    /** @param array<string,mixed> $content */
    private static function ratio(array $content, string $fallback): string
    {
        $ratio = (string)($content['ratio'] ?? $fallback);
        return in_array($ratio, ['auto', '16-9', '4-3', '1-1'], true) ? $ratio : $fallback;
    }

    /**
     * 图标标签。字号与颜色走内联样式而不是编译进 CSS：它们是控件内容
     * （每个实例一份），不是样式白名单里那种可继承的属性。
     *
     * @param array<string,mixed> $content
     */
    private static function iconTag(array $content, int $size): string
    {
        $px = max(12, min(160, $size));
        $color = VisualEditorValue::style('text_color', $content['color'] ?? '');
        $inline = 'font-size:' . $px . 'px;line-height:1;'
            . ($color !== null && $color !== '' ? 'color:' . $color . ';' : '');
        return '<i class="' . e(VisualEditorSchema::iconClass((string)($content['name'] ?? '')))
            . '" style="' . e($inline) . '" aria-hidden="true"></i>';
    }

    /** @param array<string,mixed> $content */
    private static function iconBox(array $content): string
    {
        $layout = (string)($content['layout'] ?? 'top') === 'left' ? 'left' : 'top';
        $title = (string)($content['title'] ?? '');
        $text = (string)($content['text'] ?? '');
        return '<div class="ve-iconbox ve-iconbox-' . $layout . ' ve-align-' . self::align($content) . '">'
            . '<div class="ve-iconbox-icon">' . self::iconTag($content, (int)($content['size'] ?? 32)) . '</div>'
            . '<div class="ve-iconbox-body">'
            . ($title !== '' ? '<h3 class="ve-iconbox-title">' . e($title) . '</h3>' : '')
            . ($text !== '' ? '<p class="ve-iconbox-text">' . e($text) . '</p>' : '')
            . '</div></div>';
    }

    /** @param array<string,mixed> $content */
    private static function imageBox(array $content): string
    {
        $src = (string)($content['src'] ?? '');
        $media = $src !== '' && VisualEditorValue::isMediaUrl($src)
            ? '<img src="' . e($src) . '" alt="' . e((string)($content['alt'] ?? '')) . '" loading="lazy">'
            : '<div class="ve-image-placeholder">未选择图片</div>';
        $url = (string)($content['url'] ?? '');
        if ($url !== '' && VisualEditorValue::isSafeUrl($url)) {
            $media = '<a href="' . e($url) . '">' . $media . '</a>';
        }
        $title = (string)($content['title'] ?? '');
        $text = (string)($content['text'] ?? '');
        return '<div class="ve-imagebox ve-align-' . self::align($content) . '">'
            . '<div class="ve-imagebox-media">' . $media . '</div>'
            . '<div class="ve-imagebox-body">'
            . ($title !== '' ? '<h3 class="ve-imagebox-title">' . e($title) . '</h3>' : '')
            . ($text !== '' ? '<p class="ve-imagebox-text">' . e($text) . '</p>' : '')
            . '</div></div>';
    }

    /** @param array<string,mixed> $content */
    private static function alert(array $content): string
    {
        $tone = in_array((string)($content['tone'] ?? 'info'), ['info', 'success', 'warning', 'danger'], true)
            ? (string)$content['tone'] : 'info';
        $title = (string)($content['title'] ?? '');
        $text = (string)($content['text'] ?? '');
        if ($title === '' && $text === '') return '';
        return '<div class="ve-alert ve-alert-' . $tone . '" role="note">'
            . ($title !== '' ? '<strong class="ve-alert-title">' . e($title) . '</strong>' : '')
            . ($text !== '' ? '<span class="ve-alert-text">' . e($text) . '</span>' : '')
            . '</div>';
    }

    /**
     * 进度条。用 role="progressbar" 而不是纯装饰性 div：读屏用户也该拿到这个数值。
     *
     * @param array<string,mixed> $content
     */
    private static function progress(array $content): string
    {
        $value = max(0, min(100, (int)($content['value'] ?? 0)));
        $label = (string)($content['label'] ?? '');
        $color = VisualEditorValue::style('background_color', $content['color'] ?? '');
        $barStyle = 'width:' . $value . '%;'
            . ($color !== null && $color !== '' ? 'background-color:' . $color . ';' : '');
        $showValue = (string)($content['showvalue'] ?? 'yes') !== 'no';
        return '<div class="ve-progress">'
            . ($label !== '' || $showValue
                ? '<div class="ve-progress-head">'
                    . ($label !== '' ? '<span class="ve-progress-label">' . e($label) . '</span>' : '')
                    . ($showValue ? '<span class="ve-progress-value">' . $value . '%</span>' : '')
                    . '</div>'
                : '')
            . '<div class="ve-progress-track" role="progressbar" aria-valuenow="' . $value . '"'
            . ' aria-valuemin="0" aria-valuemax="100"'
            . ($label !== '' ? ' aria-label="' . e($label) . '"' : '') . '>'
            . '<div class="ve-progress-bar" style="' . e($barStyle) . '"></div>'
            . '</div></div>';
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
