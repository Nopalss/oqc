<?php
/**
 * Action Handler: Pembatalan Penutupan Karantina Lot NG
 * File: modules/ng_quarantine/cancel_action.php
 *
 * Membatalkan status ABOLISH / ACTION dengan menghapus closure lot
 * dan mengembalikan status lot ke kondisi karantina (NG).
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Sesi login telah berakhir. Silakan login kembali.'
    ]);
    exit;
}

$currentUser = current_user();
$userRole    = strtolower($currentUser['role'] ?? '');

// Hanya level supervisor ke atas yang boleh membatalkan keputusan karantina
$authorizedRoles = ['admin', 'chief', 'supervisor', 'superadmin', 'manager'];
if (!in_array($userRole, $authorizedRoles, true)) {
    echo json_encode([
        'success' => false,
        'message' => 'Anda tidak memiliki hak akses untuk membatalkan penutupan karantina lot ini.'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Metode permintaan tidak valid.'
    ]);
    exit;
}

$lotId       = (int)($_POST['lot_id'] ?? 0);
$closureType = strtolower(trim((string)($_POST['closure_type'] ?? '')));

if ($lotId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'ID Lot tidak valid.'
    ]);
    exit;
}

if (!in_array($closureType, ['abolish', 'action'], true)) {
    echo json_encode([
        'success' => false,
        'message' => 'Tipe penutupan tidak valid.'
    ]);
    exit;
}

$pdo = getDB();

if (!$pdo) {
    echo json_encode([
        'success' => false,
        'message' => 'Gagal terhubung ke database server.'
    ]);
    exit;
}

try {
    $pdo->beginTransaction();

    // Lock data lot
    $stmtLot = $pdo->prepare("
        SELECT id, lot_number, lot_result, lot_status, replaced_by_lot_id
        FROM inspection_session_lots
        WHERE id = :id
        FOR UPDATE
    ");
    $stmtLot->execute([':id' => $lotId]);
    $lot = $stmtLot->fetch(PDO::FETCH_ASSOC);

    if (!$lot) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'message' => 'Data Lot NG tidak ditemukan.'
        ]);
        exit;
    }

    // Ambil closure terakhir (yang sedang berlaku)
    $stmtClosure = $pdo->prepare("
        SELECT id, closure_type
        FROM lot_quarantine_closures
        WHERE session_lot_id = :id
        ORDER BY id DESC
        LIMIT 1
        FOR UPDATE
    ");
    $stmtClosure->execute([':id' => $lotId]);
    $closure = $stmtClosure->fetch(PDO::FETCH_ASSOC);

    if (!$closure) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'message' => 'Lot ini tidak memiliki data penutupan yang bisa dibatalkan.'
        ]);
        exit;
    }

    if (strtolower($closure['closure_type']) !== $closureType) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'message' => 'Status penutupan lot sudah berubah. Silakan muat ulang halaman.'
        ]);
        exit;
    }

    // Hapus seluruh closure lot agar tidak ada closure lama yang aktif kembali
    $stmtDel = $pdo->prepare("DELETE FROM lot_quarantine_closures WHERE session_lot_id = :id");
    $stmtDel->execute([':id' => $lotId]);

    // Hitung defect aktif yang masih ada
    $stmtNg = $pdo->prepare("
        SELECT COALESCE(SUM(qty_ng), 0)
        FROM inspection_ng_records
        WHERE session_lot_id = :id
          AND (is_cancelled IS NULL OR is_cancelled = 0)
    ");
    $stmtNg->execute([':id' => $lotId]);
    $activeNg = (int)$stmtNg->fetchColumn();

    // Apakah lot pernah masuk proses sortir / re-inspeksi?
    $stmtSub = $pdo->prepare("
        SELECT 1
        FROM lot_substitution_log
        WHERE ng_session_lot_id = :id
        LIMIT 1
    ");
    $stmtSub->execute([':id' => $lotId]);
    $hasSubstitution = (bool)$stmtSub->fetchColumn();
    $hasSubstitution = $hasSubstitution || !empty($lot['replaced_by_lot_id']);

    $lotResult = strtolower($lot['lot_result'] ?? '');

    // Tentukan status sebelum penutupan
    if ($lotResult === 'passed') {
        $restoredStatus = 'ok';
    } elseif ($hasSubstitution) {
        $restoredStatus = 'ng_quarantine';
    } elseif ($activeNg > 0 || $lotResult === 'rejected') {
        $restoredStatus = 'ng_found';
    } else {
        $restoredStatus = 'ok';
    }

    $stmtUpdate = $pdo->prepare("
        UPDATE inspection_session_lots
        SET lot_status = :status,
            closed_at  = NULL
        WHERE id = :id
    ");
    $stmtUpdate->execute([
        ':status' => $restoredStatus,
        ':id'     => $lotId
    ]);

    $pdo->commit();

    $label = ($closureType === 'abolish') ? 'ABOLISH' : 'ACTION';
    echo json_encode([
        'success' => true,
        'message' => "Penutupan {$label} untuk Lot #{$lot['lot_number']} berhasil dibatalkan. Lot dikembalikan ke status karantina."
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()
    ]);
}
