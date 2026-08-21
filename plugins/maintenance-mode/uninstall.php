<?php
/**
 * maintenance-mode 卸载清理：删掉本插件的设置键。
 */
foreach (['enabled', 'title', 'message', 'eta', 'allow_ips', 'retry_after'] as $key) {
    try {
        \App\Core\Database::table('settings')->where('key', 'plugin.maintenance-mode.' . $key)->delete();
    } catch (\Throwable $_) {}
}
