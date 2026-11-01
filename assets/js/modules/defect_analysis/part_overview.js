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
        var signal = getPartOverviewSignal();

        fetch(url, { signal: signal })
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
                if (isAbortError(err)) return;
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
        renderPartTop3SummaryPills(validItems, unitLabel);
    }

    // ── Helper Render Top 3 Part Menjejer (Mode Halaman Web & Kop Cetak) ─────
    function renderPartTop3SummaryPills(items, unitLabel) {
        var top3 = (items || []).slice(0, 3);
        var unitDisplay = (unitLabel || (funnelState.unit || 'pcs')).toUpperCase();

        var buildPillsHtml = function(isPrint) {
            if (!top3 || top3.length === 0) {
                return '<span style="font-size:10px;color:#94a3b8;font-style:italic;">Tidak ada data</span>';
            }
            var h = '<div style="display:flex;align-items:center;gap:4px;flex-wrap:wrap;">' +
                    '<span style="font-size:' + (isPrint ? '9.5px' : '10.5px') + ';font-weight:800;color:#64748b;margin-right:2px;display:flex;align-items:center;gap:3px;">' +
                    '  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>' +
                    '  Top 3:</span>';

            top3.forEach(function(it, idx) {
                var rank = idx + 1;
                var rawVal = Number(it.val || 0);
                var formattedVal = (funnelState.unit === 'ppm' && (rawVal % 1 !== 0))
                    ? rawVal.toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 })
                    : Math.round(rawVal).toLocaleString('id-ID');

                var medalBg = (rank === 1) ? '#fef3c7' : (rank === 2 ? '#f1f5f9' : '#ffedd5');
                var medalText = (rank === 1) ? '#92400e' : (rank === 2 ? '#334155' : '#9a3412');
                var medalBorder = (rank === 1) ? '#f59e0b' : (rank === 2 ? '#cbd5e1' : '#ea580c');
                var medalIcon = (rank === 1) ? '🥇' : (rank === 2 ? '🥈' : '🥉');

                var partCode = it.part_code || it.item_name || '-';
                var partName = it.part_name || '';

                var cursor = isPrint ? 'default' : 'pointer';
                var clickAttr = isPrint ? '' : 'onclick="selectPartOverviewItem(' + it.item_id + ', \'' + escapeQuotes(partCode) + '\', \'' + escapeQuotes(partName) + '\')" title="Klik untuk fokus analisis part ini"';

                h += '<div ' + clickAttr + ' style="display:inline-flex;align-items:center;gap:4px;padding:' + (isPrint ? '1.5px 6px' : '2.5px 8px') + ';background:' + medalBg + ';border:1px solid ' + medalBorder + ';border-radius:6px;font-size:' + (isPrint ? '9.5px' : '11px') + ';cursor:' + cursor + ';transition:all 0.15s;user-select:none;">' +
                     '  <span style="font-weight:900;color:' + medalText + ';">' + medalIcon + ' #' + rank + '</span>' +
                     '  <strong style="color:#0f172a;font-family:monospace;max-width:' + (isPrint ? '110px' : '130px') + ';overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + escapeHtml(partCode) + '</strong>' +
                     '  <span style="font-weight:800;color:' + medalText + ';background:#ffffff;padding:0.5px 5px;border-radius:4px;border:1px solid ' + medalBorder + ';font-size:' + (isPrint ? '9px' : '10px') + ';">' + formattedVal + ' ' + unitDisplay + '</span>' +
                     '</div>';
            });
            h += '</div>';
            return h;
        };

        // Render ke container halaman web
        var pageContainer = document.getElementById('partOverviewTop3PillsContainer');
        if (pageContainer) {
            pageContainer.innerHTML = buildPillsHtml(false);
        }

        // Render ke Kop Cetak
        var printContainer = document.getElementById('printReportTop3Defects');
        if (printContainer && currentAnalysisMode === 'part_overview') {
            printContainer.innerHTML = buildPillsHtml(true);
        }
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

        var grpDaily = document.getElementById('partOverviewDailyModeGroup');
        if (grpDaily) {
            var isMonthly = (funnelState.preset === 'bulanan' || funnelState.filterType === 'month_year' || funnelState.preset === 'month_year');
            grpDaily.style.display = (isMonthly && interval === 'daily') ? 'inline-flex' : 'none';
        }

        loadPartOverviewTrend();
    }

    function switchPartOverviewDailyMode(mode) {
        partOverviewState.dailyMode = mode;
        var btnWork = document.getElementById('btnPartOverviewDailyWorkCycle');
        var btnFull = document.getElementById('btnPartOverviewDailyFullMonth');

        if (btnWork && btnFull) {
            if (mode === 'work_cycle') {
                btnWork.style.background = '#0f172a';
                btnWork.style.color = '#ffffff';
                btnWork.style.boxShadow = '0 1px 2px rgba(15,23,42,0.2)';
                btnFull.style.background = 'transparent';
                btnFull.style.color = '#64748b';
                btnFull.style.boxShadow = 'none';
            } else {
                btnFull.style.background = '#0f172a';
                btnFull.style.color = '#ffffff';
                btnFull.style.boxShadow = '0 1px 2px rgba(15,23,42,0.2)';
                btnWork.style.background = 'transparent';
                btnWork.style.color = '#64748b';
                btnWork.style.boxShadow = 'none';
            }
        }

        loadPartOverviewTrend();
    }

    function switchPartOverviewResolution(res) {
        partOverviewState.subResolution = res;
        var btnWeekly = document.getElementById('btnPartOverviewResWeekly');
        var btnDaily  = document.getElementById('btnPartOverviewResDaily');

        if (btnWeekly && btnDaily) {
            if (res === 'weekly') {
                btnWeekly.style.background = '#0f172a';
                btnWeekly.style.color = '#ffffff';
                btnWeekly.style.boxShadow = '0 1px 2px rgba(15,23,42,0.2)';
                btnDaily.style.background = 'transparent';
                btnDaily.style.color = '#64748b';
                btnDaily.style.boxShadow = 'none';
            } else {
                btnDaily.style.background = '#0f172a';
                btnDaily.style.color = '#ffffff';
                btnDaily.style.boxShadow = '0 1px 2px rgba(15,23,42,0.2)';
                btnWeekly.style.background = 'transparent';
                btnWeekly.style.color = '#64748b';
                btnWeekly.style.boxShadow = 'none';
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

        if (partOverviewState.trendMode === 'multi_horizon') {
            if (partOverviewState.cachedMultiHorizonData) {
                renderPartOverviewMultiHorizonChart(partOverviewState.cachedMultiHorizonData);
            } else {
                loadPartOverviewTrend();
            }
        } else {
            if (partOverviewState.cachedTrendData) {
                renderPartOverviewTrendChart(partOverviewState.cachedTrendData);
            }
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

        // Tampilkan/sembunyikan sub-toggle (Week vs Harian) & rank filter jika mode garis/batang
        var isTimeline = (type === 'line_trend' || type === 'bar_trend');
        var subResGrp = document.getElementById('partOverviewDefectSubResGroup');
        if (subResGrp) {
            var isMonthly = (funnelState.preset === 'bulanan' || funnelState.filterType === 'month_year' || funnelState.preset === 'month_year');
            subResGrp.style.display = (isMonthly && isTimeline) ? 'inline-flex' : 'none';
        }
        var rankGrp = document.getElementById('partOverviewDefectRankGroup');
        if (rankGrp) {
            rankGrp.style.display = isTimeline ? 'inline-flex' : 'none';
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

    function switchPartDefectTrendRank(rank) {
        partOverviewState.defectTopRank = rank;
        var btn3   = document.getElementById('btnPartDefectRank3');
        var btn5   = document.getElementById('btnPartDefectRank5');
        var btnAll = document.getElementById('btnPartDefectRankAll');
        var allRanks = [
            { btn: btn3, val: 3 },
            { btn: btn5, val: 5 },
            { btn: btnAll, val: 'all' }
        ];

        allRanks.forEach(function(item) {
            if (item.btn) {
                if (item.val === rank) {
                    item.btn.style.background = '#0f172a';
                    item.btn.style.color      = '#ffffff';
                    item.btn.style.boxShadow  = '0 1px 2px rgba(15,23,42,0.2)';
                } else {
                    item.btn.style.background = 'transparent';
                    item.btn.style.color      = '#64748b';
                    item.btn.style.boxShadow  = 'none';
                }
            }
        });

        loadPartOverviewDefectsTimeline();
    }

    function loadPartOverviewTrend() {
        if (!partOverviewState.selectedPartId) return;

        var signal = currentPartOverviewAbortController ? currentPartOverviewAbortController.signal : null;
        var fetchOpts = signal ? { signal: signal } : {};

        // Parallel fetch threshold value for part
        var threshUrl = buildApiUrl('get_threshold', {
            mode: 'part_overview',
            item_id: partOverviewState.selectedPartId
        });

        if (partOverviewState.trendMode === 'multi_horizon') {
            var urlHorizon = buildApiUrl('get_multi_horizon_trend', {
                part_id: partOverviewState.selectedPartId,
                sub_resolution: partOverviewState.subResolution || 'weekly'
            });

            Promise.all([
                fetch(urlHorizon, fetchOpts).then(function(res) { return res.json(); }),
                fetch(threshUrl, fetchOpts).then(function(res) { return res.json(); }).catch(function() { return { success: false }; })
            ])
            .then(function(results) {
                var data = results[0];
                var threshData = results[1];

                if (!data.success) return;
                partOverviewState.cachedMultiHorizonData = data;

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

                // Update subtitle keterangan interval
                var elDesc = document.getElementById('partOverviewTrendIntervalDesc');
                if (elDesc) {
                    var mName = (data.meta ? data.meta.monthName : '');
                    if (partOverviewState.subResolution === 'daily') {
                        elDesc.textContent = 'Komparasi makro-ke-mikro: Tahunan (Tahun dengan Data) → Bulanan (Tahun Berjalan) → Harian Siklus Kerja (' + mName + ').';
                    } else {
                        elDesc.textContent = 'Komparasi makro-ke-mikro: Tahunan (Tahun dengan Data) → Bulanan (Tahun Berjalan) → Mingguan (' + mName + ').';
                    }
                }

                renderPartOverviewMultiHorizonChart(data);
            })
            .catch(function(err) {
                if (isAbortError(err)) return;
                console.error("Error loading part multi-horizon trend:", err);
            });
            return;
        }

        // Fallback Mode Timeline Harian jika mode biasa
        var url = buildApiUrl('get_trend_timeline', {
            part_id: partOverviewState.selectedPartId,
            trend_interval: 'daily',
            daily_mode: partOverviewState.dailyMode || 'work_cycle'
        });

        Promise.all([
            fetch(url, fetchOpts).then(function(res) { return res.json(); }),
            fetch(threshUrl, fetchOpts).then(function(res) { return res.json(); }).catch(function() { return { success: false }; })
        ])
        .then(function(results) {
            var data = results[0];
            var threshData = results[1];

            if (!data.success) return;
            partOverviewState.cachedTrendData = data;

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

            if (partOverviewState.cachedDefectsData && partOverviewState.defectChartType === 'table') {
                renderPartOverviewDefectsChart(partOverviewState.cachedDefectsData);
            }

            renderPartOverviewTrendChart(data);
        })
        .catch(function(err) {
            if (isAbortError(err)) return;
            console.error("Error loading part overview trend:", err);
        });
    }

    function renderPartOverviewMultiHorizonChart(data) {
        var canvas = document.getElementById('chartPartOverviewTrend');
        if (!canvas) return;
        if (chartPartOverviewTrendInstance) chartPartOverviewTrendInstance.destroy();

        var ctx = canvas.getContext('2d');
        var hasBar = (partOverviewState.trendChartType === 'bar');
        var hasLine = (partOverviewState.trendChartType === 'bar' || partOverviewState.trendChartType === 'line');
        var unitLabel = (data.meta && data.meta.unitLabel) ? data.meta.unitLabel : 'Pcs';
        var labels = data.labels || [];
        var counts = data.counts || [];
        var barColors = data.barColors || [];
        var borderColors = data.borderColors || [];
        var tooltips = data.tooltips || [];
        var sections = data.sections || [];

        var breakdown = data.breakdown || [];
        var activeUnitCode = (funnelState.unit || 'pcs').toLowerCase();

        // Boundary indices antar seksi untuk memutus garis tren
        var boundaryIndices = [];
        if (sections && sections.length > 1) {
            for (var s = 0; s < sections.length - 1; s++) {
                boundaryIndices.push(sections[s].endIndex);
            }
        }

        var isDense = (partOverviewState.subResolution === 'daily' || labels.length > 25);
        var datasets = [];

        // 1. DATASET BATANG (Tampil saat mode 'bar')
        if (hasBar) {
            datasets.push({
                type: 'bar',
                label: 'Temuan (' + unitLabel + ')',
                data: counts,
                borderColor: borderColors,
                backgroundColor: barColors,
                borderWidth: 1,
                borderRadius: isDense ? 2 : 4,
                barPercentage: isDense ? 0.75 : 0.65,
                categoryPercentage: isDense ? 0.90 : 0.85,
                order: 3
            });
        }

        // 2. DATASET GARIS TREN (Tampil saat mode 'bar' maupun 'line')
        if (hasLine) {
            datasets.push({
                type: 'line',
                label: hasBar ? ('Tren Garis (' + unitLabel + ')') : ('Temuan (' + unitLabel + ')'),
                data: counts,
                borderColor: '#2563eb',
                backgroundColor: 'rgba(37,99,235,0.08)',
                borderWidth: 2.5,
                segment: {
                    borderColor: function(ctxSeg) {
                        var p0 = ctxSeg.p0DataIndex;
                        // Putus garis jika melintasi pembatas seksi antar zona
                        if (boundaryIndices.indexOf(p0) !== -1) {
                            return 'transparent';
                        }
                        return barColors[p0] || '#2563eb';
                    }
                },
                pointBackgroundColor: barColors.map(function(c) { return c || '#2563eb'; }),
                pointBorderColor: '#ffffff',
                pointBorderWidth: 2,
                pointRadius: hasBar ? (isDense ? 3 : 4.5) : (isDense ? 3 : 4),
                pointHoverRadius: 7,
                fill: false,
                tension: 0.25,
                order: 2
            });
        }

        // 3. Batas Ambang / Target Maksimal jika ada
        if (partOverviewState.threshold !== null && partOverviewState.threshold !== undefined && labels.length > 0) {
            var targetVal = partOverviewState.threshold;
            var targetData = labels.map(function() { return targetVal; });
            datasets.push({
                type: 'line',
                label: 'Batas Target: ' + targetVal.toLocaleString() + ' ' + unitLabel,
                data: targetData,
                borderColor: 'transparent',
                backgroundColor: '#dc2626',
                borderWidth: 0,
                pointRadius: 0,
                pointHoverRadius: 0,
                fill: false,
                tension: 0,
                order: 1
            });
        }

        // Plugin garis pemisah vertikal presisi antar zona horizon & Garis Target Full Width
        var dividerPluginPart = {
            id: 'multiHorizonSectionDividerPart',
            afterDatasetsDraw: function(chart) {
                var chartCtx = chart.ctx;
                var xAxis = chart.scales.x;
                var yAxis = chart.scales.y;
                var chartArea = chart.chartArea;
                if (!chartArea || !xAxis || !yAxis) return;

                // 1. Garis Vertikal Pemisah Antar Seksi
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

                // 2. Garis Merah Batas Target Full Width
                if (partOverviewState.threshold !== null && partOverviewState.threshold !== undefined && labels.length > 0) {
                    var targetY = yAxis.getPixelForValue(partOverviewState.threshold);
                    if (targetY >= chartArea.top && targetY <= chartArea.bottom) {
                        chartCtx.save();
                        chartCtx.lineWidth = 2;
                        chartCtx.strokeStyle = '#dc2626';
                        chartCtx.setLineDash([6, 5]);

                        chartCtx.beginPath();
                        chartCtx.moveTo(chartArea.left, targetY);
                        chartCtx.lineTo(chartArea.right, targetY);
                        chartCtx.stroke();
                        chartCtx.restore();
                    }
                }

                // 3. In-Bar Data Labels: Cetak Angka Total di Dalam Balok Bar
                // 3. Render Angka Total di Atas / Dalam Setiap Batang (Smart Position & Contrast)
                if (hasBar) {
                    var barMeta = null;
                    for (var mi = 0; mi < chart.data.datasets.length; mi++) {
                        var dMeta = chart.getDatasetMeta(mi);
                        if (dMeta && dMeta.type === 'bar' && !dMeta.hidden) {
                            barMeta = dMeta;
                            break;
                        }
                    }
                    if (barMeta && barMeta.data) {
                        chartCtx.save();
                        chartCtx.textAlign = 'center';

                        barMeta.data.forEach(function(element, index) {
                            var rawVal = Number(counts[index] || 0);
                            if (rawVal <= 0) return;

                            var barY = element.y;
                            var barBase = element.base;
                            var barHeight = Math.abs(barBase - barY);
                            var barWidth = element.width;
                            var centerX = element.x;
                            var valStr = (activeUnitCode === 'ppm' && (rawVal % 1 !== 0))
                                ? rawVal.toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 })
                                : Math.round(rawVal).toLocaleString('id-ID');

                            // Jika tinggi batang cukup besar (>= 22px), render teks PUTIH di DALAM batang
                            if (barHeight >= 22) {
                                chartCtx.save();
                                chartCtx.fillStyle = '#ffffff';
                                chartCtx.shadowColor = 'rgba(0, 0, 0, 0.5)';
                                chartCtx.shadowBlur = 3;
                                chartCtx.textBaseline = 'middle';
                                var posY = barY + Math.min(16, barHeight / 2);
                                chartCtx.font = (barWidth >= 28) ? '800 11px Inter, system-ui, sans-serif' : '700 9.5px Inter, system-ui, sans-serif';
                                chartCtx.fillText(valStr, centerX, posY);
                                chartCtx.restore();
                            } else {
                                // Batang PENDEK (< 22px): Render teks HITAM TEGAS di LUAR (DI ATAS BATANG)
                                chartCtx.save();
                                chartCtx.fillStyle = '#0f172a';
                                chartCtx.shadowColor = 'transparent';
                                chartCtx.shadowBlur = 0;
                                chartCtx.textBaseline = 'bottom';
                                chartCtx.font = (barWidth >= 24) ? '800 10.5px Inter, system-ui, sans-serif' : '800 9px Inter, system-ui, sans-serif';
                                chartCtx.fillText(valStr, centerX, barY - 4);
                                chartCtx.restore();
                            }
                        });
                        chartCtx.restore();
                    }
                }
            }
        };

        chartPartOverviewTrendInstance = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: datasets
            },
            plugins: [dividerPluginPart],
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
                        padding: 10,
                        cornerRadius: 6,
                        callbacks: {
                            title: function(items) {
                                if (!items || items.length === 0) return '';
                                var idx = items[0].dataIndex;
                                return (tooltips[idx] !== undefined) ? tooltips[idx] : items[0].label;
                            },
                            label: function(context) {
                                if (context.dataset.borderDash) {
                                    return ' 🎯 ' + context.dataset.label;
                                }
                                // Cegah duplikasi teks di tooltip saat mode bar (combo)
                                if (hasBar && context.datasetIndex > 0 && datasets[0].type === 'bar') {
                                    return null;
                                }
                                var idx = context.dataIndex;
                                var val = Number(context.raw || 0).toLocaleString();

                                // Jika unit PPM dan tersedia rincian pembagi (Defect & Sample Populasi)
                                if (activeUnitCode === 'ppm' && breakdown[idx]) {
                                    var bInfo = breakdown[idx];
                                    var defPcs = Number(bInfo.defect_count || 0).toLocaleString();
                                    var popPcs = Number(bInfo.sample_pop || 0).toLocaleString();
                                    return [
                                        ' Temuan: ' + defPcs + ' Pcs / ' + popPcs + ' Sample',
                                        ' (' + defPcs + ' ÷ ' + popPcs + ') × 1.000.000 = ' + val + ' PPM'
                                    ];
                                }

                                return ' Temuan: ' + val + ' ' + unitLabel;
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
                        title: {
                            display: true,
                            text: 'Temuan (' + unitLabel + ')',
                            color: '#64748b',
                            font: { size: 10, weight: '700' },
                            padding: { bottom: 4 }
                        },
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

        var dailyLabelsPluginPart = {
            id: 'dailyLabelsPluginPart',
            afterDatasetsDraw: function(chart) {
                if (!isBar) return;
                var chartCtx = chart.ctx;
                var barMeta = chart.getDatasetMeta(0);
                if (!barMeta || !barMeta.data) return;

                chartCtx.save();
                chartCtx.textAlign = 'center';

                barMeta.data.forEach(function(element, index) {
                    var rawVal = Number(counts[index] || 0);
                    if (rawVal <= 0) return;

                    var barY = element.y;
                    var barBase = element.base;
                    var barHeight = Math.abs(barBase - barY);
                    var barWidth = element.width;
                    var centerX = element.x;
                    var valStr = Math.round(rawVal).toLocaleString('id-ID');

                    if (barHeight >= 22) {
                        chartCtx.save();
                        chartCtx.fillStyle = '#ffffff';
                        chartCtx.shadowColor = 'rgba(0, 0, 0, 0.5)';
                        chartCtx.shadowBlur = 3;
                        chartCtx.textBaseline = 'middle';
                        var posY = barY + Math.min(16, barHeight / 2);
                        chartCtx.font = (barWidth >= 22) ? '800 10.5px Inter, system-ui, sans-serif' : '700 9px Inter, system-ui, sans-serif';
                        chartCtx.fillText(valStr, centerX, posY);
                        chartCtx.restore();
                    } else {
                        chartCtx.save();
                        chartCtx.fillStyle = '#0f172a';
                        chartCtx.shadowColor = 'transparent';
                        chartCtx.shadowBlur = 0;
                        chartCtx.textBaseline = 'bottom';
                        chartCtx.font = (barWidth >= 20) ? '800 10px Inter, system-ui, sans-serif' : '800 8.5px Inter, system-ui, sans-serif';
                        chartCtx.fillText(valStr, centerX, barY - 3);
                        chartCtx.restore();
                    }
                });
                chartCtx.restore();
            }
        };

        chartPartOverviewTrendInstance = new Chart(ctx, {
            type: partOverviewState.trendChartType,
            data: {
                labels: labels,
                datasets: datasets
            },
            plugins: [dailyLabelsPluginPart],
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
        var signal = currentPartOverviewAbortController ? currentPartOverviewAbortController.signal : null;

        fetch(url, signal ? { signal: signal } : {})
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!data.success) return;
                partOverviewState.cachedDefectsData = data;
                renderPartOverviewDefectsChart(data);
            })
            .catch(function(err) {
                if (isAbortError(err)) return;
                console.error("Error loading part overview defects:", err);
            });
    }

    function renderPartOverviewDefectsChart(data) {
        var chartType = partOverviewState.defectChartType;
        var unitLabel = data.unit || 'PCS';
        var isPcsUnit = (funnelState.unit === 'pcs');

        // Update header label TH
        var thDefectVal = document.getElementById('thPartOverviewDefectVal');
        var thDefectTarget = document.getElementById('thPartOverviewDefectTarget');
        if (thDefectVal) {
            thDefectVal.textContent = 'Total Temuan (' + unitLabel + ')';
        }
        if (thDefectTarget) {
            thDefectTarget.style.display = isPcsUnit ? '' : 'none';
        }

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
                var colSpanCount = isPcsUnit ? 4 : 3;
                if (!items || items.length === 0 || data.grandMetric === 0) {
                    tbody.innerHTML = '<tr><td colspan="' + colSpanCount + '" style="padding:22px;text-align:center;color:#94a3b8;font-size:11px;">Tidak ada temuan defect pada part ini.</td></tr>';
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
                            : null;

                        var targetCellHtml = '';
                        if (isPcsUnit) {
                            if (targetVal !== null) {
                                var isOver = (rawValNum > targetVal);
                                var badgeBg = isOver ? '#fef2f2' : '#f0fdf4';
                                var badgeColor = isOver ? '#b91c1c' : '#15803d';
                                var badgeBorder = isOver ? '#fecaca' : '#bbf7d0';
                                var statusText = isOver ? '⚠️ Over' : '✓ Aman';

                                targetCellHtml = '<td style="padding:6px 10px;text-align:center;white-space:nowrap;">' +
                                                 '  <div style="display:flex;align-items:center;justify-content:center;gap:5px;">' +
                                                 '    <span style="font-weight:900;color:' + (isOver ? '#dc2626' : '#0f172a') + ';font-size:11px;">' + targetVal.toLocaleString() + '</span> ' +
                                                 '    <span style="font-size:9.5px;font-weight:700;color:#64748b;">' + unitLabel + '</span>' +
                                                 '    <span style="font-size:9px;font-weight:800;padding:1px 5px;border-radius:4px;background:' + badgeBg + ';color:' + badgeColor + ';border:1px solid ' + badgeBorder + ';">' + statusText + '</span>' +
                                                 '  </div>' +
                                                 '</td>';
                            } else {
                                targetCellHtml = '<td style="padding:6px 10px;text-align:center;white-space:nowrap;"><span style="font-size:10px;font-weight:700;color:#94a3b8;background:#f8fafc;padding:2px 7px;border-radius:4px;border:1px solid #e2e8f0;">-</span></td>';
                            }
                        }

                        html += '<tr style="border-bottom:1px solid #f1f5f9;transition:background 0.15s;" onmouseover="this.style.background=\'#f8fafc\'" onmouseout="this.style.background=\'#ffffff\'">' +
                                '  <td style="padding:6px 8px;text-align:center;">' +
                                '    <span style="' + rankBadgeStyle + '">#' + rank + '</span>' +
                                '  </td>' +
                                '  <td style="padding:6px 10px;">' +
                                '    <div style="display:flex;align-items:center;gap:6px;">' +
                                '      <span style="width:8px;height:8px;border-radius:50%;background:' + color + ';flex-shrink:0;"></span>' +
                                '      <div style="font-weight:800;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:' + (isPcsUnit ? '220px' : '320px') + ';" title="' + escapeQuotes(defectName) + '">' +
                                         escapeHtml(defectName) +
                                '      </div>' +
                                '    </div>' +
                                '  </td>' +
                                '  <td style="padding:6px 10px;text-align:right;white-space:nowrap;">' +
                                '    <span style="font-weight:900;color:#0f172a;">' + val + '</span> ' +
                                '    <span style="font-size:10px;font-weight:700;color:#64748b;">' + unitLabel + '</span>' +
                                '  </td>' +
                                   targetCellHtml +
                        rank++;
                    });
                    tbody.innerHTML = html;
                }
            }

        } else {
            // Horizontal Bar Chart (Total Agregat)
            if (donutWrapper) donutWrapper.style.display = 'none';
            if (timelineWrapper) {
                timelineWrapper.style.display = 'flex';
                timelineWrapper.style.flexDirection = 'column';
                timelineWrapper.style.height = '100%';
                timelineWrapper.style.minHeight = '245px';
            }

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

    function switchPartDefectTrendResolution(res) {
        partOverviewState.defectTrendResolution = res;
        var btnW = document.getElementById('btnPartDefectResWeekly');
        var btnD = document.getElementById('btnPartDefectResDaily');
        if (btnW && btnD) {
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
        loadPartOverviewDefectsTimeline();
    }

    function loadPartOverviewDefectsTimeline() {
        if (!partOverviewState.selectedPartId) return;

        var subRes = partOverviewState.defectTrendResolution || 'weekly';
        var topRankVal = partOverviewState.defectTopRank || 5;
        var url = buildApiUrl('get_part_defect_stacked_trend', {
            part_id: partOverviewState.selectedPartId,
            top_rank: topRankVal,
            sub_resolution: subRes,
            trend_interval: subRes
        });
        var signal = currentPartOverviewAbortController ? currentPartOverviewAbortController.signal : null;

        fetch(url, signal ? { signal: signal } : {})
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!data.success) return;
                partOverviewState.cachedDefectsTimelineData = data;
                renderPartOverviewDefectsTimelineChart(data);
            })
            .catch(function(err) {
                if (isAbortError(err)) return;
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

        // Sync Batas Target Merah ke Diagram Kanan Part Overview jika ada (Legend & Tooltip reference)
        var labels = data.labels || [];
        var targetVal = partOverviewState.threshold;
        if (targetVal !== null && targetVal !== undefined && labels.length > 0) {
            var targetData = labels.map(function() { return targetVal; });
            datasets.push({
                type: 'line',
                label: '🎯 Batas Target: ' + targetVal.toLocaleString() + ' ' + unitLabel,
                data: targetData,
                borderColor: 'transparent',
                backgroundColor: '#dc2626',
                borderWidth: 0,
                pointRadius: 0,
                pointHoverRadius: 0,
                fill: false,
                tension: 0,
                order: 1
            });
        }

        var fullWidthTargetPluginDefect = {
            id: 'fullWidthTargetPluginDefect',
            afterDatasetsDraw: function(chart) {
                // 1. Garis Target Merah jika target aktif
                if (targetVal !== null && targetVal !== undefined && labels.length > 0) {
                    var chartCtx = chart.ctx;
                    var yAxis = chart.scales.y;
                    var chartArea = chart.chartArea;
                    if (chartArea && yAxis) {
                        var targetY = yAxis.getPixelForValue(targetVal);
                        if (targetY >= chartArea.top && targetY <= chartArea.bottom) {
                            chartCtx.save();
                            chartCtx.lineWidth = 2;
                            chartCtx.strokeStyle = '#dc2626';
                            chartCtx.setLineDash([6, 5]);

                            chartCtx.beginPath();
                            chartCtx.moveTo(chartArea.left, targetY);
                            chartCtx.lineTo(chartArea.right, targetY);
                            chartCtx.stroke();
                            chartCtx.restore();
                        }
                    }
                }

            // 2. In-Bar Data Labels: Cetak defect(qty) di dalam balok saat mode bar
            if (!isLine) {
                var chartCtx = chart.ctx;
                chartCtx.save();
                chartCtx.textAlign = 'center';
                chartCtx.textBaseline = 'middle';
                chartCtx.fillStyle = '#ffffff';
                chartCtx.shadowColor = 'rgba(0, 0, 0, 0.5)';
                chartCtx.shadowBlur = 3;

                chart.data.datasets.forEach(function(ds, dsIdx) {
                    if (ds.type === 'line') return;
                    var meta = chart.getDatasetMeta(dsIdx);
                    if (!meta || meta.hidden || !meta.data) return;

                    var rawLabel = (ds.label || '').replace(/\s*\([^)]*\)$/, '').trim();

                    meta.data.forEach(function(element, idx) {
                        var rawVal = Number(ds.data[idx] || 0);
                        if (rawVal <= 0) return;

                        var barY = element.y;
                        var barBase = element.base;
                        var barHeight = Math.abs(barBase - barY);
                        var barWidth = element.width;
                        var centerY = (barY + barBase) / 2;
                        var centerX = element.x;

                        if (barHeight < 11) return;

                        var qtyStr = Math.round(rawVal).toLocaleString('id-ID');
                        var textToShow = rawLabel ? (rawLabel + ' (' + qtyStr + ')') : qtyStr;

                        if (barHeight >= 20 && barWidth >= 30) {
                            var maxChars = Math.max(5, Math.floor(barWidth / 6.2));
                            var shortText = (textToShow.length > maxChars) ? (rawLabel.substring(0, Math.max(3, maxChars - 7)) + '..(' + qtyStr + ')') : textToShow;
                            chartCtx.font = '800 9px Inter, system-ui, sans-serif';
                            chartCtx.fillText(shortText, centerX, centerY);
                        } else if (barHeight >= 12 && barWidth >= 20) {
                            chartCtx.font = '800 8.5px Inter, system-ui, sans-serif';
                            chartCtx.fillText(qtyStr, centerX, centerY);
                        }
                    });
                });
                chartCtx.restore();
            }
        }
    };

    chartPartOverviewDefectsInstance = new Chart(ctx, {
            type: isLine ? 'line' : 'bar',
            data: {
                labels: labels,
                datasets: datasets
            },
            plugins: [fullWidthTargetPluginDefect],
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

    function renderPartOverviewHistoryRows(items) {
        if (!items || items.length === 0) {
            return '<tr><td colspan="10" style="padding:32px;text-align:center;color:#94a3b8;font-weight:600;">Tidak ada catatan riwayat temuan defect pada filter ini.</td></tr>';
        }

        var html = '';
        for (var idx = 0; idx < items.length; idx++) {
            var row = items[idx];
            var filterPart = (row.part_code + ' - ' + row.part_name).trim();
            var filterModel = (row.model_name || '').trim();
            var filterDefect = (row.defect_name || '').trim();
            var filterType = row.is_kanban ? 'Kanban' : 'Safety Stock';
            var filterCustomer = (row.customer_name || '').trim();
            var filterInspector = (row.inspector_name || 'System').trim();

            html += '<tr class="part-overview-history-row" ' +
                'data-filter-date="' + escapeHtml(row.date_formatted) + '" ' +
                'data-filter-part="' + escapeHtml(filterPart) + '" ' +
                'data-filter-model="' + escapeHtml(filterModel) + '" ' +
                'data-filter-defect="' + escapeHtml(filterDefect) + '" ' +
                'data-filter-type="' + escapeHtml(filterType) + '" ' +
                'data-filter-customer="' + escapeHtml(filterCustomer) + '" ' +
                'data-filter-inspector="' + escapeHtml(filterInspector) + '" ' +
                'style="border-bottom:1px solid #f1f5f9;" onmouseover="this.style.background=\'#f8fafc\'" onmouseout="this.style.background=\'transparent\'">' +
                '<td class="row-index" style="padding:10px 14px;text-align:center;color:#94a3b8;font-weight:700;">' + (idx + 1) + '</td>' +
                '<td style="padding:10px 14px;">' +
                    '<div style="font-weight:800;color:#0f172a;font-size:11px;">' + escapeHtml(row.date_formatted) + '</div>' +
                    '<span style="font-size:10px;color:#64748b;font-weight:600;">' + escapeHtml(row.time_formatted) + '</span>' +
                '</td>' +
                '<td style="padding:10px 14px;">' +
                    '<span style="display:inline-block;padding:2px 7px;border-radius:5px;background:#f1f5f9;color:#334155;border:1px solid #e2e8f0;font-size:11px;font-weight:700;">' +
                        escapeHtml(row.model_name) +
                    '</span>' +
                '</td>' +
                '<td style="padding:10px 14px;">' +
                    '<span style="display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:6px;font-size:11px;font-weight:800;background:#fff1f2;color:#be123c;border:1px solid #ffe4e6;">' +
                        escapeHtml(row.defect_name) +
                    '</span>' +
                '</td>' +
                '<td style="padding:10px 14px;text-align:center;">' +
                    (row.is_kanban 
                        ? '<span style="display:inline-block;padding:2px 7px;border-radius:4px;font-size:10px;font-weight:800;background:#eff6ff;color:#1d4ed8;border:1px solid #dbeafe;text-transform:uppercase;">Kanban</span>'
                        : '<span style="display:inline-block;padding:2px 7px;border-radius:4px;font-size:10px;font-weight:800;background:#f5f3ff;color:#6d28d9;border:1px solid #ede9fe;text-transform:uppercase;">Safety Stock</span>'
                    ) +
                '</td>' +
                '<td style="padding:10px 14px;">' +
                    '<span style="font-weight:800;color:#0f172a;font-family:monospace;background:#f1f5f9;padding:2px 7px;border-radius:4px;border:1px solid #e2e8f0;font-size:11px;display:inline-block;">' +
                        escapeHtml(row.kanban_no) +
                    '</span>' +
                    '<div style="font-weight:700;color:#334155;font-size:11px;margin-top:3px;">' +
                        escapeHtml(row.customer_name) +
                    '</div>' +
                '</td>' +
                '<td style="padding:10px 14px;">' +
                    '<div style="font-weight:800;color:#0f172a;font-size:12px;font-family:monospace;">' + escapeHtml(row.lot_number) + '</div>' +
                    '<div style="font-size:10px;color:#64748b;font-weight:600;margin-top:2px;font-family:monospace;">' + escapeHtml(row.ref_number) + '</div>' +
                '</td>' +
                '<td style="padding:10px 14px;">' +
                    '<div style="font-weight:700;color:#0f172a;font-size:11px;">' + escapeHtml(row.inspector_name) + '</div>' +
                '</td>' +
                '<td style="padding:10px 14px;text-align:right;">' +
                    '<div style="font-weight:900;color:#b91c1c;font-size:13px;">' + Number(row.qty_ng).toLocaleString() + ' Pcs</div>' +
                '</td>' +
            '</tr>';
        }
        return html;
    }

    function loadPartOverviewHistory() {
        if (!partOverviewState.selectedPartId) return;

        var url = buildApiUrl('get_part_history', {
            part_id: partOverviewState.selectedPartId
        });

        var tbody = document.getElementById('partOverviewHistoryTableBody');
        if (tbody) tbody.style.opacity = '0.5';

        var signal = currentPartOverviewAbortController ? currentPartOverviewAbortController.signal : null;

        fetch(url, signal ? { signal: signal } : {})
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!data.success) return;
                var items = data.items || [];
                if (tbody) {
                    tbody.innerHTML = renderPartOverviewHistoryRows(items);
                    tbody.style.opacity = '1';
                }

                var badge = document.getElementById('partOverviewTableCountBadge');
                if (badge) {
                    badge.textContent = (data.total_count !== undefined ? data.total_count : items.length) + ' Temuan';
                }

                var note = document.getElementById('partOverviewTableFooterNote');
                if (note) {
                    var title = partOverviewState.selectedPartCode + (partOverviewState.selectedPartName ? ' - ' + partOverviewState.selectedPartName : '');
                    var noteHtml = 'Menampilkan <strong>' + items.length + '</strong>';
                    if (data.is_capped) {
                        noteHtml += ' dari total <strong>' + Number(data.total_count).toLocaleString() + '</strong> temuan (dibatasi 300 terbaru)';
                    }
                    noteHtml += ' terperinci untuk part: <strong>' + escapeHtml(title) + '</strong>';
                    note.innerHTML = noteHtml;
                }

                // Inisialisasi dan jalankan filter Excel
                initPartOverviewExcelFilters();
                executePartOverviewFiltering();
            })
            .catch(function(err) {
                if (isAbortError(err)) return;
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

