<?php
$breadcrumbCategory = "OPERASIONAL";
$pageTitle          = "Analisis & Tren Defect";
$pageSubtitle       = "Distribusi cacat mutu interaktif, tren kejadian, dan audit trail temuan inspeksi — PT. Surya Technology Industri";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';

require_menu_access('defect_analysis');

$pdo = getDB();

// ── Available Years from Database ───────────────────────────────────────────
$availableYears = [];
if ($pdo) {
    try {
        $stmtY = $pdo->query("SELECT MIN(started_at) AS min_dt, MAX(started_at) AS max_dt FROM inspection_sessions WHERE started_at IS NOT NULL");
        $rowY = $stmtY->fetch(PDO::FETCH_ASSOC);
        if ($rowY && $rowY['min_dt'] && $rowY['max_dt']) {
            $minY = (int)date('Y', strtotime($rowY['min_dt']));
            $maxY = (int)date('Y', strtotime($rowY['max_dt']));
            for ($yr = $maxY; $yr >= $minY; $yr--) {
                $availableYears[] = $yr;
            }
        }
    } catch (Exception $e) {}
}
$currentYear = (int)date('Y');
if (!in_array($currentYear, $availableYears)) {
    $availableYears[] = $currentYear;
}
rsort($availableYears);
$availableYears = array_values(array_unique($availableYears));

$monthNames = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];

// ── Initial Filter Parameters ───────────────────────────────────────────────
$selectedUnit        = sanitize($_GET['unit'] ?? 'pcs');
if (!in_array($selectedUnit, ['pcs', 'lot'])) {
    $selectedUnit = 'pcs';
}

$selectedSampleBasis = sanitize($_GET['sample_basis'] ?? 'lot');
if (!in_array($selectedSampleBasis, ['lot', 'session'])) {
    $selectedSampleBasis = 'lot';
}

$selectedTopRank     = sanitize($_GET['top_rank'] ?? '5');
if (!in_array($selectedTopRank, ['3', '5', '10', 'all'])) {
    $selectedTopRank = '5';
}

