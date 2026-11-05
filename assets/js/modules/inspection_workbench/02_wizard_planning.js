function toggleModalBoxExcelFilter(event, col) {
    if (event) event.stopPropagation();
    var dropdown = document.getElementById('filter-dropdown-box-' + col);
    if (!dropdown) return;

    var isVisible = (dropdown.style.display === 'block');
    closeAllModalBoxExcelFilters();

    if (!isVisible) {
        dropdown.style.display = 'block';
        ModalBoxFilterState.activeCol = col;
        var searchInput = dropdown.querySelector('.excel-filter-search');
        if (searchInput) {
            searchInput.value = '';
            onSearchModalBoxFilterOptions(col, '');
            setTimeout(function() { searchInput.focus(); }, 40);
        }
    }
}

function closeAllModalBoxExcelFilters() {
    document.querySelectorAll('.excel-filter-dropdown[id^="filter-dropdown-box-"]').forEach(function(dd) {
        dd.style.display = 'none';
    });
    ModalBoxFilterState.activeCol = null;
}

function onSearchModalBoxFilterOptions(col, keyword) {
    var container = document.getElementById('filter-list-box-' + col);
    if (!container) return;
    var kw = (keyword || '').toLowerCase().trim();
    var items = container.querySelectorAll('.excel-filter-item');
    items.forEach(function(item) {
        var val = (item.getAttribute('data-val') || '').toLowerCase();
        if (!kw || val.indexOf(kw) !== -1) {
            item.style.display = 'flex';
        } else {
            item.style.display = 'none';
        }
    });
}

function toggleSelectAllModalBoxFilter(col, selectAll) {
    var container = document.getElementById('filter-list-box-' + col);
    if (!container) return;
    var checkboxes = container.querySelectorAll('input[type="checkbox"]');
    checkboxes.forEach(function(cb) {
        if (cb.closest('.excel-filter-item').style.display !== 'none') {
            cb.checked = selectAll;
        }
    });
}

function populateAndApplyModalBoxExcelFilters() {
    if (typeof scannedLabelsList === 'undefined') return;

    var uniqueLots = new Set();
    var uniqueRefs = new Set();
    var uniqueOrigins = new Set();

    scannedLabelsList.forEach(function(lbl) {
        var lotVal = (lbl.Z2 || '-').trim();
        var refVal = (lbl.Z5 || '-').trim();
        uniqueLots.add(lotVal);
        uniqueRefs.add(refVal);

        var isLabelReinspect = lbl.was_rejected_before ||
                              (lbl.remarks && (lbl.remarks.toUpperCase().indexOf('RE-INSPEKSI') !== -1 || lbl.remarks.toUpperCase().indexOf('REINSPEKSI') !== -1)) ||
                              (lbl.reinspection_notes && (lbl.reinspection_notes.toUpperCase().indexOf('RE-INSPEKSI') !== -1 || lbl.reinspection_notes.toUpperCase().indexOf('REINSPEKSI') !== -1));
        if (lbl.is_safety_stock_alloc) {
            uniqueOrigins.add('safety_stock');
        } else if (isLabelReinspect) {
            uniqueOrigins.add('reinspect');
        } else {
            uniqueOrigins.add('fresh');
        }
    });

    renderModalBoxFilterCheckboxes('lot', Array.from(uniqueLots).sort(), function(val) { return '#' + val; });
    renderModalBoxFilterCheckboxes('ref', Array.from(uniqueRefs).sort(), function(val) { return val; });
    
    var originDisplayMap = {
        'fresh': '✓ Lot Baru (Fresh)',
        'safety_stock': '📦 Asal: Safety Stock',
        'reinspect': '⚠️ Riwayat: Eks-NG'
    };
    renderModalBoxFilterCheckboxes('origin', Array.from(uniqueOrigins), function(val) { return originDisplayMap[val] || val; });

    executeModalBoxFiltering();
}

function renderModalBoxFilterCheckboxes(col, values, labelFormatter) {
    var container = document.getElementById('filter-list-box-' + col);
    if (!container) return;

    if (values.length === 0) {
        container.innerHTML = '<div style="color: #94a3b8; font-size: 10px; font-style: italic; padding: 4px;">Tidak ada data box</div>';
        return;
    }

    var selectedSet = ModalBoxFilterState.filters[col];
    var html = '';

    values.forEach(function(val) {
        var isChecked = (selectedSet.size === 0 || selectedSet.has(val));
        var labelText = labelFormatter ? labelFormatter(val) : val;

        html += 
            '<label class="excel-filter-item" data-val="' + escapeHtml(val) + '">' +
                '<input type="checkbox" value="' + escapeHtml(val) + '" ' + (isChecked ? 'checked' : '') + '>' +
                '<span style="font-size: 10.5px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">' + escapeHtml(labelText) + '</span>' +
            '</label>';
    });

    container.innerHTML = html;
}

function applyModalBoxExcelFilters() {
    ['lot', 'ref', 'origin'].forEach(function(col) {
        var container = document.getElementById('filter-list-box-' + col);
        if (!container) return;

        var checkboxes = container.querySelectorAll('input[type="checkbox"]');
        var allCount = checkboxes.length;
        var checkedValues = [];

        checkboxes.forEach(function(cb) {
            if (cb.checked) {
                checkedValues.push(cb.value);
            }
        });

        var btn = document.querySelector('.th-filter-btn[data-box-col="' + col + '"]');

        if (checkedValues.length === 0 || checkedValues.length === allCount) {
            ModalBoxFilterState.filters[col].clear();
            if (btn) btn.classList.remove('active');
        } else {
            ModalBoxFilterState.filters[col] = new Set(checkedValues);
            if (btn) btn.classList.add('active');
        }
    });

    closeAllModalBoxExcelFilters();
    executeModalBoxFiltering();
}

function resetSingleModalBoxFilter(col) {
    ModalBoxFilterState.filters[col].clear();
    var btn = document.querySelector('.th-filter-btn[data-box-col="' + col + '"]');
    if (btn) btn.classList.remove('active');

    var container = document.getElementById('filter-list-box-' + col);
    if (container) {
        container.querySelectorAll('input[type="checkbox"]').forEach(function(cb) { cb.checked = true; });
    }

    closeAllModalBoxExcelFilters();
    executeModalBoxFiltering();
}

function resetAllModalBoxExcelFilters() {
    ['lot', 'ref', 'origin'].forEach(function(col) {
        ModalBoxFilterState.filters[col].clear();
        var btn = document.querySelector('.th-filter-btn[data-box-col="' + col + '"]');
        if (btn) btn.classList.remove('active');

        var container = document.getElementById('filter-list-box-' + col);
        if (container) {
            container.querySelectorAll('input[type="checkbox"]').forEach(function(cb) { cb.checked = true; });
        }
    });

    closeAllModalBoxExcelFilters();
    executeModalBoxFiltering();
}

function executeModalBoxFiltering() {
    var rows = document.querySelectorAll('#scanned-labels-table-body tr.scanned-box-row');
    var resetAllBtn = document.getElementById('btn-reset-box-filters');
    
    var hasActiveFilter = (ModalBoxFilterState.filters.lot.size > 0 ||
                           ModalBoxFilterState.filters.ref.size > 0 ||
                           ModalBoxFilterState.filters.origin.size > 0);

    if (resetAllBtn) {
        resetAllBtn.style.display = hasActiveFilter ? 'inline-flex' : 'none';
    }

    if (!rows || rows.length === 0) return;

    var visibleCount = 0;

    rows.forEach(function(row) {
        var rowLot = row.getAttribute('data-box-lot');
        var rowRef = row.getAttribute('data-box-ref');
        var rowOrigin = row.getAttribute('data-box-origin');

        var matchLot = (ModalBoxFilterState.filters.lot.size === 0 || ModalBoxFilterState.filters.lot.has(rowLot));
        var matchRef = (ModalBoxFilterState.filters.ref.size === 0 || ModalBoxFilterState.filters.ref.has(rowRef));
        var matchOrigin = (ModalBoxFilterState.filters.origin.size === 0 || ModalBoxFilterState.filters.origin.has(rowOrigin));

        if (matchLot && matchRef && matchOrigin) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    // Handle state jika semua baris tersembunyi karena filter
    var noMatchRow = document.getElementById('scanned-box-filter-no-match');
    var tbody = document.getElementById('scanned-labels-table-body');
    if (visibleCount === 0 && rows.length > 0) {
        if (!noMatchRow && tbody) {
            var tr = document.createElement('tr');
            tr.id = 'scanned-box-filter-no-match';
            tr.innerHTML = '<td colspan="6" class="px-3 py-6 text-center text-amber-700 font-bold text-xs bg-amber-50/70 border-b border-amber-200">🔍 Tidak ada box yang cocok dengan kriteria filter yang Anda pilih. <button type="button" onclick="resetAllModalBoxExcelFilters()" style="color:#2563eb; text-decoration:underline; font-weight:800; cursor:pointer; background:none; border:none;">Reset Filter</button></td>';
            tbody.appendChild(tr);
        }
    } else if (noMatchRow) {
        noMatchRow.remove();
    }
}

// Global click listener to close Excel filter dropdowns when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('.excel-filter-dropdown') && !e.target.closest('.th-filter-btn')) {
        closeAllModalBoxExcelFilters();
    }
});

function toggleNativeFullscreen() {
    if (!document.fullscreenElement && !document.webkitFullscreenElement && !document.msFullscreenElement) {
        var el = document.documentElement;
        if (el.requestFullscreen) {
            el.requestFullscreen().catch(function(e){});
        } else if (el.webkitRequestFullscreen) {
            el.webkitRequestFullscreen();
        } else if (el.msRequestFullscreen) {
            el.msRequestFullscreen();
        }
    } else {
        if (document.exitFullscreen) {
            document.exitFullscreen().catch(function(e){});
        } else if (document.webkitExitFullscreen) {
            document.webkitExitFullscreen();
        } else if (document.msExitFullscreen) {
            document.msExitFullscreen();
        }
    }
}

function resetScanModalState() {
    // 1. Always return wizard UI to Step 1: Pilih Planning
    if (typeof backToStep1 === 'function') backToStep1();

    // 2. Clear scanned labels list and state
    scannedLabelsList = [];
    selectedPlanningId = 0;
    selectedPlanningType = 'kanban';
    selectedPlanningPartCode = '';
    selectedPlanningQty = 0;
    selectedPlanningTotalPassedQty = 0;
    selectedPlanningTargetTotalQty = 0;
    selectedAvailableSsQty = 0;
    isSafetyStockDeducted = false;
    availableSsLots = [];
    selectedSsSessionIds = [];
    selectedSsLotIds = [];

    // 3. Reset form input fields
    if (document.getElementById('scan-part-code')) document.getElementById('scan-part-code').value = '';
    if (document.getElementById('scan-lot-number')) document.getElementById('scan-lot-number').value = '064';
    if (document.getElementById('scan-qty-box')) document.getElementById('scan-qty-box').value = '';
    if (document.getElementById('scan-ref-number')) document.getElementById('scan-ref-number').value = '';
    if (document.getElementById('scan-qr-raw')) document.getElementById('scan-qr-raw').value = '';
    if (typeof clearSsLotSearch === 'function') clearSsLotSearch();

    if (document.getElementById('selected-plan-part-code')) document.getElementById('selected-plan-part-code').textContent = 'AUTOMATIC FIFO MATCHING';
    if (document.getElementById('selected-plan-desc')) document.getElementById('selected-plan-desc').textContent = 'Sistem otomatis mencocokkan planning berdasarkan Part Code';
    if (document.getElementById('selected-plan-cust')) document.getElementById('selected-plan-cust').textContent = '-';
    if (document.getElementById('selected-plan-qty')) document.getElementById('selected-plan-qty').textContent = '-';
    if (document.getElementById('selected-plan-tag')) document.getElementById('selected-plan-tag').classList.add('hidden');
    if (document.getElementById('selected-plan-kanban-no')) document.getElementById('selected-plan-kanban-no').textContent = '-';
    if (document.getElementById('selected-plan-eta')) document.getElementById('selected-plan-eta').textContent = '-';

    // 4. Update UI tables & cards
    setPlanningTypeTab('kanban');

    if (typeof resetLiveScanAlertPanel === 'function') resetLiveScanAlertPanel();
    if (typeof renderScannedLabelsTable === 'function') renderScannedLabelsTable();
    if (typeof updateSafetyStockDeductionUI === 'function') updateSafetyStockDeductionUI();
}

var currentModalScope = 'today'; // 'today' | 'pending_yesterday'
var currentModalBatchId = 'all';
var cachedPlanningItems = [];

function forceRefreshModalPlanning() {
    var btn = document.getElementById('btn-modal-force-refresh');
    var icon = document.getElementById('modal-refresh-spinner-icon');
    var txt = document.getElementById('modal-refresh-btn-text');

    if (btn) {
        btn.disabled = true;
        btn.classList.add('opacity-75');
    }
    if (icon) {
        icon.classList.add('animate-spin');
    }
    if (txt) {
        txt.textContent = 'Memperbarui...';
    }

    // Kosongkan cache client-side
    cachedPlanningItems = [];

    // Panggil refresh dengan parameter force
    refreshPlanningItemsAjax(null, true, function() {
        if (btn) {
            btn.disabled = false;
            btn.classList.remove('opacity-75');
        }
        if (icon) {
            icon.classList.remove('animate-spin');
        }
        if (txt) {
            txt.textContent = 'Refresh';
        }
    });
}

function refreshPlanningItemsAjax(autoSelectId, isForce, onComplete) {
    var url = window.OQC_CONFIG.baseUrl + "/modules/inspection/session.php?action=get_planning_items";
    url += '&scope=' + encodeURIComponent(currentModalScope);
    url += '&batch_id=' + encodeURIComponent(currentModalBatchId);
    if (autoSelectId) {
        url += '&selected_id=' + encodeURIComponent(autoSelectId);
    }
    if (isForce) {
        url += '&_nocache=' + Date.now() + '&force_refresh=1';
    }
    
    var tbody = document.getElementById('planning-table-body');
    if (tbody) {
        tbody.innerHTML = '<tr><td colspan="7" class="px-4 py-12 text-center text-slate-400">' +
            '<div class="inline-block animate-spin rounded-full h-7 w-7 border-b-2 border-blue-600 mb-2"></div>' +
            '<p class="font-bold text-slate-600 text-xs">Memuat data jadwal Kanban langsung dari database...</p>' +
            '</td></tr>';
    }

    fetch(url)
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (typeof onComplete === 'function') onComplete();
            if (res && res.success) {
                cachedPlanningItems = Array.isArray(res.items) ? res.items : [];
                renderModalBatchPills(res.today_batches || [], res.pending_yesterday_count || 0);
                renderModalKpiSummary(res.kpi_stats || {}, res.user_daily_stats || {});
                renderPlanningTableRows(cachedPlanningItems);

                if (autoSelectId) {
                    var targetPlan = cachedPlanningItems.find(function(p) { return parseInt(p.id) === parseInt(autoSelectId); });
                    if (targetPlan && (!selectedPlanningId || selectedPlanningId !== parseInt(targetPlan.id))) {
                        var targetTotalQty = parseInt(targetPlan.qty) || 0;
                        var totalPassedQty = parseInt(targetPlan.total_passed_qty) || 0;
                        var remainingQty = Math.max(0, targetTotalQty - totalPassedQty);
                        var isSafetyStock = (targetPlan.plan_type === 'safety_stock' || (targetPlan.batch_plan_type || '') === 'safety_stock' || (targetPlan.kanban_no || '').toUpperCase().indexOf('SS') === 0);
                        var planTypeAttr = isSafetyStock ? 'safety_stock' : 'kanban';
                        selectPlanningItem(
                            parseInt(targetPlan.id),
                            targetPlan.item_code,
                            targetPlan.display_part_name || targetPlan.item_description,
                            targetPlan.customer || 'PT. Indonesia Epson Industry',
                            remainingQty,
                            targetPlan.check_type || '',
                            planTypeAttr,
                            parseInt(targetPlan.avail_ss_qty) || 0,
                            totalPassedQty,
                            targetTotalQty,
                            targetPlan.kanban_no || '',
                            targetPlan.eta || targetPlan.req_date || ''
                        );
                    }
                }
            }
        })
        .catch(function(err) {
            console.error('Gagal memperbarui list planning items:', err);
            if (tbody) {
                tbody.innerHTML = '<tr><td colspan="7" class="px-4 py-8 text-center text-rose-500 font-bold text-xs">Gagal memuat data dari server. Silakan coba lagi.</td></tr>';
            }
        });
}

var currentModalStatusFilter = 'all';
var showCompletedKanban = false; // Default: Sembunyikan Kanban yang sudah selesai 100%

function toggleCompletedKanbanVisibility() {
    showCompletedKanban = !showCompletedKanban;
    updateCompletedToggleButton();
    filterPlanningItems();
}

function updateCompletedToggleButton() {
    var btn = document.getElementById('btn-toggle-completed-kanban');
    var lbl = document.getElementById('btn-toggle-completed-label');
    var cntEl = document.getElementById('btn-toggle-completed-count');
    
    // Hitung berapa kanban selesai dari cachedPlanningItems
    var completedCount = 0;
    if (Array.isArray(cachedPlanningItems)) {
        completedCount = cachedPlanningItems.filter(function(k) {
            var targetQty = parseInt(k.qty) || 0;
            var passedQty = parseInt(k.total_passed_qty) || 0;
            var pct = (targetQty > 0) ? Math.round((passedQty / targetQty) * 100) : 0;
            return (pct >= 100 || k.status === 'completed');
        }).length;
    }
    if (cntEl) cntEl.textContent = completedCount;

    if (!btn || !lbl) return;

    if (showCompletedKanban) {
        btn.className = 'px-3 py-2 text-xs font-bold rounded-lg border border-emerald-500 bg-emerald-600 text-white hover:bg-emerald-700 transition-all flex items-center gap-1.5 flex-shrink-0 shadow-xs cursor-pointer';
        lbl.innerHTML = 'Sembunyikan Selesai (<span id="btn-toggle-completed-count">' + completedCount + '</span>)';
    } else {
        btn.className = 'px-3 py-2 text-xs font-bold rounded-lg border border-emerald-300 bg-emerald-50 text-emerald-800 hover:bg-emerald-100 transition-all flex items-center gap-1.5 flex-shrink-0 shadow-xs cursor-pointer';
        lbl.innerHTML = 'Lihat Selesai (<span id="btn-toggle-completed-count">' + completedCount + '</span>)';
    }
}

