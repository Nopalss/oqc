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
    $sampleSize = (int)($_POST['sample_size'] ?? 32);
    $totalScannedQty = (int)($_POST['total_scanned_qty'] ?? $sampleSize);
    $excessQty = (int)($_POST['excess_qty'] ?? 0);
    $rejectNumber = (int)($_POST['reject_number'] ?? 1);

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
            if (!empty($partId)) {
                $stmtPName = $pdo->prepare("SELECT part_name FROM master_parts WHERE id = :pid LIMIT 1");
                $stmtPName->execute([':pid' => $partId]);
                $pNameFetched = $stmtPName->fetchColumn();
                if ($pNameFetched) $partName = $pNameFetched;
            }

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
            // Reuse existing 'in_progress' session if one already exists for this DID & kanban
            $stmtChk = $pdo->prepare("SELECT id FROM inspection_sessions WHERE did_id = :did AND status = 'in_progress' ORDER BY id DESC LIMIT 1");
            $stmtChk->execute([':did' => $didId]);
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
