<?php
/** uninstall.php - remove every declarative setting owned by this plugin. */
foreach (['enabled', 'ga_id', 'consent_mode'] as $key) {
    try {
        \App\Core\Setting::forget('plugin.analytics-snippet.' . $key);
    } catch (\Throwable $_) {
    }
}
