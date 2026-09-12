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
    try {
        $stmtPlan = $pdo->query("
            SELECT k.*, b.document_number, b.vendor, b.plan_type as batch_plan_type,
                   (SELECT COALESCE(SUM(s.total_scanned_qty), 0)
                    FROM inspection_sessions s
                    LEFT JOIN daily_inspection_data d ON d.id = s.did_id
                    LEFT JOIN kanban_items ki ON ki.id = s.kanban_item_id
                    WHERE s.inspection_type = 'safety_stock'
                      AND s.status = 'passed'
                      AND (s.auto_fulfilled_by_session_id IS NULL OR s.auto_fulfilled_by_session_id = 0)
                      AND (UPPER(d.part_code) = UPPER(k.item_code) OR UPPER(ki.item_code) = UPPER(k.item_code))) AS avail_ss_qty
            FROM kanban_items k
            JOIN kanban_batches b ON b.id = k.batch_id
            LEFT JOIN inspection_sessions ss ON ss.kanban_item_id = k.id
            WHERE ss.id IS NULL
            ORDER BY COALESCE(k.eta, k.req_date, '9999-12-31') ASC, k.id DESC
            LIMIT 100
        ");
        $items = $stmtPlan->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $ePlan) {}
    echo json_encode(['success' => true, 'items' => $items]);
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

    // Fetch Master Parts for scan datalist
    $stmtP = $pdo->query("SELECT part_code, part_name FROM master_parts ORDER BY part_code ASC");
    $master_parts = $stmtP->fetchAll(PDO::FETCH_ASSOC);

    // Fetch Active Planning Items for 2-Step Inspection Modal (STRICTLY UNINSPECTED ITEMS ONLY)
    $activePlanningItems = [];
    try {
        $stmtPlan = $pdo->query("
            SELECT k.*, b.document_number, b.vendor, b.plan_type as batch_plan_type,
                   (SELECT COALESCE(SUM(s.total_scanned_qty), 0)
                    FROM inspection_sessions s
                    LEFT JOIN daily_inspection_data d ON d.id = s.did_id
                    LEFT JOIN kanban_items ki ON ki.id = s.kanban_item_id
                    WHERE s.inspection_type = 'safety_stock'
                      AND s.status = 'passed'
                      AND (s.auto_fulfilled_by_session_id IS NULL OR s.auto_fulfilled_by_session_id = 0)
                      AND (UPPER(d.part_code) = UPPER(k.item_code) OR UPPER(ki.item_code) = UPPER(k.item_code))) AS avail_ss_qty
            FROM kanban_items k
            JOIN kanban_batches b ON b.id = k.batch_id
            LEFT JOIN inspection_sessions ss ON ss.kanban_item_id = k.id
            WHERE ss.id IS NULL
            ORDER BY COALESCE(k.eta, k.req_date, '9999-12-31') ASC, k.id DESC
            LIMIT 100
        ");
        $activePlanningItems = $stmtPlan->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $ePlan) {}

    if ($sessionId > 0) {
        try {
            $stmt = $pdo->prepare("
                SELECT s.*, 
                       did.part_code, did.part_name, did.lot_number, did.cavity, did.pic as did_pic,
                       k.kanban_no, k.item_code as kanban_item_code, k.item_description as kanban_item_desc, k.customer, k.req_date as kanban_req_date, k.eta as kanban_eta, k.str_loc as kanban_str_loc, k.supply_area as kanban_supply_area, k.check_type as kanban_check_type, COALESCE(NULLIF(k.remark, ''), did.remark) as kanban_remark, k.qty as kanban_qty,
                       b.document_number as doc_no, b.vendor as kanban_vendor, b.plan_type as batch_plan_type,
                       p.id as part_id, p.aql_level as part_aql_level, COALESCE(m.name, p.model) as part_model, d.drawing_2d_path, d.drawing_3d_path
                FROM inspection_sessions s
                JOIN daily_inspection_data did ON did.id = s.did_id
                LEFT JOIN kanban_items k ON k.id = s.kanban_item_id
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

            if ($session) {
                $ssQty = (int)($session['use_safety_stock_qty'] ?? 0);
                $physQty = max(0, (int)$session['total_scanned_qty'] - $ssQty);
                
                // AQL sample size is calculated ONLY from new physical scanned Qty when Safety Stock is used
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
                            LEFT JOIN master_parts mp ON (mp.id = s.part_id OR UPPER(mp.part_code) = UPPER(did.part_code))
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
                            LEFT JOIN master_parts mp ON (mp.id = s.part_id OR UPPER(mp.part_code) = UPPER(did.part_code))
                            LEFT JOIN users u ON u.id = s.inspector_id
                            WHERE s.inspection_type = 'safety_stock'
                              AND UPPER(did.part_code) = UPPER(:pcode)
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

                    // Fetch defect breakdown for each previous session
                    foreach ($previousSessions as &$ps) {
                        $stmtDefects = $pdo->prepare("
                            SELECT dt.name as defect_name, SUM(n.qty_ng) as total_qty_ng
                            FROM inspection_ng_records n
                            JOIN inspection_samples sp ON sp.id = n.inspection_sample_id
                            JOIN defect_types dt ON dt.id = n.defect_type_id
                            WHERE sp.inspection_session_id = :sid AND (n.is_cancelled IS NULL OR n.is_cancelled = 0)
                            GROUP BY n.defect_type_id, dt.name
                            ORDER BY total_qty_ng DESC
                        ");
                        $stmtDefects->execute([':sid' => $ps['id']]);
                        $ps['defects'] = $stmtDefects->fetchAll(PDO::FETCH_ASSOC);
                    }
                    unset($ps);
                }
            }
        } catch (PDOException $e) {
            $session = null;
        }
    }
}
$previousSessions = $previousSessions ?? [];

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
</style>

<!-- 100% Full-Screen Outer Container (Strict 100vh, Zero Window Scrollbar) -->
<div style="width: 100vw; height: 100vh; overflow: hidden; display: flex; flex-direction: column; background-color: #f1f5f9;" class="text-slate-800 antialiased font-sans">
    
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
        <div style="flex: 1; min-width: 0; height: 100%; display: flex; flex-direction: column; overflow: hidden;" class="bg-white rounded-2xl border border-slate-200/80 shadow-xs">
            
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

                <!-- Zoom Controls Toolbar -->
                <div class="flex items-center space-x-1 text-slate-600">
                    <button type="button" onclick="adjustZoom(0.1)" class="p-1 hover:bg-slate-200 rounded-lg" title="Zoom In">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v6m3-3H7"></path></svg>
                    </button>
                    <button type="button" onclick="adjustZoom(-0.1)" class="p-1 hover:bg-slate-200 rounded-lg" title="Zoom Out">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM13 10H7"></path></svg>
                    </button>
                    <button type="button" onclick="resetZoom()" class="p-1 hover:bg-slate-200 rounded-lg" title="Fit Screen">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 4l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"></path></svg>
                    </button>
                    <button type="button" onclick="resetZoom()" class="p-1 hover:bg-slate-200 rounded-lg" title="Reset Rotate">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                    </button>
                </div>

            </div>

            <!-- Viewport Container for 2D & 3D Drawings -->
            <div style="flex: 1; min-height: 0; position: relative; overflow: hidden; background-color: #020617;">
                
                <!-- 2D PDF Viewport -->
                <div id="viewport-2d" class="w-full h-full flex items-center justify-center overflow-auto p-2">
                    <?php if ($session && !empty($session['drawing_2d_path'])): ?>
                        <iframe id="pdf-frame" src="<?= base_url($session['drawing_2d_path']) ?>#toolbar=0" class="w-full h-full border-0 rounded-lg transition-transform duration-200"></iframe>
                    <?php else: ?>
                        <!-- Technical Drawing Placeholder -->
                        <div id="drawing-placeholder-box" class="w-full h-full bg-[#f8fafc] border border-dashed border-slate-300 rounded-xl p-4 flex flex-col items-center justify-center text-center relative">
                            <svg class="w-full h-full max-h-[360px] text-slate-300" viewBox="0 0 600 350" fill="none" stroke="currentColor">
                                <rect x="50" y="40" width="500" height="270" rx="4" stroke="#cbd5e1" stroke-width="2" stroke-dasharray="4 4" />
                                <rect x="150" y="80" width="300" height="180" fill="#f1f5f9" stroke="#94a3b8" stroke-width="2" />
                                <circle cx="200" cy="130" r="20" stroke="#64748b" stroke-width="2" />
                                <circle cx="200" cy="210" r="20" stroke="#64748b" stroke-width="2" />
                                <circle cx="400" cy="130" r="15" stroke="#64748b" stroke-width="2" />
                                <line x1="150" y1="80" x2="450" y2="260" stroke="#cbd5e1" stroke-width="1" />
                                <text x="300" y="170" font-family="monospace" font-size="14" font-weight="bold" fill="#334155" text-anchor="middle"><?= $session ? htmlspecialchars($session['part_code']) : 'TECHNICAL DRAWING' ?></text>
                            </svg>
                            <p class="text-xs font-bold text-slate-500 mt-2">Gambar Teknis PDF belum diunggah untuk Part Code ini.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- 3D STP Model Viewport -->
                <div id="viewport-3d" class="hidden w-full h-full bg-slate-900 relative">
                    <?php if ($session && !empty($session['drawing_3d_path'])): ?>
                        <div id="cad-3d-viewport" class="w-full h-full"></div>
                    <?php else: ?>
                        <div class="w-full h-full flex flex-col items-center justify-center text-white p-6 text-center">
                            <svg class="w-12 h-12 text-slate-600 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                            </svg>
                            <p class="text-xs font-bold text-slate-400">File 3D (.STP) tidak tersedia untuk Part ini.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Footer Info Bar inside Viewport -->
                <div id="viewport-footer-bar" style="position: absolute; bottom: 12px; left: 12px; right: 12px; display: flex; align-items: center; justify-content: space-between; font-size: 11px; color: #94a3b8; background-color: rgba(15, 23, 42, 0.85); padding: 6px 12px; border-radius: 8px; z-index: 20; pointer-events: none; border: 1px solid rgba(51, 65, 85, 0.5); transition: background-color 0.3s, color 0.3s;">
                    <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 600; color: inherit;"><?= $session ? htmlspecialchars($session['part_code'] . ' — ' . $session['part_name']) : 'No Part Active' ?></span>
                    <span id="zoom-level-label" style="font-family: monospace; color: inherit; margin-left: 12px; flex-shrink: 0;">Zoom 100%</span>
                </div>

            </div>

        </div>

        <!-- Right Control Panel: Compact Zero-Scroll Inspection Panel (~420px Fixed) -->
        <div style="width: 420px; flex-shrink: 0; height: 100%; display: flex; flex-direction: column; overflow-y: auto; box-sizing: border-box;" class="pr-0.5">
            
            <!-- Card 1: Top Part & Lot Metadata Summary Card -->
            <div style="background-color: #ffffff; border: 1px solid #cbd5e1; border-radius: 16px; padding: 12px; margin-bottom: 10px; box-shadow: 0 1px 2px 0 rgba(0,0,0,0.05);" class="space-y-2 flex-shrink-0 text-xs">
                <div class="flex items-center justify-between border-b border-slate-100 pb-1.5">
                    <div class="flex items-center space-x-1.5 min-w-0">
                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider flex-shrink-0">CUST:</span>
                        <span class="font-extrabold text-blue-900 text-[10px] flex items-center truncate max-w-[200px]" id="card-customer-name">
                            🏢 <?= $session ? htmlspecialchars($session['customer'] ?? 'PT. Indonesia Epson Industry') : '-' ?>
                        </span>
                    </div>
                    <div class="flex items-center space-x-1">
                        <button type="button" onclick="openKanbanDetailModal()" class="px-2 py-0.5 bg-blue-50 hover:bg-blue-100 text-blue-700 font-bold text-[10px] rounded-md border border-blue-200 shadow-2xs inline-flex items-center transition-all flex-shrink-0 cursor-pointer" title="Lihat Rincian Detail Planning Kanban">
                            📋 Detail 
                        </button>
                        <button type="button" id="btn-card-substitution-log" onclick="openSubstitutionLogModal()" class="hidden px-2 py-0.5 bg-emerald-50 hover:bg-emerald-100 text-emerald-800 font-bold text-[10px] rounded-md border border-emerald-300 shadow-2xs inline-flex items-center transition-all flex-shrink-0 cursor-pointer" title="Lihat Rincian Lineage Penggantian Lot & Ref No">
                            🔄 Substitusi (<span id="card-subst-log-count">0</span>)
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
                        $kanbanTarget = $session ? (int)($session['kanban_qty'] ?? 0) : 0;
                        $usedSs = $session ? (int)($session['use_safety_stock_qty'] ?? 0) : 0;
                        $excessQty = $session ? (int)($session['excess_qty'] ?? 0) : 0;
                        $isKanbanMode = !$session || ($session['inspection_type'] ?? 'kanban') === 'kanban';

                        if ($isKanbanMode) {
                            $targetDisplay = ($kanbanTarget > 0) ? $kanbanTarget : max(500, $totalScanned);
                            $mainQtyText = number_format($totalScanned) . ' / ' . number_format($targetDisplay) . ' pcs';
                        } else {
                            $mainQtyText = number_format($totalScanned) . ' pcs';
                        }
                        ?>
                        <span class="font-extrabold text-slate-900 text-xs block" id="card-total-qty"><?= $mainQtyText ?></span>

                        <div id="card-qty-subbadge">
                        <?php if ($isKanbanMode): ?>
                            <?php if ($usedSs > 0 && $totalScanned >= $targetDisplay && ($totalScanned - $usedSs) <= 0): ?>
                                <span class="text-[8.5px] font-bold text-emerald-800 bg-emerald-50 border border-emerald-200 px-1 py-0.2 rounded block mt-0.5" title="Seluruh Qty target Kanban dipenuhi dari Safety Stock">
                                    ✨ 100% Safety Stock (<?= number_format($usedSs) ?> pcs)
                                </span>
                            <?php elseif ($usedSs > 0): ?>
                                <span class="text-[8.5px] font-bold text-blue-800 bg-blue-50 border border-blue-200 px-1 py-0.2 rounded block mt-0.5" title="Kombinasi Scan Fisik & Alokasi Safety Stock">
                                    📦 <?= number_format(max(0, $totalScanned - $usedSs)) ?> Fisik + <?= number_format($usedSs) ?> Safety Stock
                                </span>
                            <?php elseif ($excessQty > 0): ?>
                                <span class="text-[8.5px] font-bold text-purple-700 bg-purple-50 border border-purple-200 px-1 py-0.2 rounded block mt-0.5" title="Sisa kelebihan Qty akan otomatis disimpan ke Safety Stock saat PASSED">
                                    📦 +<?= number_format($excessQty) ?> pcs Kelebihan → Safety Stock
                                </span>
                            <?php endif; ?>
                        <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div style="background-color: #1e40af; color: #ffffff; border-radius: 16px; padding: 14px; margin-bottom: 10px; box-shadow: 0 4px 6px -1px rgba(30,64,175,0.2); position: relative; overflow: hidden;" class="flex-shrink-0">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
                    <span style="color: #93c5fd; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em;">ACUAN SAMPLING WAJIB</span>
                    <span id="card-aql-level-badge" style="background-color: #2563eb; color: #ffffff; border: 1px solid #60a5fa; padding: 2px 8px; border-radius: 9999px; font-size: 10px; font-weight: 800; font-family: monospace;">
                        LEVEL <?= htmlspecialchars($session['aql_level'] ?? $session['part_aql_level'] ?? 'G-II') ?>
                    </span>
                </div>
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <div>
                        <span style="color: #bfdbfe; font-size: 10px; font-weight: 600; display: block;">SPL CODE</span>
                        <span style="color: #ffffff; font-size: 16px; font-weight: 900; letter-spacing: -0.025em; display: block;" id="card-spl-code"><?= $session ? htmlspecialchars($session['sample_code'] ?? 'H') : 'H' ?></span>
                    </div>
                    <div style="text-align: right;">
                        <span style="color: #bfdbfe; font-size: 10px; font-weight: 600; display: block;">SAMPLE SIZE</span>
                        <span style="color: #ffffff; font-size: 20px; font-weight: 900; display: block;" id="card-sample-size"><?= $session ? $session['sample_size'] : '8' ?> <span style="font-size: 12px; font-weight: 400;">pcs</span></span>
                        <?php 
                        $physQtyExp = max(0, $totalScanned - $usedSs);
                        $expText = '';
                        if ($usedSs > 0) {
                            if ($physQtyExp > 0) {
                                $expText = 'ℹ️ dari ' . number_format($physQtyExp) . ' pcs fisik baru (' . number_format($usedSs) . ' pcs SS Passed)';
                            } else {
                                $expText = '✨ 100% dipenuhi dari Safety Stock';
                            }
                        } else {
                            $expText = 'wajib diperiksa';
                        }
                        ?>
                        <span style="color: #bfdbfe; font-size: 10px; font-weight: 500; display: block; margin-top: 2px;" id="card-aql-explanation"><?= $expText ?></span>
                    </div>
                </div>
            </div>

            <!-- Card 3: Dual Progress Bars -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;" class="text-xs flex-shrink-0">
                
                <!-- Sample Diperiksa Progress Card -->
                <div style="background-color: #ffffff; border: 1px solid #cbd5e1; border-radius: 16px; padding: 10px;" class="shadow-xs space-y-1">
                    <div class="flex items-center justify-between font-bold">
                        <span class="text-slate-700 text-[11px]">Sample Diperiksa</span>
                        <span class="font-mono text-blue-700 text-xs font-black" id="label-sample-progress"><?= $session ? $session['samples_checked'] : 0 ?> / <?= $session ? $session['sample_size'] : 13 ?></span>
                    </div>
                    <?php 
                    $samplePct = ($session && $session['sample_size'] > 0) ? min(100, round(($session['samples_checked'] / $session['sample_size']) * 100)) : 0;
                    ?>
                    <div class="w-full bg-slate-100 rounded-full h-2 overflow-hidden">
                        <div id="bar-sample-progress" class="bg-blue-600 h-2 rounded-full transition-all duration-300" style="width: <?= $samplePct ?>%;"></div>
                    </div>
                </div>

                <!-- NG Ditemukan Limit Progress Card -->
                <div style="background-color: #fff1f2; border: 1px solid #fecdd3; border-radius: 16px; padding: 10px;" class="shadow-xs space-y-1">
                    <div class="flex items-center justify-between font-bold">
                        <span class="text-rose-900 text-[11px]">NG Ditemukan</span>
                        <span class="font-mono text-rose-700 text-xs font-black" id="label-ng-progress"><?= $session ? $session['ng_count'] : 0 ?> / <?= $session ? $session['reject_number'] : 2 ?> batas</span>
                    </div>
                    <?php 
                    $ngPct = ($session && $session['reject_number'] > 0) ? min(100, round(($session['ng_count'] / $session['reject_number']) * 100)) : 0;
                    ?>
                    <div class="w-full bg-rose-200 rounded-full h-2 overflow-hidden">
                        <div id="bar-ng-progress" class="bg-rose-600 h-2 rounded-full transition-all duration-300" style="width: <?= $ngPct ?>%;"></div>
                    </div>
                </div>

            </div>

            <!-- Card 4: Action Controls & Inline Defect Form Container -->
            <div id="card-4-container" style="background-color: #ffffff; border: 1px solid #cbd5e1; border-radius: 16px; padding: 12px; margin-bottom: 10px;" class="shadow-xs space-y-2 flex-shrink-0">
                
                <!-- Finished Session Banner (PASSED / REJECTED) -->
                <div id="finished-session-banner" class="<?= ($session && $session['status'] !== 'in_progress') ? '' : 'hidden' ?> p-3 bg-slate-100 border border-slate-200 rounded-xl text-center space-y-2">
                    <span class="text-[11px] font-bold text-slate-700 uppercase block tracking-wider">SESI INSPEKSI TELAH SELESAI</span>
                    <span id="finished-status-badge" class="text-[10px] font-extrabold uppercase text-white px-2.5 py-0.5 rounded-full inline-block shadow-2xs <?= ($session && $session['status'] === 'passed') ? 'bg-emerald-600' : 'bg-rose-600' ?>">
                        STATUS: <?= strtoupper($session['status'] ?? 'PASSED') ?>
                    </span>
                    
                    <div id="finished-rejection-btn-wrap" class="<?= ($session && $session['status'] === 'rejected') ? '' : 'hidden' ?> pt-1">
                        <a id="finished-rejection-link" href="<?= base_url('modules/inspection/print_rejection.php?session_id=' . ($session['id'] ?? 0)) ?>" target="_blank" rel="noopener noreferrer" class="inline-flex items-center justify-center px-3 py-1.5 bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs rounded-lg shadow-xs transition-all">
                            🖨️ Cetak Rejection Sheet
                        </a>
                    </div>

                    <div id="finished-undo-wrap" class="<?= ($session && $session['samples_checked'] > 0) ? '' : 'hidden' ?> pt-1.5 border-t border-slate-200/80">
                        <button type="button" onclick="undoLastSample()" class="w-full py-1.5 px-3 bg-white hover:bg-slate-50 text-slate-700 border border-slate-300 rounded-xl text-xs font-semibold flex items-center justify-center transition-all">
                            <svg class="w-3.5 h-3.5 mr-1 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"></path>
                            </svg>
                            <span id="finished-undo-text">Salah Input? Undo Sample Terakhir (#<?= $session['samples_checked'] ?? 0 ?>)</span>
                        </button>
                    </div>
                </div>

                <!-- Tombol Ringkas Tindakan Re-Inspeksi Kanban (Muncul saat REJECTED & ada lot NG) -->
                <div id="ng-batch-action-wrapper" class="hidden" style="background-color: #fff1f2; border: 1.5px solid #fecdd3; border-radius: 12px; padding: 10px; margin-top: 8px;">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                        <span style="font-size: 11px; font-weight: 800; color: #9f1239; text-transform: uppercase; letter-spacing: 0.05em; display: flex; align-items: center;">
                            <span style="width: 8px; height: 8px; border-radius: 50%; background-color: #e11d48; margin-right: 6px; display: inline-block;"></span>
                            TINDAKAN RE-INSPEKSI KANBAN
                        </span>
                        <span id="ng-batch-count-badge" style="font-size: 10px; font-weight: 800; color: #be123c; background-color: #ffe4e6; border: 1px solid #fca5a5; padding: 2px 8px; border-radius: 12px;"></span>
                    </div>
                    <button id="btn-open-batch-reinspection" type="button" onclick="openBatchReinspectionModal()" style="width: 100%; padding: 9px 12px; background: linear-gradient(135deg, #e11d48 0%, #be123c 100%); color: #ffffff; border: none; border-radius: 10px; font-size: 12px; font-weight: 800; cursor: pointer; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(225,29,72,0.3); transition: all 0.15s ease-in-out;">
                        <span style="margin-right: 6px; font-size: 14px;">⚡</span>
                        <span>Tangani Re-Inspeksi Kanban</span>
                    </button>
                </div>

                <!-- Active Inspection Action Buttons (IN PROGRESS) -->
                <div id="active-inspection-btns" class="<?= ($session && $session['status'] !== 'in_progress') ? 'hidden' : '' ?> space-y-2">
                    <div class="grid grid-cols-2 gap-2">
                        <!-- Red NG Button -->
                        <button type="button" onclick="toggleInlineNgForm(true)" class="w-full py-2 px-2 bg-rose-600 hover:bg-rose-700 text-white rounded-xl text-xs font-extrabold shadow-xs flex items-center justify-center transition-all transform active:scale-98">
                            <svg class="w-3.5 h-3.5 mr-1 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"></path>
                            </svg>
                            <span class="truncate">CATAT NG</span>
                        </button>

                        <!-- Green OK Button -->
                        <button type="button" onclick="submitSampleResult('OK')" class="w-full py-2 px-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold shadow-xs flex items-center justify-center transition-all transform active:scale-98">
                            <svg class="w-3.5 h-3.5 mr-1 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span class="truncate">SAMPLE OK</span>
                        </button>
                    </div>

                    <!-- Bulk Complete All OK Button -->
                    <?php 
                    $remainingPcs = $session ? max(0, (int)$session['sample_size'] - (int)$session['samples_checked']) : 0;
                    ?>
                    <button id="btn-bulk-ok" type="button" onclick="submitBulkOk(<?= $remainingPcs ?>)" style="width: 100%; padding: 8px 12px; background: linear-gradient(135deg, #059669 0%, #0d9488 100%); color: #ffffff; border-radius: 12px; font-size: 12px; font-weight: 800; border: 1px solid #059669; cursor: pointer; display: <?= ($remainingPcs > 0) ? 'flex' : 'none' ?>; align-items: center; justify-content: center; box-shadow: 0 1px 3px 0 rgba(0,0,0,0.1);" class="hover:opacity-95 transition-all transform active:scale-98">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#fef08a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 6px; flex-shrink: 0;">
                            <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
                        </svg>
                        <span id="btn-bulk-ok-label" style="color: #ffffff; font-weight: 800;">Selesaikan Sisa Sample OK (<?= $remainingPcs ?> Pcs)</span>
                    </button>

                    <!-- Undo Sample Button -->
                    <div id="active-undo-wrap" class="<?= ($session && $session['samples_checked'] > 0) ? '' : 'hidden' ?>">
                        <button type="button" onclick="undoLastSample()" class="w-full py-1.5 px-3 bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-300 rounded-xl text-[11px] font-semibold flex items-center justify-center transition-all">
                            <svg class="w-3.5 h-3.5 mr-1 text-slate-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"></path>
                            </svg>
                            <span id="active-undo-text">Undo Sample Terakhir (#<?= $session['samples_checked'] ?? 0 ?>)</span>
                        </button>
                    </div>
                </div>

                <!-- Inline Defect Form Card -->
                <div id="inline-ng-form-box" class="hidden p-3 bg-rose-50/90 border border-rose-200 rounded-xl space-y-2">
                    <div class="flex items-center justify-between text-[11px] font-bold text-rose-900 border-b border-rose-200/80 pb-1">
                        <span id="inline-form-title">FORM NG #<?= count($ngRecords) + 1 ?> — SEDANG DIISI</span>
                        <span class="text-rose-600 text-[10px]" id="inline-sample-target-label">Sample ke-<?= $session ? min($session['sample_size'], $session['samples_checked'] + 1) : 1 ?></span>
                    </div>
                    <!-- Selection of Source Box Label & Lot Number -->
                    <div class="p-2 bg-white/90 border border-rose-200 rounded-lg space-y-1">
                        <label class="block text-[10px] font-bold text-rose-900 uppercase">📦 Sumber Box / Lot Number Produksi (Ref No.) <span class="text-rose-600">*</span></label>
                        <select id="ng-session-lot-select" class="form-input text-xs font-bold py-1 bg-rose-50/50 text-slate-800 border-rose-200 focus:border-rose-500 focus:ring-rose-500">
                            <option value="">-- Pilih Box / Lot Number yang Temuan NG --</option>
                            <?php if (!empty($sessionLots)): ?>
                                <?php foreach ($sessionLots as $sl): ?>
                                    <option value="<?= $sl['id'] ?>" <?= count($sessionLots) === 1 ? 'selected' : '' ?>>
                                        Lot: <?= htmlspecialchars($sl['lot_number']) ?> — Ref Box: <?= htmlspecialchars($sl['ref_number'] ?? '-') ?> (<?= number_format($sl['qty']) ?> pcs)
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <!-- Dynamic Defect Rows Container -->
                    <div id="ng-defect-rows-container" class="space-y-2">
                        <!-- Defect Row 0 -->
                        <div class="ng-defect-row p-2 bg-white/90 border border-rose-200 rounded-lg space-y-1.5" data-row-idx="0">
                            <div>
                                <label class="block text-[10px] font-bold text-slate-700 mb-0.5">Jenis Defect #1</label>
                                <select class="ng-defect-type form-input text-xs font-semibold py-1" onchange="onInlineDefectRowChange(this)">
                                    <option value="">-- Pilih Jenis Defect --</option>
                                    <?php foreach ($defectTypes as $dt): ?>
                                        <option value="<?= $dt['id'] ?>"><?= htmlspecialchars($dt['name']) ?></option>
                                    <?php endforeach; ?>
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
                        </div>
                    </div>

                    <!-- Button to Add More Defects for the Same Sample Item -->
                    <div class="pt-0.5">
                        <button type="button" onclick="addNgDefectRow()" class="w-full py-1.5 px-2 bg-rose-100 hover:bg-rose-200 text-rose-800 rounded-lg text-[11px] font-bold border border-rose-300/70 flex items-center justify-center transition-colors shadow-2xs">
                            <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"></path></svg>
                             Tambah Jenis Defect Lain (Sample Sama)
                        </button>
                    </div>

                    <div class="flex items-center justify-end space-x-2 pt-1 border-t border-rose-200/60">
                        <button type="button" onclick="toggleInlineNgForm(false)" class="px-3 py-1 bg-white border border-slate-300 hover:bg-slate-100 text-slate-700 rounded-lg text-xs font-semibold">
                            Batal
                        </button>
                        <button type="button" onclick="submitSampleResult('NG')" class="px-3 py-1 bg-rose-600 hover:bg-rose-700 text-white rounded-lg text-xs font-bold shadow-xs">
                            Simpan NG
                        </button>
                    </div>
                </div>

            </div>

            <!-- Card 5: Riwayat NG Inspeksi Ini -->
            <div style="background-color: #ffffff; border: 1px solid #cbd5e1; border-radius: 16px; padding: 12px; min-height: 120px; max-height: 250px; display: flex; flex-direction: column; overflow: hidden;" class="shadow-xs space-y-1.5 flex-1">
                <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block flex-shrink-0">RIWAYAT NG INSPEKSI INI</span>
                
                <div id="ng-history-container" style="flex: 1; min-height: 0; overflow-x: hidden; overflow-y: auto; -webkit-overflow-scrolling: touch;" class="space-y-1.5 pr-1">
                    <?php if (empty($ngRecords)): ?>
                        <p id="ng-empty-msg" class="text-[11px] text-slate-400 text-center py-4">Belum ada defect tercatat pada lot ini.</p>
                    <?php else: ?>
                        <?php foreach ($ngRecords as $rec): ?>
                            <div style="display: flex; align-items: center; justify-content: space-between; padding: 8px 10px; background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; font-size: 12px; gap: 8px;">
                                <div style="min-width: 0; flex: 1;">
                                    <span style="font-weight: 800; color: #be123c; font-size: 11px; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                        #<?= $rec['sample_number'] ?> <?= htmlspecialchars($rec['defect_name']) ?>
                                    </span>
                                    <span style="font-size: 10px; color: #64748b; display: block;">
                                        Qty <?= $rec['qty_ng'] ?> &middot; Sample ke-<?= $rec['sample_number'] ?>
                                    </span>
                                    <?php if (!empty($rec['lot_number']) || !empty($rec['ref_number'])): ?>
                                        <span style="font-size: 9px; font-weight: 700; color: #1e40af; background-color: #eff6ff; padding: 1px 5px; border-radius: 4px; border: 1px solid #bfdbfe; font-family: monospace; display: inline-block; margin-top: 2px;">
                                            📦 Lot: #<?= htmlspecialchars($rec['lot_number'] ?: '-') ?><?= !empty($rec['ref_number']) ? ' (Ref: ' . htmlspecialchars($rec['ref_number']) . ')' : '' ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <button type="button" onclick="deleteNgRecord(<?= (int)$rec['id'] ?>)" style="flex-shrink: 0; background-color: #fff1f2; color: #e11d48; border: 1px solid #fecdd3; padding: 3px 8px; border-radius: 6px; font-size: 10px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; line-height: 1;" title="Batalkan defect ini">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display: inline-block; vertical-align: middle; flex-shrink: 0;">
                                        <polyline points="3 6 5 6 21 6"></polyline>
                                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                                    </svg>
                                    <span>Batal</span>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        </div>

    </div>

</div>

<!-- Scan & Validation Dead-Center Modal Overlay (2-Step Wizard: Step 1 Pilih Planning -> Step 2 Scan Label) -->
<div id="scan-modal-overlay" style="position: fixed; inset: 0; background-color: rgba(15, 23, 42, 0.75); backdrop-filter: blur(4px); z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 16px;" class="<?= $showScanOverlay ? '' : 'hidden' ?>">
    
    <div style="background-color: #ffffff; border-radius: 24px; max-width: 640px; width: 95vw; max-height: 88vh; padding: 24px; border: 1px solid #cbd5e1; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); position: relative; margin: auto; display: flex; flex-direction: column; overflow-x: hidden; overflow-y: auto;" class="animate-fadeIn space-y-4">
        
        <button type="button" onclick="closeScanModal(true)" class="absolute right-5 top-5 text-slate-400 hover:text-slate-600 font-bold text-xl cursor-pointer" title="Tutup / Batal">&times;</button>

        <!-- Wizard Step Indicator Header -->
        <div class="text-center space-y-1.5 flex-shrink-0">
            <div class="flex items-center justify-center space-x-2 flex-wrap gap-y-1">
                <span id="step-pill-1" style="background-color: #2563eb; color: #ffffff; padding: 4px 12px; border-radius: 9999px; font-size: 11px; font-weight: 800; display: inline-block;">
                    1. Pilih Planning
                </span>
                <span style="color: #cbd5e1; font-weight: 800;" class="hidden sm:inline">&rarr;</span>
                <span id="step-pill-2" style="background-color: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; padding: 4px 12px; border-radius: 9999px; font-size: 11px; font-weight: 700; display: inline-block;">
                    2. Scan Label QR
                </span>
            </div>
            <h2 id="wizard-modal-title" class="text-base font-black text-slate-900 leading-tight">Step 1: Pilih Item Planning Aktif</h2>
            <p id="wizard-modal-subtitle" class="text-xs text-slate-500 font-medium">Pilih Kanban / Safety Stock yang akan diinspeksi sebelum melakukan scan label</p>
        </div>

        <datalist id="modal-parts-list">
            <?php foreach ($master_parts as $mp): ?>
                <option value="<?= htmlspecialchars($mp['part_code']) ?>"><?= htmlspecialchars($mp['part_code']) ?> - <?= htmlspecialchars($mp['part_name']) ?></option>
            <?php endforeach; ?>
        </datalist>

        <!-- ── STEP 1: PILIH PLANNING ITEM ─────────────────────────── -->
        <div id="wizard-step-1-content" style="display: flex; flex-direction: column; min-height: 0; flex: 1;" class="space-y-3 overflow-hidden">
            
            <!-- Tab Filter Tipe Planning (Default Prioritas Utama: KANBAN) -->
            <div style="display: flex; align-items: center; gap: 6px;" class="flex-shrink-0">
                <button type="button" id="tab-plan-kanban" onclick="setPlanningTypeTab('kanban')" style="background-color: #2563eb; color: #ffffff; border: 1px solid #1d4ed8; font-weight: 800; padding: 5px 12px; border-radius: 8px; font-size: 11px; cursor: pointer; box-shadow: 0 2px 4px rgba(37,99,235,0.3);">
                    📋 Kanban (Utama)
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
                <input type="text" id="planning-search-input" onkeyup="filterPlanningItems()" placeholder="Cari Part Code, Item Description, Customer, atau No. Kanban..." class="form-input py-2 px-3 text-xs w-full font-semibold">
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
                        $pQty  = number_format($plan['qty']);
                        
                        $isSafetyStock = ($plan['plan_type'] === 'safety_stock' || ($plan['batch_plan_type'] ?? '') === 'safety_stock' || strpos(strtoupper($plan['kanban_no'] ?? ''), 'SS') === 0);
                        $pType = $isSafetyStock ? 'Safety Stock' : 'Kanban';
                        $pCek  = !empty($plan['check_type']) ? htmlspecialchars($plan['check_type']) : '';
                        
                        // Format ETA (Estimated Time of Arrival / Schedule Kirim ETA)
                        $etaTs = !empty($plan['eta']) ? strtotime($plan['eta']) : (!empty($plan['req_date']) ? strtotime($plan['req_date']) : 0);
                        $etaFormatted = ($etaTs > 0) ? date('d M Y, H:i', $etaTs) . ' WIB' : 'Reguler';
                        $isEarliest = ($idx === 0);
                    ?>
                    <div class="planning-card p-3 bg-white hover:bg-blue-50/80 border border-slate-200 hover:border-blue-400 rounded-xl cursor-pointer transition-all flex items-center justify-between gap-3 shadow-2xs"
                         data-plan-type="<?= $isSafetyStock ? 'safety_stock' : 'kanban' ?>"
                         onclick="selectPlanningItem(<?= (int)$plan['id'] ?>, '<?= addslashes($plan['item_code']) ?>', '<?= addslashes($plan['item_description']) ?>', '<?= addslashes($pCust) ?>', <?= (int)$plan['qty'] ?>, '<?= addslashes($pCek) ?>', '<?= $isSafetyStock ? 'safety_stock' : 'kanban' ?>', <?= (int)($plan['avail_ss_qty'] ?? 0) ?>)">
                        <div class="min-w-0 flex-1 space-y-1">
                            <div class="flex items-center space-x-1.5 flex-wrap gap-y-1">
                                <span class="font-mono font-black text-blue-700 text-xs"><?= $pCode ?></span>
                                <span class="badge text-[9px] <?= $isSafetyStock ? 'bg-purple-100 text-purple-800 border border-purple-200 font-extrabold' : 'bg-blue-100 text-blue-800 border border-blue-200 font-bold' ?>"><?= $pType ?></span>
                                <?php if ($pCek): ?>
                                    <span class="inline-flex items-center px-1.5 py-0.2 rounded text-[9px] font-extrabold bg-amber-100 text-amber-800 border border-amber-300"><?= $pCek ?> Cek</span>
                                <?php endif; ?>
                                <?php if (!empty($plan['avail_ss_qty']) && (int)$plan['avail_ss_qty'] > 0 && !$isSafetyStock): ?>
                                    <span class="inline-flex items-center px-1.5 py-0.2 rounded text-[9px] font-extrabold bg-emerald-100 text-emerald-800 border border-emerald-300" title="Tersedia stok Safety Stock yang bisa dipakai memotong Qty Kanban">📦 Safety Stock: <?= number_format($plan['avail_ss_qty']) ?> pcs (Potong Qty)</span>
                                <?php endif; ?>

                                <!-- ETA Schedule & Prioritas Badge (Compact & Small Text) -->
                                <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[9px] font-extrabold <?= $isEarliest ? 'bg-rose-100 text-rose-800 border border-rose-300' : 'bg-slate-100 text-slate-600 border border-slate-200' ?>">
                                    🚚 ETA: <?= $etaFormatted ?> <?= $isEarliest ? '⚡ (P1)' : '' ?>
                                </span>
                            </div>
                            <div class="font-bold text-slate-800 text-xs truncate"><?= $pDesc ?></div>
                            <div class="text-[10px] text-slate-500 flex items-center space-x-2 flex-wrap">
                                <span>Cust: <b><?= $pCust ?></b></span>
                                <span>&middot;</span>
                                <span>Qty: <b><?= $pQty ?> pcs</b></span>
                                <?php if (!empty($plan['kanban_no']) && $plan['kanban_no'] !== '-'): ?>
                                    <span>&middot;</span>
                                    <span class="font-mono">No. Kanban: <b>#<?= htmlspecialchars($plan['kanban_no']) ?></b></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <button type="button" class="btn-primary py-1.5 px-3 text-xs font-bold flex-shrink-0 shadow-2xs">
                            Pilih &rarr;
                        </button>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Step 1 Footer Action Bar (Batal / Kembali Button) -->
            <div style="display: flex; align-items: center; justify-content: flex-end; padding-top: 8px; border-top: 1px solid #e2e8f0;" class="flex-shrink-0">
                <button type="button" onclick="closeScanModal(true)" style="background-color: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; font-weight: 700; padding: 6px 14px; border-radius: 9px; font-size: 11px; cursor: pointer; display: inline-flex; align-items: center; gap: 5px;">
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
                <div class="space-y-1 bg-emerald-100/50 p-2 rounded-lg border border-emerald-200">
                    <div class="flex items-center justify-between font-extrabold text-[11px] text-emerald-900 pb-1 border-b border-emerald-200/80">
                        <span class="flex items-center space-x-1">
                            <span>📋 Pilih Lot Safety Stock Tersedia</span>
                        </span>
                        <span class="text-[10px] text-emerald-700 font-semibold" id="ss-lots-count-info">0 Lot</span>
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
                    <input type="text" id="scan-qr-raw" oninput="parseBarcodeQRInput(this.value, false)" onkeydown="handleScanInputKeydown(event)" placeholder="Tempel atau Scan Barcode / QR Code Label di sini..." class="form-input py-2 px-3 text-xs font-mono font-bold bg-white border-blue-400 focus:border-blue-600 w-full min-w-0 box-border" autocomplete="off">
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
                                    <tr class="hover:bg-indigo-50/50 transition-colors">
                                        <td class="px-2.5 py-1.5 font-bold text-slate-400"><?= $idx + 1 ?></td>
                                        <td class="px-2.5 py-1.5 font-mono font-bold text-blue-800 text-[10px]"><span class="bg-blue-50 px-1.5 py-0.5 rounded border border-blue-200"><?= htmlspecialchars($lbl['ref_number'] ?? '-') ?></span></td>
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
                        <span id="modal-kb-str-loc" class="font-mono font-bold text-slate-800 text-xs block"><?= $session ? htmlspecialchars($session['kanban_str_loc'] ?? 'WH-A01') : 'WH-A01' ?></span>
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
                <div style="width: 38px; height: 38px; border-radius: 12px; background-color: rgba(225, 29, 72, 0.25); border: 1.5px solid rgba(244, 63, 94, 0.4); display: flex; align-items: center; justify-content: center; color: #fb7185; font-size: 18px; font-weight: 800; flex-shrink: 0;">
                    ⚡
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
        <div style="padding: 18px; overflow-y: auto; flex: 1; background-color: #f8fafc; display: flex; flex-direction: column; gap: 16px;">

            <!-- Section 1: Ringkasan Lot NG Terdampak & Semua Lot Kanban -->
            <div style="background-color: #f8fafc; border: 1.5px solid #cbd5e1; border-radius: 14px; padding: 12px; display: flex; flex-direction: column; gap: 8px;">
                <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">
                    <span style="font-size: 11px; font-weight: 800; color: #1e293b; text-transform: uppercase; letter-spacing: 0.04em; display: flex; align-items: center;">
                        📦 DAFTAR LOT KANBAN SESI INI
                    </span>
                    <!-- Tab Switcher / Segmented Control -->
                    <div style="display: flex; gap: 4px; background: #e2e8f0; padding: 2px; border-radius: 8px;">
                        <button type="button" id="btn-batch-tab-ng" onclick="renderBatchLotList('ng')" style="font-size: 10px; font-weight: 700; padding: 3px 8px; border-radius: 6px; border: none; cursor: pointer; transition: all 0.15s; background-color: #ffffff; color: #be123c; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                            🔴 Lot NG (<span id="batch-modal-ng-count">0</span>)
                        </button>
                        <button type="button" id="btn-batch-tab-all" onclick="renderBatchLotList('all')" style="font-size: 10px; font-weight: 700; padding: 3px 8px; border-radius: 6px; border: none; cursor: pointer; transition: all 0.15s; background-color: transparent; color: #64748b;">
                            📦 Semua Lot (<span id="batch-modal-all-count">0</span>)
                        </button>
                    </div>
                </div>
                <div id="batch-modal-lot-list" style="display: flex; flex-direction: column; gap: 6px; max-height: 125px; overflow-y: auto; padding-right: 2px;">
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
                            <span style="font-size: 12px; font-weight: 900; color: #78350f; display: block; line-height: 1.3;">🔄 INSPEKSI ULANG </span>
                            <span style="font-size: 10px; font-weight: 700; color: #b45309; display: block; margin-top: 2px;">Gunakan Lot Existing</span>
                        </div>

                        <!-- Sub Action Buttons for Rescan -->
                        <div id="sub-rescan-buttons" style="margin-top: 12px; padding-top: 10px; border-top: 1px solid #fde68a; display: flex; flex-direction: column; gap: 6px;">
                            <button type="button" id="btn-sub-rescan-restart" onclick="selectSubAction('rescan_restart', event)" style="padding: 6px 10px; font-size: 11px; font-weight: 700; border-radius: 8px; border: 1.5px solid #d97706; background-color: #d97706; color: #ffffff; cursor: pointer; text-align: left; transition: all 0.15s;">
                                🔁 Ulang dari Awal (Reset ke Sample #1)
                            </button>
                            <button type="button" id="btn-sub-rescan-continue" onclick="selectSubAction('rescan_continue', event)" style="padding: 6px 10px; font-size: 11px; font-weight: 600; border-radius: 8px; border: 1.5px solid #fcd34d; background-color: #ffffff; color: #92400e; cursor: pointer; text-align: left; transition: all 0.15s;">
                                ⏩ Lanjut Inspeksi (Sisa Sample)
                            </button>
                        </div>
                    </div>

                    <!-- Card Button Strategi 2: Ganti Lot -->
                    <div id="card-btn-replace" onclick="selectBatchStrategy('replace')" style="text-align: left; cursor: pointer; border: 2px solid #cbd5e1; background-color: #ffffff; border-radius: 14px; padding: 14px; display: flex; flex-direction: column; justify-content: space-between; transition: all 0.2s; opacity: 0.65;">
                        <div>
                            <span style="font-size: 12px; font-weight: 900; color: #0f172a; display: block; line-height: 1.3;">🔁 GANTI LOT BARU</span>
                            <span style="font-size: 10px; font-weight: 600; color: #64748b; display: block; margin-top: 2px;">Scan QR Code Lot Pengganti Gudang</span>
                        </div>

                        <!-- Sub Action Buttons for Replace -->
                        <div id="sub-replace-buttons" style="margin-top: 12px; padding-top: 10px; border-top: 1px solid #e2e8f0; display: flex; flex-direction: column; gap: 6px; opacity: 0.5; pointer-events: none;">
                            <button type="button" id="btn-sub-replace-ng" onclick="selectSubAction('replace_ng_only', event)" style="padding: 6px 10px; font-size: 11px; font-weight: 700; border-radius: 8px; border: 1.5px solid #e11d48; background-color: #e11d48; color: #ffffff; cursor: pointer; text-align: left; transition: all 0.15s;">
                                🎯 Ganti Lot yang NG Saja
                            </button>
                            <button type="button" id="btn-sub-replace-all" onclick="selectSubAction('replace_all_lots', event)" style="padding: 6px 10px; font-size: 11px; font-weight: 600; border-radius: 8px; border: 1.5px solid #cbd5e1; background-color: #ffffff; color: #475569; cursor: pointer; text-align: left; transition: all 0.15s;">
                                📦 Ganti Semua Lot
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
                        <span style="font-size: 14px;">📷</span> Scan Barcode / QR Code Label
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

<script>
var currentSessionId = <?= $sessionId ?: 0 ?>;
var currentZoom = 1.0;

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
        if (p.startsWith('Z3')) obj.Z3 = parseInt(p.substring(2)) || 1;
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

    var effectivePartCode = parsed.Z1 || (document.getElementById('scan-part-code') ? document.getElementById('scan-part-code').value.trim() : '') || selectedPlanningPartCode;

    // Live update form fields for visual feedback without triggering DID check
    if (parsed.Z1 && document.getElementById('scan-part-code')) document.getElementById('scan-part-code').value = parsed.Z1;
    if (parsed.Z2 && document.getElementById('scan-lot-number')) document.getElementById('scan-lot-number').value = parsed.Z2;
    if (parsed.Z3 && document.getElementById('scan-label-qty')) document.getElementById('scan-label-qty').value = parsed.Z3;
    if (parsed.Z4 && document.getElementById('scan-remarks')) document.getElementById('scan-remarks').value = parsed.Z4;
    if (parsed.Z5 && document.getElementById('scan-ref-number')) document.getElementById('scan-ref-number').value = parsed.Z5;

    // HANYA SUBMIT jika user/scanner menekan ENTER (forceProcess === true) atau terdapat Newline (\n / \r) dari hardware scanner
    var hasNewline = (val.includes('\n') || val.includes('\r'));

    if (forceProcess || hasNewline) {
        var lotToUse = parsed.Z2 || (document.getElementById('scan-lot-number') ? document.getElementById('scan-lot-number').value.trim() : '') || val.toUpperCase();
        var qtyToUse = parsed.Z3 || (document.getElementById('scan-label-qty') ? parseInt(document.getElementById('scan-label-qty').value) : 500);
        var remToUse = parsed.Z4 || (document.getElementById('scan-remarks') ? document.getElementById('scan-remarks').value.trim() : '');
        var refToUse = parsed.Z5 || (document.getElementById('scan-ref-number') ? document.getElementById('scan-ref-number').value.trim() : '');

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
    var qty = parseInt(document.getElementById('scan-label-qty').value) || 500;
    var rem = document.getElementById('scan-remarks').value.trim();
    var ref = document.getElementById('scan-ref-number').value.trim();

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
            title: 'Part Code Mismatch!',
            text: 'Part Code pada label (' + lbl.Z1 + ') tidak cocok dengan Part Code yang sedang diinspeksi (' + selectedPlanningPartCode + ')!'
        });
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

    // 3. Real-Time DID Validation (Cek Dimensi) for this specific Part Code + Lot Number (Z2)
    Swal.fire({
        title: 'Memeriksa DID...',
        text: 'Memverifikasi Cek Dimensi Lot #' + lbl.Z2 + '...',
        allowOutsideClick: false,
        didOpen: function() { Swal.showLoading(); }
    });

    var checkUrl = '<?= base_url("modules/inspection/scan_validate.php") ?>?mode=check_did_only&part_code=' + encodeURIComponent(lbl.Z1) + '&lot_number=' + encodeURIComponent(lbl.Z2) + '&inspection_type=' + encodeURIComponent(selectedPlanningType);

    fetch(checkUrl)
        .then(function(res) { return res.json(); })
        .then(function(data) {
            closeSwalSafely();
            if (!data.success) {
                Swal.fire({
                    icon: 'error',
                    title: '⚠️ LOT NUMBER BELUM LOLOS CEK DIMENSI (DID)!',
                    html: '<div style="font-size: 12px; text-align: left; line-height: 1.6; color: #334155;">' +
                          '<b style="color: #be123c;">' + escapeHtml(data.message || 'Lot Number belum lolos cek dimensi!') + '</b><br><br>' +
                          'Part Code: <b>' + escapeHtml(lbl.Z1) + '</b><br>' +
                          'Lot Number yang Di-scan/Di-input: <b style="font-family: monospace; color: #1d4ed8;">' + escapeHtml(lbl.Z2) + '</b>' +
                          '</div>'
                });
                return;
            }

            // DID Check Passed! Push to scanned list
            scannedLabelsList.push(lbl);

            if (typeof Swal !== 'undefined' && typeof Swal.fire === 'function') {
                Swal.fire({
                    icon: 'success',
                    title: '✓ Terverifikasi DID',
                    text: 'Label Lot #' + lbl.Z2 + ' (' + lbl.Z3 + ' pcs) berhasil ditambahkan!',
                    timer: 1500,
                    showConfirmButton: false
                });
            }

            // Update input fields
            document.getElementById('scan-part-code').value = lbl.Z1;
            document.getElementById('scan-lot-number').value = lbl.Z2;

            renderScannedLabelsTable();
        })
        .catch(function(err) {
            console.error('DID Verification error:', err);
            closeSwalSafely();
            Swal.fire({
                icon: 'error',
                title: 'Gagal Memeriksa DID',
                text: 'Terjadi kesalahan sistem saat memverifikasi status DID: ' + (err.message || 'Error koneksi server')
            });
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
    var totalScanned = 0;
    scannedLabelsList.forEach(function(l) { totalScanned += parseInt(l.Z3) || 0; });
    var targetQty = isSafetyStock ? (totalScanned > 0 ? totalScanned : 0) : (selectedPlanningQty || 500);

    if (!tbody) return;

    if (scannedLabelsList.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="px-2.5 py-4 text-center text-slate-400 italic">Belum ada label QR yang discan/diinput. Silakan scan atau isi form label di atas.</td></tr>';
        if (countSpan) countSpan.textContent = '0';
        if (totalQtySpan) totalQtySpan.textContent = '0';
        if (targetQtySpan) targetQtySpan.textContent = isSafetyStock ? 'Akumulasi Gudang' : targetQty.toLocaleString();
        if (progressBar) progressBar.style.width = '0%';
        if (btnSubmit) {
            btnSubmit.disabled = true;
            btnSubmit.style.opacity = '0.6';
            btnSubmit.innerHTML = isSafetyStock ? '📦 Mulai Inspeksi Safety Stock' : 'Validasi Matching & Muat Inspeksi';
        }
        if (btnClear) btnClear.style.display = 'none';
        if (excessMsg) excessMsg.classList.add('hidden');
        if (statusMsg) statusMsg.textContent = isSafetyStock ? 'Scan / input label QR dari gudang untuk mengumpulkan Qty...' : 'Scan label QR untuk mengumpulkan Qty...';
        return;
    }

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
                        '<button type="button" onclick="removeScannedLabel(' + idx + ')" class="text-rose-600 hover:text-rose-800 font-bold px-1.5 py-0.5 rounded hover:bg-rose-50" title="Hapus Label Ini">&times;</button>' +
                    '</td>' +
                '</tr>';
    });

    tbody.innerHTML = html;

    if (countSpan) countSpan.textContent = scannedLabelsList.length;
    if (totalQtySpan) totalQtySpan.textContent = totalScanned.toLocaleString();
    if (targetQtySpan) targetQtySpan.textContent = isSafetyStock ? (totalScanned.toLocaleString() + ' pcs (Lot Gudang)') : targetQty.toLocaleString();

    var pct = isSafetyStock ? 100 : Math.min(100, Math.round((totalScanned / targetQty) * 100));
    if (progressBar) {
        progressBar.style.width = pct + '%';
        if (totalScanned >= targetQty || isSafetyStock) {
            progressBar.style.backgroundColor = isSafetyStock ? '#7c3aed' : '#10b981';
        } else {
            progressBar.style.backgroundColor = '#2563eb';
        }
    }

    var excessQty = isSafetyStock ? 0 : Math.max(0, totalScanned - targetQty);
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
            statusMsg.textContent = '✓ Total Akumulasi: ' + totalScanned.toLocaleString() + ' pcs (' + scannedLabelsList.length + ' Label Box). Siap diinspeksi!';
            statusMsg.className = 'text-purple-700 font-extrabold';
        } else if (totalScanned >= targetQty) {
            statusMsg.textContent = '✓ Target Qty Terpenuhi! Siap diproses.';
            statusMsg.className = 'text-emerald-600 font-extrabold';
        } else {
            statusMsg.textContent = 'Perlu ' + (targetQty - totalScanned).toLocaleString() + ' pcs lagi untuk memenuhi target.';
            statusMsg.className = 'text-slate-500 font-medium';
        }
    }

    if (btnSubmit) {
        if (isSafetyStock) {
            // Safety Stock: enable selama ada scan (tidak ada batasan target qty)
            if (totalScanned > 0) {
                btnSubmit.disabled = false;
                btnSubmit.style.opacity = '1';
                btnSubmit.innerHTML = '📦 MULAI INSPEKSI SAFETY STOCK (' + scannedLabelsList.length + ' Label / ' + totalScanned.toLocaleString() + ' pcs) &rarr;';
            } else {
                btnSubmit.disabled = true;
                btnSubmit.style.opacity = '0.6';
                btnSubmit.innerHTML = '📦 Mulai Inspeksi Safety Stock';
            }
        } else {
            // Kanban: harus mencapai target qty sebelum bisa submit
            var kanbanQtyMet = (totalScanned >= targetQty);
            if (totalScanned > 0 && kanbanQtyMet) {
                btnSubmit.disabled = false;
                btnSubmit.style.opacity = '1';
                btnSubmit.innerHTML = '🚀 MULAI INSPEKSI OQC (' + scannedLabelsList.length + ' Label / ' + totalScanned.toLocaleString() + ' pcs) &rarr;';
            } else if (totalScanned > 0 && !kanbanQtyMet) {
                btnSubmit.disabled = true;
                btnSubmit.style.opacity = '0.5';
                btnSubmit.innerHTML = '⚠️ Belum Cukup Qty: ' + totalScanned.toLocaleString() + ' / ' + targetQty.toLocaleString() + ' pcs (kurang ' + (targetQty - totalScanned).toLocaleString() + ' pcs)';
            } else {
                btnSubmit.disabled = true;
                btnSubmit.style.opacity = '0.6';
                btnSubmit.innerHTML = '🚀 Mulai Inspeksi OQC';
            }
        }
    }

    if (btnClear) btnClear.style.display = 'inline';
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

    // 3. Reset form input fields
    if (document.getElementById('scan-part-code')) document.getElementById('scan-part-code').value = '';
    if (document.getElementById('scan-lot-number')) document.getElementById('scan-lot-number').value = '';
    if (document.getElementById('scan-qty-box')) document.getElementById('scan-qty-box').value = '';
    if (document.getElementById('scan-ref-number')) document.getElementById('scan-ref-number').value = '';
    if (document.getElementById('scan-qr-raw')) document.getElementById('scan-qr-raw').value = '';

    if (document.getElementById('selected-plan-part-code')) document.getElementById('selected-plan-part-code').textContent = 'AUTOMATIC FIFO MATCHING';
    if (document.getElementById('selected-plan-desc')) document.getElementById('selected-plan-desc').textContent = 'Sistem otomatis mencocokkan planning berdasarkan Part Code';
    if (document.getElementById('selected-plan-cust')) document.getElementById('selected-plan-cust').textContent = '-';
    if (document.getElementById('selected-plan-qty')) document.getElementById('selected-plan-qty').textContent = '-';
    if (document.getElementById('selected-plan-tag')) document.getElementById('selected-plan-tag').classList.add('hidden');

    // 4. Update UI tables & cards
    if (typeof renderScannedLabelsTable === 'function') renderScannedLabelsTable();
    if (typeof updateSafetyStockDeductionUI === 'function') updateSafetyStockDeductionUI();
}

function refreshPlanningItemsAjax() {
    fetch('<?= base_url("modules/inspection/session.php?action=get_planning_items") ?>')
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res && res.success && Array.isArray(res.items)) {
                renderPlanningCardsList(res.items);
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
        var rawQty = parseInt(plan.qty) || 0;
        var pQty = rawQty.toLocaleString();
        
        var isSafetyStock = (plan.plan_type === 'safety_stock' || (plan.batch_plan_type || '') === 'safety_stock' || (plan.kanban_no || '').toUpperCase().indexOf('SS') === 0);
        var pType = isSafetyStock ? 'Safety Stock' : 'Kanban';
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
        var pCekBadge = pCek ? ('<span class="inline-flex items-center px-1.5 py-0.2 rounded text-[9px] font-extrabold bg-amber-100 text-amber-800 border border-amber-300">' + pCek + ' Cek</span>') : '';
        var ssBadge = (availSsQty > 0 && !isSafetyStock) ? ('<span class="inline-flex items-center px-1.5 py-0.2 rounded text-[9px] font-extrabold bg-emerald-100 text-emerald-800 border border-emerald-300" title="Tersedia stok Safety Stock yang bisa dipakai memotong Qty Kanban">📦 Safety Stock: ' + availSsQty.toLocaleString() + ' pcs (Potong Qty)</span>') : '';
        var etaBadge = '<span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[9px] font-extrabold ' + (isEarliest ? 'bg-rose-100 text-rose-800 border border-rose-300' : 'bg-slate-100 text-slate-600 border border-slate-200') + '">🚚 ETA: ' + etaFormatted + (isEarliest ? ' ⚡ (P1)' : '') + '</span>';
        var kanbanNoBadge = (plan.kanban_no && plan.kanban_no !== '-') ? ('<span>&middot;</span><span class="font-mono">No. Kanban: <b>#' + escapeHtml(plan.kanban_no) + '</b></span>') : '';

        var codeEsc = (plan.item_code || '').replace(/\\/g, "\\\\").replace(/'/g, "\\'");
        var descEsc = (plan.item_description || '').replace(/\\/g, "\\\\").replace(/'/g, "\\'");
        var custEsc = (plan.customer || 'PT. Indonesia Epson Industry').replace(/\\/g, "\\\\").replace(/'/g, "\\'");
        var cekEsc = (plan.check_type || '').replace(/\\/g, "\\\\").replace(/'/g, "\\'");

        html += '<div class="planning-card p-3 bg-white hover:bg-blue-50/80 border border-slate-200 hover:border-blue-400 rounded-xl cursor-pointer transition-all flex items-center justify-between gap-3 shadow-2xs" ' +
                'data-plan-type="' + planTypeAttr + '" ' +
                'onclick="selectPlanningItem(' + parseInt(plan.id) + ', \'' + codeEsc + '\', \'' + descEsc + '\', \'' + custEsc + '\', ' + rawQty + ', \'' + cekEsc + '\', \'' + planTypeAttr + '\', ' + availSsQty + ')">' +
                '<div class="min-w-0 flex-1 space-y-1">' +
                    '<div class="flex items-center space-x-1.5 flex-wrap gap-y-1">' +
                        '<span class="font-mono font-black text-blue-700 text-xs">' + pCode + '</span>' +
                        '<span class="badge text-[9px] ' + (isSafetyStock ? 'bg-purple-100 text-purple-800 border border-purple-200 font-extrabold' : 'bg-blue-100 text-blue-800 border border-blue-200 font-bold') + '">' + pType + '</span>' +
                        pCekBadge +
                        ssBadge +
                        etaBadge +
                    '</div>' +
                    '<div class="font-bold text-slate-800 text-xs truncate">' + pDesc + '</div>' +
                    '<div class="text-[10px] text-slate-500 flex items-center space-x-2 flex-wrap">' +
                        '<span>Cust: <b>' + pCust + '</b></span>' +
                        '<span>&middot;</span>' +
                        '<span>Qty: <b>' + pQty + ' pcs</b></span>' +
                        kanbanNoBadge +
                    '</div>' +
                '</div>' +
                '<button type="button" class="btn-primary py-1.5 px-3 text-xs font-bold flex-shrink-0 shadow-2xs">Pilih &rarr;</button>' +
                '</div>';
    }

    container.innerHTML = html;
    filterPlanningItems();
}

function openScanModal() {
    resetScanModalState();
    refreshPlanningItemsAjax();
    var modal = document.getElementById('scan-modal-overlay');
    if (modal) {
        modal.classList.remove('hidden');
        modal.style.display = 'flex';
    }
    var qrInput = document.getElementById('scan-qr-raw');
    if (qrInput) {
        qrInput.value = '';
        setTimeout(function() { qrInput.focus(); }, 150);
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
    if (container && typeof OQC3DViewer === "function") {
        OQC3DViewer("cad-3d-viewport");
        is3dViewerInitialized = true;
    }
}

function adjustZoom(delta) {
    currentZoom = Math.min(2.0, Math.max(0.5, currentZoom + delta));
    var iframe = document.getElementById('pdf-frame');
    if (iframe) {
        iframe.style.transform = 'scale(' + currentZoom + ')';
    }
    document.getElementById('zoom-level-label').textContent = 'Zoom ' + Math.round(currentZoom * 100) + '%';
}

function resetZoom() {
    currentZoom = 1.0;
    var iframe = document.getElementById('pdf-frame');
    if (iframe) {
        iframe.style.transform = 'scale(1)';
    }
    document.getElementById('zoom-level-label').textContent = 'Zoom 100%';
}

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

function selectPlanningItem(id, itemCode, itemDesc, customer, qty, checkType, planType, availSsQty) {
    selectedPlanningId = id;
    selectedPlanningType = planType || 'kanban';
    selectedPlanningPartCode = itemCode || '';
    selectedPlanningQty = parseInt(qty) || 0;
    selectedAvailableSsQty = parseInt(availSsQty) || 0;

    document.getElementById('selected-plan-part-code').textContent = itemCode;
    document.getElementById('selected-plan-desc').textContent = itemDesc;
    document.getElementById('selected-plan-cust').textContent = customer;
    document.getElementById('selected-plan-qty').textContent = qty;
    
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

                // Pre-check checkboxes in order up to targetQty
                selectedSsSessionIds = [];
                var accumQty = 0;
                availableSsLots.forEach(function(lot) {
                    var lQty = parseInt(lot.available_qty) || 0;
                    if (accumQty < targetQty) {
                        selectedSsSessionIds.push(parseInt(lot.session_id));
                        accumQty += lQty;
                    }
                });

                isSafetyStockDeducted = (selectedSsSessionIds.length > 0);

                if (countInfo) countInfo.textContent = availableSsLots.length + ' Lot Tersedia';
            } else {
                availableSsLots = [];
                selectedSsSessionIds = [];
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
            if (container) container.innerHTML = '<div class="text-center py-2 text-rose-500 font-medium text-xs">Gagal memuat list Safety Stock.</div>';
            updateSafetyStockDeductionUI();
        });
}

function onSsLotCheckboxChange() {
    selectedSsSessionIds = [];
    var checkboxes = document.querySelectorAll('.ss-lot-checkbox:checked');
    checkboxes.forEach(function(cb) {
        selectedSsSessionIds.push(parseInt(cb.value));
    });
    isSafetyStockDeducted = (selectedSsSessionIds.length > 0);
    updateSafetyStockDeductionUI();
}

function toggleSafetyStockDeduction(forceState) {
    if (typeof forceState !== 'undefined') {
        isSafetyStockDeducted = !!forceState;
    } else {
        isSafetyStockDeducted = !isSafetyStockDeducted;
    }

    if (!isSafetyStockDeducted) {
        selectedSsSessionIds = [];
    } else {
        selectedSsSessionIds = [];
        var accumQty = 0;
        if (availableSsLots && availableSsLots.length > 0) {
            availableSsLots.forEach(function(lot) {
                var lQty = parseInt(lot.available_qty) || 0;
                if (accumQty < selectedPlanningQty) {
                    selectedSsSessionIds.push(parseInt(lot.session_id));
                    accumQty += lQty;
                }
            });
        }
        isSafetyStockDeducted = (selectedSsSessionIds.length > 0);
    }
    updateSafetyStockDeductionUI();
}

function updateSafetyStockDeductionUI() {
    var card = document.getElementById('safety-stock-deduction-card');
    if (!card) return;

    var totalCheckedQty = 0;
    if (availableSsLots && availableSsLots.length > 0 && selectedSsSessionIds.length > 0) {
        availableSsLots.forEach(function(lot) {
            if (selectedSsSessionIds.indexOf(parseInt(lot.session_id)) !== -1) {
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
                    document.getElementById('ss-deduct-badge').textContent = 'ALOKASI ' + usedQty.toLocaleString() + ' PCS (' + selectedSsSessionIds.length + ' LOT)';
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
        if (fullBanner) {
            if (remainQty === 0 && isSafetyStockDeducted) {
                fullBanner.classList.remove('hidden');
                fullBanner.style.display = 'block';
            } else {
                fullBanner.classList.add('hidden');
                fullBanner.style.display = 'none';
            }
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

    } else {
        card.classList.add('hidden');
        card.style.display = 'none';
    }
}

function renderSsLotRows() {
    var container = document.getElementById('ss-lots-checkbox-list');
    if (!container || !availableSsLots) return;

    var remainingTarget = selectedPlanningQty;
    var html = '';

    availableSsLots.forEach(function(lot, idx) {
        var sId = parseInt(lot.session_id);
        var lQty = parseInt(lot.available_qty) || 0;
        var isChecked = selectedSsSessionIds.indexOf(sId) !== -1;
        var refBadge = lot.ref_numbers ? ('<span class="bg-blue-100 text-blue-800 text-[10px] font-mono font-extrabold px-1.5 py-0.5 rounded border border-blue-200 truncate">Ref: ' + escapeHtml(lot.ref_numbers) + '</span>') : '';
        var lotNumStr = lot.lot_number ? escapeHtml(lot.lot_number) : '-';

        var statusBadgeHtml = '';
        if (isChecked) {
            var takenFromThisLot = Math.min(remainingTarget, lQty);
            if (takenFromThisLot > 0) {
                statusBadgeHtml = '<span class="text-emerald-800 font-extrabold bg-emerald-100 px-1.5 py-0.5 rounded border border-emerald-300">✓ Dipotong: ' + takenFromThisLot.toLocaleString() + ' pcs</span>';
                remainingTarget -= takenFromThisLot;
            } else {
                statusBadgeHtml = '<span class="text-slate-600 font-semibold bg-slate-100 px-1.5 py-0.5 rounded border border-slate-300">ℹ️ Stok Utuh (Tidak Dipotong)</span>';
            }
        } else {
            statusBadgeHtml = '<span class="text-slate-500 font-medium">Lot #' + (idx + 1) + '</span>';
        }

        html += '<label class="flex items-start space-x-2.5 p-2 bg-white rounded-lg border ' + (isChecked ? 'border-emerald-400 bg-emerald-50/30' : 'border-slate-200') + ' hover:border-emerald-500 cursor-pointer transition-all shadow-2xs">' +
                    '<input type="checkbox" value="' + sId + '" data-qty="' + lQty + '" ' + (isChecked ? 'checked' : '') + ' onchange="onSsLotCheckboxChange()" class="ss-lot-checkbox mt-0.5 rounded text-emerald-600 focus:ring-emerald-500 w-4 h-4 cursor-pointer">' +
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
    var lNum = 'SAFETY-STOCK';

    var totalCheckedQty = 0;
    availableSsLots.forEach(function(lot) {
        if (selectedSsSessionIds.indexOf(parseInt(lot.session_id)) !== -1) {
            totalCheckedQty += parseInt(lot.available_qty) || 0;
        }
    });
    var usedQty = Math.min(selectedPlanningQty, totalCheckedQty);

    document.getElementById('modal-loading').classList.remove('hidden');
    document.getElementById('modal-error-box').classList.add('hidden');

    var validateUrl = '<?= base_url("modules/inspection/scan_validate.php") ?>?part_code=' + encodeURIComponent(pCode) + '&lot_number=' + encodeURIComponent(lNum) + '&total_scanned_qty=' + usedQty + '&kanban_item_id=' + selectedPlanningId + '&inspection_type=' + encodeURIComponent(selectedPlanningType);

    fetch(validateUrl)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            executeCreateSession(data, pCode, lNum);
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
if (!['safety_stock', 'kanban'].includes(currentPlanTypeTab)) {
    currentPlanTypeTab = 'kanban';
}

function setPlanningTypeTab(type) {
    currentPlanTypeTab = type;
    if (type === 'safety_stock') {
        startDirectSafetyStockScan();
        return;
    }

    ['safety_stock', 'kanban'].forEach(function(t) {
        var btn = document.getElementById('tab-plan-' + t);
        if (!btn) return;
        if (t === type) {
            btn.style.cssText = 'background-color: #2563eb !important; color: #ffffff !important; border: 1px solid #1d4ed8 !important; font-weight: 800; padding: 5px 12px; border-radius: 8px; font-size: 11px; cursor: pointer; box-shadow: 0 2px 4px rgba(37,99,235,0.3);';
        } else {
            btn.style.cssText = 'background-color: #f1f5f9 !important; color: #475569 !important; border: 1px solid #cbd5e1 !important; font-weight: 700; padding: 5px 12px; border-radius: 8px; font-size: 11px; cursor: pointer; box-shadow: none;';
        }
    });
    filterPlanningItems();
}

function filterPlanningItems() {
    var query = document.getElementById('planning-search-input') ? document.getElementById('planning-search-input').value.toLowerCase().trim() : '';
    var container = document.getElementById('planning-cards-container');
    if (!container) return;
    var cards = container.querySelectorAll('.planning-card');
    
    for (var i = 0; i < cards.length; i++) {
        var text = cards[i].textContent.toLowerCase();
        var cardType = cards[i].getAttribute('data-plan-type') || 'kanban';
        
        var matchesSearch = (query === '' || text.indexOf(query) !== -1);
        var matchesTab = (cardType === currentPlanTypeTab);
        
        if (matchesSearch && matchesTab) {
            cards[i].style.display = 'flex';
        } else {
            cards[i].style.display = 'none';
        }
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

            var s = data.session;
            var ngRecords = data.ng_records || [];
            var custName = s.customer || 'PT. Indonesia Epson Industry';



            // Update Card 1 Customer Name
            var cardCustEl = document.getElementById('card-customer-name');
            if (cardCustEl) {
                cardCustEl.innerHTML = '🏢 ' + escapeHtml(custName);
            }

            if (document.getElementById('card-part-code')) document.getElementById('card-part-code').textContent = s.part_code || '-';
            
            var totScanned = parseInt(s.total_scanned_qty || 0);
            var kbTarget = parseInt(s.kanban_qty || 0);
            var usedSsQty = parseInt(s.use_safety_stock_qty || 0);
            var excQty = parseInt(s.excess_qty || 0);
            var isKanban = (s.inspection_type || 'kanban') === 'kanban';

            var cardQtyEl = document.getElementById('card-total-qty');
            var cardBadgeEl = document.getElementById('card-qty-subbadge');

            if (cardQtyEl) {
                if (isKanban) {
                    var targetDisp = (kbTarget > 0) ? kbTarget : Math.max(500, totScanned);
                    cardQtyEl.textContent = totScanned.toLocaleString() + ' / ' + targetDisp.toLocaleString() + ' pcs';
                } else {
                    cardQtyEl.textContent = totScanned.toLocaleString() + ' pcs';
                }
            }

            if (cardBadgeEl) {
                if (isKanban) {
                    var physQty = Math.max(0, totScanned - usedSsQty);
                    if (usedSsQty > 0 && totScanned >= kbTarget && physQty === 0) {
                        cardBadgeEl.innerHTML = '<span class="text-[8.5px] font-bold text-emerald-800 bg-emerald-50 border border-emerald-200 px-1 py-0.2 rounded block mt-0.5" title="Seluruh Qty target Kanban dipenuhi dari Safety Stock">✨ 100% Safety Stock (' + usedSsQty.toLocaleString() + ' pcs)</span>';
                    } else if (usedSsQty > 0) {
                        cardBadgeEl.innerHTML = '<span class="text-[8.5px] font-bold text-blue-800 bg-blue-50 border border-blue-200 px-1 py-0.2 rounded block mt-0.5" title="Kombinasi Scan Fisik & Alokasi Safety Stock">📦 ' + physQty.toLocaleString() + ' Fisik + ' + usedSsQty.toLocaleString() + ' Safety Stock</span>';
                    } else if (excQty > 0) {
                        cardBadgeEl.innerHTML = '<span class="text-[8.5px] font-bold text-purple-700 bg-purple-50 border border-purple-200 px-1 py-0.2 rounded block mt-0.5" title="Sisa kelebihan Qty akan otomatis disimpan ke Safety Stock saat PASSED">📦 +' + excQty.toLocaleString() + ' pcs Kelebihan → Safety Stock</span>';
                    } else {
                        cardBadgeEl.innerHTML = '';
                    }
                } else {
                    cardBadgeEl.innerHTML = '';
                }
            }

            // Update Card 2 Acuan Sampling Wajib
            if (document.getElementById('card-spl-code')) document.getElementById('card-spl-code').textContent = s.sample_code || 'H';
            if (document.getElementById('card-sample-size')) document.getElementById('card-sample-size').innerHTML = (s.sample_size || 0).toLocaleString() + ' <span style="font-size: 12px; font-weight: 400;">pcs</span>';
            
            var cardExpEl = document.getElementById('card-aql-explanation');
            if (cardExpEl) {
                var pQty = Math.max(0, totScanned - usedSsQty);
                if (usedSsQty > 0) {
                    if (pQty > 0) {
                        cardExpEl.textContent = 'ℹ️ dari ' + pQty.toLocaleString() + ' pcs fisik baru (' + usedSsQty.toLocaleString() + ' pcs SS Passed)';
                    } else {
                        cardExpEl.textContent = '✨ 100% dipenuhi dari Safety Stock';
                    }
                } else {
                    cardExpEl.textContent = 'wajib diperiksa';
                }
            }

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
            if (document.getElementById('modal-kb-str-loc')) document.getElementById('modal-kb-str-loc').textContent = s.kanban_str_loc || 'WH-A01';
            if (document.getElementById('modal-kb-check-type')) document.getElementById('modal-kb-check-type').textContent = s.kanban_check_type ? (s.kanban_check_type + ' Cek') : 'Normal Cek';
            if (document.getElementById('modal-kb-remark')) document.getElementById('modal-kb-remark').textContent = s.kanban_remark || '-';

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

                        lotsHtml += '<tr class="hover:bg-indigo-50/50 transition-colors">' +
                                    '<td class="px-2.5 py-1.5 font-bold text-slate-400">' + (idx + 1) + '</td>' +
                                    '<td class="px-2.5 py-1.5 font-mono font-bold text-blue-800 text-[10px]"><span class="bg-blue-50 px-1.5 py-0.5 rounded border border-blue-200">' + lRef + '</span></td>' +
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

            if (document.getElementById('card-spl-code')) document.getElementById('card-spl-code').textContent = (s.sample_code || 'H');
            if (document.getElementById('card-aql-level-badge')) document.getElementById('card-aql-level-badge').textContent = 'LEVEL ' + (s.aql_level || s.part_aql_level || 'G-II');
            if (document.getElementById('card-sample-size')) document.getElementById('card-sample-size').innerHTML = s.sample_size + ' <span style="font-size: 12px; font-weight: 400;">pcs</span>';

            if (document.getElementById('label-sample-progress')) document.getElementById('label-sample-progress').textContent = s.samples_checked + ' / ' + s.sample_size;
            if (document.getElementById('label-ng-progress')) document.getElementById('label-ng-progress').textContent = s.ng_count + ' / ' + s.reject_number + ' batas';

            var nextSampleNum = Math.min(parseInt(s.sample_size || 0), parseInt(s.samples_checked || 0) + 1);
            if (document.getElementById('inline-sample-target-label')) {
                document.getElementById('inline-sample-target-label').textContent = 'Sample ke-' + nextSampleNum;
            }

            var samplePct = (s.sample_size > 0) ? Math.min(100, Math.round((s.samples_checked / s.sample_size) * 100)) : 0;
            if (document.getElementById('bar-sample-progress')) document.getElementById('bar-sample-progress').style.width = samplePct + '%';

            var ngPct = (s.reject_number > 0) ? Math.min(100, Math.round((s.ng_count / s.reject_number) * 100)) : 0;
            if (document.getElementById('bar-ng-progress')) document.getElementById('bar-ng-progress').style.width = ngPct + '%';

            renderNgHistoryWidget(ngRecords);

            // Global State & Real-Time Lot State (Selalu di-update untuk semua status)
            var ngLotsData = data.ng_lots || [];
            currentNgLots = ngLotsData;
            currentSessionLots = data.session_lots || [];
            if (data.session && data.session.part_code) {
                currentSessionPartCode = data.session.part_code;
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

            // Card 4: Switch Active Buttons vs Finished Banner
            var finishedBanner = document.getElementById('finished-session-banner');
            var activeBtns = document.getElementById('active-inspection-btns');

            if (s.status !== 'in_progress') {
                if (finishedBanner) {
                    finishedBanner.classList.remove('hidden');
                    finishedBanner.style.display = 'block';
                }
                if (activeBtns) {
                    activeBtns.classList.add('hidden');
                    activeBtns.style.display = 'none';
                }

                var badge = document.getElementById('finished-status-badge');
                if (badge) {
                    badge.textContent = 'STATUS: ' + s.status.toUpperCase();
                    badge.className = 'text-[10px] font-extrabold uppercase text-white px-2.5 py-0.5 rounded-full inline-block shadow-2xs ' + (s.status === 'passed' ? 'bg-emerald-600' : 'bg-rose-600');
                }

                var printWrap = document.getElementById('finished-rejection-btn-wrap');
                var printLink = document.getElementById('finished-rejection-link');
                if (s.status === 'rejected') {
                    if (printWrap) {
                        printWrap.classList.remove('hidden');
                        printWrap.style.display = 'block';
                    }
                    if (printLink) printLink.href = '<?= base_url("modules/inspection/print_rejection.php?session_id=") ?>' + s.id;
                } else {
                    if (printWrap) {
                        printWrap.classList.add('hidden');
                        printWrap.style.display = 'none';
                    }
                }

                var finishedUndoWrap = document.getElementById('finished-undo-wrap');
                var finishedUndoText = document.getElementById('finished-undo-text');
                if (s.samples_checked > 0) {
                    if (finishedUndoWrap) {
                        finishedUndoWrap.classList.remove('hidden');
                        finishedUndoWrap.style.display = 'block';
                    }
                    if (finishedUndoText) finishedUndoText.textContent = 'Salah Input? Undo Sample Terakhir (#' + s.samples_checked + ')';
                } else {
                    if (finishedUndoWrap) {
                        finishedUndoWrap.classList.add('hidden');
                        finishedUndoWrap.style.display = 'none';
                    }
                }
            } else {
                if (finishedBanner) {
                    finishedBanner.classList.add('hidden');
                    finishedBanner.style.display = 'none';
                }
                if (activeBtns) {
                    activeBtns.classList.remove('hidden');
                    activeBtns.style.display = 'block';
                }

                var activeUndoWrap = document.getElementById('active-undo-wrap');
                var activeUndoText = document.getElementById('active-undo-text');
                if (s.samples_checked > 0) {
                    if (activeUndoWrap) {
                        activeUndoWrap.classList.remove('hidden');
                        activeUndoWrap.style.display = 'block';
                    }
                    if (activeUndoText) activeUndoText.textContent = 'Undo Sample Terakhir (#' + s.samples_checked + ')';
                } else {
                    if (activeUndoWrap) {
                        activeUndoWrap.classList.add('hidden');
                        activeUndoWrap.style.display = 'none';
                    }
                }

                var remainingPcs = Math.max(0, parseInt(s.sample_size) - parseInt(s.samples_checked));
                var btnBulk = document.getElementById('btn-bulk-ok');
                var labelBulk = document.getElementById('btn-bulk-ok-label');
                if (btnBulk && labelBulk) {
                    labelBulk.textContent = 'Selesaikan Sisa Sample OK (' + remainingPcs + ' Pcs)';
                    btnBulk.setAttribute('onclick', 'submitBulkOk(' + remainingPcs + ')');
                    btnBulk.style.display = (remainingPcs > 0) ? 'flex' : 'none';
                }
            }

            var pdfFrame = document.getElementById('pdf-frame');
            var placeholder = document.getElementById('drawing-placeholder-box');
            if (s.drawing_2d) {
                if (pdfFrame) pdfFrame.src = s.drawing_2d + '#toolbar=0';
                if (placeholder) placeholder.style.display = 'none';
            } else {
                if (pdfFrame) pdfFrame.src = '';
                if (placeholder) placeholder.style.display = 'flex';
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
    var pCode = document.getElementById('scan-part-code').value.trim();
    var lNum = document.getElementById('scan-lot-number').value.trim();

    if (scannedLabelsList.length > 0) {
        var lastLbl = scannedLabelsList[scannedLabelsList.length - 1];
        if (!pCode) pCode = lastLbl.Z1;
        if (!lNum) lNum = lastLbl.Z2;
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
    if (totalScanned === 0) totalScanned = parseInt(document.getElementById('scan-label-qty').value) || 500;

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
    if (typeof scannedLabelsList !== 'undefined' && scannedLabelsList.length > 0) {
        scannedLabelsList.forEach(function(l) {
            if (l.Z5) scannedRefList.push(l.Z5);
        });
    }
    var refVal = document.getElementById('scan-ref-number') ? document.getElementById('scan-ref-number').value.trim() : '';
    if (refVal && scannedRefList.indexOf(refVal) === -1) {
        scannedRefList.push(refVal);
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
    document.getElementById('modal-loading').classList.remove('hidden');

    var totalScanned = 0;
    scannedLabelsList.forEach(function(l) { totalScanned += parseInt(l.Z3) || 0; });
    
    var totalCheckedQty = 0;
    availableSsLots.forEach(function(lot) {
        if (selectedSsSessionIds.indexOf(parseInt(lot.session_id)) !== -1) {
            totalCheckedQty += parseInt(lot.available_qty) || 0;
        }
    });
    var usedSsQty = (isSafetyStockDeducted && selectedSsSessionIds.length > 0) ? Math.min(selectedPlanningQty, totalCheckedQty) : 0;

    // Fix: Only apply fallback default scanned qty if NO safety stock was used AND no labels were scanned
    if (totalScanned === 0 && usedSsQty === 0) {
        totalScanned = data.aql ? data.aql.total_qty : 500;
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
    payload.append('sample_size', data.aql ? data.aql.sample_size : 50);
    payload.append('reject_number', data.aql ? data.aql.reject_number : 1);
    payload.append('total_scanned_qty', totalScanned + usedSsQty);
    payload.append('excess_qty', excessQty);
    payload.append('use_safety_stock_qty', usedSsQty);
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

function submitSampleResult(result) {
    if (!currentSessionId) return;

    var payload = new FormData();
    payload.append('session_id', currentSessionId);
    payload.append('result', result);

    if (result === 'NG') {
        var lotSelect = document.getElementById('ng-session-lot-select');
        var sessionLotId = lotSelect ? lotSelect.value : '';

        if (lotSelect && lotSelect.options.length > 1 && !sessionLotId) {
            Swal.fire({
                icon: 'warning',
                title: 'Pilih Box / Lot Number Produksi 📦',
                text: 'Harap pilih Box / Lot Number tempat ditemukannya sampel NG pada dropdown teratas!'
            });
            return;
        }

        if (sessionLotId) {
            payload.append('session_lot_id', sessionLotId);
        }

        var rows = document.querySelectorAll('.ng-defect-row');
        var defectAddedCount = 0;
        var selectedTypes = [];

        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            var selectDefect = r.querySelector('.ng-defect-type').value;
            var customNameInput = r.querySelector('.ng-custom-defect-name');
            var customName = customNameInput ? customNameInput.value.trim() : '';
            var qtyNgInput = r.querySelector('.ng-qty');
            var qtyNg = qtyNgInput ? qtyNgInput.value : 1;

            if (!selectDefect) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Pilih Defect',
                    text: 'Silakan pilih Jenis Defect untuk Defect #' + (i + 1) + '!'
                });
                return;
            }

            var defectKey = (selectDefect === 'custom') ? ('custom:' + customName.toLowerCase()) : selectDefect;
            if (selectedTypes.indexOf(defectKey) !== -1) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Defect Ganda Terdeteksi! ⚠️',
                    text: 'Jenis Defect yang sama sudah dipilih di baris sebelumnya. Silakan tambahkan nilai "Qty NG" pada baris pertama atau pilih jenis defect lain yang berbeda!'
                });
                return;
            }
            selectedTypes.push(defectKey);

            payload.append('defects[' + defectAddedCount + '][defect_type_id]', (selectDefect === 'custom') ? 0 : selectDefect);
            payload.append('defects[' + defectAddedCount + '][custom_name]', customName);
            payload.append('defects[' + defectAddedCount + '][qty_ng]', qtyNg);
            defectAddedCount++;
        }

        if (defectAddedCount === 0) {
            Swal.fire({
                icon: 'warning',
                title: 'Defect Kosong',
                text: 'Silakan isi minimal 1 Jenis Defect!'
            });
            return;
        }
    }

    fetch('<?= base_url("modules/inspection/process_sample.php") ?>', {
        method: 'POST',
        body: payload
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        toggleInlineNgForm(false);

        if (!data.success) {
            Swal.fire({
                icon: 'error',
                title: 'Gagal',
                text: data.message || 'Terjadi kesalahan'
            });
            return;
        }

        document.getElementById('label-sample-progress').textContent = data.current_sample + ' / ' + data.max_sample;
        document.getElementById('label-ng-progress').textContent = data.ng_count + ' / ' + data.reject_limit + ' batas';

        var nextSampleNum = Math.min(parseInt(data.max_sample || 0), parseInt(data.current_sample || 0) + 1);
        if (document.getElementById('inline-sample-target-label')) {
            document.getElementById('inline-sample-target-label').textContent = 'Sample ke-' + nextSampleNum;
        }

        var samplePct = Math.min(100, Math.round((data.current_sample / data.max_sample) * 100));
        document.getElementById('bar-sample-progress').style.width = samplePct + '%';

        var ngPct = Math.min(100, Math.round((data.ng_count / data.reject_limit) * 100));
        document.getElementById('bar-ng-progress').style.width = ngPct + '%';

        renderNgHistoryWidget(data.ng_records);

        var activeUndoWrap = document.getElementById('active-undo-wrap');
        var activeUndoText = document.getElementById('active-undo-text');
        if (data.current_sample > 0) {
            if (activeUndoWrap) {
                activeUndoWrap.classList.remove('hidden');
                activeUndoWrap.style.display = 'block';
            }
            if (activeUndoText) activeUndoText.textContent = 'Undo Sample Terakhir (#' + data.current_sample + ')';
        }

        var remainingPcs = Math.max(0, parseInt(data.max_sample) - parseInt(data.current_sample));
        var btnBulk = document.getElementById('btn-bulk-ok');
        var labelBulk = document.getElementById('btn-bulk-ok-label');
        if (btnBulk && labelBulk) {
            labelBulk.textContent = 'Selesaikan Sisa Sample OK (' + remainingPcs + ' Pcs)';
            btnBulk.setAttribute('onclick', 'submitBulkOk(' + remainingPcs + ')');
            if (remainingPcs <= 0) {
                btnBulk.style.display = 'none';
            }
        }

        if (data.is_finished) {
            if (data.status === 'rejected') {
                Swal.fire({
                    icon: 'error',
                    title: 'LOT DI-REJECT!',
                    text: 'Jumlah NG telah mencapai limit ' + data.reject_limit + ' pcs. Rejection Sheet akan dibuka untuk dicetak.',
                    confirmButtonColor: '#e11d48',
                    confirmButtonText: 'Cetak Rejection Sheet 🖨️'
                }).then(function() {
                    window.open('<?= base_url("modules/inspection/print_rejection.php?session_id=") ?>' + currentSessionId + '&autoprint=1', '_blank', 'noopener,noreferrer');
                });
            } else if (data.status === 'passed') {
                Swal.fire({
                    icon: 'success',
                    title: '🎉 SESI INSPEKSI SELESAI!',
                    text: 'Lot dinyatakan PASSED (Lolos OQC & Siap Packing).',
                    confirmButtonColor: '#059669',
                    confirmButtonText: 'Mantap!'
                });
            }
            loadWorkbenchSessionData(currentSessionId);
        }
    })
    .catch(function(err) {
        toggleInlineNgForm(false);
        Swal.fire({
            icon: 'error',
            title: 'Koneksi Terputus',
            text: 'Gagal terhubung ke server!'
        });
    });
}

function renderNgHistoryWidget(records) {
    var container = document.getElementById('ng-history-container');
    if (!records || records.length === 0) {
        container.innerHTML = '<p id="ng-empty-msg" class="text-[11px] text-slate-400 text-center py-4">Belum ada defect tercatat pada lot ini.</p>';
        return;
    }

    var html = '';
    for (var i = 0; i < records.length; i++) {
        var r = records[i];
        var lotBadge = '';
        if (r.lot_number || r.ref_number) {
            var refStr = r.ref_number ? (' (Ref: ' + escapeHtml(r.ref_number) + ')') : '';
            lotBadge = '<span style="font-size: 9px; font-weight: 700; color: #1e40af; background-color: #eff6ff; padding: 1px 5px; border-radius: 4px; border: 1px solid #bfdbfe; font-family: monospace; display: inline-block; margin-top: 2px;">📦 Lot: #' + escapeHtml(r.lot_number || '-') + refStr + '</span>';
        }

        html += '<div style="display: flex; align-items: center; justify-content: space-between; padding: 8px 10px; background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; font-size: 12px; gap: 8px; margin-bottom: 6px;">' +
            '<div style="min-width: 0; flex: 1;">' +
                '<span style="font-weight: 800; color: #be123c; font-size: 11px; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">#' + r.sample_number + ' ' + escapeHtml(r.defect_name) + '</span>' +
                '<span style="font-size: 10px; color: #64748b; display: block;">Qty ' + r.qty_ng + ' &middot; Sample ke-' + r.sample_number + '</span>' +
                lotBadge +
            '</div>' +
            '<button type="button" onclick="deleteNgRecord(' + r.id + ')" style="flex-shrink: 0; background-color: #fff1f2; color: #e11d48; border: 1px solid #fecdd3; padding: 3px 8px; border-radius: 6px; font-size: 10px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; line-height: 1;" title="Batalkan defect ini">' +
                '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display: inline-block; vertical-align: middle; flex-shrink: 0;">' +
                    '<polyline points="3 6 5 6 21 6"></polyline>' +
                    '<path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>' +
                '</svg>' +
                '<span>Batal</span>' +
            '</button>' +
        '</div>';
    }
    container.innerHTML = html;
}

function deleteNgRecord(recordId) {
    if (!recordId) return;

    // Hapus overlay lama jika ada
    var old = document.getElementById('_ng_cancel_overlay');
    if (old) old.remove();

    // Buat custom overlay
    var overlay = document.createElement('div');
    overlay.id = '_ng_cancel_overlay';
    overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(15,23,42,0.65);z-index:99999;display:flex;align-items:center;justify-content:center;animation:fadeIn .15s ease;';
    overlay.innerHTML =
        '<div style="background:#fff;border-radius:16px;padding:28px 24px 20px;max-width:440px;width:92%;box-shadow:0 24px 60px rgba(0,0,0,0.25);position:relative;">' +
            '<div style="font-size:28px;text-align:center;margin-bottom:6px;">🗑️</div>' +
            '<h3 style="font-size:15px;font-weight:700;color:#1e293b;margin:0 0 6px;text-align:center;">Batalkan Catatan Defect NG?</h3>' +
            '<p style="font-size:13px;color:#64748b;margin:0 0 14px;text-align:center;">Berikan alasan pembatalan untuk dicatat di <b>Audit Log QC</b>:</p>' +
            '<textarea id="_ng_reason_ta" rows="3" style="width:100%;padding:10px 12px;font-size:13px;line-height:1.5;border:1.5px solid #cbd5e1;border-radius:10px;box-sizing:border-box;resize:none;outline:none;font-family:inherit;transition:border-color .2s;" placeholder="Contoh: salah pilih jenis defect, re-check sample lolos spec..."></textarea>' +
            '<div id="_ng_err" style="color:#e11d48;font-size:12px;margin-top:4px;display:none;"></div>' +
            '<div style="display:flex;gap:8px;margin-top:16px;">' +
                '<button id="_ng_cancel_btn" style="flex:1;padding:10px;border-radius:10px;border:1.5px solid #e2e8f0;background:#fff;color:#64748b;font-size:13px;font-weight:600;cursor:pointer;">Kembali</button>' +
                '<button id="_ng_submit_btn" style="flex:2;padding:10px;border-radius:10px;border:none;background:#e11d48;color:#fff;font-size:13px;font-weight:700;cursor:pointer;">Simpan &amp; Batalkan NG</button>' +
            '</div>' +
        '</div>';

    document.body.appendChild(overlay);

    var ta      = document.getElementById('_ng_reason_ta');
    var err     = document.getElementById('_ng_err');
    var cancelB = document.getElementById('_ng_cancel_btn');
    var submitB = document.getElementById('_ng_submit_btn');

    ta.focus();

    // Style focus textarea
    ta.addEventListener('focus', function() { this.style.borderColor = '#e11d48'; });
    ta.addEventListener('blur',  function() { this.style.borderColor = '#cbd5e1'; });

    // Tutup overlay
    function closeOverlay() { overlay.remove(); }
    cancelB.addEventListener('click', closeOverlay);
    overlay.addEventListener('click', function(e) { if (e.target === overlay) closeOverlay(); });

    // Submit
    submitB.addEventListener('click', function() {
        var reason = ta.value.trim();
        if (!reason) {
            err.textContent = 'Alasan pembatalan wajib diisi!';
            err.style.display = 'block';
            ta.focus();
            return;
        }
        err.style.display = 'none';

        submitB.disabled    = true;
        submitB.textContent = 'Menyimpan...';

        var fd = new FormData();
        fd.append('action',        'delete_ng_record');
        fd.append('record_id',     recordId);
        fd.append('session_id',    currentSessionId || 0);
        fd.append('cancel_reason', reason);

        fetch('<?= base_url("modules/inspection/undo_ng.php") ?>', {
            method: 'POST',
            body:   fd
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            closeOverlay();
            if (!d.success) {
                Swal.fire('Gagal!', d.message || 'Terjadi kesalahan', 'error');
                return;
            }
            if (d.session_id)              currentSessionId = d.session_id;
            if (d.ng_records !== undefined) renderNgHistoryWidget(d.ng_records);
            if (currentSessionId)           loadWorkbenchSessionData(currentSessionId);
            Swal.fire({ icon: 'success', title: 'Pembatalan Dicatat! 📝', text: 'Tersimpan di Audit Log.', timer: 1800, showConfirmButton: false });
        })
        .catch(function() {
            submitB.disabled    = false;
            submitB.textContent = 'Simpan & Batalkan NG';
            err.textContent     = 'Gagal koneksi ke server!';
            err.style.display   = 'block';
        });
    });
}

function undoLastSample() {
    if (!currentSessionId) return;

    Swal.fire({
        title: 'Undo Sample Terakhir?',
        text: 'Pemeriksaan sample terakhir akan dihapus & counter sample dikurangi 1.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#2563eb',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Ya, Undo Sample!',
        cancelButtonText: 'Batal'
    }).then(function(res) {
        if (res.isConfirmed) {
            var payload = new FormData();
            payload.append('action', 'undo_last_sample');
            payload.append('session_id', currentSessionId);

            fetch('<?= base_url("modules/inspection/undo_ng.php") ?>', {
                method: 'POST',
                body: payload
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) {
                    Swal.fire('Gagal!', data.message, 'error');
                    return;
                }
                Swal.fire('Berhasil!', 'Sample terakhir berhasil dibatalkan.', 'success').then(function() {
                    loadWorkbenchSessionData(currentSessionId);
                });
            })
            .catch(function(err) {
                Swal.fire('Error!', 'Gagal terhubung ke server', 'error');
            });
        }
    });
}

function submitBulkOk(remainingPcs) {
    var labelText = document.getElementById('label-sample-progress') ? document.getElementById('label-sample-progress').textContent : '';
    var match = labelText.match(/(\d+)\s*\/\s*(\d+)/);
    if (match) {
        var currentChecked = parseInt(match[1], 10);
        var totalSize = parseInt(match[2], 10);
        remainingPcs = Math.max(0, totalSize - currentChecked);
    }

    if (!currentSessionId || remainingPcs <= 0) return;

    Swal.fire({
        title: 'Loloskan Seluruh Sisa Sample?',
        html: '<b>' + remainingPcs + ' pcs sample</b> sisanya akan otomatis ditandai sebagai <b>OK</b> dan sesi inspeksi akan dinyatakan <b>PASSED</b>.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#059669',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Ya, Loloskan Semua OK! 🚀',
        cancelButtonText: 'Batal'
    }).then(function(res) {
        if (res.isConfirmed) {
            var payload = new FormData();
            payload.append('session_id', currentSessionId);
            payload.append('result', 'BULK_OK');

            fetch('<?= base_url("modules/inspection/process_sample.php") ?>', {
                method: 'POST',
                body: payload
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) {
                    Swal.fire('Gagal!', data.message, 'error');
                    return;
                }
                Swal.fire('🎉 Selesai & PASSED!', data.message || 'Seluruh sample dinyatakan PASSED!', 'success').then(function() {
                    loadWorkbenchSessionData(currentSessionId);
                });
            })
            .catch(function(err) {
                Swal.fire('Error!', 'Gagal terhubung ke server', 'error');
            });
        }
    });
}



function escapeHtml(text) {
    if (!text) return '';
    return String(text).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

// ═══════════════════════════════════════════════════════════════════════════
// BATCH RE-INSPECTION MODAL HANDLERS
// ═══════════════════════════════════════════════════════════════════════════
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
                statusBadge = '<span style="font-size:9px; color:#e11d48; font-weight:700;">🔴 Lot NG</span>';
            }
        } else {
            cardStyle = 'background-color: #ffffff; border: 1px solid #e2e8f0;';
            statusBadge = '<span style="display:inline-block; background-color:#dcfce7; color:#15803d; border:1px solid #bbf7d0; border-radius:4px; padding:1px 6px; font-size:9px; font-weight:800;">✅ OK (Normal)</span>';
        }

        html += '<div style="' + cardStyle + ' border-radius: 8px; padding: 6px 10px; display: flex; align-items: center; justify-content: space-between; font-size: 12px; box-shadow: 0 1px 2px rgba(0,0,0,0.02);">' +
                    '<div>' +
                        '<div style="font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 4px;">' +
                            '<span>📦 ' + escapeHtml(lotNo) + '</span>' +
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
        if (btnSubmit) btnSubmit.innerHTML = '<span>🚀 Mulai Sesi Re-Inspeksi Baru</span>';
        
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
        if (btnSubmit) btnSubmit.innerHTML = '<span>Validasi Matching &amp; Muat Inspeksi</span>';

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

    // Live sync manual input fields for visual feedback
    if (parsed.Z1 && document.getElementById('batch-input-z1')) document.getElementById('batch-input-z1').value = parsed.Z1;
    if (parsed.Z2 && document.getElementById('batch-input-z2')) document.getElementById('batch-input-z2').value = parsed.Z2;
    if (parsed.Z3 && document.getElementById('batch-input-z3')) document.getElementById('batch-input-z3').value = parsed.Z3;
    if (parsed.Z5 && document.getElementById('batch-input-z5')) document.getElementById('batch-input-z5').value = parsed.Z5;

    var z1 = (parsed && parsed.Z1) ? parsed.Z1 : (currentSessionPartCode || '');
    var z2 = (parsed && parsed.Z2) ? parsed.Z2 : '';
    var z3 = (parsed && parsed.Z3) ? parseInt(parsed.Z3) : 0;
    var z5 = (parsed && parsed.Z5) ? parsed.Z5 : '';

    // HANYA SUBMIT jika user/scanner menekan ENTER (forceProcess === true) atau terdapat Newline (\n / \r) dari hardware scanner
    var hasNewline = (raw.includes('\n') || raw.includes('\r'));

    if (forceProcess || hasNewline) {
        if (!z2 || z3 <= 0) {
            Swal.fire('Perhatian', 'Format Barcode QR tidak valid! (Lot Number & Qty wajib terbaca)', 'warning');
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


</script>

<!-- Offline Vendor Libraries & App Scripts -->
<script src="<?= base_url('assets/js/vendor/jquery.min.js') ?>"></script>
<script src="<?= base_url('assets/js/vendor/sweetalert2.all.min.js') ?>"></script>
<script src="<?= base_url('assets/js/vendor/three.min.js') ?>"></script>
<script src="<?= base_url('assets/js/app.js') ?>"></script>


</body>
</html>
