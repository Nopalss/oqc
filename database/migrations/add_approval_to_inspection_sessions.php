<?php
/**
 * Migration: Add supervisor approval columns to inspection_sessions
 */
require_once __DIR__ . '/../../config/database.php';
$pdo = getDB();

if (!$pdo) {
    die("Database connection failed.\n");
}

echo "=== Running migration: add_approval_to_inspection_sessions ===\n";

try {
    $cols = $pdo->query("SHOW COLUMNS FROM `inspection_sessions` LIKE 'is_approved'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE `inspection_sessions` 
            ADD COLUMN `is_approved` TINYINT(1) NOT NULL DEFAULT 0 AFTER `closed_at`,
            ADD COLUMN `approved_by` BIGINT(20) UNSIGNED NULL AFTER `is_approved`,
            ADD COLUMN `approved_at` DATETIME NULL AFTER `approved_by`,
            ADD COLUMN `approval_notes` VARCHAR(255) NULL AFTER `approved_at`");
        echo "Columns is_approved, approved_by, approved_at, approval_notes added successfully.\n";

        try {
            $pdo->exec("ALTER TABLE `inspection_sessions` ADD INDEX `idx_sessions_approval` (`is_approved`, `approved_at`)");
            echo "Index idx_sessions_approval added successfully.\n";
        } catch (Exception $eIdx) {
            echo "Index migration notice: " . $eIdx->getMessage() . "\n";
        }
    } else {
        echo "Columns already exist in inspection_sessions.\n";
    }

    // Verify
    echo "\n=== Columns in inspection_sessions related to approval ===\n";
    $stmt = $pdo->query("SHOW COLUMNS FROM `inspection_sessions` WHERE `Field` IN ('is_approved', 'approved_by', 'approved_at', 'approval_notes')");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        echo "- " . $r['Field'] . " (" . $r['Type'] . ") Default: " . var_export($r['Default'], true) . "\n";
    }
    echo "\nMigration completed successfully!\n";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