function renderModalKpiSummary(kpi, userStats) {
    // 1. Update 6 Kartu KPI
    var valTotal = document.getElementById('kpi-val-total');
    var valUncompleted = document.getElementById('kpi-val-uncompleted');
    var subUncompleted = document.getElementById('kpi-sub-uncompleted');
    var valActive = document.getElementById('kpi-val-active');
    var valDone = document.getElementById('kpi-val-completed');
    var valRemain = document.getElementById('kpi-val-remain');
    var valRej = document.getElementById('kpi-val-rejected');

    var uninsp = parseInt(kpi.uninspected) || 0;
    var partial = parseInt(kpi.partial) || 0;
    var uncompleted = (typeof kpi.uncompleted !== 'undefined') ? (parseInt(kpi.uncompleted) || 0) : (uninsp + partial);

    if (valTotal) valTotal.textContent = (parseInt(kpi.total) || 0).toLocaleString();
    if (valUncompleted) valUncompleted.textContent = uncompleted.toLocaleString();
    if (subUncompleted) subUncompleted.textContent = uninsp.toLocaleString() + ' Belum Mulai • ' + partial.toLocaleString() + ' Parsial';
    if (valActive) valActive.textContent = (parseInt(kpi.active) || 0).toLocaleString();
    if (valDone) valDone.textContent = (parseInt(kpi.completed) || 0).toLocaleString();
    if (valRemain) valRemain.textContent = (parseInt(kpi.remain) || 0).toLocaleString();
    if (valRej) valRej.textContent = (parseInt(kpi.rejected) || 0).toLocaleString();

    // 2. Update Personal Work Tracker (Pencapaian Saya Hari Ini)
    var uKb = document.getElementById('user-stat-completed-kb');
    var uLots = document.getElementById('user-stat-total-lots');
    var uPass = document.getElementById('user-stat-passed-lots');
    var uNg = document.getElementById('user-stat-ng-lots');

    if (uKb) uKb.textContent = (parseInt(userStats.completed_kanban) || 0).toLocaleString();
    if (uLots) uLots.textContent = (parseInt(userStats.total_lots) || 0).toLocaleString();
    if (uPass) uPass.textContent = (parseInt(userStats.passed_lots) || 0).toLocaleString();
    if (uNg) uNg.textContent = (parseInt(userStats.ng_lots) || 0).toLocaleString();

    updateCompletedToggleButton();
    updateKpiActiveCardStyles();
}

function filterModalByStatus(status) {
    if (status === 'remain') {
        // Klik REMAIN langsung switch batch scope ke pending_yesterday (sisa kemarin)
        setModalBatchScope('pending_yesterday', 'all');
        return;
    }

    // Jika sebelumnya sedang di scope pending_yesterday lalu klik status lain, kembalikan scope ke today
    if (currentModalScope === 'pending_yesterday') {
        currentModalScope = 'today';
        currentModalBatchId = 'all';
        currentModalStatusFilter = status || 'all';
        if (status === 'completed') showCompletedKanban = true;
        updateKpiActiveCardStyles();
        refreshPlanningItemsAjax();
        return;
    }

    // Jika user mengklik filter 'completed', otomatis aktifkan showCompletedKanban
    if (status === 'completed') {
        showCompletedKanban = true;
        updateCompletedToggleButton();
    }

    // Jika status yang sama diklik lagi, toggle kembali ke 'all'
    if (currentModalStatusFilter === status && status !== 'all') {
        currentModalStatusFilter = 'all';
    } else {
        currentModalStatusFilter = status || 'all';
    }
    updateKpiActiveCardStyles();
    filterPlanningItems();
}

function updateKpiActiveCardStyles() {
    var kpiTypes = ['all', 'uncompleted', 'active', 'completed', 'remain', 'rejected', 'my_kanban'];
    kpiTypes.forEach(function(t) {
        var card = document.getElementById('kpi-btn-' + t);
        if (!card) return;
        var isActive = false;
        if (t === 'remain') {
            isActive = (currentModalScope === 'pending_yesterday');
        } else {
            isActive = (t === currentModalStatusFilter);
        }

        if (isActive) {
            card.classList.add('kpi-active-selected');
            card.style.outline = (t === 'my_kanban' || t === 'remain') ? '2.5px solid #6366f1' : '2.5px solid #2563eb';
            card.style.outlineOffset = '2px';
        } else {
            card.classList.remove('kpi-active-selected');
            card.style.outline = 'none';
            card.style.outlineOffset = '0px';
        }
    });
}

function renderModalBatchPills(todayBatches, pendingYesterdayCount) {
    var bar = document.getElementById('modal-batch-pills-bar');
    if (!bar) return;

    var html = '';

    // 1. Batch Hari Ini Pills
    if (todayBatches && todayBatches.length > 0) {
        for (var i = 0; i < todayBatches.length; i++) {
            var b = todayBatches[i];
            var bId = String(b.id);
            var bNum = i + 1;
            var bTime = b.imported_at ? b.imported_at.substring(11, 16) : '-';
            var bTotal = parseInt(b.item_count) || 0;
            var bDone = parseInt(b.completed_count) || 0;
            var isCurrentActive = (currentModalScope === 'today' && currentModalBatchId === bId);

            var activeClass = isCurrentActive 
                ? 'background:#0f172a; color:#ffffff; border:1.5px solid #0f172a; box-shadow:0 2px 4px rgba(15,23,42,0.3);' 
                : 'background:#f1f5f9; color:#1e293b; border:1.5px solid #94a3b8; box-shadow:0 1px 2px rgba(0,0,0,0.06);';

            var hoverClasses = isCurrentActive ? '' : 'hover:bg-slate-200 hover:border-blue-500 hover:text-blue-900 active:scale-95';

            html += '<button type="button" onclick="setModalBatchScope(\'today\', \'' + bId + '\')" class="' + hoverClasses + '" style="' + activeClass + ' padding:5px 12px; border-radius:8px; font-size:11px; font-weight:800; cursor:pointer; display:inline-flex; align-items:center; gap:6px; flex-shrink:0; transition:all 0.15s ease;">' +
                        '<span>Batch ' + bNum + ' (' + bTime + ')</span>' +
                        '<span style="' + (isCurrentActive ? 'background:rgba(255,255,255,0.25); color:#ffffff;' : 'background:#cbd5e1; color:#0f172a;') + ' padding:1.5px 7px; border-radius:5px; font-size:10px; font-weight:800;">' + bDone + '/' + bTotal + ' Selesai</span>' +
                    '</button>';
        }

        // Tombol: Semua Batch Hari Ini
        var isAllActive = (currentModalScope === 'today' && currentModalBatchId === 'all');
        var allClass = isAllActive 
            ? 'background:#1d4ed8; color:#ffffff; border:1.5px solid #1e40af; box-shadow:0 2px 5px rgba(29,78,216,0.35);' 
            : 'background:#eff6ff; color:#1d4ed8; border:1.5px solid #60a5fa; box-shadow:0 1px 2px rgba(37,99,235,0.08);';

        var allHover = isAllActive ? '' : 'hover:bg-blue-100 hover:border-blue-600 active:scale-95';

        var totalAllToday = todayBatches.reduce(function(acc, item) { return acc + (parseInt(item.item_count) || 0); }, 0);
        html += '<button type="button" onclick="setModalBatchScope(\'today\', \'all\')" class="' + allHover + '" style="' + allClass + ' padding:5px 13px; border-radius:8px; font-size:11px; font-weight:800; cursor:pointer; display:inline-flex; align-items:center; gap:6px; flex-shrink:0; transition:all 0.15s ease;">' +
                    '<span>Semua Batch Hari Ini</span>' +
                    '<span style="' + (isAllActive ? 'background:rgba(255,255,255,0.25); color:#ffffff;' : 'background:#bfdbfe; color:#1e40af;') + ' padding:1.5px 7px; border-radius:5px; font-size:10px; font-weight:900;">' + totalAllToday + ' Part</span>' +
                '</button>';
    }

    // 2. Tombol Spesial: Tunggakan Kemarin (Highlight Kuning/Amber Solid)
    if (pendingYesterdayCount > 0) {
        var isPyActive = (currentModalScope === 'pending_yesterday');
        var pyClass = isPyActive
            ? 'background:#d97706; color:#ffffff; border:1.5px solid #b45309; box-shadow:0 2px 5px rgba(217,119,6,0.35);'
            : 'background:#fef3c7; color:#92400e; border:1.5px solid #f59e0b; box-shadow:0 1px 2px rgba(245,158,11,0.15);';

        var pyHover = isPyActive ? '' : 'hover:bg-amber-200 hover:border-amber-600 active:scale-95';

        html += '<button type="button" onclick="setModalBatchScope(\'pending_yesterday\', \'all\')" class="' + pyHover + '" style="' + pyClass + ' padding:5px 13px; border-radius:8px; font-size:11px; font-weight:800; cursor:pointer; display:inline-flex; align-items:center; gap:6px; flex-shrink:0; transition:all 0.15s ease;" title="Kanban dari hari-hari sebelumnya yang belum selesai diinspeksi atau rejected">' +
                    '<span style="width:7px; height:7px; border-radius:50%; background:' + (isPyActive ? '#ffffff' : '#f59e0b') + ';"></span>' +
                    '<span>Tunggakan Kemarin</span>' +
                    '<span style="' + (isPyActive ? 'background:rgba(255,255,255,0.25); color:#ffffff;' : 'background:#fde68a; color:#78350f;') + ' padding:1.5px 7px; border-radius:5px; font-size:10px; font-weight:900;">' + pendingYesterdayCount + ' Belum Selesai</span>' +
                '</button>';
    }

    bar.innerHTML = html;
}

function setModalBatchScope(scope, batchId) {
    currentModalScope = scope;
    currentModalBatchId = batchId;
    currentPlanTypeTab = 'kanban';
    currentModalStatusFilter = 'all'; // Reset filter status ketika ganti batch
    updateKpiActiveCardStyles();
    var ssBanner = document.getElementById('safety-stock-direct-card');
    if (ssBanner) ssBanner.classList.add('hidden');
    refreshPlanningItemsAjax();
}

function toggleSafetyStockTab() {
    currentPlanTypeTab = 'safety_stock';
    var ssBanner = document.getElementById('safety-stock-direct-card');
    if (ssBanner) ssBanner.classList.remove('hidden');
    startDirectSafetyStockScan();
}

