<?php
/**
 * 可视化编辑器：值校验与 HTML 消毒。
 *
 * 所有写入路径（后台编辑器保存、公开 API、Agent 动作）都只经过这里，
 * 因此「界面拦住了但接口没拦」在结构上不成立。
 *
 * 校验的返回约定：合法则返回规范化后的值，非法返回 null。
 * 调用方一律把 null 当成「这个字段不写」，而不是写个空值糊过去——
 * 静默降级会让用户以为设置生效了。
 */
if (!defined('CODE_SCHEMA_VERSION')) exit;

final class VisualEditorValue
{
    /** 富文本允许的标签 => 允许的属性。不在表内的标签会被剥掉但保留其文字。 */
    private const RICH_TAGS = [
        'p' => [], 'br' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [],
        'u' => [], 's' => [], 'span' => [], 'small' => [], 'mark' => [],
        'ul' => [], 'ol' => [], 'li' => [], 'a' => ['href', 'target', 'rel', 'title'],
        'code' => [], 'sub' => [], 'sup' => [],
    ];

    /** 自定义 HTML 控件额外允许的结构标签（仍然不含 script / style / form / iframe）。 */
    private const BLOCK_TAGS = [
        'div' => [], 'section' => [], 'article' => [], 'header' => [], 'footer' => [],
        'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
        'figure' => [], 'figcaption' => [], 'blockquote' => ['cite'], 'hr' => [],
        'table' => [], 'thead' => [], 'tbody' => [], 'tfoot' => [], 'tr' => [],
        'th' => ['colspan', 'rowspan', 'scope'], 'td' => ['colspan', 'rowspan'],
        'img' => ['src', 'alt', 'width', 'height', 'loading'],
        'dl' => [], 'dt' => [], 'dd' => [], 'pre' => [],
    ];

