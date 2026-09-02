CREATE TABLE IF NOT EXISTS `{{prefix}}plugin_redirects` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `from_url`    VARCHAR(500) NOT NULL,
    `to_url`      VARCHAR(500) NOT NULL,
    `status_code` SMALLINT     NOT NULL DEFAULT 302,
    `enabled`     TINYINT(1)   NOT NULL DEFAULT 1,
    `hits`        INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_from` (`from_url`(191)),
    KEY `idx_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='重定向规则（redirect-rules 插件）';