function renderPlanningTableRows(items) {
    var tbody = document.getElementById('planning-table-body');
    if (!tbody) return;

    if (!items || items.length === 0) {
        var emptyMsg = (currentModalScope === 'pending_yesterday') 
            ? 'Tidak ada tunggakan Kanban dari hari kemarin. Semua Kanban kemarin telah selesai diinspeksi.' 
            : 'Belum ada data Kanban yang sesuai untuk batch ini.';
        tbody.innerHTML = '<tr><td colspan="7" class="px-4 py-12 text-center text-slate-400 font-bold text-xs">' + emptyMsg + '</td></tr>';
        updatePlanningTableCount(0);
        return;
    }

    var html = '';
    var todayStr = new Date().toISOString().substring(0, 10);

    for (var i = 0; i < items.length; i++) {
        var k = items[i];
        var kid = parseInt(k.id) || 0;
        var pCode = escapeHtml(k.item_code || '');
        var pDesc = escapeHtml(k.display_part_name || k.item_description || '');
        var pCust = escapeHtml(k.customer || 'PT. Indonesia Epson Industry');
        var targetQty = parseInt(k.qty) || 0;
        var passedQty = parseInt(k.total_passed_qty) || 0;
        var remainingQty = Math.max(0, targetQty - passedQty);
        var progressPct = (targetQty > 0) ? Math.min(100, Math.round((passedQty / targetQty) * 100)) : 0;
        
        var is100Check = (k.check_type && (k.check_type.toLowerCase().indexOf('100') !== -1));
        var checkTypeBadge = is100Check ? '<span class="inline-block px-1.5 py-0.2 rounded text-[8.5px] font-bold bg-amber-50 text-amber-800 border border-amber-200 ml-1">100% Cek</span>' : '';
        
        // Cek Overdue
        var schedDate = k.eta || k.req_date || k.created_at || '';
        var schedDateOnly = schedDate ? schedDate.substring(0, 10) : '';
        var isOverdue = (schedDateOnly !== '' && schedDateOnly < todayStr && progressPct < 100);

        // Schedule formatting
        var reqDateStr = k.req_date ? formatEtaDate(k.req_date) : '-';
        var etaBadgeHtml = '';
        if (isOverdue) {
            etaBadgeHtml = '<div class="mt-1 flex items-center gap-1 flex-wrap">' +
                '<span class="px-1.5 py-0.2 rounded text-[8.5px] font-extrabold bg-rose-100 text-rose-800 border border-rose-300 inline-flex items-center gap-1 leading-tight"><span class="w-1.5 h-1.5 rounded-full bg-rose-600"></span> OVERDUE</span>' +
                '<span class="px-1.5 py-0.2 rounded text-[8.5px] font-bold bg-rose-50 text-rose-700 border border-rose-200 leading-tight">Lewat Jadwal</span>' +
            '</div>';
        } else if (k.eta) {
            etaBadgeHtml = '<div class="text-[9.5px] font-semibold text-amber-700 mt-0.5">ETA: ' + formatEtaDate(k.eta) + '</div>';
        }

        // Progress bar formatting
        var barColor = (progressPct >= 100) ? 'background: linear-gradient(90deg, #10b981 0%, #059669 100%);' :
                       (k.active_session_id || progressPct > 0) ? 'background: linear-gradient(90deg, #f59e0b 0%, #d97706 100%);' :
                       'background-color: #cbd5e1;';
        var barLabel = (progressPct >= 100) ? '100% selesai' :
                       (k.active_session_id) ? (progressPct + '% dikerjakan') :
                       (progressPct > 0) ? (progressPct + '% parsial') : 'Belum mulai';
        var barFillWidth = (progressPct >= 100) ? 100 : Math.max(progressPct > 0 ? progressPct : 0, k.active_session_id ? 15 : 0);

        // Status category group for KPI filtering & row color matching
        var isCompleted = (progressPct >= 100 || k.status === 'completed');
        var isRejected = (k.status === 'rejected');
        var isInProgress = (!isCompleted && !isRejected && (k.active_session_id || progressPct > 0 || k.status === 'in_progress' || k.status === 'partial'));
        
        var statusGroup = isInProgress ? 'in_progress' :
                          isCompleted ? 'completed' :
                          isRejected ? 'rejected' : 'uninspected';

        // Status badge & Row colors aligned with the 4 KPI card colors (using solid inline styles for 100% offline reliability)
        var statusBadge = '';
        var rowStyle = '';
        var btnBgStyle = '';
        var btnHoverClass = '';
        var btnIconSvg = '';
        var btnLabel = 'Inspeksi';

        if (isCompleted) {
            // Hijau - Selesai
            rowStyle = 'background-color: #ecfdf5; border-left: 4.5px solid #10b981;';
            statusBadge = '<span style="background-color: #059669; color: #ffffff; font-weight: 800; font-size: 10px; padding: 2.5px 8.5px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 3.5px;">' +
                          '<svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>' +
                          'Selesai' +
                          '</span>';
            btnBgStyle = 'background-color: #059669; color: #ffffff;';
            btnHoverClass = 'hover:bg-emerald-700 active:bg-emerald-800';
            btnIconSvg = '<svg class="w-3 h-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>';
            btnLabel = 'Selesai';
        } else if (isRejected) {
            // Merah - Reject / Lot NG (Tombol aksi tetap standar Inspeksi/Lanjutkan seperti biasa)
            rowStyle = 'background-color: #fff1f2; border-left: 4.5px solid #e11d48;';
            statusBadge = '<span style="background-color: #e11d48; color: #ffffff; font-weight: 800; font-size: 10px; padding: 2.5px 8.5px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 3.5px;">' +
                          '<svg class="w-2.5 h-2.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>' +
                          'Reject' +
                          '</span>';
            btnBgStyle = 'background-color: #2563eb; color: #ffffff;';
            btnHoverClass = 'hover:bg-blue-700 active:bg-blue-800';
            btnIconSvg = '<svg class="w-3 h-3 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor" style="fill: #ffffff;"><path d="M6.3 2.841A1.5 1.5 0 004 4.11V15.89a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z"/></svg>';
            btnLabel = 'Inspeksi';
        } else if (isInProgress) {
            // Kuning / Amber - Sedang Berjalan / Parsial
            rowStyle = 'background-color: #fef3c7; border-left: 4.5px solid #d97706;';
            var progressText = k.active_session_id ? 'Sedang Dikerjakan' : 'Parsial (' + progressPct + '%)';
            statusBadge = '<span style="background-color: #d97706; color: #ffffff; font-weight: 800; font-size: 10px; padding: 2.5px 8px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 3.5px;">' +
                          '<span style="width: 5px; height: 5px; border-radius: 9999px; background-color: #ffffff;"></span>' +
                          progressText +
                          '</span>';
            if (k.active_inspector_name) {
                statusBadge += '<span class="block text-[8.5px] text-amber-800 font-bold mt-0.5 truncate max-w-[120px] mx-auto">Oleh: ' + escapeHtml(k.active_inspector_name) + '</span>';
            }
            btnBgStyle = 'background-color: #d97706; color: #ffffff;';
            btnHoverClass = 'hover:bg-amber-700 active:bg-amber-800';
            btnIconSvg = '<svg class="w-3 h-3 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor" style="fill: #ffffff;"><path d="M6.3 2.841A1.5 1.5 0 004 4.11V15.89a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z"/></svg>';
            btnLabel = 'Lanjutkan';
        } else {
            // Abu-abu Slate - Belum Mulai
            rowStyle = 'background-color: #ffffff; border-left: 4.5px solid #94a3b8;';
            statusBadge = '<span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-700 border border-slate-300 inline-flex items-center gap-1.5">' +
                          '<span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span>' +
                          'Belum Mulai' +
                          '</span>';
            btnBgStyle = 'background-color: #2563eb; color: #ffffff;';
            btnHoverClass = 'hover:bg-blue-700 active:bg-blue-800';
            btnIconSvg = '<svg class="w-3 h-3 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor" style="fill: #ffffff;"><path d="M6.3 2.841A1.5 1.5 0 004 4.11V15.89a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z"/></svg>';
            btnLabel = 'Inspeksi';
        }

        var importMethod = String(k.import_method || 'manual').toLowerCase();
        var isImport = (importMethod.indexOf('excel') !== -1 || importMethod.indexOf('csv') !== -1);
        var sourceBadge = isImport ? '<span class="inline-flex items-center px-1.5 py-0.2 rounded text-[8.5px] font-extrabold bg-teal-50 text-teal-700 border border-teal-200 uppercase">Import</span>' : '<span class="inline-flex items-center px-1.5 py-0.2 rounded text-[8.5px] font-extrabold bg-slate-100 text-slate-600 border border-slate-200 uppercase">Manual</span>';

        // Badge Batch (Batch 1, Batch 2...) saat melihat Semua Batch Hari Ini
        var batchBadge = '';
        if (currentModalScope === 'today' && currentModalBatchId === 'all' && k.batch_number) {
            batchBadge = '<span class="inline-flex items-center px-1.5 py-0.2 rounded text-[8.5px] font-black bg-blue-50 text-blue-700 border border-blue-200 uppercase">Batch ' + k.batch_number + '</span>';
        }

        var codeEsc = (k.item_code || '').replace(/\\/g, "\\\\").replace(/'/g, "\\'");
        var descEsc = (k.display_part_name || k.item_description || '').replace(/\\/g, "\\\\").replace(/'/g, "\\'");
        var custEsc = (k.customer || 'PT. Indonesia Epson Industry').replace(/\\/g, "\\\\").replace(/'/g, "\\'");
        var cekEsc = (k.check_type || '').replace(/\\/g, "\\\\").replace(/'/g, "\\'");
        var kbNoEsc = (k.kanban_no || '').replace(/\\/g, "\\\\").replace(/'/g, "\\'");
        var etaEsc = (k.eta || k.req_date || '').replace(/\\/g, "\\\\").replace(/'/g, "\\'");
        var availSsQty = parseInt(k.avail_ss_qty) || 0;

        var isMyKb = (parseInt(k.is_my_kanban) === 1 || (k.active_inspector_name && k.active_inspector_name.toLowerCase() === (window.OQC_CONFIG.currentUserName || '').toLowerCase())) ? '1' : '0';
        var hasActiveSess = !!k.active_session_id;
        var isPartial = (progressPct > 0 && progressPct < 100);
        var isUncompleted = (!isCompleted);

        html += '<tr class="kanban-table-row transition-colors" ' +
                    'style="' + rowStyle + '" ' +
                    'data-kanban-id="' + kid + '" ' +
                    'data-status-group="' + statusGroup + '" ' +
                    'data-is-active="' + (hasActiveSess ? '1' : '0') + '" ' +
                    'data-is-partial="' + (isPartial ? '1' : '0') + '" ' +
                    'data-is-uncompleted="' + (isUncompleted ? '1' : '0') + '" ' +
                    'data-is-my-kanban="' + isMyKb + '" ' +
                    'data-part-code="' + pCode.toLowerCase() + '" ' +
                    'data-part-name="' + pDesc.toLowerCase() + '" ' +
                    'data-kanban-no="' + (k.kanban_no ? String(k.kanban_no).toLowerCase() : '') + '" ' +
                    'data-customer="' + pCust.toLowerCase() + '">' +
                    
                    // Col 1: #
                    '<td class="text-center px-1.5 py-2.5 font-bold text-slate-500 font-mono text-[11px]">' + (i + 1) + '</td>' +
                    
                    // Col 2: Kanban No & Cust
                    '<td class="px-3 py-2.5">' +
                        '<div class="flex items-center space-x-1.5 flex-wrap gap-y-1">' +
                            '<span class="font-mono font-black text-slate-900 text-xs">#' + escapeHtml(k.kanban_no || '-') + '</span>' +
                            batchBadge +
                            sourceBadge +
                        '</div>' +
                        '<div class="text-[11px] text-slate-500 font-medium truncate max-w-[190px]" title="' + pCust + '">' + pCust + '</div>' +
                    '</td>' +
                    
                    // Col 3: Part Code & Name
                    '<td class="px-3 py-2.5">' +
                        '<span class="font-mono font-extrabold text-blue-700 text-xs block">' + pCode + '</span>' +
                        '<span class="text-[11px] text-slate-600 font-medium block truncate max-w-[220px]" title="' + pDesc + '">' + pDesc + '</span>' +
                        checkTypeBadge +
                    '</td>' +
                    
                    // Col 4: Jadwal & ETA
                    '<td class="px-2.5 py-2.5 whitespace-nowrap">' +
                        '<div class="font-medium text-slate-700 text-[10px]">' + reqDateStr + '</div>' +
                        etaBadgeHtml +
                    '</td>' +
                    
                    // Col 5: Target & Progres
                    '<td class="px-2.5 py-2.5 text-center whitespace-nowrap">' +
                        '<div class="font-mono font-black text-xs text-slate-800">' +
                            '<span>' + passedQty.toLocaleString() + '</span> <span class="text-slate-400 font-normal">/</span> ' + targetQty.toLocaleString() + ' <span class="text-[10px] text-slate-500 font-medium">pcs</span>' +
                        '</div>' +
                        '<div class="w-16 rounded-full overflow-hidden mx-auto my-1 bg-slate-200" style="height: 6px; border: 1px solid #cbd5e1;">' +
                            '<div style="height: 100%; width: ' + barFillWidth + '%; ' + barColor + ' border-radius: 9999px;"></div>' +
                        '</div>' +
                        '<span class="text-[9.5px] font-bold text-slate-500 block leading-none">' + barLabel + '</span>' +
                    '</td>' +
                    
                    // Col 6: Status
                    '<td class="px-2.5 py-2.5 text-center whitespace-nowrap">' +
                        statusBadge +
                    '</td>' +
                    
                    // Col 7: Aksi Button
                    '<td class="px-3 py-2.5 text-center whitespace-nowrap">' +
                        '<button type="button" onclick="selectPlanningItem(' + kid + ', \'' + codeEsc + '\', \'' + descEsc + '\', \'' + custEsc + '\', ' + remainingQty + ', \'' + cekEsc + '\', \'kanban\', ' + availSsQty + ', ' + passedQty + ', ' + targetQty + ', \'' + kbNoEsc + '\', \'' + etaEsc + '\')" ' +
                                'style="' + btnBgStyle + '" ' +
                                'class="w-full py-1.5 px-2.5 ' + btnHoverClass + ' font-extrabold text-xs rounded-md shadow-xs hover:shadow transition-all inline-flex items-center justify-center gap-1 cursor-pointer">' +
                            btnIconSvg +
                            '<span>' + btnLabel + '</span>' +
                        '</button>' +
                    '</td>' +
                '</tr>';
    }

    tbody.innerHTML = html;
    filterPlanningItems();
}

function updatePlanningTableCount(count) {
    var cntEl = document.getElementById('planning-visible-count');
    if (cntEl) cntEl.textContent = count;
}

function openScanModal(autoSelectId, targetPlanType) {
    resetScanModalState();
    var selectId = autoSelectId || autoKanbanId || 0;
    var planType = targetPlanType || autoScanType || 'kanban';

    var modal = document.getElementById('scan-modal-overlay');
    if (modal) {
        modal.classList.remove('hidden');
        modal.style.display = 'flex';
        modal.style.zIndex = '999999';
    }

    if (planType === 'safety_stock') {
        autoScanType = '';
        setPlanningTypeTab('safety_stock');
        return;
    }

    setPlanningTypeTab(planType);
    refreshPlanningItemsAjax(selectId);
    if (selectId > 0) {
        autoKanbanId = 0;
    }

    if (selectId > 0) {
        var qrInput = document.getElementById('scan-qr-raw');
        if (qrInput) {
            qrInput.value = '';
            setTimeout(function() { qrInput.focus(); }, 150);
        }
    } else {
        var pInput = document.getElementById('planning-search-input');
        if (pInput) {
            setTimeout(function() {
                pInput.focus();
                if (pInput.value.length > 0) pInput.select();
            }, 150);
        }
    }
}

function closeScanModal(forceClose) {
    // Proteksi data scan: Tampilkan konfirmasi jika sudah ada label yang discan
    if (!forceClose && typeof scannedLabelsList !== 'undefined' && scannedLabelsList.length > 0) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: 'Konfirmasi Tutup Modal',
                html: 'Terdapat <b>' + scannedLabelsList.length + ' label</b> yang sudah discan.<br>Jika modal ditutup, data scan sementara ini akan dibatalkan.<br><br>Yakin ingin menutup modal scan?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#2563eb',
                confirmButtonText: 'Ya, Tutup & Batalkan',
                cancelButtonText: 'Lanjutkan Scan',
                reverseButtons: true
            }).then(function(result) {
                if (result.isConfirmed) {
                    doCloseScanModal();
                }
            });
            return;
        } else {
            if (!confirm('Terdapat ' + scannedLabelsList.length + ' label yang sudah discan. Yakin ingin menutup modal?')) {
                return;
            }
        }
    }
    doCloseScanModal();
}

function doCloseScanModal() {
    window.isAddingBoxToActiveSession = false;
    clearAllScannedLabels();
    selectedSsLotIds = [];
    selectedPlanningId = 0;
    selectedPlanningPartCode = '';
    selectedPlanningQty = 0;

    var overlay = document.getElementById('scan-modal-overlay');
    if (overlay) {
        overlay.classList.add('hidden');
        overlay.style.display = 'none';
    }
    // Hapus parameter scan_new dari URL agar tidak otomatis terbuka kembali saat reload
    if (window.history && window.history.replaceState) {
        var url = new URL(window.location.href);
        if (url.searchParams.has('scan_new')) {
            url.searchParams.delete('scan_new');
            window.history.replaceState({}, document.title, url.pathname + (url.search ? url.search : ''));
        }
    }
}

// Flag untuk membedakan navigasi yang disengaja/disetujui oleh user vs tutup tab paksa
window.isWorkbenchIntentionallyExiting = false;

// Helper: Cek apakah sesi aktif masih memiliki box yang belum selesai diinspeksi (in_progress)
function hasUninspectedLotsInWorkbench() {
    if (window.isWorkbenchIntentionallyExiting) return false;
    if (!currentSessionId || !window.lastWorkbenchSessionData || !window.lastWorkbenchSessionData.session) {
        // Fallback saat scan modal awal belum submit sesi
        return (typeof scannedLabelsList !== 'undefined' && Array.isArray(scannedLabelsList) && scannedLabelsList.length > 0);
    }

    var sess = window.lastWorkbenchSessionData.session;
    var sessStatus = String(sess.status || '').toLowerCase();
    if (sessStatus === 'passed' || sessStatus === 'rejected') {
        return false;
    }

    var sessLots = window.lastWorkbenchSessionData.session_lots || [];
    for (var i = 0; i < sessLots.length; i++) {
        var lr = String(sessLots[i].lot_result || 'in_progress').toLowerCase();
        var ls = String(sessLots[i].lot_status || 'ok').toLowerCase();
        var ngCnt = parseInt(sessLots[i].ng_count || 0, 10);
        // Jika masih ada box in_progress biasa
        if (lr !== 'passed' && lr !== 'rejected' && lr !== 'skipped' && ls !== 'ng_found' && ls !== 'ng_quarantine' && ls !== 'replaced' && ls !== 'reinspected' && ngCnt === 0) {
            return true;
        }
    }
    return false;
}

// 1. Proteksi konfirmasi browser saat tab hendak ditutup / direload
window.addEventListener('beforeunload', function(e) {
    if (hasUninspectedLotsInWorkbench()) {
        e.preventDefault();
        e.returnValue = 'Anda masih memiliki box yang belum selesai diinspeksi. Keluar sekarang akan membatalkan box yang belum selesai.';
        return e.returnValue;
    }
});

// 2. Jika user tetap memilih menutup browser (pagehide / unload):
// Kirim sinyal sendBeacon ke background server untuk membersihkan box yang belum selesai
window.addEventListener('pagehide', function(e) {
    if (window.isWorkbenchIntentionallyExiting) return;
    if (hasUninspectedLotsInWorkbench() && currentSessionId) {
        try {
            var cleanupUrl = window.OQC_CONFIG.baseUrl + "/modules/inspection/api/cleanup_uninspected_lots.php";
            var fd = new FormData();
            fd.append('session_id', currentSessionId);
            if (navigator.sendBeacon) {
                navigator.sendBeacon(cleanupUrl, fd);
            } else {
                fetch(cleanupUrl, { method: 'POST', body: fd, keepalive: true });
            }
        } catch(err) {}
    }
});

function openKanbanDetailModal() {
    var modal = document.getElementById('kanban-detail-modal-overlay');
    if (modal) {
        modal.classList.remove('hidden');
        modal.style.display = 'flex';
    }
}

function closeKanbanDetailModal() {
    var modal = document.getElementById('kanban-detail-modal-overlay');
    if (modal) {
        modal.classList.add('hidden');
        modal.style.display = 'none';
    }
}

function openPrevHistoryModal() {
    var modal = document.getElementById('prev-history-modal-overlay');
    if (modal) {
        modal.classList.remove('hidden');
        modal.style.display = 'flex';
    }
}

function closePrevHistoryModal() {
    var modal = document.getElementById('prev-history-modal-overlay');
    if (modal) {
        modal.classList.add('hidden');
        modal.style.display = 'none';
    }
}

function openSubstitutionLogModal() {
    var modal = document.getElementById('substitution-log-modal-overlay');
    if (modal) {
        modal.classList.remove('hidden');
        modal.style.display = 'flex';
    }
}

function closeSubstitutionLogModal() {
    var modal = document.getElementById('substitution-log-modal-overlay');
    if (modal) {
        modal.classList.add('hidden');
        modal.style.display = 'none';
    }
}

var is3dViewerInitialized = false;

function switchDrawingTab(tab) {
    if (typeof switchDocumentTab === 'function') {
        switchDocumentTab(tab);
    }
}

function init3dViewer() {
    var container = document.getElementById('cad-3d-viewport');
    if (!container || container.style.display === 'none') return;
    var stpUrl = container.getAttribute('data-stp-url');
    if (!stpUrl) return;

    if (typeof renderCadStep === 'function') {
        renderCadStep('cad-3d-viewport', stpUrl);
        is3dViewerInitialized = true;
    } else if (typeof OQC3DViewer === 'function') {
        OQC3DViewer('cad-3d-viewport');
        is3dViewerInitialized = true;
    }
}

function adjustZoom(delta) {
    currentZoom = Math.min(2.0, Math.max(0.5, currentZoom + delta));
    var activeIframe = null;
    if (activeDocumentTab === '2d') {
        activeIframe = document.getElementById('pdf-frame');
    } else if (activeDocumentTab === 'aql') {
        activeIframe = document.getElementById('aql-doc-frame');
    } else {
        activeIframe = document.getElementById('custom-doc-frame');
    }
    if (activeIframe) {
        activeIframe.style.transform = 'scale(' + currentZoom + ')';
        activeIframe.style.transformOrigin = 'top left';
    }
    var zoomLbl = document.getElementById('zoom-level-label');
    if (zoomLbl) {
        zoomLbl.textContent = 'Zoom ' + Math.round(currentZoom * 100) + '%';
    }
}

function resetZoom() {
    currentZoom = 1.0;
    var iframe2d = document.getElementById('pdf-frame');
    if (iframe2d) {
        iframe2d.style.transform = 'scale(1)';
    }
    var iframeAql = document.getElementById('aql-doc-frame');
    if (iframeAql) {
        iframeAql.style.transform = 'scale(1)';
    }
    var iframeCustom = document.getElementById('custom-doc-frame');
    if (iframeCustom) {
        iframeCustom.style.transform = 'scale(1)';
    }
    var zoomLbl = document.getElementById('zoom-level-label');
    if (zoomLbl) {
        zoomLbl.textContent = 'Zoom 100%';
    }
}

function toggleDrawingFullscreen() {
    var panel = document.getElementById('drawing-viewer-panel');
    if (!panel) return;

    var isFull = !!(document.fullscreenElement || document.webkitFullscreenElement || document.mozFullScreenElement || document.msFullscreenElement);

    if (!isFull) {
        if (panel.requestFullscreen) {
            panel.requestFullscreen();
        } else if (panel.webkitRequestFullscreen) {
            panel.webkitRequestFullscreen();
        } else if (panel.mozRequestFullScreen) {
            panel.mozRequestFullScreen();
        } else if (panel.msRequestFullscreen) {
            panel.msRequestFullscreen();
        }
    } else {
        if (document.exitFullscreen) {
            document.exitFullscreen();
        } else if (document.webkitExitFullscreen) {
            document.webkitExitFullscreen();
        } else if (document.mozCancelFullScreen) {
            document.mozCancelFullScreen();
        } else if (document.msExitFullscreen) {
            document.msExitFullscreen();
        }
    }
}

function handleDrawingFullscreenChange() {
    var btn = document.getElementById('btn-fullscreen-toggle');
    var isFull = !!(document.fullscreenElement || document.webkitFullscreenElement || document.mozFullScreenElement || document.msFullscreenElement);
    if (btn) {
        btn.setAttribute('title', isFull ? 'Keluar Layar Penuh (Esc)' : 'Layar Penuh (Fullscreen)');
        if (isFull) {
            btn.classList.add('bg-blue-100', 'text-blue-700');
            btn.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>';
        } else {
            btn.classList.remove('bg-blue-100', 'text-blue-700');
            btn.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 4l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"></path></svg>';
        }
    }
    setTimeout(function() {
        window.dispatchEvent(new Event('resize'));
    }, 120);
}

document.addEventListener('fullscreenchange', handleDrawingFullscreenChange);
document.addEventListener('webkitfullscreenchange', handleDrawingFullscreenChange);
document.addEventListener('mozfullscreenchange', handleDrawingFullscreenChange);
document.addEventListener('MSFullscreenChange', handleDrawingFullscreenChange);


function toggleInlineNgForm(show) {
    var formBox = document.getElementById('inline-ng-form-box');
    if (show) {
        var container = document.getElementById('ng-defect-rows-container');
        var rows = container.querySelectorAll('.ng-defect-row');
        for (var i = 1; i < rows.length; i++) {
            rows[i].remove();
        }
        var firstRow = rows[0];
        if (firstRow) {
            firstRow.querySelector('.ng-defect-type').value = '';
            firstRow.querySelector('.ng-custom-defect-name').value = '';
            firstRow.querySelector('.ng-custom-defect-group').classList.add('hidden');
            firstRow.querySelector('.ng-qty').value = '1';
        }

        // Dynamically compute and update target sample number (samples_checked + 1)
        var progressEl = document.getElementById('label-sample-progress');
        if (progressEl) {
            var match = progressEl.textContent.match(/(\d+)\s*\/\s*(\d+)/);
            if (match) {
                var checked = parseInt(match[1], 10);
                var max = parseInt(match[2], 10);
                var nextSampleNum = Math.min(max, checked + 1);
                var targetLabel = document.getElementById('inline-sample-target-label');
                if (targetLabel) targetLabel.textContent = 'Sample ke-' + nextSampleNum;
            }
        }

        formBox.classList.remove('hidden');
    } else {
        formBox.classList.add('hidden');
    }
}

function onInlineDefectRowChange(sel) {
    var row = sel.closest('.ng-defect-row');
    var customGroup = row.querySelector('.ng-custom-defect-group');
    if (sel.value === 'custom') {
        customGroup.classList.remove('hidden');
        var nameInput = row.querySelector('.ng-custom-defect-name');
        if (nameInput) nameInput.focus();
    } else {
        customGroup.classList.add('hidden');
    }
}

function changeQtyRowStepper(btn, delta) {
    var row = btn.closest('.ng-defect-row');
    var input = row.querySelector('.ng-qty');
    var val = (parseInt(input.value) || 1) + delta;
    input.value = Math.max(1, val);
}

function addNgDefectRow() {
    var container = document.getElementById('ng-defect-rows-container');
    var rows = container.querySelectorAll('.ng-defect-row');
    var nextIdx = rows.length;

    var defectOptionsHtml = (window.OQC_CONFIG.defectTypesList || []).map(function(dt) { return `<option value="${dt.id}">${escapeHtml(dt.name)}</option>`; }).join("");

    var div = document.createElement('div');
    div.className = 'ng-defect-row p-2 bg-white/90 border border-rose-200 rounded-lg space-y-1.5';
    div.setAttribute('data-row-idx', nextIdx);
    div.innerHTML = `
        <div class="flex items-center justify-between mb-0.5">
            <label class="block text-[10px] font-bold text-slate-700">Jenis Defect #${nextIdx + 1}</label>
            <button type="button" onclick="this.closest('.ng-defect-row').remove()" class="text-rose-600 hover:text-rose-800 font-bold text-[10px] flex items-center space-x-0.5 px-1.5 py-0.5 rounded bg-rose-50 hover:bg-rose-100 border border-rose-200" title="Hapus defect ini">
                <svg class="w-3 h-3 mr-0.5 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                <span>Hapus</span>
            </button>
        </div>
        <div>
            <select class="ng-defect-type form-input text-xs font-semibold py-1" onchange="onInlineDefectRowChange(this)">
                <option value="">-- Pilih Jenis Defect --</option>
                ${defectOptionsHtml}
                <option value="custom">+ Defect Baru...</option>
            </select>
        </div>
        <div class="ng-custom-defect-group hidden">
            <input type="text" class="ng-custom-defect-name form-input text-xs py-1" placeholder="Nama Defect Baru...">
        </div>
        <div class="flex items-center justify-between gap-2 pt-0.5">
            <span class="text-[11px] font-bold text-slate-700">Qty NG</span>
            <div class="flex items-center space-x-1 border border-slate-300 rounded-lg bg-white p-0.5">
                <button type="button" onclick="changeQtyRowStepper(this, -1)" class="w-6 h-6 rounded bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold flex items-center justify-center text-xs">-</button>
                <input type="number" class="ng-qty w-8 text-center text-xs font-bold border-0 p-0 focus:ring-0" value="1" min="1" readonly>
                <button type="button" onclick="changeQtyRowStepper(this, 1)" class="w-6 h-6 rounded bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold flex items-center justify-center text-xs">+</button>
            </div>
        </div>
    `;
    container.appendChild(div);
}

var selectedPlanningId = 0;
var selectedPlanningType = 'kanban';
var selectedPlanningPartCode = '';
var selectedPlanningQty = 0;
var selectedPlanningTotalPassedQty = 0;
var selectedPlanningTargetTotalQty = 0;
var selectedAvailableSsQty = 0;
var isSafetyStockDeducted = false;

var availableSsLots = [];
var selectedSsSessionIds = [];
var selectedSsLotIds = [];
var ssLotSearchQuery = '';

function formatEtaDate(etaRaw) {
    if (!etaRaw || etaRaw === '-' || etaRaw === '') return 'Reguler';
    var dt = new Date(etaRaw.replace(/-/g, '/'));
    if (isNaN(dt.getTime())) return etaRaw;

    var months = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
    var day = String(dt.getDate()).padStart(2, '0');
    var mName = months[dt.getMonth()];
    var hours = String(dt.getHours()).padStart(2, '0');
    var mins = String(dt.getMinutes()).padStart(2, '0');
    var timeStr = hours + ':' + mins + ' WIB';
    var dateShort = day + ' ' + mName;

    var now = new Date();
    var todayDate = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    var targetDate = new Date(dt.getFullYear(), dt.getMonth(), dt.getDate());
    var diffDays = Math.round((targetDate - todayDate) / (1000 * 60 * 60 * 24));

    var label = '';
    if (diffDays === 0) {
        label = 'Hari ini';
    } else if (diffDays === 1) {
        label = 'Besok';
    } else if (diffDays === 2) {
        label = '2 Hari lagi';
    } else if (diffDays > 2) {
        label = diffDays + ' Hari lagi';
    } else if (diffDays === -1) {
        label = 'Kemarin';
    } else {
        label = Math.abs(diffDays) + ' Hari lalu';
    }

    return label + ' (' + dateShort + '), ' + timeStr;
}

function selectPlanningItem(id, itemCode, itemDesc, customer, qty, checkType, planType, availSsQty, totalPassedQty, targetTotalQty, kanbanNo, etaRaw) {
    clearAllScannedLabels();
    if (typeof resetLiveScanAlertPanel === 'function') resetLiveScanAlertPanel();
    selectedSsLotIds = [];
    selectedSsSessionIds = [];

    selectedPlanningId = id;
    selectedPlanningType = planType || 'kanban';
    selectedPlanningPartCode = itemCode || '';
    selectedPlanningQty = parseInt(qty) || 0;
    selectedPlanningTotalPassedQty = parseInt(totalPassedQty) || 0;
    selectedPlanningTargetTotalQty = parseInt(targetTotalQty) || selectedPlanningQty;
    selectedAvailableSsQty = parseInt(availSsQty) || 0;

    document.getElementById('selected-plan-part-code').textContent = itemCode;
    document.getElementById('selected-plan-desc').textContent = itemDesc;
    document.getElementById('selected-plan-cust').textContent = customer;

    // --- Update No. Kanban & ETA badge (prominently displayed) ---
    var kbNoEl = document.getElementById('selected-plan-kanban-no');
    var etaEl  = document.getElementById('selected-plan-eta');
    var kbBadgeEl = document.getElementById('selected-plan-kanban-badge');
    var etaBadgeEl = document.getElementById('selected-plan-eta-badge');
    if (kbNoEl) kbNoEl.textContent = (kanbanNo && kanbanNo !== '-' && kanbanNo !== '') ? kanbanNo : (planType === 'safety_stock' ? ('SS-' + id) : ('ID:' + id));
    if (etaEl)  etaEl.textContent  = formatEtaDate(etaRaw || '');
    // Pulse animation to draw attention to the newly filled badges
    if (kbBadgeEl) { kbBadgeEl.style.transition = 'transform 0.15s ease'; kbBadgeEl.style.transform = 'scale(1.08)'; setTimeout(function(){ kbBadgeEl.style.transform = 'scale(1)'; }, 180); }
    if (etaBadgeEl) { etaBadgeEl.style.transition = 'transform 0.15s ease'; etaBadgeEl.style.transform = 'scale(1.08)'; setTimeout(function(){ etaBadgeEl.style.transform = 'scale(1)'; }, 200); }
    
    totalPassedQty = parseInt(totalPassedQty) || 0;
    targetTotalQty = parseInt(targetTotalQty) || selectedPlanningQty;

    if (totalPassedQty > 0) {
        document.getElementById('selected-plan-qty').innerHTML = qty.toLocaleString() + ' pcs <span class="text-[10px] text-indigo-700 font-bold ml-1">(Cicilan Sesi Ini | Passed: ' + totalPassedQty.toLocaleString() + ' / ' + targetTotalQty.toLocaleString() + ' pcs)</span>';
    } else {
        document.getElementById('selected-plan-qty').textContent = qty.toLocaleString() + ' pcs';
    }
    
    var tagEl = document.getElementById('selected-plan-tag');
    if (checkType) {
        tagEl.textContent = checkType + ' Cek';
        tagEl.classList.remove('hidden');
    } else {
        tagEl.classList.add('hidden');
    }

    document.getElementById('scan-part-code').value = itemCode;

    // Fetch multi-lot Safety Stock sessions via API
    fetchAndRenderAvailableSsLots(itemCode, selectedPlanningQty);

    // Refresh Multi-Document Workbench Badges & Metadata (PI, Q-Point, PAC, Standar Packing)
    if (typeof fetchPartDocuments === 'function') {
        fetchPartDocuments(itemCode);
    }

    // Switch step UI
    var p1 = document.getElementById('step-pill-1');
    if (p1) {
        p1.style.cssText = 'padding: 4px 11px; border-radius: 6px; font-size: 11px; font-weight: 700; cursor: pointer; transition: all 0.15s ease; border: none; outline: none; background-color: #dcfce7; color: #166534;';
        p1.innerHTML = '<span style="width: 15px; height: 15px; border-radius: 9999px; background-color: #bbf7d0; display: inline-flex; align-items: center; justify-content: center; font-size: 9.5px; font-weight: 900; color: #15803d;">✓</span><span>Planning Terpilih</span>';
    }
    var p2 = document.getElementById('step-pill-2');
    if (p2) {
        p2.style.cssText = 'padding: 4px 11px; border-radius: 6px; font-size: 11px; font-weight: 800; cursor: pointer; transition: all 0.15s ease; border: none; outline: none; background-color: #2563eb; color: #ffffff; box-shadow: 0 1px 3px rgba(37,99,235,0.3);';
        p2.innerHTML = '<span style="width: 15px; height: 15px; border-radius: 9999px; background-color: rgba(255,255,255,0.25); display: inline-flex; align-items: center; justify-content: center; font-size: 9.5px; font-weight: 900;">2</span><span>Scan Barcode</span>';
    }
    
    var stepTag = document.getElementById('wizard-step-tag');
    if (stepTag) {
        stepTag.textContent = 'Langkah 2 dari 2';
        stepTag.style.backgroundColor = '#f0fdf4';
        stepTag.style.color = '#15803d';
        stepTag.style.borderColor = '#bbf7d0';
    }
    var titleEl = document.getElementById('wizard-modal-title');
    if (titleEl) titleEl.textContent = 'OQC Inspection Workbench';
    var subEl = document.getElementById('wizard-modal-subtitle');
    if (subEl) subEl.textContent = 'Scan QR barcode atau label box part ' + (itemCode || '') + ' untuk validasi';

    var step1El = document.getElementById('wizard-step-1-content');
    var step2El = document.getElementById('wizard-step-2-content');
    if (step1El) {
        step1El.classList.add('hidden');
        step1El.style.display = 'none';
    }
    if (step2El) {
        step2El.classList.remove('hidden');
        step2El.style.display = 'flex';
    }

    var btnChangeItem = document.getElementById('btn-change-item-header');
    if (btnChangeItem) btnChangeItem.style.display = 'inline-flex';

    renderScannedLabelsTable();

    var rawInput = document.getElementById('scan-qr-raw');
    if (rawInput) {
        rawInput.value = '';
        setTimeout(function() { rawInput.focus(); }, 150);
    }
}

function fetchAndRenderAvailableSsLots(itemCode, targetQty) {
    // Info banner Safety Stock Gudang dinonaktifkan sesuai permintaan
    availableSsLots = [];
}

function updateSafetyStockDeductionUI() {
    // Stub aman: Auto-deduction dihentikan, seluruh box Safety Stock di-scan ulang secara fisik
}

function renderSsLotRows() {
    // Info banner Safety Stock Gudang dinonaktifkan sesuai permintaan
}

function openKanbanDetailModal() {
    var modal = document.getElementById('kanban-detail-modal-overlay');
    if (modal) modal.classList.remove('hidden');
}

function closeKanbanDetailModal() {
    var modal = document.getElementById('kanban-detail-modal-overlay');
    if (modal) modal.classList.add('hidden');
}

function backToStep1(forceConfirm) {
    if (window.isAddingBoxToActiveSession) {
        closeScanModal(forceConfirm);
        return;
    }
    if (!forceConfirm && typeof scannedLabelsList !== 'undefined' && scannedLabelsList.length > 0) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: 'Konfirmasi Ganti Planning',
                html: 'Terdapat <b>' + scannedLabelsList.length + ' label</b> yang sudah discan.<br>Jika Anda mengganti planning, data scan sementara ini akan <b>dihapus/dibersihkan</b>.<br><br>Yakin ingin mengganti planning?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#2563eb',
                confirmButtonText: 'Ya, Ganti Planning',
                cancelButtonText: 'Batal & Lanjutkan Scan',
                reverseButtons: true
            }).then(function(result) {
                if (result.isConfirmed) {
                    doBackToStep1();
                }
            });
            return;
        } else {
            if (!confirm('Terdapat ' + scannedLabelsList.length + ' label yang sudah discan. Ganti planning akan menghapus data scan sementara. Yakin?')) {
                return;
            }
        }
    }
    doBackToStep1();
}

