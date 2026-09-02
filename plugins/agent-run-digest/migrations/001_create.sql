CREATE TABLE IF NOT EXISTS `{{prefix}}plugin_agent_run_events` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `run_id` BIGINT UNSIGNED NOT NULL,
    `session_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `event_type` VARCHAR(40) NOT NULL DEFAULT '',
    `task_status` VARCHAR(40) NOT NULL DEFAULT '',
    `occurred_on` DATE NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_run_event` (`run_id`, `event_type`),
    KEY `idx_digest_day` (`occurred_on`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Agent 运行事件（agent-run-digest 插件）';
