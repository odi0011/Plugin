<?php
declare(strict_types=1);

/** Shared settings, rendering, and safe configuration helpers for AI客服. */
final class AiCustomerService
{
    public const SLUG = 'ai-customer-service';
    private const SESSION_KEY = '_ai_customer_service';
    private const CONVERSATION_TTL = 21600;
    private const MAX_CONVERSATIONS = 12;
    private const CUSTOM_KEY_SETTING = 'plugin.ai-customer-service.custom_api_key';

    /** 后台四个独立子页面：key => 标签，与 plugin.json 的 settings.sections 一一对应。 */
    public const ADMIN_PAGES = [
        'conversation' => '会话内容',
        'trigger' => '显示与触发',
        'appearance' => '外观与位置',
        'ai' => 'AI 与知识库',
    ];
    private const SAVE_KEYS_FIELD = 'acs_save_keys';

    /** @return array<string,mixed> */
    public static function adminPageData(string $page = 'conversation'): array
    {
        $page = array_key_exists($page, self::ADMIN_PAGES) ? $page : 'conversation';
        $form = \App\Core\PluginSettingsService::form(self::SLUG) ?? [
            'sections' => [],
            'values' => [],
            'secret_set' => [],
            'permission' => 'ai_customer_service.manage',
        ];

        $models = [];
        try {
            foreach (\App\Models\AiModel::query()->where('enabled', 1)->orderBy('sort_order', 'asc')->get() as $model) {
                if (!\App\Core\AiService::modelSupportsChat($model)) continue;
                $provider = \App\Models\AiProvider::find((int)($model['provider_id'] ?? 0));
                if (!$provider || empty($provider['enabled'])) continue;
                $models[] = [
                    'id' => (int)($model['id'] ?? 0),
                    'label' => trim((string)($provider['name'] ?? 'AI') . ' · ' . (string)($model['name'] ?? '模型')),
                ];
            }
        } catch (\Throwable $_) {
            $models = [];
        }

        return [
            'form' => $form,
            'page' => $page,
            'pages' => self::ADMIN_PAGES,
            'section' => self::sectionFor($form, $page),
            'models' => $models,
            'customKeySet' => self::customApiKeySet(),
            'active' => \App\Core\PluginManager::isActive(self::SLUG),
            'status' => self::statusSummary(),
        ];
    }

    /** @param array<string,mixed> $form @return array<string,mixed> */
    private static function sectionFor(array $form, string $page): array
    {
        foreach ((array)($form['sections'] ?? []) as $section) {
            if ((string)($section['key'] ?? '') === $page) {
                return is_array($section) ? $section : [];
            }
        }
        return ['key' => $page, 'label' => self::ADMIN_PAGES[$page] ?? $page, 'description' => '', 'fields' => []];
    }

