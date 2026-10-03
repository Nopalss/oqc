<?php
/**
 * Action Handler: Store Kanban Schedule (FR-1)
 * Supports Multi-Row Manual Matrix Entry & CSV/Excel Batch Import
 * JSON payload supported to prevent PHP max_input_vars limit
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/kanban/index.php');
}

$pdo = getDB();

if (!$pdo) {
    set_flash('error', 'Database tidak terhubung!');
    redirect('modules/kanban/index.php');
}

$entryType = sanitize($_POST['entry_type'] ?? 'manual');

if ($entryType === 'manual') {
    // ----------------------------------------------------
    // 1. MULTI-ROW MANUAL MATRIX ENTRY & REVIEW IMPORT
    // ----------------------------------------------------
    $planType  = sanitize($_POST['plan_type'] ?? 'kanban');
    if (!in_array($planType, ['kanban', 'safety_stock'])) {
        $planType = 'kanban';
    }
    $vendor       = trim(sanitize($_POST['vendor'] ?? 'PT. SURYA TECHNOLOGY INDUSTRI'));
    $printDt      = !empty($_POST['print_datetime']) ? sanitize($_POST['print_datetime']) : null;
    $importMethod = sanitize($_POST['import_method'] ?? 'manual');
    if (!in_array($importMethod, ['manual', 'excel_import'])) {
        $importMethod = 'manual';
    }
    $docNumber = trim(sanitize($_POST['document_number'] ?? ($planType === 'safety_stock' ? 'STOCK-' : 'KANBAN-') . date('Ymd-His')));

    // Support JSON payload to bypass PHP max_input_vars limit for large excel imports
    $rows = [];
    if (!empty($_POST['kanban_json_data'])) {
        $decoded = json_decode($_POST['kanban_json_data'], true);
        if (is_array($decoded)) {
            $rows = $decoded;
        }
    }
    if (empty($rows)) {
        $rows = $_POST['rows'] ?? [];
    }

    if (empty($rows) || !is_array($rows)) {
        set_flash('error', 'Tidak ada baris data Planning yang dikirim!');
        redirect('modules/kanban/create.php');
    }

    try {
        $currentUser = current_user();
        $importedBy = !empty($currentUser['id']) ? (int)$currentUser['id'] : 1;

        // Create Batch Header with imported_by, print_datetime, and import_method
        $stmtBatch = $pdo->prepare("INSERT INTO kanban_batches (plan_type, vendor, document_number, print_datetime, import_method, imported_by, imported_at) VALUES (:ptype, :vendor, :doc, :print_dt, :import_method, :imported_by, NOW())");
        $stmtBatch->execute([
            ':ptype'         => $planType,
            ':vendor'        => $vendor ?: 'PT. SURYA TECHNOLOGY INDUSTRI',
            ':doc'           => $docNumber,
            ':print_dt'      => $printDt,
            ':import_method' => $importMethod,
            ':imported_by'   => $importedBy
        ]);
        $batchId = $pdo->lastInsertId();

        // -----------------------------------------------------------------
        // Fast Deduplication Fingerprint Cache (DB & Current Batch)
        // -----------------------------------------------------------------
        $kanbanNos = array_filter(array_unique(array_map(function($r) {
            return strtoupper(trim(sanitize($r['kanban_no'] ?? '')));
        }, $rows)));

        $dbFingerprints = [];
        if (!empty($kanbanNos)) {
            $placeholders = implode(',', array_fill(0, count($kanbanNos), '?'));
            $stmtFp = $pdo->prepare("SELECT kanban_no, item_code, req_date, qty, eta, str_loc, supply_area FROM kanban_items WHERE kanban_no IN ($placeholders)");
            $stmtFp->execute(array_values($kanbanNos));
            $existingRows = $stmtFp->fetchAll();

            foreach ($existingRows as $exR) {
                $exRDate = !empty($exR['req_date']) ? date('Y-m-d H:i:s', strtotime($exR['req_date'])) : '';
                $exREta  = !empty($exR['eta']) ? date('Y-m-d H:i:s', strtotime($exR['eta'])) : '';
                $fp = strtoupper(trim($exR['kanban_no'])) . '|' .
                      strtoupper(trim($exR['item_code'])) . '|' .
                      $exRDate . '|' .
                      (int)$exR['qty'] . '|' .
                      $exREta . '|' .
                      strtoupper(trim($exR['str_loc'] ?? '')) . '|' .
                      strtoupper(trim($exR['supply_area'] ?? ''));
                $dbFingerprints[md5($fp)] = true;
            }
        }

        $seenBatchFingerprints = [];
        $successCount = 0;
        $skipCount = 0;
        $duplicateCount = 0;

        foreach ($rows as $r) {
            $kanbanNo   = strtoupper(trim(sanitize($r['kanban_no'] ?? '')));
            $directCode = strtoupper(trim(sanitize($r['item_code'] ?? '')));
            $selectCode = trim(sanitize($r['item_code_select'] ?? ''));
            $customCode = strtoupper(trim(sanitize($r['item_code_custom'] ?? '')));

            if (!empty($directCode)) {
                $itemCode = $directCode;
            } else {
                $itemCode = ($selectCode === 'custom') ? $customCode : strtoupper($selectCode);
            }

            $itemDesc = trim(sanitize($r['item_description'] ?? ''));
            
            // Per-row Customer & Kanban No Handling
            if ($planType === 'safety_stock') {
                $rowCustomer = 'INTERNAL STOCK';
                if (empty($kanbanNo)) {
                    $kanbanNo = 'STOCK-' . date('Ymd');
                }
            } else {
                $selectCust = trim(sanitize($r['customer_select'] ?? ''));
                $customCust = trim(sanitize($r['customer_custom'] ?? ''));
                $directCust = trim(sanitize($r['customer'] ?? ''));

                if (!empty($directCust)) {
                    $rowCustomer = $directCust;
                } else {
                    $rowCustomer = ($selectCust === 'custom') ? $customCust : $selectCust;
                }
                if (empty($rowCustomer)) $rowCustomer = 'PT. Indonesia Epson Industry';
            }

            // Per-row Req Date & ETA (Supports full Date & Time YYYY-MM-DD HH:MM:SS)
            $rowReqDate = str_replace('T', ' ', sanitize($r['req_date'] ?? date('Y-m-d H:i:s')));
            if (strlen($rowReqDate) === 10) {
                $rowReqDate .= ' 00:00:00';
            } elseif (strlen($rowReqDate) === 16) {
                $rowReqDate .= ':00';
            }

            $rowEta = str_replace('T', ' ', sanitize($r['eta'] ?? $rowReqDate));
            if (strlen($rowEta) === 10) {
                $rowEta .= ' 00:00:00';
            } elseif (strlen($rowEta) === 16) {
                $rowEta .= ':00';
            }

            $qty        = (int)($r['qty'] ?? 500);
            $strLoc     = trim(sanitize($r['str_loc'] ?? ''));
            $supplyArea = trim(sanitize($r['supply_area'] ?? ''));
            $checkType  = trim(sanitize($r['check_type'] ?? ''));
            $remark     = trim(sanitize($r['remark'] ?? ''));

            if ($planType === 'safety_stock') {
                if (empty($itemCode) || empty($itemDesc) || $qty <= 0) {
                    $skipCount++;
                    continue;
                }
            } else {
                if (empty($kanbanNo) || empty($itemCode) || empty($itemDesc) || $qty <= 0) {
                    $skipCount++;
                    continue;
                }
            }

            // Check 7 criteria duplicate match
            $itemFingerprint = md5(
                $kanbanNo . '|' .
                $itemCode . '|' .
                $rowReqDate . '|' .
                $qty . '|' .
                $rowEta . '|' .
                strtoupper($strLoc) . '|' .
                strtoupper($supplyArea)
            );

            if (isset($dbFingerprints[$itemFingerprint]) || isset($seenBatchFingerprints[$itemFingerprint])) {
                $duplicateCount++;
                continue; // Reject & skip duplicate row
            }

            $seenBatchFingerprints[$itemFingerprint] = true;

            // Auto-create Master Customer if custom customer given and not exists
            if (!empty($rowCustomer)) {
                $stmtChkCust = $pdo->prepare("SELECT id FROM master_customers WHERE name = :cname");
                $stmtChkCust->execute([':cname' => $rowCustomer]);
                if (!$stmtChkCust->fetch()) {
                    $pdo->prepare("INSERT INTO master_customers (name, created_at) VALUES (:cname, NOW())")
                        ->execute([':cname' => $rowCustomer]);
                }
            }

            // Auto-create Master Part if not exists
            $stmtCheckMaster = $pdo->prepare("SELECT id FROM master_parts WHERE part_code = :pcode");
            $stmtCheckMaster->execute([':pcode' => $itemCode]);
            if (!$stmtCheckMaster->fetch()) {
                $stmtInsertMaster = $pdo->prepare("INSERT INTO master_parts (part_code, part_name, source, created_at) VALUES (:pcode, :pname, 'auto_generated', NOW())");
                $stmtInsertMaster->execute([':pcode' => $itemCode, ':pname' => $itemDesc]);
            }

            // Insert Kanban / Planning Item
            $stmtItem = $pdo->prepare("INSERT INTO kanban_items 
                (batch_id, plan_type, kanban_no, item_code, item_description, customer, req_date, qty, eta, str_loc, supply_area, check_type, remark, created_at) 
                VALUES (:batch_id, :ptype, :kno, :icode, :idesc, :cust, :rdate, :qty, :eta, :sloc, :sarea, :ctype, :remark, NOW())");
            
            $stmtItem->execute([
                ':batch_id' => $batchId,
                ':ptype'    => $planType,
                ':kno'      => $kanbanNo ?: ($planType === 'safety_stock' ? 'STOCK-' . date('Ymd') : '-'),
                ':icode'    => $itemCode,
                ':idesc'    => $itemDesc,
                ':cust'     => $rowCustomer,
                ':rdate'    => $rowReqDate,
                ':qty'      => $qty,
                ':eta'      => $rowEta,
                ':sloc'     => $strLoc,
                ':sarea'    => $supplyArea,
                ':ctype'    => $checkType,
                ':remark'   => $remark
            ]);

            $successCount++;
        }

        if ($successCount > 0) {
            $msg = 'Berhasil menyimpan ' . $successCount . ' baris data Kanban baru.';
            $details = [];
            if ($duplicateCount > 0) {
                $details[] = $duplicateCount . ' baris duplikat ditolak/dilewati';
            }
            if ($skipCount > 0) {
                $details[] = $skipCount . ' baris invalid dilewati';
            }
            if (!empty($details)) {
                $msg .= ' (' . implode(', ', $details) . ').';
            }
            set_flash('success', $msg);
        } else {
            if ($duplicateCount > 0) {
                set_flash('warning', 'Tidak ada data baru yang disimpan. Seluruh ' . $duplicateCount . ' baris terdeteksi duplikat dan sudah ada sebelumnya!');
            } else {
                set_flash('warning', 'Gagal menyimpan data Kanban! Seluruh baris tidak valid.');
            }
        }
    } catch (PDOException $e) {
        set_flash('error', 'Gagal menyimpan batch Kanban: ' . $e->getMessage());
    }

} else if ($entryType === 'excel') {
    // ----------------------------------------------------
    // 2. BATCH CSV / EXCEL IMPORT
    // ----------------------------------------------------
    if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
        set_flash('error', 'Pilih file CSV/Excel yang valid untuk diunggah!');
        redirect('modules/kanban/create.php');
    }

    $vendor = sanitize($_POST['vendor'] ?? 'PT. Surya Technology');
    $docNum = sanitize($_POST['document_number'] ?? 'DOC-IMPORT-' . date('Ymd'));

    $filePath = $_FILES['excel_file']['tmp_name'];
    $handle = fopen($filePath, 'r');
    if (!$handle) {
        set_flash('error', 'Gagal membaca file unggahan.');
        redirect('modules/kanban/create.php');
    }

    try {
        // Create Batch Header
        $stmtBatch = $pdo->prepare("INSERT INTO kanban_batches (vendor, document_number, import_method, imported_at) VALUES (:vendor, :doc, 'excel_import', NOW())");
        $stmtBatch->execute([':vendor' => $vendor, ':doc' => $docNum]);
        $batchId = $pdo->lastInsertId();

        $successCount = 0;
        $lineNum = 0;

        while (($data = fgetcsv($handle, 2000, ",")) !== false) {
            $lineNum++;
            if ($lineNum === 1 && (strtolower($data[0] ?? '') === 'kanban_no' || strtolower($data[0] ?? '') === 'kanban no')) {
                continue;
            }

            $kanbanNo = strtoupper(trim(sanitize($data[0] ?? '')));
            $itemCode = strtoupper(trim(sanitize($data[1] ?? '')));
            $itemDesc = trim(sanitize($data[2] ?? ''));
            $rawCustomer = trim(sanitize($data[3] ?? ''));
            $customer    = (!empty($rawCustomer) && strtolower($rawCustomer) !== 'customer') ? $rawCustomer : 'PT. Indonesia Epson Industry';
            $reqDate = sanitize($data[4] ?? date('Y-m-d H:i:s'));
            $qty = (int)($data[5] ?? 100);
            $eta = sanitize($data[6] ?? date('Y-m-d H:i:s'));
            $strLoc     = trim(sanitize($data[7] ?? ''));
            $supplyArea = trim(sanitize($data[8] ?? ''));
            $checkType  = trim(sanitize($data[9] ?? ''));
            $remark     = trim(sanitize($data[10] ?? ''));

            if (empty($kanbanNo) || empty($itemCode)) {
                continue;
            }

            // Auto-create Master Part if not exists
            $stmtCheckMaster = $pdo->prepare("SELECT id FROM master_parts WHERE part_code = :pcode");
            $stmtCheckMaster->execute([':pcode' => $itemCode]);
            if (!$stmtCheckMaster->fetch()) {
                $stmtInsertMaster = $pdo->prepare("INSERT INTO master_parts (part_code, part_name, source, created_at) VALUES (:pcode, :pname, 'auto_generated', NOW())");
                $stmtInsertMaster->execute([':pcode' => $itemCode, ':pname' => $itemDesc ?: $itemCode]);
            }

            // Insert Item
            $stmtItem = $pdo->prepare("INSERT INTO kanban_items 
                (batch_id, kanban_no, item_code, item_description, customer, req_date, qty, eta, str_loc, supply_area, check_type, remark, created_at) 
                VALUES (:batch_id, :kno, :icode, :idesc, :cust, :rdate, :qty, :eta, :sloc, :sarea, :ctype, :remark, NOW())");
            
            $stmtItem->execute([
                ':batch_id' => $batchId,
                ':kno'      => $kanbanNo,
                ':icode'    => $itemCode,
                ':idesc'    => $itemDesc ?: $itemCode,
                ':cust'     => $customer,
                ':rdate'    => $reqDate ?: date('Y-m-d H:i:s'),
                ':qty'      => $qty > 0 ? $qty : 100,
                ':eta'      => $eta,
                ':sloc'     => $strLoc,
                ':sarea'    => $supplyArea,
                ':ctype'    => $checkType,
                ':remark'   => $remark
            ]);

            $successCount++;
        }

        fclose($handle);

        set_flash('success', 'Berhasil meng-import ' . $successCount . ' baris item Kanban ke Dokumen Batch "' . $docNum . '"!');
    } catch (PDOException $e) {
        set_flash('error', 'Gagal memproses batch import Kanban: ' . $e->getMessage());
    }
}

redirect('modules/kanban/index.php');
