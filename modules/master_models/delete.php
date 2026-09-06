<?php
/**
 * Action Handler: Delete Master Model
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    set_flash('error', 'ID Model tidak valid.');
    redirect('modules/master_models/index.php');
}

$pdo = getDB();
if ($pdo) {
    try {
        // Reset model_id in master_parts referencing this model
        $stmtReset = $pdo->prepare("UPDATE master_parts SET model_id = NULL WHERE model_id = :id");
        $stmtReset->execute([':id' => $id]);

        $stmtDel = $pdo->prepare("DELETE FROM master_models WHERE id = :id");
        $stmtDel->execute([':id' => $id]);

        set_flash('success', 'Master Model berhasil dihapus.');
    } catch (PDOException $e) {
        set_flash('error', 'Gagal menghapus Master Model: ' . $e->getMessage());
    }
}

redirect('modules/master_models/index.php');
