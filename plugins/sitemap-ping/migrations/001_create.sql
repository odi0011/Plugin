CREATE TABLE IF NOT EXISTS `{{prefix}}plugin_sitemap_pings` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `endpoint` VARCHAR(500) NOT NULL DEFAULT '',
    `trigger_type` VARCHAR(40) NOT NULL DEFAULT '',
    `trigger_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `ok` TINYINT(1) NOT NULL DEFAULT 0,
    `message` VARCHAR(500) NOT NULL DEFAULT '',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ping_created` (`created_at`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='站点地图推送日志（sitemap-ping 插件）';
