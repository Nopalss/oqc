<?php
/**
 * Create / Start New Inspection Session Handler
 * Redirects to Unified Full-Screen Workbench (session.php)
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

$pdo = getDB();

// Handle Form Submission / AJAX request to initiate session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'start_session') {
    $partCode = strtoupper(trim(sanitize($_POST['part_code'] ?? '')));
    $lotNumber = strtoupper(trim(sanitize($_POST['lot_number'] ?? '')));
    $didId = (int)($_POST['did_id'] ?? 0);
    $kanbanItemId = (int)($_POST['kanban_item_id'] ?? 0);
    $partId = (int)($_POST['part_id'] ?? 0);
    $totalScannedQty = (int)($_POST['total_scanned_qty'] ?? 0);
    $excessQty = (int)($_POST['excess_qty'] ?? 0);
    
    // Fetch Assigned AQL Level for Part Code & Query official sample_size from aql_standards
    $partAqlLevel = 'G-II';
    if (!empty($partCode) && $pdo) {
        $stmtAqlLvl = $pdo->prepare("SELECT aql_level FROM master_parts WHERE UPPER(part_code) = UPPER(:pcode) LIMIT 1");
        $stmtAqlLvl->execute([':pcode' => $partCode]);
        $fetchedLvl = $stmtAqlLvl->fetchColumn();
        if (!empty($fetchedLvl)) $partAqlLevel = $fetchedLvl;
    }

    $aqlCalcQty = ($totalScannedQty > 0) ? $totalScannedQty : 500;
    if ($kanbanItemId > 0 && $pdo) {
        $stmtKQ = $pdo->prepare("SELECT qty FROM kanban_items WHERE id = :kid LIMIT 1");
        $stmtKQ->execute([':kid' => $kanbanItemId]);
        $kTargetQty = (int)$stmtKQ->fetchColumn();
        if ($kTargetQty > 0) $aqlCalcQty = $kTargetQty;
    }

    $stmtAqlStd = $pdo->prepare("SELECT sample_size, reject_number FROM aql_standards WHERE inspection_level = :lvl AND :qty BETWEEN qty_min AND qty_max LIMIT 1");
    $stmtAqlStd->execute([':lvl' => $partAqlLevel, ':qty' => $aqlCalcQty]);
    $aqlStdRow = $stmtAqlStd->fetch(PDO::FETCH_ASSOC);

    if (!$aqlStdRow) {
        $stmtAqlStdFB = $pdo->prepare("SELECT sample_size, reject_number FROM aql_standards WHERE :qty BETWEEN qty_min AND qty_max LIMIT 1");
        $stmtAqlStdFB->execute([':qty' => $aqlCalcQty]);
        $aqlStdRow = $stmtAqlStdFB->fetch(PDO::FETCH_ASSOC);
    }

    if ($aqlStdRow) {
        $sampleSize = (int)$aqlStdRow['sample_size'];
        $rejectNumber = (int)$aqlStdRow['reject_number'];
    } else {
        $sampleSize = (int)($_POST['sample_size'] ?? 50);
        $rejectNumber = (int)($_POST['reject_number'] ?? 1);
    }

    $scannedLabelsRaw = $_POST['scanned_labels'] ?? '[]';
    $scannedLabels = is_array($scannedLabelsRaw) ? $scannedLabelsRaw : json_decode($scannedLabelsRaw, true);
    if (!is_array($scannedLabels)) {
        $scannedLabels = [];
    }

    $inspectionType = sanitize($_POST['inspection_type'] ?? 'kanban');
    if (!in_array($inspectionType, ['kanban', 'safety_stock'])) {
        $inspectionType = 'kanban';
    }

    // Auto-create/Ensure DID record exists if didId is 0 but partCode & lotNumber provided
    if ($didId === 0 && !empty($partCode) && !empty($lotNumber) && $pdo) {
        $stmtChkDid = $pdo->prepare("SELECT id FROM daily_inspection_data WHERE UPPER(part_code) = :pcode AND UPPER(lot_number) = :lot ORDER BY id DESC LIMIT 1");
        $stmtChkDid->execute([':pcode' => $partCode, ':lot' => $lotNumber]);
        $didId = (int)$stmtChkDid->fetchColumn();

        if ($didId === 0) {
            $stmtInsDid = $pdo->prepare("INSERT INTO daily_inspection_data (part_code, part_name, lot_number, cavity, inspecting_date, status_inspect, pic, created_at) VALUES (:pcode, :pname, :lot, '1', CURDATE(), 'OK', 'SYSTEM', NOW())");
            $stmtInsDid->execute([':pcode' => $partCode, ':pname' => 'Part ' . $partCode, ':lot' => $lotNumber]);
            $didId = (int)$pdo->lastInsertId();
        }
    }

    // Auto-create/Ensure ad-hoc kanban_items entry exists for Safety Stock direct warehouse scan
    if ($inspectionType === 'safety_stock' && $kanbanItemId === 0 && !empty($partCode) && $pdo) {
        $ssKanbanNo = 'SS-' . ($lotNumber ?: 'GUDANG');
        $stmtChkK = $pdo->prepare("SELECT id FROM kanban_items WHERE UPPER(item_code) = :pcode AND (kanban_no = :kno OR plan_type = 'safety_stock') ORDER BY id DESC LIMIT 1");
        $stmtChkK->execute([':pcode' => $partCode, ':kno' => $ssKanbanNo]);
        $existingKId = (int)$stmtChkK->fetchColumn();

        if ($existingKId > 0) {
            $kanbanItemId = $existingKId;
        } else {
            $stmtBatch = $pdo->query("SELECT id FROM kanban_batches WHERE plan_type = 'safety_stock' ORDER BY id DESC LIMIT 1");
            $batchId = (int)$stmtBatch->fetchColumn();
            if ($batchId === 0) {
                $stmtInsBatch = $pdo->prepare("INSERT INTO kanban_batches (plan_type, vendor, document_number, import_method, imported_at) VALUES ('safety_stock', 'Safety Stock Storage', 'DOC-SAFETY-STOCK', 'manual', NOW())");
                $stmtInsBatch->execute();
                $batchId = (int)$pdo->lastInsertId();
            }

            $partName = 'Part ' . $partCode;
            $stmtMP = $pdo->prepare("SELECT part_name FROM master_parts WHERE id = :pid OR UPPER(part_code) = UPPER(:pcode) ORDER BY CASE WHEN id = :pid2 THEN 1 ELSE 2 END ASC LIMIT 1");
            $stmtMP->execute([':pid' => $partId ?: 0, ':pcode' => $partCode, ':pid2' => $partId ?: 0]);
            $pNameFetched = $stmtMP->fetchColumn();
            if ($pNameFetched) $partName = $pNameFetched;

            $stmtInsKItem = $pdo->prepare("INSERT INTO kanban_items (batch_id, plan_type, kanban_no, item_code, item_description, customer, req_date, qty, str_loc, check_type, remark, created_at) VALUES (:bid, 'safety_stock', :kno, :code, :desc, 'INTERNAL SAFETY STOCK', NOW(), :qty, 'WH-SS', 'Safety Stock', 'Direct Warehouse Scan', NOW())");
            $stmtInsKItem->execute([
                ':bid'  => $batchId,
                ':kno'  => $ssKanbanNo,
                ':code' => $partCode,
                ':desc' => $partName,
                ':qty'  => $totalScannedQty
            ]);
            $kanbanItemId = (int)$pdo->lastInsertId();
        }
    }

    if ($didId > 0 && $sampleSize > 0 && $pdo) {
        try {
            // Reuse existing 'in_progress' session HANYA jika kanban_item_id cocok atau (did_id & inspection_type cocok)
            // Cegah duplikasi baris session untuk kanban_item_id yang sama
            if ($kanbanItemId > 0) {
                $stmtChk = $pdo->prepare("SELECT id FROM inspection_sessions WHERE (kanban_item_id = :kid OR (did_id = :did AND inspection_type = :itype AND kanban_item_id IS NULL)) AND status = 'in_progress' ORDER BY id DESC LIMIT 1");
                $stmtChk->execute([':kid' => $kanbanItemId, ':did' => $didId, ':itype' => $inspectionType]);
            } else {
                $stmtChk = $pdo->prepare("SELECT id FROM inspection_sessions WHERE did_id = :did AND status = 'in_progress' AND inspection_type = :itype ORDER BY id DESC LIMIT 1");
                $stmtChk->execute([':did' => $didId, ':itype' => $inspectionType]);
            }
            $existingSession = $stmtChk->fetch(PDO::FETCH_ASSOC);

            if ($existingSession) {
                $sessionId = (int)$existingSession['id'];
                // Update total_scanned_qty and excess_qty
                $stmtUpdS = $pdo->prepare("UPDATE inspection_sessions SET total_scanned_qty = :tqty, excess_qty = :eqty, sample_size = :ssize, reject_number = :rej WHERE id = :sid");
                $stmtUpdS->execute([
                    ':tqty'  => $totalScannedQty,
                    ':eqty'  => $excessQty,
                    ':ssize' => $sampleSize,
                    ':rej'   => $rejectNumber,
                    ':sid'   => $sessionId
                ]);
                if ($kanbanItemId > 0) {
                    $stmtUpdK = $pdo->prepare("UPDATE inspection_sessions SET kanban_item_id = :kid WHERE id = :sid AND kanban_item_id IS NULL");
                    $stmtUpdK->execute([':kid' => $kanbanItemId, ':sid' => $sessionId]);
                }
            } else {
                $stmtIns = $pdo->prepare("INSERT INTO inspection_sessions 
                    (inspection_type, did_id, kanban_item_id, part_id, sample_size, total_scanned_qty, excess_qty, reject_number, samples_checked, ng_count, status, started_at) 
                    VALUES (:itype, :did, :kanban, :part, :ssize, :tqty, :eqty, :rej, 0, 0, 'in_progress', NOW())");
                $stmtIns->execute([
                    ':itype'  => $inspectionType,
                    ':did'    => $didId,
                    ':kanban' => $kanbanItemId ?: null,
                    ':part'   => $partId ?: null,
                    ':ssize'  => $sampleSize,
                    ':tqty'   => $totalScannedQty,
                    ':eqty'   => $excessQty,
                    ':rej'    => $rejectNumber
                ]);
                $sessionId = (int)$pdo->lastInsertId();
            }

            // Insert scanned labels into inspection_session_lots
            if ($sessionId > 0 && !empty($scannedLabels)) {
                $stmtInsLot = $pdo->prepare("INSERT INTO inspection_session_lots 
                    (inspection_session_id, ref_number, lot_number, qty, scanned_qr_raw, remarks, created_at) 
                    VALUES (:sid, :ref, :lot, :qty, :raw, :rem, NOW())");
                foreach ($scannedLabels as $lbl) {
                    $lRef = sanitize($lbl['Z5'] ?? ($lbl['ref_number'] ?? ''));
                    $lLot = sanitize($lbl['Z2'] ?? ($lbl['lot_number'] ?? $lotNumber));
                    $lQty = (int)($lbl['Z3'] ?? ($lbl['qty'] ?? 0));
                    $lRem = sanitize($lbl['Z4'] ?? ($lbl['remarks'] ?? ''));
                    $lRaw = sanitize($lbl['raw'] ?? '');

                    if (!empty($lLot)) {
                        // Check duplicate ref_number for this session
                        $stmtChkRef = $pdo->prepare("SELECT id FROM inspection_session_lots WHERE inspection_session_id = :sid AND (ref_number = :ref OR (ref_number IS NULL AND lot_number = :lot AND qty = :qty)) LIMIT 1");
                        $stmtChkRef->execute([':sid' => $sessionId, ':ref' => $lRef ?: null, ':lot' => $lLot, ':qty' => $lQty]);
                        if (!$stmtChkRef->fetch()) {
                            $stmtInsLot->execute([
                                ':sid' => $sessionId,
                                ':ref' => $lRef ?: null,
                                ':lot' => $lLot,
                                ':qty' => $lQty,
                                ':raw' => $lRaw ?: null,
                                ':rem' => $lRem ?: null
                            ]);
                        }
                    }
                }
            }

            // Deduct / Fulfill from available Safety Stock if requested
            $useSafetyStockQty = (int)($_POST['use_safety_stock_qty'] ?? 0);
            $selectedSsSessionIdsRaw = $_POST['selected_ss_session_ids'] ?? '[]';
            $selectedSsSessionIds = is_array($selectedSsSessionIdsRaw) ? $selectedSsSessionIdsRaw : json_decode($selectedSsSessionIdsRaw, true);
            if (!is_array($selectedSsSessionIds)) {
                $selectedSsSessionIds = [];
            }
            $selectedSsSessionIds = array_values(array_unique(array_filter(array_map('intval', $selectedSsSessionIds))));
            $totalAllocatedSs = 0;

            if ($sessionId > 0 && $useSafetyStockQty > 0 && $inspectionType === 'kanban' && !empty($partCode)) {
                if (!empty($selectedSsSessionIds)) {
                    $placeholders = implode(',', array_fill(0, count($selectedSsSessionIds), '?'));
                    $sqlAvail = "
                        SELECT s.id as ss_session_id, s.did_id, s.kanban_item_id, s.part_id, s.total_scanned_qty, 
                               isl.lot_number, isl.ref_number, isl.scanned_qr_raw
                        FROM inspection_sessions s
                        JOIN daily_inspection_data d ON d.id = s.did_id
                        LEFT JOIN inspection_session_lots isl ON isl.inspection_session_id = s.id
                        WHERE s.inspection_type = 'safety_stock'
                          AND s.status = 'passed'
                          AND (s.auto_fulfilled_by_session_id IS NULL OR s.auto_fulfilled_by_session_id = 0)
                          AND UPPER(d.part_code) = UPPER(?)
                          AND s.id IN ($placeholders)
                        ORDER BY s.started_at ASC, s.id ASC
                    ";
                    $stmtAvailSS = $pdo->prepare($sqlAvail);
                    $stmtAvailSS->execute(array_merge([$partCode], $selectedSsSessionIds));
                } else {
                    $stmtAvailSS = $pdo->prepare("
                        SELECT s.id as ss_session_id, s.did_id, s.kanban_item_id, s.part_id, s.total_scanned_qty, 
                               isl.lot_number, isl.ref_number, isl.scanned_qr_raw
                        FROM inspection_sessions s
                        JOIN daily_inspection_data d ON d.id = s.did_id
                        LEFT JOIN inspection_session_lots isl ON isl.inspection_session_id = s.id
                        WHERE s.inspection_type = 'safety_stock'
                          AND s.status = 'passed'
                          AND (s.auto_fulfilled_by_session_id IS NULL OR s.auto_fulfilled_by_session_id = 0)
                          AND UPPER(d.part_code) = UPPER(:pcode)
                        ORDER BY s.started_at ASC, s.id ASC
                    ");
                    $stmtAvailSS->execute([':pcode' => $partCode]);
                }
                $availSsRows = $stmtAvailSS->fetchAll(PDO::FETCH_ASSOC);

                $remainingToDeduct = $useSafetyStockQty;

                foreach ($availSsRows as $ssRow) {
                    if ($remainingToDeduct <= 0) break;

                    $ssSessionId = (int)$ssRow['ss_session_id'];
                    $ssTotalQty  = (int)($ssRow['total_scanned_qty'] ?: 0);
                    $ssLotNum    = $ssRow['lot_number'] ?: 'LOT-SS';
                    $ssRefNum    = $ssRow['ref_number'] ?: null;
                    $ssRaw       = $ssRow['scanned_qr_raw'] ?: null;

                    $takeQty = min($remainingToDeduct, $ssTotalQty > 0 ? $ssTotalQty : $remainingToDeduct);

                    // Check if current Safety Stock session needs to be split
                    if ($ssTotalQty > $takeQty) {
                        $leftoverQty = $ssTotalQty - $takeQty;

                        // 1. Update current SS session Qty to taken Qty and mark as fulfilled for this Kanban session
                        $stmtUpdSS = $pdo->prepare("UPDATE inspection_sessions SET total_scanned_qty = :tqty, auto_fulfilled_by_session_id = :ksid WHERE id = :ssid");
                        $stmtUpdSS->execute([':tqty' => $takeQty, ':ksid' => $sessionId, ':ssid' => $ssSessionId]);

                        // 2. Create a new Safety Stock kanban_items record for leftover Qty
                        $stmtInsLeftoverK = $pdo->prepare("INSERT INTO kanban_items 
                            (batch_id, plan_type, kanban_no, item_code, item_description, customer, req_date, qty, str_loc, supply_area, check_type, remark, created_at) 
                            VALUES (1, 'safety_stock', :kno, :code, 'Safety Stock Overflow', 'INTERNAL SAFETY STOCK', NOW(), :qty, 'WH-SS-SPLIT', 'SAFETY STOCK WAREHOUSE', 'Safety Stock', :rem, NOW())");
                        $stmtInsLeftoverK->execute([
                            ':kno'  => 'SS-' . $ssLotNum,
                            ':code' => $partCode,
                            ':qty'  => $leftoverQty,
                            ':rem'  => "Sisa Stok Safety Stock (Split dari Sesi #{$ssSessionId}, sisa {$leftoverQty} pcs)"
                        ]);
                        $newSSKId = (int)$pdo->lastInsertId();

                        // 3. Create a new Safety Stock inspection_sessions record for leftover Qty
                        $stmtInsLeftoverS = $pdo->prepare("INSERT INTO inspection_sessions 
                            (inspection_type, did_id, kanban_item_id, part_id, sample_size, total_scanned_qty, excess_qty, reject_number, samples_checked, ng_count, status, started_at, closed_at) 
                            VALUES ('safety_stock', :did, :kanban, :part, 1, :tqty, 0, 1, 1, 0, 'passed', NOW(), NOW())");
                        $stmtInsLeftoverS->execute([
                            ':did'    => $ssRow['did_id'],
                            ':kanban' => $newSSKId,
                            ':part'   => $ssRow['part_id'],
                            ':tqty'   => $leftoverQty
                        ]);
                        $newSSSessionId = (int)$pdo->lastInsertId();

                        // 4. Create inspection_session_lots for leftover Safety Stock
                        $stmtInsLeftoverL = $pdo->prepare("INSERT INTO inspection_session_lots 
                            (inspection_session_id, ref_number, lot_number, qty, scanned_qr_raw, remarks, lot_status, created_at) 
                            VALUES (:sid, :ref, :lot, :qty, :raw, 'Sisa Split Safety Stock', 'ok', NOW())");
                        $stmtInsLeftoverL->execute([
                            ':sid' => $newSSSessionId,
                            ':ref' => $ssRefNum,
                            ':lot' => $ssLotNum,
                            ':qty' => $leftoverQty,
                            ':raw' => $ssRaw
                        ]);
                    } else {
                        // Entire SS session consumed
                        $stmtLinkSS = $pdo->prepare("UPDATE inspection_sessions SET auto_fulfilled_by_session_id = :ksid WHERE id = :ssid");
                        $stmtLinkSS->execute([':ksid' => $sessionId, ':ssid' => $ssSessionId]);
                    }

                    // Insert Safety Stock lot into Kanban session lots
                    $stmtInsSSLot = $pdo->prepare("INSERT INTO inspection_session_lots 
                        (inspection_session_id, ref_number, lot_number, qty, scanned_qr_raw, remarks, lot_status, created_at) 
                        VALUES (:sid, :ref, :lot, :qty, :raw, :rem, 'ok', NOW())");
                    $stmtInsSSLot->execute([
                        ':sid' => $sessionId,
                        ':ref' => $ssRefNum,
                        ':lot' => $ssLotNum,
                        ':qty' => $takeQty,
                        ':raw' => $ssRaw,
                        ':rem' => 'Alokasi Safety Stock (Sesi #' . $ssSessionId . ')'
                    ]);

                    $remainingToDeduct -= $takeQty;
                    $totalAllocatedSs  += $takeQty;
                }
            }

            // Check if 100% Kanban Qty is fulfilled by Safety Stock (no physical scan needed)
            if ($sessionId > 0 && $kanbanItemId > 0) {
                $stmtKTarget = $pdo->prepare("SELECT qty FROM kanban_items WHERE id = :kid LIMIT 1");
                $stmtKTarget->execute([':kid' => $kanbanItemId]);
                $targetKanbanQty = (int)$stmtKTarget->fetchColumn();

                if ($targetKanbanQty > 0 && $totalAllocatedSs >= $targetKanbanQty) {
                    // Mark Kanban session PASSED immediately and set total_scanned_qty to targetKanbanQty!
                    $stmtPass100 = $pdo->prepare("UPDATE inspection_sessions SET status = 'passed', total_scanned_qty = :tqty, samples_checked = sample_size, closed_at = NOW() WHERE id = :sid");
                    $stmtPass100->execute([':tqty' => $targetKanbanQty, ':sid' => $sessionId]);
                }
            }

            header('Content-Type: application/json');
            echo json_encode([
                'success'    => true,
                'session_id' => $sessionId,
                'url'        => base_url('modules/inspection/session.php?id=' . $sessionId)
            ]);
            exit;
        } catch (PDOException $e) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Gagal membuat sesi inspeksi: ' . $e->getMessage()
            ]);
            exit;
        }
    } else {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Data DID atau Sample Size tidak valid!'
        ]);
        exit;
    }
}

// Default GET: redirect to unified session workbench with scan overlay active
redirect('modules/inspection/session.php?scan_new=1');
