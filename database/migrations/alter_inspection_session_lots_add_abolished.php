<?php
/**
 * Migration: Add 'abolished' to lot_status enum in inspection_session_lots
 * File: database/migrations/alter_inspection_session_lots_add_abolished.php
 */

require_once __DIR__ . '/../../config/database.php';

$pdo = getDB();

if (!$pdo) {
    die("Database connection failed.\n");
}

echo "=== Running migration: add abolished to inspection_session_lots.lot_status ===\n";

try {
    // Modify lot_status enum to include 'abolished' and 'action_closed'
    $sql = "ALTER TABLE `inspection_session_lots` 
            MODIFY COLUMN `lot_status` ENUM('ok','ng_found','ng_quarantine','replaced','reinspected','abolished','action_closed') NOT NULL DEFAULT 'ok'";
    $pdo->exec($sql);
    echo "ENUM updated successfully to include 'abolished' and 'action_closed'.\n";
} catch (Exception $e) {
    echo "Migration error: " . $e->getMessage() . "\n";
}