function doBackToStep1() {
    clearAllScannedLabels();
    selectedSsLotIds = [];
    selectedPlanningId = 0;
    selectedPlanningPartCode = '';
    currentModalStatusFilter = 'all';
    updateKpiActiveCardStyles();

    // Reset kolom pencarian agar semua item muncul
    var pInput = document.getElementById('planning-search-input');
    if (pInput) {
        pInput.value = '';
    }

    setPlanningTypeTab('kanban');

    var p1 = document.getElementById('step-pill-1');
    if (p1) {
        p1.style.cssText = 'padding: 4px 11px; border-radius: 6px; font-size: 11px; font-weight: 800; cursor: pointer; transition: all 0.15s ease; border: none; outline: none; background-color: #2563eb; color: #ffffff;';
        p1.innerHTML = '<span style="width: 15px; height: 15px; border-radius: 9999px; background-color: rgba(255,255,255,0.25); display: inline-flex; align-items: center; justify-content: center; font-size: 9.5px; font-weight: 900;">1</span><span>Pilih Planning</span>';
    }
    var p2 = document.getElementById('step-pill-2');
    if (p2) {
        p2.style.cssText = 'padding: 4px 11px; border-radius: 6px; font-size: 11px; font-weight: 700; cursor: pointer; transition: all 0.15s ease; border: none; outline: none; background-color: transparent; color: #64748b;';
        p2.innerHTML = '<span style="width: 15px; height: 15px; border-radius: 9999px; background-color: #e2e8f0; display: inline-flex; align-items: center; justify-content: center; font-size: 9.5px; font-weight: 800; color: #475569;">2</span><span>Scan Barcode</span>';
    }

    var stepTag = document.getElementById('wizard-step-tag');
    if (stepTag) {
        stepTag.textContent = 'Langkah 1 dari 2';
        stepTag.style.backgroundColor = '#eff6ff';
        stepTag.style.color = '#1d4ed8';
        stepTag.style.borderColor = '#bfdbfe';
    }
    var titleEl = document.getElementById('wizard-modal-title');
    if (titleEl) titleEl.textContent = 'OQC Inspection Workbench';
    var subEl = document.getElementById('wizard-modal-subtitle');
    if (subEl) subEl.textContent = 'Pilih Kanban atau Safety Stock yang akan diinspeksi';

    var step1El = document.getElementById('wizard-step-1-content');
    var step2El = document.getElementById('wizard-step-2-content');
    if (step1El) {
        step1El.classList.remove('hidden');
        step1El.style.display = 'flex';
    }
    if (step2El) {
        step2El.classList.add('hidden');
        step2El.style.display = 'none';
    }

    var btnChangeItem = document.getElementById('btn-change-item-header');
    if (btnChangeItem) btnChangeItem.style.display = 'none';

    if (typeof resetLiveScanAlertPanel === 'function') resetLiveScanAlertPanel();

    // Render data tabel planning (gunakan cache yang ada jika sudah dimuat, atau refresh via AJAX)
    if (cachedPlanningItems && cachedPlanningItems.length > 0) {
        renderPlanningTableRows(cachedPlanningItems);
        filterPlanningItems();
    } else {
        refreshPlanningItemsAjax();
    }

    setTimeout(function() {
        var pInput = document.getElementById('planning-search-input');
        if (pInput) {
            pInput.focus();
            if (pInput.value.length > 0) pInput.select();
        }
    }, 100);
}

