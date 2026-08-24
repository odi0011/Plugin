<?php
/**
 * 可视化编辑器：样式编译器。
 *
 * 把文档树里的「属性 => 已校验值」编译成 CSS。选择器完全由这里生成，
 * 用户永远碰不到——他们能影响的只有属性名（必须在白名单里）和值（必须过类型校验）。
 * 因此页面上出现的每一条声明都是这段代码写出来的，不是从数据库里照抄的。
 *
 * 断点顺序是桌面 → 平板 → 手机，全部用 max-width：小屏样式覆盖大屏，
 * 这样只在桌面设一次的属性会自然向下继承，用户不必三个断点各填一遍。
 */
if (!defined('CODE_SCHEMA_VERSION')) exit;

final class VisualEditorStyleCompiler
{
    /** 文档根容器类名。1.1.0 起按内容源（如 page-12）而不是文档表 id。 */
    public static function rootClass(string $sourceKey): string
    {
        return 've-doc-' . self::safeKey($sourceKey);
    }

    /**
     * 源键只允许小写字母数字与一个连字符 id：它要进 CSS 选择器和
     * data-ve-tree 标记，形态必须可预测。不合法的键整体退化成 :not(*)，
     * 页面最多丢样式，不可能被注入选择器。
     */
    public static function safeKey(string $sourceKey): string
    {
        return preg_match('/^[a-z][a-z0-9_-]{1,29}-[1-9][0-9]{0,9}$/', $sourceKey) === 1 ? $sourceKey : 'invalid';
    }

