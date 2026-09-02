<?php
declare(strict_types=1);

/**
 * 工具方法：让客服除了说话还能「办事」——查站内内容并回一张可点击的卡片、
 * 拉起询盘表单、转人工、递名片。
 *
 * 两条硬规则：
 * - 工具只读。没有任何一个工具会写库、发信或改配置；询盘的写入走访客自己提交的
 *   表单端点，而不是模型直接调用，否则模型幻觉出来的联系人就会变成真数据。
 * - 卡片回给浏览器的是**结构化数据**，不是 HTML 片段。前台用 DOM API 渲染，
 *   所以模型/站内标题里就算带标签也不可能变成可执行的东西。
 */
final class AiCustomerServiceTools
{
    /** @return array<string,array{label:string,description:string,note:string}> */
    public static function builtinCatalog(): array
    {
        return [
            'recommend_products' => [
                'label' => '推荐产品',
                'description' => '按关键词或场景从站内产品里挑几个，回一张可点击的产品卡。',
                'note' => '只挑前台可见的产品；价格与库存取自产品字段。',
            ],
            'recommend_articles' => [
                'label' => '推荐文章',
                'description' => '按关键词从站内文章里挑几篇，回一张可点击的文章卡。',
                'note' => '适合“怎么选/怎么用/案例”这类问题。',
            ],
            'search_site' => [
                'label' => '站内检索',
                'description' => '在产品/文章/页面里按关键词找标题与摘要，用于回答“你们有没有…”。',
                'note' => '返回标题+链接列表，不返回正文。',
            ],
            'lookup_knowledge' => [
                'label' => '查资料库',
                'description' => '在已配置的资料（站内内容 + 上传文件 + 补充资料）里检索答案依据。',
                'note' => '模型主动追问细节时用，命中片段会计入本次注入额度。',
            ],
            'show_inquiry_form' => [
                'label' => '拉起询盘表单',
                'description' => '识别到采购/报价意图时，在对话里展开一张留联系方式的表单卡。',
                'note' => '表单由访客本人提交，模型不能替访客填写。',
            ],
            'request_handoff' => [
                'label' => '转人工',
                'description' => '把会话交给人工客服，并给出人工入口。',
                'note' => '需要在“会话内容”页配置人工客服跳转链接。',
            ],
            'show_owner_card' => [
                'label' => '递站长名片',
                'description' => '回一张站长/负责人名片，含头衔、简介与社媒。',
                'note' => '数据来自“工具与卡片”页的站长名片资料。',
            ],
            'show_social_links' => [
                'label' => '给社媒方式',
                'description' => '回一组社媒/联系方式链接。',
                'note' => '同样取站长名片资料里的社媒列表。',
            ],
        ];
    }

