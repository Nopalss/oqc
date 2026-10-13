<?php
/**
 * API: Create DID Approval Request
 * Handles submission of pending DID requests when DID is missing during scan.
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/helper.php';

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'message' => 'Sesi login Anda telah berakhir. Silakan login kembali.']);
    exit;
}

$currentUser = current_user();
$pdo = getDB();

if (!$pdo) {
    echo json_encode(['success' => false, 'message' => 'Gagal terhubung ke database.']);
    exit;
}

$sessionId  = (int)($_POST['session_id'] ?? 0);
$partCode   = strtoupper(trim(sanitize($_POST['part_code'] ?? '')));
$partName   = trim(sanitize($_POST['part_name'] ?? ''));
$lotNumber  = strtoupper(trim(sanitize($_POST['lot_number'] ?? '')));
$reason     = trim(sanitize($_POST['reason'] ?? ''));

if (empty($partCode) || empty($lotNumber)) {
    echo json_encode(['success' => false, 'message' => 'Part Code dan Lot Number tidak boleh kosong.']);
    exit;
}

// Resolve authentic part name from master_parts
$stmtMP = $pdo->prepare("SELECT part_name FROM master_parts WHERE part_code = :pcode OR id = :pid LIMIT 1");
$stmtMP->execute([':pcode' => $partCode, ':pid' => $partCode]);
$masterPartName = $stmtMP->fetchColumn();

if (!empty($masterPartName)) {
    $partName = $masterPartName;
} elseif (empty($partName) || $partName === $partCode) {
    $partName = 'Part ' . $partCode;
}

try {
    // Check if there is already an active pending request for this exact part_code and lot_number
    $stmtCheck = $pdo->prepare("SELECT id, requested_at FROM did_approval_requests WHERE part_code = :pcode AND lot_number = :lot AND status = 'pending' LIMIT 1");
    $stmtCheck->execute([':pcode' => $partCode, ':lot' => $lotNumber]);
    $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        echo json_encode([
            'success' => true,
            'is_duplicate' => true,
            'message' => "Request persetujuan DID untuk Lot #{$lotNumber} ({$partCode}) sudah pernah dikirim sebelumnya dan sedang menunggu verifikasi Supervisor/Admin."
        ]);
        exit;
    }

    // Insert new pending approval request
    $stmtIns = $pdo->prepare("
        INSERT INTO did_approval_requests (
            inspection_session_id, part_code, part_name, lot_number,
            requested_by, requested_at, request_reason, status, created_at
        ) VALUES (
            :sid, :pcode, :pname, :lot,
            :uid, NOW(), :reason, 'pending', NOW()
        )
    ");
    
    $stmtIns->execute([
        ':sid'    => $sessionId > 0 ? $sessionId : null,
        ':pcode'  => $partCode,
        ':pname'  => $partName,
        ':lot'    => $lotNumber,
        ':uid'    => (int)($currentUser['id'] ?? 0),
        ':reason' => !empty($reason) ? $reason : 'Pengajuan persetujuan DID dari Modal Scan QC'
    ]);

    $reqId = $pdo->lastInsertId();

    echo json_encode([
        'success' => true,
        'request_id' => $reqId,
        'message' => "Request persetujuan DID untuk Lot #{$lotNumber} ({$partCode}) berhasil dikirim ke Admin & Supervisor."
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem saat mengirim request DID: ' . $e->getMessage()
    ]);
}
