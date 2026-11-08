<?php
/**
 * Modul Safety Stock - Monitoring Realisasi Safety Stock
 * Halaman pemantauan data barang Safety Stock / Internal Stock berbasis Per Part.
 * Dirancang ramah pengguna awam/operasional dengan 4 Kartu KPI Transparan dan 3 Tab Navigasi.
 */
$breadcrumbCategory = "OPERASIONAL";
$pageTitle = "Monitoring Safety Stock";
$pageSubtitle = "Daftar stok pengaman per part, ketersediaan box di gudang, dan alokasi pemotongan Kanban";

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

require_menu_access('safety_stock');

$currentUser = current_user();
$canManageLots = in_array($currentUser['role'] ?? '', ['admin', 'chief']);

$pdo = getDB();

// ── 1. Parameter Filter & Tab ────────────────────────────────────────────────
$period       = sanitize($_GET['period'] ?? '');
$startDate    = sanitize($_GET['start_date'] ?? '');
$endDate      = sanitize($_GET['end_date'] ?? '');
$search       = sanitize($_GET['search'] ?? '');
$statusFilter = sanitize($_GET['status'] ?? '');
$activeTab    = sanitize($_GET['tab'] ?? 'stok');
if (!in_array($activeTab, ['stok', 'sisa', 'riwayat'])) {
    $activeTab = 'stok';
}
$kpiFilter    = sanitize($_GET['kpi_filter'] ?? 'all');
if (!in_array($kpiFilter, ['all', 'normal', 'sisa', 'keluar', 'ready'])) {
    $kpiFilter = 'all';
}
$mutasiFilter = sanitize($_GET['mutasi'] ?? 'semua');
if (!in_array($mutasiFilter, ['semua', 'normal', 'sisa', 'keluar'])) {
    $mutasiFilter = 'semua';
}

// Menentukan periode jika kosong
if (empty($period)) {
    if (!empty($startDate) || !empty($endDate)) {
        $period = 'custom';
    } else {
        $period = 'semua';
    }
}

// Preset mapping filter tanggal cepat
if ($period === 'hari_ini') {
    $startDate = date('Y-m-d');
    $endDate   = date('Y-m-d');
} elseif ($period === 'mingguan') {
    $startDate = date('Y-m-d', strtotime('monday this week'));
    $endDate   = date('Y-m-d');
} elseif ($period === 'bulanan') {
    $startDate = date('Y-m-01');
    $endDate   = date('Y-m-d');
} elseif ($period === 'tahunan') {
    $startDate = date('Y-01-01');
    $endDate   = date('Y-m-d');
} elseif ($period === 'semua') {
    $startDate = '';
    $endDate   = '';
}

// Dynamic Limit & Page
$limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT) ?: (isset($_GET['limit']) ? (int)$_GET['limit'] : 10);
if (!in_array($limit, [5, 10, 25, 50, 100])) {
    $limit = 10;
}
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: (isset($_GET['page']) ? (int)$_GET['page'] : 1);
if ($page < 1) $page = 1;

/**
 * Helper: Ekstrak Qty Box Asli (Sebelum Dipotong) & Kuantiti Pemotongan Kanban
 * Digunakan untuk transparansi audit bagi user operasional & lapangan.
 */
if (!function_exists('parseSisaBoxOrigin')) {
    function parseSisaBoxOrigin($qrRaw, $sisaQty, $kRemark = '', $lRemark = '') {
        $origBoxQty   = 0;
        $usedQty      = 0;
        $isSplit      = false;
        $originSessId = null;

        // 1. Ekstrak Z3 dari QR raw scanner
        if (!empty($qrRaw)) {
            $parts = explode('|', $qrRaw);
            foreach ($parts as $p) {
                $p = trim($p);
                if (str_starts_with($p, 'Z3')) {
                    $v = (int)substr($p, 2);
                    if ($v > 0) {
                        $origBoxQty = $v;
                        break;
                    }
                }
            }
        }

        $combined = $kRemark . ' ' . $lRemark;

        // 2. Ekstrak jika ada catatan teks "setelah diambil X pcs"
        if (preg_match('/setelah diambil (\d+) pcs/i', $combined, $m)) {
            $usedQty = (int)$m[1];
            if ($origBoxQty <= 0) {
                $origBoxQty = $sisaQty + $usedQty;
            }
        }

        // 3. Ekstrak ID Sesi Asal Kanban
        if (preg_match('/dari Sesi #(\d+)/i', $combined, $mSess)) {
            $originSessId = (int)$mSess[1];
        } elseif (preg_match('/AUTO-SS-SESS-(\d+)/i', $combined, $mSess)) {
            $originSessId = (int)$mSess[1];
        }

        // 4. Hitung kuantiti yang terpakai jika box asli > sisa
        if ($origBoxQty > 0 && $origBoxQty > $sisaQty) {
            $isSplit = true;
            if ($usedQty <= 0) {
                $usedQty = $origBoxQty - $sisaQty;
            }
        } elseif ($origBoxQty > 0 && $sisaQty >= $origBoxQty) {
            $isSplit = false;
        }

        return [
            'orig_box_qty'   => $origBoxQty,
            'used_qty'       => $usedQty,
            'is_split'       => $isSplit,
            'origin_sess_id' => $originSessId
        ];
    }
}

// ── 2. Struktur Data Ringkasan (KPI) ─────────────────────────────────────────
$summary = [
    'total_parts'          => 0,
    // 1. Masuk Normal (IN)
    'normal_in_lots'       => 0,
    'normal_in_qty'        => 0,
    // 2. Masuk Sisa Scan Kanban (IN)
    'sisa_in_lots'         => 0,
    'sisa_in_qty'          => 0,
    // 3. Keluar Terpakai Kanban (OUT)
    'total_used_lots'      => 0,
    'total_used_qty'       => 0,
    // 4. Sisa di Gudang Saat Ini (READY)
    'total_available_lots' => 0,
    'total_available_qty'  => 0,
    // Stok NG / Reject
    'total_rejected_qty'   => 0,
    // Hitungan Khusus Tab Sisa
    'sisa_events_count'    => 0,
    'total_activities'     => 0
];

$allParts     = [];
$items        = [];
$totalItems   = 0;
$totalPages   = 1;

$sisaItems        = [];
$totalSisaItems   = 0;
$totalSisaPages   = 1;

$riwayatItems      = [];
$totalRiwayatItems = 0;
$totalRiwayatPages = 1;

