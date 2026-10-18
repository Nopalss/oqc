<?php
/**
 * Detail Traceability & Audit Trail Lot NG (Karantina OQC)
 * File: modules/ng_quarantine/detail.php
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

if (!is_logged_in()) {
    redirect('modules/auth/login.php');
}

$pdo = getDB();
$pageTitle = "Detail Traceability Lot NG";

$lotId = (int)($_GET['id'] ?? 0);

if (!$lotId) {
    set_flash('error', 'ID Lot NG tidak valid!');
    redirect('modules/ng_quarantine/index.php');
}

// -----------------------------------------------------------------------------
// POST Handler: Update Supervisor Action / Disposition Notes
// -----------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'update_disposition') {
    $actionNotes = trim(sanitize($_POST['action_notes'] ?? ''));
    $userId      = $_SESSION['user_id'] ?? null;

    if (!empty($actionNotes)) {
        try {
            $stmtUpd = $pdo->prepare("
                UPDATE inspection_session_lots 
                SET chief_notes = :notes,
                    chief_approved_at = NOW(),
                    chief_approved_by = :user_id
                WHERE id = :id
            ");
            $stmtUpd->execute([
                ':notes'   => $actionNotes,
                ':user_id' => $userId,
                ':id'      => $lotId
            ]);

            set_flash('success', 'Catatan disposisi penanganan berhasil diperbarui.');
        } catch (PDOException $e) {
            set_flash('error', 'Gagal memperbarui catatan: ' . $e->getMessage());
        }
    }
    redirect("modules/ng_quarantine/detail.php?id={$lotId}");
}

// -----------------------------------------------------------------------------
// Data Fetching: Main Lot Info
// -----------------------------------------------------------------------------
$sqlLot = "
    SELECT 
        isl.id AS lot_id,
        isl.inspection_session_id,
        isl.lot_number,
        isl.ref_number,
        isl.qty AS qty_lot,
        isl.sample_size,
        isl.lot_result,
        isl.lot_status,
        isl.replaced_by_lot_id,
        isl.remarks AS lot_remarks,
        isl.chief_notes AS action_notes,
        isl.chief_approved_at AS action_noted_at,
        isl.chief_approved_by AS action_noted_by,

        -- Session Info
        CONCAT('SESS-', s.id) AS session_code,
        s.started_at,
        s.closed_at AS ended_at,
        s.status AS session_status,
        s.inspection_type,
        s.chief_approved_at,
        s.chief_approved_by,
        s.approval_notes,

        -- Inspector & Users Info
        u_insp.name AS inspector_name,
        COALESCE(NULLIF(isl.chief_approved_by, ''), NULLIF(s.chief_approved_by, ''), u_chief.name) AS chief_name,
        u_act.name AS action_by_name,

        -- Part & Model Info
        p.part_code AS part_number,
        p.part_name,
        m.name AS model_name,
        ki.customer AS kanban_customer,

        -- Replacement Lot Info (If replaced)
        repl_lot.lot_number AS replacement_lot_number,
        repl_lot.ref_number AS replacement_ref_number

    FROM inspection_session_lots isl
    JOIN inspection_sessions s ON isl.inspection_session_id = s.id
    LEFT JOIN kanban_items ki ON s.kanban_item_id = ki.id
    LEFT JOIN master_parts p ON (s.part_id = p.id OR ki.item_code = p.part_code)
    LEFT JOIN master_models m ON p.model_id = m.id
    LEFT JOIN users u_insp ON s.inspector_id = u_insp.id
    LEFT JOIN users u_chief ON u_chief.id = COALESCE(isl.chief_approved_by, s.chief_approved_by)
    LEFT JOIN users u_act ON isl.chief_approved_by = u_act.id
    LEFT JOIN inspection_session_lots repl_lot ON isl.replaced_by_lot_id = repl_lot.id
    WHERE isl.id = :lot_id
    LIMIT 1
";

$stmtLot = $pdo->prepare($sqlLot);
$stmtLot->execute([':lot_id' => $lotId]);
$lotData = $stmtLot->fetch(PDO::FETCH_ASSOC);

if (!$lotData) {
    set_flash('error', 'Data Lot NG tidak ditemukan!');
    redirect('modules/ng_quarantine/index.php');
}

// Cek apakah lot ini bukan lot NG (sedang in_progress dan tidak memiliki riwayat cacat aktif pada sesinya)
$stmtChkNgHistory = $pdo->prepare("
    SELECT COUNT(*) FROM inspection_ng_records 
    WHERE session_lot_id = :slot_id AND (is_cancelled IS NULL OR is_cancelled = 0)
");
$stmtChkNgHistory->execute([':slot_id' => $lotId]);
$hasActiveNgOnThisLot = ((int)$stmtChkNgHistory->fetchColumn() > 0);

if ($lotData['lot_result'] === 'in_progress' && !$hasActiveNgOnThisLot) {
    // Cari apakah ada sesi awal yang pernah NG untuk lot ini
    $stmtHasPriorNg = $pdo->prepare("
        SELECT isl_p.id FROM inspection_session_lots isl_p
        JOIN inspection_sessions s_p ON s_p.id = isl_p.inspection_session_id
        LEFT JOIN daily_inspection_data did ON did.id = s_p.did_id
        LEFT JOIN kanban_items ki ON ki.id = s_p.kanban_item_id
        LEFT JOIN master_parts mp ON mp.id = s_p.part_id
        WHERE UPPER(isl_p.lot_number) = UPPER(:lot)
          AND UPPER(COALESCE(isl_p.ref_number, '')) = UPPER(:ref)
          AND (UPPER(did.part_code) = UPPER(:p1) OR UPPER(ki.item_code) = UPPER(:p2) OR UPPER(mp.part_code) = UPPER(:p3))
          AND (isl_p.lot_result = 'rejected' OR isl_p.lot_status IN ('rejected', 'ng_quarantine', 'reinspected', 'replaced'))
          AND isl_p.id != :curr_id
        ORDER BY isl_p.id ASC
        LIMIT 1
    ");
    $stmtHasPriorNg->execute([
        ':lot' => $lotData['lot_number'],
        ':ref' => $lotData['ref_number'] ?? '',
        ':p1'  => $lotData['part_number'],
        ':p2'  => $lotData['part_number'],
        ':p3'  => $lotData['part_number'],
        ':curr_id' => $lotId
    ]);
    $priorNgLotId = (int)$stmtHasPriorNg->fetchColumn();

    if ($priorNgLotId > 0) {
        // Arahkan ke lot NG awal (misal Sesi Safety Stock asal) agar audit trail karantina mengarah ke lot NG yang sah
        redirect("modules/ng_quarantine/detail.php?id={$priorNgLotId}");
    } else {
        // Lot ini sama sekali bukan lot NG (temuan salah input sudah dibatalkan seluruhnya)
        set_flash('info', 'Lot #' . $lotData['lot_number'] . ' sedang dalam pemeriksaan aktif dan tidak memiliki temuan NG.');
        redirect('modules/ng_quarantine/index.php');
    }
}

// -----------------------------------------------------------------------------
// Data Fetching: All Inspection Rounds of Target Lot (Part Code + Lot No + Ref No)
// -----------------------------------------------------------------------------
$targetLotNumber  = $lotData['lot_number'];
$targetPartNumber = $lotData['part_number'];
$targetRefNumber  = $lotData['ref_number'];

$sqlAllRounds = "
    SELECT 
        isl.id AS session_lot_id,
        isl.inspection_session_id,
        isl.lot_number,
        isl.ref_number,
        isl.qty AS qty_lot,
        isl.sample_size,
        isl.lot_result,
        isl.lot_status,
        CASE 
            WHEN isl.is_chief_approved = 1 OR s.is_chief_approved = 1 THEN 1
            WHEN isl.chief_approved_at IS NOT NULL OR s.chief_approved_at IS NOT NULL THEN 1
            ELSE 0 
        END AS is_chief_approved,
        COALESCE(NULLIF(isl.chief_notes, ''), s.approval_notes) AS chief_notes,
        COALESCE(isl.chief_approved_at, s.chief_approved_at) AS chief_approved_at,
        COALESCE(NULLIF(isl.chief_approved_by, ''), NULLIF(s.chief_approved_by, ''), u_chief.name, u_chief_sess.name) AS chief_name,
        isl.created_at AS lot_checked_at,
        s.started_at AS session_started_at,
        s.closed_at AS session_closed_at,
        s.status AS session_status,
        s.inspection_type,
        u_insp.name AS inspector_name
    FROM inspection_session_lots isl
    JOIN inspection_sessions s ON s.id = isl.inspection_session_id
    LEFT JOIN kanban_items ki ON ki.id = s.kanban_item_id
    LEFT JOIN daily_inspection_data did ON did.id = s.did_id
    LEFT JOIN master_parts p ON (p.id = s.part_id OR p.part_code = ki.item_code OR p.part_code = did.part_code)
    LEFT JOIN users u_insp ON s.inspector_id = u_insp.id
    LEFT JOIN users u_chief ON u_chief.id = isl.chief_approved_by
    LEFT JOIN users u_chief_sess ON u_chief_sess.id = s.chief_approved_by
    WHERE UPPER(isl.lot_number) = UPPER(:lot_num)
      AND (
          UPPER(p.part_code) = UPPER(:part_num1) 
          OR UPPER(did.part_code) = UPPER(:part_num2) 
          OR UPPER(ki.item_code) = UPPER(:part_num3)
          OR p.part_code IS NULL
      )
      AND (
          :ref_num1 IS NULL OR :ref_num2 = '' 
          OR UPPER(isl.ref_number) = UPPER(:ref_num3)
          OR isl.ref_number IS NULL
          OR isl.id = :target_lot_id
      )
      AND (
          isl.id = :target_lot_id2
          OR isl.lot_result IN ('rejected', 'passed')
          OR s.status IN ('rejected', 'passed')
          OR EXISTS (
              SELECT 1 FROM inspection_ng_records ngr_act
              WHERE ngr_act.session_lot_id = isl.id
                AND (ngr_act.is_cancelled IS NULL OR ngr_act.is_cancelled = 0)
          )
      )
    ORDER BY s.started_at ASC, isl.id ASC
";
$stmtRounds = $pdo->prepare($sqlAllRounds);
$stmtRounds->execute([
    ':lot_num'       => $targetLotNumber,
    ':part_num1'     => $targetPartNumber,
    ':part_num2'     => $targetPartNumber,
    ':part_num3'     => $targetPartNumber,
    ':ref_num1'       => $targetRefNumber,
    ':ref_num2'       => $targetRefNumber,
    ':ref_num3'       => $targetRefNumber,
    ':target_lot_id'  => $lotId,
    ':target_lot_id2' => $lotId
]);
$allRoundsLots = $stmtRounds->fetchAll(PDO::FETCH_ASSOC);

if (empty($allRoundsLots)) {
    $allRoundsLots = [[
        'session_lot_id'        => $lotData['lot_id'],
        'inspection_session_id' => $lotData['inspection_session_id'],
        'lot_number'            => $lotData['lot_number'],
        'ref_number'            => $lotData['ref_number'],
        'qty_lot'               => $lotData['qty_lot'],
        'sample_size'           => $lotData['sample_size'],
        'lot_result'            => $lotData['lot_result'],
        'lot_status'            => $lotData['lot_status'],
        'is_chief_approved'     => 0,
        'chief_notes'           => $lotData['action_notes'],
        'chief_approved_at'     => $lotData['action_noted_at'],
        'chief_approved_by'     => $lotData['action_noted_by'],
        'lot_checked_at'        => $lotData['started_at'],
        'session_started_at'    => $lotData['started_at'],
        'session_closed_at'     => $lotData['ended_at'],
        'session_status'        => $lotData['session_status'],
        'inspection_type'       => 'inspection',
        'inspector_name'        => $lotData['inspector_name'],
        'chief_name'            => $lotData['chief_name']
    ]];
}

// Build map of session_lot_id => Round Number
$roundNumberMap = [];
foreach ($allRoundsLots as $index => $rLot) {
    $roundNumberMap[(int)$rLot['session_lot_id']] = $index + 1;
}

// Fetch all defect records for all rounds of this lot
$roundLotIds = array_column($allRoundsLots, 'session_lot_id');
$defectsByRoundLotId = [];
$activeDefectsByRoundLotId = [];
$defectsList = [];

if (!empty($roundLotIds)) {
    $inRoundLotIds = implode(',', array_map('intval', $roundLotIds));
    $sqlAllDefects = "
        SELECT 
            ngr.id AS defect_rec_id,
            ngr.session_lot_id,
            ngr.unit_number,
            ngr.qty_ng,
            ngr.remark,
            ngr.is_cancelled,
            ngr.cancel_reason,
            ngr.created_at AS defect_created_at,
            dt.code AS defect_code,
            dt.name AS defect_name,
            COALESCE(u_insp.name, 'Inspector QC') AS defect_inspector_name
        FROM inspection_ng_records ngr
        LEFT JOIN defect_types dt ON ngr.defect_type_id = dt.id
        LEFT JOIN inspection_sessions s ON ngr.inspection_session_id = s.id
        LEFT JOIN users u_insp ON s.inspector_id = u_insp.id
        WHERE ngr.session_lot_id IN ({$inRoundLotIds})
          AND (ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)
        ORDER BY ngr.created_at ASC, ngr.id ASC
    ";
    $stmtAllDefects = $pdo->query($sqlAllDefects);
    while ($dfRow = $stmtAllDefects->fetch(PDO::FETCH_ASSOC)) {
        $rLotId = (int)$dfRow['session_lot_id'];
        $dfRow['round_number'] = $roundNumberMap[$rLotId] ?? 1;
        $defectsByRoundLotId[$rLotId][] = $dfRow;
        $activeDefectsByRoundLotId[$rLotId][] = $dfRow;
        $defectsList[] = $dfRow; // Accumulate defects from ALL rounds
    }
}

// Fetch Administrative Quarantine Closure from lot_quarantine_closures
$quarantineClosure = null;
if (!empty($roundLotIds)) {
    $inRoundLotIdsClosure = implode(',', array_map('intval', $roundLotIds));
    $stmtQC = $pdo->query("
        SELECT lqc.*, u.name AS closed_by_name 
        FROM lot_quarantine_closures lqc
        LEFT JOIN users u ON u.id = lqc.closed_by
        WHERE lqc.session_lot_id IN ($inRoundLotIdsClosure)
        ORDER BY lqc.id DESC
        LIMIT 1
    ");
    $quarantineClosure = $stmtQC->fetch(PDO::FETCH_ASSOC);
}

// Calculate NG counts across all rounds
$activeQtyNG  = 0;
$initialQtyNG = 0;
$reinspQtyNG  = 0;
$allTimeQtyNG = 0;

foreach ($defectsList as $df) {
    $qty  = (int)$df['qty_ng'];
    $rNum = (int)($df['round_number'] ?? 1);
    $allTimeQtyNG += $qty;

    if (empty($df['is_cancelled'])) {
        $activeQtyNG += $qty;
        if ($rNum === 1) {
            $initialQtyNG += $qty;
        } else {
            $reinspQtyNG += $qty;
        }
    }
}

// -----------------------------------------------------------------------------
// Data Fetching: Substitution Logs
// -----------------------------------------------------------------------------
$sqlLogs = "
    SELECT 
        lsl.*,
        u.name AS action_user,
        s_re.status AS reinspect_session_status,
        repl_l.lot_number AS repl_lot_num
    FROM lot_substitution_log lsl
    LEFT JOIN users u ON lsl.actioned_by = u.id
    LEFT JOIN inspection_sessions s_re ON lsl.reinspection_session_id = s_re.id
    LEFT JOIN inspection_session_lots repl_l ON lsl.replacement_session_lot_id = repl_l.id
    WHERE lsl.ng_session_lot_id = :lot_id1 OR lsl.replacement_session_lot_id = :lot_id2
    ORDER BY lsl.created_at DESC
";
$stmtLogs = $pdo->prepare($sqlLogs);
$stmtLogs->execute([
    ':lot_id1' => $lotId,
    ':lot_id2' => $lotId
]);
$substitutionLogs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);

// Calculate overall handling status for Lot A
$totalRoundsCount  = count($allRoundsLots);
$latestRound       = $allRoundsLots[$totalRoundsCount - 1];
$latestResult      = strtolower($latestRound['lot_result'] ?? '');
$inspectionType    = strtolower($lotData['inspection_type'] ?? 'kanban');
$isKanban          = ($inspectionType === 'kanban');
$hasReplacementBox = $isKanban && (!empty($lotData['replaced_by_lot_id']) || $lotData['lot_status'] === 'replaced');

$handlingKey        = 'pending';
$handlingLabel      = 'Belum Ditangani';
$handlingBadgeClass = 'bg-rose-100 text-rose-800 border-rose-200';

if ($latestResult === 'passed') {
    $handlingKey        = 'resolved_passed';
    $handlingLabel      = 'Selesai: Lulus Re-Inspeksi';
    $handlingBadgeClass = 'bg-emerald-100 text-emerald-800 border-emerald-200';
} elseif ($hasReplacementBox && $totalRoundsCount == 1) {
    $handlingKey        = 'resolved_replaced';
    $handlingLabel      = 'Selesai: Diganti Lot Baru';
    $handlingBadgeClass = 'bg-indigo-100 text-indigo-800 border-indigo-200';
} elseif ($latestResult === 'rejected' && $totalRoundsCount > 1) {
    $handlingKey        = 'reinspection_failed';
    $handlingLabel      = 'RE-INSPEKSI (MASIH NG / REJECTED)';
    $handlingBadgeClass = 'bg-rose-100 text-rose-800 border-rose-200';
} elseif ($totalRoundsCount > 1 || $latestResult === 'in_progress') {
    $handlingKey        = 'in_progress';
    $handlingLabel      = 'Sedang Process Re-Inspeksi';
    $handlingBadgeClass = 'bg-amber-100 text-amber-800 border-amber-200';
}

$displayLotStatus      = 'Karantina NG';
$displayLotStatusClass = 'bg-rose-100 text-rose-800 border-rose-200';

if ($latestResult === 'passed') {
    $displayLotStatus      = 'Lulus Re-Inspeksi';
    $displayLotStatusClass = 'bg-emerald-100 text-emerald-800 border-emerald-200';
} elseif ($quarantineClosure) {
    $displayLotStatus      = 'Disposisi Selesai (Divisi)';
    $displayLotStatusClass = 'bg-sky-100 text-sky-800 border-sky-300';
    $handlingLabel         = 'Disposisi Selesai (Divisi)';
    $handlingBadgeClass    = 'bg-sky-100 text-sky-800 border-sky-300';
} elseif ($hasReplacementBox && $totalRoundsCount == 1) {
    $displayLotStatus      = 'Diganti Lot Baru';
    $displayLotStatusClass = 'bg-indigo-100 text-indigo-800 border-indigo-200';
} elseif ($latestResult === 'rejected' && $totalRoundsCount > 1) {
    $displayLotStatus      = 'Re-Inspeksi (Masih NG)';
    $displayLotStatusClass = 'bg-rose-100 text-rose-800 border-rose-200';
} elseif ($lotData['lot_result'] === 'rejected') {
    $displayLotStatus      = 'Rejected';
    $displayLotStatusClass = 'bg-rose-100 text-rose-800 border-rose-200';
} else {
    $displayLotStatus      = ucwords(str_replace('_', ' ', $lotData['lot_status']));
    $displayLotStatusClass = 'bg-slate-100 text-slate-800 border-slate-200';
}

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';
?>

<!-- Main Content Wrapper with Sidebar Offset (md:pl-64) -->
<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen min-w-0 w-full overflow-x-hidden transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-4 sm:p-6 lg:p-8 space-y-6 min-w-0 w-full overflow-x-hidden">
        <div class="max-w-7xl mx-auto space-y-6">

        <!-- Top Breadcrumb & Header Section -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 sm:p-6 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
               
                <h1 class="text-xl sm:text-2xl font-bold text-slate-900 flex items-center gap-3">
                    Traceability Lot NG: <?= sanitize($lotData['lot_number']) ?>
                    <span class="px-3 py-1 text-xs sm:text-sm font-semibold rounded-full border <?= $handlingBadgeClass ?>">
                        <?= $handlingLabel ?>
                    </span>
                </h1>
                <p class="text-xs sm:text-sm text-slate-500 mt-1">
                    Ref No: <span class="font-mono font-medium text-slate-700"><?= sanitize($lotData['ref_number'] ?? '-') ?></span> 
                    | Part: <span class="font-medium text-slate-700"><?= sanitize($lotData['part_number'] ?? '-') ?></span> - <?= sanitize($lotData['part_name'] ?? '-') ?>
                </p>
            </div>

            <!-- Header Action Buttons -->
            <div class="flex flex-wrap items-center gap-2">
                <a href="<?= base_url('modules/ng_quarantine/index.php') ?>" 
                   class="inline-flex items-center px-3.5 py-2 text-xs sm:text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition shadow-sm">
                    <svg class="w-4 h-4 mr-1.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                    </svg>
                    Kembali
                </a>

                <?php if (!empty($lotData['inspection_session_id'])): ?>
                    <a href="<?= base_url('modules/inspection/print_rejection.php?id=' . $lotData['inspection_session_id']) ?>" 
                       target="_blank"
                       class="inline-flex items-center px-3.5 py-2 text-xs sm:text-sm font-medium text-amber-700 bg-amber-50 border border-amber-300 rounded-lg hover:bg-amber-100 transition shadow-sm">
                        <svg class="w-4 h-4 mr-1.5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 000-4H9a2 2 0 000 4zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                        </svg>
                        Cetak Rejection Sheet
                    </a>
                <?php endif; ?>

                <?php if ($handlingKey !== 'resolved_passed' && $latestResult !== 'passed'): ?>
                    <a href="<?= base_url('modules/inspection/session.php?scan_new=1&plan_type=safety_stock&part_code=' . urlencode($lotData['part_number'] ?? '') . '&scanned_lot=' . urlencode($lotData['lot_number'] ?? '') . '&scanned_ref=' . urlencode($lotData['ref_number'] ?? '')) ?>" 
                       class="inline-flex items-center px-4 py-2 text-xs sm:text-sm font-semibold text-white bg-blue-600 hover:bg-blue-700 rounded-lg shadow-sm transition">
                        <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                        Proses Re-Inspeksi
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Section 1: Top 3 Information Cards Grid -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.25rem;" class="w-full">

            <!-- Card 1: Detail Lot & Kanban -->
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 space-y-3">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                    <h2 class="text-sm font-bold text-slate-800 uppercase tracking-wider flex items-center gap-2">
                        <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                        </svg>
                        Informasi Part & Lot
                    </h2>
                    <span class="text-xs bg-slate-100 text-slate-600 px-2 py-0.5 rounded font-mono">ID #<?= $lotData['lot_id'] ?></span>
                </div>

                <div class="text-xs space-y-2 text-slate-600">
                   
                    <div class="flex justify-between py-1 border-b border-slate-50">
                        <span class="text-slate-400">Part Number:</span>
                        <span class="font-semibold text-slate-900"><?= sanitize($lotData['part_number'] ?? '-') ?></span>
                    </div>
                    <div class="flex justify-between py-1 border-b border-slate-50">
                        <span class="text-slate-400">Part Name:</span>
                        <span class="font-semibold text-slate-800 text-right truncate max-w-[180px]"><?= sanitize($lotData['part_name'] ?? '-') ?></span>
                    </div>
                     <div class="flex justify-between py-1 border-b border-slate-50">
                        <span class="text-slate-400"><?= $isKanban ? 'No. Kanban:' : 'Nomor Lot:' ?></span>
                        <span class="font-bold text-slate-900 font-mono"><?= sanitize($lotData['lot_number']) ?></span>
                    </div>
                    <div class="flex justify-between py-1 border-b border-slate-50">
                        <span class="text-slate-400">Ref Number (Lot Tag):</span>
                        <span class="font-semibold text-slate-800 font-mono"><?= sanitize($lotData['ref_number'] ?? '-') ?></span>
                    </div>
                    <div class="flex justify-between py-1 border-b border-slate-50">
                        <span class="text-slate-400">Model / Customer:</span>
                        <span class="font-medium text-slate-700"><?= sanitize($lotData['model_name'] ?? '-') ?> (<?= sanitize($lotData['part_customer'] ?? $lotData['kanban_customer'] ?? '-') ?>)</span>
                    </div>
                    <div class="flex justify-between py-1">
                        <span class="text-slate-400">Jumlah Lot / Sample:</span>
                        <span class="font-bold text-slate-900">
                            <?= number_format((float)$lotData['qty_lot']) ?> pcs 
                            <span class="text-slate-400 font-normal">(Sample: <?= number_format((float)$lotData['sample_size']) ?> pcs)</span>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Card 2: Status Inspeksi & Temuan NG -->
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 space-y-3">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                    <h2 class="text-sm font-bold text-slate-800 uppercase tracking-wider flex items-center gap-2">
                        <svg class="w-4 h-4 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                        </svg>
                        Ringkasan Defect
                    </h2>
                    <span class="px-2 py-0.5 text-xs font-bold rounded bg-rose-100 text-rose-800">
                        <?= strtoupper($lotData['lot_result']) ?>
                    </span>
                </div>

                <div class="text-xs space-y-2 text-slate-600">
                    <div class="flex justify-between py-1 border-b border-slate-50">
                        <span class="text-slate-400 font-medium">Total Qty NG (Akumulasi Aktif):</span>
                        <span class="font-bold text-rose-600 text-sm"><?= number_format($activeQtyNG) ?> pcs</span>
                    </div>

                    <?php foreach ($allRoundsLots as $rIdx => $rRound): ?>
                        <?php 
                        $rNum = $rIdx + 1;
                        $rLotDefects = $defectsByRoundLotId[$rRound['session_lot_id']] ?? [];
                        $rNgQty = 0;
                        foreach ($rLotDefects as $rdf) {
                            if (empty($rdf['is_cancelled'])) {
                                $rNgQty += (int)$rdf['qty_ng'];
                            }
                        }
                        $rLabel = ($rNum === 1) ? 'Qty NG Temuan Awal (Ronde 1):' : "Qty NG Re-Inspeksi (Ronde {$rNum}):";
                        ?>
                        <div class="flex justify-between py-1 border-b border-slate-50">
                            <span class="text-slate-400"><?= $rLabel ?></span>
                            <span class="font-bold <?= $rNgQty > 0 ? 'text-rose-600' : 'text-slate-800' ?>"><?= number_format($rNgQty) ?> pcs</span>
                        </div>
                    <?php endforeach; ?>
                    <div class="flex justify-between py-1 border-b border-slate-50">
                        <span class="text-slate-400">Total Jenis Defect Recorded:</span>
                        <span class="font-semibold text-slate-800"><?= count($defectsList) ?> record</span>
                    </div>
                    <div class="flex justify-between items-center py-1 border-b border-slate-50">
                        <span class="text-slate-400">Status Lot Saat Ini:</span>
                        <span class="px-2 py-0.5 text-xs font-bold rounded border <?= $displayLotStatusClass ?>">
                            <?= $displayLotStatus ?>
                        </span>
                    </div>

                    <?php if ($isKanban && !empty($lotData['replacement_lot_number'])): ?>
                        <div class="p-2.5 bg-indigo-50 border border-indigo-100 rounded-lg text-indigo-900 mt-2">
                            <div class="font-semibold text-xs mb-0.5">Digantikan Oleh Lot/Box Pengganti:</div>
                            <div class="font-mono text-xs font-bold text-indigo-700">
                                Lot <?= sanitize($lotData['replacement_lot_number']) ?>
                                <?php if (!empty($lotData['replacement_ref_number'])): ?>
                                    (Ref: <?= sanitize($lotData['replacement_ref_number']) ?>)
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Card 3: Informasi Inspector & Shift -->
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 space-y-3">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                    <h2 class="text-sm font-bold text-slate-800 uppercase tracking-wider flex items-center gap-2">
                        <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Informasi Inspector & Shift Per Ronde
                    </h2>
                </div>

                <div class="text-xs space-y-2 text-slate-600">
                    <!-- Riwayat Status Inspeksi Per Ronde -->
                    <div class="space-y-2">
                        <?php foreach ($allRoundsLots as $rIdx => $rRound): ?>
                            <?php 
                            $rNum = $rIdx + 1;
                            $rRes = strtolower($rRound['lot_result'] ?? '');
                            $rResBadgeClass = ($rRes === 'passed') 
                                ? 'bg-emerald-100 text-emerald-800 border-emerald-200' 
                                : (($rRes === 'rejected') ? 'bg-rose-100 text-rose-800 border-rose-200' : 'bg-amber-100 text-amber-800 border-amber-200');
                            $rShiftName = getShiftName($rRound['session_started_at']);
                            ?>
                            <div class="p-2.5 bg-slate-50 rounded-lg border border-slate-200 text-xs space-y-1.5">
                                <div class="flex justify-between items-center font-semibold">
                                    <span class="text-slate-800 flex items-center gap-1.5 font-bold">
                                        <span class="w-1.5 h-1.5 rounded-full <?= ($rRes === 'passed') ? 'bg-emerald-500' : 'bg-rose-500' ?>"></span>
                                        Ronde <?= $rNum ?> <?= $rNum === 1 ? '(Inspeksi Awal)' : '(Re-Inspeksi)' ?>
                                    </span>
                                    <span class="px-2 py-0.5 text-[10px] font-bold rounded border <?= $rResBadgeClass ?>">
                                        <?= strtoupper($rRound['lot_result'] ?: 'IN_PROGRESS') ?>
                                    </span>
                                </div>
                                <div class="flex flex-wrap justify-between items-center text-[11px] text-slate-600 gap-1">
                                    <span>Inspector: <strong class="text-slate-900"><?= sanitize($rRound['inspector_name'] ?? 'Inspector QC') ?></strong> (<span class="font-semibold text-slate-700"><?= sanitize($rShiftName) ?></span>)</span>
                                    <span class="font-mono text-[10.5px] text-slate-500"><?= !empty($rRound['session_started_at']) ? date('d M Y H:i', strtotime($rRound['session_started_at'])) . ' WIB' : '-' ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

        </div>

        <!-- Section 2: Defect Detail Records Table -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="p-4 sm:p-5 bg-slate-50 border-b border-slate-200 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                <div>
                    <h3 class="text-base font-bold text-slate-800 flex items-center gap-2">
                        <svg class="w-5 h-5 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                        </svg>
                        Rincian Temuan Defect / NG pada Sample (Semua Ronde Inspeksi)
                    </h3>
                    <p class="text-xs text-slate-500 mt-0.5">Daftar item cacat fisik yang dicatat oleh inspector selama pengujian sample lot awal &amp; re-inspeksi</p>
                </div>
                <span class="px-3 py-1 bg-rose-50 text-rose-700 text-xs font-semibold rounded-full border border-rose-200 self-start sm:self-auto">
                    Total: <?= count($defectsList) ?> Record Defect (<?= number_format($activeQtyNG) ?> Pcs)
                </span>
            </div>

            <div class="overflow-x-auto min-w-full">
                <table class="w-full text-left text-xs text-slate-700 border-collapse">
                    <thead>
                        <tr class="bg-slate-100 text-slate-600 uppercase text-[11px] font-bold tracking-wider border-b border-slate-200">
                            <th class="py-3 px-4 w-12 text-center">No</th>
                            <th class="py-3 px-4">Sesi / Ronde</th>
                            <th class="py-3 px-4">Kode Defect</th>
                            <th class="py-3 px-4">Nama Defect</th>
                            <th class="py-3 px-4 text-right">Qty NG</th>
                            <th class="py-3 px-4">Waktu Temuan</th>
                            <th class="py-3 px-4">Inspector / Penemu</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($defectsList)): ?>
                            <tr>
                                <td colspan="7" class="py-8 text-center text-slate-400">
                                    Tidak ada data detail defect tercatat pada database.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($defectsList as $idx => $df): ?>
                                <tr class="hover:bg-slate-50 transition">
                                    <td class="py-3 px-4 text-center font-medium text-slate-500"><?= $idx + 1 ?></td>
                                    <td class="py-3 px-4">
                                        <span class="px-2 py-0.5 text-[11px] font-bold rounded <?= ($df['round_number'] ?? 1) == 1 ? 'bg-rose-100 text-rose-800 border border-rose-200' : 'bg-amber-100 text-amber-900 border border-amber-200' ?>">
                                            Ronde <?= $df['round_number'] ?? 1 ?> <?= ($df['round_number'] ?? 1) == 1 ? '(Awal)' : '(Re-Inspeksi)' ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 font-mono font-bold text-slate-900"><?= sanitize($df['defect_code'] ?? 'DEF-00') ?></td>
                                    <td class="py-3 px-4 font-semibold text-slate-800">
                                        <?= sanitize($df['defect_name'] ?? 'Unknown Defect') ?>
                                        <?php if (!empty($df['remark'])): ?>
                                            <span class="block text-[10px] text-slate-400 font-normal"><?= sanitize($df['remark']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4 text-right font-bold font-mono text-sm text-rose-600">
                                        <?= number_format((float)$df['qty_ng']) ?> Pcs
                                    </td>
                                    <td class="py-3 px-4 font-mono text-slate-600 text-xs">
                                        <?= !empty($df['defect_created_at']) ? date('d M Y H:i', strtotime($df['defect_created_at'])) . ' WIB' : '-' ?>
                                    </td>
                                    <td class="py-3 px-4 font-medium text-slate-800">
                                        <?= sanitize($df['defect_inspector_name'] ?? 'QC Inspector') ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Section 3: Traceability Timeline & Audit Trail -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <!-- Left 2 Cols: Timeline Steps -->
            <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-slate-200 p-5 sm:p-6 space-y-6">
                <div class="border-b border-slate-200 pb-4">
                    <h3 class="text-base font-bold text-slate-800 flex items-center gap-2">
                        <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Timeline Traceability & Riwayat Penanganan
                    </h3>
                    <p class="text-xs text-slate-500 mt-0.5">Alur histori sejak penemuan cacat awal hingga proses penyelesaian lot karantina</p>
                </div>

                <div class="relative pl-10 sm:pl-12 ml-6 border-l-2 border-slate-300 space-y-8">

                    <!-- Timeline Item 1: Initial Discovery (Inspeksi Awal - Ronde 1) -->
                    <?php 
                    $initialRoundLot = $allRoundsLots[0];
                    $initialRoundDefects = $activeDefectsByRoundLotId[$initialRoundLot['session_lot_id']] ?? [];
                    if (empty($initialRoundDefects)) {
                        $initialRoundDefects = array_filter($defectsList, function($d) { return empty($d['is_cancelled']); });
                    }
                    ?>
                    <div class="relative">
                        <div class="shadow-sm z-10" style="position: absolute; left: -1.05rem; top: 0.25rem; width: 2rem; height: 2rem; border-radius: 9999px; background-color: #f43f5e; color: #ffffff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.75rem;">
                            1
                        </div>
                        <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 space-y-2">
                            <div class="flex flex-col sm:flex-row sm:items-center justify-between text-xs gap-1 border-b border-slate-200 pb-2">
                                <span class="font-bold text-slate-900 text-sm flex items-center gap-1.5">
                                    <span class="px-2 py-0.5 bg-rose-100 text-rose-800 rounded text-[11px] font-bold">Ronde 1</span>
                                    Inspeksi Awal OQC
                                </span>
                                <span class="text-slate-500 font-mono text-[11px] font-semibold"><?= date('d M Y H:i', strtotime($initialRoundLot['session_started_at'])) ?> WIB</span>
                            </div>
                            <p class="text-xs text-slate-600">
                                Lot diinspeksi oleh <span class="font-semibold text-slate-800"><?= sanitize($initialRoundLot['inspector_name'] ?? 'Inspector') ?></span> pada <span class="font-bold text-slate-800"><?= date('d M Y H:i', strtotime($initialRoundLot['session_started_at'])) ?> WIB</span> (Shift <?= sanitize(getShiftName($initialRoundLot['session_started_at'])) ?>). 
                                Ditemukan total <span class="font-bold text-rose-600"><?= number_format($initialQtyNG) ?> pcs cacat</span> dari sample <?= number_format((float)$initialRoundLot['sample_size']) ?> pcs.
                            </p>
                            <div class="text-[11px] text-slate-700 bg-white p-2.5 rounded-lg border border-slate-200 space-y-1">
                                <div class="flex justify-between items-center font-mono">
                                    <span>Status Hasil Uji Awal:</span>
                                    <span class="font-bold text-rose-600 uppercase"><?= strtoupper($initialRoundLot['lot_result'] ?: 'REJECTED') ?></span>
                                </div>
                                <?php if (!empty($initialRoundDefects)): ?>
                                    <div class="pt-2 text-slate-700 text-xs space-y-1.5 border-t border-slate-200 mt-2">
                                        <span class="font-bold text-slate-800 text-xs block">Defect Terdeteksi di Ronde 1:</span>
                                        <div class="overflow-x-auto rounded-lg border border-slate-200">
                                            <table class="w-full text-left text-xs bg-white border-collapse">
                                                <thead class="bg-slate-100 text-slate-700 text-[11px] uppercase font-bold tracking-wider border-b border-slate-200">
                                                    <tr>
                                                        <th class="py-2.5 px-3">Kode Defect</th>
                                                        <th class="py-2.5 px-3">Nama Defect</th>
                                                        <th class="py-2.5 px-3 text-right">Qty NG</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-slate-100 font-medium text-slate-800">
                                                    <?php foreach ($initialRoundDefects as $idfx): ?>
                                                        <tr class="hover:bg-slate-50">
                                                            <td class="py-2.5 px-3 font-mono font-bold text-slate-900 text-xs sm:text-sm"><?= sanitize($idfx['defect_code']) ?></td>
                                                            <td class="py-2.5 px-3 font-semibold text-slate-800 text-xs sm:text-sm"><?= sanitize($idfx['defect_name']) ?></td>
                                                            <td class="py-2.5 px-3 text-right font-mono font-bold text-rose-600 text-xs sm:text-sm"><?= number_format((float)$idfx['qty_ng']) ?> pcs</td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Timeline Item 2: Supervisor / Chief Verification (Ronde 1) -->
                    <div class="relative">
                        <?php 
                        $isInitialApproved = !empty($initialRoundLot['is_chief_approved']) || !empty($initialRoundLot['chief_approved_at']);
                        $initialBadgeBg = $isInitialApproved ? '#10b981' : '#f59e0b';
                        ?>
                        <div class="shadow-sm z-10" style="position: absolute; left: -1.05rem; top: 0.25rem; width: 2rem; height: 2rem; border-radius: 9999px; background-color: <?= $initialBadgeBg ?>; color: #ffffff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.75rem;">
                            2
                        </div>
                        <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 space-y-2">
                            <div class="flex flex-col sm:flex-row sm:items-center justify-between text-xs gap-1 border-b border-slate-200 pb-2">
                                <span class="font-bold text-slate-900 text-sm flex items-center gap-1.5">
                                    Review &amp; Approval Chief / Supervisor QA
                                    <?php if ($isInitialApproved): ?>
                                        <span class="px-2 py-0.5 bg-emerald-100 text-emerald-800 rounded text-[11px] font-bold">Disetujui</span>
                                    <?php else: ?>
                                        <span class="px-2 py-0.5 bg-amber-100 text-amber-800 rounded text-[11px] font-bold">Menunggu Approval</span>
                                    <?php endif; ?>
                                </span>
                                <span class="text-slate-500 font-mono text-[11px] font-semibold">
                                    <?= !empty($initialRoundLot['chief_approved_at']) ? date('d M Y H:i', strtotime($initialRoundLot['chief_approved_at'])) . ' WIB' : 'Menunggu Approval' ?>
                                </span>
                            </div>

                            <?php if ($isInitialApproved): ?>
                                <p class="text-xs text-emerald-800 font-semibold bg-emerald-50 p-2.5 rounded-lg border border-emerald-200 flex items-center gap-1.5">
                                    <svg class="w-4 h-4 text-emerald-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    <span>Disetujui (ACC) oleh Chief QC: <span class="font-bold text-emerald-950"><?= sanitize($initialRoundLot['chief_name'] ?: 'Chief QA') ?></span> pada <span class="font-mono"><?= date('d M Y H:i', strtotime($initialRoundLot['chief_approved_at'])) ?> WIB</span>.</span>
                                </p>
                            <?php else: ?>
                                <p class="text-xs text-slate-500 italic bg-white p-2.5 rounded-lg border border-slate-200">
                                    Sesi awal belum di-approve (ACC) oleh Chief QA.
                                </p>
                            <?php endif; ?>

                            <!-- Catatan Disposisi Tambahan Supervisor -->
                            <?php if (!empty($initialRoundLot['chief_notes']) || !empty($lotData['action_notes'])): ?>
                                <div class="text-xs text-blue-900 bg-blue-50 p-2.5 rounded-lg border border-blue-200 mt-2">
                                    <div class="flex justify-between items-center mb-1">
                                        <span class="font-bold">Instruksi / Catatan Disposisi Penanganan:</span>
                                        <span class="text-[10px] text-blue-600 font-mono">
                                            <?= !empty($initialRoundLot['chief_approved_at']) ? date('d/m/Y H:i', strtotime($initialRoundLot['chief_approved_at'])) : '' ?>
                                        </span>
                                    </div>
                                    <p><?= sanitize($initialRoundLot['chief_notes'] ?: $lotData['action_notes']) ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Timeline Item 3+: Subsequent Inspection Rounds (Ronde 2, Ronde 3, dst.) -->
                    <?php 
                    $stepNumber = 2;
                    for ($rIdx = 1; $rIdx < count($allRoundsLots); $rIdx++):
                        $rRound = $allRoundsLots[$rIdx];
                        $roundNum = $rIdx + 1;
                        $stepNumber++;
                        $badgeRoundStep = $stepNumber;
                        $stepNumber++;
                        $badgeApproveStep = $stepNumber;

                        $rDefects = $activeDefectsByRoundLotId[$rRound['session_lot_id']] ?? [];
                        $rAllDefects = $defectsByRoundLotId[$rRound['session_lot_id']] ?? [];
                        $rResult = strtolower($rRound['lot_result'] ?? '');
                        $isRoundPassed = ($rResult === 'passed');
                        $isRoundApproved = !empty($rRound['is_chief_approved']) || !empty($rRound['chief_approved_at']);

                        $roundBadgeBg = (!empty($rDefects) || $rResult === 'rejected') 
                            ? '#f43f5e' 
                            : ($isRoundPassed ? '#10b981' : '#f59e0b');
                    ?>
                        <!-- Step <?= $badgeRoundStep ?>: Re-Inspeksi & Hasil Ulang (Ronde <?= $roundNum ?>) -->
                        <div class="relative">
                            <div class="shadow-sm z-10" style="position: absolute; left: -1.05rem; top: 0.25rem; width: 2rem; height: 2rem; border-radius: 9999px; background-color: <?= $roundBadgeBg ?>; color: #ffffff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.75rem;">
                                <?= $badgeRoundStep ?>
                            </div>
                            <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 space-y-3">
                                <div class="flex flex-col sm:flex-row sm:items-center justify-between text-xs gap-1 border-b border-slate-200 pb-2">
                                    <span class="font-bold text-slate-900 text-sm flex items-center gap-1.5">
                                        <span class="px-2 py-0.5 bg-amber-100 text-amber-900 rounded text-[11px] font-bold">Ronde <?= $roundNum ?></span>
                                        Re-Inspeksi &amp; Uji Sampel Fisik
                                    </span>
                                    <span class="text-slate-500 font-mono text-[11px] font-semibold">
                                        <?= date('d M Y H:i', strtotime($rRound['session_started_at'])) ?> WIB
                                    </span>
                                </div>

                                <div class="space-y-2">
                                    <p class="text-xs text-slate-600">
                                        Pengujian ulang sampel fisik dilakukan oleh <span class="font-bold text-slate-800"><?= sanitize($rRound['inspector_name'] ?? 'QC Inspector') ?></span> pada <span class="font-bold text-slate-800"><?= date('d M Y H:i', strtotime($rRound['session_started_at'])) ?> WIB</span> (Shift <?= sanitize(getShiftName($rRound['session_started_at'])) ?>).
                                    </p>

                                    <div class="flex justify-between items-center text-xs">
                                        <span class="text-slate-600">Hasil Re-Inspeksi Ronde <?= $roundNum ?>:</span>
                                        <span class="font-semibold px-2 py-0.5 rounded text-[11px] <?= $isRoundPassed ? 'bg-emerald-100 text-emerald-800 border-emerald-200' : (!empty($rDefects) || $rResult === 'rejected' ? 'bg-rose-100 text-rose-800 border-rose-200' : 'bg-amber-100 text-amber-800 border-amber-200') ?>">
                                            <?= strtoupper($rRound['lot_result'] ?: 'in_progress') ?>
                                        </span>
                                    </div>

                                    <?php if (!empty($rDefects)): ?>
                                        <div class="pt-2 text-slate-700 text-xs space-y-1.5 border-t border-rose-200 mt-2">
                                            <span class="font-bold text-rose-900 text-xs block">Temuan Defect Baru Saat Re-Inspeksi Ronde <?= $roundNum ?>:</span>
                                            <div class="overflow-x-auto rounded-lg border border-rose-200">
                                                <table class="w-full text-left text-xs bg-white border-collapse">
                                                    <thead class="bg-rose-100/70 text-rose-900 text-[11px] uppercase font-bold tracking-wider border-b border-rose-200">
                                                        <tr>
                                                            <th class="py-2.5 px-3">Kode Defect</th>
                                                            <th class="py-2.5 px-3">Nama Defect</th>
                                                            <th class="py-2.5 px-3 text-right">Qty NG</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y divide-rose-100 font-medium text-slate-800">
                                                        <?php foreach ($rDefects as $rdf): ?>
                                                            <tr class="hover:bg-rose-50/50">
                                                                <td class="py-2.5 px-3 font-mono font-bold text-slate-900 text-xs sm:text-sm"><?= sanitize($rdf['defect_code']) ?></td>
                                                                <td class="py-2.5 px-3 font-semibold text-slate-800 text-xs sm:text-sm"><?= sanitize($rdf['defect_name']) ?></td>
                                                                <td class="py-2.5 px-3 text-right font-mono font-bold text-rose-600 text-xs sm:text-sm"><?= number_format((float)$rdf['qty_ng']) ?> pcs</td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    <?php elseif (!empty($rAllDefects)): ?>
                                        <p class="text-xs text-slate-600 bg-slate-100 p-2 rounded border border-slate-200 italic flex items-center gap-1.5">
                                            <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                            <span>Seluruh catatan defect pada ronde ini telah dibatalkan oleh operator.</span>
                                        </p>
                                    <?php elseif ($isRoundPassed): ?>
                                        <p class="text-xs text-emerald-700 bg-emerald-50 p-2 rounded border border-emerald-200 font-semibold flex items-center gap-1.5">
                                            <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                            <span>Semua sampel fisik lulus uji ulang (PASSED) dan tidak ditemukan defect lagi.</span>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Step <?= $badgeApproveStep ?>: Review & Approval Chief QA (Ronde <?= $roundNum ?>) -->
                        <div class="relative">
                            <?php
                            $badgeApproveBg = ($isRoundApproved || $isRoundPassed) ? '#10b981' : '#f59e0b';
                            ?>
                            <div class="shadow-sm z-10" style="position: absolute; left: -1.05rem; top: 0.25rem; width: 2rem; height: 2rem; border-radius: 9999px; background-color: <?= $badgeApproveBg ?>; color: #ffffff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.75rem;">
                                <?= $badgeApproveStep ?>
                            </div>
                            <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 space-y-2">
                                <div class="flex flex-col sm:flex-row sm:items-center justify-between text-xs gap-1 border-b border-slate-200 pb-2">
                                    <span class="font-bold text-slate-900 text-sm">Approval Chief QA (Re-Inspeksi Ronde <?= $roundNum ?>)</span>
                                    <span class="text-slate-500 font-mono text-[11px]">
                                        <?= $isRoundApproved ? date('d M Y H:i', strtotime($rRound['chief_approved_at'])) . ' WIB' : ($isRoundPassed ? 'SELESAI (PASSED)' : 'BELUM APPROVED') ?>
                                    </span>
                                </div>

                                <?php if ($isRoundApproved): ?>
                                    <p class="text-xs text-emerald-800 font-semibold bg-emerald-50 p-2.5 rounded-lg border border-emerald-200 flex items-center gap-1.5">
                                        <svg class="w-4 h-4 text-emerald-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                        <span>Disetujui (ACC) oleh Chief QC: <span class="font-bold text-emerald-950"><?= sanitize($rRound['chief_name'] ?? 'Chief QA') ?></span> pada <span class="font-mono"><?= date('d M Y H:i', strtotime($rRound['chief_approved_at'])) ?> WIB</span>.</span>
                                    </p>
                                <?php elseif ($isRoundPassed): ?>
                                    <p class="text-xs text-emerald-700 font-semibold bg-emerald-50 p-2.5 rounded-lg border border-emerald-200 flex items-center gap-2">
                                        <svg class="w-4 h-4 text-emerald-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                        <span>Lot telah dinyatakan <b>PASSED (LULUS RE-INSPEKSI)</b> pada Ronde <?= $roundNum ?>.</span>
                                    </p>
                                <?php else: ?>
                                    <div class="p-3 bg-amber-50 border border-amber-200 rounded-lg text-xs space-y-2">
                                        <p class="text-amber-900 font-semibold">
                                            Hasil re-inspeksi Ronde <?= $roundNum ?> lot ini (REJECTED) belum disetujui (ACC) oleh Chief QC.
                                        </p>
                                        <a href="<?= base_url('modules/inspection/print_rejection.php?session_lot_id=' . $rRound['session_lot_id']) ?>" 
                                           target="_blank"
                                           class="inline-flex items-center px-3 py-1.5 text-xs font-bold text-amber-800 bg-amber-100 hover:bg-amber-200 border border-amber-300 rounded transition">
                                            <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
                                            </svg>
                                            Input Tanda Tangan / Approval Chief QC Ronde <?= $roundNum ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endfor; ?>

                    <?php if ($isKanban && $hasReplacementBox): ?>
                        <!-- Info Box: Catatan Disposisi Penggantian Box -->
                        <div class="relative">
                            <?php $stepNumber++; ?>
                            <div class="shadow-sm z-10" style="position: absolute; left: -1.05rem; top: 0.25rem; width: 2rem; height: 2rem; border-radius: 9999px; background-color: #6366f1; color: #ffffff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.75rem;">
                                <?= $stepNumber ?>
                            </div>
                            <div class="bg-indigo-50/70 border border-indigo-200 rounded-xl p-4 space-y-2">
                                <div class="flex flex-col sm:flex-row sm:items-center justify-between text-xs gap-1 border-b border-indigo-200/80 pb-2">
                                    <span class="font-bold text-indigo-950 text-sm flex items-center gap-1.5">
                                        <span class="px-2 py-0.5 bg-indigo-100 text-indigo-800 rounded text-[11px] font-bold">Catatan Disposisi</span>
                                        Pendaftaran Box Pengganti
                                    </span>
                                    <span class="text-indigo-600 font-mono text-[11px] font-semibold">
                                        <?= !empty($lotData['chief_approved_at']) ? date('d M Y H:i', strtotime($lotData['chief_approved_at'])) . ' WIB' : 'Tercatat' ?>
                                    </span>
                                </div>
                                <p class="text-xs text-indigo-900 leading-relaxed">
                                    No. Kanban <b><?= sanitize($lotData['lot_number']) ?></b>
                                    <?php if (!empty($lotData['ref_number'])): ?>
                                        (Ref: <b><?= sanitize($lotData['ref_number']) ?></b>)
                                    <?php endif; ?>
                                    memiliki catatan pendaftaran box pengganti.
                                    <?php if (!empty($lotData['replacement_lot_number'])): ?>
                                        <br>Digantikan oleh Lot No <b><?= sanitize($lotData['replacement_lot_number']) ?></b>
                                        <?php if (!empty($lotData['replacement_ref_number'])): ?>
                                            (Ref: <b><?= sanitize($lotData['replacement_ref_number']) ?></b>)
                                        <?php endif; ?>.
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                    <?php endif; ?>

                </div>
            </div>

            <!-- Card Penutupan Karantina Administratif (Divisi Lain) -->
            <?php if ($quarantineClosure): ?>
            <div class="bg-sky-50 rounded-xl shadow-sm border border-sky-200 p-5 space-y-3 h-fit">
                <div class="border-b border-sky-200 pb-3 flex items-center justify-between">
                    <h3 class="text-sm font-bold text-sky-950 flex items-center gap-2 uppercase tracking-wider">
                        <svg class="w-4 h-4 text-sky-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Disposisi Selesai (Divisi)
                    </h3>
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-sky-200 text-sky-900 border border-sky-300">
                        MEMO / SELESAI
                    </span>
                </div>
                <div class="text-xs space-y-2 text-sky-900">
                    <p class="leading-relaxed bg-white p-3 rounded-lg border border-sky-200 font-medium">
                        <?= nl2br(sanitize($quarantineClosure['notes'])) ?>
                    </p>
                    <div class="pt-2 border-t border-sky-200/70 text-[11px] text-sky-800 flex justify-between">
                        <span>Ditutup Oleh: <b><?= sanitize($quarantineClosure['closed_by_name'] ?? 'Admin QC') ?></b></span>
                        <span class="font-mono"><?= date('d M Y H:i', strtotime($quarantineClosure['closed_at'])) ?> WIB</span>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Right 1 Col: Supervisor Action Form / Quick Notes -->
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 space-y-4 h-fit">
                <div class="border-b border-slate-200 pb-3">
                    <h3 class="text-sm font-bold text-slate-800 flex items-center gap-2 uppercase tracking-wider">
                        <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                        </svg>
                        Disposisi / Catatan SPV
                    </h3>
                    <p class="text-xs text-slate-500 mt-0.5">Input instruksi penanganan lot karantina untuk tim inspeksi / produksi</p>
                </div>

                <form action="<?= base_url('modules/ng_quarantine/detail.php?id=' . $lotId) ?>" method="POST" class="space-y-4">
                    <input type="hidden" name="action_type" value="update_disposition">

                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">
                            Instruksi Disposisi Penanganan:
                        </label>
                        <textarea 
                            name="action_notes" 
                            rows="5" 
                            class="w-full px-3 py-2 text-xs border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition placeholder-slate-400"
                            placeholder="Contoh: Lakukankan sortir 100% pada area defect permukaan. Lot di-reinspeksi ulang dengan sample 2x normal..."
                            required><?= sanitize($lotData['action_notes'] ?? '') ?></textarea>
                    </div>

                    <button 
                        type="submit" 
                        class="w-full py-2.5 px-4 bg-blue-600 text-white font-semibold text-xs rounded-lg hover:bg-blue-700 transition shadow-sm flex items-center justify-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                        Simpan Catatan Disposisi
                    </button>
                </form>

                <div class="pt-4 border-t border-slate-100 space-y-2 text-xs text-slate-500">
                    <span class="font-bold text-slate-700 block">Panduan Penanganan Lot NG:</span>
                    <ul class="list-disc pl-4 space-y-1 text-[11px] text-slate-600">
                        <li><strong>Re-Inspeksi 100%:</strong> Inspector melakukan verifikasi ulang per unit pada lot yang disortir.</li>
                        <li><strong>Penggantian Lot:</strong> Jika lot di-scrap/return ke supplier, buat lot pengganti baru via sistem.</li>
                    </ul>
                </div>
            </div>

        </div>

    </div>
</main>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
