<?php
/**
 * Redesigned Unified OQC Inspection Workbench (FR-3 & FR-6)
 * 100% Full-Screen, Zero-Scroll, All-in-One 3-Stage Workbench Matching Client Mockup
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

if (!function_exists('format_relative_eta')) {
    function format_relative_eta($rawEta) {
        if (empty($rawEta) || $rawEta === '-') return 'Reguler';
        $ts = strtotime($rawEta);
        if (!$ts) return $rawEta;

        $targetDate = new DateTime(date('Y-m-d', $ts));
        $todayDate  = new DateTime(date('Y-m-d'));
        $diff = $todayDate->diff($targetDate);
        $diffDays = (int)$diff->format('%r%a');

        $timeStr = date('H:i', $ts) . ' WIB';
        $months = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
        $mIdx = (int)date('n', $ts) - 1;
        $dateShort = date('d', $ts) . ' ' . $months[$mIdx];

        if ($diffDays === 0) {
            $label = 'Hari ini';
        } elseif ($diffDays === 1) {
            $label = 'Besok';
        } elseif ($diffDays === 2) {
            $label = '2 Hari lagi';
        } elseif ($diffDays > 2) {
            $label = "{$diffDays} Hari lagi";
        } elseif ($diffDays === -1) {
            $label = 'Kemarin';
        } else {
            $abs = abs($diffDays);
            $label = "{$abs} Hari lalu";
        }

        return "{$label} ({$dateShort}), {$timeStr}";
    }
}

require_menu_access('inspection');
$currentUser = current_user();
$currentUserId = (int)$currentUser['id'];
$currentUserRole = $currentUser['role'];

session_write_close();

$sessionId = (int)($_GET['id'] ?? $_GET['session_id'] ?? 0);
$autoScanNew = isset($_GET['scan_new']) && $_GET['scan_new'] == 1;

$pdo = getDB();

if (isset($_GET['action']) && $_GET['action'] === 'get_planning_items' && $pdo) {
    header('Content-Type: application/json');
    $items = [];
    $todayBatches = [];
    $pendingYesterdayCount = 0;
    
    $selectedId = (int)($_GET['selected_id'] ?? $_GET['kanban_id'] ?? 0);
    $batchFilter = trim(sanitize($_GET['batch_id'] ?? 'all'));
    $scopeFilter = trim(sanitize($_GET['scope'] ?? 'today')); // 'today' | 'pending_yesterday' | 'all'
    $todayDate = date('Y-m-d');
    $todayStart = $todayDate . ' 00:00:00';
    $todayEnd   = $todayDate . ' 23:59:59';
    
    $searchQuery = trim(sanitize($_GET['search_query'] ?? ''));
    $isSearchMode = ($searchQuery !== '');

    try {
        if (!$isSearchMode) {
            // 1. Ambil daftar batch aktif hari ini (HANYA saat load awal, BUKAN saat scan/search)
            // SARGABLE Index: Menggunakan range comparison datetime agar B-Tree index terpakai optimal
            $batchSql = "
                SELECT b.id, b.document_number, b.vendor, b.imported_at, b.import_method,
                       COUNT(k.id) as item_count,
                       SUM(CASE WHEN k.status = 'completed' THEN 1 ELSE 0 END) as completed_count,
                       SUM(CASE WHEN k.status IN ('in_progress', 'partial', 'rejected') THEN 1 ELSE 0 END) as in_progress_count
                FROM kanban_batches b
                JOIN kanban_items k ON k.batch_id = b.id
                WHERE (k.plan_type IS NULL OR k.plan_type = 'kanban')
                  AND (k.check_type IS NULL OR k.check_type != 'Safety Stock')
                  AND (k.kanban_no IS NULL OR k.kanban_no NOT LIKE 'SS-%')
                  AND (
                      (b.imported_at >= :b_start AND b.imported_at <= :b_end)
                      OR (k.created_at >= :k_start AND k.created_at <= :k_end)
                  )
                GROUP BY b.id, b.document_number, b.vendor, b.imported_at, b.import_method
                ORDER BY b.imported_at ASC, b.id ASC
            ";
            $stmtB = $pdo->prepare($batchSql);
            $stmtB->execute([
                ':b_start' => $todayStart,
                ':b_end'   => $todayEnd,
                ':k_start' => $todayStart,
                ':k_end'   => $todayEnd
            ]);
            $todayBatches = $stmtB->fetchAll(PDO::FETCH_ASSOC);
            
            // 2. Hitung jumlah tunggakan kemarin (sebelum hari ini yang belum selesai / reject / in_progress)
            $stmtPy = $pdo->prepare("
                SELECT COUNT(DISTINCT k.id)
                FROM kanban_items k
                WHERE (k.plan_type IS NULL OR k.plan_type = 'kanban')
                  AND (k.check_type IS NULL OR k.check_type != 'Safety Stock')
                  AND (k.kanban_no IS NULL OR k.kanban_no NOT LIKE 'SS-%')
                  AND k.created_at < :py_start
                  AND k.status IN ('uninspected', 'in_progress', 'partial', 'rejected')
            ");
            $stmtPy->execute([':py_start' => $todayStart]);
            $pendingYesterdayCount = (int)$stmtPy->fetchColumn();

            // 2.A Hitung KPI Utama Status (Disesuaikan dengan Scope / Batch yang Sedang Dipilih)
            $kpiStats = [
                'total'       => 0,
                'uncompleted' => 0,
                'uninspected' => 0,
                'partial'     => 0,
                'active'      => 0,
                'in_progress' => 0,
                'completed'   => 0,
                'remain'      => (int)$pendingYesterdayCount,
                'rejected'    => 0
            ];
            try {
                $kpiWhere = [
                    "(k.plan_type IS NULL OR k.plan_type = 'kanban')",
                    "(k.check_type IS NULL OR k.check_type != 'Safety Stock')",
                    "(k.kanban_no IS NULL OR k.kanban_no NOT LIKE 'SS-%')"
                ];
                $kpiParams = [];

                // KPI stats selalu berpatokan pada jadwal hari ini (Fixed Base) agar tidak ter-reset saat filter REMAIN aktif
                // SARGABLE: Gunakan perbandingan range langsung pada kolom b.imported_at atau k.created_at
                $kpiWhere[] = "((b.imported_at >= :kpi_start AND b.imported_at <= :kpi_end) OR (b.imported_at IS NULL AND k.created_at >= :kpi_start2 AND k.created_at <= :kpi_end2))";
                $kpiParams[':kpi_start']  = $todayStart;
                $kpiParams[':kpi_end']    = $todayEnd;
                $kpiParams[':kpi_start2'] = $todayStart;
                $kpiParams[':kpi_end2']   = $todayEnd;
                if ($scopeFilter !== 'pending_yesterday' && $batchFilter !== 'all' && is_numeric($batchFilter)) {
                    $kpiWhere[] = "k.batch_id = :kpi_batch_id";
                    $kpiParams[':kpi_batch_id'] = (int)$batchFilter;
                }

                $kpiWhereSql = implode(' AND ', $kpiWhere);

                $stmtKpi = $pdo->prepare("
                    SELECT 
                        COUNT(DISTINCT k.id) as total_count,
                        SUM(CASE WHEN (k.status IN ('uninspected', 'pending') OR k.status IS NULL) AND NOT EXISTS (
                            SELECT 1 FROM inspection_sessions s_act WHERE s_act.kanban_item_id = k.id AND s_act.status = 'in_progress'
                        ) THEN 1 ELSE 0 END) as uninspected_count,
                        SUM(CASE WHEN k.status = 'partial' THEN 1 ELSE 0 END) as partial_count,
                        SUM(CASE WHEN EXISTS (
                            SELECT 1 FROM inspection_sessions s_act WHERE s_act.kanban_item_id = k.id AND s_act.status = 'in_progress'
                        ) THEN 1 ELSE 0 END) as active_count,
                        SUM(CASE WHEN k.status != 'completed' AND EXISTS (
                            SELECT 1 FROM inspection_sessions s_act WHERE s_act.kanban_item_id = k.id AND s_act.status = 'in_progress'
                        ) THEN 1 ELSE 0 END) as in_progress_count,
                        SUM(CASE WHEN k.status = 'completed' THEN 1 ELSE 0 END) as completed_count,
                        SUM(CASE WHEN k.status = 'rejected' OR EXISTS (
                            SELECT 1 FROM inspection_sessions s_ng 
                            JOIN inspection_session_lots isl_ng ON isl_ng.inspection_session_id = s_ng.id
                            WHERE s_ng.kanban_item_id = k.id AND (isl_ng.lot_result = 'rejected' OR isl_ng.lot_result = 'ng')
                        ) THEN 1 ELSE 0 END) as rejected_count
                    FROM kanban_items k
                    LEFT JOIN kanban_batches b ON b.id = k.batch_id
                    WHERE {$kpiWhereSql}
                ");
                $stmtKpi->execute($kpiParams);
                $kRow = $stmtKpi->fetch(PDO::FETCH_ASSOC);
                if ($kRow) {
                    $kpiStats['total']       = (int)($kRow['total_count'] ?? 0);
                    $kpiStats['uninspected'] = (int)($kRow['uninspected_count'] ?? 0);
                    $kpiStats['partial']     = (int)($kRow['partial_count'] ?? 0);
                    $kpiStats['uncompleted'] = $kpiStats['uninspected'] + $kpiStats['partial'];
                    $kpiStats['active']      = (int)($kRow['active_count'] ?? 0);
                    $kpiStats['in_progress'] = (int)($kRow['in_progress_count'] ?? 0);
                    $kpiStats['completed']   = (int)($kRow['completed_count'] ?? 0);
                    $kpiStats['remain']      = (int)$pendingYesterdayCount;
                    $kpiStats['rejected']    = (int)($kRow['rejected_count'] ?? 0);
                }

                // Hitung sesi in_progress global (termasuk kanban remain/kemarin) agar kartu Sedang Dikerjakan selalu akurat
                try {
                    $stmtGlobalActive = $pdo->prepare("
                        SELECT COUNT(DISTINCT k.id)
                        FROM kanban_items k
                        JOIN inspection_sessions s_act ON s_act.kanban_item_id = k.id AND s_act.status = 'in_progress'
                        WHERE (k.plan_type IS NULL OR k.plan_type = 'kanban')
                          AND (k.check_type IS NULL OR k.check_type != 'Safety Stock')
                          AND (k.kanban_no IS NULL OR k.kanban_no NOT LIKE 'SS-%')
                          AND (k.str_loc IS NULL OR k.str_loc NOT IN ('WH-SS-OVERFLOW', 'WH-SS-SPLIT'))
                          AND k.status != 'completed'
                    ");
                    $stmtGlobalActive->execute();
                    $globalActiveCount = (int)$stmtGlobalActive->fetchColumn();
                    if ($globalActiveCount > $kpiStats['active']) {
                        $kpiStats['active'] = $globalActiveCount;
                    }
                    if ($globalActiveCount > $kpiStats['in_progress']) {
                        $kpiStats['in_progress'] = $globalActiveCount;
                    }
                } catch (Exception $eGa) {}
            } catch (Exception $eKpi) {}

            // 2.B Hitung Statistik Personal User Hari Ini (Kanban Selesai, Total Lot Box, Passed, NG)
            // SARGABLE Index: Menggunakan range datetime awal dan akhir hari
            $userDailyStats = [
                'inspector_name'    => $currentUser['name'] ?? 'Inspector QC',
                'completed_kanban'  => 0,
                'total_lots'        => 0,
                'passed_lots'       => 0,
                'ng_lots'           => 0
            ];
            try {
                // Jumlah Kanban yang diselesaikan oleh user ini hari ini
                $stmtUserKb = $pdo->prepare("
                    SELECT COUNT(DISTINCT s.kanban_item_id)
                    FROM inspection_sessions s
                    WHERE s.inspector_id = :uid
                      AND s.status = 'completed'
                      AND s.kanban_item_id IS NOT NULL
                      AND (
                          (s.finished_at >= :usr_start AND s.finished_at <= :usr_end)
                          OR (s.finished_at IS NULL AND s.created_at >= :usr_start2 AND s.created_at <= :usr_end2)
                      )
                ");
                $stmtUserKb->execute([
                    ':uid'        => $currentUserId,
                    ':usr_start'  => $todayStart,
                    ':usr_end'    => $todayEnd,
                    ':usr_start2' => $todayStart,
                    ':usr_end2'   => $todayEnd
                ]);
                $userDailyStats['completed_kanban'] = (int)$stmtUserKb->fetchColumn();

                // Total Lot box yang discan & diinspeksi oleh user ini hari ini (Passed vs NG)
                $stmtUserLots = $pdo->prepare("
                    SELECT 
                        COUNT(isl.id) as total_lots,
                        SUM(CASE WHEN isl.lot_result IN ('passed', 'skipped') THEN 1 ELSE 0 END) as passed_lots,
                        SUM(CASE WHEN isl.lot_result IN ('rejected', 'ng') THEN 1 ELSE 0 END) as ng_lots
                    FROM inspection_session_lots isl
                    JOIN inspection_sessions s ON s.id = isl.inspection_session_id
                    WHERE s.inspector_id = :uid
                      AND (
                          (isl.created_at >= :lot_start AND isl.created_at <= :lot_end)
                          OR (s.started_at >= :lot_start2 AND s.started_at <= :lot_end2)
                      )
                ");
                $stmtUserLots->execute([
                    ':uid'        => $currentUserId,
                    ':lot_start'  => $todayStart,
                    ':lot_end'    => $todayEnd,
                    ':lot_start2' => $todayStart,
                    ':lot_end2'   => $todayEnd
                ]);
                $lotRow = $stmtUserLots->fetch(PDO::FETCH_ASSOC);
                if ($lotRow) {
                    $userDailyStats['total_lots']  = (int)($lotRow['total_lots'] ?? 0);
                    $userDailyStats['passed_lots'] = (int)($lotRow['passed_lots'] ?? 0);
                    $userDailyStats['ng_lots']     = (int)($lotRow['ng_lots'] ?? 0);
                }
            } catch (Exception $eUsrStats) {}
        }

        // 3. Susun query Kanban Items
        $where = [
            "(k.plan_type IS NULL OR k.plan_type = 'kanban')",
            "(k.check_type IS NULL OR k.check_type != 'Safety Stock')",
            "(k.kanban_no IS NULL OR k.kanban_no NOT LIKE 'SS-%')"
        ];
        $params = [];

        $selCondWhere = "";
        $selCondHaving = "";

        if ($isSearchMode) {
            $cleanSearch = ltrim($searchQuery, '#');
            $cleanLower = strtolower($cleanSearch);

            // FAST-PATH: Cek Exact Match terlebih dahulu (misal part code = '1' atau kanban_no = '1')
            // Menjamin pencarian instan (< 10ms) dan akurat tanpa salah part
            $exactWhere = $where;
            $exactWhere[] = "(k.item_code = :exact_code OR k.kanban_no = :exact_kb)";
            $exactWhereSql = implode(' AND ', $exactWhere);

            $stmtExact = $pdo->prepare("
                SELECT k.id FROM kanban_items k 
                WHERE {$exactWhereSql} 
                ORDER BY CASE WHEN k.status = 'completed' THEN 1 ELSE 0 END ASC, COALESCE(k.eta, k.req_date, k.created_at) ASC, k.id ASC LIMIT 10
            ");
            $stmtExact->execute([':exact_code' => $cleanSearch, ':exact_kb' => $cleanSearch]);
            $exactIds = $stmtExact->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($exactIds)) {
                // Ada EXACT MATCH! Batasi HANYA pada ID exact ini agar query super cepat
                $inExactIds = implode(',', array_map('intval', $exactIds));
                $where[] = "k.id IN ({$inExactIds})";
                $orderSel = "CASE WHEN k.item_code = :exact_ord1 OR k.kanban_no = :exact_ord2 THEN 0 ELSE 1 END ASC, CASE WHEN k.status = 'completed' THEN 1 ELSE 0 END ASC, COALESCE(k.eta, k.req_date, k.created_at) ASC, k.id ASC";
                $params[':exact_ord1'] = $cleanSearch;
                $params[':exact_ord2'] = $cleanSearch;
                $queryLimit = 25;
            } else {
                // Fallback: Prefix / Partial Match berbobot
                $where[] = "(k.item_code LIKE :search_prefix_code OR k.kanban_no LIKE :search_prefix_kb OR k.item_code LIKE :search_like_code OR k.kanban_no LIKE :search_like_kb)";
                $params[':search_prefix_code'] = $cleanSearch . '%';
                $params[':search_prefix_kb']   = $cleanSearch . '%';
                $params[':search_like_code']   = '%' . $cleanSearch . '%';
                $params[':search_like_kb']     = '%' . $cleanSearch . '%';
                $orderSel = "CASE 
                    WHEN k.item_code LIKE :ord_p1 OR k.kanban_no LIKE :ord_p2 THEN 0 
                    ELSE 1 
                END ASC, CASE WHEN k.status = 'completed' THEN 1 ELSE 0 END ASC, COALESCE(k.eta, k.req_date, k.created_at) ASC, k.id ASC";
                $params[':ord_p1'] = $cleanSearch . '%';
                $params[':ord_p2'] = $cleanSearch . '%';
                $queryLimit = 40;
            }
        } else {
            // Urutan default: jika scope 'today' dan batch 'all', tampilkan Batch 1 terlebih dahulu, baru Batch 2, dst.
            if ($scopeFilter === 'today' && $batchFilter === 'all') {
                $orderSel = "b.imported_at ASC, b.id ASC, COALESCE(k.eta, k.req_date, '9999-12-31') ASC, k.id ASC";
            } else {
                $orderSel = "COALESCE(k.eta, k.req_date, '9999-12-31') ASC, k.id DESC";
            }

            if ($selectedId > 0) {
                $params[':sel_id_1'] = $selectedId;
                $params[':sel_id_2'] = $selectedId;
                $params[':sel_id_3'] = $selectedId;
                $selCondWhere = " OR k.id = :sel_id_1";
                $selCondHaving = " OR k.id = :sel_id_2";
                $orderSel = "CASE WHEN k.id = :sel_id_3 THEN 0 ELSE 1 END ASC, " . $orderSel;
            }

            if ($scopeFilter === 'pending_yesterday') {
                $where[] = "((b.imported_at < :py_target_date) OR (b.imported_at IS NULL AND k.created_at < :py_target_date2)) AND k.status IN ('uninspected', 'in_progress', 'partial', 'rejected')";
                $params[':py_target_date']  = $todayStart;
                $params[':py_target_date2'] = $todayStart;
            } elseif ($scopeFilter === 'today') {
                $where[] = "(((b.imported_at >= :today_start AND b.imported_at <= :today_end) OR (b.imported_at IS NULL AND k.created_at >= :today_start2 AND k.created_at <= :today_end2)) OR EXISTS (SELECT 1 FROM inspection_sessions s_act WHERE s_act.kanban_item_id = k.id AND s_act.status = 'in_progress'))";
                $params[':today_start']  = $todayStart;
                $params[':today_end']    = $todayEnd;
                $params[':today_start2'] = $todayStart;
                $params[':today_end2']   = $todayEnd;
                if ($batchFilter !== 'all' && is_numeric($batchFilter)) {
                    $where[] = "(k.batch_id = :filter_batch_id OR EXISTS (SELECT 1 FROM inspection_sessions s_act2 WHERE s_act2.kanban_item_id = k.id AND s_act2.status = 'in_progress'))";
                    $params[':filter_batch_id'] = (int)$batchFilter;
                }
            } elseif ($batchFilter !== 'all' && is_numeric($batchFilter)) {
                $where[] = "k.batch_id = :filter_batch_id";
                $params[':filter_batch_id'] = (int)$batchFilter;
            }

            $queryLimit = 250;
        }

        // Tampilkan item yang belum completed ATAU merupakan item yang sedang dipilih
        $whereSql = implode(' AND ', $where);

        // OPTIMIZED: Evaluasi kalkulasi quantity passed dipersingkat jika k.status = 'completed'
        $sql = "
            SELECT k.*, 
                   COALESCE(NULLIF(mp.part_name, ''), k.item_description) as display_part_name,
                   b.document_number, b.vendor, b.imported_at as batch_imported_at, b.plan_type as batch_plan_type,
                   0 AS avail_ss_qty,
                   CASE 
                       WHEN k.status = 'completed' THEN k.qty
                       ELSE COALESCE((SELECT SUM(
                           CASE 
                               WHEN EXISTS (SELECT 1 FROM inspection_session_lots isl_c WHERE isl_c.inspection_session_id = s.id) THEN
                                   GREATEST(0, COALESCE((SELECT SUM(CASE WHEN isl2.lot_result IN ('passed', 'skipped') AND (isl2.lot_status IS NULL OR isl2.lot_status != 'replaced') THEN isl2.qty ELSE 0 END)
                                             FROM inspection_session_lots isl2 WHERE isl2.inspection_session_id = s.id), 0) - COALESCE(s.excess_qty, 0))
                               WHEN s.status = 'passed' THEN
                                   GREATEST(0, (s.total_scanned_qty - COALESCE(s.excess_qty, 0)))
                               ELSE 0
                           END
                       ) FROM inspection_sessions s WHERE s.kanban_item_id = k.id), 0)
                   END AS total_passed_qty,
                   (SELECT s_act.id 
                    FROM inspection_sessions s_act 
                    WHERE s_act.kanban_item_id = k.id AND s_act.status = 'in_progress' 
                    ORDER BY s_act.id DESC LIMIT 1) as active_session_id,
                   (SELECT u_act.name 
                    FROM inspection_sessions s_act2 
                    JOIN users u_act ON u_act.id = s_act2.inspector_id 
                    WHERE s_act2.kanban_item_id = k.id AND s_act2.status = 'in_progress' 
                    ORDER BY s_act2.id DESC LIMIT 1) as active_inspector_name,
                    (SELECT 1 FROM inspection_sessions s_my WHERE s_my.kanban_item_id = k.id AND s_my.inspector_id = :my_uid LIMIT 1) as is_my_kanban
            FROM kanban_items k
            JOIN kanban_batches b ON b.id = k.batch_id
            LEFT JOIN master_parts mp ON mp.part_code = k.item_code
            WHERE ({$whereSql}{$selCondWhere})
            ORDER BY CASE WHEN k.status = 'completed' OR total_passed_qty >= k.qty THEN 1 ELSE 0 END ASC, {$orderSel}
            LIMIT {$queryLimit}
        ";

        $params[':my_uid'] = $currentUserId;
        $stmtPlan = $pdo->prepare($sql);
        $stmtPlan->execute($params);
        $items = $stmtPlan->fetchAll(PDO::FETCH_ASSOC);

        // Buat mapping batch_id => batch_number (1, 2, 3...)
        $batchNumMap = [];
        if (!empty($todayBatches)) {
            foreach ($todayBatches as $idx => $tb) {
                $batchNumMap[$tb['id']] = $idx + 1;
            }
        }
        foreach ($items as &$itm) {
            $bId = $itm['batch_id'] ?? null;
            $itm['batch_number'] = ($bId && isset($batchNumMap[$bId])) ? $batchNumMap[$bId] : null;
        }
        unset($itm);
    } catch (PDOException $ePlan) {
        $items = [];
    }
    echo json_encode([
        'success' => true,
        'today_batches' => $todayBatches,
        'pending_yesterday_count' => $pendingYesterdayCount,
        'kpi_stats' => $kpiStats ?? [],
        'user_daily_stats' => $userDailyStats ?? [],
        'scope' => $scopeFilter,
        'batch_id' => $batchFilter,
        'items' => $items
    ]);
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
                // Resolve drawings: Prioritaskan uploads/parts/{PartCode}/ lalu validasi fisik file
                $fsDrawings = get_part_drawing_assets($session['part_code'] ?? '', $session['part_model'] ?? '');
                
                // Gunakan hasil fsDrawings jika ditemukan
                $session['drawing_2d_path'] = $fsDrawings['drawing_2d_path'] ?? null;
                $session['drawing_3d_path'] = $fsDrawings['drawing_3d_path'] ?? null;

                // Jika fsDrawings tidak menemukan, cek apakah path lama di DB benar-benar ada di disk
                $rootAppDir = realpath(__DIR__ . '/../..');
                if (!$session['drawing_2d_path'] && !empty($session['drawing_2d_path'])) {
                    $check2d = $rootAppDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $session['drawing_2d_path']);
                    if (!file_exists($check2d)) {
                        $session['drawing_2d_path'] = null;
                    }
                }
                if (!$session['drawing_3d_path'] && !empty($session['drawing_3d_path'])) {
                    $check3d = $rootAppDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $session['drawing_3d_path']);
                    if (!file_exists($check3d)) {
                        $session['drawing_3d_path'] = null;
                    }
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
                                    if ((($pl['lot_result'] ?? '') === 'passed' || ($pl['lot_result'] ?? '') === 'skipped') && ($pl['lot_status'] ?? '') !== 'replaced') {
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

// Option 3: Fetch active parallel Kanban items for the same part code
$otherKanbanItems = [];
if ($session && !empty($session['kanban_item_id'])) {
    $currKbId = (int)$session['kanban_item_id'];
    $pCode = $session['kanban_item_code'] ?? ($session['part_code'] ?? '');
    if (!empty($pCode) && $pdo) {
        try {
            $stmtOtherKb = $pdo->prepare("
                SELECT k.id, k.kanban_no, k.customer, k.qty, k.status, k.eta, k.req_date
                FROM kanban_items k
                WHERE k.item_code = :pcode
                  AND k.id != :curr_kbid
                  AND k.status NOT IN ('completed')
                  AND (k.plan_type IS NULL OR k.plan_type = 'kanban')
                ORDER BY COALESCE(k.eta, k.req_date, k.created_at) ASC, k.id ASC
            ");
            $stmtOtherKb->execute([':pcode' => $pCode, ':curr_kbid' => $currKbId]);
            $otherKanbanItems = $stmtOtherKb->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $eOtherKb) {}
    }
}

// Prefetch initial auto kanban item jika kanban_id diteruskan di URL (agar langsung lompat ke Langkah 2)
$autoKanbanIdParam = (int)($_GET['kanban_id'] ?? $_GET['kanban_item_id'] ?? 0);
$initialAutoKanbanItem = null;
if ($autoKanbanIdParam > 0 && $pdo) {
    try {
        $stmtAutoKb = $pdo->prepare("
            SELECT k.*, 
                   COALESCE(NULLIF(mp.part_name, ''), k.item_description) as display_part_name,
                   b.document_number, b.vendor, b.imported_at as batch_imported_at, b.plan_type as batch_plan_type,
                   (SELECT COALESCE(SUM(
                       CASE 
                           WHEN EXISTS (SELECT 1 FROM inspection_session_lots isl_c WHERE isl_c.inspection_session_id = s.id) THEN
                               GREATEST(0, COALESCE((SELECT SUM(CASE WHEN isl2.lot_result IN ('passed', 'skipped') AND (isl2.lot_status IS NULL OR isl2.lot_status != 'replaced') THEN isl2.qty ELSE 0 END)
                                         FROM inspection_session_lots isl2 WHERE isl2.inspection_session_id = s.id), 0) - COALESCE(s.excess_qty, 0))
                           WHEN s.status = 'passed' THEN
                               GREATEST(0, (s.total_scanned_qty - COALESCE(s.excess_qty, 0)))
                           ELSE 0
                       END
                   ), 0)
                    FROM inspection_sessions s
                    WHERE s.kanban_item_id = k.id) AS total_passed_qty
            FROM kanban_items k
            JOIN kanban_batches b ON b.id = k.batch_id
            LEFT JOIN master_parts mp ON mp.part_code = k.item_code
            WHERE k.id = :auto_kid
            LIMIT 1
        ");
        $stmtAutoKb->execute([':auto_kid' => $autoKanbanIdParam]);
        $initialAutoKanbanItem = $stmtAutoKb->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $eAutoKb) {}
}

// Spectator / Read-Only Mode Detection
// Prinsip Otorisasi Mutlak:
// 1. Sesi milik orang lain (!isSessionOwner) -> OTOMATIS 100% Mode Pantau (Read-Only) bagi siapapun (termasuk Admin/Supervisor/Chief)
// 2. Sesi milik sendiri (isSessionOwner) -> SELALU Write/Owner Access penuh, TIDAK PERNAH dikunci ke Read-Only
$sessionInspectorName = 'QC Inspector';
$isSessionOwner = false;
$isPrivilegedUser = in_array($currentUserRole, ['admin', 'chief', 'supervisor', 'manager', 'leader'], true);

if (!empty($session['inspector_id'])) {
    try {
        $stmtInsp = $pdo->prepare("SELECT name FROM users WHERE id = :uid LIMIT 1");
        $stmtInsp->execute([':uid' => $session['inspector_id']]);
        $sessionInspectorName = $stmtInsp->fetchColumn() ?: 'QC Inspector';
    } catch (Exception $eInsp) {}

    $isSessionOwner = ((int)$session['inspector_id'] === $currentUserId);
} else {
    // Sesi belum ada inspector_id, user yang membuka otomatis dianggap owner/pengelola
    $isSessionOwner = true;
}

// Read-only hanya aktif jika sesi ini milik orang lain
$isReadOnlyView = !$isSessionOwner;

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
    width: 5px;
    height: 5px;
}
.slim-scrollbar::-webkit-scrollbar-track {
    background: rgba(241, 245, 249, 0.7);
    border-radius: 9999px;
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

    <?php 
    // Banner Jadwal Ganda di-disable sesuai instruksi user
    ?>

    <!-- Top Bar Navigation Header -->
    <?php require_once __DIR__ . '/components/session/topbar_header.php'; ?>

    <!-- Full-Screen 2-Column Split Workspace (100% Height, Zero Scroll) -->
    <div style="flex: 1; min-height: 0; display: flex; overflow: hidden; background-color: #f1f5f9; padding: 10px 14px 10px 14px; gap: 12px; box-sizing: border-box;">
        
        <!-- Left Main Panel: Modular Multi-Document Workbench Viewer (2D, 3D, PI, Q-Point, PAC, Standar Packing) -->
        <?php require_once __DIR__ . '/components/document_viewer_panel.php'; ?>

        <!-- Right Control Panel: Compact Zero-Scroll Inspection Panel (~420px Fixed) -->
        <div id="right-control-panel" style="width: 420px; flex-shrink: 0; height: 100%; display: flex; flex-direction: column; overflow-y: auto; box-sizing: border-box; padding-bottom: 30px;" class="pr-1 custom-scrollbar">
            <?php require_once __DIR__ . '/components/session/workbench_control_panel.php'; ?>
        </div>

    </div>

</div>

<!-- Modal Components -->
<?php require_once __DIR__ . '/components/session/modal_lot_defect.php'; ?>
<?php require_once __DIR__ . '/components/session/modal_lot_reinspection.php'; ?>
<?php require_once __DIR__ . '/components/session/modal_scan_wizard.php'; ?>
<?php require_once __DIR__ . '/components/session/modal_kanban_detail.php'; ?>
<?php require_once __DIR__ . '/components/session/modal_prev_history.php'; ?>
<?php require_once __DIR__ . '/components/session/modal_substitution_log.php'; ?>
<?php require_once __DIR__ . '/components/session/modal_batch_reinspection.php'; ?>

<!-- Global Workbench Configuration Injection (Bridge PHP to Modular JS) -->
<script>
window.OQC_CONFIG = {
    baseUrl: "<?= base_url() ?>",
    sessionId: <?= (int)($sessionId ?: 0) ?>,
    sessionPartCode: <?= json_encode($session['part_code'] ?? '') ?>,
    inspectionType: <?= json_encode($session['inspection_type'] ?? 'kanban') ?>,
    autoKanbanId: <?= (int)($_GET['kanban_id'] ?? $_GET['kanban_item_id'] ?? 0) ?>,
    initialAutoKanbanItem: <?= json_encode($initialAutoKanbanItem ?? null) ?>,
    autoScanType: <?= json_encode(sanitize($_GET['type'] ?? $_GET['plan_type'] ?? 'kanban')) ?>,
    prefilledScannedLot: <?= json_encode(sanitize($_GET['scanned_lot'] ?? $_GET['lot_number'] ?? '')) ?>,
    prefilledScannedRef: <?= json_encode(sanitize($_GET['scanned_ref'] ?? $_GET['ref_number'] ?? '')) ?>,
    prefilledPartCode: <?= json_encode(sanitize($_GET['part_code'] ?? '')) ?>,
    defectTypesList: <?= json_encode($defectTypes ?? []) ?>,
    showScanOverlay: <?= $showScanOverlay ? 'true' : 'false' ?>,
    isSessionOwner: <?= $isSessionOwner ? 'true' : 'false' ?>,
    isReadOnlyView: <?= $isReadOnlyView ? 'true' : 'false' ?>,
    currentUserName: <?= json_encode($currentUser['name'] ?? '') ?>,
    sessionData: <?= $session ? json_encode($session) : 'null' ?>
};
</script>

<!-- Modular Cached Client Workbench Application Script -->
<!-- Modular Cached Client Workbench Application Scripts -->
<script src="<?= base_url('assets/js/modules/inspection_workbench/01_core_scanner.js?v=' . filemtime(__DIR__ . '/../../assets/js/modules/inspection_workbench/01_core_scanner.js')) ?>"></script>
<script src="<?= base_url('assets/js/modules/inspection_workbench/02_wizard_planning.js?v=' . filemtime(__DIR__ . '/../../assets/js/modules/inspection_workbench/02_wizard_planning.js')) ?>"></script>
<script src="<?= base_url('assets/js/modules/inspection_workbench/03_workbench_box_flow.js?v=' . filemtime(__DIR__ . '/../../assets/js/modules/inspection_workbench/03_workbench_box_flow.js')) ?>"></script>
<script src="<?= base_url('assets/js/modules/inspection_workbench/04_workbench_defect.js?v=' . filemtime(__DIR__ . '/../../assets/js/modules/inspection_workbench/04_workbench_defect.js')) ?>"></script>
<script src="<?= base_url('assets/js/modules/inspection_workbench/05_workbench_reinspection.js?v=' . filemtime(__DIR__ . '/../../assets/js/modules/inspection_workbench/05_workbench_reinspection.js')) ?>"></script>
<script src="<?= base_url('assets/js/modules/inspection_workbench/06_workbench_chief_guards.js?v=' . filemtime(__DIR__ . '/../../assets/js/modules/inspection_workbench/06_workbench_chief_guards.js')) ?>"></script>

<!-- Real 3D CAD WebGL Engine Scripts (WebAssembly & Three.js) -->
<script>window.APP_BASE_URL = "<?= base_url() ?>";</script>
<script src="<?= base_url('assets/js/vendor/three.r128.min.js') ?>"></script>
<script src="<?= base_url('assets/js/vendor/OrbitControls.js') ?>"></script>
<script src="<?= base_url('assets/js/vendor/occt-import-js.js') ?>"></script>
<script src="<?= base_url('assets/js/cad_viewer.js') ?>"></script>
<script src="<?= base_url('assets/js/modules/inspection_doc_viewer.js?v=' . filemtime(__DIR__ . '/../../assets/js/modules/inspection_doc_viewer.js')) ?>"></script>
</body>
</html>
