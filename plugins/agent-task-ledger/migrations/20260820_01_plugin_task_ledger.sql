-- agent-task-ledger 插件数据库迁移（激活时执行，幂等）
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `plugin_task_ledger` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_id` BIGINT UNSIGNED NOT NULL,
    `note` VARCHAR(2000) NOT NULL DEFAULT '',
    `tag` VARCHAR(40) NOT NULL DEFAULT '',
    `created_by` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ledger_session` (`session_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
