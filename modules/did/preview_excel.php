<?php
/**
 * AJAX Endpoint: Preview XLSX sheet list + status (taken/available)
 * POST: file upload 'xlsx_file'
 * Returns JSON: { sheets: [{tab, date, rows, taken, batch_id}], error: null }
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';
require_once __DIR__ . '/xlsx_parser.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Method not allowed', 'sheets' => []]);
    exit;
}

if (!isset($_FILES['xlsx_file']) || $_FILES['xlsx_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['error' => 'File tidak valid atau belum dipilih.', 'sheets' => []]);
    exit;
}

$ext = strtolower(pathinfo($_FILES['xlsx_file']['name'], PATHINFO_EXTENSION));
if ($ext !== 'xlsx') {
    echo json_encode(['error' => 'Hanya file .xlsx yang didukung.', 'sheets' => []]);
    exit;
}

try {
    $sheets = xlsx_preview_sheets($_FILES['xlsx_file']['tmp_name']);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage(), 'sheets' => []]);
    exit;
}

if (empty($sheets)) {
    echo json_encode(['error' => 'Tidak ada tab tanggal yang ditemukan. Pastikan nama tab berformat DD-MM-YYYY.', 'sheets' => []]);
    exit;
}

// Check which dates already have batches
$pdo = getDB();
$result = [];
foreach ($sheets as $s) {
    $taken    = false;
    $batchId  = null;
    $batchName = null;

    if ($pdo) {
        try {
            $stmt = $pdo->prepare("SELECT id, batch_name FROM did_batches WHERE inspecting_date = :d LIMIT 1");
            $stmt->execute([':d' => $s['date']]);
            $row = $stmt->fetch();
            if ($row) {
                $taken     = true;
                $batchId   = (int)$row['id'];
                $batchName = $row['batch_name'];
            }
        } catch (PDOException $e) {}
    }

    $result[] = [
        'tab'        => $s['tab'],
        'date'       => $s['date'],
        'rows'       => $s['rows'],
        'taken'      => $taken,
        'batch_id'   => $batchId,
        'batch_name' => $batchName,
    ];
}

echo json_encode(['error' => null, 'sheets' => $result]);
