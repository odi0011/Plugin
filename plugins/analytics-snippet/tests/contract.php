<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$manifest = json_decode($read('plugin.json'), true, 32, JSON_THROW_ON_ERROR);
$plugin = $read('plugin.php');

$assert(($manifest['version'] ?? '') === '1.1.0', 'version was not advanced');
$assert(($manifest['requires_core'] ?? '') === '7k', 'inquiry conversion event requires the 7k core contract');
$assert(($manifest['settings']['permission'] ?? '') === 'analytics.config', 'declarative settings permission is missing');
$fields = [];
foreach (($manifest['settings']['sections'] ?? []) as $section) {
    foreach (($section['fields'] ?? []) as $field) $fields[(string)($field['key'] ?? '')] = $field;
}
$assert(($fields['ga_id']['pattern'] ?? '') === '^(?:|G-[A-Z0-9]{10})$', 'GA4 measurement id validation is not strict');
$assert(isset($fields['enabled'], $fields['consent_mode']), 'consent-aware settings are incomplete');
$assert(($manifest['api'][0]['endpoint'] ?? '') === 'status'
    && ($manifest['api'][0]['handler'] ?? '') === 'AnalyticsSnippetApi::status',
    'status API parity declaration is missing');
$assert(!str_contains($plugin, 'routes.admin.register'), 'legacy custom settings route remains executable');
$assert(str_contains($plugin, "gtag('consent','default'")
    && str_contains($plugin, "gtag('consent','update'")
    && str_contains($plugin, "consentMode==='advanced'||consentState==='granted'"),
    'Consent Mode is not enforced before tag loading');
$assert(str_contains($plugin, 'MutationObserver') && str_contains($plugin, 'clearAnalyticsCookies'),
    'consent withdrawal is not applied without a reload');
$assert(str_contains($plugin, 'analytics_snippet_migrate_legacy_settings();')
    && str_contains($plugin, 'analytics_snippet_measurement_id() !==')
    && str_contains($plugin, "Setting::setRuntime(\$modeKey, 'basic')"),
    'active 1.0 upgrades do not preserve a valid GA4 configuration inside Basic Consent Mode');

echo "analytics-snippet contract checks passed.\n";
