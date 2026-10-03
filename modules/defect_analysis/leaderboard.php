<?php
$breadcrumbCategory = "OPERASIONAL";
$pageTitle          = "Leaderboard & Pareto Defect";
$pageSubtitle       = "Peringkat cacat mutu, analisis dominasi Pareto (Top 3 / 5 / 10 / All), dan sebaran frekuensi — PT. Surya Technology Industri";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';

$pdo = getDB();

// ── Filter Resolvers (Unit, Leaderboard Rank, Date, & Customer) ─────────────
$selectedCustomer = sanitize($_GET['customer'] ?? '');
$selectedUnit     = sanitize($_GET['unit'] ?? 'pcs');
if (!in_array($selectedUnit, ['pcs', 'lot'])) {
    $selectedUnit = 'pcs';
}

$selectedTopRank  = sanitize($_GET['top_rank'] ?? '5');
if (!in_array($selectedTopRank, ['3', '5', '10', 'all'])) {
    $selectedTopRank = '5';
}

$monthNames = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];

// ── Metadata Cache (Years & Customers) ──────────────────────────────────────
$_metaCacheKey = 'oqc_defect_meta_' . date('YmdH');
if (!isset($_SESSION[$_metaCacheKey]) && $pdo) {
    $_cache = [];
    try {
        $stmtYears = $pdo->query("SELECT DISTINCT YEAR(started_at) AS y FROM inspection_sessions WHERE started_at IS NOT NULL ORDER BY y ASC");
        $_cache['years'] = array_values(array_filter(array_map('intval', $stmtYears->fetchAll(PDO::FETCH_COLUMN))));
    } catch (Exception $e) { $_cache['years'] = []; }
    try {
        $stmtCust = $pdo->query("SELECT DISTINCT name FROM master_customers UNION SELECT DISTINCT customer FROM kanban_items WHERE customer IS NOT NULL AND customer != '' ORDER BY name ASC");
        $_cache['customers'] = array_values(array_filter(array_map('trim', $stmtCust->fetchAll(PDO::FETCH_COLUMN))));
    } catch (Exception $e) { $_cache['customers'] = []; }
    try {
        $stmtDefCount = $pdo->query("SELECT COUNT(*) FROM defect_types");
        $_cache['total_master_defects'] = (int)$stmtDefCount->fetchColumn();
    } catch (Exception $e) { $_cache['total_master_defects'] = 6; }

    $_SESSION[$_metaCacheKey] = $_cache;
    foreach ($_SESSION as $_k => $_v) {
        if (strpos($_k, 'oqc_defect_meta_') === 0 && $_k !== $_metaCacheKey) {
            unset($_SESSION[$_k]);
        }
    }
}
$_meta = $_SESSION[$_metaCacheKey] ?? ['years' => [], 'customers' => [], 'total_master_defects' => 6];

$dbYears = !empty($_meta['years']) ? $_meta['years'] : [(int)date('Y')];
$availableYears = $dbYears;
$currentYear = (int)date('Y');
if (!in_array($currentYear, $availableYears)) {
    $availableYears[] = $currentYear;
}
rsort($availableYears);
$availableYears = array_values(array_unique(array_map('intval', $availableYears)));
$totalMasterDefects = $_meta['total_master_defects'] ?? 6;
$customerOptions = $_meta['customers'] ?? [];