function goToStep2IfSelected() {
    if (selectedPlanningId || selectedPlanningPartCode) {
        var step1El = document.getElementById('wizard-step-1-content');
        var step2El = document.getElementById('wizard-step-2-content');
        if (step1El) {
            step1El.classList.add('hidden');
            step1El.style.display = 'none';
        }
        if (step2El) {
            step2El.classList.remove('hidden');
            step2El.style.display = 'flex';
        }

        var btnChangeItem = document.getElementById('btn-change-item-header');
        if (btnChangeItem) btnChangeItem.style.display = 'inline-flex';
        
        var stepTag = document.getElementById('wizard-step-tag');
        if (stepTag) {
            stepTag.textContent = 'Langkah 2 dari 2';
            stepTag.style.backgroundColor = '#f0fdf4';
            stepTag.style.color = '#15803d';
            stepTag.style.borderColor = '#bbf7d0';
        }
        var titleEl = document.getElementById('wizard-modal-title');
        if (titleEl) titleEl.textContent = 'OQC Inspection Workbench';
        var subEl = document.getElementById('wizard-modal-subtitle');
        if (subEl) subEl.textContent = 'Scan QR barcode atau label box part untuk validasi';

        var p1 = document.getElementById('step-pill-1');
        if (p1) {
            p1.style.cssText = 'padding: 4px 11px; border-radius: 6px; font-size: 11px; font-weight: 700; cursor: pointer; transition: all 0.15s ease; border: none; outline: none; background-color: #dcfce7; color: #166534;';
            p1.innerHTML = '<span style="width: 15px; height: 15px; border-radius: 9999px; background-color: #bbf7d0; display: inline-flex; align-items: center; justify-content: center; font-size: 9.5px; font-weight: 900; color: #15803d;">✓</span><span>Planning Terpilih</span>';
        }
        var p2 = document.getElementById('step-pill-2');
        if (p2) {
            p2.style.cssText = 'padding: 4px 11px; border-radius: 6px; font-size: 11px; font-weight: 800; cursor: pointer; transition: all 0.15s ease; border: none; outline: none; background-color: #2563eb; color: #ffffff; box-shadow: 0 1px 3px rgba(37,99,235,0.3);';
            p2.innerHTML = '<span style="width: 15px; height: 15px; border-radius: 9999px; background-color: rgba(255,255,255,0.25); display: inline-flex; align-items: center; justify-content: center; font-size: 9.5px; font-weight: 900;">2</span><span>Scan Barcode</span>';
        }
        var rawInput = document.getElementById('scan-qr-raw');
        if (rawInput) {
            rawInput.value = '';
            setTimeout(function() { rawInput.focus(); }, 150);
        }
    } else {
        var container = document.getElementById('planning-cards-container');
        if (container) {
            var cards = container.querySelectorAll('.planning-card');
            for (var i = 0; i < cards.length; i++) {
                if (cards[i].style.display !== 'none') {
                    cards[i].click();
                    return;
                }
            }
        }
        skipPlanningSelection();
    }
}

function skipPlanningSelection() {
    clearAllScannedLabels();
    if (typeof resetLiveScanAlertPanel === 'function') resetLiveScanAlertPanel();
    selectedSsLotIds = [];

    selectedPlanningId = 0;
    selectedPlanningPartCode = '';
    selectedPlanningQty = 0;
    document.getElementById('scan-part-code').value = '';
    document.getElementById('selected-plan-part-code').textContent = 'AUTOMATIC FIFO MATCHING';
    document.getElementById('selected-plan-desc').textContent = 'Sistem otomatis mencocokkan planning berdasarkan Part Code';
    document.getElementById('selected-plan-cust').textContent = '-';
    document.getElementById('selected-plan-qty').textContent = '-';
    document.getElementById('selected-plan-tag').classList.add('hidden');

    var step1El = document.getElementById('wizard-step-1-content');
    var step2El = document.getElementById('wizard-step-2-content');
    if (step1El) {
        step1El.classList.add('hidden');
        step1El.style.display = 'none';
    }
    if (step2El) {
        step2El.classList.remove('hidden');
        step2El.style.display = 'flex';
    }

    var btnChangeItem = document.getElementById('btn-change-item-header');
    if (btnChangeItem) btnChangeItem.style.display = 'inline-flex';

    var rawInput = document.getElementById('scan-qr-raw');
    if (rawInput) {
        rawInput.value = '';
        setTimeout(function() { rawInput.focus(); }, 150);
    }
}

function startDirectSafetyStockScan(presetPartCode, presetLotNum, presetRefNum) {
    clearAllScannedLabels();
    if (typeof resetLiveScanAlertPanel === 'function') resetLiveScanAlertPanel();
    selectedSsLotIds = [];

    selectedPlanningId = 0;
    selectedPlanningType = 'safety_stock';
    selectedPlanningPartCode = presetPartCode || '';
    selectedPlanningQty = 0;

    var modal = document.getElementById('scan-modal-overlay');
    if (modal) {
        modal.classList.remove('hidden');
        modal.style.display = 'flex';
        modal.style.zIndex = '999999';
    }

    var displayPcode = presetPartCode ? ('SAFETY STOCK: ' + presetPartCode) : 'SAFETY STOCK (DIRECT GUDANG)';
    document.getElementById('selected-plan-part-code').textContent = displayPcode;
    document.getElementById('selected-plan-desc').textContent = 'Direct Scan Barcode QR atau Input Form Manual Barang dari Gudang';
    document.getElementById('selected-plan-cust').textContent = 'INTERNAL SAFETY STOCK';
    document.getElementById('selected-plan-qty').textContent = 'Akumulasi Lot Gudang';
    
    var tagEl = document.getElementById('selected-plan-tag');
    tagEl.textContent = 'Safety Stock Cek';
    tagEl.className = 'text-[9px] bg-purple-100 text-purple-800 border border-purple-300 px-1.5 py-0.2 rounded font-sans font-extrabold';
    tagEl.classList.remove('hidden');

    document.getElementById('selected-plan-qty').textContent = 'Multi-Lot (Akumulasi Stok Bebas)';

    document.getElementById('scan-part-code').value = presetPartCode || '';
    document.getElementById('scan-lot-number').value = presetLotNum || '064';
    document.getElementById('scan-label-qty').value = '';
    document.getElementById('scan-ref-number').value = presetRefNum || '';

    // Switch step UI
    var p1 = document.getElementById('step-pill-1');
    if (p1) {
        p1.style.cssText = 'padding: 4px 11px; border-radius: 6px; font-size: 11px; font-weight: 700; cursor: pointer; transition: all 0.15s ease; border: none; outline: none; background-color: #f3e8ff; color: #6b21a8;';
        p1.innerHTML = '<span style="width: 15px; height: 15px; border-radius: 9999px; background-color: #e9d5ff; display: inline-flex; align-items: center; justify-content: center; font-size: 9.5px; font-weight: 900; color: #7e22ce;">✓</span><span>Safety Stock</span>';
    }
    var p2 = document.getElementById('step-pill-2');
    if (p2) {
        p2.style.cssText = 'padding: 4px 11px; border-radius: 6px; font-size: 11px; font-weight: 800; cursor: pointer; transition: all 0.15s ease; border: none; outline: none; background-color: #7c3aed; color: #ffffff; box-shadow: 0 1px 3px rgba(124,58,237,0.3);';
        p2.innerHTML = '<span style="width: 15px; height: 15px; border-radius: 9999px; background-color: rgba(255,255,255,0.25); display: inline-flex; align-items: center; justify-content: center; font-size: 9.5px; font-weight: 900;">2</span><span>Scan Barcode</span>';
    }
    
    var stepTag = document.getElementById('wizard-step-tag');
    if (stepTag) {
        stepTag.textContent = 'Langkah 2 dari 2';
        stepTag.style.backgroundColor = '#faf5ff';
        stepTag.style.color = '#7e22ce';
        stepTag.style.borderColor = '#e9d5ff';
    }
    var titleEl = document.getElementById('wizard-modal-title');
    if (titleEl) titleEl.textContent = 'OQC Inspection Workbench';
    var step1El = document.getElementById('wizard-step-1-content');
    var step2El = document.getElementById('wizard-step-2-content');
    if (step1El) {
        step1El.classList.add('hidden');
        step1El.style.display = 'none';
    }
    if (step2El) {
        step2El.classList.remove('hidden');
        step2El.style.display = 'flex';
    }

    var btnChangeItem = document.getElementById('btn-change-item-header');
    if (btnChangeItem) btnChangeItem.style.display = 'inline-flex';

    renderScannedLabelsTable();
    updateAutoStartToggleUI();

    var rawInput = document.getElementById('scan-qr-raw');
    if (rawInput) {
        rawInput.value = '';
        setTimeout(function() { rawInput.focus(); }, 150);
    }
}

var currentPlanTypeTab = window.OQC_CONFIG.autoScanType || 'kanban';
if (!['safety_stock', 'kanban', 'partial'].includes(currentPlanTypeTab)) {
    currentPlanTypeTab = 'kanban';
}

function setPlanningTypeTab(type, presetPartCode, presetLotNum, presetRefNum) {
    currentPlanTypeTab = type;
    updateAutoStartToggleUI();
    if (type === 'safety_stock') {
        var pCode = presetPartCode || (typeof prefilledPartCode !== 'undefined' ? prefilledPartCode : '');
        var lNum = presetLotNum || (typeof prefilledScannedLot !== 'undefined' ? prefilledScannedLot : '');
        var rNum = presetRefNum || (typeof prefilledScannedRef !== 'undefined' ? prefilledScannedRef : '');
        startDirectSafetyStockScan(pCode, lNum, rNum);
        return;
    }

    // Update active styles for all tab buttons
    ['kanban', 'partial', 'safety_stock'].forEach(function(t) {
        var btn = document.getElementById('tab-plan-' + t);
        if (!btn) return;
        if (t === type) {
            if (t === 'partial') {
                btn.style.cssText = 'background-color: #f59e0b !important; color: #ffffff !important; border: 1px solid #d97706 !important; font-weight: 800; padding: 5px 12px; border-radius: 8px; font-size: 11px; cursor: pointer; box-shadow: 0 2px 4px rgba(245,158,11,0.3);';
            } else {
                btn.style.cssText = 'background-color: #2563eb !important; color: #ffffff !important; border: 1px solid #1d4ed8 !important; font-weight: 800; padding: 5px 12px; border-radius: 8px; font-size: 11px; cursor: pointer; box-shadow: 0 2px 4px rgba(37,99,235,0.3);';
            }
        } else {
            btn.style.cssText = 'background-color: #f1f5f9 !important; color: #475569 !important; border: 1px solid #cbd5e1 !important; font-weight: 700; padding: 5px 12px; border-radius: 8px; font-size: 11px; cursor: pointer; box-shadow: none;';
        }
    });
    filterPlanningItems();
}

var planningSearchScanTimer = null;
function handlePlanningSearchInput() {
    filterPlanningItems();
    var inputEl = document.getElementById('planning-search-input');
    if (!inputEl) return;
    var rawVal = inputEl.value;

    // Jika scanner memasukkan data sangat cepat tanpa mengirim Enter, beri jeda wajar (min 350ms)
    // agar seluruh potongan barcode QR (Z1..Z5) selesai diketik penuh
    if (rawVal.indexOf('|') !== -1 || (rawVal.indexOf('Z1') === 0 && rawVal.indexOf('Z2') !== -1)) {
        clearTimeout(planningSearchScanTimer);
        planningSearchScanTimer = setTimeout(function() {
            processPlanningSearchSubmit();
        }, 400);
    }
}

function handlePlanningSearchKeydown(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        clearTimeout(planningSearchScanTimer);
        processPlanningSearchSubmit();
    }
}

function processPlanningSearchSubmit() {
    var inputEl = document.getElementById('planning-search-input');
    if (!inputEl) return;
    var rawVal = inputEl.value.trim();
    if (!rawVal) return;

    var res = filterPlanningItems();
    var qrParsed = res.qrParsed || parseBarcodeQR(rawVal);
    var isQr = !!(qrParsed && qrParsed.Z1);
    var targetCode = isQr ? qrParsed.Z1.trim() : rawVal.replace(/^#/, '').trim();

    // 1. Cek apakah ada exact match di tabel yang sedang tampil
    var targetRow = res.exactMatchRow;
    if (targetRow) {
        var isTargetCompleted = (targetRow.getAttribute('data-status-group') === 'completed' || targetRow.getAttribute('data-is-uncompleted') === '0');
        // Jika target row adalah completed dan user tidak sedang dalam mode lihat selesai, jangan auto-click
        if (!isTargetCompleted || showCompletedKanban || currentModalStatusFilter === 'completed') {
            var actionBtn = targetRow.querySelector('button');
            if (actionBtn) {
                inputEl.value = '';
                filterPlanningItems();
                actionBtn.click();
                return;
            }
        }
    }

    // 2. Jika hanya ada 1 baris yang cocok terlihat di tabel (HANYA untuk ketik manual, BUKAN untuk scan QR box)
    if (!isQr && res.visibleCount === 1 && res.lastVisibleRow) {
        var actionBtn = res.lastVisibleRow.querySelector('button');
        if (actionBtn) {
            inputEl.value = '';
            filterPlanningItems();
            actionBtn.click();
            return;
        }
    }

    // 3. Jika tidak ada di batch yang sedang aktif di layar, cari lintas batch (server search)
    // Jangan asal pilih baris pertama/overdue!
    inputEl.setAttribute('disabled', 'disabled');
    var searchUrl = window.OQC_CONFIG.baseUrl + '/modules/inspection/session.php?action=get_planning_items&search_query=' + encodeURIComponent(targetCode);

    fetch(searchUrl)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            inputEl.removeAttribute('disabled');
            if (data && data.success && Array.isArray(data.items) && data.items.length > 0) {
                // Temukan item yang benar-benar exact match (Part Code atau No Kanban)
                var matchedPlan = data.items.find(function(item) {
                    var c = (item.item_code || '').trim().toLowerCase();
                    var kb = (item.kanban_no || '').trim().toLowerCase();
                    var targetLower = targetCode.toLowerCase();
                    return (c === targetLower || kb === targetLower || ('#' + kb) === targetLower);
                });

                if (matchedPlan) {
                    var targetTotalQty = parseInt(matchedPlan.qty) || 0;
                    var totalPassedQty = parseInt(matchedPlan.total_passed_qty) || 0;
                    var remainingQty = Math.max(0, targetTotalQty - totalPassedQty);
                    var isCompletedPlan = (matchedPlan.status === 'completed' || (targetTotalQty > 0 && totalPassedQty >= targetTotalQty));

                    // Jika Kanban yang dicari/discan sudah 100% selesai
                    if (isCompletedPlan) {
                        inputEl.value = '';
                        filterPlanningItems();

                        if (typeof Swal !== 'undefined') {
                            Swal.fire({
                                icon: 'info',
                                title: '<span class="text-emerald-800 font-extrabold text-base">Kanban Telah Selesai</span>',
                                html: '<div class="text-center text-xs text-slate-700 space-y-2">' +
                                    '<div class="p-2.5 bg-emerald-50 border border-emerald-200 rounded-lg">' +
                                        '<div class="font-bold text-emerald-950 font-mono">#' + escapeHtml(matchedPlan.kanban_no || '-') + ' — ' + escapeHtml(matchedPlan.item_code) + '</div>' +
                                        '<div class="text-[11px] text-emerald-800 font-medium">' + escapeHtml(matchedPlan.display_part_name || matchedPlan.item_description || '') + '</div>' +
                                        '<div class="text-[11px] font-bold text-emerald-700 mt-0.5">Progres: 100% Selesai (' + totalPassedQty + ' / ' + targetTotalQty + ' pcs)</div>' +
                                    '</div>' +
                                    '<p class="text-[11.5px] text-slate-600 font-medium">' +
                                        'Kanban ini telah selesai diinspeksi. Silakan periksa <b>Safety Stock</b> atau buka riwayat Kanban ini.' +
                                    '</p>' +
                                '</div>',
                                showCancelButton: true,
                                confirmButtonColor: '#7c3aed',
                                cancelButtonColor: '#059669',
                                confirmButtonText: '<span class="flex items-center gap-1.5 font-bold text-xs"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg> Periksa Safety Stock</span>',
                                cancelButtonText: '<span class="font-bold text-xs">Lihat Kanban Ini</span>',
                                reverseButtons: true
                            }).then(function(result) {
                                if (result.isConfirmed) {
                                    startDirectSafetyStockScan(matchedPlan.item_code);
                                } else if (result.dismiss === Swal.DismissReason.cancel) {
                                    // Buka Kanban yang sudah selesai
                                    showCompletedKanban = true;
                                    updateCompletedToggleButton();
                                    filterPlanningItems();
                                    selectPlanningItem(
                                        parseInt(matchedPlan.id),
                                        matchedPlan.item_code,
                                        matchedPlan.display_part_name || matchedPlan.item_description,
                                        matchedPlan.customer || 'PT. Indonesia Epson Industry',
                                        remainingQty,
                                        matchedPlan.check_type || '',
                                        'kanban',
                                        parseInt(matchedPlan.avail_ss_qty) || 0,
                                        totalPassedQty,
                                        targetTotalQty,
                                        matchedPlan.kanban_no || '',
                                        matchedPlan.eta || matchedPlan.req_date || ''
                                    );
                                }
                            });
                        } else {
                            alert('Kanban ' + (matchedPlan.kanban_no || matchedPlan.item_code) + ' sudah selesai 100%. Silakan periksa Safety Stock.');
                        }
                        return;
                    }

                    inputEl.value = '';
                    var isSafetyStock = (matchedPlan.plan_type === 'safety_stock' || (matchedPlan.batch_plan_type || '') === 'safety_stock' || (matchedPlan.kanban_no || '').toUpperCase().indexOf('SS') === 0);
                    var planTypeAttr = isSafetyStock ? 'safety_stock' : 'kanban';

                    selectPlanningItem(
                        parseInt(matchedPlan.id),
                        matchedPlan.item_code,
                        matchedPlan.display_part_name || matchedPlan.item_description,
                        matchedPlan.customer || 'PT. Indonesia Epson Industry',
                        remainingQty,
                        matchedPlan.check_type || '',
                        planTypeAttr,
                        parseInt(matchedPlan.avail_ss_qty) || 0,
                        totalPassedQty,
                        targetTotalQty,
                        matchedPlan.kanban_no || '',
                        matchedPlan.eta || matchedPlan.req_date || ''
                    );
                    return;
                }
            }

            // 4. Jika benar-benar tidak ditemukan di database Kanban (baik hari ini maupun tunggakan)
            handlePartNotFoundInKanban(targetCode, rawVal, qrParsed);
        })
        .catch(function(err) {
            inputEl.removeAttribute('disabled');
            console.error('Pencarian Kanban error:', err);
            handlePartNotFoundInKanban(targetCode, rawVal, qrParsed);
        });
}

