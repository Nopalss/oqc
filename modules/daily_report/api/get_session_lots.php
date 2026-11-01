<?php
/**
 * Lightweight Micro-API: Get Session Lots & Defects (On-Demand Lazy Load)
 * Digunakan oleh timeline sesi daily_report/index.php saat baris akordeon dibuka.
 * Memangkas ukuran transfer HTML awal dari 1.5 MB menjadi puluhan KB.
 * 
 * PT. Surya Technology Industri — Outgoing Quality Control Division
 */

require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/helper.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Lepas session lock segera agar tidak memblokir request lain di LAN
session_write_close();

$sessionId = filter_input(INPUT_GET, 'session_id', FILTER_VALIDATE_INT);
if (!$sessionId || $sessionId <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID Sesi tidak valid.']);
    exit;
}

$pdo = getDB();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Koneksi database gagal.']);
    exit;
}

// ── Helper: AQL Level II Lookup for Lot Fallback ───────────────────────────
if (!function_exists('getLotAqlFallback')) {
    function getLotAqlFallback($qty, $pdo = null) {
        static $aqlTable = null;
        $qty = (int)$qty;
        if ($qty <= 0) return ['sample_size' => 0, 'reject_number' => 1];
        
        if ($aqlTable === null && $pdo) {
            try {
                $stmt = $pdo->query("SELECT qty_min, qty_max, sample_size, reject_number FROM aql_standards WHERE inspection_level IN ('G-II', 'Level II', 'II') ORDER BY qty_min ASC");
                $aqlTable = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $aqlTable = [];
            }
        }
        
        if (!empty($aqlTable)) {
            foreach ($aqlTable as $row) {
                if ($qty >= (int)$row['qty_min'] && $qty <= (int)$row['qty_max']) {
                    return [
                        'sample_size' => (int)$row['sample_size'],
                        'reject_number' => (int)$row['reject_number']
                    ];
                }
            }
        }
        
        if ($qty <= 8) return ['sample_size' => min(2, $qty), 'reject_number' => 1];
        if ($qty <= 15) return ['sample_size' => min(3, $qty), 'reject_number' => 1];
        if ($qty <= 25) return ['sample_size' => min(5, $qty), 'reject_number' => 1];
        if ($qty <= 50) return ['sample_size' => min(8, $qty), 'reject_number' => 1];
        if ($qty <= 90) return ['sample_size' => min(13, $qty), 'reject_number' => 1];
        if ($qty <= 150) return ['sample_size' => min(20, $qty), 'reject_number' => 1];
        if ($qty <= 280) return ['sample_size' => min(32, $qty), 'reject_number' => 1];
        if ($qty <= 500) return ['sample_size' => min(50, $qty), 'reject_number' => 1];
        if ($qty <= 1200) return ['sample_size' => min(80, $qty), 'reject_number' => 2];
        if ($qty <= 3200) return ['sample_size' => min(125, $qty), 'reject_number' => 2];
        if ($qty <= 10000) return ['sample_size' => min(200, $qty), 'reject_number' => 3];
        return ['sample_size' => min(315, $qty), 'reject_number' => 4];
    }
}

