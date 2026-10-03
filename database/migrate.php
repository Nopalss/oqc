<?php
/**
 * OQC Database Migration Script
 * Run via CLI: php database/migrate.php
 * Or via web if needed.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

$pdo = getDB();
if (!$pdo) {
    die("Database connection failed.\n");
}

echo "Running OQC Database Migrations...\n";

// 1. did_batches table
$pdo->exec("CREATE TABLE IF NOT EXISTS `did_batches` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `batch_name` VARCHAR(255) NOT NULL,
  `import_method` ENUM('manual', 'excel_import') NOT NULL DEFAULT 'manual',
  `total_items` INT(11) NOT NULL DEFAULT 0,
  `ok_count` INT(11) NOT NULL DEFAULT 0,
  `ng_count` INT(11) NOT NULL DEFAULT 0,
  `created_by` BIGINT(20) UNSIGNED NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// 2. batch_id in daily_inspection_data
try {
    $cols = $pdo->query("SHOW COLUMNS FROM `daily_inspection_data` LIKE 'batch_id'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE `daily_inspection_data` ADD COLUMN `batch_id` BIGINT(20) UNSIGNED NULL AFTER `id`");
    }
} catch (Exception $e) {}

// 3. inspecting_date in did_batches
try {
    $colsDate = $pdo->query("SHOW COLUMNS FROM `did_batches` LIKE 'inspecting_date'")->fetchAll();
    if (empty($colsDate)) {
        $pdo->exec("ALTER TABLE `did_batches` ADD COLUMN `inspecting_date` DATE NULL AFTER `batch_name`");
    }
} catch (Exception $e) {}

// 4. master_customers
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `master_customers` (
      `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
      `name` VARCHAR(255) NOT NULL,
      `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
      `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uk_customer_name` (`name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {}

// 5. Chief Approval columns in inspection_sessions
try {
    $colsChiefApp = $pdo->query("SHOW COLUMNS FROM `inspection_sessions` LIKE 'is_chief_approved'")->fetchAll();
    if (empty($colsChiefApp)) {
        $pdo->exec("ALTER TABLE `inspection_sessions` 
            ADD COLUMN `is_chief_approved` TINYINT(1) NOT NULL DEFAULT 0 AFTER `approval_notes`,
            ADD COLUMN `chief_approved_by` BIGINT(20) UNSIGNED NULL AFTER `is_chief_approved`,
            ADD COLUMN `chief_approved_at` DATETIME NULL AFTER `chief_approved_by`,
            ADD COLUMN `chief_notes` VARCHAR(255) NULL AFTER `chief_approved_at`");
    }
} catch (Exception $e) {}

// 6. Chief Approval columns in inspection_session_lots
try {
    $colsLotChief = $pdo->query("SHOW COLUMNS FROM `inspection_session_lots` LIKE 'is_chief_approved'")->fetchAll();
    if (empty($colsLotChief)) {
        $pdo->exec("ALTER TABLE `inspection_session_lots` 
            ADD COLUMN `is_chief_approved` TINYINT(1) NOT NULL DEFAULT 0 AFTER `action_noted_at`,
            ADD COLUMN `chief_approved_by` BIGINT(20) UNSIGNED NULL AFTER `is_chief_approved`,
            ADD COLUMN `chief_approved_at` DATETIME NULL AFTER `chief_approved_by`,
            ADD COLUMN `chief_notes` VARCHAR(255) NULL AFTER `chief_approved_at`");
    }
} catch (Exception $e) {}

// 7. Defect Types code column
try {
    $colsDefCode = $pdo->query("SHOW COLUMNS FROM `defect_types` LIKE 'code'")->fetchAll();
    if (empty($colsDefCode)) {
        $pdo->exec("ALTER TABLE `defect_types` ADD COLUMN `code` VARCHAR(50) NULL AFTER `id`");
    }
// 8. High-Performance Composite Indexes for Millions of Rows
$perfIndexes = [
    "ALTER TABLE `kanban_items` ADD INDEX `idx_kanban_status_eta` (`status`, `eta`, `req_date`)",
    "ALTER TABLE `kanban_items` ADD INDEX `idx_kanban_item_status` (`item_code`, `status`)",
    "ALTER TABLE `inspection_sessions` ADD INDEX `idx_sess_kid_status_qty` (`kanban_item_id`, `status`, `total_scanned_qty`)",
    "ALTER TABLE `daily_inspection_data` ADD INDEX `idx_did_part_lot_date` (`part_code`, `lot_number`, `inspecting_date`)",
    "ALTER TABLE `inspection_session_lots` ADD INDEX `idx_isl_session_status_lot` (`inspection_session_id`, `lot_status`, `lot_number`)",
    "ALTER TABLE `inspection_ng_records` ADD INDEX `idx_ng_session_defect` (`inspection_session_id`, `defect_type_id`, `is_cancelled`)"
];
foreach ($perfIndexes as $sql) {
    try { $pdo->exec($sql); } catch (Exception $e) {}
}

echo "Migrations completed successfully.\n";