function handlePartNotFoundInKanban(targetCode, rawVal, qrParsed) {
    var isQr = !!(qrParsed && qrParsed.Z1);
    var pCode = isQr ? String(qrParsed.Z1 || '').trim() : String(targetCode || '').trim();
    var lNum  = (qrParsed && qrParsed.Z2 != null) ? String(qrParsed.Z2).trim() : '';
    var rNum  = (qrParsed && qrParsed.Z5 != null) ? String(qrParsed.Z5).trim() : '';
    var qQty  = (qrParsed && qrParsed.Z3 != null) ? String(qrParsed.Z3).trim() : '';

    var inputEl = document.getElementById('planning-search-input');
    if (inputEl) inputEl.value = '';

    // Smart Auto-Route: Part tidak ada di jadwal Kanban delivery,
    // langsung alihkan otomatis ke kamar Safety Stock Gudang!
    startDirectSafetyStockScan(pCode, lNum, rNum);

    if (qQty && document.getElementById('scan-label-qty')) {
        document.getElementById('scan-label-qty').value = qQty;
    }

    // Jika discan berupa QR lengkap, isikan juga ke scan-qr-raw agar siap dieksekusi
    if (isQr && rawVal && document.getElementById('scan-qr-raw')) {
        document.getElementById('scan-qr-raw').value = rawVal;
    }

    // Tampilkan toast notifikasi pintar non-intrusif di pojok atas (tanpa menghalangi fokus scan)
    if (typeof Swal !== 'undefined') {
        const Toast = Swal.mixin({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 3500,
            timerProgressBar: true,
            didOpen: (toast) => {
                toast.addEventListener('mouseenter', Swal.stopTimer);
                toast.addEventListener('mouseleave', Swal.resumeTimer);
            }
        });

        Toast.fire({
            icon: 'info',
            title: 'Dialihkan ke Safety Stock Gudang',
            html: 'Part <b class="font-mono">' + escapeHtml(pCode) + '</b> tidak ada di jadwal Kanban.'
        });
    }
}

