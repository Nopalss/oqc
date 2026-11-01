    // ── OVERVIEW ANALYSIS JAVASCRIPT CONTROLLER (DEFAULT EXECUTIVE VIEW) ─────
    function loadOverviewMode() {
        loadOverviewDefects();
    }

    function refreshOverviewMode() {
        loadOverviewDefects(function() {
            if (overviewState.selectedDefectId) {
                loadOverviewTrend();
                loadOverviewModels();
                loadOverviewParts();
                loadOverviewHistory();
            }
        });
    }

    function loadOverviewDefects(callback) {
        var url = buildApiUrl('get_defects');
        var signal = getOverviewSignal();

        fetch(url, { signal: signal })
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
                if (isAbortError(err)) return;
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
        renderTop3SummaryPills(validItems, unitLabel);
    }

    // ── Helper Render Top 3 Defect Menjejer (Mode Halaman Web & Kop Cetak) ────
    function renderTop3SummaryPills(items, unitLabel) {
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

                var cursor = isPrint ? 'default' : 'pointer';
                var clickAttr = isPrint ? '' : 'onclick="selectOverviewDefect(' + it.item_id + ', \'' + escapeQuotes(it.item_name) + '\')" title="Klik untuk fokus analisis defect ini"';

                h += '<div ' + clickAttr + ' style="display:inline-flex;align-items:center;gap:4px;padding:' + (isPrint ? '1.5px 6px' : '2.5px 8px') + ';background:' + medalBg + ';border:1px solid ' + medalBorder + ';border-radius:6px;font-size:' + (isPrint ? '9.5px' : '11px') + ';cursor:' + cursor + ';transition:all 0.15s;user-select:none;">' +
                     '  <span style="font-weight:900;color:' + medalText + ';">' + medalIcon + ' #' + rank + '</span>' +
                     '  <strong style="color:#0f172a;max-width:' + (isPrint ? '110px' : '130px') + ';overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + escapeHtml(it.item_name) + '</strong>' +
                     '  <span style="font-weight:800;color:' + medalText + ';background:#ffffff;padding:0.5px 5px;border-radius:4px;border:1px solid ' + medalBorder + ';font-size:' + (isPrint ? '9px' : '10px') + ';">' + formattedVal + ' ' + unitDisplay + '</span>' +
                     '</div>';
            });
            h += '</div>';
            return h;
        };

        // Render ke container halaman web
        var pageContainer = document.getElementById('overviewTop3PillsContainer');
        if (pageContainer) {
            pageContainer.innerHTML = buildPillsHtml(false);
        }

        // Render ke Kop Cetak
        var printContainer = document.getElementById('printReportTop3Defects');
        if (printContainer && currentAnalysisMode === 'overview') {
            printContainer.innerHTML = buildPillsHtml(true);
        }
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

        // Load components in parallel
        loadOverviewTrend();
        loadOverviewModels();
        loadOverviewParts();
        loadOverviewHistory();
    }

    function switchOverviewResolution(res) {
        overviewState.subResolution = res;
        var btnWeekly = document.getElementById('btnOverviewResWeekly');
        var btnDaily  = document.getElementById('btnOverviewResDaily');

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

        loadOverviewTrend();
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

        var grpDaily = document.getElementById('overviewDailyModeGroup');
        if (grpDaily) {
            var isMonthly = (funnelState.preset === 'bulanan' || funnelState.filterType === 'month_year' || funnelState.preset === 'month_year');
            grpDaily.style.display = (isMonthly && interval === 'daily') ? 'inline-flex' : 'none';
        }

        loadOverviewTrend();
    }

    function switchOverviewDailyMode(mode) {
        switchOverviewDailySubMode(mode);
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

        if (overviewState.trendMode === 'multi_horizon') {
            if (overviewState.cachedMultiHorizonData) {
                renderOverviewMultiHorizonChart(overviewState.cachedMultiHorizonData);
            } else {
                loadOverviewTrend();
            }
        } else {
            if (overviewState.cachedTrendData) {
                renderOverviewTrendChart(overviewState.cachedTrendData);
            } else {
                loadOverviewTrend();
            }
        }
    }

    function switchModelTrendRank(rank) {
        overviewState.modelTopRank = rank;
        var btn3   = document.getElementById('btnModelRank3');
        var btn5   = document.getElementById('btnModelRank5');
        var btnAll = document.getElementById('btnModelRankAll');
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

        loadOverviewModelsTimeline();
    }

    function switchPartTrendRank(rank) {
        overviewState.partTopRank = rank;
        var btn3   = document.getElementById('btnPartRank3');
        var btn5   = document.getElementById('btnPartRank5');
        var btnAll = document.getElementById('btnPartRankAll');
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

        loadOverviewPartsTimeline();
    }

    // Helper: Scrollable HTML Legend untuk Timeline Multi-Series (Model / Part)
    function renderScrollableHtmlLegend(chartInstance, containerId) {
        var container = document.getElementById(containerId);
        if (!container || !chartInstance) return;

        var datasets = chartInstance.data.datasets || [];
        if (datasets.length === 0) {
            container.innerHTML = '';
            container.style.display = 'none';
            return;
        }

        container.style.display = 'flex';
        container.innerHTML = '';

        datasets.forEach(function(ds, idx) {
            var isHidden = !chartInstance.isDatasetVisible(idx);
            var color = ds.borderColor || ds.backgroundColor || '#64748b';
            var isTargetLine = (ds.label && ds.label.indexOf('🎯') !== -1);
            var labelText = ds.label || 'Item ' + (idx + 1);

            var chip = document.createElement('div');
            chip.setAttribute('data-dataset-idx', idx);
            chip.setAttribute('title', (ds.fullLabel || labelText) + ' (Klik untuk sembunyikan/tampilkan)');
            chip.style.cssText = 'display:inline-flex;align-items:center;gap:4px;padding:1.5px 6px;border-radius:4px;cursor:pointer;font-size:9px;font-weight:700;line-height:1.2;user-select:none;transition:all 0.15s;border:1px solid ' + (isHidden ? '#e2e8f0' : '#cbd5e1') + ';background:' + (isHidden ? '#f8fafc' : '#ffffff') + ';opacity:' + (isHidden ? '0.45' : '1') + ';text-decoration:' + (isHidden ? 'line-through' : 'none') + ';';

            // Indicator dot or line
            var dot = document.createElement('span');
            if (isTargetLine) {
                dot.style.cssText = 'display:inline-block;width:10px;height:2px;background:#dc2626;flex-shrink:0;border-radius:1px;';
            } else {
                dot.style.cssText = 'display:inline-block;width:7px;height:7px;border-radius:50%;background:' + color + ';flex-shrink:0;';
            }

            var text = document.createElement('span');
            text.style.cssText = 'color:' + (isHidden ? '#94a3b8' : '#334155') + ';max-width:170px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;';
            text.textContent = labelText;

            chip.appendChild(dot);
            chip.appendChild(text);

            chip.onclick = function() {
                var visible = chartInstance.isDatasetVisible(idx);
                chartInstance.setDatasetVisibility(idx, !visible);
                chartInstance.update();
                renderScrollableHtmlLegend(chartInstance, containerId);
            };

            container.appendChild(chip);
        });
    }

    function switchModelTrendResolution(res) {
        overviewState.modelTrendResolution = res;
        var btnW = document.getElementById('btnModelResWeekly');
        var btnD = document.getElementById('btnModelResDaily');
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
        loadOverviewModelsTimeline();
    }

    function switchOverviewModelChartType(type) {
        overviewState.modelChartType = type;
        var btnDonut = document.getElementById('btnOverviewModelDonut');
        var btnTotal = document.getElementById('btnOverviewModelTotal');
        var btnLine  = document.getElementById('btnOverviewModelLine');
        var btnBar   = document.getElementById('btnOverviewModelBar');
        var grpSubRes = document.getElementById('overviewModelSubResGroup');
        var grpRank   = document.getElementById('overviewModelRankGroup');

        var allBtns = [btnDonut, btnTotal, btnLine, btnBar];
        allBtns.forEach(function(b) {
            if (b) {
                b.style.background = 'transparent';
                b.style.color = '#64748b';
                b.style.boxShadow = 'none';
            }
        });

        // Tampilkan Sub-Toggle Resolusi & Filter Rank Top [3|5|Semua] HANYA jika Tren Garis / Tren Batang
        var isTimeline = (type === 'line_trend' || type === 'bar_trend');
        if (grpSubRes) {
            grpSubRes.style.display = isTimeline ? 'inline-flex' : 'none';
        }
        if (grpRank) {
            grpRank.style.display = isTimeline ? 'inline-flex' : 'none';
        }

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
            loadOverviewModelsTimeline();
        } else {
            // Sembunyikan scrollable legend jika beralih ke mode Tabel atau Total
            var legContainer = document.getElementById('chartOverviewModelsLegend');
            if (legContainer) {
                legContainer.style.display = 'none';
                legContainer.innerHTML = '';
            }
            if (overviewState.cachedModelsData) {
                renderOverviewModelsChart(overviewState.cachedModelsData);
            } else {
                loadOverviewModels();
            }
        }
    }

    function loadOverviewModelsTimeline() {
        if (!overviewState.selectedDefectId) return;

        // get_models returns timeline in trend key
        var subRes = overviewState.modelTrendResolution || 'weekly';
        var topRankVal = overviewState.modelTopRank || 5;
        var url = buildApiUrl('get_models', {
            defect_id: overviewState.selectedDefectId,
            top_rank: topRankVal,
            sub_resolution: subRes,
            trend_interval: subRes,
            model_metric: overviewState.modelMetric || 'pcs'
        });
        var signal = currentOverviewAbortController ? currentOverviewAbortController.signal : null;

        fetch(url, signal ? { signal: signal } : {})
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!data.success) return;
                overviewState.cachedModelsTimelineData = data.trend || null;
                if (data.trend) {
                    renderOverviewModelsTimelineChart(data.trend);
                }
            })
            .catch(function(err) {
                if (isAbortError(err)) return;
                console.error("Error loading overview models timeline:", err);
            });
    }

    function renderOverviewModelsTimelineChart(data) {
        var donutWrapper    = document.getElementById('overviewModelDonutWrapper');
        var timelineWrapper = document.getElementById('overviewModelTimelineWrapper');
        if (donutWrapper) donutWrapper.style.display = 'none';
        if (timelineWrapper) {
            timelineWrapper.style.display = 'flex';
            timelineWrapper.style.flexDirection = 'column';
        }

        var canvas = document.getElementById('chartOverviewModels');
        if (!canvas) return;
        if (chartOverviewModelsInstance) chartOverviewModelsInstance.destroy();

        var ctx = canvas.getContext('2d');
        var isLine = (overviewState.modelChartType === 'line_trend');
        var isFreq = (overviewState.modelMetric === 'freq');
        var unitLabel = isFreq ? 'kali' : (data.unit || 'PCS');

        var palette = ['#4f46e5', '#0284c7', '#059669', '#d97706', '#db2777', '#7c3aed', '#0d9488', '#64748b'];

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
                    stack: 'modelStack',
                    order: 2
                };
            }
        });

        // Batas Target Merah HANYA jika bukan mode Frekuensi (karena target defect PCS tidak cocok dengan frekuensi)
        var labels = data.labels || [];
        var targetVal = isFreq ? null : overviewState.threshold;
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

        var fullWidthTargetPluginModel = {
            id: 'fullWidthTargetPluginModel',
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

            // 2. In-Bar Data Labels: Cetak model(qty) di dalam balok saat mode bar
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
                            chartCtx.font = '800 9.5px Inter, system-ui, sans-serif';
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

    chartOverviewModelsInstance = new Chart(ctx, {
            type: isLine ? 'line' : 'bar',
            data: {
                labels: labels,
                datasets: datasets
            },
            plugins: [fullWidthTargetPluginModel],
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
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

        // Render Scrollable HTML Legend di container bawah canvas
        renderScrollableHtmlLegend(chartOverviewModelsInstance, 'chartOverviewModelsLegend');
    }

    function switchOverviewModelMetric(metric) {
        overviewState.modelMetric = metric;
        var btnPcs = document.getElementById('btnModelMetricPcs');
        var btnFreq = document.getElementById('btnModelMetricFreq');

        if (btnPcs && btnFreq) {
            if (metric === 'pcs') {
                btnPcs.style.background = '#0f172a';
                btnPcs.style.color      = '#ffffff';
                btnPcs.style.boxShadow  = '0 1px 2px rgba(15,23,42,0.2)';
                btnFreq.style.background = 'transparent';
                btnFreq.style.color      = '#64748b';
                btnFreq.style.boxShadow  = 'none';
            } else {
                btnFreq.style.background = '#0f172a';
                btnFreq.style.color      = '#ffffff';
                btnFreq.style.boxShadow  = '0 1px 2px rgba(15,23,42,0.2)';
                btnPcs.style.background  = 'transparent';
                btnPcs.style.color       = '#64748b';
                btnPcs.style.boxShadow   = 'none';
            }
        }

        var thModelVal = document.getElementById('thOverviewModelVal');
        var thModelTarget = document.getElementById('thOverviewModelTarget');
        var badgeModelUnit = document.getElementById('overviewCardModelUnitBadge');
        var isFreq = (metric === 'freq');
        if (thModelVal) {
            thModelVal.textContent = isFreq ? 'Frekuensi Temuan (x)' : 'Total Temuan (PCS)';
        }
        if (thModelTarget) {
            thModelTarget.style.display = isFreq ? 'none' : '';
        }
        if (badgeModelUnit) {
            badgeModelUnit.textContent = isFreq ? 'FREQ (X)' : 'PCS';
            badgeModelUnit.style.color = isFreq ? '#0891b2' : '#4338ca';
            badgeModelUnit.style.borderColor = isFreq ? '#a5f3fc' : '#cbd5e1';
            badgeModelUnit.style.backgroundColor = isFreq ? '#ecfeff' : '#f8fafc';
        }

        loadOverviewModels();
        if (overviewState.modelChartType === 'line_trend' || overviewState.modelChartType === 'bar_trend') {
            loadOverviewModelsTimeline();
        }
    }

    function loadOverviewModels() {
        if (!overviewState.selectedDefectId) return;

        var url = buildApiUrl('get_models', {
            defect_id: overviewState.selectedDefectId,
            model_metric: overviewState.modelMetric || 'pcs'
        });
        var signal = currentOverviewAbortController ? currentOverviewAbortController.signal : null;

        fetch(url, signal ? { signal: signal } : {})
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!data.success) return;
                overviewState.cachedModelsData = data;
                renderOverviewModelsChart(data);
            })
            .catch(function(err) {
                if (isAbortError(err)) return;
                console.error("Error loading overview models:", err);
            });
    }

    function renderOverviewModelsChart(data) {
        var chartType = overviewState.modelChartType;
        var unitLabel = data.unit || ((overviewState.modelMetric === 'freq') ? 'kali' : 'PCS');
        var isFreqMode = (overviewState.modelMetric === 'freq');

        // Update header label TH
        var thModelVal = document.getElementById('thOverviewModelVal');
        var thModelTarget = document.getElementById('thOverviewModelTarget');
        if (thModelVal) {
            thModelVal.textContent = isFreqMode ? 'Frekuensi Temuan (x)' : 'Total Temuan (PCS)';
        }
        if (thModelTarget) {
            thModelTarget.style.display = isFreqMode ? 'none' : '';
        }

        // Sembunyikan scrollable legend container pada mode Table dan Total
        var legContainer = document.getElementById('chartOverviewModelsLegend');
        if (legContainer) {
            legContainer.style.display = 'none';
            legContainer.innerHTML = '';
        }

        var donutWrapper    = document.getElementById('overviewModelDonutWrapper');
        var timelineWrapper = document.getElementById('overviewModelTimelineWrapper');

        var items = data.items || [];
        var labels = (data.chartLabels || []).slice();
        var counts = (data.chartData || []).slice();
        var colors = (data.chartColors || []).slice();

        if (counts.length === 0 || data.grandMetric === 0) {
            labels = ['Tidak ada model'];
            counts = [1];
            colors = ['#e2e8f0'];
        }

        if (chartType === 'table' || chartType === 'doughnut') {
            if (donutWrapper) donutWrapper.style.display = 'block';
            if (timelineWrapper) timelineWrapper.style.display = 'none';

            var tbody = document.getElementById('overviewModelTableBody');
            if (tbody) {
                var colSpanCount = isFreqMode ? 3 : 4;
                if (!items || items.length === 0 || data.grandMetric === 0) {
                    tbody.innerHTML = '<tr><td colspan="' + colSpanCount + '" style="padding:22px;text-align:center;color:#94a3b8;font-size:11px;">Tidak ada temuan model pada defect ini.</td></tr>';
                } else {
                    var html = '';
                    var rank = 1;
                    items.forEach(function(item) {
                        var val = Number(item.val || 0).toLocaleString();
                        var pct = data.grandMetric > 0 ? (Math.round((item.val / data.grandMetric) * 1000) / 10) : 0;
                        var color = item.color || '#4f46e5';
                        var modelName = item.item_name || 'General / Unspecified Model';

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
                            : null;

                        var targetCellHtml = '';
                        if (!isFreqMode) {
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

                        var valSubHtml = '';
                        if (isFreqMode && item.total_pcs !== undefined) {
                            valSubHtml = '<div style="font-size:9px;font-weight:600;color:#94a3b8;">' + Number(item.total_pcs || 0).toLocaleString() + ' PCS cacat</div>';
                        }

                        html += '<tr style="border-bottom:1px solid #f1f5f9;transition:background 0.15s;" onmouseover="this.style.background=\'#f8fafc\'" onmouseout="this.style.background=\'#ffffff\'">' +
                                '  <td style="padding:6px 8px;text-align:center;">' +
                                '    <span style="' + rankBadgeStyle + '">#' + rank + '</span>' +
                                '  </td>' +
                                '  <td style="padding:6px 10px;">' +
                                '    <div style="display:flex;align-items:center;gap:6px;">' +
                                '      <span style="width:8px;height:8px;border-radius:50%;background:' + color + ';flex-shrink:0;"></span>' +
                                '      <div style="font-weight:800;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:' + (isFreqMode ? '310px' : '210px') + ';" title="' + escapeQuotes(modelName) + '">' +
                                         escapeHtml(modelName) +
                                '      </div>' +
                                '    </div>' +
                                '  </td>' +
                                '  <td style="padding:6px 10px;text-align:right;white-space:nowrap;">' +
                                '    <div><span style="font-weight:900;color:#0f172a;">' + val + '</span> ' +
                                '    <span style="font-size:10px;font-weight:700;color:#64748b;">' + unitLabel + '</span></div>' +
                                     valSubHtml +
                                '  </td>' +
                                   targetCellHtml +
                                '</tr>';
                        rank++;
                    });
                    tbody.innerHTML = html;
                }
            }

        } else if (chartType === 'bar_total') {
            // Horizontal Bar Chart (Total Agregat)
            if (donutWrapper) donutWrapper.style.display = 'none';
            if (timelineWrapper) {
                timelineWrapper.style.display = 'flex';
                timelineWrapper.style.flexDirection = 'column';
                timelineWrapper.style.height = '100%';
                timelineWrapper.style.minHeight = '340px';
            }

            var canvas = document.getElementById('chartOverviewModels');
            if (!canvas) return;
            if (chartOverviewModelsInstance) chartOverviewModelsInstance.destroy();

            var ctx = canvas.getContext('2d');

            var barLabelsWithQty = labels.map(function(lbl, i) {
                var v = Number(counts[i] || 0).toLocaleString();
                var p = data.grandMetric > 0 ? (Math.round((counts[i] / data.grandMetric) * 1000) / 10) + '%' : '0%';
                return lbl + '  [' + v + ' ' + unitLabel + ' • ' + p + ']';
            });

            chartOverviewModelsInstance = new Chart(ctx, {
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

    function switchPartTrendResolution(res) {
        overviewState.partTrendResolution = res;
        var btnW = document.getElementById('btnPartResWeekly');
        var btnD = document.getElementById('btnPartResDaily');
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
        loadOverviewPartsTimeline();
    }

    function switchOverviewPartChartType(type) {
        overviewState.partChartType = type;
        var btnDonut = document.getElementById('btnOverviewPartDonut');
        var btnTotal = document.getElementById('btnOverviewPartTotal');
        var btnLine  = document.getElementById('btnOverviewPartLine');
        var btnBar   = document.getElementById('btnOverviewPartBar');
        var grpSubRes = document.getElementById('overviewPartSubResGroup');
        var grpRank   = document.getElementById('overviewPartRankGroup');

        var allBtns = [btnDonut, btnTotal, btnLine, btnBar];
        allBtns.forEach(function(b) {
            if (b) {
                b.style.background = 'transparent';
                b.style.color = '#64748b';
                b.style.boxShadow = 'none';
            }
        });

        // Tampilkan Sub-Toggle Resolusi & Filter Rank Top [3|5|Semua] HANYA jika Tren Garis / Tren Batang
        var isTimeline = (type === 'line_trend' || type === 'bar_trend');
        if (grpSubRes) {
            grpSubRes.style.display = isTimeline ? 'inline-flex' : 'none';
        }
        if (grpRank) {
            grpRank.style.display = isTimeline ? 'inline-flex' : 'none';
        }

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
            // Sembunyikan scrollable legend jika beralih ke mode Tabel atau Total
            var legContainer = document.getElementById('chartOverviewPartsLegend');
            if (legContainer) {
                legContainer.style.display = 'none';
                legContainer.innerHTML = '';
            }
            if (overviewState.cachedPartsData) {
                renderOverviewPartsChart(overviewState.cachedPartsData);
            } else {
                loadOverviewParts();
            }
        }
    }

    function loadOverviewPartsTimeline() {
        if (!overviewState.selectedDefectId) return;

        var subRes = overviewState.partTrendResolution || 'weekly';
        var topRankVal = overviewState.partTopRank || 5;
        var url = buildApiUrl('get_parts', {
            defect_id: overviewState.selectedDefectId,
            top_rank: topRankVal,
            sub_resolution: subRes,
            trend_interval: subRes,
            part_metric: overviewState.partMetric || 'pcs'
        });
        var signal = currentOverviewAbortController ? currentOverviewAbortController.signal : null;

        fetch(url, signal ? { signal: signal } : {})
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!data.success) return;
                var trendData = data.trend || data;
                overviewState.cachedPartsTimelineData = trendData;
                if (data.trend) {
                    renderOverviewPartsTimelineChart(data.trend);
                } else {
                    renderOverviewPartsTimelineChart(data);
                }
            })
            .catch(function(err) {
                if (isAbortError(err)) return;
                console.error("Error loading overview parts timeline:", err);
            });
    }

    function renderOverviewPartsTimelineChart(data) {
        var donutWrapper    = document.getElementById('overviewPartDonutWrapper');
        var timelineWrapper = document.getElementById('overviewPartTimelineWrapper');
        if (donutWrapper) donutWrapper.style.display = 'none';
        if (timelineWrapper) {
            timelineWrapper.style.display = 'flex';
            timelineWrapper.style.flexDirection = 'column';
        }

        var canvas = document.getElementById('chartOverviewParts');
        if (!canvas) return;
        if (chartOverviewPartsInstance) chartOverviewPartsInstance.destroy();

        var ctx = canvas.getContext('2d');
        var isLine = (overviewState.partChartType === 'line_trend');
        var isPpm = (overviewState.partMetric === 'ppm');
        var unitLabel = isPpm ? 'PPM' : (data.unit || 'PCS');

        var palette = ['#059669', '#2563eb', '#f59e0b', '#d946ef', '#06b6d4', '#ea580c', '#8b5cf6', '#64748b'];

        var datasets = (data.datasets || []).map(function(ds, idx) {
            var color = palette[idx % palette.length];
            var code = ds.label || '';
            var pName = (ds.part_name || '').trim();
            // Legend Label ringkas: prioritaskan Part Code agar bersih & tidak memakan ruang chart
            var legendLabel = code;
            if (pName !== '' && pName !== '()' && pName !== code) {
                // Jika nama part pendek, sertakan. Jika panjang, potong rapi
                var shortName = pName.length > 20 ? (pName.substring(0, 18) + '...') : pName;
                legendLabel = code + ' - ' + shortName;
            }
            // Full Label untuk tooltip detail
            var fullLabel = (pName !== '' && pName !== '()' && pName !== code) ? (code + ' - ' + pName) : code;

            if (isLine) {
                return {
                    label: legendLabel,
                    fullLabel: fullLabel,
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
                    label: legendLabel,
                    fullLabel: fullLabel,
                    partCode: ds.part_code || (ds.label || '').split(' - ')[0].trim(),
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

        // Sync Batas Target Merah ke Diagram Kanan HANYA jika bukan mode PPM (karena target defect global tidak cocok dengan PPM per part)
        var labels = data.labels || [];
        var targetVal = isPpm ? null : overviewState.threshold;
        if (targetVal !== null && targetVal !== undefined && labels.length > 0) {
            var targetData = labels.map(function() { return targetVal; });
            datasets.push({
                type: 'line',
                label: '🎯 Batas Target: ' + targetVal.toLocaleString() + ' ' + unitLabel,
                fullLabel: '🎯 Batas Target: ' + targetVal.toLocaleString() + ' ' + unitLabel,
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

        var fullWidthTargetPluginPart = {
            id: 'fullWidthTargetPluginPart',
            afterDatasetsDraw: function(chart) {
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

                // 2. In-Bar Data Labels: Cetak partcode(qty/ppm) di dalam balok saat mode bar
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

                        var rawFull = (ds.partName || ds.fullLabel || ds.label || '').trim();
                        var pCode = (ds.partCode && ds.partCode.trim() !== '') ? ds.partCode.trim() : '';
                        
                        if (!pCode || (/^\d+$/.test(pCode) && rawFull.length > pCode.length)) {
                            pCode = rawFull.replace(/\s*\([^)]*\)$/, '').trim();
                        }

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

                            var qtyStr = isPpm 
                                ? (rawVal >= 1000 ? Math.round(rawVal).toLocaleString('id-ID') : rawVal.toFixed(1))
                                : Math.round(rawVal).toLocaleString('id-ID');
                            var textToShow = pCode ? (pCode + ' (' + qtyStr + ')') : qtyStr;

                            if (barHeight >= 20 && barWidth >= 30) {
                                var maxChars = Math.max(5, Math.floor(barWidth / 6.2));
                                var shortText = (textToShow.length > maxChars) ? (pCode.substring(0, Math.max(3, maxChars - 7)) + '..(' + qtyStr + ')') : textToShow;
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

        chartOverviewPartsInstance = new Chart(ctx, {
            type: isLine ? 'line' : 'bar',
            data: {
                labels: labels,
                datasets: datasets
            },
            plugins: [fullWidthTargetPluginPart],
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
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
                            label: function(ctxTooltip) {
                                var rawNum = Number(ctxTooltip.raw || 0);
                                var val = isPpm
                                    ? rawNum.toLocaleString(undefined, {minimumFractionDigits: 1, maximumFractionDigits: 1})
                                    : rawNum.toLocaleString();
                                var displayTitle = ctxTooltip.dataset.fullLabel || ctxTooltip.dataset.label;
                                return ' ' + displayTitle + ': ' + val + ' ' + unitLabel;
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

        // Render Scrollable HTML Legend di container bawah canvas
        renderScrollableHtmlLegend(chartOverviewPartsInstance, 'chartOverviewPartsLegend');
    }

    function switchOverviewPartMetric(metric) {
        overviewState.partMetric = metric;
        var btnPcs = document.getElementById('btnPartMetricPcs');
        var btnPpm = document.getElementById('btnPartMetricPpm');

        if (btnPcs && btnPpm) {
            if (metric === 'pcs') {
                btnPcs.style.background = '#0f172a';
                btnPcs.style.color      = '#ffffff';
                btnPcs.style.boxShadow  = '0 1px 2px rgba(15,23,42,0.2)';
                btnPpm.style.background = 'transparent';
                btnPpm.style.color      = '#64748b';
                btnPpm.style.boxShadow  = 'none';
            } else {
                btnPpm.style.background = '#0f172a';
                btnPpm.style.color      = '#ffffff';
                btnPpm.style.boxShadow  = '0 1px 2px rgba(15,23,42,0.2)';
                btnPcs.style.background  = 'transparent';
                btnPcs.style.color       = '#64748b';
                btnPcs.style.boxShadow   = 'none';
            }
        }

        var thPartVal = document.getElementById('thOverviewPartVal');
        var thPartTarget = document.getElementById('thOverviewPartTarget');
        var badgePartUnit = document.getElementById('overviewCardPartUnitBadge');
        var isPpm = (metric === 'ppm');
        if (thPartVal) {
            thPartVal.textContent = isPpm ? 'Tingkat PPM (PPM)' : 'Total Temuan (PCS)';
        }
        if (thPartTarget) {
            thPartTarget.style.display = isPpm ? 'none' : '';
        }
        if (badgePartUnit) {
            badgePartUnit.textContent = isPpm ? 'PPM' : 'PCS';
            badgePartUnit.style.color = isPpm ? '#4338ca' : '#047857';
            badgePartUnit.style.borderColor = isPpm ? '#c7d2fe' : '#cbd5e1';
            badgePartUnit.style.backgroundColor = isPpm ? '#eef2ff' : '#f8fafc';
        }

        loadOverviewParts();
        if (overviewState.partChartType === 'line_trend' || overviewState.partChartType === 'bar_trend') {
            loadOverviewPartsTimeline();
        }
    }

    function loadOverviewParts() {
        if (!overviewState.selectedDefectId) return;

        var url = buildApiUrl('get_parts', {
            defect_id: overviewState.selectedDefectId,
            part_metric: overviewState.partMetric || 'pcs'
        });
        var signal = currentOverviewAbortController ? currentOverviewAbortController.signal : null;

        fetch(url, signal ? { signal: signal } : {})
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!data.success) return;
                overviewState.cachedPartsData = data;
                renderOverviewPartsChart(data);
            })
            .catch(function(err) {
                if (isAbortError(err)) return;
                console.error("Error loading overview parts:", err);
            });
    }

    function renderOverviewPartsChart(data) {
        var chartType = overviewState.partChartType;
        var unitLabel = data.unit || ((overviewState.partMetric === 'ppm') ? 'PPM' : 'PCS');
        var isPpmMode = (overviewState.partMetric === 'ppm');

        // Update header label TH jika ada
        var thPartVal = document.getElementById('thOverviewPartVal');
        var thPartTarget = document.getElementById('thOverviewPartTarget');
        if (thPartVal) {
            thPartVal.textContent = isPpmMode ? 'Tingkat PPM (PPM)' : 'Total Temuan (PCS)';
        }
        if (thPartTarget) {
            thPartTarget.style.display = isPpmMode ? 'none' : '';
        }

        // Sembunyikan scrollable legend container pada mode Table dan Total
        var legContainer = document.getElementById('chartOverviewPartsLegend');
        if (legContainer) {
            legContainer.style.display = 'none';
            legContainer.innerHTML = '';
        }

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
                var colSpanCount = isPpmMode ? 3 : 4;
                if (!items || items.length === 0 || data.grandMetric === 0) {
                    tbody.innerHTML = '<tr><td colspan="' + colSpanCount + '" style="padding:22px;text-align:center;color:#94a3b8;font-size:11px;">Tidak ada temuan part pada defect ini.</td></tr>';
                } else {
                    var html = '';
                    var rank = 1;
                    items.forEach(function(item) {
                        var val = isPpmMode 
                            ? Number(item.ppm !== undefined ? item.ppm : item.val || 0).toLocaleString(undefined, {minimumFractionDigits: 1, maximumFractionDigits: 1})
                            : Number(item.val || 0).toLocaleString();
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
                            : null;

                        var targetCellHtml = '';
                        if (!isPpmMode) {
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

                        // Info detail transparansi perhitungan PPM
                        var partValSubHtml = '';
                        if (isPpmMode) {
                            var pcsCount = Number(item.total_pcs || 0).toLocaleString();
                            var sampleCount = Number(item.sample_pop || 0).toLocaleString();
                            partValSubHtml = '<div style="font-size:9px;font-weight:600;color:#64748b;margin-top:1px;cursor:help;" title="Perhitungan: (' + pcsCount + ' Pcs ÷ ' + sampleCount + ' Sampel) × 1.000.000 = ' + val + ' PPM">' +
                                             '(' + pcsCount + ' temuan / ' + sampleCount + ' sampel)' +
                                             '</div>';
                        }

                        html += '<tr style="border-bottom:1px solid #f1f5f9;transition:background 0.15s;" onmouseover="this.style.background=\'#f8fafc\'" onmouseout="this.style.background=\'#ffffff\'">' +
                                '  <td style="padding:6px 8px;text-align:center;">' +
                                '    <span style="' + rankBadgeStyle + '">#' + rank + '</span>' +
                                '  </td>' +
                                '  <td style="padding:6px 10px;">' +
                                '    <div style="display:flex;align-items:center;gap:6px;">' +
                                '      <span style="width:8px;height:8px;border-radius:50%;background:' + color + ';flex-shrink:0;"></span>' +
                                '      <div style="font-weight:800;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:' + (isPpmMode ? '310px' : '210px') + ';" title="' + escapeQuotes(item.item_name) + '">' +
                                         (partCode ? '<span style="font-family:monospace;color:#1d4ed8;margin-right:4px;">' + escapeHtml(partCode) + '</span> ' : '') +
                                         escapeHtml(partName) +
                                '      </div>' +
                                '    </div>' +
                                '  </td>' +
                                '  <td style="padding:6px 10px;text-align:right;white-space:nowrap;">' +
                                '    <div><span style="font-weight:900;color:#0f172a;">' + val + '</span> ' +
                                '    <span style="font-size:10px;font-weight:700;color:#64748b;">' + unitLabel + '</span></div>' +
                                     partValSubHtml +
                                '  </td>' +
                                   targetCellHtml +
                                '</tr>';
                        rank++;
                    });
                    tbody.innerHTML = html;
                }
            }

        } else if (chartType === 'bar_total') {
            // Horizontal Bar Chart (Total Agregat)
            if (donutWrapper) donutWrapper.style.display = 'none';
            if (timelineWrapper) {
                timelineWrapper.style.display = 'flex';
                timelineWrapper.style.flexDirection = 'column';
                timelineWrapper.style.height = '100%';
                timelineWrapper.style.minHeight = '340px';
            }

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

        var signal = currentOverviewAbortController ? currentOverviewAbortController.signal : null;
        var fetchOpts = signal ? { signal: signal } : {};

        // Parallel fetch threshold value
        var threshUrl = buildApiUrl('get_threshold', {
            mode: 'overview',
            item_id: overviewState.selectedDefectId
        });

        if (overviewState.trendMode === 'multi_horizon') {
            var urlHorizon = buildApiUrl('get_multi_horizon_trend', {
                defect_id: overviewState.selectedDefectId,
                sub_resolution: overviewState.subResolution || 'weekly'
            });

            Promise.all([
                fetch(urlHorizon, fetchOpts).then(function(res) { return res.json(); }),
                fetch(threshUrl, fetchOpts).then(function(res) { return res.json(); }).catch(function() { return { success: false }; })
            ])
            .then(function(results) {
                var data = results[0];
                var threshData = results[1];

                if (!data.success) return;
                overviewState.cachedMultiHorizonData = data;

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

                // Refresh tabel sebaran model & part agar Batas Target sinkron
                if (overviewState.cachedPartsData && overviewState.partChartType === 'table') {
                    renderOverviewPartsChart(overviewState.cachedPartsData);
                }
                if (overviewState.cachedModelsData && overviewState.modelChartType === 'table') {
                    renderOverviewModelsChart(overviewState.cachedModelsData);
                }

                // Update subtitle keterangan interval
                var elDesc = document.getElementById('overviewTrendIntervalDesc');
                if (elDesc) {
                    var mName = (data.meta ? data.meta.monthName : '');
                    if (overviewState.subResolution === 'daily') {
                        elDesc.textContent = 'Komparasi makro-ke-mikro: Tahunan (Tahun dengan Data) → Bulanan (Tahun Berjalan) → Harian Siklus Kerja (' + mName + ').';
                    } else {
                        elDesc.textContent = 'Komparasi makro-ke-mikro: Tahunan (Tahun dengan Data) → Bulanan (Tahun Berjalan) → Mingguan (' + mName + ').';
                    }
                }

                renderOverviewMultiHorizonChart(data);
            })
            .catch(function(err) {
                if (isAbortError(err)) return;
                console.error("Error loading multi-horizon trend:", err);
            });
            return;
        }

        // Mode Timeline Harian
        var url = buildApiUrl('get_trend_timeline', {
            defect_id: overviewState.selectedDefectId,
            trend_interval: 'daily',
            daily_mode: overviewState.dailyMode || 'work_cycle'
        });

        Promise.all([
            fetch(url, fetchOpts).then(function(res) { return res.json(); }),
            fetch(threshUrl, fetchOpts).then(function(res) { return res.json(); }).catch(function() { return { success: false }; })
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

            // Refresh tabel sebaran model & part agar Batas Target sinkron
            if (overviewState.cachedPartsData && overviewState.partChartType === 'table') {
                renderOverviewPartsChart(overviewState.cachedPartsData);
            }
            if (overviewState.cachedModelsData && overviewState.modelChartType === 'table') {
                renderOverviewModelsChart(overviewState.cachedModelsData);
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
                elDesc.textContent = (overviewState.dailyMode === 'full_month')
                    ? 'Distribusi harian kalender murni (dari tanggal 1 awal bulan).'
                    : 'Distribusi harian sesuai siklus kerja (tanggal sisa bulan lalu disinkronkan).';
            }

            renderOverviewTrendChart(data);
        })
        .catch(function(err) {
            if (isAbortError(err)) return;
            console.error("Error loading overview trend:", err);
        });
    }

    function renderOverviewMultiHorizonChart(data) {
        var canvas = document.getElementById('chartOverviewTrend');
        if (!canvas) return;
        if (chartOverviewTrendInstance) chartOverviewTrendInstance.destroy();

        var ctx = canvas.getContext('2d');
        var hasBar = (overviewState.trendChartType === 'bar');
        var hasLine = (overviewState.trendChartType === 'bar' || overviewState.trendChartType === 'line');
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

        var isDense = (overviewState.trendResolution === 'daily' || labels.length > 25);
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
        if (overviewState.threshold !== null && overviewState.threshold !== undefined && labels.length > 0) {
            var targetVal = overviewState.threshold;
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
        var dividerPlugin = {
            id: 'multiHorizonSectionDivider',
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
                if (overviewState.threshold !== null && overviewState.threshold !== undefined && labels.length > 0) {
                    var targetY = yAxis.getPixelForValue(overviewState.threshold);
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

        chartOverviewTrendInstance = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: datasets
            },
            plugins: [dividerPlugin],
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

        var dailyLabelsPlugin = {
            id: 'dailyLabelsPlugin',
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

        chartOverviewTrendInstance = new Chart(ctx, {
            type: overviewState.trendChartType,
            data: {
                labels: labels,
                datasets: datasets
            },
            plugins: [dailyLabelsPlugin],
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



    function renderOverviewHistoryRows(items) {
        if (!items || items.length === 0) {
            return '<tr><td colspan="10" style="padding:32px;text-align:center;color:#94a3b8;font-weight:600;">Tidak ada catatan riwayat temuan cacat pada filter yang dipilih.</td></tr>';
        }

        var html = '';
        for (var idx = 0; idx < items.length; idx++) {
            var row = items[idx];
            var filterPart = (row.part_code + ' - ' + row.part_name).trim();
            var filterModel = (row.model_name || '').trim();
            var filterType = row.is_kanban ? 'Kanban' : 'Safety Stock';
            var filterCustomer = (row.customer_name || '').trim();
            var filterInspector = (row.inspector_name || 'System').trim();

            html += '<tr class="overview-history-row" ' +
                'data-filter-date="' + escapeHtml(row.date_formatted) + '" ' +
                'data-filter-part="' + escapeHtml(filterPart) + '" ' +
                'data-filter-model="' + escapeHtml(filterModel) + '" ' +
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
                    '<div style="font-weight:800;color:#0f172a;font-size:12px;">' + escapeHtml(row.part_code) + '</div>' +
                    '<span style="font-size:11px;color:#64748b;">' + escapeHtml(row.part_name) + '</span>' +
                '</td>' +
                '<td style="padding:10px 14px;">' +
                    '<span style="display:inline-block;padding:2px 7px;border-radius:5px;background:#f1f5f9;color:#334155;border:1px solid #e2e8f0;font-size:11px;font-weight:700;">' +
                        escapeHtml(row.model_name) +
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

    function loadOverviewHistory() {
        if (!overviewState.selectedDefectId) return;

        var url = buildApiUrl('get_history', {
            defect_id: overviewState.selectedDefectId
        });

        var tbody = document.getElementById('overviewHistoryTableBody');
        if (tbody) tbody.style.opacity = '0.5';

        var signal = currentOverviewAbortController ? currentOverviewAbortController.signal : null;

        fetch(url, signal ? { signal: signal } : {})
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!data.success) return;
                var items = data.items || [];
                if (tbody) {
                    tbody.innerHTML = renderOverviewHistoryRows(items);
                    tbody.style.opacity = '1';
                }

                var badge = document.getElementById('overviewTableCountBadge');
                if (badge) {
                    badge.textContent = (data.total_count !== undefined ? data.total_count : items.length) + ' Temuan';
                }

                var note = document.getElementById('overviewTableFooterNote');
                if (note) {
                    var noteHtml = 'Menampilkan <strong>' + items.length + '</strong>';
                    if (data.is_capped) {
                        noteHtml += ' dari total <strong>' + Number(data.total_count).toLocaleString() + '</strong> temuan (dibatasi 300 terbaru)';
                    }
                    noteHtml += ' terperinci untuk defect: <strong>' + escapeHtml(overviewState.selectedDefectName) + '</strong>';
                    note.innerHTML = noteHtml;
                }

                // Inisialisasi dan terapkan filter Excel jika ada data
                initOverviewExcelFilters();
                executeOverviewFiltering();
            })
            .catch(function(err) {
                if (isAbortError(err)) return;
                console.error("Error loading overview history:", err);
                if (tbody) tbody.style.opacity = '1';
            });
    }

    function clearOverviewChartsAndTable() {
        if (chartOverviewTrendInstance) {
            chartOverviewTrendInstance.destroy();
            chartOverviewTrendInstance = null;
        }
        if (chartOverviewModelsInstance) {
            chartOverviewModelsInstance.destroy();
            chartOverviewModelsInstance = null;
        }
        if (chartOverviewPartsInstance) {
            chartOverviewPartsInstance.destroy();
            chartOverviewPartsInstance = null;
        }
        if (chartOverviewPartsDonutInstance) {
            chartOverviewPartsDonutInstance.destroy();
            chartOverviewPartsDonutInstance = null;
        }

        // Bersihkan canvas model & part & trend secara manual jika masih ada bekas render
        var cvsModel = document.getElementById('chartOverviewModels');
        if (cvsModel) {
            var ctxM = cvsModel.getContext('2d');
            if (ctxM) ctxM.clearRect(0, 0, cvsModel.width, cvsModel.height);
        }
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
        overviewState.cachedModelsData = null;
        overviewState.cachedModelsTimelineData = null;
        overviewState.cachedPartsData = null;
        overviewState.cachedPartsTimelineData = null;
        overviewState.cachedTrendData = null;
        overviewState.cachedMultiHorizonData = null;

        var tbodyModel = document.getElementById('overviewModelTableBody');
        if (tbodyModel) {
            tbodyModel.innerHTML = '<tr><td colspan="4" style="padding:20px;text-align:center;color:#94a3b8;font-size:11px;">Tidak ada data model.</td></tr>';
        }
        var tbodyPart = document.getElementById('overviewPartTableBody');
        if (tbodyPart) {
            tbodyPart.innerHTML = '<tr><td colspan="4" style="padding:20px;text-align:center;color:#94a3b8;font-size:11px;">Tidak ada data part.</td></tr>';
        }

        var tbody = document.getElementById('overviewHistoryTableBody');
        if (tbody) {
            tbody.innerHTML = '<tr><td colspan="10" style="padding:24px;text-align:center;color:#94a3b8;">Tidak ada data pada filter ini.</td></tr>';
        }
        var countBadge = document.getElementById('overviewTableCountBadge');
        if (countBadge) countBadge.textContent = '0 Temuan';
    }

