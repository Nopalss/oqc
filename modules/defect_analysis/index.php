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
                margin: 5mm 8mm 5mm 8mm;
            }

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
            #overviewDefectSelectorCard,
            #partOverviewPartSelectorCard,
            .th-filter-btn,
            #overviewAuditPagination,
            #partOverviewAuditPagination,
            #overviewModelRankGroup,
            #overviewPartRankGroup,
            #partOverviewDefectRankGroup,
            #overviewModelSubResGroup,
            #overviewPartSubResGroup,
            #partOverviewDefectSubResGroup,
            #btnPartOverviewThreshold,
            #overviewDefectDropdownContainer,
            #partOverviewPartDropdownContainer {
                display: none !important;
            }

            .print-only {
                display: block !important;
            }

            #printOfficialReportHeader {
                margin-bottom: 6px !important;
                padding-bottom: 6px !important;
                border-bottom-width: 1.5px !important;
            }
            #printOfficialReportHeader > div:first-child {
                padding-bottom: 4px !important;
                margin-bottom: 4px !important;
            }
            #printOfficialReportHeader h1 {
                font-size: 13px !important;
            }
            #printOfficialReportHeader h2 {
                font-size: 11.5px !important;
            }

            html, body {
                background: #ffffff !important;
                color: #0f172a !important;
                font-size: 8.5pt !important;
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

            #viewContainerOverviewMode,
            #viewContainerPartOverviewMode {
                width: 100% !important;
                gap: 6px !important;
                page-break-inside: auto !important;
            }

            #viewContainerOverviewMode > div,
            #viewContainerPartOverviewMode > div {
                padding: 6px 10px !important;
                gap: 5px !important;
                border-radius: 8px !important;
                border: 1px solid #cbd5e1 !important;
                box-shadow: none !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }

            /* Kompakkan Card Grid Tier 2 Khusus Overview Mode (Dua Sebaran Model & Part) */
            #viewContainerOverviewMode > div:nth-child(3) {
                gap: 8px !important;
                padding: 0 !important;
                border: none !important;
            }
            #viewContainerOverviewMode > div:nth-child(3) > div {
                padding: 6px 10px !important;
                border-radius: 8px !important;
                border: 1px solid #cbd5e1 !important;
                box-shadow: none !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }

            /* Atur Ketinggian Proporsional Canvas Tren Tier 1 saat Cetak */
            #chartOverviewTrend {
                height: 255px !important;
                max-height: 255px !important;
            }
            #viewContainerOverviewMode > div:nth-child(2) > div:last-child {
                height: 255px !important;
                min-height: 255px !important;
            }

            /* Khusus Part Overview: Kompakkan Tinggi Tren (Tier 1) dan Sebaran Defect (Tier 2) agar Fit 1 Halaman A4 Landscape */
            #chartPartOverviewTrend {
                height: 205px !important;
                max-height: 205px !important;
            }
            #viewContainerPartOverviewMode > div:nth-child(2) > div:last-child {
                height: 205px !important;
                min-height: 205px !important;
            }
            #viewContainerPartOverviewMode > div:nth-child(3) {
                padding: 5px 10px !important;
                gap: 4px !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }

            #overviewModelChartContainer,
            #overviewPartChartContainer,
            #partOverviewDefectChartContainer {
                min-height: 0 !important;
                height: auto !important;
                max-height: 250px !important;
                width: 100% !important;
                display: flex !important;
            }

            #overviewModelTimelineWrapper[style*="display: none"],
            #overviewModelTimelineWrapper[style*="display:none"],
            #overviewPartTimelineWrapper[style*="display: none"],
            #overviewPartTimelineWrapper[style*="display:none"],
            #partOverviewDefectTimelineWrapper[style*="display: none"],
            #partOverviewDefectTimelineWrapper[style*="display:none"] {
                display: none !important;
            }

            #overviewModelTimelineWrapper:not([style*="display: none"]):not([style*="display:none"]),
            #overviewPartTimelineWrapper:not([style*="display: none"]):not([style*="display:none"]) {
                min-height: 0 !important;
                height: 240px !important;
                max-height: 240px !important;
                width: 100% !important;
                display: flex !important;
                flex-direction: column !important;
            }
            #overviewModelTimelineWrapper > div:first-child,
            #overviewPartTimelineWrapper > div:first-child {
                flex: 1 !important;
                min-height: 0 !important;
                height: 195px !important;
                max-height: 195px !important;
                width: 100% !important;
            }

            /* Khusus Part Overview: Diagram Sebaran Defect (Tren Garis/Batang) saat Cetak */
            #partOverviewDefectTimelineWrapper:not([style*="display: none"]):not([style*="display:none"]) {
                min-height: 0 !important;
                height: 200px !important;
                max-height: 200px !important;
                width: 100% !important;
                display: flex !important;
                flex-direction: column !important;
            }
            #partOverviewDefectTimelineWrapper > div:first-child {
                flex: 1 !important;
                min-height: 0 !important;
                height: 160px !important;
                max-height: 160px !important;
                width: 100% !important;
            }

            #overviewModelDonutWrapper[style*="display: none"],
            #overviewModelDonutWrapper[style*="display:none"],
            #overviewPartDonutWrapper[style*="display: none"],
            #overviewPartDonutWrapper[style*="display:none"],
            #partOverviewDefectDonutWrapper[style*="display: none"],
            #partOverviewDefectDonutWrapper[style*="display:none"] {
                display: none !important;
            }

            #overviewModelDonutWrapper:not([style*="display: none"]):not([style*="display:none"]),
            #overviewPartDonutWrapper:not([style*="display: none"]):not([style*="display:none"]),
            #partOverviewDefectDonutWrapper:not([style*="display: none"]):not([style*="display:none"]) {
                min-height: 0 !important;
                height: auto !important;
                max-height: none !important;
                width: 100% !important;
                flex: 1 1 100% !important;
                overflow: visible !important;
                display: block !important;
            }
            #overviewModelDonutWrapper table,
            #overviewPartDonutWrapper table,
            #partOverviewDefectDonutWrapper table {
                width: 100% !important;
                table-layout: auto !important;
                border-collapse: collapse !important;
            }

            #overviewModelDonutWrapper table th:first-child,
            #overviewPartDonutWrapper table th:first-child,
            #partOverviewDefectDonutWrapper table th:first-child {
                width: 36px !important;
                text-align: center !important;
                padding: 5px 4px !important;
                font-size: 8.5px !important;
            }
            #overviewModelDonutWrapper table th:nth-child(2),
            #overviewPartDonutWrapper table th:nth-child(2),
            #partOverviewDefectDonutWrapper table th:nth-child(2) {
                width: auto !important;
                padding: 5px 6px !important;
                font-size: 8.5px !important;
                white-space: nowrap !important;
            }
            #thOverviewModelVal,
            #thOverviewPartVal,
            #thPartOverviewDefectVal {
                width: 105px !important;
                white-space: nowrap !important;
                text-align: right !important;
                padding: 5px 8px !important;
                font-size: 8.5px !important;
            }
            #thOverviewModelTarget,
            #thOverviewPartTarget,
            #thPartOverviewDefectTarget {
                width: 95px !important;
                white-space: nowrap !important;
                text-align: center !important;
                padding: 5px 6px !important;
                font-size: 8.5px !important;
            }

            #overviewModelTableBody tr:nth-child(n+6),
            #overviewPartTableBody tr:nth-child(n+6),
            #partOverviewDefectTableBody tr:nth-child(n+6) {
                display: none !important;
            }
            #overviewModelTableBody td,
            #overviewPartTableBody td,
            #partOverviewDefectTableBody td {
                padding: 4px 6px !important;
                font-size: 8.5px !important;
                line-height: 1.25 !important;
            }
            #overviewModelTableBody td span,
            #overviewPartTableBody td span,
            #partOverviewDefectTableBody td span {
                font-size: 8.5px !important;
            }
            #chartOverviewModels,
            #chartOverviewParts {
                max-height: 195px !important;
                height: 195px !important;
                width: 100% !important;
            }
            #chartPartOverviewDefects {
                max-height: 160px !important;
                height: 160px !important;
                width: 100% !important;
            }

            #chartOverviewModelsLegend,
            #chartOverviewPartsLegend,
            #chartPartOverviewDefectsLegend {
                display: flex !important;
                max-height: 40px !important;
                overflow: hidden !important;
                flex-wrap: wrap !important;
                gap: 3px !important;
                padding: 3px 4px 0 4px !important;
                border-top: 1px solid #e2e8f0 !important;
                background: #ffffff !important;
            }
            #chartOverviewModelsLegend > div,
            #chartOverviewPartsLegend > div,
            #chartPartOverviewDefectsLegend > div {
                padding: 1px 4px !important;
                font-size: 8px !important;
                line-height: 1.2 !important;
                border-radius: 3px !important;
            }
            #chartOverviewModelsLegend > div span,
            #chartOverviewPartsLegend > div span,
            #chartPartOverviewDefectsLegend > div span {
                font-size: 8px !important;
                max-width: 140px !important;
            }

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
                padding: 3px 6px !important;
            }

            canvas {
                max-width: 100% !important;
                height: auto !important;
            }
        }
        </style>

        <!-- ── Sub-views Partials ── -->
        <?php require_once __DIR__ . '/partials/filter_toolbar.php'; ?>
        <?php require_once __DIR__ . '/partials/print_header.php'; ?>
        <?php require_once __DIR__ . '/partials/view_overview.php'; ?>
        <?php require_once __DIR__ . '/partials/view_part_overview.php'; ?>
        <?php require_once __DIR__ . '/partials/modal_threshold.php'; ?>

    <!-- Vendor Chart.js -->
    <script src="<?= base_url('assets/js/vendor/chart.min.js') ?>"></script>

    <!-- Global State Bridge from PHP to JavaScript Modules -->
    <script>
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
    </script>

    <!-- Modularized JavaScript Controllers (Locally Cached by Browser) -->
    <script src="<?= base_url('assets/js/modules/defect_analysis/common.js?v=' . filemtime(__DIR__ . '/../../assets/js/modules/defect_analysis/common.js')) ?>"></script>
    <script src="<?= base_url('assets/js/modules/defect_analysis/overview.js?v=' . filemtime(__DIR__ . '/../../assets/js/modules/defect_analysis/overview.js')) ?>"></script>
    <script src="<?= base_url('assets/js/modules/defect_analysis/part_overview.js?v=' . filemtime(__DIR__ . '/../../assets/js/modules/defect_analysis/part_overview.js')) ?>"></script>
    <script src="<?= base_url('assets/js/modules/defect_analysis/excel_filters.js?v=' . filemtime(__DIR__ . '/../../assets/js/modules/defect_analysis/excel_filters.js')) ?>"></script>
    <script src="<?= base_url('assets/js/modules/defect_analysis/threshold_modal.js?v=' . filemtime(__DIR__ . '/../../assets/js/modules/defect_analysis/threshold_modal.js')) ?>"></script>

    </div><!-- End of #defect-body -->

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
