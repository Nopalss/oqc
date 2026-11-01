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

        fetch('api/drilldown_data.php?action=save_threshold', {
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

    // Tutup dropdown jika container utama di-scroll atau window di-resize (abaikan scroll di dalam dropdown list)
    window.addEventListener('resize', function() {
        closeAllOverviewExcelFilters();
        closeAllPartOverviewExcelFilters();
    });
    window.addEventListener('scroll', function(e) {
        if (e.target && e.target.closest && e.target.closest('.excel-filter-dropdown')) {
            return;
        }
        closeAllOverviewExcelFilters();
        closeAllPartOverviewExcelFilters();
    }, true);
    var defectBody = document.getElementById('defect-body');
    if (defectBody) {
        defectBody.addEventListener('scroll', function(e) {
            if (e.target && e.target.closest && e.target.closest('.excel-filter-dropdown')) {
                return;
            }
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
