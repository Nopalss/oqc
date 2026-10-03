<?php
/**
 * Jadwal & Riwayat Scan Kanban (Kanban Schedule & Workboard)
 * Modul Outgoing Quality Control (OQC)
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

require_menu_access('inspection');

$pdo = getDB();

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
        
        // Standard ISO 2859-1 / MIL-STD-105E General Inspection Level II
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

// -------------------------------------------------------------------------
$currentUser = current_user();
$currentUserId = (int)$currentUser['id'];

$allowedTabs = ['kanban', 'safety_stock', 'my_inspections'];
$activeTab = (isset($_GET['tab']) && in_array($_GET['tab'], $allowedTabs, true)) ? $_GET['tab'] : 'kanban';

// Filter & Parameters
// -------------------------------------------------------------------------
$period = isset($_GET['period']) ? trim(sanitize($_GET['period'])) : 'today';
$startDate = isset($_GET['start_date']) ? trim(sanitize($_GET['start_date'])) : '';
$endDate = isset($_GET['end_date']) ? trim(sanitize($_GET['end_date'])) : '';
$search = isset($_GET['search']) ? trim(sanitize($_GET['search'])) : '';
// Clean and extract Part Code if scanner inputs full QR code format (Z1<part_code>|Z2<lot>|...)
if ($search !== '' && (strpos($search, 'Z1') !== false || strpos($search, '|') !== false)) {
    $parts = explode('|', $search);
    foreach ($parts as $p) {
        $p = trim($p);
        if (strpos($p, 'Z1') === 0) {
            $search = substr($p, 2);
            break;
        }
    }
}
$statusFilter = isset($_GET['status']) ? trim(sanitize($_GET['status'])) : 'all';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = isset($_GET['limit']) ? max(5, min(200, (int)$_GET['limit'])) : 25;
$offset = ($page - 1) * $limit;

if (!empty($startDate) || !empty($endDate)) {
    $period = 'custom';
}

$kanbans = [];
$ssSessions = [];
$mySessions = [];
$totalItems = 0;
$totalPages = 1;
$kanbanTabCount = 0;
$safetyStockTabCount = 0;
$myInspectionsTabCount = 0;
$substitutionsBySession = [];
$ngRecordsByLot = [];

// Stats default
$stats = [
    'total_kanban'         => 0,
    'uninspected_count'    => 0,
    'in_progress_count'    => 0,
    'completed_count'      => 0,
    'rejected_count'       => 0,
    'in_progress_sessions' => 0,
    // Safety Stock specific stats
    'total_count'          => 0,
    'passed_count'         => 0,
    'total_qty_pcs'        => 0
];

$sessionsByKanban = [];
$lotsBySession = [];
$activeSessionByKanban = [];

if ($pdo) {
    try {
        // =====================================================================
        // COMMON DATE PERIOD CRITERIA BUILDERS
        // =====================================================================
        $todayStart = date('Y-m-d 00:00:00');
        $todayEnd   = date('Y-m-d 23:59:59');
        $wStart     = date('Y-m-d 00:00:00', strtotime('monday this week'));
        $wEnd       = date('Y-m-d 23:59:59', strtotime('sunday this week'));
        $mStart     = date('Y-m-01 00:00:00');
        $mEnd       = date('Y-m-t 23:59:59');

        // =====================================================================
        // 1. KANBAN BASE CONDITIONS & PARAMETERS
        // =====================================================================
        $kbBaseWhere = ["1=1"];
        $kbBaseParams = [];
        $kbBaseWhere[] = "(k.plan_type IS NULL OR k.plan_type = 'kanban')";
        $kbBaseWhere[] = "(k.check_type IS NULL OR k.check_type != 'Safety Stock')";
        $kbBaseWhere[] = "(k.kanban_no IS NULL OR k.kanban_no NOT LIKE 'SS-%')";
        $kbBaseWhere[] = "(k.str_loc IS NULL OR k.str_loc NOT IN ('WH-SS-OVERFLOW', 'WH-SS-SPLIT'))";

        if ($period === 'today') {
            $kbBaseWhere[] = "(
                (COALESCE(k.eta, k.req_date) BETWEEN :kb_t_start1 AND :kb_t_end1)
                OR (k.created_at BETWEEN :kb_t_start2 AND :kb_t_end2)
                OR EXISTS (
                    SELECT 1 FROM inspection_sessions s_today 
                    WHERE s_today.kanban_item_id = k.id 
                      AND s_today.started_at BETWEEN :kb_t_start3 AND :kb_t_end3
                )
                OR (
                    k.status NOT IN ('completed', 'rejected')
                    AND COALESCE(k.eta, k.req_date, k.created_at) < :kb_t_start4
                )
            )";
            $kbBaseParams[':kb_t_start1'] = $todayStart;
            $kbBaseParams[':kb_t_end1']   = $todayEnd;
            $kbBaseParams[':kb_t_start2'] = $todayStart;
            $kbBaseParams[':kb_t_end2']   = $todayEnd;
            $kbBaseParams[':kb_t_start3'] = $todayStart;
            $kbBaseParams[':kb_t_end3']   = $todayEnd;
            $kbBaseParams[':kb_t_start4'] = $todayStart;
        } elseif ($period === 'this_week') {
            $kbBaseWhere[] = "(
                (COALESCE(k.eta, k.req_date) BETWEEN :kb_w_start1 AND :kb_w_end1)
                OR (k.created_at BETWEEN :kb_w_start2 AND :kb_w_end2)
                OR EXISTS (
                    SELECT 1 FROM inspection_sessions s_week 
                    WHERE s_week.kanban_item_id = k.id 
                      AND s_week.started_at BETWEEN :kb_w_start3 AND :kb_w_end3
                )
                OR (
                    k.status NOT IN ('completed', 'rejected')
                    AND COALESCE(k.eta, k.req_date, k.created_at) < :kb_w_start4
                )
            )";
            $kbBaseParams[':kb_w_start1'] = $wStart;
            $kbBaseParams[':kb_w_end1']   = $wEnd;
            $kbBaseParams[':kb_w_start2'] = $wStart;
            $kbBaseParams[':kb_w_end2']   = $wEnd;
            $kbBaseParams[':kb_w_start3'] = $wStart;
            $kbBaseParams[':kb_w_end3']   = $wEnd;
            $kbBaseParams[':kb_w_start4'] = $wStart;
        } elseif ($period === 'this_month') {
            $kbBaseWhere[] = "(
                (COALESCE(k.eta, k.req_date) BETWEEN :kb_m_start1 AND :kb_m_end1)
                OR (k.created_at BETWEEN :kb_m_start2 AND :kb_m_end2)
                OR EXISTS (
                    SELECT 1 FROM inspection_sessions s_month 
                    WHERE s_month.kanban_item_id = k.id 
                      AND s_month.started_at BETWEEN :kb_m_start3 AND :kb_m_end3
                )
                OR (
                    k.status NOT IN ('completed', 'rejected')
                    AND COALESCE(k.eta, k.req_date, k.created_at) < :kb_m_start4
                )
            )";
            $kbBaseParams[':kb_m_start1'] = $mStart;
            $kbBaseParams[':kb_m_end1']   = $mEnd;
            $kbBaseParams[':kb_m_start2'] = $mStart;
            $kbBaseParams[':kb_m_end2']   = $mEnd;
            $kbBaseParams[':kb_m_start3'] = $mStart;
            $kbBaseParams[':kb_m_end3']   = $mEnd;
            $kbBaseParams[':kb_m_start4'] = $mStart;
        } elseif ($period === 'custom') {
            if (!empty($startDate) && !empty($endDate)) {
                $kbBaseWhere[] = "(
                    (COALESCE(k.eta, k.req_date) BETWEEN :kb_c_start1 AND :kb_c_end1)
                    OR (k.created_at BETWEEN :kb_c_start2 AND :kb_c_end2)
                    OR EXISTS (
                        SELECT 1 FROM inspection_sessions s_custom 
                        WHERE s_custom.kanban_item_id = k.id 
                          AND s_custom.started_at BETWEEN :kb_c_start3 AND :kb_c_end3
                    )
                )";
                $cStart = $startDate . ' 00:00:00';
                $cEnd   = $endDate . ' 23:59:59';
                $kbBaseParams[':kb_c_start1'] = $cStart;
                $kbBaseParams[':kb_c_end1']   = $cEnd;
                $kbBaseParams[':kb_c_start2'] = $cStart;
                $kbBaseParams[':kb_c_end2']   = $cEnd;
                $kbBaseParams[':kb_c_start3'] = $cStart;
                $kbBaseParams[':kb_c_end3']   = $cEnd;
            } elseif (!empty($startDate)) {
                $kbBaseWhere[] = "(
                    (COALESCE(k.eta, k.req_date) >= :kb_c_start1)
                    OR (k.created_at >= :kb_c_start2)
                    OR EXISTS (
                        SELECT 1 FROM inspection_sessions s_custom 
                        WHERE s_custom.kanban_item_id = k.id 
                          AND s_custom.started_at >= :kb_c_start3
                    )
                )";
                $cStart = $startDate . ' 00:00:00';
                $kbBaseParams[':kb_c_start1'] = $cStart;
                $kbBaseParams[':kb_c_start2'] = $cStart;
                $kbBaseParams[':kb_c_start3'] = $cStart;
            } elseif (!empty($endDate)) {
                $kbBaseWhere[] = "(
                    (COALESCE(k.eta, k.req_date) <= :kb_c_end1)
                    OR (k.created_at <= :kb_c_end2)
                    OR EXISTS (
                        SELECT 1 FROM inspection_sessions s_custom 
                        WHERE s_custom.kanban_item_id = k.id 
                          AND s_custom.started_at <= :kb_c_end3
                    )
                )";
                $cEnd = $endDate . ' 23:59:59';
                $kbBaseParams[':kb_c_end1'] = $cEnd;
                $kbBaseParams[':kb_c_end2'] = $cEnd;
                $kbBaseParams[':kb_c_end3'] = $cEnd;
            }
        }

        // =====================================================================
        // 2. SAFETY STOCK (CEK FISIK) BASE CONDITIONS & PARAMETERS
        // (Eksklusi murni: remnant/overflow/sisa split otomatis TIDAK dimasukkan)
        // =====================================================================
        $ssBaseWhere = [
            "s.inspection_type = 'safety_stock'",
            "s.original_ss_session_id IS NULL",
            "(s.inspector_id IS NOT NULL OR s.flow_version = 2 OR s.sample_size > 1)",
            "(did.pic IS NULL OR did.pic != 'SAFETY_STOCK_OVERFLOW')",
            "(k.str_loc IS NULL OR k.str_loc NOT IN ('WH-SS-OVERFLOW', 'WH-SS-SPLIT'))",
            "(k.remark IS NULL OR (k.remark NOT LIKE '%AUTO-SS-SESS-%' AND k.remark NOT LIKE '%Split%'))"
        ];
        $ssBaseParams = [];

        if ($period === 'today') {
            $ssBaseWhere[] = "s.started_at BETWEEN :ss_t_start AND :ss_t_end";
            $ssBaseParams[':ss_t_start'] = $todayStart;
            $ssBaseParams[':ss_t_end']   = $todayEnd;
        } elseif ($period === 'this_week') {
            $ssBaseWhere[] = "s.started_at BETWEEN :ss_w_start AND :ss_w_end";
            $ssBaseParams[':ss_w_start'] = $wStart;
            $ssBaseParams[':ss_w_end']   = $wEnd;
        } elseif ($period === 'this_month') {
            $ssBaseWhere[] = "s.started_at BETWEEN :ss_m_start AND :ss_m_end";
            $ssBaseParams[':ss_m_start'] = $mStart;
            $ssBaseParams[':ss_m_end']   = $mEnd;
        } elseif ($period === 'custom') {
            if (!empty($startDate) && !empty($endDate)) {
                $ssBaseWhere[] = "s.started_at BETWEEN :ss_c_start AND :ss_c_end";
                $ssBaseParams[':ss_c_start'] = $startDate . ' 00:00:00';
                $ssBaseParams[':ss_c_end']   = $endDate . ' 23:59:59';
            } elseif (!empty($startDate)) {
                $ssBaseWhere[] = "s.started_at >= :ss_c_start";
                $ssBaseParams[':ss_c_start'] = $startDate . ' 00:00:00';
            } elseif (!empty($endDate)) {
                $ssBaseWhere[] = "s.started_at <= :ss_c_end";
                $ssBaseParams[':ss_c_end']   = $endDate . ' 23:59:59';
            }
        }

        // =====================================================================
        // 3. RIWAYAT SAYA (INSPEKSI SAYA) BASE CONDITIONS & PARAMETERS
        // =====================================================================
        $myBaseWhere = ["s.inspector_id = :my_uid"];
        $myBaseParams = [':my_uid' => $currentUserId];

        if ($period === 'today') {
            $myBaseWhere[] = "s.started_at BETWEEN :my_t_start AND :my_t_end";
            $myBaseParams[':my_t_start'] = $todayStart;
            $myBaseParams[':my_t_end']   = $todayEnd;
        } elseif ($period === 'this_week') {
            $myBaseWhere[] = "s.started_at BETWEEN :my_w_start AND :my_w_end";
            $myBaseParams[':my_w_start'] = $wStart;
            $myBaseParams[':my_w_end']   = $wEnd;
        } elseif ($period === 'this_month') {
            $myBaseWhere[] = "s.started_at BETWEEN :my_m_start AND :my_m_end";
            $myBaseParams[':my_m_start'] = $mStart;
            $myBaseParams[':my_m_end']   = $mEnd;
        } elseif ($period === 'custom') {
            if (!empty($startDate) && !empty($endDate)) {
                $myBaseWhere[] = "s.started_at BETWEEN :my_c_start AND :my_c_end";
                $myBaseParams[':my_c_start'] = $startDate . ' 00:00:00';
                $myBaseParams[':my_c_end']   = $endDate . ' 23:59:59';
            } elseif (!empty($startDate)) {
                $myBaseWhere[] = "s.started_at >= :my_c_start";
                $myBaseParams[':my_c_start'] = $startDate . ' 00:00:00';
            } elseif (!empty($endDate)) {
                $myBaseWhere[] = "s.started_at <= :my_c_end";
                $myBaseParams[':my_c_end']   = $endDate . ' 23:59:59';
            }
        }

        // =====================================================================
        // TAB 1: KANBAN DELIVERY WORKFLOW
        // =====================================================================
        if ($activeTab === 'kanban') {

            // Search text filter
            if ($search !== '') {
                $kbBaseWhere[] = "(k.kanban_no LIKE :kb_s1 OR k.item_code LIKE :kb_s2 OR k.item_description LIKE :kb_s3 OR k.customer LIKE :kb_s4 OR mp.part_name LIKE :kb_s5)";
                $kbBaseParams[':kb_s1'] = '%' . $search . '%';
                $kbBaseParams[':kb_s2'] = '%' . $search . '%';
                $kbBaseParams[':kb_s3'] = '%' . $search . '%';
                $kbBaseParams[':kb_s4'] = '%' . $search . '%';
                $kbBaseParams[':kb_s5'] = '%' . $search . '%';
            }

            // Calculate Kanban KPI Statistics
            $kbStatsWhereClause = implode(" AND ", $kbBaseWhere);
            $statsSql = "
                SELECT 
                    COUNT(DISTINCT k.id) as total_kanban,
                    SUM(CASE WHEN k.status = 'uninspected' THEN 1 ELSE 0 END) as uninspected_count,
                    SUM(CASE WHEN k.status IN ('in_progress', 'partial') THEN 1 ELSE 0 END) as in_progress_count,
                    SUM(CASE WHEN k.status = 'completed' THEN 1 ELSE 0 END) as completed_count,
                    SUM(CASE WHEN k.status = 'rejected' THEN 1 ELSE 0 END) as rejected_count
                FROM kanban_items k
                LEFT JOIN master_parts mp ON UPPER(mp.part_code) = UPPER(k.item_code)
                WHERE {$kbStatsWhereClause}
            ";
            $stmtStats = $pdo->prepare($statsSql);
            $stmtStats->execute($kbBaseParams);
            $statRow = $stmtStats->fetch(PDO::FETCH_ASSOC);
            if ($statRow) {
                $stats['total_kanban']      = (int)($statRow['total_kanban'] ?? 0);
                $stats['uninspected_count'] = (int)($statRow['uninspected_count'] ?? 0);
                $stats['in_progress_count'] = (int)($statRow['in_progress_count'] ?? 0);
                $stats['completed_count']   = (int)($statRow['completed_count'] ?? 0);
                $stats['rejected_count']    = (int)($statRow['rejected_count'] ?? 0);
            }
            $kanbanTabCount = $stats['total_kanban'];

            // Check overall in-progress sessions for shift handover alert
            $stmtInProgSess = $pdo->query("SELECT COUNT(*) FROM inspection_sessions WHERE status = 'in_progress'");
            $stats['in_progress_sessions'] = (int)$stmtInProgSess->fetchColumn();

            // Quick count for Safety Stock Tab Badge
            $ssBadgeWhereClause = implode(" AND ", $ssBaseWhere);
            $stmtSSBadge = $pdo->prepare("
                SELECT COUNT(DISTINCT s.id) 
                FROM inspection_sessions s
                LEFT JOIN kanban_items k ON k.id = s.kanban_item_id
                LEFT JOIN daily_inspection_data did ON did.id = s.did_id
                WHERE {$ssBadgeWhereClause}
            ");
            $stmtSSBadge->execute($ssBaseParams);
            $safetyStockTabCount = (int)$stmtSSBadge->fetchColumn();

            // Quick count for Riwayat Saya Tab Badge
            $myBadgeWhereClause = implode(" AND ", $myBaseWhere);
            $stmtMyBadge = $pdo->prepare("SELECT COUNT(DISTINCT s.id) FROM inspection_sessions s WHERE {$myBadgeWhereClause}");
            $stmtMyBadge->execute($myBaseParams);
            $myInspectionsTabCount = (int)$stmtMyBadge->fetchColumn();

            // Build Table Filters for Kanban
            $tableWhere = $kbBaseWhere;
            $tableParams = $kbBaseParams;

            if ($statusFilter === 'uninspected') {
                $tableWhere[] = "k.status = 'uninspected' AND NOT EXISTS (SELECT 1 FROM inspection_sessions s_act WHERE s_act.kanban_item_id = k.id AND s_act.status = 'in_progress')";
            } elseif ($statusFilter === 'in_progress') {
                $tableWhere[] = "(k.status = 'in_progress' OR EXISTS (SELECT 1 FROM inspection_sessions s_act WHERE s_act.kanban_item_id = k.id AND s_act.status = 'in_progress'))";
            } elseif ($statusFilter === 'partial') {
                $tableWhere[] = "k.status = 'partial'";
            } elseif ($statusFilter === 'completed') {
                $tableWhere[] = "k.status = 'completed'";
            } elseif ($statusFilter === 'rejected') {
                $tableWhere[] = "(k.status = 'rejected' OR EXISTS (
                    SELECT 1 FROM inspection_sessions s_rej 
                    WHERE s_rej.kanban_item_id = k.id AND s_rej.status = 'rejected'
                ) OR EXISTS (
                    SELECT 1 FROM inspection_sessions s_lot
                    JOIN inspection_session_lots isl_rej ON isl_rej.inspection_session_id = s_lot.id
                    WHERE s_lot.kanban_item_id = k.id AND (isl_rej.lot_result = 'rejected' OR isl_rej.lot_status = 'rejected')
                ))";
            }

            $tableWhereClause = implode(" AND ", $tableWhere);

            // Count Total Records for Pagination
            $countSql = "
                SELECT COUNT(DISTINCT k.id) 
                FROM kanban_items k
                LEFT JOIN master_parts mp ON UPPER(mp.part_code) = UPPER(k.item_code)
                WHERE {$tableWhereClause}
            ";
            $stmtCount = $pdo->prepare($countSql);
            $stmtCount->execute($tableParams);
            $totalItems = (int)$stmtCount->fetchColumn();

            $totalPages = max(1, ceil($totalItems / $limit));
            if ($page > $totalPages) $page = $totalPages;
            $offset = ($page - 1) * $limit;

            // Fetch Kanban Schedule Items
            $sql = "
                SELECT k.*,
                       b.document_number as doc_no,
                       COALESCE(NULLIF(mp.part_name, ''), k.item_description) as display_part_name,
                       COALESCE((
                           SELECT SUM(
                               CASE 
                                   WHEN EXISTS (SELECT 1 FROM inspection_session_lots isl_c WHERE isl_c.inspection_session_id = s2.id) THEN
                                       COALESCE((SELECT SUM(CASE WHEN isl2.lot_result = 'passed' AND (isl2.lot_status IS NULL OR isl2.lot_status != 'replaced') THEN isl2.qty ELSE 0 END)
                                                 FROM inspection_session_lots isl2 WHERE isl2.inspection_session_id = s2.id), 0) - COALESCE(s2.excess_qty, 0)
                                   WHEN s2.status = 'passed' THEN
                                       (s2.total_scanned_qty - COALESCE(s2.excess_qty, 0))
                                   ELSE 0
                               END
                           )
                           FROM inspection_sessions s2
                           WHERE s2.kanban_item_id = k.id
                       ), 0) AS total_passed_qty,
                       COUNT(s.id) AS total_sessions_count,
                       SUM(CASE WHEN s.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_sessions_count
                FROM kanban_items k
                LEFT JOIN kanban_batches b ON b.id = k.batch_id
                LEFT JOIN master_parts mp ON UPPER(mp.part_code) = UPPER(k.item_code)
                LEFT JOIN inspection_sessions s ON s.kanban_item_id = k.id
                WHERE {$tableWhereClause}
                GROUP BY k.id, b.document_number, mp.part_name
                ORDER BY 
                    CASE 
                        WHEN k.status = 'completed' OR COALESCE((
                           SELECT SUM(
                               CASE 
                                   WHEN EXISTS (SELECT 1 FROM inspection_session_lots isl_c WHERE isl_c.inspection_session_id = s2.id) THEN
                                       COALESCE((SELECT SUM(CASE WHEN isl2.lot_result = 'passed' AND (isl2.lot_status IS NULL OR isl2.lot_status != 'replaced') THEN isl2.qty ELSE 0 END)
                                                 FROM inspection_session_lots isl2 WHERE isl2.inspection_session_id = s2.id), 0) - COALESCE(s2.excess_qty, 0)
                                   WHEN s2.status = 'passed' THEN
                                       (s2.total_scanned_qty - COALESCE(s2.excess_qty, 0))
                                   ELSE 0
                               END
                           )
                           FROM inspection_sessions s2
                           WHERE s2.kanban_item_id = k.id
                       ), 0) >= k.qty 
                        THEN 2 
                        WHEN DATE(COALESCE(k.eta, k.req_date, k.created_at)) < CURDATE()
                        THEN 0 
                        ELSE 1 
                    END ASC,
                    CASE WHEN k.eta IS NULL AND k.req_date IS NULL THEN 1 ELSE 0 END ASC,
                    COALESCE(k.eta, k.req_date, k.created_at) ASC,
                    k.id ASC
                LIMIT {$limit} OFFSET {$offset}
            ";
            $stmtKanban = $pdo->prepare($sql);
            $stmtKanban->execute($tableParams);
            $kanbans = $stmtKanban->fetchAll(PDO::FETCH_ASSOC);

            // Batch fetch session details and lots for the displayed kanbans
            $kanbanIds = array_column($kanbans, 'id');
            if (!empty($kanbanIds)) {
                $inKanbanIds = implode(',', array_map('intval', $kanbanIds));
                $sessSql = "
                    SELECT s.*,
                           COALESCE(u.name, did.pic, 'QC Inspector') AS inspector_name,
                           TIMESTAMPDIFF(MINUTE, s.started_at, COALESCE(s.closed_at, NOW())) AS duration_minutes
                    FROM inspection_sessions s
                    LEFT JOIN users u ON u.id = s.inspector_id
                    LEFT JOIN daily_inspection_data did ON did.id = s.did_id
                    WHERE s.kanban_item_id IN ($inKanbanIds)
                    ORDER BY s.id ASC
                ";
                $stmtSessions = $pdo->query($sessSql);
                $sessionRows = $stmtSessions->fetchAll(PDO::FETCH_ASSOC);

                $sessionIds = [];
                foreach ($sessionRows as $sRow) {
                    $kid = (int)$sRow['kanban_item_id'];
                    $sessionsByKanban[$kid][] = $sRow;
                    $sessionIds[] = (int)$sRow['id'];
                    if ($sRow['status'] === 'in_progress') {
                        $activeSessionByKanban[$kid] = $sRow;
                    }
                }

                if (!empty($sessionIds)) {
                    $inSessionIds = implode(',', $sessionIds);
                    $lotSql = "
                        SELECT id, inspection_session_id, lot_number, ref_number, qty, sample_size, lot_result, lot_status, remarks
                        FROM inspection_session_lots
                        WHERE inspection_session_id IN ($inSessionIds)
                        ORDER BY id ASC
                    ";
                    $stmtLots = $pdo->query($lotSql);
                    while ($lRow = $stmtLots->fetch(PDO::FETCH_ASSOC)) {
                        $sid = (int)$lRow['inspection_session_id'];
                        $lotsBySession[$sid][] = $lRow;
                    }
                }
            }

        // =====================================================================
        // TAB 2: SAFETY STOCK (CEK FISIK MURNI DARI GUDANG)
        // =====================================================================
        } elseif ($activeTab === 'safety_stock') {

            // Search text filter for Safety Stock
            if ($search !== '') {
                $ssBaseWhere[] = "(
                    s.id LIKE :ss_s1 
                    OR mp.part_code LIKE :ss_s2 
                    OR mp.part_name LIKE :ss_s3 
                    OR did.part_code LIKE :ss_s4 
                    OR did.part_name LIKE :ss_s5 
                    OR k.kanban_no LIKE :ss_s6 
                    OR u.name LIKE :ss_s7 
                    OR did.pic LIKE :ss_s8
                )";
                $ssBaseParams[':ss_s1'] = '%' . $search . '%';
                $ssBaseParams[':ss_s2'] = '%' . $search . '%';
                $ssBaseParams[':ss_s3'] = '%' . $search . '%';
                $ssBaseParams[':ss_s4'] = '%' . $search . '%';
                $ssBaseParams[':ss_s5'] = '%' . $search . '%';
                $ssBaseParams[':ss_s6'] = '%' . $search . '%';
                $ssBaseParams[':ss_s7'] = '%' . $search . '%';
                $ssBaseParams[':ss_s8'] = '%' . $search . '%';
            }

            // Calculate Safety Stock KPI Statistics
            $ssStatsWhereClause = implode(" AND ", $ssBaseWhere);
            $statsSql = "
                SELECT 
                    COUNT(DISTINCT s.id) as total_count,
                    SUM(CASE WHEN s.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_count,
                    SUM(CASE WHEN s.status = 'passed' THEN 1 ELSE 0 END) as passed_count,
                    SUM(CASE WHEN s.status = 'rejected' THEN 1 ELSE 0 END) as rejected_count,
                    COALESCE(SUM(s.total_scanned_qty), 0) as total_qty_pcs
                FROM inspection_sessions s
                LEFT JOIN kanban_items k ON k.id = s.kanban_item_id
                LEFT JOIN master_parts mp ON mp.id = s.part_id
                LEFT JOIN daily_inspection_data did ON did.id = s.did_id
                LEFT JOIN users u ON u.id = s.inspector_id
                WHERE {$ssStatsWhereClause}
            ";
            $stmtStats = $pdo->prepare($statsSql);
            $stmtStats->execute($ssBaseParams);
            $statRow = $stmtStats->fetch(PDO::FETCH_ASSOC);
            if ($statRow) {
                $stats['total_count']       = (int)($statRow['total_count'] ?? 0);
                $stats['in_progress_count'] = (int)($statRow['in_progress_count'] ?? 0);
                $stats['passed_count']      = (int)($statRow['passed_count'] ?? 0);
                $stats['rejected_count']    = (int)($statRow['rejected_count'] ?? 0);
                $stats['total_qty_pcs']     = (int)($statRow['total_qty_pcs'] ?? 0);
            }
            $safetyStockTabCount = $stats['total_count'];

            // Quick count for Kanban Tab Badge
            $kbBadgeWhereClause = implode(" AND ", $kbBaseWhere);
            $stmtKbBadge = $pdo->prepare("SELECT COUNT(DISTINCT k.id) FROM kanban_items k WHERE {$kbBadgeWhereClause}");
            $stmtKbBadge->execute($kbBaseParams);
            $kanbanTabCount = (int)$stmtKbBadge->fetchColumn();

            // Quick count for Riwayat Saya Tab Badge
            $myBadgeWhereClause = implode(" AND ", $myBaseWhere);
            $stmtMyBadge = $pdo->prepare("SELECT COUNT(DISTINCT s.id) FROM inspection_sessions s WHERE {$myBadgeWhereClause}");
            $stmtMyBadge->execute($myBaseParams);
            $myInspectionsTabCount = (int)$stmtMyBadge->fetchColumn();

            // Build Table Filters for Safety Stock
            $tableWhere = $ssBaseWhere;
            $tableParams = $ssBaseParams;

            if ($statusFilter === 'in_progress') {
                $tableWhere[] = "s.status = 'in_progress'";
            } elseif ($statusFilter === 'completed') {
                $tableWhere[] = "s.status = 'passed'";
            } elseif ($statusFilter === 'rejected') {
                $tableWhere[] = "s.status = 'rejected'";
            }

            $tableWhereClause = implode(" AND ", $tableWhere);

            // Count Total Records for Pagination
            $countSql = "
                SELECT COUNT(DISTINCT s.id) 
                FROM inspection_sessions s
                LEFT JOIN kanban_items k ON k.id = s.kanban_item_id
                LEFT JOIN master_parts mp ON mp.id = s.part_id
                LEFT JOIN daily_inspection_data did ON did.id = s.did_id
                LEFT JOIN users u ON u.id = s.inspector_id
                WHERE {$tableWhereClause}
            ";
            $stmtCount = $pdo->prepare($countSql);
            $stmtCount->execute($tableParams);
            $totalItems = (int)$stmtCount->fetchColumn();

            $totalPages = max(1, ceil($totalItems / $limit));
            if ($page > $totalPages) $page = $totalPages;
            $offset = ($page - 1) * $limit;

            // Fetch Physical Safety Stock Inspection Sessions
            $sql = "
                SELECT s.*,
                       COALESCE(mp.part_code, k.item_code, did.part_code, '-') AS part_number,
                       COALESCE(NULLIF(mp.part_name, ''), NULLIF(did.part_name, ''), k.item_description, '-') AS display_part_name,
                       COALESCE(u.name, did.pic, 'QC Inspector') AS inspector_name,
                       TIMESTAMPDIFF(MINUTE, s.started_at, COALESCE(s.closed_at, NOW())) AS duration_minutes,
                       k.kanban_no, k.customer, k.str_loc as storage_loc
                FROM inspection_sessions s
                LEFT JOIN kanban_items k ON k.id = s.kanban_item_id
                LEFT JOIN master_parts mp ON mp.id = s.part_id
                LEFT JOIN daily_inspection_data did ON did.id = s.did_id
                LEFT JOIN users u ON u.id = s.inspector_id
                WHERE {$tableWhereClause}
                ORDER BY s.started_at DESC, s.id DESC
                LIMIT {$limit} OFFSET {$offset}
            ";
            $stmtSS = $pdo->prepare($sql);
            $stmtSS->execute($tableParams);
            $ssSessions = $stmtSS->fetchAll(PDO::FETCH_ASSOC);

            // Batch fetch lots for the displayed safety stock sessions
            $sessionIds = array_column($ssSessions, 'id');
            if (!empty($sessionIds)) {
                $inSessionIds = implode(',', array_map('intval', $sessionIds));
                $lotSql = "
                    SELECT id, inspection_session_id, lot_number, ref_number, qty, sample_size, lot_result, lot_status, remarks, scanned_qr_raw, created_at
                    FROM inspection_session_lots
                    WHERE inspection_session_id IN ($inSessionIds)
                    ORDER BY id ASC
                ";
                $stmtLots = $pdo->query($lotSql);
                while ($lRow = $stmtLots->fetch(PDO::FETCH_ASSOC)) {
                    $sid = (int)$lRow['inspection_session_id'];
                    $lotsBySession[$sid][] = $lRow;
                }
            }

        // =====================================================================
        // TAB 3: RIWAYAT SAYA (INSPEKSI OLEH USER LOGIN)
        // =====================================================================
        } elseif ($activeTab === 'my_inspections') {

            // Search text filter for My Inspections
            if ($search !== '') {
                $myBaseWhere[] = "(
                    s.id LIKE :my_s1 
                    OR mp.part_code LIKE :my_s2 
                    OR mp.part_name LIKE :my_s3 
                    OR did.part_code LIKE :my_s4 
                    OR did.part_name LIKE :my_s5 
                    OR k.kanban_no LIKE :my_s6 
                    OR mm.name LIKE :my_s7 
                    OR EXISTS (
                        SELECT 1 FROM inspection_session_lots isl_srch 
                        WHERE isl_srch.inspection_session_id = s.id 
                          AND (isl_srch.lot_number LIKE :my_s8 OR isl_srch.ref_number LIKE :my_s9)
                    )
                )";
                $myBaseParams[':my_s1'] = '%' . $search . '%';
                $myBaseParams[':my_s2'] = '%' . $search . '%';
                $myBaseParams[':my_s3'] = '%' . $search . '%';
                $myBaseParams[':my_s4'] = '%' . $search . '%';
                $myBaseParams[':my_s5'] = '%' . $search . '%';
                $myBaseParams[':my_s6'] = '%' . $search . '%';
                $myBaseParams[':my_s7'] = '%' . $search . '%';
                $myBaseParams[':my_s8'] = '%' . $search . '%';
                $myBaseParams[':my_s9'] = '%' . $search . '%';
            }

            // Calculate My Inspections KPI Statistics
            $myStatsWhereClause = implode(" AND ", $myBaseWhere);
            $statsSql = "
                SELECT 
                    COUNT(DISTINCT s.id) as total_count,
                    SUM(CASE WHEN s.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_count,
                    SUM(CASE WHEN s.status = 'passed' THEN 1 ELSE 0 END) as passed_count,
                    SUM(CASE WHEN s.status = 'rejected' THEN 1 ELSE 0 END) as rejected_count,
                    COALESCE(SUM(s.total_scanned_qty), 0) as total_qty_pcs
                FROM inspection_sessions s
                LEFT JOIN kanban_items k ON k.id = s.kanban_item_id
                LEFT JOIN master_parts mp ON (mp.id = s.part_id OR UPPER(mp.part_code) = UPPER(COALESCE(k.item_code, '')))
                LEFT JOIN master_models mm ON mm.id = mp.model_id
                LEFT JOIN daily_inspection_data did ON did.id = s.did_id
                WHERE {$myStatsWhereClause}
            ";
            $stmtStats = $pdo->prepare($statsSql);
            $stmtStats->execute($myBaseParams);
            $statRow = $stmtStats->fetch(PDO::FETCH_ASSOC);
            if ($statRow) {
                $stats['total_count']       = (int)($statRow['total_count'] ?? 0);
                $stats['in_progress_count'] = (int)($statRow['in_progress_count'] ?? 0);
                $stats['passed_count']      = (int)($statRow['passed_count'] ?? 0);
                $stats['rejected_count']    = (int)($statRow['rejected_count'] ?? 0);
                $stats['total_qty_pcs']     = (int)($statRow['total_qty_pcs'] ?? 0);
            }
            $myInspectionsTabCount = $stats['total_count'];

            // Quick count for Kanban Tab Badge
            $kbBadgeWhereClause = implode(" AND ", $kbBaseWhere);
            $stmtKbBadge = $pdo->prepare("SELECT COUNT(DISTINCT k.id) FROM kanban_items k WHERE {$kbBadgeWhereClause}");
            $stmtKbBadge->execute($kbBaseParams);
            $kanbanTabCount = (int)$stmtKbBadge->fetchColumn();

            // Quick count for Safety Stock Tab Badge
            $ssBadgeWhereClause = implode(" AND ", $ssBaseWhere);
            $stmtSSBadge = $pdo->prepare("
                SELECT COUNT(DISTINCT s.id) 
                FROM inspection_sessions s
                LEFT JOIN kanban_items k ON k.id = s.kanban_item_id
                LEFT JOIN daily_inspection_data did ON did.id = s.did_id
                WHERE {$ssBadgeWhereClause}
            ");
            $stmtSSBadge->execute($ssBaseParams);
            $safetyStockTabCount = (int)$stmtSSBadge->fetchColumn();

            // Build Table Filters for My Inspections
            $tableWhere = $myBaseWhere;
            $tableParams = $myBaseParams;

            if ($statusFilter === 'in_progress') {
                $tableWhere[] = "s.status = 'in_progress'";
            } elseif ($statusFilter === 'completed') {
                $tableWhere[] = "s.status = 'passed'";
            } elseif ($statusFilter === 'rejected') {
                $tableWhere[] = "s.status = 'rejected'";
            }

            $tableWhereClause = implode(" AND ", $tableWhere);

            // Count Total Records for Pagination
            $countSql = "
                SELECT COUNT(DISTINCT s.id) 
                FROM inspection_sessions s
                LEFT JOIN kanban_items k ON k.id = s.kanban_item_id
                LEFT JOIN master_parts mp ON (mp.id = s.part_id OR UPPER(mp.part_code) = UPPER(COALESCE(k.item_code, '')))
                LEFT JOIN master_models mm ON mm.id = mp.model_id
                LEFT JOIN daily_inspection_data did ON did.id = s.did_id
                WHERE {$tableWhereClause}
            ";
            $stmtCount = $pdo->prepare($countSql);
            $stmtCount->execute($tableParams);
            $totalItems = (int)$stmtCount->fetchColumn();

            $totalPages = max(1, ceil($totalItems / $limit));
            if ($page > $totalPages) $page = $totalPages;
            $offset = ($page - 1) * $limit;

            // Fetch My Inspection Sessions
            $sql = "
                SELECT s.*,
                       COALESCE(mp.part_code, k.item_code, did.part_code, '-') AS part_number,
                       COALESCE(NULLIF(mp.part_name, ''), NULLIF(did.part_name, ''), k.item_description, '-') AS display_part_name,
                       COALESCE(mm.name, mp.model, '-') AS model_name,
                       COALESCE(u.name, did.pic, 'QC Inspector') AS inspector_name,
                       TIMESTAMPDIFF(MINUTE, s.started_at, COALESCE(s.closed_at, NOW())) AS duration_minutes,
                       k.kanban_no, k.customer, k.str_loc as storage_loc
                FROM inspection_sessions s
                LEFT JOIN kanban_items k ON k.id = s.kanban_item_id
                LEFT JOIN master_parts mp ON (mp.id = s.part_id OR UPPER(mp.part_code) = UPPER(COALESCE(k.item_code, '')))
                LEFT JOIN master_models mm ON mm.id = mp.model_id
                LEFT JOIN daily_inspection_data did ON did.id = s.did_id
                LEFT JOIN users u ON u.id = s.inspector_id
                WHERE {$tableWhereClause}
                ORDER BY s.started_at DESC, s.id DESC
                LIMIT {$limit} OFFSET {$offset}
            ";
            $stmtMy = $pdo->prepare($sql);
            $stmtMy->execute($tableParams);
            $mySessions = $stmtMy->fetchAll(PDO::FETCH_ASSOC);

            // Batch fetch lots, defects, and substitution logs for the displayed sessions
            $sessionIds = array_column($mySessions, 'id');
            if (!empty($sessionIds)) {
                $inSessionIds = implode(',', array_map('intval', $sessionIds));

                // Lots
                $lotSql = "
                    SELECT id, inspection_session_id, lot_number, ref_number, qty, sample_size, lot_result, lot_status, remarks, scanned_qr_raw, action_noted_at, created_at, closed_at
                    FROM inspection_session_lots
                    WHERE inspection_session_id IN ($inSessionIds)
                    ORDER BY id ASC
                ";
                $stmtLots = $pdo->query($lotSql);
                while ($lRow = $stmtLots->fetch(PDO::FETCH_ASSOC)) {
                    $sid = (int)$lRow['inspection_session_id'];
                    $lotsBySession[$sid][] = $lRow;
                }

                // Substitution & sort logs
                $subSql = "
                    SELECT lsl.*, u.name as actioned_by_name
                    FROM lot_substitution_log lsl
                    LEFT JOIN users u ON u.id = lsl.actioned_by
                    WHERE lsl.original_session_id IN ($inSessionIds) 
                       OR lsl.reinspection_session_id IN ($inSessionIds)
                    ORDER BY lsl.id ASC
                ";
                $stmtSub = $pdo->query($subSql);
                while ($subRow = $stmtSub->fetch(PDO::FETCH_ASSOC)) {
                    $origSid = (int)($subRow['original_session_id'] ?? 0);
                    $reSid = (int)($subRow['reinspection_session_id'] ?? 0);
                    if ($origSid > 0) $substitutionsBySession[$origSid][] = $subRow;
                    if ($reSid > 0 && $reSid !== $origSid) $substitutionsBySession[$reSid][] = $subRow;
                }

                // NG Defect records
                $ngSql = "
                    SELECT n.*, dt.name as defect_name, dt.code as defect_code,
                           COALESCE(n.inspection_session_id, sp.inspection_session_id) as target_session_id
                    FROM inspection_ng_records n
                    LEFT JOIN inspection_samples sp ON sp.id = n.inspection_sample_id
                    JOIN defect_types dt ON dt.id = n.defect_type_id
                    WHERE (n.inspection_session_id IN ($inSessionIds) OR sp.inspection_session_id IN ($inSessionIds))
                      AND (n.is_cancelled IS NULL OR n.is_cancelled = 0)
                    ORDER BY n.id ASC
                ";
                $stmtNg = $pdo->query($ngSql);
                while ($nRow = $stmtNg->fetch(PDO::FETCH_ASSOC)) {
                    $slotId = (int)($nRow['session_lot_id'] ?? 0);
                    if ($slotId > 0) {
                        $ngRecordsByLot[$slotId][] = $nRow;
                    }
                }
            }
        }

    } catch (PDOException $e) {
        $kanbans = [];
        $ssSessions = [];
        $mySessions = [];
    }
}

$breadcrumbCategory = "OPERASIONAL";
if ($activeTab === 'safety_stock') {
    $pageTitle = "Riwayat Inspeksi Safety Stock (Cek Fisik)";
    $pageSubtitle = "Pemeriksaan fisik Safety Stock langsung dari gudang OQC (murni sampling AQL QC, tanpa sisa box/overflow)";
} elseif ($activeTab === 'my_inspections') {
    $pageTitle = "Riwayat Inspeksi Saya";
    $pageSubtitle = "Riwayat seluruh sesi inspeksi oleh " . htmlspecialchars($currentUser['name'] ?? 'Inspektur') . " beserta rincian box / lot dan temuan cacat";
} else {
    $pageTitle = "Jadwal & Riwayat Scan Kanban";
    $pageSubtitle = "Pusat jadwal scan kanban harian dan riwayat pengerjaan per sesi inspeksi OQC";
}

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-5 space-y-3.5 min-w-0 w-full overflow-x-hidden">
        
        <?= render_flash() ?>

        <style>
        /* ── KPI Highlight Cards (Compact & Single Row) ── */
        .kpi-grid-container {
            display: grid !important;
            grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
            gap: 8px !important;
            margin-top: 2px !important;
            margin-bottom: 2px !important;
        }
        .kpi-card {
            border-radius: 8px !important;
            padding: 8px 12px !important;
            display: block !important;
            transition: all 0.2s ease !important;
            box-shadow: 0 1px 2px rgba(0,0,0,0.04) !important;
            text-decoration: none !important;
        }
        .kpi-card:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.06) !important;
        }
        .kpi-card-total {
            background: linear-gradient(135deg, #e0f2fe 0%, #dbeafe 100%) !important;
            border: 1px solid #93c5fd !important;
            border-left: 4.5px solid #2563eb !important;
        }
        .kpi-card-uninspected {
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%) !important;
            border: 1px solid #cbd5e1 !important;
            border-left: 4.5px solid #64748b !important;
        }
        .kpi-card-progress {
            background: linear-gradient(135deg, #fef3c7 0%, #ffedd5 100%) !important;
            border: 1px solid #fcd34d !important;
            border-left: 4.5px solid #f59e0b !important;
        }
        .kpi-card-completed {
            background: linear-gradient(135deg, #dcfce7 0%, #d1fae5 100%) !important;
            border: 1px solid #86efac !important;
            border-left: 4.5px solid #10b981 !important;
        }
        .kpi-card-rejected {
            background: linear-gradient(135deg, #ffe4e6 0%, #fecdd3 100%) !important;
            border: 1px solid #fda4af !important;
            border-left: 4.5px solid #e11d48 !important;
        }
        .kpi-card-ss {
            background: linear-gradient(135deg, #f3e8ff 0%, #ede9fe 100%) !important;
            border: 1px solid #d8b4fe !important;
            border-left: 4.5px solid #9333ea !important;
        }

        /* ── Workboard Table Row Status Colors ── */
        .row-status-overdue > td {
            background-color: #fff1f2 !important;
        }
        .row-status-overdue:hover > td {
            background-color: #ffe4e6 !important;
        }
        .row-status-overdue > td:first-child {
            border-left: 5px solid #e11d48 !important;
        }

        .row-status-active > td {
            background-color: #fffbeb !important;
        }
        .row-status-active:hover > td {
            background-color: #fef3c7 !important;
        }
        .row-status-active > td:first-child {
            border-left: 5px solid #d97706 !important;
        }

        .row-status-partial > td {
            background-color: #fef3c7 !important;
        }
        .row-status-partial:hover > td {
            background-color: #fde68a !important;
        }
        .row-status-partial > td:first-child {
            border-left: 5px solid #f59e0b !important;
        }

        .row-status-completed > td {
            background-color: #dcfce7 !important;
        }
        .row-status-completed:hover > td {
            background-color: #bbf7d0 !important;
        }
        .row-status-completed > td:first-child {
            border-left: 5px solid #10b981 !important;
        }

        .row-status-rejected > td {
            background-color: #fff1f2 !important;
        }
        .row-status-rejected:hover > td {
            background-color: #ffe4e6 !important;
        }
        .row-status-rejected > td:first-child {
            border-left: 5px solid #be123c !important;
        }

        .row-status-uninspected > td {
            background-color: #ffffff !important;
        }
        .row-status-uninspected:hover > td {
            background-color: #f8fafc !important;
        }
        .row-status-uninspected > td:first-child {
            border-left: 5px solid #cbd5e1 !important;
        }

        /* KPI numbers and label spacing */
        .kpi-stat-value {
            font-size: 20px !important;
            font-weight: 900 !important;
            line-height: 1 !important;
            display: inline-block !important;
        }
        .kpi-stat-unit {
            font-size: 11px !important;
            font-weight: 700 !important;
            margin-left: 4px !important;
            display: inline-block !important;
        }
        .kpi-card-my {
            background: linear-gradient(135deg, #e0e7ff 0%, #ede9fe 100%) !important;
            border: 1px solid #c7d2fe !important;
            border-left: 4.5px solid #4f46e5 !important;
        }
        </style>

        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <!-- TAB SWITCHER: KANBAN DELIVERY vs SAFETY STOCK vs RIWAYAT SAYA      -->
        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <div class="flex items-center justify-between gap-3 border-b border-slate-200 pb-2.5">
            <div class="inline-flex p-1 bg-slate-100 rounded-xl border border-slate-200 gap-1 flex-wrap">
                <a href="?tab=kanban&period=<?= urlencode($period) ?>" 
                   class="px-4 py-1.5 rounded-lg text-xs font-black transition-all inline-flex items-center gap-2 <?= ($activeTab === 'kanban') ? 'bg-white text-blue-700 shadow-xs border border-slate-200/80' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-200/60' ?>">
                    <svg class="w-4 h-4 <?= ($activeTab === 'kanban') ? 'text-blue-600' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path>
                    </svg>
                    <span>Kanban Delivery</span>
                    <span class="px-1.5 py-0.5 rounded-full text-[10px] font-extrabold <?= ($activeTab === 'kanban') ? 'bg-blue-100 text-blue-800' : 'bg-slate-200 text-slate-600' ?>">
                        <?= number_format($kanbanTabCount) ?>
                    </span>
                </a>
                <a href="?tab=safety_stock&period=<?= urlencode($period) ?>" 
                   class="px-4 py-1.5 rounded-lg text-xs font-black transition-all inline-flex items-center gap-2 <?= ($activeTab === 'safety_stock') ? 'bg-white text-purple-700 shadow-xs border border-slate-200/80' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-200/60' ?>">
                    <svg class="w-4 h-4 <?= ($activeTab === 'safety_stock') ? 'text-purple-600' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                    </svg>
                    <span>Safety Stock (Cek Fisik)</span>
                    <span class="px-1.5 py-0.5 rounded-full text-[10px] font-extrabold <?= ($activeTab === 'safety_stock') ? 'bg-purple-100 text-purple-800' : 'bg-slate-200 text-slate-600' ?>">
                        <?= number_format($safetyStockTabCount) ?>
                    </span>
                </a>
                <a href="?tab=my_inspections&period=<?= urlencode($period) ?>" 
                   class="px-4 py-1.5 rounded-lg text-xs font-black transition-all inline-flex items-center gap-2 <?= ($activeTab === 'my_inspections') ? 'bg-white text-indigo-700 shadow-xs border border-slate-200/80' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-200/60' ?>">
                    <svg class="w-4 h-4 <?= ($activeTab === 'my_inspections') ? 'text-indigo-600' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                    </svg>
                    <span>Riwayat Saya</span>
                    <span class="px-1.5 py-0.5 rounded-full text-[10px] font-extrabold <?= ($activeTab === 'my_inspections') ? 'bg-indigo-100 text-indigo-800' : 'bg-slate-200 text-slate-600' ?>">
                        <?= number_format($myInspectionsTabCount) ?>
                    </span>
                </a>
            </div>

            <div class="hidden sm:flex items-center gap-1.5 text-[11px] text-slate-500 font-medium">
                <?php if ($activeTab === 'safety_stock'): ?>
                    <span class="w-2 h-2 rounded-full bg-purple-500"></span>
                    <span>Hanya pemeriksaan fisik gudang & AQL (tanpa sisa box/overflow)</span>
                <?php elseif ($activeTab === 'my_inspections'): ?>
                    <span class="w-2 h-2 rounded-full bg-indigo-500"></span>
                    <span>Riwayat seluruh sesi inspeksi oleh <?= htmlspecialchars($currentUser['name'] ?? 'Inspektur') ?></span>
                <?php else: ?>
                    <span class="w-2 h-2 rounded-full bg-blue-500"></span>
                    <span>Jadwal pengiriman Kanban & Workboard harian</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <!-- TOOLBAR: PRESET PERIODE + SEARCH + FILTER + TOMBOL MULAI           -->
        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <div class="bg-white border border-slate-200/90 rounded-xl p-3 shadow-xs space-y-2.5">
            <!-- Baris 1: Preset Periode + Tombol Mulai Inspeksi Baru -->
            <div class="flex items-center justify-between gap-2.5 flex-wrap">
                <!-- Period Preset Pills -->
                <div class="inline-flex items-center bg-slate-100 p-1 rounded-lg border border-slate-200 gap-1 flex-shrink-0">
                    <button type="button" onclick="setKanbanPeriod('today')" 
                            class="px-3 py-1 rounded-md font-extrabold text-xs transition-all <?= ($period === 'today') ? ($activeTab === 'safety_stock' ? 'bg-purple-600 text-white shadow-xs' : ($activeTab === 'my_inspections' ? 'bg-indigo-600 text-white shadow-xs' : 'bg-blue-600 text-white shadow-xs')) : 'text-slate-600 hover:text-slate-900 hover:bg-slate-200/60' ?>">
                        Hari Ini
                    </button>
                    <button type="button" onclick="setKanbanPeriod('this_week')" 
                            class="px-3 py-1 rounded-md font-extrabold text-xs transition-all <?= ($period === 'this_week') ? ($activeTab === 'safety_stock' ? 'bg-purple-600 text-white shadow-xs' : ($activeTab === 'my_inspections' ? 'bg-indigo-600 text-white shadow-xs' : 'bg-blue-600 text-white shadow-xs')) : 'text-slate-600 hover:text-slate-900 hover:bg-slate-200/60' ?>">
                        Minggu Ini
                    </button>
                    <button type="button" onclick="setKanbanPeriod('this_month')" 
                            class="px-3 py-1 rounded-md font-extrabold text-xs transition-all <?= ($period === 'this_month') ? ($activeTab === 'safety_stock' ? 'bg-purple-600 text-white shadow-xs' : ($activeTab === 'my_inspections' ? 'bg-indigo-600 text-white shadow-xs' : 'bg-blue-600 text-white shadow-xs')) : 'text-slate-600 hover:text-slate-900 hover:bg-slate-200/60' ?>">
                        Bulan Ini
                    </button>
                    <button type="button" onclick="setKanbanPeriod('all')" 
                            class="px-3 py-1 rounded-md font-extrabold text-xs transition-all <?= ($period === 'all') ? ($activeTab === 'safety_stock' ? 'bg-purple-600 text-white shadow-xs' : ($activeTab === 'my_inspections' ? 'bg-indigo-600 text-white shadow-xs' : 'bg-blue-600 text-white shadow-xs')) : 'text-slate-600 hover:text-slate-900 hover:bg-slate-200/60' ?>">
                        Semua
                    </button>
                </div>

                <div class="flex items-center gap-2 flex-wrap">
                    <?php if ($activeTab === 'kanban'): ?>
                        <!-- Real-Time Live Sync Indicator (Khusus Kanban Workboard) -->
                        <div id="live-sync-indicator" class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-emerald-50 border border-emerald-200 rounded-lg text-emerald-700 text-[11px] font-bold shadow-2xs" title="Sinkronisasi status otomatis antar-laptop setiap 4 detik">
                            <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                            <span id="live-sync-text">Live Sync</span>
                            <span id="live-sync-time" class="text-[9.5px] text-emerald-600 font-semibold"></span>
                        </div>

                        <!-- Tombol Action: Mulai Inspeksi Baru (Kanban) -->
                        <a href="<?= base_url('modules/inspection/session.php?scan_new=1') ?>" 
                           class="px-3.5 py-1.5 bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white font-extrabold text-xs rounded-lg shadow-xs transition-all inline-flex items-center gap-1.5 whitespace-nowrap">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"></path>
                            </svg>
                            <span>Mulai Inspeksi Baru</span>
                        </a>
                    <?php elseif ($activeTab === 'safety_stock'): ?>
                        <!-- Tombol Action: Cek Fisik Safety Stock -->
                        <a href="<?= base_url('modules/inspection/session.php?scan_new=1&type=safety_stock') ?>" 
                           class="px-3.5 py-1.5 bg-purple-600 hover:bg-purple-700 active:bg-purple-800 text-white font-extrabold text-xs rounded-lg shadow-xs transition-all inline-flex items-center gap-1.5 whitespace-nowrap">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"></path>
                            </svg>
                            <span>+ Cek Fisik Safety Stock</span>
                        </a>
                    <?php else: ?>
                        <!-- Tombol Action: Mulai Inspeksi Baru (Riwayat Saya) -->
                        <a href="<?= base_url('modules/inspection/session.php?scan_new=1') ?>" 
                           class="px-3.5 py-1.5 bg-indigo-600 hover:bg-indigo-700 active:bg-indigo-800 text-white font-extrabold text-xs rounded-lg shadow-xs transition-all inline-flex items-center gap-1.5 whitespace-nowrap">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"></path>
                            </svg>
                            <span>Mulai Inspeksi Baru</span>
                        </a>
                    <?php endif; ?>
                </div>

            </div>

            <!-- Baris 2: Search Input, Status Dropdown, Limit, Date Range, Filter & Reset -->
            <form id="kanbanFilterForm" action="" method="GET" class="flex items-center gap-2 text-xs flex-wrap border-t border-slate-100 pt-2.5">
                <input type="hidden" name="tab" id="kanbanTabInput" value="<?= htmlspecialchars($activeTab) ?>">
                <input type="hidden" name="period" id="kanbanPeriodInput" value="<?= htmlspecialchars($period) ?>">

                <!-- Search Field -->
                <div class="relative flex-1 min-w-[260px] sm:min-w-[320px]">
                    <div style="position: absolute; left: 10px; top: 0; bottom: 0; display: flex; align-items: center; pointer-events: none;">
                        <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                    </div>
                    <input type="text" name="search" id="kanbanSearchInput" autofocus autocomplete="off" value="<?= htmlspecialchars($search) ?>" 
                           placeholder="<?= ($activeTab === 'safety_stock') ? 'Cari Sesi, Part, Inspektur...' : (($activeTab === 'my_inspections') ? 'Cari Sesi, Kanban, Part, Lot, Ref...' : 'Cari Kanban, Part, Customer... (Scan Barcode / Ketik)') ?>" 
                           style="padding-left: 32px;" 
                           class="w-full pr-3 py-1.5 bg-slate-50 border border-slate-200 rounded-lg text-xs text-slate-800 focus:bg-white focus:outline-none focus:ring-2 <?= ($activeTab === 'safety_stock') ? 'focus:ring-purple-500' : (($activeTab === 'my_inspections') ? 'focus:ring-indigo-500' : 'focus:ring-blue-500') ?> transition-all font-medium">
                </div>

                <!-- Status Filter -->
                <div class="w-48 flex-shrink-0">
                    <select name="status" onchange="this.form.submit()" class="w-full px-2.5 py-1.5 bg-slate-50 border border-slate-200 rounded-lg text-xs font-bold text-slate-700 focus:bg-white focus:outline-none focus:ring-2 <?= ($activeTab === 'safety_stock') ? 'focus:ring-purple-500' : (($activeTab === 'my_inspections') ? 'focus:ring-indigo-500' : 'focus:ring-blue-500') ?> transition-all cursor-pointer">
                        <?php if ($activeTab === 'safety_stock' || $activeTab === 'my_inspections'): ?>
                            <option value="all" <?= ($statusFilter === 'all') ? 'selected' : '' ?>>Semua Status</option>
                            <option value="in_progress" <?= ($statusFilter === 'in_progress') ? 'selected' : '' ?>>Sedang Dikerjakan</option>
                            <option value="completed" <?= ($statusFilter === 'completed') ? 'selected' : '' ?>>Selesai (Passed)</option>
                            <option value="rejected" <?= ($statusFilter === 'rejected') ? 'selected' : '' ?>>Ada Reject</option>
                        <?php else: ?>
                            <option value="all" <?= ($statusFilter === 'all') ? 'selected' : '' ?>>Semua Status</option>
                            <option value="uninspected" <?= ($statusFilter === 'uninspected') ? 'selected' : '' ?>>Antrean (Belum Mulai)</option>
                            <option value="in_progress" <?= ($statusFilter === 'in_progress') ? 'selected' : '' ?>>Sedang Dikerjakan</option>
                            <option value="partial" <?= ($statusFilter === 'partial') ? 'selected' : '' ?>>Parsial (Belum Penuh)</option>
                            <option value="completed" <?= ($statusFilter === 'completed') ? 'selected' : '' ?>>Selesai (Passed)</option>
                            <option value="rejected" <?= ($statusFilter === 'rejected') ? 'selected' : '' ?>>Ada Reject</option>
                        <?php endif; ?>
                    </select>
                </div>

                <!-- Rows per page (Limit Selector) -->
                <div class="flex items-center gap-1 bg-slate-50 border border-slate-200 rounded-lg px-2 py-1 text-xs text-slate-600 flex-shrink-0">
                    <span class="text-[10px] font-bold text-slate-500">Baris:</span>
                    <select name="limit" onchange="this.form.submit()" class="bg-transparent border-0 p-0 text-xs font-black text-slate-800 focus:outline-none cursor-pointer">
                        <option value="10" <?= ($limit == 10) ? 'selected' : '' ?>>10</option>
                        <option value="25" <?= ($limit == 25) ? 'selected' : '' ?>>25</option>
                        <option value="50" <?= ($limit == 50) ? 'selected' : '' ?>>50</option>
                        <option value="100" <?= ($limit == 100) ? 'selected' : '' ?>>100 (Semua)</option>
                    </select>
                </div>

                <!-- Date Range (Custom) -->
                <div class="flex items-center space-x-1 bg-slate-50 border border-slate-200 rounded-lg px-2 py-0.5 text-xs text-slate-600 flex-shrink-0">
                    <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>" class="bg-transparent border-0 p-0 text-xs text-slate-800 focus:outline-none font-bold">
                    <span class="text-slate-400 font-bold text-[10px]">s/d</span>
                    <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>" class="bg-transparent border-0 p-0 text-xs text-slate-800 focus:outline-none font-bold">
                </div>

                <!-- Filter & Reset Buttons -->
                <button type="submit" class="px-3 py-1.5 bg-slate-800 hover:bg-slate-900 active:bg-black text-white font-bold text-xs rounded-lg shadow-xs transition-all flex items-center gap-1 flex-shrink-0">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"></path>
                    </svg>
                    <span>Filter</span>
                </button>

                <?php if (!empty($search) || $statusFilter !== 'all' || $period !== 'today' || !empty($startDate) || !empty($endDate)): ?>
                    <a href="index.php?tab=<?= $activeTab ?>" class="px-2.5 py-1.5 bg-rose-50 hover:bg-rose-100 text-rose-600 font-bold text-xs rounded-lg border border-rose-200 transition-all flex-shrink-0">
                        Reset
                    </a>
                <?php endif; ?>

            </form>

        </div>

        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <!-- KPI BAR: STATISTIK RINGKAS                                          -->
        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <!-- KPI BAR: STATISTIK RINGKAS                                          -->
        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <?php if ($activeTab === 'safety_stock'): ?>
            <!-- KPI SAFETY STOCK (CEK FISIK) -->
            <div class="kpi-grid-container">
                <!-- Stat 1: Total Sesi Cek Fisik -->
                <a href="?tab=safety_stock&status=all&period=<?= urlencode($period) ?>" 
                   class="kpi-card kpi-card-ss"
                   title="Klik untuk tampilkan semua sesi Safety Stock">
                    <div class="flex items-center justify-between gap-1">
                        <div class="text-[9px] font-extrabold text-purple-800 uppercase tracking-wider">TOTAL CEK FISIK</div>
                        <span class="w-5 h-5 rounded bg-purple-200/80 text-purple-700 flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                        </span>
                    </div>
                    <div class="mt-1 flex items-baseline flex-wrap">
                        <span class="kpi-stat-value text-purple-950"><?= number_format($stats['total_count']) ?></span>
                        <span class="kpi-stat-unit text-purple-700">Sesi</span>
                        <span class="text-[9px] text-purple-800 font-extrabold bg-purple-200/60 px-1 py-0.2 rounded ml-1">(<?= number_format($stats['total_qty_pcs']) ?> pcs)</span>
                    </div>
                </a>

                <!-- Stat 2: Berjalan -->
                <a href="?tab=safety_stock&status=in_progress&period=<?= urlencode($period) ?>" 
                   class="kpi-card kpi-card-progress"
                   title="Klik untuk filter Sedang Berjalan">
                    <div class="flex items-center justify-between gap-1">
                        <div class="text-[9px] font-extrabold text-amber-900 uppercase tracking-wider">BERJALAN</div>
                        <span class="w-5 h-5 rounded bg-amber-200/80 text-amber-800 flex items-center justify-center flex-shrink-0 relative">
                            <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-ping absolute"></span>
                            <span class="w-1.5 h-1.5 rounded-full bg-amber-600"></span>
                        </span>
                    </div>
                    <div class="mt-1 flex items-baseline">
                        <span class="kpi-stat-value text-amber-950"><?= number_format($stats['in_progress_count']) ?></span>
                        <span class="kpi-stat-unit text-amber-700">Sesi</span>
                    </div>
                </a>

                <!-- Stat 3: Passed (Selesai) -->
                <a href="?tab=safety_stock&status=completed&period=<?= urlencode($period) ?>" 
                   class="kpi-card kpi-card-completed"
                   title="Klik untuk filter Passed">
                    <div class="flex items-center justify-between gap-1">
                        <div class="text-[9px] font-extrabold text-emerald-900 uppercase tracking-wider">PASSED (LOLOS)</div>
                        <span class="w-5 h-5 rounded bg-emerald-200/80 text-emerald-800 flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                        </span>
                    </div>
                    <div class="mt-1 flex items-baseline">
                        <span class="kpi-stat-value text-emerald-950"><?= number_format($stats['passed_count']) ?></span>
                        <span class="kpi-stat-unit text-emerald-700">Sesi</span>
                    </div>
                </a>

                <!-- Stat 4: Reject -->
                <a href="?tab=safety_stock&status=rejected&period=<?= urlencode($period) ?>" 
                   class="kpi-card kpi-card-rejected"
                   title="Klik untuk filter Rejected">
                    <div class="flex items-center justify-between gap-1">
                        <div class="text-[9px] font-extrabold text-rose-800 uppercase tracking-wider">REJECTED</div>
                        <span class="w-5 h-5 rounded bg-rose-200/80 text-rose-700 flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </span>
                    </div>
                    <div class="mt-1 flex items-baseline">
                        <span class="kpi-stat-value text-rose-950"><?= number_format($stats['rejected_count']) ?></span>
                        <span class="kpi-stat-unit text-rose-700">Sesi</span>
                    </div>
                </a>
            </div>

        <?php elseif ($activeTab === 'my_inspections'): ?>
            <!-- KPI RIWAYAT SAYA -->
            <div class="kpi-grid-container">
                <!-- Stat 1: Total Sesi Saya -->
                <a href="?tab=my_inspections&status=all&period=<?= urlencode($period) ?>" 
                   class="kpi-card kpi-card-my"
                   title="Klik untuk tampilkan semua sesi inspeksi Anda">
                    <div class="flex items-center justify-between gap-1">
                        <div class="text-[9px] font-extrabold text-indigo-900 uppercase tracking-wider">TOTAL SESI SAYA</div>
                        <span class="w-5 h-5 rounded bg-indigo-200/80 text-indigo-700 flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                        </span>
                    </div>
                    <div class="mt-1 flex items-baseline flex-wrap">
                        <span class="kpi-stat-value text-indigo-950"><?= number_format($stats['total_count']) ?></span>
                        <span class="kpi-stat-unit text-indigo-700">Sesi</span>
                        <span class="text-[9px] text-indigo-800 font-extrabold bg-indigo-200/60 px-1 py-0.2 rounded ml-1">(<?= number_format($stats['total_qty_pcs']) ?> pcs)</span>
                    </div>
                </a>

                <!-- Stat 2: Berjalan -->
                <a href="?tab=my_inspections&status=in_progress&period=<?= urlencode($period) ?>" 
                   class="kpi-card kpi-card-progress"
                   title="Klik untuk filter Sedang Berjalan">
                    <div class="flex items-center justify-between gap-1">
                        <div class="text-[9px] font-extrabold text-amber-900 uppercase tracking-wider">BERJALAN</div>
                        <span class="w-5 h-5 rounded bg-amber-200/80 text-amber-800 flex items-center justify-center flex-shrink-0 relative">
                            <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-ping absolute"></span>
                            <span class="w-1.5 h-1.5 rounded-full bg-amber-600"></span>
                        </span>
                    </div>
                    <div class="mt-1 flex items-baseline">
                        <span class="kpi-stat-value text-amber-950"><?= number_format($stats['in_progress_count']) ?></span>
                        <span class="kpi-stat-unit text-amber-700">Sesi</span>
                    </div>
                </a>

                <!-- Stat 3: Passed (Selesai) -->
                <a href="?tab=my_inspections&status=completed&period=<?= urlencode($period) ?>" 
                   class="kpi-card kpi-card-completed"
                   title="Klik untuk filter Passed">
                    <div class="flex items-center justify-between gap-1">
                        <div class="text-[9px] font-extrabold text-emerald-900 uppercase tracking-wider">PASSED (LOLOS)</div>
                        <span class="w-5 h-5 rounded bg-emerald-200/80 text-emerald-800 flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                        </span>
                    </div>
                    <div class="mt-1 flex items-baseline">
                        <span class="kpi-stat-value text-emerald-950"><?= number_format($stats['passed_count']) ?></span>
                        <span class="kpi-stat-unit text-emerald-700">Sesi</span>
                    </div>
                </a>

                <!-- Stat 4: Reject -->
                <a href="?tab=my_inspections&status=rejected&period=<?= urlencode($period) ?>" 
                   class="kpi-card kpi-card-rejected"
                   title="Klik untuk filter Rejected">
                    <div class="flex items-center justify-between gap-1">
                        <div class="text-[9px] font-extrabold text-rose-800 uppercase tracking-wider">REJECTED</div>
                        <span class="w-5 h-5 rounded bg-rose-200/80 text-rose-700 flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </span>
                    </div>
                    <div class="mt-1 flex items-baseline">
                        <span class="kpi-stat-value text-rose-950"><?= number_format($stats['rejected_count']) ?></span>
                        <span class="kpi-stat-unit text-rose-700">Sesi</span>
                    </div>
                </a>
            </div>

        <?php else: ?>
            <!-- KPI KANBAN DELIVERY (RICH CARDS) -->
            <div class="kpi-grid-container">
                <!-- Stat 1: Total -->
                <a href="?tab=kanban&status=all&period=<?= urlencode($period) ?>" 
                   class="kpi-card kpi-card-total"
                   title="Klik untuk tampilkan semua status">
                    <div class="flex items-center justify-between gap-1">
                        <div class="text-[9px] font-extrabold text-blue-800 uppercase tracking-wider">TOTAL KANBAN</div>
                        <span class="w-5 h-5 rounded bg-blue-200/70 text-blue-700 flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                        </span>
                    </div>
                    <div class="mt-1 flex items-baseline">
                        <span class="kpi-stat-value text-blue-900"><?= number_format($stats['total_kanban']) ?></span>
                        <span class="kpi-stat-unit text-blue-700">Kanban</span>
                    </div>
                </a>

                <!-- Stat 2: Belum Mulai -->
                <a href="?tab=kanban&status=uninspected&period=<?= urlencode($period) ?>" 
                   class="kpi-card kpi-card-uninspected"
                   title="Klik untuk filter Belum Mulai">
                    <div class="flex items-center justify-between gap-1">
                        <div class="text-[9px] font-extrabold text-slate-700 uppercase tracking-wider">BELUM MULAI</div>
                        <span class="w-5 h-5 rounded bg-slate-200 text-slate-700 flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </span>
                    </div>
                    <div class="mt-1 flex items-baseline">
                        <span class="kpi-stat-value text-slate-800"><?= number_format($stats['uninspected_count']) ?></span>
                        <span class="kpi-stat-unit text-slate-600">Kanban</span>
                    </div>
                </a>

                <!-- Stat 3: Berjalan -->
                <a href="?tab=kanban&status=in_progress&period=<?= urlencode($period) ?>" 
                   class="kpi-card kpi-card-progress"
                   title="Klik untuk filter Sedang Berjalan">
                    <div class="flex items-center justify-between gap-1">
                        <div class="text-[9px] font-extrabold text-amber-900 uppercase tracking-wider">BERJALAN</div>
                        <span class="w-5 h-5 rounded bg-amber-200/80 text-amber-800 flex items-center justify-center flex-shrink-0 relative">
                            <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-ping absolute"></span>
                            <span class="w-1.5 h-1.5 rounded-full bg-amber-600"></span>
                        </span>
                    </div>
                    <div class="mt-1 flex items-baseline">
                        <span class="kpi-stat-value text-amber-950"><?= number_format($stats['in_progress_count']) ?></span>
                        <span class="kpi-stat-unit text-amber-700">Kanban</span>
                    </div>
                </a>

                <!-- Stat 4: Selesai -->
                <a href="?tab=kanban&status=completed&period=<?= urlencode($period) ?>" 
                   class="kpi-card kpi-card-completed"
                   title="Klik untuk filter Selesai">
                    <div class="flex items-center justify-between gap-1">
                        <div class="text-[9px] font-extrabold text-emerald-900 uppercase tracking-wider">SELESAI</div>
                        <span class="w-5 h-5 rounded bg-emerald-200/80 text-emerald-800 flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                        </span>
                    </div>
                    <div class="mt-1 flex items-baseline">
                        <span class="kpi-stat-value text-emerald-950"><?= number_format($stats['completed_count']) ?></span>
                        <span class="kpi-stat-unit text-emerald-700">Kanban</span>
                    </div>
                </a>
            </div>
            <?php if ($stats['in_progress_sessions'] > 0): ?>
                <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-2.5 flex items-center justify-between gap-3">
                    <span class="text-xs font-semibold text-amber-800">
                        <?= $stats['in_progress_sessions'] ?> sesi inspeksi belum selesai
                    </span>
                    <?php if ($statusFilter !== 'in_progress'): ?>
                        <a href="?tab=kanban&status=in_progress" style="background-color: #d97706; color: #ffffff;" class="px-2.5 py-1 bg-amber-600 hover:bg-amber-700 text-white text-[11px] font-bold rounded-lg transition-all flex-shrink-0">
                            Lihat Sesi Aktif
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        <!-- TABEL DATA: KANBAN DELIVERY vs SAFETY STOCK vs RIWAYAT SAYA        -->
        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <div class="card p-0 overflow-hidden border border-slate-200/90 rounded-xl shadow-xs">
            
            <?php if ($activeTab === 'my_inspections'): ?>
                <!-- ═══════════════════════════════════════════════════════════════ -->
                <!-- TAB 3: RIWAYAT SAYA (INSPEKSI USER LOGIN & RINCIAN LOT)        -->
                <!-- ═══════════════════════════════════════════════════════════════ -->
                <div class="w-full divide-y divide-slate-200">
                    <?php if (empty($mySessions)): ?>
                        <div class="px-4 py-16 text-center text-slate-400">
                            <svg class="w-12 h-12 text-slate-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                            </svg>
                            <p class="font-bold text-slate-700 text-sm">Tidak ada riwayat inspeksi Anda pada filter ini.</p>
                            <p class="text-xs text-slate-400 mt-1">Ubah filter periode di atas atau klik tombol <b>"Mulai Inspeksi Baru"</b> untuk mulai melakukan inspeksi.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($mySessions as $idx => $s): 
                            $sid = (int)$s['id'];
                            $sLots = $lotsBySession[$sid] ?? [];
                            $totalLabels = count($sLots);

                            $isClosed = !empty($s['closed_at']);
                            $durationText = $isClosed ? (($s['duration_minutes'] > 0) ? ($s['duration_minutes'] . ' mnt') : '< 1 mnt') : 'Berjalan';
                            $shiftName = getShiftName($s['started_at']);

                            $sSubs = $substitutionsBySession[$sid] ?? [];
                            $subsByLotId = [];
                            foreach ($sSubs as $sub) {
                                if (!empty($sub['ng_session_lot_id'])) {
                                    $subsByLotId[(int)$sub['ng_session_lot_id']] = $sub;
                                }
                            }
                            $hasReinspection = false;
                            $isPendingReinspection = false;
                            foreach ($sLots as $lItem) {
                                $lStat = strtolower($lItem['lot_status'] ?? '');
                                if (in_array($lStat, ['replaced', 'sorted', 'reinspection'])) {
                                    $hasReinspection = true;
                                }
                                if ($lStat === 'pending_reinspection') {
                                    $isPendingReinspection = true;
                                    $hasReinspection = true;
                                }
                            }
                            if (!empty($sSubs)) {
                                $hasReinspection = true;
                            }
                        ?>
                            <?php
                                if ($s['status'] === 'passed') {
                                    $sessionCardStyle   = 'border: 1px solid #bbf7d0; border-left: 5px solid #16a34a;';
                                    $sessionHeaderStyle = 'background-color: #f0fdf4; border-bottom: 1px solid #bbf7d0;';
                                    $statusBadgeStyle   = 'background-color: #dcfce7; color: #15803d; border: 1px solid #86efac;';
                                } elseif ($s['status'] === 'rejected') {
                                    $sessionCardStyle   = 'border: 1px solid #fecdd3; border-left: 5px solid #dc2626;';
                                    $sessionHeaderStyle = 'background-color: #fff1f2; border-bottom: 1px solid #fecdd3;';
                                    $statusBadgeStyle   = 'background-color: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5;';
                                } else {
                                    $sessionCardStyle   = 'border: 1px solid #fde68a; border-left: 5px solid #d97706;';
                                    $sessionHeaderStyle = 'background-color: #fffbeb; border-bottom: 1px solid #fde68a;';
                                    $statusBadgeStyle   = 'background-color: #fef3c7; color: #92400e; border: 1px solid #fcd34d;';
                                }
                            ?>
                            <div style="<?= $sessionCardStyle ?>" class="bg-white rounded-xl overflow-hidden shadow-2xs mb-3">
                                <!-- Header Sesi (Sebaris Sesuai Sketsa User) -->
                                <div style="<?= $sessionHeaderStyle ?>" class="px-4 py-2.5">
                                    <div class="flex items-center justify-between gap-3 flex-wrap">

                                        <!-- 1. Identitas Sesi & Part: [Kanban #1] Sesi #3  154176200 · PART NAME -->
                                        <div class="flex items-center gap-2 min-w-0 flex-wrap">
                                            <?php if ($s['inspection_type'] === 'safety_stock'): ?>
                                                <span class="px-2 py-0.5 rounded text-[10px] font-black bg-purple-100 text-purple-700 border border-purple-200 flex-shrink-0">SAFETY STOCK</span>
                                            <?php else: ?>
                                                <span class="px-2 py-0.5 rounded text-[10px] font-black bg-blue-50 text-blue-700 border border-blue-200 flex-shrink-0">Kanban #<?= htmlspecialchars($s['kanban_no'] ?? '-') ?></span>
                                            <?php endif; ?>

                                            <span class="font-mono font-black text-slate-900 text-sm tracking-tight flex-shrink-0">Sesi #<?= $sid ?></span>

                                            <span class="text-slate-300 font-bold">&bull;</span>

                                            <!-- Part Number & Name -->
                                            <span class="font-mono font-bold text-indigo-700 text-xs bg-indigo-50 px-2 py-0.5 rounded border border-indigo-200/80 tracking-tight flex-shrink-0"><?= htmlspecialchars($s['part_number']) ?></span>
                                            <span class="text-slate-300 font-bold">&bull;</span>
                                            <span class="font-bold text-slate-900 text-xs tracking-tight truncate max-w-[260px] lg:max-w-[340px]" title="<?= htmlspecialchars($s['display_part_name']) ?>"><?= htmlspecialchars($s['display_part_name']) ?></span>
                                        </div>

                                        <!-- 2. Kotak Waktu Bertingkat (Tanggal / Jam / Shift) -->
                                        <div class="flex flex-col items-center justify-center text-center bg-white px-2.5 py-1 rounded-lg border border-slate-200/90 shadow-2xs flex-shrink-0" style="min-width: 90px;">
                                            <span class="text-[10px] font-bold text-slate-500 uppercase tracking-tight leading-none"><?= date('d M Y', strtotime($s['started_at'])) ?></span>
                                            <span class="text-[11px] font-mono font-black text-slate-800 leading-tight mt-0.5"><?= date('H:i', strtotime($s['started_at'])) ?> <span class="text-[8.5px] font-semibold text-slate-400">WIB</span></span>
                                            <span class="text-[9px] font-bold text-indigo-700 bg-indigo-50 border border-indigo-100 rounded px-1.5 py-0.2 mt-0.5 leading-tight"><?= htmlspecialchars($shiftName) ?></span>
                                        </div>

                                        <!-- 3. Total Scanned Qty -->
                                        <div class="flex items-center flex-shrink-0">
                                            <span class="text-xs font-mono font-black text-slate-800 bg-white px-2.5 py-1 rounded-md border border-slate-200 shadow-2xs">
                                                <?= number_format($s['total_scanned_qty']) ?> <span class="text-[10px] font-bold text-slate-500">pcs</span>
                                            </span>
                                        </div>

                                        <!-- 4. Status & Status Re-Inspeksi -->
                                        <div class="flex flex-col items-center justify-center gap-0.5 flex-shrink-0">
                                            <?php if ($s['status'] === 'passed'): ?>
                                                <span style="<?= $statusBadgeStyle ?>" class="px-3 py-1 rounded-md text-[11px] font-black inline-flex items-center gap-1.5 shadow-2xs">
                                                    <svg class="w-3.5 h-3.5" style="color: #16a34a;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                                    PASSED
                                                </span>
                                            <?php elseif ($s['status'] === 'rejected'): ?>
                                                <span style="<?= $statusBadgeStyle ?>" class="px-3 py-1 rounded-md text-[11px] font-black inline-flex items-center gap-1.5 shadow-2xs">
                                                    <svg class="w-3.5 h-3.5" style="color: #dc2626;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                                                    REJECTED
                                                </span>
                                            <?php else: ?>
                                                <span style="<?= $statusBadgeStyle ?>" class="px-3 py-1 rounded-md text-[11px] font-black inline-flex items-center gap-1.5 shadow-2xs">
                                                    <span class="w-2 h-2 rounded-full animate-pulse" style="background-color: #d97706;"></span>
                                                    BERJALAN
                                                </span>
                                            <?php endif; ?>

                                            <!-- Indikator Re-inspeksi jika ada -->
                                            <?php if ($isPendingReinspection): ?>
                                                <span class="text-[9px] font-extrabold text-amber-700 bg-amber-50 border border-amber-200 rounded px-1.5 py-0.2 inline-flex items-center gap-1">
                                                    <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                                                    Perlu Re-inspeksi
                                                </span>
                                            <?php elseif ($hasReinspection): ?>
                                                <span class="text-[9px] font-bold text-blue-700 bg-blue-50 border border-blue-200 rounded px-1.5 py-0.2 inline-flex items-center gap-0.5">
                                                    <svg class="w-2.5 h-2.5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                                    Ada Re-inspeksi
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <!-- 5. Tombol Aksi (Buka Sesi + Lot Toggle) -->
                                        <div class="flex items-center gap-2 flex-shrink-0">
                                            <?php if ($s['status'] === 'in_progress'): ?>
                                                <a href="<?= base_url('modules/inspection/session.php?id=' . $sid) ?>"
                                                   style="background-color: #d97706; color: #ffffff; border: 1px solid #b45309; box-shadow: 0 1px 3px rgba(217, 119, 6, 0.3);"
                                                   class="px-3 py-1.5 font-bold text-[11px] rounded-lg inline-flex items-center gap-1.5 hover:opacity-95 hover:scale-[1.02] active:scale-95 transition-all cursor-pointer">
                                                    <svg class="w-3.5 h-3.5 text-amber-200" viewBox="0 0 20 20" fill="currentColor"><path d="M6.3 2.841A1.5 1.5 0 004 4.11V15.89a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z"/></svg>
                                                    <span>Lanjutkan Sesi</span>
                                                </a>
                                            <?php else: ?>
                                                <a href="<?= base_url('modules/inspection/session.php?id=' . $sid) ?>"
                                                   style="background-color: #4f46e5; color: #ffffff; border: 1px solid #4338ca; box-shadow: 0 1px 3px rgba(79, 70, 229, 0.25);"
                                                   class="px-3 py-1.5 font-bold text-[11px] rounded-lg inline-flex items-center gap-1.5 hover:opacity-95 hover:scale-[1.02] active:scale-95 transition-all cursor-pointer">
                                                    <svg class="w-3.5 h-3.5 text-indigo-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                                    <span>Buka Sesi</span>
                                                    <span class="text-indigo-200 font-normal">↗</span>
                                                </a>
                                                <?php if ($s['status'] === 'rejected'): ?>
                                                    <a href="<?= base_url('modules/inspection/print_rejection.php?session_id=' . $sid) ?>" target="_blank" rel="noopener"
                                                       style="background-color: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5;"
                                                       class="px-2.5 py-1.5 font-bold text-[11px] rounded-lg inline-flex items-center gap-1 hover:bg-rose-200 transition-colors shadow-2xs">
                                                        <svg class="w-3.5 h-3.5 text-rose-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                                                        Cetak Reject
                                                    </a>
                                                <?php endif; ?>
                                            <?php endif; ?>

                                            <!-- Toggle Lot Button -->
                                            <button type="button" onclick="toggleMyDetail(<?= $sid ?>)"
                                                    style="background-color: #ffffff; color: #334155; border: 1px solid #cbd5e1; box-shadow: 0 1px 2px rgba(0,0,0,0.04);"
                                                    class="px-2.5 py-1.5 font-bold text-[11px] rounded-lg inline-flex items-center gap-1.5 hover:bg-slate-50 hover:border-slate-400 active:scale-95 transition-all cursor-pointer">
                                                <span>Lot</span>
                                                <span style="color: #64748b; font-weight: 600;">(<?= $totalLabels ?>)</span>
                                                <svg id="my-chevron-<?= $sid ?>" class="w-3.5 h-3.5 transition-transform duration-200 text-slate-400 transform rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <!-- Sub-Tabel Rincian Box / Lot (Persis Desain Daily Report Detail) -->
                                <div id="my-detail-<?= $sid ?>" class="px-3.5 py-3 bg-slate-50/40 border-t border-slate-100">
                                    <?php
                                        $totalDisplayAttempts = 0;
                                        foreach ($sLots as $lTmp) {
                                            $lIdTmp = (int)$lTmp['id'];
                                            $subLogTmp = $subsByLotId[$lIdTmp] ?? null;
                                            $lotDefectsTmp = $ngRecordsByLot[$lIdTmp] ?? [];
                                            $isSortTmp = ($subLogTmp && $subLogTmp['action_type'] === 'sort_reinspect');
                                            if (!$isSortTmp) {
                                                foreach ($lotDefectsTmp as $dfTmp) {
                                                    if (!empty($dfTmp['is_sorted'])) { $isSortTmp = true; break; }
                                                }
                                            }
                                            $totalDisplayAttempts += $isSortTmp ? 2 : 1;
                                        }
                                    ?>
                                    <div style="font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; margin-bottom: 6px; letter-spacing: 0.5px;">
                                        RINCIAN BOX / LOT YANG DIPERIKSA (<?= $totalDisplayAttempts ?> <?= $totalDisplayAttempts > 1 ? 'PEMERIKSAAN' : 'BOX' ?> · <?= number_format($s['total_scanned_qty']) ?> PCS):
                                    </div>

                                    <?php if (empty($sLots)): ?>
                                        <div class="py-5 text-center text-slate-400 text-xs italic bg-white rounded-lg border border-slate-200">
                                            Belum ada label/lot yang discan pada sesi ini.
                                        </div>
                                    <?php else: ?>
                                        <div style="overflow-x: auto; border: 1px solid #f1f5f9; border-radius: 8px; background: #ffffff;">
                                            <table style="width: 100%; border-collapse: collapse; font-size: 11px;">
                                                <thead style="background: #f8fafc; color: #64748b; font-weight: 700; border-bottom: 1px solid #e2e8f0;">
                                                    <tr>
                                                        <th style="padding: 6px 10px; width: 30px; text-align: center;">#</th>
                                                        <th style="padding: 6px 10px; min-width: 130px; text-align: left;">Ref Number</th>
                                                        <th style="padding: 6px 10px; min-width: 120px; text-align: left;">Lot Number</th>
                                                        <th style="padding: 6px 10px; text-align: right; width: 85px;">Qty Box</th>
                                                        <th style="padding: 6px 10px; text-align: center; width: 95px;">Sampel (pcs)</th>
                                                        <th style="padding: 6px 10px; text-align: center; width: 75px;">NG</th>
                                                        <th style="padding: 6px 10px; min-width: 100px; text-align: left;">Waktu Scan</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php 
                                                    $lotRowNo = 1;
                                                    foreach ($sLots as $lItem): 
                                                        $lId = (int)$lItem['id'];
                                                        $subLog = $subsByLotId[$lId] ?? null;
                                                        $lotDefects = $ngRecordsByLot[$lId] ?? [];
                                                        $isSortReinspect = ($subLog && $subLog['action_type'] === 'sort_reinspect');
                                                        if (!$isSortReinspect) {
                                                            foreach ($lotDefects as $df) {
                                                                if (!empty($df['is_sorted'])) {
                                                                    $isSortReinspect = true;
                                                                    break;
                                                                }
                                                            }
                                                        }

                                                        $lotAql = getLotAqlFallback($lItem['qty'], $pdo);
                                                        $sampleSize = ($lItem['sample_size'] > 0) ? (int)$lItem['sample_size'] : (int)$lotAql['sample_size'];
                                                        $rejectNumber = (int)$lotAql['reject_number'];

                                                        if ($isSortReinspect):
                                                            $attempt1Defects = [];
                                                            $attempt2Defects = [];
                                                            foreach ($lotDefects as $df) {
                                                                if (!empty($df['is_sorted'])) {
                                                                    $attempt1Defects[] = $df;
                                                                } else {
                                                                    $attempt2Defects[] = $df;
                                                                }
                                                            }
                                                            if (empty($attempt1Defects) && !empty($lotDefects)) {
                                                                if ($lItem['lot_result'] === 'passed') {
                                                                    $attempt1Defects = $lotDefects;
                                                                    $attempt2Defects = [];
                                                                } else {
                                                                    $attempt1Defects = $lotDefects;
                                                                    $attempt2Defects = $lotDefects;
                                                                }
                                                            }

                                                            $att1NgCount = 0;
                                                            foreach ($attempt1Defects as $d) { $att1NgCount += (int)($d['qty_ng'] ?? 1); }
                                                            if ($att1NgCount === 0) $att1NgCount = 1;

                                                            $att2NgCount = 0;
                                                            foreach ($attempt2Defects as $d) { $att2NgCount += (int)($d['qty_ng'] ?? 1); }

                                                            $att1Time = !empty($attempt1Defects[0]['created_at']) ? $attempt1Defects[0]['created_at'] : $lItem['created_at'];
                                                            $att2Time = !empty($subLog['created_at']) ? $subLog['created_at'] : ($lItem['action_noted_at'] ?: $lItem['closed_at'] ?: $lItem['created_at']);
                                                            $isAtt2Passed = ($lItem['lot_result'] === 'passed');
                                                    ?>
                                                            <!-- Baris 1: Uji Awal (NG) -->
                                                            <tr style="border-bottom: 1px dashed #fca5a5; background: #fffbfb;">
                                                                <td style="padding: 6px 10px; text-align: center; color: #94a3b8; font-weight: 700;"><?= $lotRowNo++ ?></td>
                                                                <td style="padding: 6px 10px; font-family: monospace; font-weight: 800; color: #7c3aed;">
                                                                    <a href="<?= base_url('modules/inspection/session.php?id=' . $sid) ?>" class="hover:underline" title="Buka Sesi Inspeksi">
                                                                        <?= htmlspecialchars($lItem['ref_number'] ?: '-') ?>
                                                                    </a>
                                                                    <span style="display: inline-block; margin-left: 5px; font-family: sans-serif; font-size: 9px; font-weight: 800; background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; padding: 1px 5px; border-radius: 4px; vertical-align: middle;">
                                                                        UJI AWAL (NG)
                                                                    </span>
                                                                </td>
                                                                <td style="padding: 6px 10px; font-family: monospace; color: #334155;">
                                                                    <?= htmlspecialchars($lItem['lot_number'] ?: '-') ?>
                                                                </td>
                                                                <td style="padding: 6px 10px; text-align: right; font-family: monospace; font-weight: 700; color: #0f172a;">
                                                                    <?= number_format($lItem['qty']) ?> pcs
                                                                </td>
                                                                <td style="padding: 6px 10px; text-align: center; font-family: monospace; font-weight: 700; color: #2563eb;">
                                                                    <?= $sampleSize ?> pcs
                                                                </td>
                                                                <td style="padding: 6px 10px; text-align: center;">
                                                                    <span style="font-family: monospace; font-size: 11px; font-weight: 800; padding: 2px 7px; border-radius: 4px; background: #fef2f2; color: #dc2626; border: 1px solid #fecaca;">
                                                                        <?= $att1NgCount ?> / <?= $rejectNumber ?>
                                                                    </span>
                                                                </td>
                                                                <td style="padding: 6px 10px; color: #64748b; font-family: monospace;">
                                                                    <?= date('H:i \W\I\B', strtotime($att1Time)) ?>
                                                                </td>
                                                            </tr>

                                                            <!-- Baris Temuan Cacat pada Pemeriksaan Awal -->
                                                            <tr style="background: #fff5f5; border-bottom: 2px solid #fecaca;">
                                                                <td colspan="7" style="padding: 6px 10px 8px 12px;">
                                                                    <div style="display: flex; flex-direction: column; gap: 4px;">
                                                                        <div style="font-size: 10px; font-weight: 800; color: #991b1b; text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 4px;">
                                                                            <svg style="width: 12px; height: 12px; color: #dc2626;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                                                            <span>TEMUAN CACAT PADA PEMERIKSAAN AWAL (<?= count($attempt1Defects) ?> DEFECT):</span>
                                                                        </div>
                                                                        <?php foreach ($attempt1Defects as $df): ?>
                                                                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 11px; background: #ffffff; padding: 4px 8px; border-radius: 5px; border: 1px solid #fee2e2; flex-wrap: wrap;">
                                                                                <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                                                                    <span style="font-weight: 800; color: #dc2626;">• <?= htmlspecialchars($df['defect_name'] ?? 'Defect') ?>:</span>
                                                                                    <span style="font-family: monospace; font-weight: 800; color: #b91c1c; background: #fee2e2; padding: 1px 6px; border-radius: 3px;">
                                                                                        <?= (int)($df['qty_ng'] ?? 1) ?> pcs
                                                                                    </span>
                                                                                    <?php if (!empty($df['remark'])): ?>
                                                                                        <span style="color: #64748b; font-style: italic; font-size: 10px;">(<?= htmlspecialchars($df['remark']) ?>)</span>
                                                                                    <?php endif; ?>
                                                                                </div>
                                                                                <div style="display: flex; align-items: center; gap: 10px; font-size: 10px; color: #475569;">
                                                                                    <span>
                                                                                        <span style="color: #64748b;">Inspector:</span>
                                                                                        <strong style="color: #0f172a;"><?= htmlspecialchars($s['inspector_name'] ?? 'Inspector') ?></strong>
                                                                                    </span>
                                                                                    <span style="background: #f1f5f9; color: #334155; padding: 1px 6px; border-radius: 4px; font-family: monospace; font-weight: 700; border: 1px solid #e2e8f0;">
                                                                                        <?= !empty($df['created_at']) ? date('H:i \W\I\B', strtotime($df['created_at'])) : date('H:i \W\I\B', strtotime($att1Time)) ?>
                                                                                    </span>
                                                                                </div>
                                                                            </div>
                                                                        <?php endforeach; ?>
                                                                    </div>
                                                                </td>
                                                            </tr>

                                                            <!-- Baris 2: Re-inspeksi -->
                                                            <tr style="border-bottom: <?= $isAtt2Passed ? '1px dashed #86efac' : '1px dashed #fca5a5' ?>; background: <?= $isAtt2Passed ? '#f0fdf4' : '#fffbfb' ?>;">
                                                                <td style="padding: 6px 10px; text-align: center; color: #94a3b8; font-weight: 700;"><?= $lotRowNo++ ?></td>
                                                                <td style="padding: 6px 10px; font-family: monospace; font-weight: 800; color: #7c3aed;">
                                                                    <a href="<?= base_url('modules/inspection/session.php?id=' . $sid) ?>" class="hover:underline" title="Buka Sesi Inspeksi">
                                                                        <?= htmlspecialchars($lItem['ref_number'] ?: '-') ?>
                                                                    </a>
                                                                    <span style="display: inline-block; margin-left: 5px; font-family: sans-serif; font-size: 9px; font-weight: 800; <?= $isAtt2Passed ? 'background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0;' : 'background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca;' ?> padding: 1px 5px; border-radius: 4px; vertical-align: middle;">
                                                                        <?= $isAtt2Passed ? 'RE-INSPEKSI' : 'RE-INSPEKSI (NG)' ?>
                                                                    </span>
                                                                </td>
                                                                <td style="padding: 6px 10px; font-family: monospace; color: #334155;">
                                                                    <?= htmlspecialchars($lItem['lot_number'] ?: '-') ?>
                                                                </td>
                                                                <td style="padding: 6px 10px; text-align: right; font-family: monospace; font-weight: 700; color: #0f172a;">
                                                                    <?= number_format($lItem['qty']) ?> pcs
                                                                </td>
                                                                <td style="padding: 6px 10px; text-align: center; font-family: monospace; font-weight: 700; color: #2563eb;">
                                                                    <?= $sampleSize ?> pcs
                                                                </td>
                                                                <td style="padding: 6px 10px; text-align: center;">
                                                                    <span style="font-family: monospace; font-size: 11px; font-weight: 800; padding: 2px 7px; border-radius: 4px; <?= ($att2NgCount > 0) ? 'background: #fef2f2; color: #dc2626; border: 1px solid #fecaca;' : 'background: #f0fdf4; color: #16a34a; border: 1px solid #dcfce7;' ?>">
                                                                        <?= $att2NgCount ?> / <?= $rejectNumber ?>
                                                                    </span>
                                                                </td>
                                                                <td style="padding: 6px 10px; color: #64748b; font-family: monospace;">
                                                                    <?= date('H:i \W\I\B', strtotime($att2Time)) ?>
                                                                </td>
                                                            </tr>

                                                            <!-- Baris Hasil Verifikasi & Re-inspeksi -->
                                                            <tr style="background: <?= $isAtt2Passed ? '#f0fdf4' : '#fff1f2' ?>; border-bottom: 2px solid <?= $isAtt2Passed ? '#bbf7d0' : '#fecdd3' ?>;">
                                                                <td colspan="7" style="padding: 6px 10px 8px 12px;">
                                                                    <div style="display: flex; flex-direction: column; gap: 4px;">
                                                                        <div style="font-size: 10px; font-weight: 800; color: <?= $isAtt2Passed ? '#166534' : '#be123c' ?>; text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 4px;">
                                                                            <?php if ($isAtt2Passed): ?>
                                                                                <svg style="width: 13px; height: 13px; color: #16a34a;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                                                <span>HASIL VERIFIKASI & RE-INSPEKSI: NOL CACAT / DEFECT (PASSED)</span>
                                                                            <?php else: ?>
                                                                                <svg style="width: 13px; height: 13px; color: #e11d48;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                                                                <span>HASIL VERIFIKASI & RE-INSPEKSI: TETAP DITEMUKAN CACAT / NG (REJECTED)</span>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 11px; background: #ffffff; padding: 5px 10px; border-radius: 5px; border: 1px solid <?= $isAtt2Passed ? '#dcfce7' : '#fecdd3' ?>; flex-wrap: wrap;">
                                                                            <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                                                                <span style="font-weight: 800; color: <?= $isAtt2Passed ? '#15803d' : '#be123c' ?>;">Tindakan:</span>
                                                                                <span style="color: #1e293b;">
                                                                                    <?php 
                                                                                        $actNotes = $subLog['notes'] ?? ($attempt1Defects[0]['sort_notes'] ?? '');
                                                                                        if (empty($actNotes)) {
                                                                                            $actNotes = $isAtt2Passed 
                                                                                                ? "Lot #{$lItem['lot_number']} (Ref: {$lItem['ref_number']}) disortir part NG-nya dan diuji ulang sampel fisik."
                                                                                                : "Lot #{$lItem['lot_number']} (Ref: {$lItem['ref_number']}) disortir part NG-nya dan diuji ulang fisik, namun tetap ditemukan cacat.";
                                                                                        }
                                                                                        echo htmlspecialchars($actNotes);
                                                                                    ?>
                                                                                </span>
                                                                            </div>
                                                                            <div style="display: flex; align-items: center; gap: 10px; font-size: 10px; color: #475569;">
                                                                                <span>
                                                                                    <span style="color: #64748b;">Inspector:</span>
                                                                                    <strong style="color: #0f172a;"><?= htmlspecialchars($subLog['actioned_by_name'] ?? ($s['inspector_name'] ?? 'Admin')) ?></strong>
                                                                                </span>
                                                                                <span style="background: #f1f5f9; color: #334155; padding: 1px 6px; border-radius: 4px; font-family: monospace; font-weight: 700; border: 1px solid #e2e8f0;">
                                                                                    <?= date('H:i \W\I\B', strtotime($att2Time)) ?>
                                                                                </span>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                </td>
                                                            </tr>

                                                    <?php else: 
                                                            $hasDefects = !empty($lotDefects);
                                                            $ngCount = (int)($lItem['ng_count'] ?? count($lotDefects));
                                                            $isReplaced = ($lItem['lot_status'] === 'replaced' || !empty($lItem['replaced_by_lot_id']));
                                                            $isReplacementBox = (isset($lItem['remarks']) && strpos($lItem['remarks'], 'Box Pengganti') !== false);
                                                    ?>
                                                            <tr style="border-bottom: <?= $hasDefects ? '1px dashed #fca5a5' : '1px solid #f8fafc' ?>; background: <?= $hasDefects ? '#fffbfb' : 'transparent' ?>;">
                                                                <td style="padding: 6px 10px; text-align: center; color: #94a3b8; font-weight: 700;"><?= $lotRowNo++ ?></td>
                                                                <td style="padding: 6px 10px; font-family: monospace; font-weight: 800; color: #7c3aed;">
                                                                    <a href="<?= base_url('modules/inspection/session.php?id=' . $sid) ?>" class="hover:underline" title="Buka Sesi Inspeksi">
                                                                        <?= htmlspecialchars($lItem['ref_number'] ?: '-') ?>
                                                                    </a>
                                                                    <?php if ($isReplaced): ?>
                                                                        <span style="display: inline-block; margin-left: 5px; font-family: sans-serif; font-size: 9px; font-weight: 800; background: #fffbeb; color: #b45309; border: 1px solid #fde68a; padding: 1px 5px; border-radius: 4px; vertical-align: middle;">
                                                                            DIGANTIKAN
                                                                        </span>
                                                                    <?php elseif ($isReplacementBox): ?>
                                                                        <span style="display: inline-block; margin-left: 5px; font-family: sans-serif; font-size: 9px; font-weight: 800; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; padding: 1px 5px; border-radius: 4px; vertical-align: middle;">
                                                                            BOX PENGGANTI
                                                                        </span>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td style="padding: 6px 10px; font-family: monospace; color: #334155;">
                                                                    <?= htmlspecialchars($lItem['lot_number'] ?: '-') ?>
                                                                </td>
                                                                <td style="padding: 6px 10px; text-align: right; font-family: monospace; font-weight: 700; color: #0f172a;">
                                                                    <?= number_format($lItem['qty']) ?> pcs
                                                                </td>
                                                                <td style="padding: 6px 10px; text-align: center; font-family: monospace; font-weight: 700; color: #2563eb;">
                                                                    <?= $sampleSize ?> pcs
                                                                </td>
                                                                <td style="padding: 6px 10px; text-align: center;">
                                                                    <span style="font-family: monospace; font-size: 11px; font-weight: 800; padding: 2px 7px; border-radius: 4px; <?= $ngCount > 0 ? 'background: #fef2f2; color: #dc2626; border: 1px solid #fecaca;' : 'background: #f0fdf4; color: #16a34a; border: 1px solid #dcfce7;' ?>">
                                                                        <?= $ngCount ?> / <?= $rejectNumber ?>
                                                                    </span>
                                                                </td>
                                                                <td style="padding: 6px 10px; color: #64748b; font-family: monospace;">
                                                                    <?= date('H:i \W\I\B', strtotime($lItem['created_at'])) ?>
                                                                </td>
                                                            </tr>
                                                            <?php if ($hasDefects): ?>
                                                                <tr style="background: #fff5f5; border-bottom: 2px solid #fecaca;">
                                                                    <td colspan="7" style="padding: 6px 10px 8px 12px;">
                                                                        <div style="display: flex; flex-direction: column; gap: 4px;">
                                                                            <div style="font-size: 10px; font-weight: 800; color: #991b1b; text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 4px;">
                                                                                <svg style="width: 12px; height: 12px; color: #dc2626;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                                                                <span>TEMUAN CACAT PADA PEMERIKSAAN AWAL (<?= count($lotDefects) ?> DEFECT):</span>
                                                                            </div>
                                                                            <?php foreach ($lotDefects as $df): ?>
                                                                                <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 11px; background: #ffffff; padding: 4px 8px; border-radius: 5px; border: 1px solid #fee2e2; flex-wrap: wrap;">
                                                                                    <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                                                                        <span style="font-weight: 800; color: #dc2626;">• <?= htmlspecialchars($df['defect_name'] ?? 'Defect') ?>:</span>
                                                                                        <span style="font-family: monospace; font-weight: 800; color: #b91c1c; background: #fee2e2; padding: 1px 6px; border-radius: 3px;">
                                                                                            <?= (int)($df['qty_ng'] ?? 1) ?> pcs
                                                                                        </span>
                                                                                        <?php if (!empty($df['remark'])): ?>
                                                                                            <span style="color: #64748b; font-style: italic; font-size: 10px;">(<?= htmlspecialchars($df['remark']) ?>)</span>
                                                                                        <?php endif; ?>
                                                                                    </div>
                                                                                    <div style="display: flex; align-items: center; gap: 10px; font-size: 10px; color: #475569;">
                                                                                        <span>
                                                                                            <span style="color: #64748b;">Inspector:</span>
                                                                                            <strong style="color: #0f172a;"><?= htmlspecialchars($s['inspector_name'] ?? 'Inspector') ?></strong>
                                                                                        </span>
                                                                                        <span style="background: #f1f5f9; color: #334155; padding: 1px 6px; border-radius: 4px; font-family: monospace; font-weight: 700; border: 1px solid #e2e8f0;">
                                                                                            <?= !empty($df['created_at']) ? date('H:i \W\I\B', strtotime($df['created_at'])) : date('H:i \W\I\B', strtotime($lItem['created_at'])) ?>
                                                                                        </span>
                                                                                    </div>
                                                                                </div>
                                                                            <?php endforeach; ?>
                                                                        </div>
                                                                    </td>
                                                                </tr>
                                                            <?php endif; ?>
                                                    <?php endif; ?>
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

            <?php elseif ($activeTab === 'safety_stock'): ?>
                <!-- ═══════════════════════════════════════════════════════════════ -->
                <!-- TABEL SAFETY STOCK (CEK FISIK MURNI DARI GUDANG)                -->
                <!-- ═══════════════════════════════════════════════════════════════ -->
                <div class="w-full">
                    <table class="w-full text-left text-xs text-slate-700 table-fixed" style="border-collapse: collapse;">
                        <colgroup>
                            <col style="width: 38px">
                            <col style="width: 18%">
                            <col style="width: 25%">
                            <col style="width: 16%">
                            <col style="width: 16%">
                            <col style="width: 11%">
                            <col>
                        </colgroup>
                        <thead class="bg-purple-50/60 text-purple-900 font-bold border-b border-purple-200 uppercase tracking-wider text-[10px]">
                            <tr>
                                <th class="text-center px-2 py-2.5">No</th>
                                <th class="px-3 py-2.5">Sesi & Waktu</th>
                                <th class="px-3 py-2.5">Part & Lokasi</th>
                                <th class="px-2.5 py-2.5">Inspektur & Line</th>
                                <th class="px-2 py-2.5 text-center whitespace-nowrap">Total Qty & AQL</th>
                                <th class="px-2.5 py-2.5 text-center whitespace-nowrap">Status</th>
                                <th class="px-3 py-2.5 text-right whitespace-nowrap">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if (empty($ssSessions)): ?>
                                <tr>
                                    <td colspan="7" class="px-4 py-12 text-center text-slate-400">
                                        <svg class="w-10 h-10 text-slate-300 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                        </svg>
                                        <p class="font-bold text-slate-700 text-sm">Tidak ada riwayat inspeksi fisik Safety Stock pada filter ini.</p>
                                        <p class="text-xs text-slate-400 mt-1">Ubah filter tanggal di atas atau klik tombol <b>"+ Cek Fisik Safety Stock"</b> untuk memulai pemeriksaan gudang.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($ssSessions as $idx => $s): 
                                    $sid = (int)$s['id'];
                                    $sLots = $lotsBySession[$sid] ?? [];
                                    $totalLabels = count($sLots);
                                    $lotNumbers = array_filter(array_unique(array_column($sLots, 'lot_number')));
                                    $distinctLots = count($lotNumbers);
                                    
                                    if ($distinctLots === $totalLabels) {
                                        $lotBadgeText = $totalLabels . ' Lot / Label';
                                    } else {
                                        $lotBadgeText = $distinctLots . ' Lot (' . $totalLabels . ' Label)';
                                    }

                                    $hasRejectedLot = false;
                                    $lotDetails = [];
                                    foreach ($sLots as $lIdx => $lItem) {
                                        $lr = $lItem['lot_result'] ?? '';
                                        $ls = $lItem['lot_status'] ?? 'ok';
                                        if ($lr === 'rejected' || $ls === 'rejected') $hasRejectedLot = true;
                                        $lNo = $lItem['lot_number'] ?? '-';
                                        $lRef = $lItem['ref_number'] ?? ($lIdx + 1);
                                        $lQty = (int)($lItem['qty'] ?? 0);
                                        $lRes = strtoupper($lr ?: $ls);
                                        $lotDetails[] = "Ref #{$lRef}: Lot {$lNo} ({$lQty} pcs) - {$lRes}";
                                    }
                                    $lotTooltip = implode("\n", $lotDetails);

                                    $isClosed = !empty($s['closed_at']);
                                    $durationText = $isClosed ? (($s['duration_minutes'] > 0) ? ($s['duration_minutes'] . ' mnt') : '< 1 mnt') : 'Berjalan';
                                    $shiftName = getShiftName($s['started_at']);
                                ?>
                                    <tr id="ss-row-<?= $sid ?>" class="hover:bg-purple-50/20 transition-colors <?= ($s['status'] === 'in_progress') ? 'bg-amber-50/30' : '' ?>" style="vertical-align: middle;">
                                        <!-- No -->
                                        <td class="w-10 text-center px-2 py-3 font-semibold text-slate-400">
                                            <?= $offset + $idx + 1 ?>
                                        </td>

                                        <!-- Sesi & Waktu -->
                                        <td class="px-3 py-3">
                                            <div class="flex items-center space-x-1.5">
                                                <span class="font-mono font-black text-purple-900 text-xs">
                                                    Sesi #<?= $sid ?>
                                                </span>
                                                <span class="px-1.5 py-0.2 rounded text-[8.5px] font-bold bg-purple-100 text-purple-800 border border-purple-200">
                                                    SS FISIK
                                                </span>
                                            </div>
                                            <div class="text-[11px] text-slate-800 font-bold mt-0.5">
                                                <?= date('d M Y, H:i', strtotime($s['started_at'])) ?>
                                            </div>
                                            <div class="text-[9.5px] text-slate-400">
                                                <?= htmlspecialchars($shiftName) ?> &middot; <?= $durationText ?>
                                            </div>
                                        </td>

                                        <!-- Part & Lokasi -->
                                        <td class="px-3 py-3">
                                            <span class="font-mono font-extrabold text-blue-700 text-xs block">
                                                <?= htmlspecialchars($s['part_number']) ?>
                                            </span>
                                            <span class="text-[11px] text-slate-700 font-medium block truncate max-w-[210px]" title="<?= htmlspecialchars($s['display_part_name']) ?>">
                                                <?= htmlspecialchars($s['display_part_name']) ?>
                                            </span>
                                            <div class="mt-0.5 flex items-center gap-1">
                                                <span class="px-1.5 py-0.2 rounded text-[8.5px] font-semibold bg-slate-100 text-slate-600 border border-slate-200 inline-block leading-tight">
                                                    <?= htmlspecialchars($s['storage_loc'] ?: 'WH-SS') ?>
                                                </span>
                                                <?php if (!empty($s['kanban_no'])): ?>
                                                    <span class="text-[9px] text-slate-400 font-mono">#<?= htmlspecialchars($s['kanban_no']) ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </td>

                                        <!-- Inspektur & Line -->
                                        <td class="px-2.5 py-3">
                                            <div class="font-bold text-slate-800 text-[11px] truncate" title="<?= htmlspecialchars($s['inspector_name']) ?>">
                                                <?= htmlspecialchars($s['inspector_name']) ?>
                                            </div>
                                            <div class="text-[9.5px] text-slate-500 font-medium mt-0.5">
                                                <?= htmlspecialchars($s['line_name'] ?: 'Line OQC') ?>
                                            </div>
                                        </td>

                                        <!-- Total Qty & AQL -->
                                        <td class="px-2 py-3 text-center whitespace-nowrap">
                                            <div class="font-mono font-black text-xs text-slate-900">
                                                <?= number_format($s['total_scanned_qty']) ?> <span class="text-[10px] text-slate-500 font-medium">pcs</span>
                                            </div>
                                            <div class="text-[9.5px] text-slate-500 font-medium">
                                                AQL: <b class="text-purple-800"><?= $s['samples_checked'] ?>/<?= $s['sample_size'] ?></b>
                                            </div>
                                            <?php if ($totalLabels > 0): ?>
                                                <div class="mt-1 flex justify-center">
                                                    <span class="px-2 py-0.5 rounded text-[9.5px] font-bold border shadow-2xs inline-flex items-center gap-1 <?= $hasRejectedLot ? 'bg-rose-50 text-rose-800 border-rose-200' : 'bg-emerald-50 text-emerald-800 border-emerald-200' ?>" title="<?= htmlspecialchars($lotTooltip) ?>">
                                                        <svg class="w-2.5 h-2.5 flex-shrink-0 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                                                        </svg>
                                                        <span><?= $lotBadgeText ?></span>
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Status -->
                                        <td class="px-2.5 py-3 text-center whitespace-nowrap">
                                            <?php if ($s['status'] === 'passed'): ?>
                                                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800 border border-emerald-200 inline-flex items-center">
                                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-600 mr-1"></span>
                                                    Passed
                                                </span>
                                            <?php elseif ($s['status'] === 'rejected'): ?>
                                                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-rose-100 text-rose-800 border border-rose-200 inline-flex items-center">
                                                    <span class="w-1.5 h-1.5 rounded-full bg-rose-600 mr-1"></span>
                                                    Rejected
                                                </span>
                                            <?php else: ?>
                                                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-amber-100 text-amber-900 border border-amber-300 inline-flex items-center gap-1 shadow-2xs">
                                                    <span class="w-1.5 h-1.5 rounded-full bg-amber-600 animate-pulse"></span>
                                                    Berjalan
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Aksi -->
                                        <td class="px-3 py-3 text-right whitespace-nowrap">
                                            <div class="inline-flex items-center space-x-1.5 justify-end">
                                                <?php if ($s['status'] === 'in_progress'): ?>
                                                    <a href="<?= base_url('modules/inspection/session.php?id=' . $sid) ?>" 
                                                       style="background-color: #d97706; color: #ffffff;"
                                                       class="px-3 py-1.5 bg-amber-600 hover:bg-amber-700 active:bg-amber-800 text-white font-bold text-xs rounded-md shadow-xs hover:shadow transition-all inline-flex items-center gap-1.5 whitespace-nowrap">
                                                        <svg class="w-3 h-3 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor" style="fill: #ffffff;">
                                                            <path d="M6.3 2.841A1.5 1.5 0 004 4.11V15.89a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z"/>
                                                        </svg>
                                                        <span>Lanjutkan</span>
                                                    </a>
                                                <?php else: ?>
                                                    <a href="<?= base_url('modules/inspection/session.php?id=' . $sid) ?>" 
                                                       class="px-2.5 py-1.5 bg-slate-100 hover:bg-slate-200 active:bg-slate-300 text-slate-700 font-bold text-xs rounded-md border border-slate-200 transition-all inline-flex items-center gap-1">
                                                        <svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                                        </svg>
                                                        <span>Detail</span>
                                                    </a>
                                                    <?php if ($s['status'] === 'rejected'): ?>
                                                        <a href="<?= base_url('modules/inspection/print_rejection.php?session_id=' . $sid) ?>"
                                                           target="_blank" rel="noopener"
                                                           class="px-2.5 py-1.5 bg-rose-50 hover:bg-rose-100 text-rose-700 font-bold text-xs rounded-md border border-rose-200 transition-all inline-flex items-center gap-1">
                                                            <svg class="w-3.5 h-3.5 text-rose-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
                                                            </svg>
                                                            <span>Cetak</span>
                                                        </a>
                                                    <?php endif; ?>
                                                <?php endif; ?>

                                                <!-- Tombol Accordion Detail Sesi -->
                                                <button type="button" onclick="toggleSsDetail(<?= $sid ?>)" 
                                                        class="px-2 py-1.5 bg-slate-100 hover:bg-slate-200 active:bg-slate-300 text-slate-700 font-bold text-[11px] rounded-lg border border-slate-200 transition-all inline-flex items-center gap-0.5 cursor-pointer"
                                                        title="Buka / Tutup Rincian Box / Lot">
                                                    <span>Lot</span>
                                                    <span class="text-[10px] font-bold text-slate-500">(<?= $totalLabels ?>)</span>
                                                    <svg id="ss-chevron-<?= $sid ?>" class="w-3 h-3 transition-transform duration-200 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                                    </svg>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>

                                    <!-- Accordion Detail Sesi Safety Stock -->
                                    <tr id="ss-detail-<?= $sid ?>" class="hidden bg-slate-50/70 border-b border-slate-200">
                                        <td colspan="7" class="p-2.5 sm:p-3">
                                            <div class="bg-white border border-slate-200 rounded-lg p-3 shadow-2xs space-y-2.5">
                                                <div class="flex items-center justify-between border-b border-slate-100 pb-1.5">
                                                    <div class="flex items-center space-x-1.5">
                                                        <span class="w-2 h-2 rounded-full bg-purple-600"></span>
                                                        <h5 class="font-extrabold text-[11px] text-slate-900 uppercase tracking-wider">
                                                            Rincian Label / Box Fisik &ndash; Sesi #<?= $sid ?> (<?= htmlspecialchars($s['part_number']) ?>)
                                                        </h5>
                                                        <span class="text-[10.5px] text-slate-400 font-medium">
                                                            (<?= $totalLabels ?> Box / Label Diperiksa)
                                                        </span>
                                                    </div>
                                                    <span class="text-[11px] font-semibold text-slate-500">
                                                        Total Qty: <b class="text-slate-800"><?= number_format($s['total_scanned_qty']) ?> pcs</b> &middot; Sampel AQL: <b class="text-purple-800"><?= $s['samples_checked'] ?>/<?= $s['sample_size'] ?></b>
                                                    </span>
                                                </div>

                                                <?php if (empty($sLots)): ?>
                                                    <div class="py-4 text-center text-slate-400 text-xs">
                                                        Belum ada data lot terperinci untuk sesi ini.
                                                    </div>
                                                <?php else: ?>
                                                    <div class="w-full overflow-x-auto">
                                                        <table class="w-full text-left text-xs table-fixed" style="border-collapse: collapse; min-width: 560px;">
                                                            <colgroup>
                                                                <col style="width: 50px">
                                                                <col style="width: 140px">
                                                                <col style="width: 110px">
                                                                <col style="width: 110px">
                                                                <col style="width: 90px">
                                                                <col>
                                                            </colgroup>
                                                            <thead class="bg-slate-50 text-slate-500 font-bold border-b border-slate-200 uppercase text-[9px]">
                                                                <tr>
                                                                    <th class="px-2.5 py-1.5 text-center">Ref</th>
                                                                    <th class="px-2.5 py-1.5">No Lot / Label</th>
                                                                    <th class="px-2.5 py-1.5 text-center">Qty (pcs)</th>
                                                                    <th class="px-2.5 py-1.5 text-center">Hasil</th>
                                                                    <th class="px-2.5 py-1.5 text-center">Status</th>
                                                                    <th class="px-2.5 py-1.5">Waktu Scan / Remarks</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody class="divide-y divide-slate-100">
                                                                <?php foreach ($sLots as $lIdx => $lot): 
                                                                    $isLotOk = (($lot['lot_result'] ?? '') === 'passed' || ($lot['lot_status'] ?? '') === 'ok');
                                                                ?>
                                                                    <tr class="hover:bg-slate-50/70 transition-colors">
                                                                        <td class="px-2.5 py-2 text-center font-mono font-bold text-slate-500">
                                                                            #<?= htmlspecialchars($lot['ref_number'] ?: ($lIdx + 1)) ?>
                                                                        </td>
                                                                        <td class="px-2.5 py-2 font-mono font-extrabold text-slate-900">
                                                                            <?= htmlspecialchars($lot['lot_number'] ?: '-') ?>
                                                                        </td>
                                                                        <td class="px-2.5 py-2 text-center font-mono font-black text-slate-800">
                                                                            <?= number_format($lot['qty']) ?>
                                                                        </td>
                                                                        <td class="px-2.5 py-2 text-center">
                                                                            <?php if ($isLotOk): ?>
                                                                                <span class="px-2 py-0.5 rounded text-[9px] font-bold bg-emerald-100 text-emerald-800 border border-emerald-200">OK</span>
                                                                            <?php else: ?>
                                                                                <span class="px-2 py-0.5 rounded text-[9px] font-bold bg-rose-100 text-rose-800 border border-rose-200">NG / Reject</span>
                                                                            <?php endif; ?>
                                                                        </td>
                                                                        <td class="px-2.5 py-2 text-center text-[10px] font-medium text-slate-600">
                                                                            <?= htmlspecialchars(strtoupper($lot['lot_status'] ?: 'OK')) ?>
                                                                        </td>
                                                                        <td class="px-2.5 py-2 text-[10.5px] text-slate-500 truncate" title="<?= htmlspecialchars($lot['remarks'] ?: ($lot['scanned_qr_raw'] ?: '-')) ?>">
                                                                            <?= !empty($lot['created_at']) ? date('d/m/Y H:i', strtotime($lot['created_at'])) : '-' ?>
                                                                            <?php if (!empty($lot['remarks'])): ?>
                                                                                &middot; <span class="italic text-slate-600"><?= htmlspecialchars($lot['remarks']) ?></span>
                                                                            <?php endif; ?>
                                                                        </td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            <?php else: ?>
                <!-- ═══════════════════════════════════════════════════════════════ -->
                <!-- TABEL JADWAL KANBAN DELIVERY                                    -->
                <!-- ═══════════════════════════════════════════════════════════════ -->
                <div class="w-full">
                    <table class="w-full text-left text-xs text-slate-700 table-fixed" style="border-collapse: collapse;">
                        <colgroup>
                            <col style="width: 38px">
                            <col style="width: 19%">
                            <col style="width: 25%">
                            <col style="width: 16%">
                            <col style="width: 15%">
                            <col style="width: 11%">
                            <col>
                        </colgroup>
                        <thead class="bg-slate-50 text-slate-600 font-bold border-b border-slate-200 uppercase tracking-wider text-[10px]">
                            <tr>
                                <th class="text-center px-2 py-2.5">No</th>
                                <th class="px-3 py-2.5">Kanban</th>
                                <th class="px-3 py-2.5">Part</th>
                                <th class="px-2.5 py-2.5 whitespace-nowrap">Jadwal & ETA</th>
                                <th class="px-2.5 py-2.5 text-center whitespace-nowrap">Progres</th>
                                <th class="px-2.5 py-2.5 text-center whitespace-nowrap">Status</th>
                                <th class="px-3 py-2.5 text-right whitespace-nowrap">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if (empty($kanbans)): ?>
                                <tr>
                                    <td colspan="7" class="px-4 py-10 text-center text-slate-400">
                                        <svg class="w-8 h-8 text-slate-300 mx-auto mb-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                                        </svg>
                                        <p class="font-bold text-slate-600 text-xs">Tidak ada data jadwal Kanban pada periode ini.</p>
                                        <p class="text-[11px] text-slate-400 mt-0.5">Ubah filter tanggal atau klik "Mulai Inspeksi Baru".</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($kanbans as $idx => $k): 
                                    $kid = (int)$k['id'];
                                    $kSessions = $sessionsByKanban[$kid] ?? [];
                                    $totalSessions = count($kSessions);
                                    $targetQty = (int)$k['qty'];
                                    $passedQty = (int)$k['total_passed_qty'];
                                    
                                    // Hitung passedQty dan deteksi reject dari data lot riil
                                    $calcPassedQty = 0;
                                    $hasAnyRejLot = false;
                                    foreach ($kSessions as $ks) {
                                        $ksId = (int)$ks['id'];
                                        $sLotsList = $lotsBySession[$ksId] ?? [];
                                        if (!empty($sLotsList)) {
                                            $sessLotPass = 0;
                                            foreach ($sLotsList as $sl) {
                                                $slRes = $sl['lot_result'] ?? '';
                                                $slSt = $sl['lot_status'] ?? '';
                                                if ($slRes === 'passed' && $slSt !== 'replaced') {
                                                    $sessLotPass += (int)($sl['qty'] ?? 0);
                                                }
                                                if ($slRes === 'rejected' || $slSt === 'rejected' || $slSt === 'ng_found') {
                                                    $hasAnyRejLot = true;
                                                }
                                            }
                                            $calcPassedQty += max(0, $sessLotPass - (int)($ks['excess_qty'] ?? 0));
                                        } elseif (($ks['status'] ?? '') === 'passed') {
                                            $calcPassedQty += max(0, (int)$ks['total_scanned_qty'] - (int)($ks['excess_qty'] ?? 0));
                                        }
                                        if (($ks['status'] ?? '') === 'rejected') {
                                            $hasAnyRejLot = true;
                                        }
                                    }
                                    if ($calcPassedQty > 0 || !empty($kSessions)) {
                                        $passedQty = max($passedQty, $calcPassedQty);
                                    }

                                    $progressPct = ($targetQty > 0) ? min(100, round(($passedQty / $targetQty) * 100)) : 0;
                                    $isFullyPassed = ($targetQty > 0 && $passedQty >= $targetQty);
                                    $hasRejectedSession = $hasAnyRejLot;
                                    
                                    $actSess = $activeSessionByKanban[$kid] ?? null;
                                    $hasActiveSession = !empty($actSess);
                                    $isOwnSession = ($hasActiveSession && (int)$actSess['inspector_id'] === $currentUserId);
                                    
                                    $is100Check = (strcasecmp($k['check_type'], '100%') === 0 || strpos($k['check_type'], '100') !== false);
                                    
                                    $schedDate = !empty($k['eta']) ? $k['eta'] : (!empty($k['req_date']) ? $k['req_date'] : $k['created_at']);
                                    $isOverdue = (!$isFullyPassed && $k['status'] !== 'completed' && !empty($schedDate) && date('Y-m-d', strtotime($schedDate)) < date('Y-m-d'));
                                    $isPartial = (!$isFullyPassed && $k['status'] !== 'completed' && ($k['status'] === 'partial' || ($passedQty > 0 && $passedQty < $targetQty)));
                                    $isCompleted = ($isFullyPassed || $k['status'] === 'completed');
                                    $isRejected = ($k['status'] === 'rejected' && $passedQty <= 0);

                                    if ($isOverdue) {
                                        $rowColorClass = 'row-status-overdue';
                                    } elseif ($hasActiveSession) {
                                        $rowColorClass = 'row-status-active';
                                    } elseif ($isPartial) {
                                        $rowColorClass = 'row-status-partial';
                                    } elseif ($isCompleted) {
                                        $rowColorClass = 'row-status-completed';
                                    } elseif ($isRejected) {
                                        $rowColorClass = 'row-status-rejected';
                                    } else {
                                        $rowColorClass = 'row-status-uninspected';
                                    }
                                ?>
                                    <!-- Kanban Master Row -->
                                    <tr id="kanban-row-<?= $kid ?>" data-kanban-id="<?= $kid ?>" class="transition-colors <?= $rowColorClass ?>" style="vertical-align: middle;">
                                        
                                        <!-- No -->
                                        <td class="w-10 text-center px-2 py-3 font-semibold text-slate-400">
                                            <?= $offset + $idx + 1 ?>
                                        </td>

                                        <!-- No Kanban & Customer -->
                                        <td class="px-3 py-3">
                                            <div class="flex items-center space-x-1.5">
                                                <span class="font-mono font-black text-slate-900 text-xs">
                                                    #<?= htmlspecialchars($k['kanban_no']) ?>
                                                </span>
                                                <?php if ($k['plan_type'] === 'safety_stock'): ?>
                                                    <span class="px-1.5 py-0.2 rounded text-[8.5px] font-bold bg-purple-100 text-purple-800 border border-purple-200">
                                                        SS
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-[11px] text-slate-500 font-medium truncate max-w-[160px]" title="<?= htmlspecialchars($k['customer'] ?? 'PT. Indonesia Epson Industry') ?>">
                                                <?= htmlspecialchars($k['customer'] ?? 'PT. Indonesia Epson Industry') ?>
                                            </div>
                                        </td>

                                        <!-- Part Code & Name -->
                                        <td class="px-3 py-3">
                                            <span class="font-mono font-extrabold text-blue-700 text-xs block">
                                                <?= htmlspecialchars($k['item_code']) ?>
                                            </span>
                                            <span class="text-[11px] text-slate-600 font-medium block truncate max-w-[210px]" title="<?= htmlspecialchars($k['display_part_name']) ?>">
                                                <?= htmlspecialchars($k['display_part_name']) ?>
                                            </span>
                                            <?php if ($is100Check): ?>
                                                <div class="mt-0.5">
                                                    <span class="px-1.5 py-0.2 rounded text-[8.5px] font-bold bg-amber-50 text-amber-800 border border-amber-200 inline-block leading-tight">
                                                        100% Cek
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Jadwal & ETA -->
                                        <td class="px-2.5 py-3 whitespace-nowrap">
                                            <div class="font-medium text-slate-700 text-[10px] flex items-center gap-1.5" title="Tanggal Request (Req Date)">
                                                <svg width="12" height="12" style="width: 12px; height: 12px; min-width: 12px; min-height: 12px;" class="text-slate-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                                <span class="<?= ($isOverdue && empty($k['eta'])) ? 'text-rose-700 font-bold' : '' ?>"><?= !empty($k['req_date']) ? date('d/m/Y H:i', strtotime($k['req_date'])) : '-' ?></span>
                                            </div>
                                            <?php if (!empty($k['eta'])): ?>
                                                <div class="text-[9.5px] font-semibold <?= $isOverdue ? 'text-rose-700 font-bold' : 'text-amber-700' ?> flex items-center gap-1.5 mt-0.5" title="Estimasi Kedatangan (ETA)">
                                                    <svg width="12" height="12" style="width: 12px; height: 12px; min-width: 12px; min-height: 12px;" class="<?= $isOverdue ? 'text-rose-500' : 'text-amber-500' ?> flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                                    <span><?= date('d/m/Y H:i', strtotime($k['eta'])) ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <?php 
                                                $isCreatedToday = (!empty($k['created_at']) && date('Y-m-d', strtotime($k['created_at'])) === date('Y-m-d'));
                                                $targetDeliveryDate = !empty($k['eta']) ? $k['eta'] : (!empty($k['req_date']) ? $k['req_date'] : null);
                                                $isDeliveryFuture = (!empty($targetDeliveryDate) && date('Y-m-d', strtotime($targetDeliveryDate)) > date('Y-m-d'));
                                            ?>
                                            <?php if ($isOverdue): ?>
                                                <?php
                                                    $schedTs = strtotime($schedDate);
                                                    $daysOver = max(1, (int)floor((strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', $schedTs))) / 86400));
                                                ?>
                                                <div class="mt-1 flex items-center gap-1 flex-wrap">
                                                    <span class="px-1.5 py-0.5 rounded text-[8.5px] font-extrabold bg-rose-100 text-rose-800 border border-rose-300 inline-flex items-center gap-1 shadow-2xs" title="Perhatian: Jadwal pengiriman/ETA sudah terlewat! Prioritas Utama!">
                                                        <span class="w-1.5 h-1.5 rounded-full bg-rose-600 animate-pulse"></span> OVERDUE
                                                    </span>
                                                    <span class="px-1.5 py-0.5 rounded text-[8.5px] font-bold bg-rose-50 text-rose-700 border border-rose-200 inline-block" title="Terlambat <?= $daysOver ?> hari dari target ETA">
                                                        Lewat <?= $daysOver ?> hari
                                                    </span>
                                                </div>
                                            <?php elseif ($isCreatedToday && $isDeliveryFuture): ?>
                                                <div class="mt-1 flex items-center gap-1 flex-wrap">
                                                    <span class="px-1.5 py-0.5 rounded text-[8.5px] font-extrabold bg-blue-50 text-blue-700 border border-blue-200 inline-flex items-center gap-1" title="Dibuat hari ini: <?= date('d/m/Y H:i', strtotime($k['created_at'])) ?>">
                                                        <span class="w-1.5 h-1.5 rounded-full bg-blue-500"></span> Dibuat Hari Ini
                                                    </span>
                                                    <span class="px-1.5 py-0.5 rounded text-[8.5px] font-extrabold bg-amber-50 text-amber-800 border border-amber-200 inline-block" title="Target Pengiriman">
                                                        Kirim: <?= (date('Y-m-d', strtotime($targetDeliveryDate)) === date('Y-m-d', strtotime('+1 day'))) ? 'Besok' : date('d/m', strtotime($targetDeliveryDate)) ?>
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Target & Progres Scan -->
                                        <td class="px-2 py-3 text-center whitespace-nowrap">
                                            <div id="progress-container-<?= $kid ?>">
                                                <div class="font-mono font-black text-xs text-slate-800">
                                                    <span class="passed-qty-val"><?= number_format($passedQty) ?></span> <span class="text-slate-400 font-normal">/</span> <?= number_format($targetQty) ?> <span class="text-[10px] text-slate-500 font-medium">pcs</span>
                                                </div>
                                                <?php
                                                    if ($isFullyPassed || $progressPct >= 100) {
                                                        $barBg = 'background: linear-gradient(90deg, #10b981 0%, #059669 100%);';
                                                        $barLabel = '100% selesai';
                                                        $labelClass = 'text-emerald-700 font-extrabold';
                                                        $barFillWidth = 100;
                                                    } elseif ($hasActiveSession) {
                                                        $barBg = 'background: linear-gradient(90deg, #f59e0b 0%, #d97706 100%);';
                                                        $barLabel = ($progressPct > 0) ? ($progressPct . '% berjalan') : 'Mulai berjalan';
                                                        $labelClass = 'text-amber-800 font-black';
                                                        $barFillWidth = max(8, $progressPct);
                                                    } elseif ($progressPct > 0) {
                                                        $barBg = 'background: linear-gradient(90deg, #f59e0b 0%, #d97706 100%);';
                                                        $barLabel = $progressPct . '% parsial';
                                                        $labelClass = 'text-amber-800 font-extrabold';
                                                        $barFillWidth = $progressPct;
                                                    } else {
                                                        $barBg = 'background-color: #cbd5e1;';
                                                        $barLabel = 'Belum mulai';
                                                        $labelClass = 'text-slate-400 font-medium';
                                                        $barFillWidth = 0;
                                                    }
                                                ?>
                                                <div class="w-20 rounded-full overflow-hidden mx-auto my-1 bg-slate-200" style="height: 7px; border: 1px solid #cbd5e1;">
                                                    <div class="progress-bar-fill" style="height: 100%; width: <?= $barFillWidth ?>%; <?= $barBg ?> border-radius: 9999px; transition: width 0.3s ease;"></div>
                                                </div>
                                                <span class="text-[10px] <?= $labelClass ?> block leading-none progress-label">
                                                    <?= $barLabel ?>
                                                </span>
                                            </div>
                                        </td>

                                        <!-- Status Kanban (Real-Time Inspector Aware) -->
                                        <td class="px-2.5 py-3 text-center whitespace-nowrap">
                                            <div id="status-container-<?= $kid ?>">
                                                <?php if ($hasActiveSession): 
                                                    $actInspName = htmlspecialchars($actSess['inspector_name']);
                                                    $actDur = (int)($actSess['duration_minutes'] ?? 0);
                                                ?>
                                                    <span class="px-2.5 py-1 rounded-full text-[10.5px] font-extrabold bg-amber-100 text-amber-950 border border-amber-300 inline-flex items-center gap-1 shadow-2xs">
                                                        <span class="w-1.5 h-1.5 rounded-full bg-amber-600 animate-pulse"></span>
                                                        Sedang Dikerjakan
                                                    </span>
                                                    <span class="block text-[9px] text-amber-800 font-bold mt-0.5 truncate max-w-[135px] mx-auto" title="Dikerjakan oleh <?= $actInspName ?> (sejak <?= $actDur ?> mnt lalu)">
                                                        Oleh: <?= $actInspName ?>
                                                    </span>
                                                <?php elseif ($k['status'] === 'completed' || $isFullyPassed): ?>
                                                    <span style="background-color: #16a34a; color: #ffffff; font-weight: 800; font-size: 11px; padding: 3.5px 11px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 3px rgba(22, 163, 74, 0.4);">
                                                        ✓ Selesai
                                                    </span>
                                                <?php elseif ($isPartial || $k['status'] === 'partial' || ($passedQty > 0 && $passedQty < $targetQty)): ?>
                                                    <div class="flex flex-col items-center gap-1">
                                                        <span style="background-color: #d97706; color: #ffffff; font-weight: 800; font-size: 11px; padding: 3px 10px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 3px rgba(217, 119, 6, 0.35);">
                                                            ⏳ Parsial
                                                        </span>
                                                        <?php if ($hasRejectedSession): ?>
                                                            <span class="px-2 py-0.5 rounded-full text-[9px] font-extrabold bg-rose-100 text-rose-800 border border-rose-300 inline-flex items-center gap-1 shadow-2xs">
                                                                <span class="w-1.5 h-1.5 rounded-full bg-rose-600 animate-pulse"></span>
                                                                Ada Reject
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php elseif ($k['status'] === 'rejected'): ?>
                                                    <span class="px-2.5 py-1 rounded-full text-[10.5px] font-extrabold bg-rose-100 text-rose-950 border border-rose-300 inline-flex items-center gap-1 shadow-2xs">
                                                        <span class="w-1.5 h-1.5 rounded-full bg-rose-600"></span>
                                                        Reject
                                                    </span>
                                                <?php else: ?>
                                                    <span class="px-2.5 py-1 rounded-full text-[10.5px] font-bold bg-slate-100 text-slate-600 border border-slate-200 inline-flex items-center gap-1">
                                                        <span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span>
                                                        Belum Mulai
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>

                                        <!-- Aksi Kanban Row (Contextual: Pemilik vs Laptop Lain vs Mulai Baru) -->
                                        <td class="px-3 py-3 text-right whitespace-nowrap">
                                            <div class="inline-flex items-center space-x-1.5 justify-end">
                                                
                                                <div id="action-container-<?= $kid ?>">
                                                    <?php if ($hasActiveSession): 
                                                        $actSid = (int)$actSess['id'];
                                                        $actInspName = htmlspecialchars($actSess['inspector_name']);
                                                        if ($isOwnSession): ?>
                                                            <a href="<?= base_url('modules/inspection/session.php?id=' . $actSid) ?>" 
                                                               style="background-color: #d97706; color: #ffffff;"
                                                               class="px-3 py-1.5 bg-amber-600 hover:bg-amber-700 active:bg-amber-800 text-white font-bold text-xs rounded-md shadow-xs hover:shadow transition-all inline-flex items-center gap-1.5 whitespace-nowrap flex-nowrap"
                                                               title="Lanjutkan sesi inspeksi Anda">
                                                                <svg class="w-3 h-3 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor" style="fill: #ffffff;">
                                                                    <path d="M6.3 2.841A1.5 1.5 0 004 4.11V15.89a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z"/>
                                                                </svg>
                                                                <span>Lanjutkan</span>
                                                            </a>
                                                        <?php else: ?>
                                                            <a href="<?= base_url('modules/inspection/session.php?id=' . $actSid . '&view_mode=readonly') ?>" 
                                                               onclick="return confirmOpenSpectatorMode('<?= addslashes($actInspName) ?>')"
                                                               class="px-3 py-1.5 bg-slate-700 hover:bg-slate-800 text-white font-bold text-xs rounded-md shadow-xs hover:shadow transition-all inline-flex items-center gap-1.5 whitespace-nowrap flex-nowrap"
                                                               title="Buka sesi dalam Mode Pantau (Read-Only) tanpa mengganggu sesi yang sedang dikerjakan">
                                                                <svg class="w-3.5 h-3.5 text-amber-300 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                                                                <span>Lihat Sesi</span>
                                                            </a>
                                                        <?php endif; ?>
                                                    <?php elseif (!$isFullyPassed && $k['status'] !== 'completed'): ?>
                                                        <a href="<?= base_url('modules/inspection/session.php?scan_new=1&kanban_id=' . $kid) ?>" 
                                                           class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white font-bold text-xs rounded-md shadow-xs hover:shadow transition-all inline-flex items-center gap-1.5 whitespace-nowrap flex-nowrap" 
                                                           title="Mulai inspeksi pengerjaan Kanban ini">
                                                            <svg class="w-3 h-3 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor" style="fill: #ffffff;">
                                                                <path d="M6.3 2.841A1.5 1.5 0 004 4.11V15.89a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z"/>
                                                            </svg>
                                                            <span>Inspeksi</span>
                                                        </a>
                                                    <?php else: ?>
                                                        <!-- Selesai (tanpa label redundant) -->
                                                    <?php endif; ?>
                                                </div>

                                                <!-- Tombol Accordion Detail Sesi -->
                                                <button type="button" onclick="toggleKanbanDetail(<?= $kid ?>)" 
                                                        class="px-2 py-1 bg-slate-100 hover:bg-slate-200 active:bg-slate-300 text-slate-700 font-bold text-[11px] rounded-lg border border-slate-200 transition-all inline-flex items-center gap-0.5 cursor-pointer"
                                                        title="Buka / Tutup Rincian Sesi">
                                                    <span>Detail</span>
                                                    <span class="text-[10px] font-bold text-slate-500">(<?= $totalSessions ?>)</span>
                                                    <svg id="chevron-icon-<?= $kid ?>" class="w-3 h-3 transition-transform duration-200 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                                    </svg>
                                                </button>

                                            </div>
                                        </td>
                                    </tr>

                                    <!-- ═══════════════════════════════════════════════════════════ -->
                                    <!-- EXPANDABLE SUB-ROW: DETAIL SESI PER KANBAN (Mulai Sesi 1) -->
                                    <!-- ═══════════════════════════════════════════════════════════ -->
                                    <tr id="kanban-detail-<?= $kid ?>" class="hidden bg-slate-50/70 border-b border-slate-200">
                                        <td colspan="7" class="p-2.5 sm:p-3">
                                            <div class="bg-white border border-slate-200 rounded-lg p-3 shadow-2xs space-y-2.5">
                                                
                                                <div class="flex items-center justify-between border-b border-slate-100 pb-1.5">
                                                    <div class="flex items-center space-x-1.5">
                                                        <span class="w-2 h-2 rounded-full bg-blue-600"></span>
                                                        <h5 class="font-extrabold text-[11px] text-slate-900 uppercase tracking-wider">
                                                            Riwayat Sesi Kanban #<?= htmlspecialchars($k['kanban_no']) ?>
                                                        </h5>
                                                        <span class="text-[10.5px] text-slate-400 font-medium">
                                                            (Total <?= $totalSessions ?> Sesi)
                                                        </span>
                                                    </div>
                                                    <span class="text-[11px] font-semibold text-slate-500">
                                                        Target: <b class="text-slate-800"><?= number_format($targetQty) ?> pcs</b>
                                                    </span>
                                                </div>

                                                <?php if (empty($kSessions)): ?>
                                                    <div class="py-4 text-center text-slate-400 text-xs">
                                                        <p class="font-semibold text-slate-600">Belum ada sesi inspeksi untuk Kanban ini.</p>
                                                        <p class="text-[11px] text-slate-400 mt-0.5">Klik tombol <b>Mulai Inspeksi</b> di atas untuk memulai pemeriksaan pertama.</p>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="w-full overflow-x-auto">
                                                        <table class="w-full text-left text-xs table-fixed" style="border-collapse: collapse; min-width: 560px;">
                                                            <colgroup>
                                                                <col style="width: 64px">
                                                                <col style="width: 26%">
                                                                <col style="width: 18%">
                                                                <col style="width: 22%">
                                                                <col style="width: 12%">
                                                                <col>
                                                            </colgroup>
                                                            <thead class="bg-slate-50 text-slate-500 font-bold border-b border-slate-200 uppercase text-[9px]">
                                                                <tr>
                                                                    <th class="px-2.5 py-1.5 text-center">Sesi</th>
                                                                    <th class="px-2.5 py-1.5">Waktu & Shift</th>
                                                                    <th class="px-2.5 py-1.5">Inspektur</th>
                                                                    <th class="px-2.5 py-1.5 text-center">Qty & Lot</th>
                                                                    <th class="px-2.5 py-1.5 text-center">Status</th>
                                                                    <th class="px-2.5 py-1.5 text-right">Aksi</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody class="divide-y divide-slate-100">
                                                                <?php foreach ($kSessions as $sIdx => $s): 
                                                                    $sid = (int)$s['id'];
                                                                    $sessionNumber = $sIdx + 1;
                                                                    $sLots = $lotsBySession[$sid] ?? [];
                                                                    $shiftName = getShiftName($s['started_at']);
                                                                    $isClosed = !empty($s['closed_at']);
                                                                    $durationText = $isClosed ? (($s['duration_minutes'] > 0) ? ($s['duration_minutes'] . ' mnt') : '< 1 mnt') : 'Berjalan';
                                                                    $isReinsp = !empty($s['is_reinspection']);
                                                                ?>
                                                                    <tr class="hover:bg-slate-50/70 transition-colors <?= ($s['status'] === 'in_progress') ? 'bg-amber-50/30' : '' ?>" style="vertical-align: middle;">
                                                                        
                                                                        <!-- Sesi -->
                                                                        <td class="px-2.5 py-2 text-center whitespace-nowrap">
                                                                            <span class="font-extrabold text-blue-700 text-xs">Sesi <?= $sessionNumber ?></span>
                                                                            <?php if ($isReinsp): ?>
                                                                                <span class="block text-[8px] font-extrabold text-amber-700 bg-amber-100 rounded px-1 mt-0.5">RE-INSP</span>
                                                                            <?php endif; ?>
                                                                        </td>

                                                                        <!-- Waktu & Shift (Merged) -->
                                                                        <td class="px-2.5 py-2">
                                                                            <div class="font-bold text-slate-800 text-[11px] leading-tight">
                                                                                <?= date('d M Y, H:i', strtotime($s['started_at'])) ?><?php if (!empty($s['closed_at'])): ?> &ndash; <?= date('H:i', strtotime($s['closed_at'])) ?><?php endif; ?>
                                                                            </div>
                                                                            <div class="text-[9px] text-slate-400 mt-0.5"><?= htmlspecialchars($shiftName) ?> &middot; <?= $durationText ?></div>
                                                                        </td>

                                                                        <!-- Inspektur -->
                                                                        <td class="px-2.5 py-2 font-semibold text-slate-800 text-[11px] truncate" title="<?= htmlspecialchars($s['inspector_name']) ?>">
                                                                            <?= htmlspecialchars($s['inspector_name']) ?>
                                                                        </td>

                                                                        <!-- Qty & Lot -->
                                                                        <td class="px-2.5 py-2 text-center">
                                                                            <div class="font-mono font-black text-slate-900 text-xs"><?= number_format($s['total_scanned_qty']) ?> pcs</div>
                                                                            <div class="text-[9px] text-slate-400 font-mono">Sampel <?= $s['samples_checked'] ?>/<?= $s['sample_size'] ?></div>
                                                                            <?php if (!empty($sLots)): 
                                                                                $passedLotsCount = 0;
                                                                                $passedLotsQty = 0;
                                                                                $rejectedLotsCount = 0;
                                                                                $rejectedLotsQty = 0;
                                                                                $inProgLotsCount = 0;
                                                                                $inProgLotsQty = 0;
                                                                                $lotDetails = [];

                                                                                foreach ($sLots as $lIdx => $lItem) {
                                                                                    $lr = $lItem['lot_result'] ?? '';
                                                                                    $ls = $lItem['lot_status'] ?? 'ok';
                                                                                    $lQty = (int)($lItem['qty'] ?? 0);
                                                                                    $lNo = $lItem['lot_number'] ?? '-';
                                                                                    $lRef = $lItem['ref_number'] ?? ($lIdx + 1);

                                                                                    if ($lr === 'passed' && $ls !== 'replaced') {
                                                                                        $passedLotsCount++;
                                                                                        $passedLotsQty += $lQty;
                                                                                    } elseif ($lr === 'rejected' || $ls === 'rejected' || $ls === 'ng_found') {
                                                                                        $rejectedLotsCount++;
                                                                                        $rejectedLotsQty += $lQty;
                                                                                    } elseif ($lr === 'in_progress') {
                                                                                        $inProgLotsCount++;
                                                                                        $inProgLotsQty += $lQty;
                                                                                    }
                                                                                    $lRes = strtoupper($lr ?: $ls);
                                                                                    $lotDetails[] = "Ref #{$lRef}: Lot {$lNo} ({$lQty} pcs) - {$lRes}";
                                                                                }
                                                                                $lotTooltip = implode("\n", $lotDetails);
                                                                            ?>
                                                                                <div class="mt-1 flex flex-wrap items-center justify-center gap-1" title="<?= htmlspecialchars($lotTooltip) ?>">
                                                                                    <?php if ($passedLotsCount > 0): ?>
                                                                                        <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[9.5px] font-extrabold border border-emerald-300 bg-emerald-50 text-emerald-700 shadow-2xs">
                                                                                            <span class="text-[9px]">✓</span> <?= $passedLotsCount ?> Pass (<?= number_format($passedLotsQty) ?> pcs)
                                                                                        </span>
                                                                                    <?php endif; ?>
                                                                                    <?php if ($rejectedLotsCount > 0): ?>
                                                                                        <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[9.5px] font-extrabold border border-rose-300 bg-rose-50 text-rose-700 shadow-2xs">
                                                                                            <span class="text-[9px]">✕</span> <?= $rejectedLotsCount ?> NG (<?= number_format($rejectedLotsQty) ?> pcs)
                                                                                        </span>
                                                                                    <?php endif; ?>
                                                                                    <?php if ($inProgLotsCount > 0): ?>
                                                                                        <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[9.5px] font-extrabold border border-amber-300 bg-amber-50 text-amber-700 shadow-2xs">
                                                                                            <span>⏳</span> <?= $inProgLotsCount ?> Draft
                                                                                        </span>
                                                                                    <?php endif; ?>
                                                                                </div>
                                                                            <?php endif; ?>
                                                                        </td>

                                                                        <!-- Status -->
                                                                        <td class="px-2.5 py-2 text-center whitespace-nowrap">
                                                                            <?php if ($s['status'] === 'passed'): ?>
                                                                                <span class="px-2 py-0.5 rounded-full text-[9.5px] font-bold bg-emerald-100 text-emerald-800 border border-emerald-200">Passed</span>
                                                                            <?php elseif ($s['status'] === 'rejected'): ?>
                                                                                <span class="px-2 py-0.5 rounded-full text-[9.5px] font-bold bg-rose-100 text-rose-800 border border-rose-200">Rejected</span>
                                                                            <?php else: ?>
                                                                                <span class="px-2 py-0.5 rounded-full text-[9.5px] font-bold bg-amber-100 text-amber-800 border border-amber-200">Berjalan</span>
                                                                            <?php endif; ?>
                                                                        </td>

                                                                        <!-- Aksi -->
                                                                        <td class="px-2.5 py-2 text-right whitespace-nowrap">
                                                                            <div class="inline-flex items-center gap-1 justify-end">
                                                                                <?php if ($s['status'] === 'in_progress'): ?>
                                                                                    <a href="<?= base_url('modules/inspection/session.php?id=' . $sid) ?>"
                                                                                       style="background-color: #d97706; color: #ffffff;"
                                                                                       class="px-2.5 py-1 bg-amber-600 hover:bg-amber-700 text-white font-bold text-[10.5px] rounded shadow-xs transition-all inline-flex items-center gap-1">
                                                                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path></svg>
                                                                                        Lanjutkan
                                                                                    </a>
                                                                                <?php else: ?>
                                                                                    <a href="<?= base_url('modules/inspection/session.php?id=' . $sid) ?>"
                                                                                       class="px-2 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-[10.5px] rounded border border-slate-200 transition-all inline-flex items-center gap-1">
                                                                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                                                                                        Detail
                                                                                    </a>
                                                                                    <?php if ($s['status'] === 'rejected'): ?>
                                                                                        <a href="<?= base_url('modules/inspection/print_rejection.php?session_id=' . $sid) ?>"
                                                                                           target="_blank" rel="noopener"
                                                                                           class="px-2 py-1 bg-rose-50 hover:bg-rose-100 text-rose-700 font-bold text-[10.5px] rounded border border-rose-200 transition-all inline-flex items-center gap-1">
                                                                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
                                                                                            Cetak
                                                                                        </a>
                                                                                    <?php endif; ?>
                                                                                <?php endif; ?>
                                                                            </div>
                                                                        </td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                <?php endif; ?>

                                            </div>
                                        </td>
                                    </tr>

                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <!-- ═══════════════════════════════════════════════════════════════ -->
            <!-- PAGINATION BAR                                                -->
            <!-- ═══════════════════════════════════════════════════════════════ -->
            <?php if ($totalPages > 1): ?>
                <div class="px-4 py-2.5 bg-slate-50 border-t border-slate-200 flex items-center justify-between text-xs text-slate-600 flex-wrap gap-2">
                    <div>
                        Menampilkan <b><?= min($totalItems, $offset + 1) ?></b> s/d <b><?= min($totalItems, $offset + ($activeTab === 'safety_stock' ? count($ssSessions) : ($activeTab === 'my_inspections' ? count($mySessions) : count($kanbans)))) ?></b> dari total <b><?= $totalItems ?></b> <?= ($activeTab === 'safety_stock') ? 'Sesi Safety Stock' : (($activeTab === 'my_inspections') ? 'Sesi Riwayat Saya' : 'Kanban') ?>
                    </div>
                    <div class="inline-flex space-x-1">
                        <?php 
                        $queryParams = $_GET;
                        ?>
                        <?php if ($page > 1): 
                            $queryParams['page'] = $page - 1;
                        ?>
                            <a href="?<?= http_build_query($queryParams) ?>" class="px-2 py-0.5 bg-white border border-slate-200 rounded hover:bg-slate-100 font-bold text-[11px]">&laquo; Prev</a>
                        <?php endif; ?>

                        <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): 
                            $queryParams['page'] = $p;
                        ?>
                            <a href="?<?= http_build_query($queryParams) ?>" class="px-2 py-0.5 rounded font-bold text-[11px] <?= ($p === $page) ? ($activeTab === 'safety_stock' ? 'bg-purple-600 text-white' : ($activeTab === 'my_inspections' ? 'bg-indigo-600 text-white' : 'bg-blue-600 text-white')) : 'bg-white border border-slate-200 hover:bg-slate-100 text-slate-700' ?>">
                                <?= $p ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): 
                            $queryParams['page'] = $page + 1;
                        ?>
                            <a href="?<?= http_build_query($queryParams) ?>" class="px-2 py-0.5 bg-white border border-slate-200 rounded hover:bg-slate-100 font-bold text-[11px]">Next &raquo;</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

        </div>

    </main>

    <script>
    /**
     * Set Preset Period and Submit Filter Form
     */
    function setKanbanPeriod(p) {
        var input = document.getElementById('kanbanPeriodInput');
        if (input) input.value = p;
        var s = document.querySelector('input[name="start_date"]');
        var e = document.querySelector('input[name="end_date"]');
        if (s) s.value = '';
        if (e) e.value = '';
        var form = document.getElementById('kanbanFilterForm');
        if (form) form.submit();
    }

    /**
     * Smooth Accordion Toggle for My Inspection Session Details
     */
    function toggleMyDetail(sid) {
        var row = document.getElementById('my-detail-' + sid);
        var icon = document.getElementById('my-chevron-' + sid);
        if (!row) return;

        if (row.classList.contains('hidden')) {
            row.classList.remove('hidden');
            if (icon) icon.style.transform = 'rotate(180deg)';
        } else {
            row.classList.add('hidden');
            if (icon) icon.style.transform = 'rotate(0deg)';
        }
    }

    /**
     * Smooth Accordion Toggle for Kanban Session Details
     */
    function toggleKanbanDetail(kid) {
        var row = document.getElementById('kanban-detail-' + kid);
        var icon = document.getElementById('chevron-icon-' + kid);
        if (!row) return;

        if (row.classList.contains('hidden')) {
            row.classList.remove('hidden');
            if (icon) icon.style.transform = 'rotate(180deg)';
        } else {
            row.classList.add('hidden');
            if (icon) icon.style.transform = 'rotate(0deg)';
        }
    }

    /**
     * Smooth Accordion Toggle for Safety Stock Session Details
     */
    function toggleSsDetail(sid) {
        var row = document.getElementById('ss-detail-' + sid);
        var icon = document.getElementById('ss-chevron-' + sid);
        if (!row) return;

        if (row.classList.contains('hidden')) {
            row.classList.remove('hidden');
            if (icon) icon.style.transform = 'rotate(180deg)';
        } else {
            row.classList.add('hidden');
            if (icon) icon.style.transform = 'rotate(0deg)';
        }
    }

    /**
     * Confirmation when entering a session active by another inspector
     */
    function confirmOpenSpectatorMode(inspectorName) {
        return confirm("Kanban ini sedang aktif diinspeksi oleh " + inspectorName + ".\n\nAnda akan membuka sesi ini dalam MODE PANTAU (Read-Only) agar tidak mengganggu pengerjaan operator utama.\n\nLanjutkan membuka?");
    }

    /**
     * Auto-Focus & Barcode Scanner Integration for Search Input
     */
    (function initSearchScanner() {
        var searchInput = document.getElementById('kanbanSearchInput');
        if (!searchInput) return;

        // Function to focus and select all text (so scanning immediately overwrites previous value)
        function focusAndSelect() {
            try {
                searchInput.focus();
                if (searchInput.value.length > 0) {
                    searchInput.select();
                }
            } catch (e) {}
        }

        // 1. Initial focus on DOM ready & window load
        setTimeout(focusAndSelect, 80);
        window.addEventListener('load', function() {
            setTimeout(focusAndSelect, 150);
        });

        // 2. Parser for QR Code format (Z1<part_code>|Z2<lot>|...)
        function cleanScanValue(val) {
            if (!val) return '';
            var trimmed = val.trim();
            if (trimmed.indexOf('|') !== -1 || trimmed.indexOf('Z1') !== -1) {
                var parts = trimmed.split('|');
                for (var i = 0; i < parts.length; i++) {
                    var p = parts[i].trim();
                    if (p.indexOf('Z1') === 0) {
                        return p.substring(2).trim();
                    }
                }
            }
            return trimmed;
        }

        // 3. Intercept input to automatically parse QR barcode format
        searchInput.addEventListener('input', function(e) {
            var raw = searchInput.value;
            if (raw && (raw.indexOf('|') !== -1 || raw.indexOf('Z1') === 0)) {
                var cleaned = cleanScanValue(raw);
                if (cleaned && cleaned !== raw) {
                    searchInput.value = cleaned;
                }
            }
        });

        // 4. On form submit, ensure value is cleaned
        var form = document.getElementById('kanbanFilterForm');
        if (form) {
            form.addEventListener('submit', function() {
                var cleaned = cleanScanValue(searchInput.value);
                if (cleaned) {
                    searchInput.value = cleaned;
                }
            });
        }

        // 5. Smart Keyboard / Scanner listener:
        // - Escape key: focus and select
        // - '/' key outside of inputs: focus search
        // - If scanner fires while focus is on table/body, redirect keystroke to searchInput
        document.addEventListener('keydown', function(e) {
            var activeEl = document.activeElement;
            var isInputActive = activeEl && (
                activeEl.tagName === 'INPUT' ||
                activeEl.tagName === 'TEXTAREA' ||
                activeEl.tagName === 'SELECT' ||
                activeEl.isContentEditable
            );

            if (e.key === 'Escape') {
                focusAndSelect();
                return;
            }

            if (e.key === '/' && !isInputActive) {
                e.preventDefault();
                focusAndSelect();
                return;
            }

            if (!isInputActive && !e.ctrlKey && !e.altKey && !e.metaKey && e.key.length === 1) {
                searchInput.focus();
            }
        });

        // 6. When clicking empty space or table background, re-focus search input
        document.addEventListener('click', function(e) {
            var target = e.target;
            if (target && !target.closest('a, button, input, select, textarea, label, [role="button"], details, summary')) {
                focusAndSelect();
            }
        });
    })();

    /**
     * High-Scale Real-Time Workboard Poller (Multi-Laptop Live Sync)
     * Queries lightweight state hash every 4 seconds.
     * Uses O(1) B-Tree point-lookups on server; zero reload or UI disruptions.
     */
    (function initRealTimeWorkboard() {
        var activeTab = '<?= $activeTab ?>';
        if (activeTab !== 'kanban') return;

        var lastLiveHash = '';
        var isPolling = false;
        var pollIntervalMs = 4000;
        var syncTimeElem = document.getElementById('live-sync-time');
        var syncIndicator = document.getElementById('live-sync-indicator');
        var baseUrl = '<?= base_url('modules/inspection/') ?>';

        function pollLiveStatus() {
            if (isPolling) return;

            var rows = document.querySelectorAll('[data-kanban-id]');
            if (!rows || rows.length === 0) return;

            var visibleIds = [];
            rows.forEach(function(r) {
                var kid = r.getAttribute('data-kanban-id');
                if (kid) visibleIds.push(kid);
            });

            if (visibleIds.length === 0) return;

            isPolling = true;
            var url = baseUrl + 'api/get_live_status.php?kanban_ids=' + encodeURIComponent(visibleIds.join(',')) + '&last_hash=' + encodeURIComponent(lastLiveHash);

            fetch(url, { credentials: 'same-origin' })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    isPolling = false;
                    if (!data || !data.success) return;

                    if (syncTimeElem && data.server_time) {
                        syncTimeElem.innerText = data.server_time;
                    }

                    // If server state hash is identical, skip DOM rendering (O(0) client work)
                    if (data.changed === false) {
                        return;
                    }

                    lastLiveHash = data.hash || '';
                    var updates = data.kanban_updates || {};

                    // Update each visible row
                    Object.keys(updates).forEach(function(kidStr) {
                        var info = updates[kidStr];
                        var kid = info.id;
                        var rowElem = document.getElementById('kanban-row-' + kid);
                        var statusContainer = document.getElementById('status-container-' + kid);
                        var actionContainer = document.getElementById('action-container-' + kid);
                        var progressContainer = document.getElementById('progress-container-' + kid);

                        if (!rowElem) return;

                        // 1. Row highlight & status border
                        var statusClasses = [
                            'row-status-overdue',
                            'row-status-active',
                            'row-status-partial',
                            'row-status-completed',
                            'row-status-rejected',
                            'row-status-uninspected'
                        ];
                        statusClasses.forEach(function(cls) { rowElem.classList.remove(cls); });

                        if (info.has_active_session) {
                            rowElem.classList.add('row-status-active');
                        } else if (info.status_type === 'completed' || info.is_fully_passed) {
                            rowElem.classList.add('row-status-completed');
                        } else if (info.status_type === 'partial' || (info.passed_qty > 0 && info.passed_qty < info.target_qty)) {
                            rowElem.classList.add('row-status-partial');
                        } else if (info.status_type === 'rejected') {
                            rowElem.classList.add('row-status-rejected');
                        } else {
                            rowElem.classList.add('row-status-uninspected');
                        }

                        // 2. Status Badge & Active Inspector Attribution
                        if (statusContainer) {
                            if (info.has_active_session) {
                                var inspName = info.active_inspector_name || 'QC Inspector';
                                var dur = info.duration_minutes || 0;
                                statusContainer.innerHTML = 
                                    '<span class="px-2.5 py-1 rounded-full text-[10.5px] font-extrabold bg-amber-100 text-amber-950 border border-amber-300 inline-flex items-center gap-1 shadow-2xs">' +
                                        '<span class="w-1.5 h-1.5 rounded-full bg-amber-600 animate-pulse"></span>' +
                                        'Sedang Dikerjakan' +
                                    '</span>' +
                                    '<span class="block text-[9px] text-amber-800 font-bold mt-0.5 truncate max-w-[135px] mx-auto" title="Dikerjakan oleh ' + escapeHtml(inspName) + ' (sejak ' + dur + ' mnt lalu)">' +
                                        'Oleh: ' + escapeHtml(inspName) +
                                    '</span>';
                            } else if (info.status_type === 'completed' || info.is_fully_passed) {
                                statusContainer.innerHTML = 
                                    '<span style="background-color: #16a34a; color: #ffffff; font-weight: 800; font-size: 11px; padding: 3.5px 11px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 3px rgba(22, 163, 74, 0.4);">' +
                                        '✓ Selesai' +
                                    '</span>';
                            } else if (info.status_type === 'partial' || (info.passed_qty > 0 && info.passed_qty < info.target_qty)) {
                                var rejectBadgeHtml = (info.has_rejected_session && !info.is_fully_passed && info.status_type !== 'completed')
                                    ? '<span class="px-2 py-0.5 rounded-full text-[9px] font-extrabold bg-rose-100 text-rose-800 border border-rose-300 inline-flex items-center gap-1 shadow-2xs mt-1"><span class="w-1.5 h-1.5 rounded-full bg-rose-600 animate-pulse"></span>Ada Reject</span>'
                                    : '';
                                statusContainer.innerHTML = 
                                    '<div class="flex flex-col items-center gap-1">' +
                                        '<span style="background-color: #d97706; color: #ffffff; font-weight: 800; font-size: 11px; padding: 3px 10px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 3px rgba(217, 119, 6, 0.35);">' +
                                            '⏳ Parsial' +
                                        '</span>' +
                                        rejectBadgeHtml +
                                    '</div>';
                            } else if (info.status_type === 'rejected') {
                                statusContainer.innerHTML = 
                                    '<span class="px-2.5 py-1 rounded-full text-[10.5px] font-extrabold bg-rose-100 text-rose-950 border border-rose-300 inline-flex items-center gap-1 shadow-2xs">' +
                                        '<span class="w-1.5 h-1.5 rounded-full bg-rose-600"></span>' +
                                        'Reject' +
                                    '</span>';
                            } else {
                                statusContainer.innerHTML = 
                                    '<span class="px-2.5 py-1 rounded-full text-[10.5px] font-bold bg-slate-100 text-slate-600 border border-slate-200 inline-flex items-center gap-1">' +
                                        '<span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span>' +
                                        'Belum Mulai' +
                                    '</span>';
                            }
                        }

                        // 3. Action Buttons (Own session vs Spectator vs Start)
                        if (actionContainer) {
                            if (info.has_active_session) {
                                var actSid = info.active_session_id;
                                var inspName = info.active_inspector_name || 'QC Inspector';
                                if (info.is_own_session) {
                                    actionContainer.innerHTML = 
                                        '<a href="' + baseUrl + 'session.php?id=' + actSid + '" ' +
                                           'style="background-color: #d97706; color: #ffffff;" ' +
                                           'class="px-3 py-1.5 bg-amber-600 hover:bg-amber-700 active:bg-amber-800 text-white font-bold text-xs rounded-md shadow-xs hover:shadow transition-all inline-flex items-center gap-1.5 whitespace-nowrap flex-nowrap" ' +
                                           'title="Lanjutkan sesi inspeksi Anda">' +
                                            '<svg class="w-3 h-3 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor" style="fill: #ffffff;">' +
                                                '<path d="M6.3 2.841A1.5 1.5 0 004 4.11V15.89a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z"/>' +
                                            '</svg>' +
                                            '<span>Lanjutkan</span>' +
                                        '</a>';
                                } else {
                                    var safeName = inspName.replace(/'/g, "\\'");
                                    actionContainer.innerHTML = 
                                        '<a href="' + baseUrl + 'session.php?id=' + actSid + '&view_mode=readonly" ' +
                                           'onclick="return confirmOpenSpectatorMode(\'' + safeName + '\')" ' +
                                           'class="px-3 py-1.5 bg-slate-700 hover:bg-slate-800 text-white font-bold text-xs rounded-md shadow-xs hover:shadow transition-all inline-flex items-center gap-1.5 whitespace-nowrap flex-nowrap" ' +
                                           'title="Buka sesi dalam Mode Pantau (Read-Only) tanpa mengganggu sesi aktif">' +
                                            '<svg class="w-3.5 h-3.5 text-amber-300 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>' +
                                            '<span>Lihat Sesi</span>' +
                                        '</a>';
                                }
                            } else if (!info.is_fully_passed && info.status_type !== 'completed') {
                                actionContainer.innerHTML = 
                                    '<a href="' + baseUrl + 'session.php?scan_new=1&kanban_id=' + kid + '" ' +
                                       'class="px-3 py-1.5 bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white font-bold text-xs rounded-md shadow-xs hover:shadow transition-all inline-flex items-center gap-1.5 whitespace-nowrap flex-nowrap" ' +
                                       'title="Mulai inspeksi pengerjaan Kanban ini">' +
                                        '<svg class="w-3 h-3 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor" style="fill: #ffffff;">' +
                                            '<path d="M6.3 2.841A1.5 1.5 0 004 4.11V15.89a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z"/>' +
                                        '</svg>' +
                                        '<span>Inspeksi</span>' +
                                    '</a>';
                            } else {
                                actionContainer.innerHTML = '';
                            }
                        }

                        // 4. Progress Bar update
                        if (progressContainer) {
                            var passedSpan = progressContainer.querySelector('.passed-qty-val');
                            if (passedSpan) passedSpan.innerText = Number(info.passed_qty).toLocaleString();
                            var barFill = progressContainer.querySelector('.progress-bar-fill');
                            var barLbl = progressContainer.querySelector('.progress-label');
                            if (barFill && barLbl) {
                                if (info.is_fully_passed || info.progress_pct >= 100) {
                                    barFill.style.width = '100%';
                                    barFill.style.background = 'linear-gradient(90deg, #10b981 0%, #059669 100%)';
                                    barLbl.className = 'text-[10px] text-emerald-700 font-extrabold block leading-none progress-label';
                                    barLbl.innerText = '100% selesai';
                                } else if (info.has_active_session) {
                                    barFill.style.width = Math.max(8, info.progress_pct) + '%';
                                    barFill.style.background = 'linear-gradient(90deg, #f59e0b 0%, #d97706 100%)';
                                    barLbl.className = 'text-[10px] text-amber-800 font-black block leading-none progress-label';
                                    barLbl.innerText = (info.progress_pct > 0) ? (info.progress_pct + '% berjalan') : 'Mulai berjalan';
                                } else if (info.progress_pct > 0) {
                                    barFill.style.width = info.progress_pct + '%';
                                    barFill.style.background = 'linear-gradient(90deg, #f59e0b 0%, #d97706 100%)';
                                    barLbl.className = 'text-[10px] text-amber-800 font-extrabold block leading-none progress-label';
                                    barLbl.innerText = info.progress_pct + '% parsial';
                                } else {
                                    barFill.style.width = '0%';
                                    barFill.style.background = '#cbd5e1';
                                    barLbl.className = 'text-[10px] text-slate-400 font-medium block leading-none progress-label';
                                    barLbl.innerText = 'Belum mulai';
                                }
                            }
                        }

                        // 5. Relocate completed row to the bottom of the workboard
                        if (info.is_fully_passed || info.status_type === 'completed') {
                            var tbody = rowElem.parentNode;
                            if (tbody && rowElem !== tbody.lastElementChild) {
                                tbody.appendChild(rowElem);
                                var detailRow = document.getElementById('kanban-detail-' + kid);
                                if (detailRow) tbody.appendChild(detailRow);
                            }
                        }
                    });
                })
                .catch(function(err) {
                    isPolling = false;
                });
        }

        function escapeHtml(text) {
            var div = document.createElement('div');
            div.textContent = text || '';
            return div.innerHTML;
        }

        // Initial poller run and recurrent interval
        setTimeout(pollLiveStatus, 1500);
        setInterval(pollLiveStatus, pollIntervalMs);
    })();
    </script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
