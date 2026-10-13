<?php
/**
 * Action Handler: Delete Entire DID Batch & Its Items
 * Hardened: Requires POST method + Valid CSRF Token + RBAC Check + Inspection Sesi Guard
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

require_menu_access('did');

// Tolak jika bukan method POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    set_flash('error', 'Metode request tidak diizinkan!');
    redirect('modules/did/index.php');
}

// Verifikasi CSRF Token
if (!verify_csrf_token()) {
    set_flash('error', 'Sesi keamanan (CSRF Token) tidak valid atau telah kedaluwarsa. Silakan muat ulang halaman.');
    redirect('modules/did/index.php');
}

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    set_flash('error', 'ID Batch tidak valid!');
    redirect('modules/did/index.php');
}

$pdo = getDB();

if ($pdo) {
    try {
        $stmtSelect = $pdo->prepare("SELECT id, batch_name FROM did_batches WHERE id = :id");
        $stmtSelect->execute([':id' => $id]);
        $row = $stmtSelect->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            set_flash('error', 'Riwayat Batch tidak ditemukan!');
            redirect('modules/did/index.php');
        }

        // ── INSPECTION DEPENDENCY GUARD ──────────────────────────────────────────
        // Cek apakah ada item di batch ini yang sudah memiliki sesi inspeksi QC
        $stmtCheckUsage = $pdo->prepare("
            SELECT COUNT(*) 
            FROM inspection_sessions s
            JOIN daily_inspection_data d ON d.id = s.did_id
            WHERE d.batch_id = :batch_id
        ");
        $stmtCheckUsage->execute([':batch_id' => $id]);
        $activeSessionsCount = (int)$stmtCheckUsage->fetchColumn();

        if ($activeSessionsCount > 0) {
            set_flash('warning', 'Batch "' . htmlspecialchars($row['batch_name']) . '" TIDAK DAPAT DIHAPUS karena memiliki ' . $activeSessionsCount . ' riwayat sesi inspeksi QC yang sudah tercatat.');
            redirect('modules/did/index.php');
        }

        // Belum dipakai inspeksi sama sekali -> aman dihapus
        $pdo->beginTransaction();

        // 1. Hapus semua item dalam batch
        $stmtDelItems = $pdo->prepare("DELETE FROM daily_inspection_data WHERE batch_id = :batch_id");
        $stmtDelItems->execute([':batch_id' => $id]);

        // 2. Hapus batch header
        $stmtDelBatch = $pdo->prepare("DELETE FROM did_batches WHERE id = :id");
        $stmtDelBatch->execute([':id' => $id]);

        $pdo->commit();
        set_flash('success', 'Riwayat Batch "' . htmlspecialchars($row['batch_name']) . '" berhasil dihapus!');

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
