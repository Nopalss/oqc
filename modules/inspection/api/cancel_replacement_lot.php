<?php
/**
 * API: Cancel Replacement Lot (Pembatalan Box Pengganti)
 * Membatalkan pendaftaran box pengganti dan mengembalikan box asli yang digantikan ke status REJECTED (NG).
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

$replacementLotId = (int)($jsonBody['replacement_lot_id'] ?? ($_POST['replacement_lot_id'] ?? 0));
$sessionLotId     = (int)($jsonBody['session_lot_id'] ?? ($_POST['session_lot_id'] ?? 0));

$targetLotId = $replacementLotId ?: $sessionLotId;
if ($targetLotId <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID Lot pengganti tidak valid']);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Cari data lot target
    $stmtFind = $pdo->prepare("
        SELECT isl.*, s.id as session_id, s.status as session_status
        FROM inspection_session_lots isl
        JOIN inspection_sessions s ON s.id = isl.inspection_session_id
        WHERE isl.id = :id
        FOR UPDATE
    ");
    $stmtFind->execute([':id' => $targetLotId]);
    $targetLot = $stmtFind->fetch(PDO::FETCH_ASSOC);

    if (!$targetLot) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Data lot tidak ditemukan']);
        exit;
    }

    $sessionId = (int)$targetLot['session_id'];

    $origLot = null;
    $repLot  = null;

    // Tentukan apakah targetLotId adalah Box Pengganti atau Box Asli
    if ($targetLot['lot_status'] === 'replaced') {
        // Target adalah Box Asli (yang berstatus replaced)
        $origLot = $targetLot;
        $repLotId = (int)($targetLot['replaced_by_lot_id'] ?? 0);
        if ($repLotId > 0) {
            $stmtRep = $pdo->prepare("SELECT * FROM inspection_session_lots WHERE id = :id FOR UPDATE");
            $stmtRep->execute([':id' => $repLotId]);
            $repLot = $stmtRep->fetch(PDO::FETCH_ASSOC);
        }
    } else {
        // Asumsikan target adalah Box Pengganti
        $repLot = $targetLot;
        $stmtOrig = $pdo->prepare("SELECT * FROM inspection_session_lots WHERE replaced_by_lot_id = :rep_id FOR UPDATE");
        $stmtOrig->execute([':rep_id' => $targetLotId]);
        $origLot = $stmtOrig->fetch(PDO::FETCH_ASSOC);
    }

    if (!$origLot || !$repLot) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Hubungan antara box pengganti dan box asli tidak ditemukan']);
        exit;
    }

    $repLotId  = (int)$repLot['id'];
    $origLotId = (int)$origLot['id'];

    // 2. Hapus defect records yang mungkin sempat diinput di Box Pengganti
    $stmtDelRepNg = $pdo->prepare("DELETE FROM inspection_ng_records WHERE session_lot_id = :rep_id");
    $stmtDelRepNg->execute([':rep_id' => $repLotId]);

    // 3. Hapus baris Box Pengganti dari tabel inspection_session_lots
    $stmtDelRepLot = $pdo->prepare("DELETE FROM inspection_session_lots WHERE id = :rep_id");
    $stmtDelRepLot->execute([':rep_id' => $repLotId]);

    // 4. Hitung total defect asli pada Box Asli
    $stmtNgOrig = $pdo->prepare("
        SELECT COALESCE(SUM(qty_ng), 0) as total_ng
        FROM inspection_ng_records
        WHERE session_lot_id = :orig_id AND (is_cancelled IS NULL OR is_cancelled = 0)
    ");
    $stmtNgOrig->execute([':orig_id' => $origLotId]);
    $origNgCount = (int)$stmtNgOrig->fetchColumn();

    // 5. Kembalikan Box Asli ke status REJECTED (ng_found)
    $stmtRevertOrig = $pdo->prepare("
        UPDATE inspection_session_lots
        SET lot_result = 'rejected',
            lot_status = 'ng_found',
            replaced_by_lot_id = NULL,
            ng_count = :ng_cnt,
            closed_at = NOW(),
            action_noted_at = NULL
        WHERE id = :orig_id
    ");
    $stmtRevertOrig->execute([
        ':ng_cnt'  => $origNgCount,
        ':orig_id' => $origLotId
    ]);

    // 6. Bersihkan riwayat di tabel lot_substitution_log
    $stmtDelLog = $pdo->prepare("
        DELETE FROM lot_substitution_log
        WHERE (ng_session_lot_id = :orig_id AND replacement_session_lot_id = :rep_id)
           OR (original_session_id = :sid AND replacement_session_lot_id = :rep_id2)
    ");
    $stmtDelLog->execute([
        ':orig_id'  => $origLotId,
        ':rep_id'   => $repLotId,
        ':sid'      => $sessionId,
        ':rep_id2'  => $repLotId
    ]);

    // 7. Hitung ulang agregat sesi inspection_sessions
    $stmtAggr = $pdo->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN lot_status != 'replaced' THEN qty ELSE 0 END), 0) as total_scanned_qty,
            COALESCE(SUM(CASE 
                WHEN (lot_status != 'replaced' AND lot_result IN ('passed', 'rejected')) 
                     OR lot_status IN ('replaced', 'reinspected') 
                THEN sample_size 
                ELSE 0 
            END), 0) as checked_samples,
            COALESCE(SUM(sample_size), 0) as total_samples,
            (SELECT COALESCE(SUM(qty_ng), 0) FROM inspection_ng_records WHERE inspection_session_id = :sid_ng AND (is_cancelled IS NULL OR is_cancelled = 0)) as total_ng,
            SUM(CASE WHEN lot_status != 'replaced' AND lot_result = 'in_progress' THEN 1 ELSE 0 END) as in_progress_lots
        FROM inspection_session_lots
        WHERE inspection_session_id = :sid
    ");
    $stmtAggr->execute([':sid' => $sessionId, ':sid_ng' => $sessionId]);
    $aggr = $stmtAggr->fetch(PDO::FETCH_ASSOC);

    $stmtUpdSess = $pdo->prepare("
        UPDATE inspection_sessions
        SET total_scanned_qty = :tot_qty,
            samples_checked   = :chk_samples,
            sample_size       = :tot_samples,
            ng_count          = :tot_ng,
            status            = 'in_progress',
            closed_at         = NULL
        WHERE id = :sid
    ");
    $stmtUpdSess->execute([
        ':tot_qty'     => (int)$aggr['total_scanned_qty'],
        ':chk_samples' => (int)$aggr['checked_samples'],
        ':tot_samples' => (int)$aggr['total_samples'],
        ':tot_ng'      => (int)$aggr['total_ng'],
        ':sid'         => $sessionId
    ]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Box Pengganti #' . ($repLot['lot_number'] ?? '-') . ' berhasil dibatalkan. Lot #' . $origLot['lot_number'] . ' dikembalikan ke status DITOLAK (REJECTED).'
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()]);
}
