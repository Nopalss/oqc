<?php
require_once __DIR__ . '/../config/database.php';

// Fix session 18 and 16 total_scanned_qty in DB to match kanban target qty 500
$stmt1 = $pdo->prepare("UPDATE inspection_sessions SET total_scanned_qty = 500 WHERE id IN (18, 16)");
$stmt1->execute();
echo "Updated session 18 & 16 total_scanned_qty to 500\n";
