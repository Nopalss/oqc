<?php
/**
 * AJAX Handler: Batch Check / Uncheck All Items (Phase-wide or Group-wide)
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

$rawChecklistIds = $_POST['checklist_ids'] ?? $_POST['checklist_id'] ?? $_GET['checklist_ids'] ?? $_GET['checklist_id'] ?? 0;
if (!is_array($rawChecklistIds)) {
    $rawChecklistIds = explode(',', (string)$rawChecklistIds);
}
$checklistIds = array_values(array_filter(array_map('intval', $rawChecklistIds)));
$state       = isset($_POST['state']) ? (int)$_POST['state'] : (isset($_GET['state']) ? (int)$_GET['state'] : 1);
$groupId     = (int)($_POST['group_id'] ?? $_GET['group_id'] ?? 0);
$user        = current_user();

$userRoleNorm = strtolower(trim($user['role'] ?? ''));
if (!in_array($userRoleNorm, ['inspektur', 'inspektor', 'operator'], true)) {
    echo json_encode(['success' => false, 'message' => 'Akun admin/non-inspektur hanya memiliki hak pantau/audit.']);
    exit;
}

if (empty($checklistIds)) {
    echo json_encode(['success' => false, 'message' => 'Parameter checklist_id tidak valid.']);
    exit;
}

try {
    $now = date('Y-m-d H:i:s');
    $targetItemIds = [];
    $allPhasesToInspect = [];

    $pdo->beginTransaction();

    foreach ($checklistIds as $chkId) {
        // 1. Verifikasi checklist session
        $stmtC = $pdo->prepare("SELECT * FROM oqc_inspector_checklists WHERE id = :id LIMIT 1");
        $stmtC->execute([':id' => $chkId]);
        $chk = $stmtC->fetch(PDO::FETCH_ASSOC);
        if (!$chk) continue;

        $phaseId = (int)$chk['phase_id'];
        $allPhasesToInspect[] = $phaseId;

        // 2. Ambil seluruh item target
        if ($groupId > 0) {
            $stmtItems = $pdo->prepare("SELECT i.id, i.group_id 
                                        FROM oqc_monitoring_items i
                                        JOIN oqc_monitoring_groups g ON i.group_id = g.id
                                        WHERE i.group_id = :gid AND g.phase_id = :pid AND i.is_active = 1
                                        ORDER BY i.sort_order ASC");
            $stmtItems->execute([':gid' => $groupId, ':pid' => $phaseId]);
        } else {
            $stmtItems = $pdo->prepare("SELECT i.id, i.group_id 
                                        FROM oqc_monitoring_items i
                                        JOIN oqc_monitoring_groups g ON i.group_id = g.id
                                        WHERE g.phase_id = :pid AND i.is_active = 1 AND g.is_active = 1
                                        ORDER BY g.sort_order ASC, i.sort_order ASC");
            $stmtItems->execute([':pid' => $phaseId]);
        }

        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
        if (empty($items)) continue;

        if ($state === 1) {
            // Ceklis semua
            $stmtUpsert = $pdo->prepare("INSERT INTO oqc_inspector_checklist_items 
                                        (checklist_id, item_id, is_checked, checked_at, unchecked_at)
                                        VALUES (:cid, :iid, 1, :now, NULL)
                                        ON DUPLICATE KEY UPDATE 
                                            is_checked = 1,
                                            checked_at = VALUES(checked_at),
                                            unchecked_at = NULL");
            foreach ($items as $it) {
                $itemId = (int)$it['id'];
                $targetItemIds[] = $itemId;
                $stmtUpsert->execute([
                    ':cid' => $chkId,
                    ':iid' => $itemId,
                    ':now' => $now
                ]);
            }
        } else {
            // Reset / uncheck semua
            $stmtUpsert = $pdo->prepare("INSERT INTO oqc_inspector_checklist_items 
                                        (checklist_id, item_id, is_checked, checked_at, unchecked_at)
                                        VALUES (:cid, :iid, 0, NULL, :now)
                                        ON DUPLICATE KEY UPDATE 
                                            is_checked = 0,
                                            unchecked_at = VALUES(unchecked_at)");
            foreach ($items as $it) {
                $itemId = (int)$it['id'];
                $targetItemIds[] = $itemId;
                $stmtUpsert->execute([
                    ':cid' => $chkId,
                    ':iid' => $itemId,
                    ':now' => $now
                ]);
            }
        }
    }

    $pdo->commit();

    // 3. Hitung ulang kepatuhan (mendukung gabungan jika checklistIds > 1)
    if (count($checklistIds) > 1) {
        $compliance = calculate_combined_monitoring_compliance($pdo, $checklistIds);
    } else {
        $compliance = calculate_monitoring_compliance($pdo, $checklistIds[0]);
    }

    // Ambil info status per grup untuk grup-grup terkait
    $groupStatuses = [];
    $allPhasesToInspect = array_unique($allPhasesToInspect);

    foreach ($allPhasesToInspect as $pId) {
        $stmtAllGroups = $pdo->prepare("SELECT id, title FROM oqc_monitoring_groups WHERE phase_id = :pid AND is_active = 1");
        $stmtAllGroups->execute([':pid' => $pId]);
        $allGroups = $stmtAllGroups->fetchAll(PDO::FETCH_ASSOC);

        foreach ($allGroups as $ag) {
            $gid = (int)$ag['id'];
            $passed = !in_array($ag['title'], $compliance['missing_groups'], true);
            $groupStatuses[$gid] = $passed;
        }
    }

    echo json_encode([
        'success'         => true,
        'state'           => $state ? 1 : 0,
        'checked_time'    => $state ? date('H:i') : null,
        'item_ids'        => array_values(array_unique($targetItemIds)),
        'group_id'        => $groupId,
        'group_statuses'  => $groupStatuses,
        'compliance_rate' => $compliance['compliance_rate'],
        'is_compliant'    => $compliance['is_compliant'],
        'total_items'     => $compliance['total_items'],
        'checked_items'   => $compliance['checked_items'],
        'missing_groups'  => $compliance['missing_groups']
    ]);
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[OQC Monitoring] Error toggle_all: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()]);
}