function filterPlanningItems() {
    var inputEl = document.getElementById('planning-search-input');
    var rawVal = inputEl ? inputEl.value.trim() : '';

    var qrParsed = parseBarcodeQR(rawVal);
    var isQr = !!(qrParsed && qrParsed.Z1);
    var query = rawVal.toLowerCase().replace(/^#/, '');

    if (qrParsed && qrParsed.Z1) {
        query = qrParsed.Z1.trim().toLowerCase();
    }

    var tbody = document.getElementById('planning-table-body');
    if (!tbody) return { visibleCount: 0, lastVisibleRow: null, exactMatchRow: null, qrParsed: qrParsed };
    var rows = tbody.querySelectorAll('.kanban-table-row');
    var visibleCount = 0;
    var lastVisibleRow = null;
    var exactMatchRow = null;

    for (var i = 0; i < rows.length; i++) {
        var row = rows[i];
        var text = row.textContent.toLowerCase();
        var rowCode = (row.getAttribute('data-part-code') || '').trim().toLowerCase();
        var rowKbNo = (row.getAttribute('data-kanban-no') || '').trim().toLowerCase();
        var rowCust = (row.getAttribute('data-customer') || '').trim().toLowerCase();

        // Cek strictly digits untuk numeric match (hindari parseInt "1A..." yang terpotong menjadi 1)
        var isQueryPureDigits = /^[0-9]+$/.test(query);
        var isRowCodePureDigits = /^[0-9]+$/.test(rowCode);
        var isNumericMatch = (isQueryPureDigits && isRowCodePureDigits && parseInt(query, 10) === parseInt(rowCode, 10));

        var isExact = (query !== '' && (rowCode === query || rowKbNo === query || ('#' + rowKbNo) === query || isNumericMatch));
        
        if (isExact && !exactMatchRow) {
            exactMatchRow = row;
        }

        var matchesSearch = false;
        if (isQr) {
            matchesSearch = isExact;
        } else {
            matchesSearch = (query === '' || text.indexOf(query) !== -1 || rowCode.indexOf(query) !== -1 || rowKbNo.indexOf(query) !== -1 || rowCust.indexOf(query) !== -1);
        }

        // Filter status berdasarkan KPI card / Work Tracker yang aktif (all, uncompleted, active, completed, rejected, my_kanban)
        var rowStatusGroup = row.getAttribute('data-status-group') || 'uninspected';
        var isRowMyKanban = (row.getAttribute('data-is-my-kanban') === '1');
        var isRowUncompleted = (row.getAttribute('data-is-uncompleted') === '1');
        var isRowActive = (row.getAttribute('data-is-active') === '1');
        var matchesStatus = false;

        if (currentModalStatusFilter === 'all') {
            matchesStatus = true;
        } else if (currentModalStatusFilter === 'uncompleted') {
            matchesStatus = isRowUncompleted;
        } else if (currentModalStatusFilter === 'active') {
            matchesStatus = isRowActive;
        } else if (currentModalStatusFilter === 'my_kanban') {
            matchesStatus = isRowMyKanban;
        } else {
            matchesStatus = (currentModalStatusFilter === rowStatusGroup);
        }

        // Cek apakah item ini completed
        var isCompleted = (row.getAttribute('data-status-group') === 'completed' || row.getAttribute('data-is-uncompleted') === '0');

        // Aturan Sembunyikan Kanban Selesai:
        // Jika status filter adalah 'completed', tampilkan.
        // Jika showCompletedKanban bernilai false dan status filter bukan 'completed', Kanban selesai disembunyikan.
        var allowsCompleted = showCompletedKanban || (currentModalStatusFilter === 'completed');
        if (isCompleted && !allowsCompleted) {
            matchesStatus = false;
        }

        if (matchesSearch && matchesStatus) {
            row.style.display = '';
            visibleCount++;
            lastVisibleRow = row;
        } else {
            row.style.display = 'none';
        }
    }

    // Periksa apakah tabel kosong karena seluruh Kanban telah selesai diinspeksi
    var bannerRow = document.getElementById('row-all-kanban-completed-banner');
    if (bannerRow) {
        bannerRow.remove();
    }

    if (visibleCount === 0 && rows.length > 0) {
        // Cek apakah sebenarnya semua row yang ada di batch ini berstatus completed
        var totalRows = rows.length;
        var completedRowsCount = 0;
        for (var j = 0; j < rows.length; j++) {
            if (rows[j].getAttribute('data-status-group') === 'completed' || rows[j].getAttribute('data-is-uncompleted') === '0') {
                completedRowsCount++;
            }
        }

        if (completedRowsCount === totalRows && !showCompletedKanban && query === '') {
            // Semua Kanban pada batch/hari ini sudah selesai diinspeksi! Tampilkan banner ringkas Opsi 1 persis di tengah
            var bannerTr = document.createElement('tr');
            bannerTr.id = 'row-all-kanban-completed-banner';
            bannerTr.innerHTML = '<td colspan="7" style="padding: 40px 16px; text-align: center; vertical-align: middle;">' +
                '<div style="background: linear-gradient(135deg, #f0fdf4 0%, #ffffff 100%); border: 1.5px solid #86efac; border-radius: 16px; padding: 22px 28px; box-shadow: 0 4px 16px rgba(16, 185, 129, 0.08); display: inline-block; max-width: 540px; width: 100%; margin: 0 auto; text-align: center;">' +
                    '<div style="width: 44px; height: 44px; border-radius: 9999px; background-color: #10b981; color: #ffffff; display: flex; align-items: center; justify-content: center; margin: 0 auto 10px auto; box-shadow: 0 2px 6px rgba(16, 185, 129, 0.3);">' +
                        '<svg style="width: 24px; height: 24px;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>' +
                    '</div>' +
                    '<h3 style="color: #064e3b; font-weight: 900; font-size: 13px; letter-spacing: 0.05em; text-transform: uppercase; margin-bottom: 6px;">SELURUH KANBAN HARI INI TELAH SELESAI</h3>' +
                    '<p style="color: #475569; font-size: 11.5px; font-weight: 500; line-height: 1.5; margin-bottom: 16px;">' +
                        'Semua lot Kanban telah tuntas diinspeksi. Silakan lanjutkan pemeriksaan <b style="color: #7c3aed; font-weight: 800;">Safety Stock</b> gudang.' +
                    '</p>' +
                    '<div style="display: flex; align-items: center; justify-content: center; gap: 10px; flex-wrap: wrap;">' +
                        '<button type="button" onclick="startDirectSafetyStockScan()" style="background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%); color: #ffffff; font-weight: 800; font-size: 12px; padding: 8px 16px; border-radius: 8px; border: none; box-shadow: 0 2px 6px rgba(124, 58, 237, 0.3); display: inline-flex; align-items: center; gap: 6px; cursor: pointer;">' +
                            '<svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>' +
                            '<span>+ Periksa Safety Stock</span>' +
                        '</button>' +
                        '<button type="button" onclick="toggleCompletedKanbanVisibility()" style="background-color: #ffffff; color: #334155; border: 1px solid #cbd5e1; font-weight: 700; font-size: 12px; padding: 8px 14px; border-radius: 8px; display: inline-flex; align-items: center; gap: 6px; cursor: pointer;">' +
                            '<svg style="width: 14px; height: 14px; color: #059669;" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>' +
                            '<span>Lihat Kanban Selesai (' + totalRows + ')</span>' +
                        '</button>' +
                    '</div>' +
                '</div>' +
            '</td>';
            tbody.appendChild(bannerTr);
        } else if (query !== '') {
            // Pencarian tidak ada yang cocok
            var noMatchTr = document.createElement('tr');
            noMatchTr.id = 'row-all-kanban-completed-banner';
            noMatchTr.innerHTML = '<td colspan="7" class="px-4 py-10 text-center text-slate-400 font-bold text-xs">' +
                'Tidak ada data Kanban yang sesuai dengan kata kunci: <span class="text-blue-600 font-mono">"' + escapeHtml(query) + '"</span>' +
            '</td>';
            tbody.appendChild(noMatchTr);
        }
    }

    updatePlanningTableCount(visibleCount);

    qrParsed.is_qr = isQr;
    return { visibleCount: visibleCount, lastVisibleRow: lastVisibleRow, exactMatchRow: exactMatchRow, qrParsed: qrParsed };
}

// Initialize default tab on load & load active workbench session data
document.addEventListener('DOMContentLoaded', function() {
    setPlanningTypeTab(currentPlanTypeTab);
    if (currentSessionId && currentSessionId > 0) {
        loadWorkbenchSessionData(currentSessionId);
    }
});

function loadWorkbenchSessionData(sessionId, onLoadedCallback) {
    if (!sessionId) return;
    currentSessionId = sessionId;

    if (window.history && window.history.pushState) {
        window.history.pushState(null, '', window.OQC_CONFIG.baseUrl + "/modules/inspection/session.php?id=" + sessionId);
    }

    fetch(window.OQC_CONFIG.baseUrl + "/modules/inspection/api/session_detail.php?id=" + sessionId)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) return;
            window.lastWorkbenchSessionData = data;

            if (data.defect_types && Array.isArray(data.defect_types)) {
                DEFECT_TYPES_LIST = data.defect_types;
            }

            var s = data.session;
            var ngRecords = data.ng_records || [];
            var custName = s.customer || 'PT. Indonesia Epson Industry';
            if (typeof updateWorkbenchLiveBadge === 'function') { updateWorkbenchLiveBadge(s); }



            // Update Card 1 Customer Name & Line Name
            var cardCustEl = document.getElementById('card-customer-name');
            if (cardCustEl) {
                cardCustEl.innerHTML = escapeHtml(custName);
            }

            // Update Card 1 Dynamic Theme (Kanban vs Safety Stock - Rich Color Edition)
            var cardTopMeta = document.getElementById('card-top-metadata');
            var cardTypeBadge = document.getElementById('card-type-badge');
            var cardCustLabel = document.getElementById('card-cust-label');
            var cardMetaHeaderRow = document.getElementById('card-meta-header-row');
            var btnCardDetail = document.getElementById('btn-card-detail');
            var labelTipe = document.getElementById('card-label-tipe');
            var labelPart = document.getElementById('card-label-part');
            var labelQty = document.getElementById('card-label-qty');
            var valPart = document.getElementById('card-part-code');
            var valQty = document.getElementById('card-total-qty');
            var valPartName = document.getElementById('card-part-name');
            var isSs = (s.inspection_type === 'safety_stock');

            if (cardTopMeta) {
                if (isSs) {
                    cardTopMeta.style.cssText = 'background: linear-gradient(135deg, #ede9fe 0%, #f3e8ff 100%); border: 2px solid #c084fc; border-left: 6px solid #7c3aed; border-radius: 16px; padding: 12px; margin-bottom: 10px; box-shadow: 0 2px 6px rgba(124, 58, 237, 0.15);';
                    if (cardCustEl) { cardCustEl.style.color = '#4c1d95'; cardCustEl.className = 'font-extrabold text-[11px] flex items-center truncate max-w-[220px]'; }
                    if (cardCustLabel) { cardCustLabel.style.color = '#6b21a8'; }
                    if (cardMetaHeaderRow) { cardMetaHeaderRow.style.borderBottom = '1px solid #ddd6fe'; }
                    if (btnCardDetail) { btnCardDetail.style.cssText = 'background-color: #7c3aed; color: #ffffff; border: none; padding: 3.5px 10px; border-radius: 7px; font-weight: 800; font-size: 10.5px; box-shadow: 0 1px 3px rgba(0,0,0,0.15); display: inline-flex; align-items: center; gap: 4px; cursor: pointer;'; }
                    if (labelTipe) labelTipe.style.color = '#6b21a8';
                    if (labelPart) labelPart.style.color = '#6b21a8';
                    if (labelQty) labelQty.style.color = '#6b21a8';
                    if (valPart) valPart.style.color = '#4c1d95';
                    if (valPartName) valPartName.style.color = '#7c3aed';
                    if (valQty) valQty.style.color = '#581c87';
                } else {
                    cardTopMeta.style.cssText = 'background: linear-gradient(135deg, #dbeafe 0%, #eff6ff 100%); border: 2px solid #93c5fd; border-left: 6px solid #2563eb; border-radius: 16px; padding: 12px; margin-bottom: 10px; box-shadow: 0 2px 6px rgba(37, 99, 235, 0.15);';
                    if (cardCustEl) { cardCustEl.style.color = '#172554'; cardCustEl.className = 'font-extrabold text-[11px] flex items-center truncate max-w-[220px]'; }
                    if (cardCustLabel) { cardCustLabel.style.color = '#1e40af'; }
                    if (cardMetaHeaderRow) { cardMetaHeaderRow.style.borderBottom = '1px solid #bfdbfe'; }
                    if (btnCardDetail) { btnCardDetail.style.cssText = 'background-color: #2563eb; color: #ffffff; border: none; padding: 3.5px 10px; border-radius: 7px; font-weight: 800; font-size: 10.5px; box-shadow: 0 1px 3px rgba(0,0,0,0.15); display: inline-flex; align-items: center; gap: 4px; cursor: pointer;'; }
                    if (labelTipe) labelTipe.style.color = '#1e40af';
                    if (labelPart) labelPart.style.color = '#1e40af';
                    if (labelQty) labelQty.style.color = '#1e40af';
                    if (valPart) valPart.style.color = '#172554';
                    if (valPartName) valPartName.style.color = '#2563eb';
                    if (valQty) valQty.style.color = '#1d4ed8';
                }
            }

            if (cardTypeBadge) {
                if (isSs) {
                    cardTypeBadge.innerHTML = '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-extrabold bg-purple-700 text-white border border-purple-800 shadow-xs truncate">' +
                        '<svg style="width: 11px; height: 11px; color: #ffffff; flex-shrink: 0;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>' +
                        '<span>SAFETY STOCK</span></span>';
                } else {
                    cardTypeBadge.innerHTML = '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-extrabold bg-blue-700 text-white border border-blue-800 shadow-xs truncate">' +
                        '<svg style="width: 11px; height: 11px; color: #ffffff; flex-shrink: 0;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>' +
                        '<span>KANBAN</span></span>';
                }
            }

            var cardLineEl = document.getElementById('card-session-line-text');
            if (cardLineEl) {
                cardLineEl.textContent = s.line_name || ('Line ' + (s.line_id || 1));
            }

            if (document.getElementById('card-part-code')) document.getElementById('card-part-code').textContent = s.part_code || '-';
            if (document.getElementById('card-part-name')) {
                var pName = s.part_name || s.display_part_name || s.kanban_item_desc || '-';
                document.getElementById('card-part-name').textContent = pName;
                document.getElementById('card-part-name').title = pName;
            }
            
            var totScanned = parseInt(s.total_scanned_qty || 0);
            var kbTarget = parseInt(s.kanban_qty || 0);
            var prevKbQty = parseInt(data.prev_kanban_qty || s.prev_kanban_qty || 0);
            var accumulatedQty = parseInt(data.accumulated_kanban_qty || (totScanned + prevKbQty));
            var usedSsQty = parseInt(s.use_safety_stock_qty || 0);
            var excQty = parseInt(s.excess_qty || 0);
            var isKanban = (s.inspection_type || 'kanban') === 'kanban';

            var cardQtyEl = document.getElementById('card-total-qty');
            var cardBadgeEl = document.getElementById('card-qty-subbadge');

            if (cardQtyEl) {
                if (isKanban) {
                    var effectiveQty = (prevKbQty > 0) ? accumulatedQty : totScanned;
                    var targetDisp = (kbTarget > 0) ? kbTarget : Math.max(500, effectiveQty);
                    cardQtyEl.textContent = effectiveQty.toLocaleString() + ' / ' + targetDisp.toLocaleString() + ' pcs';
                } else {
                    cardQtyEl.textContent = totScanned.toLocaleString() + ' pcs';
                }
            }

            if (cardBadgeEl) {
                if (isKanban && prevKbQty > 0) {
                    var statusBadge = '';
                    if (kbTarget > 0) {
                        if (accumulatedQty === kbTarget) {
                            statusBadge = '<span style="font-size: 7.5px; font-weight: 800; background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; padding: 0.5px 3.5px; border-radius: 3px; display: inline-block;">PAS KANBAN</span>';
                        } else if (accumulatedQty > kbTarget) {
                            statusBadge = '<span style="font-size: 7.5px; font-weight: 800; background: #fef3c7; color: #92400e; border: 1px solid #fde68a; padding: 0.5px 3.5px; border-radius: 3px; display: inline-block;">LEBIH +' + (accumulatedQty - kbTarget).toLocaleString() + ' PCS</span>';
                        } else {
                            statusBadge = '<span style="font-size: 7.5px; font-weight: 800; background: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe; padding: 0.5px 3.5px; border-radius: 3px; display: inline-block;">KURANG ' + (kbTarget - accumulatedQty).toLocaleString() + ' PCS</span>';
                        }
                    }
                    cardBadgeEl.innerHTML = '<div class="mt-0.5" style="line-height: 1.2;">' +
                        '<div style="font-size: 8px; color: #64748b; font-weight: 600; white-space: nowrap;">Sesi ini: <b>' + totScanned.toLocaleString() + '</b> (+' + prevKbQty.toLocaleString() + ')</div>' +
                        (statusBadge ? '<div style="margin-top: 1.5px;">' + statusBadge + '</div>' : '') +
                        '</div>';
                } else {
                    cardBadgeEl.innerHTML = '';
                }
            }

            // Card 2, 3, and 4 are rendered dynamically by renderWorkbenchLotFlow(data)

            // Populate Modal Detail Kanban fields
            if (document.getElementById('modal-kb-plan-type')) document.getElementById('modal-kb-plan-type').textContent = (s.inspection_type === 'safety_stock') ? 'SAFETY STOCK' : 'KANBAN';
            if (document.getElementById('modal-kb-doc-no')) document.getElementById('modal-kb-doc-no').textContent = s.doc_no || '-';
            if (document.getElementById('modal-kb-vendor')) document.getElementById('modal-kb-vendor').textContent = s.kanban_vendor || 'PT. SURYA TECHNOLOGY INDUSTRI';
            if (document.getElementById('modal-kb-customer')) document.getElementById('modal-kb-customer').textContent = '🏢 ' + (s.customer || 'PT. Indonesia Epson Industry');
            if (document.getElementById('modal-kb-kanban-no-badge')) document.getElementById('modal-kb-kanban-no-badge').textContent = '#' + (s.kanban_no || '-');
            if (document.getElementById('modal-kb-item-code')) document.getElementById('modal-kb-item-code').textContent = s.kanban_item_code || s.part_code || '-';
            if (document.getElementById('modal-kb-part-model')) document.getElementById('modal-kb-part-model').textContent = s.part_model || '-';
            if (document.getElementById('modal-kb-item-desc')) document.getElementById('modal-kb-item-desc').textContent = s.kanban_item_desc || s.part_name || '-';
            if (document.getElementById('modal-kb-cavity')) document.getElementById('modal-kb-cavity').textContent = 'Cavity ' + (s.cavity || '1');
            function formatKanbanDT(dtStr) {
                if (!dtStr || dtStr === '-' || dtStr === '0000-00-00 00:00:00') return '-';
                var d = new Date(String(dtStr).replace(/-/g, '/'));
                if (isNaN(d.getTime())) return dtStr + ' WIB';
                var months = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
                var day = ('0' + d.getDate()).slice(-2);
                var month = months[d.getMonth()];
                var year = d.getFullYear();
                var hours = ('0' + d.getHours()).slice(-2);
                var mins = ('0' + d.getMinutes()).slice(-2);
                return day + ' ' + month + ' ' + year + ', ' + hours + ':' + mins + ' WIB';
            }
            if (document.getElementById('modal-kb-eta')) document.getElementById('modal-kb-eta').textContent = formatKanbanDT(s.kanban_eta);
            if (document.getElementById('modal-kb-req-date')) document.getElementById('modal-kb-req-date').textContent = formatKanbanDT(s.kanban_req_date);
            if (document.getElementById('modal-kb-qty')) document.getElementById('modal-kb-qty').textContent = (s.kanban_qty ? parseInt(s.kanban_qty).toLocaleString() : '0') + ' pcs';
            if (document.getElementById('modal-kb-str-loc')) document.getElementById('modal-kb-str-loc').textContent = s.kanban_str_loc || '-';
            if (document.getElementById('modal-kb-check-type')) document.getElementById('modal-kb-check-type').textContent = s.kanban_check_type ? (s.kanban_check_type + ' Cek') : 'Normal Cek';
            if (document.getElementById('modal-kb-remark')) document.getElementById('modal-kb-remark').textContent = s.kanban_remark || '-';

            // Populate Group 2.3 Akumulasi Sesi Kanban
            var accumBox = document.getElementById('modal-kb-accumulated-box');
            var accumStatus = document.getElementById('modal-kb-accumulated-status');
            var sessListEl = document.getElementById('modal-kb-sessions-list');
            var prevSessions = data.prev_kanban_sessions || s.prev_kanban_sessions || [];

            if (accumBox) {
                if (isKanban && prevKbQty > 0) {
                    accumBox.style.display = 'block';
                    accumBox.classList.remove('hidden');
                    if (accumStatus) {
                        accumStatus.textContent = accumulatedQty.toLocaleString() + ' / ' + kbTarget.toLocaleString() + ' pcs';
                    }
                    if (sessListEl) {
                        var sessHtml = '';
                        prevSessions.forEach(function(ps) {
                            var stText = ps.status_text || (ps.status === 'passed' ? 'Lulus' : 'Parsial');
                            var pQty = Number(ps.passed_qty !== undefined ? ps.passed_qty : ps.total_scanned_qty);
                            sessHtml += '<div class="flex items-center justify-between py-0.5 border-b border-emerald-100">' +
                                '<span>Sesi #' + ps.id + ' (' + stText + ' - ' + escapeHtml(ps.inspector_name || 'QC Inspector') + '):</span>' +
                                '<span class="font-mono font-bold text-emerald-800">' + pQty.toLocaleString() + ' pcs</span>' +
                                '</div>';
                        });
                        sessHtml += '<div class="flex items-center justify-between py-0.5 font-bold text-emerald-950">' +
                            '<span>Sesi #' + s.id + ' (Sesi Berjalan):</span>' +
                            '<span class="font-mono" id="modal-kb-current-sess-qty">' + totScanned.toLocaleString() + ' pcs</span>' +
                            '</div>';
                        sessListEl.innerHTML = sessHtml;
                    }
                } else {
                    accumBox.style.display = 'none';
                    accumBox.classList.add('hidden');
                }
            }

            // Render Multi-Lot & Scanned Session Lots
            var lotsList = data.session_lots || [];
            var lotSumList = data.lot_summary || [];



            // Modal Group 2 Lot No
            if (document.getElementById('modal-kb-lot-no')) {
                if (lotSumList.length > 1) {
                    document.getElementById('modal-kb-lot-no').innerHTML = '<span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-extrabold bg-amber-100 text-amber-900 border border-amber-300 font-mono">📦 Multi-Lot (' + lotSumList.length + ' Lot)</span>';
                } else {
                    document.getElementById('modal-kb-lot-no').textContent = s.lot_number || '-';
                }
            }

            // Group 2.5 Multi-Lot Summary Cards
            var lotSumContainer = document.getElementById('modal-kb-lot-summary-container');
            if (lotSumContainer) {
                if (lotSumList.length > 1) {
                    var sumHtml = '';
                    lotSumList.forEach(function(ls) {
                        sumHtml += '<div class="p-1.5 bg-white border border-indigo-200 rounded-lg flex items-center justify-between shadow-2xs">' +
                                   '<span class="font-mono font-bold text-slate-800 text-[10px]">#' + escapeHtml(ls.lot_number) + '</span>' +
                                   '<span class="font-mono font-extrabold text-blue-800 text-[10px]">' + (parseInt(ls.total_qty) || 0).toLocaleString() + ' pcs (' + ls.label_count + ' Box)</span>' +
                                   '</div>';
                    });
                    lotSumContainer.innerHTML = sumHtml;
                    lotSumContainer.classList.remove('hidden');
                } else {
                    lotSumContainer.classList.add('hidden');
                    lotSumContainer.innerHTML = '';
                }
            }

            // Populate Form Input NG Lot Select Dropdown
            var lotSelect = document.getElementById('ng-session-lot-select');
            if (lotSelect) {
                var prevVal = lotSelect.value;
                var lotOptHtml = '<option value="">-- Pilih Box / Lot Number yang Temuan NG --</option>';
                if (lotsList.length > 0) {
                    lotsList.forEach(function(lRow) {
                        var lRefStr = lRow.ref_number ? (' — Ref Box: ' + escapeHtml(lRow.ref_number)) : '';
                        var isSel = (lotsList.length === 1 || String(lRow.id) === String(prevVal)) ? 'selected' : '';
                        lotOptHtml += '<option value="' + lRow.id + '" ' + isSel + '>' +
                                      'Lot: ' + escapeHtml(lRow.lot_number) + lRefStr + ' (' + (parseInt(lRow.qty) || 0).toLocaleString() + ' pcs)' +
                                      '</option>';
                    });
                }
                lotSelect.innerHTML = lotOptHtml;
            }

            // Group 2.5 Scanned Session Lots Table Body
            if (document.getElementById('modal-kb-lots-count')) document.getElementById('modal-kb-lots-count').textContent = lotsList.length;
            if (document.getElementById('modal-kb-lots-total-qty')) document.getElementById('modal-kb-lots-total-qty').textContent = totScanned.toLocaleString() + ' pcs';

            var lotsTbody = document.getElementById('modal-kb-session-lots-tbody');
            if (lotsTbody) {
                if (lotsList.length === 0) {
                    lotsTbody.innerHTML = '<tr><td colspan="4" class="px-2.5 py-3 text-center text-slate-400 italic">Belum ada rincian label box yang tercatat.</td></tr>';
                } else {
                    var lotsHtml = '';
                    lotsList.forEach(function(lbl, idx) {
                        var lRef = lbl.ref_number ? escapeHtml(lbl.ref_number) : '-';
                        var lLot = escapeHtml(lbl.lot_number || s.lot_number || '-');
                        var lQty = (parseInt(lbl.qty) || 0).toLocaleString();
                        var isSs = (lbl.remarks && lbl.remarks.indexOf('Safety Stock') !== -1) || lbl.is_safety_stock;
                        var originBadge = isSs 
                            ? '<span class="bg-purple-100 text-purple-900 border border-purple-300 text-[9px] font-black px-2 py-0.5 rounded-full inline-flex items-center ml-1" title="Lot dipenuhi dari Alokasi Safety Stock">Safety Stock</span>'
                            : '<span class="bg-blue-100 text-blue-900 border border-blue-300 text-[9px] font-black px-2 py-0.5 rounded-full inline-flex items-center ml-1" title="Lot berasal dari scan label box">Scan Label</span>';

                        lotsHtml += '<tr class="hover:bg-indigo-50/50 transition-colors">' +
                                    '<td class="px-2.5 py-1.5 font-bold text-slate-400">' + (idx + 1) + '</td>' +
                                    '<td class="px-2.5 py-1.5 font-mono font-bold text-blue-800 text-[10px]"><span class="bg-blue-50 px-1.5 py-0.5 rounded border border-blue-200">' + lRef + '</span>' + originBadge + '</td>' +
                                    '<td class="px-2.5 py-1.5 font-mono font-bold text-slate-800">' + lLot + '</td>' +
                                    '<td class="px-2.5 py-1.5 font-mono font-extrabold text-slate-900 text-right">' + lQty + ' pcs</td>' +
                                    '</tr>';
                    });
                    lotsTbody.innerHTML = lotsHtml;
                }
            }

            var cardTypeEl = document.getElementById('card-type-badge');
            if (cardTypeEl) {
                if (s.inspection_type === 'safety_stock') {
                    cardTypeEl.innerHTML = '<span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-extrabold bg-purple-100 text-purple-800 border border-purple-200 truncate">📦 SAFETY STOCK</span>';
                } else {
                    cardTypeEl.innerHTML = '<span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-extrabold bg-blue-100 text-blue-800 border border-blue-200 truncate">🚚 KANBAN</span>';
                }
            }

            // Render Workbench Per-Lot Flow
            renderWorkbenchLotFlow(data);

            // Global State & Real-Time Lot State (Selalu di-update untuk semua status)
            var ngLotsData = data.ng_lots || [];
            currentNgLots = ngLotsData;
            currentSessionLots = data.session_lots || [];
            if (data.session) {
                currentSessionObj = data.session;
                if (data.session.part_code) {
                    currentSessionPartCode = String(data.session.part_code).trim();
                    // Auto-load & refresh documents (PI, Q-Point, PAC, Standar Packing) on workbench load
                    if (typeof fetchPartDocuments === 'function') {
                        fetchPartDocuments(currentSessionPartCode);
                    }
                }
            }

            // Render Substitution Lineage Modal & Buttons (Top Navbar & Card Header)
            var btnTopSubst    = document.getElementById('btn-top-substitution-log');
            var btnCardSubst   = document.getElementById('btn-card-substitution-log');
            var topSubstCount  = document.getElementById('top-subst-log-count');
            var cardSubstCount = document.getElementById('card-subst-log-count');
            var modalSubstBody = document.getElementById('modal-subst-log-list-body');
            var modalKbSubWrap = document.getElementById('modal-kb-substitution-container');
            var modalKbSubList = document.getElementById('modal-kb-substitution-list');
            var substLog       = data.substitution_log || [];

            if (substLog.length > 0) {
                if (btnTopSubst) {
                    btnTopSubst.classList.remove('hidden');
                    btnTopSubst.style.display = 'flex';
                }
                if (btnCardSubst) {
                    btnCardSubst.classList.remove('hidden');
                    btnCardSubst.style.display = 'inline-flex';
                }
                if (topSubstCount) topSubstCount.textContent = substLog.length;
                if (cardSubstCount) cardSubstCount.textContent = substLog.length;

                var sHtml = '';
                substLog.forEach(function(item) {
                    var ngLotStr  = item.ng_lot_number ? ('#' + escapeHtml(item.ng_lot_number)) : 'Lot Original';
                    var ngRefStr  = item.ng_lot_ref ? (' (Ref: ' + escapeHtml(item.ng_lot_ref) + ')') : '';
                    var repLotStr = item.rep_lot_number ? ('#' + escapeHtml(item.rep_lot_number)) : 'Lot Baru';
                    var repRefStr = item.rep_lot_ref ? (' (Ref: ' + escapeHtml(item.rep_lot_ref) + ')') : '';

                    var actionLabel = (item.action_type === 'replace_ng_only') ? 'Ganti Lot NG' :
                                      (item.action_type === 'replace_all_lots') ? 'Ganti Semua Lot' : 'Re-Inspeksi';

                    sHtml += '<div style="background:#ffffff; border:1px solid #cbd5e1; border-radius:10px; padding:10px 12px; font-size:11px; box-shadow:0 1px 3px rgba(0,0,0,0.04);">' +
                                '<div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:6px;">' +
                                    '<span style="font-size:10px; font-weight:800; color:#166534; background:#dcfce7; border:1px solid #bbf7d0; padding:2px 8px; border-radius:6px;">' + actionLabel + '</span>' +
                                    '<span style="font-size:9.5px; color:#64748b; font-weight:600;">' + escapeHtml(item.actioned_by || 'QC') + ' • ' + (item.created_at || '') + '</span>' +
                                '</div>' +
                                '<div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap; font-family:monospace;">' +
                                    '<div style="background:#ffe4e6; color:#9f1239; border:1px solid #fecdd3; padding:4px 9px; border-radius:6px; font-weight:700;">' +
                                        '🎯 ' + ngLotStr + '<span style="font-size:9px; opacity:0.85;">' + ngRefStr + '</span>' +
                                    '</div>' +
                                    '<span style="font-size:13px; font-weight:900; color:#475569;">➔</span>' +
                                    '<div style="background:#dcfce7; color:#166534; border:1px solid #bbf7d0; padding:4px 9px; border-radius:6px; font-weight:800;">' +
                                        '📦 ' + repLotStr + '<span style="font-size:9px; opacity:0.85;">' + repRefStr + '</span>' +
                                    '</div>' +
                                '</div>' +
                                (item.notes ? '<div style="font-size:10px; color:#475569; margin-top:6px; font-style:italic;">💬 ' + escapeHtml(item.notes) + '</div>' : '') +
                             '</div>';
                });
                if (modalSubstBody) modalSubstBody.innerHTML = sHtml;
                if (modalKbSubList) modalKbSubList.innerHTML = sHtml;
                if (modalKbSubWrap) modalKbSubWrap.classList.remove('hidden');
            } else {
                if (btnTopSubst) {
                    btnTopSubst.classList.add('hidden');
                    btnTopSubst.style.display = 'none';
                }
                if (btnCardSubst) {
                    btnCardSubst.classList.add('hidden');
                    btnCardSubst.style.display = 'none';
                }
                if (modalSubstBody) modalSubstBody.innerHTML = '<p style="font-size:12px; color:#64748b; text-align:center; padding:16px 0;">Belum ada riwayat penggantian lot untuk sesi ini.</p>';
                if (modalKbSubWrap) modalKbSubWrap.classList.add('hidden');
                if (modalKbSubList) modalKbSubList.innerHTML = '';
            }

            // Tombol Ringkas Tindakan Re-Inspeksi Kanban (Hanya muncul jika REJECTED dan ada lot NG aktif)
            var ngBatchWrap = document.getElementById('ng-batch-action-wrapper');
            var countBadge  = document.getElementById('ng-batch-count-badge');
            var actionableLots = ngLotsData.filter(function(l) { return l.lot_status === 'ng_found' || l.lot_status === 'ng_quarantine'; });
            if (s.status === 'rejected' && actionableLots.length > 0) {
                if (ngBatchWrap) {
                    ngBatchWrap.classList.remove('hidden');
                    ngBatchWrap.style.display = 'block';
                }
                if (countBadge) countBadge.textContent = actionableLots.length + ' Lot NG';
            } else if (ngBatchWrap) {
                ngBatchWrap.classList.add('hidden');
                ngBatchWrap.style.display = 'none';
            }

            // Badge RE-INSPEKSI di Header (Selalu di-update untuk semua status sesi)
            var reinspWrapper = document.getElementById('header-reinspection-wrapper');
            if (reinspWrapper) {
                if (s.is_reinspection) {
                    var rTypeLabels = {
                        'rescan_restart':   'Scan Ulang (Awal)',
                        'rescan_continue':  'Scan Ulang (Lanjut)',
                        'rescan_same_lot':  'Scan Ulang',
                        'replace_ng_only':  'Ganti Lot NG',
                        'replace_all_lots': 'Ganti Semua Lot'
                    };
                    var rTypeLabel = rTypeLabels[s.reinspection_type] || 'Re-Inspeksi';
                    var roundNum = s.reinspection_round || 1;
                    var reinspHtml = '<span class="inline-flex items-center px-2 py-0.5 rounded-md text-[11px] font-extrabold bg-amber-100 text-amber-800 border border-amber-300 shadow-2xs ml-2 flex-shrink-0">' +
                                     '⚡ RE-INSPEKSI #' + roundNum + ' (' + escapeHtml(rTypeLabel) + ')' +
                                     '</span>';
                    if (s.parent_session_id) {
                        reinspHtml += '<a href="' + (window.OQC_CONFIG.baseUrl || '') + '/modules/inspection/session.php?id=' + s.parent_session_id + '" class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-semibold bg-slate-100 text-slate-600 border border-slate-300 ml-2 flex-shrink-0 hover:bg-slate-200 transition-colors">' +
                                      '← Sesi Original #' + s.parent_session_id +
                                      '</a>';
                    }
                    reinspWrapper.innerHTML = reinspHtml;
                } else {
                    reinspWrapper.innerHTML = '';
                }
            }

            // Card 4 finished banner and lot cards are managed by renderWorkbenchLotFlow(data)

            var pdfFrame = document.getElementById('pdf-frame');
            var placeholder = document.getElementById('drawing-placeholder-box');
            var placeholderPartText = document.getElementById('placeholder-part-code-text');
            var placeholderPartFolder = document.getElementById('placeholder-part-folder');
            if (placeholderPartText) placeholderPartText.textContent = s.part_code || '-';
            if (placeholderPartFolder) placeholderPartFolder.textContent = s.part_code || '-';

            if (s.drawing_2d) {
                if (pdfFrame) {
                    pdfFrame.onerror = function() {
                        pdfFrame.style.display = 'none';
                        if (placeholder) placeholder.style.display = 'flex';
                    };
                    pdfFrame.src = s.drawing_2d + '#toolbar=0';
                    pdfFrame.style.display = 'block';
                }
                if (placeholder) placeholder.style.display = 'none';
            } else {
                if (pdfFrame) {
                    pdfFrame.src = '';
                    pdfFrame.style.display = 'none';
                }
                if (placeholder) placeholder.style.display = 'flex';
            }

            if (typeof syncUploadRedirectButtons === 'function' && s.part_code) {
                syncUploadRedirectButtons(s.part_code);
            }

            var cad3dViewport = document.getElementById('cad-3d-viewport');
            var cad3dPlaceholder = document.getElementById('cad-3d-placeholder-box');
            var placeholder3dText = document.getElementById('placeholder-3d-part-code-text');
            var placeholder3dFolder = document.getElementById('placeholder-3d-part-folder');
            if (placeholder3dText) placeholder3dText.textContent = s.part_code || '-';
            if (placeholder3dFolder) placeholder3dFolder.textContent = s.part_code || '-';

            if (s.drawing_3d) {
                if (cad3dViewport) {
                    cad3dViewport.style.display = 'block';
                    cad3dViewport.setAttribute('data-stp-url', s.drawing_3d);
                }
                if (cad3dPlaceholder) cad3dPlaceholder.style.display = 'none';
                is3dViewerInitialized = false;
                var box3d = document.getElementById('viewport-3d');
                if (box3d && !box3d.classList.contains('hidden')) {
                    init3dViewer();
                }
            } else {
                if (cad3dViewport) {
                    cad3dViewport.style.display = 'none';
                    cad3dViewport.removeAttribute('data-stp-url');
                }
                if (cad3dPlaceholder) cad3dPlaceholder.style.display = 'flex';
            }

            scannedLabelsList = [];
            if (document.getElementById('scan-modal-overlay')) {
                document.getElementById('scan-modal-overlay').classList.add('hidden');
                document.getElementById('scan-modal-overlay').style.display = 'none';
            }

            if (typeof onLoadedCallback === 'function') {
                try {
                    onLoadedCallback(data);
                } catch (eCb) {
                    console.error('Error in onLoadedCallback:', eCb);
                }
            }
        })
        .catch(function(err) {
            console.error('Error reloading session data:', err);
        });
}

