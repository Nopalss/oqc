<?php
/**
 * Migration: Add dedicated Chief QC approval columns to inspection_sessions
 */
require_once __DIR__ . '/../../config/database.php';
$pdo = getDB();

if (!$pdo) {
    die("Database connection failed.\n");
}

echo "=== Running migration: add_chief_approval_columns ===\n";

try {
    $cols = $pdo->query("SHOW COLUMNS FROM `inspection_sessions` LIKE 'is_chief_approved'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE `inspection_sessions` 
            ADD COLUMN `is_chief_approved` TINYINT(1) NOT NULL DEFAULT 0 AFTER `approval_notes`,
            ADD COLUMN `chief_approved_by` BIGINT(20) UNSIGNED NULL AFTER `is_chief_approved`,
            ADD COLUMN `chief_approved_at` DATETIME NULL AFTER `chief_approved_by`,
            ADD COLUMN `chief_notes` VARCHAR(255) NULL AFTER `chief_approved_at`");
        echo "Columns is_chief_approved, chief_approved_by, chief_approved_at, chief_notes added successfully.\n";

        try {
            $pdo->exec("ALTER TABLE `inspection_sessions` ADD INDEX `idx_sessions_chief_approval` (`is_chief_approved`, `chief_approved_at`)");
            echo "Index idx_sessions_chief_approval added successfully.\n";
        } catch (Exception $eIdx) {
            echo "Index migration notice: " . $eIdx->getMessage() . "\n";
        }
    } else {
        echo "Columns already exist in inspection_sessions.\n";
    }

    // Verify
    echo "\n=== Columns in inspection_sessions related to Chief approval ===\n";
    $stmt = $pdo->query("SHOW COLUMNS FROM `inspection_sessions` WHERE `Field` IN ('is_chief_approved', 'chief_approved_by', 'chief_approved_at', 'chief_notes')");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        echo "- " . $r['Field'] . " (" . $r['Type'] . ") Default: " . var_export($r['Default'], true) . "\n";
    }
    echo "\nMigration completed successfully!\n";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
