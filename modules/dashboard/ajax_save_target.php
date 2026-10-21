<?php
/**
 * AJAX Endpoint to Save / Overwrite Global Target Settings (e.g., Target PPM)
 */
@ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

// Release PHP session lock immediately so other requests aren't blocked
$hasAccess = is_logged_in() && can_access('dashboard');
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if (!$hasAccess) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Akses ditolak']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metode request tidak diizinkan']);
    exit;
}

$pdo = getDB();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Koneksi database gagal']);
    exit;
}

$targetKey = sanitize($_POST['target_key'] ?? 'dashboard_target_ppm');
$allowedKeys = ['dashboard_target_ppm'];
if (!in_array($targetKey, $allowedKeys, true)) {
    $targetKey = 'dashboard_target_ppm';
}

$rawVal = trim($_POST['target_val'] ?? '');
if ($rawVal === '') {
    $targetVal = 0;
} else {
    $targetVal = max(0, (float)$rawVal);
}

try {
    // Pastikan tabel app_settings siap
    $pdo->exec("CREATE TABLE IF NOT EXISTS `app_settings` (
        `setting_key` VARCHAR(64) NOT NULL PRIMARY KEY,
        `setting_value` TEXT NULL,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Upsert query (nimpa / overwrite)
    $stmt = $pdo->prepare("INSERT INTO `app_settings` (`setting_key`, `setting_value`, `updated_at`)
        VALUES (:k, :v, NOW())
        ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`), `updated_at` = NOW()");
    $stmt->execute([
        ':k' => $targetKey,
        ':v' => (string)$targetVal
    ]);

    echo json_encode([
        'success'    => true,
        'target_key' => $targetKey,
        'target_val' => $targetVal,
        'message'    => 'Target PPM berhasil disimpan!'
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Gagal menyimpan target: ' . $e->getMessage()
    ]);
}
