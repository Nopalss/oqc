<?php
/**
 * Modul Safety Stock - Detail Realisasi Safety Stock
 * Halaman rincian hasil inspeksi OQC, Lot Number, breakdown label box (Ref No), dan riwayat pemakaian memotong Kanban.
 */
$breadcrumbCategory = "OPERASIONAL";
$pageTitle = "Detail Realisasi Safety Stock";
$pageSubtitle = "Rincian hasil inspeksi OQC, Lot Number, breakdown label box (Ref No), dan riwayat pemakaian memotong Kanban";

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

$itemId         = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$partCodeParam  = sanitize($_GET['part_code'] ?? '');
$lotNumberParam = sanitize($_GET['lot_number'] ?? '');

$pdo = getDB();
$item = null;
$sessions = [];
$sessionLots = [];
$kanbanUsage = [];
$defects = [];

if ($pdo) {
    try {
        // If part_code and lot_number not explicitly passed or if id is passed, lookup target session
        if ($itemId > 0) {
            $stLookup = $pdo->prepare("
                SELECT ss.id, ss.did_id, ss.kanban_item_id,
                       did.part_code AS did_pcode, did.lot_number AS did_lotnum,
                       ki.item_code AS ki_pcode, ki.kanban_no AS ki_kanban_no
                FROM inspection_sessions ss
                LEFT JOIN daily_inspection_data did ON did.id = ss.did_id
                LEFT JOIN kanban_items ki ON ki.id = ss.kanban_item_id
                WHERE ss.id = :id
            ");
            $stLookup->execute([':id' => $itemId]);
            $lk = $stLookup->fetch(PDO::FETCH_ASSOC);
            if ($lk) {
                if (empty($partCodeParam)) {
                    $partCodeParam = $lk['did_pcode'] ?: $lk['ki_pcode'];
                }
                if (empty($lotNumberParam)) {
                    $rawLot = $lk['did_lotnum'] ?: $lk['ki_kanban_no'];
                    $lotNumberParam = preg_replace('/^SS-/i', '', $rawLot);
                }
            }
        }

        $cleanLot = preg_replace('/^SS-/i', '', $lotNumberParam);

        if (!empty($partCodeParam) || !empty($lotNumberParam) || $itemId > 0) {
            // 1. Fetch All Safety Stock Sessions for this Part Code & Lot Number (or matching session ID)
            $stmtSess = $pdo->prepare("
                SELECT ss.*, 
                       u.name AS inspector_user_name, u.username AS inspector_username,
                       did.pic AS did_pic, did.lot_number AS did_lot, did.part_name AS did_part_name, did.part_code AS did_part_code,
                       ki.customer AS ki_customer, ki.str_loc AS ki_str_loc, ki.item_description AS ki_desc
                FROM inspection_sessions ss
                LEFT JOIN users u ON ss.inspector_id = u.id
                LEFT JOIN daily_inspection_data did ON did.id = ss.did_id
                LEFT JOIN kanban_items ki ON ki.id = ss.kanban_item_id
                WHERE ss.inspection_type = 'safety_stock'
                  AND (
                      (
                          UPPER(COALESCE(did.part_code, ki.item_code, '')) = UPPER(:pcode)
                          AND (
                              UPPER(COALESCE(did.lot_number, '')) = UPPER(:lotnum)
                              OR UPPER(COALESCE(did.lot_number, '')) = UPPER(:clean_lot)
                              OR UPPER(COALESCE(ki.kanban_no, '')) = UPPER(CONCAT('SS-', :clean_lot2))
                          )
                      )
                      OR ss.id = :direct_id
                  )
                ORDER BY ss.id ASC
            ");
            $stmtSess->execute([
                ':pcode'      => $partCodeParam,
                ':lotnum'     => $lotNumberParam,
                ':clean_lot'  => $cleanLot,
                ':clean_lot2' => $cleanLot,
                ':direct_id'  => $itemId
            ]);
            $sessions = $stmtSess->fetchAll(PDO::FETCH_ASSOC);

            // Fallback lookup if no sessions matched by filters
            if (empty($sessions) && $itemId > 0) {
                $stmtFallback = $pdo->prepare("
                    SELECT ss.*, 
                           u.name AS inspector_user_name, u.username AS inspector_username,
                           did.pic AS did_pic, did.lot_number AS did_lot, did.part_name AS did_part_name, did.part_code AS did_part_code,
                           ki.customer AS ki_customer, ki.str_loc AS ki_str_loc, ki.item_description AS ki_desc
                    FROM inspection_sessions ss
                    LEFT JOIN users u ON ss.inspector_id = u.id
                    LEFT JOIN daily_inspection_data did ON did.id = ss.did_id
                    LEFT JOIN kanban_items ki ON ki.id = ss.kanban_item_id
                    WHERE ss.id = :id
                ");
                $stmtFallback->execute([':id' => $itemId]);
                $sessions = $stmtFallback->fetchAll(PDO::FETCH_ASSOC);
            }

            if (!empty($sessions)) {
                $firstSess = $sessions[0];
                $item = [
                    'item_code'        => $firstSess['did_part_code'] ?: ($firstSess['part_code'] ?? ($partCodeParam ?: '-')),
                    'item_description' => $firstSess['did_part_name'] ?: ($firstSess['ki_desc'] ?? 'Part Safety Stock'),
                    'lot_number'       => $firstSess['did_lot'] ?: ($lotNumberParam ?: '-'),
                    'customer'         => $firstSess['ki_customer'] ?: 'INTERNAL SAFETY STOCK',
                    'str_loc'          => $firstSess['ki_str_loc'] ?: 'WH-SAFETY'
                ];

                $sessionIds = array_column($sessions, 'id');
                $inQueryInt = implode(',', array_map('intval', $sessionIds));

                // 2. Fetch Breakdown Label Scan (Ref Numbers) ordered by id ASC so first scan comes first
                $stmtLots = $pdo->prepare("
                    SELECT isl.*, ss.started_at AS scanned_at, ss.status AS session_status, ss.auto_fulfilled_by_session_id,
                           COALESCE(u.name, did.pic, 'Inspector QC') AS inspector_name
                    FROM inspection_session_lots isl
                    JOIN inspection_sessions ss ON ss.id = isl.inspection_session_id
                    LEFT JOIN daily_inspection_data did ON did.id = ss.did_id
                    LEFT JOIN users u ON u.id = ss.inspector_id
                    WHERE ss.id IN ({$inQueryInt})
                    ORDER BY isl.id ASC
                ");
                $stmtLots->execute();
                $sessionLots = $stmtLots->fetchAll(PDO::FETCH_ASSOC);

                // 3. Fetch Kanban Usage History with Session Details (Kanban Journey & Session Tracking)
                $stmtKanban = $pdo->prepare("
                    SELECT 
                        s_kanban.id AS kanban_session_id,
                        s_kanban.started_at AS used_at,
                        s_kanban.closed_at AS closed_at,
                        s_kanban.status AS session_status,
                        s_kanban.samples_checked,
                        s_kanban.sample_size,
                        s_kanban.ng_count,
                        s_ss.id AS ss_session_id,
                        COALESCE(isl_k.ref_number, (SELECT isl_ss.ref_number FROM inspection_session_lots isl_ss WHERE isl_ss.inspection_session_id = s_ss.id LIMIT 1), '-') AS ref_number,
                        COALESCE(isl_k.qty, s_ss.total_scanned_qty, 0) AS used_qty,
                        ki.kanban_no,
                        ki.customer,
                        COALESCE(u.name, 'Inspector QC') AS inspector_name
                    FROM inspection_sessions s_ss
                    JOIN inspection_sessions s_kanban ON s_kanban.id = s_ss.auto_fulfilled_by_session_id
                    LEFT JOIN kanban_items ki ON ki.id = s_kanban.kanban_item_id
                    LEFT JOIN users u ON u.id = s_kanban.inspector_id
                    LEFT JOIN inspection_session_lots isl_k ON (isl_k.inspection_session_id = s_kanban.id AND (isl_k.remarks LIKE CONCAT('%Sesi #', s_ss.id, '%') OR isl_k.remarks LIKE CONCAT('%Session #', s_ss.id, '%') OR isl_k.remarks LIKE CONCAT('%', s_ss.id, '%')))
                    WHERE s_ss.id IN ({$inQueryInt}) AND s_ss.auto_fulfilled_by_session_id > 0
                    ORDER BY s_kanban.id DESC, s_ss.id ASC
                ");
                $stmtKanban->execute();
                $kanbanUsage = $stmtKanban->fetchAll(PDO::FETCH_ASSOC);

                // 4. Fetch Defect / NG Records
                $stmtDef = $pdo->prepare("
                    SELECT ng.*, dt.name AS defect_type_name, sm.sample_number, sm.inspection_session_id, ss.started_at, ss.closed_at,
                           (SELECT GROUP_CONCAT(DISTINCT isl.ref_number SEPARATOR ', ') 
                            FROM inspection_session_lots isl 
                            WHERE isl.inspection_session_id = ss.id) AS ref_number,
                           COALESCE(u.name, did.pic, '-') AS inspector_name
                    FROM inspection_ng_records ng
                    JOIN inspection_samples sm ON sm.id = ng.inspection_sample_id
                    JOIN inspection_sessions ss ON ss.id = sm.inspection_session_id
                    LEFT JOIN users u ON ss.inspector_id = u.id
                    LEFT JOIN daily_inspection_data did ON ss.did_id = did.id
                    LEFT JOIN defect_types dt ON ng.defect_type_id = dt.id
                    WHERE sm.inspection_session_id IN ({$inQueryInt})
                    ORDER BY ng.id ASC
                ");
                $stmtDef->execute();
                $defects = $stmtDef->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    } catch (PDOException $e) {
        if (!$item) {
            set_flash('error', 'Gagal memuat data Safety Stock: ' . $e->getMessage());
        }
    }
}

if (!$item) {
    set_flash('error', 'Data Item Safety Stock tidak ditemukan.');
    redirect('modules/safety_stock/index.php');
}

// Build Session Lookup Map
$sessionMap = [];
foreach ($sessions as $s) {
    $sessionMap[$s['id']] = $s;
}

// Group Session Lots into Ref Number Cards
// Note: The earliest record for a ref_number represents the true initial scanned box qty.
// Subsequent records for the same ref_number are split sessions created upon Kanban deduction.
$refGroups = [];
if (!empty($sessionLots)) {
    foreach ($sessionLots as $sl) {
        $refKey = !empty($sl['ref_number']) ? $sl['ref_number'] : ('REF-LOT-' . $sl['id']);
        if (!isset($refGroups[$refKey])) {
            $parentSess = $sessionMap[$sl['inspection_session_id']] ?? null;
            $inspectorName = $sl['inspector_name'];
            if (!$inspectorName && $parentSess) {
                $inspectorName = $parentSess['inspector_user_name'] ?: ($parentSess['did_pic'] ?: 'Inspector QC');
            }
            $refGroups[$refKey] = [
                'ref_number'            => $sl['ref_number'] ?: '-',
                'lot_number'            => $sl['lot_number'] ?: ($item['lot_number'] ?? '-'),
                'qty'                   => (int)$sl['qty'], // Initial box qty from first scan
                'scanned_at'            => $sl['scanned_at'],
                'inspector_name'        => $inspectorName ?: 'Inspector QC',
                'inspection_session_id' => $sl['inspection_session_id'],
                'parent_session'        => $parentSess,
                'kanban_history'        => [],
                'used_qty'              => 0,
            ];
        } else {
            // Keep the maximum recorded initial qty for this ref_number
            if ((int)$sl['qty'] > $refGroups[$refKey]['qty']) {
                $refGroups[$refKey]['qty'] = (int)$sl['qty'];
            }
        }
    }
} else {
    // Fallback if no session lots exist
    foreach ($sessions as $sIdx => $s) {
        $refKey = 'REF-SS-' . $s['id'];
        $inspectorName = !empty($s['inspector_user_name']) ? $s['inspector_user_name'] : (!empty($s['did_pic']) ? $s['did_pic'] : 'Inspektur QC');
        $refGroups[$refKey] = [
            'ref_number'            => '-',
            'lot_number'            => $item['lot_number'] ?? '-',
            'qty'                   => (int)$s['total_scanned_qty'],
            'scanned_at'            => $s['started_at'],
            'inspector_name'        => $inspectorName,
            'inspection_session_id' => $s['id'],
            'parent_session'        => $s,
            'kanban_history'        => [],
            'used_qty'              => 0,
        ];
    }
}

// Attach Kanban Usage Records to Matching Ref Group
foreach ($kanbanUsage as $ku) {
    $matched = false;
    $targetRef = $ku['ref_number'];
    
    if ($targetRef !== '-' && isset($refGroups[$targetRef])) {
        $refGroups[$targetRef]['kanban_history'][] = $ku;
        $refGroups[$targetRef]['used_qty'] += (int)$ku['used_qty'];
        $matched = true;
    }
    
    if (!$matched && !empty($ku['ss_session_id'])) {
        foreach ($refGroups as $rKey => &$rg) {
            if ($rg['inspection_session_id'] == $ku['ss_session_id']) {
                $rg['kanban_history'][] = $ku;
                $rg['used_qty'] += (int)$ku['used_qty'];
                $matched = true;
                break;
            }
        }
        unset($rg);
    }

    if (!$matched && count($refGroups) === 1) {
        $firstKey = array_key_first($refGroups);
        $refGroups[$firstKey]['kanban_history'][] = $ku;
        $refGroups[$firstKey]['used_qty'] += (int)$ku['used_qty'];
    }
}

// Compute Summary Metrics
$sessionCount      = count($sessions);
$hasRejected       = false;
$hasInProgress     = false;
$hasPassed         = false;
$totalChecked      = 0;
$totalNg           = 0;
$maxSampleSize     = 0;
$minStartedAt      = null;
$maxClosedAt       = null;

foreach ($sessions as $s) {
    if ($s['status'] === 'rejected') $hasRejected = true;
    if ($s['status'] === 'in_progress') $hasInProgress = true;
    if ($s['status'] === 'passed') $hasPassed = true;

    $totalChecked = max($totalChecked, (int)$s['samples_checked']);
    $totalNg += (int)$s['ng_count'];
    $maxSampleSize = max($maxSampleSize, (int)$s['sample_size']);

    if (!empty($s['started_at'])) {
        if ($minStartedAt === null || strtotime($s['started_at']) < strtotime($minStartedAt)) {
            $minStartedAt = $s['started_at'];
        }
    }

    if (!empty($s['closed_at'])) {
        if ($maxClosedAt === null || strtotime($s['closed_at']) > strtotime($maxClosedAt)) {
            $maxClosedAt = $s['closed_at'];
        }
    }
}

// Compute accurate initial stock and used stock from refGroups
$totalInitialStock = 0;
$totalUsedQty      = 0;
foreach ($refGroups as $rg) {
    $totalInitialStock += (int)$rg['qty'];
    $totalUsedQty      += (int)$rg['used_qty'];
}
$availableStock = max(0, $totalInitialStock - $totalUsedQty);

if ($sessionCount == 0) {
    $overallBadgeHtml = '<span style="background-color: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; padding: 4px 12px; border-radius: 9999px; font-weight: 800; font-size: 11px;">BELUM MULAI</span>';
} elseif ($hasRejected) {
    $overallBadgeHtml = '<span style="background-color: #ffe4e6; color: #9f1239; border: 1px solid #fecdd3; padding: 4px 12px; border-radius: 9999px; font-weight: 800; font-size: 11px;">REJECTED (NG)</span>';
} elseif ($hasInProgress) {
    $overallBadgeHtml = '<span style="background-color: #fffbe6; color: #92400e; border: 1px solid #ffe58f; padding: 4px 12px; border-radius: 9999px; font-weight: 800; font-size: 11px;">IN PROGRESS</span>';
} else {
    $overallBadgeHtml = '<span style="background-color: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; padding: 4px 12px; border-radius: 9999px; font-weight: 700; font-size: 11px;">PASSED (LOLOS)</span>';
}

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';
?>

<!-- Main Content Wrapper with Sidebar Offset (md:pl-64) -->
<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen min-w-0 w-full overflow-x-hidden transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-4 space-y-4 min-w-0 w-full overflow-x-hidden">
        
        <?= render_flash() ?>

        <!-- 1. Header Banner Card -->
        <div style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); border-radius: 12px; padding: 12px 16px; color: #ffffff; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <div style="display: flex; align-items: center; gap: 12px;">
                <a href="<?= base_url('modules/safety_stock/index.php') ?>" style="background-color: #334155; color: #ffffff; border-radius: 8px; padding: 6px 10px; font-size: 11px; font-weight: 700; border: none; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;" title="Kembali ke Monitoring Safety Stock">
                    <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                    </svg>
                    <span>Kembali</span>
                </a>
                <div>
                    <h2 style="font-size: 14px; font-weight: 800; margin: 0; line-height: 1.2; display: flex; align-items: center; gap: 8px;">
                        <span><?= htmlspecialchars($item['item_code']) ?></span>
                        <span style="color: #64748b;">|</span>
                        <span style="color: #cbd5e1; font-weight: 500; font-size: 13px;"><?= htmlspecialchars($item['item_description']) ?></span>
                    </h2>
                    <p style="font-size: 11px; color: #94a3b8; margin-top: 2px;">
                        Lot Number: <b style="color: #93c5fd; font-family: monospace;"><?= htmlspecialchars($item['lot_number']) ?></b>
                        <?php if (!empty($item['customer']) && strtoupper(trim($item['customer'])) !== 'INTERNAL SAFETY STOCK'): ?>
                            | Customer: <b style="color: #ffffff;"><?= htmlspecialchars($item['customer']) ?></b>
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <div>
                <?= $overallBadgeHtml ?>
            </div>
        </div>

        <!-- 2. GRID 4 KPI SUMMARY CARDS -->
        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px;">
            <div style="background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px 14px; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
                <span style="font-size: 10px; font-weight: 800; color: #64748b; letter-spacing: 0.5px; text-transform: uppercase;">Stock Awal Inspeksi</span>
                <div style="font-size: 18px; font-weight: 900; color: #0f172a; margin-top: 4px; font-family: monospace;">
                    <?= number_format($totalInitialStock) ?> <span style="font-size: 11px; font-weight: 700; color: #64748b;">pcs</span>
                </div>
            </div>

            <div style="background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px 14px; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
                <span style="font-size: 10px; font-weight: 800; color: #64748b; letter-spacing: 0.5px; text-transform: uppercase;">Terpakai Memotong Kanban</span>
                <div style="font-size: 18px; font-weight: 900; color: #2563eb; margin-top: 4px; font-family: monospace;">
                    <?= number_format($totalUsedQty) ?> <span style="font-size: 11px; font-weight: 700; color: #64748b;">pcs</span>
                </div>
            </div>

            <div style="background-color: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 12px; padding: 10px 14px; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
                <span style="font-size: 10px; font-weight: 800; color: #166534; letter-spacing: 0.5px; text-transform: uppercase;">Sisa Stok Gudang</span>
                <div style="font-size: 18px; font-weight: 900; color: #15803d; margin-top: 4px; font-family: monospace;">
                    <?= number_format($availableStock) ?> <span style="font-size: 11px; font-weight: 700; color: #166534;">pcs</span>
                </div>
            </div>

            <div style="background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px 14px; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
                <span style="font-size: 10px; font-weight: 800; color: #64748b; letter-spacing: 0.5px; text-transform: uppercase;">Total Label Box (Ref No)</span>
                <div style="font-size: 18px; font-weight: 900; color: #334155; margin-top: 4px; font-family: monospace;">
                    <?= count($refGroups) ?> <span style="font-size: 11px; font-weight: 700; color: #64748b;">box / label</span>
                </div>
            </div>
        </div>

        <!-- 3. HIERARCHICAL BREAKDOWN PER REF NUMBER WITH NESTED KANBAN & SESSION TRACKING -->
        <div style="display: flex; flex-direction: column; gap: 14px;">
            
            <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid #e2e8f0; padding-bottom: 8px; margin-top: 4px;">
                <h3 style="font-size: 13px; font-weight: 800; color: #0f172a; text-transform: uppercase; letter-spacing: 0.5px; margin: 0; display: flex; align-items: center; gap: 6px;">
                    <svg style="width: 18px; height: 18px; color: #2563eb;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                    </svg>
                    <span>Breakdown & Journey Tracking per Ref Number (Kemasan Box)</span>
                </h3>
                <span style="font-size: 11px; color: #64748b; font-weight: 700; background-color: #f1f5f9; padding: 4px 10px; border-radius: 9999px;">
                    Total <?= count($refGroups) ?> Label Box
                </span>
            </div>

            <?php if (empty($refGroups)): ?>
                <div style="background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 32px; text-align: center; color: #94a3b8;">
                    Belum ada data label box Ref Number yang terscan.
                </div>
            <?php else: ?>
                <?php $rgIdx = 0; foreach ($refGroups as $rKey => $rg): $rgIdx++; ?>
                    <?php 
                        $boxInitialQty   = $rg['qty'];
                        $boxUsedQty      = $rg['used_qty'];
                        $boxRemainingQty = max(0, $boxInitialQty - $boxUsedQty);

                        if ($boxUsedQty == 0) {
                            $boxStatusBadge = '<span style="background-color: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; padding: 3px 10px; border-radius: 9999px; font-weight: 800; font-size: 10px;">AVAILABLE 100% DI WH</span>';
                        } elseif ($boxRemainingQty > 0) {
                            $boxStatusBadge = '<span style="background-color: #dbeafe; color: #1d4ed8; border: 1px solid #bfdbfe; padding: 3px 10px; border-radius: 9999px; font-weight: 800; font-size: 10px;">TERPAKAI SEBAGIAN</span>';
                        } else {
                            $boxStatusBadge = '<span style="background-color: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; padding: 3px 10px; border-radius: 9999px; font-weight: 800; font-size: 10px;">TERPAKAI PENUH KANBAN</span>';
                        }

                        $parentSess = $rg['parent_session'];
                        $sessStatus = $parentSess['status'] ?? 'passed';
                        if ($sessStatus === 'passed') {
                            $sessStatusBadge = '<span style="background-color: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; padding: 2px 8px; border-radius: 9999px; font-weight: 700; font-size: 10px;">PASSED</span>';
                        } elseif ($sessStatus === 'rejected') {
                            $sessStatusBadge = '<span style="background-color: #ffe4e6; color: #9f1239; border: 1px solid #fecdd3; padding: 2px 8px; border-radius: 9999px; font-weight: 700; font-size: 10px;">REJECTED</span>';
                        } else {
                            $sessStatusBadge = '<span style="background-color: #fffbe6; color: #92400e; border: 1px solid #ffe58f; padding: 2px 8px; border-radius: 9999px; font-weight: 700; font-size: 10px;">IN PROGRESS</span>';
                        }

                        $sessStartTime = !empty($parentSess['started_at']) ? date('d M Y, H:i', strtotime($parentSess['started_at'])) : date('d M Y, H:i', strtotime($rg['scanned_at']));
                        $sessEndTime   = !empty($parentSess['closed_at']) ? date('d M Y, H:i', strtotime($parentSess['closed_at'])) : 'In Progress';
                    ?>

                    <div style="background-color: #ffffff; border: 1px solid #cbd5e1; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
                        
                        <!-- HEADER KARTU REF NUMBER -->
                        <div style="padding: 12px 16px; background-color: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                            <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                                <span style="background-color: #0f172a; color: #ffffff; font-weight: 900; font-size: 11px; padding: 3px 8px; border-radius: 6px;">
                                    #<?= $rgIdx ?>
                                </span>
                                
                                <div style="display: flex; align-items: center; gap: 6px;">
                                    <span style="font-size: 11px; font-weight: 700; color: #64748b;">Ref Number:</span>
                                    <span style="font-family: monospace; font-weight: 800; color: #1d4ed8; background-color: #eff6ff; padding: 3px 10px; border-radius: 6px; border: 1px solid #dbeafe; font-size: 12px;">
                                        <?= htmlspecialchars($rg['ref_number']) ?>
                                    </span>
                                </div>

                                <div style="display: flex; align-items: center; gap: 6px;">
                                    <span style="font-size: 11px; font-weight: 700; color: #64748b;">Lot Number:</span>
                                    <span style="font-family: monospace; font-weight: 700; color: #334155; background-color: #ffffff; padding: 3px 8px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 11px;">
                                        <?= htmlspecialchars($rg['lot_number']) ?>
                                    </span>
                                </div>

                                <div>
                                    <?= $boxStatusBadge ?>
                                </div>
                            </div>

                            <!-- METRIK QTY PER BOX -->
                            <div style="display: flex; align-items: center; gap: 14px; background-color: #ffffff; padding: 4px 12px; border-radius: 8px; border: 1px solid #e2e8f0; font-size: 11px;">
                                <div>
                                    <span style="color: #64748b; font-weight: 600;">Stok Box:</span>
                                    <b style="font-family: monospace; color: #0f172a; font-weight: 800; margin-left: 2px;"><?= number_format($boxInitialQty) ?> pcs</b>
                                </div>
                                <div style="color: #cbd5e1;">|</div>
                                <div>
                                    <span style="color: #64748b; font-weight: 600;">Terpakai:</span>
                                    <b style="font-family: monospace; color: #2563eb; font-weight: 800; margin-left: 2px;"><?= number_format($boxUsedQty) ?> pcs</b>
                                </div>
                                <div style="color: #cbd5e1;">|</div>
                                <div>
                                    <span style="color: #64748b; font-weight: 600;">Sisa:</span>
                                    <b style="font-family: monospace; color: #15803d; font-weight: 900; margin-left: 2px;"><?= number_format($boxRemainingQty) ?> pcs</b>
                                </div>
                            </div>
                        </div>

                        <!-- INFO SESI INSPEKSI SAFETY STOCK AWAL (HANDOVER TRACKING INTEGRATION) -->
                        <div style="padding: 10px 16px; background-color: #f1f5f9; border-bottom: 1px solid #e2e8f0; font-size: 11px; color: #334155; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                            <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                                <span style="font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 4px;">
                                    <svg style="width: 14px; height: 14px; color: #64748b;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                    <span>Sesi Scan / Inspeksi Safety Stock Awal:</span>
                                </span>

                                <div style="display: flex; align-items: center; gap: 6px; font-weight: 700;">
                                    <span style="width: 20px; height: 20px; border-radius: 9999px; background-color: #dbeafe; color: #1d4ed8; font-weight: 800; font-size: 10px; display: flex; align-items: center; justify-content: center;">
                                        <?= strtoupper(substr($rg['inspector_name'], 0, 1)) ?>
                                    </span>
                                    <span><?= htmlspecialchars($rg['inspector_name']) ?></span>
                                </div>

                                <div style="color: #64748b;">
                                    Jam Mulai: <b style="color: #0f172a;"><?= $sessStartTime ?></b> 
                                    <span style="margin: 0 4px; color: #94a3b8;">s/d</span> 
                                    Jam Selesai: <b style="color: #0f172a;"><?= $sessEndTime ?></b>
                                </div>

                                <?php if ($parentSess): ?>
                                    <div style="color: #64748b;">
                                        Sampel: <b style="color: #0f172a;"><?= (int)$parentSess['samples_checked'] ?> / <?= (int)$parentSess['sample_size'] ?> pcs</b>
                                        (NG: <b style="color: <?= ($parentSess['ng_count'] > 0) ? '#e11d48' : '#059669' ?>;"><?= (int)$parentSess['ng_count'] ?></b>)
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div style="display: flex; align-items: center; gap: 8px;">
                                <?= $sessStatusBadge ?>
                                <?php if (!empty($rg['inspection_session_id'])): ?>
                                    <a href="<?= base_url('modules/inspection/session.php?id=' . $rg['inspection_session_id']) ?>" style="padding: 3px 8px; background-color: #ffffff; color: #2563eb; font-weight: 700; font-size: 10px; border-radius: 6px; text-decoration: none; border: 1px solid #bfdbfe;" title="Buka Sesi Inspeksi Safety Stock Awal">
                                        Sesi Awal #<?= $rg['inspection_session_id'] ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- BODY: SUB-TABEL RIWAYAT PEMAKAIAN MEMOTONG KANBAN & SESI KANBAN -->
                        <div style="padding: 12px 16px;">
                            <div style="font-size: 11px; font-weight: 800; color: #1e40af; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; display: flex; align-items: center; gap: 6px;">
                                <svg style="width: 14px; height: 14px; color: #2563eb;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                </svg>
                                <span>Riwayat Pemakaian Memotong Kanban & Sesi Inspeksi Kanban Target</span>
                            </div>

                            <?php if (empty($rg['kanban_history'])): ?>
                                <div style="padding: 14px 16px; background-color: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 8px; text-align: center; color: #64748b; font-size: 11px; font-weight: 500;">
                                    Label box ini <b>masih utuh 100% di gudang</b> dan belum pernah terpakai untuk memotong rencana inspeksi Kanban customer.
                                </div>
                            <?php else: ?>
                                <div style="overflow-x-auto; border: 1px solid #bfdbfe; border-radius: 8px;">
                                    <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 11px; color: #334155;">
                                        <thead style="background-color: #eff6ff; border-bottom: 1px solid #bfdbfe; font-size: 10px; font-weight: 800; color: #1e40af; text-transform: uppercase;">
                                            <tr>
                                                <th style="padding: 8px 12px; width: 35px; text-align: center;">No</th>
                                                <th style="padding: 8px 12px; min-width: 130px;">Target No. Kanban</th>
                                                <th style="padding: 8px 12px; min-width: 140px;">Customer Target</th>
                                                <th style="padding: 8px 12px; text-align: center; width: 100px;">Qty Dipakai</th>
                                                <th style="padding: 8px 12px; min-width: 160px;">Jam Mulai - Jam Selesai Sesi</th>
                                                <th style="padding: 8px 12px; min-width: 120px;">Inspektur QC</th>
                                                <th style="padding: 8px 12px; width: 100px;">Status Sesi</th>
                                                <th style="padding: 8px 12px; text-align: right; width: 90px;">Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($rg['kanban_history'] as $khIdx => $kh): ?>
                                                <?php 
                                                    $kSessStatus = $kh['session_status'] ?? 'passed';
                                                    if ($kSessStatus === 'passed') {
                                                        $kBadge = '<span style="background-color: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; padding: 2px 6px; border-radius: 9999px; font-weight: 700; font-size: 9px;">PASSED</span>';
                                                    } elseif ($kSessStatus === 'rejected') {
                                                        $kBadge = '<span style="background-color: #ffe4e6; color: #9f1239; border: 1px solid #fecdd3; padding: 2px 6px; border-radius: 9999px; font-weight: 700; font-size: 9px;">REJECTED</span>';
                                                    } else {
                                                        $kBadge = '<span style="background-color: #fffbe6; color: #92400e; border: 1px solid #ffe58f; padding: 2px 6px; border-radius: 9999px; font-weight: 700; font-size: 9px;">IN PROGRESS</span>';
                                                    }

                                                    $kStart = !empty($kh['used_at']) ? date('d M Y, H:i', strtotime($kh['used_at'])) : '-';
                                                    $kEnd   = !empty($kh['closed_at']) ? date('H:i', strtotime($kh['closed_at'])) : 'In Progress';
                                                ?>
                                                <tr style="border-bottom: 1px solid #dbeafe;">
                                                    <td style="padding: 8px 12px; text-align: center; font-weight: 700; color: #1e40af;"><?= $khIdx + 1 ?></td>
                                                    <td style="padding: 8px 12px; font-weight: 800; color: #0f172a; font-family: monospace;">
                                                        <?= htmlspecialchars($kh['kanban_no'] ?: 'KANBAN-DIRECT') ?>
                                                    </td>
                                                    <td style="padding: 8px 12px; font-weight: 600; color: #334155;">
                                                        <?= htmlspecialchars($kh['customer'] ?: 'CUSTOMER') ?>
                                                    </td>
                                                    <td style="padding: 8px 12px; text-align: center; font-weight: 900; font-family: monospace; color: #2563eb;">
                                                        <?= number_format($kh['used_qty']) ?> pcs
                                                    </td>
                                                    <td style="padding: 8px 12px; color: #475569; font-weight: 500;">
                                                        <span><?= $kStart ?></span>
                                                        <span style="color: #94a3b8; margin: 0 2px;">s/d</span>
                                                        <b style="color: #0f172a;"><?= $kEnd ?></b>
                                                    </td>
                                                    <td style="padding: 8px 12px; font-weight: 600; color: #334155;">
                                                        <div style="display: flex; align-items: center; gap: 4px;">
                                                            <span style="width: 18px; height: 18px; border-radius: 9999px; background-color: #dbeafe; color: #1d4ed8; font-weight: 800; font-size: 9px; display: flex; align-items: center; justify-content: center;">
                                                                <?= strtoupper(substr($kh['inspector_name'], 0, 1)) ?>
                                                            </span>
                                                            <span><?= htmlspecialchars($kh['inspector_name']) ?></span>
                                                        </div>
                                                    </td>
                                                    <td style="padding: 8px 12px;">
                                                        <?= $kBadge ?>
                                                    </td>
                                                    <td style="padding: 8px 12px; text-align: right; white-space: nowrap;">
                                                        <a href="<?= base_url('modules/inspection/session.php?id=' . $kh['kanban_session_id']) ?>" 
                                                           style="padding: 4px 8px; background-color: #2563eb; color: #ffffff; font-weight: 700; font-size: 10px; border-radius: 6px; text-decoration: none; display: inline-flex; align-items: gap: 3px;" 
                                                           title="Buka Sesi Inspeksi Kanban">
                                                            <span>Sesi Kanban</span>
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>

                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

        </div>

        <!-- 4. TABEL RINCIAN TEMUAN DEFECT / NG -->
        <?php if (!empty($defects)): ?>
            <div style="background-color: #ffffff; border: 1px solid #fecdd3; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
                <div style="padding: 12px 16px; background-color: #fff1f2; border-bottom: 1px solid #fecdd3; display: flex; align-items: center; justify-content: space-between;">
                    <h3 style="font-size: 12px; font-weight: 800; color: #9f1239; text-transform: uppercase; letter-spacing: 0.5px; margin: 0;">
                        Rincian Temuan Defect / NG
                    </h3>
                    <span style="font-size: 11px; color: #be123c; font-weight: 700;"><?= count($defects) ?> Item Catatan NG</span>
                </div>

                <div style="overflow-x-auto;">
                    <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 12px; color: #334155;">
                        <thead style="background-color: #ffffff; border-bottom: 1px solid #fecdd3; font-size: 10px; font-weight: 800; color: #9f1239; text-transform: uppercase;">
                            <tr>
                                <th style="padding: 10px 14px; width: 40px;">No</th>
                                <th style="padding: 10px 14px; width: 140px;">Ref Number</th>
                                <th style="padding: 10px 14px; width: 160px;">Jenis Defect</th>
                                <th style="padding: 10px 14px; width: 140px;">Inspektur / PIC</th>
                                <th style="padding: 10px 14px; text-align: center; width: 90px;">Qty NG</th>
                                <th style="padding: 10px 14px;">Catatan Inspektur</th>
                                <th style="padding: 10px 14px; width: 130px;">Waktu Temuan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($defects as $dIdx => $df): ?>
                                <tr style="border-bottom: 1px solid #ffe4e6;">
                                    <td style="padding: 10px 14px; font-weight: 700; color: #9f1239;"><?= $dIdx + 1 ?></td>
                                    <td style="padding: 10px 14px;">
                                        <span style="display: inline-block; font-size: 11px; font-family: monospace; font-weight: 800; color: #9f1239; background-color: #ffe4e6; border: 1px solid #fecdd3; padding: 2px 8px; border-radius: 6px;">
                                            <?= htmlspecialchars(!empty($df['ref_number']) ? $df['ref_number'] : '-') ?>
                                        </span>
                                    </td>
                                    <td style="padding: 10px 14px; font-weight: 800; color: #9f1239;">
                                        <?= htmlspecialchars($df['defect_type_name'] ?? 'Defect') ?>
                                        <span style="font-size: 10px; color: #64748b; font-weight: 500; display: block;">Sampel #<?= (int)$df['sample_number'] ?></span>
                                    </td>
                                    <td style="padding: 10px 14px; font-weight: 600; color: #1e293b; white-space: nowrap;">
                                        <div style="display: flex; align-items: center; gap: 6px;">
                                            <span style="width: 20px; height: 20px; border-radius: 9999px; background-color: #ffe4e6; color: #be123c; font-weight: 800; font-size: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                                <?= strtoupper(substr($df['inspector_name'] ?? 'Q', 0, 1)) ?>
                                            </span>
                                            <span><?= htmlspecialchars($df['inspector_name'] ?? '-') ?></span>
                                        </div>
                                    </td>
                                    <td style="padding: 10px 14px; text-align: center; font-weight: 900; font-family: monospace; color: #be123c;">
                                        <?= (int)($df['qty_ng'] ?? 1) ?> pcs
                                    </td>
                                    <td style="padding: 10px 14px; color: #475569;">
                                        <?= htmlspecialchars(!empty($df['remark']) ? $df['remark'] : '-') ?>
                                    </td>
                                    <td style="padding: 10px 14px; color: #64748b; font-size: 11px;">
                                        <?= date('d M Y, H:i', strtotime($df['started_at'])) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

    </main>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
