<?php
/**
 * Consent-aware Google Analytics 4 integration.
 *
 * Configuration is declared in plugin.json, so the normal settings UI, public
 * plugin-settings API, and Agent gateway all share the same validation path.
 */
if (!defined('CODE_SCHEMA_VERSION')) exit;

require_once __DIR__ . '/AnalyticsApi.php';

if (!function_exists('analytics_snippet_measurement_id')) {
    function analytics_snippet_measurement_id(): string
    {
        $id = strtoupper(trim((string)get_plugin_setting('analytics-snippet', 'ga_id', '')));
        return preg_match('/^G-[A-Z0-9]{10}$/D', $id) === 1 ? $id : '';
    }
}

if (!function_exists('analytics_snippet_consent_state')) {
    function analytics_snippet_consent_state(): string
    {
        try {
            if (class_exists(\App\Core\AttributionService::class)) {
                $state = \App\Core\AttributionService::consentState();
                if (in_array($state, ['granted', 'denied'], true)) return $state;
            }
        } catch (\Throwable $_) {
        }
        return 'unknown';
    }
}

if (!function_exists('analytics_snippet_migrate_legacy_settings')) {
    function analytics_snippet_migrate_legacy_settings(): void
    {
        try {
            $enabledKey = 'plugin.analytics-snippet.enabled';
            if (!\App\Core\Setting::has($enabledKey)) {
                \App\Core\Setting::setRuntime(
                    $enabledKey,
                    analytics_snippet_measurement_id() !== '' ? '1' : '0'
                );
            }
            $modeKey = 'plugin.analytics-snippet.consent_mode';
            if (!\App\Core\Setting::has($modeKey)) {
                \App\Core\Setting::setRuntime($modeKey, 'basic');
            }
        } catch (\Throwable $_) {
            // Declarative defaults remain fail-closed if the settings table is unavailable.
        }
    }
}

analytics_snippet_migrate_legacy_settings();

add_action('plugin.activated', static function ($slug): void {
    if ($slug !== 'analytics-snippet') return;
    register_plugin_setting('analytics-snippet', 'enabled', '0');
    register_plugin_setting('analytics-snippet', 'ga_id', '');
    register_plugin_setting('analytics-snippet', 'consent_mode', 'basic');
});

add_action('frontend.head', static function (): void {
    if ((string)get_plugin_setting('analytics-snippet', 'enabled', '0') !== '1') return;
    $measurementId = analytics_snippet_measurement_id();
    if ($measurementId === '') return;

    $mode = strtolower(trim((string)get_plugin_setting('analytics-snippet', 'consent_mode', 'basic')));
    if (!in_array($mode, ['basic', 'advanced'], true)) $mode = 'basic';
    $state = analytics_snippet_consent_state();
    $idJson = json_encode($measurementId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $modeJson = json_encode($mode, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $stateJson = json_encode($state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    if (!is_string($idJson) || !is_string($modeJson) || !is_string($stateJson)) return;

    echo <<<HTML
    <!-- Google Analytics 4 (analytics-snippet plugin) -->
    <script>
    (function(w,d){
        'use strict';
        var measurementId={$idJson};
        var consentMode={$modeJson};
        var consentState={$stateJson};
        var loaded=false;
        w.dataLayer=w.dataLayer||[];
        w.gtag=w.gtag||function(){w.dataLayer.push(arguments);};

        function consentValue(state){return state==='granted'?'granted':'denied';}
        function updateConsent(state){
            consentState=state;
            w.gtag('consent','update',{
                analytics_storage:consentValue(state),
                ad_storage:'denied',
                ad_user_data:'denied',
                ad_personalization:'denied'
            });
            if(state!=='granted') clearAnalyticsCookies();
        }
        function clearAnalyticsCookies(){
            var names=(d.cookie||'').split(';').map(function(row){return row.split('=')[0].trim();});
            var host=String(w.location&&w.location.hostname||'').replace(/^\.+/,'');
            var labels=host.split('.');
            var domains=[''];
            if(host) domains.push(host,'.'+host);
            for(var i=1;i<labels.length-1;i++) domains.push('.'+labels.slice(i).join('.'));
            names.forEach(function(name){
                if(!/^_(?:ga|gid|gat|gcl_)/.test(name)) return;
                domains.forEach(function(domain){
                    d.cookie=name+'=; Max-Age=0; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; SameSite=Lax'+(domain?'; domain='+domain:'');
                });
            });
        }
        function loadTag(){
            if(loaded) return;
            loaded=true;
            var script=d.createElement('script');
            script.async=true;
            script.src='https://www.googletagmanager.com/gtag/js?id='+encodeURIComponent(measurementId);
            d.head.appendChild(script);
            w.gtag('js',new Date());
            w.gtag('config',measurementId,{'send_page_view':true});
        }
        function applyState(state){
            updateConsent(state);
            if(consentMode==='advanced'||state==='granted') loadTag();
        }

        w.gtag('consent','default',{
            analytics_storage:consentValue(consentState),
            ad_storage:'denied',
            ad_user_data:'denied',
            ad_personalization:'denied',
            wait_for_update:500
        });
        if(consentState!=='granted') clearAnalyticsCookies();
        if(consentMode==='advanced'||consentState==='granted') loadTag();

        function bindConsentRoot(root){
            if(!root||root.dataset.analyticsConsentBound==='1') return;
            root.dataset.analyticsConsentBound='1';
            var observer=new MutationObserver(function(){applyState(String(root.dataset.state||'unknown'));});
            observer.observe(root,{attributes:true,attributeFilter:['data-state']});
        }
        d.addEventListener('odcms:inquiry-submitted',function(event){
            if(consentState!=='granted') return;
            loadTag();
            var detail=event&&event.detail&&typeof event.detail==='object'?event.detail:{};
            w.gtag('event','generate_lead',{
                send_to:measurementId,
                method:detail.channel==='cart'?'inquiry_cart':'inquiry_form',
                content_type:detail.product_context?'product':'content'
            });
        });
        if(d.readyState==='loading'){
            d.addEventListener('DOMContentLoaded',function(){d.querySelectorAll('[data-privacy-consent]').forEach(bindConsentRoot);});
        }else{
            d.querySelectorAll('[data-privacy-consent]').forEach(bindConsentRoot);
        }
    }(window,document));
    </script>
    <!-- End Google Analytics 4 -->

HTML;
});
