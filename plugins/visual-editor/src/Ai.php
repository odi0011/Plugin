<?php
/**
 * 可视化编辑器：AI 转换与 AI 重排。
 *
 * 为什么需要 AI：1.4.0 起导入的第一条规则是「不丢内容优先」——一段带 class /
 * <style> / <script> 的标记只要转成控件就会丢东西，就整块原样落进自定义 HTML
 * 控件。结果是正确的（一个字节都不少），但那样的区块在编辑台里只能当一团文本改，
 * 拖不动、调不了间距。真正想把它变成可编辑的区块，需要有人**理解**这段标记的
 * 意图再重写一遍——这件事规则做不到，模型可以试。
 *
 * 所以这里的定位很清楚：AI 转换是**有损的、可选的、需要明确同意的**第二条路，
 * 不是导入的默认行为。界面上必须先说清「不保证 1:1 还原」，用户点了才走。
 *
 * 三条保证：
 *   1. 模型的输出永远只是一棵 JSON 树，经 VisualEditorDocumentShape::normalize()
 *      收拾之后才有资格进画布——未知控件丢弃、字段逐个过白名单校验，与手工编辑
 *      走的是同一道闸门。模型说什么都不能让它绕过校验。
 *   2. 转换结果不直接入库、也不覆盖存储里的既有树：只回给编辑器当预览，
 *      用户点「保存」才走 persist。
 *   3. 原文备份照旧在首次接管时留档，AI 弄砸了「还原原文」永远能退回去。
 *
 * 用的是站点自己的 AI 配置（App\Core\AiService），插件不带任何密钥、
 * 不连任何第三方；没配默认模型时如实说「去配置 AI」，不静默失败。
 */
if (!defined('CODE_SCHEMA_VERSION')) exit;

final class VisualEditorAi
{
    /** 送进模型的原文上限。再长的页面先截断——模型的上下文不是无限的。 */
    public const MAX_INPUT_CHARS = 60000;

    /** 判定「复杂」的门槛：原样 HTML 块里的字符数。 */
    private const COMPLEX_RAW_CHARS = 400;

    /**
     * 预检：规则导入这段内容会退化到什么程度？
     *
     * 「自动预检，复杂才问」——干净的标记规则能无损转完，那就没有理由拿一个
     * AI 弹窗去打断用户。只有确实退化成了大块原样 HTML 才值得问一句。
     *
     * @return array{complex:bool,widgets:int,raw_widgets:int,raw_chars:int,reasons:list<string>}
     */
    public static function inspect(string $field): array
    {
        $html = VisualEditorContent::stripManagedBlock($field);
        $tree = VisualEditorContent::importTree($html);
        $widgets = 0;
        $rawWidgets = 0;
        $rawChars = 0;
        foreach (($tree['sections'] ?? []) as $section) {
            foreach (($section['columns'] ?? []) as $column) {
                foreach (($column['widgets'] ?? []) as $widget) {
                    $widgets++;
                    if ((string)($widget['type'] ?? '') !== 'html') continue;
                    $rawWidgets++;
                    $rawChars += strlen((string)($widget['content']['html'] ?? ''));
                }
            }
        }

        $reasons = [];
        if ($rawWidgets > 0) {
            $reasons[] = '有 ' . $rawWidgets . ' 块标记带着 class / 内联样式 / 脚本，'
                . '转成控件会丢东西，已原样保留为「自定义 HTML」块（共 ' . $rawChars . ' 字节）';
        }
        if ($rawWidgets > 0 && $widgets === $rawWidgets) {
            $reasons[] = '整篇内容没有一处能安全映射成控件：现在打开编辑台，你看到的会是一整块 HTML';
        }

        return [
            'complex' => $rawWidgets > 0 && $rawChars >= self::COMPLEX_RAW_CHARS,
            'widgets' => $widgets,
            'raw_widgets' => $rawWidgets,
            'raw_chars' => $rawChars,
            'reasons' => $reasons,
        ];
    }

    /**
     * 站点的 AI 到底能不能用？
     *
     * 分三种情况回报，界面据此给不同的出口：能用 → 「让 AI 转换」；
     * 没配模型 → 「去配置 AI」+「跳过」；连传输层都缺 → 只说原因，不给假按钮。
     *
     * @return array{available:bool,model:string,message:string,configure_url:string}
     */
    public static function availability(): array
    {
        $configure = '';
        try {
            $configure = \admin_url('/ai/config');
        } catch (\Throwable $e) {
            $configure = '';
        }
        if (!\App\Core\AiService::transportAvailable()) {
            return [
                'available' => false,
                'model' => '',
                'message' => \App\Core\AiService::transportRequirementMessage(),
                'configure_url' => $configure,
            ];
        }
        $model = \App\Core\AiService::defaultModel('chat');
        if (!is_array($model) || $model === []) {
            return [
                'available' => false,
                'model' => '',
                'message' => '站点还没有设置默认对话模型，先去 AI 配置里选一个。',
                'configure_url' => $configure,
            ];
        }
        return [
            'available' => true,
            'model' => (string)($model['name'] ?? ''),
            'message' => '',
            'configure_url' => $configure,
        ];
    }

    /**
     * 控件契约：交给模型的「可用积木清单」。
     *
     * 由 Schema 现算，而不是在提示词里手写一份——手写的那份第二天就和代码不一致，
     * 然后模型开始产出永远会被 normalize() 丢掉的控件，而没有人知道为什么。
     */
    public static function widgetContract(bool $allowCode): string
    {
        $lines = [];
        foreach (VisualEditorSchema::widgets() as $type => $definition) {
            if ((string)($definition['needs_permission'] ?? '') !== '' && !$allowCode) continue;
            $fields = [];
            foreach ((array)($definition['fields'] ?? []) as $key => $spec) {
                $fields[] = $key . '=' . $spec;
            }
            $lines[] = '- ' . $type . '（' . (string)($definition['label'] ?? $type) . '）: '
                . implode(', ', $fields);
        }
        return implode("\n", $lines);
    }

