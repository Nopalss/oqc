<?php
/**
 * AJAX Endpoint: Process Inspection Sample (OK / NG / BULK_OK)
 * Records sample results, defect details, updates session counter & status (Passed / Rejected)
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid Request Method']);
    exit;
}

$sessionId = (int)($_POST['session_id'] ?? 0);
$result = strtoupper(trim(sanitize($_POST['result'] ?? 'OK'))); // OK, NG, or BULK_OK
$defects = $_POST['defects'] ?? []; // Array of { defect_type_id / defect_name, qty_ng, remark }

if (!$sessionId || !in_array($result, ['OK', 'NG', 'BULK_OK'])) {
    echo json_encode(['success' => false, 'message' => 'Parameter Sesi Inspeksi atau Result tidak valid']);
    exit;
}

$pdo = getDB();
if (!$pdo) {
    echo json_encode(['success' => false, 'message' => 'Gagal koneksi database!']);
    exit;
}

try {
    // 1. Fetch current session
    $stmtSess = $pdo->prepare("SELECT * FROM inspection_sessions WHERE id = :id");
    $stmtSess->execute([':id' => $sessionId]);
    $session = $stmtSess->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        echo json_encode(['success' => false, 'message' => 'Sesi inspeksi tidak ditemukan']);
        exit;
    }

    if ($session['status'] !== 'in_progress') {
        echo json_encode(['success' => false, 'message' => 'Sesi inspeksi ini sudah ditutup (Status: ' . strtoupper($session['status']) . ')']);
        exit;
    }

    $maxSampleSize = (int)$session['sample_size'];
    $rejectLimit = (int)$session['reject_number'];
    $newNgCount = (int)$session['ng_count'];

    // ── BULK OK MODE ─────────────────────────────────────────────
    if ($result === 'BULK_OK') {
        $startNum = (int)$session['samples_checked'] + 1;

        if ($startNum <= $maxSampleSize) {
            $stmtInsSample = $pdo->prepare("INSERT INTO inspection_samples (inspection_session_id, sample_number, result, checked_at) VALUES (:sid, :snum, 'OK', NOW())");
            for ($i = $startNum; $i <= $maxSampleSize; $i++) {
                $stmtInsSample->execute([
                    ':sid'  => $sessionId,
                    ':snum' => $i
                ]);
            }
        }

        // Determine status after bulk OK
        $newStatus = ($newNgCount >= $rejectLimit) ? 'rejected' : 'passed';
        $closedAt = date('Y-m-d H:i:s');

        // Update inspection_sessions
        $stmtUpd = $pdo->prepare("UPDATE inspection_sessions SET 
            samples_checked = :schecked, 
            ng_count = :ngcnt, 
            status = :st, 
            closed_at = :cat 
            WHERE id = :sid");
        $stmtUpd->execute([
            ':schecked' => $maxSampleSize,
            ':ngcnt'    => $newNgCount,
            ':st'       => $newStatus,
            ':cat'      => $closedAt,
            ':sid'      => $sessionId
        ]);

        // Fetch updated NG records
        $stmtNgList = $pdo->prepare("SELECT n.*, d.name as defect_name, s.sample_number 
                                      FROM inspection_ng_records n 
                                      JOIN inspection_samples s ON s.id = n.inspection_sample_id 
                                      JOIN defect_types d ON d.id = n.defect_type_id 
                                      WHERE s.inspection_session_id = :sid 
                                      ORDER BY n.id DESC");
        $stmtNgList->execute([':sid' => $sessionId]);
        $ngRecords = $stmtNgList->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'current_sample' => $maxSampleSize,
            'max_sample' => $maxSampleSize,
            'ng_count' => $newNgCount,
            'reject_limit' => $rejectLimit,
            'status' => $newStatus,
            'is_finished' => true,
            'ng_records' => $ngRecords,
            'message' => '🎉 SESI INSPEKSI SELESAI! Seluruh sisa sample berhasil diloloskan (PASSED).'
        ]);
        exit;
    }

    // ── SINGLE SAMPLE MODE (OK / NG) ──────────────────────────────
    $currentSampleNo = (int)$session['samples_checked'] + 1;

    // 2. Insert Sample Log
    $stmtInsSample = $pdo->prepare("INSERT INTO inspection_samples (inspection_session_id, sample_number, result, checked_at) VALUES (:sid, :snum, :res, NOW())");
    $stmtInsSample->execute([
        ':sid' => $sessionId,
        ':snum' => $currentSampleNo,
        ':res' => $result
    ]);
    $sampleId = $pdo->lastInsertId();

    // 3. If NG, record defect details
    if ($result === 'NG') {
        $newNgCount++;

        if (!empty($defects) && is_array($defects)) {
            foreach ($defects as $df) {
                $defectTypeId = (int)($df['defect_type_id'] ?? 0);
                $customName = trim(sanitize($df['custom_name'] ?? ''));
                $qtyNg = (int)($df['qty_ng'] ?? 1);
                $remark = trim(sanitize($df['remark'] ?? ''));

                // Handle custom defect type creation if needed
                if ($defectTypeId === 0 && !empty($customName)) {
                    $stmtChkDef = $pdo->prepare("SELECT id FROM defect_types WHERE LOWER(name) = LOWER(:name)");
                    $stmtChkDef->execute([':name' => $customName]);
                    $existingDef = $stmtChkDef->fetch(PDO::FETCH_ASSOC);

                    if ($existingDef) {
                        $defectTypeId = (int)$existingDef['id'];
                    } else {
                        $stmtInsDef = $pdo->prepare("INSERT INTO defect_types (name, created_at) VALUES (:name, NOW())");
                        $stmtInsDef->execute([':name' => $customName]);
                        $defectTypeId = (int)$pdo->lastInsertId();
                    }
                }

                if ($defectTypeId > 0) {
                    $stmtInsNg = $pdo->prepare("INSERT INTO inspection_ng_records (inspection_sample_id, defect_type_id, qty_ng, remark, created_at) VALUES (:spid, :dtid, :qty, :rem, NOW())");
                    $stmtInsNg->execute([
                        ':spid' => $sampleId,
                        ':dtid' => $defectTypeId,
                        ':qty'  => max(1, $qtyNg),
                        ':rem'  => $remark
                    ]);
                }
            }
        }
    }

    // 4. Calculate new session status
    $newStatus = 'in_progress';
    $closedAt = null;

    if ($newNgCount >= $rejectLimit) {
        $newStatus = 'rejected';
        $closedAt = date('Y-m-d H:i:s');

        // Auto log print rejection sheet
        $stmtInsPrint = $pdo->prepare("INSERT INTO rejection_sheet_prints (inspection_session_id, print_type, printed_at) VALUES (:sid, 'auto', NOW())");
        $stmtInsPrint->execute([':sid' => $sessionId]);
    } elseif ($currentSampleNo >= $maxSampleSize) {
        $newStatus = 'passed';
        $closedAt = date('Y-m-d H:i:s');
    }

    // 5. Update inspection_sessions
    $stmtUpd = $pdo->prepare("UPDATE inspection_sessions SET 
        samples_checked = :schecked, 
        ng_count = :ngcnt, 
        status = :st, 
        closed_at = :cat 
        WHERE id = :sid");
    $stmtUpd->execute([
        ':schecked' => $currentSampleNo,
        ':ngcnt'    => $newNgCount,
        ':st'       => $newStatus,
        ':cat'      => $closedAt,
        ':sid'      => $sessionId
    ]);

    // Fetch updated NG records list for real-time history widget
    $stmtNgList = $pdo->prepare("SELECT n.*, d.name as defect_name, s.sample_number 
                                  FROM inspection_ng_records n 
                                  JOIN inspection_samples s ON s.id = n.inspection_sample_id 
                                  JOIN defect_types d ON d.id = n.defect_type_id 
                                  WHERE s.inspection_session_id = :sid 
                                  ORDER BY n.id DESC");
    $stmtNgList->execute([':sid' => $sessionId]);
    $ngRecords = $stmtNgList->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'current_sample' => $currentSampleNo,
        'max_sample' => $maxSampleSize,
        'ng_count' => $newNgCount,
        'reject_limit' => $rejectLimit,
        'status' => $newStatus,
        'is_finished' => ($newStatus !== 'in_progress'),
        'ng_records' => $ngRecords,
        'message' => ($newStatus === 'rejected') ? 'REJECTED: Jumlah NG telah mencapai batas Rejection Sheet!' : (($newStatus === 'passed') ? 'PASSED: Seluruh sample selesai diperiksa dan lolos OQC.' : 'Sample berhasil diperiksa.')
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Kesalahan DB: ' . $e->getMessage()
    ]);
}
