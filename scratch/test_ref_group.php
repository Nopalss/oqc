<?php
require_once __DIR__ . '/../config/database.php';
$pdo = getDB();

echo "=== TEST QUERY DETAIL.PHP WITH REF_NUMBER IN GROUP KEY ===\n";
$stmtLots = $pdo->query("
    SELECT
        isl.id AS lot_id,
        isl.ref_number,
        isl.lot_number,
        isl.qty,
        ss.id AS session_id,
        ss.inspection_type,
        ss.ng_count,
        ss.reject_number,
        ss.started_at,
        COALESCE(did.part_code, ki.item_code, '-') AS part_code,
        COALESCE(did.part_name, ki.item_description, '-') AS part_name,
        COALESCE(u.name, did.pic, 'Inspector QC') AS inspector_name
    FROM inspection_session_lots isl
    JOIN inspection_sessions ss ON ss.id = isl.inspection_session_id
    LEFT JOIN daily_inspection_data did ON did.id = ss.did_id
    LEFT JOIN kanban_items ki ON ki.id = ss.kanban_item_id
    LEFT JOIN users u ON u.id = ss.inspector_id
    WHERE (isl.remarks IS NULL OR isl.remarks NOT LIKE '%Sisa Split Safety Stock%')
      AND ss.inspection_type = 'safety_stock'
    ORDER BY isl.id ASC
");
$allLots = $stmtLots->fetchAll(PDO::FETCH_ASSOC);

$ssGrouped = [];
foreach ($allLots as $lot) {
    $groupKey = strtoupper($lot['part_code']) . '___' . strtoupper($lot['lot_number']) . '___' . strtoupper($lot['ref_number'] ?? '');
    if (!isset($ssGrouped[$groupKey])) {
        $ssGrouped[$groupKey] = $lot;
        $ssGrouped[$groupKey]['qty'] = (int)$lot['qty'];
        $ssGrouped[$groupKey]['box_count'] = 1;
        $ssGrouped[$groupKey]['ref_numbers'] = !empty($lot['ref_number']) ? [$lot['ref_number']] : [];
        $ssGrouped[$groupKey]['session_ids'] = [$lot['session_id']];
    } else {
        $ssGrouped[$groupKey]['qty'] += (int)$lot['qty'];
        $ssGrouped[$groupKey]['box_count']++;
        $ssGrouped[$groupKey]['ng_count'] += (int)$lot['ng_count'];
        if (!empty($lot['ref_number']) && !in_array($lot['ref_number'], $ssGrouped[$groupKey]['ref_numbers'])) {
            $ssGrouped[$groupKey]['ref_numbers'][] = $lot['ref_number'];
        }
        if (!in_array($lot['session_id'], $ssGrouped[$groupKey]['session_ids'])) {
            $ssGrouped[$groupKey]['session_ids'][] = $lot['session_id'];
        }
    }
}

$rowsSS = [];
foreach ($ssGrouped as $sGroup) {
    $sGroup['ref_number'] = implode(', ', array_filter($sGroup['ref_numbers']));
    if (empty($sGroup['ref_number'])) {
        $sGroup['ref_number'] = '-';
    }
    $rowsSS[] = $sGroup;
}

print_r($rowsSS);
