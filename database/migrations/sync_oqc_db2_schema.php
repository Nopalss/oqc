<?php
/**
 * OQC System - Safe Database Schema Synchronization Script (PHP CLI / Web)
 * 
 * Menambahkan seluruh kolom, tabel, dan index yang belum ada pada database aktif (.env).
 * SANGAT AMAN: Tidak menghapus tabel atau data apapun yang sudah ada di database.
 * 
 * Cara Menjalankan:
 * 1. Via Terminal CLI:
 *    php database/migrations/sync_oqc_db2_schema.php
 * 2. Atau akses via browser lokal jika web server aktif.
 */

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

$pdo = getDB();
if (!$pdo) {
    die("Database connection failed.\n");
}

echo "=== STARTING SAFE SCHEMA SYNC FOR: " . env('DB_NAME') . " ===\n";

function addColumnIfNotExists(PDO $pdo, string $table, string $column, string $definition) {
    try {
        $check = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'")->fetch();
        if (!$check) {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            echo "[ADDED] `{$table}`.`{$column}`\n";
        } else {
            echo "[OK] `{$table}`.`{$column}` already exists\n";
        }
    } catch (Exception $e) {
        echo "[ERROR] `{$table}`.`{$column}`: " . $e->getMessage() . "\n";
    }
}

function addIndexIfNotExists(PDO $pdo, string $table, string $indexName, string $columns) {
    try {
        $check = $pdo->query("SHOW INDEX FROM `{$table}` WHERE Key_name = '{$indexName}'")->fetch();
        if (!$check) {
            $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `{$indexName}` ({$columns})");
            echo "[INDEX ADDED] `{$table}` -> `{$indexName}`\n";
        } else {
            echo "[INDEX OK] `{$table}` -> `{$indexName}` already exists\n";
        }
    } catch (Exception $e) {
        echo "[INDEX ERROR] `{$table}` -> `{$indexName}`: " . $e->getMessage() . "\n";
    }
}

