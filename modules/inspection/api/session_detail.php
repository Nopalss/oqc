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
session_write_close();

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
               k.kanban_no, k.item_code as kanban_item_code, k.item_description as kanban_item_desc, k.customer, k.req_date as kanban_req_date, k.eta as kanban_eta, k.str_loc as kanban_str_loc, k.supply_area as kanban_supply_area, k.check_type as kanban_check_type, COALESCE(NULLIF(k.remark, ''), did.remark) as kanban_remark, k.qty as kanban_qty,
               b.document_number as doc_no, b.vendor as kanban_vendor, b.plan_type as batch_plan_type,
               p.id as part_id, p.aql_level as part_aql_level, COALESCE(m.name, p.model) as part_model, d.drawing_2d_path, d.drawing_3d_path
        FROM inspection_sessions s
        JOIN daily_inspection_data did ON did.id = s.did_id
        LEFT JOIN kanban_items k ON k.id = COALESCE(
            s.kanban_item_id,
            (SELECT ki.id FROM kanban_items ki WHERE UPPER(ki.item_code) = UPPER(did.part_code) ORDER BY ki.id DESC LIMIT 1)
        )
        LEFT JOIN kanban_batches b ON b.id = k.batch_id
        LEFT JOIN master_parts p ON p.id = COALESCE(
            s.part_id,
            (SELECT mp.id FROM master_parts mp WHERE UPPER(mp.part_code) = UPPER(did.part_code) LIMIT 1)
        )
        LEFT JOIN master_models m ON m.id = p.model_id
        LEFT JOIN master_drawings d ON d.part_id = p.id
        WHERE s.id = :id
    ");
    $stmt->execute([':id' => $sessionId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        echo json_encode(['success' => false, 'message' => 'Sesi inspeksi tidak ditemukan']);
        exit;
    }

    // AQL Standard Extra Lookup based on Physical Scanned Qty
    $ssQty = (int)($session['use_safety_stock_qty'] ?? 0);
    $physQty = max(0, (int)($session['total_scanned_qty'] ?? 0) - $ssQty);
    
    if ($ssQty > 0 && ($session['inspection_type'] ?? 'kanban') === 'kanban') {
        $qty = ($physQty > 0) ? $physQty : 1;
    } else {
        $qty = clean_qty($session['total_scanned_qty'] ?: ($session['kanban_qty'] ?? 500));
    }

    $partAqlLvl = !empty($session['part_aql_level']) ? $session['part_aql_level'] : 'G-II';
    $stmtAql = $pdo->prepare("SELECT sample_code, sample_size, accept_number, reject_number FROM aql_standards WHERE inspection_level = :lvl AND :qty BETWEEN qty_min AND qty_max LIMIT 1");
    $stmtAql->execute([':lvl' => $partAqlLvl, ':qty' => $qty]);
    $aqlExtra = $stmtAql->fetch(PDO::FETCH_ASSOC);
    if (!$aqlExtra) {
        $stmtAqlFB = $pdo->prepare("SELECT sample_code, sample_size, accept_number, reject_number FROM aql_standards WHERE :qty BETWEEN qty_min AND qty_max LIMIT 1");
        $stmtAqlFB->execute([':qty' => $qty]);
        $aqlExtra = $stmtAqlFB->fetch(PDO::FETCH_ASSOC);
    }
    if ($aqlExtra) {
        $session['sample_code'] = $aqlExtra['sample_code'];
        $session['sample_size'] = ($ssQty > 0 && $physQty == 0) ? 0 : (int)$aqlExtra['sample_size'];
        $session['accept_number'] = (int)$aqlExtra['accept_number'];
        $session['reject_number'] = (int)$aqlExtra['reject_number'];
    }
    $session['aql_level'] = $partAqlLvl;

    // Active (Non-cancelled) NG Records
    $stmtNg = $pdo->prepare("
        SELECT n.*, dt.name as defect_name, sp.sample_number,
               COALESCE(n.ref_number, sl.ref_number) as ref_number,
               COALESCE(n.lot_number, sl.lot_number) as lot_number
        FROM inspection_ng_records n
        JOIN inspection_samples sp ON sp.id = n.inspection_sample_id
        JOIN defect_types dt ON dt.id = n.defect_type_id
        LEFT JOIN inspection_session_lots sl ON sl.id = n.session_lot_id
        WHERE sp.inspection_session_id = :sid AND (n.is_cancelled IS NULL OR n.is_cancelled = 0)
        ORDER BY n.id DESC
    ");
    $stmtNg->execute([':sid' => $sessionId]);
    $ngRecords = $stmtNg->fetchAll(PDO::FETCH_ASSOC);

    // Cancelled NG Records Audit Log
    $stmtCancNg = $pdo->prepare("
        SELECT n.*, dt.name as defect_name, sp.sample_number,
               COALESCE(n.ref_number, sl.ref_number) as ref_number,
               COALESCE(n.lot_number, sl.lot_number) as lot_number
        FROM inspection_ng_records n
        JOIN inspection_samples sp ON sp.id = n.inspection_sample_id
        JOIN defect_types dt ON dt.id = n.defect_type_id
        LEFT JOIN inspection_session_lots sl ON sl.id = n.session_lot_id
        WHERE sp.inspection_session_id = :sid AND n.is_cancelled = 1
        ORDER BY n.cancelled_at DESC, n.id DESC
    ");
    $stmtCancNg->execute([':sid' => $sessionId]);
    $cancelledNgRecords = $stmtCancNg->fetchAll(PDO::FETCH_ASSOC);

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

    // Scanned Session Lots / Labels
    $stmtLots = $pdo->prepare("SELECT * FROM inspection_session_lots WHERE inspection_session_id = :sid ORDER BY id ASC");
    $stmtLots->execute([':sid' => $sessionId]);
    $sessionLots = $stmtLots->fetchAll(PDO::FETCH_ASSOC);

    // Group and summarize scanned session lots per lot_number
    $lotSummary = [];
    foreach ($sessionLots as $sl) {
        $lNo = !empty($sl['lot_number']) ? $sl['lot_number'] : '-';
        if (!isset($lotSummary[$lNo])) {
            $lotSummary[$lNo] = [
                'lot_number'  => $lNo,
                'total_qty'   => 0,
                'label_count' => 0
            ];
        }
        $lotSummary[$lNo]['total_qty'] += (int)($sl['qty'] ?? 0);
        $lotSummary[$lNo]['label_count'] += 1;
    }
    $lotSummaryList = array_values($lotSummary);

    // NG Lots: lot yang ditandai ng_found/ng_quarantine/replaced/reinspected beserta defect summary
    $ngLots = [];
    // Auto-tag & Auto-revert lot status berdasarkan ketersediaan active NG records
    try {
        // 1. Auto-tag: tandai ng_found jika ada active NG record yang belum dibatalkan
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

        // 2. Auto-revert: kembalikan ke 'ok' jika statusnya ng_found tapi SEMUA active NG records telah dibatalkan/dihapus
        $pdo->prepare("
            UPDATE inspection_session_lots isl
            SET isl.lot_status = 'ok'
            WHERE isl.inspection_session_id = :sid
              AND isl.lot_status = 'ng_found'
              AND NOT EXISTS (
                  SELECT 1
                  FROM inspection_ng_records n
                  JOIN inspection_samples sp ON sp.id = n.inspection_sample_id
                  WHERE sp.inspection_session_id = :sid2
                    AND n.session_lot_id = isl.id
                    AND (n.is_cancelled IS NULL OR n.is_cancelled = 0)
              )
        ")->execute([':sid' => $sessionId, ':sid2' => $sessionId]);
    } catch (Exception $eTag) { /* kolom mungkin belum ada pada DB lama */ }

    $stmtNgLots = $pdo->prepare("
        SELECT isl.id, isl.lot_number, isl.ref_number, isl.qty,
               isl.lot_status, isl.replaced_by_lot_id, isl.action_noted_at
        FROM inspection_session_lots isl
        WHERE isl.inspection_session_id = :sid
          AND isl.lot_status IN ('ng_found','ng_quarantine','replaced','reinspected')
        ORDER BY isl.id ASC
    ");
    $stmtNgLots->execute([':sid' => $sessionId]);
    $ngLotsRaw = $stmtNgLots->fetchAll(PDO::FETCH_ASSOC);

    // Defect summary per lot
    $stmtDefSum = $pdo->prepare("
        SELECT n.session_lot_id, dt.name as defect_name, SUM(n.qty_ng) as total_qty_ng
        FROM inspection_ng_records n
        JOIN inspection_samples sp ON sp.id = n.inspection_sample_id
        JOIN defect_types dt ON dt.id = n.defect_type_id
        WHERE sp.inspection_session_id = :sid
          AND (n.is_cancelled IS NULL OR n.is_cancelled = 0)
        GROUP BY n.session_lot_id, n.defect_type_id
    ");
    $stmtDefSum->execute([':sid' => $sessionId]);
    $defectRows = $stmtDefSum->fetchAll(PDO::FETCH_ASSOC);
    $defectByLot = [];
    foreach ($defectRows as $dr) {
        $defectByLot[$dr['session_lot_id']][] = [
            'defect_name'  => $dr['defect_name'],
            'total_qty_ng' => (int)$dr['total_qty_ng'],
        ];
    }
    foreach ($ngLotsRaw as &$nl) {
        $nl['qty']                = (int)$nl['qty'];
        $nl['ng_defects']         = $defectByLot[$nl['id']] ?? [];
        $nl['replaced_by_lot_id'] = $nl['replaced_by_lot_id'] ? (int)$nl['replaced_by_lot_id'] : null;
    }
    unset($nl);
    $ngLots = $ngLotsRaw;

    // Re-inspection chain: sesi anak yang parent_session_id = sesi ini
    $reinspChain = [];
    $stmtChain = $pdo->prepare("
        SELECT s.id, s.status, s.is_reinspection, s.reinspection_type,
               s.samples_checked, s.sample_size, s.ng_count, s.reject_number,
               s.started_at, s.closed_at
        FROM inspection_sessions s
        WHERE s.parent_session_id = :sid
        ORDER BY s.id ASC
    ");
    $stmtChain->execute([':sid' => $sessionId]);
    $reinspChain = $stmtChain->fetchAll(PDO::FETCH_ASSOC);

    // Lot substitution log (ambil riwayat jika id ini adalah original_session ATAU reinspection_session)
    $substLog = [];
    $stmtSubst = $pdo->prepare("
        SELECT l.*, 
               ng_lot.lot_number as ng_lot_number, ng_lot.ref_number as ng_lot_ref,
               rep_lot.lot_number as rep_lot_number, rep_lot.ref_number as rep_lot_ref
        FROM lot_substitution_log l
        LEFT JOIN inspection_session_lots ng_lot ON ng_lot.id = l.ng_session_lot_id
        LEFT JOIN inspection_session_lots rep_lot ON rep_lot.id = l.replacement_session_lot_id
        WHERE l.original_session_id = :sid OR l.reinspection_session_id = :sid2
        ORDER BY l.id ASC
    ");
    $stmtSubst->execute([':sid' => $sessionId, ':sid2' => $sessionId]);
    $substLog = $stmtSubst->fetchAll(PDO::FETCH_ASSOC);

    // Resolve drawing URLs
    $drawing2d = $session['drawing_2d_path'] ? base_url($session['drawing_2d_path']) : null;
    $drawing3d = $session['drawing_3d_path'] ? base_url($session['drawing_3d_path']) : null;

    echo json_encode([
        'success' => true,
        'session' => [
            'id'                  => (int)$session['id'],
            'status'              => $session['status'],
            'inspection_type'     => $session['inspection_type'],
            'samples_checked'     => (int)$session['samples_checked'],
            'sample_size'         => (int)$session['sample_size'],
            'total_scanned_qty'   => (int)($session['total_scanned_qty'] ?? $session['sample_size']),
            'excess_qty'          => (int)($session['excess_qty'] ?? 0),
            'ng_count'            => (int)$session['ng_count'],
            'reject_number'       => (int)$session['reject_number'],
            'sample_code'         => $session['sample_code'] ?? 'J',
            'aql_level'           => $session['aql_level'] ?? 'G-II',
            'started_at'          => $session['started_at'],
            'part_code'           => $session['part_code'],
            'part_name'           => $session['part_name'],
            'lot_number'          => $session['lot_number'],
            'cavity'              => $session['cavity'],
            'did_pic'             => $session['did_pic'],
            'kanban_no'           => $session['kanban_no'],
            'customer'            => $session['customer'] ?? 'PT. Indonesia Epson Industry',
            'kanban_qty'          => $session['kanban_qty'],
            'doc_no'              => $session['doc_no'],
            'part_id'             => $session['part_id'],
            'drawing_2d'          => $drawing2d,
            'drawing_3d'          => $drawing3d,
            // Kanban detail fields (previously missing from JSON output!)
            'kanban_item_code'    => $session['kanban_item_code'],
            'kanban_item_desc'    => $session['kanban_item_desc'],
            'kanban_vendor'       => $session['kanban_vendor'],
            'kanban_eta'          => $session['kanban_eta'],
            'kanban_req_date'     => $session['kanban_req_date'],
            'kanban_str_loc'      => $session['kanban_str_loc'],
            'kanban_supply_area'  => $session['kanban_supply_area'],
            'kanban_check_type'   => $session['kanban_check_type'],
            'kanban_remark'       => $session['kanban_remark'],
            'part_model'          => $session['part_model'],
            'part_aql_level'      => $session['part_aql_level'],
            // Re-inspection chain fields & round calculation
            'is_reinspection'     => (int)($session['is_reinspection'] ?? 0),
            'reinspection_type'   => $session['reinspection_type'] ?? null,
            'parent_session_id'   => $session['parent_session_id'] ? (int)$session['parent_session_id'] : null,
            'reinspection_round'  => (function() use ($pdo, $session) {
                if (empty($session['is_reinspection'])) return 0;
                $currParent = (int)($session['parent_session_id'] ?? 0);
                $depth = 1;
                while ($currParent > 0 && $depth <= 20) {
                    $stmtP = $pdo->prepare("SELECT parent_session_id, is_reinspection FROM inspection_sessions WHERE id = :pid LIMIT 1");
                    $stmtP->execute([':pid' => $currParent]);
                    $pRow = $stmtP->fetch(PDO::FETCH_ASSOC);
                    if ($pRow && !empty($pRow['is_reinspection'])) {
                        $depth++;
                        $currParent = (int)($pRow['parent_session_id'] ?? 0);
                    } else {
                        break;
                    }
                }
                return $depth;
            })(),
        ],
        'session_lots'           => $sessionLots,
        'lot_summary'            => $lotSummaryList,
        'ng_records'             => $ngRecords,
        'cancelled_ng_records'   => $cancelledNgRecords,
        'previous_sessions'      => $previousSessions,
        'defect_types'           => $defectTypes,
        // Re-inspection & substitution data
        'ng_lots'                => $ngLots,
        'reinspection_chain'     => $reinspChain,
        'substitution_log'       => $substLog,
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
}