    /**
     * 校验并保存一次后台提交。
     *
     * 每个子页面只提交自己名下的字段；表单携带 acs_save_keys 说明本次归属的键，
     * 未列出的声明字段以库中现值回填后再整体校验落库——这样布尔项不会被其他
     * 页面的提交静默重置。密钥仅由“AI 与知识库”页处理。
     *
     * @return array{ok:bool,message:string}
     */
    public static function saveAdminConfiguration(): array
    {
        $declaration = \App\Core\PluginSettingsService::declaration(self::SLUG);
        $userId = (int)\App\Core\Auth::id();
        if ($declaration === null || $userId <= 0) {
            return ['ok' => false, 'message' => 'AI客服设置声明不可用'];
        }

        $requestedRaw = $_POST[self::SAVE_KEYS_FIELD] ?? '';
        if (!is_string($requestedRaw)) {
            return ['ok' => false, 'message' => '保存范围标识不合法'];
        }
        $requested = array_values(array_intersect(
            array_map('trim', explode(',', $requestedRaw)),
            array_keys((array)$declaration['fields'])
        ));

        $storedValues = \App\Core\PluginSettingsService::values(self::SLUG)['values'];
        $input = [];
        foreach ((array)$declaration['fields'] as $key => $field) {
            if (!in_array($key, $requested, true)) {
                // 本次未提交的声明字段：保持库中现值原样通过整体校验。
                $input[$key] = $storedValues[$key] ?? ($field['default'] ?? '');
                continue;
            }
            $name = 'setting_' . $key;
            if (array_key_exists($name, $_POST)) {
                $input[$key] = $_POST[$name];
            } elseif ((string)($field['type'] ?? '') === 'boolean') {
                $input[$key] = false;
            }
        }

        $rulesRaw = $input['url_rules'] ?? self::value('url_rules', '');
        if (!is_scalar($rulesRaw) && $rulesRaw !== null) {
            return ['ok' => false, 'message' => '页面路径规则格式不合法'];
        }
        $rules = (string)$rulesRaw;
        $ruleError = self::validateUrlRules($rules);
        if ($ruleError !== '') {
            return ['ok' => false, 'message' => $ruleError];
        }

        $customKeyRaw = $_POST['custom_api_key'] ?? '';
        if (is_array($customKeyRaw) || is_object($customKeyRaw)) {
            return ['ok' => false, 'message' => '独立接口密钥格式不合法'];
        }
        $customKey = trim((string)$customKeyRaw);
        $encryptedKey = null;
        if ($customKey !== '') {
            if (strlen($customKey) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $customKey)) {
                return ['ok' => false, 'message' => '独立接口密钥格式不合法'];
            }
            try {
                $encryptedKey = \App\Core\Security::encryptApiKey($customKey);
            } catch (\Throwable $_) {
                return ['ok' => false, 'message' => '无法安全保存独立接口密钥，请先完成系统加密密钥配置'];
            }
        }

        $result = \App\Core\PluginSettingsService::save(self::SLUG, $input, $userId);
        if (empty($result['ok'])) {
            return ['ok' => false, 'message' => (string)($result['message'] ?? '设置校验失败')];
        }

        try {
            if ($encryptedKey !== null) {
                \App\Core\Setting::set(self::CUSTOM_KEY_SETTING, $encryptedKey);
            } elseif (!empty($_POST['clear_custom_api_key'])) {
                \App\Core\Setting::set(self::CUSTOM_KEY_SETTING, '');
            }
        } catch (\Throwable $_) {
            return ['ok' => false, 'message' => '常规设置已保存，但独立接口密钥未能写入'];
        }

