-- ==============================================================================
-- Migration 002: Lot Substitution & Re-Inspection Flow
-- Sistem OQC PT Surya Technology Industri | 2026-09-06
-- ==============================================================================
SET FOREIGN_KEY_CHECKS = 0;

-- 1. ALTER inspection_session_lots
ALTER TABLE `inspection_session_lots`
  ADD COLUMN `lot_status`
    ENUM('ok','ng_found','ng_quarantine','replaced','reinspected')
    NOT NULL DEFAULT 'ok' AFTER `remarks`,
  ADD COLUMN `replaced_by_lot_id`
    BIGINT(20) UNSIGNED NULL AFTER `lot_status`,
  ADD COLUMN `action_noted_at`
    DATETIME NULL AFTER `replaced_by_lot_id`,
  ADD INDEX `idx_session_lots_status` (`lot_status`);

-- 2. ALTER inspection_sessions
ALTER TABLE `inspection_sessions`
  ADD COLUMN `parent_session_id`
    BIGINT(20) UNSIGNED NULL AFTER `auto_fulfilled_by_session_id`,
  ADD COLUMN `is_reinspection`
    TINYINT(1) NOT NULL DEFAULT 0 AFTER `parent_session_id`,
  ADD COLUMN `reinspection_type`
    VARCHAR(50) NULL AFTER `is_reinspection`,
  ADD COLUMN `reinspection_notes`
    TEXT NULL AFTER `reinspection_type`,
  ADD INDEX `idx_sessions_parent` (`parent_session_id`),
  ADD INDEX `idx_sessions_is_reinspection` (`is_reinspection`);

-- 3. CREATE TABLE lot_substitution_log
CREATE TABLE IF NOT EXISTS `lot_substitution_log` (
  `id`                          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `original_session_id`         BIGINT(20) UNSIGNED NOT NULL,
  `ng_session_lot_id`           BIGINT(20) UNSIGNED NOT NULL,
  `action_type`                 ENUM('rescan_same_lot','replace_lot') NOT NULL,
  `replacement_session_lot_id`  BIGINT(20) UNSIGNED NULL,
  `reinspection_session_id`     BIGINT(20) UNSIGNED NULL,
  `actioned_by`                 BIGINT(20) UNSIGNED NULL,
  `notes`                       TEXT NULL,
  `created_at`                  DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_subst_orig_session` (`original_session_id`),
  KEY `idx_subst_ng_lot`       (`ng_session_lot_id`),
  KEY `idx_subst_reinsp_sess`  (`reinspection_session_id`),
  CONSTRAINT `fk_subst_orig_session`
    FOREIGN KEY (`original_session_id`)
    REFERENCES `inspection_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