$selectedCustomer = sanitize($_GET['customer'] ?? '');
$presetFilter     = sanitize($_GET['preset'] ?? 'bulanan');
$filterType       = sanitize($_GET['filter_type'] ?? '');
$selectedMonth    = isset($_GET['month']) && is_numeric($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$selectedYear     = isset($_GET['year']) && is_numeric($_GET['year']) ? (int)$_GET['year'] : $currentYear;

// Custom dates
$customStartDate  = sanitize($_GET['start_date'] ?? '');
$customEndDate    = sanitize($_GET['end_date'] ?? '');

if (!empty($customStartDate) && !empty($customEndDate)) {
    $presetFilter = 'custom';
    $filterType   = 'custom';
    $startDate    = $customStartDate;
    $endDate      = $customEndDate;
} elseif ($filterType === 'all_years') {
    $minYear   = min(min($availableYears), $currentYear - 2);
    $startDate = sprintf('%04d-01-01', $minYear);
    $endDate   = date('Y-12-31');
} elseif ($filterType === 'specific_year') {
    $startDate = sprintf('%04d-01-01', $selectedYear);
    $endDate   = ($selectedYear === $currentYear) ? date('Y-m-d') : sprintf('%04d-12-31', $selectedYear);
} elseif ($filterType === 'month_year') {
    $startDate = sprintf('%04d-%02d-01', $selectedYear, $selectedMonth);
    $endDate   = date('Y-m-t', strtotime($startDate));
} else {
    switch ($presetFilter) {
        case 'hari_ini':
            $startDate = date('Y-m-d');
            $endDate   = date('Y-m-d');
            break;
        case 'mingguan':
            $startDate = date('Y-m-d', strtotime('monday this week'));
            $endDate   = date('Y-m-d');
            break;
        case 'tahunan':
            $startDate = date('Y-01-01');
            $endDate   = date('Y-m-d');
            break;
        default:
            $presetFilter = 'bulanan';
            $startDate    = date('Y-m-01');
            $endDate      = date('Y-m-d');
    }
}

// Fetch Customer Options for Filter Dropdown
$customerOptions = [];
if ($pdo) {
    try {
        $stmtCust = $pdo->query("SELECT DISTINCT name FROM master_customers UNION SELECT DISTINCT customer FROM kanban_items WHERE customer IS NOT NULL AND customer != '' ORDER BY name ASC");
        $customerOptions = array_values(array_filter(array_map('trim', $stmtCust->fetchAll(PDO::FETCH_COLUMN))));
    } catch (Exception $e) {}
}
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col transition-all duration-300 min-h-screen bg-slate-100">
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <div id="defect-body" class="flex-1 p-4 space-y-4 overflow-y-auto">
        <?= render_flash() ?>

        <!-- ── 1. FILTER CONTROLS TOOLBAR (Solid Opaque Standard) ───────────── -->
        <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:14px 16px;box-shadow:0 1px 3px rgba(0,0,0,0.03);display:flex;flex-direction:column;gap:12px;">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    
                    <!-- Mode Switcher (Defect-Centric vs Part-Centric) -->
                    <div id="groupModeSwitcher" style="display:flex;align-items:center;gap:3px;background:#f1f5f9;padding:3px;border-radius:9px;border:1px solid #e2e8f0;" title="Pilih Perspektif Analisis">
                        <button type="button" id="btnModeDefect" onclick="switchAnalysisMode('defect')"
                                style="padding:5px 12px;border-radius:6px;font-size:11px;font-weight:800;border:none;cursor:pointer;transition:all 0.2s;background:#0f172a;color:#ffffff;box-shadow:0 1px 3px rgba(15,23,42,0.25);">
                            Analisis per Defect
                        </button>
                        <button type="button" id="btnModePart" onclick="switchAnalysisMode('part')"
                                style="padding:5px 12px;border-radius:6px;font-size:11px;font-weight:800;border:none;cursor:pointer;transition:all 0.2s;background:transparent;color:#64748b;">
                            Analisis per Part Code
                        </button>
                    </div>

                    <!-- Preset Date Pills Bar -->
                    <div id="groupPresetPills" style="display:flex;align-items:center;gap:3px;background:#f1f5f9;padding:3px;border-radius:9px;border:1px solid #e2e8f0;">
                        <button type="button" class="preset-pill-btn" data-preset="hari_ini" onclick="applyPreset('hari_ini')"
                                style="padding:5px 11px;border-radius:6px;font-size:11px;font-weight:700;border:none;cursor:pointer;transition:all 0.2s;background:transparent;color:#64748b;">
                            Hari Ini
                        </button>
                        <button type="button" class="preset-pill-btn" data-preset="mingguan" onclick="applyPreset('mingguan')"
                                style="padding:5px 11px;border-radius:6px;font-size:11px;font-weight:700;border:none;cursor:pointer;transition:all 0.2s;background:transparent;color:#64748b;">
                            Minggu Ini
                        </button>
                        <button type="button" class="preset-pill-btn" data-preset="bulanan" onclick="applyPreset('bulanan')"
                                style="padding:5px 11px;border-radius:6px;font-size:11px;font-weight:700;border:none;cursor:pointer;transition:all 0.2s;background:transparent;color:#64748b;">
                            Bulan Ini
                        </button>
                        <button type="button" class="preset-pill-btn" data-preset="tahunan" onclick="applyPreset('tahunan')"
                                style="padding:5px 11px;border-radius:6px;font-size:11px;font-weight:700;border:none;cursor:pointer;transition:all 0.2s;background:transparent;color:#64748b;">
                            Tahun Ini
                        </button>
                        <button type="button" class="preset-pill-btn" data-preset="month_year" onclick="openSubFilter('month_year')"
                                style="padding:5px 11px;border-radius:6px;font-size:11px;font-weight:700;border:none;cursor:pointer;transition:all 0.2s;background:transparent;color:#64748b;">
                            Pilih Bulan
                        </button>
                        <button type="button" class="preset-pill-btn" data-preset="specific_year" onclick="openSubFilter('specific_year')"
                                style="padding:5px 11px;border-radius:6px;font-size:11px;font-weight:700;border:none;cursor:pointer;transition:all 0.2s;background:transparent;color:#64748b;">
                            Pilih Tahun
                        </button>
                        <button type="button" class="preset-pill-btn" data-preset="all_years" onclick="applyPreset('all_years')"
                                style="padding:5px 11px;border-radius:6px;font-size:11px;font-weight:700;border:none;cursor:pointer;transition:all 0.2s;background:transparent;color:#64748b;"
                                title="Akumulasi data seluruh tahun di database">
                            Semua Tahun
                        </button>
                        <button type="button" class="preset-pill-btn" data-preset="custom" onclick="openSubFilter('custom')"
                                style="padding:5px 11px;border-radius:6px;font-size:11px;font-weight:700;border:none;cursor:pointer;transition:all 0.2s;background:transparent;color:#64748b;">
                            Rentang Custom
                        </button>
                    </div>

                    <!-- Dynamic Sub-Filter Container (Pilih Bulan & Tahun / Pilih Tahun / Rentang Custom) -->
                    <div id="containerSubFilter" style="display:none;align-items:center;gap:6px;background:#f8fafc;padding:3px 8px;border-radius:8px;border:1px solid #cbd5e1;">
                        
                        <!-- Sub-Box 1: Month & Year Picker -->
                        <div id="boxFilterMonthYear" style="display:none;align-items:center;gap:5px;">
                            <select id="subSelectMonth" class="form-input text-xs" style="padding:3px 6px;border-radius:6px;border:1px solid #cbd5e1;font-size:11px;font-weight:600;">
                                <?php foreach ($monthNames as $mNum => $mName): ?>
                                    <option value="<?= $mNum ?>" <?= $selectedMonth === $mNum ? 'selected' : '' ?>><?= $mName ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select id="subSelectMonthYear" class="form-input text-xs" style="padding:3px 6px;border-radius:6px;border:1px solid #cbd5e1;font-size:11px;font-weight:600;">
                                <?php foreach ($availableYears as $y): ?>
                                    <option value="<?= $y ?>" <?= $selectedYear === (int)$y ? 'selected' : '' ?>><?= $y ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" onclick="applyMonthYearFilter()" style="padding:4px 10px;background:#2563eb;color:#ffffff;font-size:11px;font-weight:700;border-radius:6px;border:none;cursor:pointer;box-shadow:0 1px 2px rgba(37,99,235,0.2);">
                                Terapkan
                            </button>
                        </div>

                        <!-- Sub-Box 2: Specific Year Picker (e.g. Cek Tahun Lalu) -->
                        <div id="boxFilterSpecificYear" style="display:none;align-items:center;gap:5px;">
                            <span style="font-size:11px;font-weight:700;color:#475569;">Tahun:</span>
                            <select id="subSelectYearOnly" class="form-input text-xs" style="padding:3px 8px;border-radius:6px;border:1px solid #cbd5e1;font-size:11px;font-weight:600;">
                                <?php foreach ($availableYears as $y): ?>
                                    <option value="<?= $y ?>" <?= $selectedYear === (int)$y ? 'selected' : '' ?>><?= $y ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" onclick="applySpecificYearFilter()" style="padding:4px 10px;background:#2563eb;color:#ffffff;font-size:11px;font-weight:700;border-radius:6px;border:none;cursor:pointer;box-shadow:0 1px 2px rgba(37,99,235,0.2);">
                                Terapkan
                            </button>
                        </div>

                        <!-- Sub-Box 3: Custom Date Range Picker -->
                        <div id="boxFilterCustomDate" style="display:none;align-items:center;gap:5px;">
                            <input type="date" id="filterStartDate" value="<?= htmlspecialchars($startDate) ?>" class="form-input text-xs" style="width:125px;padding:3px 6px;border-radius:6px;border:1px solid #cbd5e1;font-size:11px;">
                            <span style="color:#94a3b8;font-weight:bold;">&ndash;</span>
                            <input type="date" id="filterEndDate" value="<?= htmlspecialchars($endDate) ?>" class="form-input text-xs" style="width:125px;padding:3px 6px;border-radius:6px;border:1px solid #cbd5e1;font-size:11px;">
                            <button type="button" onclick="applyCustomDateRange()" style="padding:4px 10px;background:#2563eb;color:#ffffff;font-size:11px;font-weight:700;border-radius:6px;border:none;cursor:pointer;box-shadow:0 1px 2px rgba(37,99,235,0.2);">
                                Terapkan
                            </button>
                        </div>

                    </div>

                    <!-- Unit Segmented Toggle (PCS vs LOT) -->
                    <div style="display:flex;align-items:center;gap:3px;background:#f1f5f9;padding:3px;border-radius:9px;border:1px solid #e2e8f0;" title="Pilih Satuan Analisis">
                        <button type="button" id="btnUnitPcs" onclick="switchUnit('pcs')"
                                style="padding:5px 12px;border-radius:6px;font-size:11px;font-weight:800;border:none;cursor:pointer;transition:all 0.2s;background:#0f172a;color:#ffffff;box-shadow:0 1px 3px rgba(15,23,42,0.25);">
                            PCS
                        </button>
                        <button type="button" id="btnUnitLot" onclick="switchUnit('lot')"
                                style="padding:5px 12px;border-radius:6px;font-size:11px;font-weight:800;border:none;cursor:pointer;transition:all 0.2s;background:transparent;color:#64748b;">
                            LOT
                        </button>
                    </div>

                    <!-- Sample Basis Segmented Toggle (Per Lot vs Per Sesi) -->
                    <div id="containerSampleBasis" style="display:<?= $selectedUnit === 'pcs' ? 'flex' : 'none' ?>;align-items:center;gap:3px;background:#f1f5f9;padding:3px;border-radius:9px;border:1px solid #e2e8f0;" title="Pilih Dasar Perhitungan Ukuran Sampel AQL">
                        <span style="font-size:10px;font-weight:700;color:#64748b;padding:0 4px;">Basis Sampel:</span>
                        <button type="button" id="btnBasisLot" onclick="switchSampleBasis('lot')"
                                style="padding:4px 9px;border-radius:6px;font-size:11px;font-weight:800;border:none;cursor:pointer;transition:all 0.2s;
                                       background:<?= $selectedSampleBasis === 'lot' ? '#0284c7' : 'transparent' ?>;
                                       color:<?= $selectedSampleBasis === 'lot' ? '#ffffff' : '#64748b' ?>;
                                       <?= $selectedSampleBasis === 'lot' ? 'box-shadow:0 1px 3px rgba(2,132,199,0.25);' : '' ?>">
                            Per Lot
                        </button>
                        <button type="button" id="btnBasisSess" onclick="switchSampleBasis('session')"
                                style="padding:4px 9px;border-radius:6px;font-size:11px;font-weight:800;border:none;cursor:pointer;transition:all 0.2s;
                                       background:<?= $selectedSampleBasis === 'session' ? '#0284c7' : 'transparent' ?>;
                                       color:<?= $selectedSampleBasis === 'session' ? '#ffffff' : '#64748b' ?>;
                                       <?= $selectedSampleBasis === 'session' ? 'box-shadow:0 1px 3px rgba(2,132,199,0.25);' : '' ?>">
                            Per Sesi
                        </button>
                    </div>

                    <!-- Rank Limit Pills (Top 3 / 5 / 10 / All) -->
                    <div id="groupRankPills" style="display:flex;align-items:center;gap:3px;background:#f1f5f9;padding:3px;border-radius:9px;border:1px solid #e2e8f0;" title="Pilih Limit Peringkat Analisis">
                        <span style="font-size:10px;font-weight:800;color:#94a3b8;padding:0 5px;text-transform:uppercase;">Rank:</span>
                        <button type="button" class="rank-pill-btn" data-rank="3" onclick="switchTopRank('3')"
                                style="padding:5px 9px;border-radius:6px;font-size:11px;font-weight:700;border:none;cursor:pointer;transition:all 0.2s;background:transparent;color:#64748b;">
                            Top 3
                        </button>
                        <button type="button" class="rank-pill-btn" data-rank="5" onclick="switchTopRank('5')"
                                style="padding:5px 9px;border-radius:6px;font-size:11px;font-weight:800;border:none;cursor:pointer;transition:all 0.2s;background:#4f46e5;color:#ffffff;box-shadow:0 1px 3px rgba(79,70,229,0.25);">
                            Top 5
                        </button>
                        <button type="button" class="rank-pill-btn" data-rank="10" onclick="switchTopRank('10')"
                                style="padding:5px 9px;border-radius:6px;font-size:11px;font-weight:700;border:none;cursor:pointer;transition:all 0.2s;background:transparent;color:#64748b;">
                            Top 10
                        </button>
                        <button type="button" class="rank-pill-btn" data-rank="all" onclick="switchTopRank('all')"
                                style="padding:5px 9px;border-radius:6px;font-size:11px;font-weight:700;border:none;cursor:pointer;transition:all 0.2s;background:transparent;color:#64748b;">
                            Semua
                        </button>
                    </div>

                    <!-- Customer Selector -->
                    <div style="display:flex;align-items:center;gap:5px;">
                        <span style="font-size:11px;font-weight:700;color:#475569;">Customer:</span>
                        <select id="selectCustomer" onchange="onCustomerChange(this.value)" class="form-input text-xs" style="padding:4px 8px;border-radius:7px;font-weight:600;min-width:140px;border:1px solid #cbd5e1;">
                            <option value="">-- Semua Customer --</option>
                            <?php foreach ($customerOptions as $cOpt): ?>
                                <option value="<?= htmlspecialchars($cOpt) ?>" <?= $selectedCustomer === $cOpt ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cOpt) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Action Buttons: Export Excel, Print & Reset -->
                <div style="display:flex;align-items:center;gap:8px;">
                    <button type="button" onclick="exportDefectExcel()" 
                            style="display:inline-flex;align-items:center;gap:6px;padding:6px 14px;background:#059669;color:#ffffff;font-size:11px;font-weight:700;border-radius:8px;border:none;cursor:pointer;box-shadow:0 1px 3px rgba(5,150,105,0.25);transition:all 0.2s;"
                            onmouseover="this.style.background='#047857'" onmouseout="this.style.background='#059669'"
                            title="Ekspor seluruh data analisis dan audit trail ke format Excel (.xlsx)">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        Export Excel
                    </button>
                    <button type="button" onclick="window.print()" 
                            style="display:inline-flex;align-items:center;gap:6px;padding:6px 14px;background:#1e293b;color:#ffffff;font-size:11px;font-weight:700;border-radius:8px;border:none;cursor:pointer;box-shadow:0 1px 3px rgba(0,0,0,0.15);transition:all 0.2s;"
                            onmouseover="this.style.background='#0f172a'" onmouseout="this.style.background='#1e293b'">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                        </svg>
                        Cetak Laporan
                    </button>
                    <button type="button" onclick="resetAllFilters()" 
                            style="display:inline-flex;align-items:center;gap:4px;padding:6px 12px;background:#fff1f2;color:#e11d48;border:1px solid #fecdd3;border-radius:8px;font-size:11px;font-weight:700;cursor:pointer;transition:all 0.2s;">
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        Reset
                    </button>
                </div>
            </div>
        </div>

        <!-- ── 2. VIEW MODE DEFECT CONTAINER (POHON EKSPLORASI BERTINGKAT) ──────── -->
        <div id="viewContainerDefectMode" style="display:flex;flex-direction:column;gap:14px;">
            <div id="cascading-funnel-section" style="display:flex;flex-direction:column;gap:14px;">
                
                <!-- ── PANEL 1: DISTRIBUSI JENIS DEFECT ─────────────────────────── -->
                <div class="funnel-level-card" id="cardFunnelLevel1" style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,0.03);overflow:hidden;">
                <div style="padding:14px 18px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                    <div>
                        <h2 style="margin:0;font-size:15px;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:7px;">
                            <svg width="18" height="18" fill="none" stroke="#2563eb" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                            Distribusi Jenis Defect
                        </h2>
                        <p style="margin:2px 0 0 25px;font-size:11px;color:#64748b;">
                            Peringkat temuan cacat mutu. Klik salah satu jenis defect untuk melihat model produk yang terdampak.
                        </p>
                    </div>
                    <div style="display:flex;align-items:center;gap:6px;">
                        <span id="badgeDefectSelected" style="display:none;padding:3px 9px;border-radius:6px;font-size:11px;font-weight:800;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;">
                            Defect: <strong id="lblSelectedDefect" style="margin-left:3px;">-</strong>
                        </span>
                    </div>
                </div>

                <div style="padding:14px 16px;display:grid;grid-template-columns:1fr 1fr;gap:14px;align-items:stretch;" class="side-by-side-grid">
                    <!-- Kiri: Donut & List Defect -->
                    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px;display:grid;grid-template-columns:210px 1fr;gap:12px;align-items:center;">
                        <div style="position:relative;width:100%;height:220px;display:flex;align-items:center;justify-content:center;">
                            <canvas id="chartFunnelDefects"></canvas>
                            <div id="centerBadgeDefects" class="donut-center-badge">
                                <span class="donut-center-sub" id="centerSubDefects">TOTAL DEFECT</span>
                                <span class="donut-center-val" id="centerValDefects">0</span>
                                <span class="donut-center-unit" id="centerUnitDefects">PCS</span>
                            </div>
                        </div>
                        <div id="listFunnelDefects" style="display:flex;flex-direction:column;gap:6px;max-height:220px;overflow-y:auto;padding-right:4px;">
                            <div style="padding:20px;text-align:center;color:#94a3b8;font-size:12px;">Memuat data sebaran jenis defect...</div>
                        </div>
                    </div>

                    <!-- Kanan: Stacked Bar / Line Grafik Tren Tanggal per Defect -->
                    <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:10px;padding:12px;display:flex;flex-direction:column;justify-content:space-between;">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;flex-wrap:wrap;gap:6px;">
                            <span style="font-size:12px;font-weight:800;color:#0f172a;display:flex;align-items:center;gap:5px;">
                                <svg width="14" height="14" fill="none" stroke="#2563eb" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"/></svg>
                                Tren Tanggal Defect Teratas
                            </span>
                            <div style="display:flex;align-items:center;gap:4px;">
                                <div style="display:flex;align-items:center;gap:2px;background:#f1f5f9;padding:2px;border-radius:6px;" title="Tipe Grafik">
                                    <button type="button" id="btnL1TypeBar" onclick="switchL1TrendType('bar')" style="padding:2px 7px;border-radius:4px;font-size:10px;font-weight:800;border:none;cursor:pointer;background:#2563eb;color:#fff;">Bar</button>
                                    <button type="button" id="btnL1TypeLine" onclick="switchL1TrendType('line')" style="padding:2px 7px;border-radius:4px;font-size:10px;font-weight:800;border:none;cursor:pointer;background:transparent;color:#64748b;">Line</button>
                                </div>
                                <div style="display:flex;align-items:center;gap:2px;background:#f1f5f9;padding:2px;border-radius:6px;" title="Format Tampilan">
                                    <button type="button" id="btnL1ViewChart" onclick="switchL1TrendView('chart')" style="padding:2px 7px;border-radius:4px;font-size:10px;font-weight:800;border:none;cursor:pointer;background:#4f46e5;color:#fff;">Grafik</button>
                                    <button type="button" id="btnL1ViewTable" onclick="switchL1TrendView('table')" style="padding:2px 7px;border-radius:4px;font-size:10px;font-weight:800;border:none;cursor:pointer;background:transparent;color:#64748b;">Tabel</button>
                                </div>
                            </div>
                        </div>
                        <div style="position:relative;height:190px;width:100%;">
                            <div id="wrapperL1TrendChart" style="position:relative;height:100%;width:100%;">
                                <canvas id="chartL1Trend"></canvas>
                            </div>
                            <div id="wrapperL1TrendTable" style="display:none;max-height:190px;overflow:auto;font-size:11px;"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── PANEL 2: DISTRIBUSI MODEL PRODUK ─────────────────────────── -->
            <div class="funnel-level-card" id="cardFunnelLevel2" style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,0.03);overflow:hidden;">
                <div style="padding:14px 18px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                    <div>
                        <h2 style="margin:0;font-size:15px;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:7px;">
                            <svg width="18" height="18" fill="none" stroke="#4f46e5" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                            </svg>
                            Distribusi Model Produk
                        </h2>
                        <p id="subTitleLevel2" style="margin:2px 0 0 25px;font-size:11px;color:#64748b;">
                            Sebaran model produk yang mengalami cacat terpilih. Klik model untuk mengisolasi part code spesifik.
                        </p>
                    </div>
                    <div style="display:flex;align-items:center;gap:6px;">
                        <span id="badgeModelSelected" style="display:none;padding:3px 9px;border-radius:6px;font-size:11px;font-weight:800;background:#eef2ff;color:#4338ca;border:1px solid #c7d2fe;">
                            Model: <strong id="lblSelectedModel" style="margin-left:3px;">-</strong>
                        </span>
                        <button type="button" id="btnClearModel" onclick="clearModelSelection()" style="display:none;padding:3px 9px;border-radius:6px;font-size:10px;font-weight:800;background:#fee2e2;color:#b91c1c;border:1px solid #fecaca;cursor:pointer;">
                            Ganti Model
                        </button>
                    </div>
                </div>

                <div id="contentFunnelModels" style="padding:14px 16px;display:none;grid-template-columns:1fr 1fr;gap:14px;align-items:stretch;" class="side-by-side-grid">
                    <!-- Kiri: Donut & List Model -->
                    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px;display:grid;grid-template-columns:210px 1fr;gap:12px;align-items:center;">
                        <div style="position:relative;width:100%;height:220px;display:flex;align-items:center;justify-content:center;">
                            <canvas id="chartFunnelModels"></canvas>
                            <div id="centerBadgeModels" class="donut-center-badge">
                                <span class="donut-center-sub" id="centerSubModels">TOTAL MODEL</span>
                                <span class="donut-center-val" id="centerValModels">0</span>
                                <span class="donut-center-unit" id="centerUnitModels">PCS</span>
                            </div>
                        </div>
                        <div id="listFunnelModels" style="display:flex;flex-direction:column;gap:6px;max-height:220px;overflow-y:auto;padding-right:4px;">
                        </div>
                    </div>

                    <!-- Kanan: Stacked Bar / Line Grafik Tren Tanggal per Model -->
                    <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:10px;padding:12px;display:flex;flex-direction:column;justify-content:space-between;">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;flex-wrap:wrap;gap:6px;">
                            <span style="font-size:12px;font-weight:800;color:#0f172a;display:flex;align-items:center;gap:5px;">
                                <svg width="14" height="14" fill="none" stroke="#4f46e5" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"/></svg>
                                Tren Tanggal Model Teratas
                            </span>
                            <div style="display:flex;align-items:center;gap:4px;">
                                <div style="display:flex;align-items:center;gap:2px;background:#f1f5f9;padding:2px;border-radius:6px;" title="Tipe Grafik">
                                    <button type="button" id="btnL2TypeBar" onclick="switchL2TrendType('bar')" style="padding:2px 7px;border-radius:4px;font-size:10px;font-weight:800;border:none;cursor:pointer;background:#2563eb;color:#fff;">Bar</button>
                                    <button type="button" id="btnL2TypeLine" onclick="switchL2TrendType('line')" style="padding:2px 7px;border-radius:4px;font-size:10px;font-weight:800;border:none;cursor:pointer;background:transparent;color:#64748b;">Line</button>
                                </div>
                                <div style="display:flex;align-items:center;gap:2px;background:#f1f5f9;padding:2px;border-radius:6px;" title="Format Tampilan">
                                    <button type="button" id="btnL2ViewChart" onclick="switchL2TrendView('chart')" style="padding:2px 7px;border-radius:4px;font-size:10px;font-weight:800;border:none;cursor:pointer;background:#4f46e5;color:#fff;">Grafik</button>
                                    <button type="button" id="btnL2ViewTable" onclick="switchL2TrendView('table')" style="padding:2px 7px;border-radius:4px;font-size:10px;font-weight:800;border:none;cursor:pointer;background:transparent;color:#64748b;">Tabel</button>
                                </div>
                            </div>
                        </div>
                        <div style="position:relative;height:190px;width:100%;">
                            <div id="wrapperL2TrendChart" style="position:relative;height:100%;width:100%;">
                                <canvas id="chartL2Trend"></canvas>
                            </div>
                            <div id="wrapperL2TrendTable" style="display:none;max-height:190px;overflow:auto;font-size:11px;"></div>
                        </div>
                    </div>
                </div>
                <div id="placeholderFunnelModels" style="padding:36px 18px;text-align:center;color:#64748b;">
                    <div style="width:48px;height:48px;border-radius:12px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;margin:0 auto 10px auto;color:#94a3b8;">
                        <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" /></svg>
                    </div>
                    <div style="font-weight:800;font-size:13px;color:#334155;">Pilih Jenis Defect Terlebih Dahulu</div>
                    <p style="margin:3px 0 0 0;font-size:11px;color:#94a3b8;">Klik salah satu kartu jenis defect di atas untuk memuat sebaran model produk terkait.</p>
                </div>
            </div>

            <!-- ── PANEL 3: DISTRIBUSI PART CODE & NAME ─────────────────────── -->
            <div class="funnel-level-card" id="cardFunnelLevel3" style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,0.03);overflow:hidden;">
                <div style="padding:14px 18px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                    <div>
                        <h2 style="margin:0;font-size:15px;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:7px;">
                            <svg width="18" height="18" fill="none" stroke="#059669" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                            </svg>
                            Distribusi Part Code & Name
                        </h2>
                        <p id="subTitleLevel3" style="margin:2px 0 0 25px;font-size:11px;color:#64748b;">
                            Sebaran part code terdampak pada model terpilih. Klik part untuk membuka grafik tren waktu dan riwayat inspeksi.
                        </p>
                    </div>
                    <div style="display:flex;align-items:center;gap:6px;">
                        <span id="badgePartSelected" style="display:none;padding:3px 9px;border-radius:6px;font-size:11px;font-weight:800;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;">
                            Part: <strong id="lblSelectedPart" style="margin-left:3px;">-</strong>
                        </span>
                        <button type="button" id="btnClearPart" onclick="clearPartSelection()" style="display:none;padding:3px 9px;border-radius:6px;font-size:10px;font-weight:800;background:#fee2e2;color:#b91c1c;border:1px solid #fecaca;cursor:pointer;">
                            Ganti Part
                        </button>
                    </div>
                </div>

                <div id="contentFunnelParts" style="padding:14px 16px;display:none;grid-template-columns:1fr 1fr;gap:14px;align-items:stretch;" class="side-by-side-grid">
                    <!-- Kiri: Donut & List Part -->
                    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px;display:grid;grid-template-columns:210px 1fr;gap:12px;align-items:center;">
                        <div style="position:relative;width:100%;height:220px;display:flex;align-items:center;justify-content:center;">
                            <canvas id="chartFunnelParts"></canvas>
                            <div id="centerBadgeParts" class="donut-center-badge">
                                <span class="donut-center-sub" id="centerSubParts">TOTAL PART</span>
                                <span class="donut-center-val" id="centerValParts">0</span>
                                <span class="donut-center-unit" id="centerUnitParts">PCS</span>
                            </div>
                        </div>
                        <div id="listFunnelParts" style="display:flex;flex-direction:column;gap:6px;max-height:220px;overflow-y:auto;padding-right:4px;">
                        </div>
                    </div>

                    <!-- Kanan: Stacked Bar / Line Grafik Tren Tanggal per Part -->
                    <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:10px;padding:12px;display:flex;flex-direction:column;justify-content:space-between;">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;flex-wrap:wrap;gap:6px;">
                            <span style="font-size:12px;font-weight:800;color:#0f172a;display:flex;align-items:center;gap:5px;">
                                <svg width="14" height="14" fill="none" stroke="#059669" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"/></svg>
                                Tren Tanggal Part Teratas
                            </span>
                            <div style="display:flex;align-items:center;gap:4px;">
                                <div style="display:flex;align-items:center;gap:2px;background:#f1f5f9;padding:2px;border-radius:6px;" title="Tipe Grafik">
                                    <button type="button" id="btnL3TypeBar" onclick="switchL3TrendType('bar')" style="padding:2px 7px;border-radius:4px;font-size:10px;font-weight:800;border:none;cursor:pointer;background:#2563eb;color:#fff;">Bar</button>
                                    <button type="button" id="btnL3TypeLine" onclick="switchL3TrendType('line')" style="padding:2px 7px;border-radius:4px;font-size:10px;font-weight:800;border:none;cursor:pointer;background:transparent;color:#64748b;">Line</button>
                                </div>
                                <div style="display:flex;align-items:center;gap:2px;background:#f1f5f9;padding:2px;border-radius:6px;" title="Format Tampilan">
                                    <button type="button" id="btnL3ViewChart" onclick="switchL3TrendView('chart')" style="padding:2px 7px;border-radius:4px;font-size:10px;font-weight:800;border:none;cursor:pointer;background:#4f46e5;color:#fff;">Grafik</button>
                                    <button type="button" id="btnL3ViewTable" onclick="switchL3TrendView('table')" style="padding:2px 7px;border-radius:4px;font-size:10px;font-weight:800;border:none;cursor:pointer;background:transparent;color:#64748b;">Tabel</button>
                                </div>
                            </div>
                        </div>
                        <div style="position:relative;height:190px;width:100%;">
                            <div id="wrapperL3TrendChart" style="position:relative;height:100%;width:100%;">
                                <canvas id="chartL3Trend"></canvas>
                            </div>
                            <div id="wrapperL3TrendTable" style="display:none;max-height:190px;overflow:auto;font-size:11px;"></div>
                        </div>
                    </div>
                </div>
                <div id="placeholderFunnelParts" style="padding:36px 18px;text-align:center;color:#64748b;">
                    <div style="width:48px;height:48px;border-radius:12px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;margin:0 auto 10px auto;color:#94a3b8;">
                        <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" /></svg>
                    </div>
                    <div style="font-weight:800;font-size:13px;color:#334155;">Pilih Model Produk Terlebih Dahulu</div>
                    <p style="margin:3px 0 0 0;font-size:11px;color:#94a3b8;">Silakan pilih salah satu model produk di atas untuk mengisolasi part code spesifik.</p>
                </div>
            </div>

        </div><!-- End of cascading-funnel-section -->

        <!-- ── 3. ANALISIS MENDALAM PART TERPILIH (DEEP-DIVE) ───────────────── -->
        <div id="sectionDeepDiveAnalysis" style="margin-top:20px;">
            
            <!-- State 1: Placeholder sebelum Part dipilih -->
            <div id="deepDivePlaceholder" style="background:#ffffff;border:2px dashed #cbd5e1;border-radius:14px;padding:48px 24px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,0.02);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:16px;">
                <div style="width:60px;height:60px;border-radius:16px;background:#eff6ff;border:1px solid #dbeafe;display:flex;align-items:center;justify-content:center;color:#2563eb;box-shadow:0 2px 6px rgba(37,99,235,0.1);">
                    <svg width="30" height="30" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                    </svg>
                </div>
                <div style="max-width:560px;">
                    <h3 style="margin:0 0 6px 0;font-size:17px;font-weight:900;color:#0f172a;letter-spacing:-0.3px;">
                        Pilih Part Code untuk Membuka Analisis Mendalam
                    </h3>
                    <p style="margin:0;font-size:12px;color:#64748b;line-height:1.6;">
                        Silakan pilih salah satu part code pada panel <strong>Distribusi Part Code</strong> di atas. Grafik tren waktu (Line/Bar), 4 KPI cards, dan log temuan inspeksi terperinci akan otomatis ditampilkan di sini.
                    </p>
                </div>
            </div>

            <!-- State 2: Konten Analisis Mendalam Part Terpilih -->
            <div id="deepDiveContent" style="display:none;flex-direction:column;gap:16px;">
                
                <!-- Deep-Dive Header Banner -->
                <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:14px 18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;box-shadow:0 1px 3px rgba(0,0,0,0.03);">
                    <div>
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                            <span style="display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:6px;font-size:11px;font-weight:800;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;">
                                Analisis Mendalam Part
                            </span>
                            <span style="font-size:12px;font-weight:600;color:#64748b;">
                                Defect: <strong id="ddDefectName" style="color:#0f172a;">-</strong> &bull;
                                Model: <strong id="ddModelName" style="color:#4f46e5;">-</strong>
                            </span>
                        </div>
                        <h2 style="margin:4px 0 0 0;font-size:16px;font-weight:900;color:#0f172a;">
                            Part: <span id="ddPartCode" style="font-family:monospace;color:#2563eb;">-</span> &ndash; <span id="ddPartName">-</span>
                        </h2>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <button type="button" onclick="exportDefectExcel()" 
                                style="display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:8px;background:#059669;color:#ffffff;font-size:11px;font-weight:800;border:none;cursor:pointer;box-shadow:0 1px 3px rgba(5,150,105,0.25);transition:all 0.2s;"
                                onmouseover="this.style.background='#047857'" onmouseout="this.style.background='#059669'">
                            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            Export Excel Part Ini
                        </button>
                        <button type="button" onclick="clearPartSelection()" style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:8px;background:#fee2e2;color:#b91c1c;border:1px solid #fecaca;font-size:11px;font-weight:800;cursor:pointer;">
                            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                            Tutup Analisis Part
                        </button>
                    </div>
                </div>

                <!-- 4 Executive KPI Cards -->
                <div id="deepDiveKpiGrid" style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;">
                    <!-- KPI 1 -->
                    <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,0.03);display:flex;align-items:center;justify-content:space-between;">
                        <div>
                            <span style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;display:block;">Total Cacat Part</span>
                            <div style="display:flex;align-items:baseline;gap:6px;margin:2px 0;">
                                <span id="kpiTotalMetric" style="font-size:24px;font-weight:900;color:#0f172a;line-height:1;">0</span>
                                <span id="kpiUnitText" style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;">PCS</span>
                            </div>
                            <span id="kpiSubMetric" style="font-size:11px;color:#94a3b8;">0 Lot total</span>
                        </div>
                        <div style="width:44px;height:44px;border-radius:10px;background:#fff1f2;border:1px solid #ffe4e6;display:flex;align-items:center;justify-content:center;color:#e11d48;flex-shrink:0;">
                            <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 17h8m0 0V9m0 8l-8-8-4 4-6-6" /></svg>
                        </div>
                    </div>

                    <!-- KPI 2 -->
                    <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,0.03);display:flex;align-items:center;justify-content:space-between;">
                        <div style="min-width:0;padding-right:8px;">
                            <span style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;display:block;">Puncak Lonjakan Cacat</span>
                            <div id="kpiPeakLabel" style="font-size:14px;font-weight:900;color:#b91c1c;margin:2px 0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">-</div>
                            <span style="font-size:11px;color:#94a3b8;">Titik insiden frekuensi tertinggi</span>
                        </div>
                        <div style="width:44px;height:44px;border-radius:10px;background:#fffbeb;border:1px solid #fef3c7;display:flex;align-items:center;justify-content:center;color:#d97706;flex-shrink:0;">
                            <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" /></svg>
                        </div>
                    </div>

                    <!-- KPI 3 -->
                    <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,0.03);display:flex;align-items:center;justify-content:space-between;">
                        <div>
                            <span style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;display:block;">Rata-Rata per Interval</span>
                            <div style="display:flex;align-items:baseline;gap:6px;margin:2px 0;">
                                <span id="kpiAvgMetric" style="font-size:24px;font-weight:900;color:#0f172a;line-height:1;">0</span>
                                <span id="kpiAvgUnit" style="font-size:11px;font-weight:800;color:#64748b;text-transform:uppercase;">PCS</span>
                            </div>
                            <span id="kpiIntervalCount" style="font-size:11px;color:#94a3b8;">Dari 0 interval waktu</span>
                        </div>
                        <div style="width:44px;height:44px;border-radius:10px;background:#eef2ff;border:1px solid #e0e7ff;display:flex;align-items:center;justify-content:center;color:#4f46e5;flex-shrink:0;">
                            <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" /></svg>
                        </div>
                    </div>

                    <!-- KPI 4 -->
                    <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,0.03);display:flex;align-items:center;justify-content:space-between;">
                        <div>
                            <span style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;display:block;">Kasus Lot Terdampak</span>
                            <div style="display:flex;align-items:baseline;gap:6px;margin:2px 0;">
                                <span id="kpiTotalLots" style="font-size:24px;font-weight:900;color:#4338ca;line-height:1;">0</span>
                                <span style="font-size:11px;font-weight:800;color:#64748b;">Lot</span>
                            </div>
                            <span style="font-size:11px;color:#94a3b8;">Total insiden di line OQC</span>
                        </div>
                        <div style="width:44px;height:44px;border-radius:10px;background:#faf5ff;border:1px solid #f3e8ff;display:flex;align-items:center;justify-content:center;color:#7e22ce;flex-shrink:0;">
                            <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" /></svg>
                        </div>
                    </div>
                </div>

                <!-- Grafik Tren Timeline Container (Line & Bar Chart Switcher) -->
                <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:18px;box-shadow:0 1px 3px rgba(0,0,0,0.03);display:flex;flex-direction:column;gap:14px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;padding-bottom:12px;border-bottom:1px solid #f1f5f9;">
                        <div>
                            <h3 style="margin:0;font-size:15px;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:6px;">
                                <svg width="18" height="18" fill="none" stroke="#2563eb" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z" />
                                </svg>
                                Grafik Tren Kejadian: <span id="lblTimelineChartTarget" style="color:#2563eb;">-</span>
                            </h3>
                            <p id="lblTimelineIntervalDesc" style="margin:2px 0 0 0;font-size:11px;color:#64748b;">
                                Distribusi frekuensi cacat sepanjang interval waktu terpilih.
                            </p>
                        </div>

                        <!-- Switcher Buttons (Line vs Bar) -->
                        <div style="display:flex;align-items:center;gap:3px;background:#f1f5f9;padding:3px;border-radius:8px;border:1px solid #e2e8f0;">
                            <button type="button" id="btnTimelineLine" onclick="switchTimelineChartType('line')"
                                    style="padding:4px 10px;border-radius:6px;font-size:11px;font-weight:800;border:none;cursor:pointer;transition:all 0.2s;background:#2563eb;color:#ffffff;box-shadow:0 1px 3px rgba(37,99,235,0.25);">
                                Garis (Line)
                            </button>
                            <button type="button" id="btnTimelineBar" onclick="switchTimelineChartType('bar')"
                                    style="padding:4px 10px;border-radius:6px;font-size:11px;font-weight:700;border:none;cursor:pointer;transition:all 0.2s;background:transparent;color:#64748b;">
                                Batang (Bar)
                            </button>
                        </div>
                    </div>

                    <!-- Chart Canvas -->
                    <div style="position:relative;height:280px;width:100%;">
                        <canvas id="chartTrendTimeline"></canvas>
                    </div>
                </div>

                <!-- Tabel Riwayat Temuan Inspeksi Terperinci (Audit Trail) -->
                <div id="funnel-table-section" style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,0.03);overflow:hidden;">
                    <div style="padding:14px 18px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                        <div>
                            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                <h3 style="margin:0;font-size:15px;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:6px;">
                                    <svg width="18" height="18" fill="none" stroke="#059669" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                                    </svg>
                                    Riwayat Temuan Cacat Inspeksi Terperinci
                                </h3>
                                <span id="tableScopeBadge" style="display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:6px;font-size:11px;font-weight:800;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;">
                                    Cakupan: Semua Data
                                </span>
                            </div>
                            <p style="margin:2px 0 0 0;font-size:11px;color:#64748b;">
                                Catatan kronologis setiap kali cacat ditemukan di area inspeksi OQC sesuai seleksi drilldown yang aktif.
                            </p>
                        </div>

                        <!-- Table Search Box -->
                        <div style="display:flex;align-items:center;gap:8px;">
                            <div style="position:relative;">
                                <input type="text" id="tableSearchInput" placeholder="Cari part / model / defect / kanban..."
                                       style="padding:5px 10px 5px 30px;font-size:11px;border-radius:8px;border:1px solid #cbd5e1;outline:none;width:230px;background:#f8fafc;"
                                       onfocus="this.style.background='#ffffff';this.style.borderColor='#2563eb';"
                                       onblur="this.style.background='#f8fafc';this.style.borderColor='#cbd5e1';">
                                <svg width="13" height="13" fill="none" stroke="#94a3b8" stroke-width="2" viewBox="0 0 24 24"
                                     style="position:absolute;left:9px;top:50%;transform:translateY(-50%);pointer-events:none;">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                            </div>
                        </div>
                    </div>

                    <!-- Table Responsive Wrapper -->
                    <div style="overflow-x:auto;">
                        <table style="width:100%;border-collapse:collapse;text-align:left;font-size:12px;">
                            <thead>
                                <tr style="background:#f8fafc;color:#475569;border-bottom:1px solid #e2e8f0;font-weight:900;text-transform:uppercase;font-size:10px;letter-spacing:0.5px;">
                                    <th style="padding:10px 14px;text-align:center;width:45px;">No</th>
                                    <th style="padding:10px 14px;width:125px;">Tanggal & Jam</th>
                                    <th style="padding:10px 14px;">Part Code & Part Name</th>
                                    <th style="padding:10px 14px;">Model Produk</th>
                                    <th style="padding:10px 14px;">Jenis Defect</th>
                                    <th style="padding:10px 14px;text-align:center;width:100px;">Inspeksi</th>
                                    <th style="padding:10px 14px;min-width:140px;">No Kanban / Customer</th>
                                    <th style="padding:10px 14px;min-width:110px;">Lot No / Ref No</th>
                                    <th style="padding:10px 14px;">QC Inspector</th>
                                    <th style="padding:10px 14px;text-align:right;width:110px;">Jumlah Temuan</th>
                                </tr>
                            </thead>
                            <tbody id="historyTableBody">
                                <tr>
                                    <td colspan="10" style="padding:32px;text-align:center;color:#94a3b8;font-weight:600;">
                                        Memuat catatan riwayat temuan inspeksi...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div style="padding:12px 16px;background:#f8fafc;border-top:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;font-size:11px;color:#64748b;flex-wrap:wrap;gap:8px;">
                        <span id="tableRecordCount">
                            Menampilkan <strong>0</strong> temuan defect pada seleksi ini.
                        </span>
                        <span style="color:#94a3b8;">
                            Data diurutkan berdasarkan tanggal inspeksi terbaru.
                        </span>
                    </div>
                </div>
            </div><!-- End of deepDiveContent -->
        </div><!-- End of sectionDeepDiveAnalysis -->
    </div><!-- End of viewContainerDefectMode -->

        <!-- ── 3. VIEW MODE PART CODE CONTAINER (SIDE-BY-SIDE: DONUT & STACKED BAR) ──────── -->
        <div id="viewContainerPartMode" style="display:none;flex-direction:column;gap:14px;">
            
            <!-- PANEL 1: DISTRIBUSI PART CODE (KIRI: DONUT PART, KANAN: STACKED BAR PART) -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;" class="side-by-side-grid">
                
                <!-- Kiri: Donut Peringkat Part Code -->
                <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,0.03);overflow:hidden;display:flex;flex-direction:column;">
                    <div style="padding:14px 18px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                        <div>
                            <h2 style="margin:0;font-size:15px;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:7px;">
                                <svg width="18" height="18" fill="none" stroke="#059669" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                </svg>
                                Distribusi Peringkat Part Code
                            </h2>
                            <p style="margin:2px 0 0 25px;font-size:11px;color:#64748b;">
                                Peringkat part dengan temuan cacat terbanyak. Klik salah satu part untuk analisis mendalam.
                            </p>
                        </div>
                        <span id="badgeActivePartMode" style="display:none;padding:3px 9px;border-radius:6px;font-size:11px;font-weight:800;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;">
                            Part: <strong id="lblActivePartMode" style="margin-left:3px;">-</strong>
                        </span>
                    </div>

                    <div style="padding:16px 18px;display:grid;grid-template-columns:260px 1fr;gap:16px;align-items:center;flex:1;" class="part-donut-grid">
                        <div style="position:relative;width:100%;height:230px;display:flex;align-items:center;justify-content:center;">
                            <canvas id="chartPartRankingDonut"></canvas>
                            <div id="centerBadgePartRank" class="donut-center-badge">
                                <span class="donut-center-sub" id="centerSubPartRank">TOTAL CACAT</span>
                                <span class="donut-center-val" id="centerValPartRank">0</span>
                                <span class="donut-center-unit" id="centerUnitPartRank">PCS</span>
                            </div>
                        </div>
                        <div id="listPartRankingCards" style="display:flex;flex-direction:column;gap:7px;max-height:250px;overflow-y:auto;padding-right:4px;">
                            <div style="padding:20px;text-align:center;color:#94a3b8;font-size:12px;">Memuat data peringkat part...</div>
                        </div>
                    </div>
                </div>

                <!-- Kanan: Stacked Bar Tren Tanggal per Part Code -->
                <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,0.03);overflow:hidden;display:flex;flex-direction:column;">
                    <div style="padding:14px 18px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                        <div>
                            <h3 style="margin:0;font-size:15px;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:7px;">
                                <svg width="18" height="18" fill="none" stroke="#2563eb" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z" />
                                </svg>
                                Tren Tanggal Part Teratas
                            </h3>
                            <p id="lblPartStackedSubtitle" style="margin:2px 0 0 25px;font-size:11px;color:#64748b;">
                                Batang bertumpuk (stacked) menunjukkan sebaran temuan part per interval waktu.
                            </p>
                        </div>

                        <!-- Mini Controls: Unit PCS/PPM, Rank 3/5/10, View Grafik/Tabel -->
                        <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                            <div style="display:flex;align-items:center;gap:2px;background:#f1f5f9;padding:2px;border-radius:7px;border:1px solid #e2e8f0;" title="Pilih Satuan Tren">
                                <button type="button" id="btnPartUnitPcs" onclick="switchPartTrendUnit('pcs')"
                                        style="padding:3px 9px;border-radius:5px;font-size:10px;font-weight:800;border:none;cursor:pointer;background:#0f172a;color:#ffffff;box-shadow:0 1px 2px rgba(15,23,42,0.2);">
                                    PCS
                                </button>
                                <button type="button" id="btnPartUnitPpm" onclick="switchPartTrendUnit('ppm')"
                                        style="padding:3px 9px;border-radius:5px;font-size:10px;font-weight:800;border:none;cursor:pointer;background:transparent;color:#64748b;">
                                    PPM
                                </button>
                            </div>

                            <div style="display:flex;align-items:center;gap:2px;background:#f1f5f9;padding:2px;border-radius:7px;border:1px solid #e2e8f0;" title="Batas Peringkat Part">
                                <button type="button" class="part-rank-btn" data-rank="3" onclick="switchPartTrendRank('3')"
                                        style="padding:3px 8px;border-radius:5px;font-size:10px;font-weight:700;border:none;cursor:pointer;background:transparent;color:#64748b;">
                                    3
                                </button>
                                <button type="button" class="part-rank-btn" data-rank="5" onclick="switchPartTrendRank('5')"
                                        style="padding:3px 8px;border-radius:5px;font-size:10px;font-weight:800;border:none;cursor:pointer;background:#4f46e5;color:#ffffff;box-shadow:0 1px 2px rgba(79,70,229,0.25);">
                                    5
                                </button>
                                <button type="button" class="part-rank-btn" data-rank="10" onclick="switchPartTrendRank('10')"
                                        style="padding:3px 8px;border-radius:5px;font-size:10px;font-weight:700;border:none;cursor:pointer;background:transparent;color:#64748b;">
                                    10
                                </button>
                            </div>

                            <div style="display:flex;align-items:center;gap:2px;background:#f1f5f9;padding:2px;border-radius:7px;border:1px solid #e2e8f0;" title="Pilih Format Tampilan">
                                <button type="button" id="btnPartViewChart" onclick="switchPartTrendView('chart')"
                                        style="padding:3px 9px;border-radius:5px;font-size:10px;font-weight:800;border:none;cursor:pointer;background:#2563eb;color:#ffffff;box-shadow:0 1px 2px rgba(37,99,235,0.2);">
                                    Grafik
                                </button>
                                <button type="button" id="btnPartViewTable" onclick="switchPartTrendView('table')"
                                        style="padding:3px 9px;border-radius:5px;font-size:10px;font-weight:700;border:none;cursor:pointer;background:transparent;color:#64748b;">
                                    Tabel
                                </button>
                            </div>
                        </div>
                    </div>

                    <div style="padding:14px 18px;position:relative;flex:1;min-height:260px;display:flex;flex-direction:column;justify-content:center;">
                        <div id="wrapperPartStackedChart" style="position:relative;height:240px;width:100%;">
                            <canvas id="chartPartStackedTrend"></canvas>
                        </div>
                        <div id="wrapperPartStackedTable" style="display:none;max-height:240px;overflow:auto;font-size:11px;">
                            <!-- Matriks data dihasilkan via JS -->
                        </div>
                    </div>
                </div>
            </div>

            <!-- PANEL 2: ANALISIS MENDALAM PART TERPILIH (SIDE-BY-SIDE: DONUT DEFECT & STACKED BAR DEFECT) -->
            <div id="sectionPartDeepDive" style="margin-top:2px;">
                
                <!-- State 1: Placeholder Sebelum Part Dipilih -->
                <div id="partDeepDivePlaceholder" style="background:#ffffff;border:2px dashed #cbd5e1;border-radius:12px;padding:36px 20px;text-align:center;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;">
                    <div style="width:48px;height:48px;border-radius:12px;background:#eff6ff;border:1px solid #dbeafe;display:flex;align-items:center;justify-content:center;color:#2563eb;">
                        <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                        </svg>
                    </div>
                    <div style="max-width:520px;">
                        <div style="font-size:15px;font-weight:900;color:#0f172a;">Pilih Part Code untuk Membuka Analisis Mendalam</div>
                        <p style="margin:4px 0 0 0;font-size:11px;color:#64748b;line-height:1.5;">
                            Silakan klik salah satu kartu atau irisan pada panel <strong>Distribusi Peringkat Part Code</strong> di atas. Komposisi jenis defect, grafik tren bertumpuk (stacked bar), dan tabel temuan inspeksi terperinci akan otomatis terbuka di sini.
                        </p>
                    </div>
                </div>

                <!-- State 2: Konten Aktif Setelah Part Dipilih -->
                <div id="partDeepDiveContent" style="display:none;flex-direction:column;gap:14px;">
                    
                    <!-- Header Banner Part Terpilih -->
                    <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:12px 18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;box-shadow:0 1px 3px rgba(0,0,0,0.03);">
                        <div>
                            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                                <span style="padding:2px 8px;border-radius:5px;font-size:10px;font-weight:800;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;">
                                    Analisis Mendalam Part
                                </span>
                                <span style="font-size:11px;font-weight:700;color:#64748b;">
                                    Model: <strong id="ddPartModelName" style="color:#4f46e5;">-</strong>
                                </span>
                            </div>
                            <h3 style="margin:3px 0 0 0;font-size:15px;font-weight:900;color:#0f172a;">
                                Part: <span id="ddPartCodeText" style="font-family:monospace;color:#2563eb;">-</span> &ndash; <span id="ddPartNameText">-</span>
                            </h3>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <button type="button" onclick="clearSelectedPart()" 
                                    style="display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:7px;background:#fee2e2;color:#b91c1c;border:1px solid #fecaca;font-size:11px;font-weight:800;cursor:pointer;">
                                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                Ganti / Tutup Part
                            </button>
                        </div>
                    </div>

                    <!-- Side-by-Side: Kiri Donut Defect pada Part, Kanan Stacked Bar Defect (Sesuai Referensi Gambar Pengguna) -->
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;" class="side-by-side-grid">
                        
                        <!-- Kiri: Donut Komposisi Jenis Defect pada Part Ini -->
                        <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,0.03);overflow:hidden;display:flex;flex-direction:column;">
                            <div style="padding:14px 18px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                                <div>
                                    <h3 style="margin:0;font-size:15px;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:7px;">
                                        <svg width="18" height="18" fill="none" stroke="#f43f5e" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                        </svg>
                                        Komposisi Jenis Defect pada Part Ini
                                    </h3>
                                    <p style="margin:2px 0 0 25px;font-size:11px;color:#64748b;">
                                        Peringkat temuan cacat pada part ini. Klik jenis defect untuk menyaring tabel riwayat di bawah.
                                    </p>
                                </div>
                                <div style="display:flex;align-items:center;gap:6px;">
                                    <span id="badgeActiveDefectFilter" style="display:none;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:800;background:#fff1f2;color:#be123c;border:1px solid #ffe4e6;">
                                        Defect: <strong id="lblActiveDefectFilter" style="margin-left:3px;">-</strong>
                                    </span>
                                    <button type="button" id="btnResetDefectFilter" onclick="resetPartDefectFilter()" 
                                            style="display:none;padding:3px 8px;border-radius:6px;font-size:10px;font-weight:800;background:#f1f5f9;color:#475569;border:1px solid #cbd5e1;cursor:pointer;">
                                        Tampilkan Semua
                                    </button>
                                </div>
                            </div>

                            <div style="padding:16px 18px;display:grid;grid-template-columns:260px 1fr;gap:16px;align-items:center;flex:1;" class="part-donut-grid">
                                <div style="position:relative;width:100%;height:230px;display:flex;align-items:center;justify-content:center;">
                                    <canvas id="chartPartDefectDonut"></canvas>
                                    <div id="centerBadgePartDefect" class="donut-center-badge">
                                        <span class="donut-center-sub" id="centerSubPartDefect">TOTAL DEFECT</span>
                                        <span class="donut-center-val" id="centerValPartDefect">0</span>
                                        <span class="donut-center-unit" id="centerUnitPartDefect">PCS</span>
                                    </div>
                                </div>
                                <div id="listPartDefectCards" style="display:flex;flex-direction:column;gap:7px;max-height:250px;overflow-y:auto;padding-right:4px;">
                                    <div style="padding:20px;text-align:center;color:#94a3b8;font-size:12px;">Memuat data cacat...</div>
                                </div>
                            </div>
                        </div>

                        <!-- Kanan: Stacked Bar Tren Tanggal per Jenis Defect (PERSIS GAMBAR REFERENSI PENGGUNA) -->
                        <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,0.03);overflow:hidden;display:flex;flex-direction:column;">
                            <div style="padding:14px 18px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                                <div>
                                    <h3 id="lblDefectStackedTitle" style="margin:0;font-size:15px;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:7px;">
                                        <svg width="18" height="18" fill="none" stroke="#2563eb" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z" />
                                        </svg>
                                        Top Defect (PCS)
                                    </h3>
                                    <p id="lblDefectStackedSubtitle" style="margin:2px 0 0 25px;font-size:11px;color:#64748b;">
                                        Periode terpilih
                                    </p>
                                </div>

                                <!-- Mini Controls: Unit PCS/PPM, Rank 3/5/10, View Grafik/Tabel -->
                                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                                    <div style="display:flex;align-items:center;gap:2px;background:#f1f5f9;padding:2px;border-radius:7px;border:1px solid #e2e8f0;" title="Pilih Satuan Tren">
                                        <button type="button" id="btnDefectUnitPcs" onclick="switchDefectTrendUnit('pcs')"
                                                style="padding:3px 9px;border-radius:5px;font-size:10px;font-weight:800;border:none;cursor:pointer;background:#0f172a;color:#ffffff;box-shadow:0 1px 2px rgba(15,23,42,0.2);">
                                            PCS
                                        </button>
                                        <button type="button" id="btnDefectUnitPpm" onclick="switchDefectTrendUnit('ppm')"
                                                style="padding:3px 9px;border-radius:5px;font-size:10px;font-weight:800;border:none;cursor:pointer;background:transparent;color:#64748b;">
                                            PPM
                                        </button>
                                    </div>

                                    <div style="display:flex;align-items:center;gap:2px;background:#f1f5f9;padding:2px;border-radius:7px;border:1px solid #e2e8f0;" title="Batas Peringkat Defect">
                                        <button type="button" class="defect-rank-btn" data-rank="3" onclick="switchDefectTrendRank('3')"
                                                style="padding:3px 8px;border-radius:5px;font-size:10px;font-weight:800;border:none;cursor:pointer;background:#4f46e5;color:#ffffff;box-shadow:0 1px 2px rgba(79,70,229,0.25);">
                                            3
                                        </button>
                                        <button type="button" class="defect-rank-btn" data-rank="5" onclick="switchDefectTrendRank('5')"
                                                style="padding:3px 8px;border-radius:5px;font-size:10px;font-weight:700;border:none;cursor:pointer;background:transparent;color:#64748b;">
                                            5
                                        </button>
                                        <button type="button" class="defect-rank-btn" data-rank="10" onclick="switchDefectTrendRank('10')"
                                                style="padding:3px 8px;border-radius:5px;font-size:10px;font-weight:700;border:none;cursor:pointer;background:transparent;color:#64748b;">
                                            10
                                        </button>
                                    </div>

                                    <div style="display:flex;align-items:center;gap:2px;background:#f1f5f9;padding:2px;border-radius:7px;border:1px solid #e2e8f0;" title="Pilih Format Tampilan">
                                        <button type="button" id="btnDefectViewChart" onclick="switchDefectTrendView('chart')"
                                                style="padding:3px 9px;border-radius:5px;font-size:10px;font-weight:800;border:none;cursor:pointer;background:#2563eb;color:#ffffff;box-shadow:0 1px 2px rgba(37,99,235,0.2);">
                                            Grafik
                                        </button>
                                        <button type="button" id="btnDefectViewTable" onclick="switchDefectTrendView('table')"
                                                style="padding:3px 9px;border-radius:5px;font-size:10px;font-weight:700;border:none;cursor:pointer;background:transparent;color:#64748b;">
                                            Tabel
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div style="padding:14px 18px;position:relative;flex:1;min-height:260px;display:flex;flex-direction:column;justify-content:center;">
                                <div id="wrapperDefectStackedChart" style="position:relative;height:240px;width:100%;">
                                    <canvas id="chartPartDefectStackedTrend"></canvas>
                                </div>
                                <div id="wrapperDefectStackedTable" style="display:none;max-height:240px;overflow:auto;font-size:11px;">
                                    <!-- Matriks data dihasilkan via JS -->
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- PANEL 3: TABEL RIWAYAT TEMUAN INSPEKSI TERPERINCI -->
                    <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,0.03);overflow:hidden;">
                        <div style="padding:14px 18px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                            <div>
                                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                    <h3 style="margin:0;font-size:15px;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:6px;">
                                        <svg width="18" height="18" fill="none" stroke="#059669" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                                        </svg>
                                        Riwayat Temuan Cacat Part Terperinci
                                    </h3>
                                    <span id="badgePartHistoryScope" style="display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:6px;font-size:11px;font-weight:800;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;">
                                        Cakupan: Semua Defect Part Ini
                                    </span>
                                </div>
                                <p style="margin:2px 0 0 0;font-size:11px;color:#64748b;">
                                    Catatan kronologis setiap kali cacat ditemukan untuk part ini. Klik jenis defect pada doughnut di atas untuk menyaring tabel.
                                </p>
                            </div>

                            <div style="display:flex;align-items:center;gap:8px;">
                                <div style="position:relative;">
                                    <input type="text" id="partTableSearchInput" placeholder="Cari defect / model / kanban..."
                                           style="padding:5px 10px 5px 30px;font-size:11px;border-radius:8px;border:1px solid #cbd5e1;outline:none;width:230px;background:#f8fafc;"
                                           onfocus="this.style.background='#ffffff';this.style.borderColor='#2563eb';"
                                           onblur="this.style.background='#f8fafc';this.style.borderColor='#cbd5e1';">
                                    <svg width="13" height="13" fill="none" stroke="#94a3b8" stroke-width="2" viewBox="0 0 24 24"
                                         style="position:absolute;left:9px;top:50%;transform:translateY(-50%);pointer-events:none;">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                    </svg>
                                </div>
                            </div>
                        </div>

                        <div style="overflow-x:auto;">
                            <table style="width:100%;border-collapse:collapse;text-align:left;font-size:12px;">
                                <thead>
                                    <tr style="background:#f8fafc;color:#475569;border-bottom:1px solid #e2e8f0;font-weight:900;text-transform:uppercase;font-size:10px;letter-spacing:0.5px;">
                                        <th style="padding:10px 14px;text-align:center;width:45px;">No</th>
                                        <th style="padding:10px 14px;width:125px;">Tanggal & Jam</th>
                                        <th style="padding:10px 14px;">Part Code & Part Name</th>
                                        <th style="padding:10px 14px;">Model Produk</th>
                                        <th style="padding:10px 14px;">Jenis Defect</th>
                                        <th style="padding:10px 14px;text-align:center;width:100px;">Inspeksi</th>
                                        <th style="padding:10px 14px;min-width:140px;">No Kanban / Customer</th>
                                        <th style="padding:10px 14px;min-width:110px;">Lot No / Ref No</th>
                                        <th style="padding:10px 14px;">QC Inspector</th>
                                        <th style="padding:10px 14px;text-align:right;width:110px;">Jumlah Temuan</th>
                                    </tr>
                                </thead>
                                <tbody id="partHistoryTableBody">
                                    <tr>
                                        <td colspan="10" style="padding:32px;text-align:center;color:#94a3b8;font-weight:600;">
                                            Memuat catatan riwayat temuan inspeksi part...
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div style="padding:12px 16px;background:#f8fafc;border-top:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;font-size:11px;color:#64748b;flex-wrap:wrap;gap:8px;">
                            <span id="partTableRecordCount">
                                Menampilkan <strong>0</strong> temuan defect pada seleksi part ini.
                            </span>
                            <span style="color:#94a3b8;">
                                Data diurutkan berdasarkan tanggal inspeksi terbaru.
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div><!-- End of viewContainerPartMode -->

    <!-- Guidance Modal: Validasi Alur Wajib Export Excel (Defect -> Model -> Part) -->
    <div id="modalExportGuide" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.65);backdrop-filter:blur(4px);z-index:9999;align-items:center;justify-content:center;padding:16px;">
        <div style="background:#ffffff;border-radius:16px;max-width:480px;width:100%;box-shadow:0 20px 25px -5px rgba(0,0,0,0.2),0 10px 10px -5px rgba(0,0,0,0.04);overflow:hidden;border:1px solid #e2e8f0;">
            <div style="padding:18px 22px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;">
                <div style="display:flex;align-items:center;gap:10px;">
                    <div style="width:36px;height:36px;border-radius:10px;background:#fef3c7;border:1px solid #fde68a;display:flex;align-items:center;justify-content:center;color:#d97706;">
                        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    </div>
                    <div>
                        <h3 style="margin:0;font-size:15px;font-weight:900;color:#0f172a;">Lengkapi Alur Analisis</h3>
                        <p style="margin:2px 0 0 0;font-size:11px;color:#64748b;">Pilih item pada pohon funnel untuk mengekspor</p>
                    </div>
                </div>
                <button type="button" onclick="closeExportGuideModal()" style="background:transparent;border:none;color:#94a3b8;cursor:pointer;padding:4px;border-radius:6px;" onmouseover="this.style.color='#0f172a'" onmouseout="this.style.color='#94a3b8'">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div style="padding:20px 22px;">
                <p style="margin:0 0 14px 0;font-size:12px;color:#334155;line-height:1.5;">
                    Laporan Excel lengkap beserta <strong>Grafik Visual Tren & Diagram Funnel</strong> memerlukan isolasi part spesifik. Silakan ikuti 3 langkah berikut:
                </p>
                <div style="display:flex;flex-direction:column;gap:8px;">
                    <div id="guideStep1" style="display:flex;align-items:center;gap:12px;padding:10px 14px;border-radius:10px;border:1px solid #e2e8f0;background:#f8fafc;transition:all 0.2s;">
                        <div style="width:24px;height:24px;border-radius:50%;background:#2563eb;color:#fff;font-weight:900;font-size:11px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">1</div>
                        <div style="font-size:12px;font-weight:700;color:#1e293b;">Pilih Jenis Defect <span style="font-size:10px;color:#64748b;font-weight:normal;display:block;">Klik jenis cacat mutu pada Panel 1 (Distribusi Jenis Defect)</span></div>
                    </div>
                    <div id="guideStep2" style="display:flex;align-items:center;gap:12px;padding:10px 14px;border-radius:10px;border:1px solid #e2e8f0;background:#f8fafc;transition:all 0.2s;">
                        <div style="width:24px;height:24px;border-radius:50%;background:#4f46e5;color:#fff;font-weight:900;font-size:11px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">2</div>
                        <div style="font-size:12px;font-weight:700;color:#1e293b;">Pilih Model Produk <span style="font-size:10px;color:#64748b;font-weight:normal;display:block;">Klik model produk terdampak pada Panel 2 (Distribusi Model Produk)</span></div>
                    </div>
                    <div id="guideStep3" style="display:flex;align-items:center;gap:12px;padding:10px 14px;border-radius:10px;border:1px solid #e2e8f0;background:#f8fafc;transition:all 0.2s;">
                        <div style="width:24px;height:24px;border-radius:50%;background:#059669;color:#fff;font-weight:900;font-size:11px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">3</div>
                        <div style="font-size:12px;font-weight:700;color:#1e293b;">Pilih Part Code & Name <span style="font-size:10px;color:#64748b;font-weight:normal;display:block;">Klik part terdampak pada Panel 3 untuk membuka Analisis Mendalam</span></div>
                    </div>
                </div>
            </div>
            <div style="padding:14px 22px;background:#f8fafc;border-top:1px solid #e2e8f0;display:flex;align-items:center;justify-content:flex-end;gap:10px;">
                <button type="button" id="btnGuideAction" onclick="proceedGuideStep()" style="padding:8px 18px;background:#2563eb;color:#ffffff;border:none;border-radius:8px;font-size:12px;font-weight:800;cursor:pointer;box-shadow:0 1px 3px rgba(37,99,235,0.3);">
                    Paham & Mulai Pilih
                </button>
            </div>
        </div>
    </div>

    <!-- Responsive Fallback CSS -->
    <style>
        @media (max-width: 1024px) {
            #deepDiveKpiGrid { grid-template-columns: repeat(2, 1fr) !important; }
            .side-by-side-grid { grid-template-columns: 1fr !important; }
            .part-donut-grid { grid-template-columns: 1fr !important; }
        }
        @media (max-width: 768px) {
            .funnel-grid-layout { grid-template-columns: 1fr !important; }
            .side-by-side-grid { grid-template-columns: 1fr !important; }
            .part-donut-grid { grid-template-columns: 1fr !important; }
        }
        @media (max-width: 640px) {
            #deepDiveKpiGrid { grid-template-columns: 1fr !important; }
        }
        .funnel-item-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 8px 12px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            background: #ffffff;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .funnel-item-card:hover {
            border-color: #94a3b8;
            background: #f8fafc;
            transform: translateX(2px);
        }
        .funnel-card-active-1 {
            border-color: #2563eb !important;
            background: #eff6ff !important;
            box-shadow: 0 0 0 1px #2563eb;
        }
        .funnel-card-active-2 {
            border-color: #4f46e5 !important;
            background: #eef2ff !important;
            box-shadow: 0 0 0 1px #4f46e5;
        }
        .funnel-card-active-3 {
            border-color: #059669 !important;
            background: #ecfdf5 !important;
            box-shadow: 0 0 0 1px #059669;
        }
        /* ── Donut Centerpiece Widget ────────────────────────────────────────── */
        .donut-center-badge {
            position: absolute;
            pointer-events: none;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            width: 126px;
            height: 126px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.94);
            backdrop-filter: blur(4px);
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.05);
            border: 1px solid #f1f5f9;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 10;
        }
        .donut-center-sub {
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.5px;
            color: #94a3b8;
            text-transform: uppercase;
            max-width: 112px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: block;
            transition: color 0.15s ease;
        }
        .donut-center-val {
            font-size: 22px;
            font-weight: 900;
            color: #0f172a;
            line-height: 1.1;
            margin: 2px 0;
            font-feature-settings: "tnum";
            font-variant-numeric: tabular-nums;
            transition: all 0.15s ease;
        }
        .donut-center-unit {
            font-size: 10px;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
    </style>

    <!-- Vendor Chart.js & Interactive Controller -->
    <script src="<?= base_url('assets/js/vendor/chart.min.js') ?>"></script>
    <script>
    // ── Global Funnel State ───────────────────────────────────────────────────
    var funnelState = {
        unit: '<?= $selectedUnit ?>',
        sampleBasis: '<?= $selectedSampleBasis ?>',
        preset: '<?= $presetFilter ?>',
        filterType: '<?= $filterType ?>',
        month: <?= $selectedMonth ?>,
        year: <?= $selectedYear ?>,
        startDate: '<?= $startDate ?>',
        endDate: '<?= $endDate ?>',
        customer: '<?= addslashes($selectedCustomer) ?>',
        topRank: '<?= $selectedTopRank ?>',
        selectedDefectId: null,
        selectedDefectName: '',
        selectedModelId: null,
        selectedModelName: '',
        selectedPartId: null,
        selectedPartCode: '',
        selectedPartName: '',
        timelineChartType: 'line'
    };

    var currentAnalysisMode = 'defect';

    var partState = {
        selectedPartId: null,
        selectedPartCode: '',
        selectedPartName: '',
        selectedModelName: '',
        selectedDefectId: null,
        selectedDefectName: '',
        partTrendUnit: 'pcs',
        partTrendRank: '5',
        partTrendView: 'chart',
        defectTrendUnit: 'pcs',
        defectTrendRank: '3',
        defectTrendView: 'chart',
        cachedPartStackedData: null,
        cachedDefectStackedData: null
    };

    var chartFunnelDefectsInstance = null;
    var chartFunnelModelsInstance  = null;
    var chartFunnelPartsInstance   = null;
    var chartTimelineInstance      = null;

    var chartPartRankingDonutInstance       = null;
    var chartPartStackedTrendInstance       = null;
    var chartPartDefectDonutInstance        = null;
    var chartPartDefectStackedTrendInstance = null;

    var chartL1TrendInstance = null;
    var chartL2TrendInstance = null;
    var chartL3TrendInstance = null;

    var cachedL1TrendData = null;
    var cachedL2TrendData = null;
    var cachedL3TrendData = null;

    var levelTrendState = {
        l1Type: 'bar',
        l1View: 'chart',
        l2Type: 'bar',
        l2View: 'chart',
        l3Type: 'bar',
        l3View: 'chart'
    };

    function renderLevelTrendChart(level, trendData) {
        var canvasId, tableWrapperId, chartWrapperId;
        var chartType = levelTrendState['l' + level + 'Type'];
        var viewType  = levelTrendState['l' + level + 'View'];

        if (level === 1) {
            canvasId       = 'chartL1Trend';
            tableWrapperId = 'wrapperL1TrendTable';
            chartWrapperId = 'wrapperL1TrendChart';
            cachedL1TrendData = trendData;
        } else if (level === 2) {
            canvasId       = 'chartL2Trend';
            tableWrapperId = 'wrapperL2TrendTable';
            chartWrapperId = 'wrapperL2TrendChart';
            cachedL2TrendData = trendData;
        } else if (level === 3) {
            canvasId       = 'chartL3Trend';
            tableWrapperId = 'wrapperL3TrendTable';
            chartWrapperId = 'wrapperL3TrendChart';
            cachedL3TrendData = trendData;
        }

        var canvas = document.getElementById(canvasId);
        if (!canvas || !trendData) return;

        if (level === 1 && chartL1TrendInstance) chartL1TrendInstance.destroy();
        if (level === 2 && chartL2TrendInstance) chartL2TrendInstance.destroy();
        if (level === 3 && chartL3TrendInstance) chartL3TrendInstance.destroy();

        var ctx = canvas.getContext('2d');
        var isBar = (chartType === 'bar');

        var datasets = JSON.parse(JSON.stringify(trendData.datasets || []));
        datasets.forEach(function(ds) {
            ds.type = chartType;
            if (isBar) {
                ds.borderWidth = 1;
                ds.borderRadius = 4;
                ds.stack = 'stack1';
            } else {
                ds.fill = false;
                ds.tension = 0.3;
                ds.borderWidth = 2.5;
                ds.pointRadius = 3;
                ds.pointHoverRadius = 6;
                delete ds.stack;
            }
        });

        var inst = new Chart(ctx, {
            type: chartType,
            data: {
                labels: trendData.labels || [],
                datasets: datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    x: {
                        stacked: isBar,
                        grid: { display: false },
                        ticks: { font: { size: 10, weight: '700' }, color: '#64748b' }
                    },
                    y: {
                        stacked: isBar,
                        beginAtZero: true,
                        grid: { color: '#f1f5f9' },
                        ticks: {
                            font: { size: 10, weight: '600' },
                            color: '#64748b',
                            callback: function(v) { return Number(v).toLocaleString(); }
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        align: 'end',
                        labels: {
                            boxWidth: 9,
                            usePointStyle: true,
                            font: { size: 10, weight: '700' },
                            color: '#334155'
                        }
                    },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        titleColor: '#f8fafc',
                        bodyColor: '#e2e8f0',
                        padding: 8,
                        cornerRadius: 6,
                        callbacks: {
                            label: function(c) {
                                return ' ' + c.dataset.label + ': ' + Number(c.raw || 0).toLocaleString() + ' ' + funnelState.unit.toUpperCase();
                            }
                        }
                    }
                }
            }
        });

        if (level === 1) chartL1TrendInstance = inst;
        if (level === 2) chartL2TrendInstance = inst;
        if (level === 3) chartL3TrendInstance = inst;

        renderLevelTrendTable(level, trendData);

        var chartWrap = document.getElementById(chartWrapperId);
        var tableWrap = document.getElementById(tableWrapperId);
        if (chartWrap && tableWrap) {
            if (viewType === 'table') {
                chartWrap.style.display = 'none';
                tableWrap.style.display = 'block';
            } else {
                chartWrap.style.display = 'block';
                tableWrap.style.display = 'none';
            }
        }
    }

    function renderLevelTrendTable(level, trendData) {
        var tableWrap = document.getElementById('wrapperL' + level + 'TrendTable');
        if (!tableWrap || !trendData) return;

        if (!trendData.rawMatrix || trendData.rawMatrix.length === 0) {
            tableWrap.innerHTML = '<div style="padding:16px;text-align:center;color:#94a3b8;">Tidak ada data tren.</div>';
            return;
        }

        var html = '<table style="width:100%;border-collapse:collapse;font-size:11px;">';
        html += '<thead><tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;color:#475569;font-weight:800;text-align:left;">';
        html += '<th style="padding:6px 8px;">Tanggal / Waktu</th>';
        (trendData.datasets || []).forEach(function(ds) {
            html += '<th style="padding:6px 8px;text-align:right;">' + escapeHtml(ds.label) + '</th>';
        });
        html += '<th style="padding:6px 8px;text-align:right;">Total</th></tr></thead><tbody>';

        trendData.rawMatrix.forEach(function(row) {
            html += '<tr style="border-bottom:1px solid #f1f5f9;">';
            html += '<td style="padding:6px 8px;font-weight:700;color:#0f172a;">' + escapeHtml(row.label) + '</td>';
            (row.values || []).forEach(function(v) {
                html += '<td style="padding:6px 8px;text-align:right;color:#334155;">' + Number(v).toLocaleString() + '</td>';
            });
            html += '<td style="padding:6px 8px;text-align:right;font-weight:900;color:#0f172a;">' + Number(row.total).toLocaleString() + '</td>';
            html += '</tr>';
        });

        html += '</tbody></table>';
        tableWrap.innerHTML = html;
    }

    function switchL1TrendType(type) {
        levelTrendState.l1Type = type;
        var btnBar  = document.getElementById('btnL1TypeBar');
        var btnLine = document.getElementById('btnL1TypeLine');
        if (btnBar) {
            btnBar.style.background = (type === 'bar') ? '#2563eb' : 'transparent';
            btnBar.style.color      = (type === 'bar') ? '#fff' : '#64748b';
        }
        if (btnLine) {
            btnLine.style.background = (type === 'line') ? '#2563eb' : 'transparent';
            btnLine.style.color      = (type === 'line') ? '#fff' : '#64748b';
        }
        if (cachedL1TrendData) renderLevelTrendChart(1, cachedL1TrendData);
    }

    function switchL1TrendView(view) {
        levelTrendState.l1View = view;
        var btnChart = document.getElementById('btnL1ViewChart');
        var btnTable = document.getElementById('btnL1ViewTable');
        if (btnChart) {
            btnChart.style.background = (view === 'chart') ? '#4f46e5' : 'transparent';
            btnChart.style.color      = (view === 'chart') ? '#fff' : '#64748b';
        }
        if (btnTable) {
            btnTable.style.background = (view === 'table') ? '#4f46e5' : 'transparent';
            btnTable.style.color      = (view === 'table') ? '#fff' : '#64748b';
        }
        if (cachedL1TrendData) renderLevelTrendChart(1, cachedL1TrendData);
    }

    function switchL2TrendType(type) {
        levelTrendState.l2Type = type;
        var btnBar  = document.getElementById('btnL2TypeBar');
        var btnLine = document.getElementById('btnL2TypeLine');
        if (btnBar) {
            btnBar.style.background = (type === 'bar') ? '#2563eb' : 'transparent';
            btnBar.style.color      = (type === 'bar') ? '#fff' : '#64748b';
        }
        if (btnLine) {
            btnLine.style.background = (type === 'line') ? '#2563eb' : 'transparent';
            btnLine.style.color      = (type === 'line') ? '#fff' : '#64748b';
        }
        if (cachedL2TrendData) renderLevelTrendChart(2, cachedL2TrendData);
    }

    function switchL2TrendView(view) {
        levelTrendState.l2View = view;
        var btnChart = document.getElementById('btnL2ViewChart');
        var btnTable = document.getElementById('btnL2ViewTable');
        if (btnChart) {
            btnChart.style.background = (view === 'chart') ? '#4f46e5' : 'transparent';
            btnChart.style.color      = (view === 'chart') ? '#fff' : '#64748b';
        }
        if (btnTable) {
            btnTable.style.background = (view === 'table') ? '#4f46e5' : 'transparent';
            btnTable.style.color      = (view === 'table') ? '#fff' : '#64748b';
        }
        if (cachedL2TrendData) renderLevelTrendChart(2, cachedL2TrendData);
    }

    function switchL3TrendType(type) {
        levelTrendState.l3Type = type;
        var btnBar  = document.getElementById('btnL3TypeBar');
        var btnLine = document.getElementById('btnL3TypeLine');
        if (btnBar) {
            btnBar.style.background = (type === 'bar') ? '#2563eb' : 'transparent';
            btnBar.style.color      = (type === 'bar') ? '#fff' : '#64748b';
        }
        if (btnLine) {
            btnLine.style.background = (type === 'line') ? '#2563eb' : 'transparent';
            btnLine.style.color      = (type === 'line') ? '#fff' : '#64748b';
        }
        if (cachedL3TrendData) renderLevelTrendChart(3, cachedL3TrendData);
    }

    function switchL3TrendView(view) {
        levelTrendState.l3View = view;
        var btnChart = document.getElementById('btnL3ViewChart');
        var btnTable = document.getElementById('btnL3ViewTable');
        if (btnChart) {
            btnChart.style.background = (view === 'chart') ? '#4f46e5' : 'transparent';
            btnChart.style.color      = (view === 'chart') ? '#fff' : '#64748b';
        }
        if (btnTable) {
            btnTable.style.background = (view === 'table') ? '#4f46e5' : 'transparent';
            btnTable.style.color      = (view === 'table') ? '#fff' : '#64748b';
        }
        if (cachedL3TrendData) renderLevelTrendChart(3, cachedL3TrendData);
    }

    // ── Document Ready ────────────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function() {
        updatePresetPillStyles();
        updateRankPillStyles();

        // Initial Load Level 1 Defects via AJAX
        loadLevel1Defects();

        // Setup Live Search on Defect Audit Table
        $('#tableSearchInput').on('keyup', function() {
            var q = $(this).val().toLowerCase().trim();
            $('#historyTableBody tr').each(function() {
                var rowText = $(this).text().toLowerCase();
                $(this).toggle(rowText.indexOf(q) > -1);
            });
        });

        // Setup Live Search on Part Audit Table
        $('#partTableSearchInput').on('keyup', function() {
            var q = $(this).val().toLowerCase().trim();
            $('#partHistoryTableBody tr').each(function() {
                var rowText = $(this).text().toLowerCase();
                $(this).toggle(rowText.indexOf(q) > -1);
            });
        });
    });

    // ── API URL Builder ───────────────────────────────────────────────────────
    function buildApiUrl(action, extraParams) {
        var p = new URLSearchParams();
        p.append('action', action);
        p.append('unit', funnelState.unit);
        p.append('sample_basis', funnelState.sampleBasis);
        if (funnelState.customer) {
            p.append('customer', funnelState.customer);
        }

        if (funnelState.filterType) {
            p.append('filter_type', funnelState.filterType);
        }

        if (funnelState.filterType === 'month_year') {
            p.append('month', funnelState.month);
            p.append('year', funnelState.year);
        } else if (funnelState.filterType === 'specific_year') {
            p.append('year', funnelState.year);
        } else if (funnelState.filterType === 'custom') {
            p.append('start_date', funnelState.startDate);
            p.append('end_date', funnelState.endDate);
        } else if (funnelState.filterType === 'all_years') {
            p.append('filter_type', 'all_years');
        } else {
            p.append('preset', funnelState.preset);
        }

        p.append('top_rank', funnelState.topRank);

        if (extraParams) {
            for (var k in extraParams) {
                if (extraParams[k] !== null && extraParams[k] !== undefined) {
                    p.append(k, extraParams[k]);
                }
            }
        }
        return 'api/drilldown_data.php?' + p.toString();
    }

    // ── Export Excel Handler (Enforces Defect -> Model -> Part Drilldown) ────
    var currentMissingStep = 'defect';

    function exportDefectExcel() {
        if (!funnelState.selectedDefectId) {
            currentMissingStep = 'defect';
            showExportValidationModal('defect');
            return;
        }
        if (funnelState.selectedModelId === null) {
            currentMissingStep = 'model';
            showExportValidationModal('model');
            return;
        }
        if (!funnelState.selectedPartId) {
            currentMissingStep = 'part';
            showExportValidationModal('part');
            return;
        }

        var p = new URLSearchParams();
        p.append('unit', funnelState.unit);
        p.append('sample_basis', funnelState.sampleBasis);
        p.append('top_rank', funnelState.topRank);
        if (funnelState.customer) {
            p.append('customer', funnelState.customer);
        }

        if (funnelState.filterType) {
            p.append('filter_type', funnelState.filterType);
        }

        if (funnelState.filterType === 'month_year') {
            p.append('month', funnelState.month);
            p.append('year', funnelState.year);
        } else if (funnelState.filterType === 'specific_year') {
            p.append('year', funnelState.year);
        } else if (funnelState.filterType === 'custom') {
            p.append('start_date', funnelState.startDate);
            p.append('end_date', funnelState.endDate);
        } else if (funnelState.filterType === 'all_years') {
            p.append('filter_type', 'all_years');
        } else {
            p.append('preset', funnelState.preset);
        }

        p.append('defect_id', funnelState.selectedDefectId);
        p.append('model_id', funnelState.selectedModelId);
        p.append('part_id', funnelState.selectedPartId);

        window.location.href = 'export_excel.php?' + p.toString();
    }

    function showExportValidationModal(missingStep) {
        var modal = document.getElementById('modalExportGuide');
        if (!modal) return;

        var step1 = document.getElementById('guideStep1');
        var step2 = document.getElementById('guideStep2');
        var step3 = document.getElementById('guideStep3');
        var btnAction = document.getElementById('btnGuideAction');

        [step1, step2, step3].forEach(function(el) {
            if (el) {
                el.style.border = '1px solid #e2e8f0';
                el.style.background = '#f8fafc';
            }
        });

        if (missingStep === 'defect' && step1) {
            step1.style.border = '2px solid #2563eb';
            step1.style.background = '#eff6ff';
            if (btnAction) btnAction.innerText = 'Pilih Jenis Defect (Panel 1)';
        } else if (missingStep === 'model' && step2) {
            step2.style.border = '2px solid #4f46e5';
            step2.style.background = '#eef2ff';
            if (btnAction) btnAction.innerText = 'Pilih Model Produk (Panel 2)';
        } else if (missingStep === 'part' && step3) {
            step3.style.border = '2px solid #059669';
            step3.style.background = '#ecfdf5';
            if (btnAction) btnAction.innerText = 'Pilih Part Code (Panel 3)';
        }

        modal.style.display = 'flex';
    }

    function closeExportGuideModal() {
        var modal = document.getElementById('modalExportGuide');
        if (modal) modal.style.display = 'none';
    }

    function proceedGuideStep() {
        closeExportGuideModal();
        var targetCard = null;
        if (currentMissingStep === 'defect') {
            targetCard = document.getElementById('cardFunnelLevel1');
        } else if (currentMissingStep === 'model') {
            targetCard = document.getElementById('cardFunnelLevel2');
        } else if (currentMissingStep === 'part') {
            targetCard = document.getElementById('cardFunnelLevel3');
        }

        if (targetCard) {
            targetCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
            targetCard.style.transition = 'box-shadow 0.3s ease';
            targetCard.style.boxShadow = '0 0 0 3px rgba(37,99,235,0.4)';
            setTimeout(function() {
                targetCard.style.boxShadow = '0 1px 3px rgba(0,0,0,0.03)';
            }, 1600);
        }
    }

    // ── Sub-Filter Controls Toggle ────────────────────────────────────────────
    function openSubFilter(type) {
        funnelState.preset = type;
        funnelState.filterType = type;
        updatePresetPillStyles();

        var container = document.getElementById('containerSubFilter');
        var boxMY     = document.getElementById('boxFilterMonthYear');
        var boxSY     = document.getElementById('boxFilterSpecificYear');
        var boxCust   = document.getElementById('boxFilterCustomDate');

        if (container) container.style.display = 'inline-flex';
        if (boxMY)     boxMY.style.display     = (type === 'month_year') ? 'inline-flex' : 'none';
        if (boxSY)     boxSY.style.display     = (type === 'specific_year') ? 'inline-flex' : 'none';
        if (boxCust)   boxCust.style.display   = (type === 'custom') ? 'inline-flex' : 'none';
    }

    function closeSubFilter() {
        var container = document.getElementById('containerSubFilter');
        if (container) container.style.display = 'none';
    }

    // ── Filter Controls Actions (With State Preservation!) ───────────────────
    function applyPreset(presetKey) {
        funnelState.preset = presetKey;
        closeSubFilter();

        if (presetKey === 'all_years') {
            funnelState.filterType = 'all_years';
        } else {
            funnelState.filterType = '';
        }

        updatePresetPillStyles();
        refreshActiveSelections();
    }

    function applyMonthYearFilter() {
        var m = document.getElementById('subSelectMonth').value;
        var y = document.getElementById('subSelectMonthYear').value;

        funnelState.preset     = 'month_year';
        funnelState.filterType = 'month_year';
        funnelState.month      = parseInt(m, 10);
        funnelState.year       = parseInt(y, 10);

        updatePresetPillStyles();
        refreshActiveSelections();
    }

    function applySpecificYearFilter() {
        var y = document.getElementById('subSelectYearOnly').value;

        funnelState.preset     = 'specific_year';
        funnelState.filterType = 'specific_year';
        funnelState.year       = parseInt(y, 10);

        updatePresetPillStyles();
        refreshActiveSelections();
    }

    function applyCustomDateRange() {
        var s = document.getElementById('filterStartDate').value;
        var e = document.getElementById('filterEndDate').value;
        if (!s || !e) {
            alert('Silakan pilih tanggal mulai dan tanggal selesai.');
            return;
        }
        if (s > e) {
            alert('Tanggal mulai tidak boleh lebih besar dari tanggal selesai.');
            return;
        }

        funnelState.preset     = 'custom';
        funnelState.filterType = 'custom';
        funnelState.startDate  = s;
        funnelState.endDate    = e;

        updatePresetPillStyles();
        refreshActiveSelections();
    }

    function switchSampleBasis(basis) {
        if (funnelState.sampleBasis === basis) return;
        funnelState.sampleBasis = basis;

        var btnLot  = document.getElementById('btnBasisLot');
        var btnSess = document.getElementById('btnBasisSess');
        if (btnLot && btnSess) {
            if (basis === 'lot') {
                btnLot.style.background  = '#0284c7';
                btnLot.style.color       = '#ffffff';
                btnLot.style.boxShadow   = '0 1px 3px rgba(2,132,199,0.25)';
                btnSess.style.background = 'transparent';
                btnSess.style.color      = '#64748b';
                btnSess.style.boxShadow  = 'none';
            } else {
                btnSess.style.background = '#0284c7';
                btnSess.style.color      = '#ffffff';
                btnSess.style.boxShadow  = '0 1px 3px rgba(2,132,199,0.25)';
                btnLot.style.background  = 'transparent';
                btnLot.style.color       = '#64748b';
                btnLot.style.boxShadow   = 'none';
            }
        }

        refreshActiveSelections();
    }

    function switchUnit(unit) {
        if (funnelState.unit === unit) return;
        funnelState.unit = unit;

        var btnPcs = document.getElementById('btnUnitPcs');
        var btnLot = document.getElementById('btnUnitLot');
        var containerBasis = document.getElementById('containerSampleBasis');
        if (unit === 'pcs') {
            btnPcs.style.background = '#0f172a';
            btnPcs.style.color = '#ffffff';
            btnPcs.style.boxShadow = '0 1px 3px rgba(15,23,42,0.25)';
            btnLot.style.background = 'transparent';
            btnLot.style.color = '#64748b';
            btnLot.style.boxShadow = 'none';
            if (containerBasis) containerBasis.style.display = 'flex';
        } else {
            btnLot.style.background = '#d97706';
            btnLot.style.color = '#ffffff';
            btnLot.style.boxShadow = '0 1px 3px rgba(217,119,6,0.3)';
            btnPcs.style.background = 'transparent';
            btnPcs.style.color = '#64748b';
            btnPcs.style.boxShadow = 'none';
            if (containerBasis) containerBasis.style.display = 'none';
        }

        refreshActiveSelections();
    }

    function onCustomerChange(cust) {
        funnelState.customer = cust;
        refreshActiveSelections();
    }

    function resetAllFilters() {
        funnelState.preset      = 'bulanan';
        funnelState.filterType  = '';
        funnelState.unit        = 'pcs';
        funnelState.sampleBasis = 'lot';
        funnelState.customer    = '';
        funnelState.topRank     = '5';
        document.getElementById('selectCustomer').value = '';
        closeSubFilter();

        var btnPcs = document.getElementById('btnUnitPcs');
        var btnLot = document.getElementById('btnUnitLot');
        if (btnPcs) {
            btnPcs.style.background = '#0f172a';
            btnPcs.style.color = '#ffffff';
        }
        if (btnLot) {
            btnLot.style.background = 'transparent';
            btnLot.style.color = '#64748b';
        }
        var btnBasisLot  = document.getElementById('btnBasisLot');
        var btnBasisSess = document.getElementById('btnBasisSess');
        if (btnBasisLot && btnBasisSess) {
            btnBasisLot.style.background  = '#0284c7';
            btnBasisLot.style.color       = '#ffffff';
            btnBasisLot.style.boxShadow   = '0 1px 3px rgba(2,132,199,0.25)';
            btnBasisSess.style.background = 'transparent';
            btnBasisSess.style.color      = '#64748b';
            btnBasisSess.style.boxShadow  = 'none';
        }
        var containerBasis = document.getElementById('containerSampleBasis');
        if (containerBasis) {
            containerBasis.style.display = 'flex';
        }
        updateRankPillStyles();

        // Clear drilldown selections on explicit full reset
        clearFullSelections();
        applyPreset('bulanan');
    }

    function switchTopRank(rk) {
        if (funnelState.topRank === rk) return;
        funnelState.topRank = rk;
        updateRankPillStyles();
        refreshActiveSelections();
    }

    function updateRankPillStyles() {
        document.querySelectorAll('.rank-pill-btn').forEach(function(btn) {
            var r = btn.getAttribute('data-rank');
            if (r === funnelState.topRank) {
                btn.style.background = '#4f46e5';
                btn.style.color = '#ffffff';
                btn.style.boxShadow = '0 1px 3px rgba(79,70,229,0.25)';
                btn.style.fontWeight = '800';
            } else {
                btn.style.background = 'transparent';
                btn.style.color = '#64748b';
                btn.style.boxShadow = 'none';
                btn.style.fontWeight = '700';
            }
        });
    }

    function updatePresetPillStyles() {
        document.querySelectorAll('.preset-pill-btn').forEach(function(btn) {
            var p = btn.getAttribute('data-preset');
            if (p === funnelState.preset) {
                btn.style.background = '#2563eb';
                btn.style.color = '#ffffff';
                btn.style.boxShadow = '0 1px 3px rgba(37,99,235,0.25)';
            } else {
                btn.style.background = 'transparent';
                btn.style.color = '#64748b';
                btn.style.boxShadow = 'none';
            }
        });
    }

    // ── Donut Centerpiece Dynamic Controller ───────────────────────────────────
    function updateCenterStat(level, title, val, unit, titleColor) {
        var subEl  = document.getElementById('centerSub' + level);
        var valEl  = document.getElementById('centerVal' + level);
        var unitEl = document.getElementById('centerUnit' + level);
        if (!valEl) return;

        if (title !== undefined && subEl) {
            subEl.textContent = title;
            subEl.style.color = titleColor || '#94a3b8';
        }
        if (val !== undefined && valEl) {
            valEl.textContent = typeof val === 'number' ? val.toLocaleString() : val;
        }
        if (unit !== undefined && unitEl) {
            unitEl.textContent = unit;
        }
    }

    function onHoverItemCard(level, idx, val, name, color, pct) {
        updateCenterStat(level, name, val, pct + '%', color);
        var chartInstance = null;
        if (level === 'Defects') chartInstance = chartFunnelDefectsInstance;
        else if (level === 'Models') chartInstance = chartFunnelModelsInstance;
        else if (level === 'Parts') chartInstance = chartFunnelPartsInstance;
        else if (level === 'PartRank') chartInstance = chartPartRankingDonutInstance;
        else if (level === 'PartDefect') chartInstance = chartPartDefectDonutInstance;

        if (chartInstance && chartInstance.setActiveElements) {
            chartInstance.setActiveElements([{ datasetIndex: 0, index: idx }]);
            chartInstance.update();
        }
    }

    function onLeaveItemCard(level, grandMetric, unit) {
        var defTitle = 'TOTAL CACAT';
        if (level === 'Defects') defTitle = 'TOTAL DEFECT';
        else if (level === 'Models') defTitle = 'TOTAL MODEL';
        else if (level === 'Parts') defTitle = 'TOTAL PART';
        else if (level === 'PartRank') defTitle = 'TOTAL CACAT';
        else if (level === 'PartDefect') defTitle = 'TOTAL DEFECT';

        updateCenterStat(level, defTitle, grandMetric, unit, '#94a3b8');
        var chartInstance = null;
        if (level === 'Defects') chartInstance = chartFunnelDefectsInstance;
        else if (level === 'Models') chartInstance = chartFunnelModelsInstance;
        else if (level === 'Parts') chartInstance = chartFunnelPartsInstance;
        else if (level === 'PartRank') chartInstance = chartPartRankingDonutInstance;
        else if (level === 'PartDefect') chartInstance = chartPartDefectDonutInstance;

        if (chartInstance && chartInstance.setActiveElements) {
            chartInstance.setActiveElements([]);
            chartInstance.update();
        }
    }

    // ── STATE PRESERVATION ENGINE (No Selection Reset on Filter Change) ───────
    function refreshActiveSelections() {
        if (currentAnalysisMode === 'part') {
            refreshPartMode();
            return;
        }

        // 1. Refresh Level 1
        loadLevel1Defects(function() {
            // If defect was previously selected, re-highlight
            if (funnelState.selectedDefectId) {
                highlightDefectCard(funnelState.selectedDefectId);
                // 2. Refresh Level 2
                loadLevel2Models(funnelState.selectedDefectId, funnelState.selectedDefectName, function() {
                    // If model was previously selected, re-highlight
                    if (funnelState.selectedModelId !== null) {
                        highlightModelCard(funnelState.selectedModelId);
                        // 3. Refresh Level 3
                        loadLevel3Parts(funnelState.selectedDefectId, funnelState.selectedModelId, funnelState.selectedModelName, function() {
                            // If part was previously selected, re-highlight & refresh Deep-Dive
                            if (funnelState.selectedPartId) {
                                highlightPartCard(funnelState.selectedPartId);
                                loadTrendTimeline();
                                loadTableHistory();
                            }
                        });
                    }
                });
            }
        });
    }

    function clearFullSelections() {
        funnelState.selectedDefectId   = null;
        funnelState.selectedDefectName = '';
        funnelState.selectedModelId    = null;
        funnelState.selectedModelName  = '';
        funnelState.selectedPartId     = null;
        funnelState.selectedPartCode   = '';
        funnelState.selectedPartName   = '';

        var b1 = document.getElementById('badgeDefectSelected');
        if (b1) b1.style.display = 'none';

        var c2 = document.getElementById('contentFunnelModels');
        var p2 = document.getElementById('placeholderFunnelModels');
        var b2 = document.getElementById('badgeModelSelected');
        var clr2 = document.getElementById('btnClearModel');
        if (c2) c2.style.display = 'none';
        if (p2) p2.style.display = 'block';
        if (b2) b2.style.display = 'none';
        if (clr2) clr2.style.display = 'none';

        var c3 = document.getElementById('contentFunnelParts');
        var p3 = document.getElementById('placeholderFunnelParts');
        var b3 = document.getElementById('badgePartSelected');
        var clr3 = document.getElementById('btnClearPart');
        if (c3) c3.style.display = 'none';
        if (p3) p3.style.display = 'block';
        if (b3) b3.style.display = 'none';
        if (clr3) clr3.style.display = 'none';

        var ddPlaceholder = document.getElementById('deepDivePlaceholder');
        var ddContent     = document.getElementById('deepDiveContent');
        if (ddPlaceholder) ddPlaceholder.style.display = 'flex';
        if (ddContent)     ddContent.style.display     = 'none';
    }

    // ── Panel 1: Load Defects ─────────────────────────────────────────────────
    function loadLevel1Defects(callback) {
        var url = buildApiUrl('get_defects');
        var listContainer = document.getElementById('listFunnelDefects');
        if (listContainer && (!funnelState.selectedDefectId)) {
            listContainer.innerHTML = '<div style="padding:20px;text-align:center;color:#94a3b8;font-size:12px;">Memuat diagram jenis defect...</div>';
        }

        fetch(url)
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!data.success) return;
                renderLevel1Chart(data);
                renderLevel1List(data);
                if (data.trend) renderLevelTrendChart(1, data.trend);
                if (typeof callback === 'function') callback();
            })
            .catch(function(err) {
                console.error("Error loading Level 1 defects:", err);
            });
    }

    function renderLevel1Chart(data) {
        var canvas = document.getElementById('chartFunnelDefects');
        if (!canvas) return;
        if (chartFunnelDefectsInstance) chartFunnelDefectsInstance.destroy();

        // Update default Centerpiece badge
        updateCenterStat('Defects', 'TOTAL DEFECT', data.grandMetric, data.unit, '#94a3b8');

        var ctx = canvas.getContext('2d');
        chartFunnelDefectsInstance = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: data.chartLabels,
                datasets: [{
                    data: data.chartData,
                    backgroundColor: data.chartColors,
                    borderWidth: 2,
                    borderColor: '#ffffff',
                    hoverOffset: 8,
                    spacing: 3,
                    borderRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        titleColor: '#f8fafc',
                        bodyColor: '#e2e8f0',
                        padding: 10,
                        cornerRadius: 8,
                        callbacks: {
                            label: function(ctx) {
                                var val = Number(ctx.raw || 0).toLocaleString();
                                var pct = data.grandMetric > 0 ? Math.round((ctx.raw / data.grandMetric) * 1000) / 10 : 0;
                                return ' ' + ctx.label + ': ' + val + ' ' + data.unit + ' (' + pct + '%)';
                            }
                        }
                    }
                },
                onHover: function(evt, elements) {
                    if (elements && elements.length > 0) {
                        var idx = elements[0].index;
                        var item = data.items[idx];
                        if (item) {
                            var pct = data.grandMetric > 0 ? Math.round((item.val / data.grandMetric) * 1000) / 10 : 0;
                            updateCenterStat('Defects', item.item_name, item.val, pct + '%', item.color);
                        }
                    } else {
                        updateCenterStat('Defects', 'TOTAL DEFECT', data.grandMetric, data.unit, '#94a3b8');
                    }
                },
                onClick: function(evt, elements) {
                    if (elements && elements.length > 0) {
                        var idx = elements[0].index;
                        var item = data.items[idx];
                        if (item && !item.is_other && item.item_id) {
                            selectDefect(item.item_id, item.item_name);
                        }
                    }
                }
            }
        });

        canvas.onmouseleave = function() {
            updateCenterStat('Defects', 'TOTAL DEFECT', data.grandMetric, data.unit, '#94a3b8');
        };
    }

    function renderLevel1List(data) {
        var container = document.getElementById('listFunnelDefects');
        if (!container) return;

        if (!data.items || data.items.length === 0) {
            container.innerHTML = '<div style="padding:20px;text-align:center;color:#94a3b8;font-size:12px;">Tidak ada temuan cacat pada filter ini.</div>';
            return;
        }

        var html = '';
        data.items.forEach(function(item, idx) {
            var isOther = !!item.is_other;
            var clickAttr = isOther ? '' : 'onclick="selectDefect(' + item.item_id + ', \'' + escapeQuotes(item.item_name) + '\')"';
            var cursorStyle = isOther ? 'cursor:default;opacity:0.85;' : 'cursor:pointer;';
            var activeCls = (funnelState.selectedDefectId && Number(funnelState.selectedDefectId) === Number(item.item_id)) ? 'funnel-card-active-1' : '';
            var hoverAttr = 'onmouseenter="onHoverItemCard(\'Defects\', ' + idx + ', ' + item.val + ', \'' + escapeQuotes(item.item_name) + '\', \'' + item.color + '\', ' + item.percentage + ')" onmouseleave="onLeaveItemCard(\'Defects\', ' + data.grandMetric + ', \'' + data.unit + '\')"';

            html += '<div class="funnel-item-card ' + activeCls + '" id="card-defect-' + item.item_id + '" ' + clickAttr + ' ' + hoverAttr + ' style="' + cursorStyle + '">';
            html += '  <div style="display:flex;align-items:center;gap:10px;min-width:0;flex:1;">';
            html += '    <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' + item.color + ';flex-shrink:0;"></span>';
            html += '    <div style="min-width:0;flex:1;">';
            html += '      <div style="display:flex;align-items:center;justify-content:space-between;gap:6px;">';
            html += '        <span style="font-weight:800;font-size:12px;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escapeHtml(item.item_name) + '</span>';
            html += '        <span style="font-size:10px;font-weight:700;color:#64748b;">' + item.percentage + '%</span>';
            html += '      </div>';
            html += '      <div style="width:100%;height:4px;background:#f1f5f9;border-radius:2px;overflow:hidden;margin-top:4px;">';
            html += '        <div style="width:' + item.percentage + '%;height:100%;background:' + item.color + ';border-radius:2px;"></div>';
            html += '      </div>';
            html += '    </div>';
            html += '  </div>';
            html += '  <div style="text-align:right;flex-shrink:0;padding-left:10px;">';
            html += '    <div style="font-size:12px;font-weight:900;color:#0f172a;">' + Number(item.val).toLocaleString() + ' <span style="font-size:10px;color:#64748b;font-weight:700;">' + data.unit + '</span></div>';
            html += '  </div>';
            html += '</div>';
        });

        container.innerHTML = html;
        if (funnelState.selectedDefectId) {
            highlightDefectCard(funnelState.selectedDefectId);
        }
    }

    function selectDefect(defectId, defectName) {
        funnelState.selectedDefectId   = defectId;
        funnelState.selectedDefectName = defectName;
        funnelState.selectedModelId    = null;
        funnelState.selectedModelName  = '';
        funnelState.selectedPartId     = null;
        funnelState.selectedPartCode   = '';
        funnelState.selectedPartName   = '';

        highlightDefectCard(defectId);

        // Reset Deep-Dive when a different defect is chosen
        var ddPlaceholder = document.getElementById('deepDivePlaceholder');
        var ddContent     = document.getElementById('deepDiveContent');
        if (ddPlaceholder) ddPlaceholder.style.display = 'flex';
        if (ddContent)     ddContent.style.display     = 'none';

        loadLevel2Models(defectId, defectName);
    }

    function highlightDefectCard(defectId) {
        document.querySelectorAll('#listFunnelDefects .funnel-item-card').forEach(function(el) {
            el.classList.remove('funnel-card-active-1');
        });
        var card = document.getElementById('card-defect-' + defectId);
        if (card) card.classList.add('funnel-card-active-1');

        var badge = document.getElementById('badgeDefectSelected');
        var lbl   = document.getElementById('lblSelectedDefect');
        if (badge && lbl) {
            lbl.textContent = funnelState.selectedDefectName;
            badge.style.display = 'inline-flex';
        }
    }

    // ── Panel 2: Load Models ──────────────────────────────────────────────────
    function loadLevel2Models(defectId, defectName, callback) {
        var content     = document.getElementById('contentFunnelModels');
        var placeholder = document.getElementById('placeholderFunnelModels');
        var subTitle    = document.getElementById('subTitleLevel2');
        var list        = document.getElementById('listFunnelModels');

        if (content) content.style.display = 'grid';
        if (placeholder) placeholder.style.display = 'none';
        if (subTitle) subTitle.innerHTML = 'Sebaran model produk untuk cacat: <strong style="color:#4f46e5;">' + escapeHtml(defectName) + '</strong>';
        if (list && (!funnelState.selectedModelId)) {
            list.innerHTML = '<div style="padding:20px;text-align:center;color:#94a3b8;font-size:12px;">Memuat data model terdampak...</div>';
        }

        // Reset Level 3 only if model is not set
        if (funnelState.selectedModelId === null) {
            var c3 = document.getElementById('contentFunnelParts');
            var p3 = document.getElementById('placeholderFunnelParts');
            var b3 = document.getElementById('badgePartSelected');
            var clr3 = document.getElementById('btnClearPart');
            if (c3) c3.style.display = 'none';
            if (p3) p3.style.display = 'block';
            if (b3) b3.style.display = 'none';
            if (clr3) clr3.style.display = 'none';
        }

        var url = buildApiUrl('get_models', { defect_id: defectId });
        fetch(url)
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!data.success) return;
                renderLevel2Chart(data);
                renderLevel2List(data);
                if (data.trend) renderLevelTrendChart(2, data.trend);
                if (typeof callback === 'function') callback();
            })
            .catch(function(err) {
                console.error("Error loading Level 2 models:", err);
            });
    }

    function renderLevel2Chart(data) {
        var canvas = document.getElementById('chartFunnelModels');
        if (!canvas) return;
        if (chartFunnelModelsInstance) chartFunnelModelsInstance.destroy();

        // Update default Centerpiece badge
        updateCenterStat('Models', 'TOTAL MODEL', data.grandMetric, data.unit, '#94a3b8');

        var ctx = canvas.getContext('2d');
        chartFunnelModelsInstance = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: data.chartLabels,
                datasets: [{
                    data: data.chartData,
                    backgroundColor: data.chartColors,
                    borderWidth: 2,
                    borderColor: '#ffffff',
                    hoverOffset: 8,
                    spacing: 3,
                    borderRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        titleColor: '#f8fafc',
                        bodyColor: '#e2e8f0',
                        padding: 10,
                        cornerRadius: 8,
                        callbacks: {
                            label: function(ctx) {
                                var val = Number(ctx.raw || 0).toLocaleString();
                                var pct = data.grandMetric > 0 ? Math.round((ctx.raw / data.grandMetric) * 1000) / 10 : 0;
                                return ' ' + ctx.label + ': ' + val + ' ' + data.unit + ' (' + pct + '%)';
                            }
                        }
                    }
                },
                onHover: function(evt, elements) {
                    if (elements && elements.length > 0) {
                        var idx = elements[0].index;
                        var item = data.items[idx];
                        if (item) {
                            var pct = data.grandMetric > 0 ? Math.round((item.val / data.grandMetric) * 1000) / 10 : 0;
                            updateCenterStat('Models', item.item_name, item.val, pct + '%', item.color);
                        }
                    } else {
                        updateCenterStat('Models', 'TOTAL MODEL', data.grandMetric, data.unit, '#94a3b8');
                    }
                },
                onClick: function(evt, elements) {
                    if (elements && elements.length > 0) {
                        var idx = elements[0].index;
                        var item = data.items[idx];
                        if (item && !item.is_other) {
                            selectModel(item.item_id, item.item_name);
                        }
                    }
                }
            }
        });

        canvas.onmouseleave = function() {
            updateCenterStat('Models', 'TOTAL MODEL', data.grandMetric, data.unit, '#94a3b8');
        };
    }

    function renderLevel2List(data) {
        var container = document.getElementById('listFunnelModels');
        if (!container) return;

        if (!data.items || data.items.length === 0) {
            container.innerHTML = '<div style="padding:20px;text-align:center;color:#94a3b8;font-size:12px;">Tidak ada model produk terdampak untuk cacat ini.</div>';
            return;
        }

        var html = '';
        data.items.forEach(function(item, idx) {
            var isOther = !!item.is_other;
            var clickAttr = isOther ? '' : 'onclick="selectModel(' + (item.item_id !== null ? item.item_id : 0) + ', \'' + escapeQuotes(item.item_name) + '\')"';
            var cursorStyle = isOther ? 'cursor:default;opacity:0.85;' : 'cursor:pointer;';
            var activeCls = (funnelState.selectedModelId !== null && Number(funnelState.selectedModelId) === Number(item.item_id)) ? 'funnel-card-active-2' : '';
            var hoverAttr = 'onmouseenter="onHoverItemCard(\'Models\', ' + idx + ', ' + item.val + ', \'' + escapeQuotes(item.item_name) + '\', \'' + item.color + '\', ' + item.percentage + ')" onmouseleave="onLeaveItemCard(\'Models\', ' + data.grandMetric + ', \'' + data.unit + '\')"';

            html += '<div class="funnel-item-card ' + activeCls + '" id="card-model-' + item.item_id + '" ' + clickAttr + ' ' + hoverAttr + ' style="' + cursorStyle + '">';
            html += '  <div style="display:flex;align-items:center;gap:10px;min-width:0;flex:1;">';
            html += '    <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' + item.color + ';flex-shrink:0;"></span>';
            html += '    <div style="min-width:0;flex:1;">';
            html += '      <div style="display:flex;align-items:center;justify-content:space-between;gap:6px;">';
            html += '        <span style="font-weight:800;font-size:12px;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escapeHtml(item.item_name) + '</span>';
            html += '        <span style="font-size:10px;font-weight:700;color:#64748b;">' + item.percentage + '%</span>';
            html += '      </div>';
            html += '      <div style="width:100%;height:4px;background:#f1f5f9;border-radius:2px;overflow:hidden;margin-top:4px;">';
            html += '        <div style="width:' + item.percentage + '%;height:100%;background:' + item.color + ';border-radius:2px;"></div>';
            html += '      </div>';
            html += '    </div>';
            html += '  </div>';
            html += '  <div style="text-align:right;flex-shrink:0;padding-left:10px;">';
            html += '    <div style="font-size:12px;font-weight:900;color:#0f172a;">' + Number(item.val).toLocaleString() + ' <span style="font-size:10px;color:#64748b;font-weight:700;">' + data.unit + '</span></div>';
            html += '  </div>';
            html += '</div>';
        });

        container.innerHTML = html;
        if (funnelState.selectedModelId !== null) {
            highlightModelCard(funnelState.selectedModelId);
        }
    }

    function selectModel(modelId, modelName) {
        funnelState.selectedModelId   = modelId;
        funnelState.selectedModelName = modelName;
        funnelState.selectedPartId    = null;
        funnelState.selectedPartCode  = '';
        funnelState.selectedPartName  = '';

        highlightModelCard(modelId);

        // Reset Deep-Dive when a different model is chosen
        var ddPlaceholder = document.getElementById('deepDivePlaceholder');
        var ddContent     = document.getElementById('deepDiveContent');
        if (ddPlaceholder) ddPlaceholder.style.display = 'flex';
        if (ddContent)     ddContent.style.display     = 'none';

        loadLevel3Parts(funnelState.selectedDefectId, modelId, modelName);
    }

    function highlightModelCard(modelId) {
        document.querySelectorAll('#listFunnelModels .funnel-item-card').forEach(function(el) {
            el.classList.remove('funnel-card-active-2');
        });
        var card = document.getElementById('card-model-' + modelId);
        if (card) card.classList.add('funnel-card-active-2');

        var badge = document.getElementById('badgeModelSelected');
        var lbl   = document.getElementById('lblSelectedModel');
        var clr   = document.getElementById('btnClearModel');
        if (badge && lbl) {
            lbl.textContent = funnelState.selectedModelName;
            badge.style.display = 'inline-flex';
        }
        if (clr) clr.style.display = 'inline-block';
    }

    function clearModelSelection() {
        funnelState.selectedModelId   = null;
        funnelState.selectedModelName = '';
        funnelState.selectedPartId    = null;
        funnelState.selectedPartCode  = '';
        funnelState.selectedPartName  = '';

        document.querySelectorAll('#listFunnelModels .funnel-item-card').forEach(function(el) {
            el.classList.remove('funnel-card-active-2');
        });

        var b2 = document.getElementById('badgeModelSelected');
        var clr2 = document.getElementById('btnClearModel');
        if (b2) b2.style.display = 'none';
        if (clr2) clr2.style.display = 'none';

        var c3 = document.getElementById('contentFunnelParts');
        var p3 = document.getElementById('placeholderFunnelParts');
        var b3 = document.getElementById('badgePartSelected');
        var clr3 = document.getElementById('btnClearPart');
        if (c3) c3.style.display = 'none';
        if (p3) p3.style.display = 'block';
        if (b3) b3.style.display = 'none';
        if (clr3) clr3.style.display = 'none';

        var ddPlaceholder = document.getElementById('deepDivePlaceholder');
        var ddContent     = document.getElementById('deepDiveContent');
        if (ddPlaceholder) ddPlaceholder.style.display = 'flex';
        if (ddContent)     ddContent.style.display     = 'none';
    }

    // ── Panel 3: Load Parts ───────────────────────────────────────────────────
    function loadLevel3Parts(defectId, modelId, modelName, callback) {
        var content     = document.getElementById('contentFunnelParts');
        var placeholder = document.getElementById('placeholderFunnelParts');
        var subTitle    = document.getElementById('subTitleLevel3');
        var list        = document.getElementById('listFunnelParts');

        if (content) content.style.display = 'grid';
        if (placeholder) placeholder.style.display = 'none';
        if (subTitle) subTitle.innerHTML = 'Sebaran part code untuk model: <strong style="color:#059669;">' + escapeHtml(modelName) + '</strong>';
        if (list && (!funnelState.selectedPartId)) {
            list.innerHTML = '<div style="padding:20px;text-align:center;color:#94a3b8;font-size:12px;">Memuat data part code terdampak...</div>';
        }

        var url = buildApiUrl('get_parts', { defect_id: defectId, model_id: modelId });
        fetch(url)
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!data.success) return;
                renderLevel3Chart(data);
                renderLevel3List(data);
                if (data.trend) renderLevelTrendChart(3, data.trend);
                if (typeof callback === 'function') callback();
            })
            .catch(function(err) {
                console.error("Error loading Level 3 parts:", err);
            });
    }

    function renderLevel3Chart(data) {
        var canvas = document.getElementById('chartFunnelParts');
        if (!canvas) return;
        if (chartFunnelPartsInstance) chartFunnelPartsInstance.destroy();

        // Update default Centerpiece badge
        updateCenterStat('Parts', 'TOTAL PART', data.grandMetric, data.unit, '#94a3b8');

        var ctx = canvas.getContext('2d');
        chartFunnelPartsInstance = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: data.chartLabels,
                datasets: [{
                    data: data.chartData,
                    backgroundColor: data.chartColors,
                    borderWidth: 2,
                    borderColor: '#ffffff',
                    hoverOffset: 8,
                    spacing: 3,
                    borderRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        titleColor: '#f8fafc',
                        bodyColor: '#e2e8f0',
                        padding: 10,
                        cornerRadius: 8,
                        callbacks: {
                            label: function(ctx) {
                                var val = Number(ctx.raw || 0).toLocaleString();
                                var pct = data.grandMetric > 0 ? Math.round((ctx.raw / data.grandMetric) * 1000) / 10 : 0;
                                return ' ' + ctx.label + ': ' + val + ' ' + data.unit + ' (' + pct + '%)';
                            }
                        }
                    }
                },
                onHover: function(evt, elements) {
                    if (elements && elements.length > 0) {
                        var idx = elements[0].index;
                        var item = data.items[idx];
                        if (item) {
                            var pct = data.grandMetric > 0 ? Math.round((item.val / data.grandMetric) * 1000) / 10 : 0;
                            var itemName = item.part_code || item.item_name;
                            updateCenterStat('Parts', itemName, item.val, pct + '%', item.color);
                        }
                    } else {
                        updateCenterStat('Parts', 'TOTAL PART', data.grandMetric, data.unit, '#94a3b8');
                    }
                },
                onClick: function(evt, elements) {
                    if (elements && elements.length > 0) {
                        var idx = elements[0].index;
                        var item = data.items[idx];
                        if (item && !item.is_other && item.item_id) {
                            selectPart(item.item_id, item.part_code, item.part_name);
                        }
                    }
                }
            }
        });

        canvas.onmouseleave = function() {
            updateCenterStat('Parts', 'TOTAL PART', data.grandMetric, data.unit, '#94a3b8');
        };
    }

    function renderLevel3List(data) {
        var container = document.getElementById('listFunnelParts');
        if (!container) return;

        if (!data.items || data.items.length === 0) {
            container.innerHTML = '<div style="padding:20px;text-align:center;color:#94a3b8;font-size:12px;">Tidak ada part code terdampak untuk model ini.</div>';
            return;
        }

        var html = '';
        data.items.forEach(function(item, idx) {
            var isOther = !!item.is_other;
            var clickAttr = isOther ? '' : 'onclick="selectPart(' + item.item_id + ', \'' + escapeQuotes(item.part_code || '') + '\', \'' + escapeQuotes(item.part_name || item.item_name) + '\')"';
            var cursorStyle = isOther ? 'cursor:default;opacity:0.85;' : 'cursor:pointer;';
            var activeCls = (funnelState.selectedPartId && Number(funnelState.selectedPartId) === Number(item.item_id)) ? 'funnel-card-active-3' : '';
            var itemName = item.part_code || item.item_name;
            var hoverAttr = 'onmouseenter="onHoverItemCard(\'Parts\', ' + idx + ', ' + item.val + ', \'' + escapeQuotes(itemName) + '\', \'' + item.color + '\', ' + item.percentage + ')" onmouseleave="onLeaveItemCard(\'Parts\', ' + data.grandMetric + ', \'' + data.unit + '\')"';

            html += '<div class="funnel-item-card ' + activeCls + '" id="card-part-' + item.item_id + '" ' + clickAttr + ' ' + hoverAttr + ' style="' + cursorStyle + '">';
            html += '  <div style="display:flex;align-items:center;gap:10px;min-width:0;flex:1;">';
            html += '    <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' + item.color + ';flex-shrink:0;"></span>';
            html += '    <div style="min-width:0;flex:1;">';
            html += '      <div style="display:flex;align-items:center;justify-content:space-between;gap:6px;">';
            html += '        <div style="min-width:0;">';
            html += '          <span style="font-weight:900;font-size:12px;color:#0f172a;display:block;font-family:monospace;">' + escapeHtml(item.part_code || item.item_name) + '</span>';
            if (item.part_name) {
                html += '          <span style="font-size:10px;color:#64748b;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escapeHtml(item.part_name) + '</span>';
            }
            html += '        </div>';
            html += '        <span style="font-size:10px;font-weight:700;color:#64748b;">' + item.percentage + '%</span>';
            html += '      </div>';
            html += '      <div style="width:100%;height:4px;background:#f1f5f9;border-radius:2px;overflow:hidden;margin-top:4px;">';
            html += '        <div style="width:' + item.percentage + '%;height:100%;background:' + item.color + ';border-radius:2px;"></div>';
            html += '      </div>';
            html += '    </div>';
            html += '  </div>';
            html += '  <div style="text-align:right;flex-shrink:0;padding-left:10px;">';
            html += '    <div style="font-size:12px;font-weight:900;color:#0f172a;">' + Number(item.val).toLocaleString() + ' <span style="font-size:10px;color:#64748b;font-weight:700;">' + data.unit + '</span></div>';
            html += '  </div>';
            html += '</div>';
        });

        container.innerHTML = html;
        if (funnelState.selectedPartId) {
            highlightPartCard(funnelState.selectedPartId);
        }
    }

    function selectPart(partId, partCode, partName) {
        funnelState.selectedPartId   = partId;
        funnelState.selectedPartCode = partCode;
        funnelState.selectedPartName = partName;

        highlightPartCard(partId);

        // 1. Reveal Deep-Dive Section ("Muncul Belakangan")
        var ddPlaceholder = document.getElementById('deepDivePlaceholder');
        var ddContent     = document.getElementById('deepDiveContent');
        if (ddPlaceholder) ddPlaceholder.style.display = 'none';
        if (ddContent)     ddContent.style.display     = 'flex';

        // 2. Set Header Text
        var elCode   = document.getElementById('ddPartCode');
        var elName   = document.getElementById('ddPartName');
        var elDefect = document.getElementById('ddDefectName');
        var elModel  = document.getElementById('ddModelName');
        var elTarget = document.getElementById('lblTimelineChartTarget');

        if (elCode)   elCode.textContent   = partCode || '-';
        if (elName)   elName.textContent   = partName || '-';
        if (elDefect) elDefect.textContent = funnelState.selectedDefectName || '-';
        if (elModel)  elModel.textContent  = funnelState.selectedModelName  || '-';
        if (elTarget) elTarget.textContent = (partCode ? partCode + ' (' + partName + ')' : partName);

        // 3. Load Timeline Trend & KPIs via AJAX
        loadTrendTimeline();

        // 4. Load Audit Trail Table via AJAX
        loadTableHistory();

        // 5. Smooth Scroll to Deep-Dive Section
        setTimeout(function() {
            var targetEl = document.getElementById('sectionDeepDiveAnalysis');
            if (targetEl) {
                targetEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }, 100);
    }

    function highlightPartCard(partId) {
        document.querySelectorAll('#listFunnelParts .funnel-item-card').forEach(function(el) {
            el.classList.remove('funnel-card-active-3');
        });
        var card = document.getElementById('card-part-' + partId);
        if (card) card.classList.add('funnel-card-active-3');

        var badge = document.getElementById('badgePartSelected');
        var lbl   = document.getElementById('lblSelectedPart');
        var clr   = document.getElementById('btnClearPart');
        if (badge && lbl) {
            lbl.textContent = funnelState.selectedPartCode || funnelState.selectedPartName;
            badge.style.display = 'inline-flex';
        }
        if (clr) clr.style.display = 'inline-block';
    }

    function clearPartSelection() {
        funnelState.selectedPartId   = null;
        funnelState.selectedPartCode = '';
        funnelState.selectedPartName = '';

        document.querySelectorAll('#listFunnelParts .funnel-item-card').forEach(function(el) {
            el.classList.remove('funnel-card-active-3');
        });

        var b3 = document.getElementById('badgePartSelected');
        var clr3 = document.getElementById('btnClearPart');
        if (b3) b3.style.display = 'none';
        if (clr3) clr3.style.display = 'none';

        var ddPlaceholder = document.getElementById('deepDivePlaceholder');
        var ddContent     = document.getElementById('deepDiveContent');
        if (ddPlaceholder) ddPlaceholder.style.display = 'flex';
        if (ddContent)     ddContent.style.display     = 'none';
    }

    // ── Deep-Dive: Load Trend Timeline & KPIs ─────────────────────────────────
    function loadTrendTimeline() {
        if (!funnelState.selectedPartId) return;

        var url = buildApiUrl('get_trend_timeline', {
            defect_id: funnelState.selectedDefectId,
            model_id:  funnelState.selectedModelId,
            part_id:   funnelState.selectedPartId
        });

        fetch(url)
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!data.success) return;
                cachedTimelineData = data;

                // Update 4 KPI Cards
                var kpis = data.kpis || {};
                var elTot = document.getElementById('kpiTotalMetric');
                var elSub = document.getElementById('kpiSubMetric');
                var elUnt = document.getElementById('kpiUnitText');
                var elPk  = document.getElementById('kpiPeakLabel');
                var elAvg = document.getElementById('kpiAvgMetric');
                var elAvU = document.getElementById('kpiAvgUnit');
                var elInt = document.getElementById('kpiIntervalCount');
                var elLot = document.getElementById('kpiTotalLots');
                var elDesc = document.getElementById('lblTimelineIntervalDesc');

                if (elTot) elTot.textContent = Number(kpis.grandMetric || 0).toLocaleString();
                if (elUnt) elUnt.textContent = kpis.unit || 'PCS';
                if (elSub) {
                    elSub.textContent = (funnelState.unit === 'lot')
                        ? (Number(kpis.grandPcs || 0).toLocaleString() + ' Pcs total')
                        : (Number(kpis.grandLots || 0).toLocaleString() + ' Kasus Lot');
                }
                if (elPk)  elPk.textContent  = kpis.peakLabel || '-';
                if (elAvg) elAvg.textContent = (kpis.avgMetric !== undefined) ? kpis.avgMetric : '0';
                if (elAvU) elAvU.textContent = kpis.unit || 'PCS';

                var intWord = 'interval waktu';
                if (kpis.intervalType === 'tahun') intWord = 'tahun';
                else if (kpis.intervalType === 'bulan') intWord = 'bulan';
                else if (kpis.intervalType === 'hari') intWord = 'hari';
                else if (kpis.intervalType === 'jam') intWord = 'jam';

                if (elInt) elInt.textContent = 'Dari ' + (kpis.activeIntervals || 0) + ' ' + intWord;
                if (elLot) elLot.textContent = Number(kpis.grandLots || 0).toLocaleString();

                if (elDesc) {
                    if (kpis.intervalType === 'tahun') {
                        elDesc.textContent = 'Distribusi total agregat cacat per tahun kalender.';
                    } else if (kpis.intervalType === 'jam') {
                        elDesc.textContent = 'Distribusi temuan per jam (06:00 - 22:00) pada hari inspeksi.';
                    } else if (kpis.intervalType === 'hari') {
                        elDesc.textContent = 'Distribusi temuan harian sepanjang rentang waktu terpilih.';
                    } else {
                        elDesc.textContent = 'Distribusi frekuensi cacat sepanjang interval waktu terpilih.';
                    }
                }

                // Render Chart
                renderTimelineChart(data, funnelState.timelineChartType);
            })
            .catch(function(err) {
                console.error("Error loading trend timeline:", err);
            });
    }

    function renderTimelineChart(data, chartType) {
        var canvas = document.getElementById('chartTrendTimeline');
        if (!canvas) return;
        if (chartTimelineInstance) chartTimelineInstance.destroy();

        var ctx = canvas.getContext('2d');
        var isBar = (chartType === 'bar');
        var unitLabel = data.kpis ? data.kpis.unit : 'PCS';

        var datasetConfig = {
            label: 'Temuan Cacat (' + unitLabel + ')',
            data: data.counts,
            borderColor: '#2563eb',
            backgroundColor: isBar ? '#3b82f6' : 'rgba(37,99,235,0.08)',
            borderWidth: isBar ? 0 : 2.5,
            borderRadius: isBar ? 5 : 0,
            barPercentage: 0.6,
            categoryPercentage: 0.8,
            pointBackgroundColor: '#2563eb',
            pointBorderColor: '#ffffff',
            pointBorderWidth: 2,
            pointRadius: isBar ? 0 : 4,
            pointHoverRadius: 6,
            fill: !isBar,
            tension: 0.35
        };

        chartTimelineInstance = new Chart(ctx, {
            type: chartType,
            data: {
                labels: data.labels,
                datasets: [datasetConfig]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        backgroundColor: '#0f172a',
                        titleColor: '#f8fafc',
                        bodyColor: '#e2e8f0',
                        padding: 10,
                        cornerRadius: 8,
                        callbacks: {
                            label: function(context) {
                                var val = Number(context.raw || 0).toLocaleString();
                                return ' Temuan: ' + val + ' ' + unitLabel;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 10, weight: '600' }, color: '#64748b' }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: '#f1f5f9' },
                        ticks: {
                            font: { size: 10 },
                            color: '#64748b',
                            callback: function(val) {
                                if (Number.isInteger(val)) return Number(val).toLocaleString();
                                return null;
                            }
                        }
                    }
                }
            }
        });
    }

    function switchTimelineChartType(type) {
        funnelState.timelineChartType = type;
        var btnLine = document.getElementById('btnTimelineLine');
        var btnBar  = document.getElementById('btnTimelineBar');

        if (btnLine && btnBar) {
            if (type === 'line') {
                btnLine.style.background = '#2563eb';
                btnLine.style.color = '#ffffff';
                btnLine.style.boxShadow = '0 1px 3px rgba(37,99,235,0.25)';
                btnBar.style.background = 'transparent';
                btnBar.style.color = '#64748b';
                btnBar.style.boxShadow = 'none';
            } else {
                btnBar.style.background = '#2563eb';
                btnBar.style.color = '#ffffff';
                btnBar.style.boxShadow = '0 1px 3px rgba(37,99,235,0.25)';
                btnLine.style.background = 'transparent';
                btnLine.style.color = '#64748b';
                btnLine.style.boxShadow = 'none';
            }
        }

        if (cachedTimelineData) {
            renderTimelineChart(cachedTimelineData, type);
        }
    }

    // ── Deep-Dive: Load Audit Trail Table History ─────────────────────────────
    function loadTableHistory() {
        var params = {};
        if (funnelState.selectedDefectId) params.defect_id = funnelState.selectedDefectId;
        if (funnelState.selectedModelId !== null) params.model_id = funnelState.selectedModelId;
        if (funnelState.selectedPartId) params.part_id = funnelState.selectedPartId;

        var url = buildApiUrl('get_history', params);
        var tbody = document.getElementById('historyTableBody');
        if (tbody) tbody.style.opacity = '0.5';

        fetch(url)
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!data.success) return;
                if (tbody) {
                    tbody.innerHTML = data.html;
                    tbody.style.opacity = '1';
                }
                updateTableScopeBadge();
                var cnt = document.getElementById('tableRecordCount');
                if (cnt) {
                    cnt.innerHTML = 'Menampilkan <strong>' + (data.count || 0) + '</strong> temuan defect pada seleksi ini.';
                }
                var q = $('#tableSearchInput').val();
                if (q) {
                    $('#tableSearchInput').trigger('keyup');
                }
            })
            .catch(function(err) {
                console.error("Error loading table history:", err);
                if (tbody) tbody.style.opacity = '1';
            });
    }

    function updateTableScopeBadge() {
        var badge = document.getElementById('tableScopeBadge');
        if (!badge) return;

        if (funnelState.selectedPartId && funnelState.selectedPartCode) {
            badge.innerHTML = '<span style="color:#0f172a;">Defect: <strong>' + escapeHtml(funnelState.selectedDefectName) + '</strong></span> &rarr; ' +
                              '<span style="color:#4f46e5;">Model: <strong>' + escapeHtml(funnelState.selectedModelName) + '</strong></span> &rarr; ' +
                              '<span style="color:#059669;">Part: <strong>' + escapeHtml(funnelState.selectedPartCode) + '</strong></span>';
            badge.style.background = '#ecfdf5';
            badge.style.borderColor = '#a7f3d0';
            badge.style.color = '#065f46';
        } else if (funnelState.selectedModelId !== null && funnelState.selectedModelName) {
            badge.innerHTML = '<span style="color:#0f172a;">Defect: <strong>' + escapeHtml(funnelState.selectedDefectName) + '</strong></span> &rarr; ' +
                              '<span style="color:#4f46e5;">Model: <strong>' + escapeHtml(funnelState.selectedModelName) + '</strong></span>';
            badge.style.background = '#eef2ff';
            badge.style.borderColor = '#c7d2fe';
            badge.style.color = '#3730a3';
        } else if (funnelState.selectedDefectId && funnelState.selectedDefectName) {
            badge.innerHTML = '<span>Defect: <strong>' + escapeHtml(funnelState.selectedDefectName) + '</strong></span>';
            badge.style.background = '#eff6ff';
            badge.style.borderColor = '#bfdbfe';
            badge.style.color = '#1d4ed8';
        } else {
            badge.innerHTML = 'Cakupan: Semua Data';
            badge.style.background = '#f1f5f9';
            badge.style.borderColor = '#e2e8f0';
            badge.style.color = '#475569';
        }
    }

    // ── Helper Utilities ──────────────────────────────────────────────────────
    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function escapeQuotes(str) {
        if (!str) return '';
        return String(str).replace(/'/g, "\\'").replace(/"/g, '&quot;');
    }

    // ── PART-CENTRIC ANALYSIS JAVASCRIPT CONTROLLER ───────────────────────────
    function switchAnalysisMode(mode) {
        currentAnalysisMode = mode;
        var btnD  = document.getElementById('btnModeDefect');
        var btnP  = document.getElementById('btnModePart');
        var contD = document.getElementById('viewContainerDefectMode');
        var contP = document.getElementById('viewContainerPartMode');

        if (mode === 'defect') {
            if (btnD) {
                btnD.style.background = '#0f172a';
                btnD.style.color = '#ffffff';
                btnD.style.boxShadow = '0 1px 3px rgba(15,23,42,0.25)';
            }
            if (btnP) {
                btnP.style.background = 'transparent';
                btnP.style.color = '#64748b';
                btnP.style.boxShadow = 'none';
            }
            if (contD) contD.style.display = 'flex';
            if (contP) contP.style.display = 'none';
        } else {
            if (btnP) {
                btnP.style.background = '#0f172a';
                btnP.style.color = '#ffffff';
                btnP.style.boxShadow = '0 1px 3px rgba(15,23,42,0.25)';
            }
            if (btnD) {
                btnD.style.background = 'transparent';
                btnD.style.color = '#64748b';
                btnD.style.boxShadow = 'none';
            }
            if (contD) contD.style.display = 'none';
            if (contP) contP.style.display = 'flex';

            refreshPartMode();
        }
    }

    function refreshPartMode() {
        loadPartRanking(function() {
            if (partState.selectedPartId) {
                highlightPartModeCard(partState.selectedPartId);
                loadPartDefects(function() {
                    loadPartDefectStackedTrend();
                    loadPartHistory();
                });
            }
        });
        loadPartStackedTrend();
    }

    // 1. Part Ranking Donut & Cards
    function loadPartRanking(callback) {
        var url = buildApiUrl('get_part_ranking', { top_rank: funnelState.topRank });
        var listContainer = document.getElementById('listPartRankingCards');
        if (listContainer && !partState.selectedPartId) {
            listContainer.innerHTML = '<div style="padding:20px;text-align:center;color:#94a3b8;font-size:12px;">Memuat data peringkat part...</div>';
        }

        fetch(url)
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!data.success) return;
                renderPartRankingDonut(data);
                renderPartRankingList(data);
                if (typeof callback === 'function') callback();
            })
            .catch(function(err) {
                console.error("Error loading part ranking:", err);
            });
    }

    function renderPartRankingDonut(data) {
        var canvas = document.getElementById('chartPartRankingDonut');
        if (!canvas) return;
        if (chartPartRankingDonutInstance) chartPartRankingDonutInstance.destroy();

        updateCenterStat('PartRank', 'TOTAL CACAT', data.grandMetric, data.unit, '#94a3b8');

        var ctx = canvas.getContext('2d');
        chartPartRankingDonutInstance = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: data.chartLabels,
                datasets: [{
                    data: data.chartData,
                    backgroundColor: data.chartColors,
                    borderWidth: 2,
                    borderColor: '#ffffff',
                    hoverOffset: 8,
                    spacing: 3,
                    borderRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        titleColor: '#f8fafc',
                        bodyColor: '#e2e8f0',
                        padding: 10,
                        cornerRadius: 8,
                        callbacks: {
                            label: function(ctx) {
                                var val = Number(ctx.raw || 0).toLocaleString();
                                var pct = data.grandMetric > 0 ? Math.round((ctx.raw / data.grandMetric) * 1000) / 10 : 0;
                                return ' ' + ctx.label + ': ' + val + ' ' + data.unit + ' (' + pct + '%)';
                            }
                        }
                    }
                },
                onHover: function(evt, elements) {
                    if (elements && elements.length > 0) {
                        var idx = elements[0].index;
                        var item = data.items[idx];
                        if (item) {
                            var pct = data.grandMetric > 0 ? Math.round((item.val / data.grandMetric) * 1000) / 10 : 0;
                            var labelText = item.part_code || item.item_name;
                            updateCenterStat('PartRank', labelText, item.val, pct + '%', item.color);
                        }
                    } else {
                        updateCenterStat('PartRank', 'TOTAL CACAT', data.grandMetric, data.unit, '#94a3b8');
                    }
                },
                onClick: function(evt, elements) {
                    if (elements && elements.length > 0) {
                        var idx = elements[0].index;
                        var item = data.items[idx];
                        if (item && !item.is_other && item.item_id) {
                            selectPartForDeepDive(item.item_id, item.part_code, item.part_name, true);
                        }
                    }
                }
            }
        });

        canvas.onmouseleave = function() {
            updateCenterStat('PartRank', 'TOTAL CACAT', data.grandMetric, data.unit, '#94a3b8');
        };
    }

    function renderPartRankingList(data) {
        var container = document.getElementById('listPartRankingCards');
        if (!container) return;

        if (!data.items || data.items.length === 0) {
            container.innerHTML = '<div style="padding:20px;text-align:center;color:#94a3b8;font-size:12px;">Tidak ada temuan part pada filter ini.</div>';
            return;
        }

        var html = '';
        data.items.forEach(function(item, idx) {
            var isOther = !!item.is_other;
            var clickAttr = isOther ? '' : 'onclick="selectPartForDeepDive(' + item.item_id + ', \'' + escapeQuotes(item.part_code || '') + '\', \'' + escapeQuotes(item.part_name || item.item_name) + '\', true)"';
            var cursorStyle = isOther ? 'cursor:default;opacity:0.85;' : 'cursor:pointer;';
            var activeCls = (partState.selectedPartId && Number(partState.selectedPartId) === Number(item.item_id)) ? 'funnel-card-active-3' : '';
            var hoverAttr = 'onmouseenter="onHoverItemCard(\'PartRank\', ' + idx + ', ' + item.val + ', \'' + escapeQuotes(item.part_code || item.item_name) + '\', \'' + item.color + '\', ' + item.percentage + ')" onmouseleave="onLeaveItemCard(\'PartRank\', ' + data.grandMetric + ', \'' + data.unit + '\')"';

            html += '<div class="funnel-item-card ' + activeCls + '" id="card-partrank-' + item.item_id + '" ' + clickAttr + ' ' + hoverAttr + ' style="' + cursorStyle + '">';
            html += '  <div style="display:flex;align-items:center;gap:10px;min-width:0;flex:1;">';
            html += '    <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' + item.color + ';flex-shrink:0;"></span>';
            html += '    <div style="min-width:0;flex:1;">';
            html += '      <div style="display:flex;align-items:center;justify-content:space-between;gap:6px;">';
            html += '        <div style="min-width:0;">';
            html += '          <span style="font-weight:900;font-size:12px;color:#0f172a;display:block;font-family:monospace;">' + escapeHtml(item.part_code || item.item_name) + '</span>';
            if (item.part_name) {
                html += '          <span style="font-size:10px;color:#64748b;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escapeHtml(item.part_name) + '</span>';
            }
            html += '        </div>';
            html += '        <span style="font-size:10px;font-weight:700;color:#64748b;">' + item.percentage + '%</span>';
            html += '      </div>';
            html += '      <div style="width:100%;height:4px;background:#f1f5f9;border-radius:2px;overflow:hidden;margin-top:4px;">';
            html += '        <div style="width:' + item.percentage + '%;height:100%;background:' + item.color + ';border-radius:2px;"></div>';
            html += '      </div>';
            html += '    </div>';
            html += '  </div>';
            html += '  <div style="text-align:right;flex-shrink:0;padding-left:10px;">';
            html += '    <div style="font-size:12px;font-weight:900;color:#0f172a;">' + Number(item.val).toLocaleString() + ' <span style="font-size:10px;color:#64748b;font-weight:700;">' + data.unit + '</span></div>';
            html += '  </div>';
            html += '</div>';
        });

        container.innerHTML = html;
        if (partState.selectedPartId) {
            highlightPartModeCard(partState.selectedPartId);
        } else if (data.items && data.items.length > 0 && !data.items[0].is_other && data.items[0].item_id) {
            selectPartForDeepDive(data.items[0].item_id, data.items[0].part_code || '', data.items[0].part_name || data.items[0].item_name, false);
        }
    }

    function highlightPartModeCard(partId) {
        document.querySelectorAll('#listPartRankingCards .funnel-item-card').forEach(function(el) {
            el.classList.remove('funnel-card-active-3');
        });
        var card = document.getElementById('card-partrank-' + partId);
        if (card) card.classList.add('funnel-card-active-3');

        var badge = document.getElementById('badgeActivePartMode');
        var lbl   = document.getElementById('lblActivePartMode');
        if (badge && lbl) {
            lbl.textContent = partState.selectedPartCode || ('Part #' + partId);
            badge.style.display = 'inline-flex';
        }
    }

    // 2. Part Stacked Trend (Timeline by Part)
    function loadPartStackedTrend() {
        var url = buildApiUrl('get_part_stacked_trend', {
            top_rank: partState.partTrendRank,
            unit: partState.partTrendUnit
        });

        fetch(url)
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!data.success) return;
                partState.cachedPartStackedData = data;
                renderPartStackedTrend(data);
                renderPartStackedTable(data);
            })
            .catch(function(err) {
                console.error("Error loading part stacked trend:", err);
            });
    }

    function renderPartStackedTrend(data) {
        var canvas = document.getElementById('chartPartStackedTrend');
        if (!canvas) return;
        if (chartPartStackedTrendInstance) chartPartStackedTrendInstance.destroy();

        var isPpm = (partState.partTrendUnit === 'ppm');
        var unitLabel = isPpm ? 'ppm' : 'pcs';

        var ctx = canvas.getContext('2d');
        chartPartStackedTrendInstance = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.labels,
                datasets: data.datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'bottom',
                        labels: {
                            boxWidth: 10,
                            boxHeight: 10,
                            usePointStyle: false,
                            font: { size: 10, weight: '700' },
                            color: '#334155',
                            padding: 12
                        }
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        backgroundColor: '#0f172a',
                        titleColor: '#f8fafc',
                        bodyColor: '#e2e8f0',
                        footerColor: '#38bdf8',
                        footerFont: { weight: '800' },
                        padding: 10,
                        cornerRadius: 8,
                        callbacks: {
                            label: function(context) {
                                var val = Number(context.raw || 0).toLocaleString();
                                return ' ' + context.dataset.label + ': ' + val + ' ' + unitLabel;
                            },
                            footer: function(tooltipItems) {
                                var sum = 0;
                                tooltipItems.forEach(function(ti) {
                                    sum += Number(ti.raw || 0);
                                });
                                return 'Total: ' + sum.toLocaleString() + ' ' + unitLabel;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        stacked: true,
                        grid: { display: false },
                        ticks: { font: { size: 10, weight: '600' }, color: '#64748b' }
                    },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        grid: { color: '#f1f5f9' },
                        ticks: {
                            font: { size: 10 },
                            color: '#64748b',
                            callback: function(val) {
                                if (Number.isInteger(val)) return Number(val).toLocaleString();
                                return null;
                            }
                        }
                    }
                }
            }
        });
    }

    function renderPartStackedTable(data) {
        var wrapper = document.getElementById('wrapperPartStackedTable');
        if (!wrapper) return;

        var headers = data.partsHeader || [];
        var rows    = data.rawMatrix || [];

        if (rows.length === 0) {
            wrapper.innerHTML = '<div style="padding:20px;text-align:center;color:#94a3b8;">Tidak ada data matriks.</div>';
            return;
        }

        var html = '<table style="width:100%;border-collapse:collapse;font-size:11px;text-align:left;">';
        html += '<thead><tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;font-weight:800;color:#475569;">';
        html += '<th style="padding:6px 10px;">Tanggal</th>';
        headers.forEach(function(h) {
            html += '<th style="padding:6px 10px;font-family:monospace;"><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' + h.color + ';margin-right:4px;"></span>' + escapeHtml(h.part_code) + '</th>';
        });
        html += '<th style="padding:6px 10px;text-align:right;font-weight:900;">Total (' + escapeHtml(data.unit) + ')</th>';
        html += '</tr></thead><tbody>';

        rows.forEach(function(r) {
            html += '<tr style="border-bottom:1px solid #f1f5f9;">';
            html += '<td style="padding:6px 10px;font-weight:700;color:#0f172a;">' + escapeHtml(r.label) + '</td>';
            headers.forEach(function(h) {
                var v = r.values[h.part_code] || 0;
                html += '<td style="padding:6px 10px;color:#334155;">' + Number(v).toLocaleString() + '</td>';
            });
            html += '<td style="padding:6px 10px;text-align:right;font-weight:900;color:#0f172a;">' + Number(r.total).toLocaleString() + '</td>';
            html += '</tr>';
        });

        html += '</tbody></table>';
        wrapper.innerHTML = html;
    }

    function switchPartTrendUnit(unit) {
        partState.partTrendUnit = unit;
        var btnPcs = document.getElementById('btnPartUnitPcs');
        var btnPpm = document.getElementById('btnPartUnitPpm');
        if (unit === 'pcs') {
            if (btnPcs) { btnPcs.style.background = '#0f172a'; btnPcs.style.color = '#ffffff'; }
            if (btnPpm) { btnPpm.style.background = 'transparent'; btnPpm.style.color = '#64748b'; }
        } else {
            if (btnPpm) { btnPpm.style.background = '#0f172a'; btnPpm.style.color = '#ffffff'; }
            if (btnPcs) { btnPcs.style.background = 'transparent'; btnPcs.style.color = '#64748b'; }
        }
        loadPartStackedTrend();
    }

    function switchPartTrendRank(rk) {
        partState.partTrendRank = rk;
        document.querySelectorAll('.part-rank-btn').forEach(function(btn) {
            var r = btn.getAttribute('data-rank');
            if (r === rk) {
                btn.style.background = '#4f46e5';
                btn.style.color = '#ffffff';
                btn.style.fontWeight = '800';
            } else {
                btn.style.background = 'transparent';
                btn.style.color = '#64748b';
                btn.style.fontWeight = '700';
            }
        });
        loadPartStackedTrend();
    }

    function switchPartTrendView(view) {
        partState.partTrendView = view;
        var btnChart = document.getElementById('btnPartViewChart');
        var btnTable = document.getElementById('btnPartViewTable');
        var wrapChart = document.getElementById('wrapperPartStackedChart');
        var wrapTable = document.getElementById('wrapperPartStackedTable');

        if (view === 'chart') {
            if (btnChart) { btnChart.style.background = '#2563eb'; btnChart.style.color = '#ffffff'; }
            if (btnTable) { btnTable.style.background = 'transparent'; btnTable.style.color = '#64748b'; }
            if (wrapChart) wrapChart.style.display = 'block';
            if (wrapTable) wrapTable.style.display = 'none';
        } else {
            if (btnTable) { btnTable.style.background = '#2563eb'; btnTable.style.color = '#ffffff'; }
            if (btnChart) { btnChart.style.background = 'transparent'; btnChart.style.color = '#64748b'; }
            if (wrapChart) wrapChart.style.display = 'none';
            if (wrapTable) wrapTable.style.display = 'block';
        }
    }

    // 3. Selection of Part for Deep Dive
    function selectPartForDeepDive(partId, partCode, partName, shouldScroll) {
        partState.selectedPartId   = partId;
        partState.selectedPartCode = partCode;
        partState.selectedPartName = partName;
        partState.selectedDefectId = null;
        partState.selectedDefectName = '';

        highlightPartModeCard(partId);

        var placeholder = document.getElementById('partDeepDivePlaceholder');
        var content     = document.getElementById('partDeepDiveContent');
        if (placeholder) placeholder.style.display = 'none';
        if (content) content.style.display = 'flex';

        var elCode = document.getElementById('ddPartCodeText');
        var elName = document.getElementById('ddPartNameText');
        if (elCode) elCode.textContent = partCode || '-';
        if (elName) elName.textContent = partName || '-';

        // Reset defect filter badge
        var bDef = document.getElementById('badgeActiveDefectFilter');
        var btnRst = document.getElementById('btnResetDefectFilter');
        if (bDef) bDef.style.display = 'none';
        if (btnRst) btnRst.style.display = 'none';

        var bScope = document.getElementById('badgePartHistoryScope');
        if (bScope) {
            bScope.textContent = 'Cakupan: Semua Defect Part Ini';
            bScope.style.background = '#eff6ff';
            bScope.style.borderColor = '#bfdbfe';
            bScope.style.color = '#1d4ed8';
        }

        // Load defects, stacked trend, and history
        loadPartDefects();
        loadPartDefectStackedTrend();
        loadPartHistory();

        if (shouldScroll) {
            setTimeout(function() {
                var targetEl = document.getElementById('sectionPartDeepDive');
                if (targetEl) {
                    targetEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }, 100);
        }
    }

    function clearSelectedPart() {
        partState.selectedPartId   = null;
        partState.selectedPartCode = '';
        partState.selectedPartName = '';
        partState.selectedDefectId = null;
        partState.selectedDefectName = '';

        document.querySelectorAll('#listPartRankingCards .funnel-item-card').forEach(function(el) {
            el.classList.remove('funnel-card-active-3');
        });

        var badge = document.getElementById('badgeActivePartMode');
        if (badge) badge.style.display = 'none';

        var placeholder = document.getElementById('partDeepDivePlaceholder');
        var content     = document.getElementById('partDeepDiveContent');
        if (placeholder) placeholder.style.display = 'flex';
        if (content) content.style.display = 'none';
    }

    // 4. Part Defect Donut (Panel 2 Kiri)
    function loadPartDefects(callback) {
        if (!partState.selectedPartId) return;

        var url = buildApiUrl('get_part_defects', { part_id: partState.selectedPartId });
        var listContainer = document.getElementById('listPartDefectCards');
        if (listContainer) {
            listContainer.innerHTML = '<div style="padding:20px;text-align:center;color:#94a3b8;font-size:12px;">Memuat data cacat...</div>';
        }

        fetch(url)
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!data.success) return;
                var elModel = document.getElementById('ddPartModelName');
                if (elModel) elModel.textContent = data.model_name || '-';

                renderPartDefectsDonut(data);
                renderPartDefectsList(data);
                if (typeof callback === 'function') callback();
            })
            .catch(function(err) {
                console.error("Error loading part defects:", err);
            });
    }

    function renderPartDefectsDonut(data) {
        var canvas = document.getElementById('chartPartDefectDonut');
        if (!canvas) return;
        if (chartPartDefectDonutInstance) chartPartDefectDonutInstance.destroy();

        updateCenterStat('PartDefect', 'TOTAL DEFECT', data.grandMetric, data.unit, '#94a3b8');

        var ctx = canvas.getContext('2d');
        chartPartDefectDonutInstance = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: data.chartLabels,
                datasets: [{
                    data: data.chartData,
                    backgroundColor: data.chartColors,
                    borderWidth: 2,
                    borderColor: '#ffffff',
                    hoverOffset: 8,
                    spacing: 3,
                    borderRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        titleColor: '#f8fafc',
                        bodyColor: '#e2e8f0',
                        padding: 10,
                        cornerRadius: 8,
                        callbacks: {
                            label: function(ctx) {
                                var val = Number(ctx.raw || 0).toLocaleString();
                                var pct = data.grandMetric > 0 ? Math.round((ctx.raw / data.grandMetric) * 1000) / 10 : 0;
                                return ' ' + ctx.label + ': ' + val + ' ' + data.unit + ' (' + pct + '%)';
                            }
                        }
                    }
                },
                onHover: function(evt, elements) {
                    if (elements && elements.length > 0) {
                        var idx = elements[0].index;
                        var item = data.items[idx];
                        if (item) {
                            var pct = data.grandMetric > 0 ? Math.round((item.val / data.grandMetric) * 1000) / 10 : 0;
                            updateCenterStat('PartDefect', item.item_name, item.val, pct + '%', item.color);
                        }
                    } else {
                        updateCenterStat('PartDefect', 'TOTAL DEFECT', data.grandMetric, data.unit, '#94a3b8');
                    }
                },
                onClick: function(evt, elements) {
                    if (elements && elements.length > 0) {
                        var idx = elements[0].index;
                        var item = data.items[idx];
                        if (item && !item.is_other && item.item_id) {
                            filterPartHistoryByDefect(item.item_id, item.item_name);
                        }
                    }
                }
            }
        });

        canvas.onmouseleave = function() {
            updateCenterStat('PartDefect', 'TOTAL DEFECT', data.grandMetric, data.unit, '#94a3b8');
        };
    }

    function renderPartDefectsList(data) {
        var container = document.getElementById('listPartDefectCards');
        if (!container) return;

        if (!data.items || data.items.length === 0) {
            container.innerHTML = '<div style="padding:20px;text-align:center;color:#94a3b8;font-size:12px;">Tidak ada temuan cacat pada part ini.</div>';
            return;
        }

        var html = '';
        data.items.forEach(function(item, idx) {
            var isOther = !!item.is_other;
            var clickAttr = isOther ? '' : 'onclick="filterPartHistoryByDefect(' + item.item_id + ', \'' + escapeQuotes(item.item_name) + '\')"';
            var cursorStyle = isOther ? 'cursor:default;opacity:0.85;' : 'cursor:pointer;';
            var activeCls = (partState.selectedDefectId && Number(partState.selectedDefectId) === Number(item.item_id)) ? 'funnel-card-active-1' : '';
            var hoverAttr = 'onmouseenter="onHoverItemCard(\'PartDefect\', ' + idx + ', ' + item.val + ', \'' + escapeQuotes(item.item_name) + '\', \'' + item.color + '\', ' + item.percentage + ')" onmouseleave="onLeaveItemCard(\'PartDefect\', ' + data.grandMetric + ', \'' + data.unit + '\')"';

            html += '<div class="funnel-item-card ' + activeCls + '" id="card-partdefect-' + item.item_id + '" ' + clickAttr + ' ' + hoverAttr + ' style="' + cursorStyle + '">';
            html += '  <div style="display:flex;align-items:center;gap:10px;min-width:0;flex:1;">';
            html += '    <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' + item.color + ';flex-shrink:0;"></span>';
            html += '    <div style="min-width:0;flex:1;">';
            html += '      <div style="display:flex;align-items:center;justify-content:space-between;gap:6px;">';
            html += '        <span style="font-weight:800;font-size:12px;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escapeHtml(item.item_name) + '</span>';
            html += '        <span style="font-size:10px;font-weight:700;color:#64748b;">' + item.percentage + '%</span>';
            html += '      </div>';
            html += '      <div style="width:100%;height:4px;background:#f1f5f9;border-radius:2px;overflow:hidden;margin-top:4px;">';
            html += '        <div style="width:' + item.percentage + '%;height:100%;background:' + item.color + ';border-radius:2px;"></div>';
            html += '      </div>';
            html += '    </div>';
            html += '  </div>';
            html += '  <div style="text-align:right;flex-shrink:0;padding-left:10px;">';
            html += '    <div style="font-size:12px;font-weight:900;color:#0f172a;">' + Number(item.val).toLocaleString() + ' <span style="font-size:10px;color:#64748b;font-weight:700;">' + data.unit + '</span></div>';
            html += '  </div>';
            html += '</div>';
        });

        container.innerHTML = html;
        if (partState.selectedDefectId) {
            highlightPartDefectCard(partState.selectedDefectId);
        }
    }

    function highlightPartDefectCard(defectId) {
        document.querySelectorAll('#listPartDefectCards .funnel-item-card').forEach(function(el) {
            el.classList.remove('funnel-card-active-1');
        });
        var card = document.getElementById('card-partdefect-' + defectId);
        if (card) card.classList.add('funnel-card-active-1');
    }

    function filterPartHistoryByDefect(defectId, defectName) {
        if (partState.selectedDefectId === defectId) {
            resetPartDefectFilter();
            return;
        }

        partState.selectedDefectId   = defectId;
        partState.selectedDefectName = defectName;

        highlightPartDefectCard(defectId);

        var badge = document.getElementById('badgeActiveDefectFilter');
        var lbl   = document.getElementById('lblActiveDefectFilter');
        var btnRst = document.getElementById('btnResetDefectFilter');
        if (badge && lbl) {
            lbl.textContent = defectName;
            badge.style.display = 'inline-flex';
        }
        if (btnRst) btnRst.style.display = 'inline-block';

        var bScope = document.getElementById('badgePartHistoryScope');
        if (bScope) {
            bScope.innerHTML = 'Cakupan: Defect <strong>' + escapeHtml(defectName) + '</strong>';
            bScope.style.background = '#fff1f2';
            bScope.style.borderColor = '#ffe4e6';
            bScope.style.color = '#be123c';
        }

        loadPartHistory();
    }

    function resetPartDefectFilter() {
        partState.selectedDefectId   = null;
        partState.selectedDefectName = '';

        document.querySelectorAll('#listPartDefectCards .funnel-item-card').forEach(function(el) {
            el.classList.remove('funnel-card-active-1');
        });

        var badge = document.getElementById('badgeActiveDefectFilter');
        var btnRst = document.getElementById('btnResetDefectFilter');
        if (badge) badge.style.display = 'none';
        if (btnRst) btnRst.style.display = 'none';

        var bScope = document.getElementById('badgePartHistoryScope');
        if (bScope) {
            bScope.textContent = 'Cakupan: Semua Defect Part Ini';
            bScope.style.background = '#eff6ff';
            bScope.style.borderColor = '#bfdbfe';
            bScope.style.color = '#1d4ed8';
        }

        loadPartHistory();
    }

    // 5. Part Defect Stacked Trend (Timeline by Defect — Sesuai Screenshot User)
    function loadPartDefectStackedTrend() {
        if (!partState.selectedPartId) return;

        var url = buildApiUrl('get_part_defect_stacked_trend', {
            part_id: partState.selectedPartId,
            top_rank: partState.defectTrendRank,
            unit: partState.defectTrendUnit
        });

        fetch(url)
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!data.success) return;
                partState.cachedDefectStackedData = data;

                var titleEl = document.getElementById('lblDefectStackedTitle');
                var subEl   = document.getElementById('lblDefectStackedSubtitle');
                if (titleEl) {
                    titleEl.innerHTML = '<svg width="18" height="18" fill="none" stroke="#2563eb" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z" /></svg>' +
                                        'Top ' + partState.defectTrendRank + ' Defect (' + partState.defectTrendUnit.toUpperCase() + ')';
                }
                if (subEl) {
                    subEl.textContent = funnelState.startDate + ' – ' + funnelState.endDate;
                }

                renderPartDefectStackedTrend(data);
                renderPartDefectStackedTable(data);
            })
            .catch(function(err) {
                console.error("Error loading part defect stacked trend:", err);
            });
    }

    function renderPartDefectStackedTrend(data) {
        var canvas = document.getElementById('chartPartDefectStackedTrend');
        if (!canvas) return;
        if (chartPartDefectStackedTrendInstance) chartPartDefectStackedTrendInstance.destroy();

        var isPpm = (partState.defectTrendUnit === 'ppm');
        var unitLabel = isPpm ? 'ppm' : 'pcs';

        var ctx = canvas.getContext('2d');
        chartPartDefectStackedTrendInstance = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.labels,
                datasets: data.datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'bottom',
                        labels: {
                            boxWidth: 10,
                            boxHeight: 10,
                            usePointStyle: false,
                            font: { size: 10, weight: '700' },
                            color: '#334155',
                            padding: 12
                        }
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        backgroundColor: '#0f172a',
                        titleColor: '#f8fafc',
                        bodyColor: '#e2e8f0',
                        footerColor: '#38bdf8',
                        footerFont: { weight: '800' },
                        padding: 10,
                        cornerRadius: 8,
                        filter: function(tooltipItem) {
                            return Number(tooltipItem.raw || 0) > 0;
                        },
                        itemSort: function(a, b) {
                            return Number(b.raw || 0) - Number(a.raw || 0);
                        },
                        callbacks: {
                            label: function(context) {
                                var val = Number(context.raw || 0).toLocaleString();
                                var ds = context.dataset;
                                var pCode = ds.part_code || (data && data.part_code) || partState.selectedPartCode || '-';
                                var pName = ds.part_name || (data && data.part_name) || partState.selectedPartName || '-';

                                var rawNg = (ds.raw_pcs && ds.raw_pcs[context.dataIndex] !== undefined)
                                    ? ds.raw_pcs[context.dataIndex]
                                    : (context.raw || 0);

                                var mainLine = ' ' + ds.label + ': ' + val + ' ' + unitLabel;
                                var subLine = '    └ Part: ' + pCode + ' (' + pName + ') • NG: ' + Number(rawNg).toLocaleString() + ' pcs';
                                return [mainLine, subLine];
                            },
                            footer: function(tooltipItems) {
                                var sum = 0;
                                tooltipItems.forEach(function(ti) {
                                    sum += Number(ti.raw || 0);
                                });
                                return 'Total Defect: ' + sum.toLocaleString() + ' ' + unitLabel;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        stacked: true,
                        grid: { display: false },
                        ticks: { font: { size: 10, weight: '600' }, color: '#64748b' }
                    },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        grid: { color: '#f1f5f9' },
                        ticks: {
                            font: { size: 10 },
                            color: '#64748b',
                            callback: function(val) {
                                if (Number.isInteger(val)) return Number(val).toLocaleString();
                                return null;
                            }
                        }
                    }
                }
            }
        });
    }

    function renderPartDefectStackedTable(data) {
        var wrapper = document.getElementById('wrapperDefectStackedTable');
        if (!wrapper) return;

        var headers = data.defectsHeader || [];
        var rows    = data.rawMatrix || [];

        if (rows.length === 0) {
            wrapper.innerHTML = '<div style="padding:20px;text-align:center;color:#94a3b8;">Tidak ada data matriks.</div>';
            return;
        }

        var html = '<table style="width:100%;border-collapse:collapse;font-size:11px;text-align:left;">';
        html += '<thead><tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;font-weight:800;color:#475569;">';
        html += '<th style="padding:6px 10px;">Tanggal</th>';
        headers.forEach(function(h) {
            html += '<th style="padding:6px 10px;"><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' + h.color + ';margin-right:4px;"></span>' + escapeHtml(h.defect_name) + '</th>';
        });
        html += '<th style="padding:6px 10px;text-align:right;font-weight:900;">Total (' + escapeHtml(data.unit) + ')</th>';
        html += '</tr></thead><tbody>';

        rows.forEach(function(r) {
            html += '<tr style="border-bottom:1px solid #f1f5f9;">';
            html += '<td style="padding:6px 10px;font-weight:700;color:#0f172a;">' + escapeHtml(r.label) + '</td>';
            headers.forEach(function(h) {
                var v = r.values[h.defect_name] || 0;
                html += '<td style="padding:6px 10px;color:#334155;">' + Number(v).toLocaleString() + '</td>';
            });
            html += '<td style="padding:6px 10px;text-align:right;font-weight:900;color:#0f172a;">' + Number(r.total).toLocaleString() + '</td>';
            html += '</tr>';
        });

        html += '</tbody></table>';
        wrapper.innerHTML = html;
    }

    function switchDefectTrendUnit(unit) {
        partState.defectTrendUnit = unit;
        var btnPcs = document.getElementById('btnDefectUnitPcs');
        var btnPpm = document.getElementById('btnDefectUnitPpm');
        if (unit === 'pcs') {
            if (btnPcs) { btnPcs.style.background = '#0f172a'; btnPcs.style.color = '#ffffff'; }
            if (btnPpm) { btnPpm.style.background = 'transparent'; btnPpm.style.color = '#64748b'; }
        } else {
            if (btnPpm) { btnPpm.style.background = '#0f172a'; btnPpm.style.color = '#ffffff'; }
            if (btnPcs) { btnPcs.style.background = 'transparent'; btnPcs.style.color = '#64748b'; }
        }
        loadPartDefectStackedTrend();
    }

    function switchDefectTrendRank(rk) {
        partState.defectTrendRank = rk;
        document.querySelectorAll('.defect-rank-btn').forEach(function(btn) {
            var r = btn.getAttribute('data-rank');
            if (r === rk) {
                btn.style.background = '#4f46e5';
                btn.style.color = '#ffffff';
                btn.style.fontWeight = '800';
            } else {
                btn.style.background = 'transparent';
                btn.style.color = '#64748b';
                btn.style.fontWeight = '700';
            }
        });
        loadPartDefectStackedTrend();
    }

    function switchDefectTrendView(view) {
        partState.defectTrendView = view;
        var btnChart = document.getElementById('btnDefectViewChart');
        var btnTable = document.getElementById('btnDefectViewTable');
        var wrapChart = document.getElementById('wrapperDefectStackedChart');
        var wrapTable = document.getElementById('wrapperDefectStackedTable');

        if (view === 'chart') {
            if (btnChart) { btnChart.style.background = '#2563eb'; btnChart.style.color = '#ffffff'; }
            if (btnTable) { btnTable.style.background = 'transparent'; btnTable.style.color = '#64748b'; }
            if (wrapChart) wrapChart.style.display = 'block';
            if (wrapTable) wrapTable.style.display = 'none';
        } else {
            if (btnTable) { btnTable.style.background = '#2563eb'; btnTable.style.color = '#ffffff'; }
            if (btnChart) { btnChart.style.background = 'transparent'; btnChart.style.color = '#64748b'; }
            if (wrapChart) wrapChart.style.display = 'none';
            if (wrapTable) wrapTable.style.display = 'block';
        }
    }

    // 6. Part History Audit Trail
    function loadPartHistory() {
        if (!partState.selectedPartId) return;

        var params = { part_id: partState.selectedPartId };
        if (partState.selectedDefectId) {
            params.defect_id = partState.selectedDefectId;
        }

        var url = buildApiUrl('get_part_history', params);
        var tbody = document.getElementById('partHistoryTableBody');
        if (tbody) tbody.style.opacity = '0.5';

        fetch(url)
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!data.success) return;
                if (tbody) {
                    tbody.innerHTML = data.html;
                    tbody.style.opacity = '1';
                }
                var cnt = document.getElementById('partTableRecordCount');
                if (cnt) {
                    cnt.innerHTML = 'Menampilkan <strong>' + (data.count || 0) + '</strong> temuan defect pada seleksi part ini.';
                }
                var q = $('#partTableSearchInput').val();
                if (q) {
                    $('#partTableSearchInput').trigger('keyup');
                }
            })
            .catch(function(err) {
                console.error("Error loading part history:", err);
                if (tbody) tbody.style.opacity = '1';
            });
    }
    </script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
