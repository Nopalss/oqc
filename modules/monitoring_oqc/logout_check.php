<?php
/**
 * Quick Pre-Logout Checklist (Tahap Akhir 5S Penutupan Shift)
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

require_login();

$pdo = getDB();
$user = current_user();
$today = date('Y-m-d');

// Cari tahap akhir
$stmtP = $pdo->query("SELECT id FROM oqc_monitoring_phases WHERE phase_code = 'final' AND is_active = 1 LIMIT 1");
$finalPhase = $stmtP ? $stmtP->fetch(PDO::FETCH_ASSOC) : null;

if (!$finalPhase) {
    // Jika tahap akhir tidak ada atau tidak aktif, langsung logout
    redirect('logout.php');
}

$phaseId = (int)$finalPhase['id'];
$isCompleted = is_monitoring_phase_completed($pdo, $user['id'], 'final', $today);

if ($isCompleted) {
    redirect('logout.php');
}

// Redirect ke workspace monitoring di tab Tahap Akhir dengan parameter gatekeeper
redirect("modules/monitoring_oqc/index.php?phase_id={$phaseId}&gatekeeper=1");
