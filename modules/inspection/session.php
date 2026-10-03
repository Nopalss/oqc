<?php
/**
 * Redesigned Unified OQC Inspection Workbench (FR-3 & FR-6)
 * 100% Full-Screen, Zero-Scroll, All-in-One 3-Stage Workbench Matching Client Mockup
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';
session_write_close();

$sessionId = (int)($_GET['id'] ?? $_GET['session_id'] ?? 0);
$autoScanNew = isset($_GET['scan_new']) && $_GET['scan_new'] == 1;

$pdo = getDB();

if (isset($_GET['action']) && $_GET['action'] === 'get_planning_items' && $pdo) {
    header('Content-Type: application/json');
    $items = [];
    $selectedId = (int)($_GET['selected_id'] ?? $_GET['kanban_id'] ?? 0);
    try {
        $stmtPlan = $pdo->prepare("
            SELECT k.*, b.document_number, b.vendor, b.plan_type as batch_plan_type,
                   (SELECT COALESCE(SUM(s.total_scanned_qty), 0)
                    FROM inspection_sessions s
                    LEFT JOIN daily_inspection_data d ON d.id = s.did_id
                    LEFT JOIN kanban_items ki ON ki.id = s.kanban_item_id
                    WHERE s.inspection_type = 'safety_stock'
                      AND s.status = 'passed'
                      AND (s.auto_fulfilled_by_session_id IS NULL OR s.auto_fulfilled_by_session_id = 0)
                      AND (d.part_code = k.item_code OR ki.item_code = k.item_code)) AS avail_ss_qty,
                   (SELECT COALESCE(SUM(
                       CASE 
                           WHEN EXISTS (SELECT 1 FROM inspection_session_lots isl_c WHERE isl_c.inspection_session_id = s.id) THEN
                               COALESCE((SELECT SUM(CASE WHEN isl2.lot_result = 'passed' AND (isl2.lot_status IS NULL OR isl2.lot_status != 'replaced') THEN isl2.qty ELSE 0 END)
                                         FROM inspection_session_lots isl2 WHERE isl2.inspection_session_id = s.id), 0) - COALESCE(s.excess_qty, 0)
                           WHEN s.status = 'passed' THEN
                               (s.total_scanned_qty - COALESCE(s.excess_qty, 0))
                           ELSE 0
                       END
                   ), 0)
                    FROM inspection_sessions s
                    WHERE s.kanban_item_id = k.id) AS total_passed_qty
            FROM kanban_items k
            JOIN kanban_batches b ON b.id = k.batch_id
            WHERE (
                k.status IN ('uninspected', 'partial', 'rejected')
                OR (:sel_id1 > 0 AND k.id = :sel_id2)
            )
              AND k.status != 'completed'
              AND (
                SELECT COALESCE(SUM(
                    CASE 
                        WHEN EXISTS (SELECT 1 FROM inspection_session_lots isl_c WHERE isl_c.inspection_session_id = s.id) THEN
                            COALESCE((SELECT SUM(CASE WHEN isl2.lot_result = 'passed' AND (isl2.lot_status IS NULL OR isl2.lot_status != 'replaced') THEN isl2.qty ELSE 0 END)
                                      FROM inspection_session_lots isl2 WHERE isl2.inspection_session_id = s.id), 0) - COALESCE(s.excess_qty, 0)
                        WHEN s.status = 'passed' THEN
                            (s.total_scanned_qty - COALESCE(s.excess_qty, 0))
                        ELSE 0
                    END
                ), 0)
                FROM inspection_sessions s
                WHERE s.kanban_item_id = k.id
            ) < k.qty
            ORDER BY 
                CASE WHEN :sel_id3 > 0 AND k.id = :sel_id4 THEN 0 ELSE 1 END ASC,
                COALESCE(k.eta, k.req_date, '9999-12-31') ASC, 
                k.id DESC
            LIMIT 100
        ");
        $stmtPlan->execute([
            ':sel_id1' => $selectedId,
            ':sel_id2' => $selectedId,
            ':sel_id3' => $selectedId,
            ':sel_id4' => $selectedId,
        ]);
        $items = $stmtPlan->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $ePlan) {}
    echo json_encode(['success' => true, 'items' => $items]);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'search_parts' && $pdo) {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    $parts = [];
    try {
        if ($q !== '') {
            $stmt = $pdo->prepare("SELECT part_code, part_name FROM master_parts WHERE part_code LIKE :q1 OR part_name LIKE :q2 ORDER BY part_code ASC LIMIT 25");
            $stmt->execute([':q1' => '%' . $q . '%', ':q2' => '%' . $q . '%']);
            $parts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $pdo->query("SELECT part_code, part_name FROM master_parts ORDER BY part_code ASC LIMIT 25");
            $parts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $eParts) {}
    echo json_encode(['success' => true, 'parts' => $parts]);
    exit;
}

$session = null;
$defectTypes = [];
$ngRecords = [];
$master_parts = [];

if ($pdo) {
    // Fetch Defect Types
    $stmtDef = $pdo->query("SELECT * FROM defect_types ORDER BY name ASC");
    $defectTypes = $stmtDef->fetchAll(PDO::FETCH_ASSOC);

    // Fetch initial sample of Master Parts for scan datalist (fast first paint; full search via AJAX)
    $stmtP = $pdo->query("SELECT part_code, part_name FROM master_parts ORDER BY part_code ASC LIMIT 30");
    $master_parts = $stmtP->fetchAll(PDO::FETCH_ASSOC);

    // Fetch Active Users for Chief Selection Modal
    $chiefUsersList = [];
    try {
        $stmtUsers = $pdo->query("SELECT id, name, username, role FROM users WHERE status = 'active' ORDER BY name ASC");
        $chiefUsersList = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $eUsers) {}

    if ($sessionId > 0) {
        try {
            $stmt = $pdo->prepare("
                SELECT s.*, 
                       did.part_code, did.part_name, did.lot_number, did.cavity, did.pic as did_pic,
                       k.kanban_no, k.item_code as kanban_item_code, k.item_description as kanban_item_desc, k.customer, k.req_date as kanban_req_date, k.eta as kanban_eta, k.str_loc as kanban_str_loc, k.supply_area as kanban_supply_area, k.check_type as kanban_check_type, COALESCE(NULLIF(k.remark, ''), did.remark) as kanban_remark, k.qty as kanban_qty,
                       b.document_number as doc_no, b.vendor as kanban_vendor, b.plan_type as batch_plan_type,
                       p.id as part_id, p.aql_level as part_aql_level, COALESCE(m.name, p.model) as part_model, d.drawing_2d_path, d.drawing_3d_path,
                       u_chief.name as chief_name
                FROM inspection_sessions s
                JOIN daily_inspection_data did ON did.id = s.did_id
                LEFT JOIN kanban_items k ON k.id = s.kanban_item_id
                LEFT JOIN kanban_batches b ON b.id = k.batch_id
                LEFT JOIN master_parts p ON p.id = COALESCE(
                    s.part_id,
                    (SELECT mp.id FROM master_parts mp WHERE mp.part_code = did.part_code LIMIT 1)
                )
                LEFT JOIN master_models m ON m.id = p.model_id
                LEFT JOIN master_drawings d ON d.part_id = p.id
                LEFT JOIN users u_chief ON u_chief.id = s.chief_approved_by
                WHERE s.id = :id
            ");
            $stmt->execute([':id' => $sessionId]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($session) {
                // Resolve drawings from filesystem convention uploads/drawings/[Model]/[PartCode]_[PartName]/
                $fsDrawings = get_part_drawing_assets($session['part_code'] ?? '', $session['part_model'] ?? '');
                if (!empty($fsDrawings['drawing_2d_path'])) {
                    $session['drawing_2d_path'] = $fsDrawings['drawing_2d_path'];
                }
                if (!empty($fsDrawings['drawing_3d_path'])) {
                    $session['drawing_3d_path'] = $fsDrawings['drawing_3d_path'];
                }

                // Accumulated Kanban Qty across multiple sessions for the same kanban_item_id
                $prevKanbanQty = 0;
                $prevKanbanSessions = [];
                if (!empty($session['kanban_item_id']) && ($session['inspection_type'] ?? 'kanban') === 'kanban') {
                    $stmtPrevKb = $pdo->prepare("
                        SELECT s.id, s.total_scanned_qty, s.excess_qty, s.status, s.started_at, s.closed_at,
                               COALESCE(u.name, did.pic, 'QC Inspector') as inspector_name
                        FROM inspection_sessions s
                        LEFT JOIN users u ON u.id = s.inspector_id
                        LEFT JOIN daily_inspection_data did ON did.id = s.did_id
                        WHERE s.kanban_item_id = :kid
                          AND s.id != :curr_id
                          AND s.status IN ('passed', 'rejected')
                        ORDER BY s.id ASC
                    ");
                    $stmtPrevKb->execute([':kid' => $session['kanban_item_id'], ':curr_id' => $sessionId]);
                    $rawPrevSessions = $stmtPrevKb->fetchAll(PDO::FETCH_ASSOC);

                    if (!empty($rawPrevSessions)) {
                        $pSessIds = array_column($rawPrevSessions, 'id');
                        $inPSess = implode(',', array_map('intval', $pSessIds));
                        $stmtPLots = $pdo->query("SELECT inspection_session_id, qty, lot_result, lot_status FROM inspection_session_lots WHERE inspection_session_id IN ($inPSess)");
                        $lotsByPSess = [];
                        while ($plr = $stmtPLots->fetch(PDO::FETCH_ASSOC)) {
                            $lotsByPSess[(int)$plr['inspection_session_id']][] = $plr;
                        }

                        foreach ($rawPrevSessions as $pks) {
                            $pksId = (int)$pks['id'];
                            $pLots = $lotsByPSess[$pksId] ?? [];
                            $sessPassQty = 0;

                            if (!empty($pLots)) {
                                foreach ($pLots as $pl) {
                                    if (($pl['lot_result'] ?? '') === 'passed' && ($pl['lot_status'] ?? '') !== 'replaced') {
                                        $sessPassQty += (int)($pl['qty'] ?? 0);
                                    }
                                }
                                $sessPassQty = max(0, $sessPassQty - (int)($pks['excess_qty'] ?? 0));
                            } elseif ($pks['status'] === 'passed') {
                                $sessPassQty = max(0, (int)$pks['total_scanned_qty'] - (int)($pks['excess_qty'] ?? 0));
                            }

                            if ($sessPassQty > 0) {
                                $pks['passed_qty'] = $sessPassQty;
                                $pks['status_text'] = ($pks['status'] === 'passed') ? 'Lulus' : 'Parsial';
                                $prevKanbanSessions[] = $pks;
                                $prevKanbanQty += $sessPassQty;
                            }
                        }
                    }
                }
                $session['prev_kanban_qty'] = $prevKanbanQty;
                $currEffQty = max(0, (int)($session['total_scanned_qty'] ?? 0) - (int)($session['excess_qty'] ?? 0));
                $session['accumulated_kanban_qty'] = $currEffQty + $prevKanbanQty;
                $session['prev_kanban_sessions'] = $prevKanbanSessions;

                $ssQty = (int)($session['use_safety_stock_qty'] ?? 0);
                $physQty = max(0, (int)$session['total_scanned_qty'] - $ssQty);
                
                // AQL sample size is calculated ONLY from new physical scanned Qty when Safety Stock is used
                if ($ssQty > 0 && ($session['inspection_type'] ?? 'kanban') === 'kanban') {
                    $qty = ($physQty > 0) ? $physQty : 1;
                } else {
                    $qty = clean_qty($session['total_scanned_qty'] ?: ($session['kanban_qty'] ?? 0));
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

                $stmtNg = $pdo->prepare("
                    SELECT n.*, dt.name as defect_name, COALESCE(sp.sample_number, 1) as sample_number,
                           COALESCE(n.ref_number, sl.ref_number) as ref_number,
                           COALESCE(n.lot_number, sl.lot_number) as lot_number
                    FROM inspection_ng_records n
                    LEFT JOIN inspection_samples sp ON sp.id = n.inspection_sample_id
                    JOIN defect_types dt ON dt.id = n.defect_type_id
                    LEFT JOIN inspection_session_lots sl ON sl.id = n.session_lot_id
                    WHERE (n.inspection_session_id = :sid OR sp.inspection_session_id = :sid2) 
                      AND (n.is_cancelled IS NULL OR n.is_cancelled = 0)
                    ORDER BY n.id DESC
                ");
                $stmtNg->execute([':sid' => $sessionId, ':sid2' => $sessionId]);
                $ngRecords = $stmtNg->fetchAll(PDO::FETCH_ASSOC);

                // Fetch scanned session lots for initial PHP rendering
                $stmtLots = $pdo->prepare("SELECT * FROM inspection_session_lots WHERE inspection_session_id = :sid ORDER BY id ASC");
                $stmtLots->execute([':sid' => $sessionId]);
                $sessionLots = $stmtLots->fetchAll(PDO::FETCH_ASSOC);

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

                // Fetch previous inspection sessions specifically focused on this Kanban Item / Safety Stock
                if (!empty($session['part_code'])) {
                    $kanbanItemId = (int)($session['kanban_item_id'] ?? 0);
                    $parentSessId = (int)($session['parent_session_id'] ?? 0);

                    if ($kanbanItemId > 0) {
                        // Focused ONLY on this specific Kanban Item (and its re-inspection lineage)
                        $sqlPrev = "
                            SELECT s.id, s.started_at, s.status, s.samples_checked, s.sample_size, s.ng_count, s.reject_number,
                                   s.inspection_type, s.total_scanned_qty, s.parent_session_id, s.reinspection_notes,
                                   did.lot_number, did.cavity, did.pic as did_pic,
                                   k.kanban_no, k.customer,
                                   COALESCE(NULLIF(mp.part_name, ''), did.part_name) as display_part_name,
                                   u.name as inspector_name
                            FROM inspection_sessions s
                            JOIN daily_inspection_data did ON did.id = s.did_id
                            LEFT JOIN kanban_items k ON k.id = s.kanban_item_id
                            LEFT JOIN master_parts mp ON (mp.id = s.part_id OR mp.part_code = did.part_code)
                            LEFT JOIN users u ON u.id = s.inspector_id
                            WHERE (s.kanban_item_id = :kb_id OR s.id = :parent_id OR s.parent_session_id = :curr_id1)
                              AND s.id != :curr_id2
                              AND NOT EXISTS (
                                  SELECT 1 FROM inspection_session_lots isl_chk 
                                  WHERE isl_chk.inspection_session_id = s.id 
                                    AND isl_chk.remarks LIKE '%Sisa Split Safety Stock%'
                              )
                            ORDER BY s.id DESC
                            LIMIT 10
                        ";
                        $paramsPrev = [
                            ':kb_id'     => $kanbanItemId,
                            ':parent_id' => $parentSessId,
                            ':curr_id1'  => $session['id'],
                            ':curr_id2'  => $session['id']
                        ];
                    } else {
                        // Focused ONLY on Safety Stock sessions for this part code
                        $sqlPrev = "
                            SELECT s.id, s.started_at, s.status, s.samples_checked, s.sample_size, s.ng_count, s.reject_number,
                                   s.inspection_type, s.total_scanned_qty, s.parent_session_id, s.reinspection_notes,
                                   did.lot_number, did.cavity, did.pic as did_pic,
                                   k.kanban_no, k.customer,
                                   COALESCE(NULLIF(mp.part_name, ''), did.part_name) as display_part_name,
                                   u.name as inspector_name
                            FROM inspection_sessions s
                            JOIN daily_inspection_data did ON did.id = s.did_id
                            LEFT JOIN kanban_items k ON k.id = s.kanban_item_id
                            LEFT JOIN master_parts mp ON (mp.id = s.part_id OR mp.part_code = did.part_code)
                            LEFT JOIN users u ON u.id = s.inspector_id
                            WHERE s.inspection_type = 'safety_stock'
                              AND did.part_code = :pcode
                              AND s.id != :curr_id
                              AND NOT EXISTS (
                                  SELECT 1 FROM inspection_session_lots isl_chk 
                                  WHERE isl_chk.inspection_session_id = s.id 
                                    AND isl_chk.remarks LIKE '%Sisa Split Safety Stock%'
                              )
                            ORDER BY s.id DESC
                            LIMIT 10
                        ";
                        $paramsPrev = [
                            ':pcode'   => $session['part_code'],
                            ':curr_id' => $session['id']
                        ];
                    }

                    $stmtPrev = $pdo->prepare($sqlPrev);
                    $stmtPrev->execute($paramsPrev);
                    $previousSessions = $stmtPrev->fetchAll(PDO::FETCH_ASSOC);

                    // Fetch defect breakdown in single batch query (prevents N+1 query storm)
                    if (!empty($previousSessions)) {
                        $prevIds = array_map(function($ps) { return (int)$ps['id']; }, $previousSessions);
                        $inPrevIds = implode(',', $prevIds);
                        $stmtDefects = $pdo->query("
                            SELECT n.inspection_session_id, dt.name as defect_name, SUM(n.qty_ng) as total_qty_ng
                            FROM inspection_ng_records n
                            JOIN defect_types dt ON dt.id = n.defect_type_id
                            WHERE n.inspection_session_id IN ($inPrevIds) AND (n.is_cancelled IS NULL OR n.is_cancelled = 0)
                            GROUP BY n.inspection_session_id, n.defect_type_id, dt.name
                            ORDER BY total_qty_ng DESC
                        ");
                        $defectsBySession = [];
                        if ($stmtDefects) {
                            while ($dRow = $stmtDefects->fetch(PDO::FETCH_ASSOC)) {
                                $psId = (int)$dRow['inspection_session_id'];
                                $defectsBySession[$psId][] = [
                                    'defect_name' => $dRow['defect_name'],
                                    'total_qty_ng' => $dRow['total_qty_ng']
                                ];
                            }
                        }
                        foreach ($previousSessions as &$ps) {
                            $ps['defects'] = $defectsBySession[(int)$ps['id']] ?? [];
                        }
                        unset($ps);
                    }
                }
            }
        } catch (PDOException $e) {
            $session = null;
        }
    }
}
$previousSessions = $previousSessions ?? [];

// Spectator / Read-Only Mode Detection
$isReadOnlyView = (isset($_GET['view_mode']) && $_GET['view_mode'] === 'readonly') || (isset($_GET['spectator']) && $_GET['spectator'] == 1);
$sessionInspectorName = 'QC Inspector';
if (!empty($session['inspector_id'])) {
    try {
        $stmtInsp = $pdo->prepare("SELECT name FROM users WHERE id = :uid LIMIT 1");
        $stmtInsp->execute([':uid' => $session['inspector_id']]);
        $sessionInspectorName = $stmtInsp->fetchColumn() ?: 'QC Inspector';
    } catch (Exception $eInsp) {}
}

// If no session specified, auto open scan modal
$showScanOverlay = (!$session || $autoScanNew);

$pageTitle = $session ? ("Inspeksi Barang — " . htmlspecialchars($session['part_code'])) : "Workbench Inspeksi OQC";

require_once __DIR__ . '/../../layouts/header.php';
?>

<style>
/* Guarantee SweetAlert2 alerts are ALWAYS visible on top of all custom modal overlays */
.swal2-container {
    z-index: 999999 !important;
}

<?php if ($isReadOnlyView): ?>
/* Non-Disruptive Spectator Mode Styles */
.spectator-locked {
    pointer-events: none !important;
    opacity: 0.45 !important;
    filter: grayscale(50%) !important;
    cursor: not-allowed !important;
}
.spectator-hidden {
    display: none !important;
}
<?php endif; ?>

/* Sleek, Modern, Minimalist Custom Scrollbar for Workbench */
::-webkit-scrollbar {
    width: 6px;
    height: 6px;
}
::-webkit-scrollbar-track {
    background: transparent;
    border-radius: 9999px;
}
::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 9999px;
    transition: background 0.2s ease;
}
::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}
::-webkit-scrollbar-thumb:active {
    background: #64748b;
}
* {
    scrollbar-width: thin;
    scrollbar-color: #cbd5e1 transparent;
}
.custom-scrollbar::-webkit-scrollbar {
    width: 6px;
    height: 6px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: rgba(241, 245, 249, 0.7);
    border-radius: 9999px;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 9999px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}
.slim-scrollbar::-webkit-scrollbar {
    width: 4px;
    height: 4px;
}
.slim-scrollbar::-webkit-scrollbar-track {
    background: transparent;
}
.slim-scrollbar::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 9999px;
}
.slim-scrollbar::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}
</style>

<!-- 100% Full-Screen Outer Container (Strict 100vh, Zero Window Scrollbar) -->
<div style="width: 100vw; height: 100vh; overflow: hidden; display: flex; flex-direction: column; background-color: #f1f5f9;" class="text-slate-800 antialiased font-sans">
    
    <?php if ($isReadOnlyView && $session): ?>
        <!-- Non-Disruptive Spectator Mode Banner -->
        <div style="flex-shrink: 0;" class="bg-amber-600 text-white text-xs font-bold px-4 py-2 flex items-center justify-between shadow-sm z-30">
            <div class="flex items-center gap-2">
                <svg class="w-4 h-4 text-amber-200 animate-pulse flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                <span>MODE PANTAU (READ-ONLY): Sesi ini sedang aktif dikerjakan oleh <u><?= htmlspecialchars($sessionInspectorName) ?></u>. Kontrol input dikunci agar tidak mengganggu proses inspeksi yang sedang berlangsung.</span>
            </div>
            <a href="<?= base_url('modules/inspection/index.php') ?>" class="px-2.5 py-0.5 bg-black/25 hover:bg-black/45 text-white rounded text-[11px] font-extrabold transition-all">
                Kembali ke Jadwal
            </a>
        </div>
    <?php endif; ?>

    <!-- Top Bar Navigation Header -->
    <header style="flex-shrink: 0; min-height: 52px;" class="bg-white border-b border-slate-200/80 px-4 py-2 flex items-center justify-between z-20 shadow-xs">
        <div class="flex items-center space-x-3 min-w-0">
            <a href="<?= base_url('modules/inspection/index.php') ?>" id="btn-header-back" class="btn-secondary py-1.5 px-3.5 text-xs font-bold flex items-center flex-shrink-0 text-slate-700 hover:bg-slate-100 shadow-2xs" title="Kembali ke Riwayat Inspeksi">
                &larr; Kembali
            </a>

            <div class="min-w-0">
                <div class="flex items-center space-x-2 text-[10px] font-bold text-slate-400 uppercase tracking-wider">
                    <span>OPERASIONAL</span>
                    <span>/</span>
                    <span class="text-blue-600">SCAN INSPEKSI</span>
                </div>
                <h1 class="text-sm font-extrabold text-slate-900 leading-tight truncate flex items-center" id="header-session-title">
                    <span>Inspeksi Barang</span>
                    <span id="header-reinspection-wrapper" class="inline-flex items-center">
                    <?php if ($session && !empty($session['is_reinspection'])): ?>
                        <?php
                        $rTypeLabelsPHP = [
                            'rescan_restart'   => 'Scan Ulang (Awal)',
                            'rescan_continue'  => 'Scan Ulang (Lanjut)',
                            'rescan_same_lot'  => 'Scan Ulang',
                            'replace_ng_only'  => 'Ganti Lot NG',
                            'replace_all_lots' => 'Ganti Semua Lot'
                        ];
                        $rTypeLblPHP = $rTypeLabelsPHP[$session['reinspection_type'] ?? ''] ?? 'Re-Inspeksi';
                        $roundNumPHP = $session['reinspection_round'] ?? 1;
                        ?>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[11px] font-extrabold bg-amber-100 text-amber-800 border border-amber-300 shadow-2xs ml-2 flex-shrink-0">
                            ⚡ RE-INSPEKSI #<?= htmlspecialchars($roundNumPHP) ?> (<?= htmlspecialchars($rTypeLblPHP) ?>)
                        </span>
                        <?php if (!empty($session['parent_session_id'])): ?>
                        <a href="<?= base_url('modules/inspection/session.php?id=' . $session['parent_session_id']) ?>" class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-semibold bg-slate-100 text-slate-600 border border-slate-300 ml-2 flex-shrink-0 hover:bg-slate-200 transition-colors">
                            ← Sesi Original #<?= htmlspecialchars($session['parent_session_id']) ?>
                        </a>
                        <?php endif; ?>
                    <?php endif; ?>
                    </span>
                </h1>
            </div>
        </div>

        <!-- Top Right Inspector Badge & Controls -->
        <div class="flex items-center space-x-2.5 flex-shrink-0">
            
            <button type="button" onclick="toggleNativeFullscreen()" class="btn-secondary py-1.5 px-3 text-xs font-bold flex items-center shadow-xs text-slate-700 hover:bg-slate-100" title="Aktifkan Mode Full Screen Layar Penuh (Sembunyikan Tab & Address Bar Chrome)">
                <svg class="w-3.5 h-3.5 mr-1.5 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 4l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"></path>
                </svg>
                <span>Full Screen <kbd class="hidden sm:inline-block ml-1 px-1 py-0.5 bg-slate-200 text-[9px] rounded text-slate-700 font-mono">F11</kbd></span>
            </button>

            <?php if ($session): ?>
                <button type="button" onclick="openPrevHistoryModal()" class="btn-secondary py-1.5 px-3 text-xs font-bold flex items-center shadow-xs text-slate-700 hover:bg-slate-100" title="Lihat Riwayat Sesi Inspeksi Terdahulu untuk Part Code Ini">
                    <svg class="w-3.5 h-3.5 mr-1.5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <span>Riwayat Inspeksi (<?= count($previousSessions) ?>)</span>
                </button>
                
                <button type="button" id="btn-top-substitution-log" onclick="openSubstitutionLogModal()" class="hidden btn-secondary py-1.5 px-3 text-xs font-bold flex items-center shadow-xs text-emerald-800 bg-emerald-50 hover:bg-emerald-100 border border-emerald-300 transition-all cursor-pointer" title="Lihat Rincian Lineage Penggantian Lot & Ref No">
                    <svg class="w-3.5 h-3.5 mr-1.5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                    </svg>
                    <span>Substitusi Lot (<span id="top-subst-log-count">0</span>)</span>
                </button>
            <?php endif; ?>

            <button type="button" onclick="openScanModal()" class="btn-primary py-1.5 px-3 text-xs font-bold flex items-center shadow-xs">
                <svg class="w-3.5 h-3.5 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                </svg>
                <span>Scan Lot Berikutnya <kbd class="hidden sm:inline-block ml-1 px-1 py-0.5 bg-blue-700 text-[9px] rounded">F2</kbd></span>
            </button>

            <!-- Device Line Indicator in Session Header -->
            <button type="button" onclick="promptDeviceLine(true)" class="flex items-center space-x-1.5 bg-indigo-50 hover:bg-indigo-100 border border-indigo-200 text-indigo-800 rounded-full px-2.5 py-1 text-xs font-bold transition-all shadow-2xs cursor-pointer" title="Klik untuk mengubah Line kerja perangkat ini">
                <svg class="w-3.5 h-3.5 text-indigo-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                </svg>
                <span class="text-[11px] font-extrabold text-indigo-900" id="header-device-line-name">-</span>
                <span class="text-[9px] text-indigo-500 font-normal underline">(ganti)</span>
            </button>

            <!-- QC Inspector User Badge -->
            <div class="flex items-center space-x-2 pl-2 border-l border-slate-200">
                <div class="w-7 h-7 rounded-full bg-blue-600 text-white font-bold text-xs flex items-center justify-center shadow-xs">
                    RA
                </div>
                <div class="hidden sm:block text-right text-xs leading-tight">
                    <span class="font-bold text-slate-800 block">Rangga Aditya</span>
                    <span class="text-[10px] text-slate-500 font-medium">QC Inspector</span>
                </div>
            </div>

        </div>
    </header>

    <!-- Main Workspace Area: 2 Main Columns -->
    <div style="flex: 1; min-height: 0; overflow: hidden; display: flex; padding: 12px; gap: 12px;">
        
        <!-- Left Main Panel: Interactive Drawing Viewer 2D PDF & 3D STP (Flex-1) -->
        <div id="drawing-viewer-panel" style="flex: 1; min-width: 0; height: 100%; display: flex; flex-direction: column; overflow: hidden;" class="bg-white rounded-2xl border border-slate-200/80 shadow-xs">
            
            <!-- Drawing Header & Toolbar -->
            <div class="px-3 py-1.5 bg-slate-50/80 border-b border-slate-200 flex items-center justify-between flex-shrink-0">
                
                <!-- Tab Switcher -->
                <div class="flex items-center space-x-1.5 bg-slate-200/70 p-1 rounded-xl">
                    <button type="button" id="tab-btn-2d" onclick="switchDrawingTab('2d')" class="px-3 py-1 rounded-lg text-xs font-bold transition-all bg-blue-600 text-white shadow-xs flex items-center">
                        <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                        </svg>
                        Gambar 2D (PDF)
                    </button>
                    <button type="button" id="tab-btn-3d" onclick="switchDrawingTab('3d')" class="px-3 py-1 rounded-lg text-xs font-bold transition-all text-slate-700 hover:text-slate-900 hover:bg-slate-300/50 flex items-center">
                        <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                        </svg>
                        Tampilan 3D
                    </button>
                </div>

                <!-- Zoom & Viewport Controls Toolbar -->
                <div class="flex items-center space-x-1 text-slate-600">
                    <button type="button" onclick="adjustZoom(0.1)" class="p-1 hover:bg-slate-200 rounded-lg text-slate-600 hover:text-slate-900 transition-colors" title="Zoom In">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v6m3-3H7"></path></svg>
                    </button>
                    <button type="button" onclick="adjustZoom(-0.1)" class="p-1 hover:bg-slate-200 rounded-lg text-slate-600 hover:text-slate-900 transition-colors" title="Zoom Out">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM13 10H7"></path></svg>
                    </button>
                    <button type="button" id="btn-fullscreen-toggle" onclick="toggleDrawingFullscreen()" class="p-1 hover:bg-slate-200 rounded-lg text-slate-600 hover:text-slate-900 transition-colors" title="Layar Penuh (Fullscreen)">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 4l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"></path></svg>
                    </button>
                    <button type="button" onclick="resetZoom()" class="p-1 hover:bg-slate-200 rounded-lg text-slate-600 hover:text-slate-900 transition-colors" title="Reset Zoom">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                    </button>
                </div>

            </div>

            <!-- Viewport Container for 2D & 3D Drawings -->
            <div style="flex: 1; min-height: 0; position: relative; overflow: hidden; background-color: #020617;">
                
                <!-- 2D PDF Viewport -->
                <div id="viewport-2d" class="w-full h-full flex items-center justify-center overflow-auto p-2">
                    <iframe id="pdf-frame" src="<?= ($session && !empty($session['drawing_2d_path'])) ? (base_url($session['drawing_2d_path']) . '#toolbar=0') : '' ?>" class="w-full h-full border-0 rounded-lg transition-transform duration-200" style="display: <?= ($session && !empty($session['drawing_2d_path'])) ? 'block' : 'none' ?>;"></iframe>
                    <div id="drawing-placeholder-box" class="w-full h-full bg-[#f8fafc] border border-dashed border-slate-300 rounded-xl p-4 flex flex-col items-center justify-center text-center relative" style="display: <?= ($session && !empty($session['drawing_2d_path'])) ? 'none' : 'flex' ?>;">
                        <svg class="w-full h-full max-h-[360px] text-slate-300" viewBox="0 0 600 350" fill="none" stroke="currentColor">
                            <rect x="50" y="40" width="500" height="270" rx="4" stroke="#cbd5e1" stroke-width="2" stroke-dasharray="4 4" />
                            <rect x="150" y="80" width="300" height="180" fill="#f1f5f9" stroke="#94a3b8" stroke-width="2" />
                            <circle cx="200" cy="130" r="20" stroke="#64748b" stroke-width="2" />
                            <circle cx="200" cy="210" r="20" stroke="#64748b" stroke-width="2" />
                            <circle cx="400" cy="130" r="15" stroke="#64748b" stroke-width="2" />
                            <line x1="150" y1="80" x2="450" y2="260" stroke="#cbd5e1" stroke-width="1" />
                            <text x="300" y="170" font-family="monospace" font-size="14" font-weight="bold" fill="#334155" text-anchor="middle" id="placeholder-part-code-text"><?= $session ? htmlspecialchars($session['part_code']) : 'TECHNICAL DRAWING' ?></text>
                        </svg>
                        <p class="text-xs font-bold text-slate-500 mt-2">Gambar Teknis PDF belum diunggah untuk Part Code ini.</p>
                    </div>
                </div>

                <!-- 3D STP Model Viewport -->
                <div id="viewport-3d" class="hidden w-full h-full bg-slate-900 relative">
                    <div id="cad-3d-viewport" class="w-full h-full" data-stp-url="<?= ($session && !empty($session['drawing_3d_path'])) ? htmlspecialchars(base_url($session['drawing_3d_path'])) : '' ?>" style="display: <?= ($session && !empty($session['drawing_3d_path'])) ? 'block' : 'none' ?>;"></div>
                    <div id="cad-3d-placeholder-box" class="w-full h-full flex flex-col items-center justify-center text-white p-6 text-center" style="display: <?= ($session && !empty($session['drawing_3d_path'])) ? 'none' : 'flex' ?>;">
                        <svg class="w-12 h-12 text-slate-600 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                        </svg>
                        <p class="text-xs font-bold text-slate-400">File 3D (.STP) tidak tersedia untuk Part ini.</p>
                    </div>
                </div>

            </div>

        </div>

        <!-- Right Control Panel: Compact Zero-Scroll Inspection Panel (~420px Fixed) -->
        <div id="right-control-panel" style="width: 420px; flex-shrink: 0; height: 100%; display: flex; flex-direction: column; overflow-y: auto; box-sizing: border-box; padding-bottom: 30px;" class="pr-1 custom-scrollbar">
            
            <!-- Card 1: Top Part & Lot Metadata Summary Card -->
            <div style="background-color: #ffffff; border: 1px solid #cbd5e1; border-radius: 16px; padding: 12px; margin-bottom: 10px; box-shadow: 0 1px 2px 0 rgba(0,0,0,0.05);" class="space-y-2 flex-shrink-0 text-xs">
                <div class="flex items-center justify-between border-b border-slate-100 pb-1.5">
                    <div class="flex items-center space-x-1.5 min-w-0">
                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider flex-shrink-0">CUST:</span>
                        <span class="font-extrabold text-blue-900 text-[10px] flex items-center truncate max-w-[220px]" id="card-customer-name">
                            <?= $session ? htmlspecialchars($session['customer'] ?? 'PT. Indonesia Epson Industry') : '-' ?>
                        </span>
                    </div>
                    <div class="flex items-center space-x-1.5">
                        <button type="button" onclick="openKanbanDetailModal()" class="px-2 py-0.5 bg-blue-50 hover:bg-blue-100 text-blue-700 font-bold text-[10px] rounded-md border border-blue-200 shadow-2xs inline-flex items-center gap-1 transition-all flex-shrink-0 cursor-pointer" title="Lihat Rincian Detail Planning Kanban">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                            <span>Detail</span> 
                        </button>
                        <button type="button" id="btn-card-substitution-log" onclick="openSubstitutionLogModal()" class="hidden px-2 py-0.5 bg-emerald-50 hover:bg-emerald-100 text-emerald-800 font-bold text-[10px] rounded-md border border-emerald-300 shadow-2xs inline-flex items-center gap-1 transition-all flex-shrink-0 cursor-pointer" title="Lihat Rincian Lineage Penggantian Lot & Ref No">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                            <span>Substitusi (<span id="card-subst-log-count">0</span>)</span>
                        </button>
                    </div>
                </div>
                <div class="grid grid-cols-3 gap-1.5">
                    <div>
                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block mb-0.5">TIPE INSPEKSI</span>
                        <span id="card-type-badge">
                        <?php if ($session && ($session['inspection_type'] ?? 'kanban') === 'safety_stock'): ?>
                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-extrabold bg-purple-100 text-purple-800 border border-purple-200 truncate">
                                📦 SAFETY STOCK
                            </span>
                        <?php else: ?>
                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-extrabold bg-blue-100 text-blue-800 border border-blue-200 truncate">
                                🚚 KANBAN
                            </span>
                        <?php endif; ?>
                        </span>
                    </div>
                    <div>
                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block mb-0.5">PART CODE</span>
                        <span class="font-mono font-extrabold text-slate-900 text-xs truncate block" id="card-part-code"><?= $session ? htmlspecialchars($session['part_code']) : '-' ?></span>
                    </div>
                    <div>
                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block mb-0.5">TOTAL QTY</span>
                        <?php 
                        $totalScanned = $session ? (int)$session['total_scanned_qty'] : 0;
                        $prevKbQty = $session ? (int)($session['prev_kanban_qty'] ?? 0) : 0;
                        $accumulatedQty = $totalScanned + $prevKbQty;
                        $kanbanTarget = $session ? (int)($session['kanban_qty'] ?? 0) : 0;
                        $usedSs = $session ? (int)($session['use_safety_stock_qty'] ?? 0) : 0;
                        $excessQty = $session ? (int)($session['excess_qty'] ?? 0) : 0;
                        $isKanbanMode = !$session || ($session['inspection_type'] ?? 'kanban') === 'kanban';

                        if ($isKanbanMode) {
                            $effectiveQty = ($prevKbQty > 0) ? $accumulatedQty : $totalScanned;
                            $targetDisplay = ($kanbanTarget > 0) ? $kanbanTarget : max(500, $effectiveQty);
                            $mainQtyText = number_format($effectiveQty) . ' / ' . number_format($targetDisplay) . ' pcs';
                        } else {
                            $mainQtyText = number_format($totalScanned) . ' pcs';
                        }
                        ?>
                        <span class="font-extrabold text-slate-900 text-xs block" id="card-total-qty"><?= $mainQtyText ?></span>

                        <div id="card-qty-subbadge">
                            <?php if ($isKanbanMode && $prevKbQty > 0): ?>
                                <div class="mt-0.5" style="line-height: 1.2;">
                                    <div style="font-size: 8px; color: #64748b; font-weight: 600; white-space: nowrap;">
                                        Sesi ini: <b><?= number_format($totalScanned) ?></b> (+<?= number_format($prevKbQty) ?>)
                                    </div>
                                    <div style="margin-top: 1.5px;">
                                        <?php if ($kanbanTarget > 0): ?>
                                            <?php if ($accumulatedQty === $kanbanTarget): ?>
                                                <span style="font-size: 7.5px; font-weight: 800; background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; padding: 0.5px 3.5px; border-radius: 3px; display: inline-block;">PAS KANBAN</span>
                                            <?php elseif ($accumulatedQty > $kanbanTarget): ?>
                                                <span style="font-size: 7.5px; font-weight: 800; background: #fef3c7; color: #92400e; border: 1px solid #fde68a; padding: 0.5px 3.5px; border-radius: 3px; display: inline-block;">LEBIH +<?= number_format($accumulatedQty - $kanbanTarget) ?> PCS</span>
                                            <?php else: ?>
                                                <span style="font-size: 7.5px; font-weight: 800; background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe; padding: 0.5px 3.5px; border-radius: 3px; display: inline-block;">KURANG <?= number_format($kanbanTarget - $accumulatedQty) ?> PCS</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Card 2: Ringkasan Sesi & Statistik Lot (Per-Lot AQL Flow) -->
            <div id="card-session-summary" style="background-color: #ffffff; border: 1px solid #cbd5e1; border-radius: 16px; padding: 12px; margin-bottom: 10px; box-shadow: 0 1px 2px 0 rgba(0,0,0,0.05);" class="flex-shrink-0">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                    <span style="font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">RINGKASAN INSPEKSI SESI</span>
                    <span id="badge-session-status" class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold shadow-2xs">
                        <!-- Populated by JS -->
                    </span>
                </div>
                <div class="grid grid-cols-2 gap-2 text-xs">
                    <div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 8px 10px;">
                        <span style="color: #64748b; font-size: 10px; font-weight: 700; display: block; text-transform: uppercase; letter-spacing: 0.03em;">PROGRES INSPEKSI LOT</span>
                        <span id="summary-lot-progress" style="color: #1e293b; font-size: 15px; font-weight: 900; font-family: monospace;">0 / 0 Lot</span>
                        <span id="summary-lot-subtext" style="color: #94a3b8; font-size: 9.5px; display: block; margin-top: 1px;">Menunggu pemeriksaan</span>
                    </div>
                    <div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 8px 10px;">
                        <span style="color: #64748b; font-size: 10px; font-weight: 700; display: block; text-transform: uppercase; letter-spacing: 0.03em;">TOTAL SAMPEL DIINSPEKSI</span>
                        <span id="summary-sample-total" style="color: #2563eb; font-size: 15px; font-weight: 900; font-family: monospace;">0 pcs</span>
                        <span id="summary-sample-subtext" style="color: #94a3b8; font-size: 9.5px; display: block; margin-top: 1px;">Akumulasi sampel fisik AQL</span>
                    </div>
                </div>
            </div>

            <!-- Card 3: Inspeksi Per-Box (Focus Box & Ringkasan Seluruh Box) -->
            <div id="lot-cards-wrapper" style="flex-shrink: 0; display: flex; flex-direction: column; margin-bottom: 12px;">
                <!-- Header Box & Tombol Tambah Box & Tandai Semua Passed -->
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; padding: 0 2px; flex-wrap: wrap; gap: 6px;">
                    <div style="display: flex; align-items: center; gap: 6px;">
                        <span style="font-size: 11px; font-weight: 800; color: #334155; text-transform: uppercase; letter-spacing: 0.05em;">INSPEKSI PER-BOX</span>
                        <span id="lot-count-badge" style="font-size: 10px; font-weight: 800; color: #2563eb; background-color: #eff6ff; border: 1px solid #bfdbfe; padding: 1px 7px; border-radius: 9999px;">0 Lot</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 6px;">
                        <button type="button" id="btn-add-lot" onclick="openAddLotModal()" style="display: none; padding: 5px 12px; background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color: #ffffff; border: 1.5px solid #1e40af; border-radius: 8px; font-size: 10.5px; font-weight: 800; cursor: pointer; align-items: center; gap: 5px; transition: all 0.15s; box-shadow: 0 2px 5px rgba(37,99,235,0.28);" onmouseover="this.style.filter='brightness(1.08)'" onmouseout="this.style.filter='none'" title="Scan barcode QR atau tambah box baru ke sesi ini">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            <span>Tambah Box</span>
                        </button>
                        <button type="button" id="btn-pass-all-lots" onclick="confirmPassAllLots()" style="display: none; padding: 5px 12px; background: linear-gradient(135deg, #059669 0%, #047857 100%); color: #ffffff; border: 1.5px solid #065f46; border-radius: 8px; font-size: 10.5px; font-weight: 800; cursor: pointer; align-items: center; gap: 5px; transition: all 0.15s; box-shadow: 0 2px 5px rgba(5,150,105,0.28);" onmouseover="this.style.filter='brightness(1.08)'" onmouseout="this.style.filter='none'" title="Loloskan seluruh sisa box yang belum dicek sekaligus">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                            <span>Tandai Semua Box PASSED</span>
                        </button>
                    </div>
                </div>

                <!-- SECTION 1: Active Focus Box (Kotak Box yang Sedang Diperiksa) -->
                <div id="active-focus-box-section" style="background-color: #ffffff; border: 1.5px solid #cbd5e1; border-radius: 14px; padding: 9px 11px; margin-bottom: 8px; box-shadow: 0 2px 6px rgba(0,0,0,0.03);">
                    <!-- Selector Dropdown & Navigasi Prev/Next -->
                    <div style="display: flex; align-items: center; gap: 5px; margin-bottom: 7px;">
                        <button type="button" id="btn-focus-prev" onclick="navigateFocusBox(-1)" style="width: 30px; height: 30px; padding: 0; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 11px; font-weight: 700; color: #475569; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; transition: all 0.15s;" title="Box Sebelumnya">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                        </button>
                        
                        <div style="flex: 1; min-width: 0;">
                            <select id="focus-lot-select" onchange="changeFocusBox(this.value)" style="width: 100%; height: 30px; padding: 3px 8px; font-size: 10.5px; font-weight: 700; color: #0f172a; background-color: #f8fafc; border: 1.5px solid #cbd5e1; border-radius: 8px; outline: none; cursor: pointer;">
                                <option value="">Memuat daftar box...</option>
                            </select>
                        </div>
                        
                        <button type="button" id="btn-focus-next" onclick="navigateFocusBox(1)" style="width: 30px; height: 30px; padding: 0; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 11px; font-weight: 700; color: #475569; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; transition: all 0.15s;" title="Box Berikutnya">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                        </button>
                    </div>

                    <!-- Mini Numbered Box Chips ([1] [2] ... [21]) -->
                    <div id="focus-box-chips-container" style="display: flex; gap: 5px; overflow-x: auto; padding: 4px 4px 6px 4px; margin-bottom: 7px;" class="custom-scrollbar">
                        <!-- Populated by JS -->
                    </div>

                    <!-- Card Body Box Aktif -->
                    <div id="active-focus-card-body">
                        <div class="text-center py-6 text-slate-400 text-xs">Memuat data pemeriksaan box...</div>
                    </div>
                </div>

                <!-- SECTION 2: Toggle Lihat Ringkasan Seluruh Box -->
                <div style="margin-bottom: 6px;">
                    <button type="button" id="btn-toggle-all-boxes" onclick="toggleAllBoxesView()" style="width: 100%; padding: 7px 12px; background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 11px; font-weight: 800; color: #475569; cursor: pointer; display: flex; align-items: center; justify-content: space-between; transition: all 0.15s;" onmouseover="this.style.backgroundColor='#f1f5f9'" onmouseout="this.style.backgroundColor='#f8fafc'">
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                            <span id="all-boxes-toggle-title">Lihat Ringkasan Seluruh Box</span>
                        </div>
                        <svg id="all-boxes-chevron" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="transform: rotate(0deg); transition: transform 0.2s;"><polyline points="6 9 12 15 18 9"/></svg>
                    </button>
                </div>

                <!-- Konten Seluruh Box (Collapsible) -->
                <div id="all-boxes-content" style="display: none; flex-direction: column; flex: 1; min-height: 200px; overflow: hidden;">
                    <!-- Tab Filter Status Lot -->
                    <div style="display: flex; gap: 4px; margin-bottom: 8px; overflow-x: auto; padding-bottom: 2px;" class="custom-scrollbar">
                        <button type="button" id="tab-lot-filter-all" onclick="switchLotFilterTab('all')" style="padding: 4px 8px; font-size: 10px; font-weight: 800; border-radius: 8px; border: 1px solid #cbd5e1; background-color: #0f172a; color: #ffffff; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap; transition: all 0.15s;">
                            <span>Semua</span>
                            <span id="tab-count-lot-all" style="background-color: rgba(255,255,255,0.2); padding: 0 5px; border-radius: 9999px; font-size: 9px;">0</span>
                        </button>
                        <button type="button" id="tab-lot-filter-pending" onclick="switchLotFilterTab('pending')" style="padding: 4px 8px; font-size: 10px; font-weight: 700; border-radius: 8px; border: 1px solid #e2e8f0; background-color: #ffffff; color: #64748b; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap; transition: all 0.15s;">
                            <span>Belum Dicek</span>
                            <span id="tab-count-lot-pending" style="background-color: #fef3c7; color: #b45309; padding: 0 5px; border-radius: 9999px; font-size: 9px; font-weight: 800;">0</span>
                        </button>
                        <button type="button" id="tab-lot-filter-passed" onclick="switchLotFilterTab('passed')" style="padding: 4px 8px; font-size: 10px; font-weight: 700; border-radius: 8px; border: 1px solid #e2e8f0; background-color: #ffffff; color: #64748b; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap; transition: all 0.15s;">
                            <span>Passed</span>
                            <span id="tab-count-lot-passed" style="background-color: #d1fae5; color: #065f46; padding: 0 5px; border-radius: 9999px; font-size: 9px; font-weight: 800;">0</span>
                        </button>
                        <button type="button" id="tab-lot-filter-rejected" onclick="switchLotFilterTab('rejected')" style="padding: 4px 8px; font-size: 10px; font-weight: 700; border-radius: 8px; border: 1px solid #e2e8f0; background-color: #ffffff; color: #64748b; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap; transition: all 0.15s;">
                            <span>Rejected</span>
                            <span id="tab-count-lot-rejected" style="background-color: #ffe4e6; color: #be123c; padding: 0 5px; border-radius: 9999px; font-size: 9px; font-weight: 800;">0</span>
                        </button>
                    </div>

                    <div id="lot-cards-container" style="flex: 1; overflow-y: auto; padding-right: 2px;" class="space-y-2.5 custom-scrollbar">
                        <div class="text-center py-8 text-slate-400 text-xs">Memuat data lot...</div>
                    </div>
                </div>
            </div>

            <!-- Card 4: Banner Sesi Selesai (Muncul Jika Semua Lot Sudah Selesai) -->
            <div id="session-finished-banner-v2" class="hidden flex-shrink-0 p-3 bg-slate-100 border border-slate-200 rounded-2xl text-center space-y-2 mb-2">
                <span class="text-[11px] font-bold text-slate-700 uppercase block tracking-wider">HASIL AKHIR SESI INSPEKSI</span>
                <span id="finished-v2-status-badge" class="text-[11px] font-black uppercase text-white px-3 py-1 rounded-full inline-block shadow-2xs">
                    STATUS
                </span>
                
                <!-- Chief QC Approval & Print Section -->
                <div id="finished-v2-actions-wrap" class="pt-1 space-y-2">
                    <div id="v2-chief-acc-wrap" class="p-2 bg-white border border-slate-200 rounded-xl flex items-center justify-between gap-2 shadow-2xs text-left">
                        <div class="min-w-0 flex-1">
                            <span class="text-[9.5px] font-bold text-slate-400 uppercase tracking-wider block">PERSETUJUAN (ACC) CHIEF QC</span>
                            <div id="v2-chief-status-text" class="text-[11px] font-extrabold truncate">
                                <!-- Populated by JS -->
                            </div>
                        </div>
                        <div class="flex-shrink-0" id="v2-chief-btn-container">
                            <!-- Populated by JS -->
                        </div>
                    </div>
                </div>
            </div>

        </div>

    </div>

</div>

<!-- Modal: Catat NG per Lot (Per-Lot AQL) -->
<div id="modal-lot-defect" style="position: fixed; inset: 0; background-color: rgba(15, 23, 42, 0.7); backdrop-filter: blur(4px); z-index: 9999; display: none; align-items: center; justify-content: center; padding: 16px;">
    <div style="background-color: #ffffff; border-radius: 18px; max-width: 580px; width: 100%; border: 1px solid #cbd5e1; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.2); overflow: visible; display: flex; flex-direction: column;">
        <!-- Header -->
        <div style="background-color: #fff1f2; border-bottom: 1px solid #fecdd3; padding: 14px 20px; border-top-left-radius: 18px; border-top-right-radius: 18px; display: flex; align-items: center; justify-content: space-between;">
            <div>
                <h3 style="font-size: 13.5px; font-weight: 800; color: #9f1239; margin: 0; letter-spacing: 0.02em;">CATAT TEMUAN NG PER LOT</h3>
                <p id="modal-defect-lot-subtitle" style="font-size: 11.5px; color: #be123c; margin: 2px 0 0 0; font-weight: 600;">Lot # - | Ref: -</p>
            </div>
            <button type="button" onclick="closeLotNgModal()" style="color: #9f1239; font-size: 22px; font-weight: bold; background: none; border: none; cursor: pointer; line-height: 1;">&times;</button>
        </div>

        <!-- AQL Notice -->
        <div style="background-color: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 8px 20px; font-size: 11.5px; display: flex; align-items: center; justify-content: space-between;">
            <span id="modal-defect-aql-text" style="color: #475569; font-weight: 600;">AQL: G-II | Wajib Sample: 20 pcs | Batas NG: 1</span>
            <span style="color: #e11d48; font-weight: 800; font-size: 10.5px; background-color: #ffe4e6; padding: 2px 8px; border-radius: 5px; border: 1px solid #fecdd3;">REJECTED jika NG &ge; Batas</span>
        </div>

        <!-- Body / Units Container -->
        <div id="modal-defect-body" style="padding: 16px 20px; max-height: 58vh; overflow-y: auto;" class="space-y-3 custom-scrollbar">
            <input type="hidden" id="modal-defect-lot-id" value="">
            <input type="hidden" id="modal-defect-reject-num" value="1">
            <input type="hidden" id="modal-defect-sample-size" value="0">
            
            <div id="modal-defect-units-container" class="space-y-3">
                <!-- Unit cards rendered dynamically by addNgUnitCard() -->
            </div>

            <button type="button" onclick="addNgUnitCard()" style="width: 100%; padding: 9px 14px; font-size: 11.5px; font-weight: 800; color: #1d4ed8; background-color: #eff6ff; border: 1.5px dashed #93c5fd; border-radius: 12px; cursor: pointer; transition: all 0.15s; display: flex; align-items: center; justify-content: center; gap: 6px;" onmouseover="this.style.backgroundColor='#dbeafe'" onmouseout="this.style.backgroundColor='#eff6ff'">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                <span>Tambah Unit / Pcs NG Baru</span>
            </button>
        </div>

        <!-- Live Impact Notice -->
        <div id="modal-defect-live-alert" style="padding: 10px 20px; background-color: #fff1f2; border-top: 1px solid #fecdd3; font-size: 11.5px; font-weight: 700; color: #9f1239; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <span>Total: <b id="modal-defect-units-count">1</b> Pcs Fisik NG</span>
                <span style="color: #fca5a5;">&middot;</span>
                <span style="color: #be123c;"><b id="modal-defect-defects-count">1</b> Temuan Defect</span>
            </div>
            <span id="modal-defect-verdict-display" style="background-color: #be123c; color: #ffffff; padding: 2.5px 10px; border-radius: 5px; font-size: 10px; font-weight: 800;">STATUS: REJECTED</span>
        </div>

        <!-- Footer Actions -->
        <div style="background-color: #f8fafc; border-top: 1px solid #e2e8f0; padding: 12px 20px; border-bottom-left-radius: 18px; border-bottom-right-radius: 18px; display: flex; align-items: center; justify-content: flex-end; gap: 10px;">
            <button type="button" onclick="closeLotNgModal()" style="padding: 8px 18px; border: 1px solid #cbd5e1; background-color: #ffffff; color: #475569; font-size: 12px; font-weight: 700; border-radius: 10px; cursor: pointer;">
                Batal
            </button>
            <button id="btn-submit-lot-ng" type="button" onclick="submitLotDefectForm()" style="padding: 8px 20px; background-color: #e11d48; color: #ffffff; font-size: 12px; font-weight: 800; border: none; border-radius: 10px; cursor: pointer; box-shadow: 0 1px 3px rgba(225,29,72,0.3);">
                Simpan &amp; Tandai REJECTED
            </button>
        </div>
    </div>
</div>

<!-- Modal: Tindakan Re-Inspeksi Per-Lot (Opsi A) -->
<div id="modal-lot-reinspection" style="position: fixed; inset: 0; background-color: rgba(15, 23, 42, 0.75); backdrop-filter: blur(4px); z-index: 9999; display: none; align-items: center; justify-content: center; padding: 16px;">
    <div style="background-color: #ffffff; border-radius: 20px; max-width: 560px; width: 100%; border: 1px solid #cbd5e1; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); overflow: hidden; display: flex; flex-direction: column;">
        <!-- Header -->
        <div style="background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); color: #ffffff; padding: 14px 18px; display: flex; align-items: center; justify-content: space-between;">
            <div>
                <h3 style="font-size: 13px; font-weight: 800; color: #ffffff; margin: 0; text-transform: uppercase; letter-spacing: 0.03em;">TINDAKAN RE-INSPEKSI LOT</h3>
                <p id="modal-reinsp-lot-subtitle" style="font-size: 11px; color: #94a3b8; margin: 2px 0 0 0; font-weight: 600;">Lot # - | Ref: -</p>
            </div>
            <button type="button" onclick="closeLotReinspectionModal()" style="color: #94a3b8; font-size: 20px; font-weight: bold; background: none; border: none; cursor: pointer;">&times;</button>
        </div>

        <!-- Hidden Inputs -->
        <input type="hidden" id="modal-reinsp-lot-id" value="">
        <input type="hidden" id="modal-reinsp-part-code" value="">

        <!-- Tab Selector: Opsi 1 (Ganti Box) vs Opsi 2 (Sortir) -->
        <div style="display: flex; border-bottom: 1px solid #e2e8f0; background-color: #f8fafc;">
            <button type="button" id="tab-reinsp-replace" onclick="switchLotReinspTab('replace')" style="flex: 1; padding: 10px 12px; font-size: 11px; font-weight: 800; border: none; border-bottom: 2.5px solid #2563eb; background-color: #ffffff; color: #2563eb; cursor: pointer; text-align: center;">
                Ganti Box Baru (Scan QR)
            </button>
            <button type="button" id="tab-reinsp-sort" onclick="switchLotReinspTab('sort')" style="flex: 1; padding: 10px 12px; font-size: 11px; font-weight: 700; border: none; border-bottom: 2.5px solid transparent; background-color: transparent; color: #64748b; cursor: pointer; text-align: center;">
                Ganti Part NG Saja (Sortir)
            </button>
        </div>

        <!-- Body Content -->
        <div style="padding: 16px 18px; max-height: 60vh; overflow-y: auto;" class="space-y-3 custom-scrollbar">
            <!-- TAB 1: GANTI BOX BARU -->
            <div id="content-reinsp-replace" class="space-y-3">
                <div style="background-color: #eff6ff; border: 1px solid #bfdbfe; border-radius: 10px; padding: 10px 12px; font-size: 11px; color: #1e40af;">
                    Box lama akan ditandai sebagai <b>DIGANTI</b> (Rejection Sheet tetap dapat dicetak). Box pengganti baru akan didaftarkan ke sesi ini dan wajib diperiksa sampel fisiknya.
                </div>

                <!-- QR Barcode Scanner Input -->
                <div style="background-color: #f0fdf4; border: 1.5px dashed #10b981; border-radius: 10px; padding: 10px 12px;">
                    <label style="display: block; font-size: 10px; font-weight: 800; color: #065f46; margin-bottom: 4px;">
                        SCAN BARCODE / QR LABEL BOX PENGGANTI:
                    </label>
                    <input type="text" id="reinsp-scan-qr-raw" oninput="handleLotReinspQrInput(this, false)" onkeydown="if(event.key==='Enter'){event.preventDefault();handleLotReinspQrInput(this, true);}" placeholder="Tempel atau Scan QR Code Box Pengganti..." style="width: 100%; box-sizing: border-box; padding: 8px 12px; font-family: monospace; font-size: 11px; font-weight: 700; border: 1.5px solid #10b981; border-radius: 8px; outline: none; background: #ffffff;">
                </div>

                <!-- Form Fields Manual/Preview -->
                <div class="grid grid-cols-2 gap-2 text-xs">
                    <div>
                        <label style="font-size: 9.5px; font-weight: 700; color: #64748b; display: block; margin-bottom: 2px;">PART CODE</label>
                        <input type="text" id="reinsp-part-code" readonly style="width: 100%; box-sizing: border-box; padding: 6px 10px; font-family: monospace; font-size: 11px; font-weight: 700; border: 1px solid #cbd5e1; border-radius: 6px; background-color: #f1f5f9; color: #334155;">
                    </div>
                    <div>
                        <label style="font-size: 9.5px; font-weight: 700; color: #64748b; display: block; margin-bottom: 2px;">LOT NUMBER PENGGANTI <span style="color: #e11d48;">*</span></label>
                        <input type="text" id="reinsp-lot-number" placeholder="Contoh: LOT-2026-X1" style="width: 100%; box-sizing: border-box; padding: 6px 10px; font-family: monospace; font-size: 11px; font-weight: 700; border: 1px solid #cbd5e1; border-radius: 6px; outline: none;">
                    </div>
                    <div>
                        <label style="font-size: 9.5px; font-weight: 700; color: #64748b; display: block; margin-bottom: 2px;">QTY BOX (PCS) <span style="color: #e11d48;">*</span></label>
                        <input type="number" id="reinsp-qty" min="1" placeholder="Contoh: 50" style="width: 100%; box-sizing: border-box; padding: 6px 10px; font-family: monospace; font-size: 11px; font-weight: 700; border: 1px solid #cbd5e1; border-radius: 6px; outline: none;">
                    </div>
                    <div>
                        <label style="font-size: 9.5px; font-weight: 700; color: #64748b; display: block; margin-bottom: 2px;">REF NUMBER (BOX NO)</label>
                        <input type="text" id="reinsp-ref-number" placeholder="Contoh: BOX-02B" style="width: 100%; box-sizing: border-box; padding: 6px 10px; font-family: monospace; font-size: 11px; font-weight: 700; border: 1px solid #cbd5e1; border-radius: 6px; outline: none;">
                    </div>
                </div>

                <div>
                    <label style="font-size: 9.5px; font-weight: 700; color: #64748b; display: block; margin-bottom: 2px;">CATATAN PENGGANTIAN (OPSIONAL)</label>
                    <input type="text" id="reinsp-replace-remarks" placeholder="Contoh: Box pengganti dari jalur produksi Line 2" style="width: 100%; box-sizing: border-box; padding: 6px 10px; font-size: 11px; border: 1px solid #cbd5e1; border-radius: 6px; outline: none;">
                </div>
            </div>

            <!-- TAB 2: GANTI PART NG SAJA (SORTIR) -->
            <div id="content-reinsp-sort" class="space-y-3" style="display: none;">
                <div style="background-color: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: 10px 12px; font-size: 11px; color: #92400e; line-height: 1.5;">
                    Label box (Lot No &amp; Ref No) tetap sama. Gunakan opsi ini jika seluruh part NG di dalam box ini telah disortir dan ditukar dengan part bagus.<br>
                    Status box akan dikembalikan ke <b>Sedang Diinspeksi</b> untuk diuji ulang sampel fisiknya dari awal.
                </div>

                <div>
                    <label style="font-size: 9.5px; font-weight: 700; color: #64748b; display: block; margin-bottom: 2px;">CATATAN SORTIR (OPSIONAL)</label>
                    <textarea id="reinsp-sort-notes" rows="2" placeholder="Contoh: Part NG sudah ditukar dengan part bagus dari stock line" style="width: 100%; box-sizing: border-box; padding: 7px 10px; font-size: 11px; border: 1px solid #cbd5e1; border-radius: 6px; outline: none;"></textarea>
                </div>
            </div>
        </div>

        <!-- Footer Actions -->
        <div style="background-color: #f8fafc; border-top: 1px solid #e2e8f0; padding: 12px 18px; display: flex; align-items: center; justify-content: flex-end; gap: 8px;">
            <button type="button" onclick="closeLotReinspectionModal()" style="padding: 7px 16px; border: 1px solid #cbd5e1; background-color: #ffffff; color: #475569; font-size: 12px; font-weight: 700; border-radius: 10px; cursor: pointer;">
                Batal
            </button>
            <button id="btn-submit-lot-reinsp" type="button" onclick="executeLotReinspectionSubmit()" style="padding: 7px 20px; background-color: #2563eb; color: #ffffff; font-size: 12px; font-weight: 800; border: none; border-radius: 10px; cursor: pointer; box-shadow: 0 1px 3px rgba(37,99,235,0.3);">
                Konfirmasi &amp; Muat Box Pengganti
            </button>
        </div>
    </div>
</div>

<!-- Scan & Validation Dead-Center Modal Overlay (2-Step Wizard: Step 1 Pilih Planning -> Step 2 Scan Label) -->
<div id="scan-modal-overlay" style="position: fixed; inset: 0; background-color: rgba(15, 23, 42, 0.75); backdrop-filter: blur(4px); z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 16px;" class="<?= $showScanOverlay ? '' : 'hidden' ?>">
    
    <div style="background-color: #ffffff; border-radius: 24px; max-width: 960px; width: 95vw; max-height: 90vh; padding: 32px; border: 1px solid #cbd5e1; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); position: relative; margin: auto; display: flex; flex-direction: column; overflow-x: hidden; overflow-y: auto;" class="animate-fadeIn space-y-5">
        
        <button type="button" onclick="closeScanModal(true)" class="absolute right-5 top-5 text-slate-400 hover:text-slate-600 font-bold text-2xl cursor-pointer" title="Tutup / Batal">&times;</button>

        <!-- Wizard Step Indicator Header -->
        <div class="text-center space-y-2 flex-shrink-0">
            <div class="flex items-center justify-center space-x-3 flex-wrap gap-y-1">
                <span id="step-pill-1" style="background-color: #2563eb; color: #ffffff; padding: 5px 16px; border-radius: 9999px; font-size: 13px; font-weight: 800; display: inline-block;">
                    1. Pilih Planning
                </span>
                <span style="color: #cbd5e1; font-weight: 800;" class="hidden sm:inline">&rarr;</span>
                <span id="step-pill-2" style="background-color: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; padding: 5px 16px; border-radius: 9999px; font-size: 13px; font-weight: 700; display: inline-block;">
                    2. Scan Label QR
                </span>
            </div>
            <h2 id="wizard-modal-title" class="text-lg font-black text-slate-900 leading-tight">Step 1: Pilih Item Planning Aktif</h2>
            <p id="wizard-modal-subtitle" class="text-sm text-slate-500 font-medium">Pilih Kanban / Safety Stock yang akan diinspeksi sebelum melakukan scan label</p>
        </div>

        <datalist id="modal-parts-list">
            <?php foreach ($master_parts as $mp): ?>
                <option value="<?= htmlspecialchars($mp['part_code']) ?>"><?= htmlspecialchars($mp['part_code']) ?> - <?= htmlspecialchars($mp['part_name']) ?></option>
            <?php endforeach; ?>
        </datalist>

        <!-- ── STEP 1: PILIH PLANNING ITEM ─────────────────────────── -->
        <div id="wizard-step-1-content" style="display: flex; flex-direction: column; min-height: 0; flex: 1;" class="space-y-3 overflow-hidden">
            
            <!-- Tab Filter Tipe Planning (Default Prioritas Utama: KANBAN) -->
            <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;" class="flex-shrink-0">
                <button type="button" id="tab-plan-kanban" onclick="setPlanningTypeTab('kanban')" style="background-color: #2563eb; color: #ffffff; border: 1px solid #1d4ed8; font-weight: 800; padding: 5px 12px; border-radius: 8px; font-size: 11px; cursor: pointer; box-shadow: 0 2px 4px rgba(37,99,235,0.3);">
                    📋 Kanban (Utama)
                </button>
                <button type="button" id="tab-plan-partial" onclick="setPlanningTypeTab('partial')" style="background-color: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; font-weight: 700; padding: 5px 12px; border-radius: 8px; font-size: 11px; cursor: pointer;">
                    ⚡ Cicilan (Parsial)
                </button>
                <button type="button" id="tab-plan-safety_stock" onclick="setPlanningTypeTab('safety_stock')" style="background-color: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; font-weight: 700; padding: 5px 12px; border-radius: 8px; font-size: 11px; cursor: pointer;">
                    📦 Safety Stock
                </button>
            </div>

            <!-- Direct Safety Stock Scanning Banner (Tampil saat Tab Safety Stock dipilih) -->
            <div id="safety-stock-direct-card" class="p-3.5 bg-gradient-to-r from-purple-50 to-indigo-50 border-2 border-purple-300 rounded-xl space-y-2 text-xs shadow-xs hidden flex-shrink-0">
                <div class="flex items-center space-x-2">
                    <span class="p-1.5 bg-purple-600 text-white rounded-lg font-black text-sm">📦</span>
                    <div class="min-w-0 flex-1">
                        <h4 class="font-extrabold text-purple-900 text-xs">Cek Direct Safety Stock Barang Gudang</h4>
                        <p class="text-[10px] text-purple-700 font-medium">Inspeksi barang gudang tanpa planning list Excel. Langsung scan barcode QR (Z1..Z5) atau ketik form manual label barang.</p>
                    </div>
                </div>
                <button type="button" onclick="startDirectSafetyStockScan()" class="w-full py-2 px-3 bg-purple-600 hover:bg-purple-700 text-white font-extrabold text-xs rounded-lg shadow-sm flex items-center justify-center space-x-1.5 transition-all">
                    <span>Mulai Scan / Input Label Safety Stock Gudang &rarr;</span>
                </button>
            </div>

            <div class="relative flex-shrink-0">
                <input type="text" id="planning-search-input" autofocus autocomplete="off" onkeyup="filterPlanningItems()" oninput="filterPlanningItems()" placeholder="Cari Part Code, Item Description, Customer, atau No. Kanban... (Scan / Ketik)" class="form-input py-2.5 px-4 text-sm w-full font-semibold focus:ring-2 focus:ring-blue-500 focus:outline-none">
            </div>

            <!-- Planning Cards List Container (Strictly Scrollable Flex-1 Container) -->
            <div id="planning-cards-container" style="flex: 1; min-height: 0; max-height: 48vh; overflow-y: auto; -webkit-overflow-scrolling: touch;" class="space-y-2 pr-1 divide-y divide-slate-100 border border-slate-200/80 rounded-xl p-2 bg-slate-50/50">
                <?php if (empty($activePlanningItems)): ?>
                    <div class="text-center py-6 text-xs text-slate-400 bg-slate-50 rounded-xl border border-dashed border-slate-200">
                        Belum ada data Planning (Kanban / Safety Stock) tersimpan di sistem.
                    </div>
                <?php else: ?>
                    <?php foreach ($activePlanningItems as $idx => $plan): 
                        $pCode = htmlspecialchars($plan['item_code']);
                        $pDesc = htmlspecialchars($plan['item_description']);
                        $pCust = htmlspecialchars($plan['customer'] ?? 'PT. Indonesia Epson Industry');
                        
                        $targetTotalQty = (int)$plan['qty'];
                        $totalPassedQty = (int)($plan['total_passed_qty'] ?? 0);
                        $remainingQty = max(0, $targetTotalQty - $totalPassedQty);
                        $pQty = number_format($remainingQty);
                        
                        $isSafetyStock = ($plan['plan_type'] === 'safety_stock' || ($plan['batch_plan_type'] ?? '') === 'safety_stock' || strpos(strtoupper($plan['kanban_no'] ?? ''), 'SS') === 0);
                        $pType = $isSafetyStock ? 'Safety Stock' : 'Kanban';
                        $pCek  = !empty($plan['check_type']) ? htmlspecialchars($plan['check_type']) : '';
                        
                        // Format ETA
                        $etaTs = !empty($plan['eta']) ? strtotime($plan['eta']) : (!empty($plan['req_date']) ? strtotime($plan['req_date']) : 0);
                        $etaFormatted = ($etaTs > 0) ? date('d M Y, H:i', $etaTs) . ' WIB' : 'Reguler';
                        $isEarliest = ($idx === 0);
                    ?>
                    <?php $isPartial = (!$isSafetyStock && $totalPassedQty > 0); ?>
                    <div class="planning-card p-4 bg-white hover:bg-blue-50/80 border border-slate-200 hover:border-blue-400 rounded-xl cursor-pointer transition-all flex items-center justify-between gap-4 shadow-2xs"
                         data-plan-type="<?= $isSafetyStock ? 'safety_stock' : 'kanban' ?>"
                         data-partial="<?= $isPartial ? 'true' : 'false' ?>"
                         onclick="selectPlanningItem(<?= (int)$plan['id'] ?>, '<?= addslashes($plan['item_code']) ?>', '<?= addslashes($plan['item_description']) ?>', '<?= addslashes($pCust) ?>', <?= (int)$remainingQty ?>, '<?= addslashes($pCek) ?>', '<?= $isSafetyStock ? 'safety_stock' : 'kanban' ?>', <?= (int)($plan['avail_ss_qty'] ?? 0) ?>, <?= $totalPassedQty ?>, <?= $targetTotalQty ?>)">
                        <div class="min-w-0 flex-1 space-y-1.5">
                            <div class="flex items-center space-x-2 flex-wrap gap-y-1">
                                <span class="font-mono font-black text-blue-700 text-sm"><?= $pCode ?></span>
                                <span class="badge text-xs <?= $isSafetyStock ? 'bg-purple-100 text-purple-800 border border-purple-200 font-bold' : 'bg-blue-100 text-blue-800 border border-blue-200 font-bold' ?>"><?= $pType ?></span>
                                <?php if ($pCek): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-amber-100 text-amber-800 border border-amber-300"><?= $pCek ?> Cek</span>
                                <?php endif; ?>
                                <?php if ($totalPassedQty > 0 && !$isSafetyStock): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-indigo-100 text-indigo-800 border border-indigo-300" title="Kanban ini diinspeksi secara bertahap / dicicil">⚡ Inspeksi Parsial: <?= number_format($totalPassedQty) ?> / <?= number_format($targetTotalQty) ?> pcs (Sisa <?= number_format($remainingQty) ?> pcs)</span>
                                <?php endif; ?>
                                <?php if (!empty($plan['avail_ss_qty']) && (int)$plan['avail_ss_qty'] > 0 && !$isSafetyStock): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-emerald-100 text-emerald-800 border border-emerald-300" title="Tersedia stok Safety Stock yang bisa dipakai memotong Qty Kanban">📦 Safety Stock: <?= number_format($plan['avail_ss_qty']) ?> pcs (Potong Qty)</span>
                                <?php endif; ?>

                                <!-- ETA Schedule & Prioritas Badge -->
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold <?= $isEarliest ? 'bg-rose-100 text-rose-800 border border-rose-300' : 'bg-slate-100 text-slate-600 border border-slate-200' ?>">
                                    🚚 ETA: <?= $etaFormatted ?> <?= $isEarliest ? '⚡ (P1)' : '' ?>
                                </span>
                            </div>
                            <div class="font-bold text-slate-800 text-sm truncate"><?= $pDesc ?></div>
                            <div class="text-xs text-slate-500 flex items-center space-x-2 flex-wrap">
                                <span>Cust: <b><?= $pCust ?></b></span>
                                <span>&middot;</span>
                                <span>Target Sisa Qty: <b class="text-blue-700 font-extrabold"><?= $pQty ?> pcs</b> <?= ($totalPassedQty > 0) ? '(dari total ' . number_format($targetTotalQty) . ' pcs)' : '' ?></span>
                                <?php if (!empty($plan['kanban_no']) && $plan['kanban_no'] !== '-'): ?>
                                    <span>&middot;</span>
                                    <span class="font-mono">No. Kanban: <b>#<?= htmlspecialchars($plan['kanban_no']) ?></b></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <button type="button" class="btn-primary py-2 px-4 text-sm font-bold flex-shrink-0 shadow-2xs">
                            Pilih &rarr;
                        </button>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Step 1 Footer Action Bar (Batal / Kembali Button) -->
            <div style="display: flex; align-items: center; justify-content: flex-end; padding-top: 10px; border-top: 1px solid #e2e8f0;" class="flex-shrink-0">
                <button type="button" onclick="closeScanModal(true)" style="background-color: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; font-weight: 700; padding: 8px 18px; border-radius: 9px; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 5px;">
                    ❌ Batal / Kembali
                </button>
            </div>
        </div>

        <!-- ── STEP 2: SCAN BARCODE LABEL ─────────────────────────── -->
        <div id="wizard-step-2-content" style="flex: 1; min-height: 0; max-height: 62vh; overflow-x: hidden; overflow-y: auto;" class="space-y-3 hidden pr-0.5">
            
            <!-- Selected Planning Summary Banner -->
            <div class="p-3 bg-blue-50/80 border border-blue-200 rounded-xl flex items-center justify-between text-xs space-x-2">
                <div class="min-w-0 flex-1 space-y-0.5">
                    <div class="flex items-center space-x-2 flex-wrap gap-y-0.5">
                        <span class="text-[10px] font-extrabold text-blue-600 uppercase tracking-wider">PLANNING DIPILIH (STEP 1)</span>
                        <span id="selected-plan-tag" class="text-[9px] bg-amber-100 text-amber-800 border border-amber-300 px-1.5 py-0.2 rounded font-sans font-bold">100% Cek</span>
                    </div>
                    <div class="font-mono font-black text-blue-900 text-sm flex items-center space-x-2 truncate">
                        <span id="selected-plan-part-code" class="text-blue-900 font-extrabold truncate">PART-CODE</span>
                    </div>
                    <div id="selected-plan-desc" class="font-bold text-slate-800 text-xs truncate">Description</div>
                    <div class="text-[10px] text-slate-500 font-medium">
                        Cust: <b id="selected-plan-cust" class="text-slate-700">-</b> &middot; Target Qty: <b id="selected-plan-qty" class="text-blue-700 font-extrabold">0</b>
                    </div>
                </div>
                <div style="display: flex; align-items: center; gap: 6px;" class="flex-shrink-0">
                    <button type="button" onclick="backToStep1()" class="text-xs text-blue-700 font-bold hover:bg-slate-100 bg-white border border-blue-300 px-2.5 py-1.5 rounded-xl shadow-2xs flex items-center space-x-1">
                        <span>✏️ Ganti</span>
                    </button>
                    <button type="button" onclick="closeScanModal(true)" class="text-xs text-rose-700 font-bold hover:bg-rose-50 bg-white border border-rose-300 px-2.5 py-1.5 rounded-xl shadow-2xs flex items-center space-x-1">
                        <span>❌ Batal</span>
                    </button>
                </div>
            </div>

            <!-- Safety Stock Allocation Card (Muncul jika ada Safety Stock PASSED tersedia) -->
            <div id="safety-stock-deduction-card" class="hidden p-3 bg-gradient-to-r from-emerald-50 to-teal-50 border-2 border-emerald-300 rounded-xl space-y-2 text-xs shadow-2xs">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-1.5 font-extrabold text-emerald-900">
                        <span class="text-sm">📦</span>
                        <span>SAFETY STOCK PASSED TERSEDIA!</span>
                    </div>
                    <span id="ss-deduct-badge" class="text-[10px] font-extrabold px-2 py-0.5 rounded-full bg-emerald-600 text-white shadow-2xs">
                        ALOKASI 0 PCS
                    </span>
                </div>
                <div class="text-[11px] text-slate-700 space-y-1 bg-white/80 p-2 rounded-lg border border-emerald-200">
                    <div class="flex justify-between">
                        <span class="text-slate-500">Target Kanban Pesanan:</span>
                        <span id="ss-deduct-orig-qty" class="font-bold text-slate-900">0 pcs</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-emerald-700 font-semibold">Dipenuhi dari Safety Stock (Passed):</span>
                        <span id="ss-deduct-used-qty" class="font-black text-emerald-700">- 0 pcs</span>
                    </div>
                    <div class="flex justify-between border-t border-slate-200 pt-1 font-bold">
                        <span class="text-blue-900">Sisa Perlu Scan Fisik Baru:</span>
                        <span id="ss-deduct-remain-qty" class="font-black text-blue-700 text-xs">0 pcs</span>
                    </div>
                </div>

                <!-- Interactive Multi-Lot Safety Stock Selection List -->
                <div class="space-y-1.5 bg-emerald-100/50 p-2 rounded-lg border border-emerald-200">
                    <div class="flex items-center justify-between font-extrabold text-[11px] text-emerald-900 pb-1 border-b border-emerald-200/80">
                        <span class="flex items-center space-x-1">
                            <span>📋 Pilih Lot Safety Stock Tersedia</span>
                        </span>
                        <span class="text-[10px] text-emerald-700 font-semibold" id="ss-lots-count-info">0 Lot</span>
                    </div>

                    <!-- Search & Barcode Scan Input for Safety Stock Lots -->
                    <div style="position: relative; width: 100%; box-sizing: border-box; margin: 6px 0;">
                        <div style="position: absolute; left: 10px; top: 0; bottom: 0; display: flex; align-items: center; pointer-events: none; color: #059669;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="11" cy="11" r="8"></circle>
                                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                            </svg>
                        </div>
                        <input type="text"
                               id="ss-lot-search-input"
                               placeholder="Cari / Scan Ref No atau Lot Safety Stock..."
                               autocomplete="off"
                               style="width: 100%; box-sizing: border-box; font-size: 11.5px; font-weight: 600; padding: 7px 30px 7px 32px; border-radius: 8px; border: 1.5px solid #a7f3d0; background-color: #ffffff; color: #0f172a; outline: none; transition: all 0.2s;"
                               onfocus="this.style.borderColor='#059669'; this.style.boxShadow='0 0 0 3px rgba(16, 185, 129, 0.2)';"
                               onblur="this.style.borderColor='#a7f3d0'; this.style.boxShadow='none';"
                               oninput="handleSsLotSearchInput(this.value)"
                               onkeydown="handleSsLotSearchKeydown(event)">
                        <button type="button" 
                                id="ss-lot-search-clear" 
                                onclick="clearSsLotSearch()" 
                                style="position: absolute; right: 8px; top: 0; bottom: 0; margin: auto; height: 18px; width: 18px; border-radius: 50%; border: none; background: #cbd5e1; color: #475569; font-size: 10px; font-weight: 900; cursor: pointer; display: none; align-items: center; justify-content: center; line-height: 1; padding: 0;">✕</button>
                    </div>

                    <div id="ss-lots-checkbox-list" class="space-y-1.5 max-h-36 overflow-y-auto pr-1">
                        <!-- Populated dynamically via JS -->
                    </div>
                </div>

                <!-- 100% Safety Stock Completion Banner (Tampil saat 100% Kanban terpenuhi dari SS) -->
                <div id="ss-full-fulfill-banner" style="background-color: #059669; color: #ffffff; padding: 14px; border-radius: 12px; text-align: center;" class="hidden space-y-2 shadow-sm">
                    <p style="font-weight: 900; font-size: 13px; color: #ffffff; margin: 0;">✨ 100% Target Qty Terpenuhi dari Safety Stock!</p>
                    <p style="font-size: 11px; font-weight: 500; color: #d1fae5; margin: 0 0 6px 0;">Seluruh lot barang telah terverifikasi PASSED di Safety Stock. Tidak perlu pengujian sample atau scan barcode fisik baru.</p>
                    <button type="button" onclick="triggerDirectSsCompletion()" style="width: 100%; padding: 12px 14px; background-color: #ffffff !important; color: #064e3b !important; border: 2px solid #047857; border-radius: 10px; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.15); transition: all 0.2s;" onmouseover="this.style.backgroundColor='#ecfdf5'" onmouseout="this.style.backgroundColor='#ffffff'">
                        <span style="color: #064e3b !important; font-weight: 900 !important; font-size: 13px !important; line-height: 1.2; display: inline-block;">🚀 Selesaikan &amp; Loloskan Inspeksi Kanban (100% Safety Stock) &rarr;</span>
                    </button>
                </div>

                <!-- Partial Safety Stock Banner (Tampil saat Safety Stock terpakai sebagian dan masih ada sisa) -->
                <div id="ss-partial-fulfill-banner" class="hidden p-3 bg-blue-50 border border-blue-200 rounded-xl space-y-1">
                    <div class="flex items-center space-x-1.5 text-blue-900 font-extrabold text-xs">
                        <span>📦 Alokasi Safety Stock Aktif (<span id="ss-partial-used-qty-text">0</span> pcs)</span>
                    </div>
                    <p class="text-[11px] text-blue-700 font-medium">
                        Target Kanban: <b><span id="ss-partial-target-text">500</span> pcs</b>. Masih kurang <b><span id="ss-partial-remain-text">220</span> pcs</b>. Silakan scan label QR fisik pada form di bawah untuk melengkapi kekurangan.
                    </p>
                </div>

                <div class="flex items-center justify-end pt-0.5">
                    <button type="button" id="btn-toggle-ss-deduct" onclick="toggleSafetyStockDeduction()" class="px-2.5 py-1 bg-rose-100 hover:bg-rose-200 text-rose-800 border border-rose-300 font-extrabold text-[10px] rounded-lg shadow-2xs transition-all flex items-center space-x-1 cursor-pointer">
                        <span>❌ Jangan Pakai Safety Stock</span>
                    </button>
                </div>
            </div>

            <!-- Multi-Label Accumulation Progress Card -->
            <div class="p-3 bg-slate-50 border border-slate-200 rounded-xl space-y-2">
                <div class="flex items-center justify-between text-xs">
                    <span class="font-extrabold text-slate-700">Akumulasi Qty Scan Label:</span>
                    <span id="scan-progress-counter" class="font-mono font-black text-blue-700 text-sm whitespace-nowrap">
                        <span id="scanned-total-qty">0</span> / <span id="target-kanban-qty-display">0</span>
                    </span>
                </div>
                <div class="w-full bg-slate-200 h-2.5 rounded-full overflow-hidden">
                    <div id="scan-progress-bar" class="h-full transition-all duration-300" style="width: 0%; background-color: #2563eb;"></div>
                </div>
                <div class="flex items-center justify-between text-[10px] text-slate-500 font-medium">
                    <span id="scan-status-msg">Scan label QR untuk mengumpulkan Qty...</span>
                    <span id="scan-excess-msg" class="text-indigo-600 font-bold hidden">Sisa 0 pcs akan menjadi Safety Stock</span>
                </div>
            </div>

            <!-- Smart QR Barcode Multi-Scan Input -->
            <form id="modal-scan-form" onsubmit="event.preventDefault(); handleManualAddLabel();" class="space-y-3 text-xs w-full min-w-0">
                
                <div class="p-2.5 bg-blue-50/50 border border-blue-200 rounded-xl space-y-1">
                    <label class="block font-extrabold text-slate-900 text-[11px] flex items-center justify-between">
                        <span class="flex items-center">
                            <svg class="w-3.5 h-3.5 mr-1 text-blue-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"></path></svg>
                            Scan Barcode / QR Code Label
                        </span>
                        <span class="text-[10px] text-blue-600 font-semibold">Auto-Add per Scan</span>
                    </label>
                    <input type="text" id="scan-qr-raw" autofocus oninput="parseBarcodeQRInput(this.value, false)" onkeydown="handleScanInputKeydown(event)" placeholder="Tempel atau Scan Barcode / QR Code Label di sini..." class="form-input py-2 px-3 text-xs font-mono font-bold bg-white border-blue-400 focus:border-blue-600 w-full min-w-0 box-border" autocomplete="off">
                    <p class="text-[10px] text-slate-500 font-medium">Sistem membaca Ref No, Lot No, Qty secara otomatis. Scan bertahap hingga target Qty terpenuhi.</p>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 w-full min-w-0">
                    <div class="min-w-0">
                        <label class="block font-extrabold text-slate-700 mb-1 text-[10px] truncate">Part Code <span class="text-rose-500">*</span></label>
                        <input type="text" id="scan-part-code" list="modal-parts-list" placeholder="190041102" class="form-input py-1.5 px-2 text-xs font-mono font-bold uppercase w-full min-w-0 box-border" autocomplete="off">
                    </div>
                    <div class="min-w-0">
                        <label class="block font-extrabold text-slate-700 mb-1 text-[10px] truncate">Lot Number <span class="text-rose-500">*</span></label>
                        <input type="text" id="scan-lot-number" placeholder="06426817" class="form-input py-1.5 px-2 text-xs font-mono font-bold uppercase w-full min-w-0 box-border" autocomplete="off">
                    </div>
                    <div class="min-w-0">
                        <label class="block font-extrabold text-slate-700 mb-1 text-[10px] truncate">Qty Box <span class="text-rose-500">*</span></label>
                        <input type="number" id="scan-label-qty" min="1" placeholder="20" class="form-input py-1.5 px-2 text-xs font-mono font-bold w-full min-w-0 box-border" autocomplete="off">
                    </div>
                    <div class="min-w-0">
                        <label class="block font-extrabold text-blue-700 mb-1 text-[10px] truncate">Ref Number <span class="text-rose-500">*</span></label>
                        <input type="text" id="scan-ref-number" placeholder="KSKA" class="form-input py-1.5 px-2 text-xs font-mono font-bold uppercase bg-blue-50/50 border-blue-300 w-full min-w-0 box-border" autocomplete="off">
                    </div>
                </div>

                <div class="flex items-center justify-between gap-2">
                    <button type="button" onclick="handleManualAddLabel()" class="btn-secondary py-1.5 px-3 text-xs font-semibold flex items-center">
                        + Tambah Label Manual
                    </button>
                    <input type="hidden" id="scan-remarks" value="">
                </div>
            </form>

            <!-- Table List Scanned Labels -->
            <div class="space-y-1.5">
                <div class="flex items-center justify-between text-[11px] font-bold text-slate-700">
                    <span>Daftar Label / Box yang Discan (<span id="scanned-labels-count">0</span> Label):</span>
                    <button type="button" onclick="clearAllScannedLabels()" class="text-[10px] text-rose-600 hover:underline font-semibold" id="btn-clear-labels" style="display: none;">Reset Scan</button>
                </div>
                <div class="border border-slate-200 rounded-xl overflow-hidden max-h-36 overflow-y-auto bg-white">
                    <table class="w-full text-left text-[11px]">
                        <thead class="bg-slate-100 text-slate-600 font-bold border-b border-slate-200 uppercase text-[9px]">
                            <tr>
                                <th class="px-2.5 py-1.5">No</th>
                                <th class="px-2.5 py-1.5">Ref No</th>
                                <th class="px-2.5 py-1.5">Lot No</th>
                                <th class="px-2.5 py-1.5 text-right">Qty</th>
                                <th class="px-2.5 py-1.5 text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="scanned-labels-table-body" class="divide-y divide-slate-100 text-slate-700">
                            <tr>
                                <td colspan="5" class="px-2.5 py-4 text-center text-slate-400 italic">
                                    Belum ada label QR yang discan. Silakan scan barcode label di atas.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Submit Final Button -->
            <button type="button" id="btn-submit-scan-modal" onclick="triggerModalValidation()" class="btn-primary w-full py-2.5 text-xs font-bold flex items-center justify-center shadow-md shadow-blue-600/20" disabled style="opacity: 0.6;">
                Validasi Matching & Muat Inspeksi
            </button>

            <div id="modal-loading" class="hidden text-center py-4 space-y-2">
                <div class="inline-block animate-spin rounded-full h-7 w-7 border-4 border-blue-600 border-t-transparent"></div>
                <p class="text-xs font-semibold text-slate-600">Memeriksa ketersediaan status DID & Matching Planning...</p>
            </div>

            <div id="modal-error-box" class="hidden p-3 bg-rose-50 border border-rose-200 rounded-xl text-xs text-rose-800 font-medium space-y-1">
                <span class="font-bold block text-rose-900">⚠️ Validasi Gagal:</span>
                <span id="modal-error-msg" class="whitespace-pre-line"></span>
            </div>

        </div>

    </div>
</div>

<!-- Modal Pop-up Rincian Detail Kanban / Planning -->
<div id="kanban-detail-modal-overlay" style="position: fixed; inset: 0; background-color: rgba(15, 23, 42, 0.75); backdrop-filter: blur(4px); z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 16px;" class="hidden">
    
    <div style="background-color: #ffffff; border-radius: 24px; max-width: 560px; width: 100%; max-height: 85vh; padding: 20px; border: 1px solid #cbd5e1; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); position: relative; margin: auto; display: flex; flex-direction: column; overflow: hidden;" class="animate-fadeIn space-y-2.5">
        
        <!-- Header Modal dengan tombol Close Rapi -->
        <div class="flex items-center justify-between border-b border-slate-100 pb-2.5 flex-shrink-0">
            <div class="flex items-center space-x-2.5">
                <div class="w-8 h-8 rounded-xl bg-blue-100 text-blue-700 flex items-center justify-center font-bold text-base shadow-2xs">
                    📋
                </div>
                <div>
                    <h3 class="text-sm font-extrabold text-slate-900 leading-tight">Detail Planning Kanban & Lot Produksi</h3>
                    <p class="text-[11px] text-slate-500 font-medium">Rincian metadata dokumen acuan, part model, & lot produksi</p>
                </div>
            </div>
            <button type="button" onclick="closeKanbanDetailModal()" class="w-8 h-8 rounded-lg hover:bg-slate-100 text-slate-400 hover:text-slate-600 font-bold text-xl flex items-center justify-center transition-all cursor-pointer">&times;</button>
        </div>

        <!-- Grid Detail Content Container (Explicitly Max-Height 50vh Scrollable) -->
        <div class="space-y-2.5 pr-1.5 flex-1 min-h-0" style="max-height: 48vh; overflow-y: auto !important; display: block;">
            
            <!-- Group 1: Informasi Dokumen Batch & Customer -->
            <div class="p-3 bg-slate-50 border border-slate-200/80 rounded-xl space-y-2 text-xs">
                <div class="flex items-center justify-between text-[11px] font-bold text-blue-900 border-b border-slate-200 pb-1">
                    <span>📌 METADATA BATCH & CUSTOMER</span>
                    <span id="modal-kb-plan-type" class="px-2 py-0.5 rounded text-[9px] font-black bg-blue-100 text-blue-800 uppercase">
                        <?= ($session && ($session['inspection_type'] ?? 'kanban') === 'safety_stock') ? 'SAFETY STOCK' : 'KANBAN' ?>
                    </span>
                </div>
                <div class="grid grid-cols-2 gap-2 text-slate-700">
                    <div>
                        <span class="text-[10px] text-slate-400 font-bold block uppercase">No. Dokumen Batch</span>
                        <span id="modal-kb-doc-no" class="font-mono font-extrabold text-slate-900 text-xs block truncate"><?= $session ? htmlspecialchars($session['doc_no'] ?? '-') : '-' ?></span>
                    </div>
                    <div>
                        <span class="text-[10px] text-slate-400 font-bold block uppercase">Customer Tujuan</span>
                        <span id="modal-kb-customer" class="font-extrabold text-blue-900 text-xs block truncate">🏢 <?= $session ? htmlspecialchars($session['customer'] ?? 'PT. Indonesia Epson Industry') : '-' ?></span>
                    </div>
                </div>
            </div>

            <!-- Group 2: Detail Part, Nama Model & Lot Produksi -->
            <div class="p-3 bg-blue-50/60 border border-blue-200 rounded-xl space-y-2 text-xs">
                <div class="text-[11px] font-bold text-blue-900 border-b border-blue-200/80 pb-1 flex items-center justify-between">
                    <span>🏷️ RINCIAN PART, MODEL & LOT PRODUKSI</span>
                    <span class="text-[10px] font-mono text-blue-700 font-extrabold" id="modal-kb-kanban-no-badge">#<?= $session ? htmlspecialchars($session['kanban_no'] ?? '-') : '-' ?></span>
                </div>
                <div class="grid grid-cols-2 gap-2 text-slate-700">
                    <div>
                        <span class="text-[10px] text-slate-400 font-bold block uppercase">Part Code</span>
                        <span id="modal-kb-item-code" class="font-mono font-black text-blue-700 text-xs block truncate"><?= $session ? htmlspecialchars($session['kanban_item_code'] ?? $session['part_code'] ?? '-') : '-' ?></span>
                    </div>
                    <div>
                        <span class="text-[10px] text-slate-400 font-bold block uppercase">Nama Model</span>
                        <span id="modal-kb-part-model" class="font-bold text-purple-800 text-xs block truncate"><?= ($session && !empty($session['part_model'])) ? htmlspecialchars($session['part_model']) : '-' ?></span>
                    </div>
                    <div class="col-span-2">
                        <span class="text-[10px] text-slate-400 font-bold block uppercase">Item Description / Nama Part</span>
                        <span id="modal-kb-item-desc" class="font-bold text-slate-900 text-xs block truncate"><?= $session ? htmlspecialchars($session['kanban_item_desc'] ?? $session['part_name'] ?? '-') : '-' ?></span>
                    </div>
                    <div class="col-span-2 pt-1 border-t border-blue-100">
                        <span class="text-[10px] text-slate-400 font-bold block uppercase">Cavity Moulding</span>
                        <span id="modal-kb-cavity" class="font-bold text-slate-800 text-xs block">Cavity <?= $session ? htmlspecialchars($session['cavity'] ?? '1') : '1' ?></span>
                    </div>
                </div>
            </div>

            <!-- Group 2.3: Akumulasi Sesi Kanban (Visible when previous session exists) -->
            <div id="modal-kb-accumulated-box" class="p-3 bg-emerald-50/70 border border-emerald-200 rounded-xl space-y-1.5 text-xs <?= (!empty($session['prev_kanban_qty'])) ? '' : 'hidden' ?>" style="<?= (!empty($session['prev_kanban_qty'])) ? '' : 'display: none;' ?>">
                <div class="text-[11px] font-bold text-emerald-900 border-b border-emerald-200/80 pb-1 flex items-center justify-between">
                    <span>AKUMULASI INSPEKSI KANBAN</span>
                    <span class="text-[10px] font-mono font-extrabold text-emerald-800" id="modal-kb-accumulated-status">
                        <?= (!empty($session['accumulated_kanban_qty']) && !empty($session['kanban_qty'])) ? number_format($session['accumulated_kanban_qty']) . ' / ' . number_format($session['kanban_qty']) . ' pcs' : '-' ?>
                    </span>
                </div>
                <div class="text-[10.5px] text-slate-700 space-y-1" id="modal-kb-sessions-list">
                    <?php if (!empty($session['prev_kanban_sessions'])): ?>
                        <?php foreach ($session['prev_kanban_sessions'] as $pks): ?>
                            <div class="flex items-center justify-between py-0.5 border-b border-emerald-100">
                                <span>Sesi #<?= $pks['id'] ?> (<?= htmlspecialchars($pks['status_text'] ?? 'Lulus') ?> - <?= htmlspecialchars($pks['inspector_name'] ?? 'QC Inspector') ?>):</span>
                                <span class="font-mono font-bold text-emerald-800"><?= number_format($pks['passed_qty'] ?? $pks['total_scanned_qty']) ?> pcs</span>
                            </div>
                        <?php endforeach; ?>
                        <div class="flex items-center justify-between py-0.5 font-bold text-emerald-950">
                            <span>Sesi #<?= $sessionId ?> (Sesi Berjalan):</span>
                            <span class="font-mono" id="modal-kb-current-sess-qty"><?= number_format($session['total_scanned_qty'] ?? 0) ?> pcs</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Group 2.5: Rincian Box Label Barcode & Multi-Lot Breakdown -->
            <div class="p-3 bg-indigo-50/70 border border-indigo-200 rounded-xl space-y-2 text-xs">
                <div class="text-[11px] font-bold text-indigo-900 border-b border-indigo-200/80 pb-1 flex items-center justify-between">
                    <span>📦 RINCIAN LABEL BOX & MULTI-LOT PRODUKSI (<span id="modal-kb-lots-count"><?= isset($sessionLots) ? count($sessionLots) : 0 ?></span> Box)</span>
                    <span class="text-[10px] font-mono text-indigo-800 font-extrabold" id="modal-kb-lots-total-qty"><?= $session ? number_format(($session['total_scanned_qty'] && (int)$session['total_scanned_qty'] > 0) ? $session['total_scanned_qty'] : ($session['kanban_qty'] ?? 0)) : '0' ?> pcs</span>
                </div>

                <!-- Aggregated Lot Summary Cards (Visible when multiple Lot Numbers exist) -->
                <div id="modal-kb-lot-summary-container" class="grid grid-cols-2 gap-1.5 <?= (isset($lotSummaryList) && count($lotSummaryList) > 1) ? '' : 'hidden' ?>">
                    <?php if (isset($lotSummaryList) && count($lotSummaryList) > 1): ?>
                        <?php foreach ($lotSummaryList as $ls): ?>
                            <div class="p-1.5 bg-white border border-indigo-200 rounded-lg flex items-center justify-between shadow-2xs">
                                <span class="font-mono font-bold text-slate-800 text-[10px]">#<?= htmlspecialchars($ls['lot_number']) ?></span>
                                <span class="font-mono font-extrabold text-blue-800 text-[10px]"><?= number_format($ls['total_qty']) ?> pcs (<?= $ls['label_count'] ?> Box)</span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Detailed Scanned Session Lots Table -->
                <div class="border border-indigo-200 rounded-lg overflow-hidden max-h-40 overflow-y-auto bg-white shadow-2xs">
                    <table class="w-full text-left text-[11px]">
                        <thead class="bg-indigo-100/70 text-indigo-900 font-bold border-b border-indigo-200 uppercase text-[9px]">
                            <tr>
                                <th class="px-2.5 py-1.5">No</th>
                                <th class="px-2.5 py-1.5">Ref No. Box</th>
                                <th class="px-2.5 py-1.5">Lot No.</th>
                                <th class="px-2.5 py-1.5 text-right">Qty Box</th>
                            </tr>
                        </thead>
                        <tbody id="modal-kb-session-lots-tbody" class="divide-y divide-indigo-50 text-slate-700">
                            <?php if (empty($sessionLots)): ?>
                                <tr>
                                    <td colspan="4" class="px-2.5 py-3 text-center text-slate-400 italic">Belum ada rincian label box yang tercatat.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($sessionLots as $idx => $lbl): ?>
                                    <?php 
                                    $isSs = (!empty($lbl['remarks']) && strpos($lbl['remarks'], 'Safety Stock') !== false) || (!empty($lbl['is_safety_stock']));
                                    ?>
                                    <tr class="hover:bg-indigo-50/50 transition-colors">
                                        <td class="px-2.5 py-1.5 font-bold text-slate-400"><?= $idx + 1 ?></td>
                                        <td class="px-2.5 py-1.5 font-mono font-bold text-blue-800 text-[10px]">
                                            <span class="bg-blue-50 px-1.5 py-0.5 rounded border border-blue-200"><?= htmlspecialchars($lbl['ref_number'] ?? '-') ?></span>
                                            <?php if ($isSs): ?>
                                                <span class="bg-purple-100 text-purple-900 border border-purple-300 text-[9px] font-black px-2 py-0.5 rounded-full inline-flex items-center ml-1" title="Lot dipenuhi dari Alokasi Safety Stock">
                                                    Safety Stock
                                                </span>
                                            <?php else: ?>
                                                <span class="bg-blue-100 text-blue-900 border border-blue-300 text-[9px] font-black px-2 py-0.5 rounded-full inline-flex items-center ml-1" title="Lot berasal dari scan label box">
                                                    Scan Label
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-2.5 py-1.5 font-mono font-bold text-slate-800"><?= htmlspecialchars($lbl['lot_number'] ?? $session['lot_number'] ?? '-') ?></td>
                                        <td class="px-2.5 py-1.5 font-mono font-extrabold text-slate-900 text-right"><?= number_format($lbl['qty'] ?? 0) ?> pcs</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Group 3: Jadwal & Lokasi -->
            <div class="p-3 bg-slate-50 border border-slate-200/80 rounded-xl space-y-2 text-xs">
                <div class="text-[11px] font-bold text-slate-800 border-b border-slate-200 pb-1">
                    🚚 JADWAL PENGIRIMAN & SPESIFIKASI LOKASI
                </div>
                <div class="grid grid-cols-2 gap-2 text-slate-700">
                    <div>
                        <span class="text-[10px] text-slate-400 font-bold block uppercase">Jadwal Tiba (ETA)</span>
                        <span id="modal-kb-eta" class="font-extrabold text-slate-900 text-xs block truncate">
                            <?= ($session && !empty($session['kanban_eta'])) ? date('d M Y, H:i', strtotime($session['kanban_eta'])) . ' WIB' : '-' ?>
                        </span>
                    </div>
                    <div>
                        <span class="text-[10px] text-slate-400 font-bold block uppercase">Req. Date</span>
                        <span id="modal-kb-req-date" class="font-extrabold text-slate-900 text-xs block truncate">
                            <?= ($session && !empty($session['kanban_req_date'])) ? date('d M Y, H:i', strtotime($session['kanban_req_date'])) . ' WIB' : '-' ?>
                        </span>
                    </div>
                    <div>
                        <span class="text-[10px] text-slate-400 font-bold block uppercase">Target Qty</span>
                        <span id="modal-kb-qty" class="font-black text-blue-700 text-xs block"><?= $session ? number_format($session['kanban_qty'] ?? 0) : '0' ?> pcs</span>
                    </div>
                    <div>
                        <span class="text-[10px] text-slate-400 font-bold block uppercase">Storage Location</span>
                        <span id="modal-kb-str-loc" class="font-mono font-bold text-slate-800 text-xs block"><?= ($session && !empty($session['kanban_str_loc'])) ? htmlspecialchars($session['kanban_str_loc']) : '-' ?></span>
                    </div>
                    <div>
                        <span class="text-[10px] text-slate-400 font-bold block uppercase">Status Cek</span>
                        <span id="modal-kb-check-type" class="font-extrabold text-amber-800 text-xs block"><?= ($session && !empty($session['kanban_check_type'])) ? htmlspecialchars($session['kanban_check_type']) . ' Cek' : 'Normal Cek' ?></span>
                    </div>
                    <div>
                        <span class="text-[10px] text-slate-400 font-bold block uppercase">Catatan (Remark)</span>
                        <span id="modal-kb-remark" class="font-semibold text-slate-600 text-xs block truncate"><?= ($session && !empty($session['kanban_remark'])) ? htmlspecialchars($session['kanban_remark']) : '-' ?></span>
                    </div>
                </div>

                <!-- Group 4: Rincian Penggantian Lot & Ref No (Jika Ada) -->
                <div id="modal-kb-substitution-container" class="hidden pt-3 border-t border-slate-200">
                    <span class="text-[11px] font-bold text-slate-800 uppercase block mb-1.5 flex items-center gap-1">
                        <span>🔄</span> LINIMASA PENGGANTIAN LOT &amp; REF NO
                    </span>
                    <div id="modal-kb-substitution-list" class="space-y-1.5"></div>
                </div>
            </div>

        </div>

        <div class="pt-2.5 border-t border-slate-100 text-right flex-shrink-0">
            <button type="button" onclick="closeKanbanDetailModal()" class="btn-secondary py-1.5 px-4 text-xs font-bold shadow-2xs hover:bg-slate-100 cursor-pointer">
                Tutup
            </button>
        </div>

    </div>
</div>

<!-- Modal Riwayat Inspeksi Sesi Terdahulu untuk Part Ini -->
<div id="prev-history-modal-overlay" style="position: fixed; inset: 0; background-color: rgba(15, 23, 42, 0.75); backdrop-filter: blur(4px); z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 16px;" class="hidden">
    <div style="background-color: #ffffff; border-radius: 20px; max-width: 620px; width: 100%; max-height: 88vh; display: flex; flex-direction: column; border: 1px solid #cbd5e1; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); margin: auto; overflow: hidden;" class="animate-fadeIn">
        
        <!-- Modal Header -->
        <div class="p-4 border-b border-slate-200 flex items-center justify-between bg-slate-50 flex-shrink-0">
            <div>
                <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">
                    <?= ($session && !empty($session['kanban_no'])) ? ('RIWAYAT INSPEKSI KANBAN NO: KB-' . htmlspecialchars($session['kanban_no'])) : 'RIWAYAT INSPEKSI SESI TERDAHULU' ?>
                </span>
                <h3 class="text-sm font-extrabold text-slate-900 flex items-center">
                    <span class="font-mono text-blue-700 mr-1.5"><?= $session ? htmlspecialchars($session['part_code']) : '' ?></span>
                    <span class="text-slate-600 text-xs font-semibold">(<?= $session ? htmlspecialchars($session['display_part_name'] ?? $session['part_name']) : '' ?>)</span>
                </h3>
            </div>
            <button type="button" onclick="closePrevHistoryModal()" class="text-slate-400 hover:text-slate-600 font-bold text-xl leading-none px-2">&times;</button>
        </div>

        <!-- Modal Body: Cards List of Previous Inspection Sessions (Strictly Scrollable Flex-1 Container) -->
        <div style="min-height: 0; flex: 1; max-height: 65vh; overflow-y: auto; -webkit-overflow-scrolling: touch;" class="p-4 space-y-3">
            <?php if (empty($previousSessions)): ?>
                <div class="text-center py-8 space-y-2">
                    <svg class="w-10 h-10 text-slate-300 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <p class="text-xs font-bold text-slate-500">
                        <?= ($session && !empty($session['kanban_no'])) ? ('Belum ada riwayat sesi inspeksi / re-inspeksi lain untuk Kanban No: KB-' . htmlspecialchars($session['kanban_no'])) : 'Belum ada riwayat sesi inspeksi lain.' ?>
                    </p>
                    <p class="text-[11px] text-slate-400">Sesi saat ini adalah sesi inspeksi pertama yang tercatat untuk item ini.</p>
                </div>
            <?php else: ?>
                <?php foreach ($previousSessions as $ps): 
                    $isReinspection = (!empty($ps['parent_session_id']) && $ps['parent_session_id'] > 0) || !empty($ps['reinspection_notes']);
                    $inspType = $ps['inspection_type'] ?? 'kanban';
                ?>
                    <div class="p-3 bg-slate-50 border border-slate-200 rounded-xl space-y-2 hover:border-slate-300 transition-colors">
                        <div class="flex items-center justify-between flex-wrap gap-1">
                            <div class="flex items-center space-x-1.5 flex-wrap gap-y-1">
                                <!-- Inspection Type Badge -->
                                <?php if ($isReinspection): ?>
                                    <span class="bg-amber-100 text-amber-900 border border-amber-300 text-[9.5px] font-black px-2 py-0.5 rounded-full inline-flex items-center">⚡ RE-INSPEKSI</span>
                                <?php elseif ($inspType === 'safety_stock'): ?>
                                    <span class="bg-purple-100 text-purple-900 border border-purple-300 text-[9.5px] font-black px-2 py-0.5 rounded-full inline-flex items-center">📦 SAFETY STOCK</span>
                                <?php else: ?>
                                    <span class="bg-blue-100 text-blue-900 border border-blue-300 text-[9.5px] font-black px-2 py-0.5 rounded-full inline-flex items-center">📋 KANBAN</span>
                                <?php endif; ?>

                                <span class="font-mono font-extrabold text-slate-900 text-xs">Lot: #<?= htmlspecialchars($ps['lot_number']) ?></span>
                                <?php if (!empty($ps['kanban_no'])): ?>
                                    <span class="text-[10px] text-slate-600 bg-slate-200/80 px-1.5 py-0.5 rounded font-mono font-bold">No. KB: <?= htmlspecialchars($ps['kanban_no']) ?></span>
                                <?php endif; ?>
                            </div>

                            <div>
                                <?php if ($ps['status'] === 'passed'): ?>
                                    <span class="badge badge-emerald text-[10px] font-extrabold">PASSED</span>
                                <?php elseif ($ps['status'] === 'rejected'): ?>
                                    <span class="badge badge-rose text-[10px] font-extrabold">REJECTED</span>
                                <?php else: ?>
                                    <span class="badge badge-blue text-[10px] font-extrabold">IN PROGRESS</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Defect Summary Pills -->
                        <div class="pt-1">
                            <?php if (!empty($ps['defects'])): ?>
                                <div class="flex items-center gap-1 flex-wrap">
                                    <span class="text-[10px] font-bold text-rose-800 mr-1">Temuan Defect:</span>
                                    <?php foreach ($ps['defects'] as $df): ?>
                                        <span class="bg-rose-100 text-rose-900 border border-rose-200 text-[9.5px] font-extrabold px-1.5 py-0.5 rounded">
                                            <?= htmlspecialchars($df['defect_name']) ?> ×<?= $df['total_qty_ng'] ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span class="text-[10px] font-extrabold text-emerald-700 bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded inline-block">✅ Zero Defect (Lolos Full)</span>
                            <?php endif; ?>
                        </div>

                        <!-- Time, Inspector, & Sampling Details -->
                        <div class="grid grid-cols-2 gap-2 text-[11px] text-slate-600 border-t border-slate-200/60 pt-2">
                            <div>
                                <span class="text-[10px] text-slate-400 block font-bold uppercase">Waktu & Inspector</span>
                                <span class="font-semibold text-slate-800 block"><?= date('d M Y H:i', strtotime($ps['started_at'])) ?> WIB</span>
                                <span class="text-[10px] text-slate-500 block">by <?= htmlspecialchars($ps['inspector_name'] ?? $ps['did_pic'] ?? 'Inspector QC') ?></span>
                            </div>
                            <div class="text-right">
                                <span class="text-[10px] text-slate-400 block font-bold uppercase">Hasil Sampling & Total Qty</span>
                                <span class="font-mono font-extrabold text-slate-900 block"><?= number_format($ps['total_scanned_qty'] ?? 0) ?> pcs <span class="text-slate-400 font-normal">| Sample:</span> <?= $ps['samples_checked'] ?>/<?= $ps['sample_size'] ?></span>
                                <span class="text-[10px] font-mono block <?= ($ps['ng_count'] > 0) ? 'text-rose-600 font-bold' : 'text-slate-500' ?>">
                                    <?= $ps['ng_count'] ?> NG (Limit Reject: <?= $ps['reject_number'] ?>)
                                </span>
                            </div>
                        </div>

                        <div class="pt-1.5 border-t border-slate-200/40 flex items-center justify-between">
                            <span class="text-[10px] text-slate-400 font-mono">Sesi #<?= $ps['id'] ?></span>
                            <a href="<?= base_url('modules/inspection/session.php?id=' . $ps['id']) ?>" class="text-[11px] text-blue-600 font-bold hover:underline inline-flex items-center">
                                Lihat Sesi Inspeksi Ini &rarr;
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Modal Footer -->
        <div class="p-3 bg-slate-100 border-t border-slate-200 text-right flex-shrink-0">
            <button type="button" onclick="closePrevHistoryModal()" class="btn-secondary py-1 px-4 text-xs font-bold">
                Tutup
            </button>
        </div>

    </div>
</div>

<!-- Modal Rincian Penggantian Lot & Ref No -->
<div id="substitution-log-modal-overlay" style="position: fixed; inset: 0; background-color: rgba(15, 23, 42, 0.75); backdrop-filter: blur(4px); z-index: 99999; display: flex; align-items: center; justify-content: center; padding: 16px;" class="hidden">
    <div style="background-color: #ffffff; border-radius: 20px; max-width: 550px; width: 100%; max-height: 85vh; padding: 20px; border: 1px solid #cbd5e1; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); margin: auto; display: flex; flex-direction: column; overflow: hidden;" class="animate-fadeIn space-y-3">
        
        <!-- Modal Header -->
        <div class="flex items-center justify-between border-b border-slate-200 pb-2.5 flex-shrink-0">
            <div class="flex items-center space-x-2.5">
                <div class="w-8 h-8 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center font-bold text-base shadow-2xs">
                    🔄
                </div>
                <div>
                    <h3 class="text-sm font-extrabold text-slate-900 leading-tight">Rincian Penggantian Lot &amp; Ref No</h3>
                    <p class="text-[11px] text-slate-500 font-medium">Histori pemetaan lot pengganti untuk sesi ini</p>
                </div>
            </div>
            <button type="button" onclick="closeSubstitutionLogModal()" class="w-8 h-8 rounded-lg hover:bg-slate-100 text-slate-400 hover:text-slate-600 font-bold text-xl flex items-center justify-center transition-all cursor-pointer">&times;</button>
        </div>

        <!-- Modal Body Content -->
        <div id="modal-subst-log-list-body" style="display: flex; flex-direction: column; gap: 8px; max-height: 55vh; overflow-y: auto; padding-right: 2px;" class="flex-1 min-h-0">
            <!-- Populated via JS -->
        </div>

        <!-- Modal Footer -->
        <div class="pt-2 border-t border-slate-100 flex justify-end flex-shrink-0">
            <button type="button" onclick="closeSubstitutionLogModal()" class="px-4 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs rounded-lg transition-all cursor-pointer">
                Tutup
            </button>
        </div>
    </div>
</div>

<!-- Modal Dialog Batch Re-Inspeksi Kanban (REJECTED) -->
<div id="batch-reinspection-modal-overlay" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; width: 100vw; height: 100vh; background-color: rgba(15, 23, 42, 0.85); backdrop-filter: blur(6px); z-index: 999999; display: flex; align-items: center; justify-content: center; padding: 16px; margin: 0; box-sizing: border-box;" class="hidden">
    <div style="background-color: #ffffff; border-radius: 20px; max-width: 650px; width: 100%; max-height: 92vh; display: flex; flex-direction: column; border: 1px solid #cbd5e1; box-shadow: 0 25px 60px -12px rgba(0, 0, 0, 0.4); margin: auto; overflow: hidden; position: relative; z-index: 1000000;" class="animate-fadeIn">
        
        <!-- Modal Header -->
        <div style="background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 60%, #881337 100%); color: #ffffff; padding: 16px 20px; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; border-bottom: 1px solid rgba(244, 63, 94, 0.3);">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 38px; height: 38px; border-radius: 12px; background-color: rgba(225, 29, 72, 0.25); border: 1.5px solid rgba(244, 63, 94, 0.4); display: flex; align-items: center; justify-content: center; color: #fb7185; flex-shrink: 0;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg>
                </div>
                <div>
                    <h3 style="font-size: 15px; font-weight: 800; letter-spacing: 0.03em; text-transform: uppercase; color: #ffffff; margin: 0; line-height: 1.2;">
                        TANGANI RE-INSPEKSI KANBAN
                    </h3>
                    <p style="font-size: 11px; color: #cbd5e1; margin: 2px 0 0 0; font-weight: 500;">Pilih Strategi Re-Inspeksi &amp; Scope Tindakan Kanban REJECTED</p>
                </div>
            </div>
            <button type="button" onclick="closeBatchReinspectionModal()" style="background: none; border: none; color: #94a3b8; font-size: 24px; font-weight: 700; cursor: pointer; padding: 0 4px; line-height: 1; transition: color 0.15s;" onmouseover="this.style.color='#ffffff'" onmouseout="this.style.color='#94a3b8'">
                &times;
            </button>
        </div>

        <!-- Modal Body -->
        <div style="padding: 18px; overflow-y: auto; flex: 1; background-color: #f8fafc; display: flex; flex-direction: column; gap: 14px;" class="custom-scrollbar">

            <!-- Section 0: Informasi Part & Planning (Header Summary Banner) -->
            <div style="background-color: #ffffff; border: 1.5px solid #e2e8f0; border-radius: 14px; padding: 12px 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.03); display: flex; flex-direction: column; gap: 8px;">
                <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; border-bottom: 1px solid #f1f5f9; padding-bottom: 8px;">
                    <div style="min-width: 0; flex: 1;">
                        <span style="font-size: 9.5px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; display: block;">PART CODE &amp; PART NAME</span>
                        <div style="display: flex; align-items: center; gap: 6px; margin-top: 2px; flex-wrap: wrap;">
                            <span id="batch-modal-part-code" style="font-family: monospace; font-size: 13px; font-weight: 900; color: #0f172a; background-color: #f1f5f9; padding: 2px 8px; border-radius: 6px; border: 1px solid #cbd5e1;">
                                <?= $session ? htmlspecialchars($session['part_code']) : '-' ?>
                            </span>
                            <span id="batch-modal-part-name" style="font-size: 12.5px; font-weight: 800; color: #1e293b; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                <?= $session ? htmlspecialchars($session['part_name']) : '-' ?>
                            </span>
                        </div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 8px; flex-shrink: 0;">
                        <div style="text-align: right;">
                            <span style="font-size: 9.5px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; display: block;">JENIS INSPEKSI</span>
                            <span id="batch-modal-type-badge">
                                <?php if ($session && ($session['inspection_type'] ?? 'kanban') === 'safety_stock'): ?>
                                    <span style="display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 6px; font-size: 10px; font-weight: 800; background-color: #f3e8ff; color: #7e22ce; border: 1px solid #d8b4fe;">
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>
                                        SAFETY STOCK
                                    </span>
                                <?php else: ?>
                                    <span style="display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 6px; font-size: 10px; font-weight: 800; background-color: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe;">
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                                        KANBAN
                                    </span>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                </div>

                <div style="display: flex; align-items: center; justify-content: space-between; font-size: 11px; gap: 10px; flex-wrap: wrap;">
                    <div style="display: flex; align-items: center; gap: 4px;">
                        <span style="color: #64748b; font-weight: 600;">No. Kanban: </span>
                        <span id="batch-modal-kanban-no" style="font-family: monospace; font-weight: 800; color: #0f172a; background-color: #f8fafc; padding: 1px 6px; border-radius: 4px; border: 1px solid #e2e8f0;">
                            <?= $session ? htmlspecialchars($session['kanban_no'] ?: ($session['doc_no'] ?: '-')) : '-' ?>
                        </span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 4px;">
                        <span style="color: #64748b; font-weight: 600;">ETA: </span>
                        <?php
                        $etaDisplay = '-';
                        $etaVal = $session['kanban_eta'] ?? ($session['eta'] ?? ($session['kanban_req_date'] ?? ''));
                        if (!empty($etaVal) && $etaVal !== '-' && $etaVal !== '0000-00-00 00:00:00') {
                            $time = strtotime($etaVal);
                            if ($time) {
                                $monthsId = [1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'Mei',6=>'Jun',7=>'Jul',8=>'Agu',9=>'Sep',10=>'Okt',11=>'Nov',12=>'Des'];
                                $m = $monthsId[(int)date('n', $time)] ?? date('M', $time);
                                $etaDisplay = date('d', $time) . ' ' . $m . ' ' . date('Y, H:i', $time) . ' WIB';
                            } else {
                                $etaDisplay = htmlspecialchars($etaVal);
                            }
                        }
                        ?>
                        <span id="batch-modal-eta" style="font-weight: 800; color: #b45309; background-color: #fffbeb; border: 1px solid #fde68a; padding: 1px 6px; border-radius: 4px; display: inline-flex; align-items: center; gap: 4px;">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            <span><?= $etaDisplay ?></span>
                        </span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 4px;">
                        <span style="color: #64748b; font-weight: 600;">Customer: </span>
                        <span id="batch-modal-customer" style="font-weight: 800; color: #1e40af;">
                            <?= $session ? htmlspecialchars($session['customer'] ?? 'PT. Indonesia Epson Industry') : '-' ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Section 1: Ringkasan Lot NG Terdampak & Semua Lot Kanban -->
            <div style="background-color: #f8fafc; border: 1.5px solid #cbd5e1; border-radius: 14px; padding: 12px; display: flex; flex-direction: column; gap: 8px;">
                <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">
                    <span style="font-size: 11px; font-weight: 800; color: #1e293b; text-transform: uppercase; letter-spacing: 0.04em; display: flex; align-items: center; gap: 5px;">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>
                        <span>DAFTAR LOT KANBAN SESI INI</span>
                    </span>
                    <!-- Tab Switcher / Segmented Control -->
                    <div style="display: flex; gap: 4px; background: #e2e8f0; padding: 2px; border-radius: 8px;">
                        <button type="button" id="btn-batch-tab-ng" onclick="renderBatchLotList('ng')" style="font-size: 10px; font-weight: 700; padding: 3px 8px; border-radius: 6px; border: none; cursor: pointer; transition: all 0.15s; background-color: #ffffff; color: #be123c; box-shadow: 0 1px 2px rgba(0,0,0,0.05); display: inline-flex; align-items: center; gap: 4px;">
                            <span style="width: 6px; height: 6px; border-radius: 50%; background-color: #e11d48; display: inline-block;"></span>
                            <span>Lot NG (<span id="batch-modal-ng-count">0</span>)</span>
                        </button>
                        <button type="button" id="btn-batch-tab-all" onclick="renderBatchLotList('all')" style="font-size: 10px; font-weight: 700; padding: 3px 8px; border-radius: 6px; border: none; cursor: pointer; transition: all 0.15s; background-color: transparent; color: #64748b; display: inline-flex; align-items: center; gap: 4px;">
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>
                            <span>Semua Lot (<span id="batch-modal-all-count">0</span>)</span>
                        </button>
                    </div>
                </div>
                <div id="batch-modal-lot-list" style="display: flex; flex-direction: column; gap: 6px; max-height: 125px; overflow-y: auto; padding-right: 2px;" class="slim-scrollbar">
                    <!-- Populated dynamically by JS renderBatchLotList() -->
                </div>
            </div>

            <!-- Section 2: Action Buttons Strategi Utama -->
            <div style="display: flex; flex-direction: column; gap: 8px;">
                <label style="font-size: 11px; font-weight: 800; color: #334155; text-transform: uppercase; letter-spacing: 0.05em; display: block;">
                    Pilih Strategi Re-Inspeksi:
                </label>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <!-- Card Button Strategi 1: Scan Ulang -->
                    <div id="card-btn-rescan" onclick="selectBatchStrategy('rescan')" style="text-align: left; cursor: pointer; border: 2px solid #f59e0b; background-color: #fffbeb; border-radius: 14px; padding: 14px; display: flex; flex-direction: column; justify-content: space-between; transition: all 0.2s; box-shadow: 0 4px 12px rgba(245,158,11,0.12);">
                        <div>
                            <span style="font-size: 12px; font-weight: 900; color: #78350f; display: flex; align-items: center; gap: 5px; line-height: 1.3;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg>
                                <span>INSPEKSI ULANG</span>
                            </span>
                            <span style="font-size: 10px; font-weight: 700; color: #b45309; display: block; margin-top: 2px;">Gunakan Lot Existing</span>
                        </div>

                        <!-- Sub Action Buttons for Rescan -->
                        <div id="sub-rescan-buttons" style="margin-top: 12px; padding-top: 10px; border-top: 1px solid #fde68a; display: flex; flex-direction: column; gap: 6px;">
                            <button type="button" id="btn-sub-rescan-restart" onclick="selectSubAction('rescan_restart', event)" style="padding: 6px 10px; font-size: 11px; font-weight: 700; border-radius: 8px; border: 1.5px solid #d97706; background-color: #d97706; color: #ffffff; cursor: pointer; text-align: left; transition: all 0.15s; display: flex; align-items: center; gap: 6px;">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                <span>Ulang dari Awal (Reset ke Sample #1)</span>
                            </button>
                            <button type="button" id="btn-sub-rescan-continue" onclick="selectSubAction('rescan_continue', event)" style="padding: 6px 10px; font-size: 11px; font-weight: 600; border-radius: 8px; border: 1.5px solid #fcd34d; background-color: #ffffff; color: #92400e; cursor: pointer; text-align: left; transition: all 0.15s; display: flex; align-items: center; gap: 6px;">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polygon points="13 19 22 12 13 5 13 19"/><polygon points="2 19 11 12 2 5 2 19"/></svg>
                                <span>Lanjut Inspeksi (Sisa Sample)</span>
                            </button>
                        </div>
                    </div>

                    <!-- Card Button Strategi 2: Ganti Lot -->
                    <div id="card-btn-replace" onclick="selectBatchStrategy('replace')" style="text-align: left; cursor: pointer; border: 2px solid #cbd5e1; background-color: #ffffff; border-radius: 14px; padding: 14px; display: flex; flex-direction: column; justify-content: space-between; transition: all 0.2s; opacity: 0.65;">
                        <div>
                            <span style="font-size: 12px; font-weight: 900; color: #0f172a; display: flex; align-items: center; gap: 5px; line-height: 1.3;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M16 3h5v5M4 20L21 3M21 16v5h-5M15 15l6 6M4 4l5 5"/></svg>
                                <span>GANTI LOT BARU</span>
                            </span>
                            <span style="font-size: 10px; font-weight: 600; color: #64748b; display: block; margin-top: 2px;">Scan QR Code Lot Pengganti Gudang</span>
                        </div>

                        <!-- Sub Action Buttons for Replace -->
                        <div id="sub-replace-buttons" style="margin-top: 12px; padding-top: 10px; border-top: 1px solid #e2e8f0; display: flex; flex-direction: column; gap: 6px; opacity: 0.5; pointer-events: none;">
                            <button type="button" id="btn-sub-replace-ng" onclick="selectSubAction('replace_ng_only', event)" style="padding: 6px 10px; font-size: 11px; font-weight: 700; border-radius: 8px; border: 1.5px solid #e11d48; background-color: #e11d48; color: #ffffff; cursor: pointer; text-align: left; transition: all 0.15s; display: flex; align-items: center; gap: 6px;">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                                <span>Ganti Lot yang NG Saja</span>
                            </button>
                            <button type="button" id="btn-sub-replace-all" onclick="selectSubAction('replace_all_lots', event)" style="padding: 6px 10px; font-size: 11px; font-weight: 600; border-radius: 8px; border: 1.5px solid #cbd5e1; background-color: #ffffff; color: #475569; cursor: pointer; text-align: left; transition: all 0.15s; display: flex; align-items: center; gap: 6px;">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>
                                <span>Ganti Semua Lot</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section 3: Interface Step 2 QR Scanner Input (Untuk Ganti Lot) -->
            <div id="batch-replacement-form-container" class="hidden" style="background-color: #ffffff; border: 1.5px solid #3b82f6; border-radius: 16px; padding: 16px; display: flex; flex-direction: column; gap: 14px; box-shadow: 0 4px 16px rgba(59,130,246,0.08);">
                
                <!-- Header & Step Pills -->
                <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px;">
                    <div>
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                            <span style="background-color: #dcfce7; color: #166534; font-size: 10px; font-weight: 800; padding: 2px 8px; border-radius: 9999px; border: 1px solid #bbf7d0;">✓ Step 1 Complete</span>
                            <span style="background-color: #2563eb; color: #ffffff; font-size: 10px; font-weight: 800; padding: 2px 10px; border-radius: 9999px;">2. Scan Label QR</span>
                        </div>
                        <h4 style="font-size: 14px; font-weight: 800; color: #0f172a; margin: 0;">Step 2: Scan QR Code Label Barcode</h4>
                        <p style="font-size: 11px; color: #64748b; margin: 2px 0 0 0;">Scan barcode label barang yang mau diuji</p>
                    </div>
                </div>

                <!-- Main QR Barcode Scan Field -->
                <div style="background-color: #f0f9ff; border: 1.5px dashed #3b82f6; border-radius: 12px; padding: 12px;">
                    <label style="font-size: 11px; font-weight: 800; color: #1e40af; display: flex; align-items: center; gap: 6px; margin-bottom: 6px;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><rect x="7" y="7" width="10" height="10" rx="1"/></svg>
                        <span>Scan Barcode / QR Code Label</span>
                    </label>
                    <input id="batch-qr-scan-input" type="text" oninput="handleSingleBatchQrInput(this, false)" onkeydown="if(event.key==='Enter'){event.preventDefault();handleSingleBatchQrInput(this, true);}" placeholder="Tempel atau Scan Barcode / QR Code Label di sini..." style="width: 100%; padding: 10px 14px; font-family: monospace; font-size: 12px; font-weight: 700; border: 2px solid #3b82f6; border-radius: 10px; outline: none; background-color: #ffffff; box-shadow: inset 0 2px 4px rgba(0,0,0,0.03);" autofocus>
                    <span style="font-size: 10px; color: #64748b; margin-top: 4px; display: block;">Sistem membaca Ref No, Lot No, Qty. Scan bertahap hingga target Qty terpenuhi.</span>
                </div>

                <!-- Detail Manual Input Fields -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px;">
                    <div>
                        <label style="font-size: 10px; font-weight: 700; color: #475569; display: block; margin-bottom: 2px;">Part Code <span style="color: #e11d48;">*</span></label>
                        <input id="batch-input-z1" type="text" value="<?= $session ? htmlspecialchars($session['part_code']) : '' ?>" placeholder="Part Code..." style="width: 100%; padding: 7px 10px; font-family: monospace; font-size: 11px; border: 1px solid #cbd5e1; border-radius: 8px; outline: none; background-color: #f1f5f9; font-weight: 700; color: #1e293b;">
                    </div>
                    <div>
                        <label style="font-size: 10px; font-weight: 700; color: #475569; display: block; margin-bottom: 2px;">Lot Number <span style="color: #e11d48;">*</span></label>
                        <input id="batch-input-z2" type="text" placeholder="Lot Number..." style="width: 100%; padding: 7px 10px; font-family: monospace; font-size: 11px; border: 1px solid #cbd5e1; border-radius: 8px; outline: none; background-color: #ffffff;">
                    </div>
                    <div>
                        <label style="font-size: 10px; font-weight: 700; color: #475569; display: block; margin-bottom: 2px;">Qty Box <span style="color: #e11d48;">*</span></label>
                        <input id="batch-input-z3" type="number" placeholder="Qty..." style="width: 100%; padding: 7px 10px; font-family: monospace; font-size: 11px; border: 1px solid #cbd5e1; border-radius: 8px; outline: none; background-color: #ffffff;">
                    </div>
                    <div>
                        <label style="font-size: 10px; font-weight: 700; color: #475569; display: block; margin-bottom: 2px;">Ref Number <span style="color: #e11d48;">*</span></label>
                        <input id="batch-input-z5" type="text" placeholder="Ref Number..." style="width: 100%; padding: 7px 10px; font-family: monospace; font-size: 11px; border: 1px solid #cbd5e1; border-radius: 8px; outline: none; background-color: #ffffff;">
                    </div>
                    <div style="grid-column: span 2;">
                        <button type="button" onclick="addManualBatchLabel()" style="padding: 7px 14px; background-color: #ffffff; border: 1.5px solid #cbd5e1; color: #334155; border-radius: 8px; font-size: 11px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; transition: all 0.15s;" onmouseover="this.style.backgroundColor='#f1f5f9'" onmouseout="this.style.backgroundColor='#ffffff'">
                            + Tambah Label Manual
                        </button>
                    </div>
                </div>

                <!-- Table Scanned Labels List -->
                <div>
                    <label style="font-size: 11px; font-weight: 800; color: #334155; display: block; margin-bottom: 6px;">
                        Daftar Label / Box yang Discan (<span id="batch-scanned-count">0</span> Label):
                    </label>
                    <div style="border: 1px solid #cbd5e1; border-radius: 10px; overflow: hidden; background-color: #ffffff;">
                        <table style="width: 100%; border-collapse: collapse; font-size: 11px;">
                            <thead>
                                <tr style="background-color: #f1f5f9; color: #475569; font-weight: 800; text-transform: uppercase; border-bottom: 1px solid #cbd5e1;">
                                    <th style="padding: 6px 10px; text-align: left; width: 35px;">NO</th>
                                    <th style="padding: 6px 10px; text-align: left;">REF NO</th>
                                    <th style="padding: 6px 10px; text-align: left;">PEMETAAN LOT PENGGANTIAN</th>
                                    <th style="padding: 6px 10px; text-align: center;">QTY</th>
                                    <th style="padding: 6px 10px; text-align: center; width: 55px;">AKSI</th>
                                </tr>
                            </thead>
                            <tbody id="batch-scanned-table-body">
                                <tr>
                                    <td colspan="5" style="padding: 16px; text-align: center; color: #94a3b8;">
                                        Belum ada label QR yang discan. Silakan scan barcode label di atas.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

            <!-- Section 4: Catatan / Alasan (Opsional) -->
            <div>
                <label style="font-size: 11px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">Catatan Re-Inspeksi (Opsional):</label>
                <input id="batch-reinsp-notes" type="text" placeholder="Contoh: Re-inspect setelah sortir / lot diganti baru dari gudang..." style="width: 100%; padding: 9px 12px; font-size: 12px; border: 1.5px solid #cbd5e1; border-radius: 10px; outline: none; font-family: inherit; box-sizing: border-box;" onfocus="this.style.borderColor='#2563eb'" onblur="this.style.borderColor='#cbd5e1'">
            </div>

        </div>

        <!-- Modal Footer -->
        <div style="padding: 14px 18px; background-color: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0;">
            <button type="button" onclick="closeBatchReinspectionModal()" style="padding: 9px 20px; background-color: #ffffff; border: 1.5px solid #cbd5e1; color: #475569; border-radius: 10px; font-size: 12px; font-weight: 700; cursor: pointer; transition: all 0.15s;" onmouseover="this.style.backgroundColor='#f1f5f9'" onmouseout="this.style.backgroundColor='#ffffff'">
                Batal
            </button>
            <button id="btn-submit-batch-reinsp" type="button" onclick="submitBatchReinspection()" style="padding: 10px 24px; background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color: #ffffff; border: none; border-radius: 10px; font-size: 12px; font-weight: 800; cursor: pointer; box-shadow: 0 4px 14px rgba(37,99,235,0.35); display: flex; align-items: center; gap: 6px; transition: all 0.15s;" onmouseover="this.style.opacity='0.95'" onmouseout="this.style.opacity='1'">
                <span>Validasi Matching &amp; Muat Inspeksi</span>
            </button>
        </div>

    </div>
</div>

<!-- Modal Tambah Box Baru ke Sesi Aktif (Multi-Scan & Auto-Focus) -->
<div id="modal-add-lot-to-session" style="position: fixed; inset: 0; background-color: rgba(15, 23, 42, 0.75); backdrop-filter: blur(4px); z-index: 999999; display: none; align-items: center; justify-content: center; padding: 16px;">
    <div style="background-color: #ffffff; border-radius: 18px; max-width: 820px; width: 100%; max-height: 90vh; display: flex; flex-direction: column; border: 1.5px solid #cbd5e1; box-shadow: 0 25px 60px -12px rgba(0, 0, 0, 0.35); overflow: hidden; position: relative;" class="animate-fadeIn">
        
        <!-- Modal Header -->
        <div style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: #ffffff; padding: 12px 18px; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; border-bottom: 1px solid #334155;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <div style="width: 32px; height: 32px; border-radius: 8px; background-color: rgba(37, 99, 235, 0.25); border: 1.5px solid rgba(59, 130, 246, 0.4); display: flex; align-items: center; justify-content: center; color: #60a5fa; flex-shrink: 0;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                </div>
                <div>
                    <h3 style="font-size: 13.5px; font-weight: 800; letter-spacing: 0.02em; color: #ffffff; margin: 0; line-height: 1.2;">
                        Tambah Box Baru ke Sesi
                    </h3>
                    <p style="font-size: 10.5px; color: #94a3b8; margin: 2px 0 0 0; font-weight: 500;">Scan QR barcode secara berurutan untuk mendaftarkan banyak box sekaligus</p>
                </div>
            </div>
            <button type="button" onclick="closeAddLotModal()" style="background: none; border: none; color: #94a3b8; font-size: 22px; font-weight: 700; cursor: pointer; padding: 0 4px; line-height: 1; transition: color 0.15s;" onmouseover="this.style.color='#ffffff'" onmouseout="this.style.color='#94a3b8'">
                &times;
            </button>
        </div>

        <!-- Modal Body -->
        <div style="padding: 16px; overflow-y: auto; flex: 1; background-color: #f8fafc; display: flex; flex-direction: column; gap: 12px;" class="custom-scrollbar">
            
            <!-- Input Scan Barcode Utama -->
            <div style="background-color: #eff6ff; border: 1.5px solid #bfdbfe; border-radius: 12px; padding: 10px 14px;">
                <label style="display: flex; align-items: center; justify-content: space-between; font-size: 11px; font-weight: 800; color: #1e40af; margin-bottom: 6px;">
                    <span style="display: inline-flex; align-items: center; gap: 5px;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/></svg>
                        Scan Barcode / QR Code Label
                    </span>
                    <span style="font-size: 9.5px; color: #2563eb; background-color: #dbeafe; padding: 1px 6px; border-radius: 4px; font-weight: 700;">Auto-Parsing</span>
                </label>
                <input type="text" id="add-lot-qr-raw" placeholder="Tempel atau Scan Barcode / QR Code (Z1|Z2|Z3|Z4|Z5)..." 
                       oninput="handleModalAddLotQrInput(this)" onkeydown="handleModalAddLotQrKeydown(event, this)"
                       style="width: 100%; height: 38px; padding: 6px 12px; font-size: 12px; font-family: monospace; font-weight: 700; background-color: #ffffff; border: 2px solid #3b82f6; border-radius: 8px; outline: none; box-sizing: border-box; box-shadow: inset 0 1px 2px rgba(0,0,0,0.04); transition: all 0.15s;"
                       autocomplete="off">
                <p style="font-size: 9.5px; color: #64748b; margin: 4px 0 0 0;">Scanner langsung mengisi dan menambahkan box ke antrean. Anda dapat menembak scanner berulang kali.</p>
            </div>

            <!-- Manual Input Section -->
            <div style="background-color: #ffffff; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 12px;">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                    <span style="font-size: 10.5px; font-weight: 800; color: #334155; text-transform: uppercase; letter-spacing: 0.03em;">Input Manual Tambahan:</span>
                    <button type="button" onclick="addManualLotToPending()" style="padding: 4px 10px; background-color: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; border-radius: 6px; font-size: 10.5px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;">
                        + Masukkan ke Daftar Box
                    </button>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 8px;">
                    <div>
                        <label style="display: block; font-size: 9.5px; font-weight: 800; color: #64748b; margin-bottom: 2px; text-transform: uppercase;">
                            Part Code <span style="font-weight: 600;">(Terkunci)</span>
                        </label>
                        <input type="text" id="add-lot-part-code" readonly 
                               style="width: 100%; height: 32px; padding: 4px 8px; font-size: 11px; font-family: monospace; font-weight: 800; background-color: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; border-radius: 6px; outline: none; box-sizing: border-box;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 9.5px; font-weight: 800; color: #0f172a; margin-bottom: 2px; text-transform: uppercase;">
                            Lot Number <span style="color: #e11d48;">*</span>
                        </label>
                        <input type="text" id="add-lot-number" placeholder="Contoh: 06426817" 
                               style="width: 100%; height: 32px; padding: 4px 8px; font-size: 11px; font-family: monospace; font-weight: 800; text-transform: uppercase; background-color: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; outline: none; box-sizing: border-box;"
                               autocomplete="off">
                    </div>
                    <div>
                        <label style="display: block; font-size: 9.5px; font-weight: 800; color: #0f172a; margin-bottom: 2px; text-transform: uppercase;">
                            Qty Box (Pcs) <span style="color: #e11d48;">*</span>
                        </label>
                        <div style="display: flex; align-items: center; height: 32px; border: 1px solid #cbd5e1; border-radius: 6px; overflow: hidden; background: #ffffff; box-sizing: border-box;">
                            <input type="number" id="add-lot-qty" min="1" placeholder="200" 
                                   style="width: 100%; height: 100%; padding: 0 8px; border: none; font-size: 11px; font-family: monospace; font-weight: 800; color: #0f172a; outline: none; box-sizing: border-box;"
                                   autocomplete="off">
                            <span style="padding: 0 6px; font-size: 9.5px; font-weight: 700; color: #64748b; background-color: #f8fafc; border-left: 1px solid #e2e8f0; height: 100%; display: flex; align-items: center;">pcs</span>
                        </div>
                    </div>
                    <div>
                        <label style="display: block; font-size: 9.5px; font-weight: 800; color: #1e40af; margin-bottom: 2px; text-transform: uppercase;">
                            Ref Number <span style="color: #64748b; font-weight: 600;">(Unik)</span>
                        </label>
                        <input type="text" id="add-lot-ref-number" placeholder="Contoh: KSKA" 
                               style="width: 100%; height: 32px; padding: 4px 8px; font-size: 11px; font-family: monospace; font-weight: 800; text-transform: uppercase; background-color: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; border-radius: 6px; outline: none; box-sizing: border-box;"
                               autocomplete="off">
                    </div>
                </div>
            </div>

            <!-- Tabel Daftar Box yang Akan Ditambahkan -->
            <div style="background-color: #ffffff; border: 1.5px solid #cbd5e1; border-radius: 12px; overflow: hidden;">
                <div style="padding: 8px 12px; background-color: #f1f5f9; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between;">
                    <span style="font-size: 11px; font-weight: 800; color: #334155;">
                        Daftar Box yang Akan Didaftarkan (<span id="add-lot-scanned-count">0</span> Box - Total <span id="add-lot-scanned-qty">0</span> pcs)
                    </span>
                    <button type="button" onclick="pendingAddLots=[]; renderPendingAddLotsTable();" style="background: none; border: none; font-size: 10px; font-weight: 700; color: #be123c; cursor: pointer; text-decoration: underline;">
                        Reset Antrean
                    </button>
                </div>
                <div style="max-height: 180px; overflow-y: auto;">
                    <table style="width: 100%; border-collapse: collapse; text-align: left;">
                        <thead style="background-color: #f8fafc; border-bottom: 1px solid #e2e8f0; font-size: 9.5px; font-weight: 800; color: #64748b; text-transform: uppercase;">
                            <tr>
                                <th style="padding: 6px 10px; width: 40px;">No</th>
                                <th style="padding: 6px 10px;">Ref Number</th>
                                <th style="padding: 6px 10px;">Lot Number</th>
                                <th style="padding: 6px 10px; text-align: right;">Qty Box</th>
                                <th style="padding: 6px 10px; text-align: center; width: 60px;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="add-lot-table-body">
                            <tr>
                                <td colspan="5" style="padding: 20px; text-align: center; color: #94a3b8; font-size: 11px;">
                                    Belum ada box yang ditambahkan. Silakan scan barcode QR atau masukkan manual di atas.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Informational Banner -->
            <div style="background-color: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 10px; padding: 7px 10px; display: flex; align-items: flex-start; gap: 7px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="color: #059669; flex-shrink: 0; margin-top: 1px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                <span style="font-size: 10px; color: #065f46; font-weight: 600; line-height: 1.4;">
                    Standar sampling AQL dan batas penolakan (Reject Number) untuk tiap box akan dihitung otomatis sesuai dengan Qty yang didaftarkan.
                </span>
            </div>

        </div>

        <!-- Modal Footer -->
        <div style="padding: 10px 16px; background-color: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0;">
            <button type="button" onclick="closeAddLotModal()" style="padding: 8px 16px; background-color: #ffffff; border: 1.5px solid #cbd5e1; color: #475569; border-radius: 8px; font-size: 11px; font-weight: 700; cursor: pointer; transition: all 0.15s;" onmouseover="this.style.backgroundColor='#f1f5f9'" onmouseout="this.style.backgroundColor='#ffffff'">
                Batal
            </button>
            <button type="button" id="btn-submit-add-lot" onclick="executeAddLotSubmit()" style="padding: 8px 20px; background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color: #ffffff; border: none; border-radius: 8px; font-size: 11px; font-weight: 800; cursor: pointer; box-shadow: 0 2px 6px rgba(37,99,235,0.3); display: flex; align-items: center; gap: 5px; transition: all 0.15s;" onmouseover="this.style.opacity='0.95'" onmouseout="this.style.opacity='1'">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                <span>Simpan &amp; Daftarkan (<span id="add-lot-btn-count">0</span>) Box</span>
            </button>
        </div>

    </div>
</div>

<script>
var currentSessionId = <?= $sessionId ?: 0 ?>;
var currentSessionPartCode = '<?= htmlspecialchars($session['part_code'] ?? '', ENT_QUOTES) ?>';
var autoKanbanId = <?= (int)($_GET['kanban_id'] ?? 0) ?>;
var currentZoom = 1.0;
var DEFECT_TYPES_LIST = <?= json_encode($defectTypes ?? []) ?>;

<?php if ($showScanOverlay): ?>
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(function() {
        openScanModal();
    }, 100);
});
<?php endif; ?>

// Shortcut F2 listener to open scan modal & F11 for Fullscreen
window.addEventListener('keydown', function(e) {
    if (e.key === 'F11') {
        e.preventDefault();
        toggleNativeFullscreen();
    }
    if (e.key === 'F2') {
        e.preventDefault();
        openScanModal();
    }
});



var scannedLabelsList = [];

/**
 * QR Code Barcode Format Parser (formatqr.md)
 * Format: Z1inicodePart|Z2LotPart|Z3Qty|Z4line|Z5ref_number
 */
function parseBarcodeQR(code) {
    let obj = {};
    if (!code) return obj;
    let parts = code.split('|');
    parts.forEach(function(p) {
        p = p.trim();
        if (p.startsWith('Z1')) obj.Z1 = p.substring(2);
        if (p.startsWith('Z2')) obj.Z2 = p.substring(2);
        if (p.startsWith('Z3')) {
            let valZ3 = parseInt(p.substring(2), 10);
            if (!isNaN(valZ3) && valZ3 > 0) {
                obj.Z3 = valZ3;
            }
        }
        if (p.startsWith('Z4')) obj.Z4 = p.substring(2);
        if (p.startsWith('Z5')) obj.Z5 = p.substring(2);
    });
    return obj;
}

function closeSwalSafely() {
    try {
        if (typeof Swal !== 'undefined') {
            if (typeof Swal.close === 'function') Swal.close();
            else if (typeof Swal.closePopup === 'function') Swal.closePopup();
            else if (typeof Swal.closeModal === 'function') Swal.closeModal();
            else if (typeof Swal.clickConfirm === 'function') Swal.clickConfirm();
        }
        if (typeof swal !== 'undefined' && typeof swal.close === 'function') {
            swal.close();
        }
    } catch (e) {}
    if (typeof window.refocusActiveScanner === 'function') {
        setTimeout(window.refocusActiveScanner, 60);
    }
}

function setLotFromModal(lotNum) {
    if (lotNum) {
        document.getElementById('scan-lot-number').value = lotNum;
        closeSwalSafely();
    }
}

function handleScanInputKeydown(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        e.stopPropagation();
        var val = e.target.value.trim();
        if (val) {
            parseBarcodeQRInput(val, true);
        }
        return false;
    }
}

function parseBarcodeQRInput(val, forceProcess) {
    if (!val) return;
    var parsed = parseBarcodeQR(val);

    // 1. EARLY PART CODE MISMATCH CHECK:
    // Jika planning sudah dipilih dan barcode yang discan memiliki Z1 berbeda, jangan biarkan input form tertimpa!
    if (parsed.Z1 && selectedPlanningPartCode && parsed.Z1.toUpperCase() !== selectedPlanningPartCode.toUpperCase()) {
        if (forceProcess || val.includes('\n') || val.includes('\r')) {
            Swal.fire({
                icon: 'error',
                title: '⚠️ PART CODE TIDAK COCOK!',
                html: '<div style="font-size: 12px; text-align: left; line-height: 1.6; color: #334155;">' +
                      'Part Code pada label (<b style="color:#be123c;">"' + escapeHtml(parsed.Z1) + '"</b>) tidak cocok dengan Part Code Kanban yang sedang diinspeksi (<b style="color:#0284c7;">"' + escapeHtml(selectedPlanningPartCode) + '"</b>)!<br><br>' +
                      '<b style="color:#e11d48;">Pastikan Anda men-scan label box yang sesuai dengan Kanban ini!</b>' +
                      '</div>'
            });
            // Rollback & bersihkan field form seketika
            if (document.getElementById('scan-part-code')) document.getElementById('scan-part-code').value = selectedPlanningPartCode;
            if (document.getElementById('scan-lot-number')) document.getElementById('scan-lot-number').value = '';
            if (document.getElementById('scan-label-qty')) document.getElementById('scan-label-qty').value = '';
            if (document.getElementById('scan-ref-number')) document.getElementById('scan-ref-number').value = '';
            if (document.getElementById('scan-remarks')) document.getElementById('scan-remarks').value = '';
            if (document.getElementById('scan-qr-raw')) document.getElementById('scan-qr-raw').value = '';
            setTimeout(function() {
                var rawInp = document.getElementById('scan-qr-raw');
                if (rawInp) rawInp.focus();
            }, 100);
        }
        return;
    }

    var effectivePartCode = parsed.Z1 || (document.getElementById('scan-part-code') ? document.getElementById('scan-part-code').value.trim() : '') || selectedPlanningPartCode;

    // Live update form fields for visual feedback without triggering DID check
    if (parsed.Z1 && document.getElementById('scan-part-code')) document.getElementById('scan-part-code').value = parsed.Z1;
    if (parsed.Z2 && document.getElementById('scan-lot-number')) document.getElementById('scan-lot-number').value = parsed.Z2;
    if (parsed.Z3 && parsed.Z3 > 0 && document.getElementById('scan-label-qty')) document.getElementById('scan-label-qty').value = parsed.Z3;
    if (parsed.Z4 && document.getElementById('scan-remarks')) document.getElementById('scan-remarks').value = parsed.Z4;
    if (parsed.Z5 && document.getElementById('scan-ref-number')) document.getElementById('scan-ref-number').value = parsed.Z5;

    // HANYA SUBMIT jika user/scanner menekan ENTER (forceProcess === true) atau terdapat Newline (\n / \r) dari hardware scanner
    var hasNewline = (val.includes('\n') || val.includes('\r'));

    if (forceProcess || hasNewline) {
        var lotToUse = parsed.Z2 || (document.getElementById('scan-lot-number') ? document.getElementById('scan-lot-number').value.trim() : '') || val.toUpperCase();
        var rawInputQty = document.getElementById('scan-label-qty') ? parseInt(document.getElementById('scan-label-qty').value) : 0;
        var qtyToUse = parsed.Z3 || (rawInputQty > 0 ? rawInputQty : 0);
        var remToUse = parsed.Z4 || (document.getElementById('scan-remarks') ? document.getElementById('scan-remarks').value.trim() : '');
        var refToUse = parsed.Z5 || (document.getElementById('scan-ref-number') ? document.getElementById('scan-ref-number').value.trim() : '');

        if (!qtyToUse || qtyToUse <= 0) {
            Swal.fire({ icon: 'warning', title: 'Qty Box Wajib Diisi', text: 'Qty Box tidak terbaca dari barcode dan belum diisi di form. Silakan isi field Qty Box secara manual!' });
            return;
        }

        if (!refToUse) {
            Swal.fire({ icon: 'warning', title: 'Ref Number Wajib Diisi', text: 'Ref Number tidak terbaca dari barcode. Silakan isi field Ref Number secara manual sebelum menambah label.' });
            return;
        }

        if (!effectivePartCode || !lotToUse) return;

        addScannedLabelFromObj({
            Z1: effectivePartCode,
            Z2: lotToUse,
            Z3: qtyToUse,
            Z4: remToUse,
            Z5: refToUse,
            raw: val
        });
        if (document.getElementById('scan-qr-raw')) document.getElementById('scan-qr-raw').value = '';
    }
}

function handleManualAddLabel() {
    var pCode = document.getElementById('scan-part-code').value.trim();
    var lNum = document.getElementById('scan-lot-number').value.trim();
    var qty = parseInt(document.getElementById('scan-label-qty').value) || 0;
    var rem = document.getElementById('scan-remarks').value.trim();
    var ref = document.getElementById('scan-ref-number').value.trim();

    if (!qty || qty <= 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Qty Box Wajib Diisi',
            text: 'Qty Box wajib diisi dan harus lebih besar dari 0!'
        });
        return;
    }

    if (!ref) {
        Swal.fire({ icon: 'warning', title: 'Ref Number Wajib Diisi', text: 'Silakan isi Ref Number terlebih dahulu. Ref Number harus diisi manual oleh user.' });
        return;
    }

    if (!pCode || !lNum) {
        Swal.fire({
            icon: 'warning',
            title: 'Input Tidak Lengkap',
            text: 'Part Code dan Lot Number wajib diisi!'
        });
        return;
    }

    addScannedLabelFromObj({
        Z1: pCode,
        Z2: lNum,
        Z3: qty,
        Z4: rem,
        Z5: ref,
        raw: 'MANUAL|Z1' + pCode + '|Z2' + lNum + '|Z3' + qty
    });

    document.getElementById('scan-lot-number').value = '';
    document.getElementById('scan-label-qty').value = '';
    document.getElementById('scan-ref-number').value = '';
    document.getElementById('scan-remarks').value = '';
}

function addScannedLabelFromObj(lbl) {
    // 1. Check Part Code matching with selected Planning / Active Session
    if (!selectedPlanningPartCode && lbl.Z1) {
        selectedPlanningPartCode = lbl.Z1;
        document.getElementById('selected-plan-part-code').textContent = lbl.Z1;
        document.getElementById('scan-part-code').value = lbl.Z1;
    } else if (selectedPlanningPartCode && lbl.Z1.toUpperCase() !== selectedPlanningPartCode.toUpperCase()) {
        Swal.fire({
            icon: 'error',
            title: '⚠️ PART CODE TIDAK COCOK!',
            html: '<div style="font-size: 12px; text-align: left; line-height: 1.6; color: #334155;">' +
                  'Part Code pada label (<b style="color:#be123c;">"' + escapeHtml(lbl.Z1) + '"</b>) tidak cocok dengan Part Code yang sedang diinspeksi (<b style="color:#0284c7;">"' + escapeHtml(selectedPlanningPartCode) + '"</b>)!' +
                  '</div>'
        });
        // Rollback & bersihkan field form
        if (document.getElementById('scan-part-code')) document.getElementById('scan-part-code').value = selectedPlanningPartCode;
        if (document.getElementById('scan-lot-number')) document.getElementById('scan-lot-number').value = '';
        if (document.getElementById('scan-label-qty')) document.getElementById('scan-label-qty').value = '';
        if (document.getElementById('scan-ref-number')) document.getElementById('scan-ref-number').value = '';
        if (document.getElementById('scan-remarks')) document.getElementById('scan-remarks').value = '';
        if (document.getElementById('scan-qr-raw')) document.getElementById('scan-qr-raw').value = '';
        return;
    }

    // 2. Check duplicate ref_number (Z5 - unique label identifier)
    var isDuplicateRef = scannedLabelsList.some(function(item) {
        return item.Z5 && lbl.Z5 && item.Z5.toUpperCase() === lbl.Z5.toUpperCase();
    });

    if (isDuplicateRef) {
        Swal.fire({
            icon: 'warning',
            title: 'Label Sudah Discan!',
            text: 'Label QR dengan Ref Number Z5 (' + lbl.Z5 + ') sudah discan sebelumnya!'
        });
        return;
    }

    // 3. Real-Time Validation: DID, Safety Stock, In-Progress Lock, Already Passed, and Rejected Warning
    Swal.fire({
        title: 'Memeriksa Status Label...',
        text: 'Memverifikasi status label Ref #' + (lbl.Z5 || lbl.Z2) + '...',
        allowOutsideClick: false,
        didOpen: function() { Swal.showLoading(); }
    });

    var checkUrl = '<?= base_url("modules/inspection/scan_validate.php") ?>?mode=check_did_only&part_code=' + encodeURIComponent(lbl.Z1) + 
                   '&lot_number=' + encodeURIComponent(lbl.Z2) + 
                   '&ref_number=' + encodeURIComponent(lbl.Z5 || '') + 
                   '&inspection_type=' + encodeURIComponent(selectedPlanningType);

    fetch(checkUrl)
        .then(function(res) { return res.json(); })
        .then(function(data) {
            closeSwalSafely();
            if (!data.success) {
                // Skenario 2: Duplikasi Data / Label Sudah PASSED di Sesi Lain
                if (data.error_type === 'already_passed_duplicate') {
                    Swal.fire({
                        icon: 'error',
                        title: '⛔ DUPLIKASI DATA / LABEL SUDAH PASSED!',
                        html: '<div style="font-size: 12px; text-align: left; line-height: 1.6; color: #334155;">' +
                              '<b style="color: #be123c;">' + escapeHtml(data.message || 'Label ini sudah pernah digunakan dan PASSED sebelumnya!') + '</b><br><br>' +
                              'Label tidak dapat digunakan kembali demi menjaga integritas data OQC.' +
                              '</div>',
                        confirmButtonColor: '#ef4444'
                    });
                    clearScanFormInputs();
                    return;
                }

                // Skenario 4: Sedang In-Progress di Sesi Lain
                if (data.error_type === 'in_progress_duplicate') {
                    Swal.fire({
                        icon: 'error',
                        title: '⚠️ LABEL SEDANG DALAM PROSES INSPEKSI!',
                        html: '<div style="font-size: 12px; text-align: left; line-height: 1.6; color: #334155;">' +
                              '<b style="color: #be123c;">' + escapeHtml(data.message || 'Label sedang aktif diinspeksi di sesi lain!') + '</b><br><br>' +
                              'Label tidak dapat discan bersamaan pada dua sesi berbeda.' +
                              '</div>',
                        confirmButtonColor: '#ef4444'
                    });
                    clearScanFormInputs();
                    return;
                }

                // Error DID Missing atau DID NG
                Swal.fire({
                    icon: 'error',
                    title: '⚠️ VALIDASI LABEL GAGAL!',
                    html: '<div style="font-size: 12px; text-align: left; line-height: 1.6; color: #334155;">' +
                          '<b style="color: #be123c;">' + escapeHtml(data.message || 'Lot Number belum lolos cek dimensi!') + '</b><br><br>' +
                          'Part Code: <b>' + escapeHtml(lbl.Z1) + '</b><br>' +
                          'Lot Number yang Di-scan/Di-input: <b style="font-family: monospace; color: #1d4ed8;">' + escapeHtml(lbl.Z2) + '</b>' +
                          '</div>'
                });
                clearScanFormInputs();
                return;
            }

            // Skenario 1: Label Terdeteksi Sebagai Safety Stock
            if (data.is_safety_stock && data.ss_lot) {
                selectSafetyStockLotFromScan(data.ss_lot);
                return;
            }

            // Skenario 3: Label Sebelumnya Pernah REJECTED di Safety Stock atau Kanban Lain
            if (data.was_rejected_before && data.rejected_info) {
                var info = data.rejected_info;
                var originText = (info.inspection_type === 'safety_stock') ? 'Safety Stock Gudang' : ('Sesi Kanban #' + info.session_id + (info.kanban_no && info.kanban_no !== '-' ? ' (' + info.kanban_no + ')' : ''));
                Swal.fire({
                    icon: 'warning',
                    title: '⚠️ PERINGATAN: LABEL PERNAH REJECTED!',
                    html: '<div style="font-size: 12px; text-align: left; line-height: 1.6; color: #334155;">' +
                          'Label Box Ref <b style="color: #be123c; font-family: monospace;">' + escapeHtml(lbl.Z5 || lbl.Z2) + '</b> ' +
                          '(Lot: <b>' + escapeHtml(lbl.Z2) + '</b>) sebelumnya tercatat ber-status <b style="color: #be123c;">REJECTED (NG)</b> ' +
                          'pada <b>' + escapeHtml(originText) + '</b> oleh QC <b>' + escapeHtml(info.inspector_name) + '</b> (' + escapeHtml(info.closed_at) + ').<br><br>' +
                          (info.remarks && info.remarks !== '-' ? '<div style="background-color:#fee2e2; border:1px solid #fca5a5; padding:6px 10px; border-radius:6px; margin-bottom:8px; font-size:11px; color:#991b1b;">Catatan NG: ' + escapeHtml(info.remarks) + '</div>' : '') +
                          '<div style="background-color: #fffbeb; border: 1px solid #fde68a; padding: 10px 14px; border-radius: 8px; color: #92400e; font-weight: 700;">' +
                          'Apakah barang ini sudah diperbaiki dan dipastikan benar untuk diinspeksi kembali?' +
                          '</div>' +
                          '</div>',
                    showCancelButton: true,
                    confirmButtonColor: '#10b981',
                    cancelButtonColor: '#ef4444',
                    confirmButtonText: '✅ Ya, Sudah Benar (Terima)',
                    cancelButtonText: '❌ Belum / Batalkan (Tolak)',
                    allowOutsideClick: false
                }).then(function(result) {
                    if (result.isConfirmed) {
                        proceedAddLabelToList(lbl);
                    } else {
                        clearScanFormInputs();
                    }
                });
                return;
            }

            // Normal Box: Langsung tambahkan ke list
            proceedAddLabelToList(lbl);
        })
        .catch(function(err) {
            console.error('Validation error:', err);
            closeSwalSafely();
            Swal.fire({
                icon: 'error',
                title: 'Gagal Memeriksa Status Label',
                text: 'Terjadi kesalahan sistem saat memverifikasi status label: ' + (err.message || 'Error koneksi server')
            });
        });
}

function proceedAddLabelToList(lbl) {
    scannedLabelsList.push(lbl);

    if (typeof Swal !== 'undefined' && typeof Swal.fire === 'function') {
        Swal.fire({
            icon: 'success',
            title: '✓ Label Terverifikasi',
            text: 'Label Lot #' + lbl.Z2 + (lbl.Z5 ? ' (Ref: ' + lbl.Z5 + ')' : '') + ' (' + lbl.Z3 + ' pcs) berhasil ditambahkan!',
            timer: 1500,
            showConfirmButton: false
        });
    }

    if (document.getElementById('scan-part-code')) document.getElementById('scan-part-code').value = lbl.Z1;
    if (document.getElementById('scan-lot-number')) document.getElementById('scan-lot-number').value = lbl.Z2;

    clearScanFormInputs();
    renderScannedLabelsTable();
}

function clearScanFormInputs() {
    if (document.getElementById('scan-qr-raw')) document.getElementById('scan-qr-raw').value = '';
    if (document.getElementById('scan-lot-number')) document.getElementById('scan-lot-number').value = '';
    if (document.getElementById('scan-label-qty')) document.getElementById('scan-label-qty').value = '';
    if (document.getElementById('scan-ref-number')) document.getElementById('scan-ref-number').value = '';
    if (document.getElementById('scan-remarks')) document.getElementById('scan-remarks').value = '';
    setTimeout(function() {
        var rawInp = document.getElementById('scan-qr-raw');
        if (rawInp) rawInp.focus();
    }, 80);
}

function selectSafetyStockLotFromScan(ssLot) {
    if (!ssLot) return;
    var lotIdStr = String(ssLot.lot_id);
    var sId = parseInt(ssLot.session_id);

    // Pastikan lot ini ada di availableSsLots agar checkbox bisa aktif
    var existingLot = null;
    if (availableSsLots && availableSsLots.length > 0) {
        for (var i = 0; i < availableSsLots.length; i++) {
            if (String(availableSsLots[i].lot_id) === lotIdStr || (availableSsLots[i].ref_number && ssLot.ref_number && availableSsLots[i].ref_number.toUpperCase() === ssLot.ref_number.toUpperCase())) {
                existingLot = availableSsLots[i];
                break;
            }
        }
    }
    if (!existingLot) {
        if (!availableSsLots) availableSsLots = [];
        availableSsLots.push(ssLot);
    }

    // Cek apakah sudah diceklis sebelumnya
    if (selectedSsLotIds.indexOf(lotIdStr) !== -1) {
        Swal.fire({
            icon: 'info',
            title: 'Label Safety Stock Sudah Terpilih',
            text: 'Label Safety Stock Lot #' + ssLot.lot_number + (ssLot.ref_number && ssLot.ref_number !== '-' ? ' (Ref: ' + ssLot.ref_number + ')' : '') + ' sudah diceklis sebelumnya!'
        });
        clearScanFormInputs();
        return;
    }

    // Ceklis lot
    selectedSsLotIds.push(lotIdStr);
    if (sId && selectedSsSessionIds.indexOf(sId) === -1) {
        selectedSsSessionIds.push(sId);
    }
    isSafetyStockDeducted = true;
    updateSafetyStockDeductionUI();

    // Buka accordion/panel Safety Stock jika tersembunyi
    var ssContainer = document.getElementById('safety-stock-accordion-body');
    if (ssContainer && ssContainer.classList.contains('hidden')) {
        ssContainer.classList.remove('hidden');
    }

    // Animasi flash highlight pada item lot
    setTimeout(function() {
        var itemEl = document.getElementById('ss-lot-item-' + lotIdStr);
        if (itemEl) {
            itemEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            itemEl.style.transition = 'all 0.3s';
            itemEl.style.backgroundColor = '#bbf7d0';
            itemEl.style.borderColor = '#059669';
            setTimeout(function() {
                itemEl.style.backgroundColor = '';
                itemEl.style.borderColor = '';
            }, 1500);
        }
    }, 120);

    var refText = (ssLot.ref_number && ssLot.ref_number !== '-') ? ssLot.ref_number : ssLot.lot_number;
    Swal.fire({
        icon: 'info',
        title: '📦 Label Terdeteksi Safety Stock!',
        html: '<div style="font-size: 12px; text-align: left; line-height: 1.6; color: #334155;">' +
              'Label Box Ref <b style="color: #1d4ed8; font-family: monospace;">' + escapeHtml(refText) + '</b> ' +
              '(Lot: <b>' + escapeHtml(ssLot.lot_number) + '</b>, Qty: <b>' + (ssLot.available_qty || 0).toLocaleString() + ' pcs</b>) ' +
              'merupakan persediaan <b>Safety Stock Gudang</b>.<br><br>' +
              '<div style="background-color: #eff6ff; border: 1px solid #bfdbfe; padding: 10px 14px; border-radius: 8px; color: #1e40af; font-weight: 700;">' +
              '✓ Otomatis dialokasikan ke pilihan Safety Stock (tidak dijadikan box fisik baru).' +
              '</div>' +
              '</div>',
        confirmButtonColor: '#2563eb'
    });
    clearScanFormInputs();
}

function editScannedLabelQty(index) {
    if (index < 0 || index >= scannedLabelsList.length) return;
    var lbl = scannedLabelsList[index];
    var currentQty = parseInt(lbl.Z3) || 0;
    var refNo = lbl.Z5 || '-';
    var lotNo = lbl.Z2 || '-';

    Swal.fire({
        title: '✏️ Edit Qty Box Riil',
        html: '<div style="font-size: 12px; text-align: left; line-height: 1.6; color: #334155; margin-bottom: 12px;">' +
              'Label Ref: <b style="color: #1d4ed8; font-family: monospace;">' + escapeHtml(refNo) + '</b><br>' +
              'Lot Number: <b>' + escapeHtml(lotNo) + '</b><br>' +
              'Qty Label Barcode: <b>' + currentQty.toLocaleString() + ' pcs</b>' +
              '</div>' +
              '<div style="text-align: left;">' +
              '<label style="font-size: 11px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">Masukkan Qty Fisik Riil (pcs):</label>' +
              '<input type="number" id="swal-edit-qty-input" min="1" step="1" value="' + currentQty + '" style="width: 100%; padding: 8px 12px; font-size: 14px; font-weight: 700; font-family: monospace; border: 2px solid #3b82f6; border-radius: 8px; outline: none; box-sizing: border-box;" autofocus>' +
              '<p style="font-size: 10px; color: #64748b; margin-top: 4px;">Ubah jika isi barang fisik di dalam box berbeda dengan qty pada label barcode.</p>' +
              '</div>',
        showCancelButton: true,
        confirmButtonColor: '#2563eb',
        cancelButtonColor: '#64748b',
        confirmButtonText: '💾 Simpan Perubahan',
        cancelButtonText: 'Batal',
        didOpen: function() {
            var inp = document.getElementById('swal-edit-qty-input');
            if (inp) {
                inp.focus();
                inp.select();
                inp.addEventListener('keydown', function(ev) {
                    if (ev.key === 'Enter') {
                        ev.preventDefault();
                        Swal.clickConfirm();
                    }
                });
            }
        },
        preConfirm: function() {
            var inp = document.getElementById('swal-edit-qty-input');
            var val = parseInt(inp ? inp.value : 0);
            if (!val || val <= 0) {
                Swal.showValidationMessage('Qty harus berupa angka bulat dan lebih besar dari 0!');
                return false;
            }
            return val;
        }
    }).then(function(res) {
        if (res.isConfirmed && res.value > 0) {
            var newQty = res.value;
            scannedLabelsList[index].Z3 = newQty;
            renderScannedLabelsTable();

            if (typeof Swal !== 'undefined' && Swal.mixin) {
                var Toast = Swal.mixin({
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 1500,
                    timerProgressBar: true
                });
                Toast.fire({
                    icon: 'success',
                    title: '✓ Qty Box Ref #' + refNo + ' diperbarui jadi ' + newQty.toLocaleString() + ' pcs'
                });
            }
        }
    });
}

function removeScannedLabel(index) {
    if (index >= 0 && index < scannedLabelsList.length) {
        scannedLabelsList.splice(index, 1);
        renderScannedLabelsTable();
    }
}

function clearAllScannedLabels() {
    scannedLabelsList = [];
    renderScannedLabelsTable();
}

function renderScannedLabelsTable() {
    var tbody = document.getElementById('scanned-labels-table-body');
    var countSpan = document.getElementById('scanned-labels-count');
    var totalQtySpan = document.getElementById('scanned-total-qty');
    var targetQtySpan = document.getElementById('target-kanban-qty-display');
    var progressBar = document.getElementById('scan-progress-bar');
    var btnSubmit = document.getElementById('btn-submit-scan-modal');
    var btnClear = document.getElementById('btn-clear-labels');
    var excessMsg = document.getElementById('scan-excess-msg');
    var statusMsg = document.getElementById('scan-status-msg');

    var isSafetyStock = (selectedPlanningType === 'safety_stock');
    var physicalScanned = 0;
    scannedLabelsList.forEach(function(l) { physicalScanned += parseInt(l.Z3) || 0; });

    // Calculate Safety Stock Qty selected
    var usedSsQty = 0;
    if (!isSafetyStock && isSafetyStockDeducted && availableSsLots && availableSsLots.length > 0 && selectedSsLotIds && selectedSsLotIds.length > 0) {
        availableSsLots.forEach(function(lot) {
            if (selectedSsLotIds.indexOf(String(lot.lot_id)) !== -1) {
                usedSsQty += parseInt(lot.available_qty) || 0;
            }
        });
        usedSsQty = Math.min(selectedPlanningQty, usedSsQty);
    }

    var effectiveTotalScanned = physicalScanned + usedSsQty;
    var targetQty = isSafetyStock ? (physicalScanned > 0 ? physicalScanned : 0) : (selectedPlanningQty || 0);

    if (!tbody) return;

    if (scannedLabelsList.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="px-2.5 py-4 text-center text-slate-400 italic">Belum ada label QR fisik yang discan.' + (usedSsQty > 0 ? ' (Menggunakan ' + usedSsQty.toLocaleString() + ' pcs Safety Stock)' : '') + '</td></tr>';
    } else {
        var html = '';
        scannedLabelsList.forEach(function(lbl, idx) {
            var refDisp = lbl.Z5 ? escapeHtml(lbl.Z5) : '-';
            var lotDisp = escapeHtml(lbl.Z2);
            var qtyDisp = (parseInt(lbl.Z3) || 0).toLocaleString();

            html += '<tr class="hover:bg-slate-50 transition-colors">' +
                        '<td class="px-2.5 py-1.5 font-bold text-slate-400">' + (idx + 1) + '</td>' +
                        '<td class="px-2.5 py-1.5 font-mono font-bold text-blue-800 text-[10px]">' + refDisp + '</td>' +
                        '<td class="px-2.5 py-1.5 font-mono font-bold text-slate-800">' + lotDisp + '</td>' +
                        '<td class="px-2.5 py-1.5 font-mono font-extrabold text-slate-900 text-right">' + qtyDisp + ' pcs</td>' +
                        '<td class="px-2.5 py-1.5 text-center">' +
                            '<div class="flex items-center justify-center space-x-1">' +
                                '<button type="button" onclick="editScannedLabelQty(' + idx + ')" class="text-blue-600 hover:text-blue-800 p-1 rounded hover:bg-blue-50 transition-colors" title="Edit Qty Box">' +
                                    '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>' +
                                '</button>' +
                                '<button type="button" onclick="removeScannedLabel(' + idx + ')" class="text-rose-600 hover:text-rose-800 font-bold px-1.5 py-0.5 rounded hover:bg-rose-50 transition-colors text-xs leading-none" title="Hapus Label Ini">&times;</button>' +
                            '</div>' +
                        '</td>' +
                    '</tr>';
        });
        tbody.innerHTML = html;
    }

    if (countSpan) countSpan.textContent = scannedLabelsList.length;

    if (totalQtySpan) {
        if (usedSsQty > 0) {
            totalQtySpan.innerHTML = effectiveTotalScanned.toLocaleString() + ' <span class="text-[10px] font-normal text-blue-600">(' + (physicalScanned > 0 ? physicalScanned.toLocaleString() + ' Fisik + ' : '') + usedSsQty.toLocaleString() + ' SS)</span>';
        } else {
            totalQtySpan.textContent = effectiveTotalScanned.toLocaleString();
        }
    }

    if (targetQtySpan) targetQtySpan.textContent = isSafetyStock ? (physicalScanned.toLocaleString() + ' pcs (Lot Gudang)') : targetQty.toLocaleString();

    var pct = isSafetyStock ? 100 : Math.min(100, Math.round((effectiveTotalScanned / targetQty) * 100));
    if (progressBar) {
        progressBar.style.width = pct + '%';
        if (effectiveTotalScanned >= targetQty || isSafetyStock) {
            progressBar.style.backgroundColor = isSafetyStock ? '#7c3aed' : '#10b981';
        } else {
            progressBar.style.backgroundColor = '#2563eb';
        }
    }

    var excessQty = isSafetyStock ? 0 : Math.max(0, effectiveTotalScanned - targetQty);
    if (excessMsg) {
        if (excessQty > 0 && !isSafetyStock) {
            excessMsg.textContent = '✨ Sisa ' + excessQty.toLocaleString() + ' pcs akan otomatis menjadi Safety Stock';
            excessMsg.classList.remove('hidden');
        } else {
            excessMsg.classList.add('hidden');
        }
    }

    if (statusMsg) {
        if (isSafetyStock) {
            statusMsg.textContent = '✓ Total Akumulasi: ' + physicalScanned.toLocaleString() + ' pcs (' + scannedLabelsList.length + ' Label Box). Siap diproses!';
            statusMsg.className = 'text-purple-700 font-extrabold';
        } else if (effectiveTotalScanned >= targetQty) {
            var detailText = (usedSsQty > 0 ? (' (' + (physicalScanned > 0 ? physicalScanned.toLocaleString() + ' Hasil Scan Label + ' : '') + usedSsQty.toLocaleString() + ' Alokasi Safety Stock)') : '');
            statusMsg.textContent = '✓ Target Quantity Terpenuhi' + detailText + ' (' + effectiveTotalScanned.toLocaleString() + ' / ' + targetQty.toLocaleString() + ' pcs). Siap diproses.';
            statusMsg.className = 'text-emerald-600 font-extrabold';
        } else if (effectiveTotalScanned > 0) {
            var detailText = usedSsQty > 0 ? (' (' + physicalScanned.toLocaleString() + ' Hasil Scan Label + ' + usedSsQty.toLocaleString() + ' Alokasi Safety Stock)') : '';
            statusMsg.textContent = '⚡ Sesi Inspeksi Parsial: ' + effectiveTotalScanned.toLocaleString() + ' pcs' + detailText + ' (Target Total: ' + targetQty.toLocaleString() + ' pcs, Sisa: ' + (targetQty - effectiveTotalScanned).toLocaleString() + ' pcs).';
            statusMsg.className = 'text-blue-700 font-bold';
        } else {
            statusMsg.textContent = 'Perlu ' + targetQty.toLocaleString() + ' pcs untuk memenuhi target.';
            statusMsg.className = 'text-slate-500 font-medium';
        }
    }

    if (btnSubmit) {
        if (isSafetyStock) {
            if (physicalScanned > 0) {
                btnSubmit.disabled = false;
                btnSubmit.style.opacity = '1';
                btnSubmit.innerHTML = '📦 MULAI INSPEKSI SAFETY STOCK (' + scannedLabelsList.length + ' Label / ' + physicalScanned.toLocaleString() + ' pcs) &rarr;';
            } else {
                btnSubmit.disabled = true;
                btnSubmit.style.opacity = '0.6';
                btnSubmit.innerHTML = '📦 Mulai Inspeksi Safety Stock';
            }
        } else {
            var labelCountInfo = scannedLabelsList.length > 0 ? (scannedLabelsList.length + ' Label Box') : '';
            var ssCountInfo = usedSsQty > 0 ? (usedSsQty.toLocaleString() + ' pcs Safety Stock') : '';
            var combinedInfo = [ssCountInfo, labelCountInfo].filter(Boolean).join(' + ');

            if (effectiveTotalScanned >= targetQty) {
                btnSubmit.disabled = false;
                btnSubmit.style.opacity = '1';
                btnSubmit.innerHTML = '🚀 MULAI INSPEKSI PENUH (' + (combinedInfo ? combinedInfo + ' / ' : '') + effectiveTotalScanned.toLocaleString() + ' pcs) &rarr;';
            } else if (effectiveTotalScanned > 0) {
                btnSubmit.disabled = false;
                btnSubmit.style.opacity = '1';
                btnSubmit.innerHTML = '🚀 MULAI INSPEKSI PARSIAL (' + (combinedInfo ? combinedInfo + ' / ' : '') + effectiveTotalScanned.toLocaleString() + ' pcs dari ' + targetQty.toLocaleString() + ' pcs) &rarr;';
            } else {
                btnSubmit.disabled = true;
                btnSubmit.style.opacity = '0.6';
                btnSubmit.innerHTML = '🚀 Mulai Inspeksi OQC';
            }
        }
    }

    if (btnClear) btnClear.style.display = (scannedLabelsList.length > 0) ? 'inline' : 'none';
}

function toggleNativeFullscreen() {
    if (!document.fullscreenElement && !document.webkitFullscreenElement && !document.msFullscreenElement) {
        var el = document.documentElement;
        if (el.requestFullscreen) {
            el.requestFullscreen().catch(function(e){});
        } else if (el.webkitRequestFullscreen) {
            el.webkitRequestFullscreen();
        } else if (el.msRequestFullscreen) {
            el.msRequestFullscreen();
        }
    } else {
        if (document.exitFullscreen) {
            document.exitFullscreen().catch(function(e){});
        } else if (document.webkitExitFullscreen) {
            document.webkitExitFullscreen();
        } else if (document.msExitFullscreen) {
            document.msExitFullscreen();
        }
    }
}

function resetScanModalState() {
    // 1. Always return wizard UI to Step 1: Pilih Planning
    if (typeof backToStep1 === 'function') backToStep1();

    // 2. Clear scanned labels list and state
    scannedLabelsList = [];
    selectedPlanningId = 0;
    selectedPlanningType = 'kanban';
    selectedPlanningPartCode = '';
    selectedPlanningQty = 0;
    selectedAvailableSsQty = 0;
    isSafetyStockDeducted = false;
    availableSsLots = [];
    selectedSsSessionIds = [];
    selectedSsLotIds = [];

    // 3. Reset form input fields
    if (document.getElementById('scan-part-code')) document.getElementById('scan-part-code').value = '';
    if (document.getElementById('scan-lot-number')) document.getElementById('scan-lot-number').value = '';
    if (document.getElementById('scan-qty-box')) document.getElementById('scan-qty-box').value = '';
    if (document.getElementById('scan-ref-number')) document.getElementById('scan-ref-number').value = '';
    if (document.getElementById('scan-qr-raw')) document.getElementById('scan-qr-raw').value = '';
    if (typeof clearSsLotSearch === 'function') clearSsLotSearch();

    if (document.getElementById('selected-plan-part-code')) document.getElementById('selected-plan-part-code').textContent = 'AUTOMATIC FIFO MATCHING';
    if (document.getElementById('selected-plan-desc')) document.getElementById('selected-plan-desc').textContent = 'Sistem otomatis mencocokkan planning berdasarkan Part Code';
    if (document.getElementById('selected-plan-cust')) document.getElementById('selected-plan-cust').textContent = '-';
    if (document.getElementById('selected-plan-qty')) document.getElementById('selected-plan-qty').textContent = '-';
    if (document.getElementById('selected-plan-tag')) document.getElementById('selected-plan-tag').classList.add('hidden');

    // 4. Update UI tables & cards
    setPlanningTypeTab('kanban');

    if (typeof renderScannedLabelsTable === 'function') renderScannedLabelsTable();
    if (typeof updateSafetyStockDeductionUI === 'function') updateSafetyStockDeductionUI();
}

function refreshPlanningItemsAjax(autoSelectId) {
    var url = '<?= base_url("modules/inspection/session.php?action=get_planning_items") ?>';
    if (autoSelectId) {
        url += '&selected_id=' + encodeURIComponent(autoSelectId);
    }
    fetch(url)
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res && res.success && Array.isArray(res.items)) {
                renderPlanningCardsList(res.items);
                if (autoSelectId) {
                    var targetPlan = res.items.find(function(p) { return parseInt(p.id) === parseInt(autoSelectId); });
                    if (targetPlan) {
                        var targetTotalQty = parseInt(targetPlan.qty) || 0;
                        var totalPassedQty = parseInt(targetPlan.total_passed_qty) || 0;
                        var remainingQty = Math.max(0, targetTotalQty - totalPassedQty);
                        var isSafetyStock = (targetPlan.plan_type === 'safety_stock' || (targetPlan.batch_plan_type || '') === 'safety_stock' || (targetPlan.kanban_no || '').toUpperCase().indexOf('SS') === 0);
                        var planTypeAttr = isSafetyStock ? 'safety_stock' : 'kanban';
                        selectPlanningItem(
                            parseInt(targetPlan.id),
                            targetPlan.item_code,
                            targetPlan.item_description,
                            targetPlan.customer || 'PT. Indonesia Epson Industry',
                            remainingQty,
                            targetPlan.check_type || '',
                            planTypeAttr,
                            parseInt(targetPlan.avail_ss_qty) || 0,
                            totalPassedQty,
                            targetTotalQty
                        );
                    }
                }
            }
        })
        .catch(function(err) {
            console.error('Gagal memperbarui list planning items:', err);
        });
}

function renderPlanningCardsList(items) {
    var container = document.getElementById('planning-cards-container');
    if (!container) return;

    if (!items || items.length === 0) {
        container.innerHTML = '<div class="text-center py-6 text-xs text-slate-400 bg-slate-50 rounded-xl border border-dashed border-slate-200">Belum ada data Planning (Kanban / Safety Stock) tersimpan di sistem.</div>';
        return;
    }

    var html = '';
    for (var i = 0; i < items.length; i++) {
        var plan = items[i];
        var pCode = escapeHtml(plan.item_code || '');
        var pDesc = escapeHtml(plan.item_description || '');
        var pCust = escapeHtml(plan.customer || 'PT. Indonesia Epson Industry');
        var targetTotalQty = parseInt(plan.qty) || 0;
        var totalPassedQty = parseInt(plan.total_passed_qty) || 0;
        var remainingQty = Math.max(0, targetTotalQty - totalPassedQty);
        var rawQty = remainingQty;
        var pQty = rawQty.toLocaleString();
        
        var isSafetyStock = (plan.plan_type === 'safety_stock' || (plan.batch_plan_type || '') === 'safety_stock' || (plan.kanban_no || '').toUpperCase().indexOf('SS') === 0);
        var pType = isSafetyStock ? 'Safety Stock' : 'Kanban';
        var isPartial = (!isSafetyStock && totalPassedQty > 0);
        var pCek = plan.check_type ? escapeHtml(plan.check_type) : '';
        
        var etaRaw = plan.eta || plan.req_date || '';
        var etaFormatted = 'Reguler';
        if (etaRaw) {
            var dt = new Date(etaRaw.replace(/-/g, '/'));
            if (!isNaN(dt.getTime())) {
                var months = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
                var day = String(dt.getDate()).padStart(2, '0');
                var mName = months[dt.getMonth()];
                var year = dt.getFullYear();
                var hours = String(dt.getHours()).padStart(2, '0');
                var mins = String(dt.getMinutes()).padStart(2, '0');
                etaFormatted = day + ' ' + mName + ' ' + year + ', ' + hours + ':' + mins + ' WIB';
            }
        }
        var isEarliest = (i === 0);
        var availSsQty = parseInt(plan.avail_ss_qty) || 0;

        var planTypeAttr = isSafetyStock ? 'safety_stock' : 'kanban';
        var pCekBadge = pCek ? ('<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-amber-100 text-amber-800 border border-amber-300">' + pCek + ' Cek</span>') : '';
        var partialBadge = (totalPassedQty > 0 && !isSafetyStock) ? ('<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-indigo-100 text-indigo-800 border border-indigo-300" title="Kanban ini diinspeksi secara bertahap / parsial">⚡ Inspeksi Parsial: ' + totalPassedQty.toLocaleString() + ' / ' + targetTotalQty.toLocaleString() + ' pcs (Sisa ' + remainingQty.toLocaleString() + ' pcs)</span>') : '';
        var rejectedBadge = (plan.status === 'rejected') ? ('<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-rose-100 text-rose-800 border border-rose-300" title="Sesi sebelumnya tercatat Rejected / Butuh Pemeriksaan">⚠️ Sesi Terakhir Rejected</span>') : '';
        var ssBadge = (availSsQty > 0 && !isSafetyStock) ? ('<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-emerald-100 text-emerald-800 border border-emerald-300" title="Tersedia stok Safety Stock yang bisa dipakai memotong Qty Kanban">📦 Safety Stock: ' + availSsQty.toLocaleString() + ' pcs (Potong Qty)</span>') : '';
        var etaBadge = '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold ' + (isEarliest ? 'bg-rose-100 text-rose-800 border border-rose-300' : 'bg-slate-100 text-slate-600 border border-slate-200') + '">🚚 ETA: ' + etaFormatted + (isEarliest ? ' ⚡ (P1)' : '') + '</span>';
        var kanbanNoBadge = (plan.kanban_no && plan.kanban_no !== '-') ? ('<span>&middot;</span><span class="font-mono">No. Kanban: <b>#' + escapeHtml(plan.kanban_no) + '</b></span>') : '';

        var codeEsc = (plan.item_code || '').replace(/\\/g, "\\\\").replace(/'/g, "\\'");
        var descEsc = (plan.item_description || '').replace(/\\/g, "\\\\").replace(/'/g, "\\'");
        var custEsc = (plan.customer || 'PT. Indonesia Epson Industry').replace(/\\/g, "\\\\").replace(/'/g, "\\'");
        var cekEsc = (plan.check_type || '').replace(/\\/g, "\\\\").replace(/'/g, "\\'");

        html += '<div class="planning-card p-4 bg-white hover:bg-blue-50/80 border border-slate-200 hover:border-blue-400 rounded-xl cursor-pointer transition-all flex items-center justify-between gap-4 shadow-2xs" ' +
                'data-plan-type="' + planTypeAttr + '" ' +
                'data-partial="' + (isPartial ? 'true' : 'false') + '" ' +
                'onclick="selectPlanningItem(' + parseInt(plan.id) + ', \'' + codeEsc + '\', \'' + descEsc + '\', \'' + custEsc + '\', ' + rawQty + ', \'' + cekEsc + '\', \'' + planTypeAttr + '\', ' + availSsQty + ', ' + totalPassedQty + ', ' + targetTotalQty + ')">' +
                '<div class="min-w-0 flex-1 space-y-1.5">' +
                    '<div class="flex items-center space-x-2 flex-wrap gap-y-1">' +
                        '<span class="font-mono font-black text-blue-700 text-sm">' + pCode + '</span>' +
                        '<span class="badge text-xs ' + (isSafetyStock ? 'bg-purple-100 text-purple-800 border border-purple-200 font-bold' : 'bg-blue-100 text-blue-800 border border-blue-200 font-bold') + '">' + pType + '</span>' +
                        pCekBadge +
                        partialBadge +
                        rejectedBadge +
                        ssBadge +
                        etaBadge +
                    '</div>' +
                    '<div class="font-bold text-slate-800 text-sm truncate">' + pDesc + '</div>' +
                    '<div class="text-xs text-slate-500 flex items-center space-x-2 flex-wrap">' +
                        '<span>Cust: <b>' + pCust + '</b></span>' +
                        '<span>&middot;</span>' +
                        '<span>Target Sisa Qty: <b class="text-blue-700 font-extrabold">' + pQty + ' pcs</b>' + (totalPassedQty > 0 ? (' (dari total ' + targetTotalQty.toLocaleString() + ' pcs)') : '') + '</span>' +
                        kanbanNoBadge +
                    '</div>' +
                '</div>' +
                '<button type="button" class="btn-primary py-2 px-4 text-sm font-bold flex-shrink-0 shadow-2xs">Pilih &rarr;</button>' +
                '</div>';
    }

    container.innerHTML = html;
    filterPlanningItems();
}

function openScanModal(autoSelectId) {
    resetScanModalState();
    var selectId = autoSelectId || autoKanbanId || 0;
    refreshPlanningItemsAjax(selectId);
    if (selectId > 0) {
        autoKanbanId = 0;
    }
    var modal = document.getElementById('scan-modal-overlay');
    if (modal) {
        modal.classList.remove('hidden');
        modal.style.display = 'flex';
    }
    if (selectId > 0) {
        var qrInput = document.getElementById('scan-qr-raw');
        if (qrInput) {
            qrInput.value = '';
            setTimeout(function() { qrInput.focus(); }, 150);
        }
    } else {
        var pInput = document.getElementById('planning-search-input');
        if (pInput) {
            setTimeout(function() {
                pInput.focus();
                if (pInput.value.length > 0) pInput.select();
            }, 150);
        }
    }
}

function closeScanModal(forceClose) {
    var overlay = document.getElementById('scan-modal-overlay');
    if (overlay) {
        overlay.classList.add('hidden');
        overlay.style.display = 'none';
    }
    if (!currentSessionId || currentSessionId == 0 || forceClose) {
        window.location.href = '<?= base_url("modules/inspection/index.php") ?>';
    }
}

function openKanbanDetailModal() {
    var modal = document.getElementById('kanban-detail-modal-overlay');
    if (modal) {
        modal.classList.remove('hidden');
        modal.style.display = 'flex';
    }
}

function closeKanbanDetailModal() {
    var modal = document.getElementById('kanban-detail-modal-overlay');
    if (modal) {
        modal.classList.add('hidden');
        modal.style.display = 'none';
    }
}

function openPrevHistoryModal() {
    var modal = document.getElementById('prev-history-modal-overlay');
    if (modal) {
        modal.classList.remove('hidden');
        modal.style.display = 'flex';
    }
}

function closePrevHistoryModal() {
    var modal = document.getElementById('prev-history-modal-overlay');
    if (modal) {
        modal.classList.add('hidden');
        modal.style.display = 'none';
    }
}

function openSubstitutionLogModal() {
    var modal = document.getElementById('substitution-log-modal-overlay');
    if (modal) {
        modal.classList.remove('hidden');
        modal.style.display = 'flex';
    }
}

function closeSubstitutionLogModal() {
    var modal = document.getElementById('substitution-log-modal-overlay');
    if (modal) {
        modal.classList.add('hidden');
        modal.style.display = 'none';
    }
}

var is3dViewerInitialized = false;

function switchDrawingTab(tab) {
    var btn2d = document.getElementById('tab-btn-2d');
    var btn3d = document.getElementById('tab-btn-3d');
    var box2d = document.getElementById('viewport-2d');
    var box3d = document.getElementById('viewport-3d');

    if (tab === '2d') {
        btn2d.className = 'px-3 py-1 rounded-lg text-xs font-bold transition-all bg-blue-600 text-white shadow-xs flex items-center';
        btn3d.className = 'px-3 py-1 rounded-lg text-xs font-bold transition-all text-slate-700 hover:text-slate-900 hover:bg-slate-300/50 flex items-center';
        box2d.classList.remove('hidden');
        box3d.classList.add('hidden');
    } else {
        btn3d.className = 'px-3 py-1 rounded-lg text-xs font-bold transition-all bg-blue-600 text-white shadow-xs flex items-center';
        btn2d.className = 'px-3 py-1 rounded-lg text-xs font-bold transition-all text-slate-700 hover:text-slate-900 hover:bg-slate-300/50 flex items-center';
        box3d.classList.remove('hidden');
        box2d.classList.add('hidden');

        if (!is3dViewerInitialized) {
            init3dViewer();
        }
    }
}

function init3dViewer() {
    var container = document.getElementById('cad-3d-viewport');
    if (!container || container.style.display === 'none') return;
    var stpUrl = container.getAttribute('data-stp-url');
    if (!stpUrl) return;

    if (typeof renderCadStep === 'function') {
        renderCadStep('cad-3d-viewport', stpUrl);
        is3dViewerInitialized = true;
    } else if (typeof OQC3DViewer === 'function') {
        OQC3DViewer('cad-3d-viewport');
        is3dViewerInitialized = true;
    }
}

function adjustZoom(delta) {
    currentZoom = Math.min(2.0, Math.max(0.5, currentZoom + delta));
    var iframe = document.getElementById('pdf-frame');
    if (iframe) {
        iframe.style.transform = 'scale(' + currentZoom + ')';
    }
    var zoomLbl = document.getElementById('zoom-level-label');
    if (zoomLbl) {
        zoomLbl.textContent = 'Zoom ' + Math.round(currentZoom * 100) + '%';
    }
}

function resetZoom() {
    currentZoom = 1.0;
    var iframe = document.getElementById('pdf-frame');
    if (iframe) {
        iframe.style.transform = 'scale(1)';
    }
    var zoomLbl = document.getElementById('zoom-level-label');
    if (zoomLbl) {
        zoomLbl.textContent = 'Zoom 100%';
    }
}

function toggleDrawingFullscreen() {
    var panel = document.getElementById('drawing-viewer-panel');
    if (!panel) return;

    var isFull = !!(document.fullscreenElement || document.webkitFullscreenElement || document.mozFullScreenElement || document.msFullscreenElement);

    if (!isFull) {
        if (panel.requestFullscreen) {
            panel.requestFullscreen();
        } else if (panel.webkitRequestFullscreen) {
            panel.webkitRequestFullscreen();
        } else if (panel.mozRequestFullScreen) {
            panel.mozRequestFullScreen();
        } else if (panel.msRequestFullscreen) {
            panel.msRequestFullscreen();
        }
    } else {
        if (document.exitFullscreen) {
            document.exitFullscreen();
        } else if (document.webkitExitFullscreen) {
            document.webkitExitFullscreen();
        } else if (document.mozCancelFullScreen) {
            document.mozCancelFullScreen();
        } else if (document.msExitFullscreen) {
            document.msExitFullscreen();
        }
    }
}

function handleDrawingFullscreenChange() {
    var btn = document.getElementById('btn-fullscreen-toggle');
    var isFull = !!(document.fullscreenElement || document.webkitFullscreenElement || document.mozFullScreenElement || document.msFullscreenElement);
    if (btn) {
        btn.setAttribute('title', isFull ? 'Keluar Layar Penuh (Esc)' : 'Layar Penuh (Fullscreen)');
        if (isFull) {
            btn.classList.add('bg-blue-100', 'text-blue-700');
            btn.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>';
        } else {
            btn.classList.remove('bg-blue-100', 'text-blue-700');
            btn.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 4l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"></path></svg>';
        }
    }
    setTimeout(function() {
        window.dispatchEvent(new Event('resize'));
    }, 120);
}

document.addEventListener('fullscreenchange', handleDrawingFullscreenChange);
document.addEventListener('webkitfullscreenchange', handleDrawingFullscreenChange);
document.addEventListener('mozfullscreenchange', handleDrawingFullscreenChange);
document.addEventListener('MSFullscreenChange', handleDrawingFullscreenChange);


function toggleInlineNgForm(show) {
    var formBox = document.getElementById('inline-ng-form-box');
    if (show) {
        var container = document.getElementById('ng-defect-rows-container');
        var rows = container.querySelectorAll('.ng-defect-row');
        for (var i = 1; i < rows.length; i++) {
            rows[i].remove();
        }
        var firstRow = rows[0];
        if (firstRow) {
            firstRow.querySelector('.ng-defect-type').value = '';
            firstRow.querySelector('.ng-custom-defect-name').value = '';
            firstRow.querySelector('.ng-custom-defect-group').classList.add('hidden');
            firstRow.querySelector('.ng-qty').value = '1';
        }

        // Dynamically compute and update target sample number (samples_checked + 1)
        var progressEl = document.getElementById('label-sample-progress');
        if (progressEl) {
            var match = progressEl.textContent.match(/(\d+)\s*\/\s*(\d+)/);
            if (match) {
                var checked = parseInt(match[1], 10);
                var max = parseInt(match[2], 10);
                var nextSampleNum = Math.min(max, checked + 1);
                var targetLabel = document.getElementById('inline-sample-target-label');
                if (targetLabel) targetLabel.textContent = 'Sample ke-' + nextSampleNum;
            }
        }

        formBox.classList.remove('hidden');
    } else {
        formBox.classList.add('hidden');
    }
}

function onInlineDefectRowChange(sel) {
    var row = sel.closest('.ng-defect-row');
    var customGroup = row.querySelector('.ng-custom-defect-group');
    if (sel.value === 'custom') {
        customGroup.classList.remove('hidden');
        var nameInput = row.querySelector('.ng-custom-defect-name');
        if (nameInput) nameInput.focus();
    } else {
        customGroup.classList.add('hidden');
    }
}

function changeQtyRowStepper(btn, delta) {
    var row = btn.closest('.ng-defect-row');
    var input = row.querySelector('.ng-qty');
    var val = (parseInt(input.value) || 1) + delta;
    input.value = Math.max(1, val);
}

function addNgDefectRow() {
    var container = document.getElementById('ng-defect-rows-container');
    var rows = container.querySelectorAll('.ng-defect-row');
    var nextIdx = rows.length;

    var defectOptionsHtml = `<?php foreach ($defectTypes as $dt): ?><option value="<?= $dt['id'] ?>"><?= htmlspecialchars($dt['name']) ?></option><?php endforeach; ?>`;

    var div = document.createElement('div');
    div.className = 'ng-defect-row p-2 bg-white/90 border border-rose-200 rounded-lg space-y-1.5';
    div.setAttribute('data-row-idx', nextIdx);
    div.innerHTML = `
        <div class="flex items-center justify-between mb-0.5">
            <label class="block text-[10px] font-bold text-slate-700">Jenis Defect #${nextIdx + 1}</label>
            <button type="button" onclick="this.closest('.ng-defect-row').remove()" class="text-rose-600 hover:text-rose-800 font-bold text-[10px] flex items-center space-x-0.5 px-1.5 py-0.5 rounded bg-rose-50 hover:bg-rose-100 border border-rose-200" title="Hapus defect ini">
                <svg class="w-3 h-3 mr-0.5 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                <span>Hapus</span>
            </button>
        </div>
        <div>
            <select class="ng-defect-type form-input text-xs font-semibold py-1" onchange="onInlineDefectRowChange(this)">
                <option value="">-- Pilih Jenis Defect --</option>
                ${defectOptionsHtml}
                <option value="custom">+ Defect Baru...</option>
            </select>
        </div>
        <div class="ng-custom-defect-group hidden">
            <input type="text" class="ng-custom-defect-name form-input text-xs py-1" placeholder="Nama Defect Baru...">
        </div>
        <div class="flex items-center justify-between gap-2 pt-0.5">
            <span class="text-[11px] font-bold text-slate-700">Qty NG</span>
            <div class="flex items-center space-x-1 border border-slate-300 rounded-lg bg-white p-0.5">
                <button type="button" onclick="changeQtyRowStepper(this, -1)" class="w-6 h-6 rounded bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold flex items-center justify-center text-xs">-</button>
                <input type="number" class="ng-qty w-8 text-center text-xs font-bold border-0 p-0 focus:ring-0" value="1" min="1" readonly>
                <button type="button" onclick="changeQtyRowStepper(this, 1)" class="w-6 h-6 rounded bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold flex items-center justify-center text-xs">+</button>
            </div>
        </div>
    `;
    container.appendChild(div);
}

var selectedPlanningId = 0;
var selectedPlanningType = 'kanban';
var selectedPlanningPartCode = '';
var selectedPlanningQty = 0;
var selectedAvailableSsQty = 0;
var isSafetyStockDeducted = false;

var availableSsLots = [];
var selectedSsSessionIds = [];
var selectedSsLotIds = [];
var ssLotSearchQuery = '';

function selectPlanningItem(id, itemCode, itemDesc, customer, qty, checkType, planType, availSsQty, totalPassedQty, targetTotalQty) {
    selectedPlanningId = id;
    selectedPlanningType = planType || 'kanban';
    selectedPlanningPartCode = itemCode || '';
    selectedPlanningQty = parseInt(qty) || 0;
    selectedAvailableSsQty = parseInt(availSsQty) || 0;

    document.getElementById('selected-plan-part-code').textContent = itemCode;
    document.getElementById('selected-plan-desc').textContent = itemDesc;
    document.getElementById('selected-plan-cust').textContent = customer;
    
    totalPassedQty = parseInt(totalPassedQty) || 0;
    targetTotalQty = parseInt(targetTotalQty) || selectedPlanningQty;

    if (totalPassedQty > 0) {
        document.getElementById('selected-plan-qty').innerHTML = qty.toLocaleString() + ' pcs <span class="text-[10px] text-indigo-700 font-bold ml-1">(⚡ Cicilan Sesi Ini | Passed: ' + totalPassedQty.toLocaleString() + ' / ' + targetTotalQty.toLocaleString() + ' pcs)</span>';
    } else {
        document.getElementById('selected-plan-qty').textContent = qty.toLocaleString() + ' pcs';
    }
    
    var tagEl = document.getElementById('selected-plan-tag');
    if (checkType) {
        tagEl.textContent = checkType + ' Cek';
        tagEl.classList.remove('hidden');
    } else {
        tagEl.classList.add('hidden');
    }

    document.getElementById('scan-part-code').value = itemCode;

    // Fetch multi-lot Safety Stock sessions via API
    fetchAndRenderAvailableSsLots(itemCode, selectedPlanningQty);

    // Switch step UI
    var p1 = document.getElementById('step-pill-1');
    if (p1) {
        p1.style.cssText = 'background-color: #dcfce7 !important; color: #166534 !important; border: 1px solid #bbf7d0 !important; padding: 4px 12px; border-radius: 9999px; font-size: 11px; font-weight: 800; display: inline-block;';
        p1.textContent = '✓ Step 1 Complete';
    }
    var p2 = document.getElementById('step-pill-2');
    if (p2) {
        p2.style.cssText = 'background-color: #2563eb !important; color: #ffffff !important; padding: 4px 12px; border-radius: 9999px; font-size: 11px; font-weight: 800; display: inline-block; box-shadow: 0 2px 4px rgba(37,99,235,0.3);';
    }
    
    document.getElementById('wizard-modal-title').textContent = 'Step 2: Scan QR Code Label Barcode';
    document.getElementById('wizard-modal-subtitle').textContent = 'Scan barcode label barang yang mau diuji';

    document.getElementById('wizard-step-1-content').classList.add('hidden');
    document.getElementById('wizard-step-2-content').classList.remove('hidden');

    var rawInput = document.getElementById('scan-qr-raw');
    if (rawInput) {
        rawInput.value = '';
        setTimeout(function() { rawInput.focus(); }, 150);
    }
}

function fetchAndRenderAvailableSsLots(itemCode, targetQty) {
    var container = document.getElementById('ss-lots-checkbox-list');
    var countInfo = document.getElementById('ss-lots-count-info');

    if (!itemCode || selectedPlanningType !== 'kanban') {
        availableSsLots = [];
        selectedSsSessionIds = [];
        selectedSsLotIds = [];
        if (container) container.innerHTML = '';
        if (countInfo) countInfo.textContent = '0 Lot';
        updateSafetyStockDeductionUI();
        return;
    }

    if (container) {
        container.innerHTML = '<div class="text-center py-2 text-slate-500 font-medium text-xs">⌛ Memuat lot Safety Stock...</div>';
    }

    fetch('<?= base_url("modules/inspection/api/get_available_ss.php") ?>?part_code=' + encodeURIComponent(itemCode))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var rawLots = data.lots || data.items || [];
            if (data.success && Array.isArray(rawLots) && rawLots.length > 0) {
                availableSsLots = rawLots;
                
                var totalAvail = 0;
                availableSsLots.forEach(function(l) { totalAvail += parseInt(l.available_qty) || 0; });
                selectedAvailableSsQty = totalAvail;

                // Pre-check individual lots in order up to targetQty
                selectedSsLotIds = [];
                selectedSsSessionIds = [];
                var accumQty = 0;
                availableSsLots.forEach(function(lot) {
                    var lQty = parseInt(lot.available_qty) || 0;
                    if (accumQty < targetQty) {
                        selectedSsLotIds.push(String(lot.lot_id));
                        var sId = parseInt(lot.session_id);
                        if (sId && selectedSsSessionIds.indexOf(sId) === -1) {
                            selectedSsSessionIds.push(sId);
                        }
                        accumQty += lQty;
                    }
                });

                isSafetyStockDeducted = (selectedSsLotIds.length > 0);

                if (countInfo) countInfo.textContent = availableSsLots.length + ' Lot Tersedia';
            } else {
                availableSsLots = [];
                selectedSsSessionIds = [];
                selectedSsLotIds = [];
                selectedAvailableSsQty = 0;
                isSafetyStockDeducted = false;
                if (container) container.innerHTML = '<div class="text-center py-2 text-slate-400 font-medium text-xs">Tidak ada Safety Stock PASSED tersedia untuk part ini.</div>';
                if (countInfo) countInfo.textContent = '0 Lot';
            }
            updateSafetyStockDeductionUI();
        })
        .catch(function(err) {
            console.error('Error fetching available SS lots:', err);
            availableSsLots = [];
            selectedSsSessionIds = [];
            selectedSsLotIds = [];
            if (container) container.innerHTML = '<div class="text-center py-2 text-rose-500 font-medium text-xs">Gagal memuat list Safety Stock.</div>';
            updateSafetyStockDeductionUI();
        });
}

function onSsLotCheckboxChange(cb, lotId, sId) {
    if (cb && typeof lotId !== 'undefined') {
        var lid = String(lotId);
        if (cb.checked) {
            if (selectedSsLotIds.indexOf(lid) === -1) {
                selectedSsLotIds.push(lid);
            }
        } else {
            var idx = selectedSsLotIds.indexOf(lid);
            if (idx !== -1) {
                selectedSsLotIds.splice(idx, 1);
            }
        }
    } else {
        var checkboxes = document.querySelectorAll('.ss-lot-checkbox');
        checkboxes.forEach(function(c) {
            var lid = String(c.value);
            var idx = selectedSsLotIds.indexOf(lid);
            if (c.checked && idx === -1) selectedSsLotIds.push(lid);
            else if (!c.checked && idx !== -1) selectedSsLotIds.splice(idx, 1);
        });
    }

    selectedSsSessionIds = [];
    if (availableSsLots && availableSsLots.length > 0) {
        availableSsLots.forEach(function(l) {
            if (selectedSsLotIds.indexOf(String(l.lot_id)) !== -1) {
                var sid = parseInt(l.session_id);
                if (sid && selectedSsSessionIds.indexOf(sid) === -1) {
                    selectedSsSessionIds.push(sid);
                }
            }
        });
    }

    isSafetyStockDeducted = (selectedSsLotIds.length > 0);
    updateSafetyStockDeductionUI();
}

function toggleSafetyStockDeduction(forceState) {
    if (typeof forceState !== 'undefined') {
        isSafetyStockDeducted = !!forceState;
    } else {
        isSafetyStockDeducted = !isSafetyStockDeducted;
    }

    if (!isSafetyStockDeducted) {
        selectedSsLotIds = [];
        selectedSsSessionIds = [];
    } else {
        selectedSsLotIds = [];
        selectedSsSessionIds = [];
        var accumQty = 0;
        if (availableSsLots && availableSsLots.length > 0) {
            availableSsLots.forEach(function(lot) {
                var lQty = parseInt(lot.available_qty) || 0;
                if (accumQty < selectedPlanningQty) {
                    selectedSsLotIds.push(String(lot.lot_id));
                    var sId = parseInt(lot.session_id);
                    if (sId && selectedSsSessionIds.indexOf(sId) === -1) {
                        selectedSsSessionIds.push(sId);
                    }
                    accumQty += lQty;
                }
            });
        }
        isSafetyStockDeducted = (selectedSsLotIds.length > 0);
    }
    updateSafetyStockDeductionUI();
}

function updateSafetyStockDeductionUI() {
    var card = document.getElementById('safety-stock-deduction-card');
    if (!card) return;

    var totalCheckedQty = 0;
    if (availableSsLots && availableSsLots.length > 0 && selectedSsLotIds && selectedSsLotIds.length > 0) {
        availableSsLots.forEach(function(lot) {
            if (selectedSsLotIds.indexOf(String(lot.lot_id)) !== -1) {
                totalCheckedQty += parseInt(lot.available_qty) || 0;
            }
        });
    }

    if (availableSsLots.length > 0 && selectedPlanningType === 'kanban') {
        card.classList.remove('hidden');
        card.style.display = 'block';

        var usedQty = isSafetyStockDeducted ? Math.min(selectedPlanningQty, totalCheckedQty) : 0;
        var remainQty = isSafetyStockDeducted ? Math.max(0, selectedPlanningQty - totalCheckedQty) : selectedPlanningQty;

        if (document.getElementById('ss-deduct-badge')) {
            if (isSafetyStockDeducted) {
                if (totalCheckedQty > selectedPlanningQty) {
                    document.getElementById('ss-deduct-badge').textContent = 'ALOKASI ' + usedQty.toLocaleString() + ' PCS (' + totalCheckedQty.toLocaleString() + ' PCS DICENTANG)';
                    document.getElementById('ss-deduct-badge').className = 'text-[10px] font-extrabold px-2 py-0.5 rounded-full bg-emerald-600 text-white shadow-2xs';
                } else {
                    document.getElementById('ss-deduct-badge').textContent = 'ALOKASI ' + usedQty.toLocaleString() + ' PCS (' + selectedSsLotIds.length + ' LOT)';
                    document.getElementById('ss-deduct-badge').className = 'text-[10px] font-extrabold px-2 py-0.5 rounded-full bg-emerald-600 text-white shadow-2xs';
                }
            } else {
                document.getElementById('ss-deduct-badge').textContent = 'TIDAK DIPAKAI';
                document.getElementById('ss-deduct-badge').className = 'text-[10px] font-extrabold px-2 py-0.5 rounded-full bg-slate-400 text-white shadow-2xs';
            }
        }
        if (document.getElementById('ss-deduct-orig-qty')) document.getElementById('ss-deduct-orig-qty').textContent = selectedPlanningQty.toLocaleString() + ' pcs';
        if (document.getElementById('ss-deduct-used-qty')) {
            if (totalCheckedQty > selectedPlanningQty) {
                document.getElementById('ss-deduct-used-qty').innerHTML = '- ' + usedQty.toLocaleString() + ' pcs <span class="text-[10px] font-normal text-slate-500 ml-1">(sisa ' + (totalCheckedQty - usedQty).toLocaleString() + ' pcs stok utuh)</span>';
            } else {
                document.getElementById('ss-deduct-used-qty').textContent = '- ' + usedQty.toLocaleString() + ' pcs';
            }
        }
        if (document.getElementById('ss-deduct-remain-qty')) {
            if (remainQty === 0 && isSafetyStockDeducted) {
                document.getElementById('ss-deduct-remain-qty').innerHTML = '<span class="text-emerald-700 font-extrabold">0 pcs (100% Terpenuhi dari Safety Stock)</span>';
            } else {
                document.getElementById('ss-deduct-remain-qty').textContent = remainQty.toLocaleString() + ' pcs';
            }
        }

        var fullBanner = document.getElementById('ss-full-fulfill-banner');
        var partialBanner = document.getElementById('ss-partial-fulfill-banner');

        if (remainQty === 0 && isSafetyStockDeducted) {
            if (fullBanner) { fullBanner.classList.remove('hidden'); fullBanner.style.display = 'block'; }
            if (partialBanner) { partialBanner.classList.add('hidden'); partialBanner.style.display = 'none'; }
        } else if (usedQty > 0 && remainQty > 0 && isSafetyStockDeducted) {
            if (fullBanner) { fullBanner.classList.add('hidden'); fullBanner.style.display = 'none'; }
            if (partialBanner) {
                partialBanner.classList.remove('hidden');
                partialBanner.style.display = 'block';
                if (document.getElementById('ss-partial-used-qty-text')) document.getElementById('ss-partial-used-qty-text').textContent = usedQty.toLocaleString();
                if (document.getElementById('ss-partial-target-text')) document.getElementById('ss-partial-target-text').textContent = selectedPlanningQty.toLocaleString();
                if (document.getElementById('ss-partial-remain-text')) document.getElementById('ss-partial-remain-text').textContent = remainQty.toLocaleString();
            }
        } else {
            if (fullBanner) { fullBanner.classList.add('hidden'); fullBanner.style.display = 'none'; }
            if (partialBanner) { partialBanner.classList.add('hidden'); partialBanner.style.display = 'none'; }
        }

        var btn = document.getElementById('btn-toggle-ss-deduct');
        if (btn) {
            if (isSafetyStockDeducted) {
                btn.className = 'px-2.5 py-1 bg-rose-100 hover:bg-rose-200 text-rose-800 border border-rose-300 font-extrabold text-[10px] rounded-lg shadow-2xs transition-all flex items-center space-x-1 cursor-pointer';
                btn.innerHTML = '<span>❌ Hapus Semua Pilihan Safety Stock</span>';
            } else {
                btn.className = 'px-2.5 py-1 bg-emerald-600 hover:bg-emerald-700 text-white font-extrabold text-[10px] rounded-lg shadow-2xs transition-all flex items-center space-x-1 cursor-pointer';
                btn.innerHTML = '<span>⚡ Pilih Safety Stock Otomatis</span>';
            }
        }

        // Render lot rows with real-time per-lot status
        renderSsLotRows();

        // Update accumulation progress counter & progress bar in real-time
        renderScannedLabelsTable();

    } else {
        card.classList.add('hidden');
        card.style.display = 'none';
        renderScannedLabelsTable();
    }
}

function handleSsLotSearchInput(val) {
    var clearBtn = document.getElementById('ss-lot-search-clear');
    if (clearBtn) {
        if (val && val.trim() !== '') {
            clearBtn.style.display = 'flex';
            clearBtn.classList.remove('hidden');
        } else {
            clearBtn.style.display = 'none';
            clearBtn.classList.add('hidden');
        }
    }
    ssLotSearchQuery = (val || '').trim();
    renderSsLotRows();
}

function clearSsLotSearch() {
    var inp = document.getElementById('ss-lot-search-input');
    if (inp) {
        inp.value = '';
        inp.focus();
    }
    var clearBtn = document.getElementById('ss-lot-search-clear');
    if (clearBtn) {
        clearBtn.style.display = 'none';
        clearBtn.classList.add('hidden');
    }
    ssLotSearchQuery = '';
    renderSsLotRows();
}

function handleSsLotSearchKeydown(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        e.stopPropagation();
        var rawVal = (e.target.value || '').trim();
        if (!rawVal) return;

        // Smart extraction of barcode components if factory QR was scanned
        var searchRef = rawVal;
        var searchLot = '';
        if (rawVal.includes('|') || rawVal.startsWith('Z1') || rawVal.startsWith('Z5')) {
            var parsed = parseBarcodeQR(rawVal);
            if (parsed.Z5) searchRef = parsed.Z5;
            else if (parsed.Z1 && !parsed.Z5) searchRef = parsed.Z1;
            if (parsed.Z2) searchLot = parsed.Z2;
        }

        var searchRefUpper = searchRef.toUpperCase().trim();
        var searchLotUpper = searchLot.toUpperCase().trim();

        // 1. Prioritas 1: Exact Ref Number Match
        var matchedLot = null;
        for (var i = 0; i < availableSsLots.length; i++) {
            var lot = availableSsLots[i];
            var r1 = (lot.ref_number || '').toUpperCase().trim();
            var r2 = (lot.ref_numbers || '').toUpperCase().trim();
            if (searchRefUpper && (r1 === searchRefUpper || r2 === searchRefUpper)) {
                matchedLot = lot;
                break;
            }
        }

        // 2. Prioritas 2: Exact Lot Number Match (jika nomor lot diketik)
        if (!matchedLot && searchLotUpper) {
            for (var i = 0; i < availableSsLots.length; i++) {
                var lot = availableSsLots[i];
                var lNum = (lot.lot_number || '').toUpperCase().trim();
                if (lNum === searchLotUpper) {
                    matchedLot = lot;
                    break;
                }
            }
        }

        // 3. Prioritas 3: Jika list tersaring di layar, ambil lot pertama yang sedang tampil
        if (!matchedLot) {
            var query = (ssLotSearchQuery || '').trim().toLowerCase();
            var visibleLots = availableSsLots.filter(function(lot) {
                if (!query) return true;
                var refStr = ((lot.ref_number || '') + ' ' + (lot.ref_numbers || '')).toLowerCase();
                var lotNum = (lot.lot_number || '').toLowerCase();
                var qrRaw = (lot.scanned_qr_raw || '').toLowerCase();
                return refStr.indexOf(query) !== -1 || lotNum.indexOf(query) !== -1 || qrRaw.indexOf(query) !== -1;
            });
            if (visibleLots.length > 0) {
                matchedLot = visibleLots[0];
            }
        }

        // 4. Prioritas 4: Partial Ref Match
        if (!matchedLot && searchRefUpper) {
            for (var i = 0; i < availableSsLots.length; i++) {
                var lot = availableSsLots[i];
                var r1 = (lot.ref_number || '').toUpperCase().trim();
                if (r1 && (r1.indexOf(searchRefUpper) !== -1 || searchRefUpper.indexOf(r1) !== -1)) {
                    matchedLot = lot;
                    break;
                }
            }
        }

        if (matchedLot) {
            var lotIdStr = String(matchedLot.lot_id);
            var sId = parseInt(matchedLot.session_id);

            // Ceklis lot jika belum diceklis
            if (selectedSsLotIds.indexOf(lotIdStr) === -1) {
                selectedSsLotIds.push(lotIdStr);
            }
            if (sId && selectedSsSessionIds.indexOf(sId) === -1) {
                selectedSsSessionIds.push(sId);
            }
            isSafetyStockDeducted = true;
            updateSafetyStockDeductionUI();

            // Highlight flash animation pada baris lot
            setTimeout(function() {
                var itemEl = document.getElementById('ss-lot-item-' + lotIdStr);
                if (itemEl) {
                    itemEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    itemEl.style.transition = 'all 0.3s';
                    itemEl.style.backgroundColor = '#bbf7d0';
                    itemEl.style.borderColor = '#059669';
                    setTimeout(function() {
                        itemEl.style.backgroundColor = '';
                        itemEl.style.borderColor = '';
                    }, 1200);
                }
            }, 60);

            // Select all text in search box so next scan immediately replaces it
            e.target.select();

            // Toast feedback
            if (typeof Swal !== 'undefined' && Swal.mixin) {
                var Toast = Swal.mixin({
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 1500,
                    timerProgressBar: true
                });
                Toast.fire({
                    icon: 'success',
                    title: '✓ Lot Terpilih: ' + (matchedLot.ref_number && matchedLot.ref_number !== '-' ? matchedLot.ref_number : matchedLot.lot_number) + ' (' + matchedLot.available_qty + ' pcs)'
                });
            }
        } else {
            // Not found
            if (typeof Swal !== 'undefined' && Swal.mixin) {
                var Toast = Swal.mixin({
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 2000,
                    timerProgressBar: true
                });
                Toast.fire({
                    icon: 'warning',
                    title: 'Ref "' + searchRefUpper + '" tidak ada di list Safety Stock!'
                });
            }
            e.target.select();
        }
    } else if (e.key === 'Escape') {
        clearSsLotSearch();
        if (typeof window.refocusActiveScanner === 'function') {
            window.refocusActiveScanner(true);
        }
    }
}

function renderSsLotRows() {
    var container = document.getElementById('ss-lots-checkbox-list');
    var countInfo = document.getElementById('ss-lots-count-info');
    if (!container || !availableSsLots) return;

    var query = (ssLotSearchQuery || '').trim().toLowerCase();
    var filteredLots = availableSsLots.filter(function(lot) {
        if (!query) return true;
        var refStr = ((lot.ref_number || '') + ' ' + (lot.ref_numbers || '')).toLowerCase();
        var lotNum = (lot.lot_number || '').toLowerCase();
        var qrRaw = (lot.scanned_qr_raw || '').toLowerCase();
        return refStr.indexOf(query) !== -1 || lotNum.indexOf(query) !== -1 || qrRaw.indexOf(query) !== -1;
    });

    if (countInfo) {
        if (query) {
            countInfo.textContent = filteredLots.length + ' dari ' + availableSsLots.length + ' Lot';
        } else {
            countInfo.textContent = availableSsLots.length + ' Lot Tersedia';
        }
    }

    if (filteredLots.length === 0) {
        container.innerHTML = '<div class="text-center py-3 text-slate-500 font-medium text-xs bg-white/70 rounded-lg border border-dashed border-emerald-300">' +
            '🔍 Tidak ada lot Safety Stock yang cocok dengan "<b>' + escapeHtml(query) + '</b>"' +
            '<div class="mt-1"><button type="button" onclick="clearSsLotSearch()" class="text-emerald-700 underline text-[11px] font-bold cursor-pointer">Reset Pencarian</button></div>' +
            '</div>';
        return;
    }

    var remainingTarget = selectedPlanningQty;
    var html = '';

    filteredLots.forEach(function(lot, idx) {
        var lotIdStr = String(lot.lot_id);
        var sId = parseInt(lot.session_id);
        var lQty = parseInt(lot.available_qty) || 0;
        var isChecked = selectedSsLotIds.indexOf(lotIdStr) !== -1;
        var refStr = (lot.ref_number && lot.ref_number !== '-') ? escapeHtml(lot.ref_number) : (lot.ref_numbers && lot.ref_numbers !== '-' ? escapeHtml(lot.ref_numbers) : '');
        var refBadge = refStr ? ('<span class="bg-blue-100 text-blue-800 text-[10px] font-mono font-extrabold px-1.5 py-0.5 rounded border border-blue-200 truncate">Ref: ' + refStr + '</span>') : '';
        var lotNumStr = lot.lot_number ? ('Lot ' + escapeHtml(lot.lot_number)) : ('Lot #' + (idx + 1));

        var statusBadgeHtml = '';
        if (isChecked) {
            var takenFromThisLot = Math.min(remainingTarget, lQty);
            if (takenFromThisLot > 0) {
                statusBadgeHtml = '<span class="text-emerald-800 font-extrabold bg-emerald-100 px-1.5 py-0.5 rounded border border-emerald-300">✓ Dialokasikan: ' + takenFromThisLot.toLocaleString() + ' pcs</span>';
                remainingTarget -= takenFromThisLot;
            } else {
                statusBadgeHtml = '<span class="text-slate-600 font-semibold bg-slate-100 px-1.5 py-0.5 rounded border border-slate-300">ℹ️ Stok Cadangan Utuh</span>';
            }
        } else {
            statusBadgeHtml = '<span class="text-slate-500 font-medium">Tersedia: ' + lQty.toLocaleString() + ' pcs</span>';
        }

        html += '<label id="ss-lot-item-' + escapeHtml(lotIdStr) + '" class="flex items-start space-x-2.5 p-2 bg-white rounded-lg border ' + (isChecked ? 'border-emerald-400 bg-emerald-50/30' : 'border-slate-200') + ' hover:border-emerald-500 cursor-pointer transition-all shadow-2xs">' +
                    '<input type="checkbox" value="' + escapeHtml(lotIdStr) + '" data-session-id="' + sId + '" data-qty="' + lQty + '" ' + (isChecked ? 'checked' : '') + ' onchange="onSsLotCheckboxChange(this, \'' + escapeHtml(lotIdStr) + '\', ' + sId + ')" class="ss-lot-checkbox mt-0.5 rounded text-emerald-600 focus:ring-emerald-500 w-4 h-4 cursor-pointer">' +
                    '<div class="flex-1 min-w-0">' +
                        '<div class="flex items-center justify-between gap-1">' +
                            '<div class="flex items-center space-x-1.5 truncate">' +
                                '<span class="font-mono font-extrabold text-slate-900 text-xs truncate">' + lotNumStr + '</span>' +
                                refBadge +
                            '</div>' +
                            '<span class="font-mono font-black text-emerald-700 text-xs flex-shrink-0">' + lQty.toLocaleString() + ' pcs</span>' +
                        '</div>' +
                        '<div class="flex items-center justify-between text-[10px] text-slate-500 mt-0.5">' +
                            '<span>Passed: ' + escapeHtml(lot.formatted_date) + '</span>' +
                            statusBadgeHtml +
                        '</div>' +
                    '</div>' +
                '</label>';
    });

    container.innerHTML = html;
}

function triggerDirectSsCompletion() {
    var pCode = selectedPlanningPartCode || document.getElementById('scan-part-code').value.trim();

    // Hitung qty dari SS lot yang dipilih
    var totalCheckedQty = 0;
    availableSsLots.forEach(function(lot) {
        if (selectedSsLotIds.indexOf(String(lot.lot_id)) !== -1) {
            totalCheckedQty += parseInt(lot.available_qty) || 0;
        }
    });
    var usedQty = Math.min(selectedPlanningQty, totalCheckedQty);

    if (usedQty <= 0 || selectedSsLotIds.length === 0) {
        document.getElementById('modal-error-box').classList.remove('hidden');
        document.getElementById('modal-error-msg').textContent = 'Tidak ada Safety Stock yang dipilih!';
        return;
    }

    // Ambil did_id dari SS lot pertama yang dipilih — sudah terverifikasi PASSED
    var firstSelectedLot = null;
    for (var i = 0; i < availableSsLots.length; i++) {
        if (selectedSsLotIds.indexOf(String(availableSsLots[i].lot_id)) !== -1) {
            firstSelectedLot = availableSsLots[i];
            break;
        }
    }

    if (!firstSelectedLot) {
        document.getElementById('modal-error-box').classList.remove('hidden');
        document.getElementById('modal-error-msg').textContent = 'Gagal membaca data Safety Stock yang dipilih!';
        return;
    }

    var deviceLine = localStorage.getItem('oqc_device_line_name') || localStorage.getItem('oqc_device_line');
    var deviceLineId = localStorage.getItem('oqc_device_line_id') || 0;
    if (!deviceLine || deviceLine.trim() === '') {
        document.getElementById('modal-loading').classList.add('hidden');
        promptDeviceLine(false);
        return;
    }

    document.getElementById('modal-loading').classList.remove('hidden');
    document.getElementById('modal-error-box').classList.add('hidden');

    var lotNumber = firstSelectedLot.lot_number || ('SS-' + pCode);
    var didId = firstSelectedLot.did_id || 0;
    var partId = firstSelectedLot.part_id || 0;

    var payload = new FormData();
    payload.append('action', 'start_session');
    payload.append('part_code', pCode.toUpperCase());
    payload.append('lot_number', lotNumber);
    payload.append('did_id', didId);
    payload.append('kanban_item_id', selectedPlanningId);
    payload.append('inspection_type', selectedPlanningType || 'kanban');
    payload.append('part_id', partId);
    payload.append('device_line_id', deviceLineId);
    payload.append('device_line', deviceLine);
    payload.append('sample_size', 1);
    payload.append('reject_number', 1);
    payload.append('total_scanned_qty', usedQty);
    payload.append('excess_qty', 0);
    payload.append('use_safety_stock_qty', usedQty);
    payload.append('selected_ss_lot_ids', JSON.stringify(selectedSsLotIds));
    payload.append('selected_ss_session_ids', JSON.stringify(selectedSsSessionIds));
    payload.append('scanned_labels', JSON.stringify([]));

    fetch('<?= base_url("modules/inspection/create.php") ?>', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: payload
    })
    .then(function(res) { return res.json(); })
    .then(function(resData) {
        document.getElementById('modal-loading').classList.add('hidden');
        if (resData.success && (resData.url || resData.session_id)) {
            var newId = resData.session_id || parseInt((resData.url || '').split('id=')[1]);
            loadWorkbenchSessionData(newId);
        } else {
            document.getElementById('modal-error-box').classList.remove('hidden');
            document.getElementById('modal-error-msg').textContent = resData.message || 'Gagal membuat sesi inspeksi Safety Stock!';
        }
    })
    .catch(function(err) {
        document.getElementById('modal-loading').classList.add('hidden');
        document.getElementById('modal-error-box').classList.remove('hidden');
        document.getElementById('modal-error-msg').textContent = 'Gagal memproses alokasi Safety Stock!';
    });
}

function openKanbanDetailModal() {
    var modal = document.getElementById('kanban-detail-modal-overlay');
    if (modal) modal.classList.remove('hidden');
}

function closeKanbanDetailModal() {
    var modal = document.getElementById('kanban-detail-modal-overlay');
    if (modal) modal.classList.add('hidden');
}

function backToStep1() {
    setPlanningTypeTab('kanban');

    var p1 = document.getElementById('step-pill-1');
    if (p1) {
        p1.style.cssText = 'background-color: #2563eb !important; color: #ffffff !important; padding: 4px 12px; border-radius: 9999px; font-size: 11px; font-weight: 800; display: inline-block;';
        p1.textContent = '1. Pilih Planning';
    }
    var p2 = document.getElementById('step-pill-2');
    if (p2) {
        p2.style.cssText = 'background-color: #f1f5f9 !important; color: #64748b !important; border: 1px solid #cbd5e1 !important; padding: 4px 12px; border-radius: 9999px; font-size: 11px; font-weight: 700; display: inline-block;';
    }

    document.getElementById('wizard-modal-title').textContent = 'Step 1: Pilih Item Planning Aktif';
    document.getElementById('wizard-modal-subtitle').textContent = 'Pilih Kanban / Safety Stock yang akan diinspeksi sebelum melakukan scan label ';

    document.getElementById('wizard-step-1-content').classList.remove('hidden');
    document.getElementById('wizard-step-2-content').classList.add('hidden');
    setTimeout(function() {
        var pInput = document.getElementById('planning-search-input');
        if (pInput) {
            pInput.focus();
            if (pInput.value.length > 0) pInput.select();
        }
    }, 100);
}

function skipPlanningSelection() {
    selectedPlanningId = 0;
    selectedPlanningPartCode = '';
    selectedPlanningQty = 0;
    document.getElementById('scan-part-code').value = '';
    document.getElementById('selected-plan-part-code').textContent = 'AUTOMATIC FIFO MATCHING';
    document.getElementById('selected-plan-desc').textContent = 'Sistem otomatis mencocokkan planning berdasarkan Part Code';
    document.getElementById('selected-plan-cust').textContent = '-';
    document.getElementById('selected-plan-qty').textContent = '-';
    document.getElementById('selected-plan-tag').classList.add('hidden');

    document.getElementById('wizard-step-1-content').classList.add('hidden');
    document.getElementById('wizard-step-2-content').classList.remove('hidden');

    var rawInput = document.getElementById('scan-qr-raw');
    if (rawInput) {
        rawInput.value = '';
        setTimeout(function() { rawInput.focus(); }, 150);
    }
}

function startDirectSafetyStockScan() {
    selectedPlanningId = 0;
    selectedPlanningType = 'safety_stock';
    selectedPlanningPartCode = '';
    selectedPlanningQty = 0;

    document.getElementById('selected-plan-part-code').textContent = 'SAFETY STOCK (DIRECT GUDANG)';
    document.getElementById('selected-plan-desc').textContent = 'Direct Scan Barcode QR atau Input Form Manual Barang dari Gudang';
    document.getElementById('selected-plan-cust').textContent = 'INTERNAL SAFETY STOCK';
    document.getElementById('selected-plan-qty').textContent = 'Akumulasi Lot Gudang';
    
    var tagEl = document.getElementById('selected-plan-tag');
    tagEl.textContent = 'Safety Stock Cek';
    tagEl.className = 'text-[9px] bg-purple-100 text-purple-800 border border-purple-300 px-1.5 py-0.2 rounded font-sans font-extrabold';
    tagEl.classList.remove('hidden');

    document.getElementById('scan-part-code').value = '';
    document.getElementById('scan-lot-number').value = '';
    document.getElementById('scan-label-qty').value = '';
    document.getElementById('scan-ref-number').value = '';

    // Switch step UI
    var p1 = document.getElementById('step-pill-1');
    if (p1) {
        p1.style.cssText = 'background-color: #f3e8ff !important; color: #6b21a8 !important; border: 1px solid #d8b4fe !important; padding: 4px 12px; border-radius: 9999px; font-size: 11px; font-weight: 800; display: inline-block;';
        p1.textContent = '✓ Step 1: Safety Stock Gudang';
    }
    var p2 = document.getElementById('step-pill-2');
    if (p2) {
        p2.style.cssText = 'background-color: #7c3aed !important; color: #ffffff !important; padding: 4px 12px; border-radius: 9999px; font-size: 11px; font-weight: 800; display: inline-block; box-shadow: 0 2px 4px rgba(124,58,237,0.3);';
    }
    
    document.getElementById('wizard-modal-title').textContent = 'Step 2: Scan / Input Barcode Label';
    document.getElementById('wizard-modal-subtitle').textContent = 'Scan QR Barcode atau ketik manual field label barang di bawah';

    document.getElementById('wizard-step-1-content').classList.add('hidden');
    document.getElementById('wizard-step-2-content').classList.remove('hidden');

    renderScannedLabelsTable();

    var rawInput = document.getElementById('scan-qr-raw');
    if (rawInput) {
        rawInput.value = '';
        setTimeout(function() { rawInput.focus(); }, 150);
    }
}

var currentPlanTypeTab = '<?= sanitize($_GET['plan_type'] ?? 'kanban') ?>';
if (!['safety_stock', 'kanban', 'partial'].includes(currentPlanTypeTab)) {
    currentPlanTypeTab = 'kanban';
}

function setPlanningTypeTab(type) {
    currentPlanTypeTab = type;
    if (type === 'safety_stock') {
        startDirectSafetyStockScan();
        return;
    }

    // Update active styles for all tab buttons
    ['kanban', 'partial', 'safety_stock'].forEach(function(t) {
        var btn = document.getElementById('tab-plan-' + t);
        if (!btn) return;
        if (t === type) {
            if (t === 'partial') {
                btn.style.cssText = 'background-color: #f59e0b !important; color: #ffffff !important; border: 1px solid #d97706 !important; font-weight: 800; padding: 5px 12px; border-radius: 8px; font-size: 11px; cursor: pointer; box-shadow: 0 2px 4px rgba(245,158,11,0.3);';
            } else {
                btn.style.cssText = 'background-color: #2563eb !important; color: #ffffff !important; border: 1px solid #1d4ed8 !important; font-weight: 800; padding: 5px 12px; border-radius: 8px; font-size: 11px; cursor: pointer; box-shadow: 0 2px 4px rgba(37,99,235,0.3);';
            }
        } else {
            btn.style.cssText = 'background-color: #f1f5f9 !important; color: #475569 !important; border: 1px solid #cbd5e1 !important; font-weight: 700; padding: 5px 12px; border-radius: 8px; font-size: 11px; cursor: pointer; box-shadow: none;';
        }
    });
    filterPlanningItems();
}

function filterPlanningItems() {
    var inputEl = document.getElementById('planning-search-input');
    var rawVal = inputEl ? inputEl.value.trim() : '';

    // Auto-clean if scanned in QR format (Z1<part_code>|Z2...)
    if (rawVal.indexOf('|') !== -1 || (rawVal.indexOf('Z1') === 0 && rawVal.length > 5)) {
        var parts = rawVal.split('|');
        for (var k = 0; k < parts.length; k++) {
            var p = parts[k].trim();
            if (p.indexOf('Z1') === 0) {
                rawVal = p.substring(2).trim();
                if (inputEl) inputEl.value = rawVal;
                break;
            }
        }
    }

    var query = rawVal.toLowerCase();
    var container = document.getElementById('planning-cards-container');
    if (!container) return;
    var cards = container.querySelectorAll('.planning-card');
    var visibleCount = 0;

    for (var i = 0; i < cards.length; i++) {
        var text = cards[i].textContent.toLowerCase();
        var cardType = cards[i].getAttribute('data-plan-type') || 'kanban';
        var isPartial = cards[i].getAttribute('data-partial') === 'true';

        var matchesSearch = (query === '' || text.indexOf(query) !== -1);
        var matchesTab;
        if (currentPlanTypeTab === 'partial') {
            matchesTab = (cardType === 'kanban' && isPartial);
        } else {
            matchesTab = (cardType === currentPlanTypeTab);
        }

        if (matchesSearch && matchesTab) {
            cards[i].style.display = 'flex';
            visibleCount++;
        } else {
            cards[i].style.display = 'none';
        }
    }

    // Show empty state if no partial cards exist
    var emptyEl = document.getElementById('planning-partial-empty');
    if (currentPlanTypeTab === 'partial') {
        if (!emptyEl && visibleCount === 0) {
            var el = document.createElement('div');
            el.id = 'planning-partial-empty';
            el.className = 'text-center py-6 text-sm text-slate-400 bg-slate-50 rounded-xl border border-dashed border-slate-200';
            el.textContent = 'Tidak ada Kanban yang sedang dicicil / inspeksi parsial.';
            container.appendChild(el);
        } else if (emptyEl) {
            emptyEl.style.display = visibleCount === 0 ? 'block' : 'none';
        }
    } else if (emptyEl) {
        emptyEl.style.display = 'none';
    }
}

// Initialize default tab on load & load active workbench session data
document.addEventListener('DOMContentLoaded', function() {
    setPlanningTypeTab(currentPlanTypeTab);
    if (currentSessionId && currentSessionId > 0) {
        loadWorkbenchSessionData(currentSessionId);
    }
});

function loadWorkbenchSessionData(sessionId) {
    if (!sessionId) return;
    currentSessionId = sessionId;

    if (window.history && window.history.pushState) {
        window.history.pushState(null, '', '<?= base_url("modules/inspection/session.php?id=") ?>' + sessionId);
    }

    fetch('<?= base_url("modules/inspection/api/session_detail.php?id=") ?>' + sessionId)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) return;

            if (data.defect_types && Array.isArray(data.defect_types)) {
                DEFECT_TYPES_LIST = data.defect_types;
            }

            var s = data.session;
            var ngRecords = data.ng_records || [];
            var custName = s.customer || 'PT. Indonesia Epson Industry';



            // Update Card 1 Customer Name & Line Name
            var cardCustEl = document.getElementById('card-customer-name');
            if (cardCustEl) {
                cardCustEl.innerHTML = escapeHtml(custName);
            }

            var cardLineEl = document.getElementById('card-session-line-text');
            if (cardLineEl) {
                cardLineEl.textContent = s.line_name || ('Line ' + (s.line_id || 1));
            }

            if (document.getElementById('card-part-code')) document.getElementById('card-part-code').textContent = s.part_code || '-';
            
            var totScanned = parseInt(s.total_scanned_qty || 0);
            var kbTarget = parseInt(s.kanban_qty || 0);
            var prevKbQty = parseInt(data.prev_kanban_qty || s.prev_kanban_qty || 0);
            var accumulatedQty = parseInt(data.accumulated_kanban_qty || (totScanned + prevKbQty));
            var usedSsQty = parseInt(s.use_safety_stock_qty || 0);
            var excQty = parseInt(s.excess_qty || 0);
            var isKanban = (s.inspection_type || 'kanban') === 'kanban';

            var cardQtyEl = document.getElementById('card-total-qty');
            var cardBadgeEl = document.getElementById('card-qty-subbadge');

            if (cardQtyEl) {
                if (isKanban) {
                    var effectiveQty = (prevKbQty > 0) ? accumulatedQty : totScanned;
                    var targetDisp = (kbTarget > 0) ? kbTarget : Math.max(500, effectiveQty);
                    cardQtyEl.textContent = effectiveQty.toLocaleString() + ' / ' + targetDisp.toLocaleString() + ' pcs';
                } else {
                    cardQtyEl.textContent = totScanned.toLocaleString() + ' pcs';
                }
            }

            if (cardBadgeEl) {
                if (isKanban && prevKbQty > 0) {
                    var statusBadge = '';
                    if (kbTarget > 0) {
                        if (accumulatedQty === kbTarget) {
                            statusBadge = '<span style="font-size: 7.5px; font-weight: 800; background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; padding: 0.5px 3.5px; border-radius: 3px; display: inline-block;">PAS KANBAN</span>';
                        } else if (accumulatedQty > kbTarget) {
                            statusBadge = '<span style="font-size: 7.5px; font-weight: 800; background: #fef3c7; color: #92400e; border: 1px solid #fde68a; padding: 0.5px 3.5px; border-radius: 3px; display: inline-block;">LEBIH +' + (accumulatedQty - kbTarget).toLocaleString() + ' PCS</span>';
                        } else {
                            statusBadge = '<span style="font-size: 7.5px; font-weight: 800; background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe; padding: 0.5px 3.5px; border-radius: 3px; display: inline-block;">KURANG ' + (kbTarget - accumulatedQty).toLocaleString() + ' PCS</span>';
                        }
                    }
                    cardBadgeEl.innerHTML = '<div class="mt-0.5" style="line-height: 1.2;">' +
                        '<div style="font-size: 8px; color: #64748b; font-weight: 600; white-space: nowrap;">Sesi ini: <b>' + totScanned.toLocaleString() + '</b> (+' + prevKbQty.toLocaleString() + ')</div>' +
                        (statusBadge ? '<div style="margin-top: 1.5px;">' + statusBadge + '</div>' : '') +
                        '</div>';
                } else {
                    cardBadgeEl.innerHTML = '';
                }
            }

            // Card 2, 3, and 4 are rendered dynamically by renderWorkbenchLotFlow(data)

            // Populate Modal Detail Kanban fields
            if (document.getElementById('modal-kb-plan-type')) document.getElementById('modal-kb-plan-type').textContent = (s.inspection_type === 'safety_stock') ? 'SAFETY STOCK' : 'KANBAN';
            if (document.getElementById('modal-kb-doc-no')) document.getElementById('modal-kb-doc-no').textContent = s.doc_no || '-';
            if (document.getElementById('modal-kb-vendor')) document.getElementById('modal-kb-vendor').textContent = s.kanban_vendor || 'PT. SURYA TECHNOLOGY INDUSTRI';
            if (document.getElementById('modal-kb-customer')) document.getElementById('modal-kb-customer').textContent = '🏢 ' + (s.customer || 'PT. Indonesia Epson Industry');
            if (document.getElementById('modal-kb-kanban-no-badge')) document.getElementById('modal-kb-kanban-no-badge').textContent = '#' + (s.kanban_no || '-');
            if (document.getElementById('modal-kb-item-code')) document.getElementById('modal-kb-item-code').textContent = s.kanban_item_code || s.part_code || '-';
            if (document.getElementById('modal-kb-part-model')) document.getElementById('modal-kb-part-model').textContent = s.part_model || '-';
            if (document.getElementById('modal-kb-item-desc')) document.getElementById('modal-kb-item-desc').textContent = s.kanban_item_desc || s.part_name || '-';
            if (document.getElementById('modal-kb-cavity')) document.getElementById('modal-kb-cavity').textContent = 'Cavity ' + (s.cavity || '1');
            function formatKanbanDT(dtStr) {
                if (!dtStr || dtStr === '-' || dtStr === '0000-00-00 00:00:00') return '-';
                var d = new Date(String(dtStr).replace(/-/g, '/'));
                if (isNaN(d.getTime())) return dtStr + ' WIB';
                var months = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
                var day = ('0' + d.getDate()).slice(-2);
                var month = months[d.getMonth()];
                var year = d.getFullYear();
                var hours = ('0' + d.getHours()).slice(-2);
                var mins = ('0' + d.getMinutes()).slice(-2);
                return day + ' ' + month + ' ' + year + ', ' + hours + ':' + mins + ' WIB';
            }
            if (document.getElementById('modal-kb-eta')) document.getElementById('modal-kb-eta').textContent = formatKanbanDT(s.kanban_eta);
            if (document.getElementById('modal-kb-req-date')) document.getElementById('modal-kb-req-date').textContent = formatKanbanDT(s.kanban_req_date);
            if (document.getElementById('modal-kb-qty')) document.getElementById('modal-kb-qty').textContent = (s.kanban_qty ? parseInt(s.kanban_qty).toLocaleString() : '0') + ' pcs';
            if (document.getElementById('modal-kb-str-loc')) document.getElementById('modal-kb-str-loc').textContent = s.kanban_str_loc || '-';
            if (document.getElementById('modal-kb-check-type')) document.getElementById('modal-kb-check-type').textContent = s.kanban_check_type ? (s.kanban_check_type + ' Cek') : 'Normal Cek';
            if (document.getElementById('modal-kb-remark')) document.getElementById('modal-kb-remark').textContent = s.kanban_remark || '-';

            // Populate Group 2.3 Akumulasi Sesi Kanban
            var accumBox = document.getElementById('modal-kb-accumulated-box');
            var accumStatus = document.getElementById('modal-kb-accumulated-status');
            var sessListEl = document.getElementById('modal-kb-sessions-list');
            var prevSessions = data.prev_kanban_sessions || s.prev_kanban_sessions || [];

            if (accumBox) {
                if (isKanban && prevKbQty > 0) {
                    accumBox.style.display = 'block';
                    accumBox.classList.remove('hidden');
                    if (accumStatus) {
                        accumStatus.textContent = accumulatedQty.toLocaleString() + ' / ' + kbTarget.toLocaleString() + ' pcs';
                    }
                    if (sessListEl) {
                        var sessHtml = '';
                        prevSessions.forEach(function(ps) {
                            var stText = ps.status_text || (ps.status === 'passed' ? 'Lulus' : 'Parsial');
                            var pQty = Number(ps.passed_qty !== undefined ? ps.passed_qty : ps.total_scanned_qty);
                            sessHtml += '<div class="flex items-center justify-between py-0.5 border-b border-emerald-100">' +
                                '<span>Sesi #' + ps.id + ' (' + stText + ' - ' + escapeHtml(ps.inspector_name || 'QC Inspector') + '):</span>' +
                                '<span class="font-mono font-bold text-emerald-800">' + pQty.toLocaleString() + ' pcs</span>' +
                                '</div>';
                        });
                        sessHtml += '<div class="flex items-center justify-between py-0.5 font-bold text-emerald-950">' +
                            '<span>Sesi #' + s.id + ' (Sesi Berjalan):</span>' +
                            '<span class="font-mono" id="modal-kb-current-sess-qty">' + totScanned.toLocaleString() + ' pcs</span>' +
                            '</div>';
                        sessListEl.innerHTML = sessHtml;
                    }
                } else {
                    accumBox.style.display = 'none';
                    accumBox.classList.add('hidden');
                }
            }

            // Render Multi-Lot & Scanned Session Lots
            var lotsList = data.session_lots || [];
            var lotSumList = data.lot_summary || [];



            // Modal Group 2 Lot No
            if (document.getElementById('modal-kb-lot-no')) {
                if (lotSumList.length > 1) {
                    document.getElementById('modal-kb-lot-no').innerHTML = '<span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-extrabold bg-amber-100 text-amber-900 border border-amber-300 font-mono">📦 Multi-Lot (' + lotSumList.length + ' Lot)</span>';
                } else {
                    document.getElementById('modal-kb-lot-no').textContent = s.lot_number || '-';
                }
            }

            // Group 2.5 Multi-Lot Summary Cards
            var lotSumContainer = document.getElementById('modal-kb-lot-summary-container');
            if (lotSumContainer) {
                if (lotSumList.length > 1) {
                    var sumHtml = '';
                    lotSumList.forEach(function(ls) {
                        sumHtml += '<div class="p-1.5 bg-white border border-indigo-200 rounded-lg flex items-center justify-between shadow-2xs">' +
                                   '<span class="font-mono font-bold text-slate-800 text-[10px]">#' + escapeHtml(ls.lot_number) + '</span>' +
                                   '<span class="font-mono font-extrabold text-blue-800 text-[10px]">' + (parseInt(ls.total_qty) || 0).toLocaleString() + ' pcs (' + ls.label_count + ' Box)</span>' +
                                   '</div>';
                    });
                    lotSumContainer.innerHTML = sumHtml;
                    lotSumContainer.classList.remove('hidden');
                } else {
                    lotSumContainer.classList.add('hidden');
                    lotSumContainer.innerHTML = '';
                }
            }

            // Populate Form Input NG Lot Select Dropdown
            var lotSelect = document.getElementById('ng-session-lot-select');
            if (lotSelect) {
                var prevVal = lotSelect.value;
                var lotOptHtml = '<option value="">-- Pilih Box / Lot Number yang Temuan NG --</option>';
                if (lotsList.length > 0) {
                    lotsList.forEach(function(lRow) {
                        var lRefStr = lRow.ref_number ? (' — Ref Box: ' + escapeHtml(lRow.ref_number)) : '';
                        var isSel = (lotsList.length === 1 || String(lRow.id) === String(prevVal)) ? 'selected' : '';
                        lotOptHtml += '<option value="' + lRow.id + '" ' + isSel + '>' +
                                      'Lot: ' + escapeHtml(lRow.lot_number) + lRefStr + ' (' + (parseInt(lRow.qty) || 0).toLocaleString() + ' pcs)' +
                                      '</option>';
                    });
                }
                lotSelect.innerHTML = lotOptHtml;
            }

            // Group 2.5 Scanned Session Lots Table Body
            if (document.getElementById('modal-kb-lots-count')) document.getElementById('modal-kb-lots-count').textContent = lotsList.length;
            if (document.getElementById('modal-kb-lots-total-qty')) document.getElementById('modal-kb-lots-total-qty').textContent = totScanned.toLocaleString() + ' pcs';

            var lotsTbody = document.getElementById('modal-kb-session-lots-tbody');
            if (lotsTbody) {
                if (lotsList.length === 0) {
                    lotsTbody.innerHTML = '<tr><td colspan="4" class="px-2.5 py-3 text-center text-slate-400 italic">Belum ada rincian label box yang tercatat.</td></tr>';
                } else {
                    var lotsHtml = '';
                    lotsList.forEach(function(lbl, idx) {
                        var lRef = lbl.ref_number ? escapeHtml(lbl.ref_number) : '-';
                        var lLot = escapeHtml(lbl.lot_number || s.lot_number || '-');
                        var lQty = (parseInt(lbl.qty) || 0).toLocaleString();
                        var isSs = (lbl.remarks && lbl.remarks.indexOf('Safety Stock') !== -1) || lbl.is_safety_stock;
                        var originBadge = isSs 
                            ? '<span class="bg-purple-100 text-purple-900 border border-purple-300 text-[9px] font-black px-2 py-0.5 rounded-full inline-flex items-center ml-1" title="Lot dipenuhi dari Alokasi Safety Stock">Safety Stock</span>'
                            : '<span class="bg-blue-100 text-blue-900 border border-blue-300 text-[9px] font-black px-2 py-0.5 rounded-full inline-flex items-center ml-1" title="Lot berasal dari scan label box">Scan Label</span>';

                        lotsHtml += '<tr class="hover:bg-indigo-50/50 transition-colors">' +
                                    '<td class="px-2.5 py-1.5 font-bold text-slate-400">' + (idx + 1) + '</td>' +
                                    '<td class="px-2.5 py-1.5 font-mono font-bold text-blue-800 text-[10px]"><span class="bg-blue-50 px-1.5 py-0.5 rounded border border-blue-200">' + lRef + '</span>' + originBadge + '</td>' +
                                    '<td class="px-2.5 py-1.5 font-mono font-bold text-slate-800">' + lLot + '</td>' +
                                    '<td class="px-2.5 py-1.5 font-mono font-extrabold text-slate-900 text-right">' + lQty + ' pcs</td>' +
                                    '</tr>';
                    });
                    lotsTbody.innerHTML = lotsHtml;
                }
            }

            var cardTypeEl = document.getElementById('card-type-badge');
            if (cardTypeEl) {
                if (s.inspection_type === 'safety_stock') {
                    cardTypeEl.innerHTML = '<span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-extrabold bg-purple-100 text-purple-800 border border-purple-200 truncate">📦 SAFETY STOCK</span>';
                } else {
                    cardTypeEl.innerHTML = '<span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-extrabold bg-blue-100 text-blue-800 border border-blue-200 truncate">🚚 KANBAN</span>';
                }
            }

            // Render Workbench Per-Lot Flow
            renderWorkbenchLotFlow(data);

            // Global State & Real-Time Lot State (Selalu di-update untuk semua status)
            var ngLotsData = data.ng_lots || [];
            currentNgLots = ngLotsData;
            currentSessionLots = data.session_lots || [];
            if (data.session) {
                currentSessionObj = data.session;
                if (data.session.part_code) {
                    currentSessionPartCode = data.session.part_code;
                }
            }

            // Render Substitution Lineage Modal & Buttons (Top Navbar & Card Header)
            var btnTopSubst    = document.getElementById('btn-top-substitution-log');
            var btnCardSubst   = document.getElementById('btn-card-substitution-log');
            var topSubstCount  = document.getElementById('top-subst-log-count');
            var cardSubstCount = document.getElementById('card-subst-log-count');
            var modalSubstBody = document.getElementById('modal-subst-log-list-body');
            var modalKbSubWrap = document.getElementById('modal-kb-substitution-container');
            var modalKbSubList = document.getElementById('modal-kb-substitution-list');
            var substLog       = data.substitution_log || [];

            if (substLog.length > 0) {
                if (btnTopSubst) {
                    btnTopSubst.classList.remove('hidden');
                    btnTopSubst.style.display = 'flex';
                }
                if (btnCardSubst) {
                    btnCardSubst.classList.remove('hidden');
                    btnCardSubst.style.display = 'inline-flex';
                }
                if (topSubstCount) topSubstCount.textContent = substLog.length;
                if (cardSubstCount) cardSubstCount.textContent = substLog.length;

                var sHtml = '';
                substLog.forEach(function(item) {
                    var ngLotStr  = item.ng_lot_number ? ('#' + escapeHtml(item.ng_lot_number)) : 'Lot Original';
                    var ngRefStr  = item.ng_lot_ref ? (' (Ref: ' + escapeHtml(item.ng_lot_ref) + ')') : '';
                    var repLotStr = item.rep_lot_number ? ('#' + escapeHtml(item.rep_lot_number)) : 'Lot Baru';
                    var repRefStr = item.rep_lot_ref ? (' (Ref: ' + escapeHtml(item.rep_lot_ref) + ')') : '';

                    var actionLabel = (item.action_type === 'replace_ng_only') ? 'Ganti Lot NG' :
                                      (item.action_type === 'replace_all_lots') ? 'Ganti Semua Lot' : 'Re-Inspeksi';

                    sHtml += '<div style="background:#ffffff; border:1px solid #cbd5e1; border-radius:10px; padding:10px 12px; font-size:11px; box-shadow:0 1px 3px rgba(0,0,0,0.04);">' +
                                '<div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:6px;">' +
                                    '<span style="font-size:10px; font-weight:800; color:#166534; background:#dcfce7; border:1px solid #bbf7d0; padding:2px 8px; border-radius:6px;">' + actionLabel + '</span>' +
                                    '<span style="font-size:9.5px; color:#64748b; font-weight:600;">' + escapeHtml(item.actioned_by || 'QC') + ' • ' + (item.created_at || '') + '</span>' +
                                '</div>' +
                                '<div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap; font-family:monospace;">' +
                                    '<div style="background:#ffe4e6; color:#9f1239; border:1px solid #fecdd3; padding:4px 9px; border-radius:6px; font-weight:700;">' +
                                        '🎯 ' + ngLotStr + '<span style="font-size:9px; opacity:0.85;">' + ngRefStr + '</span>' +
                                    '</div>' +
                                    '<span style="font-size:13px; font-weight:900; color:#475569;">➔</span>' +
                                    '<div style="background:#dcfce7; color:#166534; border:1px solid #bbf7d0; padding:4px 9px; border-radius:6px; font-weight:800;">' +
                                        '📦 ' + repLotStr + '<span style="font-size:9px; opacity:0.85;">' + repRefStr + '</span>' +
                                    '</div>' +
                                '</div>' +
                                (item.notes ? '<div style="font-size:10px; color:#475569; margin-top:6px; font-style:italic;">💬 ' + escapeHtml(item.notes) + '</div>' : '') +
                             '</div>';
                });
                if (modalSubstBody) modalSubstBody.innerHTML = sHtml;
                if (modalKbSubList) modalKbSubList.innerHTML = sHtml;
                if (modalKbSubWrap) modalKbSubWrap.classList.remove('hidden');
            } else {
                if (btnTopSubst) {
                    btnTopSubst.classList.add('hidden');
                    btnTopSubst.style.display = 'none';
                }
                if (btnCardSubst) {
                    btnCardSubst.classList.add('hidden');
                    btnCardSubst.style.display = 'none';
                }
                if (modalSubstBody) modalSubstBody.innerHTML = '<p style="font-size:12px; color:#64748b; text-align:center; padding:16px 0;">Belum ada riwayat penggantian lot untuk sesi ini.</p>';
                if (modalKbSubWrap) modalKbSubWrap.classList.add('hidden');
                if (modalKbSubList) modalKbSubList.innerHTML = '';
            }

            // Tombol Ringkas Tindakan Re-Inspeksi Kanban (Hanya muncul jika REJECTED dan ada lot NG aktif)
            var ngBatchWrap = document.getElementById('ng-batch-action-wrapper');
            var countBadge  = document.getElementById('ng-batch-count-badge');
            var actionableLots = ngLotsData.filter(function(l) { return l.lot_status === 'ng_found' || l.lot_status === 'ng_quarantine'; });
            if (s.status === 'rejected' && actionableLots.length > 0) {
                if (ngBatchWrap) {
                    ngBatchWrap.classList.remove('hidden');
                    ngBatchWrap.style.display = 'block';
                }
                if (countBadge) countBadge.textContent = actionableLots.length + ' Lot NG';
            } else if (ngBatchWrap) {
                ngBatchWrap.classList.add('hidden');
                ngBatchWrap.style.display = 'none';
            }

            // Badge RE-INSPEKSI di Header (Selalu di-update untuk semua status sesi)
            var reinspWrapper = document.getElementById('header-reinspection-wrapper');
            if (reinspWrapper) {
                if (s.is_reinspection) {
                    var rTypeLabels = {
                        'rescan_restart':   'Scan Ulang (Awal)',
                        'rescan_continue':  'Scan Ulang (Lanjut)',
                        'rescan_same_lot':  'Scan Ulang',
                        'replace_ng_only':  'Ganti Lot NG',
                        'replace_all_lots': 'Ganti Semua Lot'
                    };
                    var rTypeLabel = rTypeLabels[s.reinspection_type] || 'Re-Inspeksi';
                    var roundNum = s.reinspection_round || 1;
                    var reinspHtml = '<span class="inline-flex items-center px-2 py-0.5 rounded-md text-[11px] font-extrabold bg-amber-100 text-amber-800 border border-amber-300 shadow-2xs ml-2 flex-shrink-0">' +
                                     '⚡ RE-INSPEKSI #' + roundNum + ' (' + escapeHtml(rTypeLabel) + ')' +
                                     '</span>';
                    if (s.parent_session_id) {
                        reinspHtml += '<a href="<?= base_url("modules/inspection/session.php?id=") ?>' + s.parent_session_id + '" class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-semibold bg-slate-100 text-slate-600 border border-slate-300 ml-2 flex-shrink-0 hover:bg-slate-200 transition-colors">' +
                                      '← Sesi Original #' + s.parent_session_id +
                                      '</a>';
                    }
                    reinspWrapper.innerHTML = reinspHtml;
                } else {
                    reinspWrapper.innerHTML = '';
                }
            }

            // Card 4 finished banner and lot cards are managed by renderWorkbenchLotFlow(data)

            var pdfFrame = document.getElementById('pdf-frame');
            var placeholder = document.getElementById('drawing-placeholder-box');
            var placeholderPartText = document.getElementById('placeholder-part-code-text');
            if (placeholderPartText) placeholderPartText.textContent = s.part_code || 'TECHNICAL DRAWING';

            if (s.drawing_2d) {
                if (pdfFrame) {
                    pdfFrame.src = s.drawing_2d + '#toolbar=0';
                    pdfFrame.style.display = 'block';
                }
                if (placeholder) placeholder.style.display = 'none';
            } else {
                if (pdfFrame) {
                    pdfFrame.src = '';
                    pdfFrame.style.display = 'none';
                }
                if (placeholder) placeholder.style.display = 'flex';
            }

            var cad3dViewport = document.getElementById('cad-3d-viewport');
            var cad3dPlaceholder = document.getElementById('cad-3d-placeholder-box');
            if (s.drawing_3d) {
                if (cad3dViewport) {
                    cad3dViewport.style.display = 'block';
                    cad3dViewport.setAttribute('data-stp-url', s.drawing_3d);
                }
                if (cad3dPlaceholder) cad3dPlaceholder.style.display = 'none';
                is3dViewerInitialized = false;
                var box3d = document.getElementById('viewport-3d');
                if (box3d && !box3d.classList.contains('hidden')) {
                    init3dViewer();
                }
            } else {
                if (cad3dViewport) {
                    cad3dViewport.style.display = 'none';
                    cad3dViewport.removeAttribute('data-stp-url');
                }
                if (cad3dPlaceholder) cad3dPlaceholder.style.display = 'flex';
            }

            if (document.getElementById('scan-modal-overlay')) {
                document.getElementById('scan-modal-overlay').classList.add('hidden');
                document.getElementById('scan-modal-overlay').style.display = 'none';
            }
        })
        .catch(function(err) {
            console.error('Error reloading session data:', err);
        });
}

function triggerModalValidation() {
    // 1. Selalu gunakan Part Code resmi Kanban yang dipilih (atau dari daftar label yang sudah tervalidasi)
    var pCode = selectedPlanningPartCode || (scannedLabelsList.length > 0 ? scannedLabelsList[0].Z1 : document.getElementById('scan-part-code').value.trim());
    var lNum = '';

    if (scannedLabelsList.length > 0) {
        var lastLbl = scannedLabelsList[scannedLabelsList.length - 1];
        if (selectedPlanningPartCode) pCode = selectedPlanningPartCode;
        else if (lastLbl.Z1) pCode = lastLbl.Z1;
        lNum = lastLbl.Z2;
    } else {
        lNum = document.getElementById('scan-lot-number').value.trim();
    }

    if (!pCode || !lNum) {
        Swal.fire({
            icon: 'warning',
            title: 'Scan Label Diperlukan',
            text: 'Silakan scan setidaknya 1 label barcode QR sebelum memuat inspeksi!'
        });
        return;
    }

    var totalScanned = 0;
    scannedLabelsList.forEach(function(l) { totalScanned += parseInt(l.Z3) || 0; });
    if (totalScanned === 0) {
        var rawQtyVal = parseInt(document.getElementById('scan-label-qty') ? document.getElementById('scan-label-qty').value : 0) || 0;
        if (rawQtyVal > 0) totalScanned = rawQtyVal;
    }

    if (totalScanned === 0 && scannedLabelsList.length === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Scan Label Diperlukan',
            text: 'Silakan scan setidaknya 1 label barcode QR sebelum memuat inspeksi!'
        });
        return;
    }

    if (!document.fullscreenElement && !document.webkitFullscreenElement && !document.msFullscreenElement) {
        var el = document.documentElement;
        if (el.requestFullscreen) {
            el.requestFullscreen().catch(function(e){});
        } else if (el.webkitRequestFullscreen) {
            el.webkitRequestFullscreen();
        } else if (el.msRequestFullscreen) {
            el.msRequestFullscreen();
        }
    }

    document.getElementById('modal-loading').classList.remove('hidden');
    document.getElementById('modal-error-box').classList.add('hidden');

    var scannedRefList = [];
    var refVal = '';
    if (typeof scannedLabelsList !== 'undefined' && scannedLabelsList.length > 0) {
        scannedLabelsList.forEach(function(l) {
            if (l.Z5) scannedRefList.push(l.Z5);
        });
        refVal = scannedLabelsList[scannedLabelsList.length - 1].Z5 || '';
    } else {
        refVal = document.getElementById('scan-ref-number') ? document.getElementById('scan-ref-number').value.trim() : '';
        if (refVal && scannedRefList.indexOf(refVal) === -1) {
            scannedRefList.push(refVal);
        }
    }

    var validateUrl = '<?= base_url("modules/inspection/scan_validate.php") ?>?part_code=' + encodeURIComponent(pCode) + 
                      '&lot_number=' + encodeURIComponent(lNum) + 
                      '&ref_number=' + encodeURIComponent(refVal) + 
                      '&scanned_ref_numbers=' + encodeURIComponent(JSON.stringify(scannedRefList)) + 
                      '&total_scanned_qty=' + totalScanned + 
                      '&kanban_item_id=' + selectedPlanningId + 
                      '&inspection_type=' + encodeURIComponent(selectedPlanningType);

    fetch(validateUrl)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) {
                document.getElementById('modal-loading').classList.add('hidden');
                document.getElementById('modal-error-box').classList.remove('hidden');
                document.getElementById('modal-error-msg').textContent = data.message;
                return;
            }

            // 1. Kasus PASSED: Auto-Bypass & Cegah Data Ganda
            if (data.already_safety_stock_passed) {
                document.getElementById('modal-loading').classList.add('hidden');

                if (selectedPlanningType === 'safety_stock') {
                    // Alert khusus scan Safety Stock Gudang
                    var refDisp = data.matched_ref_number ? (' (Ref Number: <b style="color: #1d4ed8; font-family: monospace;">' + escapeHtml(data.matched_ref_number) + '</b>)') : '';
                    Swal.fire({
                        title: 'Ref Number / Label Box Sudah Terdaftar',
                        html: '<div style="font-size: 12px; text-align: left; line-height: 1.6; color: #334155;">' +
                              'Label Box' + refDisp + ' dengan Lot Number <b style="color: #1d4ed8; font-family: monospace;">' + escapeHtml(lNum) + '</b> (Part Code: <b>' + escapeHtml(pCode) + '</b>) ' +
                              '<b>SUDAH TERDAFTAR & PASSED (Lolos)</b> di Safety Stock Gudang oleh QC <b>' + escapeHtml(data.inspector_name || 'Inspector QC') + '</b>.<br><br>' +
                              '<div style="background-color: #eff6ff; border: 1px solid #bfdbfe; padding: 8px 12px; border-radius: 8px; color: #1e40af; font-weight: 600;">' +
                              'Stok label box ini sudah tersimpan dalam persediaan Safety Stock Gudang.' +
                              '</div>' +
                              '</div>',
                        icon: 'info',
                        showCancelButton: true,
                        confirmButtonColor: '#2563eb',
                        cancelButtonColor: '#64748b',
                        confirmButtonText: 'Lihat Detail Safety Stock',
                        cancelButtonText: 'Monitoring Safety Stock'
                    }).then(function(res) {
                        if (res.isConfirmed) {
                            window.location.href = '<?= base_url("modules/safety_stock/detail.php?id=") ?>' + (data.kanban_item_id || data.session_id);
                        } else if (res.dismiss === Swal.DismissReason.cancel) {
                            window.location.href = '<?= base_url("modules/safety_stock/index.php") ?>';
                        }
                    });
                    return;
                }

                // Alert khusus inspeksi Kanban Customer (Auto-fulfillment Notification)
                Swal.fire({
                    title: 'Selamat! Lolos Safety Stock',
                    html: '<div style="font-size: 12px; text-align: left; line-height: 1.6; color: #334155;">' +
                          'Barang dengan Lot Number <b style="color: #1d4ed8; font-family: monospace;">' + escapeHtml(lNum) + '</b> (Part Code: <b>' + escapeHtml(pCode) + '</b>) ' +
                          '<b>SUDAH TERDAFTAR & PASSED (Lolos)</b> di Safety Stock oleh QC <b>' + escapeHtml(data.inspector_name || 'Inspector QC') + '</b>.<br><br>' +
                          '<div style="background-color: #f0fdf4; border: 1px solid #bbf7d0; padding: 8px 12px; border-radius: 8px; color: #166534; font-weight: 700;">' +
                          '✓ Item Kanban Planning untuk customer <u>' + escapeHtml(data.customer || 'PT Customer') + '</u> otomatis ditandai PASSED dengan Lot Number #' + escapeHtml(lNum) + '.' +
                          '</div>' +
                          '</div>',
                    icon: 'success',
                    showCancelButton: true,
                    confirmButtonColor: '#2563eb',
                    cancelButtonColor: '#64748b',
                    confirmButtonText: 'Lihat Realisasi Pekerjaan QC (Monitoring)',
                    cancelButtonText: 'Lihat Detail Safety Stock'
                }).then(function(res) {
                    if (res.isConfirmed) {
                        window.location.href = '<?= base_url("modules/monitoring/index.php") ?>';
                    } else if (res.dismiss === Swal.DismissReason.cancel) {
                        window.location.href = '<?= base_url("modules/safety_stock/detail.php?id=") ?>' + (data.kanban_item_id || data.session_id);
                    }
                });
                return;
            }

            // 2. Kasus REJECTED: Peringatan NG & Opsi Cek Ulang
            if (data.already_safety_stock_rejected) {
                document.getElementById('modal-loading').classList.add('hidden');
                Swal.fire({
                    title: '⚠️ Perhatian: Lot Pernah REJECTED',
                    html: '<div style="font-size: 12px; text-align: left; line-height: 1.6; color: #334155;">' +
                          'Lot Number <b style="color: #be123c; font-family: monospace;">' + escapeHtml(lNum) + '</b> (Part Code: <b>' + escapeHtml(pCode) + '</b>) ' +
                          'sebelumnya pernah tercatat ber-status <b style="color: #be123c;">REJECTED (NG)</b> di Safety Stock oleh <b>' + escapeHtml(data.inspector_name || 'Inspector QC') + '</b>.<br><br>' +
                          'Apakah Anda ingin melakukan <b>Cek Ulang (Re-Inspection)</b> untuk memverifikasi perbaikan barang?' +
                          '</div>',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d97706',
                    cancelButtonColor: '#64748b',
                    confirmButtonText: '🔄 Ya, Lakukan Cek Ulang',
                    cancelButtonText: '🚨 Lihat Catatan Defect NG'
                }).then(function(res) {
                    if (res.isConfirmed) {
                        executeCreateSession(data, pCode, lNum);
                    } else if (res.dismiss === Swal.DismissReason.cancel) {
                        window.location.href = '<?= base_url("modules/safety_stock/detail.php?id=") ?>' + (data.kanban_item_id || data.session_id);
                    }
                });
                return;
            }

            executeCreateSession(data, pCode, lNum);
        })
        .catch(function(err) {
            document.getElementById('modal-loading').classList.add('hidden');
            document.getElementById('modal-error-box').classList.remove('hidden');
            document.getElementById('modal-error-msg').textContent = 'Gagal memproses validasi label QR!';
        });
}

function executeCreateSession(data, pCode, lNum) {
    var deviceLine = localStorage.getItem('oqc_device_line_name') || localStorage.getItem('oqc_device_line');
    var deviceLineId = localStorage.getItem('oqc_device_line_id') || 0;
    if (!deviceLine || deviceLine.trim() === '') {
        document.getElementById('modal-loading').classList.add('hidden');
        promptDeviceLine(false);
        return;
    }

    document.getElementById('modal-loading').classList.remove('hidden');

    var totalScanned = 0;
    scannedLabelsList.forEach(function(l) { totalScanned += parseInt(l.Z3) || 0; });
    
    var totalCheckedQty = 0;
    availableSsLots.forEach(function(lot) {
        if (selectedSsLotIds.indexOf(String(lot.lot_id)) !== -1) {
            totalCheckedQty += parseInt(lot.available_qty) || 0;
        }
    });
    var usedSsQty = (isSafetyStockDeducted && selectedSsLotIds.length > 0) ? Math.min(selectedPlanningQty, totalCheckedQty) : 0;

    // Fix: Only apply fallback default scanned qty if NO safety stock was used AND no labels were scanned
    if (totalScanned === 0 && usedSsQty === 0) {
        totalScanned = data.aql ? (parseInt(data.aql.total_qty) || selectedPlanningQty) : selectedPlanningQty;
    }

    var targetKanban = (selectedPlanningQty > 0) ? Math.max(0, selectedPlanningQty - usedSsQty) : totalScanned;
    var excessQty = Math.max(0, totalScanned - targetKanban);

    // Fallback if scannedLabelsList empty AND no safety stock was used
    if (scannedLabelsList.length === 0 && usedSsQty === 0) {
        scannedLabelsList.push({
            Z1: pCode,
            Z2: lNum,
            Z3: totalScanned,
            Z4: '',
            Z5: 'REF-' + Date.now(),
            raw: 'MANUAL|Z1' + pCode + '|Z2' + lNum
        });
    }

    var payload = new FormData();
    payload.append('action', 'start_session');
    payload.append('part_code', data.part ? data.part.part_code : pCode);
    payload.append('lot_number', lNum);
    payload.append('did_id', data.did ? data.did.id : 0);
    payload.append('kanban_item_id', data.kanban ? data.kanban.id : selectedPlanningId);
    payload.append('inspection_type', data.inspection_type || selectedPlanningType);
    payload.append('part_id', data.part ? data.part.id : 0);
    payload.append('device_line_id', deviceLineId);
    payload.append('device_line', deviceLine);
    payload.append('sample_size', data.aql ? data.aql.sample_size : 50);
    payload.append('reject_number', data.aql ? data.aql.reject_number : 1);
    payload.append('total_scanned_qty', totalScanned + usedSsQty);
    payload.append('excess_qty', excessQty);
    payload.append('use_safety_stock_qty', usedSsQty);
    payload.append('selected_ss_lot_ids', JSON.stringify(selectedSsLotIds));
    payload.append('selected_ss_session_ids', JSON.stringify(selectedSsSessionIds));
    payload.append('scanned_labels', JSON.stringify(scannedLabelsList));

    fetch('<?= base_url("modules/inspection/create.php") ?>', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: payload
    })
    .then(function(res) { return res.json(); })
    .then(function(resData) {
        document.getElementById('modal-loading').classList.add('hidden');
        if (resData.success && (resData.url || resData.session_id)) {
            var newId = resData.session_id || parseInt((resData.url || '').split('id=')[1]);
            loadWorkbenchSessionData(newId);
        } else {
            document.getElementById('modal-error-box').classList.remove('hidden');
            document.getElementById('modal-error-msg').textContent = resData.message || 'Gagal membuat sesi inspeksi!';
        }
    })
    .catch(function(err) {
        document.getElementById('modal-loading').classList.add('hidden');
        document.getElementById('modal-error-box').classList.remove('hidden');
        document.getElementById('modal-error-msg').textContent = 'Gagal memproses pembuatan sesi inspeksi!';
    });
}

// =========================================================================
// PER-LOT AQL WORKBENCH RENDERING & INTERACTION HANDLERS
// =========================================================================

var currentLotFilterTab = 'all';
var activeFocusLotId = null;
var isAllBoxesExpanded = false;

function toggleAllBoxesView() {
    isAllBoxesExpanded = !isAllBoxesExpanded;
    var contentEl = document.getElementById('all-boxes-content');
    var chevronEl = document.getElementById('all-boxes-chevron');
    var titleEl = document.getElementById('all-boxes-toggle-title');
    var totalBoxes = (window.lastWorkbenchSessionData && window.lastWorkbenchSessionData.session_lots) ? 
        window.lastWorkbenchSessionData.session_lots.length : 0;

    if (!contentEl) return;

    if (isAllBoxesExpanded) {
        contentEl.style.display = 'flex';
        if (chevronEl) chevronEl.style.transform = 'rotate(180deg)';
        if (titleEl) titleEl.textContent = 'Sembunyikan Ringkasan Seluruh Box (' + totalBoxes + ' Box)';
    } else {
        contentEl.style.display = 'none';
        if (chevronEl) chevronEl.style.transform = 'rotate(0deg)';
        if (titleEl) titleEl.textContent = 'Lihat Ringkasan Seluruh Box (' + totalBoxes + ' Box)';
    }
}

function advanceFocusBoxAfterAction(currentLotId) {
    if (!window.lastWorkbenchSessionData || !window.lastWorkbenchSessionData.session_lots) return;
    var allLots = window.lastWorkbenchSessionData.session_lots;
    if (allLots.length <= 1) return;

    var curId = parseInt(currentLotId);
    var currentIdx = allLots.findIndex(function(l) { return l.id === curId; });
    if (currentIdx === -1) currentIdx = 0;

    // Helper untuk cek apakah sebuah lot belum dicek (pending)
    function isLotPending(lot) {
        if (!lot) return false;
        if (lot.id === curId) return false; // Lot yang baru saja di-submit dianggap sudah selesai
        return (lot.lot_result === 'in_progress' && lot.lot_status !== 'replaced');
    }

    // 1. Cek apakah box persis setelah ini (currentIdx + 1) belum dicek
    var nextLot = (currentIdx + 1 < allLots.length) ? allLots[currentIdx + 1] : null;
    if (isLotPending(nextLot)) {
        activeFocusLotId = nextLot.id;
        return;
    }

    // 2. Kalau box berikutnya sudah dicek/di-skip, cari yang belum di-inspeksi ke arah depan
    var foundPending = null;
    for (var i = currentIdx + 1; i < allLots.length; i++) {
        if (isLotPending(allLots[i])) {
            foundPending = allLots[i];
            break;
        }
    }

    // 3. Kalau ke depan tidak ada yang belum dicek, cari dari awal (0 ke currentIdx - 1)
    if (!foundPending) {
        for (var j = 0; j < currentIdx; j++) {
            if (isLotPending(allLots[j])) {
                foundPending = allLots[j];
                break;
            }
        }
    }

    if (foundPending) {
        activeFocusLotId = foundPending.id;
        return;
    }

    // 4. Kalau SEMUA box sudah selesai diinspeksi (tidak ada yang pending lagi):
    // Pindah ke box berikutnya dalam urutan (atau kembali ke box 1 jika sudah di paling akhir)
    if (currentIdx + 1 < allLots.length) {
        activeFocusLotId = allLots[currentIdx + 1].id;
    } else {
        activeFocusLotId = allLots[0].id;
    }
}

function changeFocusBox(lotId) {
    activeFocusLotId = parseInt(lotId);
    if (window.lastWorkbenchSessionData) {
        renderWorkbenchLotFlow(window.lastWorkbenchSessionData);
    }
}

function navigateFocusBox(direction) {
    if (!window.lastWorkbenchSessionData || !window.lastWorkbenchSessionData.session_lots) return;
    var lots = window.lastWorkbenchSessionData.session_lots;
    if (lots.length <= 1) return;

    var currentIdx = lots.findIndex(function(l) { return l.id === activeFocusLotId; });
    if (currentIdx === -1) currentIdx = 0;

    var newIdx = currentIdx + direction;
    if (newIdx >= 0 && newIdx < lots.length) {
        activeFocusLotId = lots[newIdx].id;
        renderWorkbenchLotFlow(window.lastWorkbenchSessionData);
    }
}

function confirmPassAllLots() {
    if (!currentSessionId) return;

    var lots = (window.lastWorkbenchSessionData && window.lastWorkbenchSessionData.session_lots) || [];
    var pendingLots = lots.filter(function(l) {
        return (l.lot_result === 'in_progress' && l.lot_status !== 'replaced');
    });

    if (pendingLots.length === 0) {
        Swal.fire({
            icon: 'info',
            title: 'Tidak Ada Lot Pending',
            text: 'Semua box pada sesi ini sudah selesai diperiksa.'
        });
        return;
    }

    Swal.fire({
        title: 'Tandai Semua Box PASSED?',
        html: 'Apakah Anda yakin ingin menandai seluruh <b>' + pendingLots.length + ' box</b> yang belum dicek sebagai <b>PASSED (Lolos 0 Defect)</b> sekaligus?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#059669',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Ya, Tandai Semua PASSED',
        cancelButtonText: 'Batal'
    }).then(function(result) {
        if (!result.isConfirmed) return;

        Swal.fire({
            title: 'Memproses Semua Box...',
            text: 'Mohon tunggu sebentar',
            allowOutsideClick: false,
            didOpen: function() { Swal.showLoading(); }
        });

        var payload = {
            session_id: parseInt(currentSessionId),
            action: 'pass_all'
        };

        fetch('<?= base_url("modules/inspection/api/submit_lot_result.php") ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (!res.success) {
                Swal.fire({
                    icon: 'error',
                    title: 'Gagal',
                    text: res.message || 'Terjadi kesalahan sistem'
                });
                return;
            }

            Swal.fire({
                icon: 'success',
                title: 'Semua Box PASSED!',
                text: res.message || 'Seluruh box berhasil dinyatakan PASSED.',
                timer: 1500,
                showConfirmButton: false
            });
            loadWorkbenchSessionData(currentSessionId);
        })
        .catch(function(err) {
            Swal.fire({
                icon: 'error',
                title: 'Koneksi Error',
                text: 'Gagal terhubung ke server!'
            });
        });
    });
}

function generateSingleLotCardHtml(lot, ngRecordsByLot, s, isFocusMode) {
    var lotId = lot.id;
    var lotNo = lot.lot_number || '-';
    var refNo = lot.ref_number || '';
    var qty = parseInt(lot.qty) || 0;
    var aqlLevel = lot.aql_level || s.aql_level || 'G-II';
    var splCode = lot.sample_code || 'H';
    var splSize = parseInt(lot.sample_size) || 0;
    var accNum = parseInt(lot.accept_number) || 0;
    var rejNum = parseInt(lot.reject_number) || 1;
    var ngCount = parseInt(lot.ng_count) || 0;
    var res = lot.lot_result || 'in_progress';
    var lotStatus = lot.lot_status || 'ok';
    var isSs = (lot.remarks && lot.remarks.indexOf('Safety Stock') !== -1) || lot.is_safety_stock || (res === 'skipped');
    var isReplaced = (lotStatus === 'replaced');
    var isReinspected = (lotStatus === 'reinspected');
    var isReplacement = (lot.remarks && lot.remarks.indexOf('Box Pengganti') !== -1);

    // Defect segmentation: Active defects vs Sorted defect history
    var allLotDefects = ngRecordsByLot[lotId] || [];
    var activeDefects = allLotDefects.filter(function(d) { return !d.is_sorted || parseInt(d.is_sorted) === 0; });
    var sortedDefects = allLotDefects.filter(function(d) { return parseInt(d.is_sorted) === 1; });

    var sortedHistoryHtml = '';
    if (sortedDefects.length > 0) {
        var sortedPcs = 0;
        var sortedTags = '';
        sortedDefects.forEach(function(sd) {
            var dName = sd.defect_name || (sd.defect_type_name || 'Defect');
            var dQty = parseInt(sd.qty_ng) || 1;
            sortedPcs += dQty;
            sortedTags += '<span style="background-color: #fef3c7; color: #92400e; border: 1px solid #fde68a; border-radius: 6px; padding: 2px 7px; font-size: 10px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;">' +
                escapeHtml(dName) + ' (' + dQty + ' pcs)' +
            '</span>';
        });
        sortedHistoryHtml = 
            '<div style="margin-top: 8px; padding: 7px 10px; background-color: #fffbeb; border: 1px dashed #fde68a; border-radius: 8px; font-size: 10.5px;">' +
                '<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">' +
                    '<span style="font-weight: 800; color: #92400e; font-size: 9.5px; text-transform: uppercase;">Riwayat Cacat Sebelum Sortir (' + sortedPcs + ' pcs):</span>' +
                    '<span style="font-size: 9px; font-weight: 800; background-color: #ffffff; color: #b45309; border: 1px solid #fde68a; padding: 1px 6px; border-radius: 4px;">Sudah Ditukar Part Bagus</span>' +
                '</div>' +
                '<div style="display: flex; flex-wrap: wrap; gap: 4px;">' + sortedTags + '</div>' +
            '</div>';
    }

    var cardBorder = '#cbd5e1';
    var accentColor = '#f59e0b';
    var cardBg = '#ffffff';
    var statusBadgeHtml = '';
    var actionBlockHtml = '';

    if (isReplaced) {
        cardBorder = '#cbd5e1';
        accentColor = '#94a3b8';
        cardBg = '#f8fafc';
        var isChiefAccRep = (parseInt(lot.is_chief_approved || 0) === 1);
        var chiefNameRep = lot.chief_name || 'Chief QC';
        var chiefBadgeRep = '';
        if (isChiefAccRep) {
            chiefBadgeRep = '<span style="background-color: #ecfdf5; color: #047857; border: 1.5px solid #a7f3d0; font-size: 10px; font-weight: 800; padding: 2px 8px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 4px;" title="Disetujui oleh Chief: ' + escapeHtml(chiefNameRep) + '">' +
                '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>' +
                'ACC: ' + escapeHtml(chiefNameRep) +
            '</span>';
        } else {
            chiefBadgeRep = '<span style="background-color: #fff7ed; color: #c2410c; border: 1.5px solid #fdba74; font-size: 10px; font-weight: 800; padding: 2px 8px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 4px;" title="Lembar penolakan belum di-ACC Chief QC">' +
                '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>' +
                'Belum Di-ACC Chief' +
            '</span>';
        }

        var rejBtnBadgeRep = isChiefAccRep ? 
            '<span style="background: #059669; color: #ffffff; font-size: 8.5px; font-weight: 800; padding: 1px 5px; border-radius: 4px; margin-left: 4px;">ACC OK</span>' : 
            '<span style="background: #dc2626; color: #ffffff; font-size: 8.5px; font-weight: 800; padding: 1px 5px; border-radius: 4px; margin-left: 4px;">Belum ACC</span>';

        statusBadgeHtml = '<div style="display: flex; align-items: center; gap: 4px; flex-wrap: wrap;">' +
            '<span style="background-color: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; font-size: 10px; font-weight: 800; padding: 2px 8px; border-radius: 9999px;">DIGANTI</span>' +
            chiefBadgeRep +
        '</div>';

        actionBlockHtml = 
            '<div style="margin-top: 9px; padding: 7px 10px; background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 11px; display: flex; align-items: center; justify-content: space-between; gap: 8px;">' +
                '<span style="color: #64748b; font-weight: 600; font-size: 10.5px;">Box ini telah digantikan oleh box baru (Karantina / Retur).</span>' +
                '<div style="display: flex; gap: 6px; align-items: center; flex-shrink: 0;">' +
                    '<button type="button" onclick="cancelReplacementLot(' + lotId + ', \'' + escapeHtml(lotNo) + '\')" style="padding: 4px 9px; background-color: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; border-radius: 6px; font-size: 10px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;" title="Batalkan box pengganti dan kembalikan box ini ke status REJECTED">' +
                        '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 10h10a5 5 0 0 1 5 5v2"/><path d="M7 14l-4-4 4-4"/></svg>' +
                        '<span>Batal Ganti</span>' +
                    '</button>' +
                    '<a href="<?= base_url("modules/inspection/print_rejection.php?session_lot_id=") ?>' + lotId + '" target="_blank" ' +
                        'style="padding: 4px 10px; background-color: #fff1f2; color: #be123c; border: 1px solid #fecdd3; border-radius: 6px; font-size: 10px; font-weight: 800; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; flex-shrink: 0;" title="Buka lembar penolakan resmi lot ini">' +
                        '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>' +
                        '<span>Cetak Rejection</span>' + rejBtnBadgeRep +
                    '</a>' +
                '</div>' +
            '</div>';
    } else if (res === 'in_progress') {
        cardBorder = isFocusMode ? '#3b82f6' : '#cbd5e1';
        accentColor = '#f59e0b';
        cardBg = isFocusMode ? '#ffffff' : '#ffffff';
        statusBadgeHtml = '<span style="background-color: #fef3c7; color: #92400e; border: 1px solid #fde68a; font-size: 10px; font-weight: 800; padding: 2px 8px; border-radius: 9999px;">SEDANG DIINSPEKSI</span>';
        
        var extraCancelBtn = '';
        if (isReplacement) {
            extraCancelBtn = 
                '<button type="button" onclick="cancelReplacementLot(' + lotId + ', \'' + escapeHtml(lotNo) + '\')" ' +
                    'style="padding: 8px 11px; background-color: #fef2f2; color: #b91c1c; border: 1.5px solid #fecaca; border-radius: 10px; font-size: 10.5px; font-weight: 800; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 4px; transition: all 0.15s; flex-shrink: 0;" ' +
                    'title="Batalkan box pengganti ini dan kembalikan box sebelumnya ke status REJECTED">' +
                    '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 10h10a5 5 0 0 1 5 5v2"/><path d="M7 14l-4-4 4-4"/></svg>' +
                    '<span>Batal Box Pengganti</span>' +
                '</button>';
        } else if (isReinspected) {
            extraCancelBtn = 
                '<button type="button" onclick="cancelSortLot(' + lotId + ', \'' + escapeHtml(lotNo) + '\')" ' +
                    'style="padding: 8px 11px; background-color: #fff1f2; color: #be123c; border: 1.5px solid #fecdd3; border-radius: 10px; font-size: 10.5px; font-weight: 800; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 4px; transition: all 0.15s; flex-shrink: 0;" ' +
                    'title="Batalkan tindakan sortir dan kembalikan lot ke status REJECTED">' +
                    '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 10h10a5 5 0 0 1 5 5v2"/><path d="M7 14l-4-4 4-4"/></svg>' +
                    '<span>Batal Re-Inspeksi</span>' +
                '</button>';
        }

        actionBlockHtml = 
            '<div style="display: flex; gap: 6px; margin-top: 8px; flex-wrap: wrap;">' +
                '<button type="button" onclick="openLotNgModal(' + lotId + ', \'' + escapeHtml(lotNo) + '\', \'' + escapeHtml(refNo) + '\', ' + qty + ', ' + splSize + ', ' + rejNum + ')" ' +
                    'style="flex: 1; min-width: 90px; padding: 7px 10px; background-color: #fff1f2; color: #be123c; border: 1.5px solid #fecdd3; border-radius: 9px; font-size: 11px; font-weight: 800; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 5px; transition: all 0.15s;" ' +
                    'onmouseover="this.style.backgroundColor=\'#ffe4e6\'" onmouseout="this.style.backgroundColor=\'#fff1f2\'">' +
                    '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>' +
                    '<span>Catat NG</span>' +
                '</button>' +
                '<button type="button" onclick="confirmLotPassed(' + lotId + ', \'' + escapeHtml(lotNo) + '\', \'' + escapeHtml(refNo) + '\', ' + splSize + ')" ' +
                    'style="flex: 1.4; min-width: 130px; padding: 7px 12px; background: linear-gradient(135deg, #059669 0%, #047857 100%); color: #ffffff; border: none; border-radius: 9px; font-size: 11px; font-weight: 800; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 5px; box-shadow: 0 2px 5px rgba(5,150,105,0.25); transition: all 0.15s;" ' +
                    'onmouseover="this.style.opacity=\'0.95\'" onmouseout="this.style.opacity=\'1\'">' +
                    '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>' +
                    '<span>Selesai Inspeksi (PASSED)</span>' +
                '</button>' +
                extraCancelBtn +
            '</div>';
    } else if (res === 'passed') {
        cardBorder = isFocusMode ? '#34d399' : '#a7f3d0';
        accentColor = '#10b981';
        cardBg = '#f0fdf4';
        statusBadgeHtml = '<span style="background-color: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; font-size: 10px; font-weight: 800; padding: 2px 8px; border-radius: 9999px;">PASSED</span>';
        actionBlockHtml = 
            '<div style="margin-top: 9px; padding: 7px 10px; background-color: #ffffff; border: 1px solid #bbf7d0; border-radius: 8px; display: flex; align-items: center; justify-content: space-between; font-size: 11px;">' +
                '<div style="display: flex; align-items: center; gap: 6px; color: #065f46; font-weight: 700;">' +
                    '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color: #059669;"><polyline points="20 6 9 17 4 12"/></svg>' +
                    '<span>' + splSize + ' pcs sampel fisik lolos (0 NG)</span>' +
                '</div>' +
                '<button type="button" onclick="resetLotToInProgress(' + lotId + ', \'' + escapeHtml(lotNo) + '\')" style="font-size: 10px; font-weight: 800; color: #1e293b; background-color: #f1f5f9; border: 1.5px solid #94a3b8; border-radius: 6px; padding: 3px 9px; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); transition: all 0.15s;" onmouseover="this.style.backgroundColor=\'#e2e8f0\'; this.style.borderColor=\'#64748b\';" onmouseout="this.style.backgroundColor=\'#f1f5f9\'; this.style.borderColor=\'#94a3b8\';" title="Kembalikan ke status pemeriksaan">' +
                    '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>' +
                    '<span>Batal / Re-Check</span>' +
                '</button>' +
            '</div>';
    } else if (res === 'rejected') {
        cardBorder = isFocusMode ? '#f87171' : '#fecdd3';
        accentColor = '#e11d48';
        cardBg = '#fff1f2';

        var isChiefAcc = (parseInt(lot.is_chief_approved || 0) === 1);
        var chiefName = lot.chief_name || 'Chief QC';

        var chiefBadgeHtml = '';
        if (isChiefAcc) {
            chiefBadgeHtml = '<span style="background-color: #ecfdf5; color: #047857; border: 1.5px solid #a7f3d0; font-size: 10px; font-weight: 800; padding: 2px 8px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 4px;" title="Disetujui oleh Chief: ' + escapeHtml(chiefName) + '">' +
                '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>' +
                'ACC: ' + escapeHtml(chiefName) +
            '</span>';
        } else {
            chiefBadgeHtml = '<span style="background-color: #fff7ed; color: #c2410c; border: 1.5px solid #fdba74; font-size: 10px; font-weight: 800; padding: 2px 8px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 4px;" title="Lembar penolakan belum di-ACC Chief QC">' +
                '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>' +
                'Belum Di-ACC Chief' +
            '</span>';
        }

        statusBadgeHtml = '<div style="display: flex; align-items: center; gap: 4px; flex-wrap: wrap;">' +
            '<span style="background-color: #ffe4e6; color: #9f1239; border: 1px solid #fca5a5; font-size: 10px; font-weight: 800; padding: 2px 8px; border-radius: 9999px;">REJECTED</span>' +
            chiefBadgeHtml +
        '</div>';
        
        var defects = activeDefects.length > 0 ? activeDefects : allLotDefects;
        var defectsByUnit = {};
        if (defects.length > 0) {
            defects.forEach(function(d) {
                var uNo = parseInt(d.unit_number) || 1;
                if (!defectsByUnit[uNo]) {
                    defectsByUnit[uNo] = [];
                }
                defectsByUnit[uNo].push(d);
            });
        }
        var unitKeys = Object.keys(defectsByUnit).sort(function(a, b) { return parseInt(a) - parseInt(b); });
        var totalUnitsCount = unitKeys.length > 0 ? unitKeys.length : ngCount;

        var defectUnitsHtml = '';
        if (unitKeys.length > 0) {
            defectUnitsHtml += '<div style="display: flex; flex-direction: column; gap: 5px;">';
            unitKeys.forEach(function(uKey) {
                var uDefects = defectsByUnit[uKey];
                var uBadges = '';
                uDefects.forEach(function(d) {
                    var dName = d.defect_name || (d.defect_type_name || 'Defect');
                    var dCode = d.defect_code ? '[' + escapeHtml(d.defect_code) + '] ' : '';
                    uBadges += '<span style="background-color: #ffffff; color: #991b1b; border: 1px solid #fca5a5; border-radius: 6px; padding: 2.5px 7px; font-size: 10px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">' +
                        '<span style="color: #e11d48; font-weight: 900;">•</span> ' + dCode + escapeHtml(dName) +
                    '</span>';
                });

                defectUnitsHtml += 
                    '<div style="display: flex; align-items: flex-start; gap: 6px; padding: 4.5px 7px; background-color: #fff5f5; border: 1px solid #fed7aa; border-radius: 7px;">' +
                        '<span style="font-size: 9.5px; font-weight: 800; color: #9f1239; background-color: #fee2e2; border: 1px solid #fca5a5; padding: 1.5px 6px; border-radius: 5px; flex-shrink: 0; display: inline-flex; align-items: center; gap: 3px;">' +
                            '📦 Unit #' + uKey +
                        '</span>' +
                        '<div style="display: flex; flex-wrap: wrap; gap: 4px; align-items: center; flex: 1;">' + uBadges + '</div>' +
                    '</div>';
            });
            defectUnitsHtml += '</div>';
        } else {
            defectUnitsHtml = '<span style="font-size: 10px; color: #991b1b; font-style: italic;">Defect tercatat: ' + ngCount + ' pcs</span>';
        }

        var rejBtnBadge = isChiefAcc ? 
            '<span style="background: #059669; color: #ffffff; font-size: 8.5px; font-weight: 800; padding: 1px 5px; border-radius: 4px; margin-left: 4px;">ACC OK</span>' : 
            '<span style="background: #dc2626; color: #ffffff; font-size: 8.5px; font-weight: 800; padding: 1px 5px; border-radius: 4px; margin-left: 4px;">Belum ACC</span>';

        var rejWarningNotice = !isChiefAcc ?
            '<div style="margin-top: 6px; padding: 5px 8px; background-color: #fffbeb; border: 1px dashed #fde68a; border-radius: 6px; font-size: 10px; color: #92400e; display: flex; align-items: center; gap: 6px;">' +
                '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink: 0;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>' +
                '<span>Lembar reject belum di-ACC Chief QC. Klik <b>Rejection</b> untuk tanda tangan digital.</span>' +
            '</div>' : '';

        actionBlockHtml = 
            '<div style="margin-top: 9px; space-y: 6px;">' +
                '<div style="padding: 6px 8px; background-color: #ffffff; border: 1px solid #fecdd3; border-radius: 8px; font-size: 10.5px;">' +
                    '<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">' +
                        '<div style="font-size: 9.5px; font-weight: 800; color: #9f1239; text-transform: uppercase;">RINCIAN DEFECT (' + totalUnitsCount + ' UNIT):</div>' +
                        '<button type="button" onclick="openLotNgModal(' + lotId + ', \'' + escapeHtml(lotNo) + '\', \'' + escapeHtml(refNo) + '\', ' + qty + ', ' + splSize + ', ' + rejNum + ')" ' +
                            'style="padding: 3px 8px; background-color: #fff1f2; color: #be123c; border: 1.5px solid #fda4af; border-radius: 6px; font-size: 9.5px; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 2px rgba(190,18,60,0.08); transition: all 0.15s;" ' +
                            'onmouseover="this.style.backgroundColor=\'#ffe4e6\'; this.style.borderColor=\'#f43f5e\';" onmouseout="this.style.backgroundColor=\'#fff1f2\'; this.style.borderColor=\'#fda4af\';" ' +
                            'title="Edit jenis defect, hapus unit, atau tambah temuan defect susulan">' +
                            '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>' +
                            '<span>Edit / Tambah NG</span>' +
                        '</button>' +
                    '</div>' +
                    defectUnitsHtml +
                '</div>' +
                rejWarningNotice +
                '<div style="display: flex; gap: 6px; margin-top: 8px;">' +
                    '<a href="<?= base_url("modules/inspection/print_rejection.php?session_lot_id=") ?>' + lotId + '" target="_blank" ' +
                        'style="flex: 1.1; padding: 7px 9px; background-color: #fff1f2; color: #9f1239; border: 1.5px solid #f43f5e; border-radius: 8px; font-size: 10.5px; font-weight: 800; text-align: center; text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 4px; box-shadow: 0 1px 3px rgba(225,29,72,0.12); transition: all 0.15s;" onmouseover="this.style.backgroundColor=\'#ffe4e6\'; this.style.borderColor=\'#e11d48\';" onmouseout="this.style.backgroundColor=\'#fff1f2\'; this.style.borderColor=\'#f43f5e\';" title="Buka lembar penolakan resmi lot ini">' +
                        '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="color: #be123c;"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>' +
                        '<span>Rejection</span>' + rejBtnBadge +
                    '</a>' +
                    '<button type="button" onclick="openLotReinspectionModal(' + lotId + ', \'' + escapeHtml(lotNo) + '\', \'' + escapeHtml(refNo) + '\', ' + qty + ')" ' +
                        'style="flex: 1.2; padding: 7px 10px; background-color: #be123c; color: #ffffff; border: 1.5px solid #9f1239; border-radius: 8px; font-size: 10.5px; font-weight: 800; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 4px; box-shadow: 0 2px 4px rgba(190,18,60,0.3); transition: all 0.15s;" onmouseover="this.style.filter=\'brightness(1.08)\'" onmouseout="this.style.filter=\'none\'">' +
                        '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg>' +
                        '<span>Re-Inspeksi</span>' +
                    '</button>' +
                    '<button type="button" onclick="resetLotToInProgress(' + lotId + ', \'' + escapeHtml(lotNo) + '\')" ' +
                        'style="padding: 7px 12px; background-color: #f1f5f9; color: #334155; border: 1.5px solid #94a3b8; border-radius: 8px; font-size: 10.5px; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 2px rgba(0,0,0,0.06); transition: all 0.15s;" onmouseover="this.style.backgroundColor=\'#e2e8f0\'; this.style.borderColor=\'#64748b\';" onmouseout="this.style.backgroundColor=\'#f1f5f9\'; this.style.borderColor=\'#94a3b8\';" title="Kembalikan ke status pemeriksaan">' +
                        '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>' +
                        '<span>Batal</span>' +
                    '</button>' +
                '</div>' +
            '</div>';
    } else if (res === 'skipped') {
        cardBorder = '#e9d5ff';
        accentColor = '#a855f7';
        cardBg = '#faf5ff';
        statusBadgeHtml = '<span style="background-color: #f3e8ff; color: #6b21a8; border: 1px solid #d8b4fe; font-size: 10px; font-weight: 800; padding: 2px 8px; border-radius: 9999px;">DILEWATI (SAFETY STOCK)</span>';
        actionBlockHtml = 
            '<div style="margin-top: 9px; padding: 6px 10px; background-color: #ffffff; border: 1px solid #e9d5ff; border-radius: 8px; font-size: 10px; color: #6b21a8; font-weight: 600;">' +
                'Barang gudang safety stock (Sudah lolos inspeksi sebelumnya, tidak perlu diuji ulang)' +
            '</div>';
    }

    var boxBadge = '<span style="font-size: 10px; font-weight: 800; color: #2563eb; background-color: #eff6ff; border: 1px solid #bfdbfe; padding: 2px 7px; border-radius: 6px;">Box #' + (lot._origBoxNum || 1) + '</span>';
    var ssBadge = isSs ? '<span style="font-size: 9px; font-weight: 800; color: #7e22ce; background-color: #faf5ff; border: 1px solid #e9d5ff; padding: 1px 6px; border-radius: 4px;">Safety Stock</span>' : '';
    var repBadge = isReplacement ? '<span style="font-size: 9px; font-weight: 800; color: #1d4ed8; background-color: #eff6ff; border: 1px solid #bfdbfe; padding: 1px 6px; border-radius: 4px;">Box Pengganti</span>' : (isReinspected ? '<span style="font-size: 9px; font-weight: 800; color: #b45309; background-color: #fffbeb; border: 1px solid #fde68a; padding: 1px 6px; border-radius: 4px;">Hasil Sortir</span>' : '');

    var removeLotBtn = '';
    if (s.status === 'in_progress' && !isReplaced) {
        removeLotBtn = '<button type="button" onclick="confirmRemoveLot(' + lotId + ', ' + (lot._origBoxNum || 1) + ', \'' + escapeHtml(lotNo) + '\', \'' + escapeHtml(refNo) + '\', ' + qty + ')" ' +
            'style="padding: 3px 8px; background-color: #fef2f2; color: #991b1b; border: 1.5px solid #f87171; border-radius: 6px; font-size: 9.5px; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 3px rgba(185,28,28,0.12); transition: all 0.15s;" ' +
            'onmouseover="this.style.backgroundColor=\'#fee2e2\'; this.style.borderColor=\'#ef4444\';" onmouseout="this.style.backgroundColor=\'#fef2f2\'; this.style.borderColor=\'#f87171\';" ' +
            'title="Batal pakai box ini dan hapus dari sesi agar dapat discan di sesi/PC lain">' +
            '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="color: #b91c1c;"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>' +
            '<span>Batal Pakai</span>' +
        '</button>';
    }

    var identityGridHtml = 
        '<div style="margin-top: 7px; display: flex; align-items: center; justify-content: space-between; gap: 8px; background-color: rgba(248, 250, 252, 0.9); border: 1px solid #e2e8f0; border-radius: 8px; padding: 5px 9px;">' +
            '<div style="min-width: 0;">' +
                '<span style="display: block; font-size: 8px; font-weight: 800; color: #64748b; letter-spacing: 0.05em; text-transform: uppercase;">LOT NUMBER</span>' +
                '<span style="font-size: 12.5px; font-weight: 900; color: #0f172a; font-family: monospace; line-height: 1.2;" title="' + escapeHtml(lotNo) + '">' +
                    escapeHtml(lotNo) +
                '</span>' +
            '</div>' +
            '<div style="text-align: right; flex-shrink: 0;">' +
                '<span style="display: block; font-size: 8px; font-weight: 800; color: #64748b; letter-spacing: 0.05em; text-transform: uppercase;">REF NUMBER</span>' +
                '<span style="font-size: 11.5px; font-weight: 800; color: #1e40af; font-family: monospace; white-space: nowrap; line-height: 1.2;" title="' + escapeHtml(refNo || '-') + '">' +
                    escapeHtml(refNo || '-') +
                '</span>' +
            '</div>' +
        '</div>';

    var ngValColor = (ngCount >= rejNum) ? '#be123c' : ((ngCount > 0) ? '#b45309' : '#059669');
    var ngBgColor = (ngCount >= rejNum) ? '#fff1f2' : ((ngCount > 0) ? '#fef3c7' : '#f8fafc');
    var ngBorderColor = (ngCount >= rejNum) ? '#fecdd3' : ((ngCount > 0) ? '#fde68a' : '#e2e8f0');
    var ngTitleColor = (ngCount >= rejNum) ? '#9f1239' : ((ngCount > 0) ? '#92400e' : '#64748b');

    var statsGridHtml = 
        '<div style="display: grid; grid-template-columns: 1fr 1fr 1.2fr; gap: 5px; margin-top: 6px;">' +
            '<div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 5px 6px; text-align: center;">' +
                '<span style="display: block; font-size: 8.5px; font-weight: 800; color: #64748b; letter-spacing: 0.03em;">QTY LOT</span>' +
                '<span style="font-size: 12px; font-weight: 900; color: #0f172a; font-family: monospace;">' + qty.toLocaleString() + ' pcs</span>' +
            '</div>' +
            '<div style="background-color: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 5px 6px; text-align: center;">' +
                '<span style="display: block; font-size: 8.5px; font-weight: 800; color: #1e40af; letter-spacing: 0.03em;">SAMPEL CEK</span>' +
                '<span style="font-size: 12px; font-weight: 900; color: #1d4ed8; font-family: monospace;">' + splSize + ' pcs</span>' +
            '</div>' +
            '<div style="background-color: ' + ngBgColor + '; border: 1px solid ' + ngBorderColor + '; border-radius: 8px; padding: 5px 6px; text-align: center;">' +
                '<span style="display: block; font-size: 8.5px; font-weight: 800; color: ' + ngTitleColor + '; letter-spacing: 0.03em;">TEMUAN / BATAS</span>' +
                '<span style="font-size: 12px; font-weight: 900; color: ' + ngValColor + '; font-family: monospace;">' + ngCount + ' / ' + rejNum + ' pcs</span>' +
            '</div>' +
        '</div>';

    var shadowStyle = isFocusMode ? 'box-shadow: 0 4px 10px rgba(0,0,0,0.05);' : 'box-shadow: 0 1px 3px rgba(0,0,0,0.03);';
    var borderStyle = isFocusMode ? 'border: 2px solid ' + cardBorder + ';' : 'border: 1.5px solid ' + cardBorder + ';';

    return '<div style="background-color: ' + cardBg + '; ' + borderStyle + ' border-left: 4px solid ' + accentColor + '; border-radius: 14px; padding: 9px 11px; ' + shadowStyle + ' transition: all 0.15s;">' +
        '<div style="display: flex; align-items: center; justify-content: space-between; gap: 8px;">' +
            '<div style="display: flex; align-items: center; gap: 5px; flex-wrap: wrap;">' +
                boxBadge +
                removeLotBtn +
                ssBadge +
                repBadge +
            '</div>' +
            '<div>' + statusBadgeHtml + '</div>' +
        '</div>' +
        identityGridHtml +
        statsGridHtml +
        sortedHistoryHtml +
        actionBlockHtml +
    '</div>';
}

function switchLotFilterTab(tabName) {
    currentLotFilterTab = tabName || 'all';
    if (window.lastWorkbenchSessionData) {
        renderWorkbenchLotFlow(window.lastWorkbenchSessionData);
    }
}

function renderWorkbenchLotFlow(data) {
    if (!data || !data.session) return;
    window.lastWorkbenchSessionData = data;
    var s = data.session;
    var lots = data.session_lots || [];
    var ngRecordsByLot = data.ng_records_by_lot || {};

    if (data.ng_records && (!ngRecordsByLot || Object.keys(ngRecordsByLot).length === 0)) {
        ngRecordsByLot = {};
        data.ng_records.forEach(function(rec) {
            var lid = rec.session_lot_id || 0;
            if (!ngRecordsByLot[lid]) ngRecordsByLot[lid] = [];
            ngRecordsByLot[lid].push(rec);
        });
    }

    // 1. Calculate lot stats
    var totalLots = lots.length;
    var finishedCount = 0;
    var passedCount = 0;
    var rejectedCount = 0;
    var replacedCount = 0;
    var reinspectedCount = 0;
    var skippedCount = 0;
    var inProgressCount = 0;
    var totalPhysicalSamples = 0;

    // Hitung total kuantitas temuan defect (NG) fisik pada sesi ini (aktif maupun riwayat sortir)
    var totalNgPcs = 0;
    var ngRecsList = data.ng_records || [];
    if (Array.isArray(ngRecsList)) {
        ngRecsList.forEach(function(nr) {
            totalNgPcs += (parseInt(nr.qty_ng) || 1);
        });
    }

    var activeLotsCount = 0;
    lots.forEach(function(lot) {
        var res = lot.lot_result || 'in_progress';
        var status = lot.lot_status || 'ok';
        var sSize = parseInt(lot.sample_size) || 0;
        var lotNg = parseInt(lot.ng_count) || 0;
        var hasNgRec = ngRecordsByLot[lot.id] && ngRecordsByLot[lot.id].length > 0;

        // Lot yang digantikan (karena NG): sampel fisiknya SUDAH diperiksa oleh operator!
        if (status === 'replaced') {
            replacedCount++;
            totalPhysicalSamples += sSize;
            return;
        }

        activeLotsCount++;
        if (res === 'passed') {
            finishedCount++;
            passedCount++;
            totalPhysicalSamples += sSize;
        } else if (res === 'rejected') {
            finishedCount++;
            rejectedCount++;
            totalPhysicalSamples += sSize;
        } else if (res === 'skipped') {
            finishedCount++;
            skippedCount++;
        } else if (status === 'reinspected') {
            reinspectedCount++;
            totalPhysicalSamples += sSize;
            inProgressCount++;
        } else if (status === 'ng_found' || lotNg > 0 || hasNgRec) {
            totalPhysicalSamples += sSize;
            inProgressCount++;
        } else {
            inProgressCount++;
        }
    });

    // 2. Update Card 2: Ringkasan Sesi
    var lotProgEl = document.getElementById('summary-lot-progress');
    if (lotProgEl) {
        lotProgEl.textContent = finishedCount + ' / ' + activeLotsCount + ' Lot';
    }
    var lotSubEl = document.getElementById('summary-lot-subtext');
    if (lotSubEl) {
        var subParts = [];
        if (passedCount > 0) subParts.push(passedCount + ' Lolos (OK)');
        if (rejectedCount > 0) subParts.push(rejectedCount + ' Ditolak (NG)');
        if (replacedCount > 0) subParts.push(replacedCount + ' Diganti (NG)');
        if (reinspectedCount > 0) subParts.push(reinspectedCount + ' Disortir (NG)');
        if (skippedCount > 0) subParts.push(skippedCount + ' Safety Stock');
        
        var pendingWaiting = activeLotsCount - finishedCount - reinspectedCount;
        if (pendingWaiting > 0) {
            subParts.push(pendingWaiting + ' Sedang Diuji');
        }
        lotSubEl.innerHTML = subParts.length > 0 ? subParts.join(' &bull; ') : 'Menunggu pemeriksaan';
    }

    var splTotalEl = document.getElementById('summary-sample-total');
    if (splTotalEl) {
        splTotalEl.textContent = totalPhysicalSamples.toLocaleString() + ' pcs';
    }

    var splSubEl = document.getElementById('summary-sample-subtext');
    if (splSubEl) {
        if (totalNgPcs > 0) {
            splSubEl.innerHTML = 'Akumulasi sampel fisik AQL &bull; <span style="color: #e11d48; font-weight: 700;">' + totalNgPcs + ' pcs NG</span>';
        } else {
            splSubEl.textContent = 'Akumulasi sampel fisik AQL';
        }
    }

    var badgeSessEl = document.getElementById('badge-session-status');
    if (badgeSessEl) {
        if (s.status === 'passed') {
            badgeSessEl.className = 'px-2.5 py-0.5 rounded-full text-[10px] font-extrabold shadow-2xs bg-emerald-100 text-emerald-800 border border-emerald-300';
            badgeSessEl.textContent = 'PASSED';
        } else if (s.status === 'rejected') {
            badgeSessEl.className = 'px-2.5 py-0.5 rounded-full text-[10px] font-extrabold shadow-2xs bg-rose-100 text-rose-800 border border-rose-300';
            badgeSessEl.textContent = 'REJECTED';
        } else if (s.status === 'cancelled') {
            badgeSessEl.className = 'px-2.5 py-0.5 rounded-full text-[10px] font-extrabold shadow-2xs bg-slate-200 text-slate-700 border border-slate-300';
            badgeSessEl.textContent = 'DIBATALKAN';
        } else {
            badgeSessEl.className = 'px-2.5 py-0.5 rounded-full text-[10px] font-extrabold shadow-2xs bg-amber-100 text-amber-800 border border-amber-300';
            badgeSessEl.textContent = 'IN PROGRESS';
        }
    }

    // 3. Update Card 3: Lot Count Badge, Tab Counts, Sorting & Render Focus Box / Cards
    var lotCountBadge = document.getElementById('lot-count-badge');
    if (lotCountBadge) {
        lotCountBadge.textContent = activeLotsCount + ' Lot';
    }

    // Tetapkan penomoran fisik box kardus asli (urutan scan)
    lots.forEach(function(lot, idx) {
        lot._origBoxNum = idx + 1;
    });

    // Hitung jumlah lot untuk masing-masing status
    var countAll = lots.length;
    var countPending = 0;
    var countPassed = 0;
    var countRejected = 0;

    lots.forEach(function(lot) {
        var res = lot.lot_result || 'in_progress';
        var status = lot.lot_status || 'ok';
        var lotNg = parseInt(lot.ng_count) || 0;
        var hasNgRec = ngRecordsByLot[lot.id] && ngRecordsByLot[lot.id].length > 0;

        if (res === 'in_progress' && status !== 'replaced') {
            countPending++;
        }
        if (res === 'passed') {
            countPassed++;
        }
        if (res === 'rejected' || status === 'replaced' || status === 'ng_found' || lotNg > 0 || hasNgRec) {
            countRejected++;
        }
    });

    // Perbarui tombol "Tambah Box" & "Tandai Semua Box PASSED"
    var btnAddLot = document.getElementById('btn-add-lot');
    if (btnAddLot) {
        btnAddLot.style.display = (s.status === 'in_progress' || s.status === 'passed' || s.status === 'rejected') ? 'inline-flex' : 'none';
    }

    var btnPassAll = document.getElementById('btn-pass-all-lots');
    if (btnPassAll) {
        if (countPending > 0 && s.status === 'in_progress') {
            btnPassAll.style.display = 'inline-flex';
            btnPassAll.querySelector('span').textContent = 'Tandai Semua Box PASSED (' + countPending + ')';
            btnPassAll.title = 'Loloskan seluruh ' + countPending + ' sisa box yang belum dicek sekaligus';
        } else {
            btnPassAll.style.display = 'none';
        }
    }

    // Tentukan box yang sedang difokuskan (Active Focus Box)
    var activeFocusLot = null;
    if (activeFocusLotId) {
        activeFocusLot = lots.find(function(l) { return l.id === activeFocusLotId; });
    }
    // Jika belum ada pilihan atau box yang dipilih sudah tidak ada, default ke pending pertama
    if (!activeFocusLot) {
        activeFocusLot = lots.find(function(l) {
            return (l.lot_result === 'in_progress' && l.lot_status !== 'replaced');
        });
    }
    // Jika semua sudah selesai, pilih box pertama
    if (!activeFocusLot && lots.length > 0) {
        activeFocusLot = lots[0];
    }
    activeFocusLotId = activeFocusLot ? activeFocusLot.id : null;

    // Perbarui Dropdown Selector Box Aktif
    var focusSelect = document.getElementById('focus-lot-select');
    if (focusSelect) {
        var optHtml = '';
        lots.forEach(function(lot) {
            var boxNum = lot._origBoxNum || 1;
            var lotNo = lot.lot_number || '-';
            var refNo = lot.ref_number || '';
            var qty = parseInt(lot.qty) || 0;
            var res = lot.lot_result || 'in_progress';
            var lotStatus = lot.lot_status || 'ok';

            var optStatusText = 'Belum Dicek';
            if (res === 'passed') optStatusText = 'PASSED';
            else if (res === 'rejected') optStatusText = 'REJECTED';
            else if (lotStatus === 'replaced') optStatusText = 'DIGANTI';
            else if (res === 'skipped') optStatusText = 'Safety Stock';

            var optRefStr = refNo ? ' | Ref: ' + refNo : '';
            var optText = 'Box #' + boxNum + ' — Lot: ' + lotNo + optRefStr + ' (' + qty + ' pcs) | ' + optStatusText;

            var isSel = (activeFocusLot && lot.id === activeFocusLot.id) ? 'selected' : '';
            optHtml += '<option value="' + lot.id + '" ' + isSel + '>' + escapeHtml(optText) + '</option>';
        });
        focusSelect.innerHTML = optHtml;
    }

    // Perbarui Navigasi Tombol Prev / Next
    var btnPrev = document.getElementById('btn-focus-prev');
    var btnNext = document.getElementById('btn-focus-next');
    var activeIdx = -1;
    if (activeFocusLot) {
        activeIdx = lots.findIndex(function(l) { return l.id === activeFocusLot.id; });
    }
    if (btnPrev) {
        btnPrev.disabled = (activeIdx <= 0);
        btnPrev.style.opacity = (activeIdx <= 0) ? '0.45' : '1';
        btnPrev.style.cursor = (activeIdx <= 0) ? 'not-allowed' : 'pointer';
    }
    if (btnNext) {
        btnNext.disabled = (activeIdx === -1 || activeIdx >= lots.length - 1);
        btnNext.style.opacity = (activeIdx === -1 || activeIdx >= lots.length - 1) ? '0.45' : '1';
        btnNext.style.cursor = (activeIdx === -1 || activeIdx >= lots.length - 1) ? 'not-allowed' : 'pointer';
    }

    // Perbarui Mini Chips Bar ([1] [2] ... [21])
    var chipsContainer = document.getElementById('focus-box-chips-container');
    if (chipsContainer) {
        var chipsHtml = '';
        lots.forEach(function(lot) {
            var boxNum = lot._origBoxNum || 1;
            var lotNo = lot.lot_number || '-';
            var res = lot.lot_result || 'in_progress';
            var lotStatus = lot.lot_status || 'ok';
            var lotNg = parseInt(lot.ng_count) || 0;

            var chipBg = '#f1f5f9';
            var chipColor = '#475569';
            var chipBorder = '#cbd5e1';
            var chipTitleStatus = 'Belum Dicek';

            if (res === 'in_progress' && lotStatus !== 'replaced') {
                chipBg = '#fef3c7';
                chipColor = '#92400e';
                chipBorder = '#fde68a';
                chipTitleStatus = 'Belum Dicek';
            } else if (res === 'passed') {
                chipBg = '#d1fae5';
                chipColor = '#065f46';
                chipBorder = '#a7f3d0';
                chipTitleStatus = 'PASSED';
            } else if (res === 'rejected' || lotStatus === 'replaced' || lotNg > 0) {
                chipBg = '#fee2e2';
                chipColor = '#991b1b';
                chipBorder = '#fecaca';
                chipTitleStatus = 'REJECTED';
            } else if (res === 'skipped') {
                chipBg = '#f3e8ff';
                chipColor = '#6b21a8';
                chipBorder = '#e9d5ff';
                chipTitleStatus = 'Safety Stock';
            }

            var isThisFocused = (activeFocusLot && lot.id === activeFocusLot.id);
            var focusStyle = isThisFocused ? 'box-shadow: 0 0 0 2px #2563eb; font-weight: 900; transform: scale(1.08); z-index: 1;' : 'font-weight: 700;';

            chipsHtml += '<button type="button" onclick="changeFocusBox(' + lot.id + ')" ' +
                'style="flex-shrink: 0; min-width: 28px; height: 26px; padding: 0 6px; font-size: 10px; border-radius: 6px; border: 1.5px solid ' + chipBorder + '; background-color: ' + chipBg + '; color: ' + chipColor + '; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; transition: all 0.15s; ' + focusStyle + '" ' +
                'title="Box #' + boxNum + ' (Lot: ' + escapeHtml(lotNo) + ') - ' + chipTitleStatus + '">' +
                boxNum +
            '</button>';
        });
        chipsContainer.innerHTML = chipsHtml;
    }

    // Render Card Body Box Aktif
    var activeCardBody = document.getElementById('active-focus-card-body');
    if (activeCardBody) {
        if (activeFocusLot) {
            activeCardBody.innerHTML = generateSingleLotCardHtml(activeFocusLot, ngRecordsByLot, s, true);
        } else {
            activeCardBody.innerHTML = '<div class="text-center py-6 text-slate-400 text-xs font-semibold">Tidak ada box yang dipilih.</div>';
        }
    }

    // Perbarui judul toggle ringkasan seluruh box
    var allBoxesToggleTitle = document.getElementById('all-boxes-toggle-title');
    if (allBoxesToggleTitle) {
        allBoxesToggleTitle.textContent = isAllBoxesExpanded ? 
            'Sembunyikan Ringkasan Seluruh Box (' + lots.length + ' Box)' : 
            'Lihat Ringkasan Seluruh Box (' + lots.length + ' Box)';
    }

    // Perbarui angka badge counter pada setiap tab ringkasan
    var cntAllEl = document.getElementById('tab-count-lot-all');
    if (cntAllEl) cntAllEl.textContent = countAll;
    var cntPenEl = document.getElementById('tab-count-lot-pending');
    if (cntPenEl) cntPenEl.textContent = countPending;
    var cntPasEl = document.getElementById('tab-count-lot-passed');
    if (cntPasEl) cntPasEl.textContent = countPassed;
    var cntRejEl = document.getElementById('tab-count-lot-rejected');
    if (cntRejEl) cntRejEl.textContent = countRejected;

    // Perbarui gaya tombol tab yang sedang aktif
    var tabKeys = ['all', 'pending', 'passed', 'rejected'];
    tabKeys.forEach(function(tk) {
        var btn = document.getElementById('tab-lot-filter-' + tk);
        if (!btn) return;
        if (tk === currentLotFilterTab) {
            if (tk === 'all') {
                btn.style.backgroundColor = '#0f172a';
                btn.style.color = '#ffffff';
                btn.style.borderColor = '#0f172a';
            } else if (tk === 'pending') {
                btn.style.backgroundColor = '#d97706';
                btn.style.color = '#ffffff';
                btn.style.borderColor = '#b45309';
            } else if (tk === 'passed') {
                btn.style.backgroundColor = '#059669';
                btn.style.color = '#ffffff';
                btn.style.borderColor = '#047857';
            } else if (tk === 'rejected') {
                btn.style.backgroundColor = '#e11d48';
                btn.style.color = '#ffffff';
                btn.style.borderColor = '#be123c';
            }
            btn.style.fontWeight = '800';
            btn.style.boxShadow = '0 1px 2px rgba(0,0,0,0.1)';
        } else {
            btn.style.backgroundColor = '#ffffff';
            btn.style.color = '#64748b';
            btn.style.borderColor = '#e2e8f0';
            btn.style.fontWeight = '700';
            btn.style.boxShadow = 'none';
        }
    });

    // Auto-Sort Prioritas untuk Ringkasan Seluruh Box:
    // Lot yang belum dicek (in_progress) otomatis berada di PALING ATAS!
    // Lot yang sudah selesai dicek otomatis TURUN KE BAWAH!
    var sortedLots = lots.slice(0);
    sortedLots.sort(function(a, b) {
        var aPending = (a.lot_result === 'in_progress' && a.lot_status !== 'replaced') ? 0 : 1;
        var bPending = (b.lot_result === 'in_progress' && b.lot_status !== 'replaced') ? 0 : 1;
        if (aPending !== bPending) {
            return aPending - bPending;
        }
        return a.id - b.id;
    });

    // Filter sesuai tab aktif
    var filteredLots = sortedLots.filter(function(lot) {
        var res = lot.lot_result || 'in_progress';
        var status = lot.lot_status || 'ok';
        var lotNg = parseInt(lot.ng_count) || 0;
        var hasNgRec = ngRecordsByLot[lot.id] && ngRecordsByLot[lot.id].length > 0;

        if (currentLotFilterTab === 'pending') {
            return (res === 'in_progress' && status !== 'replaced');
        } else if (currentLotFilterTab === 'passed') {
            return (res === 'passed');
        } else if (currentLotFilterTab === 'rejected') {
            return (res === 'rejected' || status === 'replaced' || status === 'ng_found' || lotNg > 0 || hasNgRec);
        }
        return true; // 'all'
    });

    var cardsContainer = document.getElementById('lot-cards-container');
    if (cardsContainer) {
        if (lots.length === 0) {
            cardsContainer.innerHTML = '<div class="text-center py-8 text-slate-400 text-xs">Belum ada label / lot yang discan pada sesi ini.</div>';
        } else if (filteredLots.length === 0) {
            var emptyMsg = 'Tidak ada lot pada filter ini.';
            if (currentLotFilterTab === 'pending') emptyMsg = 'Semua lot telah selesai diperiksa!';
            else if (currentLotFilterTab === 'passed') emptyMsg = 'Belum ada lot yang dinyatakan lolos (Passed).';
            else if (currentLotFilterTab === 'rejected') emptyMsg = 'Tidak ada lot yang ditolak (Zero Defect).';

            cardsContainer.innerHTML = '<div class="text-center py-8 text-slate-400 text-xs font-semibold">' + emptyMsg + '</div>';
        } else {
            var cardsHtml = '';
            filteredLots.forEach(function(lot) {
                cardsHtml += generateSingleLotCardHtml(lot, ngRecordsByLot, s, false);
            });
            cardsContainer.innerHTML = cardsHtml;
        }
    }

    // 4. Update Card 4: Finished Session Banner
    var finishedBanner = document.getElementById('session-finished-banner-v2');
    if (finishedBanner) {
        if (s.status !== 'in_progress') {
            finishedBanner.classList.remove('hidden');
            finishedBanner.style.display = 'block';

            var v2Badge = document.getElementById('finished-v2-status-badge');
            if (v2Badge) {
                if (s.status === 'passed') {
                    v2Badge.textContent = 'STATUS: SEMUA LOT PASSED';
                    v2Badge.className = 'text-[11px] font-black uppercase text-white px-3 py-1 rounded-full inline-block shadow-2xs bg-emerald-600';
                } else if (s.status === 'rejected') {
                    v2Badge.textContent = 'STATUS: DITEMUKAN LOT REJECTED';
                    v2Badge.className = 'text-[11px] font-black uppercase text-white px-3 py-1 rounded-full inline-block shadow-2xs bg-rose-600';
                } else {
                    v2Badge.textContent = 'STATUS: ' + s.status.toUpperCase();
                    v2Badge.className = 'text-[11px] font-black uppercase text-white px-3 py-1 rounded-full inline-block shadow-2xs bg-slate-600';
                }
            }

            var chiefWrap = document.getElementById('v2-chief-acc-wrap');
            if (s.status === 'rejected') {
                if (chiefWrap) {
                    chiefWrap.classList.remove('hidden');
                    chiefWrap.style.display = 'flex';
                }
                var rejectedLots = lots.filter(function(l) {
                    return l.lot_result === 'rejected' || l.lot_status === 'replaced';
                });
                var totalRej = rejectedLots.length;
                var approvedCount = rejectedLots.filter(function(l) {
                    return parseInt(l.is_chief_approved || 0) === 1;
                }).length;
                var unapprovedCount = totalRej - approvedCount;

                var chiefStatusTxt = document.getElementById('v2-chief-status-text');
                var chiefBtnContainer = document.getElementById('v2-chief-btn-container');

                if (chiefStatusTxt) {
                    if (totalRej === 0) {
                        chiefStatusTxt.innerHTML = '<span class="text-slate-500 font-semibold">Tidak ada lembar penolakan aktif</span>';
                    } else if (unapprovedCount === 0) {
                        chiefStatusTxt.innerHTML = '<span class="text-emerald-700 font-extrabold flex items-center gap-1.5"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>Semua lembar penolakan (' + totalRej + ' box) telah di-ACC Chief QC</span>';
                    } else {
                        chiefStatusTxt.innerHTML = '<span class="text-amber-800 font-extrabold flex items-center gap-1.5"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>' + unapprovedCount + ' dari ' + totalRej + ' box reject belum di-ACC Chief (Otorisasi via lembar Rejection)</span>';
                    }
                }
                if (chiefBtnContainer) {
                    chiefBtnContainer.innerHTML = '';
                }
            } else {
                if (chiefWrap) {
                    chiefWrap.classList.add('hidden');
                    chiefWrap.style.display = 'none';
                }
            }
        } else {
            finishedBanner.classList.add('hidden');
            finishedBanner.style.display = 'none';
        }
    }
}

function openLotNgModal(lotId, lotNumber, refNumber, qty, sampleSize, rejectNumber) {
    closeAllLotDefectDropdowns();
    var modal = document.getElementById('modal-lot-defect');
    if (!modal) return;

    document.getElementById('modal-defect-lot-id').value = lotId;
    document.getElementById('modal-defect-reject-num').value = rejectNumber;
    if (document.getElementById('modal-defect-sample-size')) {
        document.getElementById('modal-defect-sample-size').value = sampleSize;
    }
    
    var subtitle = 'Lot #' + lotNumber + (refNumber ? ' | Ref: ' + refNumber : '') + ' (' + Number(qty).toLocaleString() + ' pcs)';
    document.getElementById('modal-defect-lot-subtitle').textContent = subtitle;
    
    document.getElementById('modal-defect-aql-text').textContent = 'AQL: G-II | Wajib Sample: ' + sampleSize + ' pcs | Batas NG: ' + rejectNumber;

    var container = document.getElementById('modal-defect-units-container');
    if (container) container.innerHTML = '';

    // Ambil data defect yang sudah ada jika sedang mode edit / tambah NG
    var sessionData = window.lastWorkbenchSessionData;
    var existingDefects = [];
    if (sessionData) {
        if (sessionData.ng_records_by_lot && sessionData.ng_records_by_lot[lotId]) {
            existingDefects = sessionData.ng_records_by_lot[lotId];
        } else if (sessionData.ng_records) {
            existingDefects = sessionData.ng_records.filter(function(r) { return parseInt(r.session_lot_id) === parseInt(lotId); });
        }
    }

    var activeExisting = existingDefects.filter(function(d) {
        return (!d.is_cancelled || parseInt(d.is_cancelled) === 0) && (!d.is_sorted || parseInt(d.is_sorted) === 0);
    });

    if (activeExisting.length > 0) {
        var byUnit = {};
        activeExisting.forEach(function(d) {
            var uNo = parseInt(d.unit_number) || 1;
            if (!byUnit[uNo]) byUnit[uNo] = [];
            byUnit[uNo].push(d);
        });

        var uKeys = Object.keys(byUnit).sort(function(a, b) { return parseInt(a) - parseInt(b); });
        uKeys.forEach(function(uKey) {
            addNgUnitCardWithData(byUnit[uKey]);
        });
    } else {
        addNgUnitCard();
    }

    modal.style.display = 'flex';
}

function closeLotNgModal() {
    closeAllLotDefectDropdowns();
    var modal = document.getElementById('modal-lot-defect');
    if (modal) modal.style.display = 'none';
}

function addNgUnitCard() {
    addNgUnitCardWithData(null);
}

function addNgUnitCardWithData(uDefects) {
    var container = document.getElementById('modal-defect-units-container');
    if (!container) return;

    var emptyMsg = document.getElementById('modal-defect-empty-units');
    if (emptyMsg) emptyMsg.remove();

    var unitIdx = container.querySelectorAll('.ng-unit-card').length + 1;
    var unitCard = document.createElement('div');
    unitCard.className = 'ng-unit-card p-3 bg-white border border-slate-200 rounded-xl space-y-2.5 shadow-2xs';

    unitCard.innerHTML = 
        '<div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #f1f5f9; padding-bottom: 6px;">' +
            '<div style="display: flex; align-items: center; gap: 6px;">' +
                '<span style="font-size: 13px;">📦</span>' +
                '<span class="unit-title font-extrabold text-slate-800 text-xs">Unit NG #' + unitIdx + '</span>' +
            '</div>' +
            '<button type="button" class="btn-remove-unit" onclick="removeNgUnitCard(this)" style="background: none; border: none; font-size: 11px; font-weight: 700; color: #e11d48; cursor: pointer; padding: 2px 4px; display: inline-flex; align-items: center; gap: 3px;" title="Hapus seluruh unit ini">' +
                '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>' +
                '<span>Hapus Unit</span>' +
            '</button>' +
        '</div>' +
        '<div class="unit-defect-rows space-y-2">' +
            '<!-- Defect rows for this unit -->' +
        '</div>' +
        '<div style="padding-top: 2px;">' +
            '<button type="button" onclick="addDefectRowToUnit(this)" style="padding: 4px 10px; font-size: 10.5px; font-weight: 700; color: #0284c7; background: #f0f9ff; border: 1px dashed #7dd3fc; border-radius: 7px; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;" onmouseover="this.style.backgroundColor=\'#e0f2fe\'" onmouseout="this.style.backgroundColor=\'#f0f9ff\'">' +
                '<span>+ Tambah Jenis Defect pada Unit Ini</span>' +
            '</button>' +
        '</div>';

    container.appendChild(unitCard);
    var rowsContainer = unitCard.querySelector('.unit-defect-rows');

    if (Array.isArray(uDefects) && uDefects.length > 0) {
        uDefects.forEach(function(d) {
            var temp = document.createElement('div');
            temp.innerHTML = createDefectRowHtml();
            var row = temp.firstElementChild;
            rowsContainer.appendChild(row);

            var hiddenInput = row.querySelector('.lot-ng-defect-type');
            var searchInput = row.querySelector('.lot-defect-search-input');
            var customInput = row.querySelector('.lot-ng-custom-name');

            var dtId = d.defect_type_id ? parseInt(d.defect_type_id) : 0;
            var dtName = d.defect_name || (d.defect_type_name || '');
            var dtCode = d.defect_code || '';
            var isCustom = (d.remark && d.remark.indexOf('Custom:') === 0);

            if (isCustom || dtId === 0) {
                hiddenInput.value = 'custom';
                searchInput.value = 'Defect Lainnya (Manual)';
                if (customInput) {
                    customInput.style.display = 'block';
                    customInput.classList.remove('hidden');
                    customInput.value = d.remark ? d.remark.replace(/^Custom:\s*/, '') : dtName;
                }
            } else {
                hiddenInput.value = dtId;
                searchInput.value = dtCode ? ('[' + dtCode + '] ' + dtName) : dtName;
            }
        });
    } else {
        addDefectRowToUnit(rowsContainer);
    }

    updateLotDefectTotalDisplay();
}

function removeNgUnitCard(btn) {
    closeAllLotDefectDropdowns();
    var card = btn.closest('.ng-unit-card');
    if (card) {
        card.remove();
        var container = document.getElementById('modal-defect-units-container');
        if (container && container.querySelectorAll('.ng-unit-card').length === 0) {
            container.innerHTML = 
                '<div id="modal-defect-empty-units" style="padding: 22px 16px; text-align: center; background: #fff1f2; border: 1.5px dashed #fca5a5; border-radius: 12px; margin: 4px 0;">' +
                    '<div style="font-size: 26px; margin-bottom: 5px;">🗑️</div>' +
                    '<div style="font-size: 13px; font-weight: 800; color: #9f1239;">Semua Unit NG Telah Dihapus</div>' +
                    '<div style="font-size: 11px; color: #be123c; margin-top: 3px;">Jika disimpan, box ini akan dikembalikan ke status <b>PASSED (Normal / 0 NG)</b>.</div>' +
                    '<div style="margin-top: 10px;">' +
                        '<button type="button" onclick="addNgUnitCard()" style="padding: 5px 12px; font-size: 11px; font-weight: 700; color: #0284c7; background: #ffffff; border: 1px solid #7dd3fc; border-radius: 7px; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;">' +
                            '<span>+ Tambah Unit NG Baru</span>' +
                        '</button>' +
                    '</div>' +
                '</div>';
        }
        updateLotDefectTotalDisplay();
    }
}

function createDefectRowHtml() {
    return '<div class="lot-defect-row" style="display: flex; align-items: flex-start; gap: 8px;">' +
        '<div style="flex: 1; position: relative;" class="lot-combobox-wrap">' +
            '<input type="hidden" class="lot-ng-defect-type" value="">' +
            '<div style="position: relative; display: flex; align-items: center;">' +
                '<input type="text" class="lot-defect-search-input" ' +
                       'placeholder="Ketik kode atau nama defect... (contoh: OS, Baret)" ' +
                       'autocomplete="off" ' +
                       'onfocus="openLotDefectDropdown(this)" ' +
                       'oninput="filterLotDefectDropdown(this)" ' +
                       'onkeydown="navigateLotDefectDropdown(event, this)" ' +
                       'style="width: 100%; height: 34px; padding: 5px 30px 5px 10px; font-size: 11.5px; font-weight: 700; border: 1.5px solid #cbd5e1; border-radius: 8px; background-color: #ffffff; outline: none; transition: border-color 0.15s; box-sizing: border-box;">' +
                '<button type="button" class="btn-combobox-toggle" onclick="toggleLotDefectDropdown(this)" tabindex="-1" ' +
                        'style="position: absolute; right: 5px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: #94a3b8; padding: 3px; display: flex; align-items: center; justify-content: center;">' +
                    '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>' +
                '</button>' +
            '</div>' +
            '<div class="lot-defect-dropdown-list hidden" ' +
                 'style="display: none; position: fixed; z-index: 999999; background: #ffffff; border: 1.5px solid #cbd5e1; border-radius: 9px; box-shadow: 0 12px 28px -4px rgba(0,0,0,0.18), 0 4px 6px -2px rgba(0,0,0,0.06); max-height: 190px; overflow-y: auto; box-sizing: border-box;">' +
            '</div>' +
            '<input type="text" class="lot-ng-custom-name hidden" placeholder="Ketik nama defect manual..." style="width: 100%; height: 32px; padding: 5px 10px; font-size: 11.5px; border: 1.5px solid #fca5a5; border-radius: 8px; background-color: #fff1f2; color: #9f1239; outline: none; font-weight: 600; display: none; box-sizing: border-box; margin-top: 4px;">' +
        '</div>' +
        '<button type="button" class="btn-remove-defect-row" onclick="removeDefectRowFromUnit(this)" style="display: none; height: 34px; background: none; border: none; color: #94a3b8; font-size: 18px; font-weight: bold; cursor: pointer; padding: 0 6px; line-height: 1;" title="Hapus defect ini">&times;</button>' +
    '</div>';
}

function addDefectRowToUnit(btnOrContainer) {
    var rowsContainer = (btnOrContainer.classList && btnOrContainer.classList.contains('unit-defect-rows')) ? btnOrContainer : btnOrContainer.closest('.ng-unit-card').querySelector('.unit-defect-rows');
    if (!rowsContainer) return;

    var temp = document.createElement('div');
    temp.innerHTML = createDefectRowHtml();
    var row = temp.firstElementChild;
    rowsContainer.appendChild(row);
    updateLotDefectTotalDisplay();
}

function removeDefectRowFromUnit(btn) {
    closeAllLotDefectDropdowns();
    var row = btn.closest('.lot-defect-row');
    if (row) {
        row.remove();
        updateLotDefectTotalDisplay();
    }
}

// ── Searchable Combobox Core Functions for Lot Defect Selection ────────
function positionLotDefectDropdown(wrapEl) {
    if (!wrapEl) return;
    var inputEl = wrapEl.querySelector('.lot-defect-search-input');
    var listEl = wrapEl.querySelector('.lot-defect-dropdown-list');
    if (!inputEl || !listEl) return;

    var rect = inputEl.getBoundingClientRect();
    listEl.style.position = 'fixed';
    listEl.style.left = rect.left + 'px';
    listEl.style.width = rect.width + 'px';
    listEl.style.zIndex = '999999';

    var spaceBelow = window.innerHeight - rect.bottom;
    var maxListHeight = 190;
    if (spaceBelow < (maxListHeight + 10) && rect.top > (maxListHeight + 10)) {
        listEl.style.top = 'auto';
        listEl.style.bottom = (window.innerHeight - rect.top + 4) + 'px';
    } else {
        listEl.style.top = (rect.bottom + 4) + 'px';
        listEl.style.bottom = 'auto';
    }
}

function populateLotDefectDropdown(wrapEl, filterText) {
    var listEl = wrapEl.querySelector('.lot-defect-dropdown-list');
    if (!listEl) return;
    
    var unitCard = wrapEl.closest('.ng-unit-card');
    var alreadyChosenIds = [];
    if (unitCard) {
        var currHidden = wrapEl.querySelector('.lot-ng-defect-type');
        unitCard.querySelectorAll('.lot-ng-defect-type').forEach(function(h) {
            if (h !== currHidden && h.value && h.value !== 'custom') {
                alreadyChosenIds.push(String(h.value));
            }
        });
    }

    var query = (filterText || '').trim().toLowerCase();
    var html = '';
    var matchCount = 0;

    if (typeof DEFECT_TYPES_LIST !== 'undefined' && DEFECT_TYPES_LIST.length > 0) {
        DEFECT_TYPES_LIST.forEach(function(dt) {
            var code = (dt.code || '').trim();
            var name = (dt.name || '').trim();
            var codeLower = code.toLowerCase();
            var nameLower = name.toLowerCase();

            if (!query || codeLower.indexOf(query) !== -1 || nameLower.indexOf(query) !== -1) {
                matchCount++;
                var isAlready = alreadyChosenIds.indexOf(String(dt.id)) !== -1;
                var safeCode = escapeHtml(code);
                var safeName = escapeHtml(name);

                if (isAlready) {
                    html += '<div class="lot-defect-option-item" style="padding: 7px 12px; display: flex; align-items: center; border-bottom: 1px solid #f1f5f9; background-color: #f8fafc; opacity: 0.55; cursor: not-allowed;" title="Defect ini sudah dipilih pada unit ini">';
                    if (code) {
                        html += '<span style="font-family: monospace; font-size: 11px; font-weight: 700; background: #e2e8f0; color: #64748b; border: 1px solid #cbd5e1; padding: 2px 6px; border-radius: 4px; margin-right: 8px; flex-shrink: 0;">[' + safeCode + ']</span>';
                    }
                    html += '<span style="font-size: 11.5px; font-weight: 600; color: #94a3b8; text-decoration: line-through;">' + safeName + '</span>';
                    html += '<span style="margin-left: auto; font-size: 9.5px; font-weight: 800; background: #fef2f2; color: #ef4444; border: 1px solid #fecaca; padding: 1px 6px; border-radius: 4px; flex-shrink: 0;">Sudah ada</span>';
                    html += '</div>';
                } else {
                    html += '<div class="lot-defect-option-item" onclick="selectLotDefectOption(this, \'' + dt.id + '\', \'' + addslashesJs(code) + '\', \'' + addslashesJs(name) + '\')" ' +
                            'style="padding: 8px 12px; cursor: pointer; display: flex; align-items: center; border-bottom: 1px solid #f1f5f9; transition: background 0.1s;" ' +
                            'onmouseover="this.style.backgroundColor=\'#eff6ff\'" onmouseout="if(this.dataset.active !== \'true\') this.style.backgroundColor=\'#ffffff\'">';
                    if (code) {
                        html += '<span style="font-family: monospace; font-size: 11px; font-weight: 800; background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe; padding: 2px 6px; border-radius: 4px; margin-right: 8px; flex-shrink: 0;">[' + safeCode + ']</span>';
                    }
                    html += '<span style="font-size: 12px; font-weight: 700; color: #1e293b; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">' + safeName + '</span>';
                    html += '</div>';
                }
            }
        });
    }

    if (matchCount === 0 && query) {
        html += '<div style="padding: 10px 12px; font-size: 12px; color: #94a3b8; font-style: italic; text-align: center;">Tidak ada jenis defect yang cocok dengan "' + escapeHtml(query) + '"</div>';
    }

    // Manual custom option at bottom
    html += '<div class="lot-defect-option-item" onclick="selectLotDefectOption(this, \'custom\', \'\', \'Defect Lainnya (Ketik Manual)\')" ' +
            'style="padding: 9px 12px; cursor: pointer; display: flex; align-items: center; gap: 8px; background: #fff1f2; color: #be123c; font-size: 12px; font-weight: 800; border-top: 1px solid #fecdd3;" ' +
            'onmouseover="this.style.background=\'#ffe4e6\'" onmouseout="this.style.background=\'#fff1f2\'">' +
            '<span style="font-weight: 900; font-size: 14px;">+</span>' +
            '<span>Defect Lainnya (Ketik Manual)...</span>' +
            '</div>';

    listEl.innerHTML = html;
}

function selectLotDefectOption(itemEl, id, code, name) {
    var wrapEl = itemEl.closest('.lot-combobox-wrap');
    if (!wrapEl) return;
    
    var unitCard = wrapEl.closest('.ng-unit-card');
    var hiddenInput = wrapEl.querySelector('.lot-ng-defect-type');
    var searchInput = wrapEl.querySelector('.lot-defect-search-input');
    var listEl = wrapEl.querySelector('.lot-defect-dropdown-list');
    var row = wrapEl.closest('.lot-defect-row');

    // Real-time protection: prevent duplicate defect within the same unit card
    if (unitCard && id !== 'custom') {
        var existingSelected = false;
        unitCard.querySelectorAll('.lot-ng-defect-type').forEach(function(h) {
            if (h !== hiddenInput && h.value === String(id)) {
                existingSelected = true;
            }
        });
        if (existingSelected) {
            if (listEl) {
                listEl.style.display = 'none';
                listEl.classList.add('hidden');
            }
            Swal.fire({
                icon: 'warning',
                title: 'Defect Sudah Ada',
                text: 'Jenis defect "' + (code ? '[' + code + '] ' : '') + name + '" sudah dipilih pada unit ini. Harap pilih jenis defect yang lain.',
                timer: 2200
            });
            return;
        }
    }

    if (hiddenInput) hiddenInput.value = id;
    
    if (searchInput) {
        if (id === 'custom') {
            searchInput.value = '+ Defect Lainnya (Ketik Manual)';
            searchInput.style.color = '#be123c';
        } else {
            searchInput.value = (code ? '[' + code + '] ' : '') + name;
            searchInput.style.color = '#0f172a';
        }
    }

    if (listEl) {
        listEl.style.display = 'none';
        listEl.classList.add('hidden');
    }

    if (row) {
        var customInput = row.querySelector('.lot-ng-custom-name');
        if (id === 'custom') {
            if (customInput) {
                customInput.classList.remove('hidden');
                customInput.style.display = 'block';
                customInput.focus();
            }
        } else {
            if (customInput) {
                customInput.classList.add('hidden');
                customInput.style.display = 'none';
                customInput.value = '';
            }
        }
    }

    updateLotDefectTotalDisplay();
}

function openLotDefectDropdown(inputEl) {
    var wrapEl = inputEl.closest('.lot-combobox-wrap');
    if (!wrapEl) return;
    
    closeAllLotDefectDropdowns(wrapEl);

    var listEl = wrapEl.querySelector('.lot-defect-dropdown-list');
    if (listEl) {
        populateLotDefectDropdown(wrapEl, inputEl.value);
        listEl.style.display = 'block';
        listEl.classList.remove('hidden');
        positionLotDefectDropdown(wrapEl);
    }
}

function filterLotDefectDropdown(inputEl) {
    var wrapEl = inputEl.closest('.lot-combobox-wrap');
    if (!wrapEl) return;
    
    var hiddenInput = wrapEl.querySelector('.lot-ng-defect-type');
    if (hiddenInput && hiddenInput.value !== 'custom') {
        hiddenInput.value = '';
    }

    var listEl = wrapEl.querySelector('.lot-defect-dropdown-list');
    if (listEl) {
        populateLotDefectDropdown(wrapEl, inputEl.value);
        listEl.style.display = 'block';
        listEl.classList.remove('hidden');
        positionLotDefectDropdown(wrapEl);
    }
}

function toggleLotDefectDropdown(btnEl) {
    var wrapEl = btnEl.closest('.lot-combobox-wrap');
    if (!wrapEl) return;
    var inputEl = wrapEl.querySelector('.lot-defect-search-input');
    var listEl = wrapEl.querySelector('.lot-defect-dropdown-list');
    if (!listEl) return;

    if (listEl.style.display === 'block') {
        listEl.style.display = 'none';
        listEl.classList.add('hidden');
    } else {
        closeAllLotDefectDropdowns(wrapEl);
        populateLotDefectDropdown(wrapEl, inputEl ? inputEl.value : '');
        listEl.style.display = 'block';
        listEl.classList.remove('hidden');
        positionLotDefectDropdown(wrapEl);
        if (inputEl) inputEl.focus();
    }
}

function closeAllLotDefectDropdowns(exceptWrap) {
    document.querySelectorAll('.lot-combobox-wrap').forEach(function(wrap) {
        if (exceptWrap && wrap === exceptWrap) return;
        var list = wrap.querySelector('.lot-defect-dropdown-list');
        if (list) {
            list.style.display = 'none';
            list.classList.add('hidden');
        }
    });
}

function navigateLotDefectDropdown(e, inputEl) {
    var wrapEl = inputEl.closest('.lot-combobox-wrap');
    if (!wrapEl) return;
    var listEl = wrapEl.querySelector('.lot-defect-dropdown-list');
    if (!listEl || listEl.style.display !== 'block') {
        if (e.key === 'ArrowDown' || e.key === 'Enter') {
            openLotDefectDropdown(inputEl);
            e.preventDefault();
        }
        return;
    }

    var items = listEl.querySelectorAll('.lot-defect-option-item');
    if (items.length === 0) return;

    var activeIdx = -1;
    for (var i = 0; i < items.length; i++) {
        if (items[i].dataset.active === 'true') {
            activeIdx = i;
            break;
        }
    }

    if (e.key === 'ArrowDown') {
        e.preventDefault();
        var nextIdx = activeIdx + 1;
        if (nextIdx >= items.length) nextIdx = 0;
        setLotDefectActiveItem(items, nextIdx);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        var prevIdx = activeIdx - 1;
        if (prevIdx < 0) prevIdx = items.length - 1;
        setLotDefectActiveItem(items, prevIdx);
    } else if (e.key === 'Enter') {
        e.preventDefault();
        if (activeIdx >= 0 && items[activeIdx]) {
            items[activeIdx].click();
        } else if (items.length > 0) {
            items[0].click();
        }
    } else if (e.key === 'Escape') {
        e.preventDefault();
        listEl.style.display = 'none';
        listEl.classList.add('hidden');
    }
}

function setLotDefectActiveItem(items, idx) {
    items.forEach(function(it, i) {
        if (i === idx) {
            it.dataset.active = 'true';
            it.style.backgroundColor = '#dbeafe';
            it.scrollIntoView({ block: 'nearest' });
        } else {
            it.dataset.active = 'false';
            it.style.backgroundColor = '';
        }
    });
}

function addslashesJs(str) {
    if (!str) return '';
    return String(str).replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '\\"');
}

// Global click-outside listener to close any combobox dropdown
document.addEventListener('click', function(e) {
    if (!e.target.closest('.lot-combobox-wrap') && !e.target.closest('.lot-defect-dropdown-list')) {
        closeAllLotDefectDropdowns(null);
    }
});

// Close combobox dropdown if window resized
window.addEventListener('resize', function() {
    closeAllLotDefectDropdowns(null);
});

// Close combobox dropdown if modal body is scrolled
document.addEventListener('DOMContentLoaded', function() {
    var mb = document.getElementById('modal-defect-body');
    if (mb) {
        mb.addEventListener('scroll', function() {
            closeAllLotDefectDropdowns(null);
        });
    }
});

function updateLotDefectTotalDisplay() {
    var container = document.getElementById('modal-defect-units-container');
    if (!container) return;

    var unitCards = container.querySelectorAll('.ng-unit-card');
    var totalUnits = unitCards.length;
    var totalDefects = 0;

    unitCards.forEach(function(card, idx) {
        var titleEl = card.querySelector('.unit-title');
        if (titleEl) titleEl.textContent = 'Unit NG #' + (idx + 1);

        var delUnitBtn = card.querySelector('.btn-remove-unit');
        if (delUnitBtn) {
            delUnitBtn.style.display = 'inline-flex';
        }

        var defectRows = card.querySelectorAll('.lot-defect-row');
        totalDefects += defectRows.length;
        defectRows.forEach(function(dRow) {
            var delDefBtn = dRow.querySelector('.btn-remove-defect-row');
            if (delDefBtn) {
                delDefBtn.style.display = (defectRows.length > 1) ? 'inline-block' : 'none';
            }
        });
    });

    var dispUnits = document.getElementById('modal-defect-units-count');
    if (dispUnits) dispUnits.textContent = totalUnits;

    var dispDefects = document.getElementById('modal-defect-defects-count');
    if (dispDefects) dispDefects.textContent = totalDefects;

    var rejNum = parseInt(document.getElementById('modal-defect-reject-num').value) || 1;
    var verdictEl = document.getElementById('modal-defect-verdict-display');
    var submitBtn = document.getElementById('btn-submit-lot-ng');

    if (verdictEl) {
        if (totalUnits === 0) {
            verdictEl.textContent = 'STATUS: AKAN PASSED (0 NG)';
            verdictEl.style.backgroundColor = '#059669';
        } else if (totalUnits >= rejNum) {
            verdictEl.textContent = 'STATUS: REJECTED';
            verdictEl.style.backgroundColor = '#be123c';
        } else {
            verdictEl.textContent = 'STATUS: DI BAWAH BATAS';
            verdictEl.style.backgroundColor = '#d97706';
        }
    }

    if (submitBtn) {
        if (totalUnits === 0) {
            submitBtn.textContent = 'Simpan (Ubah Jadi PASSED)';
            submitBtn.style.backgroundColor = '#059669';
        } else {
            submitBtn.textContent = 'Simpan Temuan NG (REJECTED)';
            submitBtn.style.backgroundColor = '#e11d48';
        }
    }
}

function submitLotDefectForm() {
    var lotId = document.getElementById('modal-defect-lot-id').value;
    if (!lotId) return;

    var container = document.getElementById('modal-defect-units-container');
    if (!container) return;

    var unitCards = container.querySelectorAll('.ng-unit-card');
    if (unitCards.length === 0) {
        Swal.fire({
            title: 'Hapus Semua Temuan NG?',
            text: 'Semua unit NG telah dihapus. Box ini akan dikembalikan ke status PASSED (Normal dengan 0 NG). Lanjutkan?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#059669',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Ya, Jadikan PASSED',
            cancelButtonText: 'Batal'
        }).then(function(dialogRes) {
            if (!dialogRes.isConfirmed) return;

            Swal.fire({
                title: 'Mengubah Status ke PASSED...',
                allowOutsideClick: false,
                didOpen: function() { Swal.showLoading(); }
            });

            var payload = {
                session_lot_id: parseInt(lotId),
                action: 'passed'
            };

            fetch('<?= base_url("modules/inspection/api/submit_lot_result.php") ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                closeLotNgModal();
                if (!res.success) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Gagal Menyimpan',
                        text: res.message || 'Terjadi kesalahan pada server'
                    });
                    return;
                }
                advanceFocusBoxAfterAction(lotId);
                Swal.fire({
                    icon: 'success',
                    title: 'Box Dinyatakan PASSED',
                    text: 'Seluruh temuan NG dibatalkan. Box kembali berstatus PASSED.',
                    timer: 1400,
                    showConfirmButton: false
                }).then(function() {
                    loadWorkbenchSessionData(currentSessionId);
                });
            })
            .catch(function(err) {
                Swal.fire({
                    icon: 'error',
                    title: 'Koneksi Error',
                    text: 'Gagal terhubung ke server!'
                });
            });
        });
        return;
    }

    var defects = [];

    for (var u = 0; u < unitCards.length; u++) {
        var card = unitCards[u];
        var defectRows = card.querySelectorAll('.lot-defect-row');
        var unitSelectedKeys = [];

        if (defectRows.length === 0) {
            Swal.fire({
                icon: 'warning',
                title: 'Defect Belum Dipilih',
                text: 'Harap pilih minimal 1 jenis defect pada Unit #' + (u + 1) + '!'
            });
            return;
        }

        for (var d = 0; d < defectRows.length; d++) {
            var r = defectRows[d];
            var selectEl = r.querySelector('.lot-ng-defect-type');
            var customInput = r.querySelector('.lot-ng-custom-name');

            var defectVal = selectEl ? selectEl.value : '';
            var customName = (customInput && customInput.value) ? customInput.value.trim() : '';

            if (!defectVal) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Pilih Jenis Defect',
                    text: 'Harap pilih jenis defect pada Unit #' + (u + 1) + ' (Baris #' + (d + 1) + ')!'
                });
                return;
            }

            if (defectVal === 'custom' && !customName) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Nama Defect Manual Wajib Diisi',
                    text: 'Harap ketik nama jenis defect manual pada Unit #' + (u + 1) + '!'
                });
                return;
            }

            var key = (defectVal === 'custom') ? ('custom:' + customName.toLowerCase()) : defectVal;
            if (unitSelectedKeys.indexOf(key) !== -1) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Defect Ganda pada Unit yang Sama',
                    text: 'Jenis defect yang sama sudah dipilih pada Unit #' + (u + 1) + '. Harap pilih jenis defect yang berbeda!'
                });
                return;
            }
            unitSelectedKeys.push(key);

            defects.push({
                unit_number: (u + 1),
                defect_type_id: (defectVal === 'custom') ? 0 : parseInt(defectVal),
                custom_name: customName,
                qty_ng: 1
            });
        }
    }

    Swal.fire({
        title: 'Menyimpan Catatan Defect...',
        allowOutsideClick: false,
        didOpen: function() { Swal.showLoading(); }
    });

    var payload = {
        session_lot_id: parseInt(lotId),
        action: 'rejected',
        physical_ng_qty: unitCards.length,
        defects: defects
    };

    fetch('<?= base_url("modules/inspection/api/submit_lot_result.php") ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        closeLotNgModal();
        if (!res.success) {
            Swal.fire({
                icon: 'error',
                title: 'Gagal Menyimpan',
                text: res.message || 'Terjadi kesalahan pada server'
            });
            return;
        }

        advanceFocusBoxAfterAction(lotId);

        Swal.fire({
            icon: 'error',
            title: 'Lot Dinyatakan REJECTED',
            text: 'Temuan defect telah dicatat dan lot ditandai REJECTED.',
            confirmButtonColor: '#e11d48',
            confirmButtonText: 'OK',
            timer: 1400
        }).then(function() {
            loadWorkbenchSessionData(currentSessionId);
        });
    })
    .catch(function(err) {
        Swal.fire({
            icon: 'error',
            title: 'Koneksi Error',
            text: 'Gagal terhubung ke server!'
        });
    });
}

function confirmLotPassed(lotId, lotNumber, refNumber, sampleSize) {
    if (!lotId) return;

    var lotLabel = '#' + lotNumber + (refNumber ? ' (Ref: ' + refNumber + ')' : '');

    Swal.fire({
        title: 'Konfirmasi Lolos (PASSED)?',
        html: 'Sampel fisik sebanyak <b>' + sampleSize + ' pcs</b> pada Lot <b>' + escapeHtml(lotLabel) + '</b> telah diperiksa seluruhnya dan <b>TIDAK ADA DEFECT (OK)</b>.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#059669',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Ya, Selesai Inspeksi (PASSED)',
        cancelButtonText: 'Batal'
    }).then(function(result) {
        if (!result.isConfirmed) return;

        Swal.fire({
            title: 'Menyimpan Hasil Lot...',
            allowOutsideClick: false,
            didOpen: function() { Swal.showLoading(); }
        });

        var payload = {
            session_lot_id: parseInt(lotId),
            action: 'passed'
        };

        fetch('<?= base_url("modules/inspection/api/submit_lot_result.php") ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (!res.success) {
                Swal.fire({
                    icon: 'error',
                    title: 'Gagal',
                    text: res.message || 'Terjadi kesalahan'
                });
                return;
            }

            advanceFocusBoxAfterAction(lotId);

            Swal.fire({
                icon: 'success',
                title: 'Lot PASSED!',
                text: 'Lot ' + lotNumber + ' berhasil dinyatakan lolos inspeksi.',
                timer: 1200,
                showConfirmButton: false
            });
            loadWorkbenchSessionData(currentSessionId);
        })
        .catch(function(err) {
            Swal.fire({
                icon: 'error',
                title: 'Koneksi Error',
                text: 'Gagal terhubung ke server!'
            });
        });
    });
}

function resetLotToInProgress(lotId, lotNumber) {
    if (!lotId) return;

    Swal.fire({
        title: 'Kembalikan ke Pemeriksaan?',
        html: 'Status Lot <b>#' + escapeHtml(lotNumber) + '</b> akan dikembalikan ke status <b>Sedang Diinspeksi</b> agar dapat diperiksa ulang.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#2563eb',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Ya, Periksa Ulang',
        cancelButtonText: 'Batal'
    }).then(function(res) {
        if (!res.isConfirmed) return;

        Swal.fire({
            title: 'Memproses...',
            allowOutsideClick: false,
            didOpen: function() { Swal.showLoading(); }
        });

        var payload = {
            session_lot_id: parseInt(lotId),
            action: 'reset'
        };

        fetch('<?= base_url("modules/inspection/api/submit_lot_result.php") ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) {
                Swal.fire('Gagal!', data.message || 'Terjadi kesalahan', 'error');
                return;
            }
            Swal.fire({
                icon: 'success',
                title: 'Status Direset',
                text: 'Lot kembali dalam status sedang diinspeksi.',
                timer: 1200,
                showConfirmButton: false
            });
            loadWorkbenchSessionData(currentSessionId);
        })
        .catch(function() {
            Swal.fire('Error!', 'Gagal menghubungi server', 'error');
        });
    });
}

function confirmRemoveLot(lotId, boxNum, lotNo, refNo, qty) {
    if (!lotId || !currentSessionId) return;

    var refDisplay = refNo ? ' | Ref: ' + escapeHtml(refNo) : '';

    Swal.fire({
        title: 'Batal Pakai Box #' + boxNum + '?',
        html: '<div style="text-align: left; font-size: 12px; color: #334155; line-height: 1.5;">' +
            '<p style="margin-bottom: 8px;">Apakah Anda yakin ingin membatalkan penggunaan dan menghapus box ini dari sesi pemeriksaan?</p>' +
            '<div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 7px 10px; margin-bottom: 9px; font-size: 11.5px;">' +
                '<div><b>Nomor Box:</b> Box #' + boxNum + '</div>' +
                '<div><b>Lot Number:</b> <span style="font-family: monospace; font-weight: 700;">' + escapeHtml(lotNo) + '</span></div>' +
                (refNo ? '<div><b>Ref Number:</b> <span style="font-family: monospace; font-weight: 700; color: #1e40af;">' + escapeHtml(refNo) + '</span></div>' : '') +
                '<div><b>Qty Lot:</b> ' + Number(qty).toLocaleString() + ' pcs</div>' +
            '</div>' +
            '<div style="background-color: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 8px; padding: 7px 10px; color: #065f46; font-size: 11px; display: flex; align-items: flex-start; gap: 6px;">' +
                '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="flex-shrink: 0; margin-top: 1px;"><polyline points="20 6 9 17 4 12"/></svg>' +
                '<span><b>Label Bebas:</b> Setelah box ini dihapus, barcode/label ini dapat discan kembali pada Kanban lain atau PC lain tanpa ditolak sistem.</span>' +
            '</div>' +
        '</div>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e11d48',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Ya, Batal Pakai Box Ini',
        cancelButtonText: 'Batal',
        focusCancel: true
    }).then(function(result) {
        if (!result.isConfirmed) return;

        Swal.fire({
            title: 'Menghapus Box dari Sesi...',
            allowOutsideClick: false,
            didOpen: function() { Swal.showLoading(); }
        });

        fetch('<?= base_url("modules/inspection/api/remove_session_lot.php") ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ session_lot_id: parseInt(lotId) })
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (!res.success) {
                Swal.fire({
                    icon: 'error',
                    title: 'Gagal Membatalkan Box',
                    text: res.message || 'Terjadi kesalahan sistem'
                });
                return;
            }

            // Jika box yang sedang difokuskan dihapus, reset fokus box agar otomatis berpindah
            if (activeFocusLotId === parseInt(lotId)) {
                activeFocusLotId = null;
            }

            Swal.fire({
                icon: 'success',
                title: 'Box Berhasil Dibatalkan',
                text: res.message || 'Label telah dibebaskan dan siap digunakan kembali.',
                confirmButtonColor: '#059669',
                timer: 2000,
                timerProgressBar: true
            }).then(function() {
                loadWorkbenchSessionData(currentSessionId);
            });
        })
        .catch(function(err) {
            Swal.fire({
                icon: 'error',
                title: 'Koneksi Error',
                text: 'Gagal menghubungi server saat menghapus box.'
            });
        });
    });
}

// =========================================================================
// ADD SESSION LOT HANDLERS (Tambah Box / Lot Baru ke Sesi Aktif)
// Multi-Scan & Batch Registration
// =========================================================================

var pendingAddLots = [];
var isProcessingAddLotScan = false;

function openAddLotModal() {
    var modal = document.getElementById('modal-add-lot-to-session');
    if (!modal) return;

    pendingAddLots = [];
    isProcessingAddLotScan = false;
    renderPendingAddLotsTable();

    // Ambil Part Code aktif dari sesi
    var effectivePartCode = '';
    if (window.lastWorkbenchSessionData && window.lastWorkbenchSessionData.session) {
        effectivePartCode = window.lastWorkbenchSessionData.session.session_part_code || 
                            window.lastWorkbenchSessionData.session.part_code || '';
    } else if (typeof currentSessionPartCode !== 'undefined' && currentSessionPartCode) {
        effectivePartCode = currentSessionPartCode;
    }

    var partInput = document.getElementById('add-lot-part-code');
    if (partInput) partInput.value = effectivePartCode;

    var qrInput = document.getElementById('add-lot-qr-raw');
    if (qrInput) qrInput.value = '';

    var lotNumInput = document.getElementById('add-lot-number');
    if (lotNumInput) lotNumInput.value = '';

    var qtyInput = document.getElementById('add-lot-qty');
    if (qtyInput) qtyInput.value = '';

    var refInput = document.getElementById('add-lot-ref-number');
    if (refInput) refInput.value = '';

    modal.classList.remove('hidden');
    modal.style.display = 'flex';

    setTimeout(function() {
        if (qrInput) qrInput.focus();
    }, 100);
}

function closeAddLotModal() {
    var modal = document.getElementById('modal-add-lot-to-session');
    if (modal) modal.style.display = 'none';
    pendingAddLots = [];
    isProcessingAddLotScan = false;
}

function renderPendingAddLotsTable() {
    var tbody = document.getElementById('add-lot-table-body');
    var countEl = document.getElementById('add-lot-scanned-count');
    var qtyEl = document.getElementById('add-lot-scanned-qty');
    var btnCountEl = document.getElementById('add-lot-btn-count');

    var count = pendingAddLots.length;
    var totalQty = 0;
    pendingAddLots.forEach(function(item) {
        totalQty += (parseInt(item.qty, 10) || 0);
    });

    if (countEl) countEl.textContent = count;
    if (qtyEl) qtyEl.textContent = totalQty.toLocaleString();
    if (btnCountEl) btnCountEl.textContent = count;

    if (!tbody) return;

    if (count === 0) {
        tbody.innerHTML = '<tr><td colspan="5" style="padding: 20px; text-align: center; color: #94a3b8; font-size: 11px;">Belum ada box yang ditambahkan. Silakan scan barcode QR atau masukkan manual di atas.</td></tr>';
        return;
    }

    var html = '';
    pendingAddLots.forEach(function(lot, idx) {
        var refDisplay = lot.ref_number ? ('<span style="font-family: monospace; font-weight: 700; color: #1e40af; background-color: #dbeafe; padding: 2px 6px; border-radius: 4px; font-size: 10px;">' + escapeHtml(lot.ref_number) + '</span>') : '<span style="color:#94a3b8; font-size:10px;">-</span>';
        var lotDisplay = '<span style="font-family: monospace; font-weight: 700; color: #0f172a; font-size: 11px;">' + escapeHtml(lot.lot_number) + '</span>';
        var qtyDisplay = '<span style="font-family: monospace; font-weight: 800; color: #0f172a; font-size: 11px;">' + (parseInt(lot.qty, 10) || 0).toLocaleString() + '</span> <span style="font-size: 9.5px; color: #64748b;">pcs</span>';
        var removeBtn = '<button type="button" onclick="removePendingAddLot(' + idx + ')" style="padding: 2px 6px; background-color: #fff1f2; color: #e11d48; border: 1px solid #fecdd3; border-radius: 4px; font-size: 10px; font-weight: 700; cursor: pointer;">Hapus</button>';

        html += '<tr style="border-bottom: 1px solid #f1f5f9; background-color: ' + (idx % 2 === 0 ? '#ffffff' : '#f8fafc') + ';">' +
                '<td style="padding: 6px 10px; font-size: 11px; color: #64748b; font-weight: 600;">' + (idx + 1) + '</td>' +
                '<td style="padding: 6px 10px;">' + refDisplay + '</td>' +
                '<td style="padding: 6px 10px;">' + lotDisplay + '</td>' +
                '<td style="padding: 6px 10px; text-align: right;">' + qtyDisplay + '</td>' +
                '<td style="padding: 6px 10px; text-align: center;">' + removeBtn + '</td>' +
                '</tr>';
    });
    tbody.innerHTML = html;
}

function removePendingAddLot(idx) {
    if (idx >= 0 && idx < pendingAddLots.length) {
        pendingAddLots.splice(idx, 1);
        renderPendingAddLotsTable();
    }
}

function handleModalAddLotQrKeydown(e, inputEl) {
    if (e.key === 'Enter') {
        e.preventDefault();
        handleModalAddLotQrInput(inputEl, true);
    }
}

function handleModalAddLotQrInput(inputEl, force) {
    if (!inputEl) return;
    if (isProcessingAddLotScan) return;

    var val = inputEl.value;
    if (!val) return;

    var hasNewline = (val.includes('\n') || val.includes('\r'));

    if (!force && !hasNewline) {
        return;
    }

    var parsed = parseBarcodeQR(val);
    var expectedPartCode = (document.getElementById('add-lot-part-code') ? document.getElementById('add-lot-part-code').value.trim() : '');

    // Part Code Mismatch check
    if (parsed.Z1 && expectedPartCode && parsed.Z1.toUpperCase() !== expectedPartCode.toUpperCase()) {
        Swal.fire({
            icon: 'error',
            title: 'Part Code Box Tidak Cocok',
            html: '<div style="font-size: 12px; text-align: left; line-height: 1.6; color: #334155;">' +
                  'Part Code pada label box (<b style="color:#be123c;">"' + escapeHtml(parsed.Z1) + '"</b>) tidak cocok dengan Part Sesi ini (<b style="color:#0284c7;">"' + escapeHtml(expectedPartCode) + '"</b>).<br><br>' +
                  'Pastikan Anda men-scan label box yang sesuai dengan part sesi.' +
                  '</div>'
        }).then(function() {
            inputEl.value = '';
            inputEl.focus();
        });
        inputEl.value = '';
        return;
    }

    var lotNumber = parsed.Z2 ? parsed.Z2.trim() : '';
    var qty = parseInt(parsed.Z3, 10) || 0;
    var refNumber = parsed.Z5 ? parsed.Z5.trim() : '';
    var partCode = parsed.Z1 ? parsed.Z1.trim() : expectedPartCode;

    if (!lotNumber) {
        if (force) {
            Swal.fire({
                icon: 'warning',
                title: 'Lot Number Tidak Ditemukan',
                text: 'QR barcode tidak memuat parameter Z2 (Lot Number).'
            }).then(function() {
                inputEl.value = '';
                inputEl.focus();
            });
            inputEl.value = '';
        }
        return;
    }

    if (qty <= 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Qty Box Tidak Valid',
            text: 'Nilai Qty pada barcode harus lebih dari 0 pcs.'
        }).then(function() {
            inputEl.value = '';
            inputEl.focus();
        });
        inputEl.value = '';
        return;
    }

    // 1. Cek duplikasi di antrean yang sedang disusun
    if (refNumber && pendingAddLots.some(function(l) { return l.ref_number && l.ref_number.toUpperCase() === refNumber.toUpperCase(); })) {
        Swal.fire({
            icon: 'warning',
            title: 'Ref Number Duplikat',
            text: 'Box dengan Ref Number "' + refNumber + '" sudah ada di daftar antrean.'
        }).then(function() {
            inputEl.value = '';
            inputEl.focus();
        });
        inputEl.value = '';
        return;
    }

    // 2. Cek duplikasi dengan box yang sudah ada di sesi aktif saat ini
    if (refNumber && window.lastWorkbenchSessionData && Array.isArray(window.lastWorkbenchSessionData.lots)) {
        var isDuplicateInSession = window.lastWorkbenchSessionData.lots.some(function(l) {
            return l.ref_number && l.ref_number.toUpperCase() === refNumber.toUpperCase();
        });
        if (isDuplicateInSession) {
            Swal.fire({
                icon: 'warning',
                title: 'Box Sudah Terdaftar di Sesi',
                text: 'Box dengan Ref Number "' + refNumber + '" sudah pernah didaftarkan pada sesi ini.'
            }).then(function() {
                inputEl.value = '';
                inputEl.focus();
            });
            inputEl.value = '';
            return;
        }
    }

    // 3. Validasi DID (Daily Inspection Data) & Lock Sesi Lain via API secara asynchronous
    isProcessingAddLotScan = true;
    inputEl.disabled = true;

    var checkUrl = '<?= base_url("modules/inspection/scan_validate.php") ?>?mode=check_did_only' +
                   '&part_code=' + encodeURIComponent(partCode) +
                   '&lot_number=' + encodeURIComponent(lotNumber) +
                   '&ref_number=' + encodeURIComponent(refNumber) +
                   '&current_session_id=' + encodeURIComponent(currentSessionId);

    fetch(checkUrl)
        .then(function(res) { return res.json(); })
        .then(function(data) {
            isProcessingAddLotScan = false;
            inputEl.disabled = false;
            inputEl.value = '';

            if (!data.success) {
                var title = 'Pemeriksaan Box Gagal';
                if (data.error_type === 'in_progress_duplicate' || data.error_type === 'already_passed_duplicate') {
                    title = 'Duplikasi Label Terdeteksi';
                } else if (data.error_type === 'did_missing' || data.error_type === 'did_ng') {
                    title = 'Verifikasi Dimensi (DID) Ditolak';
                }

                Swal.fire({
                    icon: 'error',
                    title: title,
                    html: '<div style="font-size: 12px; text-align: left; line-height: 1.6; color: #334155;">' +
                          escapeHtml(data.message || 'Label tidak memenuhi syarat untuk didaftarkan.') +
                          '</div>',
                    confirmButtonColor: '#ef4444'
                }).then(function() {
                    inputEl.focus();
                });
                return;
            }

            // Tambahkan ke antrean
            pendingAddLots.push({
                part_code: partCode,
                lot_number: lotNumber,
                qty: qty,
                ref_number: refNumber,
                raw_qr: val.trim()
            });

            // Tampilkan preview di input manual sebagai feedback
            if (document.getElementById('add-lot-number')) document.getElementById('add-lot-number').value = lotNumber;
            if (document.getElementById('add-lot-qty')) document.getElementById('add-lot-qty').value = qty;
            if (document.getElementById('add-lot-ref-number')) document.getElementById('add-lot-ref-number').value = refNumber;

            renderPendingAddLotsTable();
            inputEl.focus();
        })
        .catch(function(err) {
            isProcessingAddLotScan = false;
            inputEl.disabled = false;
            inputEl.value = '';
            console.error('Validate scan error:', err);
            Swal.fire({
                icon: 'error',
                title: 'Koneksi Terganggu',
                text: 'Gagal memverifikasi label ke server. Silakan coba scan ulang.'
            }).then(function() {
                inputEl.focus();
            });
        });
}

function addManualLotToPending() {
    var partInput = document.getElementById('add-lot-part-code');
    var lotInput = document.getElementById('add-lot-number');
    var qtyInput = document.getElementById('add-lot-qty');
    var refInput = document.getElementById('add-lot-ref-number');
    var qrInput = document.getElementById('add-lot-qr-raw');

    var partCode = (partInput ? partInput.value.trim() : '');
    var lotNumber = (lotInput ? lotInput.value.trim() : '');
    var qty = parseInt(qtyInput ? qtyInput.value : 0, 10) || 0;
    var refNumber = (refInput ? refInput.value.trim() : '');

    if (!lotNumber) {
        Swal.fire({
            icon: 'warning',
            title: 'Lot Number Wajib Diisi',
            text: 'Silakan isi Lot Number box yang akan didaftarkan.'
        });
        if (lotInput) lotInput.focus();
        return;
    }

    if (qty <= 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Qty Box Wajib Diisi',
            text: 'Qty box harus bernilai lebih dari 0 pcs.'
        });
        if (qtyInput) qtyInput.focus();
        return;
    }

    // Duplikasi di antrean
    if (refNumber && pendingAddLots.some(function(l) { return l.ref_number && l.ref_number.toUpperCase() === refNumber.toUpperCase(); })) {
        Swal.fire({
            icon: 'warning',
            title: 'Ref Number Duplikat',
            text: 'Box dengan Ref Number "' + refNumber + '" sudah ada di daftar antrean.'
        });
        return;
    }

    // Duplikasi di sesi aktif
    if (refNumber && window.lastWorkbenchSessionData && Array.isArray(window.lastWorkbenchSessionData.lots)) {
        var isDuplicateInSession = window.lastWorkbenchSessionData.lots.some(function(l) {
            return l.ref_number && l.ref_number.toUpperCase() === refNumber.toUpperCase();
        });
        if (isDuplicateInSession) {
            Swal.fire({
                icon: 'warning',
                title: 'Box Sudah Terdaftar di Sesi',
                text: 'Box dengan Ref Number "' + refNumber + '" sudah pernah didaftarkan pada sesi ini.'
            });
            return;
        }
    }

    Swal.fire({
        title: 'Memeriksa Box...',
        text: 'Memverifikasi status Cek Dimensi (DID)...',
        allowOutsideClick: false,
        didOpen: function() { Swal.showLoading(); }
    });

    var checkUrl = '<?= base_url("modules/inspection/scan_validate.php") ?>?mode=check_did_only' +
                   '&part_code=' + encodeURIComponent(partCode) +
                   '&lot_number=' + encodeURIComponent(lotNumber) +
                   '&ref_number=' + encodeURIComponent(refNumber) +
                   '&current_session_id=' + encodeURIComponent(currentSessionId);

    fetch(checkUrl)
        .then(function(res) { return res.json(); })
        .then(function(data) {
            closeSwalSafely();
            if (!data.success) {
                var title = 'Pemeriksaan Box Gagal';
                if (data.error_type === 'in_progress_duplicate' || data.error_type === 'already_passed_duplicate') {
                    title = 'Duplikasi Label Terdeteksi';
                } else if (data.error_type === 'did_missing' || data.error_type === 'did_ng') {
                    title = 'Verifikasi Dimensi (DID) Ditolak';
                }

                Swal.fire({
                    icon: 'error',
                    title: title,
                    html: '<div style="font-size: 12px; text-align: left; line-height: 1.6; color: #334155;">' +
                          escapeHtml(data.message || 'Label tidak valid untuk didaftarkan.') +
                          '</div>',
                    confirmButtonColor: '#ef4444'
                });
                return;
            }

            pendingAddLots.push({
                part_code: partCode,
                lot_number: lotNumber,
                qty: qty,
                ref_number: refNumber,
                raw_qr: ''
            });

            renderPendingAddLotsTable();

            // Reset manual inputs
            if (lotInput) lotInput.value = '';
            if (qtyInput) qtyInput.value = '';
            if (refInput) refInput.value = '';

            if (qrInput) qrInput.focus();
        })
        .catch(function(err) {
            closeSwalSafely();
            console.error('Validate manual lot error:', err);
            Swal.fire({
                icon: 'error',
                title: 'Koneksi Terganggu',
                text: 'Gagal memverifikasi label ke server. Silakan coba kembali.'
            });
        });
}

function executeAddLotSubmit() {
    if (!currentSessionId) {
        Swal.fire({ icon: 'error', title: 'Sesi Tidak Ditemukan', text: 'ID Sesi aktif tidak tersedia.' });
        return;
    }

    // Jika antrean kosong, periksa apakah pengguna sudah mengisi form manual tapi lupa klik "+ Masukkan ke Daftar Box"
    if (pendingAddLots.length === 0) {
        var lotInput = document.getElementById('add-lot-number');
        var qtyInput = document.getElementById('add-lot-qty');
        var lotNumber = (lotInput ? lotInput.value.trim() : '');
        var qty = parseInt(qtyInput ? qtyInput.value : 0, 10) || 0;

        if (lotNumber && qty > 0) {
            addManualLotToPending();
            return;
        }

        Swal.fire({
            icon: 'warning',
            title: 'Daftar Box Masih Kosong',
            text: 'Silakan scan barcode QR label atau masukkan data box terlebih dahulu sebelum menyimpan.'
        });
        return;
    }

    Swal.fire({
        title: 'Mendaftarkan Box ke Sesi...',
        text: 'Memproses ' + pendingAddLots.length + ' box dan memperbarui kalkulasi AQL...',
        allowOutsideClick: false,
        didOpen: function() { Swal.showLoading(); }
    });

    fetch('<?= base_url("modules/inspection/api/add_session_lot.php") ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            session_id: currentSessionId,
            lots: pendingAddLots
        })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (!data.success) {
            Swal.fire({
                icon: 'error',
                title: 'Gagal Menambahkan Box',
                html: '<div style="font-size: 12px; text-align: left; line-height: 1.6; color: #334155;">' +
                      escapeHtml(data.message || 'Terjadi kesalahan pada sistem.') +
                      '</div>'
            });
            return;
        }

        var addedCount = pendingAddLots.length;
        closeAddLotModal();

        if (data.first_new_lot_id) {
            activeFocusLotId = parseInt(data.first_new_lot_id, 10);
        }

        Swal.fire({
            icon: 'success',
            title: 'Box Berhasil Ditambahkan',
            text: data.message || (addedCount + ' box berhasil didaftarkan ke sesi.'),
            confirmButtonColor: '#059669',
            timer: 2000,
            timerProgressBar: true
        }).then(function() {
            loadWorkbenchSessionData(currentSessionId);
        });
    })
    .catch(function(err) {
        console.error('Add session lot error:', err);
        Swal.fire({
            icon: 'error',
            title: 'Koneksi Error',
            text: 'Gagal menghubungi server saat mendaftarkan box.'
        });
    });
}

function cancelReplacementLot(lotId, lotNumber) {
    if (!lotId) return;

    Swal.fire({
        title: 'Batalkan Box Pengganti?',
        html: 'Apakah Anda yakin ingin membatalkan box pengganti untuk Lot <b>#' + escapeHtml(lotNumber) + '</b>?<br><br><span style="font-size: 11.5px; color: #be123c; background-color: #fff1f2; padding: 6px 10px; border-radius: 6px; display: inline-block;">Box pengganti ini akan dihapus dari sesi, dan box sebelumnya akan dikembalikan ke status <b>DITOLAK (REJECTED)</b> dengan temuan NG semula.</span>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e11d48',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Ya, Batalkan Box Pengganti',
        cancelButtonText: 'Batal'
    }).then(function(result) {
        if (!result.isConfirmed) return;

        Swal.fire({
            title: 'Membatalkan Box Pengganti...',
            allowOutsideClick: false,
            didOpen: function() { Swal.showLoading(); }
        });

        fetch('<?= base_url("modules/inspection/api/cancel_replacement_lot.php") ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ replacement_lot_id: parseInt(lotId), session_lot_id: parseInt(lotId) })
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (!res.success) {
                Swal.fire({
                    icon: 'error',
                    title: 'Gagal Membatalkan',
                    text: res.message || 'Terjadi kesalahan sistem'
                });
                return;
            }

            Swal.fire({
                icon: 'success',
                title: 'Box Pengganti Dibatalkan',
                text: res.message || 'Lot berhasil dikembalikan ke status REJECTED.',
                confirmButtonColor: '#059669'
            }).then(function() {
                loadWorkbenchSessionData(currentSessionId);
            });
        })
        .catch(function(err) {
            Swal.fire({
                icon: 'error',
                title: 'Koneksi Error',
                text: 'Gagal terhubung ke server!'
            });
        });
    });
}

function cancelSortLot(lotId, lotNumber) {
    if (!lotId) return;

    Swal.fire({
        title: 'Batalkan Re-Inspeksi / Sortir?',
        html: 'Apakah Anda yakin ingin membatalkan tindakan sortir pada Lot <b>#' + escapeHtml(lotNumber) + '</b>?<br><br><span style="font-size: 11.5px; color: #be123c; background-color: #fff1f2; padding: 6px 10px; border-radius: 6px; display: inline-block;">Status lot akan dikembalikan menjadi <b>DITOLAK (REJECTED)</b> dan riwayat sortir dibatalkan (temuan NG aktif kembali).</span>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e11d48',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Ya, Kembalikan ke REJECTED',
        cancelButtonText: 'Batal'
    }).then(function(result) {
        if (!result.isConfirmed) return;

        Swal.fire({
            title: 'Membatalkan Sortir...',
            allowOutsideClick: false,
            didOpen: function() { Swal.showLoading(); }
        });

        fetch('<?= base_url("modules/inspection/api/cancel_sort_lot.php") ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ session_lot_id: parseInt(lotId) })
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (!res.success) {
                Swal.fire({
                    icon: 'error',
                    title: 'Gagal Membatalkan',
                    text: res.message || 'Terjadi kesalahan sistem'
                });
                return;
            }

            Swal.fire({
                icon: 'success',
                title: 'Sortir Dibatalkan',
                text: res.message || 'Lot berhasil dikembalikan ke status REJECTED.',
                confirmButtonColor: '#059669'
            }).then(function() {
                loadWorkbenchSessionData(currentSessionId);
            });
        })
        .catch(function(err) {
            Swal.fire({
                icon: 'error',
                title: 'Koneksi Error',
                text: 'Gagal terhubung ke server!'
            });
        });
    });
}



// ═══════════════════════════════════════════════════════════════════════════
// LOT RE-INSPECTION MODAL HANDLERS (OPSI A: PER-LOT AQL WORKBENCH)
// ═══════════════════════════════════════════════════════════════════════════
var currentLotReinspTab = 'replace';

function openLotReinspectionModal(lotId, lotNumber, refNumber, qty) {
    var modal = document.getElementById('modal-lot-reinspection');
    if (!modal) return;

    document.getElementById('modal-reinsp-lot-id').value = lotId || '';
    
    // Determine part code from session
    var effectivePartCode = '';
    if (typeof activeSessionData !== 'undefined' && activeSessionData && activeSessionData.part_code) {
        effectivePartCode = activeSessionData.part_code;
    } else if (typeof currentSessionPartCode !== 'undefined' && currentSessionPartCode) {
        effectivePartCode = currentSessionPartCode;
    } else if (typeof currentSessionObj !== 'undefined' && currentSessionObj && currentSessionObj.part_code) {
        effectivePartCode = currentSessionObj.part_code;
    }
    
    document.getElementById('modal-reinsp-part-code').value = effectivePartCode;
    var partCodeInput = document.getElementById('reinsp-part-code');
    if (partCodeInput) partCodeInput.value = effectivePartCode;

    var subtitle = document.getElementById('modal-reinsp-lot-subtitle');
    if (subtitle) {
        var subText = 'Lot #' + (lotNumber || '-');
        if (refNumber) subText += ' | Ref: ' + refNumber;
        if (qty) subText += ' | Qty: ' + Number(qty).toLocaleString() + ' pcs';
        subtitle.textContent = subText;
    }

    // Reset inputs
    var qrInput = document.getElementById('reinsp-scan-qr-raw');
    if (qrInput) qrInput.value = '';
    var lotNumInput = document.getElementById('reinsp-lot-number');
    if (lotNumInput) lotNumInput.value = '';
    var qtyInput = document.getElementById('reinsp-qty');
    if (qtyInput) qtyInput.value = '';
    var refInput = document.getElementById('reinsp-ref-number');
    if (refInput) refInput.value = '';
    var remInput = document.getElementById('reinsp-replace-remarks');
    if (remInput) remInput.value = '';
    var sortNotes = document.getElementById('reinsp-sort-notes');
    if (sortNotes) sortNotes.value = '';

    switchLotReinspTab('replace');

    modal.style.display = 'flex';

    setTimeout(function() {
        if (qrInput) qrInput.focus();
    }, 100);
}

function closeLotReinspectionModal() {
    var modal = document.getElementById('modal-lot-reinspection');
    if (modal) modal.style.display = 'none';
}

function switchLotReinspTab(tabName) {
    currentLotReinspTab = tabName;
    var tabRep = document.getElementById('tab-reinsp-replace');
    var tabSort = document.getElementById('tab-reinsp-sort');
    var contentRep = document.getElementById('content-reinsp-replace');
    var contentSort = document.getElementById('content-reinsp-sort');
    var btnSubmit = document.getElementById('btn-submit-lot-reinsp');

    if (tabName === 'replace') {
        if (tabRep) {
            tabRep.style.borderBottom = '2.5px solid #2563eb';
            tabRep.style.backgroundColor = '#ffffff';
            tabRep.style.color = '#2563eb';
            tabRep.style.fontWeight = '800';
        }
        if (tabSort) {
            tabSort.style.borderBottom = '2.5px solid transparent';
            tabSort.style.backgroundColor = 'transparent';
            tabSort.style.color = '#64748b';
            tabSort.style.fontWeight = '700';
        }
        if (contentRep) contentRep.style.display = 'block';
        if (contentSort) contentSort.style.display = 'none';
        if (btnSubmit) {
            btnSubmit.innerHTML = 'Konfirmasi & Muat Box Pengganti';
            btnSubmit.style.backgroundColor = '#2563eb';
        }
        var qrInput = document.getElementById('reinsp-scan-qr-raw');
        if (qrInput) qrInput.focus();
    } else {
        if (tabSort) {
            tabSort.style.borderBottom = '2.5px solid #d97706';
            tabSort.style.backgroundColor = '#ffffff';
            tabSort.style.color = '#d97706';
            tabSort.style.fontWeight = '800';
        }
        if (tabRep) {
            tabRep.style.borderBottom = '2.5px solid transparent';
            tabRep.style.backgroundColor = 'transparent';
            tabRep.style.color = '#64748b';
            tabRep.style.fontWeight = '700';
        }
        if (contentRep) contentRep.style.display = 'none';
        if (contentSort) contentSort.style.display = 'block';
        if (btnSubmit) {
            btnSubmit.innerHTML = 'Mulai Uji Ulang Box Ini';
            btnSubmit.style.backgroundColor = '#d97706';
        }
        var sortNotes = document.getElementById('reinsp-sort-notes');
        if (sortNotes) sortNotes.focus();
    }
}

function handleLotReinspQrInput(inputEl, force) {
    if (!inputEl) return;
    var val = inputEl.value;
    if (!val) return;

    var parsed = parseBarcodeQR(val);

    var expectedPartCode = (document.getElementById('modal-reinsp-part-code') ? document.getElementById('modal-reinsp-part-code').value.trim() : '') || (typeof activeSessionData !== 'undefined' && activeSessionData ? activeSessionData.part_code : '') || (typeof currentSessionPartCode !== 'undefined' ? currentSessionPartCode : '');

    // Early Part Code Mismatch check
    if (parsed.Z1 && expectedPartCode && parsed.Z1.toUpperCase() !== expectedPartCode.toUpperCase()) {
        if (force || val.includes('\n') || val.includes('\r')) {
            Swal.fire({
                icon: 'error',
                title: '⚠️ PART CODE BOX PENGGANTI TIDAK COCOK!',
                html: '<div style="font-size: 12px; text-align: left; line-height: 1.6; color: #334155;">' +
                      'Part Code pada label box (<b style="color:#be123c;">"' + escapeHtml(parsed.Z1) + '"</b>) tidak cocok dengan Part Sesi ini (<b style="color:#0284c7;">"' + escapeHtml(expectedPartCode) + '"</b>)!<br><br>' +
                      '<b style="color:#e11d48;">Pastikan Anda men-scan label box pengganti yang sesuai dengan part sesi!</b>' +
                      '</div>'
            });
            // Rollback & bersihkan field
            if (document.getElementById('reinsp-part-code')) document.getElementById('reinsp-part-code').value = expectedPartCode;
            if (document.getElementById('reinsp-lot-number')) document.getElementById('reinsp-lot-number').value = '';
            if (document.getElementById('reinsp-qty')) document.getElementById('reinsp-qty').value = '';
            if (document.getElementById('reinsp-ref-number')) document.getElementById('reinsp-ref-number').value = '';
            inputEl.value = '';
            setTimeout(function() { if (inputEl) inputEl.focus(); }, 100);
        }
        return;
    }

    if (parsed.Z1 && document.getElementById('reinsp-part-code')) {
        document.getElementById('reinsp-part-code').value = parsed.Z1;
    }
    if (parsed.Z2 && document.getElementById('reinsp-lot-number')) {
        document.getElementById('reinsp-lot-number').value = parsed.Z2;
    }
    if (parsed.Z3 && parsed.Z3 > 0 && document.getElementById('reinsp-qty')) {
        document.getElementById('reinsp-qty').value = parsed.Z3;
    }
    if (parsed.Z5 && document.getElementById('reinsp-ref-number')) {
        document.getElementById('reinsp-ref-number').value = parsed.Z5;
    }

    var hasNewline = (val.includes('\n') || val.includes('\r'));
    if (force || hasNewline) {
        inputEl.value = '';
    }
}

function executeLotReinspectionSubmit() {
    var lotId = parseInt(document.getElementById('modal-reinsp-lot-id').value) || 0;
    if (!lotId) {
        Swal.fire({ icon: 'error', title: 'Data Tidak Valid', text: 'ID Lot tidak ditemukan.' });
        return;
    }

    if (currentLotReinspTab === 'replace') {
        var partCode = (document.getElementById('reinsp-part-code').value || '').trim();
        var lotNumber = (document.getElementById('reinsp-lot-number').value || '').trim();
        var qty = parseInt(document.getElementById('reinsp-qty').value) || 0;
        var refNumber = (document.getElementById('reinsp-ref-number').value || '').trim();
        var remarks = (document.getElementById('reinsp-replace-remarks').value || '').trim();

        if (!lotNumber) {
            Swal.fire({ icon: 'warning', title: 'Lot Number Pengganti Wajib Diisi', text: 'Silakan scan QR label atau masukkan Lot Number pengganti.' });
            return;
        }

        if (qty <= 0) {
            Swal.fire({ icon: 'warning', title: 'Qty Box Wajib Diisi', text: 'Qty Box pengganti harus lebih besar dari 0.' });
            return;
        }

        Swal.fire({
            title: 'Memproses Box Pengganti...',
            text: 'Memverifikasi status Cek Dimensi (DID) dan mendaftarkan box...',
            allowOutsideClick: false,
            didOpen: function() { Swal.showLoading(); }
        });

        fetch('<?= base_url("modules/inspection/api/replace_session_lot.php") ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                session_lot_id: lotId,
                new_z1: partCode,
                new_z2: lotNumber,
                new_z3: qty,
                new_z5: refNumber,
                remarks: remarks
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) {
                Swal.fire({
                    icon: 'error',
                    title: 'Gagal Memuat Box Pengganti',
                    html: '<div style="font-size: 12px; text-align: left; line-height: 1.6; color: #334155;">' +
                          escapeHtml(data.message || 'Terjadi kesalahan sistem') +
                          '</div>'
                });
                return;
            }

            closeLotReinspectionModal();
            Swal.fire({
                icon: 'success',
                title: 'Box Pengganti Siap',
                text: data.message || 'Box pengganti berhasil didaftarkan.',
                timer: 1600,
                showConfirmButton: false
            });
            loadWorkbenchSessionData(currentSessionId);
        })
        .catch(function(err) {
            console.error('Re-inspection replace error:', err);
            Swal.fire({ icon: 'error', title: 'Koneksi Error', text: 'Gagal menghubungi server.' });
        });

    } else if (currentLotReinspTab === 'sort') {
        var sortNotes = (document.getElementById('reinsp-sort-notes').value || '').trim();

        Swal.fire({
            title: 'Mulai Uji Ulang Box?',
            html: '<div style="font-size: 12px; text-align: left; line-height: 1.5; color: #334155;">' +
                  'Pastikan semua part cacat/NG dalam box ini sudah disortir dan ditukar dengan part bagus.<br><br>' +
                  'Temuan NG box ini akan direset ke 0 dan sampel fisik wajib diperiksa kembali.' +
                  '</div>',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#d97706',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Ya, Mulai Uji Ulang',
            cancelButtonText: 'Batal'
        }).then(function(res) {
            if (!res.isConfirmed) return;

            Swal.fire({
                title: 'Memproses...',
                allowOutsideClick: false,
                didOpen: function() { Swal.showLoading(); }
            });

            fetch('<?= base_url("modules/inspection/api/recheck_sorted_lot.php") ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    session_lot_id: lotId,
                    notes: sortNotes
                })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) {
                    Swal.fire({ icon: 'error', title: 'Gagal', text: data.message || 'Terjadi kesalahan' });
                    return;
                }

                closeLotReinspectionModal();
                Swal.fire({
                    icon: 'success',
                    title: 'Box Siap Diuji Ulang',
                    text: data.message || 'Status box berhasil dikembalikan ke pemeriksaan.',
                    timer: 1500,
                    showConfirmButton: false
                });
                loadWorkbenchSessionData(currentSessionId);
            })
            .catch(function(err) {
                console.error('Recheck sorted error:', err);
                Swal.fire({ icon: 'error', title: 'Koneksi Error', text: 'Gagal menghubungi server.' });
            });
        });
    }
}

function escapeHtml(text) {
    if (!text) return '';
    return String(text).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

// ═══════════════════════════════════════════════════════════════════════════
// BATCH RE-INSPECTION MODAL HANDLERS
// ═══════════════════════════════════════════════════════════════════════════
var currentSessionObj = <?= $session ? json_encode($session) : "null" ?>;
var currentNgLots = [];
var currentSessionLots = [];
var currentSessionPartCode = '<?= $session ? htmlspecialchars($session['part_code']) : "" ?>';
var reinspectApiUrl = '<?= base_url("modules/inspection/api/reinspect_lot.php") ?>';

var batchSelectedStrategy = 'rescan'; // 'rescan' or 'replace'
var batchSelectedSubAction = 'rescan_restart'; // 'rescan_restart', 'rescan_continue', 'replace_ng_only', 'replace_all_lots'
var scannedBatchLabels = []; // Array of { z1, z2, z3, z5, raw }
var currentBatchLotFilter = 'ng'; // 'ng' or 'all'

/**
 * Render Lot List in Modal (NG Lots vs All Session Lots)
 */
function renderBatchLotList(filterMode) {
    if (filterMode) currentBatchLotFilter = filterMode;

    var countNgBadge = document.getElementById('batch-modal-ng-count');
    var countAllBadge = document.getElementById('batch-modal-all-count');
    var listContainer = document.getElementById('batch-modal-lot-list');
    var tabNgBtn = document.getElementById('btn-batch-tab-ng');
    var tabAllBtn = document.getElementById('btn-batch-tab-all');

    var actionableLots = (currentNgLots || []).filter(function(l) {
        return l.lot_status === 'ng_found' || l.lot_status === 'ng_quarantine';
    });

    if (countNgBadge) countNgBadge.textContent = actionableLots.length;
    if (countAllBadge) countAllBadge.textContent = (currentSessionLots || []).length;

    if (tabNgBtn && tabAllBtn) {
        if (currentBatchLotFilter === 'ng') {
            tabNgBtn.style.backgroundColor = '#ffffff';
            tabNgBtn.style.color = '#be123c';
            tabNgBtn.style.boxShadow = '0 1px 3px rgba(0,0,0,0.1)';
            tabAllBtn.style.backgroundColor = 'transparent';
            tabAllBtn.style.color = '#64748b';
            tabAllBtn.style.boxShadow = 'none';
        } else {
            tabAllBtn.style.backgroundColor = '#ffffff';
            tabAllBtn.style.color = '#0284c7';
            tabAllBtn.style.boxShadow = '0 1px 3px rgba(0,0,0,0.1)';
            tabNgBtn.style.backgroundColor = 'transparent';
            tabNgBtn.style.color = '#64748b';
            tabNgBtn.style.boxShadow = 'none';
        }
    }

    if (!listContainer) return;

    var displayLots = (currentBatchLotFilter === 'ng') ? actionableLots : (currentSessionLots || []);

    if (displayLots.length === 0) {
        listContainer.innerHTML = (currentBatchLotFilter === 'ng')
            ? '<p style="font-size:12px; color:#64748b; text-align:center; padding:12px 0;">Tidak ada lot NG dalam sesi ini.</p>'
            : '<p style="font-size:12px; color:#64748b; text-align:center; padding:12px 0;">Tidak ada lot dalam sesi ini.</p>';
        return;
    }

    var ngLotMap = {};
    actionableLots.forEach(function(l) {
        if (l.id) ngLotMap['id_' + l.id] = l;
        if (l.session_lot_id) ngLotMap['id_' + l.session_lot_id] = l;
        if (l.ref_number) ngLotMap['ref_' + l.ref_number] = l;
    });

    var html = '';
    displayLots.forEach(function(lot) {
        var lotNo = lot.lot_number || '-';
        var refNo = lot.ref_number || '';
        var qty = lot.qty || lot.total_qty || 0;

        var ngData = null;
        if (lot.id && ngLotMap['id_' + lot.id]) {
            ngData = ngLotMap['id_' + lot.id];
        } else if (lot.session_lot_id && ngLotMap['id_' + lot.session_lot_id]) {
            ngData = ngLotMap['id_' + lot.session_lot_id];
        } else if (refNo && ngLotMap['ref_' + refNo]) {
            ngData = ngLotMap['ref_' + refNo];
        }

        var isNg = !!ngData || lot.lot_status === 'ng_found' || lot.lot_status === 'ng_quarantine';

        var statusBadge = '';
        var cardStyle = '';

        if (isNg) {
            cardStyle = 'background-color: #fff1f2; border: 1px solid #fecdd3;';
            var defects = (ngData && ngData.ng_defects) ? ngData.ng_defects : (lot.ng_defects || []);
            if (defects && defects.length > 0) {
                statusBadge = defects.map(function(d) {
                    return '<span style="display:inline-block; background-color:#ffe4e6; color:#9f1239; border:1px solid #fca5a5; border-radius:4px; padding:1px 5px; font-size:9px; font-weight:700; margin-right:4px;">' +
                           escapeHtml(d.defect_name) + ' ×' + d.total_qty_ng + '</span>';
                }).join('');
            } else {
                statusBadge = '<span style="display:inline-flex; align-items:center; gap:4px; font-size:9.5px; color:#e11d48; font-weight:800;"><span style="width:6px; height:6px; border-radius:50%; background-color:#e11d48; display:inline-block;"></span> Lot NG</span>';
            }
        } else {
            cardStyle = 'background-color: #ffffff; border: 1px solid #e2e8f0;';
            statusBadge = '<span style="display:inline-flex; align-items:center; gap:3px; background-color:#dcfce7; color:#15803d; border:1px solid #bbf7d0; border-radius:4px; padding:1px 6px; font-size:9px; font-weight:800;"><svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg> OK (Normal)</span>';
        }

        html += '<div style="' + cardStyle + ' border-radius: 8px; padding: 6px 10px; display: flex; align-items: center; justify-content: space-between; font-size: 12px; box-shadow: 0 1px 2px rgba(0,0,0,0.02);">' +
                    '<div>' +
                        '<div style="font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 5px;">' +
                            '<span style="display: inline-flex; align-items: center; gap: 4px;"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="color:#64748b;"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>' + escapeHtml(lotNo) + '</span>' +
                            (refNo ? '<span style="font-size: 10px; color: #64748b; font-family: monospace;">(' + escapeHtml(refNo) + ')</span>' : '') +
                        '</div>' +
                        '<div style="margin-top: 2px; display: flex; align-items: center; gap: 4px;">' + statusBadge + '</div>' +
                    '</div>' +
                    '<span style="font-size: 10px; font-weight: 800; color: #334155; background-color: #f1f5f9; border: 1px solid #cbd5e1; padding: 2px 8px; border-radius: 9999px;">' + qty + ' pcs</span>' +
                '</div>';
    });

    listContainer.innerHTML = html;
}

/**
 * Buka Modal Batch Re-Inspeksi (#batch-reinspection-modal-overlay)
 */
function openBatchReinspectionModal() {
    var modal = document.getElementById('batch-reinspection-modal-overlay');
    if (!modal) return;

    // Dynamically update Header Info Banner (Part Code, Part Name, Kanban No, ETA, Customer, Jenis Inspeksi)
    if (typeof currentSessionObj !== 'undefined' && currentSessionObj) {
        var pCode = currentSessionObj.part_code || currentSessionPartCode || '-';
        var pName = currentSessionObj.part_name || '-';
        var kNo = currentSessionObj.kanban_no || currentSessionObj.doc_no || '-';
        var pCust = currentSessionObj.customer || 'PT. Indonesia Epson Industry';
        var inspType = currentSessionObj.inspection_type || 'kanban';
        var pEta = currentSessionObj.kanban_eta || currentSessionObj.eta || currentSessionObj.kanban_req_date || '';

        var elCode = document.getElementById('batch-modal-part-code');
        if (elCode) elCode.textContent = pCode;

        var elName = document.getElementById('batch-modal-part-name');
        if (elName) elName.textContent = pName;

        var elKNo = document.getElementById('batch-modal-kanban-no');
        if (elKNo) elKNo.textContent = kNo;

        var elEta = document.getElementById('batch-modal-eta');
        if (elEta) {
            var etaFormatted = '-';
            if (pEta && pEta !== '-' && pEta !== '0000-00-00 00:00:00') {
                var d = new Date(String(pEta).replace(/-/g, '/'));
                if (!isNaN(d.getTime())) {
                    var months = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
                    var day = ('0' + d.getDate()).slice(-2);
                    var month = months[d.getMonth()];
                    var year = d.getFullYear();
                    var hours = ('0' + d.getHours()).slice(-2);
                    var mins = ('0' + d.getMinutes()).slice(-2);
                    etaFormatted = day + ' ' + month + ' ' + year + ', ' + hours + ':' + mins + ' WIB';
                } else {
                    etaFormatted = pEta;
                }
            }
            elEta.innerHTML = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg><span>' + escapeHtml(etaFormatted) + '</span>';
        }

        var elCust = document.getElementById('batch-modal-customer');
        if (elCust) elCust.textContent = pCust;

        var elBadge = document.getElementById('batch-modal-type-badge');
        if (elBadge) {
            if (inspType === 'safety_stock') {
                elBadge.innerHTML = '<span style="display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 6px; font-size: 10px; font-weight: 800; background-color: #f3e8ff; color: #7e22ce; border: 1px solid #d8b4fe;">' +
                    '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>' +
                    'SAFETY STOCK</span>';
            } else {
                elBadge.innerHTML = '<span style="display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 6px; font-size: 10px; font-weight: 800; background-color: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe;">' +
                    '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>' +
                    'KANBAN</span>';
            }
        }
    }

    // Render lot list (default 'ng' view)
    renderBatchLotList('ng');

    // Reset State & Form Fields
    scannedBatchLabels = [];
    renderBatchScannedTable();
    
    // Default: rescan_restart
    selectBatchStrategy('rescan');
    selectSubAction('rescan_restart');

    document.getElementById('batch-reinsp-notes').value = '';
    if (document.getElementById('batch-input-z1')) {
        document.getElementById('batch-input-z1').value = currentSessionPartCode || '<?= $session ? htmlspecialchars($session['part_code']) : "" ?>';
    }

    modal.classList.remove('hidden');
    modal.style.display = 'flex';
}

function closeBatchReinspectionModal() {
    var modal = document.getElementById('batch-reinspection-modal-overlay');
    if (modal) {
        modal.classList.add('hidden');
        modal.style.display = 'none';
    }
}

/**
 * Select Main Strategy (rescan vs replace)
 */
function selectBatchStrategy(strategy) {
    batchSelectedStrategy = strategy;
    var btnRescan = document.getElementById('card-btn-rescan');
    var btnReplace = document.getElementById('card-btn-replace');
    var subRescan = document.getElementById('sub-rescan-buttons');
    var subReplace = document.getElementById('sub-replace-buttons');
    var repForm = document.getElementById('batch-replacement-form-container');
    var btnSubmit = document.getElementById('btn-submit-batch-reinsp');

    if (strategy === 'rescan') {
        if (btnRescan) {
            btnRescan.style.border = '2px solid #f59e0b';
            btnRescan.style.backgroundColor = '#fffbeb';
            btnRescan.style.opacity = '1';
            btnRescan.style.boxShadow = '0 4px 14px rgba(245,158,11,0.15)';
        }
        if (btnReplace) {
            btnReplace.style.border = '2px solid #cbd5e1';
            btnReplace.style.backgroundColor = '#ffffff';
            btnReplace.style.opacity = '0.6';
            btnReplace.style.boxShadow = 'none';
        }
        if (subRescan) {
            subRescan.style.opacity = '1';
            subRescan.style.pointerEvents = 'auto';
        }
        if (subReplace) {
            subReplace.style.opacity = '0.4';
            subReplace.style.pointerEvents = 'none';
        }
        if (repForm) repForm.classList.add('hidden');
        if (btnSubmit) btnSubmit.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polygon points="5 3 19 12 5 21 5 3"/></svg><span>Mulai Sesi Re-Inspeksi Baru</span>';
        
        selectSubAction('rescan_restart');
    } else {
        if (btnReplace) {
            btnReplace.style.border = '2px solid #e11d48';
            btnReplace.style.backgroundColor = '#fff1f2';
            btnReplace.style.opacity = '1';
            btnReplace.style.boxShadow = '0 4px 14px rgba(225,29,72,0.15)';
        }
        if (btnRescan) {
            btnRescan.style.border = '2px solid #cbd5e1';
            btnRescan.style.backgroundColor = '#ffffff';
            btnRescan.style.opacity = '0.6';
            btnRescan.style.boxShadow = 'none';
        }
        if (subReplace) {
            subReplace.style.opacity = '1';
            subReplace.style.pointerEvents = 'auto';
        }
        if (subRescan) {
            subRescan.style.opacity = '0.4';
            subRescan.style.pointerEvents = 'none';
        }
        if (repForm) repForm.classList.remove('hidden');
        if (btnSubmit) btnSubmit.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="20 6 9 17 4 12"></polyline><span>Validasi Matching &amp; Muat Inspeksi</span>';

        selectSubAction('replace_ng_only');
    }
}

/**
 * Select Sub Action Strategy
 */
function selectSubAction(action, evt) {
    if (evt) {
        evt.stopPropagation();
        evt.preventDefault();
    }

    batchSelectedSubAction = action;

    // Reset styles for all sub buttons
    var btnRescanRestart = document.getElementById('btn-sub-rescan-restart');
    var btnRescanContinue = document.getElementById('btn-sub-rescan-continue');
    var btnReplaceNg = document.getElementById('btn-sub-replace-ng');
    var btnReplaceAll = document.getElementById('btn-sub-replace-all');

    if (btnRescanRestart) {
        btnRescanRestart.style.backgroundColor = '#ffffff';
        btnRescanRestart.style.color = '#92400e';
        btnRescanRestart.style.borderColor = '#fcd34d';
        btnRescanRestart.style.fontWeight = '600';
    }
    if (btnRescanContinue) {
        btnRescanContinue.style.backgroundColor = '#ffffff';
        btnRescanContinue.style.color = '#92400e';
        btnRescanContinue.style.borderColor = '#fcd34d';
        btnRescanContinue.style.fontWeight = '600';
    }
    if (btnReplaceNg) {
        btnReplaceNg.style.backgroundColor = '#ffffff';
        btnReplaceNg.style.color = '#475569';
        btnReplaceNg.style.borderColor = '#cbd5e1';
        btnReplaceNg.style.fontWeight = '600';
    }
    if (btnReplaceAll) {
        btnReplaceAll.style.backgroundColor = '#ffffff';
        btnReplaceAll.style.color = '#475569';
        btnReplaceAll.style.borderColor = '#cbd5e1';
        btnReplaceAll.style.fontWeight = '600';
    }

    // Apply active style
    if (action === 'rescan_restart' && btnRescanRestart) {
        btnRescanRestart.style.backgroundColor = '#d97706';
        btnRescanRestart.style.color = '#ffffff';
        btnRescanRestart.style.borderColor = '#d97706';
        btnRescanRestart.style.fontWeight = '700';
    } else if (action === 'rescan_continue' && btnRescanContinue) {
        btnRescanContinue.style.backgroundColor = '#d97706';
        btnRescanContinue.style.color = '#ffffff';
        btnRescanContinue.style.borderColor = '#d97706';
        btnRescanContinue.style.fontWeight = '700';
    } else if (action === 'replace_ng_only' && btnReplaceNg) {
        btnReplaceNg.style.backgroundColor = '#e11d48';
        btnReplaceNg.style.color = '#ffffff';
        btnReplaceNg.style.borderColor = '#e11d48';
        btnReplaceNg.style.fontWeight = '700';
        renderBatchLotList('ng');
    } else if (action === 'replace_all_lots' && btnReplaceAll) {
        btnReplaceAll.style.backgroundColor = '#e11d48';
        btnReplaceAll.style.color = '#ffffff';
        btnReplaceAll.style.borderColor = '#e11d48';
        btnReplaceAll.style.fontWeight = '700';
        renderBatchLotList('all');
    }

    renderBatchScannedTable();

    // Auto focus scan input when entering replace mode
    if (action.indexOf('replace') !== -1) {
        setTimeout(function() {
            var input = document.getElementById('batch-qr-scan-input');
            if (input) input.focus();
        }, 100);
    }
}

/**
 * Main Barcode Scanner Input Handler in Batch Modal
 */
function handleSingleBatchQrInput(inputEl, forceProcess) {
    if (!inputEl) return;
    var raw = inputEl.value.trim();
    if (!raw) return;

    var parsed = {};
    if (typeof parseBarcodeQR === 'function') {
        parsed = parseBarcodeQR(raw);
    }

    // Early Part Code mismatch check:
    var expectedPart = currentSessionPartCode || (document.getElementById('batch-input-z1') ? document.getElementById('batch-input-z1').getAttribute('data-original') : '') || '';
    if (parsed.Z1 && expectedPart && parsed.Z1.toUpperCase() !== expectedPart.toUpperCase()) {
        if (forceProcess || hasNewline) {
            Swal.fire({
                icon: 'error',
                title: '⚠️ PART CODE TIDAK COCOK!',
                html: '<div style="font-size: 12px; text-align: left; line-height: 1.6; color: #334155;">' +
                      'Part Code pada label (<b style="color:#be123c;">"' + escapeHtml(parsed.Z1) + '"</b>) tidak cocok dengan Part Code Sesi Kanban ini (<b style="color:#0284c7;">"' + escapeHtml(expectedPart) + '"</b>)!<br><br>' +
                      '<b style="color:#e11d48;">Pastikan Anda meng-scan label box yang sesuai dengan Kanban ini!</b>' +
                      '</div>'
            });
            // Rollback & bersihkan field
            if (document.getElementById('batch-input-z1')) document.getElementById('batch-input-z1').value = expectedPart;
            if (document.getElementById('batch-input-z2')) document.getElementById('batch-input-z2').value = '';
            if (document.getElementById('batch-input-z3')) document.getElementById('batch-input-z3').value = '';
            if (document.getElementById('batch-input-z5')) document.getElementById('batch-input-z5').value = '';
            inputEl.value = '';
            setTimeout(function() { if (inputEl) inputEl.focus(); }, 100);
        }
        return;
    }

    // Live sync manual input fields for visual feedback
    if (parsed.Z1 && document.getElementById('batch-input-z1')) document.getElementById('batch-input-z1').value = parsed.Z1;
    if (parsed.Z2 && document.getElementById('batch-input-z2')) document.getElementById('batch-input-z2').value = parsed.Z2;
    if (parsed.Z3 && parsed.Z3 > 0 && document.getElementById('batch-input-z3')) document.getElementById('batch-input-z3').value = parsed.Z3;
    if (parsed.Z5 && document.getElementById('batch-input-z5')) document.getElementById('batch-input-z5').value = parsed.Z5;

    var z1 = (parsed && parsed.Z1) ? parsed.Z1 : (currentSessionPartCode || '');
    var z2 = (parsed && parsed.Z2) ? parsed.Z2 : '';
    var z3 = (parsed && parsed.Z3) ? parseInt(parsed.Z3) : (document.getElementById('batch-input-z3') ? parseInt(document.getElementById('batch-input-z3').value) : 0);
    var z5 = (parsed && parsed.Z5) ? parsed.Z5 : '';

    // HANYA SUBMIT jika user/scanner menekan ENTER (forceProcess === true) atau terdapat Newline (\n / \r) dari hardware scanner
    var hasNewline = (raw.includes('\n') || raw.includes('\r'));

    if (forceProcess || hasNewline) {
        if (!z2 || !z3 || z3 <= 0) {
            Swal.fire({ icon: 'warning', title: 'Qty Box Wajib Diisi', text: 'Lot Number & Qty Box (> 0) wajib terisi! Silakan isi field Qty Box secara manual jika tidak ada di barcode.' });
            return;
        }

        if (!z5) {
            Swal.fire({ icon: 'warning', title: 'Ref Number Wajib Diisi', text: 'Ref Number tidak terbaca dari barcode. Silakan isi field Ref Number secara manual sebelum menambah label.' });
            if (inputEl) inputEl.value = '';
            return;
        }

        validateBatchLabelWithDid({
            z1: z1,
            z2: z2,
            z3: z3,
            z5: z5,
            raw: raw,
            inputEl: inputEl
        });
    }
}

/**
 * Add Manual Label via + Tambah Label Manual button
 */
function addManualBatchLabel() {
    var z1 = document.getElementById('batch-input-z1').value.trim() || currentSessionPartCode || '';
    var z2 = document.getElementById('batch-input-z2').value.trim();
    var z3 = parseInt(document.getElementById('batch-input-z3').value) || 0;
    var z5 = document.getElementById('batch-input-z5').value.trim();

    if (!z2) {
        Swal.fire('Perhatian', 'Lot Number wajib diisi!', 'warning');
        return;
    }
    if (z3 <= 0) {
        Swal.fire('Perhatian', 'Qty Box harus lebih dari 0!', 'warning');
        return;
    }

    if (!z5) {
        Swal.fire({ icon: 'warning', title: 'Ref Number Wajib Diisi', text: 'Silakan isi Ref Number terlebih dahulu. Ref Number harus diisi manual oleh user.' });
        return;
    }

    validateBatchLabelWithDid({
        z1: z1,
        z2: z2,
        z3: z3,
        z5: z5,
        raw: ''
    });
}

/**
 * Validasi Real-Time DID (Cek Dimensi) untuk Batch Re-Inspeksi Label
 */
function validateBatchLabelWithDid(item) {
    var pCode = item.z1 || currentSessionPartCode || '';
    var lNum = item.z2 || '';

    if (!pCode || !lNum) {
        Swal.fire('Error', 'Part Code dan Lot Number wajib diisi untuk verifikasi DID!', 'error');
        return;
    }

    // 1. Validasi Part Code Match dengan Sesi Kanban saat ini
    if (currentSessionPartCode && pCode.toUpperCase() !== currentSessionPartCode.toUpperCase()) {
        Swal.fire({
            icon: 'error',
            title: '⚠️ PART CODE TIDAK COCOK!',
            html: '<div style="font-size: 12px; text-align: left; line-height: 1.6; color: #334155;">' +
                  'Part Code pada label (<b style="color:#be123c;">"' + escapeHtml(pCode) + '"</b>) tidak cocok dengan Part Code Sesi Kanban ini (<b style="color:#0284c7;">"' + escapeHtml(currentSessionPartCode) + '"</b>)!<br><br>' +
                  '<b style="color:#e11d48;">Pastikan Anda meng-scan/memasukkan label barang yang sesuai dengan Part Code Kanban ini!</b>' +
                  '</div>'
        });
        // Rollback form fields
        if (document.getElementById('batch-input-z1')) document.getElementById('batch-input-z1').value = currentSessionPartCode;
        if (document.getElementById('batch-input-z2')) document.getElementById('batch-input-z2').value = '';
        if (document.getElementById('batch-input-z3')) document.getElementById('batch-input-z3').value = '';
        if (document.getElementById('batch-input-z5')) document.getElementById('batch-input-z5').value = '';
        if (item.inputEl) {
            item.inputEl.value = '';
            item.inputEl.focus();
        }
        return;
    }

    // Check duplicate ref_number (Z5) in scannedBatchLabels
    var isDuplicateRef = scannedBatchLabels.some(function(sb) {
        return sb.z5 && item.z5 && sb.z5.toUpperCase() === item.z5.toUpperCase();
    });

    if (isDuplicateRef) {
        Swal.fire('Perhatian', 'Label dengan Ref Number (' + item.z5 + ') sudah discan sebelumnya!', 'warning');
        return;
    }

    Swal.fire({
        title: 'Memeriksa DID...',
        text: 'Memverifikasi Cek Dimensi Lot #' + lNum + ' (Part ' + pCode + ')...',
        allowOutsideClick: false,
        didOpen: function() { Swal.showLoading(); }
    });

    var checkUrl = '<?= base_url("modules/inspection/scan_validate.php") ?>?mode=check_did_only&part_code=' + encodeURIComponent(pCode) + '&lot_number=' + encodeURIComponent(lNum);

    fetch(checkUrl)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            closeSwalSafely();
            if (!data.success) {
                Swal.fire({
                    icon: 'error',
                    title: '⚠️ LOT NUMBER BELUM LOLOS CEK DIMENSI (DID)!',
                    html: '<div style="font-size: 12px; text-align: left; line-height: 1.6; color: #334155;">' +
                          '<b style="color: #be123c;">' + escapeHtml(data.message || 'Lot Number belum lolos cek dimensi!') + '</b><br><br>' +
                          'Part Code: <b>' + escapeHtml(pCode) + '</b><br>' +
                          'Lot Number yang Di-scan/Di-input: <b style="font-family: monospace; color: #1d4ed8;">' + escapeHtml(lNum) + '</b>' +
                          '</div>'
                });
                return;
            }

            // DID Passed! Push item to scanned labels
            scannedBatchLabels.push(item);
            renderBatchScannedTable();

            if (item.inputEl) item.inputEl.value = '';

            // Clear manual input fields (reset Part Code to current session Part Code)
            if (document.getElementById('batch-input-z1')) document.getElementById('batch-input-z1').value = currentSessionPartCode || '<?= $session ? htmlspecialchars($session['part_code']) : "" ?>';
            if (document.getElementById('batch-input-z2')) document.getElementById('batch-input-z2').value = '';
            if (document.getElementById('batch-input-z3')) document.getElementById('batch-input-z3').value = '';
            if (document.getElementById('batch-input-z5')) document.getElementById('batch-input-z5').value = '';

            Swal.fire({
                icon: 'success',
                title: '✓ Terverifikasi DID',
                text: 'Label Lot #' + lNum + ' (' + item.z3 + ' pcs) berhasil ditambahkan!',
                timer: 1500,
                showConfirmButton: false
            });
        })
        .catch(function() {
            closeSwalSafely();
            Swal.fire('Koneksi Error', 'Gagal memverifikasi DID ke server', 'error');
        });
}

/**
 * Remove Item from scanned batch labels array
 */
function removeBatchLabel(index) {
    if (index >= 0 && index < scannedBatchLabels.length) {
        scannedBatchLabels.splice(index, 1);
        renderBatchScannedTable();
    }
}

/**
 * Render Scanned Labels Table in Modal
 */
function renderBatchScannedTable() {
    var tbody = document.getElementById('batch-scanned-table-body');
    var countBadge = document.getElementById('batch-scanned-count');

    if (countBadge) countBadge.textContent = scannedBatchLabels.length;
    if (!tbody) return;

    if (scannedBatchLabels.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" style="padding: 16px; text-align: center; color: #94a3b8;">Belum ada label QR yang discan. Silakan scan barcode label di atas.</td></tr>';
        return;
    }

    var targetList = (batchSelectedSubAction === 'replace_ng_only') 
                     ? currentNgLots.filter(function(l) { return l.lot_status === 'ng_found' || l.lot_status === 'ng_quarantine'; })
                     : currentSessionLots;

    var html = '';
    scannedBatchLabels.forEach(function(item, idx) {
        var targetLot = targetList[idx] || null;
        var targetHtml = '';
        if (targetLot) {
            var targetLotNo = targetLot.lot_number || '-';
            var targetRefNo = targetLot.ref_number || '-';
            targetHtml = '<div style="font-size:10px; font-weight:700; color:#9f1239; background:#ffe4e6; border:1px solid #fecdd3; padding:2px 6px; border-radius:6px; display:inline-block; margin-bottom:3px;">' +
                         '🎯 Diganti dari: <b>#' + escapeHtml(targetLotNo) + '</b> <span style="font-family:monospace; color:#be123c;">(Ref: ' + escapeHtml(targetRefNo) + ')</span>' +
                         '</div>';
        } else {
            targetHtml = '<div style="font-size:10px; font-weight:600; color:#64748b; margin-bottom:3px;">(Lot Tambahan)</div>';
        }

        html += '<tr style="border-bottom: 1px solid #e2e8f0; font-family: monospace;">' +
                    '<td style="padding: 8px 10px; font-weight: 700; color: #64748b;">' + (idx + 1) + '</td>' +
                    '<td style="padding: 8px 10px; font-weight: 700; color: #2563eb;">' + escapeHtml(item.z5 || '-') + '</td>' +
                    '<td style="padding: 8px 10px;">' +
                        targetHtml +
                        '<div style="font-weight:800; color:#166534; background:#dcfce7; border:1px solid #bbf7d0; padding:2px 6px; border-radius:6px; display:inline-block;">' +
                            '📦 Lot Pengganti: <b>#' + escapeHtml(item.z2) + '</b>' +
                        '</div>' +
                    '</td>' +
                    '<td style="padding: 8px 10px; text-align: center; font-weight: 800; color: #059669;">' + item.z3 + ' pcs</td>' +
                    '<td style="padding: 8px 10px; text-align: center;">' +
                        '<button type="button" onclick="removeBatchLabel(' + idx + ')" style="padding: 3px 8px; background-color: #ffe4e6; color: #be123c; border: 1px solid #fecdd3; border-radius: 6px; font-size: 10px; font-weight: 700; cursor: pointer;">Hapus</button>' +
                    '</td>' +
                '</tr>';
    });

    tbody.innerHTML = html;
}

/**
 * Submit Form Batch Re-Inspeksi
 */
function submitBatchReinspection() {
    var mode = batchSelectedSubAction;
    var notes = document.getElementById('batch-reinsp-notes').value.trim();
    var replacementLotsPayload = [];

    if (mode === 'replace_ng_only' || mode === 'replace_all_lots') {
        if (scannedBatchLabels.length === 0) {
            Swal.fire('Perhatian', 'Silakan scan/tambah minimal 1 Label QR Code Lot Pengganti!', 'warning');
            return;
        }

        // Map scannedBatchLabels into replacementLotsPayload
        var actionableLots = currentNgLots.filter(function(l) {
            return l.lot_status === 'ng_found' || l.lot_status === 'ng_quarantine';
        });

        scannedBatchLabels.forEach(function(item, idx) {
            var origId = 0;
            if (mode === 'replace_ng_only' && actionableLots[idx]) {
                origId = actionableLots[idx].id;
            }

            replacementLotsPayload.push({
                ng_lot_id: origId,
                new_z1: item.z1 || currentSessionPartCode || '',
                new_z2: item.z2,
                new_z3: item.z3,
                new_z5: item.z5
            });
        });
    }

    var btnSubmit = document.getElementById('btn-submit-batch-reinsp');
    if (btnSubmit) {
        btnSubmit.disabled = true;
        btnSubmit.innerHTML = '<span>⏳ Memproses...</span>';
    }

    var payload = {
        action: mode,
        original_session_id: currentSessionId,
        reinspection_notes: notes,
        replacement_lots: replacementLotsPayload
    };

    fetch(reinspectApiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (btnSubmit) {
            btnSubmit.disabled = false;
            btnSubmit.innerHTML = '<span>Validasi Matching &amp; Muat Inspeksi</span>';
        }

        closeBatchReinspectionModal();

        if (!data.success) {
            Swal.fire({ icon: 'error', title: 'Gagal', text: data.message || 'Terjadi kesalahan' });
            return;
        }

        if (data.reinspection_session_id) {
            loadWorkbenchSessionData(data.reinspection_session_id);
        }

        Swal.fire({
            icon: 'success',
            title: '✅ Re-Inspeksi Dibuat!',
            html: '<div style="font-size:12px;color:#334155;line-height:1.7;">' +
                  (data.message || 'Sesi Re-Inspeksi baru berhasil dibuat.') + '<br>' +
                  '<b>Sample:</b> ' + data.sample_size + ' pcs &nbsp;|&nbsp; <b>Batas NG:</b> ' + data.reject_number +
                  '</div>',
            confirmButtonColor: '#2563eb',
            confirmButtonText: 'Lanjut Inspeksi 🔄',
            timer: 3000,
            timerProgressBar: true
        });
    })
    .catch(function() {
        if (btnSubmit) {
            btnSubmit.disabled = false;
            btnSubmit.innerHTML = '<span>Validasi Matching &amp; Muat Inspeksi</span>';
        }
        closeBatchReinspectionModal();
        Swal.fire({ icon: 'error', title: 'Koneksi Error', text: 'Gagal terhubung ke server!' });
    });
}

var CHIEF_USERS_LIST = <?= json_encode($chiefUsersList ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

/**
 * Chief QC Approval from Workbench (Opsi 2)
 */
function workbenchChiefApprove() {
    if (!currentSessionId) return;

    var userOptionsHtml = '<option value="">-- Pilih Akun Chief QC --</option>';
    if (CHIEF_USERS_LIST && CHIEF_USERS_LIST.length > 0) {
        CHIEF_USERS_LIST.forEach(function(u) {
            var roleLabel = u.role ? (' (' + u.role.toUpperCase() + ')') : '';
            userOptionsHtml += '<option value="' + u.id + '">' + escapeHtml(u.name) + roleLabel + '</option>';
        });
    }

    var capturedUserId = '';
    var capturedPassword = '';

    Swal.fire({
        title: 'Persetujuan (ACC) Chief QC',
        html: '<div style="font-size: 13px; text-align: left; line-height: 1.5; color: #334155;">' +
              '<p style="margin-bottom: 12px;">Konfirmasi persetujuan lembar penolakan (Rejection Sheet) untuk sesi inspeksi ini:</p>' +
              '<div style="margin-bottom: 10px;">' +
              '  <label style="font-size: 11px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">Pilih Akun Chief QC:</label>' +
              '  <select id="wb-chief-user-id" style="width: 100%; box-sizing: border-box; font-size: 13px; font-weight: 600; padding: 8px 10px; border: 1.5px solid #cbd5e1; border-radius: 6px; background-color: #fff; outline: none;">' +
                 userOptionsHtml +
              '  </select>' +
              '</div>' +
              '<div style="margin-bottom: 8px;">' +
              '  <label style="font-size: 11px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">Password Akun Chief QC:</label>' +
              '  <input id="wb-chief-password" type="password" placeholder="Masukkan password akun..." style="margin: 0; width: 100%; box-sizing: border-box; font-size: 13px; font-weight: 600; padding: 8px 10px; border: 1.5px solid #94a3b8; border-radius: 6px; background-color: #fff; color: #0f172a; outline: none;">' +
              '</div>' +
              '<p style="font-size: 11px; color: #64748b; margin-top: 8px; line-height: 1.4;">💡 Masukkan password dari akun Chief QC yang Anda pilih di atas untuk validasi stempel resmi digital.</p>' +
              '</div>',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#16a34a',
        cancelButtonColor: '#64748b',
        confirmButtonText: '✅ Verifikasi &amp; ACC',
        cancelButtonText: 'Batal',
        focusConfirm: false,
        preConfirm: function() {
            var selectEl = document.getElementById('wb-chief-user-id');
            var passEl = document.getElementById('wb-chief-password');
            var userId = selectEl ? selectEl.value : '';
            var pass = passEl ? passEl.value : '';

            if (!userId) {
                Swal.showValidationMessage('Silakan pilih akun Chief QC terlebih dahulu!');
                return false;
            }
            if (!pass) {
                Swal.showValidationMessage('Password akun Chief QC wajib diisi!');
                return false;
            }
            capturedUserId = userId;
            capturedPassword = pass;
            return { chief_user_id: userId, password: pass };
        }
    }).then(function(result) {
        if (!result || !result.isConfirmed) return;

        var finalUserId = (result.value && result.value.chief_user_id) ? result.value.chief_user_id : capturedUserId;
        var finalPassword = (result.value && result.value.password) ? result.value.password : capturedPassword;

        if (!finalUserId || !finalPassword) {
            Swal.fire({ icon: 'error', title: 'Data Kurang', text: 'Akun atau password Chief QC tidak boleh kosong!' });
            return;
        }

        Swal.fire({
            title: 'Memverifikasi &amp; Menyimpan ACC...',
            allowOutsideClick: false,
            didOpen: function() { Swal.showLoading(); }
        });

        var payload = new FormData();
        payload.append('session_id', currentSessionId);
        payload.append('action', 'approve');
        payload.append('chief_user_id', finalUserId);
        payload.append('password', finalPassword);

        fetch('<?= base_url("modules/inspection/api/chief_approve.php") ?>', {
            method: 'POST',
            body: payload
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) {
                Swal.fire({ icon: 'error', title: 'Verifikasi Gagal', text: data.message || 'Terjadi kesalahan' });
                return;
            }
            Swal.fire({
                icon: 'success',
                title: '✅ Disetujui Chief QC',
                text: 'Sesi berhasil di-ACC! Lembar Rejection Sheet kini siap dicetak dengan stempel digital.',
                timer: 1500,
                showConfirmButton: false
            });
            loadWorkbenchSessionData(currentSessionId);
        })
        .catch(function() {
            Swal.fire({ icon: 'error', title: 'Koneksi Error', text: 'Gagal menghubungi server' });
        });
    });
}

function workbenchChiefUnapprove() {
    if (!currentSessionId) return;
    Swal.fire({
        title: 'Batalkan ACC Chief QC?',
        text: 'Format cetak lembar penolakan akan dikembalikan ke tanda tangan basah manual.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Ya, Batalkan ACC',
        cancelButtonText: 'Batal'
    }).then(function(res) {
        if (!res.isConfirmed) return;

        Swal.fire({
            title: 'Membatalkan...',
            allowOutsideClick: false,
            didOpen: function() { Swal.showLoading(); }
        });

        var payload = new FormData();
        payload.append('session_id', currentSessionId);
        payload.append('action', 'unapprove');

        fetch('<?= base_url("modules/inspection/api/chief_approve.php") ?>', {
            method: 'POST',
            body: payload
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) {
                Swal.fire({ icon: 'error', title: 'Gagal', text: data.message });
                return;
            }
            Swal.fire({
                icon: 'info',
                title: 'ACC Dibatalkan',
                text: 'Format cetak kembali ke tanda tangan basah manual.',
                timer: 1400,
                showConfirmButton: false
            });
            loadWorkbenchSessionData(currentSessionId);
        })
        .catch(function() {
            Swal.fire({ icon: 'error', title: 'Koneksi Error', text: 'Gagal menghubungi server' });
        });
    });
}

// High-Scale Debounced Master Parts Autocomplete
(function() {
    var partInput = document.getElementById('scan-part-code');
    var dataList = document.getElementById('modal-parts-list');
    if (!partInput || !dataList) return;

    var timer = null;
    partInput.addEventListener('input', function() {
        var q = (this.value || '').trim();
        if (q.length < 1) return;
        clearTimeout(timer);
        timer = setTimeout(function() {
            fetch('<?= base_url("modules/inspection/session.php?action=search_parts&q=") ?>' + encodeURIComponent(q))
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (data && data.success && Array.isArray(data.parts)) {
                        dataList.innerHTML = '';
                        data.parts.forEach(function(p) {
                            var opt = document.createElement('option');
                            opt.value = p.part_code;
                            opt.textContent = p.part_code + (p.part_name ? (' - ' + p.part_name) : '');
                            dataList.appendChild(opt);
                        });
                    }
                })
                .catch(function(e) {});
        }, 200);
    });
})();

/**
 * ── SMART SCANNER FOCUS & ROUTING MANAGER ─────────────────────────
 * 1. Selalu memprioritaskan fokus ke kotak scan barcode aktif (Step 2, Re-inspeksi, atau Batch Modal).
 * 2. User tetap bebas mengetik manual di field-field lain (Part, Lot, Qty, Ref, dll) tanpa terganggu.
 * 3. Jika scanner ditembak (terdeteksi format barcode Z1/pipe atau saat kursor di luar field manual),
 *    data scan otomatis dialihkan ke kotak scan aktif dan diproses.
 * 4. Saat alert Swal selesai/ditutup atau background modal diklik, fokus otomatis kembali ke kotak scan.
 */
(function initSessionScanFocusManager() {
    // Daftar ID field manual yang TIDAK BOLEH diganggu saat user sedang mengetik manual
    var manualFieldIds = [
        'scan-part-code', 'scan-lot-number', 'scan-label-qty', 'scan-ref-number', 'scan-remarks',
        'reinsp-lot-number', 'reinsp-qty', 'reinsp-ref-number', 'reinsp-replace-remarks', 'reinsp-sort-notes',
        'batch-input-z1', 'batch-input-z2', 'batch-input-z3', 'batch-input-z4', 'batch-input-z5',
        'ss-lot-search-input'
    ];

    // Mendapatkan input scan yang saat ini sedang aktif & terlihat di layar
    function getActiveScanInput() {
        // 1. Re-inspeksi / Ganti Box Modal
        var reinspModal = document.getElementById('modal-lot-reinspection');
        if (reinspModal && reinspModal.style.display !== 'none' && !reinspModal.classList.contains('hidden')) {
            var reinspInput = document.getElementById('reinsp-scan-qr-raw');
            if (reinspInput && reinspInput.offsetParent !== null) return reinspInput;
        }

        // 2. Batch Replacement / Ganti Lot Container
        var batchContainer = document.getElementById('batch-replacement-form-container');
        if (batchContainer && !batchContainer.classList.contains('hidden') && batchContainer.style.display !== 'none') {
            var batchInput = document.getElementById('batch-qr-scan-input');
            if (batchInput && batchInput.offsetParent !== null) return batchInput;
        }

        // 3. Modal Utama Wizard Step 2 (Scan QR Code Label)
        var scanModal = document.getElementById('scan-modal-overlay');
        var step2 = document.getElementById('wizard-step-2-content');
        if (scanModal && !scanModal.classList.contains('hidden') && scanModal.style.display !== 'none') {
            if (step2 && !step2.classList.contains('hidden') && step2.style.display !== 'none') {
                var rawInput = document.getElementById('scan-qr-raw');
                if (rawInput && rawInput.offsetParent !== null) return rawInput;
            }
            // 4. Modal Utama Wizard Step 1 (Pilih Item Planning Aktif -> Cari Part Code)
            var step1 = document.getElementById('wizard-step-1-content');
            if (step1 && !step1.classList.contains('hidden') && step1.style.display !== 'none') {
                var planInput = document.getElementById('planning-search-input');
                if (planInput && planInput.offsetParent !== null) return planInput;
            }
        }

        return null;
    }

    // Cek apakah user sedang aktif mengetik di salah satu field manual
    function isUserEditingManualField() {
        var activeEl = document.activeElement;
        if (!activeEl) return false;
        if (manualFieldIds.indexOf(activeEl.id) !== -1) return true;
        // Juga jangan ganggu jika user sedang di input/textarea/select lain di luar scanner
        var scanInput = getActiveScanInput();
        if (scanInput && activeEl !== scanInput && (activeEl.tagName === 'INPUT' || activeEl.tagName === 'TEXTAREA' || activeEl.tagName === 'SELECT')) {
            return true;
        }
        return false;
    }

    // Mengembalikan fokus ke scan input yang aktif
    function refocusActiveScanner(force) {
        if (window.isSpectatorMode) return;
        var scanInput = getActiveScanInput();
        if (!scanInput) return;
        if (force || !isUserEditingManualField()) {
            try {
                scanInput.focus();
                if (scanInput.value && scanInput.value.length > 0) {
                    scanInput.select();
                }
            } catch (e) {}
        }
    }

    // Expose global helper agar fungsi lain bisa panggil jika perlu
    window.refocusActiveScanner = refocusActiveScanner;
    window.getActiveScanInput = getActiveScanInput;

    // 1. Hook SweetAlert2: Setiap kali Swal selesai (ditutup / timer habis), kembalikan fokus ke scan input
    if (typeof Swal !== 'undefined' && Swal.fire) {
        var origSwalFire = Swal.fire;
        Swal.fire = function() {
            var promise = origSwalFire.apply(this, arguments);
            if (promise && typeof promise.then === 'function') {
                promise.then(function() {
                    setTimeout(function() { refocusActiveScanner(true); }, 80);
                });
            }
            return promise;
        };
    }

    // 2. Klik pada area kosong modal / tabel -> otomatis kembalikan fokus ke scan input
    document.addEventListener('click', function(e) {
        if (window.isSpectatorMode) return;
        var scanInput = getActiveScanInput();
        if (!scanInput) return;
        var target = e.target;
        // Jika user klik elemen interaktif (input, tombol, link, select, checkbox, dsb), JANGAN rebut fokus
        if (target && target.closest('a, button, input, select, textarea, label, [role="button"], tr[onclick], details, summary')) {
            return;
        }
        // User klik background / area kosong modal -> fokuskan kembali ke input scan
        refocusActiveScanner(false);
    });

    // 3. Global keydown: jika user/scanner menekan tombol saat kursor tidak di elemen input apapun
    document.addEventListener('keydown', function(e) {
        if (window.isSpectatorMode) return;
        var scanInput = getActiveScanInput();
        if (!scanInput) return;

        var activeEl = document.activeElement;
        var isAnyInputActive = activeEl && (
            activeEl.tagName === 'INPUT' ||
            activeEl.tagName === 'TEXTAREA' ||
            activeEl.tagName === 'SELECT' ||
            activeEl.isContentEditable
        );

        // Escape: refocus scan input
        if (e.key === 'Escape') {
            refocusActiveScanner(true);
            return;
        }

        // Jika tidak ada input yang aktif dan tombol karakter ditekan (scanner mulai menembak)
        if (!isAnyInputActive && !e.ctrlKey && !e.altKey && !e.metaKey && e.key.length === 1) {
            scanInput.focus();
        }
    });

    // 4. Scanner Guard pada Manual Fields:
    // Jika kursor user sedang berada di field manual (misal Lot No atau Qty), lalu scanner ditembakkan
    // (mengandung pipe '|' atau diawali format 'Z1..'), jangan biarkan field manual rusak.
    // Segera alihkan teks scan ke scan input aktif dan proses!
    function setupManualInputScannerGuards() {
        var groupMappings = [
            {
                inputs: ['scan-part-code', 'scan-lot-number', 'scan-label-qty', 'scan-ref-number', 'scan-remarks'],
                targetId: 'scan-qr-raw',
                processor: function(val) { if (typeof parseBarcodeQRInput === 'function') parseBarcodeQRInput(val, true); }
            },
            {
                inputs: ['reinsp-lot-number', 'reinsp-qty', 'reinsp-ref-number', 'reinsp-replace-remarks'],
                targetId: 'reinsp-scan-qr-raw',
                processor: function(val, el) { if (typeof handleLotReinspQrInput === 'function') handleLotReinspQrInput(el, true); }
            },
            {
                inputs: ['batch-input-z1', 'batch-input-z2', 'batch-input-z3', 'batch-input-z4', 'batch-input-z5'],
                targetId: 'batch-qr-scan-input',
                processor: function(val, el) { if (typeof handleSingleBatchQrInput === 'function') handleSingleBatchQrInput(el, true); }
            }
        ];

        groupMappings.forEach(function(grp) {
            grp.inputs.forEach(function(inpId) {
                var manualEl = document.getElementById(inpId);
                if (!manualEl) return;

                manualEl.addEventListener('input', function() {
                    var val = manualEl.value;
                    // Deteksi ciri khas scan barcode QR (ada pemisah pipe '|' atau awalan 'Z1' panjang)
                    if (val && (val.indexOf('|') !== -1 || (val.indexOf('Z1') === 0 && val.length > 5))) {
                        // Bersihkan input manual ini
                        manualEl.value = '';
                        var targetEl = document.getElementById(grp.targetId);
                        // Pindahkan ke target scan input
                        if (targetEl) {
                            targetEl.value = val;
                            targetEl.focus();
                            grp.processor(val, targetEl);
                        }
                    }
                });
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupManualInputScannerGuards);
    } else {
        setupManualInputScannerGuards();
    }

    // Enter key in planning-search-input: selects the top matching planning card automatically
    var planSearchEl = document.getElementById('planning-search-input');
    if (planSearchEl) {
        planSearchEl.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                filterPlanningItems();
                var container = document.getElementById('planning-cards-container');
                if (container) {
                    var cards = container.querySelectorAll('.planning-card');
                    for (var i = 0; i < cards.length; i++) {
                        if (cards[i].style.display !== 'none') {
                            cards[i].click();
                            break;
                        }
                    }
                }
            }
        });
    }
})();

<?php if ($isReadOnlyView): ?>
/**
 * Non-Disruptive Spectator Mode Client Locking
 * Disables all action triggers, sample buttons, lot scanners, and form inputs
 * while preserving drawing views and progress monitoring.
 */
window.isSpectatorMode = true;
document.addEventListener('DOMContentLoaded', function() {
    var actionableSelectors = [
        '#btn-pass-sample', '#btn-ng-sample', '#btn-finish-inspection',
        '#btn-scan-lot', '#btn-add-lot', '#btn-manual-lot', '#btn-confirm-finish',
        'button[data-action="pass"]', 'button[data-action="fail"]',
        '.btn-lot-action', '.btn-defect-action'
    ];
    actionableSelectors.forEach(function(sel) {
        document.querySelectorAll(sel).forEach(function(el) {
            el.classList.add('spectator-locked');
            el.setAttribute('disabled', 'disabled');
            el.setAttribute('title', 'Terkunci dalam Mode Pantau (Read-Only)');
        });
    });

    // Disable scan input field
    var scanInput = document.getElementById('barcode-input') || document.querySelector('input[name="barcode"]');
    if (scanInput) {
        scanInput.classList.add('spectator-locked');
        scanInput.setAttribute('disabled', 'disabled');
        scanInput.setAttribute('placeholder', 'Mode Pantau Aktif (Input Dikunci)');
    }
});
<?php endif; ?>

</script>

<!-- Real 3D CAD WebGL Engine Scripts (WebAssembly & Three.js) -->
<script>window.APP_BASE_URL = "<?= base_url() ?>";</script>
<script src="<?= base_url('assets/js/vendor/three.r128.min.js') ?>"></script>
<script src="<?= base_url('assets/js/vendor/OrbitControls.js') ?>"></script>
<script src="<?= base_url('assets/js/vendor/occt-import-js.js') ?>"></script>
<script src="<?= base_url('assets/js/cad_viewer.js') ?>"></script>
</body>
</html>