    /**
     * 按「字段类型:约束」校验一个控件字段值。
     *
     * @param mixed $value
     * @return array{0:bool,1:mixed} [是否合法, 规范化值]
     */
    public static function field(string $spec, mixed $value): array
    {
        [$type, $constraint] = array_pad(explode(':', $spec, 2), 2, '');

        switch ($type) {
            case 'text':
                $max = (int)$constraint > 0 ? (int)$constraint : VisualEditorSchema::MAX_TEXT_LENGTH;
                $text = self::plainText(is_scalar($value) ? (string)$value : '');
                return mb_strlen($text) > $max ? [false, null] : [true, $text];

            case 'lines':
                [$maxLines, $maxLen] = array_pad(explode(',', $constraint), 2, '');
                return self::lines(is_scalar($value) ? (string)$value : '', (int)$maxLines ?: 20, (int)$maxLen ?: 200);

            case 'rich':
                if (!is_string($value)) return [false, null];
                if (strlen($value) > VisualEditorSchema::MAX_RICH_BYTES) return [false, null];
                return [true, self::sanitizeHtml($value, self::RICH_TAGS)];

            case 'html_block':
                if (!is_string($value)) return [false, null];
                if (strlen($value) > VisualEditorSchema::MAX_RICH_BYTES) return [false, null];
                return [true, self::sanitizeHtml($value, self::RICH_TAGS + self::BLOCK_TAGS)];

            /*
             * 原样 HTML：**故意不做任何改写**，只做长度与控制字符的把关。
             *
             * 这是接管复杂内容时的无损保底通道。之前它走白名单，class 与 <style>
             * 被剥掉，一页手写页面进来就散成裸文本——保底反而成了破坏。
             *
             * 那安全性靠什么？靠权限，而不是靠改写：
             *   - 这个控件的 needs_permission 是 visual_editor.code，只有能用
             *     代码模式的管理员才能新增或修改它——同一个人本来就能在代码
             *     模式里往字段里写任意 HTML/CSS/JS，插件更严没有意义，只有破坏力；
             *   - 前台还有核心 ContentSanitizer 兜底：该行 updated_by 不是启用中的
             *     管理员时，<style> / <script> 一律不落地。
             *
             * 所以这里唯一的处理是删掉 NUL 一类控制字符（它们只会让解析器犯病）。
             */
            case 'raw_html':
                if (!is_string($value)) return [false, null];
                if (strlen($value) > VisualEditorSchema::MAX_RAW_BYTES) return [false, null];
                return [true, (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value)];

            /*
             * 重复字段：'repeater:<子结构>,<最少行>,<最多行>'。
             *
             * 每一行的每个子字段都递归走 field()，所以「行里的图片必须是媒体
             * 地址」「行里的富文本要消毒」这些规则与控件级字段完全同源，
             * 不存在「重复字段是个后门」的可能。
             */
            case 'repeater':
                [$sub, $min, $max] = array_pad(explode(',', $constraint), 3, '');
                $schema = VisualEditorSchema::repeater((string)$sub);
                if ($schema === null || !is_array($value)) return [false, null];
                $minRows = max(0, (int)$min);
                $maxRows = min(VisualEditorSchema::MAX_REPEATER_ROWS, (int)$max > 0 ? (int)$max : VisualEditorSchema::MAX_REPEATER_ROWS);
                $rows = [];
                foreach ($value as $row) {
                    if (count($rows) >= $maxRows) break;
                    if (!is_array($row)) continue;
                    $clean = [];
                    foreach ($schema as $subField => $subSpec) {
                        [$ok, $subValue] = self::field($subSpec, $row[$subField] ?? '');
                        $clean[$subField] = $ok ? $subValue : '';
                    }
                    $rows[] = $clean;
                }
                // 行数不足不算错：补空行比整个字段退回默认值更符合直觉
                // ——用户删到只剩一行时不该看到自己的编辑被整段还原。
                while (count($rows) < $minRows) {
                    $blank = [];
                    foreach ($schema as $subField => $subSpec) {
                        [, $subValue] = self::field($subSpec, '');
                        $blank[$subField] = $subValue ?? '';
                    }
                    $rows[] = $blank;
                }
                return [true, $rows];

            case 'enum':
                $allowed = array_values(array_filter(array_map('trim', explode(',', $constraint))));
                $candidate = is_scalar($value) ? trim((string)$value) : '';
                return in_array($candidate, $allowed, true) ? [true, $candidate] : [false, null];

            case 'number':
                [$min, $max] = array_pad(explode(',', $constraint), 2, '');
                if (!is_numeric($value)) return [false, null];
                $number = (int)round((float)$value);
                if ($min !== '' && $number < (int)$min) return [false, null];
                if ($max !== '' && $number > (int)$max) return [false, null];
                return [true, $number];

            case 'token':
                $max = (int)$constraint > 0 ? (int)$constraint : 64;
                $token = is_scalar($value) ? trim((string)$value) : '';
                if ($token === '') return [true, ''];
                return preg_match('/^[A-Za-z0-9_-]{1,' . $max . '}$/', $token) === 1
                    ? [true, $token]
                    : [false, null];

            case 'color':
                $color = is_scalar($value) ? trim((string)$value) : '';
                if ($color === '') return [true, ''];
                return self::isColor($color) ? [true, $color] : [false, null];

            case 'link':
                $link = is_scalar($value) ? trim((string)$value) : '';
                if ($link === '') return [true, ''];
                return self::isSafeUrl($link) ? [true, $link] : [false, null];

            case 'media':
                $media = is_scalar($value) ? trim((string)$value) : '';
                if ($media === '') return [true, ''];
                return self::isMediaUrl($media) ? [true, $media] : [false, null];
        }
        return [false, null];
    }

    /**
     * 富文本 + 结构标签的白名单。降级路径（无 visual_editor.code 时的原样 HTML 控件）
     * 需要与 html_block 完全同一张表，所以这里公开一份而不是让调用方各拼一次。
     *
     * @return array<string,list<string>>
     */
    public static function richBlockTags(): array
    {
        return self::RICH_TAGS + self::BLOCK_TAGS;
    }

