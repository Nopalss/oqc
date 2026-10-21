<?php
/**
 * Lightweight Micro-API: Get Inspector Lots with On-Demand Pagination
 * Digunakan oleh daily_report/index.php tab Aktivitas Inspektur
 * untuk memuat rincian lot secara lazy-load (Anti-Lag untuk 5.000+ lot).
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

$inspectorName = trim($_GET['inspector_name'] ?? '');
$startDate     = sanitize($_GET['start_date'] ?? '');
$endDate       = sanitize($_GET['end_date'] ?? '');
$search        = sanitize($_GET['search'] ?? '');
$page          = max(1, filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1);
$limit         = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT) ?: 50;
if (!in_array($limit, [25, 50, 100])) $limit = 50;

if (empty($inspectorName)) {
    echo json_encode(['success' => false, 'message' => 'Nama inspektur tidak ditentukan.']);
    exit;
}

$pdo = getDB();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Koneksi database gagal.']);
    exit;
}

// ── Helper: AQL Level II Lookup ───────────────────────────────────────────
if (!function_exists('getLotAqlFallback')) {
    function getLotAqlFallback($qty, $pdo = null) {
        $qty = (int)$qty;
        if ($qty <= 0) return ['sample_size' => 0, 'reject_number' => 1];
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

// ── Helper: Render Non-Transparent Solid Status Badge for Lot ───────────────
if (!function_exists('renderLotStatusBadge')) {
    function renderLotStatusBadge($lot) {
        $res = strtolower($lot['lot_result'] ?? '');
        $st = strtolower($lot['lot_status'] ?? '');
        $remUpper = strtoupper(($lot['remarks'] ?? '') . ' ' . ($lot['action_notes'] ?? '') . ' ' . ($lot['status_label'] ?? ''));

        $isInitial = !empty($lot['is_initial_attempt']) 
            || (($lot['attempt_type'] ?? '') === 'initial')
            || (strpos($remUpper, 'AWAL') !== false && strpos($remUpper, 'RE-INSPEKSI') === false);

        $isReinspect = !empty($lot['is_reinspected_attempt']) 
            || !empty($lot['is_reinspection']) 
            || (($lot['attempt_type'] ?? '') === 'reinspection') 
            || strpos($remUpper, 'RE-INSPEKSI') !== false
            || strpos($remUpper, 'REINSPEKSI') !== false
            || strpos($remUpper, 'SORTIR') !== false
            || $st === 'reinspected';

        $isKanban = (($lot['inspection_type'] ?? '') === 'kanban');
        $isSs = !$isKanban && (!empty($lot['is_safety_stock']) || (($lot['inspection_type'] ?? '') === 'safety_stock') || $res === 'skipped');

        if ($isInitial) {
            return '<span style="background-color: #dc2626; color: #ffffff; font-weight: 800; font-size: 10px; padding: 2.5px 8px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 3.5px; text-transform: uppercase; white-space: nowrap;">REJECTED (AWAL)</span>';
        }

        $isRejected = ($res === 'rejected') || ($st === 'rejected') || ($st === 'replaced') || ((int)($lot['total_ng'] ?? 0) > 0 && $res !== 'passed' && $st !== 'reinspected');

        if ($isRejected) {
            if ($isReinspect) {
                return '<span style="background-color: #dc2626; color: #ffffff; font-weight: 800; font-size: 10px; padding: 2.5px 8px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 3.5px; text-transform: uppercase; white-space: nowrap;">REJECTED (RE-INSPEKSI)</span>';
            }
            return '<span style="background-color: #dc2626; color: #ffffff; font-weight: 800; font-size: 10px; padding: 2.5px 8px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 3.5px; text-transform: uppercase; white-space: nowrap;">REJECTED</span>';
        }

        if ($isReinspect) {
            if ($res === 'passed' || $st === 'passed' || $st === 'reinspected') {
                return '<span style="background-color: #16a34a; color: #ffffff; font-weight: 800; font-size: 10px; padding: 2.5px 8px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 3.5px; text-transform: uppercase; white-space: nowrap;">PASSED (RE-INSPEKSI)</span>';
            }
            return '<span style="background-color: #d97706; color: #ffffff; font-weight: 800; font-size: 10px; padding: 2.5px 8px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 3.5px; text-transform: uppercase; white-space: nowrap;">BERJALAN</span>';
        }

        if ($isSs && ($res === 'passed' || $res === 'skipped' || empty($res))) {
            return '<span style="background-color: #7c3aed; color: #ffffff; font-weight: 800; font-size: 9.5px; padding: 2.5px 8.5px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 4px; text-transform: uppercase; white-space: nowrap;">PASSED (SS)</span>';
        }

        if ($res === 'passed' || $st === 'passed') {
            return '<span style="background-color: #16a34a; color: #ffffff; font-weight: 800; font-size: 10px; padding: 2.5px 8px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 3.5px; text-transform: uppercase; white-space: nowrap;">PASSED</span>';
        }

        return '<span style="background-color: #d97706; color: #ffffff; font-weight: 800; font-size: 10px; padding: 2.5px 8px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 3.5px; text-transform: uppercase; white-space: nowrap;">BERJALAN</span>';
    }
}

try {
    $where = [
        "u.name = :insp_name",
        "(ss.original_ss_session_id IS NULL OR ss.original_ss_session_id = 0)",
        "(ki.remark IS NULL OR (ki.remark NOT LIKE '%AUTO-SS-SESS-%' AND ki.remark NOT LIKE '%Split%'))",
        "(isl.remarks IS NULL OR (isl.remarks NOT LIKE '%Sisa Split Safety Stock%' AND isl.remarks NOT LIKE 'Sisa Kelebihan Kanban%'))"
    ];
    $params = [':insp_name' => $inspectorName];

    if (!empty($startDate) && !empty($endDate)) {
        $where[] = "DATE(ss.started_at) BETWEEN :sd AND :ed";
        $params[':sd'] = $startDate;
        $params[':ed'] = $endDate;
    }

    if (!empty($search)) {
        $where[] = "(
            isl.lot_number LIKE :s1 
            OR isl.ref_number LIKE :s2 
            OR did.part_code LIKE :s3 
            OR did.part_name LIKE :s4 
            OR ki.item_code LIKE :s5 
            OR ki.item_description LIKE :s6 
            OR ki.kanban_no LIKE :s7 
            OR mp.part_code LIKE :s8 
            OR mp.part_name LIKE :s9
        )";
        $like = '%' . $search . '%';
        $params[':s1'] = $like; $params[':s2'] = $like; $params[':s3'] = $like;
        $params[':s4'] = $like; $params[':s5'] = $like; $params[':s6'] = $like;
        $params[':s7'] = $like; $params[':s8'] = $like; $params[':s9'] = $like;
    }

    $whereStr = implode(' AND ', $where);

    // 1. Hitung total lot
    $countSql = "
        SELECT COUNT(isl.id)
        FROM inspection_session_lots isl
        JOIN inspection_sessions ss ON ss.id = isl.inspection_session_id
        LEFT JOIN users u ON u.id = ss.inspector_id
        LEFT JOIN daily_inspection_data did ON did.id = ss.did_id
        LEFT JOIN kanban_items ki ON ki.id = ss.kanban_item_id
        LEFT JOIN master_parts mp ON (mp.id = ss.part_id OR UPPER(mp.part_code) = UPPER(COALESCE(did.part_code, ki.item_code)))
        WHERE {$whereStr}
    ";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $totalLots = (int)$countStmt->fetchColumn();

    $totalPages = max(1, ceil($totalLots / $limit));
    if ($page > $totalPages) $page = $totalPages;
    $offset = ($page - 1) * $limit;

    // 2. Query data lot spesifik dengan limit & offset
    $lotsSql = "
        SELECT 
            isl.id AS lot_id,
            isl.inspection_session_id AS session_id,
            isl.ref_number,
            isl.lot_number,
            isl.qty,
            isl.sample_size,
            isl.reject_number,
            isl.lot_result,
            isl.lot_status,
            isl.remarks,
            isl.created_at AS scanned_at,
            isl.closed_at,
            ss.inspection_type,
            ss.status AS session_status,
            ss.closed_at AS session_closed_at,
            COALESCE(ss.line_name, l.name, 'Line 1') AS line_name,
            COALESCE(did.part_code, ki.item_code, mp.part_code, '-') AS part_code,
            COALESCE(did.part_name, ki.item_description, mp.part_name, '-') AS part_name,
            COALESCE(mm.name, mp.model, '-') AS model_name,
            COALESCE(ki.kanban_no, '-') AS kanban_no,
            COALESCE(ki.customer, 'INTERNAL') AS customer
        FROM inspection_session_lots isl
        JOIN inspection_sessions ss ON ss.id = isl.inspection_session_id
        LEFT JOIN `lines` l ON l.id = ss.line_id
        LEFT JOIN users u ON u.id = ss.inspector_id
        LEFT JOIN daily_inspection_data did ON did.id = ss.did_id
        LEFT JOIN kanban_items ki ON ki.id = ss.kanban_item_id
        LEFT JOIN master_parts mp ON (mp.id = ss.part_id OR UPPER(mp.part_code) = UPPER(COALESCE(did.part_code, ki.item_code)))
        LEFT JOIN master_models mm ON mm.id = mp.model_id
        WHERE {$whereStr}
        ORDER BY isl.id DESC
        LIMIT :lim OFFSET :off
    ";

    $lotStmt = $pdo->prepare($lotsSql);
    foreach ($params as $k => $v) {
        $lotStmt->bindValue($k, $v);
    }
    $lotStmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $lotStmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $lotStmt->execute();
    $lots = $lotStmt->fetchAll(PDO::FETCH_ASSOC);

    // Ambil defects untuk lot yang terpilih saja
    $lotIds = array_column($lots, 'lot_id');
    $defectsByLot = [];
    if (!empty($lotIds)) {
        $inLots = implode(',', array_map('intval', $lotIds));
        $ngStmt = $pdo->query("
            SELECT ngr.session_lot_id, ngr.qty_ng, dt.name AS defect_name
            FROM inspection_ng_records ngr
            JOIN defect_types dt ON dt.id = ngr.defect_type_id
            WHERE ngr.session_lot_id IN ({$inLots})
              AND (ngr.is_cancelled IS NULL OR ngr.is_cancelled = 0)
        ");
        while ($ngRow = $ngStmt->fetch(PDO::FETCH_ASSOC)) {
            $defectsByLot[(int)$ngRow['session_lot_id']][] = $ngRow;
        }
    }

    // Bangun HTML Baris
    ob_start();
    if (empty($lots)): ?>
        <tr>
            <td colspan="14" style="padding: 24px; text-align: center; color: #94a3b8; font-weight: 600;">
                Tidak ada data lot yang sesuai dengan kata kunci pencarian.
            </td>
        </tr>
    <?php else: 
        foreach ($lots as $idx => $lot):
            $rowNum = $offset + $idx + 1;
            $lotId = (int)$lot['lot_id'];
            $dfs = $defectsByLot[$lotId] ?? [];
            $lotNgTotal = 0;
            foreach ($dfs as $df) { $lotNgTotal += (int)$df['qty_ng']; }
            $hasDefects = !empty($dfs);

            $lotQty = (int)($lot['qty'] ?? 0);
            $lSampleSize = (int)($lot['sample_size'] ?? 0);
            $lRejectNum = isset($lot['reject_number']) ? (int)$lot['reject_number'] : 0;
            $isSs = ($lot['inspection_type'] === 'safety_stock' || $lot['lot_result'] === 'skipped');

            if ($isSs) {
                $lSampleSize = 0;
                $lRejectNum = 0;
            } elseif ($lSampleSize <= 0 || $lRejectNum <= 0) {
                $fb = getLotAqlFallback($lotQty, $pdo);
                if ($lSampleSize <= 0) $lSampleSize = $fb['sample_size'];
                if ($lRejectNum <= 0) $lRejectNum = $fb['reject_number'];
            }

            $lot['computed_sample_size'] = $lSampleSize;
            $lot['computed_reject_number'] = $lRejectNum;
            $lot['is_safety_stock'] = $isSs;
            $lot['total_ng'] = $lotNgTotal;

            $isLotLive = (empty($lot['closed_at']) && empty($lot['session_closed_at']));
            $scanTime = !empty($lot['scanned_at']) ? date('H:i', strtotime($lot['scanned_at'])) : '-';
            $isKb = ($lot['inspection_type'] === 'kanban');
            $remUpper = strtoupper($lot['remarks'] ?? '');
            $isRe = strpos($remUpper, 'RE-INSPEKSI') !== false || strpos($remUpper, 'REINSPEKSI') !== false;
            $isAwal = strpos($remUpper, 'AWAL') !== false;
    ?>
        <tr style="border-bottom: 1px solid #f1f5f9; background: <?= $isLotLive ? '#fff8f8' : ($hasDefects ? '#fffbfb' : 'transparent') ?>;">
            <td style="padding: 8px 10px; text-align: center; color: #94a3b8; font-weight: 700;"><?= $rowNum ?></td>
            <td style="padding: 8px 10px; text-align: center;">
                <div style="font-weight: 800; color: #0f172a; font-family: monospace;"><?= $scanTime ?> WIB</div>
                <div style="font-size: 10px; color: #1d4ed8; font-weight: 800;"><?= htmlspecialchars($lot['line_name']) ?></div>
            </td>
            <td style="padding: 8px 10px; text-align: center;">
                <?php if ($isKb): ?>
                    <span style="font-size: 9.5px; font-weight: 800; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; padding: 2px 7px; border-radius: 9999px;">Kanban</span>
                <?php else: ?>
                    <span style="font-size: 9.5px; font-weight: 800; background: #f3e8ff; color: #6b21a8; border: 1px solid #d8b4fe; padding: 2px 7px; border-radius: 9999px;">Safety Stock</span>
                <?php endif; ?>
            </td>
            <td style="padding: 8px 10px; font-family: monospace; font-weight: 800; color: #0f172a;">
                <?= htmlspecialchars($lot['part_code']) ?>
            </td>
            <td style="padding: 8px 10px; color: #334155; font-weight: 600;">
                <?= htmlspecialchars($lot['part_name']) ?>
            </td>
            <td style="padding: 8px 10px; text-align: center; font-size: 10.5px; color: #475569; font-weight: 700;">
                <?= htmlspecialchars($lot['model_name']) ?>
            </td>
            <td style="padding: 8px 10px; font-family: monospace; color: #1e293b; font-weight: 700;">
                <?= htmlspecialchars($lot['lot_number'] ?: '-') ?>
            </td>
            <td style="padding: 8px 10px; font-family: monospace; color: #7c3aed; font-weight: 800;">
                <?= htmlspecialchars($lot['ref_number'] ?: '-') ?>
                <?php if ($isRe): ?>
                    <span style="font-size: 9px; font-weight: 800; background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; padding: 1px 4px; border-radius: 3px; display: inline-block;">RE</span>
                <?php elseif ($isAwal): ?>
                    <span style="font-size: 9px; font-weight: 800; background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; padding: 1px 4px; border-radius: 3px; display: inline-block;">AWAL</span>
                <?php endif; ?>
            </td>
            <td style="padding: 8px 10px; text-align: right; font-family: monospace; font-weight: 700; color: #0f172a;">
                <?= number_format($lotQty) ?> pcs
            </td>
            <td style="padding: 8px 10px; text-align: center; font-family: monospace; font-weight: 700; color: #2563eb;">
                <?= $isSs ? '<span style="color:#7c3aed;font-weight:800;">0 (SS)</span>' : ($lSampleSize . ' pcs') ?>
            </td>
            <td style="padding: 8px 10px; text-align: center;">
                <span style="font-family: monospace; font-size: 10.5px; font-weight: 800; padding: 2px 6px; border-radius: 4px; <?= $lotNgTotal > 0 ? 'background: #fef2f2; color: #dc2626; border: 1px solid #fecaca;' : 'background: #f0fdf4; color: #16a34a; border: 1px solid #dcfce7;' ?>">
                    <?= $lotNgTotal ?>
                </span>
            </td>
            <td style="padding: 8px 10px;">
                <?php if ($hasDefects): ?>
                    <div style="display: flex; flex-direction: column; gap: 2px;">
                        <?php foreach ($dfs as $df): ?>
                            <span style="font-size: 10px; color: #dc2626; font-weight: 700;">
                                &bull; <?= htmlspecialchars($df['defect_name']) ?>: <?= (int)$df['qty_ng'] ?> pcs
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <span style="color: #94a3b8; font-style: italic; font-size: 10px;">Nihil (0 defect)</span>
                <?php endif; ?>
            </td>
            <td style="padding: 8px 10px; text-align: center;">
                <?php if ($isLotLive): ?>
                    <span class="oqc-rec-badge" style="font-size: 9.5px; padding: 2px 7px;">
                        <span class="oqc-rec-dot" style="width: 6px; height: 6px;"></span>
                        <span>REC IN PROG</span>
                    </span>
                <?php else: ?>
                    <?= renderLotStatusBadge($lot) ?>
                <?php endif; ?>
            </td>
            <td style="padding: 8px 10px; text-align: center;">
                <span style="font-family: monospace; font-weight: 900; font-size: 10.5px; color: #0f172a; background: #f1f5f9; padding: 2px 5px; border-radius: 4px; border: 1px solid #e2e8f0;">
                    #<?= $lot['session_id'] ?>
                </span>
            </td>
        </tr>
    <?php endforeach; 
    endif;
    $htmlContent = ob_get_clean();

    echo json_encode([
        'success'     => true,
        'html'        => $htmlContent,
        'page'        => $page,
        'total_pages' => $totalPages,
        'total_lots'  => $totalLots,
        'limit'       => $limit
    ]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}
