<?php
/**
 * Action Handler: Update Daily Inspection Data (DID)
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/did/index.php');
}

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$partCode = strtoupper(trim(sanitize($_POST['part_code'] ?? '')));
$partName = trim(sanitize($_POST['part_name'] ?? ''));
$lotNumber = strtoupper(trim(sanitize($_POST['lot_number'] ?? '')));
$cavity = trim(sanitize($_POST['cavity'] ?? ''));
$inspectingDate = sanitize($_POST['inspecting_date'] ?? date('Y-m-d'));
$statusInspect = (sanitize($_POST['status_inspect'] ?? 'OK') === 'NG') ? 'NG' : 'OK';
$pic = trim(sanitize($_POST['pic'] ?? ''));
$remark = trim(sanitize($_POST['remark'] ?? ''));

if (!$id || empty($partCode) || empty($partName) || empty($lotNumber) || empty($cavity) || empty($pic)) {
    set_flash('error', 'Semua kolom wajib (*) harus diisi!');
    redirect('modules/did/edit.php?id=' . $id);
}

$pdo = getDB();

if ($pdo) {
    try {
        // Check duplicate excluding current record
        $stmtDup = $pdo->prepare("SELECT id FROM daily_inspection_data WHERE part_code = :pcode AND lot_number = :lot AND id != :id");
        $stmtDup->execute([':pcode' => $partCode, ':lot' => $lotNumber, ':id' => $id]);
        if ($stmtDup->fetch()) {
            set_flash('error', 'Duplikat! Kombinasi Part Code "' . $partCode . '" dan Lot Number "' . $lotNumber . '" sudah digunakan oleh data lain.');
            redirect('modules/did/edit.php?id=' . $id);
        }

        $stmtUpdate = $pdo->prepare("UPDATE daily_inspection_data SET 
            part_code = :pcode,
            part_name = :pname,
            lot_number = :lot,
            cavity = :cav,
            inspecting_date = :idate,
            status_inspect = :status,
            pic = :pic,
            remark = :remark,
            updated_at = NOW()
            WHERE id = :id");

        $stmtUpdate->execute([
            ':pcode'  => $partCode,
            ':pname'  => $partName,
            ':lot'    => $lotNumber,
            ':cav'    => $cavity,
            ':idate'  => $inspectingDate,
            ':status' => $statusInspect,
            ':pic'    => $pic,
            ':remark' => $remark,
            ':id'     => $id
        ]);

        set_flash('success', 'Data DID berhasil diperbarui!');
    } catch (PDOException $e) {
        set_flash('error', 'Gagal meng-update data DID: ' . $e->getMessage());
    }
} else {
    set_flash('error', 'Database tidak terhubung!');
}

redirect('modules/did/index.php');
