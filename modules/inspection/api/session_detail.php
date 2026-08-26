<?php
/**
 * API: Session Detail (untuk SPA View Workbench)
 * Mengembalikan data lengkap sebuah inspection session dalam format JSON
 * Params: id (session ID)
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

$sessionId = (int)($_GET['id'] ?? 0);
if (!$sessionId) {
    echo json_encode(['success' => false, 'message' => 'Session ID wajib diisi']);
    exit;
}

try {
    // Main session data
    $stmt = $pdo->prepare("
        SELECT s.*,
               did.part_code, did.part_name, did.lot_number, did.cavity, did.pic as did_pic,
               k.kanban_no, k.item_code as kanban_item_code, k.item_description as kanban_item_desc, k.customer, k.req_date as kanban_req_date, k.eta as kanban_eta, k.str_loc as kanban_str_loc, k.supply_area as kanban_supply_area, k.check_type as kanban_check_type, k.remark as kanban_remark, k.qty as kanban_qty,
               b.document_number as doc_no, b.vendor as kanban_vendor, b.plan_type as batch_plan_type,
               p.id as part_id, p.model as part_model, d.drawing_2d_path, d.drawing_3d_path
        FROM inspection_sessions s
        JOIN daily_inspection_data did ON did.id = s.did_id
        LEFT JOIN kanban_items k ON k.id = s.kanban_item_id
        LEFT JOIN kanban_batches b ON b.id = k.batch_id
        LEFT JOIN master_parts p ON p.id = COALESCE(
            s.part_id,
            (SELECT mp.id FROM master_parts mp WHERE UPPER(mp.part_code) = UPPER(did.part_code) LIMIT 1)
        )
        LEFT JOIN master_drawings d ON d.part_id = p.id
        WHERE s.id = :id
    ");
    $stmt->execute([':id' => $sessionId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        echo json_encode(['success' => false, 'message' => 'Sesi inspeksi tidak ditemukan']);
        exit;
    }

    // AQL Standard Extra Lookup
    $qty = (int)($session['kanban_qty'] ?? 500);
    $stmtAql = $pdo->prepare("SELECT sample_code, accept_number FROM aql_standards WHERE :qty BETWEEN qty_min AND qty_max LIMIT 1");
    $stmtAql->execute([':qty' => $qty]);
    $aqlExtra = $stmtAql->fetch(PDO::FETCH_ASSOC);
    $session['sample_code'] = $aqlExtra['sample_code'] ?? 'H';
    $session['accept_number'] = (int)($aqlExtra['accept_number'] ?? 0);

    // NG Records
    $stmtNg = $pdo->prepare("
        SELECT n.id, n.qty_ng, n.remark, n.created_at,
               dt.name as defect_name,
               sp.sample_number
        FROM inspection_ng_records n
        JOIN inspection_samples sp ON sp.id = n.inspection_sample_id
        JOIN defect_types dt ON dt.id = n.defect_type_id
        WHERE sp.inspection_session_id = :sid
        ORDER BY n.id DESC
    ");
    $stmtNg->execute([':sid' => $sessionId]);
    $ngRecords = $stmtNg->fetchAll(PDO::FETCH_ASSOC);

    // Previous sessions (same part code)
    $previousSessions = [];
    if (!empty($session['part_code'])) {
        $stmtPrev = $pdo->prepare("
            SELECT s.id, s.started_at, s.status, s.samples_checked, s.sample_size, s.ng_count, s.reject_number,
                   did.lot_number, did.cavity
            FROM inspection_sessions s
            JOIN daily_inspection_data did ON did.id = s.did_id
            WHERE UPPER(did.part_code) = UPPER(:pcode) AND s.id != :curr_id
            ORDER BY s.id DESC
            LIMIT 5
        ");
        $stmtPrev->execute([':pcode' => $session['part_code'], ':curr_id' => $sessionId]);
        $previousSessions = $stmtPrev->fetchAll(PDO::FETCH_ASSOC);
    }

    // Defect types
    $defectTypes = $pdo->query("SELECT id, name FROM defect_types ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

    // Resolve drawing URLs
    $drawing2d = $session['drawing_2d_path'] ? base_url($session['drawing_2d_path']) : null;
    $drawing3d = $session['drawing_3d_path'] ? base_url($session['drawing_3d_path']) : null;

    echo json_encode([
        'success' => true,
        'session' => [
            'id'              => (int)$session['id'],
            'status'          => $session['status'],
            'inspection_type' => $session['inspection_type'],
            'samples_checked' => (int)$session['samples_checked'],
            'sample_size'     => (int)$session['sample_size'],
            'ng_count'        => (int)$session['ng_count'],
            'reject_number'   => (int)$session['reject_number'],
            'started_at'      => $session['started_at'],
            'part_code'       => $session['part_code'],
            'part_name'       => $session['part_name'],
            'lot_number'      => $session['lot_number'],
            'cavity'          => $session['cavity'],
            'did_pic'         => $session['did_pic'],
            'kanban_no'       => $session['kanban_no'],
            'customer'        => $session['customer'] ?? 'PT. Indonesia Epson Industry',
            'kanban_qty'      => $session['kanban_qty'],
            'doc_no'          => $session['doc_no'],
            'part_id'         => $session['part_id'],
            'drawing_2d'      => $drawing2d,
            'drawing_3d'      => $drawing3d,
        ],
        'ng_records'       => $ngRecords,
        'previous_sessions' => $previousSessions,
        'defect_types'     => $defectTypes,
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
}
