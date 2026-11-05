<?php
/**
 * Action Handler: Quick Toggle Active Status (Phase / Group / Item)
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

require_menu_access('monitoring_master');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/monitoring_master/index.php');
}

$submittedCsrf = $_POST['csrf_token'] ?? '';
$sessionCsrf   = $_SESSION['csrf_token'] ?? '';
if (!hash_equals($sessionCsrf, $submittedCsrf)) {
    set_flash('danger', 'Sesi tidak valid.');
    redirect('modules/monitoring_master/index.php');
}

$pdo = getDB();
if (!$pdo) {
    set_flash('danger', 'Koneksi database gagal.');
    redirect('modules/monitoring_master/index.php');
}

$type = sanitize($_POST['type'] ?? '');
$id   = (int)($_POST['id'] ?? 0);

if ($id <= 0 || !in_array($type, ['phase', 'group', 'item'])) {
    set_flash('danger', 'Parameter tidak valid.');
    redirect('modules/monitoring_master/index.php');
}

try {
    $table = '';
    if ($type === 'phase') $table = 'oqc_monitoring_phases';
    elseif ($type === 'group') $table = 'oqc_monitoring_groups';
    elseif ($type === 'item')  $table = 'oqc_monitoring_items';

    // Toggle status (1 -> 0, 0 -> 1)
    $stmt = $pdo->prepare("UPDATE {$table} SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id");
    $stmt->execute([':id' => $id]);

    set_flash('success', 'Status aktif berhasil diubah.');
} catch (PDOException $e) {
    set_flash('danger', 'Gagal mengubah status: ' . $e->getMessage());
}

$redirectPhase = isset($_POST['redirect_phase']) ? (int)$_POST['redirect_phase'] : 0;
$queryStr = $redirectPhase > 0 ? "?phase_id={$redirectPhase}" : '';
redirect('modules/monitoring_master/index.php' . $queryStr);

