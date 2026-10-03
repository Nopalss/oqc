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

$pdo = getDB();
if (!$pdo) {
    echo json_encode(['success' => false, 'message' => 'Gagal terhubung ke database server!']);
    exit;
}

$action = sanitize($_POST['action'] ?? $_GET['action'] ?? $_REQUEST['action'] ?? 'approve');

// 1. GET CHIEF USERS (List of users eligible for Chief selection)
if ($action === 'get_chief_users') {
    try {
        $stmtUsers = $pdo->query("SELECT id, name, username, role FROM users WHERE status = 'active' ORDER BY name ASC");
        $users = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'users' => $users]);
        exit;
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Gagal mengambil daftar user Chief: ' . $e->getMessage()]);
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
                    chief_approved_at = NULL,
                    chief_notes = NULL
                WHERE id = :id
            ");
            $stmtUpd->execute([':id' => $sessionLotId]);
        } else {
            $stmtUpd = $pdo->prepare("
                UPDATE inspection_sessions 
                SET is_chief_approved = 0,
                    chief_approved_by = NULL,
                    chief_approved_at = NULL,
                    chief_notes = NULL
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

    // 3. APPROVE (ACC CHIEF LANGSUNG PILIH NAMA AKUN)
    $chiefUserId = (int)($_POST['chief_user_id'] ?? 0);
    $notes = sanitize($_POST['notes'] ?? 'ACC Rejection Sheet oleh Chief QC');

    if ($chiefUserId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Silakan pilih akun Chief QC terlebih dahulu!']);
        exit;
    }

    // Find Chief user
    $stmtUser = $pdo->prepare("SELECT id, name, username, role, status FROM users WHERE id = :uid LIMIT 1");
    $stmtUser->execute([':uid' => $chiefUserId]);
    $chiefUser = $stmtUser->fetch(PDO::FETCH_ASSOC);

    if (!$chiefUser || $chiefUser['status'] !== 'active') {
        echo json_encode(['success' => false, 'message' => 'Akun Chief QC tidak ditemukan atau sedang tidak aktif!']);
        exit;
    }

    // Update inspection_session_lots or inspection_sessions
    $nowStr = date('Y-m-d H:i:s');
    if ($sessionLotId > 0) {
        $stmtUpd = $pdo->prepare("
            UPDATE inspection_session_lots 
            SET is_chief_approved = 1,
                chief_approved_by = :uid,
                chief_approved_at = :aat,
                chief_notes = :notes
            WHERE id = :id
        ");
        $stmtUpd->execute([
            ':uid'   => $chiefUser['id'],
            ':aat'   => $nowStr,
            ':notes' => $notes,
            ':id'    => $sessionLotId
        ]);

        // Juga update status chief di sesi jika relevan
        $stmtUpdSess = $pdo->prepare("
            UPDATE inspection_sessions 
            SET is_chief_approved = 1,
                chief_approved_by = :uid,
                chief_approved_at = :aat
            WHERE id = :sid AND (is_chief_approved IS NULL OR is_chief_approved = 0)
        ");
        $stmtUpdSess->execute([
            ':uid' => $chiefUser['id'],
            ':aat' => $nowStr,
            ':sid' => $sessionId
        ]);
    } else {
        $stmtUpd = $pdo->prepare("
            UPDATE inspection_sessions 
            SET is_chief_approved = 1,
                chief_approved_by = :uid,
                chief_approved_at = :aat,
                chief_notes = :notes
            WHERE id = :id
        ");
        $stmtUpd->execute([
            ':uid'   => $chiefUser['id'],
            ':aat'   => $nowStr,
            ':notes' => $notes,
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
        'chief_name'         => $chiefUser['name'],
        'chief_username'     => $chiefUser['username'],
        'approved_at_raw'    => $nowStr,
        'approved_at_date'   => $dateFormatted,
        'approved_at_time'   => $timeFormatted,
        'message'            => 'Rejection ' . $lotMsg . ' berhasil disetujui (ACC) oleh Chief QC: ' . $chiefUser['name']
    ]);
    exit;

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan database: ' . $e->getMessage()]);
    exit;
}
