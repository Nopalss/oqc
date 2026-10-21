<?php
/**
 * Migration: Add closure_type to lot_quarantine_closures table
 * File: database/migrations/alter_lot_quarantine_closures_add_type.php
 */

require_once __DIR__ . '/../../config/database.php';

$pdo = getDB();

if (!$pdo) {
    die("Database connection failed.\n");
}

echo "=== Running migration: add closure_type to lot_quarantine_closures ===\n";

try {
    // Check if column closure_type exists
    $stmt = $pdo->query("SHOW COLUMNS FROM `lot_quarantine_closures` LIKE 'closure_type'");
    $exists = $stmt->fetch();

    if (!$exists) {
        $sql = "ALTER TABLE `lot_quarantine_closures` 
                ADD COLUMN `closure_type` ENUM('action', 'abolish') NOT NULL DEFAULT 'action' AFTER `session_lot_id`,
                ADD INDEX `idx_lqc_closure_type` (`closure_type`)";
        $pdo->exec($sql);
        echo "Column `closure_type` successfully added to `lot_quarantine_closures`.\n";
    } else {
        echo "Column `closure_type` already exists in `lot_quarantine_closures`.\n";
    }
} catch (Exception $e) {
    echo "Migration error: " . $e->getMessage() . "\n";
}