// ── Helper: Render Non-Transparent Solid Status Badge for Lot/Label ──────────
if (!function_exists('renderLotStatusBadge')) {
    function renderLotStatusBadge($lot) {
        $res = strtolower($lot['lot_result'] ?? '');
        $st = strtolower($lot['lot_status'] ?? '');
        $remUpper = strtoupper(($lot['remarks'] ?? '') . ' ' . ($lot['action_notes'] ?? '') . ' ' . ($lot['reinspection_notes'] ?? '') . ' ' . ($lot['status_label'] ?? '') . ' ' . ($lot['status_badge'] ?? ''));

        $isInitial = !empty($lot['is_initial_attempt']) 
            || (($lot['attempt_type'] ?? '') === 'initial')
            || (strpos($remUpper, 'AWAL') !== false && strpos($remUpper, 'RE-INSPEKSI') === false);

        $isReinspect = !empty($lot['is_reinspected_attempt']) 
            || !empty($lot['is_reinspected_lot']) 
            || !empty($lot['is_reinspection']) 
            || !empty($lot['has_reinspection_log'])
            || !empty($lot['has_subst_log'])
            || !empty($lot['has_sorted_ng'])
            || !empty($lot['has_prev_rejected'])
            || (($lot['attempt_type'] ?? '') === 'reinspection') 
            || (!empty($lot['reinspection_type']) && $lot['reinspection_type'] !== '')
            || (!empty($lot['parent_session_id']) && (int)$lot['parent_session_id'] > 0)
            || (!empty($lot['status_label']) && strpos($lot['status_label'], 'RE-INSPEKSI') !== false)
            || (!empty($lot['status_badge']) && strpos($lot['status_badge'], 'RE-INSPEKSI') !== false)
            || strpos($remUpper, 'RE-INSPEKSI') !== false
            || strpos($remUpper, 'REINSPEKSI') !== false
            || strpos($remUpper, 'RE-INSPECT') !== false
            || strpos($remUpper, 'REINSPECT') !== false
            || strpos($remUpper, 'PENGGANTI') !== false
            || strpos($remUpper, 'SORTIR') !== false
            || $st === 'reinspected';

        $isKanban = (($lot['inspection_type'] ?? '') === 'kanban');
        $isSs = !$isKanban && (!empty($lot['is_safety_stock']) || (($lot['inspection_type'] ?? '') === 'safety_stock') || $res === 'skipped');

        if ($isInitial) {
            return '<span style="background-color: #dc2626; color: #ffffff; font-weight: 800; font-size: 10px; padding: 2.5px 8px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 3.5px; box-shadow: 0 1px 2px rgba(220, 38, 38, 0.3); text-transform: uppercase; white-space: nowrap;"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="stroke: #ffffff; flex-shrink: 0;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> REJECTED (AWAL)</span>';
        }

        $isRejected = ($res === 'rejected') 
            || ($st === 'rejected') 
            || ($st === 'replaced') 
            || (($lot['status_code'] ?? '') === 'rejected')
            || ((int)($lot['total_ng'] ?? 0) > 0 && $res !== 'passed' && $st !== 'reinspected')
            || (!empty($lot['defects']) && $res !== 'passed' && $st !== 'reinspected');

        if ($isRejected) {
            if ($isReinspect) {
                return '<span style="background-color: #dc2626; color: #ffffff; font-weight: 800; font-size: 10px; padding: 2.5px 8px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 3.5px; box-shadow: 0 1px 2px rgba(220, 38, 38, 0.3); text-transform: uppercase; white-space: nowrap;"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="stroke: #ffffff; flex-shrink: 0;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> REJECTED (RE-INSPEKSI)</span>';
            }
            return '<span style="background-color: #dc2626; color: #ffffff; font-weight: 800; font-size: 10px; padding: 2.5px 8px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 3.5px; box-shadow: 0 1px 2px rgba(220, 38, 38, 0.3); text-transform: uppercase; white-space: nowrap;"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="stroke: #ffffff; flex-shrink: 0;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> REJECTED</span>';
        }

        if ($isReinspect) {
            if ($res === 'passed' || ($lot['status_code'] ?? '') === 'passed' || $st === 'passed' || $st === 'reinspected') {
                return '<span style="background-color: #16a34a; color: #ffffff; font-weight: 800; font-size: 10px; padding: 2.5px 8px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 3.5px; box-shadow: 0 1px 2px rgba(22, 163, 74, 0.3); text-transform: uppercase; white-space: nowrap;"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="stroke: #ffffff; flex-shrink: 0;"><polyline points="20 6 9 17 4 12"/></svg> PASSED (RE-INSPEKSI)</span>';
            }
            return '<span style="background-color: #d97706; color: #ffffff; font-weight: 800; font-size: 10px; padding: 2.5px 8px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 3.5px; box-shadow: 0 1px 2px rgba(217, 119, 6, 0.3); text-transform: uppercase; white-space: nowrap;">BERJALAN</span>';
        }

        if ($isSs && ($res === 'passed' || $res === 'skipped' || empty($res))) {
            return '<span style="background-color: #7c3aed; color: #ffffff; font-weight: 800; font-size: 9.5px; padding: 2.5px 8.5px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 2px rgba(124, 58, 237, 0.3); text-transform: uppercase; white-space: nowrap;"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="stroke: #ffffff; flex-shrink: 0;"><polyline points="20 6 9 17 4 12"/></svg> PASSED (SS)</span>';
        }

        if ($res === 'passed' || ($lot['status_code'] ?? '') === 'passed' || $st === 'passed') {
            return '<span style="background-color: #16a34a; color: #ffffff; font-weight: 800; font-size: 10px; padding: 2.5px 8px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 3.5px; box-shadow: 0 1px 2px rgba(22, 163, 74, 0.3); text-transform: uppercase; white-space: nowrap;"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="stroke: #ffffff; flex-shrink: 0;"><polyline points="20 6 9 17 4 12"/></svg> PASSED</span>';
        }

        return '<span style="background-color: #d97706; color: #ffffff; font-weight: 800; font-size: 10px; padding: 2.5px 8px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 3.5px; box-shadow: 0 1px 2px rgba(217, 119, 6, 0.3); text-transform: uppercase; white-space: nowrap;">BERJALAN</span>';
    }
}

