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

$rawIds = $_POST['ids'] ?? $_POST['id'] ?? null;
$notes  = trim(sanitize($_POST['notes'] ?? ''));

$targetIds = [];
if (is_array($rawIds)) {
    $targetIds = array_filter(array_map('intval', $rawIds));
} elseif (is_string($rawIds) && strpos($rawIds, ',') !== false) {
    $targetIds = array_filter(array_map('intval', explode(',', $rawIds)));
} elseif (!empty($rawIds)) {
    $singleId = (int)$rawIds;
    if ($singleId > 0) $targetIds[] = $singleId;
}

if (empty($targetIds)) {
    echo json_encode(['success' => false, 'message' => 'ID Request tidak valid atau tidak ada request yang dipilih.']);
    exit;
}

try {
    $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
    $stmtFetch = $pdo->prepare("SELECT * FROM did_approval_requests WHERE id IN ($placeholders)");
    $stmtFetch->execute($targetIds);
    $requests = $stmtFetch->fetchAll(PDO::FETCH_ASSOC);

    if (empty($requests)) {
        echo json_encode(['success' => false, 'message' => 'Data request persetujuan DID tidak ditemukan.']);
        exit;
    }

    $pendingRequests = array_filter($requests, function($r) {
        return $r['status'] === 'pending';
    });

    if (empty($pendingRequests)) {
        echo json_encode(['success' => false, 'message' => 'Semua request yang dipilih sudah pernah diproses sebelumnya.']);
        exit;
    }

    $pdo->beginTransaction();

    $spvPicName = 'SPV: ' . $currentUser['name'];
    $approvedCount = 0;

    $stmtCheckDid = $pdo->prepare("SELECT id FROM daily_inspection_data WHERE part_code = :pcode AND lot_number = :lot LIMIT 1");
    $stmtMP = $pdo->prepare("SELECT part_name FROM master_parts WHERE part_code = :pcode OR id = :pid LIMIT 1");
    $stmtInsDid = $pdo->prepare("
        INSERT INTO daily_inspection_data (
            part_code, part_name, lot_number, cavity, inspecting_date, status_inspect, pic, created_at
        ) VALUES (
            :pcode, :pname, :lot, '1', CURDATE(), 'OK', :pic, NOW()
        )
    ");
    $stmtUpReq = $pdo->prepare("
        UPDATE did_approval_requests 
        SET status = 'approved',
            reviewed_by = :uid,
            reviewed_at = NOW(),
            rejection_reason = :notes
        WHERE id = :id AND status = 'pending'
    ");

    foreach ($pendingRequests as $req) {
        // 1. Check if DID row exists, insert if missing
        $stmtCheckDid->execute([':pcode' => $req['part_code'], ':lot' => $req['lot_number']]);
        $existingDidId = $stmtCheckDid->fetchColumn();

        if (!$existingDidId) {
            $resolvedPartName = !empty($req['part_name']) && $req['part_name'] !== $req['part_code'] ? $req['part_name'] : '';
            $stmtMP->execute([':pcode' => $req['part_code'], ':pid' => $req['part_code']]);
            $masterPartName = $stmtMP->fetchColumn();
            if (!empty($masterPartName)) {
                $resolvedPartName = $masterPartName;
            } elseif (empty($resolvedPartName)) {
                $resolvedPartName = 'Part ' . $req['part_code'];
            }

            $stmtInsDid->execute([
                ':pcode' => $req['part_code'],
                ':pname' => $resolvedPartName,
                ':lot'   => $req['lot_number'],
                ':pic'   => $spvPicName
            ]);
        }

        // 2. Update did_approval_requests status
        $stmtUpReq->execute([
            ':uid'   => (int)$currentUser['id'],
            ':notes' => !empty($notes) ? $notes : null,
            ':id'    => (int)$req['id']
        ]);

        $approvedCount++;
    }

    $pdo->commit();

    if ($approvedCount === 1) {
        $single = reset($pendingRequests);
        $msg = "Request persetujuan DID untuk Lot #{$single['lot_number']} ({$single['part_code']}) berhasil disetujui.";
    } else {
        $msg = "Sebanyak {$approvedCount} request persetujuan DID berhasil disetujui sekaligus.";
    }

    echo json_encode([
        'success'        => true,
        'message'        => $msg,
        'approved_count' => $approvedCount
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