function setScanModalLoading(isLoading, customText) {
    var loadingEl = document.getElementById('modal-loading');
    if (loadingEl) {
        if (isLoading) {
            loadingEl.classList.remove('hidden');
            loadingEl.style.display = 'block';
            if (customText) {
                var p = loadingEl.querySelector('p');
                if (p) p.textContent = customText;
            }
        } else {
            loadingEl.classList.add('hidden');
            loadingEl.style.display = 'none';
        }
    }
    var submitBtn = document.getElementById('btn-submit-scan-modal');
    if (submitBtn) {
        submitBtn.disabled = isLoading;
        if (isLoading) {
            submitBtn.style.opacity = '0.75';
            submitBtn.style.cursor = 'wait';
        } else {
            submitBtn.style.opacity = '1';
            submitBtn.style.cursor = 'pointer';
        }
    }
}

function showScanModalError(msg) {
    setScanModalLoading(false);
    var errBox = document.getElementById('modal-error-box');
    var errMsg = document.getElementById('modal-error-msg');
    if (errBox && errMsg) {
        errBox.classList.remove('hidden');
        errBox.style.display = 'block';
        errMsg.textContent = msg;
    }
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            icon: 'error',
            title: 'Validasi / Pembuatan Sesi Gagal',
            text: msg
        });
    }
}

function hideScanModalError() {
    var errBox = document.getElementById('modal-error-box');
    if (errBox) {
        errBox.classList.add('hidden');
        errBox.style.display = 'none';
    }
}

function triggerModalValidation() {
    if (window.isAddingBoxToActiveSession) {
        submitAddBoxesToActiveSession();
        return;
    }

    // 1. Selalu gunakan Part Code resmi Kanban yang dipilih (atau dari daftar label yang sudah tervalidasi)
    var pCode = selectedPlanningPartCode || (scannedLabelsList.length > 0 ? scannedLabelsList[0].Z1 : (document.getElementById('scan-part-code') ? document.getElementById('scan-part-code').value.trim() : ''));
    var lNum = '';

    if (scannedLabelsList.length > 0) {
        var lastLbl = scannedLabelsList[scannedLabelsList.length - 1];
        if (selectedPlanningPartCode) pCode = selectedPlanningPartCode;
        else if (lastLbl.Z1) pCode = lastLbl.Z1;
        lNum = lastLbl.Z2;
    } else {
        lNum = document.getElementById('scan-lot-number') ? document.getElementById('scan-lot-number').value.trim() : '';
    }

    if (!pCode || !lNum) {
        Swal.fire({
            icon: 'warning',
            title: 'Scan Label Diperlukan',
            text: 'Silakan scan setidaknya 1 label barcode QR sebelum memuat inspeksi!'
        });
        return;
    }

    var totalScanned = 0;
    scannedLabelsList.forEach(function(l) { totalScanned += parseInt(l.Z3) || 0; });
    if (totalScanned === 0) {
        var rawQtyVal = parseInt(document.getElementById('scan-label-qty') ? document.getElementById('scan-label-qty').value : 0) || 0;
        if (rawQtyVal > 0) totalScanned = rawQtyVal;
    }

    if (totalScanned === 0 && scannedLabelsList.length === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Scan Label Diperlukan',
            text: 'Silakan scan setidaknya 1 label barcode QR sebelum memuat inspeksi!'
        });
        return;
    }

    if (!document.fullscreenElement && !document.webkitFullscreenElement && !document.msFullscreenElement) {
        var el = document.documentElement;
        if (el.requestFullscreen) {
            el.requestFullscreen().catch(function(e){});
        } else if (el.webkitRequestFullscreen) {
            el.webkitRequestFullscreen();
        } else if (el.msRequestFullscreen) {
            el.msRequestFullscreen();
        }
    }

    setScanModalLoading(true, 'Memeriksa ketersediaan status DID & Matching Planning...');
    hideScanModalError();

    var scannedRefList = [];
    var refVal = '';
    if (typeof scannedLabelsList !== 'undefined' && scannedLabelsList.length > 0) {
        scannedLabelsList.forEach(function(l) {
            if (l.Z5) scannedRefList.push(l.Z5);
        });
        refVal = scannedLabelsList[scannedLabelsList.length - 1].Z5 || '';
    } else {
        refVal = document.getElementById('scan-ref-number') ? document.getElementById('scan-ref-number').value.trim() : '';
        if (refVal && scannedRefList.indexOf(refVal) === -1) {
            scannedRefList.push(refVal);
        }
    }

    var validateUrl = (window.OQC_CONFIG.baseUrl || '') + "/modules/inspection/scan_validate.php?part_code=" + encodeURIComponent(pCode) + 
                      '&lot_number=' + encodeURIComponent(lNum) + 
                      '&ref_number=' + encodeURIComponent(refVal) + 
                      '&scanned_ref_numbers=' + encodeURIComponent(JSON.stringify(scannedRefList)) + 
                      '&total_scanned_qty=' + totalScanned + 
                      '&kanban_item_id=' + selectedPlanningId + 
                      '&inspection_type=' + encodeURIComponent(selectedPlanningType);

    fetch(validateUrl)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) {
                showScanModalError(data.message || 'Validasi label gagal!');
                return;
            }

            // 1. Kasus PASSED: Auto-Bypass & Cegah Data Ganda
            if (data.already_safety_stock_passed) {
                setScanModalLoading(false);

                if (selectedPlanningType === 'safety_stock') {
                    // Alert khusus scan Safety Stock Gudang
                    var refDisp = data.matched_ref_number ? (' (Ref Number: <b style="color: #1d4ed8; font-family: monospace;">' + escapeHtml(data.matched_ref_number) + '</b>)') : '';
                    Swal.fire({
                        title: 'Ref Number / Label Box Sudah Terdaftar',
                        html: '<div style="font-size: 12px; text-align: left; line-height: 1.6; color: #334155;">' +
                              'Label Box' + refDisp + ' dengan Lot Number <b style="color: #1d4ed8; font-family: monospace;">' + escapeHtml(lNum) + '</b> (Part Code: <b>' + escapeHtml(pCode) + '</b>) ' +
                              '<b>SUDAH TERDAFTAR & PASSED (Lolos)</b> di Safety Stock Gudang oleh QC <b>' + escapeHtml(data.inspector_name || 'Inspector QC') + '</b>.<br><br>' +
                              '<div style="background-color: #eff6ff; border: 1px solid #bfdbfe; padding: 8px 12px; border-radius: 8px; color: #1e40af; font-weight: 600;">' +
                              'Stok label box ini sudah tersimpan dalam persediaan Safety Stock Gudang.' +
                              '</div>' +
                              '</div>',
                        icon: 'info',
                        showCancelButton: true,
                        confirmButtonColor: '#2563eb',
                        cancelButtonColor: '#64748b',
                        confirmButtonText: 'Lihat Detail Safety Stock',
                        cancelButtonText: 'Monitoring Safety Stock'
                    }).then(function(res) {
                        if (res.isConfirmed) {
                            window.location.href = window.OQC_CONFIG.baseUrl + "/modules/safety_stock/detail.php?id=" + (data.kanban_item_id || data.session_id);
                        } else if (res.dismiss === Swal.DismissReason.cancel) {
                            window.location.href = window.OQC_CONFIG.baseUrl + "/modules/safety_stock/index.php";
                        }
                    });
                    return;
                }

                // Alert khusus inspeksi Kanban Customer (Auto-fulfillment Notification)
                Swal.fire({
                    title: 'Selamat! Lolos Safety Stock',
                    html: '<div style="font-size: 12px; text-align: left; line-height: 1.6; color: #334155;">' +
                          'Barang dengan Lot Number <b style="color: #1d4ed8; font-family: monospace;">' + escapeHtml(lNum) + '</b> (Part Code: <b>' + escapeHtml(pCode) + '</b>) ' +
                          '<b>SUDAH TERDAFTAR & PASSED (Lolos)</b> di Safety Stock oleh QC <b>' + escapeHtml(data.inspector_name || 'Inspector QC') + '</b>.<br><br>' +
                          '<div style="background-color: #f0fdf4; border: 1px solid #bbf7d0; padding: 8px 12px; border-radius: 8px; color: #166534; font-weight: 700;">' +
                          '✓ Item Kanban Planning untuk customer <u>' + escapeHtml(data.customer || 'PT Customer') + '</u> otomatis ditandai PASSED dengan Lot Number #' + escapeHtml(lNum) + '.' +
                          '</div>' +
                          '</div>',
                    icon: 'success',
                    showCancelButton: true,
                    confirmButtonColor: '#2563eb',
                    cancelButtonColor: '#64748b',
                    confirmButtonText: 'Lihat Realisasi Pekerjaan QC (Monitoring)',
                    cancelButtonText: 'Lihat Detail Safety Stock'
                }).then(function(res) {
                    if (res.isConfirmed) {
                        window.location.href = window.OQC_CONFIG.baseUrl + "/modules/monitoring/index.php";
                    } else if (res.dismiss === Swal.DismissReason.cancel) {
                        window.location.href = window.OQC_CONFIG.baseUrl + "/modules/safety_stock/detail.php?id=" + (data.kanban_item_id || data.session_id);
                    }
                });
                return;
            }

            // 2. Kasus REJECTED: Peringatan NG & Opsi Cek Ulang
            if (data.already_safety_stock_rejected) {
                setScanModalLoading(false);
                Swal.fire({
                    title: '⚠️ Perhatian: Lot Pernah REJECTED',
                    html: '<div style="font-size: 12px; text-align: left; line-height: 1.6; color: #334155;">' +
                          'Lot Number <b style="color: #be123c; font-family: monospace;">' + escapeHtml(lNum) + '</b> (Part Code: <b>' + escapeHtml(pCode) + '</b>) ' +
                          'sebelumnya pernah tercatat ber-status <b style="color: #be123c;">REJECTED (NG)</b> di Safety Stock oleh <b>' + escapeHtml(data.inspector_name || 'Inspector QC') + '</b>.<br><br>' +
                          'Apakah Anda ingin melakukan <b>Cek Ulang (Re-Inspection)</b> untuk memverifikasi perbaikan barang?' +
                          '</div>',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d97706',
                    cancelButtonColor: '#64748b',
                    confirmButtonText: '🔄 Ya, Lakukan Cek Ulang',
                    cancelButtonText: '🚨 Lihat Catatan Defect NG'
                }).then(function(res) {
                    if (res.isConfirmed) {
                        executeCreateSession(data, pCode, lNum);
                    } else if (res.dismiss === Swal.DismissReason.cancel) {
                        window.location.href = window.OQC_CONFIG.baseUrl + "/modules/safety_stock/detail.php?id=" + (data.kanban_item_id || data.session_id);
                    }
                });
                return;
            }

            executeCreateSession(data, pCode, lNum);
        })
        .catch(function(err) {
            showScanModalError('Gagal memproses validasi label QR: ' + (err.message || err));
        });
}

function executeCreateSession(data, pCode, lNum) {
    var deviceLine = localStorage.getItem('oqc_device_line_name') || localStorage.getItem('oqc_device_line');
    var deviceLineId = localStorage.getItem('oqc_device_line_id') || 0;
    if (!deviceLine || deviceLine.trim() === '') {
        setScanModalLoading(false);
        promptDeviceLine(false);
        return;
    }

    setScanModalLoading(true, 'Membuat sesi inspeksi workbench...');
    hideScanModalError();

    var totalScanned = 0;
    scannedLabelsList.forEach(function(l) { totalScanned += parseInt(l.Z3) || 0; });
    
    var totalCheckedQty = 0;
    availableSsLots.forEach(function(lot) {
        if (selectedSsLotIds.indexOf(String(lot.lot_id)) !== -1) {
            totalCheckedQty += parseInt(lot.available_qty) || 0;
        }
    });
    var usedSsQty = (isSafetyStockDeducted && selectedSsLotIds.length > 0) ? Math.min(selectedPlanningQty, totalCheckedQty) : 0;

    // Fix: Only apply fallback default scanned qty if NO safety stock was used AND no labels were scanned
    if (totalScanned === 0 && usedSsQty === 0) {
        totalScanned = data.aql ? (parseInt(data.aql.total_qty) || selectedPlanningQty) : selectedPlanningQty;
    }

    var targetKanban = (selectedPlanningQty > 0) ? Math.max(0, selectedPlanningQty - usedSsQty) : totalScanned;
    var excessQty = Math.max(0, totalScanned - targetKanban);

    // Fallback if scannedLabelsList empty AND no safety stock was used
    if (scannedLabelsList.length === 0 && usedSsQty === 0) {
        scannedLabelsList.push({
            Z1: pCode,
            Z2: lNum,
            Z3: totalScanned,
            Z4: '',
            Z5: 'REF-' + Date.now(),
            raw: 'MANUAL|Z1' + pCode + '|Z2' + lNum
        });
    }

    var payload = new FormData();
    payload.append('action', 'start_session');
    payload.append('part_code', data.part ? data.part.part_code : pCode);
    payload.append('lot_number', lNum);
    payload.append('did_id', data.did ? data.did.id : 0);
    payload.append('kanban_item_id', data.kanban ? data.kanban.id : selectedPlanningId);
    payload.append('inspection_type', data.inspection_type || selectedPlanningType);
    payload.append('part_id', data.part ? data.part.id : 0);
    payload.append('device_line_id', deviceLineId);
    payload.append('device_line', deviceLine);
    payload.append('sample_size', data.aql ? data.aql.sample_size : 50);
    payload.append('reject_number', data.aql ? data.aql.reject_number : 1);
    payload.append('total_scanned_qty', totalScanned + usedSsQty);
    payload.append('excess_qty', excessQty);
    payload.append('use_safety_stock_qty', usedSsQty);
    payload.append('selected_ss_lot_ids', JSON.stringify(selectedSsLotIds));
    payload.append('selected_ss_session_ids', JSON.stringify(selectedSsSessionIds));
    payload.append('scanned_labels', JSON.stringify(scannedLabelsList));

    fetch(window.OQC_CONFIG.baseUrl + "/modules/inspection/create.php", {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: payload
    })
    .then(function(res) { return res.json(); })
    .then(function(resData) {
        setScanModalLoading(false);
        if (resData.success && (resData.url || resData.session_id)) {
            var newId = resData.session_id || parseInt((resData.url || '').split('id=')[1]);
            loadWorkbenchSessionData(newId);
        } else {
            showScanModalError(resData.message || 'Gagal membuat sesi inspeksi!');
        }
    })
    .catch(function(err) {
        showScanModalError('Gagal memproses pembuatan sesi inspeksi: ' + (err.message || err));
    });
}

// =========================================================================
// PER-LOT AQL WORKBENCH RENDERING & INTERACTION HANDLERS
// =========================================================================

var currentLotFilterTab = 'all';
var activeFocusLotId = null;
var isAllBoxesExpanded = false;

