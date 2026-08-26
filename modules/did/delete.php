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

if ($pdo) {
    try {
        $pdo->beginTransaction();

        $stmtSelect = $pdo->prepare("SELECT lot_number FROM daily_inspection_data WHERE id = :id");
        $stmtSelect->execute([':id' => $id]);
        $row = $stmtSelect->fetch();

        if ($row) {
            // Delete linked inspection sessions first (cascades to samples & ng records)
            $stmtDelSessions = $pdo->prepare("DELETE FROM inspection_sessions WHERE did_id = :id");
            $stmtDelSessions->execute([':id' => $id]);

            // Delete DID item
            $stmtDel = $pdo->prepare("DELETE FROM daily_inspection_data WHERE id = :id");
            $stmtDel->execute([':id' => $id]);

            $pdo->commit();
            set_flash('success', 'Data DID untuk Lot "' . htmlspecialchars($row['lot_number']) . '" berhasil dihapus!');
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

redirect('modules/did/index.php');
