<?php
/**
 * Action Handler: Update Kanban Batch & Sync Item Rows
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/kanban/index.php');
}

$batch_id = filter_input(INPUT_POST, 'batch_id', FILTER_VALIDATE_INT);
$docNum = trim(sanitize($_POST['document_number'] ?? ''));
$vendor = trim(sanitize($_POST['vendor'] ?? 'PT. Surya Technology'));
$rows = $_POST['rows'] ?? [];

if (!$batch_id || empty($docNum)) {
    set_flash('error', 'Data Dokumen Batch tidak valid!');
    redirect('modules/kanban/index.php');
}

$pdo = getDB();

if ($pdo) {
    try {
        // Update batch header
        $stmtUpdHeader = $pdo->prepare("UPDATE kanban_batches SET document_number = :doc, vendor = :vendor WHERE id = :id");
        $stmtUpdHeader->execute([':doc' => $docNum, ':vendor' => $vendor, ':id' => $batch_id]);

        // Fetch existing items for deletion diff
        $stmtCurr = $pdo->prepare("SELECT id FROM kanban_items WHERE batch_id = :bid");
        $stmtCurr->execute([':bid' => $batch_id]);
        $existingIds = $stmtCurr->fetchAll(PDO::FETCH_COLUMN);

        $submittedIds = [];

        foreach ($rows as $r) {
            $itemId = $r['item_id'] ?? 'new';
            $kanbanNo = strtoupper(trim(sanitize($r['kanban_no'] ?? '')));
            $selectCode = trim(sanitize($r['item_code_select'] ?? ''));
            $customCode = strtoupper(trim(sanitize($r['item_code_custom'] ?? '')));
            $itemCode = (!empty($r['item_code'])) ? strtoupper(trim(sanitize($r['item_code']))) : (($selectCode === 'custom') ? $customCode : strtoupper($selectCode));
            $itemDesc = trim(sanitize($r['item_description'] ?? ''));
            $qty       = (int)($r['qty'] ?? 100);
            $strLoc    = trim(sanitize($r['str_loc'] ?? 'WH-A01'));
            $checkType = trim(sanitize($r['check_type'] ?? ''));
            $remark    = trim(sanitize($r['remark'] ?? ''));

            // Per-row Customer
            $selectCust = trim(sanitize($r['customer_select'] ?? ''));
            $customCust = trim(sanitize($r['customer_custom'] ?? ''));
            $rowCustomer = (!empty($r['customer'])) ? trim(sanitize($r['customer'])) : (($selectCust === 'custom') ? $customCust : $selectCust);
            if (empty($rowCustomer)) $rowCustomer = 'PT. Indonesia Epson Industry';

            // Per-row Req Date & ETA
            $rowReqDate = sanitize($r['req_date'] ?? date('Y-m-d'));
            if (strlen($rowReqDate) === 10) {
                $rowReqDate .= ' 00:00:00';
            }

            $rowEta = sanitize($r['eta'] ?? $rowReqDate);
            if (strlen($rowEta) === 10) {
                $rowEta .= ' 00:00:00';
            }

            if (empty($kanbanNo) || empty($itemCode) || empty($itemDesc) || $qty <= 0) {
                continue;
            }

            // Auto-create Master Customer if missing
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

            if (is_numeric($itemId) && in_array($itemId, $existingIds)) {
                // Update existing item
                $stmtUpdate = $pdo->prepare("UPDATE kanban_items SET 
                    kanban_no = :kno, item_code = :icode, item_description = :idesc, customer = :cust, req_date = :rdate, eta = :eta, qty = :qty, str_loc = :sloc, check_type = :ctype, remark = :remark, updated_at = NOW() 
                    WHERE id = :id AND batch_id = :bid");
                $stmtUpdate->execute([
                    ':kno'    => $kanbanNo,
                    ':icode'  => $itemCode,
                    ':idesc'  => $itemDesc,
                    ':cust'   => $rowCustomer,
                    ':rdate'  => $rowReqDate,
                    ':eta'    => $rowEta,
                    ':qty'    => $qty,
                    ':sloc'   => $strLoc,
                    ':ctype'  => $checkType,
                    ':remark' => $remark,
                    ':id'     => $itemId,
                    ':bid'    => $batch_id
                ]);
                $submittedIds[] = (int)$itemId;
            } else {
                // Insert new item
                $stmtInsert = $pdo->prepare("INSERT INTO kanban_items 
                    (batch_id, kanban_no, item_code, item_description, customer, req_date, qty, eta, str_loc, supply_area, check_type, remark, created_at) 
                    VALUES (:bid, :kno, :icode, :idesc, :cust, :rdate, :qty, :eta, :sloc, 'LINE-01', :ctype, :remark, NOW())");
                $stmtInsert->execute([
                    ':bid'    => $batch_id,
                    ':kno'    => $kanbanNo,
                    ':icode'  => $itemCode,
                    ':idesc'  => $itemDesc,
                    ':cust'   => $rowCustomer,
                    ':rdate'  => $rowReqDate,
                    ':qty'    => $qty,
                    ':eta'    => $rowEta,
                    ':sloc'   => $strLoc,
                    ':ctype'  => $checkType,
                    ':remark' => $remark
                ]);
                $submittedIds[] = (int)$pdo->lastInsertId();
            }
        }

        // Delete removed items
        $toDelete = array_diff($existingIds, $submittedIds);
        if (!empty($toDelete)) {
            $inClause = implode(',', array_map('intval', $toDelete));
            $pdo->exec("DELETE FROM kanban_items WHERE id IN ({$inClause}) AND batch_id = {$batch_id}");
        }

        set_flash('success', 'Dokumen Batch Kanban berhasil diperbarui!');
    } catch (PDOException $e) {
        set_flash('error', 'Gagal memperbarui dokumen batch Kanban: ' . $e->getMessage());
    }
} else {
    set_flash('error', 'Database tidak terhubung!');
}

redirect('modules/kanban/detail_batch.php?batch_id=' . $batch_id);
