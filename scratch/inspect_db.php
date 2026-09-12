<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

$pdo = getDB();

echo "=== INSPECTION SESSIONS ===\n";
$stmt = $pdo->query("
    SELECT s.id, s.inspection_type, s.status, s.total_scanned_qty, s.auto_fulfilled_by_session_id, 
           d.part_code, d.lot_number, s.started_at
    FROM inspection_sessions s
    JOIN daily_inspection_data d ON d.id = s.did_id
    ORDER BY s.id DESC LIMIT 20
");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\n=== INSPECTION SESSION LOTS ===\n";
$stmtLots = $pdo->query("
    SELECT isl.id, isl.inspection_session_id, isl.ref_number, isl.lot_number, isl.qty, isl.remarks
    FROM inspection_session_lots isl
    ORDER BY isl.id DESC LIMIT 20
");
print_r($stmtLots->fetchAll(PDO::FETCH_ASSOC));
