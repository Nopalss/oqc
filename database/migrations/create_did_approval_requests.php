<?php
/**
 * Migration: Create did_approval_requests table for DID Approval Workflow
 */
require_once __DIR__ . '/../../config/database.php';
$pdo = getDB();

if (!$pdo) {
    die("Database connection failed.\n");
}

echo "=== Running migration: create_did_approval_requests ===\n";

try {
    $sql = "CREATE TABLE IF NOT EXISTS `did_approval_requests` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `inspection_session_id` INT NULL,
      `part_code` VARCHAR(50) NOT NULL,
      `part_name` VARCHAR(150) NULL,
      `lot_number` VARCHAR(50) NOT NULL,
      `requested_by` INT NOT NULL,
      `requested_at` DATETIME NOT NULL,
      `request_reason` TEXT NULL,
      `status` ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
      `reviewed_by` INT NULL,
      `reviewed_at` DATETIME NULL,
      `rejection_reason` TEXT NULL,
      `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
      INDEX `idx_did_req_status` (`status`),
      INDEX `idx_did_req_part_lot` (`part_code`, `lot_number`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    $pdo->exec($sql);
    echo "Table did_approval_requests created successfully or already exists.\n";

    // Verify columns
    echo "\n=== Columns in did_approval_requests ===\n";
    $stmt = $pdo->query("SHOW COLUMNS FROM `did_approval_requests`");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        echo "- " . $r['Field'] . " (" . $r['Type'] . ") Null: " . $r['Null'] . "\n";
    }
    echo "\nMigration completed successfully!\n";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
