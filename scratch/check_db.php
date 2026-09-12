<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDB();

echo "=== INSPECTION_SESSIONS ===\n";
$stmt = $pdo->query("DESCRIBE inspection_sessions");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
    echo $col['Field'] . " | " . $col['Type'] . " | " . $col['Null'] . " | " . $col['Default'] . "\n";
}

echo "\n=== INSPECTION_SAMPLES ===\n";
$stmt = $pdo->query("DESCRIBE inspection_samples");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
    echo $col['Field'] . " | " . $col['Type'] . " | " . $col['Null'] . " | " . $col['Default'] . "\n";
}

echo "\n=== DAILY_INSPECTION_DATA ===\n";
$stmt = $pdo->query("DESCRIBE daily_inspection_data");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
    echo $col['Field'] . " | " . $col['Type'] . " | " . $col['Null'] . " | " . $col['Default'] . "\n";
}

echo "\n=== KANBAN_ITEMS ===\n";
$stmt = $pdo->query("DESCRIBE kanban_items");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
    echo $col['Field'] . " | " . $col['Type'] . " | " . $col['Null'] . " | " . $col['Default'] . "\n";
}
