<?php
/**
 * Action Handler: Delete Kanban Schedule Item
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    set_flash('error', 'ID Kanban tidak valid!');
    redirect('modules/kanban/index.php');
}

$pdo = getDB();

if ($pdo) {
    try {
        $stmtSelect = $pdo->prepare("SELECT kanban_no FROM kanban_items WHERE id = :id");
        $stmtSelect->execute([':id' => $id]);
        $row = $stmtSelect->fetch();

        if ($row) {
            $stmtDel = $pdo->prepare("DELETE FROM kanban_items WHERE id = :id");
            $stmtDel->execute([':id' => $id]);
            set_flash('success', 'Data Kanban No. "' . htmlspecialchars($row['kanban_no']) . '" berhasil dihapus!');
        } else {
            set_flash('error', 'Data Kanban tidak ditemukan!');
        }
    } catch (PDOException $e) {
        set_flash('error', 'Gagal menghapus data Kanban: ' . $e->getMessage());
    }
} else {
    set_flash('error', 'Database tidak terhubung!');
}

redirect('modules/kanban/index.php');
