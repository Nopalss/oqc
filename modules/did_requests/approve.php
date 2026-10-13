<?php
/**
 * Action Handler: Approve DID Request
 * Approves pending DID request, records reviewer ID + timestamp, and auto-inserts DID record.
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

$reqId = (int)($_POST['id'] ?? 0);
$notes = trim(sanitize($_POST['notes'] ?? ''));

if ($reqId <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID Request tidak valid.']);
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

    $pdo->beginTransaction();

    // 1. Check if DID row exists, insert if missing
    $stmtCheckDid = $pdo->prepare("SELECT id FROM daily_inspection_data WHERE part_code = :pcode AND lot_number = :lot LIMIT 1");
    $stmtCheckDid->execute([':pcode' => $req['part_code'], ':lot' => $req['lot_number']]);
    $existingDidId = $stmtCheckDid->fetchColumn();

    if (!$existingDidId) {
        $spvPicName = 'SPV: ' . $currentUser['name'];
        $resolvedPartName = !empty($req['part_name']) && $req['part_name'] !== $req['part_code'] ? $req['part_name'] : '';
        $stmtMP = $pdo->prepare("SELECT part_name FROM master_parts WHERE part_code = :pcode OR id = :pid LIMIT 1");
        $stmtMP->execute([':pcode' => $req['part_code'], ':pid' => $req['part_code']]);
        $masterPartName = $stmtMP->fetchColumn();
        if (!empty($masterPartName)) {
            $resolvedPartName = $masterPartName;
        } elseif (empty($resolvedPartName)) {
            $resolvedPartName = 'Part ' . $req['part_code'];
        }

        $stmtInsDid = $pdo->prepare("
            INSERT INTO daily_inspection_data (
                part_code, part_name, lot_number, cavity, inspecting_date, status_inspect, pic, created_at
            ) VALUES (
                :pcode, :pname, :lot, '1', CURDATE(), 'OK', :pic, NOW()
            )
        ");
        $stmtInsDid->execute([
            ':pcode' => $req['part_code'],
            ':pname' => $resolvedPartName,
            ':lot'   => $req['lot_number'],
            ':pic'   => $spvPicName
        ]);
    }

    // 2. Update did_approval_requests status
    $stmtUpReq = $pdo->prepare("
        UPDATE did_approval_requests 
        SET status = 'approved',
            reviewed_by = :uid,
            reviewed_at = NOW(),
            rejection_reason = :notes
        WHERE id = :id
    ");
    $stmtUpReq->execute([
        ':uid'   => (int)$currentUser['id'],
        ':notes' => !empty($notes) ? $notes : null,
        ':id'    => $reqId
    ]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => "Request persetujuan DID untuk Lot #{$req['lot_number']} ({$req['part_code']}) berhasil disetujui."
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'success' => false,
        'message' => 'Gagal memproses persetujuan DID: ' . $e->getMessage()
    ]);
}
