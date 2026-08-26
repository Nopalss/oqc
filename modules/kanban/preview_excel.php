<?php
/**
 * AJAX Endpoint: Preview & Parse Kanban Excel/CSV File
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';
require_once __DIR__ . '/xlsx_kanban_parser.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method tidak diizinkan!']);
    exit;
}

if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'File Excel/CSV tidak valid atau gagal diunggah!']);
    exit;
}

$tmpPath = $_FILES['excel_file']['tmp_name'];
$fileName = $_FILES['excel_file']['name'];
$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

if (!in_array($ext, ['xlsx', 'csv', 'xls'])) {
    echo json_encode(['success' => false, 'message' => 'Ekstensi file harus berupa .xlsx, .csv, atau .xls']);
    exit;
}

// Parse file
$result = xlsx_parse_kanban($tmpPath);

if (!$result['success']) {
    echo json_encode($result);
    exit;
}

// Attach original filename
$result['filename'] = $fileName;

echo json_encode($result);
exit;
