<?php
/**
 * Action Handler: Update Kanban Schedule (FR-1)
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/kanban/index.php');
}

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$kanbanNo = strtoupper(trim(sanitize($_POST['kanban_no'] ?? '')));
$itemCode = strtoupper(trim(sanitize($_POST['item_code'] ?? '')));
$itemDesc = trim(sanitize($_POST['item_description'] ?? ''));
$customer = trim(sanitize($_POST['customer'] ?? 'Surya Tech Customer'));
$qty = filter_input(INPUT_POST, 'qty', FILTER_VALIDATE_INT) ?: 100;
$reqDate = sanitize($_POST['req_date'] ?? date('Y-m-d H:i:s'));
$eta = sanitize($_POST['eta'] ?? null);
$strLoc = trim(sanitize($_POST['str_loc'] ?? ''));
$supplyArea = trim(sanitize($_POST['supply_area'] ?? ''));
$remark = trim(sanitize($_POST['remark'] ?? ''));

if (!$id || empty($kanbanNo) || empty($itemCode) || empty($itemDesc) || $qty <= 0) {
    set_flash('error', 'Semua kolom wajib (*) harus diisi dengan benar!');
    redirect('modules/kanban/edit.php?id=' . $id);
}

$pdo = getDB();

if ($pdo) {
    try {
        $stmtUpdate = $pdo->prepare("UPDATE kanban_items SET 
            kanban_no = :kno,
            item_code = :icode,
            item_description = :idesc,
            customer = :cust,
            req_date = :rdate,
            qty = :qty,
            eta = :eta,
            str_loc = :sloc,
            supply_area = :sarea,
            remark = :remark,
            updated_at = NOW()
            WHERE id = :id");

        $stmtUpdate->execute([
            ':kno'   => $kanbanNo,
            ':icode' => $itemCode,
            ':idesc' => $itemDesc,
            ':cust'  => $customer,
            ':rdate' => $reqDate,
            ':qty'   => $qty,
            ':eta'   => !empty($eta) ? $eta : null,
            ':sloc'  => $strLoc,
            ':sarea' => $supplyArea,
            ':remark'=> $remark,
            ':id'    => $id
        ]);

        set_flash('success', 'Data Kanban "' . $kanbanNo . '" berhasil diperbarui!');
    } catch (PDOException $e) {
        set_flash('error', 'Gagal meng-update data Kanban: ' . $e->getMessage());
    }
} else {
    set_flash('error', 'Database tidak terhubung!');
}

redirect('modules/kanban/index.php');
