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
    /** 文档根容器类名。前台与编辑器画布共用，保证「所见即所得」。 */
    public static function rootClass(int $documentId): string
    {
        return 've-doc-' . max(0, $documentId);
    }

    /**
     * 编译整棵树。返回可直接放进 <style> 的内容（不含 style 标签本身）。
     */
    public static function compile(int $documentId, array $tree): string
    {
        $root = '.' . self::rootClass($documentId);
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

        $css = implode("\n", $buckets['desktop']);
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

    /** 元素选择器。id 形态由 Document::isElementId() 保证，因此可以安全地拼进选择器。 */
    public static function elementSelector(string $elementId): string
    {
        return VisualEditorDocument::isElementId($elementId) ? '[data-ve="' . $elementId . '"]' : ':not(*)';
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
