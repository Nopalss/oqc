<?php
/**
 * Migration 004: Indexing did_approval_requests for High Performance & Large Scale Data
 */
require_once __DIR__ . '/../../config/database.php';

$pdo = getDB();
if (!$pdo) {
    echo "DB connection failed\n";
    exit(1);
}

echo "=== MIGRATING DID_APPROVAL_REQUESTS INDEXES ===\n";

function indexExists($pdo, $table, $indexName) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tbl AND INDEX_NAME = :idx");
    $stmt->execute([':tbl' => $table, ':idx' => $indexName]);
    return (int)$stmt->fetchColumn() > 0;
}

try {
    // 1. Composite index on (status, requested_at) to eliminate filesort & boost status filtering
    if (!indexExists($pdo, 'did_approval_requests', 'idx_did_req_status_requested')) {
        echo "Creating index idx_did_req_status_requested on did_approval_requests(status, requested_at)...\n";
        $pdo->exec("ALTER TABLE `did_approval_requests` ADD INDEX `idx_did_req_status_requested` (`status`, `requested_at` DESC)");
        echo "✓ Created idx_did_req_status_requested\n";
    } else {
        echo "✓ idx_did_req_status_requested already exists\n";
    }

    // 2. Index on lot_number for search filter
    if (!indexExists($pdo, 'did_approval_requests', 'idx_did_req_lot_number')) {
        echo "Creating index idx_did_req_lot_number on did_approval_requests(lot_number)...\n";
        $pdo->exec("ALTER TABLE `did_approval_requests` ADD INDEX `idx_did_req_lot_number` (`lot_number`)");
        echo "✓ Created idx_did_req_lot_number\n";
    } else {
        echo "✓ idx_did_req_lot_number already exists\n";
    }

    // 3. Foreign key lookups on requested_by & reviewed_by
    if (!indexExists($pdo, 'did_approval_requests', 'idx_did_req_requested_by')) {
        echo "Creating index idx_did_req_requested_by on did_approval_requests(requested_by)...\n";
        $pdo->exec("ALTER TABLE `did_approval_requests` ADD INDEX `idx_did_req_requested_by` (`requested_by`)");
        echo "✓ Created idx_did_req_requested_by\n";
    } else {
        echo "✓ idx_did_req_requested_by already exists\n";
    }

    if (!indexExists($pdo, 'did_approval_requests', 'idx_did_req_reviewed_by')) {
        echo "Creating index idx_did_req_reviewed_by on did_approval_requests(reviewed_by)...\n";
        $pdo->exec("ALTER TABLE `did_approval_requests` ADD INDEX `idx_did_req_reviewed_by` (`reviewed_by`)");
        echo "✓ Created idx_did_req_reviewed_by\n";
    } else {
        echo "✓ idx_did_req_reviewed_by already exists\n";
    }

    echo "\n=== MIGRATION 004 COMPLETED SUCCESSFULLY ===\n";
} catch (PDOException $e) {
    echo "ERROR during migration: " . $e->getMessage() . "\n";
    exit(1);
}

