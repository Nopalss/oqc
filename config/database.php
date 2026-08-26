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
