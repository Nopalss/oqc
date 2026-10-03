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
$reqDate = str_replace('T', ' ', sanitize($_POST['req_date'] ?? date('Y-m-d H:i:s')));
if (strlen($reqDate) === 10) {
    $reqDate .= ' 00:00:00';
} elseif (strlen($reqDate) === 16) {
    $reqDate .= ':00';
}

$eta = !empty($_POST['eta']) ? str_replace('T', ' ', sanitize($_POST['eta'])) : null;
if ($eta && strlen($eta) === 10) {
    $eta .= ' 00:00:00';
} elseif ($eta && strlen($eta) === 16) {
    $eta .= ':00';
}

$strLoc     = trim(sanitize($_POST['str_loc'] ?? ''));
$supplyArea = trim(sanitize($_POST['supply_area'] ?? ''));
$checkType  = trim(sanitize($_POST['check_type'] ?? ''));
$remark     = trim(sanitize($_POST['remark'] ?? ''));

$returnDate = trim(sanitize($_POST['return_date'] ?? ''));
$targetRedirect = (!empty($returnDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $returnDate))
    ? 'modules/kanban/daily_detail.php?date=' . urlencode($returnDate)
    : 'modules/kanban/index.php';

if (!$id || empty($kanbanNo) || empty($itemCode) || empty($itemDesc) || $qty <= 0) {
    set_flash('error', 'Semua kolom wajib (*) harus diisi dengan benar!');
    $editUrl = 'modules/kanban/edit.php?id=' . $id . (!empty($returnDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $returnDate) ? '&return_date=' . urlencode($returnDate) : '');
    redirect($editUrl);
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
            check_type = :ctype,
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
            ':eta'   => $eta,
            ':sloc'  => $strLoc,
            ':sarea' => $supplyArea,
            ':ctype' => $checkType,
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

redirect($targetRedirect);