// ── Date Preset & Range Resolution ──────────────────────────────────────────
$filterType = sanitize($_GET['filter_type'] ?? '');
$selectedMonth = isset($_GET['month']) && is_numeric($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$selectedYear  = isset($_GET['year']) && is_numeric($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

if ($filterType === 'all_years') {
    $minYear = min($dbYears);
    $maxYear = max($dbYears);
    if ($minYear === $maxYear) {
        $minYear = $maxYear - 1;
    }
    $startDate = sprintf('%04d-01-01', $minYear);
    $endDate   = sprintf('%04d-12-31', $maxYear);
    $presetFilter = 'all_years';
    $filterTitleDesc = ($minYear !== $maxYear) ? ("Komparasi Semua Tahun (" . $minYear . " – " . $maxYear . ")") : ("Komparasi Tahunan (" . $minYear . ")");
} elseif ($filterType === 'month_year') {
    $startDate = sprintf('%04d-%02d-01', $selectedYear, $selectedMonth);
    $endDate   = date('Y-m-t', strtotime($startDate));
    $presetFilter = 'month_year';
    $filterTitleDesc = "Bulan " . ($monthNames[$selectedMonth] ?? $selectedMonth) . " " . $selectedYear;
} elseif ($filterType === 'custom' || (!empty($_GET['start_date']) && !empty($_GET['end_date']))) {
    $startDate    = sanitize($_GET['start_date'] ?? date('Y-m-01'));
    $endDate      = sanitize($_GET['end_date'] ?? date('Y-m-d'));
    $presetFilter = 'custom';
    $filterType   = 'custom';
    $filterTitleDesc = date('d M Y', strtotime($startDate)) . ' – ' . date('d M Y', strtotime($endDate));
} else {
    $presetFilter = sanitize($_GET['preset'] ?? 'bulanan');
    switch ($presetFilter) {
        case 'hari_ini':
            $startDate = date('Y-m-d');
            $endDate   = date('Y-m-d');
            $filterTitleDesc = "Hari Ini (" . date('d M Y') . ")";
            break;
        case 'mingguan':
            $startDate = date('Y-m-d', strtotime('monday this week'));
            $endDate   = date('Y-m-d');
            $filterTitleDesc = "Minggu Ini (" . date('d M', strtotime($startDate)) . " – " . date('d M Y', strtotime($endDate)) . ")";
            break;
        case 'tahunan':
            $startDate = date('Y-01-01');
            $endDate   = date('Y-m-d');
            $filterTitleDesc = "Tahun Ini (" . date('Y') . ")";
            break;
        default:
            $presetFilter = 'bulanan';
            $startDate    = date('Y-m-01');
            $endDate      = date('Y-m-d');
            $filterTitleDesc = "Bulan Ini (" . ($monthNames[(int)date('n')] ?? date('F')) . " " . date('Y') . ")";
    }
}

// ── Database Query Aggregation ──────────────────────────────────────────────
$allDefects = [];
$grandTotalCount = 0;
$grandTotalPcs   = 0;
$grandTotalLots  = 0;
$distinctDefectCount = 0;

if ($pdo) {
    try {
        $p = [
            ':sd'      => $startDate,
            ':ed_next' => date('Y-m-d', strtotime($endDate . ' +1 day'))
        ];
        $sumCustCond = '';
        if ($selectedCustomer !== '') {
            $sumCustCond = ' AND dds.customer = :customer ';
            $p[':customer'] = $selectedCustomer;
        }

        $orderCol = ($selectedUnit === 'lot') ? 'total_lots' : 'total_pcs';
        $secondaryCol = ($selectedUnit === 'lot') ? 'total_pcs' : 'total_lots';

        $stmtDef = $pdo->prepare("SELECT 
                                    dt.id, 
                                    dt.name, 
                                    COALESCE(SUM(dds.qty_ng), 0) AS total_pcs, 
                                    COALESCE(SUM(dds.lot_count), 0) AS total_lots
                                  FROM oqc_daily_defect_summary dds
                                  INNER JOIN defect_types dt ON dds.defect_type_id = dt.id
                                  WHERE dds.summary_date >= :sd AND dds.summary_date < :ed_next {$sumCustCond}
                                  GROUP BY dt.id, dt.name
                                  HAVING (total_pcs > 0 OR total_lots > 0)
                                  ORDER BY {$orderCol} DESC, {$secondaryCol} DESC, dt.name ASC");
        $stmtDef->execute($p);
        $rawDefects = $stmtDef->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rawDefects as $r) {
            $grandTotalPcs  += (int)$r['total_pcs'];
            $grandTotalLots += (int)$r['total_lots'];
        }
        $grandTotalCount = ($selectedUnit === 'lot') ? $grandTotalLots : $grandTotalPcs;
        $distinctDefectCount = count($rawDefects);

        $runningTotal = 0;
        foreach ($rawDefects as $idx => $r) {
            $count = ($selectedUnit === 'lot') ? (int)$r['total_lots'] : (int)$r['total_pcs'];
            $pct = ($grandTotalCount > 0) ? round(($count / $grandTotalCount) * 100, 1) : 0.0;
            $runningTotal += $pct;
            $cumulative = ($idx === $distinctDefectCount - 1 && $runningTotal > 99.0) ? 100.0 : round(min(100.0, $runningTotal), 1);
            $isVital = ($cumulative <= 80.0) || ($runningTotal - $pct < 80.0);

            $allDefects[] = array_merge($r, [
                'rank'        => $idx + 1,
                'count'       => $count,
                'percentage'  => $pct,
                'cumulative'  => $cumulative,
                'is_vital'    => $isVital
            ]);
        }
    } catch (PDOException $e) {}
}

// Slice by selected rank limit
$displayDefects = $allDefects;
if ($selectedTopRank !== 'all' && is_numeric($selectedTopRank)) {
    $displayDefects = array_slice($allDefects, 0, (int)$selectedTopRank);
}

// Key metrics
$topDefectName = !empty($allDefects[0]['name']) ? $allDefects[0]['name'] : 'Nihil / Zero Defect';
$topDefectCount = !empty($allDefects[0]['count']) ? (int)$allDefects[0]['count'] : 0;
$topDefectRatio = !empty($allDefects[0]['percentage']) ? $allDefects[0]['percentage'] : 0.0;
$unitLabel = ($selectedUnit === 'lot') ? 'Lot' : 'Pcs';
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col transition-all duration-300 min-h-screen bg-slate-100">
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <!-- Main Content Container with standard padding -->
    <div id="defect-body" class="flex-1 p-4 space-y-4 overflow-y-auto">
        <?= render_flash() ?>

        <!-- ── Filter Bar (Solid Opaque, Corporate Standard) ────────────────── -->
        <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:12px 16px;box-shadow:0 1px 3px rgba(0,0,0,0.03);display:flex;flex-direction:column;gap:10px;">
            
            <!-- ROW 1: Preset Pills, Unit Toggle, Rank Limit Selector, & Print Button -->
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding-bottom:10px;border-bottom:1px solid #f1f5f9;">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    
                    <!-- Preset Date Pills -->
                    <div style="display:flex;align-items:center;gap:3px;background:#f1f5f9;padding:3px;border-radius:9px;border:1px solid #e2e8f0;">
                        <?php 
                        $presets = ['hari_ini' => 'Hari Ini', 'mingguan' => 'Mingguan', 'bulanan' => 'Bulanan', 'tahunan' => 'Tahunan'];
                        foreach ($presets as $k => $lbl): 
                            $active = ($presetFilter === $k); 
                            $presetUrl = "?preset=" . $k 
                                       . ($selectedCustomer !== '' ? '&customer=' . urlencode($selectedCustomer) : '')
                                       . ($selectedUnit !== 'pcs' ? '&unit=' . urlencode($selectedUnit) : '')
                                       . ($selectedTopRank !== '5' ? '&top_rank=' . urlencode($selectedTopRank) : '');
                        ?>
                            <a href="<?= $presetUrl ?>"
                               style="padding:4px 11px;border-radius:6px;font-size:11px;font-weight:700;text-decoration:none;transition:all 0.2s;
                                      background:<?= $active ? '#2563eb' : 'transparent' ?>;
                                      color:<?= $active ? '#ffffff' : '#64748b' ?>;
                                      <?= $active ? 'box-shadow:0 1px 3px rgba(37,99,235,0.25);' : '' ?>">
                                <?= $lbl ?>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <!-- Unit Segmented Toggle (Pcs vs Lot) -->
                    <div style="display:flex;align-items:center;gap:3px;background:#f1f5f9;padding:3px;border-radius:9px;border:1px solid #e2e8f0;" title="Pilih Satuan Analisis Cacat">
                        <?php 
                        $baseParams = ($presetFilter !== 'custom' ? '&preset=' . $presetFilter : '') 
                                    . ($selectedCustomer !== '' ? '&customer=' . urlencode($selectedCustomer) : '')
                                    . ($selectedTopRank !== '5' ? '&top_rank=' . urlencode($selectedTopRank) : '')
                                    . (!empty($_GET['start_date']) ? '&start_date=' . urlencode($_GET['start_date']) . '&end_date=' . urlencode($_GET['end_date']) : '');
                        $urlUnitPcs = "?unit=pcs" . $baseParams;
                        $urlUnitLot = "?unit=lot" . $baseParams;
                        $isPcs = ($selectedUnit === 'pcs');
                        $isLot = ($selectedUnit === 'lot');
                        ?>
                        <a href="<?= $urlUnitPcs ?>" 
                           style="padding:4px 11px;border-radius:6px;font-size:11px;font-weight:800;text-decoration:none;transition:all 0.2s;
                                  background:<?= $isPcs ? '#0f172a' : 'transparent' ?>;
                                  color:<?= $isPcs ? '#ffffff' : '#64748b' ?>;
                                  <?= $isPcs ? 'box-shadow:0 1px 3px rgba(15,23,42,0.25);' : '' ?>">
                            PCS
                        </a>
                        <a href="<?= $urlUnitLot ?>" 
                           style="padding:4px 11px;border-radius:6px;font-size:11px;font-weight:800;text-decoration:none;transition:all 0.2s;
                                  background:<?= $isLot ? '#d97706' : 'transparent' ?>;
                                  color:<?= $isLot ? '#ffffff' : '#64748b' ?>;
                                  <?= $isLot ? 'box-shadow:0 1px 3px rgba(217,119,6,0.3);' : '' ?>">
                            LOT
                        </a>
                    </div>

                    <!-- Rank Limit Pills (Top 3 / 5 / 10 / All) -->
                    <div style="display:flex;align-items:center;gap:3px;background:#f1f5f9;padding:3px;border-radius:9px;border:1px solid #e2e8f0;" title="Pilih Limit Peringkat Leaderboard">
                        <span style="font-size:10px;font-weight:800;color:#94a3b8;padding:0 5px;text-transform:uppercase;">Rank:</span>
                        <?php 
                        $rankOptions = ['3' => 'Top 3', '5' => 'Top 5', '10' => 'Top 10', 'all' => 'Semua'];
                        foreach ($rankOptions as $rk => $rLbl):
                            $activeRank = ($selectedTopRank === (string)$rk);
                            $urlRank = "?top_rank=" . $rk 
                                     . ($selectedUnit !== 'pcs' ? '&unit=' . urlencode($selectedUnit) : '')
                                     . ($selectedCustomer !== '' ? '&customer=' . urlencode($selectedCustomer) : '')
                                     . ($presetFilter !== 'custom' ? '&preset=' . $presetFilter : '')
                                     . (!empty($_GET['start_date']) ? '&start_date=' . urlencode($_GET['start_date']) . '&end_date=' . urlencode($_GET['end_date']) : '');
                        ?>
                            <a href="<?= $urlRank ?>" 
                               style="padding:4px 9px;border-radius:6px;font-size:11px;font-weight:700;text-decoration:none;transition:all 0.2s;
                                      background:<?= $activeRank ? '#4f46e5' : 'transparent' ?>;
                                      color:<?= $activeRank ? '#ffffff' : '#64748b' ?>;
                                      <?= $activeRank ? 'box-shadow:0 1px 3px rgba(79,70,229,0.25);' : '' ?>">
                                <?= $rLbl ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Print Action Button -->
                <button type="button" onclick="window.print()" 
                        style="display:inline-flex;align-items:center;gap:6px;padding:6px 14px;background:#1e293b;color:#ffffff;font-size:11px;font-weight:700;border-radius:8px;border:none;cursor:pointer;box-shadow:0 1px 3px rgba(0,0,0,0.15);transition:all 0.2s;"
                        onmouseover="this.style.background='#0f172a'" onmouseout="this.style.background='#1e293b'">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                    </svg>
                    Cetak Laporan
                </button>
            </div>

            <!-- ROW 2: Customer, Date Period Mode, Date Controls, & Reset -->
            <form action="" method="GET" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:0;">
                <input type="hidden" name="unit" value="<?= htmlspecialchars($selectedUnit) ?>">
                <input type="hidden" name="top_rank" value="<?= htmlspecialchars($selectedTopRank) ?>">

                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <!-- Customer Selector -->
                    <div style="display:flex;align-items:center;gap:5px;">
                        <span style="font-size:11px;font-weight:700;color:#475569;">Customer:</span>
                        <select name="customer" onchange="this.form.submit()" class="form-input text-xs" style="padding:4px 8px;border-radius:7px;font-weight:600;min-width:140px;border:1px solid #cbd5e1;">
                            <option value="">-- Semua Customer --</option>
                            <?php foreach ($customerOptions as $cOpt): ?>
                                <option value="<?= htmlspecialchars($cOpt) ?>" <?= $selectedCustomer === $cOpt ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cOpt) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Filter Mode Selector -->
                    <div style="display:flex;align-items:center;gap:5px;">
                        <span style="font-size:11px;font-weight:700;color:#475569;">Periode:</span>
                        <select name="filter_type" id="defectFilterTypeSelect" onchange="switchDefectDateFilter(this.value)" class="form-input text-xs" style="padding:4px 8px;border-radius:7px;font-weight:700;border:1px solid #cbd5e1;background:#f8fafc;color:#1e293b;">
                            <option value="preset" <?= in_array($presetFilter, ['hari_ini','mingguan','bulanan','tahunan']) ? 'selected' : '' ?>>Preset Cepat</option>
                            <option value="month_year" <?= $presetFilter === 'month_year' ? 'selected' : '' ?>>Pilih Bulan & Tahun</option>
                            <option value="all_years" <?= $presetFilter === 'all_years' ? 'selected' : '' ?>>Komparasi Semua Tahun (YoY)</option>
                            <option value="custom" <?= $presetFilter === 'custom' ? 'selected' : '' ?>>Kustom Tanggal</option>
                        </select>
                    </div>

                    <!-- Container: Month & Year -->
                    <div id="containerDefectMonthYear" style="display:<?= $presetFilter === 'month_year' ? 'inline-flex' : 'none' ?>;align-items:center;gap:5px;">
                        <select name="month" class="form-input text-xs" style="padding:4px 8px;border-radius:7px;border:1px solid #cbd5e1;font-weight:600;">
                            <?php foreach ($monthNames as $mNum => $mName): ?>
                                <option value="<?= $mNum ?>" <?= $selectedMonth === $mNum ? 'selected' : '' ?>><?= $mName ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="year" class="form-input text-xs" style="padding:4px 8px;border-radius:7px;border:1px solid #cbd5e1;font-weight:600;">
                            <?php foreach ($availableYears as $y): ?>
                                <option value="<?= $y ?>" <?= $selectedYear === (int)$y ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn-secondary text-xs font-semibold" style="padding:4px 12px;border-radius:7px;">Terapkan</button>
                    </div>

                    <!-- Container: Custom Date Range -->
                    <div id="containerDefectCustomDate" style="display:<?= $presetFilter === 'custom' ? 'inline-flex' : 'none' ?>;align-items:center;gap:5px;">
                        <input type="date" name="start_date" value="<?= $presetFilter === 'custom' ? htmlspecialchars($startDate) : '' ?>" class="form-input text-xs" style="width:120px;padding:4px 8px;border-radius:7px;border:1px solid #cbd5e1;">
                        <span style="color:#94a3b8;font-weight:bold;">&ndash;</span>
                        <input type="date" name="end_date"   value="<?= $presetFilter === 'custom' ? htmlspecialchars($endDate) : '' ?>"   class="form-input text-xs" style="width:120px;padding:4px 8px;border-radius:7px;border:1px solid #cbd5e1;">
                        <button type="submit" class="btn-secondary text-xs font-semibold" style="padding:4px 12px;border-radius:7px;">Terapkan</button>
                    </div>
                </div>

                <!-- Reset Filter Button -->
                <div>
                    <?php if ($selectedCustomer !== '' || $selectedUnit !== 'pcs' || $selectedTopRank !== '5' || !in_array($presetFilter, ['bulanan']) || $filterType !== ''): ?>
                        <a href="index.php" style="padding:4px 10px;border:1px solid #fecdd3;border-radius:7px;background:#fff1f2;color:#e11d48;font-size:11px;font-weight:800;text-decoration:none;display:inline-flex;align-items:center;gap:4px;">
                            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                            Reset Filter
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- ── Active Filter Summary Header Banner (High-Contrast Presentation Ready) ── -->
        <div style="background:linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #312e81 100%);color:#ffffff;border-radius:12px;padding:16px 20px;box-shadow:0 2px 6px rgba(0,0,0,0.1);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;">
            <div style="display:flex;flex-direction:column;gap:5px;">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <span style="display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:9999px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:0.5px;background:rgba(16,185,129,0.2);color:#6ee7b7;border:1px solid rgba(16,185,129,0.4);">
                        <span style="width:6px;height:6px;border-radius:50%;background:#34d399;"></span>
                        Live Pre-Aggregated Engine
                    </span>
                    <span style="font-size:12px;font-weight:600;color:#cbd5e1;">
                        Periode: <strong style="color:#ffffff;"><?= htmlspecialchars($filterTitleDesc) ?></strong>
                    </span>
                </div>
                <h1 style="margin:0;font-size:18px;font-weight:900;letter-spacing:-0.5px;color:#ffffff;display:flex;align-items:center;gap:8px;">
                    Defect Leaderboard & Analisis Pareto
                    <span style="font-size:11px;font-weight:800;padding:2px 8px;border-radius:5px;background:rgba(59,130,246,0.25);border:1px solid rgba(96,165,250,0.4);color:#93c5fd;">
                        Basis: <?= strtoupper($unitLabel) ?>
                    </span>
                </h1>
                <p style="margin:0;font-size:11px;color:#94a3b8;">
                    Menampilkan data cacat diurutkan dari frekuensi tertinggi untuk memandu prioritas Corrective & Preventive Action (CAPA).
                    <?php if ($selectedCustomer !== ''): ?>
                        Filter khusus: <strong style="color:#fde047;"><?= htmlspecialchars($selectedCustomer) ?></strong>.
                    <?php endif; ?>
                </p>
            </div>

            <!-- Unit & Rank Context Badges -->
            <div style="display:flex;align-items:center;gap:8px;">
                <div style="padding:6px 12px;border-radius:8px;background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.15);text-align:center;min-width:90px;">
                    <div style="font-size:10px;color:#94a3b8;font-weight:700;text-transform:uppercase;">Satuan Hitung</div>
                    <div style="font-size:13px;font-weight:900;color:#fcd34d;"><?= strtoupper($unitLabel) ?> NG</div>
                </div>
                <div style="padding:6px 12px;border-radius:8px;background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.15);text-align:center;min-width:90px;">
                    <div style="font-size:10px;color:#94a3b8;font-weight:700;text-transform:uppercase;">Limit Tampilan</div>
                    <div style="font-size:13px;font-weight:900;color:#a5b4fc;"><?= ($selectedTopRank === 'all') ? 'SEMUA' : ('TOP ' . $selectedTopRank) ?></div>
                </div>
            </div>
        </div>

        <!-- ── 4 Executive Summary KPI Cards ────────────────────────────────── -->
        <div id="defect-kpi-grid" style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;">
            <!-- Card 1: Total Defect Volume -->
            <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,0.03);display:flex;align-items:center;justify-content:space-between;">
                <div>
                    <span style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;display:block;">Total Temuan Cacat</span>
                    <div style="display:flex;align-items:baseline;gap:6px;margin:2px 0;">
                        <span style="font-size:24px;font-weight:900;color:#0f172a;line-height:1;"><?= number_format($grandTotalCount) ?></span>
                        <span style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;"><?= $unitLabel ?></span>
                    </div>
                    <span style="font-size:11px;color:#94a3b8;">
                        <?= ($selectedUnit === 'lot') ? (number_format($grandTotalPcs) . ' Pcs total terdampak') : (number_format($grandTotalLots) . ' Lot kasus terjadi') ?>
                    </span>
                </div>
                <div style="width:44px;height:44px;border-radius:10px;background:#fff1f2;border:1px solid #ffe4e6;display:flex;align-items:center;justify-content:center;color:#e11d48;flex-shrink:0;">
                    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                </div>
            </div>

            <!-- Card 2: Variasi Defect -->
            <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,0.03);display:flex;align-items:center;justify-content:space-between;">
                <div>
                    <span style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;display:block;">Variasi Jenis Defect</span>
                    <div style="display:flex;align-items:baseline;gap:6px;margin:2px 0;">
                        <span style="font-size:24px;font-weight:900;color:#0f172a;line-height:1;"><?= $distinctDefectCount ?></span>
                        <span style="font-size:11px;font-weight:800;color:#64748b;">Jenis</span>
                    </div>
                    <span style="font-size:11px;color:#94a3b8;">
                        Dari <?= $totalMasterDefects ?> jenis terdaftar
                    </span>
                </div>
                <div style="width:44px;height:44px;border-radius:10px;background:#eef2ff;border:1px solid #e0e7ff;display:flex;align-items:center;justify-content:center;color:#4f46e5;flex-shrink:0;">
                    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                    </svg>
                </div>
            </div>

            <!-- Card 3: Defect Paling Dominan (#1) -->
            <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,0.03);display:flex;align-items:center;justify-content:space-between;">
                <div style="min-width:0;padding-right:8px;">
                    <span style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;display:block;">Defect Terparah (#1)</span>
                    <div style="font-size:15px;font-weight:900;color:#b91c1c;margin:2px 0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= htmlspecialchars($topDefectName) ?>">
                        <?= htmlspecialchars($topDefectName) ?>
                    </div>
                    <span style="font-size:11px;color:#94a3b8;">
                        <?= number_format($topDefectCount) ?> <?= $unitLabel ?> terdeteksi
                    </span>
                </div>
                <div style="width:44px;height:44px;border-radius:10px;background:#fffbeb;border:1px solid #fef3c7;display:flex;align-items:center;justify-content:center;color:#d97706;flex-shrink:0;">
                    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z" />
                    </svg>
                </div>
            </div>

            <!-- Card 4: Rasio Dominasi #1 -->
            <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,0.03);display:flex;align-items:center;justify-content:space-between;">
                <div>
                    <span style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;display:block;">Rasio Dominasi #1</span>
                    <div style="display:flex;align-items:baseline;gap:6px;margin:2px 0;">
                        <span style="font-size:24px;font-weight:900;color:#4338ca;line-height:1;"><?= $topDefectRatio ?>%</span>
                        <span style="font-size:11px;font-weight:800;color:#64748b;">Porsi</span>
                    </div>
                    <span style="font-size:11px;color:#94a3b8;">
                        Porsi fokus Kaizen utama
                    </span>
                </div>
                <div style="width:44px;height:44px;border-radius:10px;background:#faf5ff;border:1px solid #f3e8ff;display:flex;align-items:center;justify-content:center;color:#7e22ce;flex-shrink:0;">
                    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z" />
                    </svg>
                </div>
            </div>
        </div>

        <!-- ── Big 3 Podium Cards (Gold, Silver, Bronze) ────────────────────── -->
        <div style="display:flex;flex-direction:column;gap:8px;">
            <div style="display:flex;align-items:center;justify-content:space-between;">
                <div>
                    <h2 style="margin:0;font-size:14px;font-weight:900;color:#1e293b;display:flex;align-items:center;gap:6px;">
                        <svg width="18" height="18" fill="none" stroke="#f59e0b" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z" />
                        </svg>
                        The Big 3 Defect Leaders (Podium 3 Terbesar)
                    </h2>
                    <p style="margin:2px 0 0 0;font-size:11px;color:#64748b;">Tiga jenis cacat teratas yang paling memerlukan tindakan perbaikan dari tim engineering & QC.</p>
                </div>
            </div>

            <?php if (empty($allDefects)): ?>
                <!-- Zero Defect State -->
                <div style="background:#f0fdf4;border:2px solid #86efac;border-radius:16px;padding:24px;text-align:center;display:flex;flex-direction:column;align-items:center;gap:8px;">
                    <div style="width:48px;height:48px;border-radius:50%;background:#dcfce7;color:#15803d;display:flex;align-items:center;justify-content:center;">
                        <svg width="28" height="28" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <h3 style="margin:0;font-size:16px;font-weight:900;color:#14532d;">Nihil Cacat Mutu (Zero Defect)</h3>
                    <p style="margin:0;font-size:12px;color:#166534;max-width:480px;">
                        Tidak ada catatan defect mutu pada filter waktu dan customer yang dipilih. Seluruh lot inspeksi berstatus 100% OK.
                    </p>
                </div>
            <?php else: ?>
                <div id="podium-grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;">
                    
                    <!-- PODIUM 1: Gold / Juara 1 -->
                    <?php 
                    $p1 = $allDefects[0] ?? null; 
                    if ($p1):
                    ?>
                        <div style="background:linear-gradient(135deg, #fffbeb 0%, #ffffff 50%, #fef3c7 100%);border:2px solid #f59e0b;border-radius:16px;padding:18px;box-shadow:0 3px 10px rgba(245,158,11,0.12);display:flex;flex-direction:column;justify-content:space-between;gap:12px;">
                            <div style="display:flex;align-items:center;justify-content:space-between;">
                                <div style="display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:9999px;font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:0.5px;background:#f59e0b;color:#ffffff;box-shadow:0 1px 3px rgba(245,158,11,0.4);">
                                    <svg width="12" height="12" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 2a1 1 0 011 1v1.323l3.954 1.582 1.599-.8a1 1 0 01.894 1.79l-1.233.616 1.738 5.42a1 1 0 01-.285 1.05A3.989 3.989 0 0115 15a3.989 3.989 0 01-2.667-1.019 1 1 0 01-.285-1.05l1.715-5.349L11 6.477V16h2a1 1 0 110 2H7a1 1 0 110-2h2V6.477L6.237 7.582l1.715 5.349a1 1 0 01-.285 1.05A3.989 3.989 0 015 15a3.989 3.989 0 01-2.667-1.019 1 1 0 01-.285-1.05l1.738-5.42-1.233-.617a1 1 0 01.894-1.788l1.599.799L9 4.323V3a1 1 0 011-1z" clip-rule="evenodd"/>
                                    </svg>
                                    TOP DEFECT #1
                                </div>
                                <span style="font-size:11px;font-weight:900;color:#92400e;background:#fef3c7;border:1px solid #fde68a;padding:2px 8px;border-radius:6px;">
                                    <?= $p1['percentage'] ?>% Porsi
                                </span>
                            </div>

                            <div>
                                <h3 style="margin:0 0 6px 0;font-size:16px;font-weight:900;color:#451a03;line-height:1.2;">
                                    <?= htmlspecialchars($p1['name']) ?>
                                </h3>
                                <div style="display:flex;align-items:baseline;gap:6px;">
                                    <span style="font-size:28px;font-weight:900;color:#78350f;"><?= number_format($p1['count']) ?></span>
                                    <span style="font-size:12px;font-weight:900;color:#92400e;text-transform:uppercase;"><?= $unitLabel ?></span>
                                    <span style="font-size:11px;color:#78716c;font-weight:600;">(Kumulatif <?= $p1['cumulative'] ?>%)</span>
                                </div>
                            </div>

                            <!-- Progress Bar -->
                            <div>
                                <div style="width:100%;background:#fde68a;border-radius:9999px;height:9px;overflow:hidden;">
                                    <div style="width:<?= min(100, max(8, $p1['percentage'])) ?>%;height:9px;border-radius:9999px;background:linear-gradient(90deg, #f59e0b 0%, #d97706 100%);"></div>
                                </div>
                            </div>

                            <div style="padding-top:10px;border-top:1px solid rgba(245,158,11,0.25);display:flex;align-items:center;justify-content:space-between;font-size:11px;">
                                <span style="font-weight:800;color:#78350f;">Prioritas:</span>
                                <span style="font-weight:800;color:#b91c1c;background:#fef2f2;padding:2px 8px;border-radius:6px;border:1px solid #fecaca;">
                                    Segera Buat CAPA (5-Why)
                                </span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- PODIUM 2: Silver / Juara 2 -->
                    <?php 
                    $p2 = $allDefects[1] ?? null; 
                    if ($p2):
                    ?>
                        <div style="background:linear-gradient(135deg, #f8fafc 0%, #ffffff 50%, #e2e8f0 100%);border:2px solid #94a3b8;border-radius:16px;padding:18px;box-shadow:0 3px 10px rgba(148,163,184,0.15);display:flex;flex-direction:column;justify-content:space-between;gap:12px;">
                            <div style="display:flex;align-items:center;justify-content:space-between;">
                                <div style="display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:9999px;font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:0.5px;background:#475569;color:#ffffff;box-shadow:0 1px 3px rgba(71,85,105,0.4);">
                                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                                    </svg>
                                    RUNNER-UP #2
                                </div>
                                <span style="font-size:11px;font-weight:900;color:#334155;background:#e2e8f0;border:1px solid #cbd5e1;padding:2px 8px;border-radius:6px;">
                                    <?= $p2['percentage'] ?>% Porsi
                                </span>
                            </div>

                            <div>
                                <h3 style="margin:0 0 6px 0;font-size:16px;font-weight:900;color:#0f172a;line-height:1.2;">
                                    <?= htmlspecialchars($p2['name']) ?>
                                </h3>
                                <div style="display:flex;align-items:baseline;gap:6px;">
                                    <span style="font-size:28px;font-weight:900;color:#1e293b;"><?= number_format($p2['count']) ?></span>
                                    <span style="font-size:12px;font-weight:900;color:#475569;text-transform:uppercase;"><?= $unitLabel ?></span>
                                    <span style="font-size:11px;color:#64748b;font-weight:600;">(Kumulatif <?= $p2['cumulative'] ?>%)</span>
                                </div>
                            </div>

                            <!-- Progress Bar -->
                            <div>
                                <div style="width:100%;background:#e2e8f0;border-radius:9999px;height:9px;overflow:hidden;">
                                    <div style="width:<?= min(100, max(8, $p2['percentage'])) ?>%;height:9px;border-radius:9999px;background:linear-gradient(90deg, #64748b 0%, #475569 100%);"></div>
                                </div>
                            </div>

                            <div style="padding-top:10px;border-top:1px solid #cbd5e1;display:flex;align-items:center;justify-content:space-between;font-size:11px;">
                                <span style="font-weight:800;color:#334155;">Prioritas:</span>
                                <span style="font-weight:800;color:#1e293b;background:#f1f5f9;padding:2px 8px;border-radius:6px;border:1px solid #cbd5e1;">
                                    Monitoring & Pengetatan
                                </span>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- Slot 2 Kosong -->
                        <div style="background:#f8fafc;border:2px dashed #cbd5e1;border-radius:16px;padding:20px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;color:#94a3b8;gap:6px;">
                            <span style="font-size:11px;font-weight:800;text-transform:uppercase;">Rank #2 Nihil</span>
                            <p style="margin:0;font-size:11px;">Tidak ada data cacat kedua.</p>
                        </div>
                    <?php endif; ?>

                    <!-- PODIUM 3: Bronze / Juara 3 -->
                    <?php 
                    $p3 = $allDefects[2] ?? null; 
                    if ($p3):
                    ?>
                        <div style="background:linear-gradient(135deg, #fff7ed 0%, #ffffff 50%, #ffedd5 100%);border:2px solid #f97316;border-radius:16px;padding:18px;box-shadow:0 3px 10px rgba(249,115,22,0.12);display:flex;flex-direction:column;justify-content:space-between;gap:12px;">
                            <div style="display:flex;align-items:center;justify-content:space-between;">
                                <div style="display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:9999px;font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:0.5px;background:#ea580c;color:#ffffff;box-shadow:0 1px 3px rgba(234,88,12,0.4);">
                                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                    </svg>
                                    BRONZE #3
                                </div>
                                <span style="font-size:11px;font-weight:900;color:#9a3412;background:#ffedd5;border:1px solid #fed7aa;padding:2px 8px;border-radius:6px;">
                                    <?= $p3['percentage'] ?>% Porsi
                                </span>
                            </div>

                            <div>
                                <h3 style="margin:0 0 6px 0;font-size:16px;font-weight:900;color:#431407;line-height:1.2;">
                                    <?= htmlspecialchars($p3['name']) ?>
                                </h3>
                                <div style="display:flex;align-items:baseline;gap:6px;">
                                    <span style="font-size:28px;font-weight:900;color:#7c2d12;"><?= number_format($p3['count']) ?></span>
                                    <span style="font-size:12px;font-weight:900;color:#c2410c;text-transform:uppercase;"><?= $unitLabel ?></span>
                                    <span style="font-size:11px;color:#78716c;font-weight:600;">(Kumulatif <?= $p3['cumulative'] ?>%)</span>
                                </div>
                            </div>

                            <!-- Progress Bar -->
                            <div>
                                <div style="width:100%;background:#fed7aa;border-radius:9999px;height:9px;overflow:hidden;">
                                    <div style="width:<?= min(100, max(8, $p3['percentage'])) ?>%;height:9px;border-radius:9999px;background:linear-gradient(90deg, #f97316 0%, #c2410c 100%);"></div>
                                </div>
                            </div>

                            <div style="padding-top:10px;border-top:1px solid rgba(249,115,22,0.25);display:flex;align-items:center;justify-content:space-between;font-size:11px;">
                                <span style="font-weight:800;color:#7c2d12;">Prioritas:</span>
                                <span style="font-weight:800;color:#9a3412;background:#fff7ed;padding:2px 8px;border-radius:6px;border:1px solid #ffedd5;">
                                    Review SOP & Tooling
                                </span>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- Slot 3 Kosong -->
                        <div style="background:#f8fafc;border:2px dashed #cbd5e1;border-radius:16px;padding:20px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;color:#94a3b8;gap:6px;">
                            <span style="font-size:11px;font-weight:800;text-transform:uppercase;">Rank #3 Nihil</span>
                            <p style="margin:0;font-size:11px;">Tidak ada data cacat ketiga.</p>
                        </div>
                    <?php endif; ?>

                </div>
            <?php endif; ?>
        </div>

        <!-- ── Complete Pareto Leaderboard Table ────────────────────────────── -->
        <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,0.03);overflow:hidden;">
            <!-- Table Header Bar -->
            <div style="padding:14px 18px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                <div>
                    <h2 style="margin:0;font-size:15px;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:6px;">
                        <svg width="18" height="18" fill="none" stroke="#2563eb" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                        Tabel Peringkat Defect & Analisis Pareto (80/20 Rule)
                    </h2>
                    <p style="margin:2px 0 0 0;font-size:11px;color:#64748b;">
                        Menampilkan jenis defect terurut berdasarkan frekuensi <?= strtoupper($unitLabel) ?> tertinggi beserta proporsi kumulatif Pareto.
                    </p>
                </div>

                <!-- Leaderboard Rank Fast Filter inside table header -->
                <div style="display:flex;align-items:center;gap:5px;font-size:11px;">
                    <span style="color:#64748b;font-weight:700;">Tampilkan:</span>
                    <?php 
                    foreach ($rankOptions as $rk => $rLbl):
                        $isActive = ($selectedTopRank === (string)$rk);
                        $urlTabRank = "?top_rank=" . $rk 
                                    . ($selectedUnit !== 'pcs' ? '&unit=' . urlencode($selectedUnit) : '')
                                    . ($selectedCustomer !== '' ? '&customer=' . urlencode($selectedCustomer) : '')
                                    . ($presetFilter !== 'custom' ? '&preset=' . $presetFilter : '')
                                    . (!empty($_GET['start_date']) ? '&start_date=' . urlencode($_GET['start_date']) . '&end_date=' . urlencode($_GET['end_date']) : '');
                    ?>
                        <a href="<?= $urlTabRank ?>" 
                           style="padding:3px 9px;border-radius:6px;font-size:11px;font-weight:700;text-decoration:none;border:1px solid <?= $isActive ? '#0f172a' : '#cbd5e1' ?>;
                                  background:<?= $isActive ? '#0f172a' : '#f8fafc' ?>;
                                  color:<?= $isActive ? '#ffffff' : '#475569' ?>;">
                            <?= $rLbl ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Table Responsive Wrapper -->
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;text-align:left;font-size:12px;">
                    <thead>
                        <tr style="background:#f8fafc;color:#475569;border-bottom:1px solid #e2e8f0;font-weight:900;text-transform:uppercase;font-size:10px;letter-spacing:0.5px;">
                            <th style="padding:10px 16px;text-align:center;width:64px;">Peringkat</th>
                            <th style="padding:10px 16px;">Nama Jenis Defect</th>
                            <th style="padding:10px 16px;text-align:right;">Jumlah Cacat (<?= strtoupper($unitLabel) ?>)</th>
                            <th style="padding:10px 16px;text-align:right;width:180px;">Kontribusi (%)</th>
                            <th style="padding:10px 16px;text-align:right;width:150px;">Kumulatif Pareto</th>
                            <th style="padding:10px 16px;text-align:center;width:170px;">Klasifikasi Pareto</th>
                            <th style="padding:10px 16px;text-align:center;width:110px;">Status Mutu</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($displayDefects)): ?>
                            <tr>
                                <td colspan="7" style="padding:32px;text-align:center;color:#94a3b8;font-weight:600;">
                                    Tidak ada data defect untuk filter yang dipilih.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($displayDefects as $row): 
                                $rk = $row['rank'];
                                $badgeStyle = 'background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;';
                                if ($rk === 1) {
                                    $badgeStyle = 'background:#f59e0b;color:#ffffff;font-weight:900;box-shadow:0 1px 3px rgba(245,158,11,0.3);';
                                } elseif ($rk === 2) {
                                    $badgeStyle = 'background:#475569;color:#ffffff;font-weight:900;box-shadow:0 1px 3px rgba(71,85,105,0.3);';
                                } elseif ($rk === 3) {
                                    $badgeStyle = 'background:#ea580c;color:#ffffff;font-weight:900;box-shadow:0 1px 3px rgba(234,88,12,0.3);';
                                }
                            ?>
                                <tr style="border-bottom:1px solid #f1f5f9;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                                    <!-- Rank Number -->
                                    <td style="padding:12px 16px;text-align:center;">
                                        <span style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;font-size:12px;font-weight:900;<?= $badgeStyle ?>">
                                            <?= $rk ?>
                                        </span>
                                    </td>

                                    <!-- Defect Name -->
                                    <td style="padding:12px 16px;">
                                        <div style="font-weight:800;color:#0f172a;font-size:13px;">
                                            <?= htmlspecialchars($row['name']) ?>
                                        </div>
                                        <span style="font-size:10px;color:#94a3b8;font-weight:600;">
                                            ID Defect: #<?= (int)$row['id'] ?>
                                        </span>
                                    </td>

                                    <!-- Count -->
                                    <td style="padding:12px 16px;text-align:right;">
                                        <div style="font-weight:900;color:#0f172a;font-size:14px;">
                                            <?= number_format($row['count']) ?>
                                        </div>
                                        <span style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;">
                                            <?= $unitLabel ?>
                                        </span>
                                    </td>

                                    <!-- Percentage with inline visual bar -->
                                    <td style="padding:12px 16px;text-align:right;">
                                        <div style="font-weight:900;color:#0f172a;font-size:12px;margin-bottom:4px;">
                                            <?= $row['percentage'] ?>%
                                        </div>
                                        <div style="width:100%;background:#f1f5f9;border-radius:9999px;height:7px;overflow:hidden;">
                                            <div style="height:7px;border-radius:9999px;width:<?= min(100, max(5, $row['percentage'])) ?>%;background:<?= ($rk === 1 ? '#f59e0b' : ($rk === 2 ? '#475569' : ($rk === 3 ? '#ea580c' : '#2563eb'))) ?>;">
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Cumulative Pareto -->
                                    <td style="padding:12px 16px;text-align:right;">
                                        <div style="font-weight:900;color:#0f172a;font-size:12px;">
                                            <?= $row['cumulative'] ?>%
                                        </div>
                                        <span style="font-size:10px;color:#94a3b8;font-weight:500;">kumulatif</span>
                                    </td>

                                    <!-- Pareto Category Badge -->
                                    <td style="padding:12px 16px;text-align:center;">
                                        <?php if ($row['is_vital']): ?>
                                            <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:9999px;font-size:10px;font-weight:900;background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;" title="Vital Few: Sumber 80% Masalah Utama">
                                                <svg width="12" height="12" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                                                </svg>
                                                Vital Few (80%)
                                            </span>
                                        <?php else: ?>
                                            <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:9999px;font-size:10px;font-weight:700;background:#f1f5f9;color:#475569;border:1px solid #cbd5e1;" title="Trivial Many: Bagian dari 20% Masalah Sekunder">
                                                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                                </svg>
                                                Trivial Many (20%)
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Quality Action Priority -->
                                    <td style="padding:12px 16px;text-align:center;">
                                        <?php if ($rk === 1): ?>
                                            <span style="font-size:10px;font-weight:900;padding:2px 8px;border-radius:4px;background:#dc2626;color:#ffffff;text-transform:uppercase;letter-spacing:0.5px;">
                                                Kritis
                                            </span>
                                        <?php elseif ($rk <= 3): ?>
                                            <span style="font-size:10px;font-weight:800;padding:2px 8px;border-radius:4px;background:#fef3c7;color:#92400e;border:1px solid #fde68a;text-transform:uppercase;letter-spacing:0.5px;">
                                                Tinggi
                                            </span>
                                        <?php else: ?>
                                            <span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:4px;background:#f1f5f9;color:#475569;border:1px solid #cbd5e1;text-transform:uppercase;letter-spacing:0.5px;">
                                                Rutin
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Table Footer Info -->
            <div style="padding:12px 16px;background:#f8fafc;border-top:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;font-size:11px;color:#64748b;flex-wrap:wrap;gap:8px;">
                <span>
                    Menampilkan <strong><?= count($displayDefects) ?></strong> dari <strong><?= $distinctDefectCount ?></strong> tipe cacat terdeteksi pada periode ini.
                </span>
                <span style="color:#94a3b8;">
                    Kaidah Pareto: Fokuskan 80% energi tim pada jenis cacat berlabel <strong style="color:#b91c1c;">Vital Few</strong>.
                </span>
            </div>
        </div>

    </div><!-- End of defect-body -->

    <!-- Responsive Fallback CSS -->
    <style>
        @media (max-width: 1024px) {
            #defect-kpi-grid { grid-template-columns: repeat(2, 1fr) !important; }
            #podium-grid { grid-template-columns: 1fr !important; }
        }
        @media (max-width: 640px) {
            #defect-kpi-grid { grid-template-columns: 1fr !important; }
            #podium-grid { grid-template-columns: 1fr !important; }
        }
    </style>

    <script>
    function switchDefectDateFilter(val) {
        var cMonth = document.getElementById('containerDefectMonthYear');
        var cCust  = document.getElementById('containerDefectCustomDate');
        if (cMonth) cMonth.style.display = 'none';
        if (cCust)  cCust.style.display  = 'none';

        if (val === 'month_year') {
            if (cMonth) cMonth.style.display = 'inline-flex';
        } else if (val === 'custom') {
            if (cCust) cCust.style.display = 'inline-flex';
        } else if (val === 'all_years') {
            window.location.href = '?filter_type=all_years&unit=<?= urlencode($selectedUnit) ?>&top_rank=<?= urlencode($selectedTopRank) ?><?= $selectedCustomer !== '' ? '&customer=' . urlencode($selectedCustomer) : '' ?>';
        } else {
            window.location.href = '?preset=bulanan&unit=<?= urlencode($selectedUnit) ?>&top_rank=<?= urlencode($selectedTopRank) ?><?= $selectedCustomer !== '' ? '&customer=' . urlencode($selectedCustomer) : '' ?>';
        }
    }
    </script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
