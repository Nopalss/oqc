<?php
/**
 * Action Handler: Update Target PPM Setting
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/performance_report/index.php');
}

$newTargetPpm = filter_input(INPUT_POST, 'target_ppm', FILTER_VALIDATE_INT);
$redirectUrl  = sanitize($_POST['redirect_url'] ?? 'modules/performance_report/index.php');

if ($newTargetPpm === false || $newTargetPpm === null || $newTargetPpm < 0) {
    set_flash('error', 'Target PPM harus berupa angka bulat positif.');
    redirect($redirectUrl);
}

$pdo = getDB();
if ($pdo) {
    try {
        $stmt = $pdo->prepare("INSERT INTO `system_settings` (`setting_key`, `setting_value`, `description`, `updated_at`) 
                               VALUES ('target_ppm', :val, 'Target PPM Maksimal untuk STI Survival Performance Report', NOW()) 
                               ON DUPLICATE KEY UPDATE `setting_value` = :val2, `updated_at` = NOW()");
        $stmt->execute([':val' => (string)$newTargetPpm, ':val2' => (string)$newTargetPpm]);
        set_flash('success', 'Target PPM berhasil diperbarui menjadi ' . number_format($newTargetPpm) . ' PPM.');
    } catch (PDOException $e) {
        set_flash('error', 'Gagal memperbarui Target PPM: ' . $e->getMessage());
    }
}

redirect($redirectUrl);