    /**
     * 转换用的提示词。提示词留在插件里，不进数据库、不做成设置项：
     * 它与 Schema 是强耦合的，让用户改等于让用户去改一份契约。
     *
     * @return list<array{role:string,content:string}>
     */
    public static function convertMessages(string $html, bool $allowCode): array
    {
        $contract = self::widgetContract($allowCode);
        $system = <<<TXT
你是一个把 HTML 页面翻译成结构化区块文档的转换器。只输出 JSON，不要任何解释、不要 Markdown 代码围栏。

输出格式（严格遵守）：
{"version":1,"sections":[{"layout":"boxed","columns":[{"width":{"desktop":50},"widgets":[{"type":"heading","content":{"text":"…","level":"h2"}}]}]}]}

规则：
1. 只能用下面清单里的控件类型与字段名，字段值要符合冒号后的约束（text:200 表示纯文本最多 200 字；enum:a,b 只能取列出的值；rich 是允许基础标签的富文本；lines:20,200 是每行一项的多行文本；media/link 是 URL；number:5,100 是范围内的数字；repeater 是对象数组）。
2. 保留原文的**文字内容与阅读顺序**，一个字都不要新写、不要翻译、不要删减。
3. 视觉结构尽量还原：并排的卡片放进同一个 section 的多个 column（width.desktop 是百分数，同一行加起来 100）；每个 column 的 widgets 按原顺序。
4. 装饰性的容器（只为排版存在的 div）不要变成控件，直接体现为 section / column。
5. 实在无法用清单里的控件表达的片段，用 html 控件把原始标记原样放进 content.html。宁可留一块 HTML，也不要丢内容。
6. 不要输出 id 字段，服务端会生成。

可用控件清单：
$contract
TXT;

        $truncated = mb_substr($html, 0, self::MAX_INPUT_CHARS);
        $note = mb_strlen($html) > self::MAX_INPUT_CHARS
            ? "\n\n（原文过长，已截断到前 " . self::MAX_INPUT_CHARS . " 字，只转换给出的部分。）"
            : '';

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => "把下面这段 HTML 转成上述 JSON 文档：\n\n" . $truncated . $note],
        ];
    }

    /**
     * 重排用的提示词：给模型看当前这棵树（不是 HTML）加一句人话要求。
     *
     * @return list<array{role:string,content:string}>
     */
    public static function rearrangeMessages(array $tree, string $instruction, bool $allowCode): array
    {
        $contract = self::widgetContract($allowCode);
        $system = <<<TXT
你在调整一份已经结构化的区块文档。只输出调整后的完整 JSON 文档，不要解释、不要 Markdown 代码围栏。

规则：
1. 文字内容默认保持原样，除非用户明确要求改写。
2. 允许调整的是结构：区块与栏的划分、控件顺序、控件类型的替换、栏宽（width.desktop 百分数同行合计 100）。
3. 只能用下面清单里的控件类型与字段名，字段值要符合约束。
4. 不要输出 id 字段。
5. 输出必须是完整文档，不要只给改动的片段。

可用控件清单：
$contract
TXT;

        $json = json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $json = mb_substr(is_string($json) ? $json : '{}', 0, self::MAX_INPUT_CHARS);
        $wish = trim($instruction) === '' ? '按常规排版习惯把这份文档整理得更清晰。' : trim($instruction);

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => "当前文档：\n" . $json . "\n\n要求：" . $wish],
        ];
    }

    /**
     * 把模型回的文本抠成一棵可用的树。
     *
     * 模型经常在 JSON 外面裹一层围栏或客套话，哪怕提示词里说了不要。这里
     * 只做「找到最外层的一对花括号」这一件事，剩下的交给 normalize()——
     * 解析宽容、校验严格，比反过来安全。
     *
     * @return array{ok:bool,tree:array<string,mixed>,message:string}
     */
    public static function parseTree(string $raw, bool $allowCode): array
    {
        $text = trim($raw);
        if ($text === '') {
            return ['ok' => false, 'tree' => [], 'message' => '模型没有返回任何内容'];
        }
        // ```json … ``` 之类的围栏
        if (preg_match('/```(?:json)?\s*(.+?)```/s', $text, $fence) === 1) {
            $text = trim($fence[1]);
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return ['ok' => false, 'tree' => [], 'message' => '模型返回的内容里没有 JSON 文档'];
        }
        $json = substr($text, $start, $end - $start + 1);
        if (strlen($json) > VisualEditorSchema::MAX_DOC_BYTES) {
            return ['ok' => false, 'tree' => [], 'message' => '模型返回的文档过大'];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'tree' => [], 'message' => '模型返回的 JSON 无法解析'];
        }
        $tree = VisualEditorDocumentShape::normalize($decoded, $allowCode);
        $widgets = 0;
        foreach (($tree['sections'] ?? []) as $section) {
            foreach (($section['columns'] ?? []) as $column) {
                $widgets += count((array)($column['widgets'] ?? []));
            }
        }
        if ($widgets === 0) {
            // 全被 normalize() 丢掉了：与其把一张白纸端给用户，不如如实说失败。
            return ['ok' => false, 'tree' => [], 'message' => '模型给出的控件全部没通过校验，转换未采用'];
        }
        return ['ok' => true, 'tree' => $tree, 'message' => '已生成 ' . $widgets . ' 个控件'];
    }
}
