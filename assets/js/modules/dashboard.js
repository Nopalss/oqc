    Chart.defaults.font.family = "'system-ui','Segoe UI',sans-serif";
    Chart.defaults.color       = '#94a3b8';
    var activeUnit = (window.OQC_DASH_CONFIG && window.OQC_DASH_CONFIG.activeUnit) ? window.OQC_DASH_CONFIG.activeUnit : 'Pcs';
    var activeSampleBasis = (window.OQC_DASH_CONFIG && window.OQC_DASH_CONFIG.activeSampleBasis) ? window.OQC_DASH_CONFIG.activeSampleBasis : 'lot';
    var isPassedMode = (window.OQC_DASH_CONFIG && window.OQC_DASH_CONFIG.isPassedMode) || false;
    var totalInspectedKpi = (window.OQC_DASH_CONFIG && window.OQC_DASH_CONFIG.totalInspectedKpi) || 0;
    var totalNgKpi = (window.OQC_DASH_CONFIG && window.OQC_DASH_CONFIG.totalNgKpi) || 0;
    var initialTotalModelsNg = (window.OQC_DASH_CONFIG && window.OQC_DASH_CONFIG.initialTotalModelsNg) || 0;
    var initialTotalPartsNg = (window.OQC_DASH_CONFIG && window.OQC_DASH_CONFIG.initialTotalPartsNg) || 0;

    /**
     * Plugin Chart.js untuk menampilkan teks persentase (%)
     * tepat di dalam lengkungan warna donat (in-arc label)
     */
    var inDoughnutArcPercentPlugin = {
        id: 'inDoughnutArcPercentPlugin',
        afterDatasetsDraw: function(chart) {
            var ctx = chart.ctx;
            var meta = chart.getDatasetMeta(0);
            if (!meta || !meta.data || !meta.data.length || meta.hidden) return;

            var ds = chart.data.datasets[0];
            if (!ds || !ds.data) return;

            // Denominator: default ke totalInspectedKpi (rasio terhadap seluruh sampel inspeksi)
            var denom = (typeof totalInspectedKpi !== 'undefined' && totalInspectedKpi > 0) 
                        ? totalInspectedKpi 
                        : (ds.data.reduce(function(a, b) { return a + Number(b || 0); }, 0) || 1);

            ctx.save();
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';

            meta.data.forEach(function(arc, idx) {
                var val = Number(ds.data[idx] || 0);
                if (val <= 0) return;

                // Hitung sudut rentang busur (circumference)
                var startAngle = arc.startAngle;
                var endAngle = arc.endAngle;
                var angleDiff = Math.abs(endAngle - startAngle);

                // Jika busur terlalu sempit (kurang dari ~14 derajat / 0.24 radian), lewati agar tidak overlap
                if (angleDiff < 0.24) return;

                var outerR = arc.outerRadius || 0;
                var innerR = arc.innerRadius || 0;
                var midR   = (outerR + innerR) / 2;
                var midAngle = startAngle + (endAngle - startAngle) / 2;

                var posX = arc.x + Math.cos(midAngle) * midR;
                var posY = arc.y + Math.sin(midAngle) * midR;

                var labelStr = '';
                if (ds.is_freq) {
                    labelStr = val + 'x';
                } else {
                    var pct = (val / denom) * 100;
                    labelStr = (pct >= 10 ? pct.toFixed(1) : (pct >= 0.1 ? pct.toFixed(1) : pct.toFixed(2))) + '%';
                }

                // Shadow lembut agar kontras pada warna terang maupun gelap
                ctx.shadowColor = 'rgba(0, 0, 0, 0.6)';
                ctx.shadowBlur = 3;
                ctx.fillStyle = '#ffffff';

                // Sesuaikan ukuran font dengan ketebalan donat
                var thickness = outerR - innerR;
                var fontSize = (thickness >= 26) ? 9.5 : (thickness >= 18 ? 8.5 : 7.5);
                ctx.font = '800 ' + fontSize + 'px Inter, system-ui, sans-serif';

                ctx.fillText(labelStr, posX, posY);
            });

            ctx.restore();
        }
    };

    var currentDashboardVersion = (window.OQC_DASH_CONFIG && window.OQC_DASH_CONFIG.currentDashboardVersion) || '';
    var filterParams = (window.OQC_DASH_CONFIG && window.OQC_DASH_CONFIG.filterParams) ? window.OQC_DASH_CONFIG.filterParams : {};

    // ── LocalStorage Caching Engine (Single-Slot Overwrite Pattern) ───────────
    // Menggunakan 1 key statis tunggal sehingga data selalu ditimpa (overwrite),
    // mencegah penumpukan sampah cache dan menjaga konsumsi RAM/Storage tetap sangat ringan.
    var DASHBOARD_CACHE_SLOT = 'oqc_dash_cache_active';

    function getDashboardCacheKey() {
        return DASHBOARD_CACHE_SLOT;
    }

    function getDashboardFilterSignature() {
        return [
            filterParams.customer || 'all',
            filterParams.unit || 'pcs',
            filterParams.sample_basis || 'lot',
            filterParams.result || 'rejected',
            (typeof currentSubResolution !== 'undefined' ? currentSubResolution : 'weekly'),
            (typeof currentMetricScale !== 'undefined' ? currentMetricScale : 'ppm'),
            filterParams.preset || 'bulanan',
            filterParams.filter_type || 'default',
            filterParams.start_date || '',
            filterParams.end_date || '',
            filterParams.top_rank || 5,
            filterParams.defect_rank || 3
        ].join('_');
    }

    function purgeOldDashboardVersions() {
        try {
            var now = Date.now();
            for (var i = localStorage.length - 1; i >= 0; i--) {
                var k = localStorage.key(i);
                if (k && k.indexOf('oqc_dash_cache_') === 0) {
                    // Sapu bersih seluruh key sampah multi-slot lama (v1, v2, v3, v4, dll.)
                    if (k !== DASHBOARD_CACHE_SLOT) {
                        localStorage.removeItem(k);
                    } else {
                        // Jika slot aktif sudah lebih dari 2 jam, hapus agar fresh
                        try {
                            var item = JSON.parse(localStorage.getItem(k));
                            if (item && item.time && (now - item.time > 2 * 3600 * 1000)) {
                                localStorage.removeItem(k);
                            }
                        } catch (e) {
                            localStorage.removeItem(k);
                        }
                    }
                }
            }
        } catch (e) {}
    }

    function saveDashboardCache(data) {
        if (!data || data.status !== 'success') return;
        try {
            // Bersihkan sisa-sisa key lama jika ada
            purgeOldDashboardVersions();

            var key = getDashboardCacheKey();
            var payload = {
                v: data.version || currentDashboardVersion,
                sig: getDashboardFilterSignature(),
                time: Date.now(),
                data: data
            };
            // Selalu MENIMPA (overwrite) slot tunggal
            localStorage.setItem(key, JSON.stringify(payload));
        } catch (err) {
            console.warn('[OQC Cache] LocalStorage quota exceeded or error:', err);
        }
    }

    function clearDashboardCache() {
        try {
            for (var i = localStorage.length - 1; i >= 0; i--) {
                var k = localStorage.key(i);
                if (k && k.indexOf('oqc_dash_cache_') === 0) {
                    localStorage.removeItem(k);
                }
            }
        } catch (e) {}
    }

    function loadDashboardCache() {
        try {
            var key = getDashboardCacheKey();
            var raw = localStorage.getItem(key);
            if (!raw) return null;
            var parsed = JSON.parse(raw);
            // Validasi: pastikan signature filter cache sama persis dengan filter halaman saat ini
            if (parsed && parsed.data && parsed.data.status === 'success') {
                if (parsed.sig === getDashboardFilterSignature()) {
                    return parsed;
                }
            }
        } catch (e) {}
        return null;
    }

    var trendChartInstance          = null;
    var defectStackedChartInstance  = null;
    var defectDoughnutChartInstance = null;
    var modelPieChartInstance       = null;
    var partPieChartInstance        = null;

    var multiHorizonData = (window.OQC_DASH_CONFIG && window.OQC_DASH_CONFIG.multiHorizonData) ? window.OQC_DASH_CONFIG.multiHorizonData : { labels: [], rates: [], sections: [] };
    var trendLabelsData = (window.OQC_DASH_CONFIG && window.OQC_DASH_CONFIG.trendLabelsData) ? window.OQC_DASH_CONFIG.trendLabelsData : [];
    var trendNGData = (window.OQC_DASH_CONFIG && window.OQC_DASH_CONFIG.trendNGData) ? window.OQC_DASH_CONFIG.trendNGData : [];
    var trendSampleData = (window.OQC_DASH_CONFIG && window.OQC_DASH_CONFIG.trendSampleData) ? window.OQC_DASH_CONFIG.trendSampleData : [];
    var globalTargetPpm = (window.OQC_DASH_CONFIG && window.OQC_DASH_CONFIG.globalTargetPpm) ? parseFloat(window.OQC_DASH_CONFIG.globalTargetPpm) : 0;
    var currentChartType       = localStorage.getItem('oqc_trend_chart_type') || 'bar';
    var currentMetricScale     = localStorage.getItem('oqc_trend_metric_scale') || 'ppm';
    if (currentMetricScale === 'ppb') currentMetricScale = 'pct';
    var currentSubResolution   = localStorage.getItem('oqc_trend_sub_resolution') || 'weekly';
    var currentModelMetricMode = localStorage.getItem('oqc_model_metric_mode') || 'pcs';
    var modelCasesData = (window.OQC_DASH_CONFIG && window.OQC_DASH_CONFIG.modelCasesData) ? window.OQC_DASH_CONFIG.modelCasesData : [];

    // ══════════════════════════════════════════════════════════════════════════
    // ZERO-LAG CASCADING DRILLDOWN ENGINE (DEFECT -> MODEL -> PART -> FINDINGS)
    // ══════════════════════════════════════════════════════════════════════════
    var cascadeActiveFilters = {
        defect: null,
        model:  null,
        part:   null
    };

    function initTrendChart(type, scale, isLiveUpdate) {
        var canvas = document.getElementById('chartTrendNG');
        if (!canvas) return;

        type  = type || currentChartType;
        scale = scale || currentMetricScale;

        // Mode 'bar' otomatis memunculkan Batang + Garis Tren sekaligus
        var hasBar    = (type === 'bar');
        var hasLine   = (type === 'bar' || type === 'line');
        var isLineOnly= (type === 'line');
        var isPct     = (scale === 'pct' || scale === 'percent' || scale === '%');
        var scaleUnit = isPct ? '%' : 'PPM';
        var labels    = multiHorizonData.labels || [];
        var rawRates  = multiHorizonData.rates || [];
        var samples   = multiHorizonData.samples || [];
        var ngs       = multiHorizonData.ngs || [];
        var barColors = multiHorizonData.barColors || [];
        var borderCols= multiHorizonData.borderColors || [];
        var tooltips  = multiHorizonData.tooltips || [];
        var sections  = multiHorizonData.sections || [];

        // Konversi nilai jika user memilih skala %
        var displayRates = rawRates.map(function(val) {
            if (activeUnit.toLowerCase() === 'lot') {
                return Number(val || 0);
            }
            if (isPct) {
                return Math.round((Number(val || 0) / 10000) * 100) / 100;
            }
            return Number(val || 0);
        });

        var isDense = (currentSubResolution === 'daily' || labels.length > 25);
        var scrollContainer = document.getElementById('trendChartScrollContainer');
        if (scrollContainer) {
            canvas.style.minWidth = '100%';
            canvas.style.width    = '100%';
        }

        var primaryColor = isPassedMode ? '#059669' : '#2563eb';
        var primaryBg    = isPassedMode ? 'rgba(5,150,105,0.08)' : 'rgba(37,99,235,0.08)';

        // Warna titik point dan segmen garis per zona (Tahun: #1e293b, Bulan: #2563eb, Minggu: #059669)
        var pointBackgroundColors = barColors.map(function(c) { return c || primaryColor; });

        // Pembatas zona untuk memutuskan garis yang melintasi pembatas zona horizon
        var boundaryIndices = [];
        if (sections && sections.length > 1) {
            for (var s = 0; s < sections.length - 1; s++) {
                boundaryIndices.push(sections[s].endIndex);
            }
        }

        var datasets = [];
        var rateLabel = isPassedMode ? ('Yield Rate (' + scaleUnit + ')') : ('Defect Rate (' + scaleUnit + ')');

        // 1. DATASET BATANG (Tampil pada mode 'bar')
        if (hasBar) {
            datasets.push({
                type: 'bar',
                label: rateLabel,
                data: displayRates,
                borderColor: borderCols,
                backgroundColor: barColors,
                borderWidth: 1,
                borderRadius: isDense ? 2 : 4,
                barPercentage: isDense ? 0.75 : 0.65,
                categoryPercentage: isDense ? 0.90 : 0.85,
                order: 3
            });
        }

        // 2. DATASET GARIS TREN (Tampil pada mode 'bar' maupun 'line')
        if (hasLine) {
            datasets.push({
                type: 'line',
                label: 'Aktual ' + rateLabel,
                data: displayRates,
                borderColor: primaryColor,
                backgroundColor: primaryBg,
                borderWidth: 2.5,
                segment: {
                    borderColor: function(ctxSeg) {
                        var p0 = ctxSeg.p0DataIndex;
                        // Jika segmen melintasi batas zona horizon (contoh: dari 2026 ke Jan, atau dari Okt ke W1), putus garisnya!
                        if (boundaryIndices.indexOf(p0) !== -1) {
                            return 'transparent';
                        }
                        return barColors[p0] || primaryColor;
                    }
                },
                pointBackgroundColor: pointBackgroundColors,
                pointBorderColor: '#ffffff',
                pointBorderWidth: 2,
                pointRadius: hasBar ? (isDense ? 3 : 4.5) : (isDense ? 3 : 4),
                pointHoverRadius: 7,
                fill: false,
                tension: 0.25,
                order: 2
            });
        }

        // 3. Garis Merah Target PPM (Legend reference & tooltip)
        if (!isPassedMode && !isPct && globalTargetPpm > 0 && labels.length > 0) {
            var targetData = labels.map(function() { return globalTargetPpm; });
            datasets.push({
                type: 'line',
                label: 'Batas Target (Maks ' + Number(globalTargetPpm).toLocaleString() + ' PPM)',
                data: targetData,
                borderColor: '#ef4444',
                backgroundColor: '#ef4444',
                borderWidth: 2,
                borderDash: [6, 4],
                pointRadius: 0,
                pointHoverRadius: 0,
                pointHitRadius: 0,
                fill: false,
                tension: 0,
                order: 1
            });
        }

        var ctx = canvas.getContext('2d');

        var dividerPluginDashboard = {
            id: 'multiHorizonSectionDividerDash',
            afterDatasetsDraw: function(chart) {
                var chartCtx = chart.ctx;
                var xAxis = chart.scales.x;
                var yAxis = chart.scales.y;
                var chartArea = chart.chartArea;
                if (!chartArea || !xAxis || !yAxis) return;

                // 1. Gambar Garis Vertikal Pemisah Antar Seksi
                if (sections && sections.length > 1) {
                    chartCtx.save();
                    chartCtx.lineWidth = 1.5;
                    chartCtx.strokeStyle = '#94a3b8';
                    chartCtx.setLineDash([4, 4]);

                    for (var s = 0; s < sections.length - 1; s++) {
                        var endIdx = sections[s].endIndex;
                        var nextIdx = endIdx + 1;
                        if (nextIdx < labels.length) {
                            var x1 = xAxis.getPixelForValue(endIdx);
                            var x2 = xAxis.getPixelForValue(nextIdx);
                            var dividerX = (x1 + x2) / 2;

                            chartCtx.beginPath();
                            chartCtx.moveTo(dividerX, chartArea.top);
                            chartCtx.lineTo(dividerX, chartArea.bottom);
                            chartCtx.stroke();
                        }
                    }
                    chartCtx.restore();
                }

                // 2. Gambar Garis Merah Target PPM Full Width (Dari Ujung Paling Kiri ke Ujung Paling Kanan Chart)
                if (!isPassedMode && !isPct && globalTargetPpm > 0 && labels.length > 0) {
                    var targetY = yAxis.getPixelForValue(globalTargetPpm);
                    if (targetY >= chartArea.top && targetY <= chartArea.bottom) {
                        chartCtx.save();
                        chartCtx.lineWidth = 2;
                        chartCtx.strokeStyle = '#ef4444';
                        chartCtx.setLineDash([6, 4]);

                        chartCtx.beginPath();
                        chartCtx.moveTo(chartArea.left, targetY);
                        chartCtx.lineTo(chartArea.right, targetY);
                        chartCtx.stroke();
                        chartCtx.restore();
                    }
                }

                // 2. Tampilkan Angka Nilai PPM/Rate (Teks hitam di atas luar batang pada resolusi harian; teks putih di dalam batang pada resolusi mingguan/tahunan)
                var activeDatasets = chart.data.datasets || [];
                var barDsIndex = -1;
                for (var dsI = 0; dsI < activeDatasets.length; dsI++) {
                    if (activeDatasets[dsI].type === 'bar') {
                        barDsIndex = dsI;
                        break;
                    }
                }

                if (barDsIndex >= 0) {
                    var barMeta = chart.getDatasetMeta(barDsIndex);
                    if (barMeta && !barMeta.hidden && barMeta.data) {
                        var barDatasetData = activeDatasets[barDsIndex].data || [];
                        var isDailyMode = (currentSubResolution === 'daily' || isDense);

                        chartCtx.save();
                        chartCtx.textAlign = 'center';

                        barMeta.data.forEach(function(element, idx) {
                            var rawVal = Number(barDatasetData[idx] || 0);
                            if (rawVal <= 0) return; // Nilai 0 tidak perlu dicetak agar bersih

                            var barY = element.y;
                            var barBase = element.base;
                            var barWidth = element.width;
                            var centerX = element.x;

                            // Hitung tinggi final batang berdasarkan nilai data aktual terhadap skala sumbu Y
                            // Ini mencegah bug visual flicker (teks melompat dari luar ke dalam batang saat animasi refresh)
                            var targetY = yAxis.getPixelForValue(rawVal);
                            var finalBarHeight = Math.abs(barBase - targetY);

                            // Format angka ribuan tanpa kata "PPM" (contoh: 2.000 atau 10.350)
                            var valStr = isPct 
                                ? rawVal.toLocaleString(undefined, {minimumFractionDigits: 1, maximumFractionDigits: 2})
                                : Math.round(rawVal).toLocaleString('id-ID');

                            if (isDailyMode) {
                                // Resolusi Harian: Teks selalu HITAM/GELAP dan berada di LUAR/DI ATAS batang
                                chartCtx.textBaseline = 'bottom';
                                chartCtx.fillStyle = '#0f172a';
                                chartCtx.shadowColor = 'rgba(255, 255, 255, 0.9)';
                                chartCtx.shadowBlur = 3;
                                chartCtx.font = (barWidth >= 22) ? '800 10px Inter, system-ui, sans-serif' : '800 8.5px Inter, system-ui, sans-serif';
                                chartCtx.fillText(valStr, centerX, barY - 3);
                            } else {
                                // Resolusi Mingguan & Tahunan: Evaluasi berdasarkan tinggi final batang
                                if (finalBarHeight >= 22) {
                                    // Data besar / batang tinggi: Teks PUTIH konsisten di DALAM batang sejak frame awal animasi
                                    chartCtx.textBaseline = 'top';
                                    chartCtx.fillStyle = '#ffffff';
                                    chartCtx.shadowColor = 'rgba(0, 0, 0, 0.7)';
                                    chartCtx.shadowBlur = 3;
                                    chartCtx.font = (barWidth >= 28) ? '800 11px Inter, system-ui, sans-serif' : '700 9.5px Inter, system-ui, sans-serif';
                                    chartCtx.fillText(valStr, centerX, barY + 5);
                                } else {
                                    // Data memang sedikit / batang aslinya pendek (< 22px): Teks HITAM konsisten di ATAS batang
                                    chartCtx.textBaseline = 'bottom';
                                    chartCtx.fillStyle = '#0f172a';
                                    chartCtx.shadowColor = 'rgba(255, 255, 255, 0.8)';
                                    chartCtx.shadowBlur = 2;
                                    chartCtx.font = '800 10px Inter, system-ui, sans-serif';
                                    chartCtx.fillText(valStr, centerX, barY - 3);
                                }
                            }
                        });
                        chartCtx.restore();
                    }
                }
            }
        };

        if (trendChartInstance) {
            trendChartInstance.destroy();
        }

        trendChartInstance = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: datasets
            },
            plugins: [dividerPluginDashboard],
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'bottom',
                        align: 'center',
                        labels: {
                            boxWidth: 16,
                            boxHeight: 3,
                            font: { size: 10, weight: '700' },
                            color: '#475569',
                            padding: 12,
                            filter: function(legendItem, chartData) {
                                // Jika ada mode bar dan line bersamaan, sembunyikan dataset bar agar tidak dobel label
                                var ds = chartData.datasets[legendItem.datasetIndex];
                                if (!ds) return true;
                                if (hasBar && hasLine && ds.type === 'bar') {
                                    return false; // Sembunyikan balok hitam, tampilkan garis tren & garis target
                                }
                                return true;
                            }
                        }
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        backgroundColor: '#0f172a',
                        titleColor: '#f8fafc',
                        bodyColor: '#e2e8f0',
                        padding: 10,
                        cornerRadius: 6,
                        callbacks: {
                            title: function(items) {
                                if (!items || items.length === 0) return '';
                                var idx = items[0].dataIndex;
                                return (tooltips[idx] !== undefined) ? tooltips[idx] : items[0].label;
                            },
                            label: function(context) {
                                if (context.dataset && context.dataset.borderColor === '#ef4444') {
                                    return ' 🎯 Batas Target: ' + Number(context.raw || 0).toLocaleString() + ' PPM';
                                }
                                // Pada mode bar (ada bar + line), cegah dobel label di tooltip
                                if (hasBar && context.datasetIndex > 0 && datasets[0].type === 'bar') {
                                    return null; // Tampilkan satu baris saja untuk nilai aktual
                                }
                                var val = Number(context.raw || 0).toLocaleString(undefined, {minimumFractionDigits: isPct ? 1 : 0, maximumFractionDigits: isPct ? 2 : 1});
                                return (isPassedMode ? ' Yield Rate: ' : ' Defect Rate: ') + val + ' ' + scaleUnit;
                            },
                            afterLabel: function(context) {
                                if (context.dataset && context.dataset.borderColor === '#ef4444') {
                                    return '';
                                }
                                if (hasBar && context.datasetIndex > 0 && datasets[0].type === 'bar') {
                                    return '';
                                }
                                var idx = context.dataIndex;
                                var ngVal = Number(ngs[idx] || 0).toLocaleString();
                                var spVal = Number(samples[idx] || 0).toLocaleString();
                                if (activeUnit.toLowerCase() === 'lot') {
                                    return ' (' + ngVal + (isPassedMode ? ' lot pass / ' : ' lot reject / ') + spVal + ' lot dicek)';
                                } else {
                                    var basisText = (activeSampleBasis === 'session') ? ' sample sesi' : ' sample lot';
                                    return ' (' + ngVal + (isPassedMode ? ' pass / ' : ' defect / ') + spVal + basisText + ' dicek)';
                                }
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            autoSkip: false,
                            font: { 
                                size: isDense ? 8.5 : 10, 
                                weight: '700' 
                            },
                            padding: isDense ? 2 : 4,
                            color: function(ctxTick) {
                                var idx = ctxTick.index;
                                if (barColors[idx]) return barColors[idx];
                                return '#64748b';
                            },
                            maxRotation: 0
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: '#f1f5f9' },
                        ticks: {
                            font: { size: 10, weight: '600' },
                            color: '#64748b',
                            callback: function(value) {
                                return Number(value).toLocaleString() + (isPct ? '%' : '');
                            }
                        }
                    }
                }
            }
        });

        updateChartSwitcherUI(type);
        updateMetricSwitcherUI(scale);
        updateResolutionSwitcherUI(currentSubResolution);
    }

    function switchTrendResolution(res) {
        currentSubResolution = res;
        localStorage.setItem('oqc_trend_sub_resolution', res);
        updateResolutionSwitcherUI(res);

        // Fetch data multi horizon terbaru via live sync endpoint
        fetchLatestDashboardData(true);
    }

    function updateResolutionSwitcherUI(res) {
        var btnW = document.getElementById('btnTrendResWeekly');
        var btnD = document.getElementById('btnTrendResDaily');
        if (!btnW || !btnD) return;

        if (res === 'weekly') {
            btnW.style.background = '#0f172a';
            btnW.style.color      = '#ffffff';
            btnW.style.boxShadow  = '0 1px 2px rgba(15,23,42,0.2)';
            btnD.style.background = 'transparent';
            btnD.style.color      = '#64748b';
            btnD.style.boxShadow  = 'none';
        } else {
            btnD.style.background = '#0f172a';
            btnD.style.color      = '#ffffff';
            btnD.style.boxShadow  = '0 1px 2px rgba(15,23,42,0.2)';
            btnW.style.background = 'transparent';
            btnW.style.color      = '#64748b';
            btnW.style.boxShadow  = 'none';
        }
    }

    function toggleTargetPpmModal(e) {
        if (e && e.stopPropagation) e.stopPropagation();
        var pop = document.getElementById('popoverTargetPpm');
        if (!pop) return;
        var isVisible = (pop.style.display === 'block');
        pop.style.display = isVisible ? 'none' : 'block';
        if (!isVisible) {
            var inp = document.getElementById('inputTargetPpm');
            if (inp) {
                setTimeout(function() { inp.focus(); inp.select(); }, 50);
            }
        }
    }

    function closeTargetPpmModal(e) {
        if (e && e.stopPropagation) e.stopPropagation();
        var pop = document.getElementById('popoverTargetPpm');
        if (pop) pop.style.display = 'none';
    }

    document.addEventListener('click', function(e) {
        var pop = document.getElementById('popoverTargetPpm');
        var btn = document.getElementById('btnSetPpmTarget');
        if (pop && pop.style.display === 'block') {
            if (!pop.contains(e.target) && (!btn || !btn.contains(e.target))) {
                pop.style.display = 'none';
            }
        }
    });

    function saveTargetPpm(e, forcedVal) {
        if (e && e.stopPropagation) e.stopPropagation();
        if (e && e.preventDefault) e.preventDefault();

        var inp = document.getElementById('inputTargetPpm');
        var val = (forcedVal !== undefined) ? forcedVal : (inp ? inp.value.trim() : '');
        var numVal = (val === '' || isNaN(val)) ? 0 : Math.max(0, parseFloat(val));
        var btn = document.getElementById('btnSaveTargetPpm');
        var originalBtnText = btn ? btn.textContent : 'Simpan';
        
        if (btn) {
            btn.textContent = '...';
        }

        var formData = new FormData();
        formData.append('target_key', 'dashboard_target_ppm');
        formData.append('target_val', numVal);

        fetch('ajax_save_target.php', {
            method: 'POST',
            body: formData
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (btn) {
                btn.textContent = originalBtnText;
            }
            if (data && data.success) {
                globalTargetPpm = Number(data.target_val || 0);
                if (inp) inp.value = (globalTargetPpm > 0) ? globalTargetPpm : '';
                var lbl = document.getElementById('lblDisplayTargetPpm');
                if (lbl) {
                    lbl.textContent = (globalTargetPpm > 0) ? Number(globalTargetPpm).toLocaleString() : 'Belum Diatur';
                }
                closeTargetPpmModal();
                clearDashboardCache();
                initTrendChart(currentChartType, currentMetricScale);
                if (typeof checkLiveHeartbeat === 'function') {
                    checkLiveHeartbeat();
                }
            } else {
                alert((data && data.message) ? data.message : 'Gagal menyimpan target');
            }
        })
        .catch(function(err) {
            if (btn) {
                btn.textContent = originalBtnText;
            }
            console.error('Error saving target ppm:', err);
            alert('Terjadi kesalahan jaringan saat menyimpan target');
        });
    }

    function switchTrendChartType(type) {
        currentChartType = type;
        localStorage.setItem('oqc_trend_chart_type', type);
        initTrendChart(type, currentMetricScale);
    }

    function updateKpiMetricCard(scale) {
        var totalInspected = totalInspectedKpi;
        var totalNG = totalNgKpi;
        var isPct = (scale === 'pct' || scale === 'percent' || scale === '%');
        var mult = isPct ? 100 : 1000000;
        var rate = totalInspected > 0 ? (totalNG / totalInspected) * mult : 0;
        
        var valEl = document.getElementById('kpiMetricRateVal');
        if (valEl) valEl.textContent = rate.toLocaleString(undefined, {minimumFractionDigits: 1, maximumFractionDigits: isPct ? 2 : 1});

        var scaleUnitEl = document.getElementById('kpiMetricScaleLabel');
        if (scaleUnitEl) scaleUnitEl.textContent = (isPct ? '%' : 'PPM');
    }

    function switchTrendMetricScale(scale) {
        currentMetricScale = scale;
        localStorage.setItem('oqc_trend_metric_scale', scale);
        
        var isPct = (scale === 'pct' || scale === 'percent' || scale === '%');
        var titleUnit = document.getElementById('trendChartUnitText');
        if (titleUnit) titleUnit.textContent = (isPct ? '%' : 'PPM');

        var badgeText = document.getElementById('trendChartBadgeText');
        if (badgeText) badgeText.textContent = (isPct ? 'Persentase (%)' : 'Parts Per Million');

        updateKpiMetricCard(scale);
        initTrendChart(currentChartType, scale);
    }

    function updateChartSwitcherUI(type) {
        var btnLine = document.getElementById('btnChartTypeLine');
        var btnBar  = document.getElementById('btnChartTypeBar');
        if (!btnLine || !btnBar) return;

        if (type === 'line') {
            btnLine.style.background = '#ffffff';
            btnLine.style.color = '#2563eb';
            btnLine.style.boxShadow = '0 1px 2px rgba(0,0,0,0.08)';
            btnBar.style.background = 'transparent';
            btnBar.style.color = '#64748b';
            btnBar.style.boxShadow = 'none';
        } else {
            btnBar.style.background = '#ffffff';
            btnBar.style.color = '#2563eb';
            btnBar.style.boxShadow = '0 1px 2px rgba(0,0,0,0.08)';
            btnLine.style.background = 'transparent';
            btnLine.style.color = '#64748b';
            btnLine.style.boxShadow = 'none';
        }
    }

    function updateMetricSwitcherUI(scale) {
        var btnPPM = document.getElementById('btnMetricPPM');
        var btnPCT = document.getElementById('btnMetricPCT') || document.getElementById('btnMetricPPB');
        if (!btnPPM || !btnPCT) return;

        var isPct = (scale === 'pct' || scale === 'percent' || scale === '%');
        if (isPct) {
            btnPCT.style.background = '#ffffff';
            btnPCT.style.color = '#2563eb';
            btnPCT.style.boxShadow = '0 1px 2px rgba(0,0,0,0.08)';
            btnPPM.style.background = 'transparent';
            btnPPM.style.color = '#64748b';
            btnPPM.style.boxShadow = 'none';
        } else {
            btnPPM.style.background = '#ffffff';
            btnPPM.style.color = '#2563eb';
            btnPPM.style.boxShadow = '0 1px 2px rgba(0,0,0,0.08)';
            btnPCT.style.background = 'transparent';
            btnPCT.style.color = '#64748b';
            btnPCT.style.boxShadow = 'none';
        }
    }

    // Initialize KPI Card and Chart with current metric scale & sub resolution
    updateKpiMetricCard(currentMetricScale);

    var initBadge = document.getElementById('trendChartBadgeText');
    var isPctInit = (currentMetricScale === 'pct' || currentMetricScale === 'percent' || currentMetricScale === '%');
    if (initBadge && isPctInit) {
        initBadge.textContent = 'Persentase (%)';
    }
    var initUnit = document.getElementById('trendChartUnitText');
    if (initUnit && isPctInit) {
        initUnit.textContent = '%';
    }
    initTrendChart(currentChartType, currentMetricScale);

    // ── Defect Timeline Stacked Chart & View/Metric Switcher ─────────────────
    var defectStackedChartInstance = null;
    var currentDefectViewMode      = localStorage.getItem('oqc_defect_view_mode') || 'table';
    var currentDefectMetricUnit    = localStorage.getItem('oqc_defect_metric_unit') || 'pcs';

    // Plugin Custom Canvas: In-Bar Data Label (Nama Defect Ringkas + Qty Langsung di Dalam Batang)
    var inBarDefectLabelPlugin = {
        id: 'inBarDefectLabelPlugin',
        afterDatasetsDraw: function(chart) {
            var ctx = chart.ctx;
            ctx.save();
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';

            chart.data.datasets.forEach(function(dataset, datasetIndex) {
                var meta = chart.getDatasetMeta(datasetIndex);
                if (meta.hidden) return;

                meta.data.forEach(function(element, index) {
                    var rawVal = Number(dataset.data[index] || 0);
                    if (rawVal <= 0) return;

                    var barY = element.y;
                    var barBase = element.base;
                    var barHeight = Math.abs(barBase - barY);
                    var barWidth = element.width;
                    var centerY = (barY + barBase) / 2;
                    var centerX = element.x;

                    // Jika tinggi balok kurang dari 11px, lewati agar tidak overlap
                    if (barHeight < 11) return;

                    var labelName = '';
                    if (dataset.defect_names && dataset.defect_names[index]) {
                        labelName = dataset.defect_names[index];
                    } else if (dataset.label) {
                        labelName = dataset.label;
                    }
                    // Bersihkan teks suffix jika ada
                    labelName = labelName.replace(/\s*\([^)]*\)$/, '').trim();
                    var qtyStr = rawVal.toLocaleString();

                    ctx.shadowColor = 'rgba(0, 0, 0, 0.45)';
                    ctx.shadowBlur = 3;
                    ctx.fillStyle = '#ffffff';

                    if (barHeight >= 22 && barWidth >= 30) {
                        // Cukup tinggi & lebar: tampilkan nama ringkas + qty (2 baris)
                        var maxLen = Math.max(6, Math.floor(barWidth / 5.5));
                        var shortName = labelName.length > maxLen ? (labelName.substring(0, maxLen - 1) + '…') : labelName;

                        ctx.font = '700 8.5px Inter, system-ui, sans-serif';
                        ctx.fillText(shortName, centerX, centerY - 5);
                        ctx.font = '900 9.5px Inter, system-ui, sans-serif';
                        ctx.fillText(qtyStr, centerX, centerY + 6);
                    } else if (barHeight >= 14 && barWidth >= 20) {
                        // Sedang: tampilkan Nama ringkas / Qty saja
                        ctx.font = '900 9px Inter, system-ui, sans-serif';
                        ctx.fillText(qtyStr, centerX, centerY);
                    } else {
                        // Tipis: tampilkan hanya angka kecil
                        ctx.font = '800 8px Inter, system-ui, sans-serif';
                        ctx.fillText(qtyStr, centerX, centerY);
                    }
                });
            });
            ctx.restore();
        }
    };

    function renderDefectStackedLegend(datasets) {
        var container = document.getElementById('defectStackedLegendChips');
        if (!container) return;
        container.innerHTML = '';

        if (!datasets || !datasets.length) return;

        // Kumpulkan jenis defect yang memiliki nilai > 0 di setidaknya satu periode
        var defectTotalMap = {};
        var defectColorMap = {};

        datasets.forEach(function(ds) {
            (ds.data || []).forEach(function(val, idx) {
                var v = Number(val || 0);
                if (v <= 0) return;

                var dName = (ds.defect_names && ds.defect_names[idx]) ? ds.defect_names[idx] : ds.label;
                if (!dName) return;
                var cleanName = dName.replace(/\s*\([^)]*\)$/, '').trim();

                var col = Array.isArray(ds.backgroundColor) ? (ds.backgroundColor[idx] || '#64748b') : (ds.backgroundColor || '#64748b');

                defectTotalMap[cleanName] = (defectTotalMap[cleanName] || 0) + v;
                if (!defectColorMap[cleanName] && col !== 'transparent') {
                    defectColorMap[cleanName] = col;
                }
            });
        });

        var activeItems = [];
        Object.keys(defectTotalMap).forEach(function(dName) {
            activeItems.push({
                name: dName,
                color: defectColorMap[dName] || '#64748b',
                total: defectTotalMap[dName]
            });
        });

        // Urutkan legend dari total terbanyak
        activeItems.sort(function(a, b) { return b.total - a.total; });

        if (activeItems.length === 0) {
            var zeroNotice = document.createElement('div');
            zeroNotice.style.display = 'inline-flex';
            zeroNotice.style.alignItems = 'center';
            zeroNotice.style.gap = '5px';
            zeroNotice.style.padding = '3px 10px';
            zeroNotice.style.background = '#ecfdf5';
            zeroNotice.style.border = '1px solid #a7f3d0';
            zeroNotice.style.borderRadius = '6px';
            zeroNotice.style.fontSize = '10px';
            zeroNotice.style.color = '#047857';
            zeroNotice.style.fontWeight = '700';
            zeroNotice.innerHTML = '<svg width="12" height="12" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg> Zero Defect (0 NG / 100% Passed)';
            container.appendChild(zeroNotice);
            return;
        }

        activeItems.forEach(function(item) {
            var chip = document.createElement('div');
            chip.style.display = 'inline-flex';
            chip.style.alignItems = 'center';
            chip.style.gap = '5px';
            chip.style.padding = '2px 8px';
            chip.style.background = '#f8fafc';
            chip.style.border = '1px solid #e2e8f0';
            chip.style.borderRadius = '6px';
            chip.style.fontSize = '9.5px';
            chip.style.color = '#1e293b';
            chip.style.fontWeight = '700';

            var dot = document.createElement('span');
            dot.style.display = 'inline-block';
            dot.style.width = '8px';
            dot.style.height = '8px';
            dot.style.borderRadius = '2px';
            dot.style.background = item.color;
            dot.style.flexShrink = '0';

            var txt = document.createElement('span');
            txt.textContent = item.name + ' (' + item.total.toLocaleString() + ')';

            chip.appendChild(dot);
            chip.appendChild(txt);
            container.appendChild(chip);
        });
    }

    function initDefectStackedChart() {
        var ctx = document.getElementById('chartDefectStacked');
        if (!ctx) return;

        if (defectStackedChartInstance) {
            defectStackedChartInstance.destroy();
        }

        var stackedDatasets = (currentDefectMetricUnit === 'ppm') ? ((window.OQC_DASH_CONFIG && window.OQC_DASH_CONFIG.stackedDatasetsPpm) || []) : ((window.OQC_DASH_CONFIG && window.OQC_DASH_CONFIG.stackedDatasetsPcs) || []);
        var stackedLabels = (window.OQC_DASH_CONFIG && window.OQC_DASH_CONFIG.stackedLabels) ? window.OQC_DASH_CONFIG.stackedLabels : [];

        defectStackedChartInstance = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: stackedLabels,
                datasets: stackedDatasets
            },
            plugins: [inBarDefectLabelPlugin],
            options: {
                responsive: true,
                maintainAspectRatio: false,
                maxBarThickness: 50,
                categoryPercentage: 0.7,
                barPercentage: 0.65,
                interaction: {
                    mode: 'nearest',
                    axis: 'xy',
                    intersect: true
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        mode: 'nearest',
                        intersect: true,
                        backgroundColor: '#0f172a',
                        titleColor: '#f8fafc',
                        bodyColor: '#e2e8f0',
                        padding: 10,
                        cornerRadius: 8,
                        filter: function(tooltipItem) {
                            return Number(tooltipItem.raw || 0) > 0;
                        },
                        callbacks: {
                            title: function(tooltipItems) {
                                if (!tooltipItems || !tooltipItems.length) return '';
                                var item = tooltipItems[0];
                                var val = Number(item.raw || 0).toLocaleString();
                                var unitStr = (currentDefectMetricUnit === 'ppm') ? 'PPM' : activeUnit.toLowerCase();
                                var dateLabel = item.label || '';
                                var ds = item.dataset;
                                var dName = (ds.defect_names && ds.defect_names[item.dataIndex]) ? ds.defect_names[item.dataIndex] : ds.label;
                                return dateLabel + ' • ' + dName + ' (' + val + ' ' + unitStr + ')';
                            },
                            label: function(context) {
                                var ds = context.dataset;
                                var lines = [];
                                var partMeta = (ds.part_meta && ds.part_meta[context.dataIndex]) ? ds.part_meta[context.dataIndex] : null;
                                if (partMeta && Array.isArray(partMeta) && partMeta.length > 0) {
                                    partMeta.forEach(function(pm) {
                                        var pCode = pm.code || '-';
                                        var pName = pm.name || '-';
                                        var pQty  = Number(pm.qty || 0).toLocaleString();
                                        lines.push('  └ Part: ' + pCode + ' (' + pName + ') • NG: ' + pQty + ' ' + activeUnit.toLowerCase());
                                    });
                                } else {
                                    lines.push('  (Tidak ada rincian spesifik part)');
                                }
                                return lines;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        stacked: true,
                        grid: { display: false },
                        ticks: {
                            font: { size: 9.5, weight: '700' },
                            color: '#475569',
                            maxRotation: 0,
                            autoSkip: false
                        }
                    },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        grid: { color: '#f1f5f9' },
                        ticks: {
                            font: { size: 9 },
                            color: '#64748b',
                            precision: 0,
                            callback: function(val) {
                                return Number(val).toLocaleString() + (currentDefectMetricUnit === 'ppm' ? ' PPM' : '');
                            }
                        }
                    }
                }
            }
        });

        // Render Legend Badge Chips di bawah grafik
        renderDefectStackedLegend(stackedDatasets);
    }

    function switchDefectMetricUnit(unit) {
        currentDefectMetricUnit = unit;
        localStorage.setItem('oqc_defect_metric_unit', unit);

        var btnPcs = document.getElementById('btnDefectUnitPcs');
        var btnPpm = document.getElementById('btnDefectUnitPpm');
        var badge  = document.getElementById('defectMetricScaleBadge');

        if (badge) badge.textContent = (unit === 'ppm' ? 'PPM' : 'PCS');

        if (btnPcs && btnPpm) {
            if (unit === 'ppm') {
                btnPpm.style.background = '#ffffff';
                btnPpm.style.color = '#2563eb';
                btnPpm.style.fontWeight = '800';
                btnPpm.style.boxShadow = '0 1px 2px rgba(0,0,0,0.05)';

                btnPcs.style.background = 'transparent';
                btnPcs.style.color = '#64748b';
                btnPcs.style.fontWeight = '700';
                btnPcs.style.boxShadow = 'none';
            } else {
                btnPcs.style.background = '#ffffff';
                btnPcs.style.color = '#2563eb';
                btnPcs.style.fontWeight = '800';
                btnPcs.style.boxShadow = '0 1px 2px rgba(0,0,0,0.05)';

                btnPpm.style.background = 'transparent';
                btnPpm.style.color = '#64748b';
                btnPpm.style.fontWeight = '700';
                btnPpm.style.boxShadow = 'none';
            }
        }

        // Update Chart
        if (defectStackedChartInstance) {
            var newDatasets = (unit === 'ppm') ? ((window.OQC_DASH_CONFIG && window.OQC_DASH_CONFIG.stackedDatasetsPpm) || []) : ((window.OQC_DASH_CONFIG && window.OQC_DASH_CONFIG.stackedDatasetsPcs) || []);
            defectStackedChartInstance.data.datasets = newDatasets;
            defectStackedChartInstance.update();
            renderDefectStackedLegend(newDatasets);
        }

        // Update Table Cells
        var cells = document.querySelectorAll('.defect-metric-val');
        cells.forEach(function(cell) {
            var pcsVal = Number(cell.getAttribute('data-pcs') || 0);
            var ppmVal = Number(cell.getAttribute('data-ppm') || 0);
            if (unit === 'ppm') {
                cell.textContent = ppmVal > 0 ? (ppmVal.toLocaleString(undefined, {minimumFractionDigits: 1, maximumFractionDigits: 1})) : '-';
            } else {
                cell.textContent = pcsVal > 0 ? pcsVal.toLocaleString() : '-';
            }
        });

        var totCells = document.querySelectorAll('.defect-metric-total-val');
        totCells.forEach(function(cell) {
            var pcsVal = Number(cell.getAttribute('data-pcs') || 0);
            var ppmVal = Number(cell.getAttribute('data-ppm') || 0);
            if (unit === 'ppm') {
                cell.textContent = ppmVal.toLocaleString(undefined, {minimumFractionDigits: 1, maximumFractionDigits: 1});
            } else {
                cell.textContent = pcsVal.toLocaleString();
            }
        });
    }

    function switchDefectTimelineView(mode) {
        currentDefectViewMode = mode;
        localStorage.setItem('oqc_defect_view_mode', mode);

        var chartEl  = document.getElementById('defectTimelineChartView');
        var tableEl  = document.getElementById('defectTimelineTableView');
        var btnChart = document.getElementById('btnDefectViewChart');
        var btnTable = document.getElementById('btnDefectViewTable');

        if (!chartEl || !tableEl || !btnChart || !btnTable) return;

        if (mode === 'table') {
            chartEl.style.display = 'none';
            tableEl.style.display = 'block';

            btnTable.style.background = '#ffffff';
            btnTable.style.color = '#2563eb';
            btnTable.style.fontWeight = '800';
            btnTable.style.boxShadow = '0 1px 2px rgba(0,0,0,0.05)';

            btnChart.style.background = 'transparent';
            btnChart.style.color = '#64748b';
            btnChart.style.fontWeight = '700';
            btnChart.style.boxShadow = 'none';
        } else {
            tableEl.style.display = 'none';
            chartEl.style.display = 'flex';

            btnChart.style.background = '#ffffff';
            btnChart.style.color = '#2563eb';
            btnChart.style.fontWeight = '800';
            btnChart.style.boxShadow = '0 1px 2px rgba(0,0,0,0.05)';

            btnTable.style.background = 'transparent';
            btnTable.style.color = '#64748b';
            btnTable.style.fontWeight = '700';
            btnTable.style.boxShadow = 'none';

            if (defectStackedChartInstance) {
                requestAnimationFrame(function() {
                    defectStackedChartInstance.resize();
                });
            }
        }
    }

    // ── Instant Switch Peringkat Defect (1 vs 3) via AJAX ───────────────────
    function switchDefectRank(rankNum, event) {
        if (event && event.preventDefault) {
            event.preventDefault();
        }
        rankNum = (rankNum === 1) ? 1 : 3;
        filterParams.defect_rank = rankNum;

        // Pastikan preset sinkron dari URL jika ada
        try {
            var currUrl = new URL(window.location.href);
            var urlPreset = currUrl.searchParams.get('preset');
            if (urlPreset) {
                filterParams.preset = urlPreset;
                if (filterParams.filter_type === 'custom') {
                    filterParams.filter_type = '';
                }
            }
        } catch (e) {}

        // Update Pill Visuals
        [1, 3].forEach(function(r) {
            var btn = document.getElementById('btnDefectRank_' + r);
            if (btn) {
                if (r === rankNum) {
                    btn.style.background = '#2563eb';
                    btn.style.color = '#ffffff';
                    btn.style.boxShadow = '0 1px 2px rgba(37,99,235,0.25)';
                } else {
                    btn.style.background = 'transparent';
                    btn.style.color = '#64748b';
                    btn.style.boxShadow = 'none';
                }
            }
        });

        var rankDisplay = document.getElementById('defectRankDisplayText');
        if (rankDisplay) {
            rankDisplay.textContent = rankNum;
        }

        // Sinkronkan URL di Address Bar tanpa reload penuh
        try {
            var url = new URL(window.location.href);
            url.searchParams.set('defect_rank', rankNum);
            if (!url.searchParams.get('preset') && filterParams.preset) {
                url.searchParams.set('preset', filterParams.preset);
            }
            window.history.replaceState({}, '', url.toString());
        } catch (e) {}

        // Bersihkan cache lama untuk rank ini agar langsung ditimpa respon baru
        try {
            var cacheKey = getDashboardCacheKey();
            localStorage.removeItem(cacheKey);
        } catch (e) {}

        // Fetch Data Terbaru via AJAX Live Sync (force refresh)
        if (typeof fetchLatestDashboardData === 'function') {
            fetchLatestDashboardData(true);
        }
    }
    window.switchDefectRank = switchDefectRank;

    if (window.OQC_DASH_CONFIG && window.OQC_DASH_CONFIG.stackedLabels && window.OQC_DASH_CONFIG.stackedLabels.length > 0) {
        initDefectStackedChart();
        switchDefectMetricUnit(currentDefectMetricUnit);
        switchDefectTimelineView(currentDefectViewMode);
    }

    // ── Universal Doughnut Floating Tooltip ───────────────────────────────────
    var windowMouseX = 0;
    var windowMouseY = 0;
    window.addEventListener('mousemove', function(e) {
        windowMouseX = e.clientX;
        windowMouseY = e.clientY;
    }, { passive: true });

    function hideDashDonutTooltip() {
        var tooltipEl = document.getElementById('chartjs-dash-donut-tooltip');
        if (tooltipEl) {
            tooltipEl.style.opacity = '0';
            tooltipEl.style.display = 'none';
        }
    }

    function dashDonutTooltipHandler(context) {
        var tooltipEl = document.getElementById('chartjs-dash-donut-tooltip');

        if (!tooltipEl) {
            tooltipEl = document.createElement('div');
            tooltipEl.id = 'chartjs-dash-donut-tooltip';
            tooltipEl.style.position = 'fixed';
            tooltipEl.style.zIndex = '99999';
            tooltipEl.style.pointerEvents = 'none';
            tooltipEl.style.background = 'rgba(15, 23, 42, 0.94)';
            tooltipEl.style.backdropFilter = 'blur(6px)';
            tooltipEl.style.color = '#ffffff';
            tooltipEl.style.borderRadius = '8px';
            tooltipEl.style.padding = '7px 12px';
            tooltipEl.style.fontSize = '11px';
            tooltipEl.style.boxShadow = '0 10px 25px -3px rgba(0, 0, 0, 0.4), 0 4px 6px -2px rgba(0, 0, 0, 0.2)';
            tooltipEl.style.border = '1px solid rgba(255, 255, 255, 0.16)';
            tooltipEl.style.transition = 'opacity 0.08s ease-out';
            tooltipEl.style.whiteSpace = 'nowrap';
            document.body.appendChild(tooltipEl);
        }

        var tooltipModel = context.tooltip;
        if (!tooltipModel || tooltipModel.opacity === 0) {
            tooltipEl.style.opacity = '0';
            tooltipEl.style.display = 'none';
            return;
        }

        if (tooltipModel.body) {
            var bodyLines = tooltipModel.body.map(function(b) { return b.lines; });
            var colors = (tooltipModel.labelColors && tooltipModel.labelColors[0]) ? tooltipModel.labelColors[0] : null;
            var bgColor = colors ? (colors.backgroundColor || '#2563eb') : '#2563eb';

            var html = '';
            bodyLines.forEach(function(body) {
                var text = Array.isArray(body) ? body.join(' ') : String(body);
                html += '<div style="display:flex;align-items:center;gap:7px;">' +
                        '<span style="display:inline-block;width:9px;height:9px;border-radius:2px;background:' + bgColor + ';box-shadow:0 0 0 1.5px rgba(255,255,255,0.4);flex-shrink:0;"></span>' +
                        '<span style="font-weight:800;color:#f8fafc;letter-spacing:0.2px;">' + (typeof escapeHtml === 'function' ? escapeHtml(text.trim()) : text.trim()) + '</span>' +
                        '</div>';
            });
            tooltipEl.innerHTML = html;
        }

        var canvas = context.chart.canvas;
        var rect = canvas.getBoundingClientRect();

        tooltipEl.style.display = 'block';
        tooltipEl.style.opacity = '1';

        var centerX = rect.left + rect.width / 2;
        var centerY = rect.top + rect.height / 2;

        if (windowMouseX > 0 && windowMouseX >= rect.left && windowMouseX <= rect.right && windowMouseY >= rect.top && windowMouseY <= rect.bottom) {
            var dy = windowMouseY - centerY;
            if (dy < 0) {
                tooltipEl.style.transform = 'translate(-50%, -115%)';
                tooltipEl.style.top = (windowMouseY - 8) + 'px';
            } else {
                tooltipEl.style.transform = 'translate(-50%, 18%)';
                tooltipEl.style.top = (windowMouseY + 8) + 'px';
            }
            tooltipEl.style.left = windowMouseX + 'px';
        } else {
            var posX = rect.left + tooltipModel.caretX;
            var posY = rect.top + tooltipModel.caretY;
            var dx = posX - centerX;
            var dy = posY - centerY;
            var dist = Math.sqrt(dx * dx + dy * dy);
            if (dist < 70) {
                var angle = Math.atan2(dy, dx);
                if (dist === 0) angle = -Math.PI / 2;
                posX = centerX + Math.cos(angle) * 75;
                posY = centerY + Math.sin(angle) * 75;
            }
            if (dy < 0) {
                tooltipEl.style.transform = 'translate(-50%, -115%)';
            } else {
                tooltipEl.style.transform = 'translate(-50%, 18%)';
            }
            tooltipEl.style.left = posX + 'px';
            tooltipEl.style.top = posY + 'px';
        }
    }

    if (window.OQC_DASH_CONFIG && window.OQC_DASH_CONFIG.defectCounts && window.OQC_DASH_CONFIG.defectCounts.length > 0) {
        var ctxDef = document.getElementById('chartDefect');
        if (ctxDef) {
            defectDoughnutChartInstance = new Chart(ctxDef, {
                type:'doughnut',
                data:{ 
                    labels: window.OQC_DASH_CONFIG.defectLabels || [], 
                    datasets:[{ 
                        data: window.OQC_DASH_CONFIG.defectCounts || [], 
                        backgroundColor: window.OQC_DASH_CONFIG.defectColors || [], 
                        borderWidth:2.5, 
                        borderColor:'#ffffff', 
                        hoverOffset:5,
                        borderRadius:4
                    }] 
                },
                plugins: [inDoughnutArcPercentPlugin],
                options:{ 
                    responsive:true, 
                    maintainAspectRatio:false, 
                    cutout:'52%', 
                    plugins:{ 
                        legend:{display:false}, 
                        tooltip:{ 
                            enabled:false,
                            external:dashDonutTooltipHandler,
                            callbacks:{ 
                                label:function(c){ 
                                    var denom = (typeof totalInspectedKpi !== 'undefined' && totalInspectedKpi > 0) ? totalInspectedKpi : (c.dataset.data.reduce(function(a,b){return a+b;},0) || 1); 
                                    var pct = ((Number(c.raw) / denom) * 100).toFixed(2);
                                    return c.label+': '+Number(c.raw).toLocaleString()+' '+activeUnit.toLowerCase()+' ('+pct+'%)'; 
                                } 
                            } 
                        } 
                    } 
                }
            });
            ctxDef.onmouseleave = hideDashDonutTooltip;
        }
    }

    if (window.OQC_DASH_CONFIG && window.OQC_DASH_CONFIG.modelCounts && window.OQC_DASH_CONFIG.modelCounts.length > 0) {
        var ctxMod = document.getElementById('chartModelPie');
        var rawModelCountsPcs = window.OQC_DASH_CONFIG.modelCounts || [];
        var rawModelCasesFreq = window.OQC_DASH_CONFIG.modelCasesData || [];
        var rawModelLabels    = window.OQC_DASH_CONFIG.modelLabels || [];
        var rawModelColors    = window.OQC_DASH_CONFIG.modelColors || [];

    if (ctxMod) {
        var isModelFreqMode = (currentModelMetricMode === 'freq');
        var initialModelData = isModelFreqMode ? rawModelCasesFreq : rawModelCountsPcs;

        modelPieChartInstance = new Chart(ctxMod, {
            type:'doughnut',
            data:{ 
                labels:rawModelLabels, 
                datasets:[{ 
                    data:initialModelData, 
                    raw_pcs:rawModelCountsPcs,
                    cases_freq:rawModelCasesFreq,
                    is_freq:isModelFreqMode,
                    backgroundColor:rawModelColors, 
                    borderWidth:2.5, 
                    borderColor:'#ffffff', 
                    hoverOffset:5,
                    borderRadius:3
                }] 
            },
            plugins: [inDoughnutArcPercentPlugin],
            options:{ 
                responsive:true, 
                maintainAspectRatio:false, 
                cutout:'52%', 
                plugins:{ 
                    legend:{display:false}, 
                    tooltip:{ 
                        enabled:false,
                        external:dashDonutTooltipHandler,
                        callbacks:{ 
                            label:function(c){ 
                                var ds = c.dataset;
                                if (ds.is_freq) {
                                    return 'Model ' + c.label + ': ' + Number(c.raw).toLocaleString() + ' kali temuan';
                                } else {
                                    var denom = (typeof totalInspectedKpi !== 'undefined' && totalInspectedKpi > 0) ? totalInspectedKpi : (ds.data.reduce(function(a,b){return a+b;},0) || 1); 
                                    var pct = ((Number(c.raw) / denom) * 100).toFixed(2);
                                    return 'Model ' + c.label + ': ' + Number(c.raw).toLocaleString() + ' ' + activeUnit.toLowerCase() + ' (' + pct + '%)'; 
                                }
                            } 
                        } 
                    } 
                } 
            }
        });
        ctxMod.onmouseleave = hideDashDonutTooltip;
        updateModelModeSwitcherUI(currentModelMetricMode);
    }

    function switchModelMetricMode(mode) {
        currentModelMetricMode = (mode === 'freq') ? 'freq' : 'pcs';
        localStorage.setItem('oqc_model_metric_mode', currentModelMetricMode);
        updateModelModeSwitcherUI(currentModelMetricMode);

        var isFreq = (currentModelMetricMode === 'freq');

        if (modelPieChartInstance && modelPieChartInstance.data.datasets[0]) {
            var ds = modelPieChartInstance.data.datasets[0];
            ds.is_freq = isFreq;
            var pcsData  = ds.raw_pcs || rawModelCountsPcs || [];
            var freqData = ds.cases_freq || rawModelCasesFreq || [];

            ds.data = isFreq ? freqData : pcsData;
            modelPieChartInstance.update();

            var modQtyEl = document.getElementById('donutModelQtyVal');
            if (modQtyEl) {
                var totVal = ds.data.reduce(function(a, b) { return a + Number(b || 0); }, 0);
                modQtyEl.textContent = '(' + totVal.toLocaleString() + ' ' + (isFreq ? 'kali' : activeUnit.toLowerCase()) + ')';
            }

            renderDonutLegendHTML('model', modelPieChartInstance.data.labels, ds.data, ds.backgroundColor, isFreq ? 'kali' : 'tipe');
        } else {
            // Update legend DOM secara statis jika chart instance belum siap
            var items = document.querySelectorAll('#modelLegendContainer [data-legend-type="model"]');
            var totPcsStatic = 0;
            var totCasesStatic = 0;
            items.forEach(function(el) {
                var valEl = el.querySelector('.model-legend-val');
                var pcs = parseInt(el.getAttribute('data-pcs') || '0', 10);
                var cases = parseInt(el.getAttribute('data-cases') || '0', 10);
                var pct = el.getAttribute('data-pct') || '0';
                totPcsStatic += pcs;
                totCasesStatic += cases;
                if (!valEl) return;
                if (isFreq) {
                    valEl.innerHTML = cases.toLocaleString() + ' <span style="font-weight:500;color:#64748b;">kali</span>';
                } else {
                    valEl.innerHTML = pcs.toLocaleString() + ' <span style="font-weight:500;color:#64748b;">' + activeUnit.toLowerCase() + ' (' + pct + '%)</span>';
                }
            });
            var modQtyEl = document.getElementById('donutModelQtyVal');
            if (modQtyEl) {
                modQtyEl.textContent = '(' + (isFreq ? totCasesStatic : totPcsStatic).toLocaleString() + ' ' + (isFreq ? 'kali' : activeUnit.toLowerCase()) + ')';
            }
        }
    }

    function updateModelModeSwitcherUI(mode) {
        var btnPcs = document.getElementById('btnModelModePcs');
        var btnFreq = document.getElementById('btnModelModeFreq');
        var subTitle = document.getElementById('subTitleDonutModel');
        if (!btnPcs || !btnFreq) return;

        if (mode === 'freq') {
            btnFreq.style.background = '#6366f1';
            btnFreq.style.color      = '#ffffff';
            btnFreq.style.fontWeight = '800';
            btnFreq.style.boxShadow  = '0 1px 2px rgba(99,102,241,0.25)';

            btnPcs.style.background  = 'transparent';
            btnPcs.style.color       = '#64748b';
            btnPcs.style.fontWeight  = '700';
            btnPcs.style.boxShadow   = 'none';

            if (subTitle) subTitle.textContent = 'Satuan: kali temuan';
        } else {
            btnPcs.style.background  = '#6366f1';
            btnPcs.style.color       = '#ffffff';
            btnPcs.style.fontWeight  = '800';
            btnPcs.style.boxShadow   = '0 1px 2px rgba(99,102,241,0.25)';

            btnFreq.style.background = 'transparent';
            btnFreq.style.color      = '#64748b';
            btnFreq.style.fontWeight = '700';
            btnFreq.style.boxShadow  = 'none';

            if (subTitle) subTitle.textContent = 'Satuan: ' + activeUnit.toLowerCase();
        }
    }

    // Sync initial model mode switch jika pengguna memilih frekuensi sebelumnya
    if (currentModelMetricMode === 'freq') {
            switchModelMetricMode('freq');
        }
    }

    if (window.OQC_DASH_CONFIG && window.OQC_DASH_CONFIG.pieCounts && window.OQC_DASH_CONFIG.pieCounts.length > 0) {
        var ctxPart = document.getElementById('chartPartPie');
        if (ctxPart) {
            partPieChartInstance = new Chart(ctxPart, {
                type:'doughnut',
                data:{ 
                    labels: window.OQC_DASH_CONFIG.pieLabels || [], 
                    datasets:[{ 
                        data: window.OQC_DASH_CONFIG.pieCounts || [], 
                        backgroundColor: window.OQC_DASH_CONFIG.partColors || [], 
                        borderWidth:2, 
                        borderColor:'#ffffff', 
                        hoverOffset:5,
                        borderRadius:4
                    }] 
                },
                plugins: [inDoughnutArcPercentPlugin],
                options:{ 
                    responsive:true, 
                    maintainAspectRatio:false, 
                    cutout:'52%', 
                    plugins:{ 
                        legend:{display:false}, 
                        tooltip:{ 
                            enabled:false,
                            external:dashDonutTooltipHandler,
                            callbacks:{ 
                                label:function(c){ 
                                    var denom = (typeof totalInspectedKpi !== 'undefined' && totalInspectedKpi > 0) ? totalInspectedKpi : (c.dataset.data.reduce(function(a,b){return a+b;},0) || 1); 
                                    var pct = ((Number(c.raw) / denom) * 100).toFixed(2);
                                    return 'Part '+c.label+': '+Number(c.raw).toLocaleString()+' '+activeUnit.toLowerCase()+' ('+pct+'%)'; 
                                } 
                            } 
                        } 
                    } 
                } 
            });
            ctxPart.onmouseleave = hideDashDonutTooltip;
        }
    }

    var donutRankSettings = {
        defect: parseInt(localStorage.getItem('dash_donut_rank_defect') || '5'),
        model:  parseInt(localStorage.getItem('dash_donut_rank_model')  || '5'),
        part:   parseInt(localStorage.getItem('dash_donut_rank_part')   || '5')
    };

    // Inisialisasi UI tombol pill rank
    ['defect', 'model', 'part'].forEach(function(cardKey) {
        updateDonutRankPillUI(cardKey, donutRankSettings[cardKey]);
    });

    // Jika user menyimpan setting rank yang bukan 5 di localStorage, sinkronkan data saat init
    if (donutRankSettings.defect !== 5 || donutRankSettings.model !== 5 || donutRankSettings.part !== 5) {
        setTimeout(function() {
            triggerCascadeRefresh();
        }, 100);
    }

    function updateDonutRankPillUI(cardKey, rank) {
        var activeColors = { defect: '#ef4444', model: '#6366f1', part: '#0d9488' };
        var actCol = activeColors[cardKey] || '#2563eb';
        [3, 5, 10].forEach(function(rk) {
            var btn = document.getElementById('btnRank' + cardKey.charAt(0).toUpperCase() + cardKey.slice(1) + '_' + rk);
            if (btn) {
                if (rk === rank) {
                    btn.style.background = actCol;
                    btn.style.color      = '#ffffff';
                } else {
                    btn.style.background = 'transparent';
                    btn.style.color      = '#64748b';
                }
            }
        });
        var lbl = document.getElementById('lblRank' + cardKey.charAt(0).toUpperCase() + cardKey.slice(1));
        if (lbl) lbl.textContent = rank;
    }

    function changeDonutRank(cardKey, rank) {
        donutRankSettings[cardKey] = rank;
        localStorage.setItem('dash_donut_rank_' + cardKey, rank);
        updateDonutRankPillUI(cardKey, rank);
        triggerCascadeRefresh();
    }

    function handleDonutLegendClick(type, name) {
        if (!name) return;
        if (typeof cascadeActiveFilters === 'undefined' || !cascadeActiveFilters) return;
        if (cascadeActiveFilters[type] === name) {
            clearCascadeFilter(type);
        } else {
            setCascadeFilter(type, name);
        }
    }

    // Attach click listeners ke canvas chart donut
    if (ctxDef) {
        ctxDef.onclick = function(evt) {
            if (!defectDoughnutChartInstance) return;
            var points = defectDoughnutChartInstance.getElementsAtEventForMode(evt, 'nearest', { intersect: true }, true);
            if (points.length) {
                var firstPoint = points[0];
                var label = defectDoughnutChartInstance.data.labels[firstPoint.index];
                handleDonutLegendClick('defect', label);
            }
        };
    }
    if (ctxMod) {
        ctxMod.onclick = function(evt) {
            if (!modelPieChartInstance) return;
            var points = modelPieChartInstance.getElementsAtEventForMode(evt, 'nearest', { intersect: true }, true);
            if (points.length) {
                var firstPoint = points[0];
                var label = modelPieChartInstance.data.labels[firstPoint.index];
                handleDonutLegendClick('model', label);
            }
        };
    }
    if (ctxPart) {
        ctxPart.onclick = function(evt) {
            if (!partPieChartInstance) return;
            var points = partPieChartInstance.getElementsAtEventForMode(evt, 'nearest', { intersect: true }, true);
            if (points.length) {
                var firstPoint = points[0];
                var label = partPieChartInstance.data.labels[firstPoint.index];
                handleDonutLegendClick('part', label);
            }
        };
    }

    function setCascadeFilter(type, val) {
        cascadeActiveFilters[type] = val;
        if (type === 'defect') {
            cascadeActiveFilters.model = null;
            cascadeActiveFilters.part  = null;
        } else if (type === 'model') {
            cascadeActiveFilters.part  = null;
        }
        updateCascadeBadgesUI();
        triggerCascadeRefresh();
    }

    function clearCascadeFilter(type) {
        cascadeActiveFilters[type] = null;
        if (type === 'defect') {
            cascadeActiveFilters.model = null;
            cascadeActiveFilters.part  = null;
        } else if (type === 'model') {
            cascadeActiveFilters.part  = null;
        }
        updateCascadeBadgesUI();
        triggerCascadeRefresh();
    }

    function resetAllCascadeFilters() {
        cascadeActiveFilters.defect = null;
        cascadeActiveFilters.model  = null;
        cascadeActiveFilters.part   = null;
        updateCascadeBadgesUI();
        triggerCascadeRefresh();
        closeCascadeFindingsSection();
    }

    function updateCascadeBadgesUI() {
        if (typeof cascadeActiveFilters === 'undefined' || !cascadeActiveFilters) return;

        // 1. In-Header Filter Badge untuk Card Model
        var bModFilter = document.getElementById('badgeHeaderModelFilter');
        var tModFilter = document.getElementById('textHeaderModelFilter');
        var lModDef    = document.getElementById('lblModelDefaultMode');
        if (bModFilter && lModDef) {
            if (cascadeActiveFilters.defect) {
                bModFilter.style.display = 'inline-flex';
                if (tModFilter) tModFilter.textContent = cascadeActiveFilters.defect;
                lModDef.style.display = 'none';
            } else {
                bModFilter.style.display = 'none';
                lModDef.style.display = 'inline';
            }
        }

        // 2. In-Header Filter Badge untuk Card Part
        var bPrtFilter = document.getElementById('badgeHeaderPartFilter');
        var tPrtFilter = document.getElementById('textHeaderPartFilter');
        var lPrtDef    = document.getElementById('lblPartDefaultMode');
        if (bPrtFilter && lPrtDef) {
            var activeFilterTexts = [];
            if (cascadeActiveFilters.defect) activeFilterTexts.push(cascadeActiveFilters.defect);
            if (cascadeActiveFilters.model) activeFilterTexts.push(cascadeActiveFilters.model);

            if (activeFilterTexts.length > 0) {
                bPrtFilter.style.display = 'inline-flex';
                if (tPrtFilter) tPrtFilter.textContent = activeFilterTexts.join(' · ');
                lPrtDef.style.display = 'none';
            } else {
                bPrtFilter.style.display = 'none';
                lPrtDef.style.display = 'inline';
            }
        }

        // Highlight aktif pada legend dan baris tabel
        document.querySelectorAll('[data-legend-type]').forEach(function(el) {
            var lType = el.getAttribute('data-legend-type');
            var lName = el.getAttribute('data-legend-name');
            var isActive = (cascadeActiveFilters[lType] && cascadeActiveFilters[lType] === lName);
            if (isActive) {
                el.style.backgroundColor = (lType === 'defect' ? '#fef2f2' : (lType === 'model' ? '#e0e7ff' : '#ccfbf1'));
                el.style.borderColor = (lType === 'defect' ? '#f87171' : (lType === 'model' ? '#818cf8' : '#2dd4bf'));
                el.style.transform = 'translateX(2px)';
                el.style.boxShadow = '0 1px 3px rgba(0,0,0,0.08)';
            } else {
                el.style.backgroundColor = '#f8fafc';
                el.style.borderColor = 'transparent';
                el.style.transform = 'none';
                el.style.boxShadow = 'none';
            }
        });
    }

    var cascadeRawFindingsData = [];
    var cascadeChartAbortController = null;
    var cascadeTableAbortController = null;

    function triggerCascadeRefresh() {
        if (cascadeChartAbortController) {
            cascadeChartAbortController.abort();
        }
        cascadeChartAbortController = (typeof AbortController !== 'undefined') ? new AbortController() : null;

        var qs = new URLSearchParams();
        qs.set('action', 'cascade');
        qs.set('start_date', (filterParams && filterParams.start_date) ? filterParams.start_date : '');
        qs.set('end_date', (filterParams && filterParams.end_date) ? filterParams.end_date : '');
        var custName = (filterParams && filterParams.customer) ? filterParams.customer : '';
        if (custName) qs.set('customer', custName);

        if (cascadeActiveFilters.defect) qs.set('defect_name', cascadeActiveFilters.defect);
        if (cascadeActiveFilters.model)  qs.set('model_name', cascadeActiveFilters.model);
        qs.set('rank_defect', donutRankSettings.defect);
        qs.set('rank_model',  donutRankSettings.model);
        qs.set('rank_part',   donutRankSettings.part);
        qs.set('unit',        activeUnit.toLowerCase());

        fetch('ajax_drilldown_cascade.php?' + qs.toString(), {
            signal: cascadeChartAbortController ? cascadeChartAbortController.signal : undefined
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (!data || !data.success) return;

            // 0. Update Defect Chart & Legends (hanya jika user tidak sedang memilih/mengunci defect tertentu)
            if (!cascadeActiveFilters.defect && defectDoughnutChartInstance && data.defects) {
                defectDoughnutChartInstance.data.labels = data.defects.labels;
                defectDoughnutChartInstance.data.datasets[0].data = data.defects.counts;
                defectDoughnutChartInstance.data.datasets[0].backgroundColor = data.defects.colors;
                defectDoughnutChartInstance.update();

                var defTotEl = document.getElementById('donutDefectTotalVal');
                if (defTotEl) {
                    var totVal = (typeof totalNgKpi !== 'undefined' && totalNgKpi > 0) ? totalNgKpi : data.defects.counts.reduce(function(a, b) { return a + b; }, 0);
                    defTotEl.textContent = totVal.toLocaleString();
                }
                renderDonutLegendHTML('defect', data.defects.labels, data.defects.counts, data.defects.colors, activeUnit.toLowerCase());
            }

            // 1. Update Model Chart & Legends
            if (modelPieChartInstance && data.models) {
                var isFreq = (currentModelMetricMode === 'freq');
                var pcsCounts = data.models.counts || [];
                var casesCounts = data.models.cases || [];

                modelPieChartInstance.data.labels = data.models.labels;
                modelPieChartInstance.data.datasets[0].raw_pcs = pcsCounts;
                modelPieChartInstance.data.datasets[0].cases_freq = casesCounts;
                modelPieChartInstance.data.datasets[0].is_freq = isFreq;
                modelPieChartInstance.data.datasets[0].data = isFreq ? casesCounts : pcsCounts;
                modelPieChartInstance.data.datasets[0].backgroundColor = data.models.colors;
                modelPieChartInstance.update();

                var modTotEl = document.getElementById('donutModelTotalVal');
                if (modTotEl) {
                    var totModels = (typeof data.total_models_count !== 'undefined') ? data.total_models_count : (cascadeActiveFilters.defect ? data.models.labels.length : initialTotalModelsNg);
                    modTotEl.textContent = totModels;
                }
                var modQtyEl = document.getElementById('donutModelQtyVal');
                if (modQtyEl) {
                    var totMVal = (isFreq ? casesCounts : pcsCounts).reduce(function(a, b) { return a + Number(b || 0); }, 0);
                    modQtyEl.textContent = '(' + totMVal.toLocaleString() + ' ' + (isFreq ? 'kali' : activeUnit.toLowerCase()) + ')';
                }
                renderDonutLegendHTML('model', data.models.labels, isFreq ? casesCounts : pcsCounts, data.models.colors, isFreq ? 'kali' : 'tipe');
            }

            // 2. Update Part Chart & Legends
            if (partPieChartInstance && data.parts) {
                partPieChartInstance.data.labels = data.parts.labels;
                partPieChartInstance.data.datasets[0].data = data.parts.counts;
                partPieChartInstance.data.datasets[0].backgroundColor = data.parts.colors;
                partPieChartInstance.update();

                var prtTotEl = document.getElementById('donutPartTotalVal');
                if (prtTotEl) {
                    var totParts = (typeof data.total_parts_count !== 'undefined') ? data.total_parts_count : ((cascadeActiveFilters.defect || cascadeActiveFilters.model) ? data.parts.labels.length : initialTotalPartsNg);
                    prtTotEl.textContent = totParts;
                }
                var prtQtyEl = document.getElementById('donutPartQtyVal');
                if (prtQtyEl) {
                    var totPVal = data.parts.counts.reduce(function(a, b) { return a + Number(b || 0); }, 0);
                    prtQtyEl.textContent = '(' + totPVal.toLocaleString() + ' ' + activeUnit.toLowerCase() + ')';
                }
                renderDonutLegendHTML('part', data.parts.labels, data.parts.counts, data.parts.colors, 'item');
            }

            // 3. Jika Defect, Model, atau Part dipilih, langsung tampilkan & perbarui tabel temuan terperinci
            var hasAnyCascadeFilter = (cascadeActiveFilters.defect || cascadeActiveFilters.model || cascadeActiveFilters.part);
            if (hasAnyCascadeFilter) {
                loadCascadeFindingsTable();
            } else {
                closeCascadeFindingsSection();
            }
            updateCascadeBadgesUI();
        })
        .catch(function(err) {
            if (err.name === 'AbortError') return; // Request lama dibatalkan secara normal
            console.error('Error in cascade drilldown:', err);
        });
    }

    function renderDonutLegendHTML(type, labels, counts, colors, unitName) {
        var containerId = (type === 'defect') ? 'defectTypeLegendContainer' : ((type === 'model') ? 'modelLegendContainer' : 'partLegendContainer');
        var el = document.getElementById(containerId);
        if (!el) return;

        if (!labels || labels.length === 0) {
            el.innerHTML = '<div style="padding:20px;text-align:center;color:#94a3b8;font-size:10.5px;">Tidak ada data pada kombinasi ini</div>';
            return;
        }

        var denom = (typeof totalInspectedKpi !== 'undefined' && totalInspectedKpi > 0) ? totalInspectedKpi : (counts.reduce(function(a, b) { return a + b; }, 0) || 1);
        var hoverClass = (type === 'defect') ? 'hover:bg-red-50' : ((type === 'model') ? 'hover:bg-indigo-50' : 'hover:bg-teal-50');
        var isModelFreq = (type === 'model' && currentModelMetricMode === 'freq');
        var html = '';

        labels.forEach(function(lbl, idx) {
            var val  = counts[idx] || 0;
            var pct  = ((val / denom) * 100).toFixed(2);
            var col  = colors[idx] || '#64748b';
            var safeLbl = (lbl || '').replace(/'/g, "\\'");
            var isActive = (typeof cascadeActiveFilters !== 'undefined' && cascadeActiveFilters && cascadeActiveFilters[type] && cascadeActiveFilters[type] === lbl);
            var activeBg = isActive ? (type === 'defect' ? '#fef2f2' : (type === 'model' ? '#e0e7ff' : '#ccfbf1')) : '#f8fafc';
            var activeBorder = isActive ? (type === 'defect' ? '#f87171' : (type === 'model' ? '#818cf8' : '#2dd4bf')) : 'transparent';

            html += '<div onclick="handleDonutLegendClick(\'' + type + '\', \'' + safeLbl + '\')" data-legend-type="' + type + '" data-legend-name="' + safeLbl + '" style="display:flex;align-items:center;justify-content:space-between;font-size:10.5px;gap:6px;padding:4px 8px;border-radius:6px;background:' + activeBg + ';border-left:3.5px solid ' + col + ';border-top:1px solid ' + activeBorder + ';border-right:1px solid ' + activeBorder + ';border-bottom:1px solid ' + activeBorder + ';cursor:pointer;transition:all 0.15s ease-in-out;" class="' + hoverClass + ' hover:shadow-xs group">';
            html += '  <span style="display:flex;align-items:center;gap:5px;color:#334155;flex:1;min-width:0;">';
            html += '    <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:700;">' + lbl + '</span>';
            html += '  </span>';
            if (isModelFreq) {
                html += '  <span class="model-legend-val" style="font-weight:800;color:#0f172a;flex-shrink:0;">' + val.toLocaleString() + ' <span style="font-weight:500;color:#64748b;">kali</span></span>';
            } else {
                html += '  <span class="model-legend-val" style="font-weight:800;color:#0f172a;flex-shrink:0;">' + val.toLocaleString() + ' <span style="font-weight:500;color:#64748b;">' + activeUnit.toLowerCase() + ' (' + pct + '%)</span></span>';
            }
            html += '</div>';
        });

        el.innerHTML = html;
    }

    function loadCascadeFindingsTable() {
        if (cascadeTableAbortController) {
            cascadeTableAbortController.abort();
        }
        cascadeTableAbortController = (typeof AbortController !== 'undefined') ? new AbortController() : null;

        var sec = document.getElementById('cascadeFindingsSection');
        if (sec) sec.style.display = 'block';

        var tbody = document.getElementById('tbodyCascadeFindings');
        if (tbody) tbody.innerHTML = '<tr><td colspan="9" style="padding:25px;text-align:center;color:#64748b;font-weight:700;">Memuat riwayat temuan...</td></tr>';

        var qs = new URLSearchParams();
        qs.set('action', 'get_findings');
        qs.set('start_date', (filterParams && filterParams.start_date) ? filterParams.start_date : '');
        qs.set('end_date', (filterParams && filterParams.end_date) ? filterParams.end_date : '');
        var custName = (filterParams && filterParams.customer) ? filterParams.customer : '';
        if (custName) qs.set('customer', custName);

        if (cascadeActiveFilters.defect) qs.set('defect_name', cascadeActiveFilters.defect);
        if (cascadeActiveFilters.model)  qs.set('model_name', cascadeActiveFilters.model);
        if (cascadeActiveFilters.part)   qs.set('part_name', cascadeActiveFilters.part);

        fetch('ajax_drilldown_cascade.php?' + qs.toString(), {
            signal: cascadeTableAbortController ? cascadeTableAbortController.signal : undefined
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (!data || !data.success) return;
            cascadeRawFindingsData = data.findings || [];
            
            var cntEl = document.getElementById('badgeFindingsTotalCount');
            if (cntEl) cntEl.textContent = cascadeRawFindingsData.length + ' Temuan';

            var subEl = document.getElementById('descFindingsSubtitle');
            if (subEl) {
                var badgesHtml = '';
                if (data.defect_name) {
                    badgesHtml += '<span style="display:inline-flex;align-items:center;background:#fee2e2;color:#dc2626;border:1px solid #fecaca;padding:2px 8px;border-radius:5px;font-size:11px;font-weight:800;gap:4px;">' +
                                  '  <span style="font-weight:600;color:#991b1b;font-size:10px;">DEFECT:</span> ' + escapeHtml(data.defect_name) +
                                  '</span>';
                }
                if (data.model_name) {
                    badgesHtml += '<span style="display:inline-flex;align-items:center;background:#e0e7ff;color:#4338ca;border:1px solid #c7d2fe;padding:2px 8px;border-radius:5px;font-size:11px;font-weight:800;gap:4px;">' +
                                  '  <span style="font-weight:600;color:#3730a3;font-size:10px;">MODEL:</span> ' + escapeHtml(data.model_name) +
                                  '</span>';
                }
                if (data.part_name) {
                    badgesHtml += '<span style="display:inline-flex;align-items:center;background:#ccfbf1;color:#0f766e;border:1px solid #99f6e4;padding:2px 8px;border-radius:5px;font-size:11px;font-weight:800;gap:4px;">' +
                                  '  <span style="font-weight:600;color:#115e59;font-size:10px;">PART:</span> ' + escapeHtml(data.part_name) +
                                  '</span>';
                }
                if (!badgesHtml) {
                    badgesHtml = '<span style="font-size:11px;color:#64748b;font-weight:600;">Semua Temuan Cacat Aktif</span>';
                }
                subEl.innerHTML = badgesHtml;
            }

            renderFindingsTableRows(cascadeRawFindingsData);

            // Smooth scroll ke section temuan agar user langsung melihat hasilnya
            if (sec) sec.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        })
        .catch(function(err) {
            if (err.name === 'AbortError') return; // Request lama dibatalkan secara normal
            console.error('Error fetching findings:', err);
            if (tbody) tbody.innerHTML = '<tr><td colspan="9" style="padding:20px;text-align:center;color:#dc2626;">Gagal memuat riwayat temuan.</td></tr>';
        });
    }

    function renderFindingsTableRows(rows) {
        var tbody = document.getElementById('tbodyCascadeFindings');
        if (!tbody) return;

        if (!rows || rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="9" style="padding:30px;text-align:center;color:#94a3b8;">Tidak ada data temuan cacat untuk kombinasi ini.</td></tr>';
            return;
        }

        var html = '';
        rows.forEach(function(r, idx) {
            var dateStr = r.started_at ? r.started_at.substring(0, 16) : '-';
            var inspType = r.inspection_type || 'NORMAL';
            var badgeCol = (inspType === 'SAFETY_STOCK' || inspType === 'SAFETY STOCK') ? '#7c3aed' : '#2563eb';
            var badgeBg  = (inspType === 'SAFETY_STOCK' || inspType === 'SAFETY STOCK') ? '#f5f3ff' : '#eff6ff';

            html += '<tr style="border-bottom:1px solid #f1f5f9;background:#ffffff;" class="hover:bg-slate-50">';
            html += '  <td style="padding:8px 10px;font-weight:700;color:#94a3b8;">' + (idx + 1) + '</td>';
            html += '  <td style="padding:8px 10px;white-space:nowrap;font-weight:700;color:#1e293b;">' + dateStr + '</td>';
            html += '  <td style="padding:8px 10px;"><div style="font-weight:800;color:#0f172a;">' + (r.part_code || '-') + '</div><div style="font-size:10px;color:#64748b;">' + (r.part_name || '-') + '</div></td>';
            html += '  <td style="padding:8px 10px;"><span style="background:#f1f5f9;color:#334155;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:700;">' + (r.model_name || '-') + '</span></td>';
            html += '  <td style="padding:8px 10px;"><span style="background:' + badgeBg + ';color:' + badgeCol + ';padding:2px 6px;border-radius:4px;font-size:9.5px;font-weight:800;">' + inspType + '</span></td>';
            html += '  <td style="padding:8px 10px;"><div style="font-weight:700;color:#1e293b;">' + (r.kanban_no || '-') + '</div><div style="font-size:10px;color:#64748b;">' + (r.customer_name || 'INTERNAL') + '</div></td>';
            html += '  <td style="padding:8px 10px;font-family:monospace;font-weight:700;color:#475569;">' + (r.lot_number || '-') + '</td>';
            html += '  <td style="padding:8px 10px;font-weight:700;color:#334155;">' + (r.inspector_name || '-') + '</td>';
            html += '  <td style="padding:8px 10px;text-align:right;font-family:monospace;font-weight:900;color:#dc2626;">' + Number(r.qty_ng || 1).toLocaleString() + ' ' + activeUnit.toLowerCase() + '</td>';
            html += '</tr>';
        });

        tbody.innerHTML = html;
    }

    function filterCascadeFindingsTable() {
        var q = (document.getElementById('inputSearchFindings').value || '').toLowerCase().trim();
        if (!q) {
            renderFindingsTableRows(cascadeRawFindingsData);
            return;
        }
        var filtered = cascadeRawFindingsData.filter(function(r) {
            var hay = ((r.part_code || '') + ' ' + (r.part_name || '') + ' ' + (r.model_name || '') + ' ' + (r.customer_name || '') + ' ' + (r.kanban_no || '') + ' ' + (r.lot_number || '') + ' ' + (r.inspector_name || '')).toLowerCase();
            return hay.indexOf(q) !== -1;
        });
        renderFindingsTableRows(filtered);
    }

    function closeCascadeFindingsSection() {
        var sec = document.getElementById('cascadeFindingsSection');
        if (sec) sec.style.display = 'none';
    }

    function switchDateFilterMode(mode) {
        var cMY = document.getElementById('containerMonthYear');
        var cCD = document.getElementById('containerCustomDate');

        if (cMY) cMY.style.display = (mode === 'month_year') ? 'inline-flex' : 'none';
        if (cCD) cCD.style.display = (mode === 'custom') ? 'inline-flex' : 'none';

        var custParam = (filterParams && filterParams.customer) ? ('&customer=' + encodeURIComponent(filterParams.customer)) : '';
        var unitParam = '&unit=' + encodeURIComponent((filterParams && filterParams.unit) ? filterParams.unit : 'pcs');
        var resParam  = '&result=' + encodeURIComponent((filterParams && filterParams.result) ? filterParams.result : 'rejected');

        if (mode === 'all_years') {
            window.location.href = '?filter_type=all_years' + unitParam + resParam + custParam;
        } else if (mode === 'preset') {
            window.location.href = '?preset=bulanan' + unitParam + resParam + custParam;
        }
    }

    function printDashboardReport() {
        // Refresh & sesuaikan ukuran seluruh canvas Chart.js sebelum dialog cetak aktif
        if (trendChartInstance) {
            trendChartInstance.resize();
        }
        if (defectStackedChartInstance) {
            defectStackedChartInstance.resize();
        }
        if (defectDoughnutChartInstance) {
            defectDoughnutChartInstance.resize();
        }
        if (modelPieChartInstance) {
            modelPieChartInstance.resize();
        }
        if (partPieChartInstance) {
            partPieChartInstance.resize();
        }
        setTimeout(function() {
            window.print();
        }, 150);
    }

    function exportDashboardExcel(btn) {
        var origText = btn ? btn.innerHTML : '';
        if (btn) {
            btn.innerHTML = '<svg class="w-3.5 h-3.5 animate-spin" style="display:inline-block;vertical-align:-2px;margin-right:6px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10" stroke-opacity="0.25"/><path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"/></svg>Menyiapkan Excel...';
            btn.disabled = true;
        }

        var form = document.createElement('form');
        form.method = 'POST';
        form.action = 'export_excel.php';
        form.style.display = 'none';

        var params = {
            preset: filterParams.preset || 'bulanan',
            filter_type: filterParams.filter_type || '',
            month: filterParams.month || '',
            year: filterParams.year || '',
            customer: filterParams.customer || '',
            unit: filterParams.unit || 'pcs',
            sample_basis: filterParams.sample_basis || 'lot',
            result: filterParams.result || 'rejected',
            top_rank: filterParams.top_rank || 5,
            defect_rank: filterParams.defect_rank || 3,
            sub_resolution: (typeof currentSubResolution !== 'undefined') ? currentSubResolution : 'weekly',
            metric_scale: currentMetricScale,
            defect_unit: currentDefectMetricUnit,
            start_date: filterParams.start_date || '',
            end_date: filterParams.end_date || ''
        };

        for (var k in params) {
            if (params.hasOwnProperty(k)) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = k;
                input.value = params[k];
                form.appendChild(input);
            }
        }

        document.body.appendChild(form);
        form.submit();
        setTimeout(function() {
            document.body.removeChild(form);
            if (btn) {
                btn.innerHTML = origText;
                btn.disabled = false;
            }
        }, 1500);
    }

    // ── Universal KPI Drilldown Modal System ──────────────────────────────────
    var kpiDrilldownCache      = null;
    var activeKpiTab           = null;
    var currentKpiGroupingMode = 'part'; // Default: Per Part

    function switchKpiGroupingMode(mode) {
        currentKpiGroupingMode = mode;
        updateKpiGroupSwitcherUI();
        if (kpiDrilldownCache) {
            renderKpiDrilldownContent(kpiDrilldownCache);
            var sInput = document.getElementById('kpiModalSearchInput');
            if (sInput && sInput.value) {
                filterKpiModalTable(sInput.value);
            }
        }
    }

    function updateKpiGroupSwitcherUI() {
        var btnPart = document.getElementById('btnKpiGroupPart');
        var btnCust = document.getElementById('btnKpiGroupCust');
        if (!btnPart || !btnCust) return;

        if (currentKpiGroupingMode === 'part') {
            btnPart.style.background = '#ffffff';
            btnPart.style.color = '#2563eb';
            btnPart.style.boxShadow = '0 1px 2px rgba(0,0,0,0.06)';
            btnCust.style.background = 'transparent';
            btnCust.style.color = '#64748b';
            btnCust.style.boxShadow = 'none';
        } else {
            btnCust.style.background = '#ffffff';
            btnCust.style.color = '#2563eb';
            btnCust.style.boxShadow = '0 1px 2px rgba(0,0,0,0.06)';
            btnPart.style.background = 'transparent';
            btnPart.style.color = '#64748b';
            btnPart.style.boxShadow = 'none';
        }
    }

    function groupRowsByPart(rawRows, kpiType) {
        if (!rawRows || !rawRows.length || currentKpiGroupingMode !== 'part') {
            return rawRows || [];
        }

        var map = {};
        var order = [];
        var grandTotalSample = 0;
        var grandTotalNg = 0;

        rawRows.forEach(function(r) {
            var pCode = (r.part_code || '').trim();
            var key = pCode || ('unassigned_' + Math.random());
            var sChecked = Number(r.total_sample_checked || 0);
            var ngPcs = Number(r.total_ng_pcs || 0);

            grandTotalSample += sChecked;
            grandTotalNg += ngPcs;

            if (!map[key]) {
                map[key] = {
                    part_code: r.part_code,
                    part_name: r.part_name,
                    model: r.model,
                    customer_list: [],
                    total_sessions: 0,
                    total_lots: 0,
                    total_sample_checked: 0,
                    total_ng_pcs: 0,
                    passed_lots: 0,
                    rejected_lots: 0
                };
                order.push(key);
            }

            var entry = map[key];
            if (r.customer && r.customer !== '-' && entry.customer_list.indexOf(r.customer) === -1) {
                entry.customer_list.push(r.customer);
            }
            entry.total_sessions += Number(r.total_sessions || 0);
            entry.total_lots += Number(r.total_lots || 0);
            entry.total_sample_checked += sChecked;
            entry.total_ng_pcs += ngPcs;
            entry.passed_lots += Number(r.passed_lots || 0);
            entry.rejected_lots += Number(r.rejected_lots || 0);
        });

        var grouped = order.map(function(key) {
            var g = map[key];
            g.customer = g.customer_list.length ? g.customer_list.join(', ') : '-';
            g.customer_count = g.customer_list.length;

            if (kpiType === 'sample_inspected') {
                g.percent_share = grandTotalSample > 0 ? ((g.total_sample_checked / grandTotalSample) * 100).toFixed(1) : '0.0';
            } else if (kpiType === 'total_ng') {
                g.ppm = g.total_sample_checked > 0 ? Math.round((g.total_ng_pcs / g.total_sample_checked) * 1000000) : 0;
                g.percent_share = grandTotalNg > 0 ? ((g.total_ng_pcs / grandTotalNg) * 100).toFixed(1) : '0.0';
            } else if (kpiType === 'defect_rate') {
                g.ppm = g.total_sample_checked > 0 ? Math.round((g.total_ng_pcs / g.total_sample_checked) * 1000000) : 0;
                g.pct = g.total_sample_checked > 0 ? Number(((g.total_ng_pcs / g.total_sample_checked) * 100).toFixed(2)) : 0;
                if (g.ppm > 10000) {
                    g.status_badge = 'kritis';
                } else if (g.ppm > 0) {
                    g.status_badge = 'waspada';
                } else {
                    g.status_badge = 'aman';
                }
            } else if (kpiType === 'total_lot') {
                g.pass_rate = g.total_lots > 0 ? Math.round((g.passed_lots / g.total_lots) * 1000) / 10 : 0;
            }

            return g;
        });

        if (kpiType === 'sample_inspected') {
            grouped.sort(function(a, b) { return b.total_sample_checked - a.total_sample_checked; });
        } else if (kpiType === 'total_ng') {
            grouped.sort(function(a, b) { return b.total_ng_pcs - a.total_ng_pcs; });
        } else if (kpiType === 'defect_rate') {
            grouped.sort(function(a, b) { return b.ppm - a.ppm; });
        } else if (kpiType === 'total_lot') {
            grouped.sort(function(a, b) { return b.total_lots - a.total_lots; });
        }

        return grouped;
    }

    function formatCustomerDisplay(r) {
        if (r.customer_list && r.customer_list.length > 1) {
            var html = '<div style="display:flex;flex-wrap:wrap;gap:4px;">';
            r.customer_list.forEach(function(c) {
                html += '<span style="display:inline-block;padding:1px 6px;font-size:9.5px;font-weight:700;background:#eff6ff;color:#1e40af;border-radius:4px;border:1px solid #dbeafe;">' + escapeHtml(c) + '</span>';
            });
            html += '</div>';
            return html;
        }
        return escapeHtml(r.customer || '-');
    }

    function openKpiDrilldownModal(kpiType) {
        var modal = document.getElementById('modalKpiDrilldown');
        if (!modal) return;

        // Default to Per Part view on open
        currentKpiGroupingMode = 'part';
        updateKpiGroupSwitcherUI();
        activeKpiTab = 'by_part';

        // Reset search input and container
        var searchInput = document.getElementById('kpiModalSearchInput');
        if (searchInput) searchInput.value = '';

        var statsContainer = document.getElementById('kpiModalStatsContainer');
        if (statsContainer) statsContainer.innerHTML = '';

        var tabsContainer = document.getElementById('kpiModalTabsContainer');
        if (tabsContainer) {
            tabsContainer.innerHTML = '';
            tabsContainer.style.display = 'none';
        }

        var bodyEl = document.getElementById('kpiModalBody');
        if (bodyEl) {
            bodyEl.innerHTML = '<div style="padding:48px 16px;text-align:center;color:#64748b;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;">' +
                               '<svg class="w-7 h-7 animate-spin" style="color:#2563eb;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10" stroke-opacity="0.25"/><path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"/></svg>' +
                               '<span style="font-size:12.5px;font-weight:700;color:#334155;">Memuat rincian data analitik...</span>' +
                               '</div>';
        }

        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';

        // Set initial headers based on type
        var accentBar = document.getElementById('kpiModalAccentBar');
        var colorMap = {
            'sample_inspected': '#2563eb',
            'total_ng':         '#dc2626',
            'defect_rate':      '#d97706',
            'total_lot':        '#0284c7',
            'ng_lot':           '#e11d48',
            'passed_lot':       '#059669'
        };
        if (accentBar) accentBar.style.background = colorMap[kpiType] || '#2563eb';

        // Build query string matching current active filters
        var qs = new URLSearchParams(window.location.search);
        qs.set('kpi_type', kpiType);
        qs.set('metric_scale', currentMetricScale);

        fetch('ajax_kpi_drilldown.php?' + qs.toString(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(res) {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
        })
        .then(function(json) {
            if (!json.success) {
                if (bodyEl) bodyEl.innerHTML = '<div style="padding:40px;text-align:center;color:#dc2626;font-size:12px;font-weight:700;">' + (json.message || 'Gagal memuat data.') + '</div>';
                return;
            }
            kpiDrilldownCache = json;
            renderKpiDrilldownContent(json);
        })
        .catch(function(err) {
            if (bodyEl) {
                bodyEl.innerHTML = '<div style="padding:40px;text-align:center;color:#dc2626;font-size:12px;font-weight:700;">Terjadi kesalahan saat memuat data: ' + err.message + '</div>';
            }
        });
    }

    function closeKpiDrilldownModal() {
        var modal = document.getElementById('modalKpiDrilldown');
        if (modal) modal.style.display = 'none';
        document.body.style.overflow = '';
        kpiDrilldownCache = null;
    }

    // Close on ESC key or backdrop click
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeKpiDrilldownModal();
    });
    var modalBackdropEl = document.getElementById('modalKpiDrilldown');
    if (modalBackdropEl) {
        modalBackdropEl.addEventListener('click', function(e) {
            if (e.target === this) closeKpiDrilldownModal();
        });
    }

    function renderKpiDrilldownContent(json) {
        var titleEl    = document.getElementById('kpiModalTitle');
        var subEl      = document.getElementById('kpiModalSubtitle');
        var badgePer   = document.getElementById('kpiModalBadgePeriod');
        var statsEl    = document.getElementById('kpiModalStatsContainer');
        var tabsEl     = document.getElementById('kpiModalTabsContainer');
        var bodyEl     = document.getElementById('kpiModalBody');

        if (titleEl)  titleEl.textContent  = json.title || 'Rincian KPI';
        if (subEl)    subEl.textContent    = json.subtitle || '';
        if (badgePer) badgePer.textContent = (json.period_label || '') + (json.customer_label && json.customer_label !== 'Semua Customer' ? ' &middot; ' + json.customer_label : '');

        // Render Stats Pills
        if (statsEl && json.stats && json.stats.length) {
            var statsHtml = '';
            json.stats.forEach(function(st) {
                statsHtml += '<div style="background:#f8fafc;border:1px solid #e2e8f0;padding:6px 12px;border-radius:8px;display:flex;align-items:center;gap:8px;font-size:11px;">' +
                             '<span style="color:#64748b;font-weight:600;">' + st.label + ':</span>' +
                             '<span style="font-weight:800;color:' + (st.color || '#0f172a') + ';">' + st.value + '</span>' +
                             '</div>';
            });
            statsEl.innerHTML = statsHtml;
        }

        // Handle Tabs for total_ng
        if (json.kpi_type === 'total_ng') {
            if (tabsEl) {
                tabsEl.style.display = 'inline-flex';
                activeKpiTab = activeKpiTab || 'by_part';
                var tabs = [
                    { key: 'by_part',   label: 'Rincian per Part' },
                    { key: 'by_defect', label: 'Rincian per Defect' },
                    { key: 'by_log',    label: 'Log Temuan Lapangan' }
                ];
                var tabsHtml = '';
                tabs.forEach(function(tb) {
                    var isAct = (tb.key === activeKpiTab);
                    tabsHtml += '<button type="button" onclick="switchKpiModalTab(\'' + tb.key + '\')" ' +
                                'style="padding:3px 10px;font-size:10.5px;font-weight:800;border-radius:6px;border:none;cursor:pointer;transition:all 0.15s;' +
                                (isAct ? 'background:#ffffff;color:#dc2626;box-shadow:0 1px 2px rgba(0,0,0,0.06);' : 'background:transparent;color:#64748b;') + '">' +
                                tb.label + '</button>';
                });
                tabsEl.innerHTML = tabsHtml;
            }

            var groupSwitcher = document.getElementById('kpiModalGroupSwitcher');
            if (groupSwitcher) {
                groupSwitcher.style.display = (activeKpiTab === 'by_part') ? 'inline-flex' : 'none';
                updateKpiGroupSwitcherUI();
            }

            renderTotalNgTable(activeKpiTab, json.data);
            return;
        }

        // Other KPI Cards: group switcher visibility
        var groupSwitcher = document.getElementById('kpiModalGroupSwitcher');
        var canGroup = (json.kpi_type === 'sample_inspected' || json.kpi_type === 'defect_rate' || json.kpi_type === 'total_lot');
        if (groupSwitcher) {
            groupSwitcher.style.display = canGroup ? 'inline-flex' : 'none';
            updateKpiGroupSwitcherUI();
        }

        // Render direct table
        switch (json.kpi_type) {
            case 'sample_inspected':
                renderSampleInspectedTable(json.data);
                break;
            case 'defect_rate':
                renderDefectRateTable(json.data, json.metric_scale);
                break;
            case 'total_lot':
                renderTotalLotTable(json.data);
                break;
            case 'ng_lot':
            case 'passed_lot':
                renderDetailLotTable(json.data, json.kpi_type === 'passed_lot');
                break;
            default:
                if (bodyEl) bodyEl.innerHTML = '<div style="padding:20px;text-align:center;color:#64748b;">Format data belum didukung.</div>';
        }
    }

    function switchKpiModalTab(tabKey) {
        activeKpiTab = tabKey;
        if (!kpiDrilldownCache) return;

        // Update visual tab button secara instan
        var tabsContainer = document.getElementById('kpiModalTabsContainer');
        if (tabsContainer) {
            var tabBtns = tabsContainer.querySelectorAll('button');
            tabBtns.forEach(function(btn) {
                if (btn.getAttribute('onclick') && btn.getAttribute('onclick').indexOf(tabKey) !== -1) {
                    btn.style.background = '#ffffff';
                    btn.style.color = '#dc2626';
                    btn.style.boxShadow = '0 1px 2px rgba(0,0,0,0.06)';
                } else {
                    btn.style.background = 'transparent';
                    btn.style.color = '#64748b';
                    btn.style.boxShadow = 'none';
                }
            });
        }

        var groupSwitcher = document.getElementById('kpiModalGroupSwitcher');
        if (groupSwitcher) {
            groupSwitcher.style.display = (tabKey === 'by_part') ? 'inline-flex' : 'none';
        }

        // Lazy load tab Log Temuan jika belum pernah diambil dari backend
        if (tabKey === 'by_log' && (!kpiDrilldownCache.data || kpiDrilldownCache.data.by_log === null)) {
            var bodyEl = document.getElementById('kpiModalBody');
            if (bodyEl) {
                bodyEl.innerHTML = '<div style="padding:48px 16px;text-align:center;color:#64748b;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;">' +
                                   '<svg class="w-7 h-7 animate-spin" style="color:#dc2626;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10" stroke-opacity="0.25"/><path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"/></svg>' +
                                   '<span style="font-size:12.5px;font-weight:700;color:#334155;">Memuat rincian log temuan cacat lapangan...</span>' +
                                   '</div>';
            }

            var qs = new URLSearchParams(window.location.search);
            qs.set('kpi_type', 'total_ng');
            qs.set('tab', 'by_log');
            qs.set('metric_scale', currentMetricScale);

            fetch('ajax_kpi_drilldown.php?' + qs.toString(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(res) { return res.json(); })
            .then(function(res) {
                if (res.success && res.data) {
                    kpiDrilldownCache.data.by_log = res.data;
                } else {
                    kpiDrilldownCache.data.by_log = [];
                }
                renderKpiDrilldownContent(kpiDrilldownCache);
                var sInput = document.getElementById('kpiModalSearchInput');
                if (sInput && sInput.value) {
                    filterKpiModalTable(sInput.value);
                }
            })
            .catch(function(err) {
                if (bodyEl) {
                    bodyEl.innerHTML = '<div style="padding:40px;text-align:center;color:#dc2626;font-size:12px;font-weight:700;">Gagal memuat log temuan: ' + err.message + '</div>';
                }
            });
            return;
        }

        renderKpiDrilldownContent(kpiDrilldownCache);
        var sInput = document.getElementById('kpiModalSearchInput');
        if (sInput && sInput.value) {
            filterKpiModalTable(sInput.value);
        }
    }

    // ── Table Renderers ───────────────────────────────────────────────────────
    function renderSampleInspectedTable(rows) {
        var bodyEl = document.getElementById('kpiModalBody');
        if (!bodyEl) return;
        rows = groupRowsByPart(rows, 'sample_inspected');
        if (!rows || !rows.length) {
            bodyEl.innerHTML = renderEmptyState('Tidak ada data sampel diperiksa pada periode ini.');
            updateFooterCount(0, 0);
            return;
        }
        var html = '<table id="kpiDrilldownDataTable" style="width:100%;border-collapse:collapse;font-size:11px;text-align:left;">' +
                   '<thead><tr style="background:#1e293b;color:#ffffff;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;position:sticky;top:0;z-index:2;">' +
                   '<th style="padding:8px 10px;text-align:center;width:40px;">NO</th>' +
                   '<th style="padding:8px 10px;">KODE PART</th>' +
                   '<th style="padding:8px 10px;">NAMA PART</th>' +
                   '<th style="padding:8px 10px;">MODEL</th>' +
                   '<th style="padding:8px 10px;">CUSTOMER</th>' +
                   '<th style="padding:8px 10px;text-align:center;">TOTAL SESI</th>' +
                   '<th style="padding:8px 10px;text-align:center;">TOTAL LOT</th>' +
                   '<th style="padding:8px 10px;text-align:right;">SAMPEL DICEK (PCS)</th>' +
                   '<th style="padding:8px 10px;text-align:right;">KONTRIBUSI</th>' +
                   '</tr></thead><tbody>';

        rows.forEach(function(r, idx) {
            html += '<tr class="kpi-row-hover" style="border-bottom:1px solid #f1f5f9;">' +
                    '<td style="padding:7px 10px;text-align:center;font-weight:700;color:#94a3b8;">' + (idx + 1) + '</td>' +
                    '<td style="padding:7px 10px;font-family:monospace;font-weight:700;color:#1e293b;">' + escapeHtml(r.part_code) + '</td>' +
                    '<td style="padding:7px 10px;font-weight:800;color:#0f172a;">' + escapeHtml(r.part_name) + '</td>' +
                    '<td style="padding:7px 10px;color:#475569;">' + escapeHtml(r.model) + '</td>' +
                    '<td style="padding:7px 10px;color:#475569;">' + formatCustomerDisplay(r) + '</td>' +
                    '<td style="padding:7px 10px;text-align:center;font-weight:700;color:#334155;">' + Number(r.total_sessions || 0).toLocaleString() + '</td>' +
                    '<td style="padding:7px 10px;text-align:center;font-weight:700;color:#334155;">' + Number(r.total_lots || 0).toLocaleString() + '</td>' +
                    '<td style="padding:7px 10px;text-align:right;font-family:monospace;font-weight:900;color:#2563eb;background:#eff6ff;">' + Number(r.total_sample_checked || 0).toLocaleString() + '</td>' +
                    '<td style="padding:7px 10px;text-align:right;font-weight:700;color:#64748b;">' + (r.percent_share || 0) + '%</td>' +
                    '</tr>';
        });

        html += '</tbody></table>';
        bodyEl.innerHTML = html;
        updateFooterCount(rows.length, rows.length);
    }

    function renderTotalNgTable(tabKey, dataObj) {
        var bodyEl = document.getElementById('kpiModalBody');
        if (!bodyEl) return;
        dataObj = dataObj || {};

        if (tabKey === 'by_part') {
            var rows = groupRowsByPart(dataObj.by_part || [], 'total_ng');
            if (!rows.length) {
                bodyEl.innerHTML = renderEmptyState('Tidak ada temuan part reject pada periode ini.');
                updateFooterCount(0, 0);
                return;
            }
            var html = '<table id="kpiDrilldownDataTable" style="width:100%;border-collapse:collapse;font-size:11px;text-align:left;">' +
                       '<thead><tr style="background:#1e293b;color:#ffffff;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;position:sticky;top:0;z-index:2;">' +
                       '<th style="padding:8px 10px;text-align:center;width:40px;">NO</th>' +
                       '<th style="padding:8px 10px;">KODE PART</th>' +
                       '<th style="padding:8px 10px;">NAMA PART</th>' +
                       '<th style="padding:8px 10px;">MODEL</th>' +
                       '<th style="padding:8px 10px;">CUSTOMER</th>' +
                       '<th style="padding:8px 10px;text-align:right;">SAMPEL DICEK</th>' +
                       '<th style="padding:8px 10px;text-align:right;">TOTAL NG (PCS)</th>' +
                       '<th style="padding:8px 10px;text-align:right;">DEFECT RATE</th>' +
                       '<th style="padding:8px 10px;text-align:right;">KONTRIBUSI</th>' +
                       '</tr></thead><tbody>';

            rows.forEach(function(r, idx) {
                html += '<tr class="kpi-row-hover" style="border-bottom:1px solid #f1f5f9;">' +
                        '<td style="padding:7px 10px;text-align:center;font-weight:700;color:#94a3b8;">' + (idx + 1) + '</td>' +
                        '<td style="padding:7px 10px;font-family:monospace;font-weight:700;color:#1e293b;">' + escapeHtml(r.part_code) + '</td>' +
                        '<td style="padding:7px 10px;font-weight:800;color:#0f172a;">' + escapeHtml(r.part_name) + '</td>' +
                        '<td style="padding:7px 10px;color:#475569;">' + escapeHtml(r.model) + '</td>' +
                        '<td style="padding:7px 10px;color:#475569;">' + formatCustomerDisplay(r) + '</td>' +
                        '<td style="padding:7px 10px;text-align:right;font-family:monospace;color:#64748b;">' + Number(r.total_sample_checked || 0).toLocaleString() + '</td>' +
                        '<td style="padding:7px 10px;text-align:right;font-family:monospace;font-weight:900;color:#dc2626;background:#fff1f2;">' + Number(r.total_ng_pcs || 0).toLocaleString() + '</td>' +
                        '<td style="padding:7px 10px;text-align:right;font-family:monospace;font-weight:800;color:#b91c1c;">' + Number(r.ppm || 0).toLocaleString() + ' PPM</td>' +
                        '<td style="padding:7px 10px;text-align:right;font-weight:700;color:#64748b;">' + (r.percent_share || 0) + '%</td>' +
                        '</tr>';
            });
            html += '</tbody></table>';
            bodyEl.innerHTML = html;
            updateFooterCount(rows.length, rows.length);

        } else if (tabKey === 'by_defect') {
            var rows = dataObj.by_defect || [];
            if (!rows.length) {
                bodyEl.innerHTML = renderEmptyState('Tidak ada data jenis cacat pada periode ini.');
                updateFooterCount(0, 0);
                return;
            }
            var html = '<table id="kpiDrilldownDataTable" style="width:100%;border-collapse:collapse;font-size:11px;text-align:left;">' +
                       '<thead><tr style="background:#1e293b;color:#ffffff;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;position:sticky;top:0;z-index:2;">' +
                       '<th style="padding:8px 10px;text-align:center;width:40px;">NO</th>' +
                       '<th style="padding:8px 10px;">KODE DEFECT</th>' +
                       '<th style="padding:8px 10px;">JENIS CACAT / DEFECT</th>' +
                       '<th style="padding:8px 10px;text-align:right;">TOTAL NG (PCS)</th>' +
                       '<th style="padding:8px 10px;text-align:center;">LOT TERDAMPAK</th>' +
                       '<th style="padding:8px 10px;text-align:right;">KONTRIBUSI</th>' +
                       '</tr></thead><tbody>';

            rows.forEach(function(r, idx) {
                html += '<tr class="kpi-row-hover" style="border-bottom:1px solid #f1f5f9;">' +
                        '<td style="padding:7px 10px;text-align:center;font-weight:700;color:#94a3b8;">' + (idx + 1) + '</td>' +
                        '<td style="padding:7px 10px;font-family:monospace;font-weight:700;color:#475569;">' + escapeHtml(r.defect_code) + '</td>' +
                        '<td style="padding:7px 10px;font-weight:800;color:#0f172a;">' + escapeHtml(r.defect_name) + '</td>' +
                        '<td style="padding:7px 10px;text-align:right;font-family:monospace;font-weight:900;color:#dc2626;background:#fff1f2;">' + Number(r.total_qty_ng || 0).toLocaleString() + '</td>' +
                        '<td style="padding:7px 10px;text-align:center;font-weight:700;color:#334155;">' + Number(r.total_lots_affected || 0).toLocaleString() + ' lot</td>' +
                        '<td style="padding:7px 10px;text-align:right;font-weight:700;color:#64748b;">' + (r.percent_share || 0) + '%</td>' +
                        '</tr>';
            });
            html += '</tbody></table>';
            bodyEl.innerHTML = html;
            updateFooterCount(rows.length, rows.length);

        } else if (tabKey === 'by_log') {
            var rows = dataObj.by_log || [];
            if (!rows.length) {
                bodyEl.innerHTML = renderEmptyState('Tidak ada catatan temuan inspeksi.');
                updateFooterCount(0, 0);
                return;
            }
            var html = '<table id="kpiDrilldownDataTable" style="width:100%;border-collapse:collapse;font-size:11px;text-align:left;">' +
                       '<thead><tr style="background:#1e293b;color:#ffffff;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;position:sticky;top:0;z-index:2;">' +
                       '<th style="padding:8px 10px;text-align:center;width:40px;">NO</th>' +
                       '<th style="padding:8px 10px;">TANGGAL / WAKTU</th>' +
                       '<th style="padding:8px 10px;">NO LOT / KANBAN</th>' +
                       '<th style="padding:8px 10px;">PART</th>' +
                       '<th style="padding:8px 10px;">JENIS CACAT</th>' +
                       '<th style="padding:8px 10px;text-align:center;">QTY NG</th>' +
                       '<th style="padding:8px 10px;">CATATAN / STATUS SORTIR</th>' +
                       '</tr></thead><tbody>';

            rows.forEach(function(r, idx) {
                var sortStatus = (r.is_sorted == 1) 
                    ? '<span style="display:inline-block;padding:2px 7px;font-size:9.5px;font-weight:800;border-radius:5px;background:#dcfce7;color:#15803d;border:1px solid #bbf7d0;">Telah Disortir</span>'
                    : '<span style="display:inline-block;padding:2px 7px;font-size:9.5px;font-weight:800;border-radius:5px;background:#fee2e2;color:#b91c1c;border:1px solid #fecdd3;">Perlu Sortir</span>';

                html += '<tr class="kpi-row-hover" style="border-bottom:1px solid #f1f5f9;">' +
                        '<td style="padding:7px 10px;text-align:center;font-weight:700;color:#94a3b8;">' + (idx + 1) + '</td>' +
                        '<td style="padding:7px 10px;white-space:nowrap;color:#475569;font-weight:600;">' + escapeHtml(r.created_at || r.started_at) + '</td>' +
                        '<td style="padding:7px 10px;font-family:monospace;font-weight:700;color:#1e293b;">' + escapeHtml(r.lot_number) + (r.ref_number && r.ref_number !== '-' ? ' <span style="font-size:9.5px;color:#64748b;">(' + escapeHtml(r.ref_number) + ')</span>' : '') + '</td>' +
                        '<td style="padding:7px 10px;">' +
                            '<div style="font-weight:800;color:#0f172a;">' + escapeHtml(r.part_name) + '</div>' +
                            '<div style="font-size:9.5px;color:#64748b;font-family:monospace;">' + escapeHtml(r.part_code) + ' &middot; ' + escapeHtml(r.model) + '</div>' +
                        '</td>' +
                        '<td style="padding:7px 10px;font-weight:800;color:#b91c1c;">' + escapeHtml(r.defect_name) + '</td>' +
                        '<td style="padding:7px 10px;text-align:center;font-family:monospace;font-weight:900;color:#dc2626;background:#fff1f2;">' + Number(r.qty_ng || 1) + '</td>' +
                        '<td style="padding:7px 10px;">' +
                            '<div style="display:flex;align-items:center;gap:6px;">' + sortStatus + ' <span style="color:#64748b;">' + escapeHtml(r.remark || r.sort_notes || '-') + '</span></div>' +
                        '</td>' +
                        '</tr>';
            });
            html += '</tbody></table>';
            bodyEl.innerHTML = html;
            updateFooterCount(rows.length, rows.length);
        }
    }

    function renderDefectRateTable(rows, scale) {
        var bodyEl = document.getElementById('kpiModalBody');
        if (!bodyEl) return;
        rows = groupRowsByPart(rows, 'defect_rate');
        if (!rows || !rows.length) {
            bodyEl.innerHTML = renderEmptyState('Tidak ada data kualitas per part.');
            updateFooterCount(0, 0);
            return;
        }

        var isPct = (scale === 'pct' || scale === 'percent' || scale === '%');
        var rateLabel = isPct ? 'DEFECT RATE (%)' : 'DEFECT RATE (PPM)';

        var html = '<table id="kpiDrilldownDataTable" style="width:100%;border-collapse:collapse;font-size:11px;text-align:left;">' +
                   '<thead><tr style="background:#1e293b;color:#ffffff;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;position:sticky;top:0;z-index:2;">' +
                   '<th style="padding:8px 10px;text-align:center;width:40px;">NO</th>' +
                   '<th style="padding:8px 10px;">KODE PART</th>' +
                   '<th style="padding:8px 10px;">NAMA PART</th>' +
                   '<th style="padding:8px 10px;">MODEL</th>' +
                   '<th style="padding:8px 10px;">CUSTOMER</th>' +
                   '<th style="padding:8px 10px;text-align:right;">SAMPEL DICEK</th>' +
                   '<th style="padding:8px 10px;text-align:right;">TOTAL NG (PCS)</th>' +
                   '<th style="padding:8px 10px;text-align:right;">' + rateLabel + '</th>' +
                   '<th style="padding:8px 10px;text-align:center;">STATUS MUTU</th>' +
                   '</tr></thead><tbody>';

        rows.forEach(function(r, idx) {
            var badgeHtml = '';
            if (r.status_badge === 'kritis') {
                badgeHtml = '<span style="display:inline-block;padding:2px 8px;font-size:10px;font-weight:800;border-radius:5px;background:#fee2e2;color:#b91c1c;border:1px solid #fecdd3;">Kritis</span>';
            } else if (r.status_badge === 'waspada') {
                badgeHtml = '<span style="display:inline-block;padding:2px 8px;font-size:10px;font-weight:800;border-radius:5px;background:#fef3c7;color:#b45309;border:1px solid #fde68a;">Waspada</span>';
            } else {
                badgeHtml = '<span style="display:inline-block;padding:2px 8px;font-size:10px;font-weight:800;border-radius:5px;background:#dcfce7;color:#15803d;border:1px solid #bbf7d0;">Aman</span>';
            }

            var rateValStr = isPct 
                ? (Number(r.pct || 0).toLocaleString(undefined, {minimumFractionDigits: 1, maximumFractionDigits: 2}) + ' %')
                : (Number(r.ppm || 0).toLocaleString() + ' PPM');

            html += '<tr class="kpi-row-hover" style="border-bottom:1px solid #f1f5f9;">' +
                    '<td style="padding:7px 10px;text-align:center;font-weight:700;color:#94a3b8;">' + (idx + 1) + '</td>' +
                    '<td style="padding:7px 10px;font-family:monospace;font-weight:700;color:#1e293b;">' + escapeHtml(r.part_code) + '</td>' +
                    '<td style="padding:7px 10px;font-weight:800;color:#0f172a;">' + escapeHtml(r.part_name) + '</td>' +
                    '<td style="padding:7px 10px;color:#475569;">' + escapeHtml(r.model) + '</td>' +
                    '<td style="padding:7px 10px;color:#475569;">' + formatCustomerDisplay(r) + '</td>' +
                    '<td style="padding:7px 10px;text-align:right;font-family:monospace;color:#64748b;">' + Number(r.total_sample_checked || 0).toLocaleString() + '</td>' +
                    '<td style="padding:7px 10px;text-align:right;font-family:monospace;font-weight:900;color:' + (r.total_ng_pcs > 0 ? '#dc2626' : '#64748b') + ';">' + Number(r.total_ng_pcs || 0).toLocaleString() + '</td>' +
                    '<td style="padding:7px 10px;text-align:right;font-family:monospace;font-weight:900;color:' + (r.ppm > 10000 ? '#b91c1c' : (r.ppm > 0 ? '#b45309' : '#059669')) + ';">' + rateValStr + '</td>' +
                    '<td style="padding:7px 10px;text-align:center;">' + badgeHtml + '</td>' +
                    '</tr>';
        });

        html += '</tbody></table>';
        bodyEl.innerHTML = html;
        updateFooterCount(rows.length, rows.length);
    }

    function renderTotalLotTable(rows) {
        var bodyEl = document.getElementById('kpiModalBody');
        if (!bodyEl) return;
        rows = groupRowsByPart(rows, 'total_lot');
        if (!rows || !rows.length) {
            bodyEl.innerHTML = renderEmptyState('Tidak ada data volume lot.');
            updateFooterCount(0, 0);
            return;
        }

        var html = '<table id="kpiDrilldownDataTable" style="width:100%;border-collapse:collapse;font-size:11px;text-align:left;">' +
                   '<thead><tr style="background:#1e293b;color:#ffffff;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;position:sticky;top:0;z-index:2;">' +
                   '<th style="padding:8px 10px;text-align:center;width:40px;">NO</th>' +
                   '<th style="padding:8px 10px;">KODE PART</th>' +
                   '<th style="padding:8px 10px;">NAMA PART</th>' +
                   '<th style="padding:8px 10px;">MODEL</th>' +
                   '<th style="padding:8px 10px;">CUSTOMER</th>' +
                   '<th style="padding:8px 10px;text-align:center;">TOTAL LOT</th>' +
                   '<th style="padding:8px 10px;text-align:center;">LOT LOLOS</th>' +
                   '<th style="padding:8px 10px;text-align:center;">LOT REJECT</th>' +
                   '<th style="padding:8px 10px;text-align:right;">% KELULUSAN</th>' +
                   '</tr></thead><tbody>';

        rows.forEach(function(r, idx) {
            var passRate = Number(r.pass_rate || 0);
            var passColor = passRate >= 95 ? '#059669' : (passRate >= 80 ? '#d97706' : '#dc2626');

            html += '<tr class="kpi-row-hover" style="border-bottom:1px solid #f1f5f9;">' +
                    '<td style="padding:7px 10px;text-align:center;font-weight:700;color:#94a3b8;">' + (idx + 1) + '</td>' +
                    '<td style="padding:7px 10px;font-family:monospace;font-weight:700;color:#1e293b;">' + escapeHtml(r.part_code) + '</td>' +
                    '<td style="padding:7px 10px;font-weight:800;color:#0f172a;">' + escapeHtml(r.part_name) + '</td>' +
                    '<td style="padding:7px 10px;color:#475569;">' + escapeHtml(r.model) + '</td>' +
                    '<td style="padding:7px 10px;color:#475569;">' + formatCustomerDisplay(r) + '</td>' +
                    '<td style="padding:7px 10px;text-align:center;font-family:monospace;font-weight:900;color:#0284c7;background:#f0f9ff;">' + Number(r.total_lots || 0).toLocaleString() + '</td>' +
                    '<td style="padding:7px 10px;text-align:center;font-family:monospace;font-weight:700;color:#059669;">' + Number(r.passed_lots || 0).toLocaleString() + '</td>' +
                    '<td style="padding:7px 10px;text-align:center;font-family:monospace;font-weight:700;color:' + (r.rejected_lots > 0 ? '#dc2626' : '#94a3b8') + ';">' + Number(r.rejected_lots || 0).toLocaleString() + '</td>' +
                    '<td style="padding:7px 10px;text-align:right;font-family:monospace;font-weight:900;color:' + passColor + ';">' + passRate + '%</td>' +
                    '</tr>';
        });

        html += '</tbody></table>';
        bodyEl.innerHTML = html;
        updateFooterCount(rows.length, rows.length);
    }

    function renderDetailLotTable(rows, isPassed) {
        var bodyEl = document.getElementById('kpiModalBody');
        if (!bodyEl) return;
        if (!rows || !rows.length) {
            bodyEl.innerHTML = renderEmptyState(isPassed ? 'Tidak ada box/lot lolos.' : 'Tidak ada box/lot berstatus reject.');
            updateFooterCount(0, 0);
            return;
        }

        var html = '<table id="kpiDrilldownDataTable" style="width:100%;border-collapse:collapse;font-size:11px;text-align:left;">' +
                   '<thead><tr style="background:#1e293b;color:#ffffff;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;position:sticky;top:0;z-index:2;">' +
                   '<th style="padding:8px 10px;text-align:center;width:40px;">NO</th>' +
                   '<th style="padding:8px 10px;">WAKTU SESI</th>' +
                   '<th style="padding:8px 10px;">NO LOT</th>' +
                   '<th style="padding:8px 10px;">NO KANBAN</th>' +
                   '<th style="padding:8px 10px;">PART</th>' +
                   '<th style="padding:8px 10px;text-align:center;">QTY BOX</th>' +
                   '<th style="padding:8px 10px;text-align:center;">SAMPEL</th>' +
                   (isPassed ? '<th style="padding:8px 10px;text-align:center;">STATUS HASIL</th>' : '<th style="padding:8px 10px;">TEMUAN DEFECT</th><th style="padding:8px 10px;text-align:center;">STATUS LOT</th>') +
                   '</tr></thead><tbody>';

        rows.forEach(function(r, idx) {
            var lotStatusBadge = '';
            if (isPassed) {
                lotStatusBadge = '<span style="display:inline-block;padding:2px 7px;font-size:9.5px;font-weight:800;border-radius:5px;background:#dcfce7;color:#15803d;border:1px solid #bbf7d0;">Passed OK</span>';
            } else {
                if (r.lot_status === 'replaced') {
                    lotStatusBadge = '<span style="display:inline-block;padding:2px 7px;font-size:9.5px;font-weight:800;border-radius:5px;background:#fef3c7;color:#b45309;border:1px solid #fde68a;">Replaced (Diganti)</span>';
                } else if (r.lot_status === 'ng_quarantine') {
                    lotStatusBadge = '<span style="display:inline-block;padding:2px 7px;font-size:9.5px;font-weight:800;border-radius:5px;background:#fee2e2;color:#b91c1c;border:1px solid #fecdd3;">Karantina NG</span>';
                } else if (r.lot_status === 'reinspected' || (r.lot_result === 'passed' && r.active_ng_cnt > 0)) {
                    lotStatusBadge = '<span style="display:inline-block;padding:2px 7px;font-size:9.5px;font-weight:800;border-radius:5px;background:#e0e7ff;color:#4338ca;border:1px solid #c7d2fe;">Re-inspeksi (Passed)</span>';
                } else if (r.lot_status === 'action_closed') {
                    lotStatusBadge = '<span style="display:inline-block;padding:2px 7px;font-size:9.5px;font-weight:800;border-radius:5px;background:#f1f5f9;color:#475569;border:1px solid #cbd5e1;">Tindakan Selesai</span>';
                } else if (r.lot_status === 'abolished') {
                    lotStatusBadge = '<span style="display:inline-block;padding:2px 7px;font-size:9.5px;font-weight:800;border-radius:5px;background:#fef2f2;color:#991b1b;border:1px solid #f87171;">Dimusnahkan (Scrap)</span>';
                } else {
                    lotStatusBadge = '<span style="display:inline-block;padding:2px 7px;font-size:9.5px;font-weight:800;border-radius:5px;background:#ffe4e6;color:#e11d48;border:1px solid #fecdd3;">Reject</span>';
                }
            }

            html += '<tr class="kpi-row-hover" style="border-bottom:1px solid #f1f5f9;">' +
                    '<td style="padding:7px 10px;text-align:center;font-weight:700;color:#94a3b8;">' + (idx + 1) + '</td>' +
                    '<td style="padding:7px 10px;white-space:nowrap;color:#475569;font-weight:600;">' + escapeHtml(r.started_at) + '</td>' +
                    '<td style="padding:7px 10px;font-family:monospace;font-weight:800;color:#1e293b;">' + escapeHtml(r.lot_number) + '</td>' +
                    '<td style="padding:7px 10px;font-family:monospace;color:#64748b;">' + escapeHtml(r.ref_number || '-') + '</td>' +
                    '<td style="padding:7px 10px;">' +
                        '<div style="font-weight:800;color:#0f172a;">' + escapeHtml(r.part_name) + '</div>' +
                        '<div style="font-size:9.5px;color:#64748b;font-family:monospace;">' + escapeHtml(r.part_code) + ' &middot; ' + escapeHtml(r.model) + '</div>' +
                    '</td>' +
                    '<td style="padding:7px 10px;text-align:center;font-family:monospace;font-weight:700;color:#334155;">' + Number(r.qty || 0).toLocaleString() + '</td>' +
                    '<td style="padding:7px 10px;text-align:center;font-family:monospace;color:#64748b;">' + Number(r.sample_size || 0).toLocaleString() + '</td>' +
                    (isPassed 
                        ? '<td style="padding:7px 10px;text-align:center;">' + lotStatusBadge + '</td>'
                        : '<td style="padding:7px 10px;font-weight:800;color:#dc2626;">' + escapeHtml(r.defect_breakdown || r.remarks || 'Temuan Cacat') + '</td><td style="padding:7px 10px;text-align:center;">' + lotStatusBadge + '</td>') +
                    '</tr>';
        });

        html += '</tbody></table>';
        bodyEl.innerHTML = html;
        updateFooterCount(rows.length, rows.length);
    }

    // ── Helper Utilities ──────────────────────────────────────────────────────
    function renderEmptyState(msg) {
        return '<div style="padding:48px 16px;text-align:center;color:#94a3b8;font-size:12px;background:#f8fafc;border-radius:10px;border:1px dashed #e2e8f0;">' +
               '<div style="font-weight:700;color:#64748b;">' + escapeHtml(msg) + '</div>' +
               '</div>';
    }

    function escapeHtml(str) {
        if (!str) return '-';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function updateFooterCount(visibleCount, totalCount) {
        var fc = document.getElementById('kpiModalFooterCount');
        if (fc) {
            if (visibleCount === totalCount) {
                fc.textContent = 'Menampilkan total ' + totalCount + ' baris data';
            } else {
                fc.textContent = 'Menampilkan ' + visibleCount + ' dari total ' + totalCount + ' baris data';
            }
        }
    }

    function filterKpiModalTable(query) {
        var table = document.getElementById('kpiDrilldownDataTable');
        if (!table) return;
        query = (query || '').toLowerCase().trim();
        var rows = table.querySelectorAll('tbody tr');
        var visible = 0;

        rows.forEach(function(r) {
            var text = r.textContent.toLowerCase();
            if (!query || text.indexOf(query) !== -1) {
                r.style.display = '';
                visible++;
            } else {
                r.style.display = 'none';
            }
        });

        updateFooterCount(visible, rows.length);
    }

    function exportKpiModalToExcel() {
        var table = document.getElementById('kpiDrilldownDataTable');
        if (!table) {
            alert('Tidak ada data tabel yang dapat diunduh.');
            return;
        }

        var btn = document.getElementById('btnExportKpiExcel');
        var origHtml = btn ? btn.innerHTML : '';
        if (btn) {
            btn.innerHTML = '<svg class="w-3.5 h-3.5 animate-spin" style="display:inline-block;vertical-align:-2px;margin-right:4px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10" stroke-opacity="0.25"/><path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"/></svg>Menyiapkan...';
            btn.disabled = true;
        }

        // Ambil Header Tabel
        var headers = [];
        var thCols = table.querySelectorAll('thead th');
        thCols.forEach(function(th) {
            headers.push((th.innerText || th.textContent || '').trim());
        });

        // Ambil Baris Tabel (hanya baris yang tidak disembunyikan oleh filter pencarian)
        var rows = [];
        var trRows = table.querySelectorAll('tbody tr');
        trRows.forEach(function(tr) {
            if (tr.style.display === 'none') return;
            var tds = tr.querySelectorAll('td');
            if (!tds || !tds.length) return;
            var rowVals = [];
            tds.forEach(function(td) {
                rowVals.push((td.innerText || td.textContent || '').trim());
            });
            rows.push(rowVals);
        });

        if (!rows.length) {
            alert('Tidak ada baris data yang terlihat untuk diunduh.');
            if (btn) {
                btn.innerHTML = origHtml;
                btn.disabled = false;
            }
            return;
        }

        var titleEl = document.getElementById('kpiModalTitle');
        var subEl   = document.getElementById('kpiModalSubtitle');
        var perEl   = document.getElementById('kpiModalBadgePeriod');

        var form = document.createElement('form');
        form.method = 'POST';
        form.action = 'export_kpi_excel.php';
        form.style.display = 'none';

        var postData = {
            title: titleEl ? titleEl.textContent : 'Rincian KPI',
            subtitle: subEl ? subEl.textContent : '',
            period: perEl ? perEl.textContent : '',
            active_tab: activeKpiTab || '',
            stats: JSON.stringify((kpiDrilldownCache && kpiDrilldownCache.stats) ? kpiDrilldownCache.stats : []),
            headers: JSON.stringify(headers),
            rows: JSON.stringify(rows)
        };

        for (var k in postData) {
            if (postData.hasOwnProperty(k)) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = k;
                input.value = postData[k];
                form.appendChild(input);
            }
        }

        document.body.appendChild(form);
        form.submit();

        setTimeout(function() {
            document.body.removeChild(form);
            if (btn) {
                btn.innerHTML = origHtml;
                btn.disabled = false;
            }
        }, 1500);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // ── OQC DASHBOARD REALTIME LIVE ENGINE (SMART HEARTBEAT & EXPONENTIAL BACKOFF) ──
    // ══════════════════════════════════════════════════════════════════════════
    var isLiveSyncActive       = localStorage.getItem('oqc_live_sync_active') !== 'false';
    var liveSyncBaseDelayMs    = 10000; // 10 detik interval standar
    var liveSyncCurrentDelayMs = 10000;
    var liveSyncMaxDelayMs     = 60000; // Maksimum delay 60 detik saat terjadi error / gangguan server
    var liveSyncTimer          = null;
    var isFetchingLiveSync     = false;
    var isCheckingHeartbeat     = false;

    function scheduleNextHeartbeat(delay) {
        if (liveSyncTimer) {
            clearTimeout(liveSyncTimer);
            liveSyncTimer = null;
        }
        if (!isLiveSyncActive) return;
        var d = (typeof delay === 'number') ? delay : liveSyncCurrentDelayMs;
        liveSyncTimer = setTimeout(function() {
            checkLiveHeartbeat();
        }, d);
    }

    function updateLiveSyncToggleUI() {
        var btn = document.getElementById('btnToggleLiveSync');
        var dot = document.getElementById('dotLiveSyncIndicator');
        var txt = document.getElementById('txtLiveSyncStatus');
        if (!btn || !dot || !txt) return;

        if (isLiveSyncActive) {
            btn.style.background = '#ffffff';
            btn.style.borderColor = '#cbd5e1';
            btn.style.color = '#0f172a';
            dot.style.background = '#10b981';
            dot.style.boxShadow = '0 0 6px rgba(16,185,129,0.7)';
            txt.textContent = 'Live';
            btn.title = 'Realtime Aktif (Klik untuk Jeda/Pause)';
        } else {
            btn.style.background = '#fef3c7';
            btn.style.borderColor = '#fde68a';
            btn.style.color = '#92400e';
            dot.style.background = '#f59e0b';
            dot.style.boxShadow = 'none';
            txt.textContent = 'Paused';
            btn.title = 'Realtime Dijeda (Klik untuk Nyalakan Kembali)';
        }
    }

    function toggleLiveSyncState() {
        isLiveSyncActive = !isLiveSyncActive;
        localStorage.setItem('oqc_live_sync_active', isLiveSyncActive ? 'true' : 'false');
        updateLiveSyncToggleUI();

        if (isLiveSyncActive) {
            resetLiveSyncTimer();
            // Jalankan heartbeat segera saat kembali diaktifkan
            checkLiveHeartbeat();
        } else {
            if (liveSyncTimer) {
                clearInterval(liveSyncTimer);
                liveSyncTimer = null;
            }
        }
    }

    function animateValue(elemId, endVal) {
        var el = document.getElementById(elemId);
        if (!el) return;
        var startVal = parseInt(el.textContent.replace(/[^0-9]/g, '')) || 0;
        endVal = parseInt(endVal) || 0;
        if (startVal === endVal) return;

        var duration = 400;
        var startTime = performance.now();
        function step(currentTime) {
            var progress = Math.min((currentTime - startTime) / duration, 1);
            var current = Math.floor(progress * (endVal - startVal) + startVal);
            el.textContent = current.toLocaleString();
            if (progress < 1) {
                requestAnimationFrame(step);
            } else {
                el.textContent = endVal.toLocaleString();
            }
        }
        requestAnimationFrame(step);
    }

    function checkLiveHeartbeat() {
        if (!isLiveSyncActive || isFetchingLiveSync || isCheckingHeartbeat) return;
        if (document.hidden) return; // Tab sedang di background, hemat resource

        isCheckingHeartbeat = true;
        var q = new URLSearchParams(filterParams);
        q.set('v', currentDashboardVersion);

        var controller = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        var fetchTimeout = null;
        if (controller) {
            fetchTimeout = setTimeout(function() {
                controller.abort();
            }, 5000); // 5s timeout
        }

        fetch('ajax_heartbeat.php?' + q.toString(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            signal: controller ? controller.signal : undefined
        })
        .then(function(res) {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
        })
        .then(function(res) {
            if (res.status === 'success') {
                // Berhasil: reset delay kembali ke 10s normal
                liveSyncCurrentDelayMs = liveSyncBaseDelayMs;
                if (res.has_update) {
                    fetchLatestDashboardData();
                } else {
                    var timeEl = document.getElementById('liveSyncLastTime');
                    if (timeEl && res.server_time) {
                        timeEl.textContent = res.server_time;
                    }
                }
            } else {
                throw new Error(res.error || 'Heartbeat unsuccessful');
            }
        })
        .catch(function(err) {
            // Exponential backoff dengan jitter ±2 detik jika server error/terputus
            var jitter = Math.floor(Math.random() * 4000) - 2000;
            liveSyncCurrentDelayMs = Math.min(liveSyncCurrentDelayMs * 2, liveSyncMaxDelayMs) + jitter;
            liveSyncCurrentDelayMs = Math.max(liveSyncBaseDelayMs, liveSyncCurrentDelayMs);
        })
        .finally(function() {
            if (fetchTimeout) clearTimeout(fetchTimeout);
            isCheckingHeartbeat = false;
            if (isLiveSyncActive) {
                scheduleNextHeartbeat(liveSyncCurrentDelayMs);
            }
        });
    }

    function fetchLatestDashboardData(isForce) {
        if (isFetchingLiveSync) return;
        isFetchingLiveSync = true;

        var spinIcon = document.getElementById('iconLiveSyncSpin');
        if (spinIcon) spinIcon.classList.add('animate-spin');

        var q = new URLSearchParams(filterParams);
        if (filterParams.preset && filterParams.preset !== 'custom') {
            q.set('preset', filterParams.preset);
            if (q.get('filter_type') === 'custom') {
                q.delete('filter_type');
            }
        }
        q.set('sub_resolution', currentSubResolution || 'weekly');
        if (isForce) {
            q.set('force', '1');
            q.set('_t', Date.now());
        }

        var controller = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        var fetchTimeout = null;
        if (controller) {
            fetchTimeout = setTimeout(function() {
                controller.abort();
            }, 8000); // 8s timeout
        }

        fetch('ajax_get_data.php?' + q.toString(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            signal: controller ? controller.signal : undefined
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data && data.status === 'success') {
                saveDashboardCache(data);
                applyLiveDashboardData(data, false);
            }
        })
        .catch(function(err) {
            console.error('[OQC Live Sync] Data refresh failed:', err);
        })
        .finally(function() {
            if (fetchTimeout) clearTimeout(fetchTimeout);
            isFetchingLiveSync = false;
            if (spinIcon) spinIcon.classList.remove('animate-spin');
        });
    }

    function applyLiveDashboardData(d, skipAnim) {
        if (!d || d.status !== 'success') return;

        // 1. Update version token & clock
        if (d.version) {
            currentDashboardVersion = d.version;
        }
        var timeEl = document.getElementById('liveSyncLastTime');
        if (timeEl && d.server_time) {
            timeEl.textContent = d.server_time;
        }

        // 2. Animate KPI numbers
        if (d.kpi) {
            totalInspectedKpi = Number(d.kpi.total_inspected || 0);
            totalNgKpi = Number(d.kpi.total_ng || 0);

            animateValue('kpi-val-sample_inspected', d.kpi.total_inspected);
            animateValue('kpi-val-total_ng', d.kpi.total_ng);
            animateValue('kpi-val-total_lot', d.kpi.total_lot);
            animateValue('kpi-val-pass_reject_lot', isPassedMode ? d.kpi.pass_count : d.kpi.rejected_count);

            var isPct = (currentMetricScale === 'pct' || currentMetricScale === 'percent' || currentMetricScale === '%');
            var rateVal = isPct ? (d.kpi.pct_rate || 0) : (d.kpi.ppm_rate || 0);
            var elRate = document.getElementById('kpiMetricRateVal');
            if (elRate) {
                elRate.textContent = Number(rateVal).toLocaleString(undefined, {minimumFractionDigits: 1, maximumFractionDigits: isPct ? 2 : 1});
            }
        }

        // Update target PPM if provided
        if (d.target_ppm !== undefined) {
            globalTargetPpm = Number(d.target_ppm || 0);
            var lblTgt = document.getElementById('lblDisplayTargetPpm');
            if (lblTgt) {
                lblTgt.textContent = (globalTargetPpm > 0) ? Number(globalTargetPpm).toLocaleString() : 'Belum Diatur';
            }
        }

        // 3. Update Multi-Horizon Trend Chart (Gunakan update non-animasi agar tidak kedip terus-menerus)
        if (d.multi_horizon) {
            multiHorizonData = d.multi_horizon;
            var subDescEl = document.getElementById('trendChartSubDesc');
            var custName = (filterParams && filterParams.customer) ? filterParams.customer : '';
            if (subDescEl && d.multi_horizon.sub_desc) {
                subDescEl.textContent = d.multi_horizon.sub_desc + (custName !== '' ? (' · Customer: ' + custName) : '');
            }
            initTrendChart(currentChartType, currentMetricScale, true);
        }

        // 4. Update Stacked Defect Timeline Chart & Sync Global Memory Config
        if (d.stacked) {
            if (window.OQC_DASH_CONFIG) {
                if (Array.isArray(d.stacked.labels) && d.stacked.labels.length > 0) {
                    window.OQC_DASH_CONFIG.stackedLabels = d.stacked.labels;
                }
                if (d.stacked.datasets_ppm) {
                    window.OQC_DASH_CONFIG.stackedDatasetsPpm = d.stacked.datasets_ppm;
                }
                if (d.stacked.datasets_pcs) {
                    window.OQC_DASH_CONFIG.stackedDatasetsPcs = d.stacked.datasets_pcs;
                }
            }

            if (defectStackedChartInstance) {
                if (Array.isArray(d.stacked.labels) && d.stacked.labels.length > 0) {
                    defectStackedChartInstance.data.labels = d.stacked.labels;
                }
                defectStackedChartInstance.data.datasets = (currentDefectMetricUnit === 'ppm') ? d.stacked.datasets_ppm : d.stacked.datasets_pcs;
                defectStackedChartInstance.update('none');
                if (typeof renderDefectStackedLegend === 'function') {
                    renderDefectStackedLegend(defectStackedChartInstance.data.datasets);
                }
            } else if (typeof initDefectStackedChart === 'function') {
                initDefectStackedChart();
            }
        }

        // 5. Update Donut Charts & Legends (hanya jika cascade filter tidak sedang aktif agar filter user tidak tertimpa)
        var isCascadingActive = (typeof cascadeActiveFilters !== 'undefined' && (cascadeActiveFilters.defect || cascadeActiveFilters.model || cascadeActiveFilters.part));
        
        if (!isCascadingActive) {
            if (d.defect_types && defectDoughnutChartInstance) {
                defectDoughnutChartInstance.data.labels = d.defect_types.labels;
                if (defectDoughnutChartInstance.data.datasets[0]) {
                    defectDoughnutChartInstance.data.datasets[0].data = d.defect_types.counts;
                }
                defectDoughnutChartInstance.update('none');
                updateDefectLegend(d.defect_types);
            }

            if (d.pie_model && modelPieChartInstance) {
                var isFreq = (currentModelMetricMode === 'freq');
                var pcsCounts = d.pie_model.counts || [];
                var casesCounts = d.pie_model.cases || [];

                modelPieChartInstance.data.labels = d.pie_model.labels;
                if (modelPieChartInstance.data.datasets[0]) {
                    modelPieChartInstance.data.datasets[0].raw_pcs = pcsCounts;
                    modelPieChartInstance.data.datasets[0].cases_freq = casesCounts;
                    modelPieChartInstance.data.datasets[0].is_freq = isFreq;
                    modelPieChartInstance.data.datasets[0].data = isFreq ? casesCounts : pcsCounts;
                }
                modelPieChartInstance.update('none');
                updateModelLegend(d.pie_model);
            }

            if (d.pie_part && partPieChartInstance) {
                partPieChartInstance.data.labels = d.pie_part.labels;
                if (partPieChartInstance.data.datasets[0]) {
                    partPieChartInstance.data.datasets[0].data = d.pie_part.counts;
                }
                partPieChartInstance.update('none');
                updatePartLegend(d.pie_part);
            }
        }

        // 6. Update Worst / Best Parts Section
        if (d.worst_parts) {
            updateWorstBestPartsSection(d.worst_parts);
        }
    }

    function updateDefectLegend(dt) {
        var elTotal = document.getElementById('donutDefectTotalVal');
        var elList  = document.getElementById('defectTypeLegendContainer');
        var counts  = dt.counts || [];
        var labels  = dt.labels || [];
        var totNg   = (typeof totalNgKpi !== 'undefined' && totalNgKpi > 0) ? totalNgKpi : counts.reduce(function(a, b) { return a + b; }, 0);
        if (elTotal) elTotal.textContent = totNg.toLocaleString();
        if (!elList) return;

        var denom = (typeof totalInspectedKpi !== 'undefined' && totalInspectedKpi > 0) ? totalInspectedKpi : 1;
        var html = '';
        var pal = isPassedMode ? ['#059669', '#dc2626', '#3b82f6', '#f59e0b', '#8b5cf6', '#06b6d4', '#10b981', '#6366f1'] : ['#ef4444', '#f59e0b', '#8b5cf6', '#06b6d4', '#ec4899', '#3b82f6', '#10b981', '#f97316', '#64748b', '#14b8a6'];
        labels.forEach(function(l, i) {
            var c = counts[i] || 0;
            var pct = ((c / denom) * 100).toFixed(2);
            var col = pal[i % pal.length];
            var safeLbl = (l || '').replace(/'/g, "\\'");
            var isActive = (typeof cascadeActiveFilters !== 'undefined' && cascadeActiveFilters.defect && cascadeActiveFilters.defect === l);
            var activeBg = isActive ? '#fef2f2' : '#f8fafc';
            var activeBorder = isActive ? '#f87171' : 'transparent';

            html += '<div onclick="handleDonutLegendClick(\'defect\', \'' + safeLbl + '\')" data-legend-type="defect" data-legend-name="' + escapeHtml(l) + '" style="display:flex;align-items:center;justify-content:space-between;font-size:10.5px;gap:6px;padding:4px 8px;border-radius:6px;background:' + activeBg + ';border-left:3.5px solid ' + col + ';border-top:1px solid ' + activeBorder + ';border-right:1px solid ' + activeBorder + ';border-bottom:1px solid ' + activeBorder + ';cursor:pointer;transition:all 0.15s ease-in-out;" class="hover:bg-red-50 hover:shadow-xs group">' +
                    '<span style="display:flex;align-items:center;gap:5px;color:#334155;flex:1;min-width:0;">' +
                    '<span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:700;">' + escapeHtml(l) + '</span>' +
                    '</span>' +
                    '<span style="font-weight:800;color:#0f172a;flex-shrink:0;">' + c.toLocaleString() + ' <span style="font-weight:500;color:#64748b;">' + activeUnit.toLowerCase() + ' (' + pct + '%)</span></span>' +
                    '</div>';
        });
        elList.innerHTML = html;
        if (typeof updateCascadeBadgesUI === 'function') updateCascadeBadgesUI();
    }

    function updateModelLegend(mp) {
        var elTotal = document.getElementById('donutModelTotalVal');
        var elList  = document.getElementById('modelLegendContainer');
        var counts  = mp.counts || [];
        var cases   = mp.cases || [];
        var labels  = mp.labels || [];
        if (elTotal) {
            var totM = (typeof mp.total_distinct !== 'undefined') ? mp.total_distinct : (typeof initialTotalModelsNg !== 'undefined' ? initialTotalModelsNg : labels.length);
            elTotal.textContent = totM;
        }
        var elQtyM = document.getElementById('donutModelQtyVal');
        if (elQtyM) {
            var isFreqMode = (currentModelMetricMode === 'freq');
            var totValM = (isFreqMode ? cases : counts).reduce(function(a, b) { return a + Number(b || 0); }, 0);
            elQtyM.textContent = '(' + totValM.toLocaleString() + ' ' + (isFreqMode ? 'kali' : activeUnit.toLowerCase()) + ')';
        }
        if (!elList) return;

        var isFreq = (currentModelMetricMode === 'freq');
        var denom = (typeof totalInspectedKpi !== 'undefined' && totalInspectedKpi > 0) ? totalInspectedKpi : 1;
        var html = '';
        var pal = ['#6366f1', '#0ea5e9', '#3b82f6', '#8b5cf6', '#475569', '#06b6d4', '#2563eb', '#1e293b', '#64748b', '#0284c7'];
        labels.forEach(function(l, i) {
            var c = counts[i] || 0;
            var cas = cases[i] || 0;
            var pct = ((c / denom) * 100).toFixed(2);
            var col = pal[i % pal.length];
            var safeLbl = (l || '').replace(/'/g, "\\'");
            var isActive = (typeof cascadeActiveFilters !== 'undefined' && cascadeActiveFilters.model && cascadeActiveFilters.model === l);
            var activeBg = isActive ? '#e0e7ff' : '#f8fafc';
            var activeBorder = isActive ? '#818cf8' : 'transparent';

            html += '<div onclick="handleDonutLegendClick(\'model\', \'' + safeLbl + '\')" data-legend-type="model" data-legend-name="' + escapeHtml(l) + '" data-pcs="' + c + '" data-cases="' + cas + '" data-pct="' + pct + '" style="display:flex;align-items:center;justify-content:space-between;font-size:10.5px;gap:6px;padding:4px 8px;border-radius:6px;background:' + activeBg + ';border-left:3.5px solid ' + col + ';border-top:1px solid ' + activeBorder + ';border-right:1px solid ' + activeBorder + ';border-bottom:1px solid ' + activeBorder + ';cursor:pointer;transition:all 0.15s ease-in-out;" class="hover:bg-indigo-50 hover:shadow-xs group">' +
                    '<span style="display:flex;align-items:center;gap:5px;color:#334155;flex:1;min-width:0;">' +
                    '<span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:700;">' + escapeHtml(l) + '</span>' +
                    '</span>';
            if (isFreq) {
                html += '<span class="model-legend-val" style="font-weight:800;color:#0f172a;flex-shrink:0;">' + cas.toLocaleString() + ' <span style="font-weight:500;color:#64748b;">kali</span></span>';
            } else {
                html += '<span class="model-legend-val" style="font-weight:800;color:#0f172a;flex-shrink:0;">' + c.toLocaleString() + ' <span style="font-weight:500;color:#64748b;">' + activeUnit.toLowerCase() + ' (' + pct + '%)</span></span>';
            }
            html += '</div>';
        });
        elList.innerHTML = html;
        if (typeof updateCascadeBadgesUI === 'function') updateCascadeBadgesUI();
    }

    function updatePartLegend(pp) {
        var elTotal = document.getElementById('donutPartTotalVal');
        var elList  = document.getElementById('partLegendContainer');
        var counts  = pp.counts || [];
        var labels  = pp.labels || [];
        if (elTotal) {
            var totP = (typeof pp.total_distinct !== 'undefined') ? pp.total_distinct : (typeof initialTotalPartsNg !== 'undefined' ? initialTotalPartsNg : labels.length);
            elTotal.textContent = totP;
        }
        var elQtyP = document.getElementById('donutPartQtyVal');
        if (elQtyP) {
            var totValP = counts.reduce(function(a, b) { return a + Number(b || 0); }, 0);
            elQtyP.textContent = '(' + totValP.toLocaleString() + ' ' + activeUnit.toLowerCase() + ')';
        }
        if (!elList) return;

        var denom = (typeof totalInspectedKpi !== 'undefined' && totalInspectedKpi > 0) ? totalInspectedKpi : 1;
        var html = '';
        var pal = ['#0d9488', '#10b981', '#f59e0b', '#f43f5e', '#7c3aed', '#0284c7', '#d97706', '#64748b', '#ec4899', '#3b82f6'];
        labels.forEach(function(l, i) {
            var c = counts[i] || 0;
            var pct = ((c / denom) * 100).toFixed(2);
            var col = pal[i % pal.length];
            var safeLbl = (l || '').replace(/'/g, "\\'");
            var isActive = (typeof cascadeActiveFilters !== 'undefined' && cascadeActiveFilters.part && cascadeActiveFilters.part === l);
            var activeBg = isActive ? '#ccfbf1' : '#f8fafc';
            var activeBorder = isActive ? '#2dd4bf' : 'transparent';

            html += '<div onclick="handleDonutLegendClick(\'part\', \'' + safeLbl + '\')" data-legend-type="part" data-legend-name="' + escapeHtml(l) + '" style="display:flex;align-items:center;justify-content:space-between;font-size:10.5px;gap:6px;padding:4px 8px;border-radius:6px;background:' + activeBg + ';border-left:3.5px solid ' + col + ';border-top:1px solid ' + activeBorder + ';border-right:1px solid ' + activeBorder + ';border-bottom:1px solid ' + activeBorder + ';cursor:pointer;transition:all 0.15s ease-in-out;" class="hover:bg-teal-50 hover:shadow-xs group">' +
                    '<span style="display:flex;align-items:center;gap:5px;color:#334155;flex:1;min-width:0;">' +
                    '<span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:700;">' + escapeHtml(l) + '</span>' +
                    '</span>' +
                    '<span style="font-weight:800;color:#0f172a;flex-shrink:0;">' + c.toLocaleString() + ' <span style="font-weight:500;color:#64748b;">' + activeUnit.toLowerCase() + ' (' + pct + '%)</span></span>' +
                    '</div>';
        });
        elList.innerHTML = html;
        if (typeof updateCascadeBadgesUI === 'function') updateCascadeBadgesUI();
    }

    function updateWorstBestPartsSection(wb) {
        var wrapper = document.getElementById('sectionWorstBestPartsContainer');
        if (!wrapper) return;

        var html = '';
        if (wb.is_passed) {
            html += '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid #f1f5f9;">' +
                    '<div><h2 style="font-size:14px;font-weight:900;color:#1e293b;letter-spacing:.02em;text-transform:uppercase;margin:0;display:flex;align-items:center;gap:6px;"><span style="color:#059669;"></span> BEST PERFORMANCE PART OQC</h2></div>' +
                    '<span style="font-size:11px;font-weight:600;color:#059669;">Top Part &amp; Model Performa Lulus Terbaik</span>' +
                    '</div>';

            var best = wb.best || [];
            if (best.length === 0) {
                html += '<div style="padding:30px 0;text-align:center;color:#cbd5e1;font-size:11px;background:#f8fafc;border-radius:8px;border:1px dashed #e2e8f0;">Belum ada data part lulus pada periode ini.</div>';
            } else {
                html += '<div style="overflow-x:auto;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:12px;">' +
                        '<table style="width:100%;border-collapse:collapse;font-size:11px;text-align:left;">' +
                        '<thead><tr style="background:#065f46;color:#ffffff;font-weight:800;font-size:10px;text-transform:uppercase;letter-spacing:.05em;">' +
                        '<th style="padding:8px 12px;text-align:center;width:45px;">NO</th><th style="padding:8px 12px;">PART NAME</th><th style="padding:8px 12px;">PART CODE</th><th style="padding:8px 12px;">MODEL</th><th style="padding:8px 12px;text-align:center;">LOT CASE (X)</th><th style="padding:8px 12px;text-align:center;">PASSED CASE</th>' +
                        '</tr></thead><tbody>' +
                        '<tr style="background:#ecfdf5;border-top:1px solid #a7f3d0;border-bottom:1px solid #a7f3d0;"><td colspan="6" style="padding:6px 12px;font-weight:900;color:#047857;font-size:11px;text-transform:uppercase;letter-spacing:.04em;">TOP PASSED PART &amp; MODEL PERFORMANCE</td></tr>';

                best.forEach(function(b, idx) {
                    html += '<tr style="border-bottom:1px solid #f1f5f9;" class="hover:bg-slate-50">' +
                            '<td style="padding:8px 12px;text-align:center;font-weight:700;color:#94a3b8;">' + (idx + 1) + '</td>' +
                            '<td style="padding:8px 12px;font-weight:800;color:#0f172a;">' + escapeHtml(b.part_name) + '</td>' +
                            '<td style="padding:8px 12px;font-family:monospace;color:#475569;">' + escapeHtml(b.part_code) + '</td>' +
                            '<td style="padding:8px 12px;color:#475569;">' + escapeHtml(b.model) + '</td>' +
                            '<td style="padding:8px 12px;text-align:center;font-family:monospace;font-weight:700;color:#334155;">' + Number(b.lot_case || 0).toLocaleString() + '</td>' +
                            '<td style="padding:8px 12px;text-align:center;font-family:monospace;font-weight:900;color:#059669;background:#ecfdf5;">' + Number(b.passed_lot_case || 0).toLocaleString() + '</td>' +
                            '</tr>';
                });
                html += '</tbody></table></div>';
                html += '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:10px 14px;"><span style="font-size:12px;font-weight:900;color:#166534;">Conclusion : </span><span style="font-size:12px;font-weight:700;color:#15803d;">3 best performing parts are <span style="font-weight:900;color:#0f172a;">' + escapeHtml(wb.summary_str) + '</span></span></div>';
            }
        } else {
            html += '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid #f1f5f9;">' +
                    '<div><h2 style="font-size:14px;font-weight:900;color:#1e293b;letter-spacing:.02em;text-transform:uppercase;margin:0;display:flex;align-items:center;gap:6px;"><span style="color:#2563eb;"></span> WORST PART OQC</h2></div>' +
                    '<span style="font-size:11px;font-weight:600;color:#94a3b8;">Breakdown per Defect Area</span>' +
                    '</div>';

            var grouped = wb.grouped || {};
            var keys = Object.keys(grouped);
            if (keys.length === 0) {
                html += '<div style="padding:30px 0;text-align:center;color:#cbd5e1;font-size:11px;background:#f8fafc;border-radius:8px;border:1px dashed #e2e8f0;">Belum ada data part terburuk pada periode ini.</div>';
            } else {
                html += '<div style="overflow-x:auto;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:12px;">' +
                        '<table style="width:100%;border-collapse:collapse;font-size:11px;text-align:left;">' +
                        '<thead><tr style="background:#1e293b;color:#ffffff;font-weight:800;font-size:10px;text-transform:uppercase;letter-spacing:.05em;">' +
                        '<th style="padding:8px 12px;text-align:center;width:45px;">NO</th><th style="padding:8px 12px;">PART NAME</th><th style="padding:8px 12px;">PART CODE</th><th style="padding:8px 12px;">MODEL</th><th style="padding:8px 12px;text-align:center;">LOT NG (X)</th><th style="padding:8px 12px;text-align:center;">QTY NG (PCS)</th>' +
                        '</tr></thead><tbody>';

                keys.forEach(function(dCat) {
                    html += '<tr style="background:#fff1f2;border-top:1px solid #fecdd3;border-bottom:1px solid #fecdd3;"><td colspan="6" style="padding:6px 12px;font-weight:900;color:#be123c;font-size:11px;text-transform:uppercase;letter-spacing:.04em;">' + escapeHtml(dCat) + '</td></tr>';
                    var pList = grouped[dCat] || [];
                    pList.forEach(function(p, idx) {
                        html += '<tr style="border-bottom:1px solid #f1f5f9;" class="hover:bg-slate-50">' +
                                '<td style="padding:8px 12px;text-align:center;font-weight:700;color:#94a3b8;">' + (idx + 1) + '</td>' +
                                '<td style="padding:8px 12px;font-weight:800;color:#0f172a;">' + escapeHtml(p.part_name) + '</td>' +
                                '<td style="padding:8px 12px;font-family:monospace;color:#475569;">' + escapeHtml(p.part_code) + '</td>' +
                                '<td style="padding:8px 12px;color:#475569;">' + escapeHtml(p.model) + '</td>' +
                                '<td style="padding:8px 12px;text-align:center;font-family:monospace;font-weight:700;color:#334155;">' + Number(p.lot_case || 0).toLocaleString() + '</td>' +
                                '<td style="padding:8px 12px;text-align:center;font-family:monospace;font-weight:900;color:#dc2626;background:#fff1f2;">' + Number(p.ng_case || 0).toLocaleString() + '</td>' +
                                '</tr>';
                    });
                });
                html += '</tbody></table></div>';
                html += '<div style="background:#eff6ff;border:1px solid #dbeafe;border-radius:10px;padding:10px 14px;"><span style="font-size:12px;font-weight:900;color:#1e40af;">Conclusion : </span><span style="font-size:12px;font-weight:700;color:#1d4ed8;">3 worst parts are <span style="font-weight:900;color:#0f172a;">' + escapeHtml(wb.summary_str) + '</span></span></div>';
            }
        }
        wrapper.innerHTML = html;
    }

    function resetLiveSyncTimer() {
        if (liveSyncTimer) {
            clearTimeout(liveSyncTimer);
            liveSyncTimer = null;
        }
        liveSyncCurrentDelayMs = liveSyncBaseDelayMs;
        if (isLiveSyncActive) {
            scheduleNextHeartbeat(liveSyncBaseDelayMs);
        }
    }

    function manualSyncNow() {
        var btn = document.getElementById('btnLiveSyncManual');
        if (btn) {
            btn.style.opacity = '0.6';
            btn.style.pointerEvents = 'none';
        }
        // Force refresh langsung ke server (bypass server query cache & client cache)
        fetchLatestDashboardData(true);
        setTimeout(function() {
            if (btn) {
                btn.style.opacity = '1';
                btn.style.pointerEvents = 'auto';
            }
        }, 1200);
    }

    document.addEventListener('visibilitychange', function() {
        if (!document.hidden && isLiveSyncActive) {
            checkLiveHeartbeat();
        }
    });

    // ── Instant Cache Hydration & Start Realtime Live Sync ────────────────────
    (function initDashboardCacheAndRealtime() {
        // Musnahkan seluruh cache dashboard versi lama / kedaluwarsa seketika
        purgeOldDashboardVersions();

        updateLiveSyncToggleUI();
        var cached = loadDashboardCache();
        if (cached && cached.data) {
            // Jika ada cache lokal, terapkan seketika dalam 0 ms
            applyLiveDashboardData(cached.data, true);
        }
        if (isLiveSyncActive) {
            resetLiveSyncTimer();
        }
    })();
