<?php
/**
 * Action Handler: Delete Daily Inspection Data (DID) Item
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
$batchId = 0;

if (!$id) {
    set_flash('error', 'ID DID tidak valid!');
    redirect('modules/did/index.php');
}

$pdo = getDB();

if ($pdo) {
    try {
        $stmtSelect = $pdo->prepare("SELECT batch_id, part_code, lot_number FROM daily_inspection_data WHERE id = :id");
        $stmtSelect->execute([':id' => $id]);
        $row = $stmtSelect->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            set_flash('error', 'Data DID tidak ditemukan!');
            redirect('modules/did/index.php');
        }

        $batchId = (int)$row['batch_id'];

        // ── INSPECTION DEPENDENCY GUARD ──────────────────────────────────────────
        // Cek apakah item DID ini sudah dipakai oleh sesi inspeksi QC
        $stmtCheckSession = $pdo->prepare("SELECT COUNT(*) FROM inspection_sessions WHERE did_id = :id");
        $stmtCheckSession->execute([':id' => $id]);
        $sessionCount = (int)$stmtCheckSession->fetchColumn();

        if ($sessionCount > 0) {
            set_flash('warning', 'Item Lot "' . htmlspecialchars($row['lot_number']) . '" (Part: ' . htmlspecialchars($row['part_code']) . ') TIDAK DAPAT DIHAPUS karena sudah terikat dengan ' . $sessionCount . ' riwayat sesi inspeksi QC.');
            if ($batchId > 0) {
                redirect('modules/did/detail_batch.php?batch_id=' . $batchId);
            } else {
                redirect('modules/did/index.php');
            }
        }

        // Belum dipakai inspeksi -> hapus item secara aman
        $pdo->beginTransaction();

        $stmtDel = $pdo->prepare("DELETE FROM daily_inspection_data WHERE id = :id");
        $stmtDel->execute([':id' => $id]);

        // Resync counter pada did_batches
        if ($batchId > 0) {
            $stmtResync = $pdo->prepare("
                UPDATE did_batches SET 
                    total_items = (SELECT COUNT(*) FROM daily_inspection_data WHERE batch_id = :b1),
                    ok_count = (SELECT COUNT(*) FROM daily_inspection_data WHERE batch_id = :b2 AND status_inspect = 'OK'),
                    ng_count = (SELECT COUNT(*) FROM daily_inspection_data WHERE batch_id = :b3 AND status_inspect = 'NG')
                WHERE id = :bid
            ");
            $stmtResync->execute([':b1' => $batchId, ':b2' => $batchId, ':b3' => $batchId, ':bid' => $batchId]);
        }

        $pdo->commit();
        set_flash('success', 'Data DID Part "' . htmlspecialchars($row['part_code']) . '" Lot "' . htmlspecialchars($row['lot_number']) . '" berhasil dihapus!');

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
