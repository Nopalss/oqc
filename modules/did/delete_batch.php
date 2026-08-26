<?php
/**
 * Action Handler: Delete Entire DID Batch & Its Items
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    set_flash('error', 'ID Batch tidak valid!');
    redirect('modules/did/index.php');
}

$pdo = getDB();

if ($pdo) {
    try {
        $pdo->beginTransaction();

        $stmtSelect = $pdo->prepare("SELECT batch_name FROM did_batches WHERE id = :id");
        $stmtSelect->execute([':id' => $id]);
        $row = $stmtSelect->fetch();

        if ($row) {
            // 1. Delete linked inspection sessions for all items in this batch
            $stmtDelSessions = $pdo->prepare("DELETE FROM inspection_sessions WHERE did_id IN (SELECT id FROM daily_inspection_data WHERE batch_id = :batch_id)");
            $stmtDelSessions->execute([':batch_id' => $id]);

            // 2. Delete all item rows belonging to this batch
            $stmtDelItems = $pdo->prepare("DELETE FROM daily_inspection_data WHERE batch_id = :batch_id");
            $stmtDelItems->execute([':batch_id' => $id]);

            // 3. Delete batch header
            $stmtDelBatch = $pdo->prepare("DELETE FROM did_batches WHERE id = :id");
            $stmtDelBatch->execute([':id' => $id]);

            $pdo->commit();
            set_flash('success', 'Riwayat Batch "' . htmlspecialchars($row['batch_name']) . '" beserta seluruh item di dalamnya berhasil dihapus!');
        } else {
            $pdo->rollBack();
            set_flash('error', 'Riwayat Batch tidak ditemukan!');
        }
    } catch (PDOException $e) {
        if ($pdo && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', 'Gagal menghapus batch DID: ' . $e->getMessage());
    }
} else {
    set_flash('error', 'Database tidak terhubung!');
}

redirect('modules/did/index.php');
