<?php
declare(strict_types=1);

/**
 * 卡片负载。
 *
 * 这里只产出**结构化数据**，一律不产出 HTML：前台用 DOM API 按预设渲染，所以
 * 站内标题、摘要、模型措辞里出现的标签都不可能变成可执行内容。每种卡片都有多套预设，
 * 预设只影响前台的 class 名，数据形状不变——这样加预设不需要改服务端。
 */
final class AiCustomerServiceCards
{
    /** 询盘表单字段的标签与类型。 */
    private const INQUIRY_FIELDS = [
        'name' => ['姓名', 'text', 60],
        'email' => ['邮箱', 'email', 190],
        'phone' => ['电话 / WhatsApp', 'tel', 40],
        'company' => ['公司', 'text', 120],
        'message' => ['需求描述', 'textarea', 2000],
    ];

    /**
     * 内容卡（产品 / 文章 / 检索结果）。
     *
     * @param list<array<string,mixed>> $records
     * @return array<string,mixed>|null
     */
    public static function content(string $kind, array $records, array $config, string $presetOverride = ''): ?array
    {
        $kind = $kind === 'product' ? 'product' : 'article';
        $settings = $config['cards'][$kind];
        $allowed = array_keys(AiCustomerService::CARD_PRESETS[$kind]);
        $preset = $presetOverride !== '' && in_array($presetOverride, $allowed, true)
            ? $presetOverride
            : (string)$settings['preset'];

        $items = [];
        foreach (array_slice($records, 0, (int)$settings['max']) as $record) {
            $url = AiCustomerService::relativeOrHttpUrl((string)($record['url'] ?? ''));
            $item = [
                'title' => AiCustomerService::text($record['title'] ?? '', 120, '未命名'),
                'summary' => $kind === 'product' && empty($settings['show_summary'])
                    ? ''
                    : AiCustomerService::text($record['summary'] ?? '', 160, ''),
                'url' => $url,
                'cover' => AiCustomerService::relativeOrHttpUrl((string)($record['cover'] ?? '')),
                'price' => $kind === 'product' && !empty($settings['show_price'])
                    ? AiCustomerService::text($record['price'] ?? '', 40, '')
                    : '',
                'badge' => $kind === 'product' ? self::stockBadge((string)($record['stock'] ?? '')) : '',
            ];
            if ($item['title'] === '') continue;
            $items[] = $item;
        }
        if ($items === []) return null;

        return [
            'type' => 'content',
            'kind' => $kind,
            'preset' => $preset,
            'cta' => AiCustomerService::text($settings['cta'] ?? '', 24, $kind === 'product' ? '查看详情' : '阅读全文'),
            'items' => $items,
        ];
    }

    private static function stockBadge(string $status): string
    {
        return match ($status) {
            'out_of_stock' => '暂无库存',
            'on_backorder' => '可预订',
            'in_stock' => '现货',
            default => '',
        };
    }

    /**
     * 询盘卡。表单由访客本人提交到插件自己的端点，模型不参与填写，也拿不到提交结果。
     *
     * @return array<string,mixed>
     */
    public static function inquiry(array $config, string $reason = ''): array
    {
        $settings = $config['cards']['inquiry'];
        $fields = [];
        foreach ((array)$settings['fields'] as $key) {
            if (!isset(self::INQUIRY_FIELDS[$key])) continue;
            [$label, $type, $max] = self::INQUIRY_FIELDS[$key];
            $fields[] = [
                'name' => $key,
                'label' => $label,
                'type' => $type,
                'max' => $max,
                'required' => in_array($key, ['message'], true) || ($key === 'email' && !in_array('phone', (array)$settings['fields'], true)),
            ];
        }
        /* 服务端还有一条"邮箱和电话至少留一个"的规则（submitInquiry）。两个字段都配上时
         * 谁都不是 required，前台按 required 校验就会放过只填了姓名+需求的提交，
         * 让访客白跑一趟服务端才被拒。把这条规则显式告诉前台，让它自己先拦。 */
        $names = array_column($fields, 'name');
        $eitherContact = in_array('email', $names, true) && in_array('phone', $names, true);

        return [
            'type' => 'inquiry',
            'preset' => (string)$settings['preset'],
            'title' => '留个联系方式',
            'note' => $reason !== '' ? $reason : (string)$config['events']['inquiry']['message'],
            'fields' => $fields,
            'eitherContact' => $eitherContact,
            'submit' => (string)$settings['submit'],
            'success' => (string)$settings['success'],
            'handoffUrl' => (string)$config['handoff_url'],
            'handoffLabel' => (string)$config['handoff_label'],
        ];
    }

