<?php
require_once __DIR__ . '/../config/database.php';

$batchId = (int)$pdo->query("SELECT id FROM kanban_batches LIMIT 1")->fetchColumn();
if ($batchId <= 0) {
    $pdo->exec("INSERT INTO kanban_batches (upload_date, file_name, created_at) VALUES (NOW(), 'test.xlsx', NOW())");
    $batchId = (int)$pdo->lastInsertId();
}

// Clean test data
$pdo->exec("DELETE FROM inspection_session_lots WHERE inspection_session_id IN (SELECT id FROM inspection_sessions WHERE did_id = 999)");
$pdo->exec("DELETE FROM inspection_sessions WHERE did_id = 999");
$pdo->exec("DELETE FROM kanban_items WHERE id IN (9991, 9992)");
$pdo->exec("DELETE FROM daily_inspection_data WHERE id = 999");

// Insert DID
$pdo->exec("INSERT INTO daily_inspection_data (id, inspecting_date, pic, part_code, part_name, lot_number, cavity, created_at) VALUES (999, NOW(), 'QC', 'TEST-QTY-PART', 'Test Part Qty', 'LOT-SS-999', 1, NOW())");

// Insert Safety Stock Kanban item
$pdo->exec("INSERT INTO kanban_items (id, batch_id, plan_type, kanban_no, item_code, item_description, customer, qty, req_date, created_at) VALUES (9991, {$batchId}, 'safety_stock', 'SS-999', 'TEST-QTY-PART', 'SS Test', 'TEST CUST', 1000, NOW(), NOW())");

// Create Safety Stock session with 1000 Qty
$pdo->exec("INSERT INTO inspection_sessions (id, inspection_type, did_id, kanban_item_id, part_id, sample_size, reject_number, total_scanned_qty, status, started_at, closed_at) VALUES (9991, 'safety_stock', 999, 9991, 1, 32, 1, 1000, 'passed', NOW(), NOW())");
$pdo->exec("INSERT INTO inspection_session_lots (inspection_session_id, ref_number, lot_number, qty, lot_status, created_at) VALUES (9991, 'REF-SS-999', 'LOT-SS-999', 1000, 'ok', NOW())");

// Insert Kanban item with Qty = 500
$pdo->exec("INSERT INTO kanban_items (id, batch_id, plan_type, kanban_no, item_code, item_description, customer, qty, req_date, created_at) VALUES (9992, {$batchId}, 'kanban', 'KB-500', 'TEST-QTY-PART', 'Kanban 500 Test', 'TEST CUST', 500, NOW(), NOW())");

$_SERVER['REQUEST_METHOD'] = 'POST';

// Simulate POST to create.php for 100% SS allocation
$_POST = [
    'action' => 'start_session',
    'part_code' => 'TEST-QTY-PART',
    'lot_number' => 'SAFETY-STOCK',
    'did_id' => 999,
    'kanban_item_id' => 9992,
    'inspection_type' => 'kanban',
    'part_id' => 1,
    'sample_size' => 32,
    'reject_number' => 1,
    'total_scanned_qty' => 500, // 0 physical + 500 SS
    'excess_qty' => 0,
    'use_safety_stock_qty' => 500,
    'scanned_labels' => '[]'
];

try {
    require __DIR__ . '/../modules/inspection/create.php';
} catch (Throwable $e) {
    // catch exit
}

$stmtS = $pdo->prepare("SELECT * FROM inspection_sessions WHERE kanban_item_id = 9992");
$stmtS->execute();
$kSession = $stmtS->fetch(PDO::FETCH_ASSOC);

echo "New Kanban Session Qty: " . $kSession['total_scanned_qty'] . " (Expected: 500)\n";
echo "New Kanban Session Status: " . $kSession['status'] . " (Expected: passed)\n";

$stmtLeft = $pdo->prepare("SELECT total_scanned_qty FROM inspection_sessions WHERE id = 9991");
$stmtLeft->execute();
$ssTakenQty = $stmtLeft->fetchColumn();
echo "Taken SS Session Qty: " . $ssTakenQty . " (Expected: 500 taken)\n";

$stmtSplit = $pdo->prepare("SELECT total_scanned_qty FROM inspection_sessions WHERE inspection_type = 'safety_stock' AND id != 9991 AND did_id = 999");
$stmtSplit->execute();
$ssSplitQty = $stmtSplit->fetchColumn();
echo "Split Remaining SS Session Qty: " . $ssSplitQty . " (Expected: 500 leftover)\n";

// Cleanup
$pdo->exec("DELETE FROM inspection_session_lots WHERE inspection_session_id IN (SELECT id FROM inspection_sessions WHERE did_id = 999)");
$pdo->exec("DELETE FROM inspection_sessions WHERE did_id = 999");
$pdo->exec("DELETE FROM kanban_items WHERE id IN (9991, 9992)");
$pdo->exec("DELETE FROM daily_inspection_data WHERE id = 999");
