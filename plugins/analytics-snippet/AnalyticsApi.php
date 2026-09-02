<?php

final class AnalyticsSnippetApi
{
    public static function status(array $arguments, array $context): array
    {
        $id = function_exists('analytics_snippet_measurement_id')
            ? analytics_snippet_measurement_id()
            : '';
        $mode = strtolower(trim((string)get_plugin_setting('analytics-snippet', 'consent_mode', 'basic')));
        if (!in_array($mode, ['basic', 'advanced'], true)) $mode = 'basic';

        return [
            'ok' => true,
            'message' => $id !== '' ? 'Google Analytics 4 配置有效' : 'Google Analytics 4 尚未配置',
            'data' => [
                'enabled' => (string)get_plugin_setting('analytics-snippet', 'enabled', '0') === '1',
                'configured' => $id !== '',
                'measurement_id' => $id,
                'consent_mode' => $mode,
                'consent_state' => function_exists('analytics_snippet_consent_state')
                    ? analytics_snippet_consent_state()
                    : 'unknown',
            ],
        ];
    }
}
