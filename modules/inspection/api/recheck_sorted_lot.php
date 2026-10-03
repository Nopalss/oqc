<?php
/**
 * API: Recheck Sorted Lot (Opsi A: Sortir & Ganti Part NG Per-Lot)
 * Menyetel ulang lot yang telah disortir ke status pemeriksaan (in_progress) dengan label tetap sama.
 * 
 * PT. Surya Technology Industri — OQC System
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/helper.php';
require_once __DIR__ . '/../../../config/summary_helper.php';

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
$notes        = trim(sanitize($jsonBody['notes'] ?? ($_POST['notes'] ?? '')));

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

    if ($lot['lot_status'] === 'replaced') {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Lot ini sudah digantikan oleh box lain dan tidak dapat disortir']);
        exit;
    }

    $sessionId = (int)$lot['session_id'];

    // 2. Tandai catatan temuan NG sebelumnya sebagai telah disortir (is_sorted = 1)
    // Opsi 1: is_cancelled TETAP 0 agar data defect tetap tercatat di Analisis & Tren Defect (Defect Analysis) & PPM!
    $cleanReason = 'Part NG disortir dan ditukar operator' . (!empty($notes) ? ": {$notes}" : '');
    $stmtSortNg = $pdo->prepare("
        UPDATE inspection_ng_records
        SET is_sorted = 1,
            sort_notes = :notes,
            sorted_at = NOW(),
            cancel_reason = :reason,
            is_cancelled = 0
        WHERE session_lot_id = :slot_id AND (is_cancelled IS NULL OR is_cancelled = 0)
    ");
    $stmtSortNg->execute([
        ':notes'   => !empty($notes) ? $notes : 'Part NG disortir dan ditukar operator dengan part bagus',
        ':reason'  => $cleanReason,
        ':slot_id' => $sessionLotId
    ]);

    // 3. Kembalikan status lot ke 'in_progress' dan lot_status ke 'reinspected'
    $stmtUpdLot = $pdo->prepare("
        UPDATE inspection_session_lots
        SET lot_result = 'in_progress',
            lot_status = 'reinspected',
            ng_count = 0,
            closed_at = NULL,
            action_noted_at = NOW()
        WHERE id = :slot_id
    ");
    $stmtUpdLot->execute([':slot_id' => $sessionLotId]);

    // 4. Catat ke lot_substitution_log
    $userId = (int)($_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? 0)) ?: null;
    $logNote = "Lot #{$lot['lot_number']} (Ref: {$lot['ref_number']}) disortir part NG-nya dan diuji ulang sampel fisik." . (!empty($notes) ? " Catatan: {$notes}" : '');

    $stmtSubst = $pdo->prepare("
        INSERT INTO lot_substitution_log (
            original_session_id, ng_session_lot_id, replacement_session_lot_id,
            action_type, actioned_by, notes, created_at
        ) VALUES (
            :sid, :old_id, :rep_id,
            'sort_reinspect', :uid, :notes, NOW()
        )
    ");
    $stmtSubst->execute([
        ':sid'    => $sessionId,
        ':old_id' => $sessionLotId,
        ':rep_id' => $sessionLotId,
        ':uid'    => $userId,
        ':notes'  => $logNote
    ]);

    // 5. Update status sesi menjadi 'in_progress'
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

    if (function_exists('syncDailySummaryForSession')) {
        syncDailySummaryForSession($pdo, $sessionId);
    }

    echo json_encode([
        'success'        => true,
        'message'        => "Lot #{$lot['lot_number']} telah disortir dan siap diuji ulang sampel fisiknya.",
        'session_id'     => $sessionId,
        'session_lot_id' => $sessionLotId
    ]);
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()
    ]);
    exit;
}
