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

$selectedTopRank     = sanitize($_GET['top_rank'] ?? 'all');
if (!in_array($selectedTopRank, ['3', '5', '10', 'all'])) {
    $selectedTopRank = 'all';
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

        <style>
        /* ── Excel-Style Column Header Filters for Overview Audit Table ── */
        .th-filter-wrapper {
            display: inline-flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            position: relative;
            gap: 4px;
        }
        .th-filter-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 19px;
            height: 19px;
            border-radius: 4px;
            color: #475569 !important;
            cursor: pointer;
            transition: all 0.15s ease;
            background-color: #ffffff !important;
            border: 1.5px solid #cbd5e1 !important;
            flex-shrink: 0;
            padding: 0;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.06);
        }
        .th-filter-btn:hover {
            color: #1d4ed8 !important;
            background-color: #dbeafe !important;
            border-color: #3b82f6 !important;
        }
        .th-filter-btn.active {
            color: #ffffff !important;
            background-color: #2563eb !important;
            border-color: #1d4ed8 !important;
            box-shadow: 0 1px 3px rgba(37, 99, 235, 0.4);
        }
        .excel-filter-dropdown {
            position: fixed;
            z-index: 9999;
            background-color: #ffffff !important;
            border: 1.5px solid #94a3b8 !important;
            border-radius: 10px;
            box-shadow: 0 10px 25px -3px rgba(0, 0, 0, 0.25), 0 4px 10px -2px rgba(0, 0, 0, 0.15);
            width: 245px;
            padding: 10px;
            text-transform: none;
            letter-spacing: normal;
            font-weight: normal;
            color: #0f172a;
            display: none;
            text-align: left;
        }
        .excel-filter-dropdown.align-right {
            right: 0;
            left: auto;
        }
        .excel-filter-dropdown.align-left {
            left: 0;
            right: auto;
        }
        .excel-filter-search {
            width: 100%;
            padding: 6px 10px;
            border: 1.5px solid #cbd5e1;
            border-radius: 6px;
            font-size: 11px;
            outline: none;
            margin-bottom: 8px;
            background-color: #ffffff;
            color: #0f172a;
            box-sizing: border-box;
        }
        .excel-filter-search:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 2px #bfdbfe;
        }
        .excel-quick-btn {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3.5px 8px;
            border-radius: 5px;
            font-size: 10.5px;
            font-weight: 800;
            cursor: pointer;
            border: 1px solid #cbd5e1;
            background-color: #f1f5f9;
            color: #1e293b;
            line-height: 1.2;
            transition: all 0.15s ease;
        }
        .excel-quick-btn:hover {
            background-color: #e2e8f0;
            border-color: #94a3b8;
            color: #0f172a;
        }
        .excel-reset-btn {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 2.5px 7px;
            border-radius: 5px;
            font-size: 10px;
            font-weight: 800;
            cursor: pointer;
            border: 1px solid #fecdd3;
            background-color: #ffe4e6;
            color: #9f1239;
            line-height: 1.2;
            transition: all 0.15s ease;
        }
        .excel-reset-btn:hover {
            background-color: #fecdd3;
            border-color: #fda4af;
            color: #881337;
        }
        .excel-filter-list {
            max-height: 170px;
            overflow-y: auto;
            border: 1.5px solid #e2e8f0;
            background-color: #f8fafc;
            border-radius: 6px;
            padding: 4px;
            font-size: 11px;
        }
        .excel-filter-item {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 4px 6px;
            border-radius: 4px;
            cursor: pointer;
            user-select: none;
            font-weight: 600;
            color: #1e293b;
        }
        .excel-filter-item:hover {
            background-color: #e2e8f0;
        }
        .excel-filter-item input[type="checkbox"] {
            cursor: pointer;
            width: 14px;
            height: 14px;
            border-radius: 3px;
            accent-color: #2563eb;
        }
        .excel-filter-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 10px;
            padding-top: 8px;
            border-top: 1.5px solid #f1f5f9;
        }
        .excel-btn-close {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 5px 12px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 800;
            cursor: pointer;
            border: 1.5px solid #cbd5e1;
            background-color: #f1f5f9;
            color: #334155;
            transition: all 0.15s ease;
        }
        .excel-btn-close:hover {
            background-color: #e2e8f0;
            border-color: #94a3b8;
            color: #0f172a;
        }
        .excel-btn-apply {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            justify-content: center;
            padding: 5px 14px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 800;
            cursor: pointer;
            border: 1.5px solid #1d4ed8;
            background-color: #2563eb;
            color: #ffffff;
            box-shadow: 0 1px 2px rgba(37, 99, 235, 0.4);
            transition: all 0.15s ease;
        }
        .excel-btn-apply:hover {
            background-color: #1d4ed8;
            border-color: #1e40af;
        }

        /* ── Screen Utility: Hide Print Banner ── */
        .print-only {
            display: none !important;
        }

        /* ── Optimalisasi Cetak Dokumen A4 Landscape (1:1 Bersih Tanpa Sidebar/Navbar) ── */
        @media print {
            @page {
                size: A4 landscape;
                margin: 8mm 10mm 8mm 10mm;
            }

            /* Sembunyikan elemen shell navigasi, sidebar, header web, dan tombol aksi */
            #sidebar,
            #sidebar-overlay,
            header,
            .navbar,
            nav,
            #main-content-wrapper > header,
            .no-print,
            button,
            .excel-filter-dropdown,
            #filterControlsToolbar,
            #overviewDefectDropdownContainer,
            #partOverviewPartDropdownContainer,
            .th-filter-btn,
            #overviewAuditPagination,
            #partOverviewAuditPagination {
                display: none !important;
            }

            /* Tampilkan elemen khusus dokumen cetak */
            .print-only {
                display: block !important;
            }

            /* Reset halaman ke kanvas putih cetak */
            html, body {
                background: #ffffff !important;
                color: #0f172a !important;
                font-size: 9pt !important;
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
                height: auto !important;
                min-height: auto !important;
                overflow: visible !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            #main-content-wrapper {
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
                background: #ffffff !important;
            }

            #defect-body {
                padding: 0 !important;
                margin: 0 !important;
                overflow: visible !important;
            }

            /* Pastikan kontainer tab aktif terbentang 100% */
            #viewContainerOverviewMode,
            #viewContainerPartOverviewMode {
                width: 100% !important;
                page-break-inside: auto !important;
            }

            /* Pertahankan layout kartu dan tabel */
            .print-card-panel {
                border: 1px solid #cbd5e1 !important;
                box-shadow: none !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }

            table {
                width: 100% !important;
                border-collapse: collapse !important;
            }

            th, td {
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }

            /* Pastikan kanvas grafik tercetak tajam */
            canvas {
                max-width: 100% !important;
                height: auto !important;
            }
        }
        </style>

        <!-- ── 1. FILTER CONTROLS TOOLBAR (Stable & Non-Wrapping 2-Row Layout) ───────────── -->
        <div id="filterControlsToolbar" style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:12px 16px;box-shadow:0 1px 3px rgba(0,0,0,0.03);display:flex;flex-direction:column;gap:12px;">
            
            <!-- ROW 1: Perspective Tabs (Left) & Primary Action Buttons (Right) -->
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                <!-- Left: Mode Switcher -->
                <div style="display:flex;align-items:center;gap:6px;">
                    <div id="groupModeSwitcher" style="display:inline-flex;align-items:center;gap:3px;background:#f1f5f9;padding:3.5px;border-radius:9px;border:1px solid #e2e8f0;" title="Pilih Perspektif Analisis">
                        <button type="button" id="btnModeOverview" onclick="switchAnalysisMode('overview')"
                                style="padding:6px 14px;border-radius:6px;font-size:11.5px;font-weight:800;border:none;cursor:pointer;transition:all 0.2s;background:#0f172a;color:#ffffff;box-shadow:0 1px 3px rgba(15,23,42,0.25);">
                            🎯 Analisis &amp; Tren Defect
                        </button>
                        <button type="button" id="btnModePartOverview" onclick="switchAnalysisMode('part_overview')"
                                style="padding:6px 14px;border-radius:6px;font-size:11.5px;font-weight:800;border:none;cursor:pointer;transition:all 0.2s;background:transparent;color:#64748b;">
                            📦 Analisis &amp; Tren Part
                        </button>
                    </div>
                </div>

                <!-- Right: Action Buttons (Export Excel, Print, Reset) -->
                <div style="display:flex;align-items:center;gap:8px;">
                    <button type="button" onclick="exportDefectExcel()" 
                            style="display:inline-flex;align-items:center;gap:6px;padding:6px 14px;background:#059669;color:#ffffff;font-size:11px;font-weight:700;border-radius:8px;border:none;cursor:pointer;box-shadow:0 1px 3px rgba(5,150,105,0.25);transition:all 0.2s;"
                            onmouseover="this.style.background='#047857'" onmouseout="this.style.background='#059669'"
                            title="Ekspor data analisis dan riwayat temuan ke Excel (.xlsx)">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        Export Excel
                    </button>
                    <button type="button" onclick="printAnalysisReport()" 
                            style="display:inline-flex;align-items:center;gap:6px;padding:6px 14px;background:#1e293b;color:#ffffff;font-size:11px;font-weight:700;border-radius:8px;border:none;cursor:pointer;box-shadow:0 1px 3px rgba(0,0,0,0.15);transition:all 0.2s;"
                            onmouseover="this.style.background='#0f172a'" onmouseout="this.style.background='#1e293b'"
                            title="Cetak laporan analisis 1:1 sesuai tab yang sedang aktif">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                        </svg>
                        Cetak Laporan
                    </button>
                    <button type="button" onclick="resetAllFilters()" 
                            style="display:inline-flex;align-items:center;gap:4px;padding:6px 12px;background:#fff1f2;color:#e11d48;border:1px solid #fecdd3;border-radius:8px;font-size:11px;font-weight:700;cursor:pointer;transition:all 0.2s;"
                            title="Kembalikan semua filter ke pengaturan standar">
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        Reset
                    </button>
                </div>
            </div>

            <!-- ROW 2: Date Range Presets + Pickers (Left) & Customer + Satuan (Right) -->
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;border-top:1px solid #f1f5f9;padding-top:10px;">
                <!-- Left: Preset Date Pills & Dynamic Sub-Filters (Extremely Spacious) -->
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;flex:1;min-width:0;">
                    <div id="groupPresetPills" style="display:inline-flex;align-items:center;gap:2px;background:#f1f5f9;padding:3.5px;border-radius:9px;border:1px solid #e2e8f0;flex-shrink:0;">
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
                    <div id="containerSubFilter" style="display:none;align-items:center;gap:6px;background:#f8fafc;padding:3.5px 8px;border-radius:8px;border:1px solid #cbd5e1;flex-shrink:0;">
                        
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
                </div>

                <!-- Right: Customer Filter & Satuan Toggle -->
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;flex-shrink:0;">
                    <!-- Customer Selector -->
                    <div style="display:flex;align-items:center;gap:5px;">
                        <span style="font-size:11px;font-weight:700;color:#475569;">Customer:</span>
                        <select id="selectCustomer" onchange="onCustomerChange(this.value)" class="form-input text-xs" style="padding:5px 9px;border-radius:7px;font-weight:600;min-width:150px;border:1px solid #cbd5e1;background:#ffffff;">
                            <option value="">-- Semua Customer --</option>
                            <?php foreach ($customerOptions as $cOpt): ?>
                                <option value="<?= htmlspecialchars($cOpt) ?>" <?= $selectedCustomer === $cOpt ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cOpt) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
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
                </div>
            </div>
        </div>

        <!-- ── KOP RESMI LAPORAN CETAK (PRINT-ONLY HEADER BANNER) ────────────────── -->
        <div id="printOfficialReportHeader" class="print-only" style="margin-bottom:14px;border-bottom:2px solid #0f172a;padding-bottom:10px;">
            <div style="display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #cbd5e1;padding-bottom:8px;margin-bottom:8px;">
                <div style="display:flex;align-items:center;gap:12px;">
                    <div style="width:36px;height:36px;background:#2563eb;color:#ffffff;border-radius:8px;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:14px;">
                        ST
                    </div>
                    <div>
                        <h1 style="margin:0;font-size:16px;font-weight:900;color:#0f172a;letter-spacing:-0.3px;">
                            PT. SURYA TECHNOLOGY INDUSTRI
                        </h1>
                        <span style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;">
                            Outgoing Quality Control (OQC) Division &mdash; Official Report
                        </span>
                    </div>
                </div>
                <div style="text-align:right;">
                    <span style="display:block;font-size:9px;color:#64748b;font-weight:600;">Waktu Cetak:</span>
                    <span id="printHeaderTimestamp" style="font-size:11px;font-weight:800;color:#0f172a;">-</span>
                </div>
            </div>
            
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                <div>
                    <h2 id="printReportTitle" style="margin:0;font-size:14px;font-weight:900;color:#1e40af;text-transform:uppercase;">
                        LAPORAN ANALISIS &amp; TREN
                    </h2>
                    <div style="display:flex;align-items:center;gap:8px;margin-top:3px;">
                        <span style="font-size:10px;font-weight:700;color:#475569;">Target Analisis:</span>
                        <span id="printReportTargetBadge" style="display:inline-block;padding:2px 8px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:5px;font-size:11px;font-weight:800;">
                            -
                        </span>
                    </div>
                </div>

                <div style="display:flex;align-items:center;gap:16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:4px 10px;font-size:10px;">
                    <div>
                        <span style="color:#64748b;font-weight:600;">Periode:</span>
                        <strong id="printReportPeriod" style="color:#0f172a;margin-left:3px;">-</strong>
                    </div>
                    <div>
                        <span style="color:#64748b;font-weight:600;">Customer:</span>
                        <strong id="printReportCustomer" style="color:#0f172a;margin-left:3px;">Semua</strong>
                    </div>
                    <div>
                        <span style="color:#64748b;font-weight:600;">Satuan:</span>
                        <strong id="printReportUnit" style="color:#0f172a;margin-left:3px;">PCS</strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── 2. VIEW MODE OVERVIEW: ANALISIS & TREN DEFECT (DEFAULT - FAST EXECUTIVE VIEW) ──── -->
        <div id="viewContainerOverviewMode" style="display:flex;flex-direction:column;gap:12px;">
            <!-- CARD 1: DEFECT SELECTOR TOOLBAR -->
            <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:12px 16px;box-shadow:0 1px 3px rgba(0,0,0,0.03);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <span style="font-size:12px;font-weight:900;color:#0f172a;text-transform:uppercase;letter-spacing:0.5px;display:flex;align-items:center;gap:6px;">
                        <svg width="16" height="16" fill="none" stroke="#2563eb" stroke-width="2.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z" />
                        </svg>
                        Defect Terpilih:
                    </span>
                    <div id="overviewSelectedBadge" style="display:inline-flex;align-items:center;gap:6px;padding:3.5px 10px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:7px;font-size:12px;font-weight:800;">
                        <span id="lblOverviewDefectName">Memuat...</span>
                    </div>
                </div>

                <!-- Executive Styled Defect Custom Dropdown with Colored Rank Badges -->
                <div style="display:flex;align-items:center;gap:8px;position:relative;">
                    <span style="font-size:11px;font-weight:800;color:#475569;">Peringkat &amp; Daftar Defect:</span>
                    <div style="position:relative;" id="overviewDefectDropdownContainer">
                        <button type="button" id="btnOverviewDefectDropdownToggle" onclick="toggleOverviewDefectMenu(event)"
                                style="display:inline-flex;align-items:center;justify-content:space-between;gap:12px;padding:6px 14px;border-radius:9px;font-size:12px;font-weight:800;min-width:320px;background:#f0f7ff;color:#0f172a;border:1.5px solid #3b82f6;outline:none;cursor:pointer;box-shadow:0 1px 3px rgba(37,99,235,0.15);transition:all 0.15s;">
                            <div id="overviewDropdownSelectedDisplay" style="display:inline-flex;align-items:center;gap:8px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                <span style="font-size:11px;color:#64748b;">Memuat daftar defect...</span>
                            </div>
                            <svg width="14" height="14" fill="none" stroke="#2563eb" stroke-width="2.5" viewBox="0 0 24 24" style="flex-shrink:0;">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>

                        <!-- Floating Custom Menu -->
                        <div id="overviewDefectMenuDropdown" class="thin-scrollbar"
                             style="display:none;position:absolute;right:0;top:calc(100% + 6px);z-index:100;background:#ffffff;border:1.5px solid #cbd5e1;border-radius:10px;box-shadow:0 12px 28px -4px rgba(15,23,42,0.18),0 4px 10px -2px rgba(15,23,42,0.08);width:360px;max-height:280px;overflow-y:auto;padding:5px;">
                            <!-- Items populated dynamically via renderOverviewRankPills -->
                        </div>
                    </div>
                </div>
            </div>

            <!-- ROW 2: DUA GRAFIK BERDAMPINGAN (TREN KEJADIAN & DISTRIBUSI PART) -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(360px, 1fr));gap:12px;">
                <!-- KIRI: GRAFIK TREN KEJADIAN DEFECT -->
                <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:14px;box-shadow:0 1px 3px rgba(0,0,0,0.03);display:flex;flex-direction:column;gap:10px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                        <div>
                            <h3 style="margin:0;font-size:13px;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                                <svg width="15" height="15" fill="none" stroke="#2563eb" stroke-width="2.5" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                                </svg>
                                <span>Tren Kejadian Defect</span>
                                <span class="overviewCardDefectBadge" style="display:inline-flex;align-items:center;padding:1.5px 7px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:5px;font-size:11px;font-weight:800;">-</span>
                            </h3>
                            <p id="overviewTrendIntervalDesc" style="margin:1px 0 0 21px;font-size:10px;color:#64748b;">
                                Fluktuasi temuan defect sepanjang rentang waktu terpilih.
                            </p>
                        </div>
                        <div style="display:flex;align-items:center;gap:6px;">
                            <button type="button" id="btnOverviewThreshold" onclick="openThresholdModal('overview')"
                                    style="display:inline-flex;align-items:center;gap:4px;padding:3px 8px;font-size:10px;font-weight:800;border:1px solid #fecaca;border-radius:5px;cursor:pointer;background:#fef2f2;color:#b91c1c;transition:all 0.15s;"
                                    title="Atur Batas Ambang / Target Maksimal untuk Tren Ini">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                                <span>Target: <strong id="lblOverviewThreshold" style="font-weight:900;">-</strong></span>
                            </button>
                            <!-- Toggle Interval Harian vs Mingguan (Khusus Rentang Bulanan) -->
                            <div id="overviewTrendIntervalGroup" style="display:none;align-items:center;background:#f1f5f9;padding:2px;border-radius:6px;border:1px solid #e2e8f0;">
                                <button type="button" id="btnOverviewIntervalDaily" onclick="switchOverviewInterval('daily')"
                                        style="padding:3px 8px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:#0f172a;color:#ffffff;box-shadow:0 1px 2px rgba(15,23,42,0.2);">
                                    Harian
                                </button>
                                <button type="button" id="btnOverviewIntervalWeekly" onclick="switchOverviewInterval('weekly')"
                                        style="padding:3px 8px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:transparent;color:#64748b;">
                                    Mingguan
                                </button>
                            </div>
                            <!-- Sub-Toggle Mode Mingguan 6 Hari Kerja (Cut-off Kalender vs Siklus Kerja Penuh) -->
                            <div id="overviewWeeklyModeGroup" style="display:none;align-items:center;background:#f1f5f9;padding:2px;border-radius:6px;border:1px solid #e2e8f0;">
                                <button type="button" id="btnOverviewWeeklyCutoff" onclick="switchOverviewWeeklyMode('cutoff')"
                                        style="padding:3px 8px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:#0f172a;color:#ffffff;box-shadow:0 1px 2px rgba(15,23,42,0.2);"
                                        title="Menghitung hari kerja Senin-Sabtu murni dalam batas kalender bulan ini">
                                    Cut-off Kalender
                                </button>
                                <button type="button" id="btnOverviewWeeklyFull" onclick="switchOverviewWeeklyMode('full_week')"
                                        style="padding:3px 8px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:transparent;color:#64748b;"
                                        title="Menarik awal minggu ke hari Senin terdekat di akhir bulan sebelumnya agar genap 6 hari kerja">
                                    Siklus Kerja Penuh
                                </button>
                            </div>
                            <div style="display:flex;align-items:center;background:#f1f5f9;padding:2px;border-radius:6px;border:1px solid #e2e8f0;">
                                <button type="button" id="btnOverviewTrendLine" onclick="switchOverviewTrendType('line')"
                                        style="padding:3px 8px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:#0f172a;color:#ffffff;box-shadow:0 1px 2px rgba(15,23,42,0.2);">
                                    Garis
                                </button>
                                <button type="button" id="btnOverviewTrendBar" onclick="switchOverviewTrendType('bar')"
                                        style="padding:3px 8px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:transparent;color:#64748b;">
                                    Batang
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Mini KPI Summary Strip -->
                    <div style="display:grid;grid-template-columns:repeat(3, 1fr);gap:8px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:8px 10px;">
                        <div>
                            <span style="font-size:9px;font-weight:800;color:#64748b;text-transform:uppercase;display:block;">Total Temuan:</span>
                            <span id="overviewKpiTotal" style="font-size:15px;font-weight:900;color:#0f172a;">-</span>
                            <span id="overviewKpiUnit" style="font-size:10px;font-weight:700;color:#64748b;margin-left:2px;">PCS</span>
                        </div>
                        <div>
                            <span style="font-size:9px;font-weight:800;color:#64748b;text-transform:uppercase;display:block;">Puncak Kasus:</span>
                            <span id="overviewKpiPeak" style="font-size:12px;font-weight:800;color:#dc2626;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;">-</span>
                        </div>
                        <div>
                            <span style="font-size:9px;font-weight:800;color:#64748b;text-transform:uppercase;display:block;">Rata-Rata:</span>
                            <span id="overviewKpiAvg" style="font-size:15px;font-weight:900;color:#0f172a;">-</span>
                            <span id="overviewKpiAvgUnit" style="font-size:10px;font-weight:700;color:#64748b;margin-left:2px;">PCS</span>
                        </div>
                    </div>

                    <!-- Canvas Tren -->
                    <div style="position:relative;height:210px;width:100%;">
                        <canvas id="chartOverviewTrend"></canvas>
                    </div>
                </div>

                <!-- KANAN: GRAFIK DISTRIBUSI PART TERDAMPAK (TOGGLE DONUT / BAR) -->
                <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:14px;box-shadow:0 1px 3px rgba(0,0,0,0.03);display:flex;flex-direction:column;gap:10px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                        <div>
                            <h3 style="margin:0;font-size:13px;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                                <svg width="15" height="15" fill="none" stroke="#059669" stroke-width="2.5" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z" />
                                </svg>
                                <span>Sebaran Part Terdampak - </span>
                                <span class="overviewCardDefectBadge" style="display:inline-flex;align-items:center;padding:1.5px 7px;background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;border-radius:5px;font-size:11px;font-weight:800;">-</span>
                            </h3>
                            <p style="margin:1px 0 0 21px;font-size:10px;color:#64748b;">
                                Komposisi part code penyumbang cacat ini.
                            </p>
                        </div>
                        <div style="display:flex;align-items:center;gap:4px;">
                            <div style="display:flex;align-items:center;background:#f1f5f9;padding:2px;border-radius:6px;border:1px solid #e2e8f0;flex-wrap:wrap;gap:2px;">
                                <button type="button" id="btnOverviewPartDonut" onclick="switchOverviewPartChartType('table')"
                                        style="padding:3px 9px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:#0f172a;color:#ffffff;box-shadow:0 1px 2px rgba(15,23,42,0.2);">
                                    Tabel
                                </button>
                                <button type="button" id="btnOverviewPartTotal" onclick="switchOverviewPartChartType('bar_total')"
                                        style="padding:3px 9px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:transparent;color:#64748b;">
                                    Total
                                </button>
                                <button type="button" id="btnOverviewPartLine" onclick="switchOverviewPartChartType('line_trend')"
                                        style="padding:3px 9px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:transparent;color:#64748b;"
                                        title="Grafik tren garis rentang tanggal per part code">
                                    Tren Garis
                                </button>
                                <button type="button" id="btnOverviewPartBar" onclick="switchOverviewPartChartType('bar_trend')"
                                        style="padding:3px 9px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:transparent;color:#64748b;"
                                        title="Grafik tren batang bertingkat rentang tanggal per part code">
                                    Tren Batang
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Container Grafik Part (Table Layout OR Full Width Timeline/Bar) -->
                    <div id="overviewPartChartContainer" style="min-height:245px;display:flex;align-items:stretch;">
                        <!-- Mode 1: Table Layout (Pengganti Donut: Tabel Sebaran Ramping Responsif) -->
                        <div id="overviewPartDonutWrapper" style="display:flex;flex-direction:column;width:100%;max-height:245px;overflow-y:auto;" class="thin-scrollbar">
                            <table style="width:100%;border-collapse:collapse;font-size:11px;text-align:left;">
                                <thead style="position:sticky;top:0;background:#f8fafc;z-index:5;border-bottom:1.5px solid #e2e8f0;box-shadow:0 1px 2px rgba(0,0,0,0.02);">
                                    <tr style="color:#64748b;font-weight:800;text-transform:uppercase;font-size:9.5px;letter-spacing:0.4px;">
                                        <th style="padding:6px 8px;width:32px;text-align:center;">No</th>
                                        <th style="padding:6px 10px;">Part Code &amp; Part Name</th>
                                        <th style="padding:6px 10px;text-align:right;width:110px;">Total Temuan</th>
                                        <th style="padding:6px 10px;text-align:center;width:130px;">Batas Target</th>
                                    </tr>
                                </thead>
                                <tbody id="overviewPartTableBody">
                                    <tr>
                                        <td colspan="4" style="padding:20px;text-align:center;color:#94a3b8;font-size:11px;">Memuat sebaran part...</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Mode 2: Full Width Canvas untuk Total Bar / Tren Garis / Tren Batang -->
                        <div id="overviewPartTimelineWrapper" style="display:none;position:relative;height:245px;width:100%;">
                            <canvas id="chartOverviewParts"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ROW 3: TABEL RIWAYAT TEMUAN INSPEKSI TERPERINCI (FIT-TO-SCREEN INTERNAL SCROLL) -->
            <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,0.03);overflow:hidden;display:flex;flex-direction:column;">
                <div style="padding:10px 16px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;background:#f8fafc;">
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <svg width="16" height="16" fill="none" stroke="#2563eb" stroke-width="2.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                        </svg>
                        <h3 style="margin:0;font-size:13px;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                            <span>Riwayat Temuan Cacat Inspeksi Terperinci</span>
                            <span class="overviewCardDefectBadge" style="display:inline-flex;align-items:center;padding:1.5px 7px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:5px;font-size:11px;font-weight:800;">-</span>
                        </h3>
                        <span id="overviewTableCountBadge" style="padding:2px 7px;border-radius:5px;font-size:10px;font-weight:800;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;">
                            0 Temuan
                        </span>
                    </div>

                    <div style="display:flex;align-items:center;gap:8px;">
                        <div style="position:relative;">
                            <input type="text" id="overviewTableSearchInput" placeholder="Cari part / model / customer / lot..."
                                   style="padding:4px 8px 4px 26px;font-size:11px;border-radius:6px;border:1px solid #cbd5e1;outline:none;width:220px;background:#ffffff;">
                            <svg width="12" height="12" fill="none" stroke="#94a3b8" stroke-width="2" viewBox="0 0 24 24"
                                 style="position:absolute;left:8px;top:50%;transform:translateY(-50%);pointer-events:none;">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                        </div>
                    </div>
                </div>

                <!-- Indikator Filter Excel Aktif Bar -->
                <div id="overview-excel-filter-active-bar" style="display: none; background: #eff6ff; border-bottom: 1.5px solid #bfdbfe; padding: 7px 16px; align-items: center; justify-content: space-between; font-size: 11px;">
                    <div style="display: flex; align-items: center; gap: 6px; color: #1e40af; font-weight: 700;">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                        <span id="overview-excel-filter-summary-text">Menyaring data tabel...</span>
                    </div>
                    <button type="button" onclick="resetAllOverviewExcelFilters()" style="background: #ffffff; border: 1px solid #bfdbfe; color: #1d4ed8; font-weight: 800; font-size: 10.5px; padding: 2.5px 9px; border-radius: 5px; cursor: pointer; transition: all 0.15s;" onmouseover="this.style.background='#dbeafe'" onmouseout="this.style.background='#ffffff'">
                        Reset Semua Filter
                    </button>
                </div>

                <!-- Table Body Container (Full view, horizontal scrollable if needed) -->
                <div style="overflow-x:auto;">
                    <table style="width:100%;border-collapse:collapse;text-align:left;font-size:11px;">
                        <thead style="position:sticky;top:0;background:#f8fafc;z-index:10;box-shadow:0 1px 2px rgba(0,0,0,0.05);">
                            <tr style="color:#475569;border-bottom:1px solid #e2e8f0;font-weight:800;text-transform:uppercase;font-size:10px;letter-spacing:0.5px;">
                                <th style="padding:8px 12px;text-align:center;width:40px;">No</th>

                                <!-- Tanggal & Jam -->
                                <th style="padding:8px 12px;width:130px;position:relative;">
                                    <div class="th-filter-wrapper">
                                        <span>Tanggal &amp; Jam</span>
                                        <button type="button" class="th-filter-btn" data-col="date" title="Filter Tanggal" onclick="toggleOverviewExcelFilter(event, 'date')">
                                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                        </button>
                                    </div>
                                    <div id="overview-filter-dropdown-date" class="excel-filter-dropdown align-left">
                                        <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                            <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER TANGGAL</span>
                                            <button type="button" class="excel-reset-btn" onclick="resetSingleOverviewColFilter('date')" title="Reset filter">
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                                <span>Reset</span>
                                            </button>
                                        </div>
                                        <input type="text" class="excel-filter-search" placeholder="Cari tanggal..." oninput="onSearchOverviewFilterOptions('date', this.value)">
                                        <div class="flex items-center gap-1.5 mb-2">
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllOverviewFilter('date', true)">
                                                <svg width="11" height="11" class="text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                                <span>Pilih Semua</span>
                                            </button>
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllOverviewFilter('date', false)">
                                                <svg width="11" height="11" class="text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                <span>Hapus Pilihan</span>
                                            </button>
                                        </div>
                                        <div class="excel-filter-list" id="overview-filter-list-date"></div>
                                        <div class="excel-filter-actions">
                                            <span class="text-[10px] text-slate-500 font-bold filter-count-info"></span>
                                            <div class="flex items-center gap-2">
                                                <button type="button" class="excel-btn-close" onclick="closeAllOverviewExcelFilters()">Tutup</button>
                                                <button type="button" class="excel-btn-apply" onclick="applyOverviewExcelFilters()">
                                                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                                    <span>Terapkan</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </th>

                                <!-- Part Code & Part Name -->
                                <th style="padding:8px 12px;min-width:180px;position:relative;">
                                    <div class="th-filter-wrapper">
                                        <span>Part Code &amp; Part Name</span>
                                        <button type="button" class="th-filter-btn" data-col="part" title="Filter Part" onclick="toggleOverviewExcelFilter(event, 'part')">
                                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                        </button>
                                    </div>
                                    <div id="overview-filter-dropdown-part" class="excel-filter-dropdown align-left" style="width:270px;">
                                        <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                            <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER PART</span>
                                            <button type="button" class="excel-reset-btn" onclick="resetSingleOverviewColFilter('part')" title="Reset filter">
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                                <span>Reset</span>
                                            </button>
                                        </div>
                                        <input type="text" class="excel-filter-search" placeholder="Cari part..." oninput="onSearchOverviewFilterOptions('part', this.value)">
                                        <div class="flex items-center gap-1.5 mb-2">
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllOverviewFilter('part', true)">
                                                <svg width="11" height="11" class="text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                                <span>Pilih Semua</span>
                                            </button>
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllOverviewFilter('part', false)">
                                                <svg width="11" height="11" class="text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                <span>Hapus Pilihan</span>
                                            </button>
                                        </div>
                                        <div class="excel-filter-list" id="overview-filter-list-part"></div>
                                        <div class="excel-filter-actions">
                                            <span class="text-[10px] text-slate-500 font-bold filter-count-info"></span>
                                            <div class="flex items-center gap-2">
                                                <button type="button" class="excel-btn-close" onclick="closeAllOverviewExcelFilters()">Tutup</button>
                                                <button type="button" class="excel-btn-apply" onclick="applyOverviewExcelFilters()">
                                                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                                    <span>Terapkan</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </th>

                                <!-- Model Produk -->
                                <th style="padding:8px 12px;min-width:110px;position:relative;">
                                    <div class="th-filter-wrapper">
                                        <span>Model Produk</span>
                                        <button type="button" class="th-filter-btn" data-col="model" title="Filter Model" onclick="toggleOverviewExcelFilter(event, 'model')">
                                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                        </button>
                                    </div>
                                    <div id="overview-filter-dropdown-model" class="excel-filter-dropdown align-left">
                                        <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                            <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER MODEL</span>
                                            <button type="button" class="excel-reset-btn" onclick="resetSingleOverviewColFilter('model')" title="Reset filter">
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                                <span>Reset</span>
                                            </button>
                                        </div>
                                        <input type="text" class="excel-filter-search" placeholder="Cari model..." oninput="onSearchOverviewFilterOptions('model', this.value)">
                                        <div class="flex items-center gap-1.5 mb-2">
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllOverviewFilter('model', true)">
                                                <svg width="11" height="11" class="text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                                <span>Pilih Semua</span>
                                            </button>
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllOverviewFilter('model', false)">
                                                <svg width="11" height="11" class="text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                <span>Hapus Pilihan</span>
                                            </button>
                                        </div>
                                        <div class="excel-filter-list" id="overview-filter-list-model"></div>
                                        <div class="excel-filter-actions">
                                            <span class="text-[10px] text-slate-500 font-bold filter-count-info"></span>
                                            <div class="flex items-center gap-2">
                                                <button type="button" class="excel-btn-close" onclick="closeAllOverviewExcelFilters()">Tutup</button>
                                                <button type="button" class="excel-btn-apply" onclick="applyOverviewExcelFilters()">
                                                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                                    <span>Terapkan</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </th>

                                <!-- Model Produk -->

                                <!-- Inspeksi -->
                                <th style="padding:8px 12px;text-align:center;width:95px;position:relative;">
                                    <div class="th-filter-wrapper">
                                        <span>Inspeksi</span>
                                        <button type="button" class="th-filter-btn" data-col="type" title="Filter Jenis Inspeksi" onclick="toggleOverviewExcelFilter(event, 'type')">
                                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                        </button>
                                    </div>
                                    <div id="overview-filter-dropdown-type" class="excel-filter-dropdown align-left">
                                        <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                            <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER INSPEKSI</span>
                                            <button type="button" class="excel-reset-btn" onclick="resetSingleOverviewColFilter('type')" title="Reset filter">
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                                <span>Reset</span>
                                            </button>
                                        </div>
                                        <div class="flex items-center gap-1.5 mb-2">
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllOverviewFilter('type', true)">
                                                <svg width="11" height="11" class="text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                                <span>Pilih Semua</span>
                                            </button>
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllOverviewFilter('type', false)">
                                                <svg width="11" height="11" class="text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                <span>Hapus Pilihan</span>
                                            </button>
                                        </div>
                                        <div class="excel-filter-list" id="overview-filter-list-type"></div>
                                        <div class="excel-filter-actions">
                                            <span class="text-[10px] text-slate-500 font-bold filter-count-info"></span>
                                            <div class="flex items-center gap-2">
                                                <button type="button" class="excel-btn-close" onclick="closeAllOverviewExcelFilters()">Tutup</button>
                                                <button type="button" class="excel-btn-apply" onclick="applyOverviewExcelFilters()">
                                                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                                    <span>Terapkan</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </th>

                                <!-- No Kanban / Customer -->
                                <th style="padding:8px 12px;min-width:140px;position:relative;">
                                    <div class="th-filter-wrapper">
                                        <span>No Kanban / Customer</span>
                                        <button type="button" class="th-filter-btn" data-col="customer" title="Filter Customer" onclick="toggleOverviewExcelFilter(event, 'customer')">
                                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                        </button>
                                    </div>
                                    <div id="overview-filter-dropdown-customer" class="excel-filter-dropdown align-right" style="width:260px;">
                                        <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                            <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER CUSTOMER</span>
                                            <button type="button" class="excel-reset-btn" onclick="resetSingleOverviewColFilter('customer')" title="Reset filter">
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                                <span>Reset</span>
                                            </button>
                                        </div>
                                        <input type="text" class="excel-filter-search" placeholder="Cari customer..." oninput="onSearchOverviewFilterOptions('customer', this.value)">
                                        <div class="flex items-center gap-1.5 mb-2">
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllOverviewFilter('customer', true)">
                                                <svg width="11" height="11" class="text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                                <span>Pilih Semua</span>
                                            </button>
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllOverviewFilter('customer', false)">
                                                <svg width="11" height="11" class="text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                <span>Hapus Pilihan</span>
                                            </button>
                                        </div>
                                        <div class="excel-filter-list" id="overview-filter-list-customer"></div>
                                        <div class="excel-filter-actions">
                                            <span class="text-[10px] text-slate-500 font-bold filter-count-info"></span>
                                            <div class="flex items-center gap-2">
                                                <button type="button" class="excel-btn-close" onclick="closeAllOverviewExcelFilters()">Tutup</button>
                                                <button type="button" class="excel-btn-apply" onclick="applyOverviewExcelFilters()">
                                                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                                    <span>Terapkan</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </th>

                                <th style="padding:8px 12px;min-width:100px;">Lot No / Ref</th>

                                <!-- QC Inspector -->
                                <th style="padding:8px 12px;min-width:110px;position:relative;">
                                    <div class="th-filter-wrapper">
                                        <span>QC Inspector</span>
                                        <button type="button" class="th-filter-btn" data-col="inspector" title="Filter Inspector" onclick="toggleOverviewExcelFilter(event, 'inspector')">
                                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                        </button>
                                    </div>
                                    <div id="overview-filter-dropdown-inspector" class="excel-filter-dropdown align-right">
                                        <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                            <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER INSPECTOR</span>
                                            <button type="button" class="excel-reset-btn" onclick="resetSingleOverviewColFilter('inspector')" title="Reset filter">
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                                <span>Reset</span>
                                            </button>
                                        </div>
                                        <input type="text" class="excel-filter-search" placeholder="Cari inspector..." oninput="onSearchOverviewFilterOptions('inspector', this.value)">
                                        <div class="flex items-center gap-1.5 mb-2">
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllOverviewFilter('inspector', true)">
                                                <svg width="11" height="11" class="text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                                <span>Pilih Semua</span>
                                            </button>
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllOverviewFilter('inspector', false)">
                                                <svg width="11" height="11" class="text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                <span>Hapus Pilihan</span>
                                            </button>
                                        </div>
                                        <div class="excel-filter-list" id="overview-filter-list-inspector"></div>
                                        <div class="excel-filter-actions">
                                            <span class="text-[10px] text-slate-500 font-bold filter-count-info"></span>
                                            <div class="flex items-center gap-2">
                                                <button type="button" class="excel-btn-close" onclick="closeAllOverviewExcelFilters()">Tutup</button>
                                                <button type="button" class="excel-btn-apply" onclick="applyOverviewExcelFilters()">
                                                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                                    <span>Terapkan</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </th>

                                <th style="padding:8px 12px;text-align:right;width:100px;">Jumlah Temuan</th>
                            </tr>
                        </thead>
                        <tbody id="overviewHistoryTableBody">
                            <tr>
                                <td colspan="10" style="padding:24px;text-align:center;color:#94a3b8;font-weight:600;">
                                    Memuat catatan riwayat temuan inspeksi...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div style="padding:8px 16px;background:#f8fafc;border-top:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;font-size:11px;color:#64748b;">
                    <span id="overviewTableFooterNote">
                        Data riwayat temuan inspeksi terperinci untuk defect terpilih.
                    </span>
                    <span style="color:#94a3b8;font-size:10px;">
                        Urutan inspeksi terbaru di atas.
                    </span>
                </div>
            </div>
        </div>

        <!-- ── 2B. VIEW MODE PART OVERVIEW: ANALISIS & TREN PART (FAST EXECUTIVE VIEW) ──── -->
        <div id="viewContainerPartOverviewMode" style="display:none;flex-direction:column;gap:12px;">
            <!-- CARD 1: PART SELECTOR TOOLBAR -->
            <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:12px 16px;box-shadow:0 1px 3px rgba(0,0,0,0.03);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <span style="font-size:12px;font-weight:900;color:#0f172a;text-transform:uppercase;letter-spacing:0.5px;display:flex;align-items:center;gap:6px;">
                        <svg width="16" height="16" fill="none" stroke="#2563eb" stroke-width="2.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                        </svg>
                        Part Terpilih:
                    </span>
                    <div id="partOverviewSelectedBadge" style="display:inline-flex;align-items:center;gap:6px;padding:3.5px 10px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:7px;font-size:12px;font-weight:800;">
                        <span id="lblPartOverviewPartName">Memuat...</span>
                    </div>
                </div>

                <!-- Executive Styled Part Custom Dropdown with Colored Rank Badges -->
                <div style="display:flex;align-items:center;gap:8px;position:relative;">
                    <span style="font-size:11px;font-weight:800;color:#475569;">Peringkat &amp; Daftar Part:</span>
                    <div style="position:relative;" id="partOverviewPartDropdownContainer">
                        <button type="button" id="btnPartOverviewDropdownToggle" onclick="togglePartOverviewMenu(event)"
                                style="display:inline-flex;align-items:center;justify-content:space-between;gap:12px;padding:6px 14px;border-radius:9px;font-size:12px;font-weight:800;min-width:320px;background:#f0f7ff;color:#0f172a;border:1.5px solid #3b82f6;outline:none;cursor:pointer;box-shadow:0 1px 3px rgba(37,99,235,0.15);transition:all 0.15s;">
                            <div id="partOverviewDropdownSelectedDisplay" style="display:inline-flex;align-items:center;gap:8px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                <span style="font-size:11px;color:#64748b;">Memuat daftar part...</span>
                            </div>
                            <svg width="14" height="14" fill="none" stroke="#2563eb" stroke-width="2.5" viewBox="0 0 24 24" style="flex-shrink:0;">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>

                        <!-- Floating Custom Menu -->
                        <div id="partOverviewMenuDropdown" class="thin-scrollbar"
                             style="display:none;position:absolute;right:0;top:calc(100% + 6px);z-index:100;background:#ffffff;border:1.5px solid #cbd5e1;border-radius:10px;box-shadow:0 12px 28px -4px rgba(15,23,42,0.18),0 4px 10px -2px rgba(15,23,42,0.08);width:380px;max-height:280px;overflow-y:auto;padding:5px;">
                            <!-- Items populated dynamically via renderPartOverviewRankPills -->
                        </div>
                    </div>
                </div>
            </div>

            <!-- ROW 2: DUA GRAFIK BERDAMPINGAN (TREN KEJADIAN PART & SEBARAN DEFECT TERDAMPAK) -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(360px, 1fr));gap:12px;">
                <!-- KIRI: GRAFIK TREN KEJADIAN PART -->
                <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:14px;box-shadow:0 1px 3px rgba(0,0,0,0.03);display:flex;flex-direction:column;gap:10px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                        <div>
                            <h3 style="margin:0;font-size:13px;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                                <svg width="15" height="15" fill="none" stroke="#2563eb" stroke-width="2.5" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                                </svg>
                                <span>Tren Kejadian Part</span>
                                <span class="partOverviewCardPartBadge" style="display:inline-flex;align-items:center;padding:1.5px 7px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:5px;font-size:11px;font-weight:800;font-family:monospace;">-</span>
                            </h3>
                            <p id="partOverviewTrendIntervalDesc" style="margin:1px 0 0 21px;font-size:10px;color:#64748b;">
                                Fluktuasi temuan cacat pada part sepanjang rentang waktu terpilih.
                            </p>
                        </div>
                        <div style="display:flex;align-items:center;gap:6px;">
                            <button type="button" id="btnPartOverviewThreshold" onclick="openThresholdModal('part_overview')"
                                    style="display:inline-flex;align-items:center;gap:4px;padding:3px 8px;font-size:10px;font-weight:800;border:1px solid #fecaca;border-radius:5px;cursor:pointer;background:#fef2f2;color:#b91c1c;transition:all 0.15s;"
                                    title="Atur Batas Ambang / Target Maksimal untuk Tren Part Ini">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                                <span>Target: <strong id="lblPartOverviewThreshold" style="font-weight:900;">-</strong></span>
                            </button>
                            <!-- Toggle Interval Harian vs Mingguan (Khusus Rentang Bulanan) -->
                            <div id="partOverviewTrendIntervalGroup" style="display:none;align-items:center;background:#f1f5f9;padding:2px;border-radius:6px;border:1px solid #e2e8f0;">
                                <button type="button" id="btnPartOverviewIntervalDaily" onclick="switchPartOverviewInterval('daily')"
                                        style="padding:3px 8px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:#0f172a;color:#ffffff;box-shadow:0 1px 2px rgba(15,23,42,0.2);">
                                    Harian
                                </button>
                                <button type="button" id="btnPartOverviewIntervalWeekly" onclick="switchPartOverviewInterval('weekly')"
                                        style="padding:3px 8px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:transparent;color:#64748b;">
                                    Mingguan
                                </button>
                            </div>
                            <!-- Sub-Toggle Mode Mingguan 6 Hari Kerja (Cut-off Kalender vs Siklus Kerja Penuh) -->
                            <div id="partOverviewWeeklyModeGroup" style="display:none;align-items:center;background:#f1f5f9;padding:2px;border-radius:6px;border:1px solid #e2e8f0;">
                                <button type="button" id="btnPartOverviewWeeklyCutoff" onclick="switchPartOverviewWeeklyMode('cutoff')"
                                        style="padding:3px 8px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:#0f172a;color:#ffffff;box-shadow:0 1px 2px rgba(15,23,42,0.2);"
                                        title="Menghitung hari kerja Senin-Sabtu murni dalam batas kalender bulan ini">
                                    Cut-off Kalender
                                </button>
                                <button type="button" id="btnPartOverviewWeeklyFull" onclick="switchPartOverviewWeeklyMode('full_week')"
                                        style="padding:3px 8px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:transparent;color:#64748b;"
                                        title="Menarik awal minggu ke hari Senin terdekat di akhir bulan sebelumnya agar genap 6 hari kerja">
                                    Siklus Kerja Penuh
                                </button>
                            </div>
                            <div style="display:flex;align-items:center;background:#f1f5f9;padding:2px;border-radius:6px;border:1px solid #e2e8f0;">
                                <button type="button" id="btnPartOverviewTrendLine" onclick="switchPartOverviewTrendType('line')"
                                        style="padding:3px 8px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:#0f172a;color:#ffffff;box-shadow:0 1px 2px rgba(15,23,42,0.2);">
                                    Garis
                                </button>
                                <button type="button" id="btnPartOverviewTrendBar" onclick="switchPartOverviewTrendType('bar')"
                                        style="padding:3px 8px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:transparent;color:#64748b;">
                                    Batang
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Mini KPI Summary Strip -->
                    <div style="display:grid;grid-template-columns:repeat(3, 1fr);gap:8px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:8px 10px;">
                        <div>
                            <span style="font-size:9px;font-weight:800;color:#64748b;text-transform:uppercase;display:block;">Total Cacat Part:</span>
                            <span id="partOverviewKpiTotal" style="font-size:15px;font-weight:900;color:#0f172a;">-</span>
                            <span id="partOverviewKpiUnit" style="font-size:10px;font-weight:700;color:#64748b;margin-left:2px;">PCS</span>
                        </div>
                        <div>
                            <span style="font-size:9px;font-weight:800;color:#64748b;text-transform:uppercase;display:block;">Puncak Kasus:</span>
                            <span id="partOverviewKpiPeak" style="font-size:12px;font-weight:800;color:#dc2626;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;">-</span>
                        </div>
                        <div>
                            <span style="font-size:9px;font-weight:800;color:#64748b;text-transform:uppercase;display:block;">Rata-Rata:</span>
                            <span id="partOverviewKpiAvg" style="font-size:15px;font-weight:900;color:#0f172a;">-</span>
                            <span id="partOverviewKpiAvgUnit" style="font-size:10px;font-weight:700;color:#64748b;margin-left:2px;">PCS</span>
                        </div>
                    </div>

                    <!-- Canvas Tren -->
                    <div style="position:relative;height:210px;width:100%;">
                        <canvas id="chartPartOverviewTrend"></canvas>
                    </div>
                </div>

                <!-- KANAN: TABEL DISTRIBUSI DEFECT TERDAMPAK (TOGGLE TABEL / BAR / LINE / STACKED) -->
                <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:14px;box-shadow:0 1px 3px rgba(0,0,0,0.03);display:flex;flex-direction:column;gap:10px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                        <div>
                            <h3 style="margin:0;font-size:13px;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                                <svg width="15" height="15" fill="none" stroke="#059669" stroke-width="2.5" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z" />
                                </svg>
                                <span>Sebaran Jenis Defect</span>
                                <span class="partOverviewCardPartBadge" style="display:inline-flex;align-items:center;padding:1.5px 7px;background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;border-radius:5px;font-size:11px;font-weight:800;font-family:monospace;">-</span>
                            </h3>
                            <p style="margin:1px 0 0 21px;font-size:10px;color:#64748b;">
                                Komposisi defect yang paling sering terjadi pada part terpilih ini.
                            </p>
                        </div>
                        <div style="display:flex;align-items:center;gap:4px;">
                            <div style="display:flex;align-items:center;background:#f1f5f9;padding:2px;border-radius:6px;border:1px solid #e2e8f0;flex-wrap:wrap;gap:2px;">
                                <button type="button" id="btnPartOverviewDefectDonut" onclick="switchPartOverviewDefectChartType('table')"
                                        style="padding:3px 9px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:#0f172a;color:#ffffff;box-shadow:0 1px 2px rgba(15,23,42,0.2);">
                                    Tabel
                                </button>
                                <button type="button" id="btnPartOverviewDefectTotal" onclick="switchPartOverviewDefectChartType('bar_total')"
                                        style="padding:3px 9px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:transparent;color:#64748b;">
                                    Total
                                </button>
                                <button type="button" id="btnPartOverviewDefectLine" onclick="switchPartOverviewDefectChartType('line_trend')"
                                        style="padding:3px 9px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:transparent;color:#64748b;"
                                        title="Grafik tren garis rentang tanggal per jenis defect">
                                    Tren Garis
                                </button>
                                <button type="button" id="btnPartOverviewDefectBar" onclick="switchPartOverviewDefectChartType('bar_trend')"
                                        style="padding:3px 9px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:transparent;color:#64748b;"
                                        title="Grafik tren batang bertingkat rentang tanggal per jenis defect">
                                    Tren Batang
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Container Grafik Defect (Table Layout OR Full Width Timeline/Bar) -->
                    <div id="partOverviewDefectChartContainer" style="min-height:245px;display:flex;align-items:stretch;">
                        <!-- Mode 1: Table Layout (Pengganti Donut: Tabel Sebaran Ramping Responsif) -->
                        <div id="partOverviewDefectDonutWrapper" style="display:flex;flex-direction:column;width:100%;max-height:245px;overflow-y:auto;" class="thin-scrollbar">
                            <table style="width:100%;border-collapse:collapse;font-size:11px;text-align:left;">
                                <thead style="position:sticky;top:0;background:#f8fafc;z-index:5;border-bottom:1.5px solid #e2e8f0;box-shadow:0 1px 2px rgba(0,0,0,0.02);">
                                    <tr style="color:#64748b;font-weight:800;text-transform:uppercase;font-size:9.5px;letter-spacing:0.4px;">
                                        <th style="padding:6px 8px;width:32px;text-align:center;">No</th>
                                        <th style="padding:6px 10px;">Jenis Defect</th>
                                        <th style="padding:6px 10px;text-align:right;width:110px;">Total Temuan</th>
                                        <th style="padding:6px 10px;text-align:center;width:130px;">Batas Target</th>
                                    </tr>
                                </thead>
                                <tbody id="partOverviewDefectTableBody">
                                    <tr>
                                        <td colspan="4" style="padding:20px;text-align:center;color:#94a3b8;font-size:11px;">Memuat sebaran defect...</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Mode 2: Full Width Canvas untuk Total Bar / Tren Garis / Tren Batang -->
                        <div id="partOverviewDefectTimelineWrapper" style="display:none;position:relative;height:245px;width:100%;">
                            <canvas id="chartPartOverviewDefects"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ROW 3: TABEL RIWAYAT TEMUAN INSPEKSI TERPERINCI PART (FIT-TO-SCREEN INTERNAL SCROLL) -->
            <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,0.03);overflow:hidden;display:flex;flex-direction:column;">
                <div style="padding:10px 16px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;background:#f8fafc;">
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <svg width="16" height="16" fill="none" stroke="#2563eb" stroke-width="2.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                        </svg>
                        <h3 style="margin:0;font-size:13px;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                            <span>Riwayat Temuan Cacat Inspeksi Part Terperinci</span>
                            <span class="partOverviewCardPartBadge" style="display:inline-flex;align-items:center;padding:1.5px 7px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:5px;font-size:11px;font-weight:800;font-family:monospace;">-</span>
                        </h3>
                        <span id="partOverviewTableCountBadge" style="padding:2px 7px;border-radius:5px;font-size:10px;font-weight:800;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;">
                            0 Temuan
                        </span>
                    </div>

                    <div style="display:flex;align-items:center;gap:8px;">
                        <div style="position:relative;">
                            <input type="text" id="partOverviewTableSearchInput" placeholder="Cari defect / model / customer / lot..."
                                   style="padding:4px 8px 4px 26px;font-size:11px;border-radius:6px;border:1px solid #cbd5e1;outline:none;width:220px;background:#ffffff;">
                            <svg width="12" height="12" fill="none" stroke="#94a3b8" stroke-width="2" viewBox="0 0 24 24"
                                 style="position:absolute;left:8px;top:50%;transform:translateY(-50%);pointer-events:none;">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                        </div>
                    </div>
                </div>

                <!-- Indikator Filter Excel Aktif Bar -->
                <div id="part-overview-excel-filter-active-bar" style="display: none; background: #eff6ff; border-bottom: 1.5px solid #bfdbfe; padding: 7px 16px; align-items: center; justify-content: space-between; font-size: 11px;">
                    <div style="display: flex; align-items: center; gap: 6px; color: #1e40af; font-weight: 700;">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                        <span id="part-overview-excel-filter-summary-text">Menyaring data tabel...</span>
                    </div>
                    <button type="button" onclick="resetAllPartOverviewExcelFilters()" style="background: #ffffff; border: 1px solid #bfdbfe; color: #1d4ed8; font-weight: 800; font-size: 10.5px; padding: 2.5px 9px; border-radius: 5px; cursor: pointer; transition: all 0.15s;" onmouseover="this.style.background='#dbeafe'" onmouseout="this.style.background='#ffffff'">
                        Reset Semua Filter
                    </button>
                </div>

                <!-- Table Body Container (Full view, horizontal scrollable if needed) -->
                <div style="overflow-x:auto;">
                    <table style="width:100%;border-collapse:collapse;text-align:left;font-size:11px;">
                        <thead style="position:sticky;top:0;background:#f8fafc;z-index:10;box-shadow:0 1px 2px rgba(0,0,0,0.05);">
                            <tr style="color:#475569;border-bottom:1px solid #e2e8f0;font-weight:800;text-transform:uppercase;font-size:10px;letter-spacing:0.5px;">
                                <th style="padding:8px 12px;text-align:center;width:40px;">No</th>

                                <!-- Tanggal & Jam -->
                                <th style="padding:8px 12px;width:130px;position:relative;">
                                    <div class="th-filter-wrapper">
                                        <span>Tanggal &amp; Jam</span>
                                        <button type="button" class="th-filter-btn" data-col="date" title="Filter Tanggal" onclick="togglePartOverviewExcelFilter(event, 'date')">
                                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                        </button>
                                    </div>
                                    <div id="part-overview-filter-dropdown-date" class="excel-filter-dropdown align-left">
                                        <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                            <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER TANGGAL</span>
                                            <button type="button" class="excel-reset-btn" onclick="resetSinglePartOverviewColFilter('date')" title="Reset filter">
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                                <span>Reset</span>
                                            </button>
                                        </div>
                                        <input type="text" class="excel-filter-search" placeholder="Cari tanggal..." oninput="onSearchPartOverviewFilterOptions('date', this.value)">
                                        <div class="flex items-center gap-1.5 mb-2">
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllPartOverviewFilter('date', true)">
                                                <svg width="11" height="11" class="text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                                <span>Pilih Semua</span>
                                            </button>
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllPartOverviewFilter('date', false)">
                                                <svg width="11" height="11" class="text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                <span>Hapus Pilihan</span>
                                            </button>
                                        </div>
                                        <div class="excel-filter-list" id="part-overview-filter-list-date"></div>
                                        <div class="excel-filter-actions">
                                            <span class="text-[10px] text-slate-500 font-bold filter-count-info"></span>
                                            <div class="flex items-center gap-2">
                                                <button type="button" class="excel-btn-close" onclick="closeAllPartOverviewExcelFilters()">Tutup</button>
                                                <button type="button" class="excel-btn-apply" onclick="applyPartOverviewExcelFilters()">
                                                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                                    <span>Terapkan</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </th>

                                <!-- Model -->

                                <!-- Model -->
                                <th style="padding:8px 12px;min-width:110px;position:relative;">
                                    <div class="th-filter-wrapper">
                                        <span>Model</span>
                                        <button type="button" class="th-filter-btn" data-col="model" title="Filter Model" onclick="togglePartOverviewExcelFilter(event, 'model')">
                                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                        </button>
                                    </div>
                                    <div id="part-overview-filter-dropdown-model" class="excel-filter-dropdown align-left">
                                        <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                            <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER MODEL</span>
                                            <button type="button" class="excel-reset-btn" onclick="resetSinglePartOverviewColFilter('model')" title="Reset filter">
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                                <span>Reset</span>
                                            </button>
                                        </div>
                                        <input type="text" class="excel-filter-search" placeholder="Cari model..." oninput="onSearchPartOverviewFilterOptions('model', this.value)">
                                        <div class="flex items-center gap-1.5 mb-2">
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllPartOverviewFilter('model', true)">
                                                <svg width="11" height="11" class="text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                                <span>Pilih Semua</span>
                                            </button>
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllPartOverviewFilter('model', false)">
                                                <svg width="11" height="11" class="text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                <span>Hapus Pilihan</span>
                                            </button>
                                        </div>
                                        <div class="excel-filter-list" id="part-overview-filter-list-model"></div>
                                        <div class="excel-filter-actions">
                                            <span class="text-[10px] text-slate-500 font-bold filter-count-info"></span>
                                            <div class="flex items-center gap-2">
                                                <button type="button" class="excel-btn-close" onclick="closeAllPartOverviewExcelFilters()">Tutup</button>
                                                <button type="button" class="excel-btn-apply" onclick="applyPartOverviewExcelFilters()">
                                                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                                    <span>Terapkan</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </th>

                                <!-- Jenis Defect -->
                                <th style="padding:8px 12px;min-width:130px;position:relative;">
                                    <div class="th-filter-wrapper">
                                        <span>Jenis Defect</span>
                                        <button type="button" class="th-filter-btn" data-col="defect" title="Filter Defect" onclick="togglePartOverviewExcelFilter(event, 'defect')">
                                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                        </button>
                                    </div>
                                    <div id="part-overview-filter-dropdown-defect" class="excel-filter-dropdown align-left">
                                        <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                            <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER DEFECT</span>
                                            <button type="button" class="excel-reset-btn" onclick="resetSinglePartOverviewColFilter('defect')" title="Reset filter">
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                                <span>Reset</span>
                                            </button>
                                        </div>
                                        <input type="text" class="excel-filter-search" placeholder="Cari defect..." oninput="onSearchPartOverviewFilterOptions('defect', this.value)">
                                        <div class="flex items-center gap-1.5 mb-2">
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllPartOverviewFilter('defect', true)">
                                                <svg width="11" height="11" class="text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                                <span>Pilih Semua</span>
                                            </button>
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllPartOverviewFilter('defect', false)">
                                                <svg width="11" height="11" class="text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                <span>Hapus Pilihan</span>
                                            </button>
                                        </div>
                                        <div class="excel-filter-list" id="part-overview-filter-list-defect"></div>
                                        <div class="excel-filter-actions">
                                            <span class="text-[10px] text-slate-500 font-bold filter-count-info"></span>
                                            <div class="flex items-center gap-2">
                                                <button type="button" class="excel-btn-close" onclick="closeAllPartOverviewExcelFilters()">Tutup</button>
                                                <button type="button" class="excel-btn-apply" onclick="applyPartOverviewExcelFilters()">
                                                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                                    <span>Terapkan</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </th>

                                <!-- Tipe Inspeksi -->
                                <th style="padding:8px 12px;text-align:center;width:95px;position:relative;">
                                    <div class="th-filter-wrapper">
                                        <span>Inspeksi</span>
                                        <button type="button" class="th-filter-btn" data-col="type" title="Filter Tipe" onclick="togglePartOverviewExcelFilter(event, 'type')">
                                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                        </button>
                                    </div>
                                    <div id="part-overview-filter-dropdown-type" class="excel-filter-dropdown align-left">
                                        <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                            <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER INSPEKSI</span>
                                            <button type="button" class="excel-reset-btn" onclick="resetSinglePartOverviewColFilter('type')" title="Reset filter">
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                                <span>Reset</span>
                                            </button>
                                        </div>
                                        <input type="text" class="excel-filter-search" placeholder="Cari tipe..." oninput="onSearchPartOverviewFilterOptions('type', this.value)">
                                        <div class="flex items-center gap-1.5 mb-2">
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllPartOverviewFilter('type', true)">
                                                <svg width="11" height="11" class="text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                                <span>Pilih Semua</span>
                                            </button>
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllPartOverviewFilter('type', false)">
                                                <svg width="11" height="11" class="text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                <span>Hapus Pilihan</span>
                                            </button>
                                        </div>
                                        <div class="excel-filter-list" id="part-overview-filter-list-type"></div>
                                        <div class="excel-filter-actions">
                                            <span class="text-[10px] text-slate-500 font-bold filter-count-info"></span>
                                            <div class="flex items-center gap-2">
                                                <button type="button" class="excel-btn-close" onclick="closeAllPartOverviewExcelFilters()">Tutup</button>
                                                <button type="button" class="excel-btn-apply" onclick="applyPartOverviewExcelFilters()">
                                                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                                    <span>Terapkan</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </th>

                                <!-- Kanban & Customer -->
                                <th style="padding:8px 12px;min-width:130px;position:relative;">
                                    <div class="th-filter-wrapper">
                                        <span>No Kanban / Cust</span>
                                        <button type="button" class="th-filter-btn" data-col="customer" title="Filter Customer" onclick="togglePartOverviewExcelFilter(event, 'customer')">
                                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                        </button>
                                    </div>
                                    <div id="part-overview-filter-dropdown-customer" class="excel-filter-dropdown align-left">
                                        <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                            <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER CUSTOMER</span>
                                            <button type="button" class="excel-reset-btn" onclick="resetSinglePartOverviewColFilter('customer')" title="Reset filter">
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                                <span>Reset</span>
                                            </button>
                                        </div>
                                        <input type="text" class="excel-filter-search" placeholder="Cari customer..." oninput="onSearchPartOverviewFilterOptions('customer', this.value)">
                                        <div class="flex items-center gap-1.5 mb-2">
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllPartOverviewFilter('customer', true)">
                                                <svg width="11" height="11" class="text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                                <span>Pilih Semua</span>
                                            </button>
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllPartOverviewFilter('customer', false)">
                                                <svg width="11" height="11" class="text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                <span>Hapus Pilihan</span>
                                            </button>
                                        </div>
                                        <div class="excel-filter-list" id="part-overview-filter-list-customer"></div>
                                        <div class="excel-filter-actions">
                                            <span class="text-[10px] text-slate-500 font-bold filter-count-info"></span>
                                            <div class="flex items-center gap-2">
                                                <button type="button" class="excel-btn-close" onclick="closeAllPartOverviewExcelFilters()">Tutup</button>
                                                <button type="button" class="excel-btn-apply" onclick="applyPartOverviewExcelFilters()">
                                                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                                    <span>Terapkan</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </th>

                                <th style="padding:8px 12px;min-width:100px;">Lot No / Ref</th>

                                <!-- QC Inspector -->
                                <th style="padding:8px 12px;min-width:110px;position:relative;">
                                    <div class="th-filter-wrapper">
                                        <span>QC Inspector</span>
                                        <button type="button" class="th-filter-btn" data-col="inspector" title="Filter Inspector" onclick="togglePartOverviewExcelFilter(event, 'inspector')">
                                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                        </button>
                                    </div>
                                    <div id="part-overview-filter-dropdown-inspector" class="excel-filter-dropdown align-right">
                                        <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                            <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER INSPECTOR</span>
                                            <button type="button" class="excel-reset-btn" onclick="resetSinglePartOverviewColFilter('inspector')" title="Reset filter">
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                                <span>Reset</span>
                                            </button>
                                        </div>
                                        <input type="text" class="excel-filter-search" placeholder="Cari inspector..." oninput="onSearchPartOverviewFilterOptions('inspector', this.value)">
                                        <div class="flex items-center gap-1.5 mb-2">
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllPartOverviewFilter('inspector', true)">
                                                <svg width="11" height="11" class="text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                                <span>Pilih Semua</span>
                                            </button>
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllPartOverviewFilter('inspector', false)">
                                                <svg width="11" height="11" class="text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                <span>Hapus Pilihan</span>
                                            </button>
                                        </div>
                                        <div class="excel-filter-list" id="part-overview-filter-list-inspector"></div>
                                        <div class="excel-filter-actions">
                                            <span class="text-[10px] text-slate-500 font-bold filter-count-info"></span>
                                            <div class="flex items-center gap-2">
                                                <button type="button" class="excel-btn-close" onclick="closeAllPartOverviewExcelFilters()">Tutup</button>
                                                <button type="button" class="excel-btn-apply" onclick="applyPartOverviewExcelFilters()">
                                                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                                    <span>Terapkan</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </th>

                                <th style="padding:8px 12px;text-align:right;width:100px;">Jumlah Temuan</th>
                            </tr>
                        </thead>
                        <tbody id="partOverviewHistoryTableBody">
                            <tr>
                                <td colspan="10" style="padding:24px;text-align:center;color:#94a3b8;font-weight:600;">
                                    Memuat catatan riwayat temuan inspeksi...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div style="padding:8px 16px;background:#f8fafc;border-top:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;font-size:11px;color:#64748b;">
                    <span id="partOverviewTableFooterNote">
                        Data riwayat temuan inspeksi terperinci untuk part terpilih.
                    </span>
                    <span style="color:#94a3b8;font-size:10px;">
                        Urutan inspeksi terbaru di atas.
                    </span>
                </div>
            </div>
        </div>

        <!-- ── MODAL PENGATURAN BATAS TARGET / AMBANG (THRESHOLD MODAL) ── -->
        <div id="modalDefectThreshold" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.6);backdrop-filter:blur(3px);z-index:9999;align-items:center;justify-content:center;padding:16px;">
            <div style="background:#ffffff;border-radius:14px;box-shadow:0 20px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.1);border:1px solid #e2e8f0;width:100%;max-width:480px;max-height:90vh;display:flex;flex-direction:column;overflow:hidden;">
                <!-- Modal Header -->
                <div style="padding:14px 18px;border-bottom:1px solid #f1f5f9;background:#f8fafc;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <div style="width:30px;height:30px;background:#fee2e2;border:1px solid #fecaca;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#dc2626;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                        </div>
                        <div>
                            <h4 style="margin:0;font-size:13px;font-weight:900;color:#0f172a;">Pengaturan Batas Maksimal (Target)</h4>
                            <p id="modalThresholdSubtitle" style="margin:1px 0 0 0;font-size:10px;color:#64748b;">Batas ambang garis merah pada grafik tren</p>
                        </div>
                    </div>
                    <button type="button" onclick="closeThresholdModal()" style="border:none;background:transparent;cursor:pointer;color:#94a3b8;padding:4px;border-radius:6px;display:flex;align-items:center;justify-content:center;" onmouseover="this.style.color='#0f172a'" onmouseout="this.style.color='#94a3b8'">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>

                <!-- Modal Body -->
                <div style="padding:16px 18px;display:flex;flex-direction:column;gap:14px;overflow-y:auto;flex:1;" class="thin-scrollbar">
                    <!-- Active Item Info Banner -->
                    <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:9px 12px;display:flex;align-items:center;justify-content:space-between;gap:10px;">
                        <span id="modalThresholdItemType" style="font-size:10.5px;font-weight:700;color:#1e40af;text-transform:uppercase;">Item Terpilih:</span>
                        <strong id="modalThresholdItemName" style="font-size:11.5px;font-weight:900;color:#1e3a8a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:240px;">-</strong>
                    </div>

                    <!-- Input Nilai Batas Target -->
                    <div>
                        <label style="display:block;font-size:11px;font-weight:800;color:#334155;margin-bottom:5px;">
                            Batas Ambang / Nilai Target Maksimal (<span id="modalThresholdUnitLabel">PCS</span>) <span style="color:#ef4444;">*</span>
                        </label>
                        <div style="position:relative;">
                            <input type="number" step="any" min="0" id="inputThresholdValue" placeholder="Misal: 10 atau 0.5"
                                   style="width:100%;box-sizing:border-box;padding:8px 45px 8px 12px;font-size:13px;font-weight:800;border:1.5px solid #cbd5e1;border-radius:8px;outline:none;background:#ffffff;color:#0f172a;">
                            <span id="inputThresholdUnitSuffix" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);font-size:11px;font-weight:800;color:#64748b;pointer-events:none;">
                                PCS
                            </span>
                        </div>
                        <p style="margin:4px 0 0 0;font-size:10px;color:#64748b;">
                            Garis putus-putus merah akan muncul horizontal di grafik tren pada nilai ini.
                        </p>
                    </div>

                    <!-- Radio Pilihan Cakupan Target (Scope) -->
                    <div>
                        <label style="display:block;font-size:11px;font-weight:800;color:#334155;margin-bottom:6px;">
                            Cakupan Penerapan Target:
                        </label>
                        <div style="display:flex;flex-direction:column;gap:7px;">
                            <label style="display:flex;align-items:flex-start;gap:8px;padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:8px;background:#ffffff;cursor:pointer;transition:all 0.15s;" id="labelScopeSpecific">
                                <input type="radio" name="thresholdScope" value="specific" checked onchange="onThresholdScopeChange(this.value)" style="margin-top:2px;">
                                <div style="font-size:11px;">
                                    <strong style="color:#0f172a;display:block;">Terapkan HANYA untuk item ini</strong>
                                    <span style="color:#64748b;font-size:10px;">Hanya berlaku untuk defect / part yang sedang aktif dipilih.</span>
                                </div>
                            </label>

                            <label style="display:flex;align-items:flex-start;gap:8px;padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:8px;background:#ffffff;cursor:pointer;transition:all 0.15s;" id="labelScopeBatch">
                                <input type="radio" name="thresholdScope" value="batch" onchange="onThresholdScopeChange(this.value)" style="margin-top:2px;">
                                <div style="font-size:11px;">
                                    <strong style="color:#0f172a;display:block;">Pilih beberapa item (Centang / Checklist)</strong>
                                    <span style="color:#64748b;font-size:10px;">Centang item tertentu untuk menerapkan nilai target secara bersamaan.</span>
                                </div>
                            </label>

                            <!-- Container Checklist Items (Defect / Part) -->
                            <div id="containerThresholdBatchList" style="display:none;background:#f8fafc;border:1.5px solid #cbd5e1;border-radius:8px;padding:10px;margin-top:2px;">
                                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;gap:6px;flex-wrap:wrap;">
                                    <input type="text" id="thresholdBatchSearch" placeholder="Cari nama item / part..." oninput="filterThresholdBatchList(this.value)"
                                           style="flex:1;padding:4px 8px;font-size:11px;border-radius:6px;border:1px solid #cbd5e1;outline:none;background:#ffffff;">
                                    <div style="display:flex;align-items:center;gap:4px;">
                                        <button type="button" onclick="toggleAllThresholdBatchItems(true)" style="padding:3px 7px;font-size:10px;font-weight:800;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:5px;cursor:pointer;">
                                            Pilih Semua
                                        </button>
                                        <button type="button" onclick="toggleAllThresholdBatchItems(false)" style="padding:3px 7px;font-size:10px;font-weight:800;background:#ffffff;color:#64748b;border:1px solid #cbd5e1;border-radius:5px;cursor:pointer;">
                                            Bersihkan
                                        </button>
                                    </div>
                                </div>
                                <div id="thresholdBatchItemsWrapper" style="max-height:160px;overflow-y:auto;display:flex;flex-direction:column;gap:4px;padding-right:2px;" class="thin-scrollbar">
                                    <div style="font-size:11px;color:#94a3b8;text-align:center;padding:10px;">Memuat daftar item...</div>
                                </div>
                                <div style="margin-top:6px;display:flex;align-items:center;justify-content:space-between;font-size:10px;color:#64748b;">
                                    <span>Dipilih: <strong id="lblThresholdBatchSelectedCount" style="color:#0f172a;">0</strong> item</span>
                                    <span style="font-style:italic;">*Badge warna menunjukkan target saat ini</span>
                                </div>
                            </div>

                            <label style="display:flex;align-items:flex-start;gap:8px;padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:8px;background:#ffffff;cursor:pointer;transition:all 0.15s;" id="labelScopeAll">
                                <input type="radio" name="thresholdScope" value="all" onchange="onThresholdScopeChange(this.value)" style="margin-top:2px;">
                                <div style="font-size:11px;">
                                    <strong style="color:#0f172a;display:block;">Terapkan ke SEMUA defect / part (Global)</strong>
                                    <span style="color:#64748b;font-size:10px;">Batas ini menjadi acuan default untuk seluruh item yang belum memiliki batas khusus.</span>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Status Target Saat Ini -->
                    <div id="modalThresholdCurrentStatus" style="font-size:10.5px;color:#64748b;background:#f8fafc;padding:7px 10px;border-radius:6px;border:1px solid #f1f5f9;">
                        Status saat ini: <span id="modalThresholdCurrentText" style="font-weight:700;color:#0f172a;">Belum ada target</span>
                    </div>
                </div>

                <!-- Modal Footer Actions -->
                <div style="padding:12px 18px;border-top:1px solid #f1f5f9;background:#f8fafc;display:flex;align-items:center;justify-content:space-between;gap:8px;flex-shrink:0;">
                    <button type="button" id="btnThresholdClear" onclick="submitThresholdForm(true)"
                            style="padding:6px 12px;font-size:11px;font-weight:800;color:#dc2626;background:#fee2e2;border:1px solid #fecaca;border-radius:6px;cursor:pointer;transition:all 0.15s;"
                            title="Hapus konfigurasi target untuk item/lingkup ini">
                        Hapus Batas
                    </button>
                    <div style="display:flex;align-items:center;gap:6px;">
                        <button type="button" onclick="closeThresholdModal()"
                                style="padding:6px 12px;font-size:11px;font-weight:700;color:#475569;background:#ffffff;border:1px solid #cbd5e1;border-radius:6px;cursor:pointer;">
                            Batal
                        </button>
                        <button type="button" id="btnThresholdSave" onclick="submitThresholdForm(false)"
                                style="padding:6px 16px;font-size:11px;font-weight:800;color:#ffffff;background:#2563eb;border:none;border-radius:6px;cursor:pointer;box-shadow:0 1px 2px rgba(37,99,235,0.25);">
                            Simpan Target
                        </button>
                    </div>
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

    var currentAnalysisMode = 'overview';

    var overviewState = {
        selectedDefectId: null,
        selectedDefectName: '',
        trendChartType: 'line',
        trendInterval: 'daily',
        weeklyMode: 'cutoff',
        partChartType: 'table',
        threshold: null,
        thresholdInfo: null,
        cachedDefects: null,
        cachedTrendData: null,
        cachedPartsData: null,
        cachedPartsTimelineData: null
    };

    var chartOverviewTrendInstance = null;
    var chartOverviewPartsInstance = null;
    var chartOverviewPartsDonutInstance = null;

    var partOverviewState = {
        selectedPartId: null,
        selectedPartCode: '',
        selectedPartName: '',
        trendChartType: 'line',
        trendInterval: 'daily',
        weeklyMode: 'cutoff',
        defectChartType: 'table',
        threshold: null,
        thresholdInfo: null,
        cachedParts: null,
        cachedTrendData: null,
        cachedDefectsData: null,
        cachedDefectsTimelineData: null
    };

    var chartPartOverviewTrendInstance = null;
    var chartPartOverviewDefectsInstance = null;
    var chartPartOverviewDefectsDonutInstance = null;

    var activeThresholdModalMode = 'overview'; // 'overview' | 'part_overview'



    // ── Document Ready ────────────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function() {
        updatePresetPillStyles();
        updateRankPillStyles();

        // Initial Load based on default mode ('overview')
        loadOverviewMode();

        // Setup Live Search on Overview Audit Table
        $('#overviewTableSearchInput').on('keyup', function() {
            var q = $(this).val().toLowerCase().trim();
            $('#overviewHistoryTableBody tr').each(function() {
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

    // ── Export Excel Handler ──────────────────────────────────────────────────
    function exportDefectExcel() {
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

        p.append('mode', currentAnalysisMode);

        if (currentAnalysisMode === 'overview') {
            if (overviewState.selectedDefectId) {
                p.append('defect_id', overviewState.selectedDefectId);
            }
        } else if (currentAnalysisMode === 'part_overview') {
            if (partOverviewState.selectedPartId) {
                p.append('part_id', partOverviewState.selectedPartId);
            }
        }

        window.location.href = 'export_excel.php?' + p.toString();
    }

    // ── Official Print Report Handler (1:1 WYSIWYG Active Tab) ────────────────
    function printAnalysisReport() {
        var now = new Date();
        var days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
        var months = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        var dayName = days[now.getDay()];
        var d = String(now.getDate()).padStart(2, '0');
        var mName = months[now.getMonth()];
        var y = now.getFullYear();
        var h = String(now.getHours()).padStart(2, '0');
        var min = String(now.getMinutes()).padStart(2, '0');
        var timestampStr = dayName + ', ' + d + ' ' + mName + ' ' + y + ' ' + h + ':' + min + ' WIB';

        var tsEl = document.getElementById('printHeaderTimestamp');
        if (tsEl) tsEl.textContent = timestampStr;

        var titleEl = document.getElementById('printReportTitle');
        var badgeEl = document.getElementById('printReportTargetBadge');
        var periodEl = document.getElementById('printReportPeriod');
        var custEl = document.getElementById('printReportCustomer');
        var unitEl = document.getElementById('printReportUnit');

        if (periodEl) {
            var periodDesc = 'Bulan Ini';
            if (funnelState.filterType === 'month_year') {
                periodDesc = 'Bulan ' + funnelState.month + '/' + funnelState.year;
            } else if (funnelState.filterType === 'specific_year') {
                periodDesc = 'Tahun ' + funnelState.year;
            } else if (funnelState.filterType === 'all_years') {
                periodDesc = 'Semua Tahun';
            } else if (funnelState.filterType === 'custom') {
                periodDesc = funnelState.startDate + ' s.d. ' + funnelState.endDate;
            } else {
                periodDesc = (funnelState.preset || 'bulanan').replace('_', ' ').toUpperCase();
            }
            periodEl.textContent = periodDesc;
        }

        if (custEl) {
            custEl.textContent = funnelState.customer || 'Semua Customer';
        }

        if (unitEl) {
            unitEl.textContent = (funnelState.unit || 'pcs').toUpperCase();
        }

        if (currentAnalysisMode === 'overview') {
            if (titleEl) titleEl.textContent = 'LAPORAN ANALISIS & TREN DEFECT';
            var defName = overviewState.selectedDefectName || $('#lblOverviewDefectName').text() || 'Semua Defect';
            if (badgeEl) badgeEl.textContent = defName;
        } else {
            if (titleEl) titleEl.textContent = 'LAPORAN ANALISIS & TREN PART';
            var pCode = partOverviewState.selectedPartCode || '';
            var pName = partOverviewState.selectedPartName || $('#lblPartOverviewPartName').text() || 'Semua Part';
            var fullP = pCode ? (pCode + ' - ' + pName) : pName;
            if (badgeEl) badgeEl.textContent = fullP;
        }

        // Trigger browser native print dialogue
        window.print();
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
        if (unit === 'pcs') {
            if (btnPcs) {
                btnPcs.style.background = '#0f172a';
                btnPcs.style.color = '#ffffff';
                btnPcs.style.boxShadow = '0 1px 3px rgba(15,23,42,0.25)';
            }
            if (btnLot) {
                btnLot.style.background = 'transparent';
                btnLot.style.color = '#64748b';
                btnLot.style.boxShadow = 'none';
            }
        } else {
            if (btnLot) {
                btnLot.style.background = '#d97706';
                btnLot.style.color = '#ffffff';
                btnLot.style.boxShadow = '0 1px 3px rgba(217,119,6,0.3)';
            }
            if (btnPcs) {
                btnPcs.style.background = 'transparent';
                btnPcs.style.color = '#64748b';
                btnPcs.style.boxShadow = 'none';
            }
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
        funnelState.topRank     = 'all';
        var selCust = document.getElementById('selectCustomer');
        if (selCust) selCust.value = '';
        closeSubFilter();

        var btnPcs = document.getElementById('btnUnitPcs');
        var btnLot = document.getElementById('btnUnitLot');
        if (btnPcs) {
            btnPcs.style.background = '#0f172a';
            btnPcs.style.color = '#ffffff';
            btnPcs.style.boxShadow = '0 1px 3px rgba(15,23,42,0.25)';
        }
        if (btnLot) {
            btnLot.style.background = 'transparent';
            btnLot.style.color = '#64748b';
            btnLot.style.boxShadow = 'none';
        }

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

        updateWeeklySwitcherVisibility();
    }

    function updateWeeklySwitcherVisibility() {
        // Tampilkan switcher mingguan hanya jika filter adalah bulanan atau pilih bulan (atau custom <= 35 hari)
        var isMonthly = (funnelState.preset === 'bulanan' || funnelState.filterType === 'month_year' || funnelState.preset === 'month_year');
        
        var grpOverview = document.getElementById('overviewTrendIntervalGroup');
        var grpPart     = document.getElementById('partOverviewTrendIntervalGroup');
        var grpOverviewWeekly = document.getElementById('overviewWeeklyModeGroup');
        var grpPartWeekly     = document.getElementById('partOverviewWeeklyModeGroup');

        if (grpOverview) grpOverview.style.display = isMonthly ? 'inline-flex' : 'none';
        if (grpPart) grpPart.style.display = isMonthly ? 'inline-flex' : 'none';

        if (grpOverviewWeekly) {
            grpOverviewWeekly.style.display = (isMonthly && overviewState.trendInterval === 'weekly') ? 'inline-flex' : 'none';
        }
        if (grpPartWeekly) {
            grpPartWeekly.style.display = (isMonthly && partOverviewState.trendInterval === 'weekly') ? 'inline-flex' : 'none';
        }

        if (!isMonthly) {
            overviewState.trendInterval = 'daily';
            partOverviewState.trendInterval = 'daily';
        }
    }


    // ── STATE PRESERVATION ENGINE (No Selection Reset on Filter Change) ───────
    function refreshActiveSelections() {
        if (currentAnalysisMode === 'overview') {
            refreshOverviewMode();
            return;
        }

        if (currentAnalysisMode === 'part_overview') {
            refreshPartOverviewMode();
            return;
        }
    }

    function clearFullSelections() {
        funnelState.selectedDefectId   = null;
        funnelState.selectedDefectName = '';
        funnelState.selectedModelId    = null;
        funnelState.selectedModelName  = '';
        funnelState.selectedPartId     = null;
        funnelState.selectedPartCode   = '';
        funnelState.selectedPartName   = '';
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

    // ── OVERVIEW ANALYSIS JAVASCRIPT CONTROLLER (DEFAULT EXECUTIVE VIEW) ─────
    function loadOverviewMode() {
        loadOverviewDefects();
    }

    function refreshOverviewMode() {
        loadOverviewDefects(function() {
            if (overviewState.selectedDefectId) {
                loadOverviewTrend();
                loadOverviewParts();
                loadOverviewHistory();
            }
        });
    }

    function loadOverviewDefects(callback) {
        var url = buildApiUrl('get_defects');

        fetch(url)
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!data.success) return;
                overviewState.cachedDefects = data;

                // Render Defect Dropdown
                renderOverviewRankPills(data);

                // Auto-select rank 1 if no defect selected or previously selected no longer in list
                var items = data.items || [];
                var validItems = items.filter(function(it) { return !it.is_other && it.item_id; });

                if (validItems.length > 0) {
                    var currentDefectStillExists = validItems.some(function(it) {
                        return it.item_id === overviewState.selectedDefectId;
                    });

                    if (!overviewState.selectedDefectId || !currentDefectStillExists) {
                        selectOverviewDefect(validItems[0].item_id, validItems[0].item_name);
                    } else {
                        highlightOverviewRankPill(overviewState.selectedDefectId);
                        if (typeof callback === 'function') callback();
                    }
                } else {
                    overviewState.selectedDefectId = null;
                    overviewState.selectedDefectName = '';
                    funnelState.selectedDefectId = null;
                    funnelState.selectedDefectName = '';
                    var lbl = document.getElementById('lblOverviewDefectName');
                    if (lbl) lbl.textContent = 'Tidak Ada Data';
                    document.querySelectorAll('.overviewCardDefectBadge').forEach(function(badge) {
                        badge.textContent = '-';
                    });
                    var displayBox = document.getElementById('overviewDropdownSelectedDisplay');
                    if (displayBox) {
                        displayBox.innerHTML = '<span style="font-size:11px;color:#94a3b8;font-style:italic;">Tidak ada data defect</span>';
                    }
                    clearOverviewChartsAndTable();
                }
            })
            .catch(function(err) {
                console.error("Error loading overview defects:", err);
            });
    }

    function toggleOverviewDefectMenu(event) {
        event.stopPropagation();
        var menu = document.getElementById('overviewDefectMenuDropdown');
        if (!menu) return;
        var isVisible = (menu.style.display === 'block');
        menu.style.display = isVisible ? 'none' : 'block';
    }

    function closeOverviewDefectMenu() {
        var menu = document.getElementById('overviewDefectMenuDropdown');
        if (menu) menu.style.display = 'none';
    }

    function renderOverviewRankPills(data) {
        var menuContainer = document.getElementById('overviewDefectMenuDropdown');
        if (!menuContainer) return;

        var items = data.items || [];
        var validItems = items.filter(function(it) { return !it.is_other && it.item_id; });
        var unitLabel = data.unit || 'PCS';

        if (validItems.length === 0) {
            menuContainer.innerHTML = '<div style="padding:12px;text-align:center;color:#94a3b8;font-size:11px;">Tidak ada data defect</div>';
            return;
        }

        var html = '';
        validItems.forEach(function(item, idx) {
            var rankNum = idx + 1;
            var valFormatted = Number(item.val || 0).toLocaleString();
            var pct = data.grandMetric > 0 ? (Math.round((item.val / data.grandMetric) * 1000) / 10) + '%' : '0%';
            var isSelected = (overviewState.selectedDefectId === item.item_id);

            // Tentukan style badge warna peringkat
            var rankBadgeBg, rankBadgeColor, rankBorder;
            if (rankNum === 1) {
                rankBadgeBg = 'linear-gradient(135deg, #fef3c7 0%, #fde68a 100%)';
                rankBadgeColor = '#92400e';
                rankBorder = '#f59e0b';
            } else if (rankNum === 2) {
                rankBadgeBg = 'linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%)';
                rankBadgeColor = '#334155';
                rankBorder = '#94a3b8';
            } else if (rankNum === 3) {
                rankBadgeBg = 'linear-gradient(135deg, #ffedd5 0%, #fed7aa 100%)';
                rankBadgeColor = '#9a3412';
                rankBorder = '#ea580c';
            } else {
                rankBadgeBg = '#f8fafc';
                rankBadgeColor = '#64748b';
                rankBorder = '#cbd5e1';
            }

            var itemBg = isSelected ? '#eff6ff' : '#ffffff';
            var itemBorder = isSelected ? '1.5px solid #3b82f6' : '1px solid #f1f5f9';

            html += '<div onclick="selectOverviewDefect(' + item.item_id + ', \'' + escapeQuotes(item.item_name) + '\')" ' +
                    '     class="overview-defect-menu-item" ' +
                    '     data-id="' + item.item_id + '" ' +
                    '     style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:7px 10px;margin-bottom:3px;border-radius:8px;cursor:pointer;background:' + itemBg + ';border:' + itemBorder + ';transition:all 0.15s;" ' +
                    '     onmouseover="if (!this.classList.contains(\'is-selected\')) this.style.background=\'#f8fafc\'" ' +
                    '     onmouseout="if (!this.classList.contains(\'is-selected\')) this.style.background=\'#ffffff\'">' +
                    '  <div style="display:flex;align-items:center;gap:8px;overflow:hidden;flex:1;">' +
                    '    <span class="defect-rank-badge" style="font-size:10px;font-weight:900;padding:2px 7px;border-radius:5px;background:' + rankBadgeBg + ';color:' + rankBadgeColor + ';border:1px solid ' + rankBorder + ';flex-shrink:0;">' +
                    '      #' + rankNum +
                    '    </span>' +
                    '    <span class="defect-color-dot" style="width:9px;height:9px;border-radius:50%;background:' + (item.color || '#3b82f6') + ';flex-shrink:0;"></span>' +
                    '    <span class="defect-name-text" style="font-size:11.5px;font-weight:800;color:#0f172a;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + escapeQuotes(item.item_name) + '">' +
                    '      ' + escapeHtml(item.item_name) +
                    '    </span>' +
                    '  </div>' +
                    '  <div style="display:flex;align-items:center;gap:5px;flex-shrink:0;font-size:11px;font-weight:900;">' +
                    '    <span class="defect-val-badge" style="color:#b91c1c;background:#fef2f2;padding:2px 6px;border-radius:4px;border:1px solid #fecaca;">' + valFormatted + ' ' + unitLabel + '</span>' +
                    '    <span class="defect-pct-badge" style="color:#2563eb;background:#eff6ff;padding:2px 5px;border-radius:4px;border:1px solid #bfdbfe;font-size:10px;">' + pct + '</span>' +
                    '  </div>' +
                    '</div>';
        });

        menuContainer.innerHTML = html;
        highlightOverviewRankPill(overviewState.selectedDefectId);
    }

    function highlightOverviewRankPill(defectId) {
        var menuContainer = document.getElementById('overviewDefectMenuDropdown');
        if (!menuContainer) return;

        var items = menuContainer.querySelectorAll('.overview-defect-menu-item');
        var displayBox = document.getElementById('overviewDropdownSelectedDisplay');

        items.forEach(function(el) {
            var id = parseInt(el.getAttribute('data-id'), 10);
            if (id === defectId) {
                el.classList.add('is-selected');
                el.style.background = '#eff6ff';
                el.style.borderColor = '#3b82f6';

                // Update teks dan badge di tombol utama
                if (displayBox) {
                    var rankBadge = el.querySelector('.defect-rank-badge');
                    var dot       = el.querySelector('.defect-color-dot');
                    var nameSpan  = el.querySelector('.defect-name-text');
                    var pcsBadge  = el.querySelector('.defect-val-badge');
                    var pctBadge  = el.querySelector('.defect-pct-badge');

                    if (rankBadge && nameSpan && pcsBadge && pctBadge) {
                        displayBox.innerHTML = 
                            '<span style="' + rankBadge.getAttribute('style') + '">' + rankBadge.textContent.trim() + '</span>' +
                            (dot ? '<span style="' + dot.getAttribute('style') + '"></span>' : '') +
                            '<span style="font-size:12px;font-weight:900;color:#0f172a;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + nameSpan.textContent.trim() + '</span>' +
                            '<span style="' + pcsBadge.getAttribute('style') + '">' + pcsBadge.textContent.trim() + '</span>' +
                            '<span style="' + pctBadge.getAttribute('style') + '">' + pctBadge.textContent.trim() + '</span>';
                    }
                }
            } else {
                el.classList.remove('is-selected');
                el.style.background = '#ffffff';
                el.style.borderColor = '#f1f5f9';
            }
        });
    }

    function selectOverviewDefect(defectId, defectName) {
        overviewState.selectedDefectId = defectId;
        overviewState.selectedDefectName = defectName;

        // Also sync to funnelState for seamless export excel & consistency
        funnelState.selectedDefectId = defectId;
        funnelState.selectedDefectName = defectName;

        var lbl = document.getElementById('lblOverviewDefectName');
        if (lbl) lbl.textContent = defectName;

        document.querySelectorAll('.overviewCardDefectBadge').forEach(function(badge) {
            badge.textContent = defectName || '-';
        });

        highlightOverviewRankPill(defectId);
        closeOverviewDefectMenu();

        // Load 3 components in parallel
        loadOverviewTrend();
        loadOverviewParts();
        loadOverviewHistory();
    }

    function switchOverviewInterval(interval) {
        overviewState.trendInterval = interval;
        var btnDaily  = document.getElementById('btnOverviewIntervalDaily');
        var btnWeekly = document.getElementById('btnOverviewIntervalWeekly');

        if (btnDaily && btnWeekly) {
            if (interval === 'daily') {
                btnDaily.style.background = '#0f172a';
                btnDaily.style.color = '#ffffff';
                btnDaily.style.boxShadow = '0 1px 2px rgba(15,23,42,0.2)';
                btnWeekly.style.background = 'transparent';
                btnWeekly.style.color = '#64748b';
                btnWeekly.style.boxShadow = 'none';
            } else {
                btnWeekly.style.background = '#0f172a';
                btnWeekly.style.color = '#ffffff';
                btnWeekly.style.boxShadow = '0 1px 2px rgba(15,23,42,0.2)';
                btnDaily.style.background = 'transparent';
                btnDaily.style.color = '#64748b';
                btnDaily.style.boxShadow = 'none';
            }
        }

        var grpWeeklyMode = document.getElementById('overviewWeeklyModeGroup');
        if (grpWeeklyMode) {
            grpWeeklyMode.style.display = (interval === 'weekly') ? 'inline-flex' : 'none';
        }

        loadOverviewTrend();
    }

    function switchOverviewWeeklyMode(mode) {
        overviewState.weeklyMode = mode;
        var btnCutoff = document.getElementById('btnOverviewWeeklyCutoff');
        var btnFull   = document.getElementById('btnOverviewWeeklyFull');

        if (btnCutoff && btnFull) {
            if (mode === 'cutoff') {
                btnCutoff.style.background = '#0f172a';
                btnCutoff.style.color = '#ffffff';
                btnCutoff.style.boxShadow = '0 1px 2px rgba(15,23,42,0.2)';
                btnFull.style.background = 'transparent';
                btnFull.style.color = '#64748b';
                btnFull.style.boxShadow = 'none';
            } else {
                btnFull.style.background = '#0f172a';
                btnFull.style.color = '#ffffff';
                btnFull.style.boxShadow = '0 1px 2px rgba(15,23,42,0.2)';
                btnCutoff.style.background = 'transparent';
                btnCutoff.style.color = '#64748b';
                btnCutoff.style.boxShadow = 'none';
            }
        }

        loadOverviewTrend();
    }

    function switchOverviewTrendType(type) {
        overviewState.trendChartType = type;
        var btnLine = document.getElementById('btnOverviewTrendLine');
        var btnBar  = document.getElementById('btnOverviewTrendBar');

        if (btnLine && btnBar) {
            if (type === 'line') {
                btnLine.style.background = '#0f172a';
                btnLine.style.color = '#ffffff';
                btnLine.style.boxShadow = '0 1px 2px rgba(15,23,42,0.2)';
                btnBar.style.background = 'transparent';
                btnBar.style.color = '#64748b';
                btnBar.style.boxShadow = 'none';
            } else {
                btnBar.style.background = '#0f172a';
                btnBar.style.color = '#ffffff';
                btnBar.style.boxShadow = '0 1px 2px rgba(15,23,42,0.2)';
                btnLine.style.background = 'transparent';
                btnLine.style.color = '#64748b';
                btnLine.style.boxShadow = 'none';
            }
        }

        if (overviewState.cachedTrendData) {
            renderOverviewTrendChart(overviewState.cachedTrendData);
        }
    }

    function switchOverviewPartChartType(type) {
        overviewState.partChartType = type;
        var btnDonut = document.getElementById('btnOverviewPartDonut');
        var btnTotal = document.getElementById('btnOverviewPartTotal');
        var btnLine  = document.getElementById('btnOverviewPartLine');
        var btnBar   = document.getElementById('btnOverviewPartBar');

        var allBtns = [btnDonut, btnTotal, btnLine, btnBar];
        allBtns.forEach(function(b) {
            if (b) {
                b.style.background = 'transparent';
                b.style.color = '#64748b';
                b.style.boxShadow = 'none';
            }
        });

        if ((type === 'table' || type === 'doughnut') && btnDonut) {
            btnDonut.style.background = '#0f172a';
            btnDonut.style.color = '#ffffff';
            btnDonut.style.boxShadow = '0 1px 2px rgba(15,23,42,0.2)';
        } else if (type === 'bar_total' && btnTotal) {
            btnTotal.style.background = '#0f172a';
            btnTotal.style.color = '#ffffff';
            btnTotal.style.boxShadow = '0 1px 2px rgba(15,23,42,0.2)';
        } else if (type === 'line_trend' && btnLine) {
            btnLine.style.background = '#0f172a';
            btnLine.style.color = '#ffffff';
            btnLine.style.boxShadow = '0 1px 2px rgba(15,23,42,0.2)';
        } else if (type === 'bar_trend' && btnBar) {
            btnBar.style.background = '#0f172a';
            btnBar.style.color = '#ffffff';
            btnBar.style.boxShadow = '0 1px 2px rgba(15,23,42,0.2)';
        }

        if (type === 'line_trend' || type === 'bar_trend') {
            loadOverviewPartsTimeline();
        } else {
            if (overviewState.cachedPartsData) {
                renderOverviewPartsChart(overviewState.cachedPartsData);
            } else {
                loadOverviewParts();
            }
        }
    }

    function loadOverviewPartsTimeline() {
        if (!overviewState.selectedDefectId) return;

        var url = buildApiUrl('get_part_stacked_trend', {
            defect_id: overviewState.selectedDefectId,
            top_rank: funnelState.topRank
        });

        fetch(url)
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!data.success) return;
                overviewState.cachedPartsTimelineData = data;
                renderOverviewPartsTimelineChart(data);
            })
            .catch(function(err) {
                console.error("Error loading overview parts timeline:", err);
            });
    }

    function renderOverviewPartsTimelineChart(data) {
        var donutWrapper    = document.getElementById('overviewPartDonutWrapper');
        var timelineWrapper = document.getElementById('overviewPartTimelineWrapper');
        if (donutWrapper) donutWrapper.style.display = 'none';
        if (timelineWrapper) timelineWrapper.style.display = 'block';

        var canvas = document.getElementById('chartOverviewParts');
        if (!canvas) return;
        if (chartOverviewPartsInstance) chartOverviewPartsInstance.destroy();

        var ctx = canvas.getContext('2d');
        var isLine = (overviewState.partChartType === 'line_trend');
        var unitLabel = data.unit || 'PCS';

        var palette = ['#059669', '#2563eb', '#f59e0b', '#d946ef', '#06b6d4', '#ea580c', '#8b5cf6', '#64748b'];

        var datasets = (data.datasets || []).map(function(ds, idx) {
            var color = palette[idx % palette.length];
            if (isLine) {
                return {
                    label: ds.label + ' (' + (ds.part_name || '') + ')',
                    data: ds.data,
                    borderColor: color,
                    backgroundColor: color,
                    borderWidth: 2.5,
                    pointBackgroundColor: color,
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 3.5,
                    fill: false,
                    tension: 0.3,
                    order: 2
                };
            } else {
                return {
                    label: ds.label + ' (' + (ds.part_name || '') + ')',
                    data: ds.data,
                    backgroundColor: color,
                    borderRadius: 4,
                    barPercentage: 0.7,
                    categoryPercentage: 0.8,
                    stack: 'partStack',
                    order: 2
                };
            }
        });

        // Sync Batas Target Merah ke Diagram Kanan jika ada
        var labels = data.labels || [];
        if (overviewState.threshold !== null && overviewState.threshold !== undefined && labels.length > 0) {
            var targetVal = overviewState.threshold;
            var targetData = labels.map(function() { return targetVal; });
            datasets.push({
                type: 'line',
                label: '🎯 Batas Target: ' + targetVal.toLocaleString() + ' ' + unitLabel,
                data: targetData,
                borderColor: '#dc2626',
                borderWidth: 2,
                borderDash: [6, 5],
                pointRadius: 0,
                pointHoverRadius: 0,
                fill: false,
                tension: 0,
                order: 1
            });
        }

        chartOverviewPartsInstance = new Chart(ctx, {
            type: isLine ? 'line' : 'bar',
            data: {
                labels: labels,
                datasets: datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        align: 'center',
                        labels: {
                            boxWidth: 12,
                            font: { size: 10, weight: '700' },
                            color: '#475569',
                            padding: 10
                        }
                    },
                    tooltip: {
                        mode: isLine ? 'nearest' : 'index',
                        intersect: false,
                        backgroundColor: '#0f172a',
                        titleColor: '#f8fafc',
                        bodyColor: '#e2e8f0',
                        padding: 10,
                        cornerRadius: 8,
                        filter: function(tooltipItem) {
                            // Sembunyikan item yang nilainya 0 PCS
                            return Number(tooltipItem.raw || 0) > 0;
                        },
                        callbacks: {
                            label: function(ctx) {
                                var val = Number(ctx.raw || 0).toLocaleString();
                                return ' ' + ctx.dataset.label + ': ' + val + ' ' + unitLabel;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 10, weight: '600' }, color: '#64748b', maxRotation: 45 }
                    },
                    y: {
                        beginAtZero: true,
                        stacked: !isLine,
                        grid: { color: '#f1f5f9' },
                        ticks: {
                            font: { size: 10, weight: '600' },
                            color: '#64748b',
                            callback: function(value) {
                                if (Number.isInteger(value)) return Number(value).toLocaleString();
                                return null;
                            }
                        }
                    }
                }
            }
        });
    }

    function loadOverviewParts() {
        if (!overviewState.selectedDefectId) return;

        var url = buildApiUrl('get_parts', {
            defect_id: overviewState.selectedDefectId
        });

        fetch(url)
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!data.success) return;
                overviewState.cachedPartsData = data;
                renderOverviewPartsChart(data);
            })
            .catch(function(err) {
                console.error("Error loading overview parts:", err);
            });
    }

    function renderOverviewPartsChart(data) {
        var chartType = overviewState.partChartType;
        var unitLabel = data.unit || 'PCS';

        var donutWrapper    = document.getElementById('overviewPartDonutWrapper');
        var timelineWrapper = document.getElementById('overviewPartTimelineWrapper');

        // Prepare labels, values, and raw items
        var items = data.items || [];
        var labels = (data.chartLabels || []).slice();
        var counts = (data.chartData || []).slice();
        var colors = (data.chartColors || []).slice();

        // If no data
        if (counts.length === 0 || data.grandMetric === 0) {
            labels = ['Tidak ada part'];
            counts = [1];
            colors = ['#e2e8f0'];
        }

        if (chartType === 'table' || chartType === 'doughnut') {
            if (donutWrapper) donutWrapper.style.display = 'block';
            if (timelineWrapper) timelineWrapper.style.display = 'none';

            var tbody = document.getElementById('overviewPartTableBody');
            if (tbody) {
                if (!items || items.length === 0 || data.grandMetric === 0) {
                    tbody.innerHTML = '<tr><td colspan="4" style="padding:22px;text-align:center;color:#94a3b8;font-size:11px;">Tidak ada temuan part pada defect ini.</td></tr>';
                } else {
                    var html = '';
                    var rank = 1;
                    items.forEach(function(item) {
                        var val = Number(item.val || 0).toLocaleString();
                        var pct = data.grandMetric > 0 ? (Math.round((item.val / data.grandMetric) * 1000) / 10) : 0;
                        var color = item.color || '#3b82f6';
                        var partCode = item.part_code || '';
                        var partName = item.part_name || item.item_name || '-';

                        var rankBadgeStyle = 'font-size:10px;font-weight:900;padding:2px 6px;border-radius:4px;';
                        if (rank === 1) {
                            rankBadgeStyle += 'background:#fef3c7;color:#92400e;border:1px solid #fcd34d;';
                        } else if (rank === 2) {
                            rankBadgeStyle += 'background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;';
                        } else if (rank === 3) {
                            rankBadgeStyle += 'background:#ffedd5;color:#9a3412;border:1px solid #fdba74;';
                        } else {
                            rankBadgeStyle += 'background:#f8fafc;color:#64748b;border:1px solid #e2e8f0;';
                        }

                        var rawValNum = Number(item.val || 0);
                        var targetVal = (overviewState.threshold !== null && overviewState.threshold !== undefined) 
                            ? Number(overviewState.threshold) 
                            : ((item.threshold_val !== undefined && item.threshold_val !== null) ? Number(item.threshold_val) : null);

                        var targetCellHtml = '';
                        if (targetVal !== null) {
                            var isOver = (rawValNum > targetVal);
                            var badgeBg = isOver ? '#fef2f2' : '#f0fdf4';
                            var badgeColor = isOver ? '#b91c1c' : '#15803d';
                            var badgeBorder = isOver ? '#fecaca' : '#bbf7d0';
                            var statusText = isOver ? '⚠️ Over' : '✓ Aman';

                            targetCellHtml = '<div style="display:flex;align-items:center;justify-content:center;gap:5px;">' +
                                             '  <span style="font-weight:900;color:' + (isOver ? '#dc2626' : '#0f172a') + ';font-size:11px;">' + targetVal.toLocaleString() + '</span> ' +
                                             '  <span style="font-size:9.5px;font-weight:700;color:#64748b;">' + unitLabel + '</span>' +
                                             '  <span style="font-size:9px;font-weight:800;padding:1px 5px;border-radius:4px;background:' + badgeBg + ';color:' + badgeColor + ';border:1px solid ' + badgeBorder + ';">' + statusText + '</span>' +
                                             '</div>';
                        } else {
                            targetCellHtml = '<span style="font-size:10px;font-weight:700;color:#94a3b8;background:#f8fafc;padding:2px 7px;border-radius:4px;border:1px solid #e2e8f0;">-</span>';
                        }

                        html += '<tr style="border-bottom:1px solid #f1f5f9;transition:background 0.15s;" onmouseover="this.style.background=\'#f8fafc\'" onmouseout="this.style.background=\'#ffffff\'">' +
                                '  <td style="padding:6px 8px;text-align:center;">' +
                                '    <span style="' + rankBadgeStyle + '">#' + rank + '</span>' +
                                '  </td>' +
                                '  <td style="padding:6px 10px;">' +
                                '    <div style="display:flex;align-items:center;gap:6px;">' +
                                '      <span style="width:8px;height:8px;border-radius:50%;background:' + color + ';flex-shrink:0;"></span>' +
                                '      <div style="font-weight:800;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:210px;" title="' + escapeQuotes(item.item_name) + '">' +
                                         (partCode ? '<span style="font-family:monospace;color:#1d4ed8;margin-right:4px;">' + escapeHtml(partCode) + '</span> ' : '') +
                                         escapeHtml(partName) +
                                '      </div>' +
                                '    </div>' +
                                '  </td>' +
                                '  <td style="padding:6px 10px;text-align:right;white-space:nowrap;">' +
                                '    <span style="font-weight:900;color:#0f172a;">' + val + '</span> ' +
                                '    <span style="font-size:10px;font-weight:700;color:#64748b;">' + unitLabel + '</span>' +
                                '  </td>' +
                                '  <td style="padding:6px 10px;text-align:center;white-space:nowrap;">' +
                                     targetCellHtml +
                                '  </td>' +
                                '</tr>';
                        rank++;
                    });
                    tbody.innerHTML = html;
                }
            }

        } else {
            // Horizontal Bar Chart (Total Agregat)
            if (donutWrapper) donutWrapper.style.display = 'none';
            if (timelineWrapper) timelineWrapper.style.display = 'block';

            var canvas = document.getElementById('chartOverviewParts');
            if (!canvas) return;
            if (chartOverviewPartsInstance) chartOverviewPartsInstance.destroy();

            var ctx = canvas.getContext('2d');

            var barLabelsWithQty = labels.map(function(lbl, i) {
                var v = Number(counts[i] || 0).toLocaleString();
                var p = data.grandMetric > 0 ? (Math.round((counts[i] / data.grandMetric) * 1000) / 10) + '%' : '0%';
                return lbl + '  [' + v + ' ' + unitLabel + ' • ' + p + ']';
            });

            chartOverviewPartsInstance = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: barLabelsWithQty,
                    datasets: [{
                        label: 'Temuan (' + unitLabel + ')',
                        data: counts,
                        backgroundColor: colors,
                        borderRadius: 5,
                        barPercentage: 0.7
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#0f172a',
                            padding: 10,
                            cornerRadius: 8,
                            filter: function(tooltipItem) {
                                return Number(tooltipItem.raw || 0) > 0;
                            },
                            callbacks: {
                                label: function(ctx) {
                                    var val = Number(ctx.raw || 0).toLocaleString();
                                    var pct = data.grandMetric > 0 ? (Math.round((ctx.raw / data.grandMetric) * 1000) / 10) + '%' : '0%';
                                    return ' Temuan: ' + val + ' ' + unitLabel + ' (' + pct + ')';
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            grid: { color: '#f1f5f9' },
                            ticks: {
                                font: { size: 11, weight: '700' },
                                color: '#475569',
                                callback: function(val) {
                                    if (Number.isInteger(val)) return Number(val).toLocaleString();
                                    return null;
                                }
                            }
                        },
                        y: {
                            grid: { display: false },
                            ticks: {
                                font: { size: 11, weight: '800' },
                                color: '#0f172a',
                                callback: function(val, index) {
                                    var lbl = this.getLabelForValue(val);
                                    if (lbl && lbl.length > 36) return lbl.substring(0, 34) + '...';
                                    return lbl;
                                }
                            }
                        }
                    }
                }
            });
        }
    }

    function loadOverviewTrend() {
        if (!overviewState.selectedDefectId) return;

        var url = buildApiUrl('get_trend_timeline', {
            defect_id: overviewState.selectedDefectId,
            trend_interval: overviewState.trendInterval || 'daily',
            weekly_mode: overviewState.weeklyMode || 'cutoff'
        });

        // Parallel fetch threshold value
        var threshUrl = buildApiUrl('get_threshold', {
            mode: 'overview',
            item_id: overviewState.selectedDefectId
        });

        Promise.all([
            fetch(url).then(function(res) { return res.json(); }),
            fetch(threshUrl).then(function(res) { return res.json(); }).catch(function() { return { success: false }; })
        ])
        .then(function(results) {
            var data = results[0];
            var threshData = results[1];

            if (!data.success) return;
            overviewState.cachedTrendData = data;

            // Process threshold info
            if (threshData && threshData.success && threshData.threshold !== null && threshData.threshold !== undefined) {
                overviewState.threshold = Number(threshData.threshold);
                overviewState.thresholdInfo = threshData;
                var lblThresh = document.getElementById('lblOverviewThreshold');
                if (lblThresh) {
                    lblThresh.textContent = overviewState.threshold.toLocaleString() + (threshData.is_specific ? '' : ' (All)');
                }
            } else {
                overviewState.threshold = null;
                overviewState.thresholdInfo = null;
                var lblThresh = document.getElementById('lblOverviewThreshold');
                if (lblThresh) lblThresh.textContent = '-';
            }

            // Refresh tabel sebaran part agar Batas Target sinkron dengan target defect ini
            if (overviewState.cachedPartsData && overviewState.partChartType === 'table') {
                renderOverviewPartsChart(overviewState.cachedPartsData);
            }

            // Update Mini KPIs
            var kpis = data.kpis || {};
            var elTot = document.getElementById('overviewKpiTotal');
            var elUnt = document.getElementById('overviewKpiUnit');
            var elPk  = document.getElementById('overviewKpiPeak');
            var elAvg = document.getElementById('overviewKpiAvg');
            var elAvU = document.getElementById('overviewKpiAvgUnit');
            var elDesc = document.getElementById('overviewTrendIntervalDesc');

            if (elTot) elTot.textContent = Number(kpis.grandMetric || 0).toLocaleString();
            if (elUnt) elUnt.textContent = kpis.unit || 'PCS';
            if (elPk)  elPk.textContent  = kpis.peakLabel || '-';
            if (elAvg) elAvg.textContent = (kpis.avgMetric !== undefined) ? kpis.avgMetric : '0';
            if (elAvU) elAvU.textContent = kpis.unit || 'PCS';

            if (elDesc) {
                if (kpis.intervalType === 'tahun') {
                    elDesc.textContent = 'Distribusi total agregat temuan per tahun kalender.';
                } else if (kpis.intervalType === 'jam') {
                    elDesc.textContent = 'Distribusi temuan per jam (06:00 - 22:00) pada hari inspeksi.';
                } else if (kpis.intervalType === 'minggu') {
                    elDesc.textContent = 'Komparasi temuan mingguan (W1 - W5) sepanjang bulan terpilih.';
                } else if (kpis.intervalType === 'hari') {
                    elDesc.textContent = 'Distribusi temuan harian sepanjang rentang waktu terpilih.';
                } else {
                    elDesc.textContent = 'Distribusi temuan bulanan sepanjang rentang waktu terpilih.';
                }
            }

            renderOverviewTrendChart(data);
        })
        .catch(function(err) {
            console.error("Error loading overview trend:", err);
        });
    }

    function renderOverviewTrendChart(data) {
        var canvas = document.getElementById('chartOverviewTrend');
        if (!canvas) return;
        if (chartOverviewTrendInstance) chartOverviewTrendInstance.destroy();

        var ctx = canvas.getContext('2d');
        var isBar = (overviewState.trendChartType === 'bar');
        var unitLabel = data.kpis ? data.kpis.unit : 'PCS';
        var labels = data.labels || [];
        var counts = data.counts || [];

        var datasets = [];

        // Primary Dataset: Temuan Defect
        datasets.push({
            label: 'Temuan (' + unitLabel + ')',
            data: counts,
            borderColor: '#2563eb',
            backgroundColor: isBar ? '#3b82f6' : 'rgba(37,99,235,0.08)',
            borderWidth: isBar ? 0 : 2.5,
            borderRadius: isBar ? 4 : 0,
            barPercentage: 0.6,
            categoryPercentage: 0.8,
            pointBackgroundColor: '#2563eb',
            pointBorderColor: '#ffffff',
            pointBorderWidth: 2,
            pointRadius: isBar ? 0 : 3.5,
            pointHoverRadius: 6,
            fill: !isBar,
            tension: 0.35,
            order: 2
        });

        // Secondary Dataset: Batas Ambang / Target Maksimal (Garis Merah Putus-putus)
        if (overviewState.threshold !== null && overviewState.threshold !== undefined && labels.length > 0) {
            var targetVal = overviewState.threshold;
            var targetData = labels.map(function() { return targetVal; });
            datasets.push({
                type: 'line',
                label: 'Batas Target: ' + targetVal.toLocaleString() + ' ' + unitLabel,
                data: targetData,
                borderColor: '#dc2626',
                borderWidth: 2,
                borderDash: [6, 5],
                pointRadius: 0,
                pointHoverRadius: 0,
                fill: false,
                tension: 0,
                order: 1
            });
        }

        chartOverviewTrendInstance = new Chart(ctx, {
            type: overviewState.trendChartType,
            data: {
                labels: labels,
                datasets: datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: (overviewState.threshold !== null),
                        position: 'bottom',
                        align: 'center',
                        labels: {
                            boxWidth: 14,
                            boxHeight: 2,
                            font: { size: 10, weight: '700' },
                            color: '#475569',
                            padding: 8
                        }
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        backgroundColor: '#0f172a',
                        titleColor: '#f8fafc',
                        bodyColor: '#e2e8f0',
                        padding: 8,
                        cornerRadius: 6,
                        callbacks: {
                            label: function(context) {
                                var val = Number(context.raw || 0).toLocaleString();
                                if (context.dataset.borderDash) {
                                    return ' 🎯 ' + context.dataset.label;
                                }
                                return ' Temuan: ' + val + ' ' + unitLabel;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 10, weight: '600' }, color: '#64748b', maxRotation: 45 }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: '#f1f5f9' },
                        ticks: {
                            font: { size: 10, weight: '600' },
                            color: '#64748b',
                            callback: function(value) {
                                if (Number.isInteger(value)) return Number(value).toLocaleString();
                                return null;
                            }
                        }
                    }
                }
            }
        });
    }



    function loadOverviewHistory() {
        if (!overviewState.selectedDefectId) return;

        var url = buildApiUrl('get_history', {
            defect_id: overviewState.selectedDefectId
        });

        var tbody = document.getElementById('overviewHistoryTableBody');
        if (tbody) tbody.style.opacity = '0.5';

        fetch(url)
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!data.success) return;
                if (tbody) {
                    tbody.innerHTML = data.html || '<tr><td colspan="10" style="padding:24px;text-align:center;color:#94a3b8;">Tidak ada catatan riwayat temuan inspeksi.</td></tr>';
                    tbody.style.opacity = '1';
                }

                var badge = document.getElementById('overviewTableCountBadge');
                if (badge) badge.textContent = (data.count || 0) + ' Temuan';

                var note = document.getElementById('overviewTableFooterNote');
                if (note) {
                    note.innerHTML = 'Menampilkan <strong>' + (data.count || 0) + '</strong> temuan inspeksi terperinci untuk defect: <strong>' + escapeHtml(overviewState.selectedDefectName) + '</strong>';
                }

                // Inisialisasi dan terapkan filter Excel jika ada data
                initOverviewExcelFilters();
                executeOverviewFiltering();
            })
            .catch(function(err) {
                console.error("Error loading overview history:", err);
                if (tbody) tbody.style.opacity = '1';
            });
    }

    function clearOverviewChartsAndTable() {
        if (chartOverviewTrendInstance) {
            chartOverviewTrendInstance.destroy();
            chartOverviewTrendInstance = null;
        }
        if (chartOverviewPartsInstance) {
            chartOverviewPartsInstance.destroy();
            chartOverviewPartsInstance = null;
        }
        if (chartOverviewPartsDonutInstance) {
            chartOverviewPartsDonutInstance.destroy();
            chartOverviewPartsDonutInstance = null;
        }

        // Bersihkan canvas donut & bar secara manual jika masih ada bekas render
        var cvsDonut = document.getElementById('chartOverviewPartsDonut');
        if (cvsDonut) {
            var ctxD = cvsDonut.getContext('2d');
            if (ctxD) ctxD.clearRect(0, 0, cvsDonut.width, cvsDonut.height);
        }
        var cvsBar = document.getElementById('chartOverviewParts');
        if (cvsBar) {
            var ctxB = cvsBar.getContext('2d');
            if (ctxB) ctxB.clearRect(0, 0, cvsBar.width, cvsBar.height);
        }
        var cvsTrend = document.getElementById('chartOverviewTrend');
        if (cvsTrend) {
            var ctxT = cvsTrend.getContext('2d');
            if (ctxT) ctxT.clearRect(0, 0, cvsTrend.width, cvsTrend.height);
        }

        // Reset list part di sebelah donut
        var partListContainer = document.getElementById('overviewPartCustomList');
        if (partListContainer) {
            partListContainer.innerHTML = '<div style="color:#94a3b8;font-size:11px;padding:12px;">Tidak ada temuan part pada rentang ini.</div>';
        }

        // Reset display dropdown jika belum direset
        var displayBox = document.getElementById('overviewDropdownSelectedDisplay');
        if (displayBox && !overviewState.selectedDefectId) {
            displayBox.innerHTML = '<span style="font-size:11px;color:#64748b;font-style:italic;">Tidak ada data defect</span>';
        }

        // Reset state cache
        overviewState.cachedPartsData = null;
        overviewState.cachedTrendData = null;

        var tbody = document.getElementById('overviewHistoryTableBody');
        if (tbody) {
            tbody.innerHTML = '<tr><td colspan="10" style="padding:24px;text-align:center;color:#94a3b8;">Tidak ada data pada filter ini.</td></tr>';
        }
        var countBadge = document.getElementById('overviewTableCountBadge');
        if (countBadge) countBadge.textContent = '0 Temuan';
        var elTot = document.getElementById('overviewKpiTotal');
        if (elTot) elTot.textContent = '0';
        var elPk = document.getElementById('overviewKpiPeak');
        if (elPk) elPk.textContent = '-';
        var elAvg = document.getElementById('overviewKpiAvg');
        if (elAvg) elAvg.textContent = '0';
    }

    // ── PART OVERVIEW JAVASCRIPT CONTROLLER (EXECUTIVE PART-CENTRIC VIEW) ─────
    function loadPartOverviewMode() {
        loadPartOverviewRanking();
    }

    function refreshPartOverviewMode() {
        loadPartOverviewRanking(function() {
            if (partOverviewState.selectedPartId) {
                loadPartOverviewTrend();
                loadPartOverviewDefects();
                loadPartOverviewHistory();
            }
        });
    }

    function loadPartOverviewRanking(callback) {
        var url = buildApiUrl('get_part_ranking');

        fetch(url)
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!data.success) return;
                partOverviewState.cachedParts = data;

                // Render Part Dropdown
                renderPartOverviewRankPills(data);

                // Auto-select rank 1 if no part selected or previously selected no longer in list
                var items = data.items || [];
                var validItems = items.filter(function(it) { return !it.is_other && it.item_id; });

                if (validItems.length > 0) {
                    var currentPartStillExists = validItems.some(function(it) {
                        return it.item_id === partOverviewState.selectedPartId;
                    });

                    if (!partOverviewState.selectedPartId || !currentPartStillExists) {
                        selectPartOverviewItem(validItems[0].item_id, validItems[0].part_code, validItems[0].part_name);
                    } else {
                        highlightPartOverviewRankPill(partOverviewState.selectedPartId);
                        if (typeof callback === 'function') callback();
                    }
                } else {
                    partOverviewState.selectedPartId = null;
                    partOverviewState.selectedPartCode = '';
                    partOverviewState.selectedPartName = '';
                    var lbl = document.getElementById('lblPartOverviewPartName');
                    if (lbl) lbl.textContent = 'Tidak Ada Data';
                    document.querySelectorAll('.partOverviewCardPartBadge').forEach(function(badge) {
                        badge.textContent = '-';
                    });
                    var displayBox = document.getElementById('partOverviewDropdownSelectedDisplay');
                    if (displayBox) {
                        displayBox.innerHTML = '<span style="font-size:11px;color:#94a3b8;font-style:italic;">Tidak ada data part</span>';
                    }
                    clearPartOverviewChartsAndTable();
                }
            })
            .catch(function(err) {
                console.error("Error loading part overview ranking:", err);
            });
    }

    function togglePartOverviewMenu(event) {
        event.stopPropagation();
        var menu = document.getElementById('partOverviewMenuDropdown');
        if (!menu) return;
        var isVisible = (menu.style.display === 'block');
        menu.style.display = isVisible ? 'none' : 'block';
    }

    function closePartOverviewMenu() {
        var menu = document.getElementById('partOverviewMenuDropdown');
        if (menu) menu.style.display = 'none';
    }

    function renderPartOverviewRankPills(data) {
        var menuContainer = document.getElementById('partOverviewMenuDropdown');
        if (!menuContainer) return;

        var items = data.items || [];
        var validItems = items.filter(function(it) { return !it.is_other && it.item_id; });
        var unitLabel = data.unit || 'PCS';

        if (validItems.length === 0) {
            menuContainer.innerHTML = '<div style="padding:12px;text-align:center;color:#94a3b8;font-size:11px;">Tidak ada data part</div>';
            return;
        }

        var html = '';
        validItems.forEach(function(item, idx) {
            var rankNum = idx + 1;
            var valFormatted = Number(item.val || 0).toLocaleString();
            var pct = data.grandMetric > 0 ? (Math.round((item.val / data.grandMetric) * 1000) / 10) + '%' : '0%';
            var isSelected = (partOverviewState.selectedPartId === item.item_id);

            // Style badge warna peringkat (Medali)
            var rankBadgeBg, rankBadgeColor, rankBorder;
            if (rankNum === 1) {
                rankBadgeBg = 'linear-gradient(135deg, #fef3c7 0%, #fde68a 100%)';
                rankBadgeColor = '#92400e';
                rankBorder = '#f59e0b';
            } else if (rankNum === 2) {
                rankBadgeBg = 'linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%)';
                rankBadgeColor = '#334155';
                rankBorder = '#94a3b8';
            } else if (rankNum === 3) {
                rankBadgeBg = 'linear-gradient(135deg, #ffedd5 0%, #fed7aa 100%)';
                rankBadgeColor = '#9a3412';
                rankBorder = '#ea580c';
            } else {
                rankBadgeBg = '#f8fafc';
                rankBadgeColor = '#64748b';
                rankBorder = '#cbd5e1';
            }

            var itemBg = isSelected ? '#eff6ff' : '#ffffff';
            var itemBorder = isSelected ? '1.5px solid #3b82f6' : '1px solid #f1f5f9';
            var partCode = item.part_code || item.item_name || '-';
            var partName = item.part_name || '';

            html += '<div onclick="selectPartOverviewItem(' + item.item_id + ', \'' + escapeQuotes(partCode) + '\', \'' + escapeQuotes(partName) + '\')" ' +
                    '     class="part-overview-menu-item" ' +
                    '     data-id="' + item.item_id + '" ' +
                    '     style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:7px 10px;margin-bottom:3px;border-radius:8px;cursor:pointer;background:' + itemBg + ';border:' + itemBorder + ';transition:all 0.15s;" ' +
                    '     onmouseover="if (!this.classList.contains(\'is-selected\')) this.style.background=\'#f8fafc\'" ' +
                    '     onmouseout="if (!this.classList.contains(\'is-selected\')) this.style.background=\'#ffffff\'">' +
                    '  <div style="display:flex;align-items:center;gap:8px;overflow:hidden;flex:1;">' +
                    '    <span class="part-rank-badge" style="font-size:10px;font-weight:900;padding:2px 7px;border-radius:5px;background:' + rankBadgeBg + ';color:' + rankBadgeColor + ';border:1px solid ' + rankBorder + ';flex-shrink:0;">' +
                    '      #' + rankNum +
                    '    </span>' +
                    '    <span class="part-color-dot" style="width:9px;height:9px;border-radius:50%;background:' + (item.color || '#3b82f6') + ';flex-shrink:0;"></span>' +
                    '    <div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' +
                    '      <span class="part-code-text" style="font-size:11.5px;font-weight:800;color:#0f172a;font-family:monospace;" title="' + escapeQuotes(partCode) + '">' +
                             escapeHtml(partCode) +
                    '      </span>' +
                    (partName ? ' <span class="part-name-text" style="font-size:10.5px;color:#64748b;font-weight:600;">(' + escapeHtml(partName) + ')</span>' : '') +
                    '    </div>' +
                    '  </div>' +
                    '  <div style="display:flex;align-items:center;gap:5px;flex-shrink:0;font-size:11px;font-weight:900;">' +
                    '    <span class="part-val-badge" style="color:#b91c1c;background:#fef2f2;padding:2px 6px;border-radius:4px;border:1px solid #fecaca;">' + valFormatted + ' ' + unitLabel + '</span>' +
                    '    <span class="part-pct-badge" style="color:#2563eb;background:#eff6ff;padding:2px 5px;border-radius:4px;border:1px solid #bfdbfe;font-size:10px;">' + pct + '</span>' +
                    '  </div>' +
                    '</div>';
        });

        menuContainer.innerHTML = html;
        highlightPartOverviewRankPill(partOverviewState.selectedPartId);
    }

    function highlightPartOverviewRankPill(partId) {
        var menuContainer = document.getElementById('partOverviewMenuDropdown');
        if (!menuContainer) return;

        var items = menuContainer.querySelectorAll('.part-overview-menu-item');
        var displayBox = document.getElementById('partOverviewDropdownSelectedDisplay');

        items.forEach(function(el) {
            var id = parseInt(el.getAttribute('data-id'), 10);
            if (id === partId) {
                el.classList.add('is-selected');
                el.style.background = '#eff6ff';
                el.style.borderColor = '#3b82f6';

                // Update teks dan badge di tombol utama
                if (displayBox) {
                    var rankBadge = el.querySelector('.part-rank-badge');
                    var dot       = el.querySelector('.part-color-dot');
                    var codeSpan  = el.querySelector('.part-code-text');
                    var pcsBadge  = el.querySelector('.part-val-badge');
                    var pctBadge  = el.querySelector('.part-pct-badge');

                    if (rankBadge && codeSpan && pcsBadge && pctBadge) {
                        displayBox.innerHTML = 
                            '<span style="' + rankBadge.getAttribute('style') + '">' + rankBadge.textContent.trim() + '</span>' +
                            (dot ? '<span style="' + dot.getAttribute('style') + '"></span>' : '') +
                            '<span style="font-size:12px;font-weight:900;color:#0f172a;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-family:monospace;">' + codeSpan.textContent.trim() + '</span>' +
                            '<span style="' + pcsBadge.getAttribute('style') + '">' + pcsBadge.textContent.trim() + '</span>' +
                            '<span style="' + pctBadge.getAttribute('style') + '">' + pctBadge.textContent.trim() + '</span>';
                    }
                }
            } else {
                el.classList.remove('is-selected');
                el.style.background = '#ffffff';
                el.style.borderColor = '#f1f5f9';
            }
        });
    }

    function selectPartOverviewItem(partId, partCode, partName) {
        partOverviewState.selectedPartId = partId;
        partOverviewState.selectedPartCode = partCode;
        partOverviewState.selectedPartName = partName;

        var fullPartText = partCode + (partName ? ' - ' + partName : '');
        var lbl = document.getElementById('lblPartOverviewPartName');
        if (lbl) lbl.textContent = fullPartText;

        document.querySelectorAll('.partOverviewCardPartBadge').forEach(function(badge) {
            badge.textContent = fullPartText || '-';
        });

        highlightPartOverviewRankPill(partId);
        closePartOverviewMenu();

        // Load 3 components in parallel
        loadPartOverviewTrend();
        loadPartOverviewDefects();
        loadPartOverviewHistory();
    }

    function switchPartOverviewInterval(interval) {
        partOverviewState.trendInterval = interval;
        var btnDaily  = document.getElementById('btnPartOverviewIntervalDaily');
        var btnWeekly = document.getElementById('btnPartOverviewIntervalWeekly');

        if (btnDaily && btnWeekly) {
            if (interval === 'daily') {
                btnDaily.style.background = '#0f172a';
                btnDaily.style.color = '#ffffff';
                btnDaily.style.boxShadow = '0 1px 2px rgba(15,23,42,0.2)';
                btnWeekly.style.background = 'transparent';
                btnWeekly.style.color = '#64748b';
                btnWeekly.style.boxShadow = 'none';
            } else {
                btnWeekly.style.background = '#0f172a';
                btnWeekly.style.color = '#ffffff';
                btnWeekly.style.boxShadow = '0 1px 2px rgba(15,23,42,0.2)';
                btnDaily.style.background = 'transparent';
                btnDaily.style.color = '#64748b';
                btnDaily.style.boxShadow = 'none';
            }
        }

        var grpWeeklyMode = document.getElementById('partOverviewWeeklyModeGroup');
        if (grpWeeklyMode) {
            grpWeeklyMode.style.display = (interval === 'weekly') ? 'inline-flex' : 'none';
        }

        loadPartOverviewTrend();
    }

    function switchPartOverviewWeeklyMode(mode) {
        partOverviewState.weeklyMode = mode;
        var btnCutoff = document.getElementById('btnPartOverviewWeeklyCutoff');
        var btnFull   = document.getElementById('btnPartOverviewWeeklyFull');

        if (btnCutoff && btnFull) {
            if (mode === 'cutoff') {
                btnCutoff.style.background = '#0f172a';
                btnCutoff.style.color = '#ffffff';
                btnCutoff.style.boxShadow = '0 1px 2px rgba(15,23,42,0.2)';
                btnFull.style.background = 'transparent';
                btnFull.style.color = '#64748b';
                btnFull.style.boxShadow = 'none';
            } else {
                btnFull.style.background = '#0f172a';
                btnFull.style.color = '#ffffff';
                btnFull.style.boxShadow = '0 1px 2px rgba(15,23,42,0.2)';
                btnCutoff.style.background = 'transparent';
                btnCutoff.style.color = '#64748b';
                btnCutoff.style.boxShadow = 'none';
            }
        }

        loadPartOverviewTrend();
    }

    function switchPartOverviewTrendType(type) {
        partOverviewState.trendChartType = type;
        var btnLine = document.getElementById('btnPartOverviewTrendLine');
        var btnBar  = document.getElementById('btnPartOverviewTrendBar');

        if (btnLine && btnBar) {
            if (type === 'line') {
                btnLine.style.background = '#0f172a';
                btnLine.style.color = '#ffffff';
                btnLine.style.boxShadow = '0 1px 2px rgba(15,23,42,0.2)';
                btnBar.style.background = 'transparent';
                btnBar.style.color = '#64748b';
                btnBar.style.boxShadow = 'none';
            } else {
                btnBar.style.background = '#0f172a';
                btnBar.style.color = '#ffffff';
                btnBar.style.boxShadow = '0 1px 2px rgba(15,23,42,0.2)';
                btnLine.style.background = 'transparent';
                btnLine.style.color = '#64748b';
                btnLine.style.boxShadow = 'none';
            }
        }

        if (partOverviewState.cachedTrendData) {
            renderPartOverviewTrendChart(partOverviewState.cachedTrendData);
        }
    }

    function switchPartOverviewDefectChartType(type) {
        partOverviewState.defectChartType = type;
        var btnDonut = document.getElementById('btnPartOverviewDefectDonut');
        var btnTotal = document.getElementById('btnPartOverviewDefectTotal');
        var btnLine  = document.getElementById('btnPartOverviewDefectLine');
        var btnBar   = document.getElementById('btnPartOverviewDefectBar');

        var allBtns = [btnDonut, btnTotal, btnLine, btnBar];
        allBtns.forEach(function(b) {
            if (b) {
                b.style.background = 'transparent';
                b.style.color = '#64748b';
                b.style.boxShadow = 'none';
            }
        });

        if ((type === 'table' || type === 'doughnut') && btnDonut) {
            btnDonut.style.background = '#0f172a';
            btnDonut.style.color = '#ffffff';
            btnDonut.style.boxShadow = '0 1px 2px rgba(15,23,42,0.2)';
        } else if (type === 'bar_total' && btnTotal) {
            btnTotal.style.background = '#0f172a';
            btnTotal.style.color = '#ffffff';
            btnTotal.style.boxShadow = '0 1px 2px rgba(15,23,42,0.2)';
        } else if (type === 'line_trend' && btnLine) {
            btnLine.style.background = '#0f172a';
            btnLine.style.color = '#ffffff';
            btnLine.style.boxShadow = '0 1px 2px rgba(15,23,42,0.2)';
        } else if (type === 'bar_trend' && btnBar) {
            btnBar.style.background = '#0f172a';
            btnBar.style.color = '#ffffff';
            btnBar.style.boxShadow = '0 1px 2px rgba(15,23,42,0.2)';
        }

        if (type === 'line_trend' || type === 'bar_trend') {
            loadPartOverviewDefectsTimeline();
        } else {
            if (partOverviewState.cachedDefectsData) {
                renderPartOverviewDefectsChart(partOverviewState.cachedDefectsData);
            } else {
                loadPartOverviewDefects();
            }
        }
    }

    function loadPartOverviewTrend() {
        if (!partOverviewState.selectedPartId) return;

        var url = buildApiUrl('get_trend_timeline', {
            part_id: partOverviewState.selectedPartId,
            trend_interval: partOverviewState.trendInterval || 'daily',
            weekly_mode: partOverviewState.weeklyMode || 'cutoff'
        });

        // Parallel fetch threshold value for part
        var threshUrl = buildApiUrl('get_threshold', {
            mode: 'part_overview',
            item_id: partOverviewState.selectedPartId
        });

        Promise.all([
            fetch(url).then(function(res) { return res.json(); }),
            fetch(threshUrl).then(function(res) { return res.json(); }).catch(function() { return { success: false }; })
        ])
        .then(function(results) {
            var data = results[0];
            var threshData = results[1];

            if (!data.success) return;
            partOverviewState.cachedTrendData = data;

            // Process threshold info
            if (threshData && threshData.success && threshData.threshold !== null && threshData.threshold !== undefined) {
                partOverviewState.threshold = Number(threshData.threshold);
                partOverviewState.thresholdInfo = threshData;
                var lblThresh = document.getElementById('lblPartOverviewThreshold');
                if (lblThresh) {
                    lblThresh.textContent = partOverviewState.threshold.toLocaleString() + (threshData.is_specific ? '' : ' (All)');
                }
            } else {
                partOverviewState.threshold = null;
                partOverviewState.thresholdInfo = null;
                var lblThresh = document.getElementById('lblPartOverviewThreshold');
                if (lblThresh) lblThresh.textContent = '-';
            }

            // Refresh tabel sebaran defect agar Batas Target sinkron dengan target part ini
            if (partOverviewState.cachedDefectsData && partOverviewState.defectChartType === 'table') {
                renderPartOverviewDefectsChart(partOverviewState.cachedDefectsData);
            }

            // Update Mini KPIs
            var kpis = data.kpis || {};
            var elTot = document.getElementById('partOverviewKpiTotal');
            var elUnt = document.getElementById('partOverviewKpiUnit');
            var elPk  = document.getElementById('partOverviewKpiPeak');
            var elAvg = document.getElementById('partOverviewKpiAvg');
            var elAvU = document.getElementById('partOverviewKpiAvgUnit');
            var elDesc = document.getElementById('partOverviewTrendIntervalDesc');

            if (elTot) elTot.textContent = Number(kpis.grandMetric || 0).toLocaleString();
            if (elUnt) elUnt.textContent = kpis.unit || 'PCS';
            if (elPk)  elPk.textContent  = kpis.peakLabel || '-';
            if (elAvg) elAvg.textContent = (kpis.avgMetric !== undefined) ? kpis.avgMetric : '0';
            if (elAvU) elAvU.textContent = kpis.unit || 'PCS';

            if (elDesc) {
                if (kpis.intervalType === 'tahun') {
                    elDesc.textContent = 'Distribusi total agregat temuan part per tahun kalender.';
                } else if (kpis.intervalType === 'jam') {
                    elDesc.textContent = 'Distribusi temuan part per jam (06:00 - 22:00) pada hari inspeksi.';
                } else if (kpis.intervalType === 'minggu') {
                    elDesc.textContent = 'Komparasi temuan part mingguan (W1 - W5) sepanjang bulan terpilih.';
                } else if (kpis.intervalType === 'hari') {
                    elDesc.textContent = 'Distribusi temuan part harian sepanjang rentang waktu terpilih.';
                } else {
                    elDesc.textContent = 'Distribusi temuan part bulanan sepanjang rentang waktu terpilih.';
                }
            }

            renderPartOverviewTrendChart(data);
        })
        .catch(function(err) {
            console.error("Error loading part overview trend:", err);
        });
    }

    function renderPartOverviewTrendChart(data) {
        var canvas = document.getElementById('chartPartOverviewTrend');
        if (!canvas) return;
        if (chartPartOverviewTrendInstance) chartPartOverviewTrendInstance.destroy();

        var ctx = canvas.getContext('2d');
        var isBar = (partOverviewState.trendChartType === 'bar');
        var unitLabel = data.kpis ? data.kpis.unit : 'PCS';
        var labels = data.labels || [];
        var counts = data.counts || [];

        var datasets = [];

        // Primary Dataset: Temuan Part
        datasets.push({
            label: 'Temuan (' + unitLabel + ')',
            data: counts,
            borderColor: '#2563eb',
            backgroundColor: isBar ? '#3b82f6' : 'rgba(37,99,235,0.08)',
            borderWidth: isBar ? 0 : 2.5,
            borderRadius: isBar ? 4 : 0,
            barPercentage: 0.6,
            categoryPercentage: 0.8,
            pointBackgroundColor: '#2563eb',
            pointBorderColor: '#ffffff',
            pointBorderWidth: 2,
            pointRadius: isBar ? 0 : 3.5,
            pointHoverRadius: 6,
            fill: !isBar,
            tension: 0.35,
            order: 2
        });

        // Secondary Dataset: Batas Ambang / Target Maksimal (Garis Merah Putus-putus)
        if (partOverviewState.threshold !== null && partOverviewState.threshold !== undefined && labels.length > 0) {
            var targetVal = partOverviewState.threshold;
            var targetData = labels.map(function() { return targetVal; });
            datasets.push({
                type: 'line',
                label: 'Batas Target: ' + targetVal.toLocaleString() + ' ' + unitLabel,
                data: targetData,
                borderColor: '#dc2626',
                borderWidth: 2,
                borderDash: [6, 5],
                pointRadius: 0,
                pointHoverRadius: 0,
                fill: false,
                tension: 0,
                order: 1
            });
        }

        chartPartOverviewTrendInstance = new Chart(ctx, {
            type: partOverviewState.trendChartType,
            data: {
                labels: labels,
                datasets: datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: (partOverviewState.threshold !== null),
                        position: 'bottom',
                        align: 'center',
                        labels: {
                            boxWidth: 14,
                            boxHeight: 2,
                            font: { size: 10, weight: '700' },
                            color: '#475569',
                            padding: 8
                        }
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        backgroundColor: '#0f172a',
                        titleColor: '#f8fafc',
                        bodyColor: '#e2e8f0',
                        padding: 8,
                        cornerRadius: 6,
                        callbacks: {
                            label: function(context) {
                                var val = Number(context.raw || 0).toLocaleString();
                                if (context.dataset.borderDash) {
                                    return ' 🎯 ' + context.dataset.label;
                                }
                                return ' Temuan: ' + val + ' ' + unitLabel;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 10, weight: '600' }, color: '#64748b', maxRotation: 45 }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: '#f1f5f9' },
                        ticks: {
                            font: { size: 10, weight: '600' },
                            color: '#64748b',
                            callback: function(value) {
                                if (Number.isInteger(value)) return Number(value).toLocaleString();
                                return null;
                            }
                        }
                    }
                }
            }
        });
    }

    function loadPartOverviewDefects() {
        if (!partOverviewState.selectedPartId) return;

        var url = buildApiUrl('get_part_defects', {
            part_id: partOverviewState.selectedPartId
        });

        fetch(url)
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!data.success) return;
                partOverviewState.cachedDefectsData = data;
                renderPartOverviewDefectsChart(data);
            })
            .catch(function(err) {
                console.error("Error loading part overview defects:", err);
            });
    }

    function renderPartOverviewDefectsChart(data) {
        var chartType = partOverviewState.defectChartType;
        var unitLabel = data.unit || 'PCS';

        var donutWrapper    = document.getElementById('partOverviewDefectDonutWrapper');
        var timelineWrapper = document.getElementById('partOverviewDefectTimelineWrapper');

        // Prepare labels, values, and raw items
        var items = data.items || [];
        var labels = (data.chartLabels || []).slice();
        var counts = (data.chartData || []).slice();
        var colors = (data.chartColors || []).slice();

        // If no data
        if (counts.length === 0 || data.grandMetric === 0) {
            labels = ['Tidak ada defect'];
            counts = [1];
            colors = ['#e2e8f0'];
        }

        if (chartType === 'table' || chartType === 'doughnut') {
            if (donutWrapper) donutWrapper.style.display = 'block';
            if (timelineWrapper) timelineWrapper.style.display = 'none';

            var tbody = document.getElementById('partOverviewDefectTableBody');
            if (tbody) {
                if (!items || items.length === 0 || data.grandMetric === 0) {
                    tbody.innerHTML = '<tr><td colspan="4" style="padding:22px;text-align:center;color:#94a3b8;font-size:11px;">Tidak ada temuan defect pada part ini.</td></tr>';
                } else {
                    var html = '';
                    var rank = 1;
                    items.forEach(function(item) {
                        var val = Number(item.val || 0).toLocaleString();
                        var pct = data.grandMetric > 0 ? (Math.round((item.val / data.grandMetric) * 1000) / 10) : 0;
                        var color = item.color || '#059669';
                        var defectName = item.item_name || '-';

                        var rankBadgeStyle = 'font-size:10px;font-weight:900;padding:2px 6px;border-radius:4px;';
                        if (rank === 1) {
                            rankBadgeStyle += 'background:#fef3c7;color:#92400e;border:1px solid #fcd34d;';
                        } else if (rank === 2) {
                            rankBadgeStyle += 'background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;';
                        } else if (rank === 3) {
                            rankBadgeStyle += 'background:#ffedd5;color:#9a3412;border:1px solid #fdba74;';
                        } else {
                            rankBadgeStyle += 'background:#f8fafc;color:#64748b;border:1px solid #e2e8f0;';
                        }

                        var rawValNum = Number(item.val || 0);
                        var targetVal = (partOverviewState.threshold !== null && partOverviewState.threshold !== undefined) 
                            ? Number(partOverviewState.threshold) 
                            : ((item.threshold_val !== undefined && item.threshold_val !== null) ? Number(item.threshold_val) : null);

                        var targetCellHtml = '';
                        if (targetVal !== null) {
                            var isOver = (rawValNum > targetVal);
                            var badgeBg = isOver ? '#fef2f2' : '#f0fdf4';
                            var badgeColor = isOver ? '#b91c1c' : '#15803d';
                            var badgeBorder = isOver ? '#fecaca' : '#bbf7d0';
                            var statusText = isOver ? '⚠️ Over' : '✓ Aman';

                            targetCellHtml = '<div style="display:flex;align-items:center;justify-content:center;gap:5px;">' +
                                             '  <span style="font-weight:900;color:' + (isOver ? '#dc2626' : '#0f172a') + ';font-size:11px;">' + targetVal.toLocaleString() + '</span> ' +
                                             '  <span style="font-size:9.5px;font-weight:700;color:#64748b;">' + unitLabel + '</span>' +
                                             '  <span style="font-size:9px;font-weight:800;padding:1px 5px;border-radius:4px;background:' + badgeBg + ';color:' + badgeColor + ';border:1px solid ' + badgeBorder + ';">' + statusText + '</span>' +
                                             '</div>';
                        } else {
                            targetCellHtml = '<span style="font-size:10px;font-weight:700;color:#94a3b8;background:#f8fafc;padding:2px 7px;border-radius:4px;border:1px solid #e2e8f0;">-</span>';
                        }

                        html += '<tr style="border-bottom:1px solid #f1f5f9;transition:background 0.15s;" onmouseover="this.style.background=\'#f8fafc\'" onmouseout="this.style.background=\'#ffffff\'">' +
                                '  <td style="padding:6px 8px;text-align:center;">' +
                                '    <span style="' + rankBadgeStyle + '">#' + rank + '</span>' +
                                '  </td>' +
                                '  <td style="padding:6px 10px;">' +
                                '    <div style="display:flex;align-items:center;gap:6px;">' +
                                '      <span style="width:8px;height:8px;border-radius:50%;background:' + color + ';flex-shrink:0;"></span>' +
                                '      <div style="font-weight:800;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:220px;" title="' + escapeQuotes(defectName) + '">' +
                                         escapeHtml(defectName) +
                                '      </div>' +
                                '    </div>' +
                                '  </td>' +
                                '  <td style="padding:6px 10px;text-align:right;white-space:nowrap;">' +
                                '    <span style="font-weight:900;color:#0f172a;">' + val + '</span> ' +
                                '    <span style="font-size:10px;font-weight:700;color:#64748b;">' + unitLabel + '</span>' +
                                '  </td>' +
                                '  <td style="padding:6px 10px;text-align:center;white-space:nowrap;">' +
                                     targetCellHtml +
                                '  </td>' +
                                '</tr>';
                        rank++;
                    });
                    tbody.innerHTML = html;
                }
            }

        } else {
            // Horizontal Bar Chart (Total Agregat)
            if (donutWrapper) donutWrapper.style.display = 'none';
            if (timelineWrapper) timelineWrapper.style.display = 'block';

            var canvas = document.getElementById('chartPartOverviewDefects');
            if (!canvas) return;
            if (chartPartOverviewDefectsInstance) chartPartOverviewDefectsInstance.destroy();

            var ctx = canvas.getContext('2d');

            var barLabelsWithQty = labels.map(function(lbl, i) {
                var v = Number(counts[i] || 0).toLocaleString();
                var p = data.grandMetric > 0 ? (Math.round((counts[i] / data.grandMetric) * 1000) / 10) + '%' : '0%';
                return lbl + '  [' + v + ' ' + unitLabel + ' • ' + p + ']';
            });

            chartPartOverviewDefectsInstance = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: barLabelsWithQty,
                    datasets: [{
                        label: 'Temuan (' + unitLabel + ')',
                        data: counts,
                        backgroundColor: colors,
                        borderRadius: 5,
                        barPercentage: 0.7
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#0f172a',
                            padding: 10,
                            cornerRadius: 8,
                            filter: function(tooltipItem) {
                                return Number(tooltipItem.raw || 0) > 0;
                            },
                            callbacks: {
                                label: function(ctx) {
                                    var val = Number(ctx.raw || 0).toLocaleString();
                                    var pct = data.grandMetric > 0 ? (Math.round((ctx.raw / data.grandMetric) * 1000) / 10) + '%' : '0%';
                                    return ' Temuan: ' + val + ' ' + unitLabel + ' (' + pct + ')';
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            grid: { color: '#f1f5f9' },
                            ticks: {
                                font: { size: 11, weight: '700' },
                                color: '#475569',
                                callback: function(val) {
                                    if (Number.isInteger(val)) return Number(val).toLocaleString();
                                    return null;
                                }
                            }
                        },
                        y: {
                            grid: { display: false },
                            ticks: {
                                font: { size: 11, weight: '800' },
                                color: '#0f172a',
                                callback: function(val, index) {
                                    var lbl = this.getLabelForValue(val);
                                    if (lbl && lbl.length > 36) return lbl.substring(0, 34) + '...';
                                    return lbl;
                                }
                            }
                        }
                    }
                }
            });
        }
    }

    function loadPartOverviewDefectsTimeline() {
        if (!partOverviewState.selectedPartId) return;

        var url = buildApiUrl('get_part_defect_stacked_trend', {
            part_id: partOverviewState.selectedPartId,
            top_rank: funnelState.topRank
        });

        fetch(url)
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!data.success) return;
                partOverviewState.cachedDefectsTimelineData = data;
                renderPartOverviewDefectsTimelineChart(data);
            })
            .catch(function(err) {
                console.error("Error loading part overview defects timeline:", err);
            });
    }

    function renderPartOverviewDefectsTimelineChart(data) {
        var donutWrapper    = document.getElementById('partOverviewDefectDonutWrapper');
        var timelineWrapper = document.getElementById('partOverviewDefectTimelineWrapper');
        if (donutWrapper) donutWrapper.style.display = 'none';
        if (timelineWrapper) timelineWrapper.style.display = 'block';

        var canvas = document.getElementById('chartPartOverviewDefects');
        if (!canvas) return;
        if (chartPartOverviewDefectsInstance) chartPartOverviewDefectsInstance.destroy();

        var ctx = canvas.getContext('2d');
        var isLine = (partOverviewState.defectChartType === 'line_trend');
        var unitLabel = data.unit || 'PCS';

        var palette = ['#e11d48', '#2563eb', '#f59e0b', '#059669', '#d946ef', '#06b6d4', '#ea580c', '#64748b'];

        var datasets = (data.datasets || []).map(function(ds, idx) {
            var color = palette[idx % palette.length];
            if (isLine) {
                return {
                    label: ds.label,
                    data: ds.data,
                    borderColor: color,
                    backgroundColor: color,
                    borderWidth: 2.5,
                    pointBackgroundColor: color,
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 3.5,
                    fill: false,
                    tension: 0.3,
                    order: 2
                };
            } else {
                return {
                    label: ds.label,
                    data: ds.data,
                    backgroundColor: color,
                    borderRadius: 4,
                    barPercentage: 0.7,
                    categoryPercentage: 0.8,
                    stack: 'defectStack',
                    order: 2
                };
            }
        });

        // Sync Batas Target Merah ke Diagram Kanan Part Overview jika ada
        var labels = data.labels || [];
        if (partOverviewState.threshold !== null && partOverviewState.threshold !== undefined && labels.length > 0) {
            var targetVal = partOverviewState.threshold;
            var targetData = labels.map(function() { return targetVal; });
            datasets.push({
                type: 'line',
                label: '🎯 Batas Target: ' + targetVal.toLocaleString() + ' ' + unitLabel,
                data: targetData,
                borderColor: '#dc2626',
                borderWidth: 2,
                borderDash: [6, 5],
                pointRadius: 0,
                pointHoverRadius: 0,
                fill: false,
                tension: 0,
                order: 1
            });
        }

        chartPartOverviewDefectsInstance = new Chart(ctx, {
            type: isLine ? 'line' : 'bar',
            data: {
                labels: labels,
                datasets: datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        align: 'center',
                        labels: {
                            boxWidth: 12,
                            font: { size: 10, weight: '700' },
                            color: '#475569',
                            padding: 10
                        }
                    },
                    tooltip: {
                        mode: isLine ? 'nearest' : 'index',
                        intersect: false,
                        backgroundColor: '#0f172a',
                        titleColor: '#f8fafc',
                        bodyColor: '#e2e8f0',
                        padding: 10,
                        cornerRadius: 8,
                        filter: function(tooltipItem) {
                            return Number(tooltipItem.raw || 0) > 0;
                        },
                        callbacks: {
                            label: function(ctx) {
                                var val = Number(ctx.raw || 0).toLocaleString();
                                return ' ' + ctx.dataset.label + ': ' + val + ' ' + unitLabel;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 10, weight: '600' }, color: '#64748b', maxRotation: 45 }
                    },
                    y: {
                        beginAtZero: true,
                        stacked: !isLine,
                        grid: { color: '#f1f5f9' },
                        ticks: {
                            font: { size: 10, weight: '600' },
                            color: '#64748b',
                            callback: function(value) {
                                if (Number.isInteger(value)) return Number(value).toLocaleString();
                                return null;
                            }
                        }
                    }
                }
            }
        });
    }

    function loadPartOverviewHistory() {
        if (!partOverviewState.selectedPartId) return;

        var url = buildApiUrl('get_part_history', {
            part_id: partOverviewState.selectedPartId
        });

        var tbody = document.getElementById('partOverviewHistoryTableBody');
        if (tbody) tbody.style.opacity = '0.5';

        fetch(url)
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!data.success) return;
                if (tbody) {
                    tbody.innerHTML = data.html || '<tr><td colspan="10" style="padding:24px;text-align:center;color:#94a3b8;">Tidak ada catatan riwayat temuan inspeksi.</td></tr>';
                    tbody.style.opacity = '1';
                }

                var badge = document.getElementById('partOverviewTableCountBadge');
                if (badge) badge.textContent = (data.count || 0) + ' Temuan';

                var note = document.getElementById('partOverviewTableFooterNote');
                if (note) {
                    var title = partOverviewState.selectedPartCode + (partOverviewState.selectedPartName ? ' - ' + partOverviewState.selectedPartName : '');
                    note.innerHTML = 'Menampilkan <strong>' + (data.count || 0) + '</strong> temuan inspeksi terperinci untuk part: <strong>' + escapeHtml(title) + '</strong>';
                }

                // Inisialisasi dan jalankan filter Excel
                initPartOverviewExcelFilters();
                executePartOverviewFiltering();
            })
            .catch(function(err) {
                console.error("Error loading part overview history:", err);
                if (tbody) tbody.style.opacity = '1';
            });
    }

    function clearPartOverviewChartsAndTable() {
        if (chartPartOverviewTrendInstance) {
            chartPartOverviewTrendInstance.destroy();
            chartPartOverviewTrendInstance = null;
        }
        if (chartPartOverviewDefectsInstance) {
            chartPartOverviewDefectsInstance.destroy();
            chartPartOverviewDefectsInstance = null;
        }
        if (chartPartOverviewDefectsDonutInstance) {
            chartPartOverviewDefectsDonutInstance.destroy();
            chartPartOverviewDefectsDonutInstance = null;
        }

        // Bersihkan canvas donut & bar secara manual jika masih ada bekas render
        var cvsDonut = document.getElementById('chartPartOverviewDefectsDonut');
        if (cvsDonut) {
            var ctxD = cvsDonut.getContext('2d');
            if (ctxD) ctxD.clearRect(0, 0, cvsDonut.width, cvsDonut.height);
        }
        var cvsBar = document.getElementById('chartPartOverviewDefects');
        if (cvsBar) {
            var ctxB = cvsBar.getContext('2d');
            if (ctxB) ctxB.clearRect(0, 0, cvsBar.width, cvsBar.height);
        }
        var cvsTrend = document.getElementById('chartPartOverviewTrend');
        if (cvsTrend) {
            var ctxT = cvsTrend.getContext('2d');
            if (ctxT) ctxT.clearRect(0, 0, cvsTrend.width, cvsTrend.height);
        }

        // Reset list defect di sebelah donut pada tab Part Overview
        var defectListContainer = document.getElementById('partOverviewDefectCustomList');
        if (defectListContainer) {
            defectListContainer.innerHTML = '<div style="color:#94a3b8;font-size:11px;padding:12px;">Tidak ada temuan defect pada rentang ini.</div>';
        }

        // Reset display dropdown jika belum direset
        var displayBox = document.getElementById('partOverviewDropdownSelectedDisplay');
        if (displayBox && !partOverviewState.selectedPartId) {
            displayBox.innerHTML = '<span style="font-size:11px;color:#64748b;font-style:italic;">Tidak ada data part</span>';
        }

        // Reset state cache
        partOverviewState.cachedDefectsData = null;
        partOverviewState.cachedTrendData = null;

        var tbody = document.getElementById('partOverviewHistoryTableBody');
        if (tbody) {
            tbody.innerHTML = '<tr><td colspan="10" style="padding:24px;text-align:center;color:#94a3b8;">Tidak ada data pada filter ini.</td></tr>';
        }
        var countBadge = document.getElementById('partOverviewTableCountBadge');
        if (countBadge) countBadge.textContent = '0 Temuan';
        var elTot = document.getElementById('partOverviewKpiTotal');
        if (elTot) elTot.textContent = '0';
        var elPk = document.getElementById('partOverviewKpiPeak');
        if (elPk) elPk.textContent = '-';
        var elAvg = document.getElementById('partOverviewKpiAvg');
        if (elAvg) elAvg.textContent = '0';
    }

    // ── EXCEL-STYLE COLUMN HEADER FILTERS ENGINE (PART OVERVIEW AUDIT TABLE) ───
    var PartOverviewExcelFilterState = {
        activeCol: null,
        filters: {
            date: new Set(),
            model: new Set(),
            defect: new Set(),
            type: new Set(),
            customer: new Set(),
            inspector: new Set()
        },
        options: {
            date: [],
            model: [],
            defect: [],
            type: [],
            customer: [],
            inspector: []
        }
    };

    function initPartOverviewExcelFilters() {
        var rows = document.querySelectorAll('tr.part-overview-history-row');
        if (!rows || rows.length === 0) return;

        var sets = {
            date: new Set(),
            model: new Set(),
            defect: new Set(),
            type: new Set(),
            customer: new Set(),
            inspector: new Set()
        };

        rows.forEach(function(r) {
            var d   = (r.getAttribute('data-filter-date') || '').trim();
            var m   = (r.getAttribute('data-filter-model') || '').trim();
            var def = (r.getAttribute('data-filter-defect') || '').trim();
            var t   = (r.getAttribute('data-filter-type') || '').trim();
            var c   = (r.getAttribute('data-filter-customer') || '').trim();
            var ins = (r.getAttribute('data-filter-inspector') || '').trim();

            if (d) sets.date.add(d);
            if (m) sets.model.add(m);
            if (def) sets.defect.add(def);
            if (t) sets.type.add(t);
            if (c) sets.customer.add(c);
            if (ins) sets.inspector.add(ins);
        });

        ['date', 'model', 'defect', 'type', 'customer', 'inspector'].forEach(function(col) {
            PartOverviewExcelFilterState.options[col] = Array.from(sets[col]).sort();
            renderPartOverviewFilterCheckboxes(col);
        });
    }

    function renderPartOverviewFilterCheckboxes(col) {
        var container = document.getElementById('part-overview-filter-list-' + col);
        if (!container) return;

        var options = PartOverviewExcelFilterState.options[col] || [];
        var activeSet = PartOverviewExcelFilterState.filters[col];
        var isAllActive = (activeSet.size === 0);

        container.innerHTML = '';
        if (options.length === 0) {
            container.innerHTML = '<div style="color: #94a3b8; padding: 6px; text-align: center; font-size: 10px;">Tidak ada opsi</div>';
            return;
        }

        options.forEach(function(opt) {
            var isChecked = isAllActive || activeSet.has(opt);
            var itemDiv = document.createElement('label');
            itemDiv.className = 'excel-filter-item';
            itemDiv.setAttribute('data-val', opt.toLowerCase());

            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.value = opt;
            cb.checked = isChecked;
            cb.onchange = function() {
                updatePartOverviewFilterCountInfo(col);
            };

            var span = document.createElement('span');
            span.textContent = opt;
            span.style.overflow = 'hidden';
            span.style.textOverflow = 'ellipsis';
            span.style.whiteSpace = 'nowrap';
            span.title = opt;

            itemDiv.appendChild(cb);
            itemDiv.appendChild(span);
            container.appendChild(itemDiv);
        });

        updatePartOverviewFilterCountInfo(col);
    }

    function updatePartOverviewFilterCountInfo(col) {
        var dropdown = document.getElementById('part-overview-filter-dropdown-' + col);
        if (!dropdown) return;
        var info = dropdown.querySelector('.filter-count-info');
        if (!info) return;

        var checkboxes = dropdown.querySelectorAll('.excel-filter-list input[type="checkbox"]');
        var checkedCount = 0;
        checkboxes.forEach(function(cb) {
            if (cb.checked) checkedCount++;
        });
        info.textContent = checkedCount + ' / ' + checkboxes.length + ' dipilih';
    }

    function togglePartOverviewExcelFilter(event, col) {
        event.stopPropagation();
        var btn = event.currentTarget;
        var dropdown = document.getElementById('part-overview-filter-dropdown-' + col);
        if (!dropdown || !btn) return;

        var isVisible = (dropdown.style.display === 'block');
        closeAllPartOverviewExcelFilters();

        if (!isVisible) {
            dropdown.style.display = 'block';
            PartOverviewExcelFilterState.activeCol = col;

            // Hitung posisi fixed secara dinamis dari tombol
            var rect = btn.getBoundingClientRect();
            var ddWidth = dropdown.offsetWidth || 245;
            var ddHeight = dropdown.offsetHeight || 260;
            var winWidth = window.innerWidth;
            var winHeight = window.innerHeight;

            var top = rect.bottom + 6;
            if (top + ddHeight > winHeight - 10 && rect.top - ddHeight - 6 > 10) {
                top = rect.top - ddHeight - 6;
            }

            var left = rect.left;
            if (dropdown.classList.contains('align-right') || (left + ddWidth > winWidth - 15)) {
                left = rect.right - ddWidth;
            }
            if (left < 10) left = 10;

            dropdown.style.top = top + 'px';
            dropdown.style.left = left + 'px';

            var searchInput = dropdown.querySelector('.excel-filter-search');
            if (searchInput) {
                searchInput.value = '';
                onSearchPartOverviewFilterOptions(col, '');
                setTimeout(function() { searchInput.focus(); }, 50);
            }
        }
    }

    function closeAllPartOverviewExcelFilters() {
        document.querySelectorAll('#viewContainerPartOverviewMode .excel-filter-dropdown').forEach(function(dd) {
            dd.style.display = 'none';
        });
        PartOverviewExcelFilterState.activeCol = null;
    }

    function onSearchPartOverviewFilterOptions(col, keyword) {
        var container = document.getElementById('part-overview-filter-list-' + col);
        if (!container) return;
        var kw = (keyword || '').toLowerCase().trim();
        var items = container.querySelectorAll('.excel-filter-item');
        items.forEach(function(item) {
            var val = item.getAttribute('data-val') || '';
            if (!kw || val.indexOf(kw) !== -1) {
                item.style.display = 'flex';
            } else {
                item.style.display = 'none';
            }
        });
    }

    function toggleSelectAllPartOverviewFilter(col, selectAll) {
        var container = document.getElementById('part-overview-filter-list-' + col);
        if (!container) return;
        var checkboxes = container.querySelectorAll('input[type="checkbox"]');
        checkboxes.forEach(function(cb) {
            if (cb.closest('.excel-filter-item').style.display !== 'none') {
                cb.checked = selectAll;
            }
        });
        updatePartOverviewFilterCountInfo(col);
    }

    function applyPartOverviewExcelFilters() {
        var cols = ['date', 'model', 'defect', 'type', 'customer', 'inspector'];

        cols.forEach(function(col) {
            var container = document.getElementById('part-overview-filter-list-' + col);
            if (!container) return;

            var checkboxes = container.querySelectorAll('input[type="checkbox"]');
            var allCount = checkboxes.length;
            var checkedValues = [];

            checkboxes.forEach(function(cb) {
                if (cb.checked) {
                    checkedValues.push(cb.value);
                }
            });

            var btn = document.querySelector('#viewContainerPartOverviewMode .th-filter-btn[data-col="' + col + '"]');

            if (checkedValues.length === 0 || checkedValues.length === allCount) {
                PartOverviewExcelFilterState.filters[col].clear();
                if (btn) btn.classList.remove('active');
            } else {
                PartOverviewExcelFilterState.filters[col] = new Set(checkedValues);
                if (btn) btn.classList.add('active');
            }
        });

        closeAllPartOverviewExcelFilters();
        executePartOverviewFiltering();
    }

    function resetSinglePartOverviewColFilter(col) {
        PartOverviewExcelFilterState.filters[col].clear();
        var btn = document.querySelector('#viewContainerPartOverviewMode .th-filter-btn[data-col="' + col + '"]');
        if (btn) btn.classList.remove('active');
        renderPartOverviewFilterCheckboxes(col);
        closeAllPartOverviewExcelFilters();
        executePartOverviewFiltering();
    }

    function resetAllPartOverviewExcelFilters() {
        ['date', 'model', 'defect', 'type', 'customer', 'inspector'].forEach(function(col) {
            PartOverviewExcelFilterState.filters[col].clear();
            var btn = document.querySelector('#viewContainerPartOverviewMode .th-filter-btn[data-col="' + col + '"]');
            if (btn) btn.classList.remove('active');
            renderPartOverviewFilterCheckboxes(col);
        });
        var searchInput = document.getElementById('partOverviewTableSearchInput');
        if (searchInput) searchInput.value = '';
        closeAllPartOverviewExcelFilters();
        executePartOverviewFiltering();
    }

    function executePartOverviewFiltering() {
        var rows = document.querySelectorAll('tr.part-overview-history-row');
        if (!rows || rows.length === 0) return;

        var searchVal = (document.getElementById('partOverviewTableSearchInput') ? document.getElementById('partOverviewTableSearchInput').value : '').toLowerCase().trim();

        var activeFilterCols = [];
        ['date', 'model', 'defect', 'type', 'customer', 'inspector'].forEach(function(c) {
            if (PartOverviewExcelFilterState.filters[c].size > 0) activeFilterCols.push(c);
        });

        var visibleCount = 0;
        var totalRows = rows.length;

        rows.forEach(function(r) {
            if (searchVal) {
                var rowText = r.textContent.toLowerCase();
                if (rowText.indexOf(searchVal) === -1) {
                    r.style.display = 'none';
                    return;
                }
            }

            var d   = (r.getAttribute('data-filter-date') || '').trim();
            var m   = (r.getAttribute('data-filter-model') || '').trim();
            var def = (r.getAttribute('data-filter-defect') || '').trim();
            var t   = (r.getAttribute('data-filter-type') || '').trim();
            var c   = (r.getAttribute('data-filter-customer') || '').trim();
            var ins = (r.getAttribute('data-filter-inspector') || '').trim();

            var match = true;
            if (PartOverviewExcelFilterState.filters.date.size > 0 && !PartOverviewExcelFilterState.filters.date.has(d)) match = false;
            if (match && PartOverviewExcelFilterState.filters.model.size > 0 && !PartOverviewExcelFilterState.filters.model.has(m)) match = false;
            if (match && PartOverviewExcelFilterState.filters.defect.size > 0 && !PartOverviewExcelFilterState.filters.defect.has(def)) match = false;
            if (match && PartOverviewExcelFilterState.filters.type.size > 0 && !PartOverviewExcelFilterState.filters.type.has(t)) match = false;
            if (match && PartOverviewExcelFilterState.filters.customer.size > 0 && !PartOverviewExcelFilterState.filters.customer.has(c)) match = false;
            if (match && PartOverviewExcelFilterState.filters.inspector.size > 0 && !PartOverviewExcelFilterState.filters.inspector.has(ins)) match = false;

            if (match) {
                r.style.display = '';
                visibleCount++;
                var idxTd = r.querySelector('.row-index');
                if (idxTd) idxTd.textContent = visibleCount;
            } else {
                r.style.display = 'none';
            }
        });

        // Update active filter bar
        var indicatorBar = document.getElementById('part-overview-excel-filter-active-bar');
        var summaryText  = document.getElementById('part-overview-excel-filter-summary-text');
        var badgeCount   = document.getElementById('partOverviewTableCountBadge');

        var isFiltered = (activeFilterCols.length > 0 || searchVal !== '');

        if (isFiltered) {
            if (indicatorBar) indicatorBar.style.display = 'flex';
            if (summaryText) {
                var filterNames = activeFilterCols.slice();
                if (searchVal) filterNames.push('pencarian "' + searchVal + '"');
                summaryText.innerText = 'Menyaring ' + visibleCount + ' dari ' + totalRows + ' temuan (' + filterNames.join(', ') + ')';
            }
            if (badgeCount) {
                badgeCount.innerText = visibleCount + ' / ' + totalRows + ' Temuan';
            }
        } else {
            if (indicatorBar) indicatorBar.style.display = 'none';
            if (badgeCount) {
                badgeCount.innerText = totalRows + ' Temuan';
            }
        }
    }

    // ── MAIN ANALYSIS MODE SWITCHER ───────────────────────────────────────────
        function switchAnalysisMode(mode) {
        currentAnalysisMode = mode;
        var btnO  = document.getElementById('btnModeOverview');
        var btnPO = document.getElementById('btnModePartOverview');
        var contO = document.getElementById('viewContainerOverviewMode');
        var contPO= document.getElementById('viewContainerPartOverviewMode');

        // Reset mode buttons
        [btnO, btnPO].forEach(function(btn) {
            if (btn) {
                btn.style.background = 'transparent';
                btn.style.color = '#64748b';
                btn.style.boxShadow = 'none';
            }
        });

        // Hide mode containers
        if (contO) contO.style.display = 'none';
        if (contPO) contPO.style.display = 'none';

        if (mode === 'overview') {
            if (btnO) {
                btnO.style.background = '#0f172a';
                btnO.style.color = '#ffffff';
                btnO.style.boxShadow = '0 1px 3px rgba(15,23,42,0.25)';
            }
            if (contO) contO.style.display = 'flex';
            loadOverviewMode();
        } else if (mode === 'part_overview') {
            if (btnPO) {
                btnPO.style.background = '#0f172a';
                btnPO.style.color = '#ffffff';
                btnPO.style.boxShadow = '0 1px 3px rgba(15,23,42,0.25)';
            }
            if (contPO) contPO.style.display = 'flex';
            loadPartOverviewMode();
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    var OverviewExcelFilterState = {
        activeCol: null,
        filters: {
            date: new Set(),
            part: new Set(),
            model: new Set(),
            type: new Set(),
            customer: new Set(),
            inspector: new Set()
        },
        options: {
            date: [],
            part: [],
            model: [],
            type: [],
            customer: [],
            inspector: []
        }
    };

    function initOverviewExcelFilters() {
        var rows = document.querySelectorAll('tr.overview-history-row');
        if (!rows || rows.length === 0) return;

        var sets = {
            date: new Set(),
            part: new Set(),
            model: new Set(),
            type: new Set(),
            customer: new Set(),
            inspector: new Set()
        };

        rows.forEach(function(r) {
            var d   = (r.getAttribute('data-filter-date') || '').trim();
            var p   = (r.getAttribute('data-filter-part') || '').trim();
            var m   = (r.getAttribute('data-filter-model') || '').trim();
            var t   = (r.getAttribute('data-filter-type') || '').trim();
            var c   = (r.getAttribute('data-filter-customer') || '').trim();
            var ins = (r.getAttribute('data-filter-inspector') || '').trim();

            if (d) sets.date.add(d);
            if (p) sets.part.add(p);
            if (m) sets.model.add(m);
            if (t) sets.type.add(t);
            if (c) sets.customer.add(c);
            if (ins) sets.inspector.add(ins);
        });

        ['date', 'part', 'model', 'type', 'customer', 'inspector'].forEach(function(col) {
            OverviewExcelFilterState.options[col] = Array.from(sets[col]).sort();
            renderOverviewFilterCheckboxes(col);
        });
    }

    function renderOverviewFilterCheckboxes(col) {
        var container = document.getElementById('overview-filter-list-' + col);
        if (!container) return;

        var options = OverviewExcelFilterState.options[col] || [];
        var activeSet = OverviewExcelFilterState.filters[col];
        var isAllActive = (activeSet.size === 0);

        container.innerHTML = '';
        if (options.length === 0) {
            container.innerHTML = '<div style="color: #94a3b8; padding: 6px; text-align: center; font-size: 10px;">Tidak ada opsi</div>';
            return;
        }

        options.forEach(function(opt) {
            var isChecked = isAllActive || activeSet.has(opt);
            var itemDiv = document.createElement('label');
            itemDiv.className = 'excel-filter-item';
            itemDiv.setAttribute('data-val', opt.toLowerCase());

            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.value = opt;
            cb.checked = isChecked;
            cb.onchange = function() {
                updateOverviewFilterCountInfo(col);
            };

            var span = document.createElement('span');
            span.textContent = opt;
            span.style.overflow = 'hidden';
            span.style.textOverflow = 'ellipsis';
            span.style.whiteSpace = 'nowrap';
            span.title = opt;

            itemDiv.appendChild(cb);
            itemDiv.appendChild(span);
            container.appendChild(itemDiv);
        });

        updateOverviewFilterCountInfo(col);
    }

    function updateOverviewFilterCountInfo(col) {
        var dropdown = document.getElementById('overview-filter-dropdown-' + col);
        if (!dropdown) return;
        var info = dropdown.querySelector('.filter-count-info');
        if (!info) return;

        var checkboxes = dropdown.querySelectorAll('.excel-filter-list input[type="checkbox"]');
        var checkedCount = 0;
        checkboxes.forEach(function(cb) {
            if (cb.checked) checkedCount++;
        });
        info.textContent = checkedCount + ' / ' + checkboxes.length + ' dipilih';
    }

    function toggleOverviewExcelFilter(event, col) {
        event.stopPropagation();
        var btn = event.currentTarget;
        var dropdown = document.getElementById('overview-filter-dropdown-' + col);
        if (!dropdown || !btn) return;

        var isVisible = (dropdown.style.display === 'block');
        closeAllOverviewExcelFilters();

        if (!isVisible) {
            dropdown.style.display = 'block';
            OverviewExcelFilterState.activeCol = col;

            // Hitung posisi fixed secara dinamis dari tombol
            var rect = btn.getBoundingClientRect();
            var ddWidth = dropdown.offsetWidth || 245;
            var ddHeight = dropdown.offsetHeight || 260;
            var winWidth = window.innerWidth;
            var winHeight = window.innerHeight;

            var top = rect.bottom + 6;
            // Jika melebihi batas bawah viewport, buka ke atas
            if (top + ddHeight > winHeight - 10 && rect.top - ddHeight - 6 > 10) {
                top = rect.top - ddHeight - 6;
            }

            var left = rect.left;
            // Jika kolom di kanan atau dropdown overflow ke kanan layar, align kanan
            if (dropdown.classList.contains('align-right') || (left + ddWidth > winWidth - 15)) {
                left = rect.right - ddWidth;
            }
            if (left < 10) left = 10;

            dropdown.style.top = top + 'px';
            dropdown.style.left = left + 'px';

            var searchInput = dropdown.querySelector('.excel-filter-search');
            if (searchInput) {
                searchInput.value = '';
                onSearchOverviewFilterOptions(col, '');
                setTimeout(function() { searchInput.focus(); }, 50);
            }
        }
    }

    function closeAllOverviewExcelFilters() {
        document.querySelectorAll('#viewContainerOverviewMode .excel-filter-dropdown').forEach(function(dd) {
            dd.style.display = 'none';
        });
        OverviewExcelFilterState.activeCol = null;
    }

    function onSearchOverviewFilterOptions(col, keyword) {
        var container = document.getElementById('overview-filter-list-' + col);
        if (!container) return;
        var kw = (keyword || '').toLowerCase().trim();
        var items = container.querySelectorAll('.excel-filter-item');
        items.forEach(function(item) {
            var val = item.getAttribute('data-val') || '';
            if (!kw || val.indexOf(kw) !== -1) {
                item.style.display = 'flex';
            } else {
                item.style.display = 'none';
            }
        });
    }

    function toggleSelectAllOverviewFilter(col, selectAll) {
        var container = document.getElementById('overview-filter-list-' + col);
        if (!container) return;
        var checkboxes = container.querySelectorAll('input[type="checkbox"]');
        checkboxes.forEach(function(cb) {
            if (cb.closest('.excel-filter-item').style.display !== 'none') {
                cb.checked = selectAll;
            }
        });
        updateOverviewFilterCountInfo(col);
    }

    function applyOverviewExcelFilters() {
        var cols = ['date', 'part', 'model', 'type', 'customer', 'inspector'];

        cols.forEach(function(col) {
            var container = document.getElementById('overview-filter-list-' + col);
            if (!container) return;

            var checkboxes = container.querySelectorAll('input[type="checkbox"]');
            var allCount = checkboxes.length;
            var checkedValues = [];

            checkboxes.forEach(function(cb) {
                if (cb.checked) {
                    checkedValues.push(cb.value);
                }
            });

            var btn = document.querySelector('#viewContainerOverviewMode .th-filter-btn[data-col="' + col + '"]');

            if (checkedValues.length === 0 || checkedValues.length === allCount) {
                OverviewExcelFilterState.filters[col].clear();
                if (btn) btn.classList.remove('active');
            } else {
                OverviewExcelFilterState.filters[col] = new Set(checkedValues);
                if (btn) btn.classList.add('active');
            }
        });

        closeAllOverviewExcelFilters();
        executeOverviewFiltering();
    }

    function resetSingleOverviewColFilter(col) {
        OverviewExcelFilterState.filters[col].clear();
        var btn = document.querySelector('#viewContainerOverviewMode .th-filter-btn[data-col="' + col + '"]');
        if (btn) btn.classList.remove('active');
        renderOverviewFilterCheckboxes(col);
        closeAllOverviewExcelFilters();
        executeOverviewFiltering();
    }

    function resetAllOverviewExcelFilters() {
        ['date', 'part', 'model', 'type', 'customer', 'inspector'].forEach(function(col) {
            OverviewExcelFilterState.filters[col].clear();
            var btn = document.querySelector('#viewContainerOverviewMode .th-filter-btn[data-col="' + col + '"]');
            if (btn) btn.classList.remove('active');
            renderOverviewFilterCheckboxes(col);
        });
        var searchInput = document.getElementById('overviewTableSearchInput');
        if (searchInput) searchInput.value = '';
        closeAllOverviewExcelFilters();
        executeOverviewFiltering();
    }

    function executeOverviewFiltering() {
        var rows = document.querySelectorAll('tr.overview-history-row');
        if (!rows || rows.length === 0) return;

        var searchVal = (document.getElementById('overviewTableSearchInput') ? document.getElementById('overviewTableSearchInput').value : '').toLowerCase().trim();

        var activeFilterCols = [];
        ['date', 'part', 'model', 'type', 'customer', 'inspector'].forEach(function(c) {
            if (OverviewExcelFilterState.filters[c].size > 0) activeFilterCols.push(c);
        });

        var visibleCount = 0;
        var totalRows = rows.length;

        rows.forEach(function(r) {
            // 1. Text Search Filter
            if (searchVal) {
                var rowText = r.textContent.toLowerCase();
                if (rowText.indexOf(searchVal) === -1) {
                    r.style.display = 'none';
                    return;
                }
            }

            // 2. Excel Column Filters
            var d   = (r.getAttribute('data-filter-date') || '').trim();
            var p   = (r.getAttribute('data-filter-part') || '').trim();
            var m   = (r.getAttribute('data-filter-model') || '').trim();
            var t   = (r.getAttribute('data-filter-type') || '').trim();
            var c   = (r.getAttribute('data-filter-customer') || '').trim();
            var ins = (r.getAttribute('data-filter-inspector') || '').trim();

            var match = true;
            if (OverviewExcelFilterState.filters.date.size > 0 && !OverviewExcelFilterState.filters.date.has(d)) match = false;
            if (match && OverviewExcelFilterState.filters.part.size > 0 && !OverviewExcelFilterState.filters.part.has(p)) match = false;
            if (match && OverviewExcelFilterState.filters.model.size > 0 && !OverviewExcelFilterState.filters.model.has(m)) match = false;
            if (match && OverviewExcelFilterState.filters.type.size > 0 && !OverviewExcelFilterState.filters.type.has(t)) match = false;
            if (match && OverviewExcelFilterState.filters.customer.size > 0 && !OverviewExcelFilterState.filters.customer.has(c)) match = false;
            if (match && OverviewExcelFilterState.filters.inspector.size > 0 && !OverviewExcelFilterState.filters.inspector.has(ins)) match = false;

            if (match) {
                r.style.display = '';
                visibleCount++;
                var idxTd = r.querySelector('.row-index');
                if (idxTd) idxTd.textContent = visibleCount;
            } else {
                r.style.display = 'none';
            }
        });

        // Update active filter bar
        var indicatorBar = document.getElementById('overview-excel-filter-active-bar');
        var summaryText  = document.getElementById('overview-excel-filter-summary-text');
        var badgeCount   = document.getElementById('overviewTableCountBadge');

        var isFiltered = (activeFilterCols.length > 0 || searchVal !== '');

        if (isFiltered) {
            if (indicatorBar) indicatorBar.style.display = 'flex';
            if (summaryText) {
                var filterNames = activeFilterCols.slice();
                if (searchVal) filterNames.push('pencarian "' + searchVal + '"');
                summaryText.innerText = 'Menyaring ' + visibleCount + ' dari ' + totalRows + ' temuan (' + filterNames.join(', ') + ')';
            }
            if (badgeCount) {
                badgeCount.innerText = visibleCount + ' / ' + totalRows + ' Temuan';
            }
        } else {
            if (indicatorBar) indicatorBar.style.display = 'none';
            if (badgeCount) {
                badgeCount.innerText = totalRows + ' Temuan';
            }
        }
    }

    // Event listener: Klik di luar untuk menutup dropdown filter overview & defect dropdown
    document.addEventListener('click', function(e) {
        if (!e.target.closest('#viewContainerOverviewMode .excel-filter-dropdown') && !e.target.closest('#viewContainerOverviewMode .th-filter-btn')) {
            closeAllOverviewExcelFilters();
        }
        if (!e.target.closest('#overviewDefectDropdownContainer')) {
            closeOverviewDefectMenu();
        }
        if (!e.target.closest('#viewContainerPartOverviewMode .excel-filter-dropdown') && !e.target.closest('#viewContainerPartOverviewMode .th-filter-btn')) {
            closeAllPartOverviewExcelFilters();
        }
        if (!e.target.closest('#partOverviewPartDropdownContainer')) {
            closePartOverviewMenu();
        }
    });

    // ── THRESHOLD (BATAS TARGET) MODAL CONTROLLER ───────────────────────────
    function openThresholdModal(mode) {
        activeThresholdModalMode = mode || 'overview';
        var modal = document.getElementById('modalDefectThreshold');
        if (!modal) return;

        var isOverview = (activeThresholdModalMode === 'overview');
        var activeId = isOverview ? overviewState.selectedDefectId : partOverviewState.selectedPartId;
        var activeName = isOverview ? (overviewState.selectedDefectName || 'Defect Terpilih') : ((partOverviewState.selectedPartCode ? partOverviewState.selectedPartCode + ' - ' : '') + (partOverviewState.selectedPartName || 'Part Terpilih'));
        var unitLabel = funnelState.unit.toUpperCase();

        var elType = document.getElementById('modalThresholdItemType');
        var elName = document.getElementById('modalThresholdItemName');
        var elUnit = document.getElementById('modalThresholdUnitLabel');
        var elSuffix = document.getElementById('inputThresholdUnitSuffix');
        var elInput = document.getElementById('inputThresholdValue');
        var elStatus = document.getElementById('modalThresholdCurrentText');

        if (elType) elType.textContent = isOverview ? 'Defect Terpilih:' : 'Part Terpilih:';
        if (elName) {
            elName.textContent = activeName;
            elName.title = activeName;
        }
        if (elUnit) elUnit.textContent = unitLabel;
        if (elSuffix) elSuffix.textContent = unitLabel;

        // Populate current threshold if any
        var currentThresh = isOverview ? overviewState.threshold : partOverviewState.threshold;
        var currentInfo = isOverview ? overviewState.thresholdInfo : partOverviewState.thresholdInfo;

        if (elInput) {
            elInput.value = (currentThresh !== null && currentThresh !== undefined) ? currentThresh : '';
        }

        if (elStatus) {
            if (currentThresh !== null && currentThresh !== undefined) {
                var scopeText = (currentInfo && currentInfo.is_specific) ? 'Khusus item ini' : 'Batas Global (Semua)';
                elStatus.innerHTML = '<strong style="color:#dc2626;">' + Number(currentThresh).toLocaleString() + ' ' + unitLabel + '</strong> (' + scopeText + ')';
            } else {
                elStatus.innerHTML = '<span style="color:#64748b;">Belum ditentukan (grafik tanpa garis batas)</span>';
            }
        }

        // Set radio default
        var radioSpecific = document.querySelector('input[name="thresholdScope"][value="specific"]');
        var radioAll = document.querySelector('input[name="thresholdScope"][value="all"]');
        var radioBatch = document.querySelector('input[name="thresholdScope"][value="batch"]');
        if (currentInfo && !currentInfo.is_specific && currentInfo.global_val !== null) {
            if (radioAll) radioAll.checked = true;
        } else {
            if (radioSpecific) radioSpecific.checked = true;
        }

        // Reset batch container state
        var batchContainer = document.getElementById('containerThresholdBatchList');
        if (batchContainer) batchContainer.style.display = 'none';

        modal.style.display = 'flex';
        setTimeout(function() {
            if (elInput) elInput.focus();
        }, 100);
    }

    function closeThresholdModal() {
        var modal = document.getElementById('modalDefectThreshold');
        if (modal) modal.style.display = 'none';
    }

    var cachedBatchItems = [];

    function onThresholdScopeChange(scope) {
        var batchContainer = document.getElementById('containerThresholdBatchList');
        if (scope === 'batch') {
            if (batchContainer) batchContainer.style.display = 'block';
            loadThresholdBatchItems();
        } else {
            if (batchContainer) batchContainer.style.display = 'none';
        }
    }

    function loadThresholdBatchItems() {
        var wrapper = document.getElementById('thresholdBatchItemsWrapper');
        if (!wrapper) return;
        wrapper.innerHTML = '<div style="font-size:11px;color:#94a3b8;text-align:center;padding:12px;">Memuat daftar item...</div>';

        var url = buildApiUrl('get_all_item_thresholds', {
            mode: activeThresholdModalMode
        });

        fetch(url)
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!data.success) {
                    wrapper.innerHTML = '<div style="font-size:11px;color:#ef4444;text-align:center;padding:10px;">Gagal memuat item.</div>';
                    return;
                }

                cachedBatchItems = data.items || [];
                renderThresholdBatchList(cachedBatchItems);
            })
            .catch(function(err) {
                console.error("Error loading batch threshold items:", err);
                wrapper.innerHTML = '<div style="font-size:11px;color:#ef4444;text-align:center;padding:10px;">Terjadi kesalahan saat memuat item.</div>';
            });
    }

    function renderThresholdBatchList(items) {
        var wrapper = document.getElementById('thresholdBatchItemsWrapper');
        if (!wrapper) return;

        if (!items || items.length === 0) {
            wrapper.innerHTML = '<div style="font-size:11px;color:#94a3b8;text-align:center;padding:10px;">Tidak ada item ditemukan.</div>';
            updateThresholdBatchSelectedCount();
            return;
        }

        var isOverview = (activeThresholdModalMode === 'overview');
        var activeId = isOverview ? overviewState.selectedDefectId : partOverviewState.selectedPartId;
        var unitLabel = funnelState.unit.toUpperCase();

        var html = '';
        items.forEach(function(item) {
            var isCurrentActive = (item.id === activeId);
            var itemDisplayName = isOverview ? item.name : ((item.code ? item.code + ' - ' : '') + item.name);

            // Badge Batas Sebelumnya (Existing Limit)
            var badgeHtml = '';
            if (item.current_val !== null && item.current_val !== undefined) {
                var badgeBg = item.is_specific ? '#eff6ff' : '#f1f5f9';
                var badgeColor = item.is_specific ? '#1d4ed8' : '#475569';
                var badgeBorder = item.is_specific ? '#bfdbfe' : '#cbd5e1';
                var badgeLabel = item.is_specific ? 'Spesifik' : 'Global';
                badgeHtml = '<span style="font-size:9.5px;font-weight:800;padding:1.5px 6px;border-radius:4px;background:' + badgeBg + ';color:' + badgeColor + ';border:1px solid ' + badgeBorder + ';white-space:nowrap;" title="Target Saat Ini: ' + item.current_val + ' ' + unitLabel + ' (' + badgeLabel + ')">' +
                            'Target: ' + Number(item.current_val).toLocaleString() + ' ' + unitLabel +
                            '</span>';
            } else {
                badgeHtml = '<span style="font-size:9.5px;font-weight:700;padding:1.5px 6px;border-radius:4px;background:#f8fafc;color:#94a3b8;border:1px solid #e2e8f0;white-space:nowrap;">' +
                            'Belum Ada (-)' +
                            '</span>';
            }

            var checkedAttr = isCurrentActive ? 'checked' : '';

            html += '<label class="threshold-batch-item" data-name="' + escapeQuotes(itemDisplayName.toLowerCase()) + '" style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:5px 8px;background:#ffffff;border:1px solid #e2e8f0;border-radius:6px;cursor:pointer;transition:all 0.15s;" onmouseover="this.style.background=\'#eff6ff\'" onmouseout="this.style.background=\'#ffffff\'">' +
                    '  <div style="display:flex;align-items:center;gap:6px;overflow:hidden;flex:1;">' +
                    '    <input type="checkbox" class="cb-threshold-batch-item" value="' + item.id + '" ' + checkedAttr + ' onchange="updateThresholdBatchSelectedCount()">' +
                    '    <span style="font-size:11px;font-weight:700;color:#0f172a;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + escapeQuotes(itemDisplayName) + '">' +
                           (isCurrentActive ? '<strong style="color:#2563eb;">[Aktif]</strong> ' : '') +
                           escapeHtml(itemDisplayName) +
                    '    </span>' +
                    '  </div>' +
                    '  <div style="flex-shrink:0;">' + badgeHtml + '</div>' +
                    '</label>';
        });

        wrapper.innerHTML = html;
        updateThresholdBatchSelectedCount();
    }

    function filterThresholdBatchList(query) {
        var q = (query || '').toLowerCase().trim();
        var items = document.querySelectorAll('#thresholdBatchItemsWrapper .threshold-batch-item');
        items.forEach(function(el) {
            var name = el.getAttribute('data-name') || '';
            el.style.display = (name.indexOf(q) > -1) ? 'flex' : 'none';
        });
    }

    function toggleAllThresholdBatchItems(selectAll) {
        var visibleCheckboxes = document.querySelectorAll('#thresholdBatchItemsWrapper .threshold-batch-item:not([style*="display: none"]) .cb-threshold-batch-item');
        visibleCheckboxes.forEach(function(cb) {
            cb.checked = !!selectAll;
        });
        updateThresholdBatchSelectedCount();
    }

    function updateThresholdBatchSelectedCount() {
        var count = document.querySelectorAll('#thresholdBatchItemsWrapper .cb-threshold-batch-item:checked').length;
        var lbl = document.getElementById('lblThresholdBatchSelectedCount');
        if (lbl) lbl.textContent = count;
    }

    function submitThresholdForm(doClear) {
        var isOverview = (activeThresholdModalMode === 'overview');
        var activeId = isOverview ? overviewState.selectedDefectId : partOverviewState.selectedPartId;
        var radioScope = document.querySelector('input[name="thresholdScope"]:checked');
        var scope = radioScope ? radioScope.value : 'specific';
        var inputVal = document.getElementById('inputThresholdValue');
        var rawVal = inputVal ? parseFloat(inputVal.value) : 0;

        var selectedBatchIds = [];
        if (scope === 'batch') {
            document.querySelectorAll('#thresholdBatchItemsWrapper .cb-threshold-batch-item:checked').forEach(function(cb) {
                var valId = parseInt(cb.value, 10);
                if (valId > 0) selectedBatchIds.push(valId);
            });

            if (selectedBatchIds.length === 0) {
                alert('Silakan centang setidaknya 1 item dari daftar untuk menerapkan target batch.');
                return;
            }
        }

        if (!doClear) {
            if (isNaN(rawVal) || rawVal < 0) {
                alert('Silakan masukkan angka batas ambang / target yang valid (positif atau 0).');
                if (inputVal) inputVal.focus();
                return;
            }
        }

        var saveBtn = document.getElementById('btnThresholdSave');
        var clearBtn = document.getElementById('btnThresholdClear');
        if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = 'Menyimpan...'; }
        if (clearBtn) clearBtn.disabled = true;

        var payload = {
            mode: activeThresholdModalMode,
            item_id: activeId,
            scope: scope,
            item_ids: selectedBatchIds,
            value: rawVal,
            clear: doClear ? 1 : 0
        };

        fetch('<?= base_url("modules/defect_analysis/api/drilldown_data.php?action=save_threshold") ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Simpan Target'; }
            if (clearBtn) clearBtn.disabled = false;

            if (!data.success) {
                alert('Gagal menyimpan batas target: ' + (data.message || 'Terjadi kesalahan sistem'));
                return;
            }

            closeThresholdModal();

            // Reload current trend chart
            if (isOverview) {
                loadOverviewTrend();
                if (overviewState.partChartType === 'line_trend' || overviewState.partChartType === 'bar_trend') {
                    loadOverviewPartsTimeline();
                }
            } else {
                loadPartOverviewTrend();
                if (partOverviewState.defectChartType === 'line_trend' || partOverviewState.defectChartType === 'bar_trend') {
                    loadPartOverviewDefectsTimeline();
                }
            }
        })
        .catch(function(err) {
            if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Simpan Target'; }
            if (clearBtn) clearBtn.disabled = false;
            console.error('Error saving threshold:', err);
            alert('Gagal menghubungi server saat menyimpan target');
        });
    }

    // Event listener: Tombol Escape untuk menutup dropdown & modal
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeAllOverviewExcelFilters();
            closeOverviewDefectMenu();
            closeAllPartOverviewExcelFilters();
            closePartOverviewMenu();
            closeThresholdModal();
        }
    });

    // Tutup dropdown jika container di-scroll atau window di-resize
    window.addEventListener('resize', function() {
        closeAllOverviewExcelFilters();
        closeAllPartOverviewExcelFilters();
    });
    window.addEventListener('scroll', function() {
        closeAllOverviewExcelFilters();
        closeAllPartOverviewExcelFilters();
    }, true);
    var defectBody = document.getElementById('defect-body');
    if (defectBody) {
        defectBody.addEventListener('scroll', function() {
            closeAllOverviewExcelFilters();
            closeAllPartOverviewExcelFilters();
        });
    }

    // Setup input search box filter binding
    $(document).ready(function() {
        $('#overviewTableSearchInput').on('input keyup', function() {
            executeOverviewFiltering();
        });
        $('#partOverviewTableSearchInput').on('input keyup', function() {
            executePartOverviewFiltering();
        });
    });
    </script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
