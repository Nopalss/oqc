<?php
/**
 * Action Handler: Delete Daily Inspection Data (DID)
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    set_flash('error', 'ID DID tidak valid!');
    redirect('modules/did/index.php');
}

$pdo = getDB();
$batchId = 0;

if ($pdo) {
    try {
        $pdo->beginTransaction();

        $stmtSelect = $pdo->prepare("SELECT batch_id, part_code, lot_number FROM daily_inspection_data WHERE id = :id");
        $stmtSelect->execute([':id' => $id]);
        $row = $stmtSelect->fetch();

        if ($row) {
            $batchId = (int)$row['batch_id'];

            // Delete linked inspection sessions first (cascades to samples & ng records)
            $stmtDelSessions = $pdo->prepare("DELETE FROM inspection_sessions WHERE did_id = :id");
            $stmtDelSessions->execute([':id' => $id]);

            // Delete DID item
            $stmtDel = $pdo->prepare("DELETE FROM daily_inspection_data WHERE id = :id");
            $stmtDel->execute([':id' => $id]);

            $pdo->commit();
            set_flash('success', 'Data DID Part "' . htmlspecialchars($row['part_code']) . '" Lot "' . htmlspecialchars($row['lot_number']) . '" berhasil dihapus!');
        } else {
            $pdo->rollBack();
            set_flash('error', 'Data DID tidak ditemukan!');
        }
    } catch (PDOException $e) {
        if ($pdo && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', 'Gagal menghapus data DID: ' . $e->getMessage());
    }
} else {
    set_flash('error', 'Database tidak terhubung!');
}

if ($batchId > 0) {
    redirect('modules/did/detail_batch.php?batch_id=' . $batchId);
} else {
    redirect('modules/did/index.php');
}
