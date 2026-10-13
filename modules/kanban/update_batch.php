<?php
/**
 * Action Handler: Update Kanban Batch & Sync Item Rows
 * Secured with RBAC, CSRF, Atomic Transaction, and Session Integrity Check
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

require_menu_access('kanban');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/kanban/index.php');
}

csrf_verify();

$batch_id = filter_input(INPUT_POST, 'batch_id', FILTER_VALIDATE_INT);
$docNum = trim(sanitize($_POST['document_number'] ?? ''));
$vendor = trim(sanitize($_POST['vendor'] ?? 'PT. Surya Technology'));
$returnDate = trim(sanitize($_POST['return_date'] ?? ''));
$rows = $_POST['rows'] ?? [];

$targetRedirect = (!empty($returnDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $returnDate))
    ? 'modules/kanban/daily_detail.php?date=' . urlencode($returnDate)
    : 'modules/kanban/detail_batch.php?batch_id=' . ($batch_id ?: 0);

if (!$batch_id || empty($docNum)) {
    set_flash('error', 'Data Dokumen Batch tidak valid!');
    redirect($targetRedirect);
}

$pdo = getDB();

if ($pdo) {
    try {
        $pdo->beginTransaction();

        // Update batch header
        $stmtUpdHeader = $pdo->prepare("UPDATE kanban_batches SET document_number = :doc, vendor = :vendor WHERE id = :id");
        $stmtUpdHeader->execute([':doc' => $docNum, ':vendor' => $vendor, ':id' => $batch_id]);

        // Fetch existing items for deletion diff
        $stmtCurr = $pdo->prepare("SELECT id FROM kanban_items WHERE batch_id = :bid");
        $stmtCurr->execute([':bid' => $batch_id]);
        $existingIds = $stmtCurr->fetchAll(PDO::FETCH_COLUMN);

        // Preload caches for master parts & customers to avoid N+1 queries
        $cachedParts = [];
        $stmtAllParts = $pdo->query("SELECT UPPER(part_code) AS pcode FROM master_parts");
        while ($pRow = $stmtAllParts->fetch(PDO::FETCH_ASSOC)) {
            $cachedParts[$pRow['pcode']] = true;
        }

        $cachedCustomers = [];
        $stmtAllCust = $pdo->query("SELECT UPPER(name) AS cname FROM master_customers");
        while ($cRow = $stmtAllCust->fetch(PDO::FETCH_ASSOC)) {
            $cachedCustomers[$cRow['cname']] = true;
        }

        $stmtInsertMaster = $pdo->prepare("INSERT INTO master_parts (part_code, part_name, source, created_at) VALUES (:pcode, :pname, 'auto_generated', NOW())");
        $stmtInsertCust   = $pdo->prepare("INSERT INTO master_customers (name, created_at) VALUES (:cname, NOW())");

        $stmtUpdate = $pdo->prepare("UPDATE kanban_items SET 
            kanban_no = :kno, item_code = :icode, item_description = :idesc, customer = :cust, req_date = :rdate, eta = :eta, qty = :qty, str_loc = :sloc, supply_area = :sarea, check_type = :ctype, remark = :remark, updated_at = NOW() 
            WHERE id = :id AND batch_id = :bid");

        $stmtInsert = $pdo->prepare("INSERT INTO kanban_items 
            (batch_id, kanban_no, item_code, item_description, customer, req_date, qty, eta, str_loc, supply_area, check_type, remark, created_at) 
            VALUES (:bid, :kno, :icode, :idesc, :cust, :rdate, :qty, :eta, :sloc, :sarea, :ctype, :remark, NOW())");

        $submittedIds = [];

        foreach ($rows as $r) {
            $itemId = $r['item_id'] ?? 'new';
            $kanbanNo = strtoupper(trim(sanitize($r['kanban_no'] ?? '')));
            $selectCode = trim(sanitize($r['item_code_select'] ?? ''));
            $customCode = strtoupper(trim(sanitize($r['item_code_custom'] ?? '')));
            $itemCode = (!empty($r['item_code'])) ? strtoupper(trim(sanitize($r['item_code']))) : (($selectCode === 'custom') ? $customCode : strtoupper($selectCode));
            $itemDesc = trim(sanitize($r['item_description'] ?? ''));
            $qty        = (int)($r['qty'] ?? 100);
            $strLoc     = trim(sanitize($r['str_loc'] ?? ''));
            $supplyArea = trim(sanitize($r['supply_area'] ?? ''));
            $checkType  = trim(sanitize($r['check_type'] ?? ''));
            $remark     = trim(sanitize($r['remark'] ?? ''));

            // Per-row Customer
            $selectCust = trim(sanitize($r['customer_select'] ?? ''));
            $customCust = trim(sanitize($r['customer_custom'] ?? ''));
            $rowCustomer = (!empty($r['customer'])) ? trim(sanitize($r['customer'])) : (($selectCust === 'custom') ? $customCust : $selectCust);
            if (empty($rowCustomer)) $rowCustomer = 'PT. Indonesia Epson Industry';

            // Per-row Req Date & ETA
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

            if (empty($kanbanNo) || empty($itemCode) || empty($itemDesc) || $qty <= 0) {
                continue;
            }

            // Auto-create Master Customer if missing (cached)
            $upperCust = strtoupper($rowCustomer);
            if (!empty($rowCustomer) && !isset($cachedCustomers[$upperCust])) {
                $stmtInsertCust->execute([':cname' => $rowCustomer]);
                $cachedCustomers[$upperCust] = true;
            }

            // Auto-create Master Part if not exists (cached)
            if (!isset($cachedParts[$itemCode])) {
                $stmtInsertMaster->execute([':pcode' => $itemCode, ':pname' => $itemDesc]);
                $cachedParts[$itemCode] = true;
            }

            if (is_numeric($itemId) && in_array($itemId, $existingIds)) {
                // Update existing item
                $stmtUpdate->execute([
                    ':kno'    => $kanbanNo,
                    ':icode'  => $itemCode,
                    ':idesc'  => $itemDesc,
                    ':cust'   => $rowCustomer,
                    ':rdate'  => $rowReqDate,
                    ':eta'    => $rowEta,
                    ':qty'    => $qty,
                    ':sloc'   => $strLoc,
                    ':sarea'  => $supplyArea,
                    ':ctype'  => $checkType,
                    ':remark' => $remark,
                    ':id'     => $itemId,
                    ':bid'    => $batch_id
                ]);
                $submittedIds[] = (int)$itemId;
            } else {
                // Insert new item
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
                    ':sarea'  => $supplyArea,
                    ':ctype'  => $checkType,
                    ':remark' => $remark
                ]);
                $submittedIds[] = (int)$pdo->lastInsertId();
            }
        }

        // Delete removed items with inspection session guard
        $toDelete = array_diff($existingIds, $submittedIds);
        if (!empty($toDelete)) {
            $inClause = implode(',', array_map('intval', $toDelete));

            // Guard: ensure none of the items being removed are linked to inspection_sessions
            $stmtChkUsed = $pdo->query("SELECT COUNT(*) FROM inspection_sessions WHERE kanban_item_id IN ({$inClause})");
            if ((int)$stmtChkUsed->fetchColumn() > 0) {
                $pdo->rollBack();
                set_flash('error', 'Gagal memperbarui batch: Beberapa baris item yang Anda hapus sudah terdaftar dalam sesi inspeksi dan tidak dapat dihapus!');
                redirect($targetRedirect);
            }

            $pdo->exec("DELETE FROM kanban_items WHERE id IN ({$inClause}) AND batch_id = {$batch_id}");
        }

        $pdo->commit();
        set_flash('success', 'Dokumen Batch Kanban berhasil diperbarui!');
    } catch (PDOException $e) {
        if ($pdo && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', 'Gagal memperbarui dokumen batch Kanban: ' . $e->getMessage());
    }
} else {
    set_flash('error', 'Database tidak terhubung!');
}

redirect($targetRedirect);
