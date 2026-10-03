<?php
/**
 * Migration: Add status column to kanban_items and backfill lifecycle state
 * 
 * Column: status ENUM('uninspected', 'in_progress', 'partial', 'completed', 'rejected') NOT NULL DEFAULT 'uninspected'
 * Index: idx_kanban_status (status)
 */
require_once __DIR__ . '/../../config/database.php';

$pdo = getDB();
if (!$pdo) {
    die("Database connection failed\n");
}

echo "=== 1. CHECK & ALTER TABLE kanban_items ===\n";
$cols = $pdo->query("SHOW COLUMNS FROM kanban_items")->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('status', $cols)) {
    echo "Adding column 'status' to kanban_items...\n";
    $pdo->exec("
        ALTER TABLE kanban_items 
        ADD COLUMN status ENUM('uninspected', 'in_progress', 'partial', 'completed', 'rejected') 
        NOT NULL DEFAULT 'uninspected' AFTER check_type
    ");
    echo "[OK] Column status added successfully.\n";
} else {
    echo "[-] Column status already exists in kanban_items.\n";
}

echo "\n=== 2. CHECK & ADD INDEX ON status ===\n";
$indexes = $pdo->query("SHOW INDEX FROM kanban_items WHERE Key_name = 'idx_kanban_status'")->fetchAll(PDO::FETCH_ASSOC);
if (empty($indexes)) {
    echo "Adding index 'idx_kanban_status'...\n";
    $pdo->exec("ALTER TABLE kanban_items ADD INDEX idx_kanban_status (status)");
    echo "[OK] Index idx_kanban_status added successfully.\n";
} else {
    echo "[-] Index idx_kanban_status already exists.\n";
}

echo "\n=== 3. BACKFILL KANBAN ITEMS STATUS ===\n";
$stmtItems = $pdo->query("SELECT id, qty FROM kanban_items ORDER BY id ASC");
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
$total = count($items);
echo "Found {$total} kanban items to evaluate.\n";

$stmtSess = $pdo->prepare("
    SELECT id, status, total_scanned_qty, is_reinspection, parent_session_id
    FROM inspection_sessions 
    WHERE kanban_item_id = :kid 
    ORDER BY id ASC
");

$stmtUpd = $pdo->prepare("UPDATE kanban_items SET status = :status WHERE id = :kid");

$counts = [
    'uninspected' => 0,
    'in_progress' => 0,
    'partial'     => 0,
    'completed'   => 0,
    'rejected'    => 0,
];

foreach ($items as $item) {
    $kid = (int)$item['id'];
    $targetQty = (int)$item['qty'];

    $stmtSess->execute([':kid' => $kid]);
    $sessions = $stmtSess->fetchAll(PDO::FETCH_ASSOC);

    $newStatus = 'uninspected';

    if (!empty($sessions)) {
        $hasInProgress = false;
        $passedQty = 0;
        $latestSession = end($sessions);

        foreach ($sessions as $s) {
            if ($s['status'] === 'in_progress') {
                $hasInProgress = true;
            } elseif ($s['status'] === 'passed') {
                $passedQty += (int)$s['total_scanned_qty'];
            }
        }

        if ($hasInProgress) {
            $newStatus = 'in_progress';
        } elseif ($latestSession['status'] === 'rejected') {
            $newStatus = 'rejected';
        } elseif ($targetQty > 0 && $passedQty >= $targetQty) {
            $newStatus = 'completed';
        } elseif ($passedQty > 0) {
            $newStatus = 'partial';
        } else {
            $newStatus = 'uninspected';
        }
    }

    $stmtUpd->execute([
        ':status' => $newStatus,
        ':kid'    => $kid,
    ]);

    $counts[$newStatus]++;
}

echo "[OK] Backfill complete. Summary:\n";
foreach ($counts as $st => $cnt) {
    echo "  - {$st}: {$cnt} items\n";
}
echo "=== Migration Finished Successfully ===\n";
