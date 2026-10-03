<?php
/**
 * API: Cancel Sort Lot (Pembatalan Tindakan Sortir / Re-Inspeksi Box Lama)
 * Mengembalikan lot yang berstatus reinspected kembali ke status REJECTED (NG).
 * 
 * PT. Surya Technology Industri — OQC System
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Metode permintaan tidak valid']);
    exit;
}

$pdo = getDB();
if (!$pdo) {
    echo json_encode(['success' => false, 'message' => 'Gagal terhubung ke database server']);
    exit;
}

$rawInput = file_get_contents('php://input');
$jsonBody = json_decode($rawInput, true) ?: [];

$sessionLotId = (int)($jsonBody['session_lot_id'] ?? ($_POST['session_lot_id'] ?? 0));

if ($sessionLotId <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID Lot tidak valid']);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Ambil data lot & sesi
    $stmtLot = $pdo->prepare("
        SELECT isl.*, s.id as session_id, s.status as session_status
        FROM inspection_session_lots isl
        JOIN inspection_sessions s ON s.id = isl.inspection_session_id
        WHERE isl.id = :id
        FOR UPDATE
    ");
    $stmtLot->execute([':id' => $sessionLotId]);
    $lot = $stmtLot->fetch(PDO::FETCH_ASSOC);

    if (!$lot) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Data lot tidak ditemukan']);
        exit;
    }

    if ($lot['lot_status'] !== 'reinspected') {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Lot ini tidak sedang dalam status Hasil Sortir / Re-Inspeksi']);
        exit;
    }

    $sessionId = (int)$lot['session_id'];

    // 2. Kembalikan catatan defect yang sebelumnya ditandai is_sorted = 1
    $stmtRevertNg = $pdo->prepare("
        UPDATE inspection_ng_records
        SET is_sorted = 0,
            sort_notes = NULL,
            sorted_at = NULL,
            cancel_reason = NULL
        WHERE session_lot_id = :slot_id AND is_sorted = 1
    ");
    $stmtRevertNg->execute([':slot_id' => $sessionLotId]);

    // 3. Hitung total defect aktif pada lot ini
    $stmtNgCount = $pdo->prepare("
        SELECT COALESCE(SUM(qty_ng), 0) as total_ng
        FROM inspection_ng_records
        WHERE session_lot_id = :slot_id AND (is_cancelled IS NULL OR is_cancelled = 0)
    ");
    $stmtNgCount->execute([':slot_id' => $sessionLotId]);
    $totalNg = (int)$stmtNgCount->fetchColumn();

    // 4. Kembalikan status lot ke REJECTED (ng_found)
    $stmtUpdLot = $pdo->prepare("
        UPDATE inspection_session_lots
        SET lot_result = 'rejected',
            lot_status = 'ng_found',
            ng_count = :ng_cnt,
            closed_at = NOW(),
            action_noted_at = NULL
        WHERE id = :slot_id
    ");
    $stmtUpdLot->execute([
        ':ng_cnt'  => $totalNg,
        ':slot_id' => $sessionLotId
    ]);

    // 5. Hapus log tindakan sortir dari lot_substitution_log
    $stmtDelLog = $pdo->prepare("
        DELETE FROM lot_substitution_log
        WHERE original_session_id = :sid 
          AND ng_session_lot_id = :slot_id 
          AND action_type = 'sort_reinspect'
    ");
    $stmtDelLog->execute([
        ':sid'     => $sessionId,
        ':slot_id' => $sessionLotId
    ]);

    // 6. Hitung ulang agregat sesi inspection_sessions
    $stmtAggr = $pdo->prepare("
        SELECT 
            COALESCE(SUM(CASE 
                WHEN (lot_status != 'replaced' AND lot_result IN ('passed', 'rejected')) 
                     OR lot_status IN ('replaced', 'reinspected') 
                THEN sample_size 
                ELSE 0 
            END), 0) as checked_samples,
            COALESCE(SUM(sample_size), 0) as total_samples,
            (SELECT COALESCE(SUM(qty_ng), 0) FROM inspection_ng_records WHERE inspection_session_id = :sid_ng AND (is_cancelled IS NULL OR is_cancelled = 0)) as total_ng
        FROM inspection_session_lots
        WHERE inspection_session_id = :sid
    ");
    $stmtAggr->execute([':sid' => $sessionId, ':sid_ng' => $sessionId]);
    $aggr = $stmtAggr->fetch(PDO::FETCH_ASSOC);

    $stmtUpdSess = $pdo->prepare("
        UPDATE inspection_sessions
        SET samples_checked = :chk_samples,
            sample_size     = :tot_samples,
            ng_count        = :tot_ng,
            status          = 'in_progress',
            closed_at       = NULL
        WHERE id = :sid
    ");
    $stmtUpdSess->execute([
        ':chk_samples' => (int)$aggr['checked_samples'],
        ':tot_samples' => (int)$aggr['total_samples'],
        ':tot_ng'      => (int)$aggr['total_ng'],
        ':sid'         => $sessionId
    ]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Tindakan sortir dibatalkan. Lot #' . $lot['lot_number'] . ' dikembalikan ke status DITOLAK (REJECTED).'
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()]);
}
