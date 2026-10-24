<?php
/**
 * API: Cleanup Uninspected Lots / Sesi Keluar (Workbench Exit Handler)
 * 
 * Menangani pembersihan saat inspektur keluar atau menutup tab:
 * 1. Jika 0 box diinspeksi: Batalkan dan hapus seluruh sesi & bebaskan semua label QR.
 * 2. Jika sebagian box sudah diinspeksi (Passed/NG):
 *    - Pertahankan box yang sudah selesai diinspeksi.
 *    - Hapus & bebaskan HANYA box yang masih berstatus 'in_progress'.
 *    - Hitung ulang total_scanned_qty, AQL sample size, dan sinkronisasi kuota Kanban / Daily Summary.
 * 3. Jika seluruh box sudah selesai: Tidak ada yang dihapus.
 * 
 * PT. Surya Technology Industri — OQC System
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/helper.php';
require_once __DIR__ . '/../../../config/summary_helper.php';

// Release PHP session lock immediately so parallel page unloads / beacons don't block subsequent requests
session_write_close();

// Mendukung POST biasa (FormData / JSON) maupun sendBeacon (POST raw / form-urlencoded)
$rawInput = file_get_contents('php://input');
$jsonBody = json_decode($rawInput, true) ?: [];

$sessionId = (int)($_POST['session_id'] ?? $jsonBody['session_id'] ?? 0);

if ($sessionId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Session ID tidak valid.']);
    exit;
}

$pdo = getDB();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Koneksi database server gagal.']);
    exit;
}

// Security: User harus login (sendBeacon tetap membawa cookie session PHP otomatis)
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sesi login tidak valid atau berakhir.']);
    exit;
}

$currentUser = current_user();
$currentUserId = (int)($currentUser['id'] ?? 0);
$currentUserRole = strtolower(trim((string)($currentUser['role'] ?? '')));
$isPrivilegedUser = in_array($currentUserRole, ['admin', 'chief', 'supervisor', 'manager', 'leader'], true);

try {
    $pdo->beginTransaction();

    // 1. Ambil data sesi inspeksi dengan lock
    $stmtSess = $pdo->prepare("
        SELECT s.*, did.part_code, did.part_name, p.aql_level as part_aql_level
        FROM inspection_sessions s
        JOIN daily_inspection_data did ON did.id = s.did_id
        LEFT JOIN master_parts p ON p.id = s.part_id
        WHERE s.id = :id
        FOR UPDATE
    ");
    $stmtSess->execute([':id' => $sessionId]);
    $session = $stmtSess->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Sesi inspeksi tidak ditemukan.']);
        exit;
    }

    // Ownership check (IDOR Protection): Hanya pemilik sesi atau user privileged
    if (!empty($session['inspector_id']) && (int)$session['inspector_id'] !== $currentUserId && !$isPrivilegedUser) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki hak untuk memodifikasi sesi inspektur lain.']);
        exit;
    }

    $sessionStatus = (string)($session['status'] ?? '');
    $kanbanItemId  = (int)($session['kanban_item_id'] ?? 0);
    $sessionDate   = !empty($session['started_at']) ? date('Y-m-d', strtotime($session['started_at'])) : date('Y-m-d');

    // Jika sesi sudah final selesai (passed / rejected), tidak perlu pembersihan
    if (in_array($sessionStatus, ['passed', 'rejected'], true)) {
        $pdo->commit();
        echo json_encode([
            'success' => true,
            'action' => 'none',
            'message' => 'Sesi sudah selesai diinspeksi. Tidak ada box yang dibatalkan.'
        ]);
        exit;
    }

    // 2. Ambil seluruh lot di sesi ini
    $stmtLots = $pdo->prepare("
        SELECT id, lot_number, ref_number, qty, lot_result, lot_status, ng_count
        FROM inspection_session_lots
        WHERE inspection_session_id = :sid
        ORDER BY id ASC
        FOR UPDATE
    ");
    $stmtLots->execute([':sid' => $sessionId]);
    $allLots = $stmtLots->fetchAll(PDO::FETCH_ASSOC);

    $completedLots = [];
    $pendingLots   = [];

    foreach ($allLots as $l) {
        $res = strtolower(trim((string)($l['lot_result'] ?? 'in_progress')));
        $stat = strtolower(trim((string)($l['lot_status'] ?? 'ok')));

        // Dianggap selesai jika: passed, rejected, skipped, atau statusnya bukan ok biasa
        if (in_array($res, ['passed', 'rejected', 'skipped'], true) || in_array($stat, ['ng_found', 'ng_quarantine', 'replaced', 'reinspected'], true) || (int)($l['ng_count'] ?? 0) > 0) {
            $completedLots[] = $l;
        } else {
            $pendingLots[] = $l;
        }
    }

    // =========================================================================
    // KASUS 1: BELUM ADA BOX YANG DIINSPEKSI SAMA SEKALI (0 Selesai)
    // =========================================================================
    if (empty($completedLots)) {
        // Kembalikan alokasi Safety Stock jika ada
        $stmtRestoreSS = $pdo->prepare("
            UPDATE inspection_sessions 
            SET auto_fulfilled_by_session_id = NULL 
            WHERE auto_fulfilled_by_session_id = :sid
        ");
        $stmtRestoreSS->execute([':sid' => $sessionId]);

        // Hapus detail NG
        $pdo->prepare("DELETE FROM inspection_ng_records WHERE inspection_session_id = :sid")
            ->execute([':sid' => $sessionId]);

        // Hapus sample
        $pdo->prepare("DELETE FROM inspection_samples WHERE inspection_session_id = :sid")
            ->execute([':sid' => $sessionId]);

        // Hapus seluruh lot
        $pdo->prepare("DELETE FROM inspection_session_lots WHERE inspection_session_id = :sid")
            ->execute([':sid' => $sessionId]);

        // Hapus sesi
        $pdo->prepare("DELETE FROM inspection_sessions WHERE id = :sid")
            ->execute([':sid' => $sessionId]);

        $pdo->commit();

        // Sinkronisasi data agregat & kanban
        if (function_exists('syncDailySummaryForDate')) {
            syncDailySummaryForDate($pdo, $sessionDate);
        }
        if ($kanbanItemId > 0 && function_exists('syncKanbanStatus')) {
            syncKanbanStatus($pdo, $kanbanItemId);
        }

        echo json_encode([
            'success' => true,
            'action' => 'session_deleted',
            'message' => 'Sesi belum mulai diinspeksi. Sesi telah dibatalkan dan seluruh label box telah dibebaskan kembali.'
        ]);
        exit;
    }

    // =========================================================================
    // KASUS 2: SEMUA BOX SUDAH SELESAI (Tidak ada pending)
    // =========================================================================
    if (empty($pendingLots)) {
        $pdo->commit();
        echo json_encode([
            'success' => true,
            'action' => 'none',
            'message' => 'Seluruh box telah selesai diinspeksi. Tidak ada perubahan.'
        ]);
        exit;
    }

    // =========================================================================
    // KASUS 3: SEBAGIAN SELESAI & SEBAGIAN PENDING (Multi-Lot Parsial)
    // =========================================================================
    $pendingLotIds = array_map(function($pl) { return (int)$pl['id']; }, $pendingLots);
    $inPendingIds = implode(',', $pendingLotIds);

    // Hapus relasi NG record tak disengaja pada pending lots jika ada
    $pdo->query("DELETE FROM inspection_ng_records WHERE session_lot_id IN ($inPendingIds)");

    // Hapus pending lots saja
    $pdo->query("DELETE FROM inspection_session_lots WHERE id IN ($inPendingIds)");

    // Hitung ulang total Qty dari box-box yang tersisa (yang sudah diinspeksi)
    $newScannedQty = 0;
    $newNgCount = 0;
    $hasNgLot = false;

    foreach ($completedLots as $cl) {
        $newScannedQty += (int)($cl['qty'] ?? 0);
        $newNgCount += (int)($cl['ng_count'] ?? 0);
        if (($cl['lot_result'] ?? '') === 'rejected' || ($cl['lot_status'] ?? '') === 'ng_found') {
            $hasNgLot = true;
        }
    }

    // Hitung AQL baru berdasarkan Qty fisik yang sudah selesai
    $partAqlLvl = !empty($session['part_aql_level']) ? $session['part_aql_level'] : 'G-II';
    $calcAqlQty = max(1, $newScannedQty);
    $stmtAql = $pdo->prepare("SELECT sample_code, sample_size, accept_number, reject_number FROM aql_standards WHERE inspection_level = :lvl AND :qty BETWEEN qty_min AND qty_max LIMIT 1");
    $stmtAql->execute([':lvl' => $partAqlLvl, ':qty' => $calcAqlQty]);
    $aqlData = $stmtAql->fetch(PDO::FETCH_ASSOC);

    $newSampleSize = $aqlData ? (int)$aqlData['sample_size'] : (int)($session['sample_size'] ?? 0);
    $newRejectNumber = $aqlData ? (int)$aqlData['reject_number'] : (int)($session['reject_number'] ?? 1);

    // Tentukan status sesi:
    // Jika ada box NG -> rejected
    // Jika semua box tersisa passed -> passed
    $newStatus = $hasNgLot ? 'rejected' : 'passed';

    $stmtUpdSess = $pdo->prepare("
        UPDATE inspection_sessions
        SET total_scanned_qty = :qty,
            sample_size = :ssize,
            reject_number = :rejno,
            ng_count = :ngcnt,
            status = :status,
            closed_at = NOW()
        WHERE id = :sid
    ");
    $stmtUpdSess->execute([
        ':qty'   => $newScannedQty,
        ':ssize' => $newSampleSize,
        ':rejno' => $newRejectNumber,
        ':ngcnt' => $newNgCount,
        ':status'=> $newStatus,
        ':sid'   => $sessionId
    ]);

    $pdo->commit();

    // Sinkronisasi Kanban & Summary
    if (function_exists('syncDailySummaryForDate')) {
        syncDailySummaryForDate($pdo, $sessionDate);
    }
    if ($kanbanItemId > 0 && function_exists('syncKanbanStatus')) {
        syncKanbanStatus($pdo, $kanbanItemId);
    }

    echo json_encode([
        'success'           => true,
        'action'            => 'uninspected_lots_cleared',
        'completed_count'   => count($completedLots),
        'cleared_count'     => count($pendingLots),
        'new_total_qty'     => $newScannedQty,
        'message'           => count($completedLots) . ' box yang sudah diinspeksi tetap disimpan. ' . count($pendingLots) . ' box yang belum diinspeksi telah dibatalkan & labelnya dibebaskan.'
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Gagal memproses pembersihan sesi: ' . $e->getMessage()
    ]);
}
