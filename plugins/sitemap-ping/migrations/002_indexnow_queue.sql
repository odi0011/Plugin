CREATE TABLE IF NOT EXISTS `{{prefix}}plugin_sitemap_submission_urls` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `url_hash` CHAR(64) NOT NULL,
    `url` VARCHAR(2048) NOT NULL,
    `change_type` ENUM('upsert','delete') NOT NULL DEFAULT 'upsert',
    `status` ENUM('queued','running','succeeded','failed') NOT NULL DEFAULT 'queued',
    `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `available_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `locked_until` DATETIME DEFAULT NULL,
    `claim_token` CHAR(64) DEFAULT NULL,
    `last_error` VARCHAR(180) DEFAULT NULL,
    `completed_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_indexnow_url_hash` (`url_hash`),
    KEY `idx_indexnow_claim` (`status`,`available_at`,`id`),
    KEY `idx_indexnow_fence` (`claim_token`),
    KEY `idx_indexnow_updated` (`updated_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='IndexNow URL submission queue';

UPDATE `{{prefix}}plugin_sitemap_pings`
SET `endpoint` = 'https://api.indexnow.org/indexnow'
WHERE `endpoint` <> 'https://api.indexnow.org/indexnow';
