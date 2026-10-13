<?php
/**
 * Migration: Optimize DID Indexes for High Scale Performance (100M+ Rows)
 */
require_once __DIR__ . '/../../config/database.php';

$pdo = getDB();
if (!$pdo) {
    echo "DB connection failed\n";
    exit(1);
}

echo "=== MIGRATING DID INDEXES ===\n";

// Helper to check if index exists
function indexExists($pdo, $table, $indexName) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tbl AND INDEX_NAME = :idx");
    $stmt->execute([':tbl' => $table, ':idx' => $indexName]);
    return (int)$stmt->fetchColumn() > 0;
}

try {
    // 1. Index on did_batches(inspecting_date)
    if (!indexExists($pdo, 'did_batches', 'idx_did_batches_date')) {
        echo "Creating index idx_did_batches_date on did_batches(inspecting_date)...\n";
        $pdo->exec("ALTER TABLE `did_batches` ADD INDEX `idx_did_batches_date` (`inspecting_date`)");
        echo "✓ Created idx_did_batches_date\n";
    } else {
        echo "✓ idx_did_batches_date already exists\n";
    }

    // 2. Composite index on daily_inspection_data(batch_id, status_inspect)
    if (!indexExists($pdo, 'daily_inspection_data', 'idx_did_batch_status')) {
        echo "Creating index idx_did_batch_status on daily_inspection_data(batch_id, status_inspect)...\n";
        $pdo->exec("ALTER TABLE `daily_inspection_data` ADD INDEX `idx_did_batch_status` (`batch_id`, `status_inspect`)");
        echo "✓ Created idx_did_batch_status\n";
    } else {
        echo "✓ idx_did_batch_status already exists\n";
    }

    // 3. Drop redundant indexes if present
    $redundantIndexes = ['idx_did_part_code_id', 'idx_did_partcode_lot'];
    foreach ($redundantIndexes as $rIdx) {
        if (indexExists($pdo, 'daily_inspection_data', $rIdx)) {
            echo "Dropping redundant index $rIdx on daily_inspection_data...\n";
            $pdo->exec("ALTER TABLE `daily_inspection_data` DROP INDEX `$rIdx`");
            echo "✓ Dropped $rIdx\n";
        }
    }

    echo "\n=== MIGRATION COMPLETED SUCCESSFULLY ===\n";
} catch (PDOException $e) {
    echo "ERROR during migration: " . $e->getMessage() . "\n";
    exit(1);
}