        return ['ok' => true, 'message' => 'AI客服配置已保存'];
    }

    /** 后台保存后应跳回的子页面。 */
    public static function adminReturnPage(): string
    {
        $raw = isset($_POST['acs_return_page']) && is_string($_POST['acs_return_page'])
            ? trim($_POST['acs_return_page'])
            : '';
        return array_key_exists($raw, self::ADMIN_PAGES) ? $raw : 'conversation';
    }

    /** @return array<string,mixed> */
    public static function config(): array
    {
        $defaults = self::defaults();
        $raw = [];
        foreach ($defaults as $key => $default) {
            $raw[$key] = self::value($key, $default);
        }

        return [
            'enabled' => self::bool($raw['enabled']),
            'brand_name' => self::text($raw['brand_name'], 80, 'AI客服'),
            'team_label' => self::text($raw['team_label'], 120, '智能在线服务'),
            'welcome_message' => self::text($raw['welcome_message'], 1000, '您好，我是您的 AI 客服。有什么可以帮您？'),
            'input_placeholder' => self::text($raw['input_placeholder'], 160, '输入您的问题...'),
            'quick_replies_title' => self::text($raw['quick_replies_title'], 60, ''),
            'quick_replies' => self::quickReplies((string)$raw['quick_replies']),
            'unavailable_message' => self::text($raw['unavailable_message'], 500, '当前客服暂时不可用，请稍后再试。'),
            'handoff_label' => self::text($raw['handoff_label'], 80, '联系人工客服'),
            'handoff_url' => self::httpUrl((string)$raw['handoff_url']),
            'history_limit' => self::integer($raw['history_limit'], 2, 20, 8),
            'rate_limit_per_minute' => self::integer($raw['rate_limit_per_minute'], 1, 60, 8),
            'device_mode' => self::choice($raw['device_mode'], ['all', 'desktop', 'mobile'], 'all'),
            'url_mode' => self::choice($raw['url_mode'], ['all', 'include', 'exclude'], 'all'),
            'url_rules' => self::urlRules((string)$raw['url_rules']),
            'delay_seconds' => self::integer($raw['delay_seconds'], 0, 300, 0),
            'scroll_percent' => self::integer($raw['scroll_percent'], 0, 100, 0),
            'exit_intent' => self::bool($raw['exit_intent']),
            'initial_open' => self::bool($raw['initial_open']),
            'initial_open_delay' => self::integer($raw['initial_open_delay'], 0, 120, 0),
            'once_per_session' => self::bool($raw['once_per_session']),
            'show_launcher' => self::bool($raw['show_launcher']),
            'tooltip_text' => self::text($raw['tooltip_text'], 80, ''),
            'teaser_enabled' => self::bool($raw['teaser_enabled']),
            'teaser_text' => self::text($raw['teaser_text'], 120, '有问题？随时咨询'),
            'badge_enabled' => self::bool($raw['badge_enabled']),
            'attention_effect' => self::choice($raw['attention_effect'], ['none', 'wiggle', 'bounce', 'pulse'], 'none'),
            'schedule_enabled' => self::bool($raw['schedule_enabled']),
            'schedule_days' => self::scheduleDays((string)$raw['schedule_days']),
            'schedule_start' => self::timeValue((string)$raw['schedule_start'], '00:00'),
            'schedule_end' => self::timeValue((string)$raw['schedule_end'], '23:59'),
            'launcher_style' => self::choice($raw['launcher_style'], ['bubble', 'pill'], 'bubble'),
            'launcher_icon' => self::choice($raw['launcher_icon'], ['chat', 'sparkles', 'headset', 'question'], 'chat'),
            'launcher_image_url' => self::httpUrl((string)$raw['launcher_image_url']),
            'launcher_corner' => self::integer($raw['launcher_corner'], 0, 30, 10),
            'position' => self::choice($raw['position'], ['right', 'left'], 'right'),
            'widget_size' => self::integer($raw['widget_size'], 48, 80, 56),
            'panel_width' => self::integer($raw['panel_width'], 320, 520, 388),
            'panel_height' => self::integer($raw['panel_height'], 420, 760, 580),
            'panel_radius' => self::integer($raw['panel_radius'], 4, 24, 10),
            'panel_shadow' => self::choice($raw['panel_shadow'], ['none', 'sm', 'md', 'lg'], 'md'),
            'font_size' => self::integer($raw['font_size'], 12, 18, 14),
            'desktop_offset_x' => self::integer($raw['desktop_offset_x'], 0, 96, 20),
            'desktop_offset_y' => self::integer($raw['desktop_offset_y'], 0, 96, 20),
            'mobile_offset_x' => self::integer($raw['mobile_offset_x'], 8, 32, 16),
            'mobile_offset_y' => self::integer($raw['mobile_offset_y'], 8, 64, 16),
            'accent_color' => self::color((string)$raw['accent_color'], '#0D6EFD'),
            'surface_color' => self::color((string)$raw['surface_color'], '#FFFFFF'),
            'text_color' => self::color((string)$raw['text_color'], '#1F2937'),
            'muted_color' => self::color((string)$raw['muted_color'], '#667085'),
            'header_color' => self::color((string)$raw['header_color'], '#0D6EFD'),
            'header_text_color' => self::color((string)$raw['header_text_color'], '#FFFFFF'),
            'bot_bubble_color' => self::color((string)$raw['bot_bubble_color'], '#F2F4F7'),
            'bot_bubble_text_color' => self::color((string)$raw['bot_bubble_text_color'], '#1F2937'),
            'visitor_bubble_color' => self::color((string)$raw['visitor_bubble_color'], '#0D6EFD'),
            'visitor_bubble_text_color' => self::color((string)$raw['visitor_bubble_text_color'], '#FFFFFF'),
            'avatar_url' => self::httpUrl((string)$raw['avatar_url']),
            'show_avatar' => self::bool($raw['show_avatar']),
            'show_powered_by' => self::bool($raw['show_powered_by']),
            'provider_mode' => self::choice($raw['provider_mode'], ['system', 'custom'], 'system'),
            'system_model_id' => self::integer($raw['system_model_id'], 0, 99999999, 0),
            'custom_api_endpoint' => self::customEndpoint((string)$raw['custom_api_endpoint']),
            'custom_model' => self::text($raw['custom_model'], 160, ''),
            'system_prompt' => self::text($raw['system_prompt'], 8000, ''),
            'knowledge_base' => self::text($raw['knowledge_base'], 30000, ''),
            'temperature' => self::number($raw['temperature'], 0.0, 2.0, 0.3),
            'max_tokens' => self::integer($raw['max_tokens'], 128, 4096, 800),
        ];
    }

    /** @return array<string,mixed> */
    public static function statusSummary(): array
    {
        $config = self::config();
        return [
            'enabled' => $config['enabled'],
            'provider_mode' => $config['provider_mode'],
            'system_model_id' => $config['system_model_id'],
            'custom_api_key_set' => self::customApiKeySet(),
            'knowledge_base_characters' => mb_strlen((string)$config['knowledge_base']),
            'display' => [
                'device_mode' => $config['device_mode'],
                'url_mode' => $config['url_mode'],
                'schedule_enabled' => $config['schedule_enabled'],
            ],
        ];
    }

    public static function customApiKeySet(): bool
    {
        try {
            return trim((string)\App\Core\Setting::get(self::CUSTOM_KEY_SETTING, '')) !== '';
        } catch (\Throwable $_) {
            return false;
        }
    }

    /** @throws \RuntimeException */
    public static function customApiKey(): string
    {
        $stored = '';
        try {
            $stored = trim((string)\App\Core\Setting::get(self::CUSTOM_KEY_SETTING, ''));
        } catch (\Throwable $_) {
            $stored = '';
        }
        if ($stored === '') {
            throw new \RuntimeException('custom_api_key_missing');
        }
        return \App\Core\Security::decryptApiKey($stored);
    }

    public static function renderWidget(): void
    {
        $config = self::config();
        if (!$config['enabled'] || !self::pathAllowed($config) || !self::scheduleAllowed($config)) {
            return;
        }

        $public = self::publicWidgetConfig($config);
        $json = json_encode(
            $public,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        if (!is_string($json)) return;

        $style = implode(';', [
            '--acs-accent:' . $config['accent_color'],
            '--acs-surface:' . $config['surface_color'],
            '--acs-text:' . $config['text_color'],
            '--acs-muted:' . $config['muted_color'],
            '--acs-font:' . (int)$config['font_size'] . 'px',
            '--acs-header-bg:' . $config['header_color'],
            '--acs-header-fg:' . $config['header_text_color'],
            '--acs-bot-bubble:' . $config['bot_bubble_color'],
            '--acs-bot-text:' . $config['bot_bubble_text_color'],
            '--acs-vis-bubble:' . $config['visitor_bubble_color'],
            '--acs-vis-text:' . $config['visitor_bubble_text_color'],
            '--acs-size:' . (int)$config['widget_size'] . 'px',
            '--acs-panel-width:' . (int)$config['panel_width'] . 'px',
            '--acs-panel-height:' . (int)$config['panel_height'] . 'px',
            '--acs-panel-radius:' . (int)$config['panel_radius'] . 'px',
            '--acs-launcher-corner:' . (int)$config['launcher_corner'] . 'px',
            '--acs-desktop-x:' . (int)$config['desktop_offset_x'] . 'px',
            '--acs-desktop-y:' . (int)$config['desktop_offset_y'] . 'px',
            '--acs-mobile-x:' . (int)$config['mobile_offset_x'] . 'px',
            '--acs-mobile-y:' . (int)$config['mobile_offset_y'] . 'px',
        ]);

        $bareClass = empty($config['show_launcher']) ? ' acs-widget--bare' : '';
        echo '<div id="ai-customer-service-widget" class="acs-widget acs-widget--'
            . self::escape((string)$config['position']) . ' acs-widget--' . self::escape((string)$config['launcher_style'])
            . ' acs-shadow--' . self::escape((string)$config['panel_shadow']) . $bareClass
            . '" data-visible="false" style="' . self::escape($style) . '">';

        if (!empty($config['show_launcher'])) {
            $launcherClasses = ['acs-launcher'];
            if (!empty($config['badge_enabled'])) $launcherClasses[] = 'has-badge';
            if ($config['attention_effect'] !== 'none') $launcherClasses[] = 'acs-fx--' . $config['attention_effect'];
            $inner = '';
            if ((string)$config['launcher_image_url'] !== '') {
                $inner .= '<img class="acs-launcher-img" src="' . self::escape((string)$config['launcher_image_url']) . '" alt="">';
            } else {
                $inner .= '<i class="bi ' . self::escape(self::launcherIconClass((string)$config['launcher_icon'])) . ' acs-launcher-icon" aria-hidden="true"></i>';
            }
            $inner .= '<span class="acs-launcher-label">' . self::escape((string)$config['brand_name']) . '</span>';
            $inner .= '<i class="bi bi-x-lg acs-launcher-close" aria-hidden="true"></i>';
            echo '<button type="button" class="' . self::escape(implode(' ', $launcherClasses))
                . '" aria-expanded="false" aria-controls="acs-panel" aria-label="打开 AI客服">' . $inner . '</button>';
            if ((string)$config['tooltip_text'] !== '') {
                echo '<span class="acs-tooltip" aria-hidden="true">' . self::escape((string)$config['tooltip_text']) . '</span>';
            }
            if (!empty($config['teaser_enabled'])) {
                echo '<div class="acs-teaser" data-acs-teaser hidden>'
                    . '<button type="button" class="acs-teaser-close" aria-label="收起引导消息"><i class="bi bi-x-lg" aria-hidden="true"></i></button>'
                    . '<span class="acs-teaser-text">' . self::escape((string)$config['teaser_text']) . '</span></div>';
            }
        }

        echo '<section id="acs-panel" class="acs-panel" aria-label="AI客服对话" aria-hidden="true" hidden>';
        echo '<header class="acs-header"><div class="acs-agent">' . self::avatarMarkup($config) . '<div class="acs-agent-copy">'
            . '<strong>' . self::escape((string)$config['brand_name']) . '</strong>'
            . '<span>' . self::escape((string)$config['team_label']) . '</span></div></div>'
            . '<button type="button" class="acs-close" aria-label="关闭对话"><i class="bi bi-x-lg" aria-hidden="true"></i></button></header>';
        echo '<div class="acs-chat" aria-live="polite" aria-relevant="additions text"></div>';
        echo '<div class="acs-quick-replies" aria-label="快捷问题"></div>';
        if ($config['handoff_url'] !== '') {
            echo '<a class="acs-handoff" href="' . self::escape($config['handoff_url']) . '" target="_blank" rel="noopener">'
                . '<i class="bi bi-person-workspace" aria-hidden="true"></i><span>' . self::escape((string)$config['handoff_label']) . '</span></a>';
        }
        if (!empty($config['show_powered_by'])) {
            echo '<div class="acs-powered">AI客服</div>';
        }
        echo '<form class="acs-composer"><label class="visually-hidden" for="acs-message">输入消息</label>'
            . '<textarea id="acs-message" name="message" rows="1" maxlength="2000" placeholder="'
            . self::escape((string)$config['input_placeholder']) . '"></textarea>'
            . '<button type="submit" class="acs-send" aria-label="发送消息"><i class="bi bi-arrow-up" aria-hidden="true"></i></button>'
            . '</form><div class="acs-feedback" role="status" aria-live="polite"></div></section>';
        echo '<script id="acs-widget-config" type="application/json">' . $json . '</script></div>';
    }

    /** @return array<string,mixed> */
    public static function publicWidgetConfig(array $config): array
    {
        return [
            'endpoint' => url('ai-customer-service/chat'),
            'csrf' => \App\Core\Csrf::token(),
            'conversationId' => self::newConversation(),
            'brandName' => $config['brand_name'],
            'welcomeMessage' => $config['welcome_message'],
            'quickRepliesTitle' => $config['quick_replies_title'],
            'quickReplies' => $config['quick_replies'],
            'unavailableMessage' => $config['unavailable_message'],
            'deviceMode' => $config['device_mode'],
            'delaySeconds' => $config['delay_seconds'],
            'scrollPercent' => $config['scroll_percent'],
            'exitIntent' => $config['exit_intent'],
            'initialOpen' => $config['initial_open'],
            'initialOpenDelay' => $config['initial_open_delay'],
            'oncePerSession' => $config['once_per_session'],
            'teaserEnabled' => $config['teaser_enabled']
                && (string)$config['teaser_text'] !== ''
                && !empty($config['show_launcher']),
            'teaserText' => $config['teaser_text'],
            'badgeEnabled' => $config['badge_enabled'] && !empty($config['show_launcher']),
            'attentionEffect' => empty($config['show_launcher']) ? 'none' : $config['attention_effect'],
        ];
    }

    public static function newConversation(): string
    {
        self::pruneConversations();
        $id = bin2hex(random_bytes(18));
        $conversations =& self::conversations();
        $conversations[$id] = [
            'messages' => [],
            'created_at' => time(),
            'updated_at' => time(),
        ];
        return $id;
    }

    /** @return array<string,mixed>|null */
    public static function conversation(string $id): ?array
    {
        if (!preg_match('/^[a-f0-9]{36}$/', $id)) return null;
        self::pruneConversations();
        $conversations =& self::conversations();
        $conversation = $conversations[$id] ?? null;
        return is_array($conversation) ? $conversation : null;
    }

    /** @param array<string,mixed> $conversation */
    public static function saveConversation(string $id, array $conversation): void
    {
        if (!preg_match('/^[a-f0-9]{36}$/', $id)) return;
        $conversation['updated_at'] = time();
        $conversations =& self::conversations();
        $conversations[$id] = $conversation;
        self::pruneConversations();
    }

    /** @return array<string,mixed> */
    private static function defaults(): array
    {
        return [
            'enabled' => true, 'brand_name' => 'AI客服', 'team_label' => '智能在线服务',
            'welcome_message' => '您好，我是您的 AI 客服。有什么可以帮您？', 'input_placeholder' => '输入您的问题...',
            'quick_replies_title' => '', 'quick_replies' => "我想了解产品\n如何获取报价\n如何联系人工客服",
            'unavailable_message' => '当前客服暂时不可用，请稍后再试。',
            'handoff_label' => '联系人工客服', 'handoff_url' => '', 'history_limit' => 8, 'rate_limit_per_minute' => 8,
            'device_mode' => 'all', 'url_mode' => 'all', 'url_rules' => '', 'delay_seconds' => 0, 'scroll_percent' => 0,
            'exit_intent' => false, 'initial_open' => false, 'initial_open_delay' => 0, 'once_per_session' => false,
            'show_launcher' => true, 'tooltip_text' => '', 'teaser_enabled' => false,
            'teaser_text' => '有问题？随时咨询', 'badge_enabled' => false, 'attention_effect' => 'none',
            'schedule_enabled' => false, 'schedule_days' => '1,2,3,4,5,6,7',
            'schedule_start' => '00:00', 'schedule_end' => '23:59', 'launcher_style' => 'bubble', 'launcher_icon' => 'chat',
            'launcher_image_url' => '', 'launcher_corner' => 10,
            'position' => 'right', 'widget_size' => 56, 'panel_width' => 388, 'panel_height' => 580,
            'panel_radius' => 10, 'panel_shadow' => 'md', 'font_size' => 14,
            'desktop_offset_x' => 20, 'desktop_offset_y' => 20, 'mobile_offset_x' => 16, 'mobile_offset_y' => 16,
            'accent_color' => '#0D6EFD', 'surface_color' => '#FFFFFF',
            'text_color' => '#1F2937', 'muted_color' => '#667085',
            'header_color' => '#0D6EFD', 'header_text_color' => '#FFFFFF',
            'bot_bubble_color' => '#F2F4F7', 'bot_bubble_text_color' => '#1F2937',
            'visitor_bubble_color' => '#0D6EFD', 'visitor_bubble_text_color' => '#FFFFFF',
            'avatar_url' => '', 'show_avatar' => true, 'show_powered_by' => false, 'provider_mode' => 'system',
            'system_model_id' => 0, 'custom_api_endpoint' => 'https://api.openai.com/v1/chat/completions',
            'custom_model' => '', 'system_prompt' => '以专业、友好、简洁的方式回答访客问题。仅在信息明确时给出结论；信息不足时明确说明并引导访客联系人工客服。',
            'knowledge_base' => '', 'temperature' => 0.3, 'max_tokens' => 800,
        ];
    }

    private static function value(string $key, mixed $default): mixed
    {
        try {
            return \App\Core\PluginSettingsService::value(self::SLUG, $key, $default);
        } catch (\Throwable $_) {
            return $default;
        }
    }

    /** @return array<string,mixed> */
    private static function &conversations(): array
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_array($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = [];
        }
        if (!isset($_SESSION[self::SESSION_KEY]['conversations']) || !is_array($_SESSION[self::SESSION_KEY]['conversations'])) {
            $_SESSION[self::SESSION_KEY]['conversations'] = [];
        }
        return $_SESSION[self::SESSION_KEY]['conversations'];
    }

    private static function pruneConversations(): void
    {
        $now = time();
        $conversations =& self::conversations();
        foreach ($conversations as $id => $conversation) {
            if (!is_array($conversation) || $now - (int)($conversation['updated_at'] ?? 0) > self::CONVERSATION_TTL) {
                unset($conversations[$id]);
            }
        }
        if (count($conversations) <= self::MAX_CONVERSATIONS) return;
        uasort($conversations, static fn (array $a, array $b): int => (int)($a['updated_at'] ?? 0) <=> (int)($b['updated_at'] ?? 0));
        while (count($conversations) > self::MAX_CONVERSATIONS) {
            array_shift($conversations);
        }
    }

    private static function pathAllowed(array $config): bool
    {
        $mode = (string)$config['url_mode'];
        if ($mode === 'all') return true;
        $rules = (array)$config['url_rules'];
        if ($rules === []) return $mode !== 'include';
        $path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
        $matched = false;
        foreach ($rules as $rule) {
            if (self::pathMatches($path, (string)$rule)) {
                $matched = true;
                break;
            }
        }
        return $mode === 'include' ? $matched : !$matched;
    }

    private static function scheduleAllowed(array $config): bool
    {
        if (empty($config['schedule_enabled'])) return true;
        $day = (int)date('N');
        if (!in_array($day, (array)$config['schedule_days'], true)) return false;
        $now = date('H:i');
        $start = (string)$config['schedule_start'];
        $end = (string)$config['schedule_end'];
        if ($start <= $end) return $now >= $start && $now <= $end;
        return $now >= $start || $now <= $end;
    }

    private static function pathMatches(string $path, string $rule): bool
    {
        $rule = trim($rule);
        if ($rule === '') return false;
        $regex = '#^' . str_replace('\\*', '.*', preg_quote($rule, '#')) . '$#u';
        return preg_match($regex, $path) === 1;
    }

    private static function validateUrlRules(string $raw): string
    {
        $rules = self::urlRules($raw);
        if (count($rules) > 80) return '页面路径规则最多 80 条';
        foreach ($rules as $rule) {
            if (strlen($rule) > 250 || $rule[0] !== '/' || str_contains($rule, '?') || str_contains($rule, '#')) {
                return '页面路径规则必须是以 / 开头、不含查询参数的相对路径';
            }
        }
        return '';
    }

    /** @return list<string> */
    private static function urlRules(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $raw) ?: [] as $rule) {
            $rule = trim($rule);
            if ($rule !== '') $out[] = mb_substr($rule, 0, 250);
        }
        return array_values(array_unique(array_slice($out, 0, 80)));
    }

    /** @return list<string> */
    private static function quickReplies(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $raw) ?: [] as $reply) {
            $reply = trim($reply);
            if ($reply !== '') $out[] = mb_substr($reply, 0, 180);
        }
        return array_values(array_unique(array_slice($out, 0, 8)));
    }

    /** @return list<int> */
    private static function scheduleDays(string $raw): array
    {
        $out = [];
        foreach (explode(',', $raw) as $day) {
            $number = (int)trim($day);
            if ($number >= 1 && $number <= 7) $out[] = $number;
        }
        return array_values(array_unique($out)) ?: [1, 2, 3, 4, 5, 6, 7];
    }

    private static function bool(mixed $value): bool
    {
        return is_bool($value) ? $value : in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function integer(mixed $value, int $min, int $max, int $fallback): int
    {
        return max($min, min($max, is_numeric($value) ? (int)$value : $fallback));
    }

    private static function number(mixed $value, float $min, float $max, float $fallback): float
    {
        return max($min, min($max, is_numeric($value) ? (float)$value : $fallback));
    }

    private static function choice(mixed $value, array $allowed, string $fallback): string
    {
        $value = strtolower(trim((string)$value));
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private static function text(mixed $value, int $limit, string $fallback): string
    {
        $value = trim(\App\Core\Security::sanitizeUserInput((string)$value));
        return $value === '' && $fallback !== '' ? $fallback : mb_substr($value, 0, $limit);
    }

    private static function color(string $value, string $fallback): string
    {
        return preg_match('/^#[0-9a-f]{6}$/i', $value) === 1 ? strtoupper($value) : $fallback;
    }

    private static function httpUrl(string $value): string
    {
        $value = trim($value);
        return preg_match('#^https?://#i', $value) === 1 && filter_var($value, FILTER_VALIDATE_URL) !== false
            ? mb_substr($value, 0, 2048)
            : '';
    }

    private static function customEndpoint(string $value): string
    {
        $value = self::httpUrl($value);
        if ($value === '' || !preg_match('#^https://#i', $value)) return '';
        $parts = parse_url($value);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            return '';
        }
        return $value;
    }

    private static function timeValue(string $value, string $fallback): string
    {
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) === 1 ? $value : $fallback;
    }

    private static function launcherIconClass(string $icon): string
    {
        return match ($icon) {
            'sparkles' => 'bi-stars',
            'headset' => 'bi-headset',
            'question' => 'bi-question-circle-fill',
            default => 'bi-chat-dots-fill',
        };
    }

    private static function avatarMarkup(array $config): string
    {
        if (empty($config['show_avatar'])) return '';
        if ((string)$config['avatar_url'] !== '') {
            return '<img class="acs-avatar" src="' . self::escape((string)$config['avatar_url']) . '" alt="">';
        }
        return '<span class="acs-avatar acs-avatar--fallback" aria-hidden="true"><i class="bi bi-stars"></i></span>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/** Canonical plugin API handler; its manifest declaration generates API docs and Agent access. */
final class AiCustomerServiceApi
{
    /** @return array<string,mixed> */
    public static function status(array $arguments, array $context): array
    {
        return [
            'ok' => true,
            'message' => '已读取 AI 客服状态',
            'data' => AiCustomerService::statusSummary(),
        ];
    }
}