    /**
     * 按样式属性白名单校验一个样式值。合法返回规范化值，非法返回 null。
     */
    public static function style(string $property, mixed $value): ?string
    {
        $definition = VisualEditorSchema::styleProperties()[$property] ?? null;
        if ($definition === null) return null;
        $raw = is_scalar($value) ? trim((string)$value) : '';
        if ($raw === '') return null;
        // CSS 里没有「安全的转义形态」，含结构字符的值一律丢弃而不是转义。
        if (preg_match('/[<>{};@\\\\]/', $raw) || str_contains($raw, '/*')) return null;

        [, $valueType] = $definition;
        switch ($valueType) {
            case 'length':
                return preg_match('/^-?(?:\d{1,4})(?:\.\d{1,2})?(?:px|rem|em|%|vh|vw)?$/', $raw) === 1 ? $raw : null;
            case 'ratio':
                return preg_match('/^\d(?:\.\d{1,2})?$/', $raw) === 1 ? $raw : null;
            case 'color':
                return self::isColor($raw) ? $raw : null;
            case 'image':
                return self::isMediaUrl($raw) ? $raw : null;
            case 'shadow':
                return VisualEditorSchema::shadowValue($raw) !== '' ? strtolower($raw) : null;
            default:
                if (str_starts_with($valueType, 'enum:')) {
                    $allowed = array_values(array_filter(array_map('trim', explode(',', substr($valueType, 5)))));
                    return in_array($raw, $allowed, true) ? $raw : null;
                }
        }
        return null;
    }

    // ============================================================
    // 基础校验
    // ============================================================

    /** 纯文本：去掉控制字符与首尾空白，折叠内部空白但保留换行由调用方决定。 */
    public static function plainText(string $value): string
    {
        $value = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
        return trim((string)preg_replace('/[ \t]+/u', ' ', $value));
    }

