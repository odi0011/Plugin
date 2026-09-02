<?php
declare(strict_types=1);

if (!defined('CODE_SCHEMA_VERSION')) exit;

$pdo = \App\Core\Database::pdo();
foreach ([
    'plugin_search_insights_merchant_issues',
    'plugin_search_insights_geo',
    'plugin_search_insights_pagespeed',
    'plugin_search_insights_url_inspections',
    'plugin_search_insights_metrics',
    'plugin_search_insights_connections',
] as $table) {
    $safe = str_replace('`', '', \App\Core\Database::prefix() . $table);
    $pdo->exec("DROP TABLE IF EXISTS `{$safe}`");
}
foreach (['google_verification', 'bing_verification'] as $key) {
    \App\Core\Database::table('settings')
        ->where('key', 'plugin.search-insights.' . $key)
        ->delete();
}
