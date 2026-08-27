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
        return self::structureCss() . "\n" . self::tabsCss();
    }

    /**
     * 标签页的「哪一页可见」没法用一条规则表达：CSS 里没有「第 n 个选中就显示第 n 个」
     * 的通配写法，只能按序号展开。上限与 Schema 的 panel_item 行数上限（12）对齐——
     * 多出来的标签点了不切换，比生成一份无界的 CSS 更可控。
     */
    private static function tabsCss(): string
    {
        $rules = [];
        for ($index = 1; $index <= 12; $index++) {
            $checked = '.ve-tabs-radio:nth-of-type(' . $index . '):checked';
            $rules[] = $checked . ' ~ .ve-tabs-nav .ve-tabs-label:nth-of-type(' . $index . ')'
                . '{border-bottom-color:var(--ui-primary,#2563eb);color:var(--ui-primary-text,#2563eb)}';
            $rules[] = $checked . ' ~ .ve-tabs-panels .ve-tabs-panel:nth-of-type(' . $index . ')'
                . '{display:block}';
        }
        return implode("\n", $rules);
    }

    private static function structureCss(): string
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
.ve-icon{line-height:1}
.ve-icon i,.ve-iconbox-icon i{display:inline-block}
.ve-iconbox{display:flex;flex-direction:column;gap:.75rem}
.ve-iconbox-left{align-items:flex-start;flex-direction:row;text-align:left}
.ve-iconbox-top.ve-align-center{align-items:center}
.ve-iconbox-top.ve-align-right{align-items:flex-end}
.ve-iconbox-icon{color:var(--ui-primary,#2563eb);flex:0 0 auto}
.ve-iconbox-body{min-width:0}
.ve-iconbox-title{font-size:1.125rem;line-height:1.3;margin:0 0 .35rem}
.ve-iconbox-text{margin:0}
.ve-imagebox{display:flex;flex-direction:column;gap:.75rem}
.ve-imagebox-media img{border-radius:8px;display:block;height:auto;max-width:100%}
.ve-imagebox.ve-align-center .ve-imagebox-media img{margin-inline:auto}
.ve-imagebox.ve-align-right .ve-imagebox-media img{margin-left:auto}
.ve-imagebox-title{font-size:1.125rem;line-height:1.3;margin:0 0 .35rem}
.ve-imagebox-text{margin:0}
.ve-alert{border:1px solid var(--ui-border,#d4d4d8);border-left-width:4px;border-radius:6px;display:flex;flex-wrap:wrap;gap:.5rem;padding:.75rem 1rem}
.ve-alert-title{flex:0 0 auto}
.ve-alert-text{flex:1 1 auto;min-width:0}
.ve-imagebox-body{min-width:0}
.ve-progress{width:100%}
.ve-tabs{position:relative;width:100%}
.ve-alert-info{background:rgba(37,99,235,.06);border-left-color:#2563eb}
.ve-alert-success{background:rgba(22,163,74,.07);border-left-color:#16a34a}
.ve-alert-warning{background:rgba(217,119,6,.08);border-left-color:#d97706}
.ve-alert-danger{background:rgba(220,38,38,.07);border-left-color:#dc2626}
.ve-progress-head{display:flex;font-size:.875rem;justify-content:space-between;margin-bottom:.35rem}
.ve-progress-track{background:var(--ui-border,#e4e4e7);border-radius:999px;height:.5rem;overflow:hidden;width:100%}
.ve-progress-bar{background:var(--ui-primary,#2563eb);border-radius:inherit;height:100%}
.ve-anchor{display:block;height:0;overflow:hidden;scroll-margin-top:5rem}
.ve-ratio-16-9 img{aspect-ratio:16/9;object-fit:cover}
.ve-ratio-4-3 img{aspect-ratio:4/3;object-fit:cover}
.ve-ratio-1-1 img{aspect-ratio:1/1;object-fit:cover}
.ve-gallery{display:grid;gap:.75rem;grid-template-columns:repeat(var(--ve-cols,3),minmax(0,1fr))}
.ve-gallery-cell{display:block;overflow:hidden;border-radius:var(--ve-radius,8px)}
.ve-gallery-cell img{display:block;height:auto;width:100%}
.ve-carousel{display:flex;gap:.75rem;overflow-x:auto;scroll-snap-type:x mandatory;scrollbar-width:thin;-webkit-overflow-scrolling:touch}
.ve-carousel-cell{border-radius:var(--ve-radius,8px);flex:0 0 calc((100% - (var(--ve-per,3) - 1) * .75rem) / var(--ve-per,3));overflow:hidden;scroll-snap-align:start}
.ve-carousel-cell img{display:block;height:auto;width:100%}
.ve-logos{align-items:center;display:grid;gap:1.25rem;grid-template-columns:repeat(var(--ve-cols,4),minmax(0,1fr))}
.ve-logos-cell img{display:block;height:auto;margin-inline:auto;max-height:3.5rem;object-fit:contain;width:auto;max-width:100%}
.ve-logos-gray .ve-logos-cell img{filter:grayscale(1);opacity:.72;transition:filter .2s ease,opacity .2s ease}
.ve-logos-gray .ve-logos-cell:hover img{filter:none;opacity:1}
.ve-tabs-radio{position:absolute;opacity:0;pointer-events:none;height:0;width:0}
.ve-tabs-nav{border-bottom:1px solid var(--ui-border,#e4e4e7);display:flex;flex-wrap:wrap;gap:.25rem}
.ve-tabs-label{border-bottom:2px solid transparent;cursor:pointer;font-weight:500;margin-bottom:-1px;padding:.5rem .9rem}
.ve-tabs-panel{display:none;padding-top:1rem}
.ve-tabs-panel>:first-child{margin-top:0}.ve-tabs-panel>:last-child{margin-bottom:0}
.ve-accordion{border:1px solid var(--ui-border,#e4e4e7);border-radius:8px;overflow:hidden}
.ve-accordion-item+.ve-accordion-item{border-top:1px solid var(--ui-border,#e4e4e7)}
.ve-accordion-head{cursor:pointer;font-weight:500;list-style:none;padding:.75rem 1rem;position:relative}
.ve-accordion-head::-webkit-details-marker{display:none}
.ve-accordion-head::after{content:"+";position:absolute;right:1rem;top:.7rem}
.ve-accordion-item[open]>.ve-accordion-head::after{content:"−"}
.ve-accordion-body{padding:0 1rem 1rem}
.ve-accordion-body>:first-child{margin-top:0}.ve-accordion-body>:last-child{margin-bottom:0}
.ve-iconlist{list-style:none;margin:0;padding:0}
.ve-iconlist-item{align-items:flex-start;display:flex;gap:.6rem;padding:.35rem 0}
.ve-iconlist-divided .ve-iconlist-item+.ve-iconlist-item{border-top:1px solid var(--ui-border,#e4e4e7)}
.ve-iconlist-icon{color:var(--ve-icon-color,var(--ui-primary,#2563eb));flex:0 0 auto;line-height:1.5}
.ve-iconlist-text a{color:inherit}
.ve-counter-number{font-size:2.5rem;font-weight:700;line-height:1.1}
.ve-counter-label{color:var(--ui-text-muted,#71717a);margin-top:.25rem}
.ve-rating{align-items:center;display:flex;gap:.5rem}
.ve-rating.ve-align-center{justify-content:center}.ve-rating.ve-align-right{justify-content:flex-end}
.ve-rating-stars{color:#f59e0b;line-height:1}
.ve-rating-value{color:var(--ui-text-muted,#71717a);font-size:.875rem}
.ve-social{display:flex;flex-wrap:wrap;gap:.5rem}
.ve-social.ve-align-center{justify-content:center}.ve-social.ve-align-right{justify-content:flex-end}
.ve-social-item{align-items:center;background:var(--ui-primary,#2563eb);color:#fff;display:inline-flex;font-size:var(--ve-social-size,18px);height:calc(var(--ve-social-size,18px) * 2.2);justify-content:center;text-decoration:none;width:calc(var(--ve-social-size,18px) * 2.2)}
.ve-social-circle .ve-social-item{border-radius:999px}
.ve-social-square .ve-social-item{border-radius:8px}
.ve-social-plain .ve-social-item{background:transparent;color:var(--ui-primary-text,#2563eb);height:auto;width:auto}
.ve-social-sr{clip:rect(0 0 0 0);height:1px;overflow:hidden;position:absolute;width:1px}
.ve-pricing{border:1px solid var(--ui-border,#e4e4e7);border-radius:12px;padding:1.5rem;position:relative;text-align:center}
.ve-pricing-featured{border-color:var(--ui-primary,#2563eb);box-shadow:0 12px 32px rgba(37,99,235,.12)}
.ve-pricing-ribbon{background:var(--ui-primary,#2563eb);border-radius:999px;color:#fff;font-size:.75rem;left:50%;padding:.15rem .7rem;position:absolute;top:-.7rem;transform:translateX(-50%)}
.ve-pricing-plan{color:var(--ui-text-muted,#71717a);font-weight:500}
.ve-pricing-price{align-items:baseline;display:flex;gap:.15rem;justify-content:center;margin:.5rem 0 1rem}
.ve-pricing-currency{font-size:1.1rem}
.ve-pricing-amount{font-size:2.4rem;font-weight:700;line-height:1}
.ve-pricing-period{color:var(--ui-text-muted,#71717a);font-size:.875rem}
.ve-pricing-features{list-style:none;margin:0 0 1.25rem;padding:0;text-align:left}
.ve-pricing-features>li{border-top:1px solid var(--ui-border,#f1f5f9);padding:.45rem 0}
.ve-cta{align-items:center;background-position:center;background-size:cover;border-radius:12px;display:flex;overflow:hidden;padding:2.5rem 1.5rem;position:relative}
.ve-cta.ve-align-center{justify-content:center}.ve-cta.ve-align-right{justify-content:flex-end}
.ve-cta::before{content:"";inset:0;position:absolute}
.ve-cta-dark::before{background:rgba(15,23,42,.55)}
.ve-cta-light::before{background:rgba(255,255,255,.7)}
.ve-cta-none::before{display:none}
.ve-cta-body{max-width:44rem;position:relative}
.ve-cta-dark .ve-cta-title,.ve-cta-dark .ve-cta-text{color:#fff}
.ve-cta-title{font-size:1.75rem;line-height:1.25;margin:0 0 .5rem}
.ve-cta-text{margin:0 0 1.25rem}
.ve-testimonial{margin:0}
.ve-testimonial-text{border-left:3px solid var(--ui-primary,#2563eb);font-size:1.05rem;margin:0;padding-left:1rem}
.ve-testimonial-meta{align-items:center;display:flex;gap:.75rem;margin-top:1rem}
.ve-testimonial.ve-align-center .ve-testimonial-meta{justify-content:center}
.ve-testimonial.ve-align-right .ve-testimonial-meta{justify-content:flex-end}
.ve-testimonial-avatar{border-radius:999px;height:2.75rem;object-fit:cover;width:2.75rem}
.ve-testimonial-who{display:flex;flex-direction:column;text-align:left}
.ve-testimonial-name{font-weight:600}
.ve-testimonial-role{color:var(--ui-text-muted,#71717a);font-size:.875rem}
.ve-timeline{list-style:none;margin:0;padding:0 0 0 1.5rem;position:relative}
.ve-timeline::before{background:var(--ve-line,var(--ui-border,#e4e4e7));content:"";left:.32rem;position:absolute;top:.4rem;bottom:.4rem;width:2px}
.ve-timeline-item{padding:0 0 1.25rem;position:relative}
.ve-timeline-item:last-child{padding-bottom:0}
.ve-timeline-dot{background:var(--ve-line,var(--ui-primary,#2563eb));border-radius:999px;height:.7rem;left:-1.5rem;position:absolute;top:.35rem;width:.7rem}
.ve-timeline-date{color:var(--ui-text-muted,#71717a);font-size:.8125rem}
.ve-timeline-title{font-size:1.05rem;margin:.15rem 0 .25rem}
.ve-timeline-text{margin:0}
.ve-flip{perspective:1200px}
.ve-flip-inner{height:100%;min-height:inherit;position:relative;transform-style:preserve-3d;transition:transform .6s cubic-bezier(.2,.7,.2,1)}
.ve-flip:hover .ve-flip-inner,.ve-flip:focus-within .ve-flip-inner{transform:rotateY(180deg)}
.ve-flip-face{align-items:center;backface-visibility:hidden;border:1px solid var(--ui-border,#e4e4e7);border-radius:12px;box-sizing:border-box;display:flex;flex-direction:column;gap:.5rem;justify-content:center;min-height:inherit;padding:1.5rem;text-align:center}
.ve-flip-back{background:var(--ui-primary,#2563eb);color:#fff;inset:0;position:absolute;transform:rotateY(180deg)}
.ve-flip-icon{color:var(--ui-primary,#2563eb)}
.ve-flip-title{font-size:1.15rem;margin:0}
.ve-flip-text{margin:0}
.ve-table-wrap{overflow-x:auto;width:100%}
.ve-table{border-collapse:collapse;width:100%}
.ve-table th,.ve-table td{padding:.55rem .75rem;text-align:left}
.ve-table thead th{background:var(--ui-surface-muted,#f8fafc);font-weight:600}
.ve-table-bordered th,.ve-table-bordered td{border:1px solid var(--ui-border,#e4e4e7)}
.ve-table-striped tbody tr:nth-child(2n){background:var(--ui-surface-muted,#f8fafc)}
@media (prefers-reduced-motion:reduce){.ve-flip-inner{transition:none}}
@media (max-width:640px){
.ve-gallery,.ve-logos{grid-template-columns:repeat(min(var(--ve-cols,3),2),minmax(0,1fr))}
.ve-carousel-cell{flex-basis:calc(100% - 2rem)}
.ve-cta{padding:1.75rem 1.15rem}
.ve-counter-number{font-size:2rem}
}
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