    /** @return array{0:bool,1:string|null} */
    private static function lines(string $value, int $maxLines, int $maxLength): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $value) ?: [] as $line) {
            $line = self::plainText((string)$line);
            if ($line === '') continue;
            if (mb_strlen($line) > $maxLength) return [false, null];
            $out[] = $line;
            if (count($out) > $maxLines) return [false, null];
        }
        return [true, implode("\n", $out)];
    }

    private static function isColor(string $value): bool
    {
        if (preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value)) return true;
        if (preg_match('/^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(?:,\s*(?:0|1|0?\.\d{1,3})\s*)?\)$/', $value)) return true;
        // 站点 CSS 变量是允许的：值域由核心的变量白名单守着，这里只认变量名形态。
        return (bool)preg_match('/^var\(--[a-z0-9-]{1,60}\)$/i', $value);
    }

    /**
     * 链接：只接受 http(s)、站内相对路径、mailto 与 tel。
     * 拒绝 javascript:、data:、协议相对（//host）与任何带控制字符的值。
     */
    public static function isSafeUrl(string $value): bool
    {
        if ($value === '' || strlen($value) > 2000) return false;
        if (preg_match('/[\x00-\x1F\x7F\s<>"\']/', $value)) return false;
        if (str_starts_with($value, '//')) return false;
        if (str_starts_with($value, '#')) return (bool)preg_match('/^#[A-Za-z0-9_-]{1,80}$/', $value);
        if (str_starts_with($value, '/')) return !str_contains($value, '..');
        if (preg_match('#^https?://[^/]#i', $value)) return true;
        if (preg_match('/^mailto:[^@\s]+@[^@\s]+$/i', $value)) return true;
        return (bool)preg_match('/^tel:\+?[0-9 ()-]{3,32}$/i', $value);
    }

    /** 媒体：站内相对路径或 http(s) 绝对地址，且扩展名必须是图片。 */
    public static function isMediaUrl(string $value): bool
    {
        if (!self::isSafeUrl($value)) return false;
        if (str_starts_with($value, 'mailto:') || str_starts_with($value, 'tel:') || str_starts_with($value, '#')) {
            return false;
        }
        $path = (string)parse_url($value, PHP_URL_PATH);
        return (bool)preg_match('/\.(?:png|jpe?g|gif|webp|avif|svg)$/i', $path);
    }

    /**
     * HTML 消毒：解析成 DOM，逐节点按标签白名单过滤，再序列化回来。
     *
     * 用 DOM 而不是正则，是因为正则永远拦不住变形（大小写、实体、属性折行、
     * 未闭合标签）。不在白名单里的元素被「解开」——保留子节点丢掉标签本身，
     * 这样删掉一个 <script> 不会顺手删掉整段正文。
     *
     * @param array<string,list<string>> $allowedTags 标签 => 允许的属性
     */
    public static function sanitizeHtml(string $html, array $allowedTags): string
    {
        $html = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $html);
        if (trim($html) === '') return '';
        if (!class_exists('\DOMDocument')) {
            // 没有 DOM 扩展时不猜：整段转义成文本，宁可显示成字符也不放行未过滤的标签。
            return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8"?><div id="ve-root">' . $html . '</div>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $root = $document->getElementById('ve-root');
        if (!$root instanceof \DOMElement) {
            return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        self::sanitizeNode($root, $allowedTags);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $document->saveHTML($child);
        }
        return trim($out);
    }

    /** @param array<string,list<string>> $allowedTags */
    private static function sanitizeNode(\DOMElement $parent, array $allowedTags): void
    {
        foreach (iterator_to_array($parent->childNodes) as $child) {
            if ($child instanceof \DOMText) continue;
            if ($child instanceof \DOMComment) {
                $parent->removeChild($child);
                continue;
            }
            if (!$child instanceof \DOMElement) {
                $parent->removeChild($child);
                continue;
            }
            $tag = strtolower($child->tagName);

            // script / style / 事件源一律整棵删除（连内容），而不是解开——
            // 解开一个 <script> 会把代码变成可见文本，等于把源码贴到页面上。
            if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'template', 'noscript', 'svg', 'math'], true)) {
                $parent->removeChild($child);
                continue;
            }

            if (!array_key_exists($tag, $allowedTags)) {
                self::sanitizeNode($child, $allowedTags);
                while ($child->firstChild !== null) {
                    $parent->insertBefore($child->firstChild, $child);
                }
                $parent->removeChild($child);
                continue;
            }

            self::sanitizeAttributes($child, $tag, $allowedTags[$tag]);
            self::sanitizeNode($child, $allowedTags);
        }
    }

    /** @param list<string> $allowedAttributes */
    private static function sanitizeAttributes(\DOMElement $element, string $tag, array $allowedAttributes): void
    {
        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            $name = strtolower((string)$attribute->nodeName);
            $value = trim((string)$attribute->nodeValue);
            if (!in_array($name, $allowedAttributes, true)) {
                $element->removeAttribute($name);
                continue;
            }
            if (($name === 'href' || $name === 'src') && !self::isSafeUrl($value)) {
                $element->removeAttribute($name);
                continue;
            }
            if ($name === 'src' && !self::isMediaUrl($value)) {
                $element->removeAttribute($name);
                continue;
            }
            if ($name === 'target' && $value !== '_blank' && $value !== '_self') {
                $element->removeAttribute($name);
                continue;
            }
            if (in_array($name, ['width', 'height', 'colspan', 'rowspan'], true)
                && !preg_match('/^\d{1,4}$/', $value)) {
                $element->removeAttribute($name);
                continue;
            }
            if ($name === 'loading' && $value !== 'lazy' && $value !== 'eager') {
                $element->removeAttribute($name);
            }
        }
        // target=_blank 一律补 rel：反向标签劫持不该靠编辑者记得填。
        if ($tag === 'a' && $element->getAttribute('target') === '_blank') {
            $element->setAttribute('rel', 'noopener noreferrer');
        }
    }
}
