-- 可视化编辑器（visual-editor）数据表
--
-- 插件迁移只接受 CREATE TABLE / CREATE INDEX / ALTER TABLE ADD 与普通 DML，
-- 且没有站点表前缀替换。因此这里建的是**字面表名**，运行时也只用字面表名
-- （见 src/Document.php 的 TABLE_* 常量），不走 Database::table()——
-- 后者会拼上站点前缀，和这里建出来的名字对不上。

CREATE TABLE IF NOT EXISTS `plugin_visual_documents` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `slug`            VARCHAR(191)    NOT NULL,
    `title`           VARCHAR(255)    NOT NULL DEFAULT '',
    `status`          VARCHAR(20)     NOT NULL DEFAULT 'draft',
    `doc_json`        LONGTEXT        NULL,
    `css_cache`       LONGTEXT        NULL,
    `seo_title`       VARCHAR(255)    NOT NULL DEFAULT '',
    `seo_description` VARCHAR(500)    NOT NULL DEFAULT '',
    `lock_version`    INT UNSIGNED    NOT NULL DEFAULT 0,
    `created_by`      INT UNSIGNED    NOT NULL DEFAULT 0,
    `updated_by`      INT UNSIGNED    NOT NULL DEFAULT 0,
    `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_visual_slug` (`slug`),
    KEY `idx_visual_status` (`status`, `updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='可视化文档（visual-editor 插件）';

CREATE TABLE IF NOT EXISTS `plugin_visual_revisions` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `document_id` BIGINT UNSIGNED NOT NULL,
    `doc_json`    LONGTEXT        NULL,
    `note`        VARCHAR(200)    NOT NULL DEFAULT '',
    `created_by`  INT UNSIGNED    NOT NULL DEFAULT 0,
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_visual_revision_doc` (`document_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='可视化文档修订（visual-editor 插件）';
