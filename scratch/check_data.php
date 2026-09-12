<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDB();

echo "=== RECENT INSPECTION SESSIONS ===\n";
$stmt = $pdo->query("SELECT id, inspection_type, ng_count, reject_number, sample_size, status, started_at FROM inspection_sessions ORDER BY id DESC LIMIT 10");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    print_r($row);
}
