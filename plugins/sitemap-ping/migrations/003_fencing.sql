ALTER TABLE `{{prefix}}plugin_sitemap_submission_urls`
    ADD COLUMN `claim_token` CHAR(64) DEFAULT NULL AFTER `locked_until`;