try {
    // 1. Ambil detail sesi
    $stmtSess = $pdo->prepare("
        SELECT 
            ss.id,
            ss.sample_size,
            ss.reject_number,
            ss.ng_count,
            ss.reinspection_notes
        FROM inspection_sessions ss
        WHERE ss.id = :sid
        LIMIT 1
    ");
    $stmtSess->execute([':sid' => $sessionId]);
    $sess = $stmtSess->fetch(PDO::FETCH_ASSOC);
    if (!$sess) {
        echo json_encode(['success' => false, 'message' => 'Sesi tidak ditemukan.']);
        exit;
    }

    // 2. Ambil lots sesi
    $stmtLots = $pdo->prepare("
        SELECT 
            isl.id AS lot_id,
            isl.inspection_session_id,
            isl.ref_number,
            isl.lot_number,
            isl.qty,
            isl.sample_size,
            isl.reject_number,
            isl.accept_number,
            isl.lot_result,
            isl.lot_status,
            isl.remarks,
            isl.created_at AS scanned_at,
            isl.closed_at AS closed_at,
            isl.action_noted_at,
            isl.replaced_by_lot_id,
            (SELECT COUNT(*) FROM lot_substitution_log sub 
             WHERE sub.ng_session_lot_id = isl.id 
                OR sub.replacement_session_lot_id = isl.id) AS has_subst_log,
            (SELECT COUNT(*) FROM inspection_ng_records ngr 
             WHERE ngr.session_lot_id = isl.id 
               AND ngr.is_sorted = 1) AS has_sorted_ng
        FROM inspection_session_lots isl
        WHERE isl.inspection_session_id = :sid
          AND (isl.remarks IS NULL OR (isl.remarks NOT LIKE '%Sisa Split Safety Stock%' AND isl.remarks NOT LIKE 'Sisa Kelebihan Kanban%'))
        ORDER BY isl.id ASC
    ");
    $stmtLots->execute([':sid' => $sessionId]);
    $lots = $stmtLots->fetchAll(PDO::FETCH_ASSOC);

    // 3. Ambil defects sesi
    $stmtNg = $pdo->prepare("
        SELECT 
            ngr.id,
            ngr.inspection_session_id,
            ngr.session_lot_id,
            ngr.part_id,
            ngr.defect_type_id,
            ngr.qty_ng,
            ngr.remark,
            COALESCE(ngr.is_sorted, 0) AS is_sorted,
            ngr.created_at AS defect_created_at,
            dt.name AS defect_name,
            COALESCE(NULLIF(ngr.lot_number, ''), isl.lot_number, '-') AS lot_number,
            COALESCE(NULLIF(ngr.ref_number, ''), isl.ref_number, '-') AS ref_number,
            COALESCE(u.name, 'Inspector') AS inspector_name
        FROM inspection_ng_records ngr
        INNER JOIN defect_types dt ON ngr.defect_type_id = dt.id
        LEFT JOIN inspection_session_lots isl ON ngr.session_lot_id = isl.id
        LEFT JOIN inspection_sessions ss ON ngr.inspection_session_id = ss.id
        LEFT JOIN users u ON u.id = ss.inspector_id
        WHERE ngr.inspection_session_id = :sid
          AND ((ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0) OR ngr.is_sorted = 1)
        ORDER BY ngr.id ASC
    ");
    $stmtNg->execute([':sid' => $sessionId]);
    $ngRecords = $stmtNg->fetchAll(PDO::FETCH_ASSOC);

    // Pemetaan defect ke lots
    $unmatchedDefects = [];
    foreach ($lots as &$lotRef) {
        $lotRef['defects'] = [];
        $isSafetyStock = ($lotRef['lot_result'] === 'skipped') || (empty($lotRef['sample_size']) && isset($lotRef['remarks']) && strpos($lotRef['remarks'], 'Alokasi Safety Stock') !== false);
        $lotQty = (int)($lotRef['qty'] ?? 0);
        $lSampleSize = (int)($lotRef['sample_size'] ?? 0);
        $lRejectNum = isset($lotRef['reject_number']) ? (int)$lotRef['reject_number'] : 0;
        
        if ($isSafetyStock) {
            $lSampleSize = 0;
            $lRejectNum = 0;
        } elseif ($lSampleSize <= 0 || $lRejectNum <= 0) {
            $aqlFb = getLotAqlFallback($lotQty, $pdo);
            if ($lSampleSize <= 0) $lSampleSize = $aqlFb['sample_size'];
            if ($lRejectNum <= 0) $lRejectNum = $aqlFb['reject_number'];
        }
        $lotRef['is_safety_stock'] = $isSafetyStock;
        $lotRef['computed_sample_size'] = $lSampleSize;
        $lotRef['computed_reject_number'] = $lRejectNum;
    }
    unset($lotRef);

    foreach ($ngRecords as $ng) {
        $matched = false;
        foreach ($lots as &$lotRef) {
            $isMatched = false;
            if (!empty($ng['session_lot_id']) && (int)$ng['session_lot_id'] === (int)$lotRef['lot_id']) {
                $isMatched = true;
            } elseif (empty($ng['session_lot_id'])) {
                if (!empty($ng['ref_number']) && $ng['ref_number'] !== '-' && $ng['ref_number'] === $lotRef['ref_number']) {
                    $isMatched = true;
                } elseif (!empty($ng['lot_number']) && $ng['lot_number'] !== '-' && $ng['lot_number'] === $lotRef['lot_number']) {
                    $isMatched = true;
                }
            }
            if ($isMatched) {
                $lotRef['defects'][] = $ng;
                $matched = true;
                break;
            }
        }
        unset($lotRef);
        if (!$matched) {
            $unmatchedDefects[] = $ng;
        }
    }

    $effectiveSampleSize = 0;
    foreach ($lots as $sl) {
        $effectiveSampleSize += (int)($sl['computed_sample_size'] ?? 0);
    }
    if ($effectiveSampleSize === 0) {
        $effectiveSampleSize = (int)($sess['sample_size'] ?? 0);
    }

    $ngCount = 0;
    foreach ($ngRecords as $sn) {
        $ngCount += (int)($sn['qty_ng'] ?? 1);
    }
    if ($ngCount === 0) {
        $ngCount = (int)$sess['ng_count'];
    }

    // Render HTML Fragment
    ob_start();
    ?>
    <div style="border-left: 3px solid #0f172a; padding-left: 16px;" class="space-y-3">
        <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap;">
            <div style="font-size: 11px; font-weight: 800; color: #0f172a; text-transform: uppercase;">
                Rincian Box / Lot yang Digunakan pada Sesi #<?= $sessionId ?> (<?= count($lots) ?> <?= count($lots) > 1 ? 'Pemeriksaan' : 'Box' ?>):
            </div>
            <div style="font-size: 11px; font-weight: 700; color: #475569;">
                Sample : <b><?= (int)$effectiveSampleSize ?> pcs</b> &middot; NG: <b style="color: <?= ((int)$ngCount > 0) ? '#dc2626' : '#16a34a' ?>;"><?= (int)$ngCount ?> / <?= max(1, (int)$sess['reject_number']) ?></b>
            </div>
        </div>

        <?php if (!empty($sess['reinspection_notes'])): ?>
            <div style="background: #fefce8; border: 1px solid #fef08a; border-radius: 8px; padding: 6px 10px; font-size: 11px; color: #854d0e;">
                <b>Catatan Re-inspeksi:</b> <?= htmlspecialchars($sess['reinspection_notes']) ?>
            </div>
        <?php endif; ?>

        <!-- Lots list table -->
        <div style="overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 8px; background: #fff;">
            <table style="width: 100%; border-collapse: collapse; font-size: 11px;">
                <thead style="background: #f8fafc; color: #64748b; font-weight: 700; border-bottom: 1px solid #e2e8f0;">
                    <tr>
                        <th style="padding: 7px 12px; width: 35px; text-align: center;">No</th>
                        <th style="padding: 7px 12px; min-width: 130px;">Lot Number</th>
                        <th style="padding: 7px 12px; min-width: 130px;">Ref Number</th>
                        <th style="padding: 7px 12px; text-align: right; width: 90px;">Qty</th>
                        <th style="padding: 7px 12px; text-align: center; width: 100px;">Sampel (pcs)</th>
                        <th style="padding: 7px 12px; text-align: center; width: 75px;">NG</th>
                        <th style="padding: 7px 12px; text-align: center; min-width: 130px;">Status Lot</th>
                        <th style="padding: 7px 12px; min-width: 110px;">Waktu Scan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($lots)): ?>
                        <tr><td colspan="8" style="padding: 8px; text-align: center; color: #94a3b8;">Tidak ada catatan rincian box.</td></tr>
                    <?php else: ?>
                        <?php foreach ($lots as $bIdx => $bLot): 
                            $hasDefects = !empty($bLot['defects']);
                            $lotNgTotal = 0;
                            if ($hasDefects) {
                                foreach ($bLot['defects'] as $df) { $lotNgTotal += (int)$df['qty_ng']; }
                            } else {
                                $lotNgTotal = (int)($bLot['ng_count'] ?? 0);
                            }
                            $isReinspect = !empty($bLot['is_reinspected_attempt']);
                        ?>
                            <tr style="border-bottom: <?= $hasDefects ? '1px dashed #fca5a5' : ($isReinspect ? '1px dashed #86efac' : '1px solid #f8fafc') ?>; background: <?= $hasDefects ? '#fffbfb' : ($isReinspect ? '#f0fdf4' : 'transparent') ?>;">
                                <td style="padding: 7px 12px; text-align: center; color: #94a3b8; font-weight: 700;"><?= $bIdx + 1 ?></td>
                                <td style="padding: 7px 12px; font-family: monospace; color: #334155;">
                                    <?= htmlspecialchars($bLot['lot_number'] ?: '-') ?>
                                </td>
                                <td style="padding: 7px 12px; font-family: monospace; font-weight: 800; color: #0284c7;">
                                    <?= htmlspecialchars($bLot['ref_number'] ?: '-') ?>
                                </td>
                                <td style="padding: 7px 12px; text-align: right; font-family: monospace; font-weight: 700; color: #0f172a;">
                                    <?= number_format($bLot['qty']) ?> pcs
                                </td>
                                <td style="padding: 7px 12px; text-align: center; font-family: monospace; font-weight: 700; color: #2563eb;">
                                    <?= !empty($bLot['is_safety_stock']) ? '<span style="color:#7c3aed;font-weight:800;">0 (Lolos SS)</span>' : ((int)$bLot['computed_sample_size'] . ' pcs') ?>
                                </td>
                                <td style="padding: 7px 12px; text-align: center;">
                                    <span style="font-family: monospace; font-size: 11px; font-weight: 800; padding: 2px 7px; border-radius: 4px; <?= $lotNgTotal > 0 ? 'background: #fef2f2; color: #dc2626; border: 1px solid #fecaca;' : 'background: #f0fdf4; color: #16a34a; border: 1px solid #dcfce7;' ?>">
                                        <?= $lotNgTotal ?> / <?= (int)$bLot['computed_reject_number'] ?>
                                    </span>
                                </td>
                                <td style="padding: 7px 12px; text-align: center;">
                                    <?= renderLotStatusBadge($bLot) ?>
                                </td>
                                <td style="padding: 7px 12px; color: #64748b;">
                                    <?= date('H:i', strtotime($bLot['scanned_at'])) ?> WIB
                                </td>
                            </tr>
                            <?php if ($hasDefects): ?>
                                <tr style="background: #fff5f5; border-bottom: 2px solid #fecaca;">
                                    <td colspan="8" style="padding: 6px 12px 8px 16px;">
                                        <div style="display: flex; flex-direction: column; gap: 4px;">
                                            <div style="font-size: 10px; font-weight: 800; color: #991b1b; text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 4px;">
                                                <svg style="width: 12px; height: 12px; color: #dc2626;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                                <span>Temuan Cacat pada Box / Lot Ini (<?= count($bLot['defects']) ?> defect):</span>
                                            </div>
                                            <?php foreach ($bLot['defects'] as $df): ?>
                                                <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 11px; background: #ffffff; padding: 5px 10px; border-radius: 5px; border: 1px solid #fee2e2; flex-wrap: wrap;">
                                                    <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                                        <span style="font-weight: 800; color: #dc2626;">• <?= htmlspecialchars($df['defect_name']) ?>:</span>
                                                        <span style="font-family: monospace; font-weight: 800; color: #b91c1c; background: #fee2e2; padding: 1px 6px; border-radius: 3px;">
                                                            <?= (int)$df['qty_ng'] ?> pcs
                                                        </span>
                                                        <?php if (!empty($df['remark'])): ?>
                                                            <span style="color: #64748b; font-style: italic; font-size: 10px;">
                                                                (<?= htmlspecialchars($df['remark']) ?>)
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div style="display: flex; align-items: center; gap: 10px; font-size: 10px; color: #475569;">
                                                        <span>
                                                            <span style="color: #64748b;">Inspector:</span> 
                                                            <strong style="color: #0f172a;"><?= htmlspecialchars($df['inspector_name'] ?? 'Inspector') ?></strong>
                                                        </span>
                                                        <span style="background: #f1f5f9; color: #334155; padding: 1px 6px; border-radius: 4px; font-family: monospace; font-weight: 700; border: 1px solid #e2e8f0;">
                                                            <?= !empty($df['defect_created_at']) ? date('H:i \W\I\B', strtotime($df['defect_created_at'])) : '-' ?>
                                                        </span>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Defects box (unmatched) -->
        <?php if (!empty($unmatchedDefects)): ?>
            <div style="background: #fff5f5; border: 1px solid #fed7d7; border-radius: 8px; padding: 10px 14px;">
                <div style="font-size: 11px; font-weight: 800; color: #9b2c2c; margin-bottom: 6px; display: flex; align-items: center; gap: 5px;">
                    <svg style="width: 13px; height: 13px; color: #dc2626;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    <span>Temuan Cacat Sesi (Tanpa Mapping Lot):</span>
                </div>
                <div style="display: flex; flex-direction: column; gap: 4px;">
                    <?php foreach ($unmatchedDefects as $ng): ?>
                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 11px; background: #fff; padding: 6px 10px; border-radius: 6px; border: 1px solid #fee2e2; flex-wrap: wrap;">
                            <div style="display: flex; align-items: center; gap: 6px;">
                                <span style="font-weight: 800; color: #dc2626;">• <?= htmlspecialchars($ng['defect_name']) ?>:</span>
                                <span style="font-family: monospace; font-weight: 900; color: #b91c1c; background: #fee2e2; padding: 1px 6px; border-radius: 3px;"><?= (int)$ng['qty_ng'] ?> pcs</span>
                                <span style="color: #64748b; font-size: 10px;">(Posisi Lot: <?= htmlspecialchars($ng['lot_number']) ?>, Ref: <?= htmlspecialchars($ng['ref_number']) ?>)</span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 10px; font-size: 10px; color: #475569;">
                                <span><span style="color: #64748b;">Inspector:</span> <strong style="color: #0f172a;"><?= htmlspecialchars($ng['inspector_name'] ?? 'Inspector') ?></strong></span>
                                <span style="background: #f1f5f9; color: #334155; padding: 1px 6px; border-radius: 4px; font-family: monospace; font-weight: 700; border: 1px solid #e2e8f0;"><?= !empty($ng['defect_created_at']) ? date('H:i \W\I\B', strtotime($ng['defect_created_at'])) : '-' ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php elseif (empty($ngRecords)): ?>
            <div style="font-size: 11px; color: #166534; background: #f0fdf4; border: 1px solid #dcfce7; border-radius: 6px; padding: 5px 10px; display: inline-flex; align-items: center; gap: 4px;">
                <svg style="width: 13px; height: 13px; color: #16a34a;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                <span><b>All Samples OK:</b> Seluruh sampel lot memenuhi standar, tidak ditemukan cacat.</span>
            </div>
        <?php endif; ?>
    </div>
    <?php
    $htmlContent = ob_get_clean();

    echo json_encode([
        'success' => true,
        'html'    => $htmlContent
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Gagal mengambil data lot: ' . $e->getMessage()
    ]);
}

