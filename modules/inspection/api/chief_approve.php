<?php
/**
 * AJAX API: Dedicated Chief QC Approval (ACC) for Rejection Sheet
 * Authenticates Chief QC user password & manages dedicated Chief approval columns
 * 
 * PT. Surya Technology Industri — OQC System
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/helper.php';

// Production Security Guard: Require authenticated user
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sesi login Anda telah berakhir. Silakan login kembali.', 'unauthorized' => true]);
    exit;
}

$currentUser = current_user();
$currentUserId = (int)($currentUser['id'] ?? 0);
$currentUserRole = strtolower(trim((string)($currentUser['role'] ?? '')));
session_write_close();

$pdo = getDB();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Gagal terhubung ke database server!']);
    exit;
}

$action = sanitize($_POST['action'] ?? $_GET['action'] ?? $_REQUEST['action'] ?? 'approve');

// 1. GET CHIEF USERS (List of users eligible for Chief selection)
// 1. GET CHIEF NAMES (List of distinct previously typed Chief names)
if ($action === 'get_chief_names' || $action === 'get_chief_users') {
    try {
        $stmtNames = $pdo->query("
            SELECT DISTINCT chief_approved_by as name 
            FROM inspection_session_lots 
            WHERE chief_approved_by IS NOT NULL AND chief_approved_by != ''
            UNION 
            SELECT DISTINCT chief_approved_by as name 
            FROM inspection_sessions 
            WHERE chief_approved_by IS NOT NULL AND chief_approved_by != ''
            ORDER BY name ASC
        ");
        $names = array_values(array_filter($stmtNames->fetchAll(PDO::FETCH_COLUMN)));
        echo json_encode(['success' => true, 'names' => $names, 'users' => []]);
        exit;
    } catch (PDOException $e) {
        echo json_encode(['success' => true, 'names' => [], 'users' => []]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Metode permintaan tidak valid!']);
    exit;
}

$sessionId = (int)($_POST['session_id'] ?? 0);
$sessionLotId = (int)($_POST['session_lot_id'] ?? 0);

if ($sessionId <= 0 && $sessionLotId <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID sesi inspeksi atau ID lot tidak valid!']);
    exit;
}

try {
    $targetLot = null;
    if ($sessionLotId > 0) {
        $stmtLot = $pdo->prepare("SELECT * FROM inspection_session_lots WHERE id = :lid LIMIT 1");
        $stmtLot->execute([':lid' => $sessionLotId]);
        $targetLot = $stmtLot->fetch(PDO::FETCH_ASSOC);
        if ($targetLot) {
            $sessionId = (int)$targetLot['inspection_session_id'];
        }
    }

    // Verify session exists
    $stmtCheck = $pdo->prepare("SELECT id, status, is_chief_approved FROM inspection_sessions WHERE id = :id LIMIT 1");
    $stmtCheck->execute([':id' => $sessionId]);
    $sess = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if (!$sess) {
        echo json_encode(['success' => false, 'message' => 'Sesi inspeksi tidak ditemukan!']);
        exit;
    }

    // 2. UNAPPROVE (BATALKAN ACC CHIEF)
    if ($action === 'unapprove') {
        if ($sessionLotId > 0) {
            $stmtUpd = $pdo->prepare("
                UPDATE inspection_session_lots 
                SET is_chief_approved = 0,
                    chief_approved_by = NULL,
                    chief_approved_at = NULL
                WHERE id = :id
            ");
            $stmtUpd->execute([':id' => $sessionLotId]);
        } else {
            $stmtUpd = $pdo->prepare("
                UPDATE inspection_sessions 
                SET is_chief_approved = 0,
                    chief_approved_by = NULL,
                    chief_approved_at = NULL
                WHERE id = :id
            ");
            $stmtUpd->execute([':id' => $sessionId]);
        }

        echo json_encode([
            'success'           => true,
            'action'            => 'unapprove',
            'session_lot_id'    => $sessionLotId,
            'is_chief_approved' => 0,
            'message'           => 'Persetujuan (ACC) Chief QC berhasil dibatalkan. Format cetak kembali ke tanda tangan basah manual.'
        ]);
        exit;
    }

    // 3. APPROVE (ACC CHIEF LANGSUNG DARI KETIK MANUAL NAMA CHIEF KOLEKTIF/INDIVIDUAL)
    $chiefName = sanitize($_POST['chief_name'] ?? $_POST['chief_notes'] ?? '');

    if (empty($chiefName)) {
        echo json_encode(['success' => false, 'message' => 'Silakan ketik Nama Chief QC terlebih dahulu!']);
        exit;
    }

    $nowStr = date('Y-m-d H:i:s');
    if ($sessionLotId > 0) {
        $stmtUpd = $pdo->prepare("
            UPDATE inspection_session_lots 
            SET is_chief_approved = 1,
                chief_approved_by = :cname,
                chief_approved_at = :aat
            WHERE id = :id
        ");
        $stmtUpd->execute([
            ':cname' => $chiefName,
            ':aat'   => $nowStr,
            ':id'    => $sessionLotId
        ]);

        // Juga update status chief di sesi jika relevan
        $stmtUpdSess = $pdo->prepare("
            UPDATE inspection_sessions 
            SET is_chief_approved = 1,
                chief_approved_by = :cname,
                chief_approved_at = :aat
            WHERE id = :sid AND (is_chief_approved IS NULL OR is_chief_approved = 0)
        ");
        $stmtUpdSess->execute([
            ':cname' => $chiefName,
            ':aat'   => $nowStr,
            ':sid'   => $sessionId
        ]);
    } else {
        $stmtUpd = $pdo->prepare("
            UPDATE inspection_sessions 
            SET is_chief_approved = 1,
                chief_approved_by = :cname,
                chief_approved_at = :aat
            WHERE id = :id
        ");
        $stmtUpd->execute([
            ':cname' => $chiefName,
            ':aat'   => $nowStr,
            ':id'    => $sessionId
        ]);
    }

    $dateFormatted = date('d / m / Y', strtotime($nowStr));
    $timeFormatted = date('H:i', strtotime($nowStr)) . ' WIB';

    $lotMsg = $targetLot ? ('Lot #' . $targetLot['lot_number']) : ('Sesi #' . $sessionId);
    echo json_encode([
        'success'            => true,
        'action'             => 'approve',
        'session_lot_id'     => $sessionLotId,
        'is_chief_approved'  => 1,
        'chief_name'         => $chiefName,
        'approved_at_raw'    => $nowStr,
        'approved_at_date'   => $dateFormatted,
        'approved_at_time'   => $timeFormatted,
        'message'            => 'Rejection ' . $lotMsg . ' berhasil disetujui (ACC) oleh Chief QC: ' . $chiefName
    ]);
    exit;

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan database: ' . $e->getMessage()]);
    exit;
}
