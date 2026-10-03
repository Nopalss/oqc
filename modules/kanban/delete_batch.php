<?php
/**
 * Action Handler: Delete Entire Kanban Batch & Its Items
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$returnDate = trim(sanitize($_GET['return_date'] ?? ''));
$targetRedirect = (!empty($returnDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $returnDate))
    ? 'modules/kanban/daily_detail.php?date=' . urlencode($returnDate)
    : 'modules/kanban/index.php';

if (!$id) {
    set_flash('error', 'ID Dokumen Batch tidak valid!');
    redirect($targetRedirect);
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

redirect($targetRedirect);
