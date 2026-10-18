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
$notes = trim(sanitize($_POST['action_notes'] ?? ''));

if ($lotId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'ID Lot tidak valid.'
    ]);
    exit;
}

if (empty($notes)) {
    echo json_encode([
        'success' => false,
        'message' => 'Catatan penanganan wajib diisi sebagai bukti penutupan karantina.'
    ]);
    exit;
}

$pdo = getDB();

try {
    // Pastikan lot ada
    $stmtLot = $pdo->prepare("
        SELECT isl.id, isl.lot_number, isl.ref_number 
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

    // Simpan ke tabel baru lot_quarantine_closures
    $stmtInsert = $pdo->prepare("
        INSERT INTO lot_quarantine_closures (session_lot_id, closed_by, closed_at, notes)
        VALUES (:lot_id, :closed_by, NOW(), :notes)
    ");
    $stmtInsert->execute([
        ':lot_id'    => $lotId,
        ':closed_by' => $userId,
        ':notes'     => $notes
    ]);

    echo json_encode([
        'success' => true,
        'message' => "Karantina Lot #{$lot['lot_number']} berhasil ditutup berdasarkan laporan penanganan divisi terkait.",
        'lot_id'  => $lotId
    ]);
    exit;

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Terjadi kesalahan sistem saat menyimpan penutupan karantina: ' . $e->getMessage()
    ]);
    exit;
}
