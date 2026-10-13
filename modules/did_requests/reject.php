<?php
/**
 * Action Handler: Reject DID Request
 * Rejects pending DID request and records reviewer ID, timestamp, and rejection reason.
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'message' => 'Sesi login Anda telah berakhir. Silakan login kembali.']);
    exit;
}

$currentUser = current_user();
if (!in_array($currentUser['role'] ?? '', ['admin', 'supervisor', 'chief', 'manager', 'superadmin'], true)) {
    echo json_encode(['success' => false, 'message' => 'Akses ditolak. Fitur ini khusus untuk Admin & Supervisor.']);
    exit;
}

$pdo = getDB();
if (!$pdo) {
    echo json_encode(['success' => false, 'message' => 'Gagal terhubung ke database.']);
    exit;
}

$reqId  = (int)($_POST['id'] ?? 0);
$reason = trim(sanitize($_POST['reason'] ?? ''));

if ($reqId <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID Request tidak valid.']);
    exit;
}

if (empty($reason)) {
    echo json_encode(['success' => false, 'message' => 'Alasan penolakan wajib diisi.']);
    exit;
}

try {
    $stmtFetch = $pdo->prepare("SELECT * FROM did_approval_requests WHERE id = :id LIMIT 1");
    $stmtFetch->execute([':id' => $reqId]);
    $req = $stmtFetch->fetch(PDO::FETCH_ASSOC);

    if (!$req) {
        echo json_encode(['success' => false, 'message' => 'Data request persetujuan DID tidak ditemukan.']);
        exit;
    }

    if ($req['status'] !== 'pending') {
        echo json_encode(['success' => false, 'message' => "Request ini sudah pernah diproses sebelumnya (Status: " . strtoupper($req['status']) . ")."]);
        exit;
    }

    $stmtUpReq = $pdo->prepare("
        UPDATE did_approval_requests 
        SET status = 'rejected',
            reviewed_by = :uid,
            reviewed_at = NOW(),
            rejection_reason = :reason
        WHERE id = :id
    ");
    $stmtUpReq->execute([
        ':uid'    => (int)$currentUser['id'],
        ':reason' => $reason,
        ':id'     => $reqId
    ]);

    echo json_encode([
        'success' => true,
        'message' => "Request persetujuan DID untuk Lot #{$req['lot_number']} ({$req['part_code']}) telah ditolak."
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Gagal memproses penolakan DID: ' . $e->getMessage()
    ]);
}
