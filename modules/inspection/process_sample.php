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
            $batchValues = [];
            $batchParams = [];
            $batchSize = 250;
            $cnt = 0;
            for ($i = $startNum; $i <= $maxSampleSize; $i++) {
                $batchValues[] = "(:sid_{$cnt}, :snum_{$cnt}, 'OK', NOW())";
                $batchParams[":sid_{$cnt}"] = $sessionId;
                $batchParams[":snum_{$cnt}"] = $i;
                $cnt++;
                if (count($batchValues) >= $batchSize) {
                    $sqlBatch = "INSERT INTO inspection_samples (inspection_session_id, sample_number, result, checked_at) VALUES " . implode(',', $batchValues);
                    $stmtBatch = $pdo->prepare($sqlBatch);
                    $stmtBatch->execute($batchParams);
                    $batchValues = [];
                    $batchParams = [];
                }
            }
            if (!empty($batchValues)) {
                $sqlBatch = "INSERT INTO inspection_samples (inspection_session_id, sample_number, result, checked_at) VALUES " . implode(',', $batchValues);
                $stmtBatch = $pdo->prepare($sqlBatch);
                $stmtBatch->execute($batchParams);
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

        // Auto-sync daily aggregate summary for dashboard
        if ($newStatus !== 'in_progress') {
            syncDailySummaryForSession($pdo, $sessionId);
        }

        // Auto-sync Kanban lifecycle status
        if (!empty($session['kanban_item_id'])) {
            syncKanbanStatus($pdo, $session['kanban_item_id']);
        }

        // Fetch updated active (non-cancelled) NG records
        $stmtNgList = $pdo->prepare("SELECT n.*, d.name as defect_name, s.sample_number,
                                            COALESCE(n.ref_number, sl.ref_number) as ref_number,
                                            COALESCE(n.lot_number, sl.lot_number) as lot_number
                                      FROM inspection_ng_records n 
                                      JOIN inspection_samples s ON s.id = n.inspection_sample_id 
                                      JOIN defect_types d ON d.id = n.defect_type_id 
                                      LEFT JOIN inspection_session_lots sl ON sl.id = n.session_lot_id
                                      WHERE n.inspection_session_id = :sid AND (n.is_cancelled IS NULL OR n.is_cancelled = 0)
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

    // 2. Extract Box/Lot selection for NG samples
    $sessionLotId = (int)($_POST['session_lot_id'] ?? $_POST['session_lot'] ?? 0);
    $refNumber = null;
    $lotNumber = null;

    if ($sessionLotId > 0) {
        $stmtLotRow = $pdo->prepare("SELECT ref_number, lot_number FROM inspection_session_lots WHERE id = :lid AND inspection_session_id = :sid");
        $stmtLotRow->execute([':lid' => $sessionLotId, ':sid' => $sessionId]);
        $lotRow = $stmtLotRow->fetch(PDO::FETCH_ASSOC);
        if ($lotRow) {
            $refNumber = $lotRow['ref_number'];
            $lotNumber = $lotRow['lot_number'];
        } else {
            $sessionLotId = null;
        }
    } else {
        $sessionLotId = null;
    }

    // Insert Sample Log
    $stmtInsSample = $pdo->prepare("INSERT INTO inspection_samples (inspection_session_id, session_lot_id, sample_number, result, checked_at) VALUES (:sid, :slid, :snum, :res, NOW())");
    $stmtInsSample->execute([
        ':sid'  => $sessionId,
        ':slid' => $sessionLotId,
        ':snum' => $currentSampleNo,
        ':res'  => $result
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
                    $partIdVal = !empty($session['part_id']) ? (int)$session['part_id'] : null;
                    $stmtInsNg = $pdo->prepare("INSERT INTO inspection_ng_records 
                        (inspection_sample_id, inspection_session_id, session_lot_id, part_id, ref_number, lot_number, defect_type_id, qty_ng, remark, created_at) 
                        VALUES (:spid, :sid, :slid, :pid, :refn, :lotn, :dtid, :qty, :rem, NOW())");
                    $stmtInsNg->execute([
                        ':spid' => $sampleId,
                        ':sid'  => $sessionId,
                        ':slid' => $sessionLotId,
                        ':pid'  => $partIdVal,
                        ':refn' => $refNumber,
                        ':lotn' => $lotNumber,
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

        // Auto-tag: tandai semua lot yang memiliki NG records sebagai 'ng_found'
        // Menggunakan index idx_ngr_session_part tanpa join inspection_samples
        try {
            $pdo->prepare("
                UPDATE inspection_session_lots isl
                SET isl.lot_status = 'ng_found',
                    isl.action_noted_at = NOW()
                WHERE isl.inspection_session_id = :sid
                  AND isl.lot_status = 'ok'
                  AND EXISTS (
                      SELECT 1
                      FROM inspection_ng_records n
                      WHERE n.inspection_session_id = :sid2
                        AND n.session_lot_id = isl.id
                        AND (n.is_cancelled IS NULL OR n.is_cancelled = 0)
                  )
            ")->execute([':sid' => $sessionId, ':sid2' => $sessionId]);
        } catch (Exception $eTag) {
            // Non-fatal: abaikan jika kolom belum ada (backward compat)
        }
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

    // Auto-create Safety Stock if session passed with excess_qty > 0
    if ($newStatus === 'passed' && function_exists('createAutoSafetyStockForSession')) {
        createAutoSafetyStockForSession($pdo, $sessionId);
    }

    // Auto-sync daily aggregate summary for dashboard
    if ($newStatus !== 'in_progress') {
        syncDailySummaryForSession($pdo, $sessionId);
    }

    // Auto-sync Kanban lifecycle status
    if (!empty($session['kanban_item_id'])) {
        syncKanbanStatus($pdo, $session['kanban_item_id']);
    }

    // Fetch updated active (non-cancelled) NG records list for real-time history widget
    $stmtNgList = $pdo->prepare("SELECT n.*, d.name as defect_name, s.sample_number,
                                        COALESCE(n.ref_number, sl.ref_number) as ref_number,
                                        COALESCE(n.lot_number, sl.lot_number) as lot_number
                                  FROM inspection_ng_records n 
                                  JOIN inspection_samples s ON s.id = n.inspection_sample_id 
                                  JOIN defect_types d ON d.id = n.defect_type_id 
                                  LEFT JOIN inspection_session_lots sl ON sl.id = n.session_lot_id
                                  WHERE n.inspection_session_id = :sid AND (n.is_cancelled IS NULL OR n.is_cancelled = 0)
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
