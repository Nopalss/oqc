<?php
/**
 * Action Handler Hapus Data Master Template Monitoring OQC
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

require_menu_access('monitoring_master');

$redirectPhase = (int)($_POST['redirect_phase'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/monitoring_master/index.php');
}

$submittedCsrf = $_POST['csrf_token'] ?? '';
$sessionCsrf   = $_SESSION['csrf_token'] ?? '';
if (!hash_equals($sessionCsrf, $submittedCsrf)) {
    set_flash('danger', 'Sesi kedaluwarsa atau token tidak valid.');
    redirect('modules/monitoring_master/index.php');
}

$pdo = getDB();
if (!$pdo) {
    set_flash('danger', 'Koneksi database gagal.');
    redirect('modules/monitoring_master/index.php');
}

$targetType = sanitize($_POST['target_type'] ?? '');
$targetId   = (int)($_POST['target_id'] ?? 0);

if ($targetId <= 0) {
    set_flash('danger', 'ID data tidak valid.');
    redirect('modules/monitoring_master/index.php');
}

try {
    if ($targetType === 'phase') {
        $stmt = $pdo->prepare("DELETE FROM oqc_monitoring_phases WHERE id = :id");
        $stmt->execute([':id' => $targetId]);
        set_flash('success', 'Tahap SOP berhasil dihapus beserta seluruh grup dan item di dalamnya.');

    } elseif ($targetType === 'group') {
        $stmt = $pdo->prepare("DELETE FROM oqc_monitoring_groups WHERE id = :id");
        $stmt->execute([':id' => $targetId]);
        set_flash('success', 'Grup / Kategori berhasil dihapus beserta butir checklist di dalamnya.');

    } elseif ($targetType === 'item') {
        $stmt = $pdo->prepare("DELETE FROM oqc_monitoring_items WHERE id = :id");
        $stmt->execute([':id' => $targetId]);
        set_flash('success', 'Butir checklist berhasil dihapus.');

    } else {
        set_flash('danger', 'Tipe target hapus tidak valid.');
    }
} catch (PDOException $e) {
    set_flash('danger', 'Gagal menghapus data: ' . $e->getMessage());
}

$targetUrl = 'modules/monitoring_master/index.php';
if ($redirectPhase > 0) {
    $targetUrl .= '?phase_id=' . $redirectPhase;
}
redirect($targetUrl);
