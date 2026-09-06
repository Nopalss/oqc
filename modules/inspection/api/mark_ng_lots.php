<?php
/**
 * API: Mark NG Lots
 * Dipanggil oleh process_sample.php (internal) dan/atau frontend saat sesi REJECTED.
 * Menandai semua inspection_session_lots yang memiliki NG record sebagai 'ng_found'.
 * Mengembalikan list lot NG beserta ringkasan defect per lot.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/helper.php';

$pdo = getDB();
if (!$pdo) {
    echo json_encode(['success' => false, 'message' => 'DB connection failed']);
    exit;
}

$sessionId = (int)($_POST['session_id'] ?? $_GET['session_id'] ?? 0);
if (!$sessionId) {
    echo json_encode(['success' => false, 'message' => 'Session ID wajib diisi']);
    exit;
}

try {
    // Validasi: sesi harus REJECTED
    $stmtSess = $pdo->prepare("SELECT id, status FROM inspection_sessions WHERE id = :id");
    $stmtSess->execute([':id' => $sessionId]);
    $session = $stmtSess->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        echo json_encode(['success' => false, 'message' => 'Sesi tidak ditemukan']);
        exit;
    }
    if ($session['status'] !== 'rejected') {
        echo json_encode(['success' => false, 'message' => 'Sesi harus berstatus REJECTED']);
        exit;
    }

    // Auto-tag: update semua lot yang punya NG records di sesi ini menjadi 'ng_found'
    $pdo->prepare("
        UPDATE inspection_session_lots isl
        SET isl.lot_status = 'ng_found',
            isl.action_noted_at = NOW()
        WHERE isl.inspection_session_id = :sid
          AND isl.lot_status = 'ok'
          AND EXISTS (
              SELECT 1
              FROM inspection_ng_records n
              JOIN inspection_samples sp ON sp.id = n.inspection_sample_id
              WHERE sp.inspection_session_id = :sid2
                AND n.session_lot_id = isl.id
                AND (n.is_cancelled IS NULL OR n.is_cancelled = 0)
          )
    ")->execute([':sid' => $sessionId, ':sid2' => $sessionId]);

    // Ambil semua lot yang NG (ng_found, ng_quarantine) beserta ringkasan defect
    $stmtNgLots = $pdo->prepare("
        SELECT
            isl.id,
            isl.lot_number,
            isl.ref_number,
            isl.qty,
            isl.lot_status,
            isl.replaced_by_lot_id,
            isl.action_noted_at,
            isl.scanned_qr_raw,
            isl.remarks
        FROM inspection_session_lots isl
        WHERE isl.inspection_session_id = :sid
          AND isl.lot_status IN ('ng_found','ng_quarantine','replaced','reinspected')
        ORDER BY isl.id ASC
    ");
    $stmtNgLots->execute([':sid' => $sessionId]);
    $ngLots = $stmtNgLots->fetchAll(PDO::FETCH_ASSOC);

    // Ambil defect summary per lot
    $stmtDefSum = $pdo->prepare("
        SELECT
            n.session_lot_id,
            dt.name as defect_name,
            SUM(n.qty_ng) as total_qty_ng
        FROM inspection_ng_records n
        JOIN inspection_samples sp ON sp.id = n.inspection_sample_id
        JOIN defect_types dt ON dt.id = n.defect_type_id
        WHERE sp.inspection_session_id = :sid
          AND (n.is_cancelled IS NULL OR n.is_cancelled = 0)
        GROUP BY n.session_lot_id, n.defect_type_id
        ORDER BY n.session_lot_id ASC, total_qty_ng DESC
    ");
    $stmtDefSum->execute([':sid' => $sessionId]);
    $defectRows = $stmtDefSum->fetchAll(PDO::FETCH_ASSOC);

    // Index defect summary by session_lot_id
    $defectByLot = [];
    foreach ($defectRows as $dr) {
        $lid = $dr['session_lot_id'];
        if (!isset($defectByLot[$lid])) {
            $defectByLot[$lid] = [];
        }
        $defectByLot[$lid][] = [
            'defect_name'  => $dr['defect_name'],
            'total_qty_ng' => (int)$dr['total_qty_ng'],
        ];
    }

    // Attach defect summary to each NG lot
    foreach ($ngLots as &$lot) {
        $lot['qty']                = (int)$lot['qty'];
        $lot['ng_defects']         = $defectByLot[$lot['id']] ?? [];
        $lot['replaced_by_lot_id'] = $lot['replaced_by_lot_id'] ? (int)$lot['replaced_by_lot_id'] : null;
    }
    unset($lot);

    echo json_encode([
        'success'     => true,
        'ng_lots'     => $ngLots,
        'ng_lot_count'=> count($ngLots),
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
}
