<?php
/**
 * Action Handler: Penutupan Karantina Administratif Lot NG
 * File: modules/ng_quarantine/close_action.php
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    echo json_encode([
        'success' => false,
        'message' => 'Sesi login telah berakhir. Silakan login kembali.'
    ]);
    exit;
}

$currentUser = current_user();
$userRole    = strtolower($currentUser['role'] ?? '');

// Otorisasi role Chief / Admin / Supervisor / Manager
$authorizedRoles = ['admin', 'chief', 'supervisor', 'superadmin', 'manager'];
$isAuthorized = in_array($userRole, $authorizedRoles, true) || can_access('ng_quarantine') || can_access('inspection');

if (!$isAuthorized) {
    echo json_encode([
        'success' => false,
        'message' => 'Anda tidak memiliki hak akses untuk menutup karantina lot ini.'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Metode request tidak valid. Gunakan POST.'
    ]);
    exit;
}

$lotId = (int)($_POST['lot_id'] ?? 0);
$closureType = strtolower(trim(sanitize($_POST['closure_type'] ?? 'action')));
if (!in_array($closureType, ['action', 'abolish'], true)) {
    $closureType = 'action';
}
$notes = trim(sanitize($_POST['action_notes'] ?? ''));

if ($lotId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'ID Lot tidak valid.'
    ]);
    exit;
}

if (empty($notes)) {
    $fieldLabel = ($closureType === 'abolish') ? 'Keterangan/alasan pembuangan (abolish)' : 'Catatan penanganan';
    echo json_encode([
        'success' => false,
        'message' => $fieldLabel . ' wajib diisi.'
    ]);
    exit;
}

$pdo = getDB();

try {
    // Pastikan lot ada
    $stmtLot = $pdo->prepare("
        SELECT isl.id, isl.lot_number, isl.ref_number, isl.inspection_session_id
        FROM inspection_session_lots isl
        WHERE isl.id = :id
        LIMIT 1
    ");
    $stmtLot->execute([':id' => $lotId]);
    $lot = $stmtLot->fetch(PDO::FETCH_ASSOC);

    if (!$lot) {
        echo json_encode([
            'success' => false,
            'message' => 'Data Lot NG tidak ditemukan di database.'
        ]);
        exit;
    }

    $userId = $currentUser['id'] ?? ($_SESSION['user_id'] ?? 0);

    $pdo->beginTransaction();

    // 1. Simpan ke tabel lot_quarantine_closures dengan closure_type
    $stmtInsert = $pdo->prepare("
        INSERT INTO lot_quarantine_closures (session_lot_id, closure_type, closed_by, closed_at, notes)
        VALUES (:lot_id, :closure_type, :closed_by, NOW(), :notes)
    ");
    $stmtInsert->execute([
        ':lot_id'       => $lotId,
        ':closure_type' => $closureType,
        ':closed_by'    => $userId,
        ':notes'        => $notes
    ]);

    // 2. Update status lot di inspection_session_lots
    $newStatus = ($closureType === 'abolish') ? 'abolished' : 'action_closed';
    $stmtUpdateLot = $pdo->prepare("
        UPDATE inspection_session_lots
        SET lot_status = :new_status,
            closed_at = NOW(),
            chief_notes = CONCAT(COALESCE(chief_notes, ''), '\n[', UPPER(:ctype), ']: ', :notes)
        WHERE id = :lot_id
    ");
    $stmtUpdateLot->execute([
        ':new_status' => $newStatus,
        ':ctype'      => $closureType,
        ':notes'      => $notes,
        ':lot_id'     => $lotId
    ]);

    $pdo->commit();

    $successMsg = ($closureType === 'abolish') 
        ? "Lot #{$lot['lot_number']} berhasil di-ABOLISH (Scrap/Dibuang). Status karantina ditutup dan lot ini diblokir dari inspeksi/scan berikutnya."
        : "Action untuk Lot #{$lot['lot_number']} berhasil disimpan. Status karantina ditutup berdasarkan laporan penanganan divisi terkait.";

    echo json_encode([
        'success'      => true,
        'closure_type' => $closureType,
        'message'      => $successMsg,
        'lot_id'       => $lotId
    ]);
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem saat menyimpan penutupan karantina: ' . $e->getMessage()
    ]);
    exit;
}
