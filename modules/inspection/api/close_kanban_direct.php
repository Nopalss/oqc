<?php
/**
 * AJAX API: Direct Close Kanban as Passed (Option A)
 * Selesaikan Kanban secara langsung tanpa inspeksi fisik per box/lot.
 * Otomatis membuatkan Sesi Inspeksi (Passed) & Virtual Lot dengan standar sampling AQL yang tepat
 * agar data tetap akurat 100% untuk kalkulasi OQC PPM (Parts Per Million) & KPI Dashboard.
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

// Hanya role Admin/Supervisor/Chief/Manager yang boleh Direct Close
$isAdminOrSupervisor = in_array($user['role'] ?? '', ['supervisor', 'admin', 'superadmin', 'chief', 'manager'], true);
if (!$isAdminOrSupervisor) {
    echo json_encode([
        'success' => false,
        'message' => 'Akses ditolak. Fitur Direct Close Kanban hanya untuk Administrator / Supervisor / Chief.'
    ]);
    exit;
}

// Release PHP session lock immediately so parallel AJAX / polling requests are not blocked
session_write_close();

$pdo = getDB();
if (!$pdo) {
    echo json_encode([
        'success' => false,
        'message' => 'Gagal terhubung ke database server!'
    ]);
    exit;
}

$kanbanId = (int)($_POST['kanban_id'] ?? 0);
$remarks  = trim(sanitize($_POST['remarks'] ?? ''));

if ($kanbanId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'ID Kanban tidak valid!'
    ]);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Lock dan ambil data kanban_items
    $stmtK = $pdo->prepare("SELECT id, batch_id, plan_type, kanban_no, item_code, item_description, customer, qty, status FROM kanban_items WHERE id = :kid FOR UPDATE");
    $stmtK->execute([':kid' => $kanbanId]);
    $kRow = $stmtK->fetch(PDO::FETCH_ASSOC);

    if (!$kRow) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'message' => 'Data Kanban tidak ditemukan di database!'
        ]);
        exit;
    }

    if ($kRow['status'] === 'completed') {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'message' => "Kanban #{$kRow['kanban_no']} sudah berstatus Selesai (Completed)."
        ]);
        exit;
    }

    $targetQty = (int)$kRow['qty'];

    // 2. Cek seluruh sesi yang terhubung dengan kanban ini
    $stmtS = $pdo->prepare("SELECT id, line_id, line_name, total_scanned_qty, excess_qty, status, inspector_id FROM inspection_sessions WHERE kanban_item_id = :kid ORDER BY id ASC");
    $stmtS->execute([':kid' => $kanbanId]);
    $allSessions = $stmtS->fetchAll(PDO::FETCH_ASSOC);

    $alreadyPassed = 0;
    foreach ($allSessions as $s) {
        if ($s['status'] === 'in_progress') {
            $pdo->rollBack();
            $line = $s['line_name'] ?: 'Line';
            echo json_encode([
                'success' => false,
                'message' => "Kanban ini sedang dikerjakan pada Sesi #{$s['id']} ({$line}) dan belum selesai. Harap selesaikan atau batalkan sesi tersebut terlebih dahulu."
            ]);
            exit;
        }

        if ($s['status'] === 'passed') {
            $effective = (int)$s['total_scanned_qty'] - (int)($s['excess_qty'] ?? 0);
            $alreadyPassed += max(0, $effective);
        }
    }

    $remainingQty = max(0, $targetQty - $alreadyPassed);

    // Jika kuota sudah habis / terpenuhi, update status kanban dan selesai
    if ($remainingQty <= 0) {
        $stmtUpdK = $pdo->prepare("UPDATE kanban_items SET status = 'completed', updated_at = NOW() WHERE id = :kid");
        $stmtUpdK->execute([':kid' => $kanbanId]);

        $pdo->commit();
        if (function_exists('syncKanbanStatus')) {
            syncKanbanStatus($pdo, $kanbanId);
        }
        if (function_exists('syncDailySummaryForDate')) {
            syncDailySummaryForDate($pdo, date('Y-m-d'));
        }

        echo json_encode([
            'success' => true,
            'message' => "Seluruh kuota Qty Kanban #{$kRow['kanban_no']} ({$targetQty} pcs) sudah terpenuhi.",
            'data'    => ['kanban_id' => $kanbanId, 'status' => 'completed', 'remaining_qty' => 0]
        ]);
        exit;
    }

    // 3. Cari detail master part & AQL Level
    $partCode = strtoupper(trim($kRow['item_code']));
    $partId   = 0;
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

    // 4. Hitung Standar Sampling AQL untuk remainingQty
    $stmtAql = $pdo->prepare("SELECT sample_code, sample_size, accept_number, reject_number FROM aql_standards WHERE inspection_level = :lvl AND :qty BETWEEN qty_min AND qty_max LIMIT 1");
    $stmtAql->execute([':lvl' => $partAqlLevel, ':qty' => $remainingQty]);
    $aqlRow = $stmtAql->fetch(PDO::FETCH_ASSOC);

    if (!$aqlRow) {
        $stmtAqlFB = $pdo->prepare("SELECT sample_code, sample_size, accept_number, reject_number FROM aql_standards WHERE :qty BETWEEN qty_min AND qty_max LIMIT 1");
        $stmtAqlFB->execute([':qty' => $remainingQty]);
        $aqlRow = $stmtAqlFB->fetch(PDO::FETCH_ASSOC);
    }

    if ($aqlRow) {
        $sampleSize   = min((int)$aqlRow['sample_size'], $remainingQty);
        $sampleCode   = $aqlRow['sample_code'] ?: 'H';
        $acceptNumber = (int)$aqlRow['accept_number'];
        $rejectNumber = (int)$aqlRow['reject_number'];
    } else {
        $sampleSize   = min(50, $remainingQty);
        $sampleCode   = 'H';
        $acceptNumber = 0;
        $rejectNumber = 1;
    }

    // 5. Pastikan Daily Inspection Data (DID) tersedia untuk part & hari ini
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
            ':lot'   => $lotNumberAuto,
            ':pic'   => $user['name'] ?? 'SYSTEM'
        ]);
        $didId = (int)$pdo->lastInsertId();
    }

    // 6. Buat Sesi Baru (status = 'passed', ng_count = 0)
    $inspectorId   = (int)$user['id'];
    $inspectorName = $user['name'] ?? 'Inspector';
    $customNotes   = 'Direct Close by ' . $inspectorName;
    if (!empty($remarks)) {
        $customNotes .= ' (' . $remarks . ')';
    }

    $stmtInsSess = $pdo->prepare("INSERT INTO inspection_sessions 
        (flow_version, inspection_type, did_id, kanban_item_id, part_id, inspector_id, line_id, line_name, sample_size, total_scanned_qty, batch_sample_size, use_safety_stock_qty, excess_qty, reject_number, samples_checked, ng_count, status, approval_notes, started_at, closed_at) 
        VALUES (2, 'kanban', :did, :kid, :pid, :insp, 1, 'Line 1', :ssize, :tqty, :batch_ssize, 0, 0, :rej, :samples_checked, 0, 'passed', :app_notes, NOW(), NOW())");
    $stmtInsSess->execute([
        ':did'             => $didId,
        ':kid'             => $kanbanId,
        ':pid'             => $partId ?: null,
        ':insp'            => $inspectorId,
        ':ssize'           => $sampleSize,
        ':tqty'            => $remainingQty,
        ':batch_ssize'     => $sampleSize,
        ':rej'             => $rejectNumber,
        ':samples_checked' => $sampleSize,
        ':app_notes'       => $customNotes
    ]);
    $newSessionId = (int)$pdo->lastInsertId();

    // 7. Buat 1 Record Lot Virtual (Passed)
    $lotRemarks = !empty($remarks) ? $remarks : 'Direct Close without physical check (Auto Passed)';
    $stmtInsLot = $pdo->prepare("INSERT INTO inspection_session_lots 
        (inspection_session_id, ref_number, lot_number, qty, aql_level, sample_code, sample_size, accept_number, reject_number, ng_count, lot_result, remarks, lot_status, created_at) 
        VALUES (:sid, 'DIRECT-PASS', :lot, :qty, :aql_lvl, :spl_code, :spl_size, :acc, :rej, 0, 'passed', :remarks, 'ok', NOW())");
    $stmtInsLot->execute([
        ':sid'      => $newSessionId,
        ':lot'      => $lotNumberAuto,
        ':qty'      => $remainingQty,
        ':aql_lvl'  => $partAqlLevel,
        ':spl_code' => $sampleCode,
        ':spl_size' => $sampleSize,
        ':acc'      => $acceptNumber,
        ':rej'      => $rejectNumber,
        ':remarks'  => $lotRemarks
    ]);

    // 8. Update status kanban_items menjadi 'completed'
    $stmtUpdK = $pdo->prepare("UPDATE kanban_items SET status = 'completed', updated_at = NOW() WHERE id = :kid");
    $stmtUpdK->execute([':kid' => $kanbanId]);

    // Commit seluruh transaksi database
    $pdo->commit();

    // 9. Sinkronisasi status Kanban & Agregasi Dashboard PPM Harian
    if (function_exists('syncKanbanStatus')) {
        syncKanbanStatus($pdo, $kanbanId);
    }
    if (function_exists('syncDailySummaryForDate')) {
        syncDailySummaryForDate($pdo, date('Y-m-d'));
    }

    echo json_encode([
        'success' => true,
        'message' => "Kanban #{$kanbanNoClean} berhasil diselesaikan langsung (Passed).",
        'data'    => [
            'kanban_id'        => $kanbanId,
            'kanban_no'        => $kanbanNoClean,
            'session_id'       => $newSessionId,
            'closed_qty'       => $remainingQty,
            'total_target_qty' => $targetQty,
            'sample_size'      => $sampleSize,
            'status'           => 'completed'
        ]
    ]);
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem saat menutup Kanban: ' . $e->getMessage()
    ]);
    exit;
}
