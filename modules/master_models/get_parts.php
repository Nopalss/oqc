<?php
/**
 * AJAX Endpoint: Get Parts by Model ID (Lazy Loaded)
 * PT. Surya Technology Industri
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in() || !can_access('master_models')) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Akses tidak diizinkan.'
    ]);
    exit;
}

$modelId = filter_input(INPUT_GET, 'model_id', FILTER_VALIDATE_INT);
if (!$modelId || $modelId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Parameter model_id tidak valid.'
    ]);
    exit;
}

$pdo = getDB();
if (!$pdo) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Koneksi database tidak tersedia.'
    ]);
    exit;
}

try {
    // Get model info
    $stmtModel = $pdo->prepare("SELECT id, name FROM master_models WHERE id = :id LIMIT 1");
    $stmtModel->execute([':id' => $modelId]);
    $model = $stmtModel->fetch(PDO::FETCH_ASSOC);

    if (!$model) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Data Model tidak ditemukan.'
        ]);
        exit;
    }

    // Get parts for this model
    $stmtParts = $pdo->prepare("
        SELECT id, part_code, part_name, aql_level, created_at
        FROM master_parts
        WHERE model_id = :model_id
        ORDER BY part_name ASC
    ");
    $stmtParts->execute([':model_id' => $modelId]);
    $parts = $stmtParts->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'    => true,
        'model_id'   => (int)$model['id'],
        'model_name' => $model['name'],
        'count'      => count($parts),
        'data'       => $parts
    ]);
    exit;

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan basis data: ' . $e->getMessage()
    ]);
    exit;
}