    /** @return array<string,mixed> */
    public static function handoff(array $config, string $reason = ''): array
    {
        return [
            'type' => 'handoff',
            'preset' => 'panel',
            'title' => '转人工客服',
            'note' => $reason !== '' ? $reason : (string)$config['events']['handoff']['message'],
            'label' => (string)$config['handoff_label'],
            'url' => (string)$config['handoff_url'],
            'fallback' => $config['handoff_url'] === '' ? '当前没有配置人工入口，您可以留下联系方式由销售回访。' : '',
        ];
    }

    /** @return array<string,mixed>|null */
    public static function owner(array $config): ?array
    {
        $owner = $config['owner'];
        if ((string)$owner['name'] === '' && $owner['socials'] === []) return null;
        return [
            'type' => 'owner',
            'preset' => (string)$config['cards']['owner']['preset'],
            'name' => (string)$owner['name'],
            'title' => (string)$owner['title'],
            'avatar' => (string)$owner['avatar'],
            'bio' => (string)$owner['bio'],
            'socials' => self::socialItems($owner['socials']),
        ];
    }

    /** @return array<string,mixed>|null */
    public static function social(array $config): ?array
    {
        $items = self::socialItems($config['owner']['socials']);
        if ($items === []) return null;
        return [
            'type' => 'social',
            'preset' => (string)$config['cards']['social']['preset'],
            'title' => '联系方式',
            'socials' => $items,
        ];
    }

    /**
     * 社媒条目。微信号不是链接，标成 copy 让前台渲染成"点击复制"而不是超链接；
     * 电话 / Viber / 短信填的是号码，在这里拼成对应的协议 URI。
     * 除了社媒卡，浮标旁的多渠道展开也用这一份数据（同一套联系方式只维护一处）。
     *
     * @return list<array<string,mixed>>
     */
    public static function socialItems(array $socials): array
    {
        $out = [];
        foreach ($socials as $social) {
            $network = (string)($social['network'] ?? '');
            $meta = AiCustomerService::SOCIAL_NETWORKS[$network] ?? null;
            if ($meta === null) continue;
            $value = (string)($social['url'] ?? '');
            if ($value === '') continue;
            $mode = match ($network) {
                'wechat' => 'copy',
                'phone' => 'tel',
                'email' => 'mailto',
                'viber' => 'viber',
                'sms' => 'sms',
                default => 'link',
            };
            // 号码类只留数字和 +：viber:// 与 sms: 的号码位不接受空格、括号、连字符。
            $digits = static fn (string $raw): string => (string)preg_replace('/[^0-9+]/', '', $raw);
            $href = match ($mode) {
                'tel' => 'tel:' . preg_replace('/[^0-9+\-\s]/', '', $value),
                'mailto' => 'mailto:' . $value,
                'viber' => 'viber://chat?number=' . rawurlencode($digits($value)),
                'sms' => 'sms:' . $digits($value),
                'link' => $value,
                default => '',
            };
            $out[] = [
                'network' => $network,
                'label' => (string)($social['label'] ?? $meta[0]),
                'icon' => $meta[1],
                'value' => $value,
                'mode' => $mode,
                'href' => (string)$href,
                'qr' => (string)($social['qr'] ?? ''),
            ];
        }
        return $out;
    }
}
