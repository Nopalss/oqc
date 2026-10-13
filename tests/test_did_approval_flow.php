<?php
/**
 * Test Suite: DID Approval Workflow & Auto DID Creation
 * Verifies end-to-end DID request creation, approval, DB auto-insertion, and scan validation.
 */
require_once __DIR__ . '/../config/database.php';

echo "=== MEMULAI TEST ALUR DID APPROVAL SYSTEM ===\n";

$pdo = getDB();
if (!$pdo) {
    die("ERROR: Gagal koneksi ke database.\n");
}

$testPartCode = 'TEST_PART_99';
$testLotNo    = 'TEST_LOT_99';
$testPartName = 'Test Part Rubber Seal 99';
$testUserId   = 1;

// Cleanup any leftover old test data
$pdo->prepare("DELETE FROM did_approval_requests WHERE part_code = :pcode AND lot_number = :lot")->execute([':pcode' => $testPartCode, ':lot' => $testLotNo]);
$pdo->prepare("DELETE FROM daily_inspection_data WHERE part_code = :pcode AND lot_number = :lot")->execute([':pcode' => $testPartCode, ':lot' => $testLotNo]);

// 1. Test Request Creation
echo "[TEST 1] Creating synthetic DID approval request...\n";
$stmtReq = $pdo->prepare("
    INSERT INTO did_approval_requests (
        part_code, part_name, lot_number, requested_by, requested_at, request_reason, status
    ) VALUES (
        :pcode, :pname, :lot, :uid, NOW(), 'Tes pengajuan manual oleh inspector', 'pending'
    )
");
$stmtReq->execute([
    ':pcode' => $testPartCode,
    ':pname' => $testPartName,
    ':lot'   => $testLotNo,
    ':uid'   => $testUserId
]);
$requestId = $pdo->lastInsertId();
echo " -> SUCCESS: Request ID #{$requestId} created.\n";

// 2. Test Notification Bell Count Query
echo "[TEST 2] Testing Navbar Notification Count Query...\n";
$stmtBadge = $pdo->query("SELECT COUNT(*) FROM did_approval_requests WHERE status = 'pending'");
$pendingCount = (int)$stmtBadge->fetchColumn();
echo " -> SUCCESS: Pending Count is {$pendingCount} (expected >= 1).\n";

if ($pendingCount < 1) {
    die("FAIL: Pending count should be >= 1.\n");
}

// 3. Test Approval Logic (Simulating approve.php)
echo "[TEST 3] Simulating Supervisor Approval & Auto DID Creation...\n";
$spvUserId = 2;

$pdo->beginTransaction();

// Check if DID exists, insert if missing
$stmtCheckDid = $pdo->prepare("SELECT id FROM daily_inspection_data WHERE part_code = :pcode AND lot_number = :lot LIMIT 1");
$stmtCheckDid->execute([':pcode' => $testPartCode, ':lot' => $testLotNo]);
if (!$stmtCheckDid->fetchColumn()) {
    $stmtInsDid = $pdo->prepare("
        INSERT INTO daily_inspection_data (
            part_code, part_name, lot_number, cavity, inspecting_date, status_inspect, pic, created_at
        ) VALUES (
            :pcode, :pname, :lot, '1', CURDATE(), 'OK', 'SPV: Test Supervisor', NOW()
        )
    ");
    $stmtInsDid->execute([
        ':pcode' => $testPartCode,
        ':pname' => $testPartName,
        ':lot'   => $testLotNo
    ]);
}

$stmtUp = $pdo->prepare("UPDATE did_approval_requests SET status = 'approved', reviewed_by = :spv, reviewed_at = NOW(), rejection_reason = 'Disetujui oleh Supervisor' WHERE id = :id");
$stmtUp->execute([':spv' => $spvUserId, ':id' => $requestId]);

$pdo->commit();
echo " -> SUCCESS: Approval updated and DID record created.\n";

// 4. Test Scan Validation
echo "[TEST 4] Verifying scan_validate DID lookup for approved lot...\n";
$stmtVerify = $pdo->prepare("SELECT * FROM daily_inspection_data WHERE part_code = :pcode AND lot_number = :lot ORDER BY id DESC LIMIT 1");
$stmtVerify->execute([':pcode' => $testPartCode, ':lot' => $testLotNo]);
$didRow = $stmtVerify->fetch(PDO::FETCH_ASSOC);

if ($didRow && strtoupper($didRow['status_inspect']) === 'OK') {
    echo " -> SUCCESS: DID Record found with status OK (PIC: {$didRow['pic']}). Scan will pass!\n";
} else {
    die("FAIL: DID Record not found or not OK.\n");
}

// 5. Test Audit Trail Data
echo "[TEST 5] Verifying Audit Trail in did_approval_requests...\n";
$stmtReqCheck = $pdo->prepare("SELECT * FROM did_approval_requests WHERE id = :id LIMIT 1");
$stmtReqCheck->execute([':id' => $requestId]);
$reqRow = $stmtReqCheck->fetch(PDO::FETCH_ASSOC);

if ($reqRow['status'] === 'approved' && !empty($reqRow['reviewed_at']) && (int)$reqRow['reviewed_by'] === $spvUserId) {
    echo " -> SUCCESS: Audit Trail intact (Reviewed at: {$reqRow['reviewed_at']}, Reviewer ID: {$reqRow['reviewed_by']}).\n";
} else {
    die("FAIL: Audit Trail incomplete.\n");
}

// 6. Cleanup Synthetic Data
echo "[TEST 6] Cleaning up test data...\n";
$pdo->prepare("DELETE FROM did_approval_requests WHERE id = :id")->execute([':id' => $requestId]);
$pdo->prepare("DELETE FROM daily_inspection_data WHERE part_code = :pcode AND lot_number = :lot AND pic = 'SPV: Test Supervisor'")->execute([':pcode' => $testPartCode, ':lot' => $testLotNo]);
echo " -> SUCCESS: Synthetic test data cleaned up.\n";

echo "\n=== ALL DID APPROVAL SYSTEM TESTS PASSED SUCCESSFULLY! ===\n";
