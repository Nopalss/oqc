<?php
/**
 * AJAX Endpoint: Undo NG Defect / Undo Sample
 * Recalculates session counters & status (Restores status to 'in_progress' if NG limit is no longer exceeded)
 */
ob_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (ob_get_length()) ob_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid Request Method']);
    exit;
}

$action = sanitize($_POST['action'] ?? 'delete_ng_record');
$recordId = (int)($_POST['record_id'] ?? 0);
$sessionId = (int)($_POST['session_id'] ?? 0);

$pdo = getDB();
if (!$pdo) {
    echo json_encode(['success' => false, 'message' => 'Gagal terhubung ke database server']);
    exit;
}

try {
    if ($action === 'delete_ng_record') {
        if ($recordId <= 0) {
            if (ob_get_length()) ob_clean();
            echo json_encode(['success' => false, 'message' => 'ID Record Defect tidak valid']);
            exit;
        }

        $cancelReason = trim(sanitize($_POST['cancel_reason'] ?? ''));
        if (empty($cancelReason)) {
            if (ob_get_length()) ob_clean();
            echo json_encode(['success' => false, 'message' => 'Alasan pembatalan wajib diisi!']);
            exit;
        }

        // 1. Fetch NG record to get sample_id and session_id
        $stmtNg = $pdo->prepare("SELECT n.*, s.inspection_session_id, s.id as sample_id 
                                 FROM inspection_ng_records n 
                                 JOIN inspection_samples s ON s.id = n.inspection_sample_id 
                                 WHERE n.id = :id");
        $stmtNg->execute([':id' => $recordId]);
        $ngRow = $stmtNg->fetch(PDO::FETCH_ASSOC);

        if (!$ngRow) {
            if (ob_get_length()) ob_clean();
            echo json_encode(['success' => false, 'message' => 'Catatan defect tidak ditemukan']);
            exit;
        }

        $sessionId = (int)$ngRow['inspection_session_id'];
        $sampleId = (int)$ngRow['sample_id'];
        $inspectorName = $_SESSION['user_name'] ?? 'Rangga Aditya';

        // 2. Mark NG record as cancelled with reason (Audit Trail)
        $stmtDelNg = $pdo->prepare("UPDATE inspection_ng_records SET 
                                    is_cancelled = 1, 
                                    cancel_reason = :reas, 
                                    cancelled_at = NOW(), 
                                    cancelled_by = :cby 
                                    WHERE id = :id");
        $stmtDelNg->execute([
            ':reas' => $cancelReason,
            ':cby'  => $inspectorName,
            ':id'   => $recordId
        ]);

        // 3. Check if sample has any remaining active (non-cancelled) NG records
        $stmtChk = $pdo->prepare("SELECT COUNT(*) FROM inspection_ng_records WHERE inspection_sample_id = :spid AND (is_cancelled IS NULL OR is_cancelled = 0)");
        $stmtChk->execute([':spid' => $sampleId]);
        $remNgCount = (int)$stmtChk->fetchColumn();

        if ($remNgCount === 0) {
            // Update sample result back to OK
            $stmtUpdSp = $pdo->prepare("UPDATE inspection_samples SET result = 'OK' WHERE id = :spid");
            $stmtUpdSp->execute([':spid' => $sampleId]);
        }

    } elseif ($action === 'undo_last_sample') {
        if ($sessionId <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID Sesi Inspeksi tidak valid']);
            exit;
        }

        // 1. Fetch last checked sample for this session
        $stmtLastSp = $pdo->prepare("SELECT * FROM inspection_samples WHERE inspection_session_id = :sid ORDER BY sample_number DESC LIMIT 1");
        $stmtLastSp->execute([':sid' => $sessionId]);
        $lastSample = $stmtLastSp->fetch(PDO::FETCH_ASSOC);

        if (!$lastSample) {
            echo json_encode(['success' => false, 'message' => 'Belum ada sample yang diperiksa pada sesi ini']);
            exit;
        }

        $sampleId = (int)$lastSample['id'];

        // 2. Delete associated NG records & sample row
        $stmtDelNgs = $pdo->prepare("DELETE FROM inspection_ng_records WHERE inspection_sample_id = :spid");
        $stmtDelNgs->execute([':spid' => $sampleId]);

        $stmtDelSp = $pdo->prepare("DELETE FROM inspection_samples WHERE id = :spid");
        $stmtDelSp->execute([':spid' => $sampleId]);

    } else {
        echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenali']);
        exit;
    }

    // Recalculate session status & counters
    $stmtSess = $pdo->prepare("SELECT * FROM inspection_sessions WHERE id = :sid");
    $stmtSess->execute([':sid' => $sessionId]);
    $session = $stmtSess->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        echo json_encode(['success' => false, 'message' => 'Sesi inspeksi tidak ditemukan']);
        exit;
    }

    // Count actual checked samples
    $stmtCntChecked = $pdo->prepare("SELECT COUNT(*) FROM inspection_samples WHERE inspection_session_id = :sid");
    $stmtCntChecked->execute([':sid' => $sessionId]);
    $newSamplesChecked = (int)$stmtCntChecked->fetchColumn();

    // Count actual active (non-cancelled) NG samples
    $stmtCntNg = $pdo->prepare("SELECT COUNT(DISTINCT n.inspection_sample_id) FROM inspection_ng_records n JOIN inspection_samples s ON s.id = n.inspection_sample_id WHERE s.inspection_session_id = :sid AND (n.is_cancelled IS NULL OR n.is_cancelled = 0)");
    $stmtCntNg->execute([':sid' => $sessionId]);
    $newNgCount = (int)$stmtCntNg->fetchColumn();

    $maxSampleSize = (int)$session['sample_size'];
    $rejectLimit = (int)$session['reject_number'];

    // Determine recalculated status
    $newStatus = 'in_progress';
    $closedAt = null;

    if ($newNgCount >= $rejectLimit) {
        $newStatus = 'rejected';
        $closedAt = $session['closed_at'] ?: date('Y-m-d H:i:s');
    } elseif ($newSamplesChecked >= $maxSampleSize) {
        $newStatus = 'passed';
        $closedAt = $session['closed_at'] ?: date('Y-m-d H:i:s');
    }

    // Update inspection_sessions
    $stmtUpdSess = $pdo->prepare("UPDATE inspection_sessions SET 
        samples_checked = :schecked, 
        ng_count = :ngcnt, 
        status = :st, 
        closed_at = :cat 
        WHERE id = :sid");
    $stmtUpdSess->execute([
        ':schecked' => $newSamplesChecked,
        ':ngcnt'    => $newNgCount,
        ':st'       => $newStatus,
        ':cat'      => $closedAt,
        ':sid'      => $sessionId
    ]);

    // Sync lot status: reset lot_status = 'ok' jika lot berstatus 'ng_found' tapi tidak memiliki active NG records
    try {
        $pdo->prepare("
            UPDATE inspection_session_lots isl
            SET isl.lot_status = 'ok'
            WHERE isl.inspection_session_id = :sid
              AND isl.lot_status = 'ng_found'
              AND NOT EXISTS (
                  SELECT 1
                  FROM inspection_ng_records n
                  JOIN inspection_samples sp ON sp.id = n.inspection_sample_id
                  WHERE sp.inspection_session_id = :sid2
                    AND n.session_lot_id = isl.id
                    AND (n.is_cancelled IS NULL OR n.is_cancelled = 0)
              )
        ")->execute([':sid' => $sessionId, ':sid2' => $sessionId]);
    } catch (Exception $eSync) {}

    // Fetch updated active (non-cancelled) NG records
    $stmtNgList = $pdo->prepare("SELECT n.*, d.name as defect_name, s.sample_number,
                                        COALESCE(n.ref_number, sl.ref_number) as ref_number,
                                        COALESCE(n.lot_number, sl.lot_number) as lot_number
                                  FROM inspection_ng_records n 
                                  JOIN inspection_samples s ON s.id = n.inspection_sample_id 
                                  JOIN defect_types d ON d.id = n.defect_type_id 
                                  LEFT JOIN inspection_session_lots sl ON sl.id = n.session_lot_id
                                  WHERE s.inspection_session_id = :sid AND (n.is_cancelled IS NULL OR n.is_cancelled = 0)
                                  ORDER BY n.id DESC");
    $stmtNgList->execute([':sid' => $sessionId]);
    $ngRecords = $stmtNgList->fetchAll(PDO::FETCH_ASSOC);

    if (ob_get_length()) ob_clean();
    echo json_encode([
        'success' => true,
        'session_id' => $sessionId,
        'current_sample' => $newSamplesChecked,
        'max_sample' => $maxSampleSize,
        'ng_count' => $newNgCount,
        'reject_limit' => $rejectLimit,
        'status' => $newStatus,
        'is_finished' => ($newStatus !== 'in_progress'),
        'ng_records' => $ngRecords,
        'message' => 'Catatan defect / sample berhasil diperbarui'
    ]);

} catch (PDOException $e) {
    if (ob_get_length()) ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Kesalahan Database: ' . $e->getMessage()
    ]);
}
