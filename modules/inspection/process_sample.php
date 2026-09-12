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
                    $stmtInsNg = $pdo->prepare("INSERT INTO inspection_ng_records (inspection_sample_id, session_lot_id, ref_number, lot_number, defect_type_id, qty_ng, remark, created_at) VALUES (:spid, :slid, :refn, :lotn, :dtid, :qty, :rem, NOW())");
                    $stmtInsNg->execute([
                        ':spid' => $sampleId,
                        ':slid' => $sessionLotId,
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
        // Ini memungkinkan frontend menampilkan Panel Tindakan Lot NG secara langsung
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
                      JOIN inspection_samples sp ON sp.id = n.inspection_sample_id
                      WHERE sp.inspection_session_id = :sid2
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

        // Check if session has excess_qty > 0 and create auto Safety Stock overflow
        try {
            $stmtSessEx = $pdo->prepare("SELECT s.excess_qty, s.kanban_item_id, s.part_id, did.part_code, did.part_name, b.id as batch_id
                                         FROM inspection_sessions s
                                         JOIN daily_inspection_data did ON did.id = s.did_id
                                         LEFT JOIN kanban_items k ON k.id = s.kanban_item_id
                                         LEFT JOIN kanban_batches b ON b.id = k.batch_id
                                         WHERE s.id = :sid LIMIT 1");
            $stmtSessEx->execute([':sid' => $sessionId]);
            $sessEx = $stmtSessEx->fetch(PDO::FETCH_ASSOC);

            if ($sessEx && (int)$sessEx['excess_qty'] > 0) {
                $excessQty = (int)$sessEx['excess_qty'];
                
                // Fetch last scanned lot details for this session (lot_number, ref_number, scanned_qr_raw)
                $stmtLastLot = $pdo->prepare("SELECT lot_number, ref_number, scanned_qr_raw FROM inspection_session_lots WHERE inspection_session_id = :sid ORDER BY id DESC LIMIT 1");
                $stmtLastLot->execute([':sid' => $sessionId]);
                $lastLotRow = $stmtLastLot->fetch(PDO::FETCH_ASSOC);

                $lastLotNumber = $lastLotRow['lot_number'] ?? 'LOT-OVERFLOW';
                $lastRefNumber = $lastLotRow['ref_number'] ?? null;
                $lastQrRaw     = $lastLotRow['scanned_qr_raw'] ?? null;

                // Check if Safety Stock overflow item was already created for this session to prevent duplicate processing
                $checkRef = "AUTO-SS-SESS-" . $sessionId;
                $stmtChkSS = $pdo->prepare("SELECT id FROM kanban_items WHERE remark LIKE :chk LIMIT 1");
                $stmtChkSS->execute([':chk' => '%' . $checkRef . '%']);
                $existingSSItem = $stmtChkSS->fetch(PDO::FETCH_ASSOC);

                if (!$existingSSItem) {
                    $batchId = $sessEx['batch_id'] ?: 1;
                    $pCode   = $sessEx['part_code'];
                    $pName   = $sessEx['part_name'];
                    $partId  = $sessEx['part_id'] ?: null;

                    // 1. Create kanban_items entry for Safety Stock
                    $stmtInsSS = $pdo->prepare("INSERT INTO kanban_items 
                        (batch_id, plan_type, kanban_no, item_code, item_description, customer, req_date, qty, str_loc, supply_area, check_type, remark, created_at) 
                        VALUES (:bid, 'safety_stock', :kno, :code, :desc, 'INTERNAL SAFETY STOCK', NOW(), :qty, 'WH-SS-OVERFLOW', 'SAFETY STOCK WAREHOUSE', 'Safety Stock', :rem, NOW())");
                    $stmtInsSS->execute([
                        ':bid'  => $batchId,
                        ':kno'  => 'SS-' . $lastLotNumber,
                        ':code' => $pCode,
                        ':desc' => $pName,
                        ':qty'  => $excessQty,
                        ':rem'  => "Auto Safety Stock Sisa Excess Kanban (Sisa Qty: {$excessQty} pcs, Lot: {$lastLotNumber}" . ($lastRefNumber ? ", Ref No: {$lastRefNumber}" : "") . ") [Ref: {$checkRef}]"
                    ]);
                    $ssKanbanItemId = (int)$pdo->lastInsertId();

                    // 2. Ensure DID record exists for Safety Stock
                    $stmtChkDid = $pdo->prepare("SELECT id FROM daily_inspection_data WHERE UPPER(part_code) = UPPER(:pcode) AND UPPER(lot_number) = UPPER(:lot) ORDER BY id DESC LIMIT 1");
                    $stmtChkDid->execute([':pcode' => $pCode, ':lot' => $lastLotNumber]);
                    $ssDidId = (int)$stmtChkDid->fetchColumn();

                    if ($ssDidId === 0) {
                        $stmtInsDid = $pdo->prepare("INSERT INTO daily_inspection_data (part_code, part_name, lot_number, cavity, inspecting_date, status_inspect, pic, created_at) VALUES (:pcode, :pname, :lot, '1', CURDATE(), 'OK', 'SAFETY_STOCK_OVERFLOW', NOW())");
                        $stmtInsDid->execute([':pcode' => $pCode, ':pname' => $pName, ':lot' => $lastLotNumber]);
                        $ssDidId = (int)$pdo->lastInsertId();
                    }

                    // 3. Auto-create PASSED inspection_sessions for Safety Stock
                    $stmtInsSSSession = $pdo->prepare("INSERT INTO inspection_sessions 
                        (inspection_type, did_id, kanban_item_id, part_id, sample_size, total_scanned_qty, excess_qty, reject_number, samples_checked, ng_count, status, started_at, closed_at) 
                        VALUES ('safety_stock', :did, :kanban, :part, 1, :tqty, 0, 1, 1, 0, 'passed', NOW(), NOW())");
                    $stmtInsSSSession->execute([
                        ':did'    => $ssDidId,
                        ':kanban' => $ssKanbanItemId,
                        ':part'   => $partId,
                        ':tqty'   => $excessQty
                    ]);
                    $ssSessionId = (int)$pdo->lastInsertId();

                    // 4. Create inspection_session_lots entry for Safety Stock session
                    $stmtInsSSLot = $pdo->prepare("INSERT INTO inspection_session_lots 
                        (inspection_session_id, ref_number, lot_number, qty, scanned_qr_raw, remarks, lot_status, created_at) 
                        VALUES (:sid, :ref, :lot, :qty, :raw, :rem, 'ok', NOW())");
                    $stmtInsSSLot->execute([
                        ':sid' => $ssSessionId,
                        ':ref' => $lastRefNumber,
                        ':lot' => $lastLotNumber,
                        ':qty' => $excessQty,
                        ':raw' => $lastQrRaw,
                        ':rem' => "Sisa Kelebihan Kanban dari Sesi #" . $sessionId
                    ]);
                }
            }
        } catch (Exception $eExSS) {
            error_log("Error creating Safety Stock overflow: " . $eExSS->getMessage());
        }
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

    // Fetch updated active (non-cancelled) NG records list for real-time history widget
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
