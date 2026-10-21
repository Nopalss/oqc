<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDB();

echo "=== SEARCH LOT OR REF ===\n";
$stmt = $pdo->query("
    SELECT isl.id, isl.inspection_session_id, isl.lot_number, isl.ref_number, isl.lot_status, isl.lot_result, lqc.closure_type, lqc.notes
    FROM inspection_session_lots isl
    LEFT JOIN lot_quarantine_closures lqc ON lqc.session_lot_id = isl.id
    WHERE isl.ref_number LIKE '%NG2%' 
       OR isl.lot_number = '1'
       OR lqc.id IS NOT NULL
");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\n=== ALL LOT_QUARANTINE_CLOSURES ===\n";
$stmt2 = $pdo->query("SELECT * FROM lot_quarantine_closures");
print_r($stmt2->fetchAll(PDO::FETCH_ASSOC));
