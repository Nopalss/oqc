<?php
/**
 * AJAX Endpoint: Check if a DID batch already exists for a given inspecting_date
 * Returns JSON: { "taken": true/false, "batch_id": int|null, "batch_name": string|null }
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

header('Content-Type: application/json');

$date = isset($_GET['date']) ? trim($_GET['date']) : '';

if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['taken' => false]);
    exit;
}

$pdo = getDB();
if (!$pdo) {
    echo json_encode(['taken' => false]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, batch_name FROM did_batches WHERE inspecting_date = :d LIMIT 1");
    $stmt->execute([':d' => $date]);
    $row = $stmt->fetch();

    if ($row) {
        echo json_encode([
            'taken'      => true,
            'batch_id'   => (int)$row['id'],
            'batch_name' => $row['batch_name'],
        ]);
    } else {
        echo json_encode(['taken' => false]);
    }
} catch (PDOException $e) {
    echo json_encode(['taken' => false]);
}
