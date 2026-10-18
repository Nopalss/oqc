<?php
/**
 * Migration: Create lot_quarantine_closures table
 * File: database/migrations/create_lot_quarantine_closures.php
 */

require_once __DIR__ . '/../../config/database.php';

$pdo = getDB();

if (!$pdo) {
    die("Database connection failed.\n");
}

echo "=== Running migration: create_lot_quarantine_closures ===\n";

try {
    $sql = "CREATE TABLE IF NOT EXISTS `lot_quarantine_closures` (
        `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        `session_lot_id` BIGINT(20) UNSIGNED NOT NULL,
        `closed_by` BIGINT(20) UNSIGNED NOT NULL,
        `closed_at` DATETIME NOT NULL,
        `notes` TEXT NOT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_lqc_session_lot` (`session_lot_id`),
        KEY `idx_lqc_closed_by` (`closed_by`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    $pdo->exec($sql);
    echo "Table lot_quarantine_closures created or already exists successfully.\n";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
