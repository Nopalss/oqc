<?php
/**
 * Database Connection Management (PDO Prepared Statements)
 */

$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'oqc_db';
$db_port = 3306;

try {
    $dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    
    $pdo = new PDO($dsn, $db_user, $db_pass, $options);

    // Auto-migration: Create did_batches table if not exists
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

    // Safe batch_id column migration — wrapped in own try-catch so it never kills $pdo
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `daily_inspection_data` LIKE 'batch_id'")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE `daily_inspection_data` ADD COLUMN `batch_id` BIGINT(20) UNSIGNED NULL AFTER `id`");
        }
    } catch (Exception $migEx) {
        // Migration skipped safely — table may not exist yet
    }

    // Safe inspecting_date column migration for did_batches (1 batch per day validation)
    try {
        $colsDate = $pdo->query("SHOW COLUMNS FROM `did_batches` LIKE 'inspecting_date'")->fetchAll();
        if (empty($colsDate)) {
            $pdo->exec("ALTER TABLE `did_batches` ADD COLUMN `inspecting_date` DATE NULL AFTER `batch_name`");
        }
    } catch (Exception $migEx2) {
        // Migration skipped safely
    }

    // Safe drop of uk_did_part_lot index to allow same part/lot across different dates & cavity
    try {
        $pdo->exec("ALTER TABLE `daily_inspection_data` DROP INDEX `uk_did_part_lot`");
    } catch (Exception $migEx3) {
        // Index already dropped or missing
    }

    // Safe master_customers table creation & initial seed
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `master_customers` (
          `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
          `name` VARCHAR(255) NOT NULL,
          `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
          `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uk_customer_name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Seed initial customers if empty
        $custCount = (int)$pdo->query("SELECT COUNT(*) FROM `master_customers`")->fetchColumn();
        if ($custCount === 0) {
            $pdo->exec("INSERT INTO `master_customers` (`name`) VALUES
                ('PT. Indonesia Epson Industry'),
                ('PT. Astra Honda Motor'),
                ('PT. Yamaha Indonesia Motor Mfg'),
                ('PT. Suzuki Indomobil Motor'),
                ('PT. Kawasaki Motor Indonesia'),
                ('PT. Indonesia Nippon Seiki')");
        } else {
            // Ensure PT. Indonesia Epson Industry exists
            $stmtChkEps = $pdo->query("SELECT id FROM `master_customers` WHERE `name` = 'PT. Indonesia Epson Industry'");
            if (!$stmtChkEps->fetch()) {
                $pdo->exec("INSERT INTO `master_customers` (`name`) VALUES ('PT. Indonesia Epson Industry')");
            }
            // Migration fix: Update any rows that had 'PT. EPSON INDONESIA'
            $pdo->exec("UPDATE `master_customers` SET `name` = 'PT. Indonesia Epson Industry' WHERE `name` = 'PT. EPSON INDONESIA'");
            $pdo->exec("UPDATE `kanban_items` SET `customer` = 'PT. Indonesia Epson Industry' WHERE `customer` = 'PT. EPSON INDONESIA'");
        }
    } catch (Exception $migEx4) {
        // Migration skipped safely
    }

    // Auto-migration: Add plan_type to kanban_batches, kanban_items, & inspection_type to inspection_sessions
    try {
        $colsKb = $pdo->query("SHOW COLUMNS FROM `kanban_batches` LIKE 'plan_type'")->fetchAll();
        if (empty($colsKb)) {
            $pdo->exec("ALTER TABLE `kanban_batches` ADD COLUMN `plan_type` ENUM('kanban', 'safety_stock') NOT NULL DEFAULT 'kanban' AFTER `id`");
        }
    } catch (Exception $eKb) {}

    try {
        $colsKi = $pdo->query("SHOW COLUMNS FROM `kanban_items` LIKE 'plan_type'")->fetchAll();
        if (empty($colsKi)) {
            $pdo->exec("ALTER TABLE `kanban_items` ADD COLUMN `plan_type` ENUM('kanban', 'safety_stock') NOT NULL DEFAULT 'kanban' AFTER `batch_id`");
            $pdo->exec("ALTER TABLE `kanban_items` MODIFY COLUMN `kanban_no` VARCHAR(50) NULL");
        }
    } catch (Exception $eKi) {}

    try {
        $colsIns = $pdo->query("SHOW COLUMNS FROM `inspection_sessions` LIKE 'inspection_type'")->fetchAll();
        if (empty($colsIns)) {
            $pdo->exec("ALTER TABLE `inspection_sessions` ADD COLUMN `inspection_type` ENUM('kanban', 'safety_stock') NOT NULL DEFAULT 'kanban' AFTER `id`");
        }
        $colsIns2 = $pdo->query("SHOW COLUMNS FROM `inspection_sessions` LIKE 'auto_fulfilled_by_session_id'")->fetchAll();
        if (empty($colsIns2)) {
            $pdo->exec("ALTER TABLE `inspection_sessions` ADD COLUMN `auto_fulfilled_by_session_id` BIGINT(20) UNSIGNED NULL AFTER `status`");
        }
    } catch (Exception $eIns) {}

    // Safe migration: Add check_type column to kanban_items for 100% check tags
    try {
        $colsCheck = $pdo->query("SHOW COLUMNS FROM `kanban_items` LIKE 'check_type'")->fetchAll();
        if (empty($colsCheck)) {
            $pdo->exec("ALTER TABLE `kanban_items` ADD COLUMN `check_type` VARCHAR(50) NULL AFTER `supply_area`");
        }
    } catch (Exception $eCheck) {}

    // Safe migration: Update fk_sessions_did constraint to ON DELETE CASCADE
    try {
        $pdo->exec("ALTER TABLE `inspection_sessions` DROP FOREIGN KEY `fk_sessions_did`");
        $pdo->exec("ALTER TABLE `inspection_sessions` ADD CONSTRAINT `fk_sessions_did` FOREIGN KEY (`did_id`) REFERENCES `daily_inspection_data` (`id`) ON DELETE CASCADE");
    } catch (Exception $eFk) {}

    // Safe system_settings table creation & initial seed for target_ppm
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `system_settings` (
          `setting_key` VARCHAR(100) NOT NULL,
          `setting_value` TEXT NULL,
          `description` VARCHAR(255) NULL,
          `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $stmtPpm = $pdo->prepare("SELECT setting_value FROM `system_settings` WHERE `setting_key` = 'target_ppm'");
        $stmtPpm->execute();
        if (!$stmtPpm->fetch()) {
            $pdo->exec("INSERT INTO `system_settings` (`setting_key`, `setting_value`, `description`) VALUES ('target_ppm', '10000', 'Target PPM Maksimal untuk STI Survival Performance Report')");
        }
    } catch (Exception $migExSettings) {}

    // Safe master_models creation, model_id migration, & sa_route removal
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `master_models` (
          `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
          `name` VARCHAR(150) NOT NULL,
          `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
          `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uk_master_models_name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Add model_id to master_parts if not exists
        $colsM = $pdo->query("SHOW COLUMNS FROM `master_parts` LIKE 'model_id'")->fetchAll();
        if (empty($colsM)) {
            $pdo->exec("ALTER TABLE `master_parts` ADD COLUMN `model_id` BIGINT(20) UNSIGNED NULL AFTER `part_name`");
            try {
                $pdo->exec("ALTER TABLE `master_parts` ADD CONSTRAINT `fk_parts_model` FOREIGN KEY (`model_id`) REFERENCES `master_models` (`id`) ON DELETE SET NULL");
            } catch (Exception $eFkM) {}
        }

        // Migrate existing text 'model' column data into master_models
        try {
            $colsModel = $pdo->query("SHOW COLUMNS FROM `master_parts` LIKE 'model'")->fetchAll();
            if (!empty($colsModel)) {
                $existingParts = $pdo->query("SELECT id, model FROM master_parts WHERE model IS NOT NULL AND model != '' AND (model_id IS NULL OR model_id = 0)")->fetchAll();
                foreach ($existingParts as $ep) {
                    $mName = trim($ep['model']);
                    if ($mName !== '') {
                        $stmtInsM = $pdo->prepare("INSERT INTO master_models (name) VALUES (:name) ON DUPLICATE KEY UPDATE name=VALUES(name)");
                        $stmtInsM->execute([':name' => $mName]);
                        
                        $stmtGetM = $pdo->prepare("SELECT id FROM master_models WHERE name = :name");
                        $stmtGetM->execute([':name' => $mName]);
                        $mId = $stmtGetM->fetchColumn();

                        if ($mId) {
                            $stmtUpdP = $pdo->prepare("UPDATE master_parts SET model_id = :mid WHERE id = :pid");
                            $stmtUpdP->execute([':mid' => $mId, ':pid' => $ep['id']]);
                        }
                    }
                }
            }
        } catch (Exception $eMigModels) {}

        // Safe migration: Add aql_level column to master_parts
        try {
            $colsAql = $pdo->query("SHOW COLUMNS FROM `master_parts` LIKE 'aql_level'")->fetchAll();
            if (empty($colsAql)) {
                $pdo->exec("ALTER TABLE `master_parts` ADD COLUMN `aql_level` ENUM('G-I', 'G-II', 'G-III') NOT NULL DEFAULT 'G-II' AFTER `model_id`");
            }
        } catch (Exception $eAql) {}

        // Safe migration: Add inspection_level column & seed 45 master rows to aql_standards
        try {
            $colsAqlStd = $pdo->query("SHOW COLUMNS FROM `aql_standards` LIKE 'inspection_level'")->fetchAll();
            if (empty($colsAqlStd)) {
                $pdo->exec("ALTER TABLE `aql_standards` ADD COLUMN `inspection_level` ENUM('G-I', 'G-II', 'G-III') NOT NULL DEFAULT 'G-II' AFTER `qty_max`");
            }

            // Check if we need to re-seed or expand to 45 rows for G-I, G-II, G-III
            $cntAql = (int)$pdo->query("SELECT COUNT(*) FROM `aql_standards`")->fetchColumn();
            $cntGi  = (int)$pdo->query("SELECT COUNT(*) FROM `aql_standards` WHERE `inspection_level` = 'G-I'")->fetchColumn();
            
            if ($cntAql < 45 || $cntGi === 0) {
                // Clear existing old 15 rows to rebuild complete 45 rows set cleanly
                $pdo->exec("TRUNCATE TABLE `aql_standards`");
                
                $sqlSeedAql = "INSERT INTO `aql_standards` (`qty_min`, `qty_max`, `inspection_level`, `sample_code`, `sample_size`, `accept_number`, `reject_number`) VALUES
                -- G-I (Longgar / Reduced)
                (2, 8, 'G-I', 'A', 2, 0, 1),
                (9, 15, 'G-I', 'A', 2, 0, 1),
                (16, 25, 'G-I', 'B', 3, 0, 1),
                (26, 50, 'G-I', 'C', 5, 0, 1),
                (51, 90, 'G-I', 'C', 5, 0, 1),
                (91, 150, 'G-I', 'D', 8, 0, 1),
                (151, 280, 'G-I', 'E', 13, 0, 1),
                (281, 500, 'G-I', 'F', 20, 0, 1),
                (501, 1200, 'G-I', 'G', 32, 0, 1),
                (1201, 3200, 'G-I', 'H', 50, 0, 1),
                (3201, 10000, 'G-I', 'J', 80, 1, 2),
                (10001, 35000, 'G-I', 'K', 125, 1, 2),
                (35001, 150000, 'G-I', 'L', 200, 2, 3),
                (150001, 500000, 'G-I', 'M', 315, 3, 4),
                (500001, 99999999, 'G-I', 'N', 500, 5, 6),

                -- G-II (Normal / Standar STI)
                (2, 8, 'G-II', 'A', 2, 0, 1),
                (9, 15, 'G-II', 'B', 3, 0, 1),
                (16, 25, 'G-II', 'C', 5, 0, 1),
                (26, 50, 'G-II', 'D', 8, 0, 1),
                (51, 90, 'G-II', 'E', 13, 0, 1),
                (91, 150, 'G-II', 'F', 20, 0, 1),
                (151, 280, 'G-II', 'G', 32, 0, 1),
                (281, 500, 'G-II', 'H', 50, 0, 1),
                (501, 1200, 'G-II', 'J', 80, 1, 2),
                (1201, 3200, 'G-II', 'K', 125, 1, 2),
                (3201, 10000, 'G-II', 'L', 200, 2, 3),
                (10001, 35000, 'G-II', 'M', 315, 3, 4),
                (35001, 150000, 'G-II', 'N', 500, 5, 6),
                (150001, 500000, 'G-II', 'P', 800, 7, 8),
                (500001, 99999999, 'G-II', 'Q', 1250, 10, 11),

                -- G-III (Ketat / Tightened)
                (2, 8, 'G-III', 'B', 3, 0, 1),
                (9, 15, 'G-III', 'C', 5, 0, 1),
                (16, 25, 'G-III', 'D', 8, 0, 1),
                (26, 50, 'G-III', 'E', 13, 0, 1),
                (51, 90, 'G-III', 'F', 20, 0, 1),
                (91, 150, 'G-III', 'G', 32, 0, 1),
                (151, 280, 'G-III', 'H', 50, 0, 1),
                (281, 500, 'G-III', 'J', 80, 1, 2),
                (501, 1200, 'G-III', 'K', 125, 1, 2),
                (1201, 3200, 'G-III', 'L', 200, 2, 3),
                (3201, 10000, 'G-III', 'M', 315, 3, 4),
                (10001, 35000, 'G-III', 'N', 500, 5, 6),
                (35001, 150000, 'G-III', 'P', 800, 7, 8),
                (150001, 500000, 'G-III', 'Q', 1250, 10, 11),
                (500001, 99999999, 'G-III', 'R', 2000, 14, 15)";

                $pdo->exec($sqlSeedAql);
            }
        } catch (Exception $eAqlStd) {}

        // Safe migration: Add inspection_session_lots table & total_scanned_qty/excess_qty columns to inspection_sessions
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `inspection_session_lots` (
              `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
              `inspection_session_id` BIGINT(20) UNSIGNED NOT NULL,
              `ref_number` VARCHAR(100) NULL,
              `lot_number` VARCHAR(100) NOT NULL,
              `qty` INT(11) NOT NULL DEFAULT 0,
              `scanned_qr_raw` TEXT NULL,
              `remarks` VARCHAR(255) NULL,
              `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `fk_session_lots_session` (`inspection_session_id`),
              KEY `idx_session_lots_ref` (`ref_number`),
              KEY `idx_session_lots_lot` (`lot_number`),
              CONSTRAINT `fk_session_lots_session` FOREIGN KEY (`inspection_session_id`) REFERENCES `inspection_sessions` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $colsTotQty = $pdo->query("SHOW COLUMNS FROM `inspection_sessions` LIKE 'total_scanned_qty'")->fetchAll();
            if (empty($colsTotQty)) {
                $pdo->exec("ALTER TABLE `inspection_sessions` ADD COLUMN `total_scanned_qty` INT(11) NOT NULL DEFAULT 0 AFTER `sample_size`");
            }

            $colsExcQty = $pdo->query("SHOW COLUMNS FROM `inspection_sessions` LIKE 'excess_qty'")->fetchAll();
            if (empty($colsExcQty)) {
                $pdo->exec("ALTER TABLE `inspection_sessions` ADD COLUMN `excess_qty` INT(11) NOT NULL DEFAULT 0 AFTER `total_scanned_qty`");
            }
        } catch (Exception $eSessionLots) {}

        // Safe migration: Add session_lot_id, ref_number, lot_number to inspection_samples and inspection_ng_records
        try {
            $colsSampLot = $pdo->query("SHOW COLUMNS FROM `inspection_samples` LIKE 'session_lot_id'")->fetchAll();
            if (empty($colsSampLot)) {
                $pdo->exec("ALTER TABLE `inspection_samples` ADD COLUMN `session_lot_id` BIGINT(20) UNSIGNED NULL AFTER `inspection_session_id`");
            }

            $colsNgLot = $pdo->query("SHOW COLUMNS FROM `inspection_ng_records` LIKE 'session_lot_id'")->fetchAll();
            if (empty($colsNgLot)) {
                $pdo->exec("ALTER TABLE `inspection_ng_records` ADD COLUMN `session_lot_id` BIGINT(20) UNSIGNED NULL AFTER `inspection_sample_id`");
            }

            $colsNgRef = $pdo->query("SHOW COLUMNS FROM `inspection_ng_records` LIKE 'ref_number'")->fetchAll();
            if (empty($colsNgRef)) {
                $pdo->exec("ALTER TABLE `inspection_ng_records` ADD COLUMN `ref_number` VARCHAR(100) NULL AFTER `session_lot_id`");
            }

            $colsNgLotNo = $pdo->query("SHOW COLUMNS FROM `inspection_ng_records` LIKE 'lot_number'")->fetchAll();
            if (empty($colsNgLotNo)) {
                $pdo->exec("ALTER TABLE `inspection_ng_records` ADD COLUMN `lot_number` VARCHAR(100) NULL AFTER `ref_number`");
            }
        } catch (Exception $eNgLotMig) {}

        // Safe migration: Add is_cancelled, cancel_reason, cancelled_at, cancelled_by to inspection_ng_records
        try {
            $colsIsCanc = $pdo->query("SHOW COLUMNS FROM `inspection_ng_records` LIKE 'is_cancelled'")->fetchAll();
            if (empty($colsIsCanc)) {
                $pdo->exec("ALTER TABLE `inspection_ng_records` ADD COLUMN `is_cancelled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `remark`");
            }

            $colsCancReas = $pdo->query("SHOW COLUMNS FROM `inspection_ng_records` LIKE 'cancel_reason'")->fetchAll();
            if (empty($colsCancReas)) {
                $pdo->exec("ALTER TABLE `inspection_ng_records` ADD COLUMN `cancel_reason` TEXT NULL AFTER `is_cancelled`");
            }

            $colsCancAt = $pdo->query("SHOW COLUMNS FROM `inspection_ng_records` LIKE 'cancelled_at'")->fetchAll();
            if (empty($colsCancAt)) {
                $pdo->exec("ALTER TABLE `inspection_ng_records` ADD COLUMN `cancelled_at` DATETIME NULL AFTER `cancel_reason`");
            }

            $colsCancBy = $pdo->query("SHOW COLUMNS FROM `inspection_ng_records` LIKE 'cancelled_by'")->fetchAll();
            if (empty($colsCancBy)) {
                $pdo->exec("ALTER TABLE `inspection_ng_records` ADD COLUMN `cancelled_by` VARCHAR(255) NULL AFTER `cancelled_at`");
            }
        } catch (Exception $eNgCancMig) {}

    } catch (Exception $migExModels) {}




} catch (PDOException $e) {
    $pdo = null;
    $db_error = $e->getMessage();
}

/**
 * Get active PDO instance
 * 
 * @return PDO|null
 */
function getDB() {
    global $pdo;
    return $pdo;
}