// 1. TABEL did_approval_requests
try {
    $hasTable = $pdo->query("SHOW TABLES LIKE 'did_approval_requests'")->fetch();
    if (!$hasTable) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `did_approval_requests` (
          `id` int NOT NULL AUTO_INCREMENT,
          `inspection_session_id` int DEFAULT NULL,
          `part_code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
          `part_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `lot_number` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
          `requested_by` int NOT NULL,
          `requested_at` datetime NOT NULL,
          `request_reason` text COLLATE utf8mb4_unicode_ci,
          `status` enum('pending','approved','rejected') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
          `reviewed_by` int DEFAULT NULL,
          `reviewed_at` datetime DEFAULT NULL,
          `rejection_reason` text COLLATE utf8mb4_unicode_ci,
          `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_did_req_status` (`status`),
          KEY `idx_did_req_part_lot` (`part_code`,`lot_number`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        echo "[TABLE CREATED] did_approval_requests\n";
    } else {
        echo "[TABLE OK] did_approval_requests already exists\n";
    }
} catch (Exception $e) {
    echo "[TABLE ERROR] did_approval_requests: " . $e->getMessage() . "\n";
}

// 2. oqc_daily_summary
addColumnIfNotExists($pdo, 'oqc_daily_summary', 'total_sample_size_lot', "INT NULL DEFAULT 0 AFTER `total_sample_size`");
addColumnIfNotExists($pdo, 'oqc_daily_summary', 'total_sample_size_session', "INT NULL DEFAULT 0 AFTER `total_sample_size_lot`");

// 3. inspection_session_lots
addColumnIfNotExists($pdo, 'inspection_session_lots', 'aql_level', "VARCHAR(20) NULL DEFAULT 'G-II' AFTER `remarks`");
addColumnIfNotExists($pdo, 'inspection_session_lots', 'sample_code', "VARCHAR(10) NULL DEFAULT NULL AFTER `aql_level`");
addColumnIfNotExists($pdo, 'inspection_session_lots', 'sample_size', "INT NULL DEFAULT 0 AFTER `sample_code`");
addColumnIfNotExists($pdo, 'inspection_session_lots', 'accept_number', "INT NULL DEFAULT 0 AFTER `sample_size`");
addColumnIfNotExists($pdo, 'inspection_session_lots', 'reject_number', "INT NULL DEFAULT 1 AFTER `accept_number`");
addColumnIfNotExists($pdo, 'inspection_session_lots', 'ng_count', "INT NULL DEFAULT 0 AFTER `reject_number`");
addColumnIfNotExists($pdo, 'inspection_session_lots', 'lot_result', "ENUM('in_progress','passed','rejected','skipped') NULL DEFAULT 'in_progress' AFTER `ng_count`");
addColumnIfNotExists($pdo, 'inspection_session_lots', 'closed_at', "DATETIME NULL DEFAULT NULL AFTER `lot_result`");
addColumnIfNotExists($pdo, 'inspection_session_lots', 'is_chief_approved', "TINYINT(1) NOT NULL DEFAULT 0 AFTER `closed_at`");
addColumnIfNotExists($pdo, 'inspection_session_lots', 'chief_approved_by', "VARCHAR(255) NULL DEFAULT NULL AFTER `is_chief_approved`");
addColumnIfNotExists($pdo, 'inspection_session_lots', 'chief_approved_at', "DATETIME NULL DEFAULT NULL AFTER `chief_approved_by`");
addColumnIfNotExists($pdo, 'inspection_session_lots', 'chief_notes', "VARCHAR(255) NULL DEFAULT NULL AFTER `chief_approved_at`");

// 4. inspection_sessions
addColumnIfNotExists($pdo, 'inspection_sessions', 'flow_version', "TINYINT NOT NULL DEFAULT 1 AFTER `status`");
addColumnIfNotExists($pdo, 'inspection_sessions', 'batch_sample_size', "INT NULL DEFAULT 0 AFTER `excess_qty`");

// 5. inspection_ng_records
addColumnIfNotExists($pdo, 'inspection_ng_records', 'unit_number', "INT NULL DEFAULT 1 AFTER `qty_ng`");
addColumnIfNotExists($pdo, 'inspection_ng_records', 'is_sorted', "TINYINT(1) NOT NULL DEFAULT 0 AFTER `remark`");
addColumnIfNotExists($pdo, 'inspection_ng_records', 'sort_notes', "TEXT NULL DEFAULT NULL AFTER `is_sorted`");
addColumnIfNotExists($pdo, 'inspection_ng_records', 'sorted_at', "DATETIME NULL DEFAULT NULL AFTER `sort_notes`");

// 6. defect_types
addColumnIfNotExists($pdo, 'defect_types', 'code', "VARCHAR(50) NULL DEFAULT NULL AFTER `id`");

// 7. users
addColumnIfNotExists($pdo, 'users', 'role_id', "INT UNSIGNED NULL DEFAULT NULL AFTER `role`");

// 8. DATA BACKFILL: Selaraskan lot_result pada data eksisting
try {
    $affected1 = $pdo->exec("UPDATE `inspection_session_lots` 
                             SET `lot_result` = 'passed' 
                             WHERE (`lot_result` IS NULL OR `lot_result` = 'in_progress') AND `lot_status` = 'ok'");
    if ($affected1) echo "[BACKFILL] Updated $affected1 lots to 'passed' based on lot_status=ok\n";

    $affected2 = $pdo->exec("UPDATE `inspection_session_lots` 
                             SET `lot_result` = 'rejected' 
                             WHERE (`lot_result` IS NULL OR `lot_result` = 'in_progress') AND `lot_status` IN ('ng_found', 'ng_quarantine', 'replaced')");
    if ($affected2) echo "[BACKFILL] Updated $affected2 lots to 'rejected' based on lot_status\n";
} catch (Exception $e) {
    echo "[BACKFILL NOTE] " . $e->getMessage() . "\n";
}

// 9. HIGH PERFORMANCE INDEXES
$indexes = [
    ['daily_inspection_data', 'idx_did_batch_status', '`batch_id`, `status_inspect`'],
    ['daily_inspection_data', 'idx_did_part_lot_date', '`part_code`, `lot_number`, `inspecting_date`'],
    ['defect_types', 'idx_defect_types_code', '`code`'],
    ['did_batches', 'idx_did_batches_date', '`inspecting_date`'],
    ['inspection_ng_records', 'idx_ng_session_defect', '`inspection_session_id`, `defect_type_id`, `is_cancelled`'],
    ['inspection_ng_records', 'idx_ngr_created_at', '`is_cancelled`, `created_at`'],
    ['inspection_ng_records', 'idx_ngr_lot_cancelled', '`session_lot_id`, `is_cancelled`'],
    ['inspection_session_lots', 'idx_isl_created_at', '`created_at`'],
    ['inspection_session_lots', 'idx_isl_perf_ref_lot', '`inspection_session_id`, `ref_number`, `lot_number`'],
    ['inspection_session_lots', 'idx_isl_sess_sample_res', '`inspection_session_id`, `sample_size`, `lot_result`'],
    ['inspection_session_lots', 'idx_isl_session_status_lot', '`inspection_session_id`, `lot_status`, `lot_number`'],
    ['inspection_session_lots', 'idx_lots_chief_approval', '`is_chief_approved`, `chief_approved_at`'],
    ['inspection_sessions', 'idx_sess_kid_status_qty', '`kanban_item_id`, `status`, `total_scanned_qty`'],
    ['inspection_sessions', 'idx_ss_perf_lookup', '`inspection_type`, `auto_fulfilled_by_session_id`, `did_id`'],
    ['inspection_sessions', 'idx_ss_perf_part', '`inspection_type`, `part_id`, `auto_fulfilled_by_session_id`'],
    ['kanban_batches', 'idx_kbatches_type_imported', '`plan_type`, `imported_at`'],
    ['kanban_items', 'idx_kanban_created_at', '`created_at`'],
    ['kanban_items', 'idx_kanban_item_status', '`item_code`, `status`'],
    ['kanban_items', 'idx_kanban_status_eta', '`status`, `eta`, `req_date`'],
    ['kanban_items', 'idx_kitems_batch_plantype', '`batch_id`, `plan_type`'],
    ['master_parts', 'idx_parts_name', '`part_name`'],
    ['master_parts', 'idx_parts_source_id', '`source`, `id`'],
];

foreach ($indexes as $idx) {
    addIndexIfNotExists($pdo, $idx[0], $idx[1], $idx[2]);
}

echo "\n=== SCHEMA SYNC COMPLETED SUCCESSFULLY! ===\n";