    /**
     * 结构基础样式。1.1.0 起它随编译产物一起写进内容字段——插件停用后，
     * 页面的排布仍然成立，这正是「卸载不影响内容」的一部分。
     * 只放结构与默认外观，颜色走站点 CSS 变量，页面跟着主题走。
     */
    public static function baseCss(): string
    {
        return <<<'CSS'
.ve-doc{--ve-container:1200px;width:100%}
.ve-doc img{max-width:100%}
.ve-section{box-sizing:border-box;display:flow-root;position:relative;width:100%}
.ve-section-inner{align-items:stretch;box-sizing:border-box;display:flex;flex-wrap:wrap;gap:1.5rem;width:100%}
.ve-section-boxed>.ve-section-inner{margin-inline:auto;max-width:var(--ve-container,1200px);padding-inline:1rem}
.ve-section-full>.ve-section-inner{max-width:none;padding-inline:0}
.ve-column{box-sizing:border-box;display:flow-root;flex:0 0 100%;max-width:100%;min-width:0;position:relative}
.ve-widget{box-sizing:border-box;position:relative}
.ve-widget+.ve-widget{margin-top:1rem}
.ve-align-left{text-align:left}.ve-align-center{text-align:center}.ve-align-right{text-align:right}.ve-align-justify{text-align:justify}
.ve-heading{line-height:1.25;margin:0;overflow-wrap:break-word}
.ve-text{overflow-wrap:break-word}.ve-text>:first-child{margin-top:0}.ve-text>:last-child{margin-bottom:0}
.ve-image{margin:0}
.ve-image img{display:inline-block;height:auto;max-width:100%;vertical-align:middle}
.ve-image-placeholder{border:1px dashed var(--ui-border,#d4d4d8);border-radius:6px;color:var(--ui-text-muted,#71717a);font-size:.875rem;padding:2rem 1rem;text-align:center}
.ve-button-wrap{line-height:1}
.ve-button{border:1px solid transparent;border-radius:6px;cursor:pointer;display:inline-block;font-weight:500;line-height:1.4;text-decoration:none;transition:filter .15s ease,background-color .15s ease}
.ve-button-sm{font-size:.875rem;padding:.375rem .875rem}.ve-button-md{font-size:1rem;padding:.5rem 1.25rem}.ve-button-lg{font-size:1.125rem;padding:.75rem 1.75rem}
.ve-button-primary{background:var(--ui-primary,#2563eb);color:var(--ui-primary-contrast,#fff)}
.ve-button-outline{background:transparent;border-color:var(--ui-primary,#2563eb);color:var(--ui-primary-text,#2563eb)}
.ve-button-ghost{background:transparent;color:var(--ui-primary-text,#2563eb)}
.ve-button:hover{filter:brightness(.94)}
.ve-list{margin:0;padding-left:1.5rem}.ve-list>li+li{margin-top:.25rem}
.ve-list-none{list-style:none;padding-left:0}
.ve-list-check{list-style:none;padding-left:0}.ve-list-check>li::before{content:"✓";margin-right:.5rem}
.ve-quote{border-left:3px solid var(--ui-border,#d4d4d8);margin:0;padding-left:1rem}
.ve-quote p{margin:0}
.ve-quote cite{color:var(--ui-text-muted,#71717a);display:block;font-size:.875rem;margin-top:.5rem}
.ve-divider{border:0;border-top:1px solid var(--ui-border,#d4d4d8);margin:0;opacity:1;width:100%}
.ve-spacer{width:100%}
.ve-embed{position:relative;width:100%}
.ve-embed::before{content:"";display:block}
.ve-embed-16-9::before{padding-top:56.25%}.ve-embed-4-3::before{padding-top:75%}.ve-embed-1-1::before{padding-top:100%}
.ve-embed iframe{border:0;height:100%;left:0;position:absolute;top:0;width:100%}
.ve-html>:first-child{margin-top:0}.ve-html>:last-child{margin-bottom:0}
.ve-html img{height:auto;max-width:100%}
CSS;
    }

    /**
     * 编译整棵树：结构基础样式 + 文档个性化声明。
     * 返回可直接放进 <style> 的内容（不含 style 标签本身）。
     */
    public static function compile(string $sourceKey, array $tree): string
    {
        $root = '.' . self::rootClass($sourceKey);
        $breakpoints = VisualEditorSettings::breakpoints();
        $buckets = ['desktop' => [], 'tablet' => [], 'mobile' => []];

        // 文档级样式落在根容器自身上。
        self::collect($buckets, $root, $tree['style'] ?? []);
        $buckets['desktop'][] = $root . ' { --ve-container: ' . VisualEditorSettings::containerMax() . 'px; }';

        foreach (($tree['sections'] ?? []) as $section) {
            $sectionSelector = $root . ' ' . self::elementSelector((string)($section['id'] ?? ''));
            self::collect($buckets, $sectionSelector, $section['style'] ?? []);

            foreach (($section['columns'] ?? []) as $column) {
                $columnSelector = $root . ' ' . self::elementSelector((string)($column['id'] ?? ''));
                self::collect($buckets, $columnSelector, $column['style'] ?? []);
                self::collectWidth($buckets, $columnSelector, $column['width'] ?? []);

                foreach (($column['widgets'] ?? []) as $widget) {
                    $widgetSelector = $root . ' ' . self::elementSelector((string)($widget['id'] ?? ''));
                    self::collect($buckets, $widgetSelector, $widget['style'] ?? []);
                }
            }
        }

        $css = self::baseCss() . "\n" . implode("\n", $buckets['desktop']);
        if ($buckets['tablet'] !== []) {
            $css .= "\n@media (max-width: " . $breakpoints['tablet'] . "px) {\n"
                . implode("\n", $buckets['tablet']) . "\n}";
        }
        if ($buckets['mobile'] !== []) {
            $css .= "\n@media (max-width: " . $breakpoints['mobile'] . "px) {\n"
                . implode("\n", $buckets['mobile']) . "\n}";
        }
        return trim($css);
    }

    /** 元素选择器。id 形态由 DocumentShape::validElementId() 保证，因此可以安全地拼进选择器。 */
    public static function elementSelector(string $elementId): string
    {
        return VisualEditorDocumentShape::validElementId($elementId) ? '[data-ve="' . $elementId . '"]' : ':not(*)';
    }

    /**
     * @param array<string,list<string>> $buckets
     * @param array<string,mixed>        $style
     */
    private static function collect(array &$buckets, string $selector, mixed $style): void
    {
        if (!is_array($style)) return;
        $properties = VisualEditorSchema::styleProperties();
        foreach (VisualEditorSchema::BREAKPOINTS as $breakpoint) {
            $values = is_array($style[$breakpoint] ?? null) ? $style[$breakpoint] : [];
            $declarations = [];
            foreach ($values as $property => $value) {
                $definition = $properties[strtolower(trim((string)$property))] ?? null;
                if ($definition === null) continue;
                // 二次校验：css_cache 可能是旧版本写的，编译时不信任已存的值。
                $normalized = VisualEditorValue::style((string)$property, $value);
                if ($normalized === null) continue;
                $declarations[] = $definition[0] . ': ' . self::cssValue($definition[1], $normalized) . ';';
            }
            if ($declarations !== []) {
                $buckets[$breakpoint][] = $selector . ' { ' . implode(' ', $declarations) . ' }';
            }
        }
    }

    /** @param array<string,list<string>> $buckets */
    private static function collectWidth(array &$buckets, string $selector, mixed $width): void
    {
        if (!is_array($width)) return;
        foreach (VisualEditorSchema::BREAKPOINTS as $breakpoint) {
            $value = $width[$breakpoint] ?? null;
            if (!is_numeric($value)) continue;
            $percent = max(5, min(100, (int)round((float)$value)));
            $buckets[$breakpoint][] = $selector . ' { flex: 0 0 ' . $percent . '%; max-width: ' . $percent . '%; }';
        }
    }

    /** 值的最终成形。image 与 shadow 需要包一层，其余按原样输出（已过校验）。 */
    private static function cssValue(string $valueType, string $normalized): string
    {
        if ($valueType === 'image') {
            return 'url("' . str_replace(['"', '\\'], ['%22', '%5C'], $normalized) . '")';
        }
        if ($valueType === 'shadow') {
            return VisualEditorSchema::shadowValue($normalized);
        }
        return $normalized;
    }
}
