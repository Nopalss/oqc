<?php
/**
 * Safe File Downloader for Master Part Import Template (Excel / CSV)
 */
$format = strtolower($_GET['format'] ?? 'xlsx');

if ($format === 'csv') {
    $filePath = __DIR__ . '/template_master_part.csv';
    $fileName = 'template_master_part.csv';
    $mimeType = 'text/csv; charset=utf-8';
} else {
    $filePath = __DIR__ . '/template_master_part.xlsx';
    $fileName = 'template_master_part.xlsx';
    $mimeType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
}

if (!file_exists($filePath)) {
    http_response_code(404);
    die('File template tidak ditemukan!');
}

while (ob_get_level()) {
    ob_end_clean();
}

header('Content-Description: File Transfer');
header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Expires: 0');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Pragma: public');
header('Content-Length: ' . filesize($filePath));

readfile($filePath);
exit;
