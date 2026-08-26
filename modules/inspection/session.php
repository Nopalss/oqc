<?php
/**
 * Redesigned Unified OQC Inspection Workbench (FR-3 & FR-6)
 * 100% Full-Screen, Zero-Scroll, All-in-One 3-Stage Workbench Matching Client Mockup
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

$sessionId = (int)($_GET['id'] ?? 0);
$autoScanNew = isset($_GET['scan_new']) && $_GET['scan_new'] == 1;

$pdo = getDB();
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

    // Fetch Active Planning Items for 2-Step Inspection Modal
    $activePlanningItems = [];
    try {
        $stmtPlan = $pdo->query("
            SELECT k.*, b.document_number, b.vendor, b.plan_type as batch_plan_type
            FROM kanban_items k
            JOIN kanban_batches b ON b.id = k.batch_id
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

            if ($session) {
                $qty = (int)($session['kanban_qty'] ?? 500);
                $stmtAql = $pdo->prepare("SELECT sample_code, accept_number FROM aql_standards WHERE :qty BETWEEN qty_min AND qty_max LIMIT 1");
                $stmtAql->execute([':qty' => $qty]);
                $aqlExtra = $stmtAql->fetch(PDO::FETCH_ASSOC);
                $session['sample_code'] = $aqlExtra['sample_code'] ?? 'H';
                $session['accept_number'] = (int)($aqlExtra['accept_number'] ?? 0);

                $stmtNg = $pdo->prepare("
                    SELECT n.*, dt.name as defect_name, sp.sample_number
                    FROM inspection_ng_records n
                    JOIN inspection_samples sp ON sp.id = n.inspection_sample_id
                    JOIN defect_types dt ON dt.id = n.defect_type_id
                    WHERE sp.inspection_session_id = :sid
                    ORDER BY n.id DESC
                ");
                $stmtNg->execute([':sid' => $sessionId]);
                $ngRecords = $stmtNg->fetchAll(PDO::FETCH_ASSOC);

                // Fetch previous inspection sessions for the same part_code
                if (!empty($session['part_code'])) {
                    $stmtPrev = $pdo->prepare("
                        SELECT s.id, s.started_at, s.status, s.samples_checked, s.sample_size, s.ng_count, s.reject_number,
                               did.lot_number, did.cavity, did.pic as did_pic, u.name as inspector_name
                        FROM inspection_sessions s
                        JOIN daily_inspection_data did ON did.id = s.did_id
                        LEFT JOIN users u ON u.id = s.inspector_id
                        WHERE UPPER(did.part_code) = UPPER(:pcode) AND s.id != :curr_id
                        ORDER BY s.id DESC
                        LIMIT 5
                    ");
                    $stmtPrev->execute([
                        ':pcode' => $session['part_code'],
                        ':curr_id' => $session['id']
                    ]);
                    $previousSessions = $stmtPrev->fetchAll(PDO::FETCH_ASSOC);
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
                    <?php if ($session): ?>
                        <span>Inspeksi Barang — <span class="font-mono text-blue-700 font-black"><?= htmlspecialchars($session['part_code']) ?></span>
                        <span class="font-semibold text-slate-600 text-xs hidden sm:inline"> (<?= htmlspecialchars($session['part_name']) ?>)</span></span>
                        <span id="header-customer-badge" class="inline-flex items-center px-2 py-0.5 rounded-md text-[11px] font-extrabold bg-indigo-50 text-indigo-700 border border-indigo-200/80 shadow-2xs ml-2 flex-shrink-0">
                            🏢 <?= htmlspecialchars($session['customer'] ?? 'PT. Indonesia Epson Industry') ?>
                        </span>
                    <?php else: ?>
                        Workbench Scan & Inspeksi OQC
                    <?php endif; ?>
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
                <button type="button" onclick="openPrevHistoryModal()" class="btn-secondary py-1.5 px-3 text-xs font-bold flex items-center shadow-xs text-slate-700 hover:bg-slate-100" title="Lihat Riwayat Sesi Inspeksi Lot-Lot Sebelumnya untuk Part Ini">
                    <svg class="w-3.5 h-3.5 mr-1.5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <span>Riwayat Lot Part (<?= count($previousSessions) ?>)</span>
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
                <div style="position: absolute; bottom: 12px; left: 12px; right: 12px; display: flex; align-items: center; justify-content: space-between; font-size: 11px; color: #94a3b8; background-color: rgba(15, 23, 42, 0.85); padding: 6px 12px; border-radius: 8px; z-index: 20; pointer-events: none; border: 1px solid rgba(51, 65, 85, 0.5);">
                    <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 600; color: #e2e8f0;"><?= $session ? htmlspecialchars($session['part_code'] . ' — ' . $session['part_name']) : 'No Part Active' ?></span>
                    <span id="zoom-level-label" style="font-family: monospace; color: #94a3b8; margin-left: 12px; flex-shrink: 0;">Zoom 100%</span>
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
                    <button type="button" onclick="openKanbanDetailModal()" class="px-2 py-0.5 bg-blue-50 hover:bg-blue-100 text-blue-700 font-bold text-[10px] rounded-md border border-blue-200 shadow-2xs inline-flex items-center transition-all flex-shrink-0 cursor-pointer" title="Lihat Rincian Detail Planning Kanban">
                        📋 Detail 
                    </button>
                </div>
                <div class="grid grid-cols-4 gap-1.5">
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
                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block mb-0.5">LOT NO</span>
                        <span class="font-mono font-extrabold text-slate-900 text-[11px] truncate block" id="card-lot-no"><?= $session ? htmlspecialchars($session['lot_number']) : '-' ?></span>
                    </div>
                    <div>
                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block mb-0.5">TOTAL QTY</span>
                        <span class="font-extrabold text-slate-900 text-xs block" id="card-total-qty"><?= $session ? number_format($session['kanban_qty'] ?? 200) : '0' ?> <span class="text-[10px] font-normal text-slate-500">pcs</span></span>
                    </div>
                </div>
            </div>

            <!-- Card 2: Acuan Sampling Wajib (Dark Blue Card with Explicit Margin & Border Radius) -->
            <div style="background-color: #1e40af; color: #ffffff; border-radius: 16px; padding: 14px; margin-bottom: 10px; box-shadow: 0 4px 6px -1px rgba(30,64,175,0.2); position: relative; overflow: hidden;" class="flex-shrink-0">
                <span style="color: #93c5fd; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; display: block; margin-bottom: 6px;">ACUAN SAMPLING WAJIB</span>
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <div>
                        <span style="color: #bfdbfe; font-size: 10px; font-weight: 600; display: block;">SPL CODE</span>
                        <span style="color: #ffffff; font-size: 16px; font-weight: 900; letter-spacing: -0.025em; display: block;" id="card-spl-code"><?= $session ? htmlspecialchars($session['sample_code'] ?? 'H') : 'H' ?></span>
                    </div>
                    <div style="text-align: right;">
                        <span style="color: #bfdbfe; font-size: 10px; font-weight: 600; display: block;">SAMPLE SIZE</span>
                        <span style="color: #ffffff; font-size: 20px; font-weight: 900; display: block;" id="card-sample-size"><?= $session ? $session['sample_size'] : '13' ?> <span style="font-size: 12px; font-weight: 400;">pcs</span></span>
                        <span style="color: #bfdbfe; font-size: 10px; font-weight: 500; display: block; margin-top: 2px;">wajib diperiksa </span>
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
                        <a id="finished-rejection-link" href="<?= base_url('modules/inspection/print_rejection.php?session_id=' . ($session['id'] ?? 0)) ?>" target="_blank" class="inline-flex items-center justify-center px-3 py-1.5 bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs rounded-lg shadow-xs transition-all">
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
    
    <div style="background-color: #ffffff; border-radius: 24px; max-width: 580px; width: 100%; max-height: 85vh; padding: 24px; border: 1px solid #cbd5e1; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); position: relative; margin: auto; display: flex; flex-direction: column; overflow: hidden;" class="animate-fadeIn space-y-4">
        
        <?php if ($session): ?>
            <button type="button" onclick="closeScanModal()" class="absolute right-5 top-5 text-slate-400 hover:text-slate-600 font-bold text-xl">&times;</button>
        <?php endif; ?>

        <!-- Wizard Step Indicator Header -->
        <div class="text-center space-y-2 flex-shrink-0">
            <div class="flex items-center justify-center space-x-2">
                <span id="step-pill-1" class="px-3 py-1 rounded-full text-xs font-bold bg-blue-600 text-white shadow-xs">
                    1. Pilih Planning
                </span>
                <span class="text-slate-300 font-bold">&rarr;</span>
                <span id="step-pill-2" class="px-3 py-1 rounded-full text-xs font-bold bg-slate-100 text-slate-400 border border-slate-200">
                    2. Scan Label QR
                </span>
            </div>
            <h2 id="wizard-modal-title" class="text-base font-black text-slate-900">Step 1: Pilih Item Planning Aktif</h2>
            <p id="wizard-modal-subtitle" class="text-xs text-slate-500">Pilih Kanban / Safety Stock yang akan diinspeksi sebelum melakukan scan label </p>
        </div>

        <datalist id="modal-parts-list">
            <?php foreach ($master_parts as $mp): ?>
                <option value="<?= htmlspecialchars($mp['part_code']) ?>"><?= htmlspecialchars($mp['part_code']) ?> - <?= htmlspecialchars($mp['part_name']) ?></option>
            <?php endforeach; ?>
        </datalist>

        <!-- ── STEP 1: PILIH PLANNING ITEM ─────────────────────────── -->
        <div id="wizard-step-1-content" style="display: flex; flex-direction: column; min-height: 0; flex: 1;" class="space-y-3 overflow-hidden">
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
                        $pType = ($plan['plan_type'] === 'safety_stock') ? 'Safety Stock' : 'Kanban';
                        $pCek  = !empty($plan['check_type']) ? htmlspecialchars($plan['check_type']) : '';
                        
                        // Format ETA (Estimated Time of Arrival / Schedule Kirim ETA)
                        $etaTs = !empty($plan['eta']) ? strtotime($plan['eta']) : (!empty($plan['req_date']) ? strtotime($plan['req_date']) : 0);
                        $etaFormatted = ($etaTs > 0) ? date('d M Y, H:i', $etaTs) . ' WIB' : 'Reguler';
                        $isEarliest = ($idx === 0);
                    ?>
                    <div class="planning-card p-3 bg-white hover:bg-blue-50/80 border border-slate-200 hover:border-blue-400 rounded-xl cursor-pointer transition-all flex items-center justify-between gap-3 shadow-2xs"
                         onclick="selectPlanningItem(<?= (int)$plan['id'] ?>, '<?= addslashes($plan['item_code']) ?>', '<?= addslashes($plan['item_description']) ?>', '<?= addslashes($pCust) ?>', <?= (int)$plan['qty'] ?>, '<?= addslashes($pCek) ?>', '<?= $plan['plan_type'] ?>')">
                        <div class="min-w-0 flex-1 space-y-1">
                            <div class="flex items-center space-x-1.5 flex-wrap gap-y-1">
                                <span class="font-mono font-black text-blue-700 text-xs"><?= $pCode ?></span>
                                <span class="badge text-[9px] <?= $plan['plan_type'] === 'safety_stock' ? 'bg-purple-100 text-purple-800 border border-purple-200' : 'bg-blue-100 text-blue-800 border border-blue-200' ?>"><?= $pType ?></span>
                                <?php if ($pCek): ?>
                                    <span class="inline-flex items-center px-1.5 py-0.2 rounded text-[9px] font-extrabold bg-amber-100 text-amber-800 border border-amber-300"><?= $pCek ?> Cek</span>
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

            <div class="pt-2 text-center border-t border-slate-100 flex-shrink-0">
                <button type="button" onclick="skipPlanningSelection()" class="text-xs text-slate-500 hover:text-slate-700 font-semibold underline">
                    Atau Lanjut Tanpa Memilih Planning (Scan Langsung)
                </button>
            </div>
        </div>

        <!-- ── STEP 2: SCAN BARCODE LABEL ─────────────────────────── -->
        <div id="wizard-step-2-content" class="space-y-3 hidden">
            
            <!-- Selected Planning Summary Banner -->
            <div class="p-3 bg-blue-50 border border-blue-200 rounded-xl flex items-center justify-between text-xs">
                <div class="min-w-0 flex-1 space-y-0.5">
                    <span class="text-[10px] font-bold text-blue-600 block uppercase">PLANNING DIPILIH (STEP 1)</span>
                    <div class="font-mono font-black text-blue-900 text-sm flex items-center space-x-2">
                        <span id="selected-plan-part-code">PART-CODE</span>
                        <span id="selected-plan-tag" class="text-[9px] bg-amber-100 text-amber-800 border border-amber-300 px-1.5 py-0.2 rounded font-sans font-bold">100% Cek</span>
                    </div>
                    <div id="selected-plan-desc" class="font-bold text-slate-800 truncate">Description</div>
                    <div class="text-[10px] text-slate-500">
                        Cust: <b id="selected-plan-cust">-</b> &middot; Target Qty: <b id="selected-plan-qty">0</b> pcs
                    </div>
                </div>
                <button type="button" onclick="backToStep1()" class="text-xs text-blue-700 font-bold hover:underline bg-white border border-blue-300 px-2.5 py-1 rounded-lg shadow-2xs ml-2 flex-shrink:0">
                    ✏️ Ganti
                </button>
            </div>

            <!-- Smart QR Barcode Input & Form -->
            <form id="modal-scan-form" onsubmit="event.preventDefault(); triggerModalValidation();" class="space-y-3 text-xs">
                
                <div class="p-2.5 bg-slate-50 border border-slate-200 rounded-xl space-y-1">
                    <label class="block font-bold text-slate-900 text-[11px] flex items-center">
                        <svg class="w-3.5 h-3.5 mr-1 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"></path></svg>
                        Scan / Paste Barcode atau QR Code Label 
                    </label>
                    <input type="text" id="scan-qr-raw" oninput="parseBarcodeQRInput(this.value)" placeholder="Tempel atau Scan Barcode / QR Code di sini..." class="form-input py-2 px-3 text-xs font-mono font-bold bg-white border-blue-400 focus:border-blue-600" autocomplete="off">
                    <p class="text-[10px] text-slate-500 font-medium">Part Code label yang discan harus cocok dengan Planning yang dipilih</p>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Part Code Label <span class="text-rose-500">*</span></label>
                        <input type="text" id="scan-part-code" list="modal-parts-list" placeholder="CONTOH: 184314701" class="form-input py-2 text-xs font-mono font-bold uppercase" required autocomplete="off">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-700 mb-1">Lot Number Label <span class="text-rose-500">*</span></label>
                        <input type="text" id="scan-lot-number" placeholder="CONTOH: LOT-20260817-A" class="form-input py-2 text-xs font-mono font-bold uppercase" required autocomplete="off">
                    </div>
                </div>

                <button type="submit" class="btn-primary w-full py-2.5 text-xs font-bold flex items-center justify-center shadow-md shadow-blue-600/20">
                    Validasi Matching & Muat Inspeksi
                </button>
            </form>

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
                    <div class="pt-1 border-t border-blue-100">
                        <span class="text-[10px] text-slate-400 font-bold block uppercase">Lot Number Produksi</span>
                        <span id="modal-kb-lot-no" class="font-mono font-extrabold text-slate-900 text-xs block truncate"><?= $session ? htmlspecialchars($session['lot_number'] ?? '-') : '-' ?></span>
                    </div>
                    <div class="pt-1 border-t border-blue-100">
                        <span class="text-[10px] text-slate-400 font-bold block uppercase">Cavity</span>
                        <span id="modal-kb-cavity" class="font-bold text-slate-800 text-xs block">Cavity <?= $session ? htmlspecialchars($session['cavity'] ?? '1') : '1' ?></span>
                    </div>
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
            </div>

        </div>

        <div class="pt-2.5 border-t border-slate-100 text-right flex-shrink-0">
            <button type="button" onclick="closeKanbanDetailModal()" class="btn-secondary py-1.5 px-4 text-xs font-bold shadow-2xs hover:bg-slate-100 cursor-pointer">
                Tutup
            </button>
        </div>

    </div>
</div>

<!-- Modal Riwayat Inspeksi Lot Sebelumnya untuk Part Ini -->
<div id="prev-history-modal-overlay" style="position: fixed; inset: 0; background-color: rgba(15, 23, 42, 0.75); backdrop-filter: blur(4px); z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 16px;" class="hidden">
    <div style="background-color: #ffffff; border-radius: 20px; max-width: 600px; width: 100%; max-height: 85vh; display: flex; flex-direction: column; border: 1px solid #cbd5e1; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); margin: auto; overflow: hidden;" class="animate-fadeIn">
        
        <!-- Modal Header -->
        <div class="p-4 border-b border-slate-200 flex items-center justify-between bg-slate-50 flex-shrink-0">
            <div>
                <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">RIWAYAT INSPEKSI PREVIOUS LOT</span>
                <h3 class="text-sm font-extrabold text-slate-900 flex items-center">
                    <span class="font-mono text-blue-700 mr-1.5"><?= $session ? htmlspecialchars($session['part_code']) : '' ?></span>
                    <span class="text-slate-600 text-xs font-semibold">(<?= $session ? htmlspecialchars($session['part_name']) : '' ?>)</span>
                </h3>
            </div>
            <button type="button" onclick="closePrevHistoryModal()" class="text-slate-400 hover:text-slate-600 font-bold text-xl leading-none px-2">&times;</button>
        </div>

        <!-- Modal Body: Cards List of Previous Lots (Strictly Scrollable Flex-1 Container) -->
        <div style="min-height: 0; flex: 1; max-height: 60vh; overflow-y: auto; -webkit-overflow-scrolling: touch;" class="p-4 space-y-2.5">
            <?php if (empty($previousSessions)): ?>
                <div class="text-center py-8 space-y-2">
                    <svg class="w-10 h-10 text-slate-300 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <p class="text-xs font-bold text-slate-500">Belum ada riwayat sesi inspeksi lain untuk Part Code ini.</p>
                    <p class="text-[11px] text-slate-400">Sesi saat ini adalah sesi pertama yang tercatat untuk part ini.</p>
                </div>
            <?php else: ?>
                <?php foreach ($previousSessions as $ps): ?>
                    <div class="p-3 bg-slate-50 border border-slate-200 rounded-xl space-y-2 hover:border-slate-300 transition-colors">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-2">
                                <span class="font-mono font-black text-slate-900 text-xs">Lot: <?= htmlspecialchars($ps['lot_number']) ?></span>
                                <span class="text-[10px] text-slate-400 bg-slate-200 px-1.5 py-0.5 rounded font-mono">Cavity <?= htmlspecialchars($ps['cavity']) ?></span>
                            </div>
                            <div>
                                <?php if ($ps['status'] === 'passed'): ?>
                                    <span class="badge badge-emerald text-[10px] font-bold">PASSED</span>
                                <?php elseif ($ps['status'] === 'rejected'): ?>
                                    <span class="badge badge-rose text-[10px] font-bold">REJECTED</span>
                                <?php else: ?>
                                    <span class="badge badge-blue text-[10px] font-bold">IN PROGRESS</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-2 text-[11px] text-slate-600 border-t border-slate-200/60 pt-2">
                            <div>
                                <span class="text-[10px] text-slate-400 block font-bold">WAKTU & INSPECTOR</span>
                                <span class="font-semibold text-slate-800"><?= date('d M Y H:i', strtotime($ps['started_at'])) ?> WIB</span>
                                <span class="text-[10px] text-slate-500 block">by <?= htmlspecialchars($ps['inspector_name'] ?? $ps['did_pic'] ?? 'Inspector') ?></span>
                            </div>
                            <div class="text-right">
                                <span class="text-[10px] text-slate-400 block font-bold">HASIL SAMPLING</span>
                                <span class="font-mono font-bold text-slate-800"><?= $ps['samples_checked'] ?> / <?= $ps['sample_size'] ?> pcs sample</span>
                                <span class="text-[10px] font-mono block <?= ($ps['ng_count'] > 0) ? 'text-rose-600 font-bold' : 'text-slate-500' ?>">
                                    <?= $ps['ng_count'] ?> NG (Limit: <?= $ps['reject_number'] ?>)
                                </span>
                            </div>
                        </div>

                        <div class="pt-1 text-right">
                            <a href="<?= base_url('modules/inspection/session.php?id=' . $ps['id']) ?>" class="text-[11px] text-blue-600 font-bold hover:underline inline-flex items-center">
                                Buka Sesi Inspeksi Ini &rarr;
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



/**
 * QR Code Barcode Format Parser (formatqr.md)
 * Format: Z1inicodePart|Z2LotPart|Z3Cavity|Z4line
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

function parseBarcodeQRInput(val) {
    if (!val) return;
    var parsed = parseBarcodeQR(val);
    if (parsed.Z1 || parsed.Z2) {
        if (parsed.Z1) document.getElementById('scan-part-code').value = parsed.Z1;
        if (parsed.Z2) document.getElementById('scan-lot-number').value = parsed.Z2;
        // If both Z1 & Z2 are extracted, automatically validate & load inspection!
        if (parsed.Z1 && parsed.Z2) {
            triggerModalValidation();
        }
    }
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

function openScanModal() {
    document.getElementById('scan-modal-overlay').classList.remove('hidden');
    document.getElementById('scan-modal-overlay').style.display = 'flex';
    var qrInput = document.getElementById('scan-qr-raw');
    if (qrInput) {
        qrInput.value = '';
        qrInput.focus();
    }
}

function closeScanModal() {
    if (currentSessionId > 0) {
        document.getElementById('scan-modal-overlay').classList.add('hidden');
        document.getElementById('scan-modal-overlay').style.display = 'none';
    } else {
        Swal.fire({
            icon: 'info',
            title: 'Perhatian',
            text: 'Silakan scan / validasi Part Code & Lot Number terlebih dahulu!'
        });
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

function selectPlanningItem(id, itemCode, itemDesc, customer, qty, checkType, planType) {
    selectedPlanningId = id;
    selectedPlanningType = planType || 'kanban';

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

    // Switch step UI
    document.getElementById('step-pill-1').className = 'px-3 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800 border border-emerald-300';
    document.getElementById('step-pill-1').textContent = '✓ Step 1 Complete';
    document.getElementById('step-pill-2').className = 'px-3 py-1 rounded-full text-xs font-bold bg-blue-600 text-white shadow-xs';
    
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

function backToStep1() {
    document.getElementById('step-pill-1').className = 'px-3 py-1 rounded-full text-xs font-bold bg-blue-600 text-white shadow-xs';
    document.getElementById('step-pill-1').textContent = '1. Pilih Planning';
    document.getElementById('step-pill-2').className = 'px-3 py-1 rounded-full text-xs font-bold bg-slate-100 text-slate-400 border border-slate-200';

    document.getElementById('wizard-modal-title').textContent = 'Step 1: Pilih Item Planning Aktif';
    document.getElementById('wizard-modal-subtitle').textContent = 'Pilih Kanban / Safety Stock yang akan diinspeksi sebelum melakukan scan label ';

    document.getElementById('wizard-step-1-content').classList.remove('hidden');
    document.getElementById('wizard-step-2-content').classList.add('hidden');
}

function skipPlanningSelection() {
    selectedPlanningId = 0;
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

function filterPlanningItems() {
    var query = document.getElementById('planning-search-input').value.toLowerCase().trim();
    var container = document.getElementById('planning-cards-container');
    if (!container) return;
    var cards = container.querySelectorAll('.planning-card');
    
    for (var i = 0; i < cards.length; i++) {
        var text = cards[i].textContent.toLowerCase();
        if (query === '' || text.indexOf(query) !== -1) {
            cards[i].style.display = 'flex';
        } else {
            cards[i].style.display = 'none';
        }
    }
}

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

            // Update Header Title & Customer
            var titleEl = document.getElementById('header-session-title');
            if (titleEl) {
                titleEl.innerHTML = '<span>Inspeksi Barang — <span class="font-mono text-blue-700 font-black">' + escapeHtml(s.part_code) + '</span><span class="font-semibold text-slate-600 text-xs hidden sm:inline"> (' + escapeHtml(s.part_name) + ')</span></span>' +
                    '<span id="header-customer-badge" class="inline-flex items-center px-2 py-0.5 rounded-md text-[11px] font-extrabold bg-indigo-50 text-indigo-700 border border-indigo-200/80 shadow-2xs ml-2 flex-shrink-0">🏢 ' + escapeHtml(custName) + '</span>';
            }

            // Update Card 1 Customer Name
            var cardCustEl = document.getElementById('card-customer-name');
            if (cardCustEl) {
                cardCustEl.innerHTML = '🏢 ' + escapeHtml(custName);
            }

            if (document.getElementById('card-part-code')) document.getElementById('card-part-code').textContent = s.part_code || '-';
            if (document.getElementById('card-lot-no')) document.getElementById('card-lot-no').textContent = s.lot_number || '-';
            if (document.getElementById('card-total-qty')) document.getElementById('card-total-qty').innerHTML = (s.kanban_qty ? parseInt(s.kanban_qty).toLocaleString() : '0') + ' <span class="text-[10px] font-normal text-slate-500">pcs</span>';

            // Populate Modal Detail Kanban fields
            if (document.getElementById('modal-kb-plan-type')) document.getElementById('modal-kb-plan-type').textContent = (s.inspection_type === 'safety_stock') ? 'SAFETY STOCK' : 'KANBAN';
            if (document.getElementById('modal-kb-doc-no')) document.getElementById('modal-kb-doc-no').textContent = s.doc_no || '-';
            if (document.getElementById('modal-kb-vendor')) document.getElementById('modal-kb-vendor').textContent = s.kanban_vendor || 'PT. SURYA TECHNOLOGY INDUSTRI';
            if (document.getElementById('modal-kb-customer')) document.getElementById('modal-kb-customer').textContent = '🏢 ' + (s.customer || 'PT. Indonesia Epson Industry');
            if (document.getElementById('modal-kb-kanban-no-badge')) document.getElementById('modal-kb-kanban-no-badge').textContent = '#' + (s.kanban_no || '-');
            if (document.getElementById('modal-kb-item-code')) document.getElementById('modal-kb-item-code').textContent = s.kanban_item_code || s.part_code || '-';
            if (document.getElementById('modal-kb-part-model')) document.getElementById('modal-kb-part-model').textContent = s.part_model || '-';
            if (document.getElementById('modal-kb-item-desc')) document.getElementById('modal-kb-item-desc').textContent = s.kanban_item_desc || s.part_name || '-';
            if (document.getElementById('modal-kb-lot-no')) document.getElementById('modal-kb-lot-no').textContent = s.lot_number || '-';
            if (document.getElementById('modal-kb-cavity')) document.getElementById('modal-kb-cavity').textContent = 'Cavity ' + (s.cavity || '1');
            if (document.getElementById('modal-kb-eta')) document.getElementById('modal-kb-eta').textContent = s.kanban_eta ? (s.kanban_eta + ' WIB') : '-';
            if (document.getElementById('modal-kb-req-date')) document.getElementById('modal-kb-req-date').textContent = s.kanban_req_date ? (s.kanban_req_date + ' WIB') : '-';
            if (document.getElementById('modal-kb-qty')) document.getElementById('modal-kb-qty').textContent = (s.kanban_qty ? parseInt(s.kanban_qty).toLocaleString() : '0') + ' pcs';
            if (document.getElementById('modal-kb-str-loc')) document.getElementById('modal-kb-str-loc').textContent = s.kanban_str_loc || 'WH-A01';
            if (document.getElementById('modal-kb-check-type')) document.getElementById('modal-kb-check-type').textContent = s.kanban_check_type ? (s.kanban_check_type + ' Cek') : 'Normal Cek';
            if (document.getElementById('modal-kb-remark')) document.getElementById('modal-kb-remark').textContent = s.kanban_remark || '-';

            var cardTypeEl = document.getElementById('card-type-badge');
            if (cardTypeEl) {
                if (s.inspection_type === 'safety_stock') {
                    cardTypeEl.innerHTML = '<span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-extrabold bg-purple-100 text-purple-800 border border-purple-200 truncate">📦 SAFETY STOCK</span>';
                } else {
                    cardTypeEl.innerHTML = '<span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-extrabold bg-blue-100 text-blue-800 border border-blue-200 truncate">🚚 KANBAN</span>';
                }
            }

            if (document.getElementById('card-spl-code')) document.getElementById('card-spl-code').textContent = (s.sample_code || 'H');
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

    if (!pCode || !lNum) return;

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

    var validateUrl = '<?= base_url("modules/inspection/scan_validate.php") ?>?part_code=' + encodeURIComponent(pCode) + '&lot_number=' + encodeURIComponent(lNum) + '&kanban_item_id=' + selectedPlanningId;

    fetch(validateUrl)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) {
                document.getElementById('modal-loading').classList.add('hidden');
                document.getElementById('modal-error-box').classList.remove('hidden');
                document.getElementById('modal-error-msg').textContent = data.message;
                return;
            }

            var payload = new FormData();
            payload.append('action', 'start_session');
            payload.append('part_code', data.part.part_code);
            payload.append('lot_number', lNum);
            payload.append('did_id', data.did.id);
            payload.append('kanban_item_id', data.kanban ? data.kanban.id : selectedPlanningId);
            payload.append('inspection_type', data.inspection_type || selectedPlanningType);
            payload.append('part_id', data.part.id);
            payload.append('sample_size', data.aql.sample_size);
            payload.append('reject_number', data.aql.reject_number);

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
        })
        .catch(function(err) {
            document.getElementById('modal-loading').classList.add('hidden');
            document.getElementById('modal-error-box').classList.remove('hidden');
            document.getElementById('modal-error-msg').textContent = 'Koneksi server gagal saat memvalidasi label!';
        });
}

function submitSampleResult(result) {
    if (!currentSessionId) return;

    var payload = new FormData();
    payload.append('session_id', currentSessionId);
    payload.append('result', result);

    if (result === 'NG') {
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
                    window.open('<?= base_url("modules/inspection/print_rejection.php?session_id=") ?>' + currentSessionId + '&autoprint=1', '_blank');
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
        html += '<div style="display: flex; align-items: center; justify-content: space-between; padding: 8px 10px; background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; font-size: 12px; gap: 8px; margin-bottom: 6px;">' +
            '<div style="min-width: 0; flex: 1;">' +
                '<span style="font-weight: 800; color: #be123c; font-size: 11px; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">#' + r.sample_number + ' ' + escapeHtml(r.defect_name) + '</span>' +
                '<span style="font-size: 10px; color: #64748b; display: block;">Qty ' + r.qty_ng + ' &middot; Sample ke-' + r.sample_number + '</span>' +
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
    if (!recordId || !currentSessionId) return;

    Swal.fire({
        title: 'Batalkan Catatan Defect NG Ini?',
        text: 'Catatan defect akan dihapus & status sample dikembalikan ke OK (jika tidak ada defect lain).',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e11d48',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Ya, Batalkan NG!',
        cancelButtonText: 'Batal'
    }).then(function(res) {
        if (res.isConfirmed) {
            var payload = new FormData();
            payload.append('action', 'delete_ng_record');
            payload.append('record_id', recordId);
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
                Swal.fire('Berhasil!', 'Catatan defect NG berhasil dibatalkan.', 'success').then(function() {
                    loadWorkbenchSessionData(currentSessionId);
                });
            })
            .catch(function(err) {
                Swal.fire('Error!', 'Gagal terhubung ke server', 'error');
            });
        }
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
</script>

<!-- Offline Vendor Libraries & App Scripts -->
<script src="<?= base_url('assets/js/vendor/jquery.min.js') ?>"></script>
<script src="<?= base_url('assets/js/vendor/sweetalert2.all.min.js') ?>"></script>
<script src="<?= base_url('assets/js/vendor/three.min.js') ?>"></script>
<script src="<?= base_url('assets/js/app.js') ?>"></script>

</body>
</html>
