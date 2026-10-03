<?php
require_once __DIR__ . '/../config/database.php';

$stmt = $pdo->query("SELECT id, kanban_item_id, status, started_at, closed_at, total_scanned_qty, inspector_id, did_id FROM inspection_sessions ORDER BY id DESC LIMIT 5");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows, JSON_PRETTY_PRINT);
