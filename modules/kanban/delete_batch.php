<?php
/**
 * Action Handler: Delete Entire Kanban Batch & Its Items
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    set_flash('error', 'ID Dokumen Batch tidak valid!');
    redirect('modules/kanban/index.php');
}

$pdo = getDB();

if ($pdo) {
    try {
        $stmtSelect = $pdo->prepare("SELECT document_number FROM kanban_batches WHERE id = :id");
        $stmtSelect->execute([':id' => $id]);
        $row = $stmtSelect->fetch();

        if ($row) {
            // Delete all item rows belonging to this batch
            $stmtDelItems = $pdo->prepare("DELETE FROM kanban_items WHERE batch_id = :batch_id");
            $stmtDelItems->execute([':batch_id' => $id]);

            // Delete batch header
            $stmtDelBatch = $pdo->prepare("DELETE FROM kanban_batches WHERE id = :id");
            $stmtDelBatch->execute([':id' => $id]);

            set_flash('success', 'Dokumen Batch "' . htmlspecialchars($row['document_number']) . '" beserta seluruh item di dalamnya berhasil dihapus!');
        } else {
            set_flash('error', 'Dokumen Batch tidak ditemukan!');
        }
    } catch (PDOException $e) {
        set_flash('error', 'Gagal menghapus dokumen batch Kanban: ' . $e->getMessage());
    }
} else {
    set_flash('error', 'Database tidak terhubung!');
}

redirect('modules/kanban/index.php');
