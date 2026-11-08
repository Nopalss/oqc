<?php
/**
 * AJAX Endpoint - Ambil Daftar Lot Fisik Ready di Rak untuk Part Tertentu
 * Digunakan oleh Modal Kelola & Hapus Lot di modules/safety_stock/index.php
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

require_menu_access('safety_stock');

$currentUser = current_user();
$canManage = in_array($currentUser['role'] ?? '', ['admin', 'chief']);

$partCode = sanitize($_GET['part_code'] ?? '');
if (empty($partCode)) {
    echo json_encode([
        'success' => false,
        'message' => 'Parameter part_code tidak boleh kosong.'
    ]);
    exit;
}

$pdo = getDB();
if (!$pdo) {
    echo json_encode([
        'success' => false,
        'message' => 'Koneksi database gagal.'
    ]);
    exit;
}

try {
    // 1. Ambil Informasi Part
    $stPart = $pdo->prepare("
        SELECT 
            COALESCE(did.part_code, ki.item_code, :pcode) AS part_code,
            COALESCE(did.part_name, ki.item_description, 'Part Safety Stock') AS part_name
        FROM inspection_sessions ss
        LEFT JOIN daily_inspection_data did ON did.id = ss.did_id
        LEFT JOIN kanban_items ki ON ki.id = ss.kanban_item_id
        WHERE ss.inspection_type = 'safety_stock'
          AND (did.part_code = :p1 OR ki.item_code = :p2)
        LIMIT 1
    ");
    $stPart->execute([
        ':pcode' => $partCode,
        ':p1'    => $partCode,
        ':p2'    => $partCode
    ]);
    $partInfo = $stPart->fetch(PDO::FETCH_ASSOC);

    $partName = $partInfo ? $partInfo['part_name'] : 'Part Safety Stock';

    // 2. Ambil seluruh lot yang ADA DI RAK (Passed & Belum memotong kanban)
    $stLots = $pdo->prepare("
        SELECT 
            isl.id AS lot_id,
            isl.inspection_session_id AS session_id,
            COALESCE(isl.lot_number, did.lot_number, '-') AS lot_number,
            COALESCE(isl.ref_number, '-') AS ref_number,
            COALESCE(isl.qty, 0) AS qty,
            isl.remarks AS lot_remarks,
            ss.ss_source,
            ss.started_at,
            ss.auto_fulfilled_by_session_id,
            COALESCE(u.name, did.pic, 'Inspector QC') AS inspector_name
        FROM inspection_session_lots isl
        JOIN inspection_sessions ss ON ss.id = isl.inspection_session_id
        LEFT JOIN daily_inspection_data did ON did.id = ss.did_id
        LEFT JOIN kanban_items ki ON ki.id = ss.kanban_item_id
        LEFT JOIN users u ON u.id = ss.inspector_id
        WHERE ss.inspection_type = 'safety_stock'
          AND (did.part_code = :p1 OR ki.item_code = :p2)
          AND ss.status = 'passed'
          AND (ss.auto_fulfilled_by_session_id IS NULL OR ss.auto_fulfilled_by_session_id = 0)
          AND (isl.lot_status IS NULL OR isl.lot_status != 'action_closed')
          AND (isl.lot_result IS NULL OR isl.lot_result != 'rejected')
        ORDER BY isl.id DESC
    ");
    $stLots->execute([':p1' => $partCode, ':p2' => $partCode]);
    $lots = $stLots->fetchAll(PDO::FETCH_ASSOC);

    $formattedLots = [];
    $totalQty = 0;

    foreach ($lots as $row) {
        $qty = (int)$row['qty'];
        $totalQty += $qty;

        $sourceType = $row['ss_source'] ?? 'direct';
        $sourceLabel = ($sourceType === 'kanban_excess' || $sourceType === 'split_leftover')
            ? 'Sisa Kanban'
            : 'Masuk Normal';

        $formattedLots[] = [
            'lot_id'         => (int)$row['lot_id'],
            'session_id'     => (int)$row['session_id'],
            'lot_number'     => $row['lot_number'],
            'ref_number'     => $row['ref_number'],
            'qty'            => $qty,
            'source_type'    => $sourceType,
            'source_label'   => $sourceLabel,
            'remarks'        => $row['lot_remarks'] ?: '-',
            'inspector'      => $row['inspector_name'],
            'date_formatted' => !empty($row['started_at']) ? date('d M Y, H:i', strtotime($row['started_at'])) : '-',
            'is_locked'      => false // Semua yang masuk kriteria ini ready dan bisa dikelola
        ];
    }

    // 3. Fallback: jika tidak ada baris di inspection_session_lots (sesi legacy safety stock)
    if (empty($formattedLots)) {
        $stFallback = $pdo->prepare("
            SELECT 
                ss.id AS session_id,
                ss.total_scanned_qty,
                ss.started_at,
                ss.ss_source,
                COALESCE(did.lot_number, 'LOT-SS') AS did_lot,
                COALESCE(u.name, did.pic, 'Inspector QC') AS inspector_name
            FROM inspection_sessions ss
            LEFT JOIN daily_inspection_data did ON did.id = ss.did_id
            LEFT JOIN kanban_items ki ON ki.id = ss.kanban_item_id
            LEFT JOIN users u ON u.id = ss.inspector_id
            WHERE ss.inspection_type = 'safety_stock'
              AND (did.part_code = :p1 OR ki.item_code = :p2)
              AND ss.status = 'passed'
              AND (ss.auto_fulfilled_by_session_id IS NULL OR ss.auto_fulfilled_by_session_id = 0)
            ORDER BY ss.id DESC
        ");
        $stFallback->execute([':p1' => $partCode, ':p2' => $partCode]);
        $fbSessions = $stFallback->fetchAll(PDO::FETCH_ASSOC);

        foreach ($fbSessions as $fb) {
            $fQty = (int)$fb['total_scanned_qty'];
            if ($fQty <= 0) continue;

            $totalQty += $fQty;
            $sourceType = $fb['ss_source'] ?? 'direct';
            $sourceLabel = ($sourceType === 'kanban_excess' || $sourceType === 'split_leftover')
                ? 'Sisa Kanban'
                : 'Masuk Normal';

            $formattedLots[] = [
                'lot_id'         => 0, // 0 menandakan sesi tanpa individual lot row
                'session_id'     => (int)$fb['session_id'],
                'lot_number'     => $fb['did_lot'],
                'ref_number'     => 'SESI-#' . $fb['session_id'],
                'qty'            => $fQty,
                'source_type'    => $sourceType,
                'source_label'   => $sourceLabel,
                'remarks'        => 'Sesi Utuh (Tanpa Label Pisah)',
                'inspector'      => $fb['inspector_name'],
                'date_formatted' => !empty($fb['started_at']) ? date('d M Y, H:i', strtotime($fb['started_at'])) : '-',
                'is_locked'      => false
            ];
        }
    }

    echo json_encode([
        'success'    => true,
        'can_manage' => $canManage,
        'part_code'  => $partCode,
        'part_name'  => $partName,
        'total_lots' => count($formattedLots),
        'total_qty'  => $totalQty,
        'lots'       => $formattedLots
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Kesalahan database: ' . $e->getMessage()
    ]);
}

