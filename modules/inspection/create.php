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
    $deviceLineId = (int)($_POST['device_line_id'] ?? 0);
    $deviceLine = trim(sanitize($_POST['device_line'] ?? ''));

    // Sinkronisasi deviceLineId dan deviceLine dengan tabel master `lines`
    if ($deviceLineId > 0 && $pdo) {
        $stmtL = $pdo->prepare("SELECT id, name FROM `lines` WHERE id = :id LIMIT 1");
        $stmtL->execute([':id' => $deviceLineId]);
        $lineRow = $stmtL->fetch(PDO::FETCH_ASSOC);
        if ($lineRow) {
            $deviceLineId = (int)$lineRow['id'];
            $deviceLine = $lineRow['name'];
        }
    }
    if ($deviceLineId <= 0 && !empty($deviceLine) && $pdo) {
        $stmtL = $pdo->prepare("SELECT id, name FROM `lines` WHERE LOWER(name) = LOWER(:name) LIMIT 1");
        $stmtL->execute([':name' => $deviceLine]);
        $lineRow = $stmtL->fetch(PDO::FETCH_ASSOC);
        if ($lineRow) {
            $deviceLineId = (int)$lineRow['id'];
            $deviceLine = $lineRow['name'];
        }
    }
    if (empty($deviceLine)) {
        $deviceLine = 'Line 1';
        $deviceLineId = 1;
    }
    
    // Fetch Assigned AQL Level for Part Code & Query official sample_size from aql_standards
    $partAqlLevel = 'G-II';
    if (!empty($partCode) && $pdo) {
        $stmtAqlLvl = $pdo->prepare("SELECT aql_level FROM master_parts WHERE UPPER(part_code) = UPPER(:pcode) LIMIT 1");
        $stmtAqlLvl->execute([':pcode' => $partCode]);
        $fetchedLvl = $stmtAqlLvl->fetchColumn();
        if (!empty($fetchedLvl)) $partAqlLevel = $fetchedLvl;
    }

    $inspectionType = sanitize($_POST['inspection_type'] ?? 'kanban');
    if (!in_array($inspectionType, ['kanban', 'safety_stock'])) {
        $inspectionType = 'kanban';
    }

    $useSafetyStockQtyInput = (int)($_POST['use_safety_stock_qty'] ?? 0);
    $physicalScanQty = max(0, $totalScannedQty - $useSafetyStockQtyInput);

    $aqlCalcQty = ($useSafetyStockQtyInput > 0 && $inspectionType === 'kanban') ? max(1, $physicalScanQty) : max(1, $totalScannedQty);
    if ($kanbanItemId > 0 && $pdo) {
        $stmtKQ = $pdo->prepare("
            SELECT k.qty, COALESCE((
                SELECT SUM(s.total_scanned_qty - COALESCE(s.excess_qty, 0)) 
                FROM inspection_sessions s 
                WHERE s.kanban_item_id = k.id AND s.status = 'passed'
            ), 0) AS already_passed
            FROM kanban_items k 
            WHERE k.id = :kid 
            LIMIT 1
        ");
        $stmtKQ->execute([':kid' => $kanbanItemId]);
        $kRow = $stmtKQ->fetch(PDO::FETCH_ASSOC);
        if ($kRow) {
            $kTargetQty = (int)$kRow['qty'];
            $alreadyPassed = (int)$kRow['already_passed'];
            $remainingQty = max(1, $kTargetQty - $alreadyPassed);
            if ($totalScannedQty > 0) {
                $aqlCalcQty = ($useSafetyStockQtyInput > 0) ? max(1, $physicalScanQty) : $totalScannedQty;
            } else {
                $aqlCalcQty = $remainingQty;
            }
        }
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
            $inspectorId = (int)($_SESSION['user_id'] ?? 1);
            $existingSession = null;

            if ($kanbanItemId > 0 && $inspectionType === 'kanban') {
                $pdo->beginTransaction();

                // Lock baris kanban_items terlebih dahulu agar atomic & mencegah race condition perebutan antar line
                $stmtLock = $pdo->prepare("SELECT qty FROM kanban_items WHERE id = :kid LIMIT 1 FOR UPDATE");
                $stmtLock->execute([':kid' => $kanbanItemId]);
                $targetKanbanQty = (int)$stmtLock->fetchColumn();

                // Periksa seluruh sesi yang terhubung dengan kanban ini setelah lock didapat
                $stmtAllK = $pdo->prepare("
                    SELECT id, line_id, line_name, total_scanned_qty, excess_qty, status 
                    FROM inspection_sessions 
                    WHERE kanban_item_id = :kid 
                    ORDER BY id ASC
                ");
                $stmtAllK->execute([':kid' => $kanbanItemId]);
                $allSessions = $stmtAllK->fetchAll(PDO::FETCH_ASSOC);

                $sameLineInProgress = null;
                $totalAllocatedQty = 0;

                foreach ($allSessions as $sItem) {
                    $sLine = !empty($sItem['line_name']) ? trim($sItem['line_name']) : 'Line 1';
                    $sLineId = (int)($sItem['line_id'] ?? 0);
                    
                    // Sesi passed dan in_progress yang mengurangi kuota Kanban
                    // Sesi rejected TIDAK mengurangi kuota Kanban (karena barang NG harus diganti agar Kanban terpenuhi)
                    if ($sItem['status'] === 'passed' || $sItem['status'] === 'in_progress') {
                        $effectivePortion = (int)$sItem['total_scanned_qty'] - (int)($sItem['excess_qty'] ?? 0);
                        $totalAllocatedQty += max(0, $effectivePortion);
                    }

                    if ($sItem['status'] === 'in_progress') {
                        $isMatch = false;
                        if ($deviceLineId > 0 && $sLineId > 0) {
                            $isMatch = ($sLineId === $deviceLineId);
                        } else {
                            $isMatch = (strcasecmp($sLine, $deviceLine) === 0);
                        }
                        if ($isMatch) {
                            $sameLineInProgress = $sItem;
                        }
                    }
                }

                // 1. Jika di LINE YANG SAMA masih ada sesi in_progress: Wajib selesaikan dulu!
                if ($sameLineInProgress) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    if (ob_get_length()) ob_clean();
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => false,
                        'message' => "Kanban ini sedang berjalan di {$deviceLine} (Sesi #{$sameLineInProgress['id']}) dan belum selesai. Harap selesaikan sesi tersebut terlebih dahulu di {$deviceLine} sebelum memulai inspeksi berikutnya."
                    ]);
                    exit;
                }

                // 2. Cek sisa kuota Kanban
                $remainingQuota = max(0, $targetKanbanQty - $totalAllocatedQty);
                if ($targetKanbanQty > 0 && $remainingQuota <= 0) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    if (ob_get_length()) ob_clean();
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => false,
                        'message' => "Seluruh kuota Qty Kanban ({$targetKanbanQty} pcs) sudah teralokasi pada sesi-sesi sebelumnya."
                    ]);
                    exit;
                }

                // 3. Jika box/scanned qty melebihi sisa kuota Kanban, kelebihannya otomatis masuk Safety Stock (excess_qty)
                $physicalScan = max(0, $totalScannedQty - $useSafetyStockQtyInput);
                if ($targetKanbanQty > 0 && $physicalScan > $remainingQuota) {
                    $autoExcess = $physicalScan - $remainingQuota;
                    if ($autoExcess > $excessQty) {
                        $excessQty = $autoExcess;
                    }
                }

                // Validasi Cross-Session: Pastikan tidak ada label yang in_progress di sesi lain atau sudah PASSED di sesi lain
                if (!empty($scannedLabels)) {
                    foreach ($scannedLabels as $chkLbl) {
                        $cRef = strtoupper(trim(sanitize($chkLbl['Z5'] ?? ($chkLbl['ref_number'] ?? ''))));
                        if (!empty($cRef)) {
                            // 1. Cek in_progress di sesi lain
                            $stmtChkActive = $pdo->prepare("
                                SELECT s.id, s.line_name, s.inspection_type, ki.kanban_no 
                                FROM inspection_session_lots isl 
                                JOIN inspection_sessions s ON s.id = isl.inspection_session_id 
                                LEFT JOIN kanban_items ki ON ki.id = s.kanban_item_id
                                WHERE UPPER(isl.ref_number) = :ref AND s.status = 'in_progress'
                                LIMIT 1
                            ");
                            $stmtChkActive->execute([':ref' => $cRef]);
                            $activeSess = $stmtChkActive->fetch(PDO::FETCH_ASSOC);
                            if ($activeSess) {
                                $sessDesc = ($activeSess['inspection_type'] === 'safety_stock') ? 'Safety Stock' : ('Kanban ' . ($activeSess['kanban_no'] ?: ('#' . $activeSess['id'])));
                                echo json_encode([
                                    'success' => false,
                                    'message' => "Label Ref '{$cRef}' saat ini sedang aktif diinspeksi pada Sesi #{$activeSess['id']} [{$sessDesc}] Line {$activeSess['line_name']}."
                                ]);
                                exit;
                            }

                            // 2. Cek already passed (jika tipe kanban)
                            if ($inspectionType !== 'safety_stock') {
                                $stmtChkPassed = $pdo->prepare("
                                    SELECT s.id, ki.kanban_no 
                                    FROM inspection_session_lots isl 
                                    JOIN inspection_sessions s ON s.id = isl.inspection_session_id 
                                    LEFT JOIN kanban_items ki ON ki.id = s.kanban_item_id
                                    WHERE UPPER(isl.ref_number) = :ref 
                                      AND (isl.lot_result = 'passed' OR s.status = 'passed') 
                                      AND s.inspection_type = 'kanban'
                                    LIMIT 1
                                ");
                                $stmtChkPassed->execute([':ref' => $cRef]);
                                $passSess = $stmtChkPassed->fetch(PDO::FETCH_ASSOC);
                                if ($passSess) {
                                    echo json_encode([
                                        'success' => false,
                                        'message' => "Label Ref '{$cRef}' SUDAH PERNAH diinspeksi dan dinyatakan PASSED pada Sesi #{$passSess['id']} (Kanban " . ($passSess['kanban_no'] ?: '-') . "). Tidak dapat digunakan kembali demi mencegah data ganda!"
                                    ]);
                                    exit;
                                }
                            }
                        }
                    }
                }

                // Sesi baru di line ini (INSERT baru, tidak menimpa sesi line lain)
                $existingSession = null;
            } else {
                // Untuk non-kanban (Safety Stock), cek in_progress pada line yang sama
                $stmtChk = $pdo->prepare("SELECT id FROM inspection_sessions WHERE did_id = :did AND status = 'in_progress' AND inspection_type = :itype AND ((line_id IS NOT NULL AND line_id = :lid) OR (line_id IS NULL AND (line_name = :line OR line_name IS NULL))) ORDER BY id DESC LIMIT 1");
                $stmtChk->execute([':did' => $didId, ':itype' => $inspectionType, ':lid' => $deviceLineId ?: 0, ':line' => $deviceLine]);
                $existingSession = $stmtChk->fetch(PDO::FETCH_ASSOC);
            }

            if ($existingSession) {
                $sessionId = (int)$existingSession['id'];
                // Update existing in-progress session pada line yang sama
                $stmtUpdS = $pdo->prepare("UPDATE inspection_sessions SET total_scanned_qty = :tqty, batch_sample_size = :batch_ssize, use_safety_stock_qty = :usss, excess_qty = :eqty, sample_size = :ssize, reject_number = :rej, line_id = COALESCE(line_id, :lid), line_name = COALESCE(line_name, :line), inspector_id = COALESCE(inspector_id, :insp) WHERE id = :sid");
                $stmtUpdS->execute([
                    ':tqty'        => $totalScannedQty,
                    ':batch_ssize' => $sampleSize,
                    ':usss'        => $useSafetyStockQtyInput,
                    ':eqty'        => $excessQty,
                    ':ssize'       => $sampleSize,
                    ':rej'         => $rejectNumber,
                    ':lid'         => $deviceLineId ?: null,
                    ':line'        => $deviceLine,
                    ':insp'        => $inspectorId,
                    ':sid'         => $sessionId
                ]);
                if ($kanbanItemId > 0) {
                    $stmtUpdK = $pdo->prepare("UPDATE inspection_sessions SET kanban_item_id = :kid WHERE id = :sid AND kanban_item_id IS NULL");
                    $stmtUpdK->execute([':kid' => $kanbanItemId, ':sid' => $sessionId]);
                }
            } else {
                // INSERT baris sesi baru dengan flow_version = 2 dan mencatat line_id dan line_name
                $stmtIns = $pdo->prepare("INSERT INTO inspection_sessions 
                    (flow_version, inspection_type, did_id, kanban_item_id, part_id, inspector_id, line_id, line_name, sample_size, total_scanned_qty, batch_sample_size, use_safety_stock_qty, excess_qty, reject_number, samples_checked, ng_count, status, started_at) 
                    VALUES (2, :itype, :did, :kanban, :part, :insp, :lid, :line, :ssize, :tqty, :batch_ssize, :usss, :eqty, :rej, 0, 0, 'in_progress', NOW())");
                $stmtIns->execute([
                    ':itype'       => $inspectionType,
                    ':did'         => $didId,
                    ':kanban'      => $kanbanItemId ?: null,
                    ':part'        => $partId ?: null,
                    ':insp'        => $inspectorId,
                    ':lid'         => $deviceLineId ?: null,
                    ':line'        => $deviceLine,
                    ':ssize'       => $sampleSize,
                    ':tqty'        => $totalScannedQty,
                    ':batch_ssize' => $sampleSize,
                    ':usss'        => $useSafetyStockQtyInput,
                    ':eqty'        => $excessQty,
                    ':rej'         => $rejectNumber
                ]);
                $sessionId = (int)$pdo->lastInsertId();
            }

            // Insert scanned labels into inspection_session_lots with per-lot AQL
            if ($sessionId > 0 && !empty($scannedLabels)) {
                $stmtInsLot = $pdo->prepare("INSERT INTO inspection_session_lots 
                    (inspection_session_id, ref_number, lot_number, qty, aql_level, sample_code, sample_size, accept_number, reject_number, ng_count, lot_result, scanned_qr_raw, remarks, lot_status, created_at) 
                    VALUES (:sid, :ref, :lot, :qty, :aql_lvl, :spl_code, :spl_size, :acc, :rej, 0, 'in_progress', :raw, :rem, 'ok', NOW())");
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
                            // Query AQL for this specific lot qty
                            $stmtAqlLot = $pdo->prepare("SELECT sample_code, sample_size, accept_number, reject_number FROM aql_standards WHERE inspection_level = :lvl AND :qty BETWEEN qty_min AND qty_max LIMIT 1");
                            $stmtAqlLot->execute([':lvl' => $partAqlLevel, ':qty' => max(1, $lQty)]);
                            $lotAql = $stmtAqlLot->fetch(PDO::FETCH_ASSOC);
                            if (!$lotAql) {
                                $stmtAqlLotFB = $pdo->prepare("SELECT sample_code, sample_size, accept_number, reject_number FROM aql_standards WHERE :qty BETWEEN qty_min AND qty_max LIMIT 1");
                                $stmtAqlLotFB->execute([':qty' => max(1, $lQty)]);
                                $lotAql = $stmtAqlLotFB->fetch(PDO::FETCH_ASSOC);
                            }
                            $lSplCode = $lotAql['sample_code'] ?? 'H';
                            $lSplSize = (int)($lotAql['sample_size'] ?? 20);
                            $lAccNum  = (int)($lotAql['accept_number'] ?? 0);
                            $lRejNum  = (int)($lotAql['reject_number'] ?? 1);

                            $stmtInsLot->execute([
                                ':sid'      => $sessionId,
                                ':ref'      => $lRef ?: null,
                                ':lot'      => $lLot,
                                ':qty'      => $lQty,
                                ':aql_lvl'  => $partAqlLevel,
                                ':spl_code' => $lSplCode,
                                ':spl_size' => $lSplSize,
                                ':acc'      => $lAccNum,
                                ':rej'      => $lRejNum,
                                ':raw'      => $lRaw ?: null,
                                ':rem'      => $lRem ?: null
                            ]);
                        }
                    }
                }

                // Update aggregate sample_size on inspection_sessions
                $stmtSyncSpl = $pdo->prepare("UPDATE inspection_sessions SET sample_size = (SELECT COALESCE(SUM(sample_size), 0) FROM inspection_session_lots WHERE inspection_session_id = :sid AND lot_result != 'skipped') WHERE id = :sid2");
                $stmtSyncSpl->execute([':sid' => $sessionId, ':sid2' => $sessionId]);
            }

            // Deduct / Fulfill from available Safety Stock if requested
            $useSafetyStockQty = (int)($_POST['use_safety_stock_qty'] ?? 0);
            $selectedSsSessionIdsRaw = $_POST['selected_ss_session_ids'] ?? '[]';
            $selectedSsSessionIds = is_array($selectedSsSessionIdsRaw) ? $selectedSsSessionIdsRaw : json_decode($selectedSsSessionIdsRaw, true);
            if (!is_array($selectedSsSessionIds)) {
                $selectedSsSessionIds = [];
            }
            $selectedSsSessionIds = array_values(array_unique(array_filter(array_map('intval', $selectedSsSessionIds))));

            $selectedSsLotIdsRaw = $_POST['selected_ss_lot_ids'] ?? '[]';
            $selectedSsLotIds = is_array($selectedSsLotIdsRaw) ? $selectedSsLotIdsRaw : json_decode($selectedSsLotIdsRaw, true);
            if (!is_array($selectedSsLotIds)) {
                $selectedSsLotIds = [];
            }
            $selectedSsLotIds = array_values(array_unique(array_filter(array_map('strval', $selectedSsLotIds))));

            $totalAllocatedSs = 0;

            if ($sessionId > 0 && $useSafetyStockQty > 0 && $inspectionType === 'kanban' && !empty($partCode)) {
                if (!empty($selectedSsSessionIds)) {
                    $placeholders = implode(',', array_fill(0, count($selectedSsSessionIds), '?'));
                    $sqlAvail = "
                        SELECT s.id as ss_session_id, s.did_id, s.kanban_item_id, s.part_id, s.total_scanned_qty, s.original_ss_session_id,
                               COALESCE(d.lot_number, 'LOT-SS') as fallback_lot_number
                        FROM inspection_sessions s
                        JOIN daily_inspection_data d ON d.id = s.did_id
                        WHERE s.inspection_type = 'safety_stock'
                          AND s.status != 'in_progress'
                          AND (s.auto_fulfilled_by_session_id IS NULL OR s.auto_fulfilled_by_session_id = 0)
                          AND UPPER(d.part_code) = UPPER(?)
                          AND s.id IN ($placeholders)
                        ORDER BY s.started_at ASC, s.id ASC
                    ";
                    $stmtAvailSS = $pdo->prepare($sqlAvail);
                    $stmtAvailSS->execute(array_merge([$partCode], $selectedSsSessionIds));
                } else {
                    $stmtAvailSS = $pdo->prepare("
                        SELECT s.id as ss_session_id, s.did_id, s.kanban_item_id, s.part_id, s.total_scanned_qty, s.original_ss_session_id,
                               COALESCE(d.lot_number, 'LOT-SS') as fallback_lot_number
                        FROM inspection_sessions s
                        JOIN daily_inspection_data d ON d.id = s.did_id
                        WHERE s.inspection_type = 'safety_stock'
                          AND s.status != 'in_progress'
                          AND (s.auto_fulfilled_by_session_id IS NULL OR s.auto_fulfilled_by_session_id = 0)
                          AND UPPER(d.part_code) = UPPER(:pcode)
                        ORDER BY s.started_at ASC, s.id ASC
                    ");
                    $stmtAvailSS->execute([':pcode' => $partCode]);
                }
                $availSsRows = $stmtAvailSS->fetchAll(PDO::FETCH_ASSOC);

                $remainingToDeduct = $useSafetyStockQty;

                $stmtGetSsLots = $pdo->prepare("
                    SELECT isl.* 
                    FROM inspection_session_lots isl 
                    WHERE isl.inspection_session_id = :ssid 
                      AND (isl.lot_status = 'ok' OR isl.lot_status IS NULL)
                      AND (isl.lot_result IS NULL OR isl.lot_result IN ('passed', 'skipped'))
                    ORDER BY isl.id ASC
                ");

                $stmtInsSSLot = $pdo->prepare("INSERT INTO inspection_session_lots 
                    (inspection_session_id, ref_number, lot_number, qty, aql_level, sample_code, sample_size, accept_number, reject_number, ng_count, lot_result, closed_at, scanned_qr_raw, remarks, lot_status, created_at) 
                    VALUES (:sid, :ref, :lot, :qty, 'G-II', NULL, 0, 0, 0, 0, 'skipped', NOW(), :raw, :rem, 'ok', NOW())");

                foreach ($availSsRows as $ssRow) {
                    if ($remainingToDeduct <= 0) break;

                    $ssSessionId = (int)$ssRow['ss_session_id'];
                    $ssTotalQty  = (int)($ssRow['total_scanned_qty'] ?: 0);

                    // Fetch individual physical lots for this Safety Stock session
                    $stmtGetSsLots->execute([':ssid' => $ssSessionId]);
                    $sessionLots = $stmtGetSsLots->fetchAll(PDO::FETCH_ASSOC);

                    // If session has no rows in inspection_session_lots, create virtual fallback lot
                    if (empty($sessionLots)) {
                        $sessionLots = [
                            [
                                'id'             => 0,
                                'lot_number'     => $ssRow['fallback_lot_number'] ?: 'LOT-SS',
                                'ref_number'     => null,
                                'qty'            => $ssTotalQty,
                                'scanned_qr_raw' => null
                            ]
                        ];
                    }

                    $totalTakenFromSession = 0;
                    $leftoverLotsToInsert = [];
                    $untouchedLotIdsToMove = [];
                    $partiallyTakenLotUpdates = [];

                    foreach ($sessionLots as $lot) {
                        $lotQty = (int)($lot['qty'] ?? 0);
                        if ($lotQty <= 0) continue;

                        $lotId = (int)($lot['id'] ?? 0);
                        $lotIdStr = (string)$lotId;

                        // Check if this lot is selected by user
                        $isLotChecked = empty($selectedSsLotIds) 
                            || in_array($lotIdStr, $selectedSsLotIds, true) 
                            || ($lotId === 0 && in_array('s_' . $ssSessionId, $selectedSsLotIds, true));

                        if ($isLotChecked && $remainingToDeduct > 0) {
                            $takeFromLot = min($remainingToDeduct, $lotQty);

                            // Insert as separate individual lot into Kanban session
                            $stmtInsSSLot->execute([
                                ':sid' => $sessionId,
                                ':ref' => $lot['ref_number'] ?? null,
                                ':lot' => $lot['lot_number'] ?: 'LOT-SS',
                                ':qty' => $takeFromLot,
                                ':raw' => $lot['scanned_qr_raw'] ?? null,
                                ':rem' => 'Alokasi Safety Stock (Sesi #' . $ssSessionId . ', Lot #' . ($lot['lot_number'] ?: 'SS') . ')'
                            ]);

                            $totalTakenFromSession += $takeFromLot;
                            $remainingToDeduct     -= $takeFromLot;
                            $totalAllocatedSs      += $takeFromLot;

                            if ($takeFromLot < $lotQty) {
                                // Partially taken: update original lot Qty in old session to $takeFromLot
                                if ($lotId > 0) {
                                    $partiallyTakenLotUpdates[] = [
                                        'id'  => $lotId,
                                        'qty' => $takeFromLot
                                    ];
                                }
                                $leftoverLotsToInsert[] = [
                                    'lot_number'     => $lot['lot_number'] ?: 'LOT-SS',
                                    'ref_number'     => $lot['ref_number'] ?? null,
                                    'qty'            => ($lotQty - $takeFromLot),
                                    'scanned_qr_raw' => $lot['scanned_qr_raw'] ?? null
                                ];
                            }
                        } else {
                            // This lot was not selected by user or quota already fulfilled
                            if ($lotId > 0) {
                                $untouchedLotIdsToMove[] = [
                                    'id'  => $lotId,
                                    'qty' => $lotQty,
                                    'lot' => $lot
                                ];
                            } else {
                                $leftoverLotsToInsert[] = [
                                    'lot_number'     => $lot['lot_number'] ?: 'LOT-SS',
                                    'ref_number'     => $lot['ref_number'] ?? null,
                                    'qty'            => $lotQty,
                                    'scanned_qr_raw' => $lot['scanned_qr_raw'] ?? null
                                ];
                            }
                        }
                    }

                    if ($totalTakenFromSession <= 0) {
                        // Nothing was taken from this session, leave session completely untouched
                        continue;
                    }

                    $hasLeftovers = (!empty($leftoverLotsToInsert) || !empty($untouchedLotIdsToMove));

                    // Handle session splitting if not all Qty was consumed
                    if ($hasLeftovers) {
                        $leftoverTotalQty = 0;
                        foreach ($leftoverLotsToInsert as $lo) {
                            $leftoverTotalQty += (int)$lo['qty'];
                        }
                        foreach ($untouchedLotIdsToMove as $un) {
                            $leftoverTotalQty += (int)$un['qty'];
                        }

                        // 1. Update current SS session Qty to taken Qty and mark as fulfilled for this Kanban session
                        $stmtUpdSS = $pdo->prepare("UPDATE inspection_sessions SET total_scanned_qty = :tqty, auto_fulfilled_by_session_id = :ksid WHERE id = :ssid");
                        $stmtUpdSS->execute([':tqty' => $totalTakenFromSession, ':ksid' => $sessionId, ':ssid' => $ssSessionId]);

                        // Update partially taken lots in original session
                        if (!empty($partiallyTakenLotUpdates)) {
                            $stmtUpdPartLot = $pdo->prepare("UPDATE inspection_session_lots SET qty = :qty WHERE id = :id");
                            foreach ($partiallyTakenLotUpdates as $pu) {
                                $stmtUpdPartLot->execute([':qty' => $pu['qty'], ':id' => $pu['id']]);
                            }
                        }

                        // 2. Create a new Safety Stock kanban_items record for leftover Qty
                        $firstLoLot = 'SS';
                        if (!empty($untouchedLotIdsToMove)) {
                            $firstLoLot = $untouchedLotIdsToMove[0]['lot']['lot_number'] ?? 'SS';
                        } elseif (!empty($leftoverLotsToInsert)) {
                            $firstLoLot = $leftoverLotsToInsert[0]['lot_number'] ?? 'SS';
                        }

                        $stmtInsLeftoverK = $pdo->prepare("INSERT INTO kanban_items 
                            (batch_id, plan_type, kanban_no, item_code, item_description, customer, req_date, qty, str_loc, supply_area, check_type, remark, created_at) 
                            VALUES (1, 'safety_stock', :kno, :code, 'Safety Stock Overflow', 'INTERNAL SAFETY STOCK', NOW(), :qty, 'WH-SS-SPLIT', 'SAFETY STOCK WAREHOUSE', 'Safety Stock', :rem, NOW())");
                        $stmtInsLeftoverK->execute([
                            ':kno'  => 'SS-' . $firstLoLot,
                            ':code' => $partCode,
                            ':qty'  => $leftoverTotalQty,
                            ':rem'  => "Sisa Stok Safety Stock (Split dari Sesi #{$ssSessionId}, sisa {$leftoverTotalQty} pcs)"
                        ]);
                        $newSSKId = (int)$pdo->lastInsertId();

                        // 3. Create a new Safety Stock inspection_sessions record for leftover Qty
                        $origSsId = (int)(($ssRow['original_ss_session_id'] ?? null) ?: $ssSessionId);
                        $stmtInsLeftoverS = $pdo->prepare("INSERT INTO inspection_sessions 
                            (inspection_type, did_id, kanban_item_id, part_id, sample_size, total_scanned_qty, excess_qty, reject_number, samples_checked, ng_count, status, original_ss_session_id, started_at, closed_at) 
                            VALUES ('safety_stock', :did, :kanban, :part, 1, :tqty, 0, 1, 1, 0, 'passed', :orig_ss, NOW(), NOW())");
                        $stmtInsLeftoverS->execute([
                            ':did'     => $ssRow['did_id'],
                            ':kanban'  => $newSSKId,
                            ':part'    => $ssRow['part_id'],
                            ':tqty'    => $leftoverTotalQty,
                            ':orig_ss' => $origSsId
                        ]);
                        $newSSSessionId = (int)$pdo->lastInsertId();

                        // 4. Move untouched lots to the new Safety Stock session
                        if (!empty($untouchedLotIdsToMove)) {
                            $stmtMoveLot = $pdo->prepare("UPDATE inspection_session_lots SET inspection_session_id = :new_sid WHERE id = :id");
                            foreach ($untouchedLotIdsToMove as $un) {
                                $stmtMoveLot->execute([':new_sid' => $newSSSessionId, ':id' => $un['id']]);
                            }
                        }

                        // 5. Create inspection_session_lots for each partial leftover lot in the new Safety Stock session
                        if (!empty($leftoverLotsToInsert)) {
                            $stmtInsLeftoverL = $pdo->prepare("INSERT INTO inspection_session_lots 
                                (inspection_session_id, ref_number, lot_number, qty, scanned_qr_raw, remarks, lot_status, created_at) 
                                VALUES (:sid, :ref, :lot, :qty, :raw, 'Sisa Split Safety Stock', 'ok', NOW())");
                            foreach ($leftoverLotsToInsert as $loLot) {
                                $stmtInsLeftoverL->execute([
                                    ':sid' => $newSSSessionId,
                                    ':ref' => $loLot['ref_number'],
                                    ':lot' => $loLot['lot_number'],
                                    ':qty' => $loLot['qty'],
                                    ':raw' => $loLot['scanned_qr_raw']
                                ]);
                            }
                        }
                    } else {
                        // Entire SS session consumed
                        $stmtLinkSS = $pdo->prepare("UPDATE inspection_sessions SET auto_fulfilled_by_session_id = :ksid WHERE id = :ssid");
                        $stmtLinkSS->execute([':ksid' => $sessionId, ':ssid' => $ssSessionId]);
                    }
                }
            }

            if ($sessionId > 0 && $totalAllocatedSs > 0) {
                $stmtUpdSsQty = $pdo->prepare("UPDATE inspection_sessions SET use_safety_stock_qty = :usss WHERE id = :sid");
                $stmtUpdSsQty->execute([':usss' => $totalAllocatedSs, ':sid' => $sessionId]);

                // Recalculate AQL sample_size based strictly on Physical Scan Qty
                $physScanQty = max(0, $totalScannedQty - $totalAllocatedSs);
                $calcAqlQty = max(1, $physScanQty);

                $stmtAqlRe = $pdo->prepare("SELECT sample_size, reject_number FROM aql_standards WHERE inspection_level = :lvl AND :qty BETWEEN qty_min AND qty_max LIMIT 1");
                $stmtAqlRe->execute([':lvl' => $partAqlLevel, ':qty' => $calcAqlQty]);
                $aqlReRow = $stmtAqlRe->fetch(PDO::FETCH_ASSOC);

                if (!$aqlReRow) {
                    $stmtAqlReFB = $pdo->prepare("SELECT sample_size, reject_number FROM aql_standards WHERE :qty BETWEEN qty_min AND qty_max LIMIT 1");
                    $stmtAqlReFB->execute([':qty' => $calcAqlQty]);
                    $aqlReRow = $stmtAqlReFB->fetch(PDO::FETCH_ASSOC);
                }

                if ($aqlReRow) {
                    $finalSampleSize = ($physScanQty == 0) ? 0 : (int)$aqlReRow['sample_size'];
                    $finalRejectNum  = (int)$aqlReRow['reject_number'];

                    $stmtUpdReAql = $pdo->prepare("UPDATE inspection_sessions SET sample_size = :ssize, reject_number = :rej WHERE id = :sid");
                    $stmtUpdReAql->execute([':ssize' => $finalSampleSize, ':rej' => $finalRejectNum, ':sid' => $sessionId]);
                }
            }

            // Check if the REMAINING Kanban quota is fully covered by Safety Stock (no physical scan needed)
            // FIX: Dulu cek SS >= total target (mis. 500 pcs), harusnya cek SS >= sisa quota (mis. 100 pcs partial)
            if ($sessionId > 0 && $kanbanItemId > 0) {
                $stmtKFull = $pdo->prepare("
                    SELECT k.qty AS target_qty,
                           COALESCE((
                               SELECT SUM(s2.total_scanned_qty - COALESCE(s2.excess_qty, 0))
                               FROM inspection_sessions s2
                               WHERE s2.kanban_item_id = k.id
                                 AND s2.status = 'passed'
                                 AND s2.id != :curr_sid
                           ), 0) AS already_passed
                    FROM kanban_items k
                    WHERE k.id = :kid
                    LIMIT 1
                ");
                $stmtKFull->execute([':kid' => $kanbanItemId, ':curr_sid' => $sessionId]);
                $kFullRow = $stmtKFull->fetch(PDO::FETCH_ASSOC);

                if ($kFullRow) {
                    $targetKanbanQty  = (int)$kFullRow['target_qty'];
                    $alreadyPassedQty = (int)$kFullRow['already_passed'];
                    $remainingQuota   = max(0, $targetKanbanQty - $alreadyPassedQty);

                    // Auto-pass hanya jika SS mencukupi SISA KUOTA (bukan total target Kanban)
                    // Contoh: target 500, sudah passed 400, sisa 100 → cukup jika SS >= 100
                    if ($remainingQuota > 0 && $totalAllocatedSs >= $remainingQuota) {
                        $stmtPassSS = $pdo->prepare("UPDATE inspection_sessions SET status = 'passed', total_scanned_qty = :tqty, samples_checked = sample_size, closed_at = NOW() WHERE id = :sid");
                        $stmtPassSS->execute([':tqty' => $remainingQuota, ':sid' => $sessionId]);
                        syncDailySummaryForSession($pdo, $sessionId);
                    } elseif ($targetKanbanQty > 0 && $alreadyPassedQty === 0 && $totalAllocatedSs >= $targetKanbanQty) {
                        // Fallback: kanban baru tanpa sesi sebelumnya, SS cover full target
                        $stmtPassSS = $pdo->prepare("UPDATE inspection_sessions SET status = 'passed', total_scanned_qty = :tqty, samples_checked = sample_size, closed_at = NOW() WHERE id = :sid");
                        $stmtPassSS->execute([':tqty' => $targetKanbanQty, ':sid' => $sessionId]);
                        syncDailySummaryForSession($pdo, $sessionId);
                    }
                }

                // Sync Kanban status lifecycle
                syncKanbanStatus($pdo, $kanbanItemId);
            }

            if ($pdo->inTransaction()) {
                $pdo->commit();
            }

            if (ob_get_length()) ob_clean();
            header('Content-Type: application/json');
            echo json_encode([
                'success'    => true,
                'session_id' => $sessionId,
                'url'        => base_url('modules/inspection/session.php?id=' . $sessionId)
            ]);
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (ob_get_length()) ob_clean();
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Gagal membuat sesi inspeksi: ' . $e->getMessage()
            ]);
            exit;
        }
    } else {
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Data DID atau Sample Size tidak valid!'
        ]);
        exit;
    }
}

// Default GET: redirect to unified session workbench with scan overlay active
$kanbanId = (int)($_GET['kanban_id'] ?? 0);
if ($kanbanId > 0) {
    redirect('modules/inspection/session.php?scan_new=1&kanban_id=' . $kanbanId);
}
redirect('modules/inspection/session.php?scan_new=1');
