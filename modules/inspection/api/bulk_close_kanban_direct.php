<?php
/**
 * AJAX API: Bulk Direct Close Kanban as Passed (Batch Option A)
 * Selesaikan beberapa Kanban sekaligus tanpa inspeksi fisik per box/lot.
 * Khusus untuk Administrator / Supervisor / Chief / Manager.
 * 
 * PT. Surya Technology Industri — OQC System
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/helper.php';
require_once __DIR__ . '/../../../config/summary_helper.php';

// Pastikan request metode POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Metode permintaan tidak valid (wajib POST).'
    ]);
    exit;
}

// Cek autentikasi user
$user = current_user();
if (empty($user['id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Sesi login telah berakhir. Silakan login kembali.'
    ]);
    exit;
}

$pdo = getDB();
if (!$pdo) {
    echo json_encode([
        'success' => false,
        'message' => 'Gagal terhubung ke database server!'
    ]);
    exit;
}

// Normalize role: cek role session maupun roles table agar tahan terhadap perbedaan huruf besar/kecil
$rawRole = strtolower(trim($user['role'] ?? ''));
$roleNamesList = [$rawRole];

if (!empty($user['role_id'])) {
    try {
        $stmtRoleCheck = $pdo->prepare("SELECT role_name, display_name FROM roles WHERE id = :rid LIMIT 1");
        $stmtRoleCheck->execute([':rid' => (int)$user['role_id']]);
        $rRow = $stmtRoleCheck->fetch(PDO::FETCH_ASSOC);
        if ($rRow) {
            $roleNamesList[] = strtolower(trim($rRow['role_name'] ?? ''));
            $roleNamesList[] = strtolower(trim($rRow['display_name'] ?? ''));
        }
    } catch (Exception $eRCheck) {}
}

$adminRoles = ['admin', 'superadmin', 'supervisor', 'chief', 'manager', 'lead', 'leader'];
$isAdminOrSupervisor = false;
foreach ($roleNamesList as $rCheck) {
    if ($rCheck !== '' && in_array($rCheck, $adminRoles, true)) {
        $isAdminOrSupervisor = true;
        break;
    }
}

if (!$isAdminOrSupervisor) {
    echo json_encode([
        'success' => false,
        'message' => 'Akses ditolak. Fitur Batch Direct Close Kanban hanya untuk Administrator / Supervisor / Chief.'
    ]);
    exit;
}

$rawIds = $_POST['kanban_ids'] ?? [];
$remarks = trim(sanitize($_POST['remarks'] ?? ''));

if (!is_array($rawIds) && is_string($rawIds)) {
    $rawIds = explode(',', $rawIds);
}

$kanbanIds = [];
foreach ($rawIds as $idVal) {
    $cleanId = (int)$idVal;
    if ($cleanId > 0) {
        $kanbanIds[] = $cleanId;
    }
}
$kanbanIds = array_unique($kanbanIds);

if (empty($kanbanIds)) {
    echo json_encode([
        'success' => false,
        'message' => 'Pilih minimal satu Kanban yang ingin diselesaikan!'
    ]);
    exit;
}

$processedCount = 0;
$totalQtyClosed = 0;
$skippedCount = 0;
$inspectorId = (int)$user['id'];
$inspectorName = $user['name'] ?? 'Administrator';

try {
    $pdo->beginTransaction();

    foreach ($kanbanIds as $kanbanId) {
        // Lock baris kanban_items
        $stmtK = $pdo->prepare("SELECT id, batch_id, plan_type, kanban_no, item_code, item_description, customer, qty, status FROM kanban_items WHERE id = :kid FOR UPDATE");
        $stmtK->execute([':kid' => $kanbanId]);
        $kRow = $stmtK->fetch(PDO::FETCH_ASSOC);

        if (!$kRow || $kRow['status'] === 'completed') {
            $skippedCount++;
            continue;
        }

        $targetQty = (int)$kRow['qty'];

        // Cek sesi aktif
        $stmtS = $pdo->prepare("SELECT id, line_id, line_name, total_scanned_qty, excess_qty, status, inspector_id FROM inspection_sessions WHERE kanban_item_id = :kid ORDER BY id ASC");
        $stmtS->execute([':kid' => $kanbanId]);
        $allSessions = $stmtS->fetchAll(PDO::FETCH_ASSOC);

        $hasInProgress = false;
        $alreadyPassed = 0;
        foreach ($allSessions as $s) {
            if ($s['status'] === 'in_progress') {
                $hasInProgress = true;
                break;
            }
            if ($s['status'] === 'passed') {
                $effective = (int)$s['total_scanned_qty'] - (int)($s['excess_qty'] ?? 0);
                $alreadyPassed += max(0, $effective);
            }
        }

        // Jangan direct close jika sedang ada orang yang mengerjakan sesi aktif
        if ($hasInProgress) {
            $skippedCount++;
            continue;
        }

        $remainingQty = max(0, $targetQty - $alreadyPassed);

        if ($remainingQty <= 0) {
            $stmtUpdK = $pdo->prepare("UPDATE kanban_items SET status = 'completed', updated_at = NOW() WHERE id = :kid");
            $stmtUpdK->execute([':kid' => $kanbanId]);
            $processedCount++;
            continue;
        }

        // Cari master part & AQL Level
        $partCode = strtoupper(trim($kRow['item_code']));
        $partId = 0;
        $partAqlLevel = 'G-II';
        $partName = !empty($kRow['item_description']) ? $kRow['item_description'] : ('Part ' . $partCode);

        $stmtP = $pdo->prepare("SELECT id, part_name, aql_level FROM master_parts WHERE UPPER(part_code) = :pcode LIMIT 1");
        $stmtP->execute([':pcode' => $partCode]);
        $pRow = $stmtP->fetch(PDO::FETCH_ASSOC);
        if ($pRow) {
            $partId = (int)$pRow['id'];
            if (!empty($pRow['part_name'])) $partName = $pRow['part_name'];
            if (!empty($pRow['aql_level'])) $partAqlLevel = $pRow['aql_level'];
        }

        // Hitung sampling AQL Level II
        $stmtAql = $pdo->prepare("SELECT sample_code, sample_size, accept_number, reject_number FROM aql_standards WHERE inspection_level = :lvl AND :qty BETWEEN qty_min AND qty_max LIMIT 1");
        $stmtAql->execute([':lvl' => $partAqlLevel, ':qty' => $remainingQty]);
        $aqlRow = $stmtAql->fetch(PDO::FETCH_ASSOC);

        if (!$aqlRow) {
            $stmtAqlFB = $pdo->prepare("SELECT sample_code, sample_size, accept_number, reject_number FROM aql_standards WHERE :qty BETWEEN qty_min AND qty_max LIMIT 1");
            $stmtAqlFB->execute([':qty' => $remainingQty]);
            $aqlRow = $stmtAqlFB->fetch(PDO::FETCH_ASSOC);
        }

        if ($aqlRow) {
            $sampleSize = min((int)$aqlRow['sample_size'], $remainingQty);
            $sampleCode = $aqlRow['sample_code'] ?: 'H';
            $acceptNumber = (int)$aqlRow['accept_number'];
            $rejectNumber = (int)$aqlRow['reject_number'];
        } else {
            $sampleSize = min(50, $remainingQty);
            $sampleCode = 'H';
            $acceptNumber = 0;
            $rejectNumber = 1;
        }

        // Pastikan DID tersedia
        $stmtDid = $pdo->prepare("SELECT id FROM daily_inspection_data WHERE UPPER(part_code) = :pcode AND inspecting_date = CURDATE() ORDER BY id DESC LIMIT 1");
        $stmtDid->execute([':pcode' => $partCode]);
        $didId = (int)$stmtDid->fetchColumn();

        $kanbanNoClean = $kRow['kanban_no'] ?: (string)$kanbanId;
        $lotNumberAuto = 'KB-' . $kanbanNoClean;

        if ($didId <= 0) {
            $stmtInsDid = $pdo->prepare("INSERT INTO daily_inspection_data 
                (part_code, part_name, lot_number, cavity, inspecting_date, status_inspect, pic, created_at) 
                VALUES (:pcode, :pname, :lot, '1', CURDATE(), 'OK', :pic, NOW())");
            $stmtInsDid->execute([
                ':pcode' => $partCode,
                ':pname' => $partName,
                ':lot' => $lotNumberAuto,
                ':pic' => $inspectorName
            ]);
            $didId = (int)$pdo->lastInsertId();
        }

        $customNotes = 'Batch Direct Close by ' . $inspectorName;
        if (!empty($remarks)) {
            $customNotes .= ' (' . $remarks . ')';
        }

        // Buat Sesi Baru Passed
        $stmtInsSess = $pdo->prepare("INSERT INTO inspection_sessions 
            (flow_version, inspection_type, did_id, kanban_item_id, part_id, inspector_id, line_id, line_name, sample_size, total_scanned_qty, batch_sample_size, use_safety_stock_qty, excess_qty, reject_number, samples_checked, ng_count, status, approval_notes, started_at, closed_at) 
            VALUES (2, 'kanban', :did, :kid, :pid, :insp, 1, 'Line 1', :ssize, :tqty, :batch_ssize, 0, 0, :rej, :samples_checked, 0, 'passed', :app_notes, NOW(), NOW())");
        $stmtInsSess->execute([
            ':did' => $didId,
            ':kid' => $kanbanId,
            ':pid' => $partId ?: null,
            ':insp' => $inspectorId,
            ':ssize' => $sampleSize,
            ':tqty' => $remainingQty,
            ':batch_ssize' => $sampleSize,
            ':rej' => $rejectNumber,
            ':samples_checked' => $sampleSize,
            ':app_notes' => $customNotes
        ]);
        $newSessionId = (int)$pdo->lastInsertId();

        // Buat Lot Virtual Passed
        $lotRemarks = !empty($remarks) ? $remarks : 'Batch Direct Close without physical check (Auto Passed)';
        $stmtInsLot = $pdo->prepare("INSERT INTO inspection_session_lots 
            (inspection_session_id, ref_number, lot_number, qty, aql_level, sample_code, sample_size, accept_number, reject_number, ng_count, lot_result, remarks, lot_status, created_at) 
            VALUES (:sid, 'DIRECT-PASS', :lot, :qty, :aql_lvl, :spl_code, :spl_size, :acc, :rej, 0, 'passed', :remarks, 'ok', NOW())");
        $stmtInsLot->execute([
            ':sid' => $newSessionId,
            ':lot' => $lotNumberAuto,
            ':qty' => $remainingQty,
            ':aql_lvl' => $partAqlLevel,
            ':spl_code' => $sampleCode,
            ':spl_size' => $sampleSize,
            ':acc' => $acceptNumber,
            ':rej' => $rejectNumber,
            ':remarks' => $lotRemarks
        ]);

        // Update status kanban_items
        $stmtUpdK = $pdo->prepare("UPDATE kanban_items SET status = 'completed', updated_at = NOW() WHERE id = :kid");
        $stmtUpdK->execute([':kid' => $kanbanId]);

        $processedCount++;
        $totalQtyClosed += $remainingQty;

        if (function_exists('syncKanbanStatus')) {
            syncKanbanStatus($pdo, $kanbanId);
        }
    }

    $pdo->commit();

    if (function_exists('syncDailySummaryForDate')) {
        syncDailySummaryForDate($pdo, date('Y-m-d'));
    }

    $msg = "Berhasil menyelesaikan {$processedCount} Kanban sekaligus (Total Qty: " . number_format($totalQtyClosed) . " pcs).";
    if ($skippedCount > 0) {
        $msg .= " ({$skippedCount} dilewati karena sedang berjalan atau sudah selesai).";
    }

    echo json_encode([
        'success' => true,
        'message' => $msg,
        'data' => [
            'processed_count' => $processedCount,
            'total_qty' => $totalQtyClosed,
            'skipped_count' => $skippedCount
        ]
    ]);
} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem saat memproses batch: ' . $e->getMessage()
    ]);
}
