<?php
/**
 * API: Submit Close Kanban dengan Multi-Lot Fisik
 * Menyimpan sesi inspeksi Passed dan mendaftarkan lot-lot fisik yang dimasukkan user
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/helper.php';
require_once __DIR__ . '/../../../config/summary_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Metode request wajib POST']);
    exit;
}

$user = current_user();
if (empty($user['id'])) {
    echo json_encode(['success' => false, 'message' => 'Sesi login telah berakhir']);
    exit;
}

$pdo = getDB();
if (!$pdo) {
    echo json_encode(['success' => false, 'message' => 'Gagal koneksi database']);
    exit;
}

$kanbanId = (int)($_POST['kanban_id'] ?? 0);
$remarks  = trim(sanitize($_POST['remarks'] ?? ''));
$rawLots  = $_POST['lots'] ?? [];

if ($kanbanId <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID Kanban tidak valid']);
    exit;
}

if (empty($rawLots) || !is_array($rawLots)) {
    echo json_encode(['success' => false, 'message' => 'Minimal harus memasukkan 1 Lot']);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Lock data Kanban
    $stmtK = $pdo->prepare("SELECT id, batch_id, plan_type, kanban_no, item_code, item_description, customer, qty, status FROM kanban_items WHERE id = :kid FOR UPDATE");
    $stmtK->execute([':kid' => $kanbanId]);
    $kRow = $stmtK->fetch(PDO::FETCH_ASSOC);

    if (!$kRow) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Data Kanban tidak ditemukan']);
        exit;
    }

    if ($kRow['status'] === 'completed') {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => "Kanban #{$kRow['kanban_no']} sudah berstatus Selesai"]);
        exit;
    }

    $targetQty = (int)$kRow['qty'];

    // 2. Hitung kuota yang sudah lulus dari sesi sebelumnya
    $stmtS = $pdo->prepare("SELECT id, line_id, line_name, total_scanned_qty, excess_qty, status FROM inspection_sessions WHERE kanban_item_id = :kid ORDER BY id ASC");
    $stmtS->execute([':kid' => $kanbanId]);
    $allSessions = $stmtS->fetchAll(PDO::FETCH_ASSOC);

    $alreadyPassed = 0;
    foreach ($allSessions as $s) {
        if ($s['status'] === 'in_progress') {
            $pdo->rollBack();
            $line = $s['line_name'] ?: 'Line';
            echo json_encode([
                'success' => false,
                'message' => "Kanban sedang dikerjakan pada Sesi #{$s['id']} ({$line}). Selesaikan atau batalkan sesi aktif tersebut terlebih dahulu."
            ]);
            exit;
        }
        if ($s['status'] === 'passed') {
            $effective = (int)$s['total_scanned_qty'] - (int)($s['excess_qty'] ?? 0);
            $alreadyPassed += max(0, $effective);
        }
    }

    $remainingQty = max(0, $targetQty - $alreadyPassed);
    if ($remainingQty <= 0) {
        $pdo->prepare("UPDATE kanban_items SET status = 'completed', updated_at = NOW() WHERE id = :kid")->execute([':kid' => $kanbanId]);
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Kanban sudah terpenuhi dan ditutup']);
        exit;
    }

    // 3. Filter dan olah lot yang valid
    $cleanLots = [];
    foreach ($rawLots as $l) {
        $lNum = trim(sanitize($l['lot_number'] ?? ''));
        $rNum = trim(sanitize($l['ref_number'] ?? ''));
        $qVal = isset($l['qty']) && is_numeric($l['qty']) ? (int)$l['qty'] : 0;
        $isFixed = !empty($l['ng_fixed']) ? 1 : 0;

        if (!empty($lNum)) {
            $cleanLots[] = [
                'lot_number' => $lNum,
                'ref_number' => $rNum,
                'qty'        => $qVal,
                'ng_fixed'   => $isFixed
            ];
        }
    }

    $lotCount = count($cleanLots);
    if ($lotCount === 0) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Tidak ada data lot yang valid']);
        exit;
    }

    // 4. Pembagian Qty: Jika qty = 0, bagi rata sisa target Kanban
    $totalSpecifiedQty = 0;
    $unspecifiedIndices = [];

    foreach ($cleanLots as $idx => $lot) {
        if ($lot['qty'] > 0) {
            $totalSpecifiedQty += $lot['qty'];
        } else {
            $unspecifiedIndices[] = $idx;
        }
    }

    $unspecifiedCount = count($unspecifiedIndices);
    if ($unspecifiedCount > 0) {
        $leftoverToDistribute = max(0, $remainingQty - $totalSpecifiedQty);
        $baseQtyPerLot = (int)floor($leftoverToDistribute / $unspecifiedCount);
        $remainder = $leftoverToDistribute % $unspecifiedCount;

        foreach ($unspecifiedIndices as $i => $uIdx) {
            $cleanLots[$uIdx]['qty'] = $baseQtyPerLot + ($i < $remainder ? 1 : 0);
        }
    }

    // Hitung total akumulasi qty lot
    $actualScannedQty = 0;
    foreach ($cleanLots as $lot) {
        $actualScannedQty += $lot['qty'];
    }

    // 5. Lookup Master Part ID
    $partId = null;
    $stmtP = $pdo->prepare("SELECT id FROM master_parts WHERE part_code = :pcode LIMIT 1");
    $stmtP->execute([':pcode' => $kRow['item_code']]);
    $partRow = $stmtP->fetch(PDO::FETCH_ASSOC);
    if ($partRow) {
        $partId = (int)$partRow['id'];
    }

    // 6. Tentukan Standar AQL Sample Size
    $sampleSize = 80;
    $rejectNumber = 1;
    try {
        $stmtAql = $pdo->prepare("SELECT sample_size, reject_number FROM aql_standards WHERE inspection_level IN ('G-II', 'Level II', 'II') AND :qty >= qty_min AND :qty <= qty_max LIMIT 1");
        $stmtAql->execute([':qty' => $actualScannedQty]);
        $aqlRow = $stmtAql->fetch(PDO::FETCH_ASSOC);
        if ($aqlRow) {
            $sampleSize = (int)$aqlRow['sample_size'];
            $rejectNumber = (int)$aqlRow['reject_number'];
        }
    } catch (Exception $eAql) {}

    // 7. Buat Sesi Inspeksi Passed
    $now = date('Y-m-d H:i:s');
    $sessionRemarks = 'Tutup Kanban (Multi-Lot Fisik: ' . $lotCount . ' Lot)';
    if (!empty($remarks)) {
        $sessionRemarks .= ' - ' . $remarks;
    }

    $insSessSql = "
        INSERT INTO inspection_sessions (
            kanban_item_id, part_id, inspector_id, inspection_type,
            started_at, closed_at, status, total_scanned_qty,
            sample_size, reject_number, samples_checked, ng_count,
            excess_qty, approval_notes, created_at, updated_at
        ) VALUES (
            :kid, :pid, :uid, 'kanban',
            :started_at, :closed_at, 'passed', :scanned_qty,
            :sample_size, :reject_number, :samples_checked, 0,
            0, :notes, :created_at, :updated_at
        )
    ";
    $stmtInsSess = $pdo->prepare($insSessSql);
    $stmtInsSess->execute([
        ':kid'             => $kanbanId,
        ':pid'             => $partId,
        ':uid'             => $user['id'],
        ':started_at'      => $now,
        ':closed_at'       => $now,
        ':scanned_qty'     => $actualScannedQty,
        ':sample_size'     => $sampleSize,
        ':reject_number'   => $rejectNumber,
        ':samples_checked' => $sampleSize,
        ':notes'           => $sessionRemarks,
        ':created_at'      => $now,
        ':updated_at'      => $now
    ]);
    $sessionId = (int)$pdo->lastInsertId();

    // 8. Masukkan Data Lot ke inspection_session_lots
    $insLotSql = "
        INSERT INTO inspection_session_lots (
            inspection_session_id, lot_number, ref_number, qty,
            sample_size, ng_count, lot_result, lot_status, remarks,
            created_at, updated_at
        ) VALUES (
            :sid, :lot_no, :ref_no, :qty,
            :sample_sz, 0, 'passed', 'completed', :lot_rem,
            :created_at, :updated_at
        )
    ";
    $stmtInsLot = $pdo->prepare($insLotSql);

    foreach ($cleanLots as $lot) {
        $lotSampleEst = max(2, (int)round(($lot['qty'] / max(1, $actualScannedQty)) * $sampleSize));
        $lotRem = 'Tutup Kanban Langsung';
        if ($lot['ng_fixed'] === 1) {
            $lotRem .= ' (NG Sebelumnya Sudah Diperbaiki/Fix)';
        }

        $stmtInsLot->execute([
            ':sid'        => $sessionId,
            ':lot_no'     => $lot['lot_number'],
            ':ref_no'     => !empty($lot['ref_number']) ? $lot['ref_number'] : null,
            ':qty'        => $lot['qty'],
            ':sample_sz'  => $lotSampleEst,
            ':lot_rem'    => $lotRem,
            ':created_at' => $now,
            ':updated_at' => $now
        ]);
    }

    // 9. Update status Kanban menjadi completed
    $stmtUpdK = $pdo->prepare("UPDATE kanban_items SET status = 'completed', updated_at = NOW() WHERE id = :kid");
    $stmtUpdK->execute([':kid' => $kanbanId]);

    $pdo->commit();

    // Sinkronisasi status & ringkasan jika fungsi pembantu tersedia
    if (function_exists('syncKanbanStatus')) {
        syncKanbanStatus($pdo, $kanbanId);
    }
    if (function_exists('syncDailySummaryForDate')) {
        syncDailySummaryForDate($pdo, date('Y-m-d'));
    }

    echo json_encode([
        'success'    => true,
        'message'    => "Kanban #{$kRow['kanban_no']} berhasil ditutup dengan {$lotCount} lot fisik.",
        'session_id' => $sessionId
    ]);

} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()
    ]);
}