    /**
     * 组装给模型的工具声明（OpenAI function 形状）。
     *
     * @return list<array<string,mixed>>
     */
    public static function definitions(array $config): array
    {
        if (empty($config['tools_enabled'])) return [];
        $enabled = $config['tools']['builtin'];
        $out = [];

        $fn = static function (string $name, string $description, array $properties = [], array $required = []) use (&$out): void {
            $out[] = ['type' => 'function', 'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => [
                    'type' => 'object',
                    'properties' => $properties === [] ? new \stdClass() : $properties,
                    'required' => $required,
                    'additionalProperties' => false,
                ],
            ]];
        };
        $keyword = ['type' => 'string', 'description' => '访客问题里的关键词或品类，尽量用访客自己的说法'];
        $limit = ['type' => 'integer', 'description' => '返回条数，1-6', 'minimum' => 1, 'maximum' => 6];

        if (!empty($enabled['recommend_products'])) {
            $fn('recommend_products', '按关键词推荐站内产品，返回可点击的产品卡。访客问“有什么产品/推荐哪个型号/多少钱”时用。',
                ['keyword' => $keyword, 'limit' => $limit], ['keyword']);
        }
        if (!empty($enabled['recommend_articles'])) {
            $fn('recommend_articles', '按关键词推荐站内文章，返回可点击的文章卡。访客问“怎么选/怎么用/有没有案例”时用。',
                ['keyword' => $keyword, 'limit' => $limit], ['keyword']);
        }
        if (!empty($enabled['search_site'])) {
            $fn('search_site', '在站内产品、文章、页面里检索标题与摘要，确认“你们有没有 X”。',
                [
                    'keyword' => $keyword,
                    'kind' => ['type' => 'string', 'enum' => ['product', 'article', 'page', 'any'], 'description' => '限定检索范围，不确定就用 any'],
                ], ['keyword']);
        }
        if (!empty($enabled['lookup_knowledge'])) {
            $fn('lookup_knowledge', '在站点资料库里检索答案依据。需要具体条款、参数、流程时用。',
                ['question' => ['type' => 'string', 'description' => '要查证的问题，越具体越好']], ['question']);
        }
        if (!empty($enabled['show_inquiry_form'])) {
            $fn('show_inquiry_form', '展开留联系方式的询盘表单。访客表达采购、报价、下单意图时用。不要替访客填写任何字段。',
                ['reason' => ['type' => 'string', 'description' => '一句话说明为什么需要留联系方式']]);
        }
        if (!empty($enabled['request_handoff'])) {
            $fn('request_handoff', '转人工客服。访客明确要真人、或问题超出你能回答的范围时用。',
                ['reason' => ['type' => 'string', 'description' => '一句话转接理由']]);
        }
        if (!empty($enabled['show_owner_card'])) {
            $fn('show_owner_card', '展示站长/负责人名片。访客问“谁负责/找谁”时用。');
        }
        if (!empty($enabled['show_social_links'])) {
            $fn('show_social_links', '展示社媒与联系方式。访客要微信/WhatsApp/公众号等联系方式时用。');
        }

        foreach ($config['tools']['custom'] as $tool) {
            if (empty($tool['enabled'])) continue;
            $description = (string)$tool['description'] !== ''
                ? (string)$tool['description']
                : ('在站内' . (AiCustomerService::SOURCE_KINDS[$tool['source']] ?? '内容') . '里按条件挑几条并回一张卡片。');
            $fn((string)$tool['name'], mb_substr($description, 0, 400),
                ['keyword' => $keyword, 'limit' => $limit]);
            if (count($out) >= 24) break;
        }
        return $out;
    }

    /**
     * 执行一次工具调用。
     *
     * 返回的 text 会作为 tool 消息回灌给模型（它据此续写自然语言），card 则直接下发给
     * 浏览器渲染。两者刻意分开：模型看到的是事实摘要，访客看到的是可点的卡片。
     *
     * @return array{text:string,card:array<string,mixed>|null}
     */
    public static function execute(string $name, array $arguments, array $config): array
    {
        // 站长关掉的工具一律不执行。definitions() 不会把它发给模型，但这里是**按名字分发**的：
        // 模型完全可能从历史里的旧 tool_calls 学到一个名字再喊一次（关掉开关之前的会话就留在
        // 上下文里），也有厂商会在没给 tools 时凭记忆吐 tool_calls。下面自定义工具那一支一直
        // 在查 enabled，八个内置工具漏了同一道检查——症状是「开关明明关了，卡片还是会冒出来」。
        // 回的话术与「没有这个工具」完全一致：不该让访客那侧的模型推断出站点关了哪些开关。
        // 名字取自 BUILTIN_TOOL_DEFAULTS 而不是配置里的那份：配置残缺时按「没开」处理，
        // 而不是按「不是内置工具」放过去——show_owner_card / show_social_links 默认就是关的。
        if (empty($config['tools_enabled'])) return self::unknownTool();
        $builtin = is_array($config['tools']['builtin'] ?? null) ? $config['tools']['builtin'] : [];
        if (array_key_exists($name, AiCustomerService::BUILTIN_TOOL_DEFAULTS) && empty($builtin[$name])) {
            return self::unknownTool();
        }

        $keyword = AiCustomerService::text($arguments['keyword'] ?? '', 60, '');
        $limit = AiCustomerService::int($arguments['limit'] ?? 3, 1, 6, 3);

        if ($name === 'recommend_products') {
            return self::contentResult('product', '', $keyword, [], min($limit, (int)$config['cards']['product']['max']), $config, 'product');
        }
        if ($name === 'recommend_articles') {
            return self::contentResult('article', '', $keyword, [], min($limit, (int)$config['cards']['article']['max']), $config, 'article');
        }
        if ($name === 'search_site') {
            return self::searchResult($keyword, AiCustomerService::choice($arguments['kind'] ?? '', ['product', 'article', 'page', 'any'], 'any'), $config);
        }
        if ($name === 'lookup_knowledge') {
            $question = AiCustomerService::text($arguments['question'] ?? '', 200, $keyword);
            $context = AiCustomerServiceKnowledge::context($config, $question);
            return [
                'text' => $context === '' ? '资料库里没有相关内容，请据实说明并建议转人工。' : mb_substr($context, 0, 4000),
                'card' => null,
            ];
        }
        if ($name === 'show_inquiry_form') {
            return self::inquiryResult($config, AiCustomerService::text($arguments['reason'] ?? '', 200, ''));
        }
        if ($name === 'request_handoff') {
            return self::handoffResult($config, AiCustomerService::text($arguments['reason'] ?? '', 200, ''));
        }
        if ($name === 'show_owner_card') {
            return self::ownerResult($config);
        }
        if ($name === 'show_social_links') {
            return self::socialResult($config);
        }

        foreach ($config['tools']['custom'] as $tool) {
            if ((string)$tool['name'] !== $name || empty($tool['enabled'])) continue;
            $cardKind = in_array($tool['source'], ['product'], true) ? 'product' : 'article';
            return self::contentResult(
                (string)$tool['source'],
                (string)$tool['entry_type'],
                $keyword,
                (array)$tool['filters'],
                min($limit, (int)$tool['limit']),
                $config,
                $cardKind,
                (string)$tool['card']
            );
        }
        return self::unknownTool();
    }

    /** @return array{text:string,card:array<string,mixed>|null} 名字不认识、或这个工具被关掉了。 */
    private static function unknownTool(): array
    {
        return ['text' => '没有这个工具，请直接用文字回答。', 'card' => null];
    }

    /** @return array{text:string,card:array<string,mixed>|null} */
    private static function contentResult(
        string $kind,
        string $type,
        string $keyword,
        array $filters,
        int $limit,
        array $config,
        string $cardKind,
        string $presetOverride = ''
    ): array {
        $records = AiCustomerServiceKnowledge::pick($kind, $type, $keyword, $filters, $limit);
        if ($records === []) {
            // 关键词太窄是常态，退一步给同类里的默认几条，比直接说"没有"有用。
            $records = AiCustomerServiceKnowledge::pick($kind, $type, '', $filters, $limit);
        }
        if ($records === []) {
            return ['text' => '站内暂时没有可推荐的' . (AiCustomerService::SOURCE_KINDS[$kind] ?? '内容') . '。请据实说明，不要编造。', 'card' => null];
        }
        return [
            'text' => self::describeForModel($records),
            'card' => AiCustomerServiceCards::content($cardKind, $records, $config, $presetOverride),
        ];
    }

    /** @return array{text:string,card:array<string,mixed>|null} */
    private static function searchResult(string $keyword, string $kind, array $config): array
    {
        $kinds = $kind === 'any' ? ['product', 'article', 'page'] : [$kind];
        $records = [];
        foreach ($kinds as $one) {
            foreach (AiCustomerServiceKnowledge::pick($one, '', $keyword, [], 3) as $record) {
                $records[] = $record;
            }
        }
        if ($records === []) {
            return ['text' => '站内检索“' . $keyword . '”没有结果。请据实告诉访客没有找到，并可建议转人工。', 'card' => null];
        }
        $records = array_slice($records, 0, 6);
        return ['text' => self::describeForModel($records), 'card' => AiCustomerServiceCards::content('article', $records, $config, 'minimal')];
    }

    /** @param list<array<string,mixed>> $records */
    private static function describeForModel(array $records): string
    {
        $lines = [];
        foreach ($records as $index => $record) {
            $bits = [($index + 1) . '. ' . $record['title']];
            if (($record['price'] ?? '') !== '') $bits[] = '价格 ' . $record['price'];
            if (($record['sku'] ?? '') !== '') $bits[] = 'SKU ' . $record['sku'];
            if (($record['summary'] ?? '') !== '') $bits[] = mb_substr((string)$record['summary'], 0, 160);
            if (($record['url'] ?? '') !== '') $bits[] = $record['url'];
            $lines[] = implode('｜', $bits);
        }
        return "以下是站内真实数据，卡片已经展示给访客了。请用一两句话概括推荐理由，不要重复罗列链接：\n"
            . implode("\n", $lines);
    }

    /** @return array{text:string,card:array<string,mixed>|null} */
    private static function inquiryResult(array $config, string $reason): array
    {
        return [
            'text' => '询盘表单已经展示给访客了。请用一句话说明留下联系方式后会发生什么，不要替访客填写内容。',
            'card' => AiCustomerServiceCards::inquiry($config, $reason),
        ];
    }

    /** @return array{text:string,card:array<string,mixed>|null} */
    private static function handoffResult(array $config, string $reason): array
    {
        $hasLink = (string)$config['handoff_url'] !== '';
        return [
            'text' => $hasLink
                ? '转人工入口已经展示给访客了。请用一句话安抚并说明人工会接手。'
                : '站点没有配置人工客服入口。请告诉访客可以留下联系方式，由销售回访。',
            'card' => AiCustomerServiceCards::handoff($config, $reason),
        ];
    }

    /** @return array{text:string,card:array<string,mixed>|null} */
    private static function ownerResult(array $config): array
    {
        $card = AiCustomerServiceCards::owner($config);
        return [
            'text' => $card === null ? '站点还没有配置站长名片资料，请据实说明。' : '名片已经展示给访客了，简单一句话介绍即可。',
            'card' => $card,
        ];
    }

    /** @return array{text:string,card:array<string,mixed>|null} */
    private static function socialResult(array $config): array
    {
        $card = AiCustomerServiceCards::social($config);
        return [
            'text' => $card === null ? '站点还没有配置社媒联系方式，请据实说明。' : '联系方式已经展示给访客了。',
            'card' => $card,
        ];
    }

    /**
     * 归一化模型回传的 tool_calls。不同厂商在 arguments 上会给字符串或对象，这里统一。
     *
     * @return list<array{id:string,name:string,arguments:array<string,mixed>}>
     */
    public static function normalizeCalls(mixed $calls, int $max): array
    {
        if (!is_array($calls)) return [];
        $out = [];
        foreach (array_slice(array_values($calls), 0, max(1, $max)) as $call) {
            if (!is_array($call)) continue;
            $function = is_array($call['function'] ?? null) ? $call['function'] : $call;
            $name = (string)preg_replace('/[^A-Za-z0-9_]/', '', (string)($function['name'] ?? ''));
            if ($name === '') continue;
            $arguments = $function['arguments'] ?? [];
            if (is_string($arguments)) {
                $decoded = json_decode($arguments, true);
                $arguments = is_array($decoded) ? $decoded : [];
            }
            $out[] = [
                'id' => AiCustomerService::slugValue((string)($call['id'] ?? ''), 40) ?: substr(bin2hex(random_bytes(6)), 0, 12),
                'name' => substr($name, 0, 64),
                'arguments' => is_array($arguments) ? $arguments : [],
            ];
        }
        return $out;
    }
}