if ($pdo) {
    try {
        // ── A. Membangun Kondisi WHERE Dasar ─────────────────────────────────
        $where = ["ss.inspection_type = 'safety_stock'"];
        $params = [];

        if (!empty($startDate) && !empty($endDate)) {
            $where[] = "(
                DATE(COALESCE(ss.started_at, did.created_at)) BETWEEN :sd AND :ed
                OR
                (ss.auto_fulfilled_by_session_id > 0 AND DATE(s_kanban.started_at) BETWEEN :sd2 AND :ed2)
            )";
            $params[':sd']  = $startDate;
            $params[':ed']  = $endDate;
            $params[':sd2'] = $startDate;
            $params[':ed2'] = $endDate;
        }

        if (!empty($search)) {
            // Deteksi & Parsing Barcode QR Label Scanner
            $qrPart = '';
            $qrLot  = '';
            $qrRef  = '';
            if (strpos($search, '|') !== false || str_starts_with($search, 'Z1') || str_starts_with($search, 'Z2') || str_starts_with($search, 'Z5')) {
                $parts = explode('|', $search);
                foreach ($parts as $p) {
                    $p = trim($p);
                    if (str_starts_with($p, 'Z1')) $qrPart = substr($p, 2);
                    if (str_starts_with($p, 'Z2')) $qrLot  = substr($p, 2);
                    if (str_starts_with($p, 'Z5')) $qrRef  = substr($p, 2);
                }
            }

            if (!empty($qrRef) || !empty($qrLot) || !empty($qrPart)) {
                $qrConds = [];
                if (!empty($qrPart)) {
                    $qrConds[] = "(did.part_code LIKE :qr_part OR ki.item_code LIKE :qr_part_ki)";
                    $params[':qr_part'] = '%' . $qrPart . '%';
                    $params[':qr_part_ki'] = '%' . $qrPart . '%';
                }
                if (!empty($qrLot)) {
                    $qrConds[] = "(did.lot_number LIKE :qr_lot OR EXISTS (
                        SELECT 1 FROM inspection_session_lots isl_qr 
                        WHERE isl_qr.inspection_session_id = ss.id 
                          AND isl_qr.lot_number LIKE :qr_lot_isl
                    ))";
                    $params[':qr_lot'] = '%' . $qrLot . '%';
                    $params[':qr_lot_isl'] = '%' . $qrLot . '%';
                }
                if (!empty($qrRef)) {
                    $qrConds[] = "EXISTS (
                        SELECT 1 FROM inspection_session_lots isl_ref 
                        WHERE isl_ref.inspection_session_id = ss.id 
                          AND isl_ref.ref_number LIKE :qr_ref
                    )";
                    $params[':qr_ref'] = '%' . $qrRef . '%';
                }
                $where[] = "(" . implode(' AND ', $qrConds) . ")";
            } else {
                $where[] = "(
                    did.part_code LIKE :s1 
                    OR did.part_name LIKE :s2 
                    OR ki.item_code LIKE :s3 
                    OR ki.item_description LIKE :s4
                    OR EXISTS (
                        SELECT 1 FROM inspection_session_lots isl_s 
                        WHERE isl_s.inspection_session_id = ss.id 
                          AND (isl_s.ref_number LIKE :s5 OR isl_s.lot_number LIKE :s6)
                    )
                )";
                $like = '%' . $search . '%';
                $params[':s1'] = $like;
                $params[':s2'] = $like;
                $params[':s3'] = $like;
                $params[':s4'] = $like;
                $params[':s5'] = $like;
                $params[':s6'] = $like;
            }
        }

        $whereStr = implode(" AND ", $where);

        // ── B. Query Pemakaian Memotong Kanban per Part ──────────────────────
        $kanbanUsageWhere = [
            "s_ss.inspection_type = 'safety_stock'",
            "s_ss.auto_fulfilled_by_session_id > 0"
        ];
        $kanbanUsageParams = [];
        if (!empty($startDate) && !empty($endDate)) {
            $kanbanUsageWhere[] = "DATE(s_k.started_at) BETWEEN :ku_sd AND :ku_ed";
            $kanbanUsageParams[':ku_sd'] = $startDate;
            $kanbanUsageParams[':ku_ed'] = $endDate;
        }
        $kanbanUsageWhereStr = implode(" AND ", $kanbanUsageWhere);

        $stKanbanUsage = $pdo->prepare("
            SELECT 
                UPPER(TRIM(COALESCE(did.part_code, ki.item_code, ''))) AS pcode,
                SUM(COALESCE(s_ss.total_scanned_qty, 0)) AS total_kanban_used_qty,
                COUNT(DISTINCT s_k.id) AS total_kanban_orders
            FROM inspection_sessions s_ss
            JOIN inspection_sessions s_k ON s_k.id = s_ss.auto_fulfilled_by_session_id
            LEFT JOIN daily_inspection_data did ON did.id = s_ss.did_id
            LEFT JOIN kanban_items ki ON ki.id = s_ss.kanban_item_id
            WHERE {$kanbanUsageWhereStr}
            GROUP BY UPPER(TRIM(COALESCE(did.part_code, ki.item_code, '')))
        ");
        $stKanbanUsage->execute($kanbanUsageParams);
        $kanbanUsageMap = [];
        while ($uRow = $stKanbanUsage->fetch(PDO::FETCH_ASSOC)) {
            $kanbanUsageMap[$uRow['pcode']] = $uRow;
        }

        // ── C. Hitung Box Fisik per Part (Membedakan Normal vs Sisa) ──────────
        $boxesWhere = [
            "ss.inspection_type = 'safety_stock'",
            "(isl.lot_status IS NULL OR isl.lot_status IN ('ok', 'reinspected', 'passed', ''))",
            "(isl.lot_result IS NULL OR isl.lot_result != 'rejected')"
        ];
        $boxesParams = [];
        if (!empty($startDate) && !empty($endDate)) {
            $boxesWhere[] = "(
                DATE(COALESCE(ss.started_at, did.created_at)) BETWEEN :b_sd AND :b_ed
                OR
                (ss.auto_fulfilled_by_session_id > 0 AND DATE(s_kanban.started_at) BETWEEN :b_sd2 AND :b_ed2)
            )";
            $boxesParams[':b_sd']  = $startDate;
            $boxesParams[':b_ed']  = $endDate;
            $boxesParams[':b_sd2'] = $startDate;
            $boxesParams[':b_ed2'] = $endDate;
        }
        $boxesWhereStr = implode(" AND ", $boxesWhere);

        $stmtBoxes = $pdo->prepare("
            SELECT 
                UPPER(TRIM(COALESCE(did.part_code, ki.item_code, ''))) AS pcode,
                COUNT(CASE 
                    WHEN ss.status = 'passed' AND COALESCE(ss.ss_source, 'direct') = 'direct'
                    THEN isl.id 
                    ELSE NULL 
                END) AS normal_boxes,
                COUNT(CASE 
                    WHEN ss.status = 'passed' AND COALESCE(ss.ss_source, 'direct') IN ('kanban_excess', 'split_leftover')
                    THEN isl.id 
                    ELSE NULL 
                END) AS sisa_boxes,
                COUNT(CASE WHEN ss.status = 'passed' THEN isl.id ELSE NULL END) AS total_boxes,
                COUNT(CASE 
                    WHEN (ss.auto_fulfilled_by_session_id IS NULL OR ss.auto_fulfilled_by_session_id = 0) 
                         AND ss.status = 'passed'
                    THEN isl.id 
                    ELSE NULL 
                END) AS active_boxes,
                COUNT(CASE WHEN ss.status = 'rejected' THEN isl.id ELSE NULL END) AS rejected_boxes
            FROM inspection_session_lots isl
            JOIN inspection_sessions ss ON ss.id = isl.inspection_session_id
            LEFT JOIN daily_inspection_data did ON did.id = ss.did_id
            LEFT JOIN kanban_items ki ON ki.id = ss.kanban_item_id
            LEFT JOIN inspection_sessions s_kanban ON s_kanban.id = ss.auto_fulfilled_by_session_id
            WHERE {$boxesWhereStr}
            GROUP BY UPPER(TRIM(COALESCE(did.part_code, ki.item_code, '')))
        ");
        $stmtBoxes->execute($boxesParams);
        $boxMap = [];
        while ($bRow = $stmtBoxes->fetch(PDO::FETCH_ASSOC)) {
            $boxMap[$bRow['pcode']] = $bRow;
        }

        // ── D. Hitung Lot Terpakai Kanban per Part ───────────────────────────
        $usedWhere = [
            "ss.inspection_type = 'safety_stock'",
            "ss.auto_fulfilled_by_session_id > 0"
        ];
        $usedParams = [];
        if (!empty($startDate) && !empty($endDate)) {
            $usedWhere[] = "DATE(s_kanban.started_at) BETWEEN :u_sd AND :u_ed";
            $usedParams[':u_sd'] = $startDate;
            $usedParams[':u_ed'] = $endDate;
        }
        $usedWhereStr = implode(" AND ", $usedWhere);

        $stUsedLots = $pdo->prepare("
            SELECT 
                UPPER(TRIM(COALESCE(did.part_code, ki.item_code, ''))) AS pcode,
                COUNT(DISTINCT CONCAT(COALESCE(NULLIF(TRIM(isl.ref_number), ''), CONCAT('NOREF-', isl.id)), '_', COALESCE(TRIM(isl.lot_number), 'NOLOT'))) AS used_lots
            FROM inspection_session_lots isl
            JOIN inspection_sessions ss ON ss.id = isl.inspection_session_id
            LEFT JOIN daily_inspection_data did ON did.id = ss.did_id
            LEFT JOIN kanban_items ki ON ki.id = ss.kanban_item_id
            LEFT JOIN inspection_sessions s_kanban ON s_kanban.id = ss.auto_fulfilled_by_session_id
            WHERE {$usedWhereStr}
            GROUP BY UPPER(TRIM(COALESCE(did.part_code, ki.item_code, '')))
        ");
        $stUsedLots->execute($usedParams);
        $usedLotsMap = [];
        while ($uRow = $stUsedLots->fetch(PDO::FETCH_ASSOC)) {
            $usedLotsMap[$uRow['pcode']] = (int)$uRow['used_lots'];
        }

        // ── E. Query Utama Stok Dikelompokkan per Part ────────────────────────
        $groupedSql = "SELECT 
                        UPPER(COALESCE(did.part_code, ki.item_code, '-')) AS part_code,
                        MIN(COALESCE(did.part_name, ki.item_description, 'Part Safety Stock')) AS part_name,
                        MIN(COALESCE(ki.customer, 'INTERNAL SAFETY STOCK')) AS customer,
                        MAX(ss.started_at) AS last_started_at,
                        MAX(ss.id) AS max_session_id,
                        -- Masuk Normal Lolos (Passed)
                        SUM(CASE 
                            WHEN COALESCE(ss.ss_source, 'direct') = 'direct' AND ss.status = 'passed'
                            THEN ss.total_scanned_qty 
                            ELSE 0 
                        END) AS normal_in_qty,
                        -- Masuk Sisa Kanban Lolos (Passed)
                        SUM(CASE 
                            WHEN COALESCE(ss.ss_source, 'direct') IN ('kanban_excess', 'split_leftover') AND ss.status = 'passed'
                            THEN ss.total_scanned_qty 
                            ELSE 0 
                        END) AS sisa_in_qty,
                        -- Kuantiti Reject (NG)
                        SUM(CASE 
                            WHEN ss.status = 'rejected' 
                            THEN ss.total_scanned_qty 
                            ELSE 0 
                        END) AS rejected_qty,
                        -- Sisa yang belum memotong kanban & passed (Ready di rak)
                        SUM(CASE 
                            WHEN (ss.auto_fulfilled_by_session_id IS NULL OR ss.auto_fulfilled_by_session_id = 0) AND ss.status = 'passed' 
                            THEN ss.total_scanned_qty 
                            ELSE 0 
                        END) AS available_qty_raw,
                        CASE 
                            WHEN SUM(CASE WHEN ss.status = 'in_progress' THEN 1 ELSE 0 END) > 0 THEN 'in_progress'
                            WHEN SUM(CASE WHEN (ss.auto_fulfilled_by_session_id IS NULL OR ss.auto_fulfilled_by_session_id = 0) AND ss.status = 'passed' THEN ss.total_scanned_qty ELSE 0 END) > 0 THEN 'available'
                            WHEN SUM(CASE WHEN ss.status = 'rejected' THEN 1 ELSE 0 END) > 0 THEN 'rejected'
                            ELSE 'exhausted'
                        END AS part_status,
                        COUNT(DISTINCT ss.id) AS session_count
                       FROM inspection_sessions ss
                       LEFT JOIN daily_inspection_data did ON did.id = ss.did_id
                       LEFT JOIN kanban_items ki ON ki.id = ss.kanban_item_id
                       LEFT JOIN inspection_sessions s_kanban ON s_kanban.id = ss.auto_fulfilled_by_session_id
                       WHERE {$whereStr}
                       GROUP BY UPPER(COALESCE(did.part_code, ki.item_code, '-'))
                       ORDER BY 
                          CASE 
                              WHEN SUM(CASE WHEN (ss.auto_fulfilled_by_session_id IS NULL OR ss.auto_fulfilled_by_session_id = 0) AND ss.status = 'passed' THEN ss.total_scanned_qty ELSE 0 END) > 0 THEN 1
                              WHEN SUM(CASE WHEN ss.status = 'in_progress' THEN 1 ELSE 0 END) > 0 THEN 2
                              WHEN SUM(CASE WHEN ss.status = 'rejected' THEN 1 ELSE 0 END) > 0 THEN 3
                              ELSE 4 
                          END ASC,
                          MAX(ss.id) DESC";

        $stAll = $pdo->prepare($groupedSql);
        $stAll->execute($params);
        $allParts = $stAll->fetchAll(PDO::FETCH_ASSOC);

        // Hitung Kuantiti Realistis & Akumulasi untuk Setiap Part
        foreach ($allParts as &$p) {
            $pCode       = strtoupper(trim($p['part_code']));
            $normalIn    = (int)$p['normal_in_qty'];
            $sisaIn      = (int)$p['sisa_in_qty'];
            $rejectedQty = (int)$p['rejected_qty'];
            $totalIn     = $normalIn + $sisaIn;
            $usedQty     = (int)($kanbanUsageMap[$pCode]['total_kanban_used_qty'] ?? 0);
            $availQty    = max(0, $totalIn - $usedQty);

            $p['normal_in_qty']  = $normalIn;
            $p['sisa_in_qty']    = $sisaIn;
            $p['rejected_qty']   = $rejectedQty;
            $p['total_in_qty']   = $totalIn;
            $p['total_used_qty'] = $usedQty;
            $p['available_qty']  = $availQty;
            if ($availQty > 0) {
                $p['part_status'] = 'available';
            } elseif ($rejectedQty > 0 && $usedQty == 0) {
                $p['part_status'] = 'rejected';
            } elseif ($usedQty > 0) {
                $p['part_status'] = 'exhausted';
            } else {
                $p['part_status'] = 'available';
            }
        }
        unset($p);

        // ── F. TAB 2: DATA KHUSUS SISA SCAN KANBAN (EXCESS) ──────────────────
        // Mengambil seluruh kejadian sisa scan Kanban untuk menjawab: berapa kali, apa saja, kapan saja
        $sisaWhere = [
            "ss.inspection_type = 'safety_stock'",
            "COALESCE(ss.ss_source, 'direct') IN ('kanban_excess', 'split_leftover')"
        ];
        $sisaParams = [];

        if (!empty($startDate) && !empty($endDate)) {
            $sisaWhere[] = "DATE(COALESCE(ss.started_at, isl.created_at)) BETWEEN :sisa_sd AND :sisa_ed";
            $sisaParams[':sisa_sd'] = $startDate;
            $sisaParams[':sisa_ed'] = $endDate;
        }

        if (!empty($search)) {
            $sisaWhere[] = "(
                did.part_code LIKE :ss1 
                OR did.part_name LIKE :ss2 
                OR ki.item_code LIKE :ss3 
                OR isl.lot_number LIKE :ss4 
                OR isl.ref_number LIKE :ss5
            )";
            $like = '%' . $search . '%';
            $sisaParams[':ss1'] = $like;
            $sisaParams[':ss2'] = $like;
            $sisaParams[':ss3'] = $like;
            $sisaParams[':ss4'] = $like;
            $sisaParams[':ss5'] = $like;
        }

        $sisaWhereStr = implode(" AND ", $sisaWhere);
        $sqlSisa = "
            SELECT 
                ss.id AS session_id,
                ss.started_at,
                ss.status,
                ss.auto_fulfilled_by_session_id,
                COALESCE(did.part_code, ki.item_code) AS part_code,
                COALESCE(did.part_name, ki.item_description, 'Part Safety Stock') AS part_name,
                COALESCE(isl.lot_number, did.lot_number, '-') AS lot_number,
                COALESCE(isl.ref_number, '-') AS ref_number,
                COALESCE(isl.qty, ss.total_scanned_qty, 0) AS sisa_qty,
                isl.remarks AS lot_remarks,
                ki.remark AS kanban_remark,
                isl.scanned_qr_raw
            FROM inspection_sessions ss
            LEFT JOIN daily_inspection_data did ON did.id = ss.did_id
            LEFT JOIN kanban_items ki ON ki.id = ss.kanban_item_id
            LEFT JOIN inspection_session_lots isl ON isl.inspection_session_id = ss.id
            WHERE {$sisaWhereStr}
            ORDER BY ss.id DESC
        ";
        $stSisa = $pdo->prepare($sqlSisa);
        $stSisa->execute($sisaParams);
        $allSisaData = $stSisa->fetchAll(PDO::FETCH_ASSOC);
        $summary['sisa_events_count'] = count($allSisaData);

        // ── G. TAB 3: DATA LOG RIWAYAT AKTIVITAS (MUTASI KELUAR - MASUK) ─────
        // Menggabungkan mutasi Masuk Normal, Masuk Sisa, dan Keluar Terpakai secara sinkron
        $allActivities = [];

        // 1. Aktivitas Masuk Sisa (dari $allSisaData yang sudah difilter periode/search)
        foreach ($allSisaData as $sd) {
            $allActivities[] = [
                'timestamp' => $sd['started_at'],
                'type'      => 'masuk_sisa',
                'part_code' => $sd['part_code'],
                'part_name' => $sd['part_name'],
                'lot'       => $sd['lot_number'],
                'ref'       => $sd['ref_number'],
                'qty'       => (int)$sd['sisa_qty'],
                'note'      => $sd['lot_remarks'] ?: $sd['kanban_remark'],
                'session_id'=> $sd['session_id']
            ];
        }

        // 2. Aktivitas Masuk Normal (Sesuai Filter Periode & Search, tanpa batasan LIMIT yang memotong data)
        $normalActWhere = [
            "ss.inspection_type = 'safety_stock'",
            "ss.status = 'passed'",
            "COALESCE(ss.ss_source, 'direct') = 'direct'",
            "(isl.lot_status IS NULL OR isl.lot_status != 'action_closed')"
        ];
        $normalActParams = [];
        if (!empty($startDate) && !empty($endDate)) {
            $normalActWhere[] = "DATE(COALESCE(ss.started_at, did.created_at)) BETWEEN :na_sd AND :na_ed";
            $normalActParams[':na_sd'] = $startDate;
            $normalActParams[':na_ed'] = $endDate;
        }
        if (!empty($search)) {
            $normalActWhere[] = "(did.part_code LIKE :na_s1 OR did.part_name LIKE :na_s2 OR ki.item_code LIKE :na_s3 OR isl.lot_number LIKE :na_s4 OR isl.ref_number LIKE :na_s5)";
            $like = '%' . $search . '%';
            $normalActParams[':na_s1'] = $like;
            $normalActParams[':na_s2'] = $like;
            $normalActParams[':na_s3'] = $like;
            $normalActParams[':na_s4'] = $like;
            $normalActParams[':na_s5'] = $like;
        }
        $normalActWhereStr = implode(" AND ", $normalActWhere);

        $stNormalAct = $pdo->prepare("
            SELECT 
                ss.id AS session_id,
                ss.started_at,
                COALESCE(did.part_code, ki.item_code) AS part_code,
                COALESCE(did.part_name, ki.item_description, 'Part Safety Stock') AS part_name,
                COALESCE(isl.lot_number, did.lot_number, '-') AS lot_number,
                COALESCE(isl.ref_number, '-') AS ref_number,
                COALESCE(isl.qty, ss.total_scanned_qty, 0) AS qty
            FROM inspection_sessions ss
            LEFT JOIN daily_inspection_data did ON did.id = ss.did_id
            LEFT JOIN kanban_items ki ON ki.id = ss.kanban_item_id
            LEFT JOIN inspection_session_lots isl ON isl.inspection_session_id = ss.id
            WHERE {$normalActWhereStr}
            ORDER BY ss.started_at DESC, ss.id DESC
        ");
        $stNormalAct->execute($normalActParams);
        while ($nRow = $stNormalAct->fetch(PDO::FETCH_ASSOC)) {
            $allActivities[] = [
                'timestamp' => $nRow['started_at'],
                'type'      => 'masuk_normal',
                'part_code' => $nRow['part_code'],
                'part_name' => $nRow['part_name'],
                'lot'       => $nRow['lot_number'],
                'ref'       => $nRow['ref_number'],
                'qty'       => (int)$nRow['qty'],
                'note'      => 'Hasil inspeksi OQC Safety Stock (Masuk Normal)',
                'session_id'=> $nRow['session_id']
            ];
        }

        // 3. Aktivitas Keluar Kanban (DEDICATED QUERY - 100% SINKRON DENGAN 161 LOT KELUAR)
        $keluarActWhere = [
            "ss.inspection_type = 'safety_stock'",
            "ss.auto_fulfilled_by_session_id > 0"
        ];
        $keluarActParams = [];
        if (!empty($startDate) && !empty($endDate)) {
            $keluarActWhere[] = "DATE(COALESCE(s_k.started_at, ss.started_at)) BETWEEN :ka_sd AND :ka_ed";
            $keluarActParams[':ka_sd'] = $startDate;
            $keluarActParams[':ka_ed'] = $endDate;
        }
        if (!empty($search)) {
            $keluarActWhere[] = "(did.part_code LIKE :ka_s1 OR did.part_name LIKE :ka_s2 OR ki.item_code LIKE :ka_s3 OR isl.lot_number LIKE :ka_s4 OR isl.ref_number LIKE :ka_s5 OR ki_k.kanban_no LIKE :ka_s6)";
            $like = '%' . $search . '%';
            $keluarActParams[':ka_s1'] = $like;
            $keluarActParams[':ka_s2'] = $like;
            $keluarActParams[':ka_s3'] = $like;
            $keluarActParams[':ka_s4'] = $like;
            $keluarActParams[':ka_s5'] = $like;
            $keluarActParams[':ka_s6'] = $like;
        }
        $keluarActWhereStr = implode(" AND ", $keluarActWhere);

        $stKeluarAct = $pdo->prepare("
            SELECT 
                ss.id AS session_id,
                COALESCE(s_k.started_at, ss.started_at) AS timestamp_keluar,
                COALESCE(did.part_code, ki.item_code) AS part_code,
                COALESCE(did.part_name, ki.item_description, 'Part Safety Stock') AS part_name,
                COALESCE(isl.lot_number, did.lot_number, '-') AS lot_number,
                COALESCE(isl.ref_number, '-') AS ref_number,
                COALESCE(isl.qty, ss.total_scanned_qty, 0) AS qty_keluar,
                ss.auto_fulfilled_by_session_id,
                ki_k.kanban_no,
                ki_k.qty AS kanban_order_qty
            FROM inspection_sessions ss
            JOIN inspection_sessions s_k ON s_k.id = ss.auto_fulfilled_by_session_id
            LEFT JOIN kanban_items ki_k ON ki_k.id = s_k.kanban_item_id
            LEFT JOIN daily_inspection_data did ON did.id = ss.did_id
            LEFT JOIN kanban_items ki ON ki.id = ss.kanban_item_id
            LEFT JOIN inspection_session_lots isl ON isl.inspection_session_id = ss.id
            WHERE {$keluarActWhereStr}
            ORDER BY COALESCE(s_k.started_at, ss.started_at) DESC, ss.id DESC
        ");
        $stKeluarAct->execute($keluarActParams);
        while ($kRow = $stKeluarAct->fetch(PDO::FETCH_ASSOC)) {
            $kNo = !empty($kRow['kanban_no']) ? "Kanban #{$kRow['kanban_no']}" : "Kanban Sesi #{$kRow['auto_fulfilled_by_session_id']}";
            $kOrderQty = !empty($kRow['kanban_order_qty']) ? " (Demand: " . number_format($kRow['kanban_order_qty']) . " pcs)" : "";
            $allActivities[] = [
                'timestamp' => $kRow['timestamp_keluar'],
                'type'      => 'keluar_kanban',
                'part_code' => $kRow['part_code'],
                'part_name' => $kRow['part_name'],
                'lot'       => $kRow['lot_number'],
                'ref'       => $kRow['ref_number'],
                'qty'       => (int)$kRow['qty_keluar'],
                'note'      => "Terpakai untuk memotong order {$kNo}{$kOrderQty}",
                'session_id'=> $kRow['session_id'],
                'kanban_id' => $kRow['auto_fulfilled_by_session_id']
            ];
        }

        // Urutkan aktivitas dari waktu terbaru ke terlama
        usort($allActivities, function($a, $b) {
            return strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? '');
        });
        $summary['total_activities'] = count($allActivities);

        // ── H. Hitung Akumulasi KPI Global ───────────────────────────────────
        $summary['total_parts'] = count($allParts);
        foreach ($allParts as $p) {
            $pCode = strtoupper(trim($p['part_code']));
            $bInfo = $boxMap[$pCode] ?? null;

            $normalBoxes = ($bInfo && isset($bInfo['normal_boxes'])) ? (int)$bInfo['normal_boxes'] : (($p['normal_in_qty'] > 0) ? 1 : 0);
            $sisaBoxes   = ($bInfo && isset($bInfo['sisa_boxes'])) ? (int)$bInfo['sisa_boxes'] : (($p['sisa_in_qty'] > 0) ? 1 : 0);
            $totalBoxes  = max(1, $normalBoxes + $sisaBoxes);
            $activeBoxes = ($bInfo && isset($bInfo['active_boxes'])) ? (int)$bInfo['active_boxes'] : (($p['available_qty'] > 0) ? $totalBoxes : 0);

            $pUsedQty    = (int)$p['total_used_qty'];
            if ($pUsedQty <= 0) {
                $usedBoxes = 0;
            } else {
                $rawUsed = $usedLotsMap[$pCode] ?? 1;
                $usedBoxes = min($totalBoxes, max(1, (int)$rawUsed));
            }

            // 1. Masuk Normal
            $summary['normal_in_lots']       += $normalBoxes;
            $summary['normal_in_qty']        += (int)$p['normal_in_qty'];
            // 2. Masuk Sisa Kanban
            $summary['sisa_in_lots']         += $sisaBoxes;
            $summary['sisa_in_qty']          += (int)$p['sisa_in_qty'];
            // 3. Keluar Terpakai Kanban
            $summary['total_used_lots']      += $usedBoxes;
            $summary['total_used_qty']       += $pUsedQty;
            // 4. Sisa di Gudang (Ready)
            $summary['total_available_lots'] += $activeBoxes;
            $summary['total_available_qty']  += (int)$p['available_qty'];
            // 5. Total Reject / NG
            $summary['total_rejected_qty']   += (int)($p['rejected_qty'] ?? 0);
        }

        // Pastikan Card 4 (Stok Gudang Saat Ini) konsisten 100% dengan rumus visual: (Masuk Normal + Sisa) - Keluar
        $summary['total_available_lots'] = max(0, ($summary['normal_in_lots'] + $summary['sisa_in_lots']) - $summary['total_used_lots']);
        $summary['total_available_qty']  = max(0, ($summary['normal_in_qty'] + $summary['sisa_in_qty']) - $summary['total_used_qty']);

        // ── I. Pagination Data untuk Masing-Masing Tab ────────────────────────
        // Filter Part berdasarkan KPI Card (Model A)
        $unfilteredTotalParts = count($allParts);
        $filteredParts = $allParts;
        if ($kpiFilter === 'normal') {
            $filteredParts = array_values(array_filter($allParts, function($p) {
                return ((int)$p['normal_in_qty']) > 0;
            }));
        } elseif ($kpiFilter === 'sisa') {
            $filteredParts = array_values(array_filter($allParts, function($p) {
                return ((int)$p['sisa_in_qty']) > 0;
            }));
        } elseif ($kpiFilter === 'keluar') {
            $filteredParts = array_values(array_filter($allParts, function($p) {
                return ((int)$p['total_used_qty']) > 0;
            }));
        } elseif ($kpiFilter === 'ready') {
            $filteredParts = array_values(array_filter($allParts, function($p) {
                return ((int)$p['available_qty']) > 0;
            }));
        }

        // Tab 1: Stok per Part
        $totalItems = count($filteredParts);
        $totalPages = max(1, ceil($totalItems / $limit));
        if ($page > $totalPages) $page = $totalPages;
        $offset = ($page - 1) * $limit;
        $items = array_slice($filteredParts, $offset, $limit);

        // Tab 2: Sisa Scan Kanban
        $totalSisaItems = count($allSisaData);
        $totalSisaPages = max(1, ceil($totalSisaItems / $limit));
        $sisaItems = array_slice($allSisaData, $offset, $limit);

        // Tab 3: Riwayat Aktivitas & Hitung Rincian KPI Mutasi
        $mutasiSummary = [
            'semua' => ['count' => count($allActivities), 'qty' => 0],
            'normal'=> ['count' => 0, 'qty' => 0],
            'sisa'  => ['count' => 0, 'qty' => 0],
            'keluar'=> ['count' => 0, 'qty' => 0],
        ];
        foreach ($allActivities as $act) {
            $qty = (int)$act['qty'];
            $mutasiSummary['semua']['qty'] += $qty;
            if ($act['type'] === 'masuk_normal') {
                $mutasiSummary['normal']['count']++;
                $mutasiSummary['normal']['qty'] += $qty;
            } elseif ($act['type'] === 'masuk_sisa') {
                $mutasiSummary['sisa']['count']++;
                $mutasiSummary['sisa']['qty'] += $qty;
            } elseif ($act['type'] === 'keluar_kanban') {
                $mutasiSummary['keluar']['count']++;
                $mutasiSummary['keluar']['qty'] += $qty;
            }
        }

        // Filter aktivitas berdasarkan kategori kartu mutasi yang dipilih
        $filteredActivities = $allActivities;
        if ($mutasiFilter === 'normal') {
            $filteredActivities = array_values(array_filter($allActivities, fn($a) => $a['type'] === 'masuk_normal'));
        } elseif ($mutasiFilter === 'sisa') {
            $filteredActivities = array_values(array_filter($allActivities, fn($a) => $a['type'] === 'masuk_sisa'));
        } elseif ($mutasiFilter === 'keluar') {
            $filteredActivities = array_values(array_filter($allActivities, fn($a) => $a['type'] === 'keluar_kanban'));
        }

        $totalRiwayatItems = count($filteredActivities);
        $totalRiwayatPages = max(1, ceil($totalRiwayatItems / $limit));
        $offsetRiwayat     = max(0, ($page - 1) * $limit);
        $riwayatItems      = array_slice($filteredActivities, $offsetRiwayat, $limit);

    } catch (PDOException $e) {
        set_flash('error', 'Gagal memuat data Safety Stock: ' . $e->getMessage());
    }
}

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';
?>

