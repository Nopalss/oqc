<?php
/**
 * API: Remove Session Lot (Batal Pakai / Hapus Box dari Sesi)
 * Menghapus pendaftaran lot/box tertentu dari sesi inspeksi yang sedang berlangsung (in_progress).
 * Membebaskan label barcode/ref_number sehingga dapat digunakan kembali pada Kanban/PC lain tanpa ditolak.
 *
 * PT. Surya Technology Industri — OQC System
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/helper.php';
require_once __DIR__ . '/../../../config/summary_helper.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sesi login Anda telah berakhir. Silakan login kembali.', 'unauthorized' => true]);
    exit;
}

$currentUser = current_user();
$currentUserId = (int)$currentUser['id'];
$currentUserRole = $currentUser['role'];
$isPrivilegedUser = in_array($currentUserRole, ['admin', 'chief', 'supervisor', 'manager'], true);

// Release PHP session lock immediately so parallel AJAX / polling requests are not blocked
session_write_close();

$pdo = getDB();
if (!$pdo) {
    echo json_encode(['success' => false, 'message' => 'Koneksi database gagal']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Metode request tidak valid']);
    exit;
}

$rawInput = file_get_contents('php://input');
$jsonData = json_decode($rawInput, true) ?: [];

$sessionLotId = (int)($jsonData['session_lot_id'] ?? ($_POST['session_lot_id'] ?? 0));

if ($sessionLotId <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID Lot/Box tidak valid']);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Ambil data lot dan sesi terkait
    $stmtLot = $pdo->prepare("
        SELECT isl.*, s.id as session_id, s.kanban_item_id, s.status as session_status, s.inspection_type, s.inspector_id
        FROM inspection_session_lots isl
        JOIN inspection_sessions s ON s.id = isl.inspection_session_id
        WHERE isl.id = :id
        FOR UPDATE
    ");
    $stmtLot->execute([':id' => $sessionLotId]);
    $lot = $stmtLot->fetch(PDO::FETCH_ASSOC);

    if (!$lot) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Data lot tidak ditemukan dalam sistem']);
        exit;
    }

    // Ownership check (IDOR protection)
    if (!empty($lot['inspector_id']) && (int)$lot['inspector_id'] !== $currentUserId && !$isPrivilegedUser) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki hak untuk menghapus lot pada sesi inspektur lain.']);
        exit;
    }

    $sessionId     = (int)$lot['session_id'];
    $kanbanItemId  = (int)($lot['kanban_item_id'] ?? 0);
    $sessionStatus = $lot['session_status'] ?? '';
    $lotNo         = $lot['lot_number'] ?? '-';
    $refNo         = $lot['ref_number'] ?? '';

    // 2. Validasi status sesi: Hanya boleh dihapus jika sesi masih in_progress
    if ($sessionStatus !== 'in_progress') {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'message' => 'Hanya sesi yang masih berstatus "Sedang Diinspeksi" yang dapat diubah atau dihapus box-nya.'
        ]);
        exit;
    }

    // 3. Pengecekan jumlah box dalam sesi:
    // Jika ini adalah satu-satunya box dalam sesi, membatalkan box ini akan otomatis membatalkan seluruh sesi in-progress!
    $stmtCount = $pdo->prepare("
        SELECT COUNT(*) 
        FROM inspection_session_lots 
        WHERE inspection_session_id = :sid
    ");
    $stmtCount->execute([':sid' => $sessionId]);
    $totalLotsInSession = (int)$stmtCount->fetchColumn();

    if ($totalLotsInSession <= 1) {
        // A. Kembalikan / Restore alokasi Safety Stock jika sesi ini pernah mengonsumsi Safety Stock
        $stmtRestoreSS = $pdo->prepare("
            UPDATE inspection_sessions 
            SET auto_fulfilled_by_session_id = NULL 
            WHERE auto_fulfilled_by_session_id = :sid
        ");
        $stmtRestoreSS->execute([':sid' => $sessionId]);

        // Cek juga jika ada Safety Stock yang dialokasikan via part/ref
        if (!empty($refNo)) {
            $stmtRestoreRefSS = $pdo->prepare("
                UPDATE inspection_sessions s
                JOIN inspection_session_lots isl ON isl.inspection_session_id = s.id
                SET s.auto_fulfilled_by_session_id = NULL
                WHERE s.inspection_type = 'safety_stock'
                  AND s.auto_fulfilled_by_session_id = :sid
                  AND UPPER(isl.ref_number) = UPPER(:ref)
            ");
            $stmtRestoreRefSS->execute([':sid' => $sessionId, ':ref' => $refNo]);
        }

        // B. Hapus seluruh data terkait sesi in-progress ini
        $stmtDelNg = $pdo->prepare("DELETE FROM inspection_ng_records WHERE inspection_session_id = :sid");
        $stmtDelNg->execute([':sid' => $sessionId]);

        $stmtDelSamples = $pdo->prepare("DELETE FROM inspection_samples WHERE inspection_session_id = :sid");
        $stmtDelSamples->execute([':sid' => $sessionId]);

        $stmtDelLots = $pdo->prepare("DELETE FROM inspection_session_lots WHERE inspection_session_id = :sid");
        $stmtDelLots->execute([':sid' => $sessionId]);

        $stmtDelSess = $pdo->prepare("DELETE FROM inspection_sessions WHERE id = :sid");
        $stmtDelSess->execute([':sid' => $sessionId]);

        // C. Sinkronisasi status Kanban
        if ($kanbanItemId > 0 && function_exists('syncKanbanStatus')) {
            syncKanbanStatus($pdo, $kanbanItemId);
        }

        $sessionDate = !empty($lot['created_at']) ? date('Y-m-d', strtotime($lot['created_at'])) : date('Y-m-d');
        if (function_exists('syncDailySummaryForDate')) {
            syncDailySummaryForDate($pdo, $sessionDate);
        }

        $pdo->commit();

        $refStr = !empty($refNo) ? " (Ref: {$refNo})" : "";
        echo json_encode([
            'success'           => true,
            'session_deleted'   => true,
            'message'           => "Box Lot #{$lotNo}{$refStr} adalah box terakhir pada sesi ini. Box berhasil dibatalkan dan seluruh sesi telah dibersihkan. Barcode/label bebas digunakan kembali.",
            'session_id'        => $sessionId,
            'removed_lot_id'    => $sessionLotId,
            'remaining_lots'    => 0,
            'total_scanned_qty' => 0,
            'session_status'    => 'deleted',
            'redirect_url'      => base_url('modules/inspection/index.php')
        ]);
        exit;
    }

    // 4. Validasi jika lot berstatus diganti atau merupakan box pengganti
    if (($lot['lot_status'] ?? '') === 'replaced') {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'message' => 'Box ini telah digantikan oleh box lain. Silakan batalkan box pengganti terlebih dahulu jika ingin mengelolanya.'
        ]);
        exit;
    }

    $stmtIsRep = $pdo->prepare("SELECT id FROM inspection_session_lots WHERE replaced_by_lot_id = :lid LIMIT 1");
    $stmtIsRep->execute([':lid' => $sessionLotId]);
    if ($stmtIsRep->fetch()) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'message' => 'Box ini adalah box pengganti untuk box yang reject. Silakan gunakan tombol "Batal Box Pengganti" pada kartu box.'
        ]);
        exit;
    }

    // 5. Hapus catatan temuan defect (NG records) untuk lot ini
    $stmtDelNg = $pdo->prepare("
        DELETE FROM inspection_ng_records 
        WHERE session_lot_id = :lot_id
    ");
    $stmtDelNg->execute([':lot_id' => $sessionLotId]);

    // 6. Hapus baris lot dari inspection_session_lots
    $stmtDelLot = $pdo->prepare("
        DELETE FROM inspection_session_lots 
        WHERE id = :lot_id
    ");
    $stmtDelLot->execute([':lot_id' => $sessionLotId]);

    // 7. Hitung ulang agregat sesi di inspection_sessions
    $stmtAggr = $pdo->prepare("
        SELECT 
            COUNT(*) as total_lots,
            SUM(CASE WHEN lot_result = 'in_progress' AND (lot_status != 'replaced' OR lot_status IS NULL) THEN 1 ELSE 0 END) as in_progress_lots,
            SUM(CASE WHEN lot_result = 'rejected' AND (lot_status != 'replaced' OR lot_status IS NULL) THEN 1 ELSE 0 END) as rejected_lots,
            SUM(CASE WHEN lot_result = 'passed' AND (lot_status != 'replaced' OR lot_status IS NULL) THEN 1 ELSE 0 END) as passed_lots,
            SUM(CASE WHEN lot_result = 'skipped' AND (lot_status != 'replaced' OR lot_status IS NULL) THEN 1 ELSE 0 END) as skipped_lots,
            COALESCE(SUM(CASE 
                WHEN (lot_status != 'replaced' AND lot_result IN ('passed', 'rejected')) 
                     OR lot_status IN ('replaced', 'reinspected') 
                THEN sample_size 
                ELSE 0 
            END), 0) as checked_samples,
            COALESCE(SUM(sample_size), 0) as total_samples,
            (SELECT COALESCE(SUM(qty_ng), 0) FROM inspection_ng_records WHERE inspection_session_id = :sid_ng AND (is_cancelled IS NULL OR is_cancelled = 0)) as total_ng,
            COALESCE(SUM(CASE WHEN lot_status != 'replaced' OR lot_status IS NULL THEN qty ELSE 0 END), 0) as total_scanned_qty
        FROM inspection_session_lots
        WHERE inspection_session_id = :sid
    ");
    $stmtAggr->execute([':sid' => $sessionId, ':sid_ng' => $sessionId]);
    $aggr = $stmtAggr->fetch(PDO::FETCH_ASSOC);

    $remainingTotalLots  = (int)$aggr['total_lots'];
    $inProgressLots      = (int)$aggr['in_progress_lots'];
    $rejectedLots        = (int)$aggr['rejected_lots'];
    $checkedSamples      = (int)$aggr['checked_samples'];
    $totalSamples        = (int)$aggr['total_samples'];
    $totalNg             = (int)$aggr['total_ng'];
    $newTotalScannedQty  = (int)$aggr['total_scanned_qty'];

    // Status sesi: jika masih ada lot in_progress, tetap in_progress
    $newSessionStatus = ($inProgressLots > 0) ? 'in_progress' : ($rejectedLots > 0 ? 'rejected' : 'passed');
    $closedAtVal      = ($newSessionStatus !== 'in_progress') ? date('Y-m-d H:i:s') : null;

    $stmtUpdSess = $pdo->prepare("
        UPDATE inspection_sessions
        SET total_scanned_qty = :tot_qty,
            samples_checked   = :chk_samples,
            sample_size       = :tot_samples,
            ng_count          = :ng_cnt,
            status            = :st,
            closed_at         = CASE WHEN :cl_at IS NOT NULL THEN :cl_at2 ELSE closed_at END
        WHERE id = :sid
    ");
    $stmtUpdSess->execute([
        ':tot_qty'     => $newTotalScannedQty,
        ':chk_samples' => $checkedSamples,
        ':tot_samples' => $totalSamples,
        ':ng_cnt'      => $totalNg,
        ':st'          => $newSessionStatus,
        ':cl_at'       => $closedAtVal,
        ':cl_at2'      => $closedAtVal,
        ':sid'         => $sessionId
    ]);

    // 8. Sinkronisasi Kanban & Daily Summary jika terhubung
    if ($kanbanItemId > 0 && function_exists('syncKanbanStatus')) {
        syncKanbanStatus($pdo, $kanbanItemId);
    }
    if (function_exists('syncDailySummaryForSession')) {
        syncDailySummaryForSession($pdo, $sessionId);
    }

    $pdo->commit();

    $refStr = !empty($refNo) ? " (Ref: {$refNo})" : "";
    echo json_encode([
        'success'           => true,
        'message'           => "Box Lot #{$lotNo}{$refStr} berhasil dibatalkan dan dihapus dari sesi. Barcode/label ini sekarang bebas digunakan kembali.",
        'session_id'        => $sessionId,
        'removed_lot_id'    => $sessionLotId,
        'remaining_lots'    => $remainingTotalLots,
        'total_scanned_qty' => $newTotalScannedQty,
        'session_status'    => $newSessionStatus
    ]);
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem saat membatalkan box: ' . $e->getMessage()
    ]);
    exit;
}
