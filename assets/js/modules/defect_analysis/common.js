    // ── Global Analysis States ───────────────────────────────────────────────
    var overviewState = {
        selectedDefectId: null,
        selectedDefectName: '',
        trendMode: 'multi_horizon',     // 'multi_horizon' | 'daily'
        subResolution: 'weekly',        // 'weekly' (default) | 'daily'
        trendChartType: 'bar',          // default 'bar' for multi_horizon
        trendInterval: 'daily',
        dailyMode: 'work_cycle',        // 'work_cycle' | 'full_month'
        modelChartType: 'table',        // 'table' | 'bar_total' | 'line_trend' | 'bar_trend'
        modelTopRank: 5,                // 3 | 5 | 'all'
        modelMetric: 'pcs',             // 'pcs' | 'freq'
        partChartType: 'table',         // 'table' | 'bar_total' | 'line_trend' | 'bar_trend'
        partTopRank: 5,                 // 3 | 5 | 'all'
        partMetric: 'pcs',              // 'pcs' | 'ppm'
        threshold: null,
        thresholdInfo: null,
        cachedDefects: null,
        cachedTrendData: null,
        cachedMultiHorizonData: null,
        cachedModelsData: null,
        cachedModelsTimelineData: null,
        cachedPartsData: null,
        cachedPartsTimelineData: null
    };

    var chartOverviewTrendInstance = null;
    var chartOverviewModelsInstance = null;
    var chartOverviewPartsInstance = null;
    var chartOverviewPartsDonutInstance = null;

    var partOverviewState = {
        selectedPartId: null,
        selectedPartCode: '',
        selectedPartName: '',
        trendMode: 'multi_horizon',     // 'multi_horizon' | 'daily'
        subResolution: 'weekly',        // 'weekly' (default) | 'daily'
        trendChartType: 'bar',          // default 'bar' for multi_horizon
        trendInterval: 'daily',
        dailyMode: 'work_cycle',        // 'work_cycle' | 'full_month'
        defectChartType: 'table',
        defectTrendResolution: 'weekly',
        defectTopRank: 5,
        threshold: null,
        thresholdInfo: null,
        cachedParts: null,
        cachedTrendData: null,
        cachedMultiHorizonData: null,
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
        } else {
            p.append('preset', funnelState.preset);
        }

        // Always append month and year for multi-horizon & monthly synchronization
        p.append('month', funnelState.month);
        p.append('year', funnelState.year);

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
        } else {
            p.append('preset', funnelState.preset);
        }
        p.append('month', funnelState.month);
        p.append('year', funnelState.year);

        p.append('mode', currentAnalysisMode);

        if (currentAnalysisMode === 'overview') {
            if (overviewState.selectedDefectId) {
                p.append('defect_id', overviewState.selectedDefectId);
            }
            p.append('trend_mode', overviewState.trendMode || 'multi_horizon');
            p.append('sub_resolution', overviewState.subResolution || 'weekly');
        } else if (currentAnalysisMode === 'part_overview') {
            if (partOverviewState.selectedPartId) {
                p.append('part_id', partOverviewState.selectedPartId);
            }
            p.append('trend_mode', partOverviewState.trendMode || 'multi_horizon');
            p.append('sub_resolution', partOverviewState.subResolution || 'weekly');
        }

        if (funnelState.filterType === 'custom' && funnelState.startDate && funnelState.endDate) {
            p.append('start_date', funnelState.startDate);
            p.append('end_date', funnelState.endDate);
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
            var mNames = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
            var mIndex = parseInt(funnelState.month, 10) || (new Date()).getMonth() + 1;
            var curMonthName = mNames[mIndex] || ('Bulan ' + mIndex);
            var curYear = funnelState.year || (new Date()).getFullYear();

            var periodDesc = curMonthName + ' ' + curYear;
            if (funnelState.filterType === 'specific_year') {
                periodDesc = 'Tahun ' + funnelState.year;
            } else if (funnelState.filterType === 'all_years') {
                periodDesc = 'Semua Tahun';
            } else if (funnelState.filterType === 'custom' && funnelState.startDate && funnelState.endDate) {
                periodDesc = funnelState.startDate + ' s.d. ' + funnelState.endDate;
            } else {
                // Default / Preset bulanan / month_year
                periodDesc = curMonthName + ' ' + curYear;
            }
            periodEl.textContent = periodDesc;
        }

        if (custEl) {
            custEl.textContent = funnelState.customer || 'Semua Customer';
        }

        if (currentAnalysisMode === 'overview') {
            if (titleEl) titleEl.textContent = 'LAPORAN ANALISIS & TREN DEFECT';
            var defName = overviewState.selectedDefectName || $('#lblOverviewDefectName').text() || 'Semua Defect';
            if (badgeEl) badgeEl.textContent = defName;
            if (overviewState.cachedDefects && overviewState.cachedDefects.items) {
                renderTop3SummaryPills(overviewState.cachedDefects.items, overviewState.cachedDefects.unit);
            }
        } else {
            if (titleEl) titleEl.textContent = 'LAPORAN ANALISIS & TREN PART';
            var pCode = partOverviewState.selectedPartCode || '';
            var pName = partOverviewState.selectedPartName || $('#lblPartOverviewPartName').text() || 'Semua Part';
            var fullP = pCode ? (pCode + ' - ' + pName) : pName;
            if (badgeEl) badgeEl.textContent = fullP;
            if (partOverviewState.cachedParts && partOverviewState.cachedParts.items) {
                renderPartTop3SummaryPills(partOverviewState.cachedParts.items, partOverviewState.cachedParts.unit);
            }
        }

        // Resize seluruh canvas chart aktif sebelum dialog print muncul
        if (chartOverviewTrendInstance) chartOverviewTrendInstance.resize();
        if (chartOverviewModelsInstance) chartOverviewModelsInstance.resize();
        if (chartOverviewPartsInstance) chartOverviewPartsInstance.resize();
        if (chartPartOverviewTrendInstance) chartPartOverviewTrendInstance.resize();
        if (chartPartOverviewDefectsInstance) chartPartOverviewDefectsInstance.resize();

        // Trigger browser native print dialogue with slight delay for clean canvas rendering
        setTimeout(function() {
            window.print();
        }, 150);
    }

    // ── Sub-Filter Controls Toggle ────────────────────────────────────────────
    // ── Filter Controls Actions (Simplified Direct Month & Year Controls) ──
    var CURRENT_SYSTEM_MONTH = (new Date()).getMonth() + 1;
    var CURRENT_SYSTEM_YEAR  = (new Date()).getFullYear();

    function applyCurrentMonthFilter() {
        var selM = document.getElementById('directSelectMonth');
        var selY = document.getElementById('directSelectYear');
        if (selM) selM.value = CURRENT_SYSTEM_MONTH;
        if (selY) selY.value = CURRENT_SYSTEM_YEAR;

        funnelState.month      = CURRENT_SYSTEM_MONTH;
        funnelState.year       = CURRENT_SYSTEM_YEAR;
        funnelState.preset     = 'bulanan';
        funnelState.filterType = 'month_year';

        updateDateFilterButtonStyles();
        refreshActiveSelections();
    }

    function onMonthYearChanged() {
        var selM = document.getElementById('directSelectMonth');
        var selY = document.getElementById('directSelectYear');
        var m = selM ? parseInt(selM.value, 10) : CURRENT_SYSTEM_MONTH;
        var y = selY ? parseInt(selY.value, 10) : CURRENT_SYSTEM_YEAR;

        funnelState.month      = m;
        funnelState.year       = y;
        funnelState.preset     = (m === CURRENT_SYSTEM_MONTH && y === CURRENT_SYSTEM_YEAR) ? 'bulanan' : 'month_year';
        funnelState.filterType = 'month_year';

        updateDateFilterButtonStyles();
        refreshActiveSelections();
    }

    function updateDateFilterButtonStyles() {
        var btn = document.getElementById('btnFilterBulanIni');
        if (!btn) return;
        var isCurrent = (funnelState.month === CURRENT_SYSTEM_MONTH && funnelState.year === CURRENT_SYSTEM_YEAR);
        if (isCurrent) {
            btn.style.background = '#2563eb';
            btn.style.color      = '#ffffff';
            btn.style.borderColor = '#bfdbfe';
            btn.style.boxShadow  = '0 1px 3px rgba(37,99,235,0.25)';
        } else {
            btn.style.background = 'transparent';
            btn.style.color      = '#64748b';
            btn.style.borderColor = '#cbd5e1';
            btn.style.boxShadow  = 'none';
        }
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
        var btnPpm = document.getElementById('btnUnitPpm');
        var btnLot = document.getElementById('btnUnitLot');

        [btnPcs, btnPpm, btnLot].forEach(function(b) {
            if (b) {
                b.style.background = 'transparent';
                b.style.color = '#64748b';
                b.style.boxShadow = 'none';
            }
        });

        if (unit === 'pcs' && btnPcs) {
            btnPcs.style.background = '#0f172a';
            btnPcs.style.color = '#ffffff';
            btnPcs.style.boxShadow = '0 1px 3px rgba(15,23,42,0.25)';
        } else if (unit === 'ppm' && btnPpm) {
            btnPpm.style.background = '#4f46e5';
            btnPpm.style.color = '#ffffff';
            btnPpm.style.boxShadow = '0 1px 3px rgba(79,70,229,0.3)';
        } else if (unit === 'lot' && btnLot) {
            btnLot.style.background = '#d97706';
            btnLot.style.color = '#ffffff';
            btnLot.style.boxShadow = '0 1px 3px rgba(217,119,6,0.3)';
        }

        var uUpper = unit.toUpperCase();
        document.querySelectorAll('.overviewCardUnitBadge, .partOverviewCardUnitBadge').forEach(function(el) {
            el.textContent = uUpper;
            if (unit === 'ppm') {
                el.style.color = '#4338ca';
                el.style.backgroundColor = '#eef2ff';
                el.style.borderColor = '#c7d2fe';
            } else if (unit === 'lot') {
                el.style.color = '#b45309';
                el.style.backgroundColor = '#fffbeb';
                el.style.borderColor = '#fde68a';
            } else {
                el.style.color = '#1e293b';
                el.style.backgroundColor = '#f8fafc';
                el.style.borderColor = '#cbd5e1';
            }
        });

        refreshActiveSelections();
    }

    function onCustomerChange(cust) {
        funnelState.customer = cust;
        refreshActiveSelections();
    }

    function resetAllFilters() {
        funnelState.unit        = 'pcs';
        funnelState.sampleBasis = 'lot';
        funnelState.customer    = '';
        funnelState.topRank     = 'all';
        var selCust = document.getElementById('selectCustomer');
        if (selCust) selCust.value = '';

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
        var btnPpm = document.getElementById('btnUnitPpm');
        if (btnPpm) {
            btnPpm.style.background = 'transparent';
            btnPpm.style.color = '#64748b';
            btnPpm.style.boxShadow = 'none';
        }

        document.querySelectorAll('.overviewCardUnitBadge, .partOverviewCardUnitBadge').forEach(function(el) {
            el.textContent = 'PCS';
            el.style.color = '#1e293b';
            el.style.backgroundColor = '#f8fafc';
            el.style.borderColor = '#cbd5e1';
        });

        // Clear drilldown selections on explicit full reset
        clearFullSelections();
        applyCurrentMonthFilter();
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
        updateDateFilterButtonStyles();
        updateWeeklySwitcherVisibility();
    }

    function updateWeeklySwitcherVisibility() {
        // Tampilkan switcher mingguan hanya jika filter adalah bulanan atau pilih bulan (atau custom <= 35 hari)
        var isMonthly = (funnelState.preset === 'bulanan' || funnelState.filterType === 'month_year' || funnelState.preset === 'month_year');
        
        var grpOverview = document.getElementById('overviewTrendIntervalGroup');
        var grpPart     = document.getElementById('partOverviewTrendIntervalGroup');
        var grpOverviewDaily = document.getElementById('overviewDailyModeGroup');
        var grpPartDaily     = document.getElementById('partOverviewDailyModeGroup');

        if (grpOverview) grpOverview.style.display = isMonthly ? 'inline-flex' : 'none';
        if (grpPart) grpPart.style.display = isMonthly ? 'inline-flex' : 'none';

        if (grpOverviewDaily) {
            grpOverviewDaily.style.display = (isMonthly && overviewState.trendInterval === 'daily') ? 'inline-flex' : 'none';
        }
        if (grpPartDaily) {
            grpPartDaily.style.display = (isMonthly && partOverviewState.trendInterval === 'daily') ? 'inline-flex' : 'none';
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
        if (!str && str !== 0) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function escapeQuotes(str) {
        if (!str) return '';
        return String(str).replace(/'/g, "\\'").replace(/"/g, '&quot;');
    }

    // ── AbortController Request Manager (Mencegah Race Condition di LAN) ───────
    var currentOverviewAbortController = null;
    var currentPartOverviewAbortController = null;

    function getOverviewSignal() {
        if (currentOverviewAbortController) {
            currentOverviewAbortController.abort();
        }
        currentOverviewAbortController = new AbortController();
        return currentOverviewAbortController.signal;
    }

    function getPartOverviewSignal() {
        if (currentPartOverviewAbortController) {
            currentPartOverviewAbortController.abort();
        }
        currentPartOverviewAbortController = new AbortController();
        return currentPartOverviewAbortController.signal;
    }

    function isAbortError(err) {
        return err && (err.name === 'AbortError' || err.code === 20);
    }