<!-- Main Content Wrapper with Sidebar Offset (md:pl-64) -->
<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen min-w-0 w-full overflow-x-hidden transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-4 space-y-3 min-w-0 w-full overflow-x-hidden">
        
        <?= render_flash() ?>

        <!-- 1. Header Banner Card -->
        <div style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); border-radius: 12px; padding: 14px 18px; color: #ffffff; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="background-color: #2563eb; padding: 10px; border-radius: 10px; color: #ffffff; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <svg style="width: 22px; height: 22px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                    </svg>
                </div>
                <div>
                    <h2 style="font-size: 15px; font-weight: 800; margin: 0; line-height: 1.2;">Monitoring Safety Stock</h2>
                    <p style="font-size: 11px; color: #cbd5e1; font-weight: 500; margin-top: 3px;">
                        Pemantauan stok fisik di gudang, limpahan sisa scan Kanban, dan catatan mutasi keluar-masuk
                    </p>
                </div>
            </div>

            <a href="<?= base_url('modules/safety_stock/index.php?tab=' . urlencode($activeTab)) ?>" style="background-color: #334155; color: #ffffff; border-radius: 8px; padding: 7px 14px; font-size: 11px; font-weight: 700; border: none; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;" title="Refresh Data">
                <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                </svg>
                <span>Refresh Data</span>
            </a>
        </div>

        <!-- 1.5 Quick Period Preset Pills Bar -->
        <div style="background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px 14px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
            <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                <span style="font-size: 11px; font-weight: 800; color: #1e293b; letter-spacing: 0.5px; text-transform: uppercase;">PERIODE:</span>
                <div style="display: flex; align-items: center; gap: 4px; background: #f1f5f9; padding: 3px; border-radius: 9px; border: 1px solid #e2e8f0; flex-wrap: wrap;">
                    <?php
                    $periodOptions = [
                        'hari_ini' => 'Hari Ini',
                        'mingguan' => 'Minggu Ini',
                        'bulanan'  => 'Bulan Ini',
                        'tahunan'  => 'Tahun Ini',
                        'semua'    => 'Semua'
                    ];
                    foreach ($periodOptions as $optKey => $optLabel):
                        $isActive = ($period === $optKey);
                        $pillStyle = $isActive 
                            ? "background-color: #2563eb; color: #ffffff; font-weight: 800; box-shadow: 0 1px 3px rgba(37,99,235,0.3);" 
                            : "background-color: transparent; color: #475569; font-weight: 700;";
                        
                        $pillParams = array_filter([
                            'tab'        => $activeTab,
                            'kpi_filter' => ($kpiFilter !== 'all') ? $kpiFilter : null,
                            'period'     => $optKey,
                            'search'     => $search,
                            'limit'      => ($limit != 10) ? $limit : null
                        ]);
                        $pillUrl = base_url('modules/safety_stock/index.php?') . http_build_query($pillParams);
                    ?>
                        <a href="<?= $pillUrl ?>" 
                           style="padding: 5px 14px; border-radius: 6px; font-size: 11px; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; transition: all 0.15s ease-in-out; <?= $pillStyle ?>">
                            <?= $optLabel ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Keterangan Status Periode Aktif -->
            <div style="font-size: 11px; font-weight: 600; color: #64748b; display: flex; align-items: center; gap: 6px;">
                <?php if ($period === 'semua'): ?>
                    <span style="display: inline-flex; align-items: center; gap: 5px;">
                        <span style="width: 7px; height: 7px; border-radius: 9999px; background-color: #2563eb; display: inline-block;"></span>
                        Cakupan: <b style="color: #2563eb;">Seluruh Stok Gudang (All-Time)</b>
                    </span>
                <?php elseif (!empty($startDate) && !empty($endDate)): ?>
                    <span style="display: inline-flex; align-items: center; gap: 5px;">
                        <span style="width: 7px; height: 7px; border-radius: 9999px; background-color: #10b981; display: inline-block;"></span>
                        Filter: <b style="color: #1e293b;"><?= date('d M Y', strtotime($startDate)) ?></b> s/d <b style="color: #1e293b;"><?= date('d M Y', strtotime($endDate)) ?></b>
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <!-- 2. KPI CARDS (4 Interactive Clickable Filter Cards - Model A) -->
        <?php
            $kpiCardUrl = function($targetFilter) use ($kpiFilter, $period, $startDate, $endDate, $search, $limit) {
                $newFilter = ($kpiFilter === $targetFilter) ? 'all' : $targetFilter;
                $p = [
                    'tab'        => 'stok',
                    'kpi_filter' => ($newFilter !== 'all') ? $newFilter : null,
                    'period'     => $period,
                    'start_date' => $startDate,
                    'end_date'   => $endDate,
                    'search'     => $search,
                    'limit'      => ($limit != 10) ? $limit : null
                ];
                return base_url('modules/safety_stock/index.php?') . http_build_query(array_filter($p, function($v) { return $v !== null && $v !== ''; }));
            };

            $isNormalActive = ($kpiFilter === 'normal');
            $isSisaActive   = ($kpiFilter === 'sisa');
            $isKeluarActive = ($kpiFilter === 'keluar');
            $isReadyActive  = ($kpiFilter === 'ready');
        ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px;">
            
            <!-- Card 1: 📥 Masuk Normal (IN) -->
            <a href="<?= $kpiCardUrl('normal') ?>" 
               title="<?= $isNormalActive ? 'Klik untuk matikan filter (Tampilkan semua part)' : 'Klik untuk filter tabel: Hanya part dengan Masuk Normal' ?>"
               style="text-decoration: none; cursor: pointer; display: flex; flex-direction: column; justify-content: space-between; border-radius: 12px; padding: 12px 16px; transition: all 0.15s ease-in-out; <?= $isNormalActive ? 'background-color: #f0f9ff; border: 2px solid #0284c7; box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.25), 0 4px 12px rgba(2, 132, 199, 0.15);' : 'background-color: #ffffff; border: 1px solid #bae6fd; border-left: 4px solid #0284c7; box-shadow: 0 1px 2px rgba(0,0,0,0.03);' ?>">
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <span style="font-size: 11px; font-weight: 800; color: #0369a1; letter-spacing: 0.5px; text-transform: uppercase;">
                        📥 Masuk Normal (IN)
                    </span>
                    <?php if ($isNormalActive): ?>
                        <span style="font-size: 9px; font-weight: 800; background-color: #0284c7; color: #ffffff; padding: 2px 7px; border-radius: 9999px;">✓ FILTER AKTIF</span>
                    <?php else: ?>
                        <span style="font-size: 10px; font-weight: 700; background-color: #e0f2fe; color: #0369a1; padding: 2px 6px; border-radius: 6px;">Baru</span>
                    <?php endif; ?>
                </div>
                <div style="display: flex; align-items: baseline; gap: 4px; margin-top: 6px; flex-wrap: wrap;">
                    <span style="font-size: 22px; font-weight: 900; color: #0f172a; line-height: 1;"><?= number_format($summary['normal_in_lots']) ?></span>
                    <span style="font-size: 11px; font-weight: 700; color: #64748b;">Lot/Box</span>
                    <span style="font-size: 13px; font-weight: 800; color: #0284c7; margin-left: 2px;">(<?= number_format($summary['normal_in_qty']) ?> pcs)</span>
                </div>
                <div style="font-size: 10px; color: #64748b; font-weight: 500; margin-top: 8px; border-top: 1px solid <?= $isNormalActive ? '#bae6fd' : '#f1f5f9' ?>; padding-top: 5px; display: flex; align-items: center; justify-content: space-between;">
                    <span>Asal: <b style="color: #0369a1;">Inspeksi OQC Murni</b></span>
                    <span style="font-weight: 700; color: #0284c7;"><?= $isNormalActive ? '✕ Matikan' : 'Filter &raquo;' ?></span>
                </div>
            </a>

            <!-- Card 2: 🔄 Masuk Sisa Scan Kanban (IN) -->
            <a href="<?= $kpiCardUrl('sisa') ?>" 
               title="<?= $isSisaActive ? 'Klik untuk matikan filter (Tampilkan semua part)' : 'Klik untuk filter tabel: Hanya part dengan Limpahan Sisa Kanban' ?>"
               style="text-decoration: none; cursor: pointer; display: flex; flex-direction: column; justify-content: space-between; border-radius: 12px; padding: 12px 16px; transition: all 0.15s ease-in-out; <?= $isSisaActive ? 'background-color: #fffbeb; border: 2px solid #d97706; box-shadow: 0 0 0 3px rgba(217, 119, 6, 0.25), 0 4px 12px rgba(217, 119, 6, 0.15);' : 'background-color: #fffdf5; border: 1px solid #fde68a; border-left: 4px solid #d97706; box-shadow: 0 1px 2px rgba(0,0,0,0.03);' ?>">
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <span style="font-size: 11px; font-weight: 800; color: #b45309; letter-spacing: 0.5px; text-transform: uppercase;">
                        🔄 Masuk Sisa Scan (IN)
                    </span>
                    <?php if ($isSisaActive): ?>
                        <span style="font-size: 9px; font-weight: 800; background-color: #d97706; color: #ffffff; padding: 2px 7px; border-radius: 9999px;">✓ FILTER AKTIF</span>
                    <?php else: ?>
                        <span style="font-size: 10px; font-weight: 800; background-color: #fef3c7; color: #b45309; padding: 2px 6px; border-radius: 6px;">Sisa Kanban</span>
                    <?php endif; ?>
                </div>
                <div style="display: flex; align-items: baseline; gap: 4px; margin-top: 6px; flex-wrap: wrap;">
                    <span style="font-size: 22px; font-weight: 900; color: #b45309; line-height: 1;"><?= number_format($summary['sisa_in_lots']) ?></span>
                    <span style="font-size: 11px; font-weight: 700; color: #64748b;">Lot/Box</span>
                    <span style="font-size: 13px; font-weight: 800; color: #d97706; margin-left: 2px;">(<?= number_format($summary['sisa_in_qty']) ?> pcs)</span>
                </div>
                <div style="font-size: 10px; color: #64748b; font-weight: 500; margin-top: 8px; border-top: 1px solid <?= $isSisaActive ? '#fde68a' : '#fef3c7' ?>; padding-top: 5px; display: flex; align-items: center; justify-content: space-between;">
                    <span>Asal: <b style="color: #b45309;">Limpahan Sisa Kanban</b></span>
                    <span style="font-weight: 700; color: #d97706;"><?= $isSisaActive ? '✕ Matikan' : 'Filter &raquo;' ?></span>
                </div>
            </a>

            <!-- Card 3: 📤 Total Keluar (OUT) -->
            <a href="<?= $kpiCardUrl('keluar') ?>" 
               title="<?= $isKeluarActive ? 'Klik untuk matikan filter (Tampilkan semua part)' : 'Klik untuk filter tabel: Hanya part yang Terpakai memotong Kanban' ?>"
               style="text-decoration: none; cursor: pointer; display: flex; flex-direction: column; justify-content: space-between; border-radius: 12px; padding: 12px 16px; transition: all 0.15s ease-in-out; <?= $isKeluarActive ? 'background-color: #faf5ff; border: 2px solid #7e22ce; box-shadow: 0 0 0 3px rgba(126, 34, 206, 0.25), 0 4px 12px rgba(126, 34, 206, 0.15);' : 'background-color: #ffffff; border: 1px solid #e9d5ff; border-left: 4px solid #9333ea; box-shadow: 0 1px 2px rgba(0,0,0,0.03);' ?>">
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <span style="font-size: 11px; font-weight: 800; color: #7e22ce; letter-spacing: 0.5px; text-transform: uppercase;">
                        📤 Total Keluar (OUT)
                    </span>
                    <?php if ($isKeluarActive): ?>
                        <span style="font-size: 9px; font-weight: 800; background-color: #7e22ce; color: #ffffff; padding: 2px 7px; border-radius: 9999px;">✓ FILTER AKTIF</span>
                    <?php else: ?>
                        <span style="font-size: 10px; font-weight: 700; background-color: #f3e8ff; color: #7e22ce; padding: 2px 6px; border-radius: 6px;">Terpakai</span>
                    <?php endif; ?>
                </div>
                <div style="display: flex; align-items: baseline; gap: 4px; margin-top: 6px; flex-wrap: wrap;">
                    <span style="font-size: 22px; font-weight: 900; color: #9333ea; line-height: 1;"><?= number_format($summary['total_used_lots']) ?></span>
                    <span style="font-size: 11px; font-weight: 700; color: #64748b;">Lot/Box</span>
                    <span style="font-size: 13px; font-weight: 800; color: #7e22ce; margin-left: 2px;">(<?= number_format($summary['total_used_qty']) ?> pcs)</span>
                </div>
                <div style="font-size: 10px; color: #64748b; font-weight: 500; margin-top: 8px; border-top: 1px solid <?= $isKeluarActive ? '#e9d5ff' : '#f1f5f9' ?>; padding-top: 5px; display: flex; align-items: center; justify-content: space-between;">
                    <span>Alokasi: <b style="color: #7e22ce;">Memotong Kanban</b></span>
                    <span style="font-weight: 700; color: #7e22ce;"><?= $isKeluarActive ? '✕ Matikan' : 'Filter &raquo;' ?></span>
                </div>
            </a>

            <!-- Card 4: 📦 Sisa Stok di Gudang (READY) -->
            <a href="<?= $kpiCardUrl('ready') ?>" 
               title="<?= $isReadyActive ? 'Klik untuk matikan filter (Tampilkan semua part)' : 'Klik untuk filter tabel: Hanya part dengan Stok Fisik Siap Pakai di Rak' ?>"
               style="text-decoration: none; cursor: pointer; display: flex; flex-direction: column; justify-content: space-between; border-radius: 12px; padding: 12px 16px; transition: all 0.15s ease-in-out; <?= $isReadyActive ? 'background-color: #ecfdf5; border: 2px solid #16a34a; box-shadow: 0 0 0 3px rgba(22, 163, 74, 0.25), 0 4px 12px rgba(22, 163, 74, 0.15);' : 'background-color: #f0fdf4; border: 1px solid #bbf7d0; border-left: 4px solid #16a34a; box-shadow: 0 1px 2px rgba(0,0,0,0.03);' ?>">
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <span style="font-size: 11px; font-weight: 800; color: #166534; letter-spacing: 0.5px; text-transform: uppercase;">
                        📦 Stok Gudang Saat Ini
                    </span>
                    <?php if ($isReadyActive): ?>
                        <span style="font-size: 9px; font-weight: 800; background-color: #16a34a; color: #ffffff; padding: 2px 7px; border-radius: 9999px;">✓ FILTER AKTIF</span>
                    <?php else: ?>
                        <span style="font-size: 10px; font-weight: 800; background-color: #dcfce7; color: #15803d; padding: 2px 6px; border-radius: 6px;">Fisik Siap Pakai</span>
                    <?php endif; ?>
                </div>
                <div style="display: flex; align-items: baseline; gap: 4px; margin-top: 6px; flex-wrap: wrap;">
                    <span style="font-size: 22px; font-weight: 900; color: #15803d; line-height: 1;"><?= number_format($summary['total_available_lots']) ?></span>
                    <span style="font-size: 11px; font-weight: 700; color: #166534;">Lot/Box</span>
                    <span style="font-size: 13px; font-weight: 900; color: #15803d; margin-left: 2px;">(<?= number_format($summary['total_available_qty']) ?> pcs)</span>
                </div>
                <div style="font-size: 10px; color: #166534; font-weight: 600; margin-top: 8px; border-top: 1px solid <?= $isReadyActive ? '#bbf7d0' : '#dcfce7' ?>; padding-top: 5px; display: flex; align-items: center; justify-content: space-between;">
                    <span>Status: <b style="color: #15803d;">Siap Pakai di Rak</b></span>
                    <span style="font-weight: 700; color: #16a34a;"><?= $isReadyActive ? '✕ Matikan' : 'Filter &raquo;' ?></span>
                </div>
            </a>

        </div>

        <!-- 3. FILTER SEARCH BAR (Dengan scanner barcode & QR) -->
        <div style="background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px 14px; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
            <form action="<?= base_url('modules/safety_stock/index.php') ?>" method="GET" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab) ?>">
                <input type="hidden" name="kpi_filter" value="<?= htmlspecialchars($kpiFilter) ?>">
                <input type="hidden" name="mutasi" value="<?= htmlspecialchars($mutasiFilter) ?>">
                <input type="hidden" name="period" value="<?= htmlspecialchars($period) ?>">
                
                <div style="width: 320px; flex-shrink: 0; position: relative;">
                    <input type="text" id="safetyStockSearchInput" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Scan QR atau Cari Part, Lot, Ref..." 
                           onkeydown="handleSafetyStockSearchScan(event)"
                           style="width: 100%; padding: 7px 12px; background-color: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 12px; color: #1e293b; font-weight: 600; outline: none;">
                </div>

                <button type="submit" style="padding: 7px 16px; background-color: #2563eb; color: #ffffff; font-weight: 700; font-size: 12px; border-radius: 8px; border: none; cursor: pointer; flex-shrink: 0;">
                    Cari
                </button>

                <a href="<?= base_url('modules/safety_stock/index.php?tab=' . urlencode($activeTab)) ?>" style="padding: 7px 12px; background-color: #f1f5f9; color: #64748b; font-weight: 700; font-size: 12px; border-radius: 8px; border: 1px solid #cbd5e1; text-decoration: none; flex-shrink: 0;">
                    Reset
                </a>
            </form>
        </div>

        <!-- 4. TAB NAVIGATION BAR (3 Tab Jelas & Bersih) -->
        <div style="background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px 12px 0 0; padding: 6px 10px; border-bottom: 2px solid #e2e8f0; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
            <?php
                $tabUrlHelper = function($tabName) use ($period, $search, $limit, $kpiFilter) {
                    $p = [
                        'tab'        => $tabName,
                        'kpi_filter' => ($tabName === 'stok' && $kpiFilter !== 'all') ? $kpiFilter : null,
                        'period'     => $period,
                        'search'     => $search,
                        'limit'      => ($limit != 10) ? $limit : null
                    ];
                    return base_url('modules/safety_stock/index.php?') . http_build_query(array_filter($p));
                };
            ?>

            <!-- Tab 1: Stok Barang di Gudang -->
            <a href="<?= $tabUrlHelper('stok') ?>" 
               style="padding: 8px 16px; border-radius: 8px; font-size: 12px; font-weight: 800; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: all 0.15s; <?= ($activeTab === 'stok') ? 'background-color: #2563eb; color: #ffffff; box-shadow: 0 1px 3px rgba(37,99,235,0.3);' : 'background-color: transparent; color: #475569;' ?>">
                <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                </svg>
                <span>1. Stok Barang per Part</span>
                <span style="font-size: 10px; padding: 2px 7px; border-radius: 9999px; font-weight: 800; <?= ($activeTab === 'stok') ? 'background-color: #1d4ed8; color: #ffffff;' : 'background-color: #e2e8f0; color: #334155;' ?>">
                    <?= ($kpiFilter !== 'all') ? number_format($totalItems) . ' / ' . number_format($unfilteredTotalParts) : number_format($summary['total_parts']) ?> Part
                </span>
            </a>

            <!-- Tab 2: Sisa dari Scan Kanban -->
            <a href="<?= $tabUrlHelper('sisa') ?>" 
               style="padding: 8px 16px; border-radius: 8px; font-size: 12px; font-weight: 800; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: all 0.15s; <?= ($activeTab === 'sisa') ? 'background-color: #d97706; color: #ffffff; box-shadow: 0 1px 3px rgba(217,119,6,0.3);' : 'background-color: transparent; color: #475569;' ?>">
                <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                </svg>
                <span>2. Sisa dari Scan Kanban</span>
                <span style="font-size: 10px; padding: 2px 7px; border-radius: 9999px; font-weight: 800; <?= ($activeTab === 'sisa') ? 'background-color: #b45309; color: #ffffff;' : 'background-color: #fef3c7; color: #b45309; border: 1px solid #fde68a;' ?>">
                    <?= number_format($summary['sisa_events_count']) ?> Box Sisa
                </span>
            </a>

            <!-- Tab 3: Riwayat Aktivitas / Mutasi -->
            <a href="<?= $tabUrlHelper('riwayat') ?>" 
               style="padding: 8px 16px; border-radius: 8px; font-size: 12px; font-weight: 800; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: all 0.15s; <?= ($activeTab === 'riwayat') ? 'background-color: #7e22ce; color: #ffffff; box-shadow: 0 1px 3px rgba(126,34,206,0.3);' : 'background-color: transparent; color: #475569;' ?>">
                <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path>
                </svg>
                <span>3. Riwayat Mutasi (Keluar - Masuk)</span>
                <span style="font-size: 10px; padding: 2px 7px; border-radius: 9999px; font-weight: 800; <?= ($activeTab === 'riwayat') ? 'background-color: #6b21a8; color: #ffffff;' : 'background-color: #f3e8ff; color: #7e22ce;' ?>">
                    <?= number_format($summary['total_activities']) ?> Log
                </span>
            </a>
        </div>

        <!-- 5. KONTEN MASING-MASING TAB -->
        <div style="background-color: #ffffff; border: 1px solid #e2e8f0; border-top: none; border-radius: 0 0 12px 12px; overflow: hidden; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">

            <!-- ════════════════════════════════════════════════════════════════
                 TAB 1: STOK BARANG PER PART
                 ════════════════════════════════════════════════════════════════ -->
            <?php if ($activeTab === 'stok'): ?>
                <div style="padding: 12px 16px; background-color: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <h2 style="font-size: 13px; font-weight: 800; color: #1e293b; text-transform: uppercase; margin: 0;">
                            Daftar Stok Safety Stock per Part
                        </h2>
                    </div>

                    <!-- Limit Dropdown -->
                    <form method="GET" action="<?= base_url('modules/safety_stock/index.php') ?>" style="display: flex; align-items: center; gap: 8px; font-size: 12px;">
                        <input type="hidden" name="tab" value="stok">
                        <input type="hidden" name="kpi_filter" value="<?= htmlspecialchars($kpiFilter) ?>">
                        <input type="hidden" name="period" value="<?= htmlspecialchars($period) ?>">
                        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">

                        <span style="color: #64748b; font-weight: 600;">Tampilkan:</span>
                        <select name="limit" onchange="this.form.submit()" style="padding: 4px 8px; background-color: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; font-weight: 700; color: #334155; outline: none;">
                            <option value="5" <?= ($limit == 5) ? 'selected' : '' ?>>5</option>
                            <option value="10" <?= ($limit == 10) ? 'selected' : '' ?>>10</option>
                            <option value="25" <?= ($limit == 25) ? 'selected' : '' ?>>25</option>
                            <option value="50" <?= ($limit == 50) ? 'selected' : '' ?>>50</option>
                            <option value="100" <?= ($limit == 100) ? 'selected' : '' ?>>100</option>
                        </select>
                    </form>
                </div>

                <?php if ($kpiFilter !== 'all'): ?>
                    <?php
                        $kpiMeta = [
                            'normal' => ['label' => 'Masuk Normal (Hasil Inspeksi OQC Murni)', 'color' => '#0284c7', 'bg' => '#f0f9ff', 'border' => '#bae6fd'],
                            'sisa'   => ['label' => 'Masuk Sisa Scan (Limpahan Sisa Kanban)', 'color' => '#b45309', 'bg' => '#fffbeb', 'border' => '#fde68a'],
                            'keluar' => ['label' => 'Total Keluar (Pernah Memotong Order Kanban)', 'color' => '#7e22ce', 'bg' => '#faf5ff', 'border' => '#e9d5ff'],
                            'ready'  => ['label' => 'Stok Gudang Saat Ini (Fisik Siap Pakai di Rak)', 'color' => '#166534', 'bg' => '#ecfdf5', 'border' => '#bbf7d0'],
                        ];
                        $curMeta = $kpiMeta[$kpiFilter] ?? ['label' => $kpiFilter, 'color' => '#1e293b', 'bg' => '#f8fafc', 'border' => '#cbd5e1'];
                        $resetUrl = $kpiCardUrl($kpiFilter);
                    ?>
                    <div style="padding: 10px 16px; background-color: <?= $curMeta['bg'] ?>; border-bottom: 1px solid <?= $curMeta['border'] ?>; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                        <div style="display: flex; align-items: center; gap: 8px; font-size: 12px; color: <?= $curMeta['color'] ?>;">
                            <span style="font-weight: 800; background-color: <?= $curMeta['color'] ?>; color: #ffffff; padding: 2px 8px; border-radius: 6px; font-size: 10px;">FILTER KPI AKTIF</span>
                            <span>Menampilkan <b><?= number_format($totalItems) ?></b> dari <b><?= number_format($unfilteredTotalParts) ?></b> part (Kriteria: <b><?= $curMeta['label'] ?></b>)</span>
                        </div>
                        <a href="<?= $resetUrl ?>" style="padding: 4px 10px; background-color: #ffffff; border: 1px solid <?= $curMeta['border'] ?>; border-radius: 6px; color: <?= $curMeta['color'] ?>; font-weight: 800; font-size: 11px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;" title="Reset filter dan tampilkan semua part">
                            <span>✕ Tampilkan Semua Part</span>
                        </a>
                    </div>
                <?php endif; ?>

                <div id="mainSafetyStockTableWrap" style="overflow-x: auto; width: 100%; box-sizing: border-box;">
                    <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 12px; color: #334155;">
                        <thead style="background-color: #f8fafc; border-bottom: 2px solid #e2e8f0; font-size: 10px; font-weight: 800; color: #475569; text-transform: uppercase;">
                            <tr>
                                <th style="padding: 10px 14px; width: 40px; text-align: center; white-space: nowrap;">No</th>
                                <th style="padding: 10px 14px; min-width: 180px; white-space: nowrap;">Kode & Nama Part</th>
                                <th style="padding: 10px 14px; text-align: center; min-width: 110px; white-space: nowrap;">Kemasan Box</th>
                                <th style="padding: 10px 14px; text-align: right; min-width: 130px; white-space: nowrap; color: #0369a1;">📥 Masuk Normal</th>
                                <th style="padding: 10px 14px; text-align: right; min-width: 135px; white-space: nowrap; color: #b45309;">🔄 Sisa Kanban</th>
                                <th style="padding: 10px 14px; text-align: right; min-width: 120px; white-space: nowrap; color: #7e22ce;">📤 Terpakai</th>
                                <th style="padding: 10px 14px; text-align: right; min-width: 130px; white-space: nowrap; color: #15803d; background-color: #f0fdf4;">📦 Sisa di Rak</th>
                                <th style="padding: 10px 14px; min-width: 90px; text-align: center; white-space: nowrap;">Status</th>
                                <th style="padding: 10px 14px; text-align: right; min-width: 175px; white-space: nowrap;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($items)): ?>
                                <tr>
                                    <td colspan="9" style="padding: 48px; text-align: center; color: #94a3b8;">
                                        <div style="display: flex; flex-direction: column; align-items: center; justify-content: center;">
                                            <?php if ($kpiFilter !== 'all'): ?>
                                                <span style="font-weight: 800; color: #334155; font-size: 14px;">Tidak Ada Part untuk Kriteria Filter Ini</span>
                                                <span style="font-size: 12px; color: #64748b; margin-top: 4px; margin-bottom: 12px;">Tidak ada part yang memenuhi kriteria filter kartu KPI yang dipilih.</span>
                                                <a href="<?= $kpiCardUrl($kpiFilter) ?>" style="padding: 6px 14px; background-color: #2563eb; color: #ffffff; border-radius: 6px; font-size: 11px; font-weight: 700; text-decoration: none;">
                                                    Tampilkan Semua Part
                                                </a>
                                            <?php else: ?>
                                                <span style="font-weight: 800; color: #334155; font-size: 14px;">Belum Ada Data Safety Stock</span>
                                                <span style="font-size: 12px; color: #64748b; margin-top: 4px;">Data stok pengaman yang diinspeksi OQC akan tampil di sini.</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($items as $idx => $it): ?>
                                    <?php 
                                        $rowNum = $offset + $idx + 1;
                                        $pCode = strtoupper(trim($it['part_code']));
                                        $bInfo = $boxMap[$pCode] ?? null;

                                        $normalBoxes = ($bInfo && isset($bInfo['normal_boxes'])) ? (int)$bInfo['normal_boxes'] : (($it['normal_in_qty'] > 0) ? 1 : 0);
                                        $sisaBoxes   = ($bInfo && isset($bInfo['sisa_boxes'])) ? (int)$bInfo['sisa_boxes'] : (($it['sisa_in_qty'] > 0) ? 1 : 0);
                                        $totalBoxes  = max(($it['available_qty'] > 0 || $normalBoxes > 0 || $sisaBoxes > 0) ? 1 : 0, $normalBoxes + $sisaBoxes);
                                        $availBoxes  = ($bInfo && isset($bInfo['active_boxes'])) ? (int)$bInfo['active_boxes'] : (($it['available_qty'] > 0) ? $totalBoxes : 0);

                                        $normalIn    = (int)$it['normal_in_qty'];
                                        $sisaIn      = (int)$it['sisa_in_qty'];
                                        $rejectedQty = (int)($it['rejected_qty'] ?? 0);
                                        $usedQty     = (int)$it['total_used_qty'];
                                        $availQty    = (int)$it['available_qty'];

                                        // Status badge
                                        if ($availQty > 0) {
                                            $statusBadge = '<span style="display: inline-flex; align-items: center; justify-content: center; background-color: #dcfce7; color: #166534; border: 1px solid #bbf7d0; padding: 3px 8px; border-radius: 9999px; font-weight: 800; font-size: 10px; white-space: nowrap;">TERSEDIA</span>';
                                        } else {
                                            $statusBadge = '<span style="display: inline-flex; align-items: center; justify-content: center; background-color: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; padding: 3px 8px; border-radius: 9999px; font-weight: 800; font-size: 10px; white-space: nowrap;">HABIS</span>';
                                        }
                                    ?>
                                    <tr style="border-bottom: 1px solid #f1f5f9;">
                                        <td style="padding: 10px 14px; text-align: center; font-weight: 700; color: #64748b;"><?= $rowNum ?></td>
                                        
                                        <!-- Kode & Nama Part -->
                                        <td style="padding: 10px 14px;">
                                            <div style="font-weight: 800; color: #1d4ed8; font-family: monospace; font-size: 13px; margin-bottom: 2px;">
                                                <?= htmlspecialchars($it['part_code']) ?>
                                            </div>
                                            <div style="font-weight: 600; color: #1e293b; font-size: 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 220px;" title="<?= htmlspecialchars($it['part_name']) ?>">
                                                <?= htmlspecialchars($it['part_name']) ?>
                                            </div>
                                            <?php if (!empty($it['last_started_at'])): ?>
                                                <div style="font-size: 10px; color: #94a3b8; font-weight: 600; margin-top: 2px; white-space: nowrap;" title="Terakhir aktif: <?= date('d M Y, H:i', strtotime($it['last_started_at'])) ?>">
                                                    Aktif: <?= date('d M, H:i', strtotime($it['last_started_at'])) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Kemasan Box -->
                                        <td style="padding: 10px 14px; text-align: center; white-space: nowrap;">
                                            <span style="font-weight: 800; color: #1e293b; background-color: #f8fafc; padding: 3px 8px; border-radius: 9999px; border: 1px solid #cbd5e1; font-size: 11px; display: inline-block; white-space: nowrap;">
                                                <?= number_format($totalBoxes) ?> Box
                                            </span>
                                        </td>

                                        <!-- Masuk Normal -->
                                        <td style="padding: 10px 14px; text-align: right; white-space: nowrap;">
                                            <span style="font-weight: 800; color: #0284c7; font-size: 12px; font-family: monospace; display: inline-block; white-space: nowrap;">
                                                <?= number_format($normalIn) ?> <span style="font-size: 10px; font-weight: 600; color: #64748b;">pcs</span>
                                            </span>
                                        </td>

                                        <!-- Masuk Sisa Kanban -->
                                        <td style="padding: 10px 14px; text-align: right; white-space: nowrap;">
                                            <?php if ($sisaIn > 0): ?>
                                                <span style="font-weight: 800; color: #d97706; font-size: 12px; font-family: monospace; background-color: #fffbeb; padding: 2px 8px; border-radius: 6px; border: 1px solid #fde68a; display: inline-block; white-space: nowrap;">
                                                    +<?= number_format($sisaIn) ?> pcs
                                                </span>
                                            <?php else: ?>
                                                <span style="color: #94a3b8; font-size: 11px; white-space: nowrap;">0 pcs</span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Terpakai Kanban -->
                                        <td style="padding: 10px 14px; text-align: right; white-space: nowrap;">
                                            <span style="font-weight: 800; color: #7e22ce; font-size: 12px; font-family: monospace; display: inline-block; white-space: nowrap;">
                                                <?= number_format($usedQty) ?> <span style="font-size: 10px; font-weight: 600; color: #7e22ce;">pcs</span>
                                            </span>
                                        </td>

                                        <!-- Sisa di Rak Gudang -->
                                        <td style="padding: 10px 14px; text-align: right; white-space: nowrap; background-color: <?= ($availQty > 0) ? '#f0fdf4' : 'transparent' ?>;">
                                            <div style="font-weight: 900; color: <?= ($availQty > 0) ? '#15803d' : '#94a3b8' ?>; font-size: 13px; font-family: monospace; white-space: nowrap;">
                                                <?= number_format($availQty) ?> <span style="font-size: 10px; font-weight: 700;">pcs</span>
                                            </div>
                                            <div style="font-size: 10px; color: <?= ($availQty > 0) ? '#166534' : '#94a3b8' ?>; font-weight: 600; white-space: nowrap;">
                                                (<?= number_format($availBoxes) ?> Box)
                                            </div>
                                        </td>

                                        <!-- Status -->
                                        <td style="padding: 10px 14px; text-align: center; white-space: nowrap;">
                                            <?= $statusBadge ?>
                                        </td>

                                        <!-- Aksi -->
                                        <td style="padding: 10px 14px; text-align: right; white-space: nowrap;">
                                            <?php $timelineRowId = 'timeline-row-' . md5($it['part_code']); ?>
                                            <div style="display: inline-flex; align-items: center; justify-content: flex-end; gap: 6px;">
                                                <button type="button" 
                                                        onclick="togglePartTimeline('<?= htmlspecialchars($it['part_code'], ENT_QUOTES) ?>', '<?= $timelineRowId ?>', this)" 
                                                        class="btn-toggle-timeline"
                                                        style="padding: 5px 9px; background-color: #f8fafc; color: #475569; font-weight: 700; font-size: 11px; border-radius: 6px; border: 1px solid #cbd5e1; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; transition: all 0.15s;"
                                                        title="Buka / Tutup Kronologi Mutasi Stok">
                                                    <span>⏱️ Timeline</span>
                                                    <svg class="timeline-chevron" style="width: 11px; height: 11px; transition: transform 0.2s;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                                    </svg>
                                                </button>

                                                <?php if ($canManageLots): ?>
                                                    <button type="button" 
                                                            onclick="openManageLotsModal('<?= htmlspecialchars($it['part_code'], ENT_QUOTES) ?>', '<?= htmlspecialchars($it['part_name'], ENT_QUOTES) ?>')" 
                                                            style="padding: 5px 9px; background-color: #fff1f2; color: #dc2626; font-weight: 700; font-size: 11px; border-radius: 6px; border: 1px solid #fecaca; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; transition: all 0.15s;"
                                                            title="Hapus lot atau sesuaikan kuantiti fisik box di gudang">
                                                        <svg style="width: 12px; height: 12px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                        </svg>
                                                        <span>Hapus Lot</span>
                                                    </button>
                                                <?php endif; ?>

                                                <a href="<?= base_url('modules/safety_stock/detail.php?part_code=' . urlencode($it['part_code']) . '&period=' . urlencode($period) . (!empty($startDate) ? '&start_date=' . urlencode($startDate) . '&end_date=' . urlencode($endDate) : '')) ?>" 
                                                   style="padding: 5px 10px; background-color: #eff6ff; color: #1d4ed8; font-weight: 700; font-size: 11px; border-radius: 6px; border: 1px solid #bfdbfe; text-decoration: none; display: inline-flex; align-items: center; gap: 3px;">
                                                    <span>Detail</span> &raquo;
                                                </a>
                                            </div>
                                        </td>
                                    </tr>

                                    <!-- Expandable Child Row untuk Horizontal Timeline -->
                                    <tr id="<?= $timelineRowId ?>" class="timeline-child-row" style="display: none; background-color: #f8fafc;">
                                        <td colspan="9" style="padding: 10px 14px 14px 14px; border-bottom: 2px solid #cbd5e1; background-color: #f8fafc; max-width: 0 !important; width: 100%; overflow: hidden;">
                                            <div class="timeline-content-box" data-loaded="0" data-part="<?= htmlspecialchars($it['part_code']) ?>" style="width: 100%; max-width: 100%; box-sizing: border-box; overflow: hidden;">
                                                <!-- Content loaded via AJAX lazy load -->
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Tab 1 -->
                <?php if ($totalPages > 1): ?>
                    <div style="padding: 12px 16px; border-top: 1px solid #e2e8f0; background-color: #f8fafc; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; font-size: 12px;">
                        <div style="color: #64748b;">
                            Halaman <b style="color: #1e293b;"><?= $page ?></b> dari <b style="color: #1e293b;"><?= $totalPages ?></b> (Total <?= number_format($totalItems) ?> part)
                        </div>
                        <div style="display: flex; align-items: center; gap: 4px;">
                            <?php 
                                $queryParams = $_GET; 
                                $queryParams['tab'] = 'stok';
                                $queryParams['page'] = max(1, $page - 1);
                                $prevUrl = base_url('modules/safety_stock/index.php?') . http_build_query($queryParams);
                                $queryParams['page'] = min($totalPages, $page + 1);
                                $nextUrl = base_url('modules/safety_stock/index.php?') . http_build_query($queryParams);
                            ?>
                            <a href="<?= $prevUrl ?>" style="padding: 5px 10px; background-color: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; color: #334155; font-weight: 700; text-decoration: none; <?= ($page <= 1) ? 'pointer-events-none opacity-50' : '' ?>">&laquo; Prev</a>
                            <span style="padding: 5px 10px; font-weight: 800; color: #1d4ed8;"><?= $page ?></span>
                            <a href="<?= $nextUrl ?>" style="padding: 5px 10px; background-color: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; color: #334155; font-weight: 700; text-decoration: none; <?= ($page >= $totalPages) ? 'pointer-events-none opacity-50' : '' ?>">Next &raquo;</a>
                        </div>
                    </div>
                <?php endif; ?>

            <!-- ════════════════════════════════════════════════════════════════
                 TAB 2: SISA DARI SCAN KANBAN (EXCESS AUDIT)
                 ════════════════════════════════════════════════════════════════ -->
            <?php elseif ($activeTab === 'sisa'): ?>
                <div style="padding: 12px 16px; background-color: #fffdf5; border-bottom: 1px solid #fde68a; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                    <div>
                        <h2 style="font-size: 13px; font-weight: 800; color: #b45309; text-transform: uppercase; margin: 0;">
                            Laporan Khusus Sisa Scan Kanban (Limpahan Excess)
                        </h2>
                        <p style="font-size: 11px; color: #78350f; margin: 2px 0 0 0;">
                            Menjawab kapan dan apa saja box sisa/kelebihan yang terjadi saat proses scan order Kanban
                        </p>
                    </div>

                    <span style="padding: 4px 10px; background-color: #fef3c7; color: #b45309; font-size: 11px; font-weight: 800; border-radius: 9999px; border: 1px solid #fde68a;">
                        Total: <?= number_format($totalSisaItems) ?> Kejadian Sisa
                    </span>
                </div>

                <div id="mainSafetyStockTableWrap" style="overflow-x: auto; width: 100%; box-sizing: border-box;">
                    <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 12px; color: #334155;">
                        <thead style="background-color: #f8fafc; border-bottom: 2px solid #e2e8f0; font-size: 10px; font-weight: 800; color: #475569; text-transform: uppercase;">
                            <tr>
                                <th style="padding: 10px 14px; width: 40px; text-align: center; white-space: nowrap;">No</th>
                                <th style="padding: 10px 14px; min-width: 130px; white-space: nowrap;">Waktu Terjadi</th>
                                <th style="padding: 10px 14px; min-width: 180px; white-space: nowrap;">Part (Kode & Nama)</th>
                                <th style="padding: 10px 14px; min-width: 120px; white-space: nowrap;">No. Lot / No. Ref</th>
                                <th style="padding: 10px 14px; text-align: right; min-width: 155px; white-space: nowrap; color: #b45309;">Rincian Qty (Asli &rarr; Sisa)</th>
                                <th style="padding: 10px 14px; min-width: 240px; white-space: nowrap;">Keterangan Asal Sisa</th>
                                <th style="padding: 10px 14px; width: 120px; text-align: center; white-space: nowrap;">Status Fisik</th>
                                <th style="padding: 10px 14px; text-align: right; width: 80px; white-space: nowrap;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($sisaItems)): ?>
                                <tr>
                                    <td colspan="8" style="padding: 48px; text-align: center; color: #94a3b8;">
                                        <div style="display: flex; flex-direction: column; align-items: center; justify-content: center;">
                                            <span style="font-weight: 800; color: #334155; font-size: 14px;">Belum Ada Sisa Scan Kanban</span>
                                            <span style="font-size: 12px; color: #64748b; margin-top: 4px;">Jika ada scan kanban yang menghasilkan sisa pecahan box, datanya otomatis tercatat di sini.</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($sisaItems as $idx => $sRow): ?>
                                    <?php 
                                        $rowNum = $offset + $idx + 1;
                                        $isUsed = (!empty($sRow['auto_fulfilled_by_session_id']) && (int)$sRow['auto_fulfilled_by_session_id'] > 0);
                                        $qtySisa = (int)$sRow['sisa_qty'];
                                        $bInfo = parseSisaBoxOrigin($sRow['scanned_qr_raw'], $qtySisa, $sRow['kanban_remark'], $sRow['lot_remarks']);
                                    ?>
                                    <tr style="border-bottom: 1px solid #f1f5f9;">
                                        <td style="padding: 10px 14px; text-align: center; font-weight: 700; color: #64748b;"><?= $rowNum ?></td>
                                        
                                        <!-- Waktu Terjadi -->
                                        <td style="padding: 10px 14px; white-space: nowrap;">
                                            <div style="font-weight: 800; color: #1e293b; font-size: 12px;">
                                                <?= date('d M Y', strtotime($sRow['started_at'])) ?>
                                            </div>
                                            <div style="font-size: 11px; color: #64748b; font-weight: 600;">
                                                Pukul <?= date('H:i', strtotime($sRow['started_at'])) ?> WIB
                                            </div>
                                        </td>

                                        <!-- Part -->
                                        <td style="padding: 10px 14px;">
                                            <div style="font-weight: 800; color: #1d4ed8; font-family: monospace; font-size: 13px;">
                                                <?= htmlspecialchars($sRow['part_code']) ?>
                                            </div>
                                            <div style="font-size: 11px; color: #475569; font-weight: 600;">
                                                <?= htmlspecialchars($sRow['part_name']) ?>
                                            </div>
                                        </td>

                                        <!-- Lot & Ref -->
                                        <td style="padding: 10px 14px;">
                                            <div style="font-weight: 700; color: #1e293b; font-size: 12px;">
                                                Lot: <span style="font-family: monospace; font-weight: 800;"><?= htmlspecialchars($sRow['lot_number']) ?></span>
                                            </div>
                                            <div style="font-size: 11px; color: #64748b;">
                                                Ref: <span style="font-family: monospace; font-weight: 600;"><?= htmlspecialchars($sRow['ref_number']) ?></span>
                                            </div>
                                        </td>

                                        <!-- Rincian Qty (Asli & Sisa) -->
                                        <td style="padding: 10px 14px; text-align: right;">
                                            <!-- Qty Sisa di Rak (Angka Utama) -->
                                            <div style="font-size: 14px; font-weight: 900; color: #b45309; font-family: monospace;">
                                                <?= number_format($qtySisa) ?> <span style="font-size: 11px; font-weight: 700;">pcs</span>
                                            </div>

                                            <?php if ($bInfo['is_split'] && $bInfo['orig_box_qty'] > 0): ?>
                                                <!-- Kotak Rincian Box Asli vs Pemotongan -->
                                                <div style="margin-top: 4px; padding: 4px 8px; background-color: #fffdf5; border: 1px solid #fde68a; border-radius: 6px; font-size: 10px; line-height: 1.4; text-align: right; display: inline-block;">
                                                    <div style="color: #475569; font-weight: 700;">
                                                        📦 Box Asli: <b style="color: #0369a1; font-family: monospace;"><?= number_format($bInfo['orig_box_qty']) ?> pcs</b>
                                                    </div>
                                                    <div style="color: #991b1b; font-weight: 700;">
                                                        ✂️ Terpakai: <b style="color: #b91c1c; font-family: monospace;">-<?= number_format($bInfo['used_qty']) ?> pcs</b>
                                                    </div>
                                                </div>
                                            <?php elseif ($bInfo['orig_box_qty'] > 0): ?>
                                                <?php $boxCount = max(1, round($qtySisa / $bInfo['orig_box_qty'])); ?>
                                                <div style="margin-top: 3px; font-size: 10px; color: #64748b; font-weight: 600;">
                                                    Standar: <b style="color: #1e293b;"><?= number_format($bInfo['orig_box_qty']) ?> pcs/box</b>
                                                    <div style="color: #0284c7; font-weight: 700;">(<?= $boxCount ?> Box Utuh)</div>
                                                </div>
                                            <?php else: ?>
                                                <div style="font-size: 10px; color: #64748b; font-weight: 600;">(Box Pecahan)</div>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Keterangan Asal Sisa -->
                                        <td style="padding: 10px 14px;">
                                            <?php if ($bInfo['is_split'] && $bInfo['orig_box_qty'] > 0): ?>
                                                <div style="font-size: 11px; font-weight: 700; color: #1e293b; line-height: 1.4;">
                                                    Diambil <span style="color: #7e22ce; font-family: monospace;"><?= number_format($bInfo['used_qty']) ?> pcs</span> untuk Kanban, sisa <span style="color: #b45309; font-family: monospace;"><?= number_format($qtySisa) ?> pcs</span> masuk Safety Stock
                                                </div>
                                                <div style="font-size: 10px; color: #64748b; margin-top: 4px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                                    <?php if (!empty($bInfo['origin_sess_id'])): ?>
                                                        <span style="background-color: #f1f5f9; color: #334155; padding: 2px 7px; border-radius: 5px; border: 1px solid #cbd5e1; font-weight: 700;">
                                                            Asal Kanban: Sesi #<?= $bInfo['origin_sess_id'] ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <span style="color: #94a3b8;">Sesi SS: #<?= $sRow['session_id'] ?></span>
                                                </div>
                                            <?php else: ?>
                                                <div style="font-size: 11px; font-weight: 600; color: #1e293b; line-height: 1.4;">
                                                    <?= htmlspecialchars($sRow['lot_remarks'] ?: $sRow['kanban_remark']) ?>
                                                </div>
                                                <div style="font-size: 10px; color: #94a3b8; margin-top: 3px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                                    <?php if (!empty($bInfo['origin_sess_id'])): ?>
                                                        <span style="background-color: #f1f5f9; color: #334155; padding: 2px 7px; border-radius: 5px; border: 1px solid #cbd5e1; font-weight: 700;">
                                                            Asal Kanban: Sesi #<?= $bInfo['origin_sess_id'] ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <span>Sesi SS: #<?= $sRow['session_id'] ?></span>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Status Fisik -->
                                        <td style="padding: 10px 14px; text-align: center;">
                                            <?php if ($isUsed): ?>
                                                <span style="background-color: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; padding: 3px 8px; border-radius: 9999px; font-weight: 800; font-size: 10px; display: inline-block;">
                                                    SUDAH DIPAKAI
                                                </span>
                                            <?php else: ?>
                                                <span style="background-color: #dcfce7; color: #166534; border: 1px solid #bbf7d0; padding: 3px 8px; border-radius: 9999px; font-weight: 800; font-size: 10px; display: inline-block;">
                                                    ADA DI RAK GUDANG
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Aksi -->
                                        <td style="padding: 10px 14px; text-align: right;">
                                            <a href="<?= base_url('modules/safety_stock/detail.php?part_code=' . urlencode($sRow['part_code']) . '&period=' . urlencode($period) . (!empty($startDate) ? '&start_date=' . urlencode($startDate) . '&end_date=' . urlencode($endDate) : '')) ?>" 
                                               style="padding: 5px 10px; background-color: #eff6ff; color: #1d4ed8; font-weight: 700; font-size: 11px; border-radius: 6px; border: 1px solid #bfdbfe; text-decoration: none;">
                                                Lihat
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Tab 2 -->
                <?php if ($totalSisaPages > 1): ?>
                    <div style="padding: 12px 16px; border-top: 1px solid #e2e8f0; background-color: #f8fafc; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; font-size: 12px;">
                        <div style="color: #64748b;">
                            Halaman <b style="color: #1e293b;"><?= $page ?></b> dari <b style="color: #1e293b;"><?= $totalSisaPages ?></b> (Total <?= number_format($totalSisaItems) ?> kejadian sisa)
                        </div>
                        <div style="display: flex; align-items: center; gap: 4px;">
                            <?php 
                                $queryParams = $_GET; 
                                $queryParams['tab'] = 'sisa';
                                $queryParams['page'] = max(1, $page - 1);
                                $prevUrl = base_url('modules/safety_stock/index.php?') . http_build_query($queryParams);
                                $queryParams['page'] = min($totalSisaPages, $page + 1);
                                $nextUrl = base_url('modules/safety_stock/index.php?') . http_build_query($queryParams);
                            ?>
                            <a href="<?= $prevUrl ?>" style="padding: 5px 10px; background-color: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; color: #334155; font-weight: 700; text-decoration: none; <?= ($page <= 1) ? 'pointer-events-none opacity-50' : '' ?>">&laquo; Prev</a>
                            <span style="padding: 5px 10px; font-weight: 800; color: #d97706;"><?= $page ?></span>
                            <a href="<?= $nextUrl ?>" style="padding: 5px 10px; background-color: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; color: #334155; font-weight: 700; text-decoration: none; <?= ($page >= $totalSisaPages) ? 'pointer-events-none opacity-50' : '' ?>">Next &raquo;</a>
                        </div>
                    </div>
                <?php endif; ?>

            <!-- ════════════════════════════════════════════════════════════════
                 TAB 3: RIWAYAT AKTIVITAS (LOG MUTASI KELUAR - MASUK)
                 ════════════════════════════════════════════════════════════════ -->
            <?php elseif ($activeTab === 'riwayat'): ?>
                <div style="padding: 12px 16px; background-color: #faf5ff; border-bottom: 1px solid #e9d5ff; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                    <div>
                        <h2 style="font-size: 13px; font-weight: 800; color: #7e22ce; text-transform: uppercase; margin: 0;">
                            Buku Riwayat Mutasi Safety Stock (Keluar - Masuk)
                        </h2>
                        <p style="font-size: 11px; color: #6b21a8; margin: 2px 0 0 0;">
                            Catatan kronologis perubahan kuantiti stok pengaman (urut dari aktivitas paling baru)
                        </p>
                    </div>

                    <span style="padding: 4px 10px; background-color: #f3e8ff; color: #7e22ce; font-size: 11px; font-weight: 800; border-radius: 9999px; border: 1px solid #e9d5ff;">
                        Ditampilkan: <?= number_format($totalRiwayatItems) ?> Log
                    </span>
                </div>

                <!-- 4 KARTU FILTER MUTASI INTERAKTIF (BISA DIKLIK) -->
                <div style="padding: 12px 16px; background-color: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 10px;">
                        <span style="font-size: 11px; font-weight: 800; color: #475569; letter-spacing: 0.5px; text-transform: uppercase;">
                            ⚡ PILIH JENIS MUTASI (KLIK KARTU UNTUK FILTER):
                        </span>
                        <?php if ($mutasiFilter !== 'semua'): ?>
                            <a href="<?= base_url('modules/safety_stock/index.php?tab=riwayat&mutasi=semua&period=' . urlencode($period) . '&search=' . urlencode($search)) ?>" 
                               style="font-size: 11px; font-weight: 700; color: #2563eb; text-decoration: none;">
                                &times; Reset Filter Mutasi
                            </a>
                        <?php endif; ?>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px;">
                        <?php
                            $filterUrlHelper = function($mType) use ($period, $search, $limit) {
                                $p = [
                                    'tab'    => 'riwayat',
                                    'mutasi' => $mType,
                                    'period' => $period,
                                    'search' => $search,
                                    'limit'  => ($limit != 10) ? $limit : null
                                ];
                                return base_url('modules/safety_stock/index.php?') . http_build_query(array_filter($p));
                            };
                        ?>

                        <!-- Card 1: 🌐 SEMUA MUTASI -->
                        <?php $isAll = ($mutasiFilter === 'semua'); ?>
                        <a href="<?= $filterUrlHelper('semua') ?>" 
                           style="background-color: <?= $isAll ? '#0f172a' : '#ffffff' ?>; color: <?= $isAll ? '#ffffff' : '#1e293b' ?>; border: <?= $isAll ? '2px solid #0f172a' : '1px solid #cbd5e1' ?>; border-radius: 10px; padding: 10px 14px; text-decoration: none; display: flex; flex-direction: column; justify-content: space-between; transition: all 0.15s; box-shadow: <?= $isAll ? '0 2px 6px rgba(15,23,42,0.25)' : '0 1px 2px rgba(0,0,0,0.03)' ?>; cursor: pointer;">
                            <div style="display: flex; align-items: center; justify-content: space-between;">
                                <span style="font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; color: <?= $isAll ? '#94a3b8' : '#64748b' ?>;">
                                    🌐 SEMUA
                                </span>
                                <?php if ($isAll): ?>
                                    <span style="background-color: #2563eb; color: #ffffff; font-size: 9px; font-weight: 800; padding: 2px 6px; border-radius: 9999px;">✓ AKTIF</span>
                                <?php endif; ?>
                            </div>
                            <div style="margin-top: 6px;">
                                <div style="font-size: 19px; font-weight: 900; line-height: 1;">
                                    <?= number_format($mutasiSummary['semua']['count']) ?> <span style="font-size: 11px; font-weight: 700;">Log</span>
                                </div>
                                <div style="font-size: 10px; margin-top: 4px; font-weight: 600; color: <?= $isAll ? '#cbd5e1' : '#64748b' ?>;">
                                    Seluruh Mutasi Gerak
                                </div>
                            </div>
                        </a>

                        <!-- Card 2: 📥 MASUK NORMAL -->
                        <?php $isNormal = ($mutasiFilter === 'normal'); ?>
                        <a href="<?= $filterUrlHelper('normal') ?>" 
                           style="background-color: <?= $isNormal ? '#0284c7' : '#ffffff' ?>; color: <?= $isNormal ? '#ffffff' : '#0369a1' ?>; border: <?= $isNormal ? '2px solid #0284c7' : '1px solid #bae6fd' ?>; border-radius: 10px; padding: 10px 14px; text-decoration: none; display: flex; flex-direction: column; justify-content: space-between; transition: all 0.15s; box-shadow: <?= $isNormal ? '0 2px 6px rgba(2,132,199,0.3)' : '0 1px 2px rgba(0,0,0,0.03)' ?>; cursor: pointer;">
                            <div style="display: flex; align-items: center; justify-content: space-between;">
                                <span style="font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; color: <?= $isNormal ? '#e0f2fe' : '#0369a1' ?>;">
                                    📥 MASUK NORMAL
                                </span>
                                <?php if ($isNormal): ?>
                                    <span style="background-color: #ffffff; color: #0284c7; font-size: 9px; font-weight: 800; padding: 2px 6px; border-radius: 9999px;">✓ AKTIF</span>
                                <?php endif; ?>
                            </div>
                            <div style="margin-top: 6px;">
                                <div style="font-size: 19px; font-weight: 900; line-height: 1;">
                                    <?= number_format($mutasiSummary['normal']['count']) ?> <span style="font-size: 11px; font-weight: 700;">Log</span>
                                </div>
                                <div style="font-size: 10px; margin-top: 4px; font-weight: 700; color: <?= $isNormal ? '#e0f2fe' : '#0284c7' ?>;">
                                    +<?= number_format($summary['normal_in_qty']) ?> pcs (Baru)
                                </div>
                            </div>
                        </a>

                        <!-- Card 3: 🔄 SISA KANBAN -->
                        <?php $isSisa = ($mutasiFilter === 'sisa'); ?>
                        <a href="<?= $filterUrlHelper('sisa') ?>" 
                           style="background-color: <?= $isSisa ? '#d97706' : '#ffffff' ?>; color: <?= $isSisa ? '#ffffff' : '#b45309' ?>; border: <?= $isSisa ? '2px solid #d97706' : '1px solid #fde68a' ?>; border-radius: 10px; padding: 10px 14px; text-decoration: none; display: flex; flex-direction: column; justify-content: space-between; transition: all 0.15s; box-shadow: <?= $isSisa ? '0 2px 6px rgba(217,119,6,0.3)' : '0 1px 2px rgba(0,0,0,0.03)' ?>; cursor: pointer;">
                            <div style="display: flex; align-items: center; justify-content: space-between;">
                                <span style="font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; color: <?= $isSisa ? '#fef3c7' : '#b45309' ?>;">
                                    🔄 SISA KANBAN
                                </span>
                                <?php if ($isSisa): ?>
                                    <span style="background-color: #ffffff; color: #d97706; font-size: 9px; font-weight: 800; padding: 2px 6px; border-radius: 9999px;">✓ AKTIF</span>
                                <?php endif; ?>
                            </div>
                            <div style="margin-top: 6px;">
                                <div style="font-size: 19px; font-weight: 900; line-height: 1;">
                                    <?= number_format($mutasiSummary['sisa']['count']) ?> <span style="font-size: 11px; font-weight: 700;">Log</span>
                                </div>
                                <div style="font-size: 10px; margin-top: 4px; font-weight: 700; color: <?= $isSisa ? '#fef3c7' : '#d97706' ?>;">
                                    +<?= number_format($mutasiSummary['sisa']['qty']) ?> pcs (Sisa)
                                </div>
                            </div>
                        </a>

                        <!-- Card 4: 📤 KELUAR KANBAN -->
                        <?php $isKeluar = ($mutasiFilter === 'keluar'); ?>
                        <a href="<?= $filterUrlHelper('keluar') ?>" 
                           style="background-color: <?= $isKeluar ? '#7e22ce' : '#ffffff' ?>; color: <?= $isKeluar ? '#ffffff' : '#7e22ce' ?>; border: <?= $isKeluar ? '2px solid #7e22ce' : '1px solid #e9d5ff' ?>; border-radius: 10px; padding: 10px 14px; text-decoration: none; display: flex; flex-direction: column; justify-content: space-between; transition: all 0.15s; box-shadow: <?= $isKeluar ? '0 2px 6px rgba(126,34,206,0.3)' : '0 1px 2px rgba(0,0,0,0.03)' ?>; cursor: pointer;">
                            <div style="display: flex; align-items: center; justify-content: space-between;">
                                <span style="font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; color: <?= $isKeluar ? '#f3e8ff' : '#7e22ce' ?>;">
                                    📤 KELUAR KANBAN
                                </span>
                                <?php if ($isKeluar): ?>
                                    <span style="background-color: #ffffff; color: #7e22ce; font-size: 9px; font-weight: 800; padding: 2px 6px; border-radius: 9999px;">✓ AKTIF</span>
                                <?php endif; ?>
                            </div>
                            <div style="margin-top: 6px;">
                                <div style="font-size: 19px; font-weight: 900; line-height: 1;">
                                    <?= number_format($mutasiSummary['keluar']['count']) ?> <span style="font-size: 11px; font-weight: 700;">Log</span>
                                </div>
                                <div style="font-size: 10px; margin-top: 4px; font-weight: 700; color: <?= $isKeluar ? '#f3e8ff' : '#7e22ce' ?>;">
                                    -<?= number_format($mutasiSummary['keluar']['qty']) ?> pcs (Terpakai)
                                </div>
                            </div>
                        </a>

                    </div>
                </div>

                <div id="mainSafetyStockTableWrap" style="overflow-x: auto; width: 100%; box-sizing: border-box;">
                    <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 12px; color: #334155;">
                        <thead style="background-color: #f8fafc; border-bottom: 2px solid #e2e8f0; font-size: 10px; font-weight: 800; color: #475569; text-transform: uppercase;">
                            <tr>
                                <th style="padding: 10px 14px; width: 40px; text-align: center;">No</th>
                                <th style="padding: 10px 14px; min-width: 130px; white-space: nowrap;">Waktu & Tanggal</th>
                                <th style="padding: 10px 14px; width: 1%; white-space: nowrap;">Jenis Mutasi</th>
                                <th style="padding: 10px 14px; min-width: 180px;">Part (Kode & Nama)</th>
                                <th style="padding: 10px 14px; min-width: 140px; white-space: nowrap;">No. Lot / Ref</th>
                                <th style="padding: 10px 14px; text-align: right; width: 120px; white-space: nowrap;">Perubahan Qty</th>
                                <th style="padding: 10px 14px; min-width: 260px;">Keterangan Mutasi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($riwayatItems)): ?>
                                <tr>
                                    <td colspan="7" style="padding: 48px; text-align: center; color: #94a3b8;">
                                        <div style="display: flex; flex-direction: column; align-items: center; justify-content: center;">
                                            <span style="font-weight: 800; color: #334155; font-size: 14px;">Tidak Ada Riwayat Mutasi</span>
                                            <span style="font-size: 12px; color: #64748b; margin-top: 4px;">Tidak ada pergerakan data untuk filter jenis mutasi yang dipilih.</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($riwayatItems as $idx => $act): ?>
                                    <?php 
                                        $rowNum = $offsetRiwayat + $idx + 1;
                                        $type = $act['type'];
                                        if ($type === 'masuk_normal') {
                                            $typeBadge = '<span style="display: inline-block; white-space: nowrap !important; background-color: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; padding: 4px 12px; border-radius: 9999px; font-weight: 800; font-size: 10px; line-height: 1.4; vertical-align: middle;">📥 MASUK NORMAL</span>';
                                            $qtyStyle = 'color: #0284c7; font-weight: 900;';
                                            $qtyPrefix = '+';
                                        } elseif ($type === 'masuk_sisa') {
                                            $typeBadge = '<span style="display: inline-block; white-space: nowrap !important; background-color: #fef3c7; color: #b45309; border: 1px solid #fde68a; padding: 4px 12px; border-radius: 9999px; font-weight: 800; font-size: 10px; line-height: 1.4; vertical-align: middle;">🔄 SISA KANBAN</span>';
                                            $qtyStyle = 'color: #d97706; font-weight: 900;';
                                            $qtyPrefix = '+';
                                        } else {
                                            $typeBadge = '<span style="display: inline-block; white-space: nowrap !important; background-color: #f3e8ff; color: #7e22ce; border: 1px solid #e9d5ff; padding: 4px 12px; border-radius: 9999px; font-weight: 800; font-size: 10px; line-height: 1.4; vertical-align: middle;">📤 KELUAR KANBAN</span>';
                                            $qtyStyle = 'color: #9333ea; font-weight: 900;';
                                            $qtyPrefix = '-';
                                        }
                                    ?>
                                    <tr style="border-bottom: 1px solid #f1f5f9;">
                                        <td style="padding: 10px 14px; text-align: center; font-weight: 700; color: #64748b;"><?= $rowNum ?></td>
                                        
                                        <!-- Waktu & Tanggal -->
                                        <td style="padding: 10px 14px; white-space: nowrap;">
                                            <div style="font-weight: 800; color: #1e293b; font-size: 12px;">
                                                <?= date('d M Y', strtotime($act['timestamp'])) ?>
                                            </div>
                                            <div style="font-size: 11px; color: #64748b;">
                                                <?= date('H:i:s', strtotime($act['timestamp'])) ?>
                                            </div>
                                        </td>

                                        <!-- Jenis Mutasi -->
                                        <td style="padding: 10px 14px; width: 1%; white-space: nowrap;">
                                            <?= $typeBadge ?>
                                        </td>

                                        <!-- Part -->
                                        <td style="padding: 10px 14px;">
                                            <div style="font-weight: 800; color: #1d4ed8; font-family: monospace; font-size: 12px;">
                                                <?= htmlspecialchars($act['part_code']) ?>
                                            </div>
                                            <div style="font-size: 11px; color: #475569; font-weight: 600;">
                                                <?= htmlspecialchars($act['part_name']) ?>
                                            </div>
                                        </td>

                                        <!-- Lot & Ref -->
                                        <td style="padding: 10px 14px; font-family: monospace; font-size: 11px; color: #334155;">
                                            <div>Lot: <?= htmlspecialchars($act['lot']) ?></div>
                                            <?php if (!empty($act['ref']) && $act['ref'] !== '-'): ?>
                                                <div style="color: #64748b;">Ref: <?= htmlspecialchars($act['ref']) ?></div>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Perubahan Qty -->
                                        <td style="padding: 10px 14px; text-align: right; font-family: monospace; font-size: 13px; <?= $qtyStyle ?>">
                                            <?= $qtyPrefix . number_format($act['qty']) ?> pcs
                                        </td>

                                        <!-- Keterangan Mutasi -->
                                        <td style="padding: 10px 14px; font-size: 11px; color: #334155; line-height: 1.4;">
                                            <?= htmlspecialchars($act['note']) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Tab 3 -->
                <?php if ($totalRiwayatPages > 1): ?>
                    <div style="padding: 12px 16px; border-top: 1px solid #e2e8f0; background-color: #f8fafc; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; font-size: 12px;">
                        <div style="color: #64748b;">
                            Halaman <b style="color: #1e293b;"><?= $page ?></b> dari <b style="color: #1e293b;"><?= $totalRiwayatPages ?></b> (Total <?= number_format($totalRiwayatItems) ?> log)
                        </div>
                        <div style="display: flex; align-items: center; gap: 4px;">
                            <?php 
                                $queryParams = $_GET; 
                                $queryParams['tab'] = 'riwayat';
                                $queryParams['mutasi'] = $mutasiFilter;
                                $queryParams['page'] = max(1, $page - 1);
                                $prevUrl = base_url('modules/safety_stock/index.php?') . http_build_query($queryParams);
                                $queryParams['page'] = min($totalRiwayatPages, $page + 1);
                                $nextUrl = base_url('modules/safety_stock/index.php?') . http_build_query($queryParams);
                            ?>
                            <a href="<?= $prevUrl ?>" style="padding: 5px 10px; background-color: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; color: #334155; font-weight: 700; text-decoration: none; <?= ($page <= 1) ? 'pointer-events-none opacity-50' : '' ?>">&laquo; Prev</a>
                            <span style="padding: 5px 10px; font-weight: 800; color: #7e22ce;"><?= $page ?></span>
                            <a href="<?= $nextUrl ?>" style="padding: 5px 10px; background-color: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; color: #334155; font-weight: 700; text-decoration: none; <?= ($page >= $totalRiwayatPages) ? 'pointer-events-none opacity-50' : '' ?>">Next &raquo;</a>
                        </div>
                    </div>
                <?php endif; ?>

            <?php endif; ?>

        </div>

        <!-- MODAL KELOLA & HAPUS LOT FISIK GUDANG (KHUSUS ADMIN / CHIEF) -->
        <?php if ($canManageLots): ?>
        <div id="modalManageLots" style="display: none; position: fixed; inset: 0; z-index: 50; background-color: rgba(15, 23, 42, 0.65); backdrop-filter: blur(2px); align-items: center; justify-content: center; padding: 16px;">
            <div style="background-color: #ffffff; border-radius: 14px; max-width: 860px; width: 100%; max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); overflow: hidden; border: 1px solid #cbd5e1;">
                
                <!-- Header Modal -->
                <div style="padding: 14px 18px; background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: #ffffff; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #334155;">
                    <div>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span style="background-color: #dc2626; color: #ffffff; padding: 3px 8px; border-radius: 6px; font-size: 11px; font-weight: 800; display: inline-flex; align-items: center; gap: 4px;">
                                <svg style="width: 12px; height: 12px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                </svg>
                                KELOLA & HAPUS LOT
                            </span>
                            <span id="manageModalPartCode" style="font-family: monospace; font-size: 14px; font-weight: 800; color: #60a5fa;">-</span>
                        </div>
                        <div id="manageModalPartName" style="font-size: 12px; color: #cbd5e1; font-weight: 600; margin-top: 3px;">-</div>
                    </div>
                    <button type="button" onclick="closeManageLotsModal()" style="color: #94a3b8; border: none; background: transparent; font-size: 22px; cursor: pointer; line-height: 1; padding: 4px 8px; border-radius: 6px;" title="Tutup Modal">&times;</button>
                </div>

                <!-- Info Alert -->
                <div style="padding: 10px 18px; background-color: #fff7ed; border-bottom: 1px solid #fed7aa; display: flex; align-items: center; justify-content: space-between; gap: 10px; font-size: 11px; color: #9a3412; flex-wrap: wrap;">
                    <div style="display: flex; align-items: center; gap: 6px;">
                        <svg style="width: 15px; height: 15px; flex-shrink: 0; color: #ea580c;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <span>Centang lot yang ingin dihapus (bisa pilih banyak), atau ubah angka kuantiti pada kolom penyesuaian jika ada selisih pcs fisik.</span>
                    </div>
                    <div id="manageModalTotalReady" style="font-weight: 800; color: #ea580c; white-space: nowrap;">
                        Total: 0 Box (0 pcs)
                    </div>
                </div>

                <!-- Body Modal (Daftar Lot) -->
                <div id="manageModalBody" style="padding: 16px 18px; flex: 1; overflow-y: auto; max-height: calc(90vh - 210px); min-height: 220px; background-color: #ffffff;">
                    <!-- Content populated via AJAX -->
                </div>

                <!-- Footer Modal -->
                <div style="padding: 12px 18px; background-color: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span id="manageSelectedCounter" style="font-size: 12px; font-weight: 800; color: #475569; background-color: #f1f5f9; padding: 4px 10px; border-radius: 6px; border: 1px solid #cbd5e1;">
                            0 lot dipilih (0 pcs)
                        </span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                        <button type="button" onclick="closeManageLotsModal()" style="padding: 7px 14px; background-color: #ffffff; color: #475569; font-weight: 700; font-size: 12px; border-radius: 8px; border: 1px solid #cbd5e1; cursor: pointer;">
                            Tutup
                        </button>
                        <button type="button" id="btnSaveLotQtys" onclick="submitBatchUpdateQty()" disabled style="padding: 7px 14px; background-color: #2563eb; color: #ffffff; font-weight: 700; font-size: 12px; border-radius: 8px; border: none; cursor: not-allowed; opacity: 0.5; display: inline-flex; align-items: center; gap: 6px; transition: all 0.15s;">
                            <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>Simpan Penyesuaian Qty</span>
                        </button>
                        <button type="button" id="btnDeleteSelectedLots" onclick="submitBatchDeleteLots()" disabled style="padding: 7px 16px; background-color: #dc2626; color: #ffffff; font-weight: 700; font-size: 12px; border-radius: 8px; border: none; cursor: not-allowed; opacity: 0.5; display: inline-flex; align-items: center; gap: 6px; transition: all 0.15s;">
                            <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                            </svg>
                            <span id="btnDeleteText">Hapus Terpilih (0)</span>
                        </button>
                    </div>
                </div>

            </div>
        </div>
        <?php endif; ?>

    </main>

    <script>
    // ── Helper: Parser QR Code Scanner Label Pabrik ─────────────────────────
    function parseSafetyStockQR(raw) {
        var obj = {};
        if (!raw) return obj;
        var parts = raw.split('|');
        parts.forEach(function(p) {
            p = p.trim();
            if (p.startsWith('Z1')) obj.Z1 = p.substring(2);
            if (p.startsWith('Z2')) obj.Z2 = p.substring(2);
            if (p.startsWith('Z3')) {
                var valZ3 = parseInt(p.substring(2), 10);
                if (!isNaN(valZ3) && valZ3 > 0) obj.Z3 = valZ3;
            }
            if (p.startsWith('Z4')) obj.Z4 = p.substring(2);
            if (p.startsWith('Z5')) obj.Z5 = p.substring(2);
        });
        return obj;
    }

    // ── Helper: Scroll Timeline via Tombol Panah ────────────────────────────
    function scrollTimelineTrack(trackId, offset) {
        var el = document.getElementById(trackId);
        if (el) {
            el.scrollBy({ left: offset, behavior: 'smooth' });
        }
    }

    // ── Helper: Aktifkan Mouse Wheel Scroll, Drag-to-Scroll & Infinite Prepend ──
    function initTimelineMouseInteractions(track) {
        if (!track || track._hasMouseInteractions) return;
        track._hasMouseInteractions = true;

        // 1. Mouse Wheel: Map roda vertikal (deltaY) ke pergeseran horizontal
        track.addEventListener('wheel', function(e) {
            if (e.deltaY !== 0) {
                e.preventDefault();
                this.scrollLeft += (e.deltaY * 1.5);
            }
        }, { passive: false });

        // 2. Drag-to-Scroll (Klik dan Tahan Geser Mouse)
        var isDown = false;
        var startX = 0;
        var scrollLeft = 0;

        track.addEventListener('mousedown', function(e) {
            if (e.target.tagName === 'BUTTON' || e.target.tagName === 'A') return;
            isDown = true;
            track.style.cursor = 'grabbing';
            startX = e.pageX - track.offsetLeft;
            scrollLeft = track.scrollLeft;
        });

        track.addEventListener('mouseleave', function() {
            isDown = false;
            track.style.cursor = 'grab';
        });

        track.addEventListener('mouseup', function() {
            isDown = false;
            track.style.cursor = 'grab';
        });

        track.addEventListener('mousemove', function(e) {
            if (!isDown) return;
            e.preventDefault();
            var x = e.pageX - track.offsetLeft;
            var walk = (x - startX) * 1.5;
            track.scrollLeft = scrollLeft - walk;
        });

        // 3. Infinite Scroll: Otomatis memuat chunk data sebelumnya saat digeser ke ujung kiri (< 35px)
        track.addEventListener('scroll', function() {
            if (track.scrollLeft < 35 && !track._isLoadingChunk) {
                var btnMore = track.querySelector('.btn-timeline-load-more');
                if (btnMore && !btnMore.disabled) {
                    track._isLoadingChunk = true;
                    btnMore.click();
                    setTimeout(function() { track._isLoadingChunk = false; }, 1500);
                }
            }
        });
    }

    // ── Helper: Muat 50 Transaksi Sebelumnya (Chunking Masa Lalu) ───────────
    function loadMoreTimelineChunk(partCode, nextChunk, btn) {
        var container = btn.closest('.timeline-content-box');
        if (!container) return;

        var track = container.querySelector('.timeline-scroll-track');
        var oldScrollWidth = track ? track.scrollWidth : 0;
        var oldScrollLeft = track ? track.scrollLeft : 0;

        btn.disabled = true;
        btn.innerHTML = '<span>Memuat data...</span>';

        var periodParam = '<?= htmlspecialchars($period) ?>';
        var startParam  = '<?= htmlspecialchars($startDate) ?>';
        var endParam    = '<?= htmlspecialchars($endDate) ?>';
        var url = '<?= base_url("modules/safety_stock/ajax_timeline.php") ?>?part_code=' + encodeURIComponent(partCode) + 
                  '&period=' + encodeURIComponent(periodParam) + 
                  '&start_date=' + encodeURIComponent(startParam) + 
                  '&end_date=' + encodeURIComponent(endParam) + 
                  '&chunk=' + nextChunk;

        fetch(url)
            .then(function(res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.text();
            })
            .then(function(html) {
                container.innerHTML = html;
                var newTrack = container.querySelector('.timeline-scroll-track');
                if (newTrack) {
                    initTimelineMouseInteractions(newTrack);
                    // Pertahankan posisi scroll visual agar mulus tanpa loncat
                    var newScrollWidth = newTrack.scrollWidth;
                    var diff = newScrollWidth - oldScrollWidth;
                    newTrack.scrollLeft = oldScrollLeft + diff;
                }
            })
            .catch(function(err) {
                alert('Gagal memuat transaksi sebelumnya: ' + err.message);
                btn.disabled = false;
                btn.innerHTML = '<span>◄ Coba Lagi</span>';
            });
    }

    // ── Lazy Load & Accordion Toggle untuk Horizontal Timeline Mutasi Part ──
    function togglePartTimeline(partCode, rowId, btn) {
        var row = document.getElementById(rowId);
        if (!row) return;

        var isHidden = (row.style.display === 'none' || getComputedStyle(row).display === 'none');
        var chevron = btn.querySelector('.timeline-chevron');
        var container = row.querySelector('.timeline-content-box');

        if (isHidden) {
            // Tampilkan Baris Accordion
            row.style.display = 'table-row';
            if (chevron) chevron.style.transform = 'rotate(180deg)';
            btn.style.backgroundColor = '#eff6ff';
            btn.style.borderColor = '#93c5fd';
            btn.style.color = '#1d4ed8';

            // Kunci lebar kontainer agar tidak melebarkan tabel induk dan mengaktifkan scrollbar horizontal
            var tableWrap = document.getElementById('mainSafetyStockTableWrap');
            if (tableWrap) {
                var availWidth = Math.max(300, tableWrap.clientWidth - 28);
                container.style.maxWidth = availWidth + 'px';
                container.style.width = availWidth + 'px';
            }

            // Lazy Load data hanya jika belum dimuat (Caching)
            if (container && container.getAttribute('data-loaded') !== '1') {
                container.innerHTML = `
                    <div style="padding: 20px; text-align: center; color: #64748b; font-size: 12px; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 8px;">
                        <svg style="animation: spin 1s linear infinite; width: 16px; height: 16px; color: #2563eb;" fill="none" viewBox="0 0 24 24">
                            <circle style="opacity: 0.25;" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path style="opacity: 0.75;" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span>Memuat kronologi mutasi stok part ${partCode}...</span>
                    </div>
                `;

                var periodParam = '<?= htmlspecialchars($period) ?>';
                var startParam  = '<?= htmlspecialchars($startDate) ?>';
                var endParam    = '<?= htmlspecialchars($endDate) ?>';
                var url = '<?= base_url("modules/safety_stock/ajax_timeline.php") ?>?part_code=' + encodeURIComponent(partCode) + 
                          '&period=' + encodeURIComponent(periodParam) + 
                          '&start_date=' + encodeURIComponent(startParam) + 
                          '&end_date=' + encodeURIComponent(endParam) + 
                          '&chunk=30';

                fetch(url)
                    .then(function(res) {
                        if (!res.ok) throw new Error('HTTP ' + res.status);
                        return res.text();
                    })
                    .then(function(html) {
                        container.innerHTML = html;
                        container.setAttribute('data-loaded', '1');
                        var track = container.querySelector('.timeline-scroll-track');
                        if (track) {
                            initTimelineMouseInteractions(track);
                        }
                    })
                    .catch(function(err) {
                        container.innerHTML = `
                            <div style="padding: 16px; text-align: center; color: #dc2626; font-size: 12px; font-weight: 700;">
                                Gagal memuat timeline (${err.message}). Silakan coba klik tombol kembali.
                            </div>
                        `;
                    });
            } else {
                // Pastikan listener tetap aktif jika baris dibuka kembali
                var existingTrack = container.querySelector('.timeline-scroll-track');
                if (existingTrack) {
                    initTimelineMouseInteractions(existingTrack);
                }
            }
        } else {
            // Tutup Baris Accordion
            row.style.display = 'none';
            if (chevron) chevron.style.transform = 'rotate(0deg)';
            btn.style.backgroundColor = '#f8fafc';
            btn.style.borderColor = '#cbd5e1';
            btn.style.color = '#475569';
        }
    }

    // Sinkronkan lebar kontainer timeline saat ukuran jendela browser diubah
    window.addEventListener('resize', function() {
        var tableWrap = document.getElementById('mainSafetyStockTableWrap');
        if (!tableWrap) return;
        var availWidth = Math.max(300, tableWrap.clientWidth - 28);
        document.querySelectorAll('.timeline-content-box').forEach(function(box) {
            box.style.maxWidth = availWidth + 'px';
            box.style.width = availWidth + 'px';
        });
    });

    // Handler untuk Scanner Barcode pada Kotak Pencarian Safety Stock
    function handleSafetyStockSearchScan(e) {
        if (e.key === 'Enter') {
            var input = document.getElementById('safetyStockSearchInput');
            if (!input) return;
            var val = input.value.trim();
            if (val.includes('|') || val.startsWith('Z1') || val.startsWith('Z2') || val.startsWith('Z5')) {
                var qr = parseSafetyStockQR(val);
                if (qr.Z1) {
                    input.value = qr.Z1;
                } else if (qr.Z5) {
                    input.value = qr.Z5;
                } else if (qr.Z2) {
                    input.value = qr.Z2;
                }
            }
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Modal Kelola & Hapus Lot Fisik Safety Stock (Batch & Inline Edit)
    // ═════════════════════════════════════════════════════════════════════════
    var currentManagePartCode = '';
    var currentManagePartName = '';
    var currentManageLotsData = [];

    function openManageLotsModal(partCode, partName) {
        var modal = document.getElementById('modalManageLots');
        if (!modal) return;

        currentManagePartCode = partCode;
        currentManagePartName = partName || partCode;
        currentManageLotsData = [];

        document.getElementById('manageModalPartCode').textContent = partCode;
        document.getElementById('manageModalPartName').textContent = currentManagePartName;
        document.getElementById('manageModalTotalReady').textContent = 'Memuat data...';

        var body = document.getElementById('manageModalBody');
        body.innerHTML = `
            <div style="padding: 40px; text-align: center; color: #64748b; font-size: 13px; font-weight: 600; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px;">
                <svg style="animation: spin 1s linear infinite; width: 24px; height: 24px; color: #dc2626;" fill="none" viewBox="0 0 24 24">
                    <circle style="opacity: 0.25;" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path style="opacity: 0.75;" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span>Mengambil data lot fisik siap pakai untuk part <b>${escapeHtml(partCode)}</b>...</span>
            </div>
        `;

        modal.style.display = 'flex';
        updateManageLotsSelectionState();

        var url = '<?= base_url("modules/safety_stock/ajax_get_lots.php") ?>?part_code=' + encodeURIComponent(partCode);
        fetch(url)
            .then(function(res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            })
            .then(function(data) {
                if (!data.success) {
                    throw new Error(data.message || 'Gagal memuat lot.');
                }
                currentManageLotsData = data.lots || [];
                renderManageLotsTable(data);
            })
            .catch(function(err) {
                body.innerHTML = `
                    <div style="padding: 30px; text-align: center; color: #dc2626;">
                        <div style="font-weight: 800; font-size: 14px; margin-bottom: 6px;">Gagal Memuat Data Lot</div>
                        <div style="font-size: 12px; color: #64748b;">${escapeHtml(err.message)}</div>
                        <button type="button" onclick="openManageLotsModal('${escapeHtml(partCode)}', '${escapeHtml(partName)}')" 
                                style="margin-top: 14px; padding: 6px 14px; background-color: #2563eb; color: #ffffff; border-radius: 6px; font-weight: 700; font-size: 11px; border: none; cursor: pointer;">
                            Coba Lagi
                        </button>
                    </div>
                `;
            });
    }

    function closeManageLotsModal() {
        var modal = document.getElementById('modalManageLots');
        if (modal) {
            modal.style.display = 'none';
        }
    }

    function renderManageLotsTable(data) {
        var body = document.getElementById('manageModalBody');
        var lots = data.lots || [];

        document.getElementById('manageModalTotalReady').textContent = 
            'Total: ' + lots.length + ' Box (' + Number(data.total_qty || 0).toLocaleString() + ' pcs)';

        if (lots.length === 0) {
            body.innerHTML = `
                <div style="padding: 40px; text-align: center; color: #94a3b8;">
                    <div style="font-weight: 800; color: #334155; font-size: 14px;">Tidak Ada Lot Fisik Ready di Rak</div>
                    <div style="font-size: 12px; color: #64748b; margin-top: 4px;">Seluruh stok untuk part ini mungkin sudah habis atau telah teralokasi untuk pemotongan Kanban.</div>
                </div>
            `;
            updateManageLotsSelectionState();
            return;
        }

        var html = `
            <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 12px; color: #334155;">
                <thead style="background-color: #f8fafc; border-bottom: 2px solid #e2e8f0; font-size: 10px; font-weight: 800; color: #475569; text-transform: uppercase;">
                    <tr>
                        <th style="padding: 8px 10px; width: 36px; text-align: center;">
                            <input type="checkbox" id="manageSelectAllMaster" onchange="toggleSelectAllManageLots(this)" style="cursor: pointer; width: 15px; height: 15px;" title="Pilih Semua Lot">
                        </th>
                        <th style="padding: 8px 10px; width: 36px; text-align: center;">No</th>
                        <th style="padding: 8px 10px; min-width: 140px;">No. Lot / No. Ref</th>
                        <th style="padding: 8px 10px; width: 110px;">Asal Box</th>
                        <th style="padding: 8px 10px; width: 130px;">Waktu Masuk</th>
                        <th style="padding: 8px 10px; text-align: right; width: 110px;">Qty Asli</th>
                        <th style="padding: 8px 10px; text-align: right; width: 140px;">Sesuaikan Qty</th>
                    </tr>
                </thead>
                <tbody>
        `;

        lots.forEach(function(l, idx) {
            var isSisa = (l.source_type === 'kanban_excess' || l.source_type === 'split_leftover');
            var badgeStyle = isSisa 
                ? 'background-color: #fef3c7; color: #b45309; border: 1px solid #fde68a;' 
                : 'background-color: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd;';
            
            html += `
                <tr style="border-bottom: 1px solid #f1f5f9; transition: background-color 0.1s;" class="manage-lot-row" id="manage-lot-row-${l.lot_id}-${l.session_id}">
                    <td style="padding: 8px 10px; text-align: center;">
                        <input type="checkbox" class="manage-lot-checkbox" 
                               data-lot-id="${l.lot_id}" 
                               data-session-id="${l.session_id}" 
                               data-qty="${l.qty}" 
                               onchange="updateManageLotsSelectionState()" 
                               style="cursor: pointer; width: 15px; height: 15px;">
                    </td>
                    <td style="padding: 8px 10px; text-align: center; color: #64748b; font-weight: 700;">
                        ${idx + 1}
                    </td>
                    <td style="padding: 8px 10px;">
                        <div style="font-weight: 800; color: #1e293b; font-family: monospace;">Lot: ${escapeHtml(l.lot_number)}</div>
                        <div style="font-size: 11px; color: #64748b; font-family: monospace;">Ref: ${escapeHtml(l.ref_number)}</div>
                    </td>
                    <td style="padding: 8px 10px;">
                        <span style="font-size: 10px; font-weight: 800; padding: 2px 7px; border-radius: 6px; display: inline-block; ${badgeStyle}">
                            ${escapeHtml(l.source_label)}
                        </span>
                    </td>
                    <td style="padding: 8px 10px; font-size: 11px; color: #64748b; white-space: nowrap;">
                        ${escapeHtml(l.date_formatted)}
                    </td>
                    <td style="padding: 8px 10px; text-align: right; font-weight: 800; color: #0f172a; font-family: monospace; font-size: 13px;">
                        ${Number(l.qty).toLocaleString()} <span style="font-size: 10px; font-weight: 600; color: #64748b;">pcs</span>
                    </td>
                    <td style="padding: 8px 10px; text-align: right;">
                        <input type="number" min="1" 
                               value="${l.qty}" 
                               data-orig-qty="${l.qty}" 
                               data-lot-id="${l.lot_id}" 
                               data-session-id="${l.session_id}" 
                               class="manage-lot-qty-input" 
                               oninput="markQtyChanged(this)" 
                               style="width: 90px; padding: 4px 8px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; font-weight: 700; font-family: monospace; text-align: right; outline: none; background-color: #ffffff;"
                               title="Ketik angka baru jika ingin mengubah jumlah pcs fisik">
                    </td>
                </tr>
            `;
        });

        html += `
                </tbody>
            </table>
        `;

        body.innerHTML = html;
        updateManageLotsSelectionState();
    }

    function toggleSelectAllManageLots(masterCheckbox) {
        var checkboxes = document.querySelectorAll('.manage-lot-checkbox');
        checkboxes.forEach(function(cb) {
            cb.checked = masterCheckbox.checked;
            var row = cb.closest('tr');
            if (row) {
                row.style.backgroundColor = cb.checked ? '#fff1f2' : 'transparent';
            }
        });
        updateManageLotsSelectionState();
    }

    function updateManageLotsSelectionState() {
        var checkboxes = document.querySelectorAll('.manage-lot-checkbox');
        var checkedBoxes = document.querySelectorAll('.manage-lot-checkbox:checked');
        var master = document.getElementById('manageSelectAllMaster');

        var totalSelectedQty = 0;
        checkedBoxes.forEach(function(cb) {
            totalSelectedQty += parseInt(cb.getAttribute('data-qty') || 0, 10);
            var row = cb.closest('tr');
            if (row) row.style.backgroundColor = '#fff1f2';
        });

        document.querySelectorAll('.manage-lot-checkbox:not(:checked)').forEach(function(cb) {
            var row = cb.closest('tr');
            if (row) row.style.backgroundColor = 'transparent';
        });

        if (master && checkboxes.length > 0) {
            master.checked = (checkboxes.length === checkedBoxes.length);
            master.indeterminate = (checkedBoxes.length > 0 && checkedBoxes.length < checkboxes.length);
        }

        var counterEl = document.getElementById('manageSelectedCounter');
        if (counterEl) {
            counterEl.textContent = checkedBoxes.length + ' lot dipilih (' + totalSelectedQty.toLocaleString() + ' pcs)';
        }

        var btnDel = document.getElementById('btnDeleteSelectedLots');
        var btnDelText = document.getElementById('btnDeleteText');
        if (btnDel) {
            if (checkedBoxes.length > 0) {
                btnDel.disabled = false;
                btnDel.style.cursor = 'pointer';
                btnDel.style.opacity = '1';
                btnDelText.textContent = 'Hapus Terpilih (' + checkedBoxes.length + ')';
            } else {
                btnDel.disabled = true;
                btnDel.style.cursor = 'not-allowed';
                btnDel.style.opacity = '0.5';
                btnDelText.textContent = 'Hapus Terpilih (0)';
            }
        }
    }

    function markQtyChanged(inputEl) {
        var orig = parseInt(inputEl.getAttribute('data-orig-qty'), 10);
        var curr = parseInt(inputEl.value, 10);

        if (!isNaN(curr) && curr !== orig && curr >= 1) {
            inputEl.style.backgroundColor = '#eff6ff';
            inputEl.style.borderColor = '#2563eb';
            inputEl.style.color = '#1d4ed8';
        } else {
            inputEl.style.backgroundColor = '#ffffff';
            inputEl.style.borderColor = '#cbd5e1';
            inputEl.style.color = '#0f172a';
        }

        var anyChanged = false;
        document.querySelectorAll('.manage-lot-qty-input').forEach(function(inp) {
            var o = parseInt(inp.getAttribute('data-orig-qty'), 10);
            var c = parseInt(inp.value, 10);
            if (!isNaN(c) && c !== o && c >= 1) anyChanged = true;
        });

        var btnSave = document.getElementById('btnSaveLotQtys');
        if (btnSave) {
            btnSave.disabled = !anyChanged;
            btnSave.style.cursor = anyChanged ? 'pointer' : 'not-allowed';
            btnSave.style.opacity = anyChanged ? '1' : '0.5';
        }
    }

    function submitBatchDeleteLots() {
        var checkedBoxes = document.querySelectorAll('.manage-lot-checkbox:checked');
        if (checkedBoxes.length === 0) {
            alert('Pilih minimal satu lot untuk dihapus.');
            return;
        }

        var lotIds = [];
        var sessionIds = [];
        var totalQty = 0;

        checkedBoxes.forEach(function(cb) {
            var lId = parseInt(cb.getAttribute('data-lot-id') || 0, 10);
            var sId = parseInt(cb.getAttribute('data-session-id') || 0, 10);
            var q = parseInt(cb.getAttribute('data-qty') || 0, 10);
            if (lId > 0) {
                lotIds.push(lId);
            } else if (sId > 0) {
                sessionIds.push(sId);
            }
            totalQty += q;
        });

        var confirmMsg = 'Apakah Anda YAKIN ingin menghapus ' + checkedBoxes.length + ' box/lot (' + totalQty.toLocaleString() + ' pcs) dari stok gudang?\n\n' +
                         '• Part: ' + currentManagePartCode + '\n' +
                         '• Stok di rak dan 4 Kartu KPI akan otomatis dikurangi secara realtime.\n' +
                         '• Data yang dihapus tidak dapat dibatalkan.';

        if (!confirm(confirmMsg)) return;

        var btnDel = document.getElementById('btnDeleteSelectedLots');
        if (btnDel) {
            btnDel.disabled = true;
            btnDel.innerHTML = '<span>Menghapus data...</span>';
        }

        var formData = new FormData();
        formData.append('action', 'batch_delete_lots');
        formData.append('part_code', currentManagePartCode);
        formData.append('is_ajax', '1');
        formData.append('lot_ids', JSON.stringify(lotIds));
        formData.append('session_ids', JSON.stringify(sessionIds));

        fetch('<?= base_url("modules/safety_stock/action.php") ?>', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function(res) {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
        })
        .then(function(data) {
            if (data.success) {
                alert(data.message || 'Lot berhasil dihapus.');
                location.reload();
            } else {
                alert('Gagal: ' + (data.message || 'Terjadi kesalahan sistem.'));
                if (btnDel) {
                    btnDel.disabled = false;
                    btnDel.innerHTML = '<span id="btnDeleteText">Hapus Terpilih (' + checkedBoxes.length + ')</span>';
                }
            }
        })
        .catch(function(err) {
            alert('Kesalahan jaringan: ' + err.message);
            if (btnDel) {
                btnDel.disabled = false;
                btnDel.innerHTML = '<span id="btnDeleteText">Hapus Terpilih (' + checkedBoxes.length + ')</span>';
            }
        });
    }

    function submitBatchUpdateQty() {
        var changedLots = {};
        var changedSessions = {};
        var changeCount = 0;

        document.querySelectorAll('.manage-lot-qty-input').forEach(function(inp) {
            var orig = parseInt(inp.getAttribute('data-orig-qty'), 10);
            var curr = parseInt(inp.value, 10);
            var lId  = parseInt(inp.getAttribute('data-lot-id') || 0, 10);
            var sId  = parseInt(inp.getAttribute('data-session-id') || 0, 10);

            if (!isNaN(curr) && curr !== orig) {
                if (curr < 1) {
                    alert('Kuantiti harus minimal 1 pcs.');
                    inp.focus();
                    return false;
                }
                if (lId > 0) {
                    changedLots[lId] = curr;
                } else if (sId > 0) {
                    changedSessions[sId] = curr;
                }
                changeCount++;
            }
        });

        if (changeCount === 0) {
            alert('Tidak ada kuantiti yang diubah.');
            return;
        }

        if (!confirm('Simpan penyesuaian kuantiti untuk ' + changeCount + ' box/lot pada part ' + currentManagePartCode + '?')) {
            return;
        }

        var btnSave = document.getElementById('btnSaveLotQtys');
        if (btnSave) {
            btnSave.disabled = true;
            btnSave.innerHTML = '<span>Menyimpan...</span>';
        }

        var formData = new FormData();
        formData.append('action', 'batch_update_qty');
        formData.append('part_code', currentManagePartCode);
        formData.append('is_ajax', '1');
        formData.append('lot_qtys', JSON.stringify(changedLots));
        formData.append('session_qtys', JSON.stringify(changedSessions));

        fetch('<?= base_url("modules/safety_stock/action.php") ?>', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function(res) {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
        })
        .then(function(data) {
            if (data.success) {
                alert(data.message || 'Kuantiti berhasil diperbarui.');
                location.reload();
            } else {
                alert('Gagal: ' + (data.message || 'Terjadi kesalahan sistem.'));
                if (btnSave) {
                    btnSave.disabled = false;
                    btnSave.innerHTML = '<span>Simpan Penyesuaian Qty</span>';
                }
            }
        })
        .catch(function(err) {
            alert('Kesalahan jaringan: ' + err.message);
            if (btnSave) {
                btnSave.disabled = false;
                btnSave.innerHTML = '<span>Simpan Penyesuaian Qty</span>';
            }
        });
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // Close modal on escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeManageLotsModal();
        }
    });
    </script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
