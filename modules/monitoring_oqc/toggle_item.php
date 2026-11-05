<?php
/**
 * AJAX Handler: Toggle Check / Uncheck Item Checklist Monitoring OQC
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Silakan login terlebih dahulu.']);
    exit;
}

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST', 'GET'])) {
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$pdo = getDB();
if (!$pdo) {
    echo json_encode(['success' => false, 'message' => 'Koneksi database gagal.']);
    exit;
}

$checklistId = (int)($_POST['checklist_id'] ?? $_GET['checklist_id'] ?? 0);
$itemId      = (int)($_POST['item_id'] ?? $_GET['item_id'] ?? 0);
$state       = isset($_POST['state']) ? (int)$_POST['state'] : (isset($_GET['state']) ? (int)$_GET['state'] : 0);
$user        = current_user();

$userRoleNorm = strtolower(trim($user['role'] ?? ''));
if (!in_array($userRoleNorm, ['inspektur', 'inspektor', 'operator'], true)) {
    echo json_encode(['success' => false, 'message' => 'Akun admin/non-inspektur hanya memiliki hak pantau/audit.']);
    exit;
}

if ($checklistId <= 0 || $itemId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Parameter checklist_id atau item_id tidak valid.']);
    exit;
}

try {
    // Verifikasi kepemilikan atau role
    $stmtC = $pdo->prepare("SELECT * FROM oqc_inspector_checklists WHERE id = :id LIMIT 1");
    $stmtC->execute([':id' => $checklistId]);
    $chk = $stmtC->fetch(PDO::FETCH_ASSOC);

    if (!$chk) {
        echo json_encode(['success' => false, 'message' => 'Sesi checklist tidak ditemukan.']);
        exit;
    }

    $now = date('Y-m-d H:i:s');

    // Cek apakah item sudah pernah ada di tabel detail
    $stmtD = $pdo->prepare("SELECT id, is_checked FROM oqc_inspector_checklist_items WHERE checklist_id = :cid AND item_id = :iid LIMIT 1");
    $stmtD->execute([':cid' => $checklistId, ':iid' => $itemId]);
    $detail = $stmtD->fetch(PDO::FETCH_ASSOC);

    if ($detail) {
        if ($state) {
            $upd = $pdo->prepare("UPDATE oqc_inspector_checklist_items 
                                 SET is_checked = 1, 
                                     checked_at = :now 
                                 WHERE id = :id");
        } else {
            $upd = $pdo->prepare("UPDATE oqc_inspector_checklist_items 
                                 SET is_checked = 0, 
                                     unchecked_at = :now 
                                 WHERE id = :id");
        }
        $upd->execute([
            ':now' => $now,
            ':id'  => $detail['id']
        ]);
    } else {
        $ins = $pdo->prepare("INSERT INTO oqc_inspector_checklist_items 
                             (checklist_id, item_id, is_checked, checked_at, unchecked_at) 
                             VALUES (:cid, :iid, :st, :c_at, :u_at)");
        $ins->execute([
            ':cid'  => $checklistId,
            ':iid'  => $itemId,
            ':st'   => $state ? 1 : 0,
            ':c_at' => $state ? $now : null,
            ':u_at' => $state ? null : $now
        ]);
    }

    // Hitung ulang kepatuhan (dukungan gabungan all_checklist_ids jika ada)
    $rawCombined = $_POST['all_checklist_ids'] ?? $_GET['all_checklist_ids'] ?? '';
    if (!empty($rawCombined)) {
        $cIds = is_array($rawCombined) ? $rawCombined : explode(',', (string)$rawCombined);
        $cIds = array_filter(array_map('intval', $cIds));
        $compliance = calculate_combined_monitoring_compliance($pdo, $cIds);
    } else {
        $compliance = calculate_monitoring_compliance($pdo, $checklistId);
    }

    // Ambil info grup item ini untuk status grup
    $stmtG = $pdo->prepare("SELECT g.* FROM oqc_monitoring_items i 
                            JOIN oqc_monitoring_groups g ON i.group_id = g.id 
                            WHERE i.id = :iid LIMIT 1");
    $stmtG->execute([':iid' => $itemId]);
    $groupInfo = $stmtG->fetch(PDO::FETCH_ASSOC);

    $groupPassed = true;
    if ($groupInfo && in_array($groupInfo['title'], $compliance['missing_groups'], true)) {
        $groupPassed = false;
    }

    echo json_encode([
        'success'         => true,
        'item_id'         => $itemId,
        'is_checked'      => $state ? 1 : 0,
        'checked_time'    => $state ? date('H:i') : null,
        'group_id'        => $groupInfo ? (int)$groupInfo['id'] : 0,
        'group_passed'    => $groupPassed,
        'compliance_rate' => $compliance['compliance_rate'],
        'is_compliant'    => $compliance['is_compliant'],
        'total_items'     => $compliance['total_items'],
        'checked_items'   => $compliance['checked_items'],
        'missing_groups'  => $compliance['missing_groups']
    ]);

} catch (Exception $e) {
    error_log('[OQC Monitoring] Error toggle_item: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()]);
}
