<?php
/**
 * AJAX Endpoint: Get Part Available Documents & Versions (Lightning Fast JSON)
 */
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../config/helper.php';
require_once __DIR__ . '/../../../config/document_helper.php';

header('Content-Type: application/json');

$partCode = trim($_GET['part_code'] ?? '');
if ($partCode === '') {
    echo json_encode(['success' => false, 'message' => 'Part code kosong', 'documents' => []]);
    exit;
}

$documents = get_part_documents_summary($partCode);

echo json_encode([
    'success' => true,
    'part_code' => $partCode,
    'documents' => $documents,
]);
exit;
