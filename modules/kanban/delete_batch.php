<?php
/**
 * Action Handler: Delete Entire Kanban Batch & Its Items
 * Secured with RBAC, CSRF, and Inspection Session Dependency Check
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

require_menu_access('kanban');

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$returnDate = trim(sanitize($_POST['return_date'] ?? $_GET['return_date'] ?? ''));

$targetRedirect = (!empty($returnDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $returnDate))
    ? 'modules/kanban/daily_detail.php?date=' . urlencode($returnDate)
    : 'modules/kanban/index.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

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
            // Guard: Check if any item in this batch is already linked to an inspection session
            $stmtCheckSess = $pdo->prepare("
                SELECT COUNT(s.id) 
                FROM inspection_sessions s
                INNER JOIN kanban_items ki ON ki.id = s.kanban_item_id
                WHERE ki.batch_id = :bid
            ");
            $stmtCheckSess->execute([':bid' => $id]);
            if ((int)$stmtCheckSess->fetchColumn() > 0) {
                set_flash('error', 'Dokumen Batch "' . htmlspecialchars($row['document_number']) . '" tidak dapat dihapus karena terdapat item yang sudah terdaftar dalam sesi inspeksi!');
                redirect($targetRedirect);
            }

            $pdo->beginTransaction();

            // Delete all item rows belonging to this batch
            $stmtDelItems = $pdo->prepare("DELETE FROM kanban_items WHERE batch_id = :batch_id");
            $stmtDelItems->execute([':batch_id' => $id]);

            // Delete batch header
            $stmtDelBatch = $pdo->prepare("DELETE FROM kanban_batches WHERE id = :id");
            $stmtDelBatch->execute([':id' => $id]);

            $pdo->commit();

            set_flash('success', 'Dokumen Batch "' . htmlspecialchars($row['document_number']) . '" beserta seluruh item di dalamnya berhasil dihapus!');
        } else {
            set_flash('error', 'Dokumen Batch tidak ditemukan!');
        }
    } catch (PDOException $e) {
        if ($pdo && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        set_flash('error', 'Gagal menghapus dokumen batch Kanban: ' . $e->getMessage());
    }
} else {
    set_flash('error', 'Database tidak terhubung!');
}

redirect($targetRedirect);
