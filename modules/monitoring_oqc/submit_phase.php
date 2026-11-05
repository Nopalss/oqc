<?php
/**
 * Action Handler Submit / Selesai Pengisian Checklist Monitoring OQC
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

if (!is_logged_in()) {
    redirect('login.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/monitoring_oqc/index.php');
}

$submittedCsrf = $_POST['csrf_token'] ?? '';
$sessionCsrf   = $_SESSION['csrf_token'] ?? '';
if (!hash_equals($sessionCsrf, $submittedCsrf)) {
    set_flash('danger', 'Sesi kedaluwarsa atau token tidak valid.');
    redirect('modules/monitoring_oqc/index.php');
}

$pdo = getDB();
if (!$pdo) {
    set_flash('danger', 'Koneksi database gagal.');
    redirect('modules/monitoring_oqc/index.php');
}

$user = current_user();
$userRoleNorm = strtolower(trim($user['role'] ?? ''));
if (!in_array($userRoleNorm, ['inspektur', 'inspektor', 'operator'], true)) {
    set_flash('danger', 'Akun admin/non-inspektur hanya memiliki akses monitoring dan tidak dapat mengisi checklist.');
    redirect('modules/monitoring_oqc/audit.php');
}

$rawChecklistIds = $_POST['checklist_ids'] ?? $_POST['checklist_id'] ?? 0;
if (!is_array($rawChecklistIds)) {
    $rawChecklistIds = explode(',', (string)$rawChecklistIds);
}
$checklistIds = array_values(array_filter(array_map('intval', $rawChecklistIds)));
$notes        = trim(sanitize($_POST['notes'] ?? ''));
$isGatekeeper = !empty($_POST['is_gatekeeper']);

if (empty($checklistIds)) {
    set_flash('danger', 'Sesi checklist tidak valid.');
    redirect('modules/monitoring_oqc/index.php');
}

try {
    $processedChecklists = [];
    $allCompliant = true;
    $allMissingGroups = [];
    $primaryTrigger = 'post_login';
    $phaseNames = [];

    foreach ($checklistIds as $chkId) {
        $stmtC = $pdo->prepare("SELECT c.*, p.trigger_event, p.name as phase_name 
                                FROM oqc_inspector_checklists c 
                                JOIN oqc_monitoring_phases p ON c.phase_id = p.id 
                                WHERE c.id = :id LIMIT 1");
        $stmtC->execute([':id' => $chkId]);
        $chk = $stmtC->fetch(PDO::FETCH_ASSOC);
        if (!$chk) continue;

        $processedChecklists[] = $chk;
        $phaseNames[] = $chk['phase_name'];
        if ((int)$chk['phase_id'] === 3 || $chk['trigger_event'] === 'pre_logout') {
            $primaryTrigger = 'pre_logout';
        }

        // Hitung kepatuhan
        $compliance = calculate_monitoring_compliance($pdo, $chkId);

        // Simpan catatan jika ada
        if ($notes !== '') {
            $upd = $pdo->prepare("UPDATE oqc_inspector_checklists SET notes = :notes WHERE id = :id");
            $upd->execute([':notes' => $notes, ':id' => $chkId]);
        }

        if (!$compliance['is_compliant']) {
            $allCompliant = false;
            if (!empty($compliance['missing_groups'])) {
                $allMissingGroups = array_merge($allMissingGroups, $compliance['missing_groups']);
            }
        }
    }

    if (empty($processedChecklists)) {
        set_flash('danger', 'Data checklist tidak ditemukan.');
        redirect('modules/monitoring_oqc/index.php');
    }

    // Evaluasi kelengkapan
    if (!$allCompliant) {
        $missingList = implode(', ', array_unique($allMissingGroups));
        set_flash('warning', "Checklist belum memenuhi standar SOP! Mohon lengkapi bagian: <b>{$missingList}</b>.");
        redirect("modules/monitoring_oqc/index.php?phase_id={$processedChecklists[0]['phase_id']}&gatekeeper=" . ($isGatekeeper ? '1' : '0'));
    }

    // Jika sudah compliant semua
    $namesStr = implode(' & ', array_unique($phaseNames));
    set_flash('success', "Checklist <b>{$namesStr}</b> berhasil diselesaikan!");

    // Set session flag untuk bypass gatekeeper hari ini
    $_SESSION['oqc_early_check_completed_' . date('Y-m-d')] = true;

    if ($primaryTrigger === 'post_login' || $isGatekeeper) {
        redirect('modules/inspection/index.php');
    } elseif ($primaryTrigger === 'pre_logout') {
        redirect('logout.php');
    } else {
        redirect("modules/monitoring_oqc/index.php");
    }

} catch (Exception $e) {
    set_flash('danger', 'Gagal memproses checklist: ' . $e->getMessage());
    redirect('modules/monitoring_oqc/index.php');
}
