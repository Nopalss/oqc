<?php
/**
 * Action Handler: Delete Kanban Schedule Item (FR-1)
 * Secured with RBAC, CSRF, and Inspection Session Dependency Check
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

require_menu_access('kanban');

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$returnDate = trim(sanitize($_POST['return_date'] ?? $_GET['return_date'] ?? ''));
$batchId = filter_input(INPUT_POST, 'batch_id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_GET, 'batch_id', FILTER_VALIDATE_INT);

if (!empty($batchId)) {
    $targetRedirect = 'modules/kanban/detail_batch.php?batch_id=' . $batchId;
} elseif (!empty($returnDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $returnDate)) {
    $targetRedirect = 'modules/kanban/daily_detail.php?date=' . urlencode($returnDate);
} else {
    $targetRedirect = 'modules/kanban/index.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

if (!$id) {
    set_flash('error', 'ID Kanban tidak valid!');
    redirect($targetRedirect);
}

$pdo = getDB();

if ($pdo) {
    try {
        $stmtSelect = $pdo->prepare("SELECT id, kanban_no, batch_id FROM kanban_items WHERE id = :id");
        $stmtSelect->execute([':id' => $id]);
        $row = $stmtSelect->fetch();

        if ($row) {
            // Guard: Check if linked to any inspection session
            $stmtCheckSess = $pdo->prepare("SELECT COUNT(*) FROM inspection_sessions WHERE kanban_item_id = :id");
            $stmtCheckSess->execute([':id' => $id]);
            if ((int)$stmtCheckSess->fetchColumn() > 0) {
                set_flash('error', 'Item Kanban "' . htmlspecialchars($row['kanban_no']) . '" tidak dapat dihapus karena sudah memiliki riwayat sesi inspeksi!');
                redirect($targetRedirect);
            }

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

redirect($targetRedirect);
