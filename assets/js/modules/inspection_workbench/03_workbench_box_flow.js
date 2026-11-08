var isPartNgHistoryExpanded = false;
// Ultra-lightweight in-memory single-part cache (hanya menyimpan 1 part aktif saat ini, anti-menumpuk & auto-garbage-collected)
var activePartNgCache = {
    partCode: null,
    data: null
};

function safeEscapeNgHtml(text) {
    if (text === null || text === undefined) return '';
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function togglePartNgHistory() {
    isPartNgHistoryExpanded = !isPartNgHistoryExpanded;
    var contentEl = document.getElementById('part-ng-history-content');
    var chevronEl = document.getElementById('part-ng-history-chevron');
    if (!contentEl) return;

    if (isPartNgHistoryExpanded) {
        contentEl.style.display = 'flex';
        if (chevronEl) chevronEl.style.transform = 'rotate(180deg)';

        var partCode = (window.lastWorkbenchSessionData && window.lastWorkbenchSessionData.session && window.lastWorkbenchSessionData.session.part_code)
            || (window.lastWorkbenchSessionData && window.lastWorkbenchSessionData.part_code)
            || (window.OQC_CONFIG && window.OQC_CONFIG.sessionPartCode)
            || '';

        if (!partCode) {
            var domPart = document.getElementById('card-part-code');
            if (domPart) partCode = domPart.textContent.trim();
        }

        if (partCode && partCode !== '-') {
            loadPartNgHistory(partCode);
        } else {
            var bodyEl = document.getElementById('part-ng-history-body');
            if (bodyEl) {
                bodyEl.innerHTML = '<div class="text-center py-4 text-slate-400 text-xs">Part code belum aktif atau belum discan.</div>';
            }
        }
    } else {
        contentEl.style.display = 'none';
        if (chevronEl) chevronEl.style.transform = 'rotate(0deg)';
    }
}

function loadPartNgHistory(partCode, forceRefresh) {
    var bodyEl = document.getElementById('part-ng-history-body');
    var badgeEl = document.getElementById('part-ng-history-badge');
    if (!bodyEl) return;

    if (!forceRefresh && activePartNgCache.partCode === partCode && activePartNgCache.data) {
        renderPartNgHistory(activePartNgCache.data);
        return;
    }

    bodyEl.innerHTML = '<div class="text-center py-6 text-slate-400 text-xs">' +
        '<div class="inline-block animate-spin rounded-full h-4 w-4 border-2 border-rose-600 border-t-transparent mb-2"></div>' +
        '<div>Memuat data riwayat defect part...</div></div>';

    var baseUrl = (window.OQC_CONFIG && window.OQC_CONFIG.baseUrl) ? window.OQC_CONFIG.baseUrl : '';
    var apiUrl = baseUrl + '/modules/inspection/api/get_part_ng_history.php?part_code=' + encodeURIComponent(partCode);

    fetch(apiUrl)
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data && data.success) {
                activePartNgCache.partCode = partCode;
                activePartNgCache.data = data;
                renderPartNgHistory(data);
            } else {
                bodyEl.innerHTML = '<div class="text-center py-4 text-rose-500 text-xs font-semibold">' + 
                    safeEscapeNgHtml(data.message || 'Gagal memuat riwayat defect.') + '</div>';
            }
        })
        .catch(function(err) {
            bodyEl.innerHTML = '<div class="text-center py-4 text-rose-500 text-xs font-semibold">' +
                'Koneksi gagal saat mengambil data riwayat defect.</div>';
        });
}

function renderPartNgHistory(data) {
    var bodyEl = document.getElementById('part-ng-history-body');
    var badgeEl = document.getElementById('part-ng-history-badge');
    if (!bodyEl) return;

    var totalQty = (data.summary && data.summary.total_qty_ng) ? parseInt(data.summary.total_qty_ng) : 0;
    var totalOccurrences = (data.summary && data.summary.total_occurrences) ? parseInt(data.summary.total_occurrences) : 0;

    if (badgeEl) {
        if (totalQty === 0) {
            badgeEl.textContent = 'Zero Defect';
            badgeEl.style.backgroundColor = '#10b981';
        } else {
            badgeEl.textContent = totalQty + ' pcs (' + totalOccurrences + 'x)';
            badgeEl.style.backgroundColor = '#e11d48';
        }
    }

    if (totalQty === 0) {
        bodyEl.innerHTML = 
            '<div class="text-center py-6 px-4 bg-emerald-50/70 rounded-xl border border-emerald-200">' +
                '<svg class="w-8 h-8 text-emerald-500 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>' +
                '<div class="text-xs font-extrabold text-emerald-800">Zero Defect (Bersih)</div>' +
                '<div class="text-[11px] text-emerald-600 mt-0.5">Belum pernah tercatat temuan NG pada Part Code <span class="font-mono font-bold">' + safeEscapeNgHtml(data.part_code) + '</span>.</div>' +
            '</div>';
        return;
    }

    var html = '';

    // SECTION 1: Top 5 Defect Ranking Leaderboard
    if (data.top5 && data.top5.length > 0) {
        html += '<div class="p-2.5 bg-rose-50/70 rounded-xl border border-rose-200/80 mb-2">' +
            '<div class="flex items-center justify-between mb-2">' +
                '<div class="flex items-center gap-1.5">' +
                    '<span class="text-xs">🏆</span>' +
                    '<span class="text-[11px] font-extrabold uppercase tracking-wider text-rose-900">Rank Top 5 Defect Terbanyak</span>' +
                '</div>' +
                '<span class="text-[10px] font-bold text-rose-700 bg-rose-200/60 px-2 py-0.5 rounded-full font-mono">' +
                    'Total: ' + totalQty + ' pcs' +
                '</span>' +
            '</div>' +
            '<div class="space-y-1.5">';

        var rankColors = [
            { bg: '#fee2e2', border: '#fca5a5', text: '#991b1b', bar: '#e11d48', badge: '🥇 #1' },
            { bg: '#ffedd5', border: '#fdba74', text: '#9a3412', bar: '#ea580c', badge: '🥈 #2' },
            { bg: '#fef3c7', border: '#fde68a', text: '#92400e', bar: '#d97706', badge: '🥉 #3' },
            { bg: '#f1f5f9', border: '#cbd5e1', text: '#334155', bar: '#64748b', badge: ' #4' },
            { bg: '#f1f5f9', border: '#cbd5e1', text: '#334155', bar: '#64748b', badge: ' #5' }
        ];

        data.top5.forEach(function(item, idx) {
            var color = rankColors[idx] || rankColors[4];
            var pct = item.percentage || 0;
            html += '<div style="background-color: ' + color.bg + '; border: 1px solid ' + color.border + ';" class="p-1.5 rounded-lg text-xs">' +
                '<div class="flex items-center justify-between text-[11px] font-extrabold mb-1">' +
                    '<div class="flex items-center gap-1.5 truncate">' +
                        '<span class="px-1.5 py-0.2 rounded font-black text-[9.5px] shadow-2xs" style="background-color: #ffffff; color: ' + color.text + ';">' + color.badge + '</span>' +
                        '<span class="truncate" style="color: ' + color.text + ';" title="' + safeEscapeNgHtml(item.defect_name) + '">' + safeEscapeNgHtml(item.defect_name) + '</span>' +
                    '</div>' +
                    '<div class="flex items-center gap-1.5 flex-shrink-0 font-mono text-[10.5px]">' +
                        '<span class="font-extrabold" style="color: ' + color.text + ';">' + item.total_qty + ' pcs</span>' +
                    '</div>' +
                '</div>' +
                '<div style="background-color: rgba(255,255,255,0.7); height: 5px; border-radius: 9999px; overflow: hidden;">' +
                    '<div style="background-color: ' + color.bar + '; width: ' + Math.min(100, Math.max(4, pct)) + '%; height: 100%; border-radius: 9999px; transition: width 0.3s ease;"></div>' +
                '</div>' +
            '</div>';
        });

        html += '</div></div>';
    }

    // SECTION 2: Log Riwayat Temuan Box / Lot (Daftar Kejadian)
    if (data.logs && data.logs.length > 0) {
        html += '<div class="space-y-1.5">' +
            '<div class="flex items-center justify-between px-0.5 pt-1">' +
                '<span class="text-[10.5px] font-extrabold uppercase tracking-wider text-slate-700">Log Kejadian Defect Terakhir (' + data.logs.length + ')</span>' +
                '<button type="button" onclick="loadPartNgHistory(\'' + safeEscapeNgHtml(data.part_code) + '\', true)" class="text-[9.5px] text-blue-600 hover:text-blue-800 font-bold inline-flex items-center gap-0.5 cursor-pointer" title="Refresh Riwayat">' +
                    '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M23 4v6h-6"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>' +
                    '<span>Refresh</span>' +
                '</button>' +
            '</div>';

        data.logs.forEach(function(log) {
            var dateStr = log.ng_date ? log.ng_date.substring(0, 16) : '-';
            var inspTypeBadge = (log.inspection_type === 'safety_stock') ?
                '<span class="px-1.5 py-0.2 rounded text-[8.5px] font-extrabold bg-purple-100 text-purple-800 border border-purple-200">SS</span>' :
                '<span class="px-1.5 py-0.2 rounded text-[8.5px] font-extrabold bg-blue-100 text-blue-800 border border-blue-200">KB</span>';

            html += '<div class="p-2 bg-slate-50 hover:bg-slate-100/80 transition-colors border border-slate-200 rounded-lg text-xs space-y-1">' +
                '<div class="flex items-center justify-between text-[10.5px]">' +
                    '<div class="flex items-center gap-1 font-mono font-bold text-slate-800 truncate">' +
                        inspTypeBadge +
                        '<span class="truncate">Lot: ' + safeEscapeNgHtml(log.lot_number) + '</span>' +
                    '</div>' +
                    '<span class="text-[9.5px] text-slate-400 font-mono flex-shrink-0">' + safeEscapeNgHtml(dateStr) + '</span>' +
                '</div>' +
                '<div class="flex items-center justify-between">' +
                    '<div class="flex items-center gap-1.5">' +
                        '<span class="px-1.5 py-0.5 rounded text-[10px] font-extrabold bg-rose-100 text-rose-800 border border-rose-200">' +
                            safeEscapeNgHtml(log.defect_name) + ' (' + log.qty_ng + ' pcs)' +
                        '</span>' +
                    '</div>' +
                    '<span class="text-[10px] font-semibold text-slate-600 truncate max-w-[150px]">' +
                        'by ' + safeEscapeNgHtml(log.inspector_name) + (log.line_name && log.line_name !== '-' ? (' (' + safeEscapeNgHtml(log.line_name) + ')') : '') +
                    '</span>' +
                '</div>' +
                (log.ref_number && log.ref_number !== '-' ?
                    '<div class="text-[9.5px] text-slate-500 font-mono flex items-center justify-between pt-0.5 border-t border-slate-200/50">' +
                        '<span>Ref: ' + safeEscapeNgHtml(log.ref_number) + '</span>' +
                        (log.kanban_no ? ('<span>No. KB: ' + safeEscapeNgHtml(log.kanban_no) + '</span>') : '') +
                    '</div>' : '') +
                (log.ng_remark ?
                    '<div class="text-[9.5px] text-slate-600 italic bg-white p-1 rounded border border-slate-100">' +
                        'Catatan: ' + safeEscapeNgHtml(log.ng_remark) +
                    '</div>' : '') +
            '</div>';
        });

        html += '</div>';
    }

    bodyEl.innerHTML = html;
}

function toggleAllBoxesView() {
    isAllBoxesExpanded = !isAllBoxesExpanded;
    var contentEl = document.getElementById('all-boxes-content');
    var chevronEl = document.getElementById('all-boxes-chevron');
    var titleEl = document.getElementById('all-boxes-toggle-title');
    var totalBoxes = (window.lastWorkbenchSessionData && window.lastWorkbenchSessionData.session_lots) ? 
        window.lastWorkbenchSessionData.session_lots.length : 0;

    if (!contentEl) return;

    if (isAllBoxesExpanded) {
        contentEl.style.display = 'flex';
        if (chevronEl) chevronEl.style.transform = 'rotate(180deg)';
        if (titleEl) titleEl.textContent = 'Sembunyikan Ringkasan Seluruh Box (' + totalBoxes + ' Box)';
    } else {
        contentEl.style.display = 'none';
        if (chevronEl) chevronEl.style.transform = 'rotate(0deg)';
        if (titleEl) titleEl.textContent = 'Lihat Ringkasan Seluruh Box (' + totalBoxes + ' Box)';
    }
}

function advanceFocusBoxAfterAction(currentLotId) {
    if (!window.lastWorkbenchSessionData || !window.lastWorkbenchSessionData.session_lots) return;
    var allLots = window.lastWorkbenchSessionData.session_lots;
    if (allLots.length <= 1) return;

    var curId = parseInt(currentLotId);
    var currentIdx = allLots.findIndex(function(l) { return l.id === curId; });
    if (currentIdx === -1) currentIdx = 0;

    // Helper untuk cek apakah sebuah lot belum dicek (pending)
    function isLotPending(lot) {
        if (!lot) return false;
        if (lot.id === curId) return false; // Lot yang baru saja di-submit dianggap sudah selesai
        return (lot.lot_result === 'in_progress' && lot.lot_status !== 'replaced');
    }

    // 1. Cek apakah box persis setelah ini (currentIdx + 1) belum dicek
    var nextLot = (currentIdx + 1 < allLots.length) ? allLots[currentIdx + 1] : null;
    if (isLotPending(nextLot)) {
        activeFocusLotId = nextLot.id;
        return;
    }

    // 2. Kalau box berikutnya sudah dicek/di-skip, cari yang belum di-inspeksi ke arah depan
    var foundPending = null;
    for (var i = currentIdx + 1; i < allLots.length; i++) {
        if (isLotPending(allLots[i])) {
            foundPending = allLots[i];
            break;
        }
    }

    // 3. Kalau ke depan tidak ada yang belum dicek, cari dari awal (0 ke currentIdx - 1)
    if (!foundPending) {
        for (var j = 0; j < currentIdx; j++) {
            if (isLotPending(allLots[j])) {
                foundPending = allLots[j];
                break;
            }
        }
    }

    if (foundPending) {
        activeFocusLotId = foundPending.id;
        return;
    }

    // 4. Kalau SEMUA box sudah selesai diinspeksi (tidak ada yang pending lagi):
    // Pindah ke box berikutnya dalam urutan (atau kembali ke box 1 jika sudah di paling akhir)
    if (currentIdx + 1 < allLots.length) {
        activeFocusLotId = allLots[currentIdx + 1].id;
    } else {
        activeFocusLotId = allLots[0].id;
    }
}

function changeFocusBox(lotId) {
    activeFocusLotId = parseInt(lotId);
    if (window.lastWorkbenchSessionData) {
        renderWorkbenchLotFlow(window.lastWorkbenchSessionData);
    }
}

function navigateFocusBox(direction) {
    if (!window.lastWorkbenchSessionData || !window.lastWorkbenchSessionData.session_lots) return;
    var lots = window.lastWorkbenchSessionData.session_lots;
    if (lots.length <= 1) return;

    var currentIdx = lots.findIndex(function(l) { return l.id === activeFocusLotId; });
    if (currentIdx === -1) currentIdx = 0;

    var newIdx = currentIdx + direction;
    if (newIdx >= 0 && newIdx < lots.length) {
        activeFocusLotId = lots[newIdx].id;
        renderWorkbenchLotFlow(window.lastWorkbenchSessionData);
    }
}

function confirmPassAllLots() {
    if (!currentSessionId) return;

    var lots = (window.lastWorkbenchSessionData && window.lastWorkbenchSessionData.session_lots) || [];
    var pendingLots = lots.filter(function(l) {
        return (l.lot_result === 'in_progress' && l.lot_status !== 'replaced');
    });

    if (pendingLots.length === 0) {
        Swal.fire({
            icon: 'info',
            title: 'Tidak Ada Lot Pending',
            text: 'Semua box pada sesi ini sudah selesai diperiksa.'
        });
        return;
    }

    Swal.fire({
        title: 'Tandai Semua Box PASSED?',
        html: 'Apakah Anda yakin ingin menandai seluruh <b>' + pendingLots.length + ' box</b> yang belum dicek sebagai <b>PASSED (Lolos 0 Defect)</b> sekaligus?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#059669',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Ya, Tandai Semua PASSED',
        cancelButtonText: 'Batal'
    }).then(function(result) {
        if (!result.isConfirmed) return;

        Swal.fire({
            title: 'Memproses Semua Box...',
            text: 'Mohon tunggu sebentar',
            allowOutsideClick: false,
            didOpen: function() { Swal.showLoading(); }
        });

        var payload = {
            session_id: parseInt(currentSessionId),
            action: 'pass_all'
        };

        fetch(window.OQC_CONFIG.baseUrl + "/modules/inspection/api/submit_lot_result.php", {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (!res.success) {
                Swal.fire({
                    icon: 'error',
                    title: 'Gagal',
                    text: res.message || 'Terjadi kesalahan sistem'
                });
                return;
            }

            if (res.session_status === 'passed') {
                if (typeof Swal !== 'undefined' && Swal.isVisible()) Swal.close();
                loadWorkbenchSessionData(currentSessionId, function(data) {
                    handleSessionFinishedModalFlow(data);
                });
            } else {
                Swal.fire({
                    icon: 'success',
                    title: 'Semua Box PASSED!',
                    text: res.message || 'Seluruh box berhasil dinyatakan PASSED.',
                    timer: 1500,
                    showConfirmButton: false
                });
                loadWorkbenchSessionData(currentSessionId);
            }
        })
        .catch(function(err) {
            Swal.fire({
                icon: 'error',
                title: 'Koneksi Error',
                text: 'Gagal terhubung ke server!'
            });
        });
    });
}

function generateSingleLotCardHtml(lot, ngRecordsByLot, s, isFocusMode) {
    var lotId = lot.id;
    var lotNo = lot.lot_number || '-';
    var refNo = lot.ref_number || '';
    var qty = parseInt(lot.qty) || 0;
    var aqlLevel = lot.aql_level || s.aql_level || 'G-II';
    var splCode = lot.sample_code || s.sample_code || '';
    var splSize = parseInt(lot.sample_size) || 0;
    var accNum = parseInt(lot.accept_number) || 0;
    var rejNum = parseInt(lot.reject_number) || 1;
    var ngCount = parseInt(lot.ng_count) || 0;
    var res = lot.lot_result || 'in_progress';
    var lotStatus = lot.lot_status || 'ok';
    var isSs = (lot.remarks && lot.remarks.toUpperCase().indexOf('SAFETY STOCK') !== -1) || parseInt(lot.is_safety_stock || 0) === 1 || parseInt(lot.is_safety_stock_alloc || 0) === 1 || (res === 'skipped') || ((s.inspection_type || '') === 'safety_stock');
    var isReplaced = (lotStatus === 'replaced');
    var lotRemUpper = (lot.remarks || '').toUpperCase();
    var lotActUpper = (lot.action_notes || '').toUpperCase();
    var lotReinspUpper = (lot.reinspection_notes || '').toUpperCase();
    var sessReinspType = (s.reinspection_type || '').toUpperCase();
    var isReinspected = (lotStatus === 'reinspected') ||
                        (parseInt(s.is_reinspection || 0) === 1) ||
                        (parseInt(s.parent_session_id || 0) > 0) ||
                        (sessReinspType !== '') ||
                        (lotRemUpper.indexOf('RE-INSPEKSI') !== -1 || lotRemUpper.indexOf('REINSPEKSI') !== -1 || lotRemUpper.indexOf('RE-INSPECT') !== -1 || lotRemUpper.indexOf('REINSPECT') !== -1) ||
                        (lotActUpper.indexOf('RE-INSPEKSI') !== -1 || lotActUpper.indexOf('REINSPEKSI') !== -1) ||
                        (lotReinspUpper.indexOf('RE-INSPEKSI') !== -1 || lotReinspUpper.indexOf('REINSPEKSI') !== -1);
    var isReplacement = (lot.remarks && lot.remarks.indexOf('Box Pengganti') !== -1);

    // Defect segmentation: Active defects vs Sorted defect history
    var allLotDefects = ngRecordsByLot[lotId] || [];
    var activeDefects = allLotDefects.filter(function(d) { return !d.is_sorted || parseInt(d.is_sorted) === 0; });
    var sortedDefects = allLotDefects.filter(function(d) { return parseInt(d.is_sorted) === 1; });

    var sortedHistoryHtml = '';
    if (sortedDefects.length > 0) {
        var sortedPcs = 0;
        var sortedTags = '';
        sortedDefects.forEach(function(sd) {
            var dName = sd.defect_name || (sd.defect_type_name || 'Defect');
            var dQty = parseInt(sd.qty_ng) || 1;
            sortedPcs += dQty;
            sortedTags += '<span style="background-color: #fef3c7; color: #92400e; border: 1px solid #fde68a; border-radius: 6px; padding: 2px 7px; font-size: 10px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;">' +
                escapeHtml(dName) + ' (' + dQty + ' pcs)' +
            '</span>';
        });
        sortedHistoryHtml = 
            '<div style="margin-top: 8px; padding: 7px 10px; background-color: #fffbeb; border: 1px dashed #fde68a; border-radius: 8px; font-size: 10.5px;">' +
                '<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">' +
                    '<span style="font-weight: 800; color: #92400e; font-size: 9.5px; text-transform: uppercase;">Riwayat Cacat Sebelum Sortir (' + sortedPcs + ' pcs):</span>' +
                    '<span style="font-size: 9px; font-weight: 800; background-color: #ffffff; color: #b45309; border: 1px solid #fde68a; padding: 1px 6px; border-radius: 4px;">Sudah Ditukar Part Bagus</span>' +
                '</div>' +
                '<div style="display: flex; flex-wrap: wrap; gap: 4px;">' + sortedTags + '</div>' +
            '</div>';
    }

    var isReadOnly = (window.OQC_CONFIG && window.OQC_CONFIG.isReadOnlyView);
    var cardBorder = '#cbd5e1';
    var accentColor = '#f59e0b';
    var cardBg = '#ffffff';
    var statusBadgeHtml = '';
    var actionBlockHtml = '';

    if (isReplaced) {
        cardBorder = '#cbd5e1';
        accentColor = '#94a3b8';
        cardBg = '#f8fafc';
        var isChiefAccRep = (parseInt(lot.is_chief_approved || 0) === 1);
        var chiefNameRep = lot.chief_name || 'Chief QC';
        var chiefBadgeRep = '';
        if (isChiefAccRep) {
            chiefBadgeRep = '<span style="background-color: #ecfdf5; color: #047857; border: 1.5px solid #a7f3d0; font-size: 10px; font-weight: 800; padding: 2px 8px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 4px;" title="Disetujui oleh Chief: ' + escapeHtml(chiefNameRep) + '">' +
                '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>' +
                'ACC: ' + escapeHtml(chiefNameRep) +
            '</span>';
        } else {
            chiefBadgeRep = '<span style="background-color: #fff7ed; color: #c2410c; border: 1.5px solid #fdba74; font-size: 10px; font-weight: 800; padding: 2px 8px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 4px;" title="Lembar penolakan belum di-ACC Chief QC">' +
                '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>' +
                'Belum Di-ACC Chief' +
            '</span>';
        }

        var rejBtnBadgeRep = isChiefAccRep ? 
            '<span style="background: #059669; color: #ffffff; font-size: 8.5px; font-weight: 800; padding: 1px 5px; border-radius: 4px; margin-left: 4px;">ACC OK</span>' : 
            '<span style="background: #dc2626; color: #ffffff; font-size: 8.5px; font-weight: 800; padding: 1px 5px; border-radius: 4px; margin-left: 4px;">Belum ACC</span>';

        statusBadgeHtml = '<div style="display: flex; align-items: center; gap: 4px; flex-wrap: wrap;">' +
            '<span style="background-color: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; font-size: 10px; font-weight: 800; padding: 2px 8px; border-radius: 9999px;">DIGANTI</span>' +
            chiefBadgeRep +
        '</div>';

        var cancelRepBtn = isReadOnly ? '' :
            '<button type="button" onclick="cancelReplacementLot(' + lotId + ', \'' + escapeHtml(lotNo) + '\')" style="padding: 4px 9px; background-color: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; border-radius: 6px; font-size: 10px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;" title="Batalkan box pengganti dan kembalikan box ini ke status REJECTED">' +
                '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 10h10a5 5 0 0 1 5 5v2"/><path d="M7 14l-4-4 4-4"/></svg>' +
                '<span>Batal Ganti</span>' +
            '</button>';

        actionBlockHtml = 
            '<div style="margin-top: 9px; padding: 7px 10px; background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 11px; display: flex; align-items: center; justify-content: space-between; gap: 8px;">' +
                '<span style="color: #64748b; font-weight: 600; font-size: 10.5px;">Box ini telah digantikan oleh box baru (Karantina / Retur).</span>' +
                '<div style="display: flex; gap: 6px; align-items: center; flex-shrink: 0;">' +
                    cancelRepBtn +
                    '<a href="' + (window.OQC_CONFIG.baseUrl || '') + '/modules/inspection/print_rejection.php?session_lot_id=' + lotId + '" target="_blank" ' +
                        'style="padding: 4px 10px; background-color: #fff1f2; color: #be123c; border: 1px solid #fecdd3; border-radius: 6px; font-size: 10px; font-weight: 800; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; flex-shrink: 0;" title="Buka lembar penolakan resmi lot ini">' +
                        '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>' +
                        '<span>Cetak Rejection</span>' + rejBtnBadgeRep +
                    '</a>' +
                '</div>' +
            '</div>';
    } else if (res === 'in_progress') {
        cardBorder = isFocusMode ? '#3b82f6' : '#cbd5e1';
        accentColor = '#f59e0b';
        cardBg = isFocusMode ? '#ffffff' : '#ffffff';
        statusBadgeHtml = '<span style="background-color: #fef3c7; color: #92400e; border: 1px solid #fde68a; font-size: 10px; font-weight: 800; padding: 2px 8px; border-radius: 9999px;">SEDANG DIINSPEKSI</span>';
        
        var extraCancelBtn = '';
        if (isReplacement) {
            extraCancelBtn = 
                '<button type="button" onclick="cancelReplacementLot(' + lotId + ', \'' + escapeHtml(lotNo) + '\')" ' +
                    'style="padding: 8px 11px; background-color: #fef2f2; color: #b91c1c; border: 1.5px solid #fecaca; border-radius: 10px; font-size: 10.5px; font-weight: 800; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 4px; transition: all 0.15s; flex-shrink: 0;" ' +
                    'title="Batalkan box pengganti ini dan kembalikan box sebelumnya ke status REJECTED">' +
                    '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 10h10a5 5 0 0 1 5 5v2"/><path d="M7 14l-4-4 4-4"/></svg>' +
                    '<span>Batal Box Pengganti</span>' +
                '</button>';
        } else if (isReinspected) {
            extraCancelBtn = 
                '<button type="button" onclick="cancelSortLot(' + lotId + ', \'' + escapeHtml(lotNo) + '\')" ' +
                    'style="padding: 8px 11px; background-color: #fff1f2; color: #be123c; border: 1.5px solid #fecdd3; border-radius: 10px; font-size: 10.5px; font-weight: 800; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 4px; transition: all 0.15s; flex-shrink: 0;" ' +
                    'title="Batalkan tindakan sortir dan kembalikan lot ke status REJECTED">' +
                    '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 10h10a5 5 0 0 1 5 5v2"/><path d="M7 14l-4-4 4-4"/></svg>' +
                    '<span>Batal Re-Inspeksi</span>' +
                '</button>';
        }

        if (isReadOnly) {
            var currInspName = (window.lastWorkbenchSessionData && window.lastWorkbenchSessionData.session && window.lastWorkbenchSessionData.session.inspector_name) ? window.lastWorkbenchSessionData.session.inspector_name : 'Inspektur';
            actionBlockHtml = 
                '<div style="margin-top: 8px; padding: 7px 11px; background-color: #fffbeb; border: 1.5px solid #fde68a; border-radius: 8px; font-size: 11px; color: #92400e; font-weight: 700; display: flex; align-items: center; justify-content: space-between; gap: 6px;">' +
                    '<div style="display: flex; align-items: center; gap: 6px;">' +
                        '<span style="width: 7px; height: 7px; border-radius: 9999px; background-color: #d97706;" class="animate-pulse"></span>' +
                        '<span>Sedang Diperiksa oleh ' + escapeHtml(currInspName) + ' (Mode Pantau)</span>' +
                    '</div>' +
                    '<span style="font-size: 9.5px; font-weight: 800; background: #ffffff; color: #b45309; border: 1px solid #fde68a; padding: 1.5px 6px; border-radius: 4px;">Terkunci</span>' +
                '</div>';
        } else {
            actionBlockHtml = 
                '<div style="display: flex; gap: 6px; margin-top: 8px; flex-wrap: wrap;">' +
                    '<button type="button" onclick="openLotNgModal(' + lotId + ', \'' + escapeHtml(lotNo) + '\', \'' + escapeHtml(refNo) + '\', ' + qty + ', ' + splSize + ', ' + rejNum + ')" ' +
                        'style="flex: 1; min-width: 90px; padding: 7px 10px; background-color: #fff1f2; color: #be123c; border: 1.5px solid #fecdd3; border-radius: 9px; font-size: 11px; font-weight: 800; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 5px; transition: all 0.15s;" ' +
                        'onmouseover="this.style.backgroundColor=\'#ffe4e6\'" onmouseout="this.style.backgroundColor=\'#fff1f2\'">' +
                        '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>' +
                        '<span>Catat NG</span>' +
                    '</button>' +
                    '<button type="button" onclick="confirmLotPassed(' + lotId + ', \'' + escapeHtml(lotNo) + '\', \'' + escapeHtml(refNo) + '\', ' + splSize + ')" ' +
                        'style="flex: 1.4; min-width: 130px; padding: 7px 12px; background: linear-gradient(135deg, #059669 0%, #047857 100%); color: #ffffff; border: none; border-radius: 9px; font-size: 11px; font-weight: 800; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 5px; box-shadow: 0 2px 5px rgba(5,150,105,0.25); transition: all 0.15s;" ' +
                        'onmouseover="this.style.opacity=\'0.95\'" onmouseout="this.style.opacity=\'1\'">' +
                        '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>' +
                        '<span>Selesai Inspeksi (PASSED)</span>' +
                    '</button>' +
                    extraCancelBtn +
                '</div>';
        }
    } else if (res === 'passed') {
        cardBorder = isFocusMode ? '#34d399' : '#a7f3d0';
        accentColor = '#10b981';
        cardBg = '#f0fdf4';
        var passedCardBadgeText = isReinspected ? 'PASSED (RE-INSPEKSI)' : 'PASSED';
        statusBadgeHtml = '<span style="background-color: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; font-size: 10px; font-weight: 800; padding: 2px 8px; border-radius: 9999px;">' + passedCardBadgeText + '</span>';
        
        var passedResetBtn = isReadOnly ? '' :
            '<button type="button" onclick="resetLotToInProgress(' + lotId + ', \'' + escapeHtml(lotNo) + '\')" style="font-size: 10px; font-weight: 800; color: #1e293b; background-color: #f1f5f9; border: 1.5px solid #94a3b8; border-radius: 6px; padding: 3px 9px; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); transition: all 0.15s;" onmouseover="this.style.backgroundColor=\'#e2e8f0\'; this.style.borderColor=\'#64748b\';" onmouseout="this.style.backgroundColor=\'#f1f5f9\'; this.style.borderColor=\'#94a3b8\';" title="Kembalikan ke status pemeriksaan">' +
                '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>' +
                '<span>Batal / Re-Check</span>' +
            '</button>';

        actionBlockHtml = 
            '<div style="margin-top: 9px; padding: 7px 10px; background-color: #ffffff; border: 1px solid #bbf7d0; border-radius: 8px; display: flex; align-items: center; justify-content: space-between; font-size: 11px;">' +
                '<div style="display: flex; align-items: center; gap: 6px; color: #065f46; font-weight: 700;">' +
                    '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color: #059669;"><polyline points="20 6 9 17 4 12"/></svg>' +
                    '<span>' + splSize + ' pcs sampel fisik lolos (0 NG)</span>' +
                '</div>' +
                passedResetBtn +
            '</div>';
    } else if (res === 'rejected') {
        cardBorder = isFocusMode ? '#f87171' : '#fecdd3';
        accentColor = '#e11d48';
        cardBg = '#fff1f2';

        var isChiefAcc = (parseInt(lot.is_chief_approved || 0) === 1);
        var chiefName = lot.chief_name || 'Chief QC';

        var chiefBadgeHtml = '';
        if (isChiefAcc) {
            chiefBadgeHtml = '<span style="background-color: #ecfdf5; color: #047857; border: 1.5px solid #a7f3d0; font-size: 10px; font-weight: 800; padding: 2px 8px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 4px;" title="Disetujui oleh Chief: ' + escapeHtml(chiefName) + '">' +
                '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>' +
                'ACC: ' + escapeHtml(chiefName) +
            '</span>';
        } else {
            chiefBadgeHtml = '<span style="background-color: #fff7ed; color: #c2410c; border: 1.5px solid #fdba74; font-size: 10px; font-weight: 800; padding: 2px 8px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 4px;" title="Lembar penolakan belum di-ACC Chief QC">' +
                '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>' +
                'Belum Di-ACC Chief' +
            '</span>';
        }

        var rejectedCardBadgeText = isReinspected ? 'REJECTED (RE-INSPEKSI)' : 'REJECTED';
        statusBadgeHtml = '<div style="display: flex; align-items: center; gap: 4px; flex-wrap: wrap;">' +
            '<span style="background-color: #ffe4e6; color: #9f1239; border: 1px solid #fca5a5; font-size: 10px; font-weight: 800; padding: 2px 8px; border-radius: 9999px;">' + rejectedCardBadgeText + '</span>' +
            chiefBadgeHtml +
        '</div>';
        
        var defects = activeDefects.length > 0 ? activeDefects : allLotDefects;
        var defectBadgesHtml = '';

        if (defects.length > 0) {
            defectBadgesHtml += '<div style="display: flex; flex-wrap: wrap; gap: 4px; align-items: center;">';
            defects.forEach(function(d) {
                var dName = d.defect_name || (d.defect_type_name || 'Defect');
                var dCode = d.defect_code ? '[' + escapeHtml(d.defect_code) + '] ' : '';
                var dQty = parseInt(d.qty_ng) || 1;
                defectBadgesHtml += '<span style="background-color: #ffffff; color: #991b1b; border: 1px solid #fca5a5; border-radius: 6px; padding: 2.5px 7px; font-size: 10px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">' +
                    '<span style="color: #e11d48; font-weight: 900;">•</span> ' + dCode + escapeHtml(dName) + ' <b style="color: #be123c; font-family: monospace;">(' + dQty + ' pcs)</b>' +
                '</span>';
            });
            defectBadgesHtml += '</div>';
        } else {
            defectBadgesHtml = '<span style="font-size: 10px; color: #991b1b; font-style: italic;">Defect tercatat: ' + ngCount + ' pcs</span>';
        }

        var rejBtnBadge = isChiefAcc ? 
            '<span style="background: #059669; color: #ffffff; font-size: 8.5px; font-weight: 800; padding: 1px 5px; border-radius: 4px; margin-left: 4px;">ACC OK</span>' : 
            '<span style="background: #dc2626; color: #ffffff; font-size: 8.5px; font-weight: 800; padding: 1px 5px; border-radius: 4px; margin-left: 4px;">Belum ACC</span>';

        var rejWarningNotice = !isChiefAcc ?
            '<div style="margin-top: 6px; padding: 5px 8px; background-color: #fffbeb; border: 1px dashed #fde68a; border-radius: 6px; font-size: 10px; color: #92400e; display: flex; align-items: center; gap: 6px;">' +
                '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink: 0;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>' +
                '<span>Lembar reject belum di-ACC Chief QC. Klik <b>Rejection</b> untuk tanda tangan digital.</span>' +
            '</div>' : '';

        var editNgBtn = isReadOnly ? '' :
            '<button type="button" onclick="openLotNgModal(' + lotId + ', \'' + escapeHtml(lotNo) + '\', \'' + escapeHtml(refNo) + '\', ' + qty + ', ' + splSize + ', ' + rejNum + ')" ' +
                'style="padding: 3px 8px; background-color: #fff1f2; color: #be123c; border: 1.5px solid #fda4af; border-radius: 6px; font-size: 9.5px; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 2px rgba(190,18,60,0.08); transition: all 0.15s;" ' +
                'onmouseover="this.style.backgroundColor=\'#ffe4e6\'; this.style.borderColor=\'#f43f5e\';" onmouseout="this.style.backgroundColor=\'#fff1f2\'; this.style.borderColor=\'#fda4af\';" ' +
                'title="Edit jenis defect, ubah Qty NG, atau tambah temuan defect susulan">' +
                '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>' +
                '<span>Edit / Tambah NG</span>' +
            '</button>';

        var rejActionButtons = isReadOnly ? 
            '<div style="display: flex; gap: 6px; margin-top: 8px;">' +
                '<a href="' + (window.OQC_CONFIG.baseUrl || '') + '/modules/inspection/print_rejection.php?session_lot_id=' + lotId + '" target="_blank" ' +
                    'style="width: 100%; padding: 7px 9px; background-color: #fff1f2; color: #9f1239; border: 1.5px solid #f43f5e; border-radius: 8px; font-size: 10.5px; font-weight: 800; text-align: center; text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 4px; box-shadow: 0 1px 3px rgba(225,29,72,0.12); transition: all 0.15s;" onmouseover="this.style.backgroundColor=\'#ffe4e6\'; this.style.borderColor=\'#e11d48\';" onmouseout="this.style.backgroundColor=\'#fff1f2\'; this.style.borderColor=\'#f43f5e\';" title="Buka lembar penolakan resmi lot ini">' +
                    '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="color: #be123c;"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>' +
                    '<span>Lihat Lembar Rejection Resmi</span>' + rejBtnBadge +
                '</a>' +
            '</div>' :
            '<div style="display: flex; gap: 6px; margin-top: 8px;">' +
                '<a href="' + (window.OQC_CONFIG.baseUrl || '') + '/modules/inspection/print_rejection.php?session_lot_id=' + lotId + '" target="_blank" ' +
                    'style="flex: 1.1; padding: 7px 9px; background-color: #fff1f2; color: #9f1239; border: 1.5px solid #f43f5e; border-radius: 8px; font-size: 10.5px; font-weight: 800; text-align: center; text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 4px; box-shadow: 0 1px 3px rgba(225,29,72,0.12); transition: all 0.15s;" onmouseover="this.style.backgroundColor=\'#ffe4e6\'; this.style.borderColor=\'#e11d48\';" onmouseout="this.style.backgroundColor=\'#fff1f2\'; this.style.borderColor=\'#f43f5e\';" title="Buka lembar penolakan resmi lot ini">' +
                    '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="color: #be123c;"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>' +
                    '<span>Rejection</span>' + rejBtnBadge +
                '</a>' +
                '<button type="button" onclick="openLotReinspectionModal(' + lotId + ', \'' + escapeHtml(lotNo) + '\', \'' + escapeHtml(refNo) + '\', ' + qty + ')" ' +
                    'style="flex: 1.2; padding: 7px 10px; background-color: #be123c; color: #ffffff; border: 1.5px solid #9f1239; border-radius: 8px; font-size: 10.5px; font-weight: 800; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 4px; box-shadow: 0 2px 4px rgba(190,18,60,0.3); transition: all 0.15s;" onmouseover="this.style.filter=\'brightness(1.08)\'" onmouseout="this.style.filter=\'none\'">' +
                    '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg>' +
                    '<span>Re-Inspeksi</span>' +
                '</button>' +
                '<button type="button" onclick="resetLotToInProgress(' + lotId + ', \'' + escapeHtml(lotNo) + '\')" ' +
                    'style="padding: 7px 12px; background-color: #f1f5f9; color: #334155; border: 1.5px solid #94a3b8; border-radius: 8px; font-size: 10.5px; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 2px rgba(0,0,0,0.06); transition: all 0.15s;" onmouseover="this.style.backgroundColor=\'#e2e8f0\'; this.style.borderColor=\'#64748b\';" onmouseout="this.style.backgroundColor=\'#f1f5f9\'; this.style.borderColor=\'#94a3b8\';" title="Kembalikan ke status pemeriksaan">' +
                    '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>' +
                    '<span>Batal</span>' +
                '</button>' +
            '</div>';

        actionBlockHtml = 
            '<div style="margin-top: 9px; space-y: 6px;">' +
                '<div style="padding: 6px 8px; background-color: #ffffff; border: 1px solid #fecdd3; border-radius: 8px; font-size: 10.5px;">' +
                    '<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">' +
                        '<div style="font-size: 9.5px; font-weight: 800; color: #9f1239; text-transform: uppercase;">RINCIAN TEMUAN DEFECT (' + ngCount + ' PCS NG):</div>' +
                        editNgBtn +
                    '</div>' +
                    defectBadgesHtml +
                '</div>' +
                rejWarningNotice +
                rejActionButtons +
            '</div>';
    } else if (res === 'skipped') {
        cardBorder = '#e9d5ff';
        accentColor = '#a855f7';
        cardBg = '#faf5ff';
        statusBadgeHtml = '<span style="background-color: #f3e8ff; color: #6b21a8; border: 1px solid #d8b4fe; font-size: 9.5px; font-weight: 800; padding: 2px 7.5px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 3.5px; white-space: nowrap; line-height: 1.2; box-shadow: 0 1px 2px rgba(107,33,168,0.06);">' +
            '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="color: #9333ea; flex-shrink: 0;"><polyline points="20 6 9 17 4 12"/></svg>' +
            '<span>DILEWATI (SAFETY STOCK)</span>' +
        '</span>';
        actionBlockHtml = 
            '<div style="margin-top: 8px; padding: 6px 10px; background-color: #ffffff; border: 1px solid #e9d5ff; border-radius: 8px; font-size: 10px; color: #6b21a8; font-weight: 600; display: flex; align-items: center; gap: 6px;">' +
                '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="color: #9333ea; flex-shrink: 0;"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>' +
                '<span>Barang gudang safety stock (Sudah lolos inspeksi sebelumnya, tidak perlu diuji ulang)</span>' +
            '</div>';
    }

    var boxBadge = '<span style="font-size: 10px; font-weight: 800; color: #2563eb; background-color: #eff6ff; border: 1px solid #bfdbfe; padding: 2px 7px; border-radius: 6px;">Box #' + (lot._origBoxNum || 1) + '</span>';
    var ssBadgeText = (s.inspection_type === 'safety_stock') ? 'SAFETY STOCK' : 'ALOKASI SAFETY STOCK';
    var ssBadgeTitle = (s.inspection_type === 'safety_stock') ? 'Barang Safety Stock Gudang' : 'Barang dari Safety Stock Gudang (Wajib Re-Check untuk Kanban)';
    var ssBadge = isSs ? '<span style="font-size: 9px; font-weight: 900; color: #6b21a8; background-color: #f3e8ff; border: 1.5px solid #c084fc; padding: 2px 7px; border-radius: 6px; letter-spacing: 0.3px; display: inline-flex; align-items: center; gap: 4px;" title="' + ssBadgeTitle + '">' + ssBadgeText + '</span>' : '';
    
    var prevDefectsList = lot.previous_ng_defects || (lot.rejected_info ? lot.rejected_info.ng_defects : []);
    var isPrevRej = lot.was_rejected_before || (prevDefectsList && prevDefectsList.length > 0) || (lot.remarks && (lot.remarks.indexOf('REJECTED') !== -1 || lot.remarks.indexOf('Bekas NG') !== -1));

    // Badge header box: Jangan tampilkan "Re-Inspeksi NG" karena bisa mislead user
    // (item mungkin sudah PASSED). Riwayat NG tetap ada di section bawah.
    var repBadge = isReplacement ? '<span style="font-size: 9px; font-weight: 800; color: #1d4ed8; background-color: #eff6ff; border: 1px solid #bfdbfe; padding: 1px 6px; border-radius: 4px;">Box Pengganti</span>' : (isReinspected ? '<span style="font-size: 9px; font-weight: 800; color: #b45309; background-color: #fffbeb; border: 1px solid #fde68a; padding: 1px 6px; border-radius: 4px;">Hasil Sortir</span>' : '');

    var removeLotBtn = '';
    if (!isReadOnly && s.status === 'in_progress' && !isReplaced) {
        removeLotBtn = '<button type="button" onclick="confirmRemoveLot(' + lotId + ', ' + (lot._origBoxNum || 1) + ', \'' + escapeHtml(lotNo) + '\', \'' + escapeHtml(refNo) + '\', ' + qty + ')" ' +
            'style="padding: 3px 8px; background-color: #fef2f2; color: #991b1b; border: 1.5px solid #f87171; border-radius: 6px; font-size: 9.5px; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 3px rgba(185,28,28,0.12); transition: all 0.15s;" ' +
            'onmouseover="this.style.backgroundColor=\'#fee2e2\'; this.style.borderColor=\'#ef4444\';" onmouseout="this.style.backgroundColor=\'#fef2f2\'; this.style.borderColor=\'#f87171\';" ' +
            'title="Batal pakai box ini dan hapus dari sesi agar dapat discan di sesi/PC lain">' +
            '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="color: #b91c1c;"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>' +
            '<span>Batal Pakai</span>' +
        '</button>';
    }

    var identityGridHtml = 
        '<div style="margin-top: 7px; display: flex; align-items: center; justify-content: space-between; gap: 8px; background-color: rgba(248, 250, 252, 0.9); border: 1px solid #e2e8f0; border-radius: 8px; padding: 5px 9px;">' +
            '<div style="min-width: 0;">' +
                '<span style="display: block; font-size: 8px; font-weight: 800; color: #64748b; letter-spacing: 0.05em; text-transform: uppercase;">LOT NUMBER</span>' +
                '<span style="font-size: 12.5px; font-weight: 900; color: #0f172a; font-family: monospace; line-height: 1.2;" title="' + escapeHtml(lotNo) + '">' +
                    escapeHtml(lotNo) +
                '</span>' +
            '</div>' +
            '<div style="text-align: right; flex-shrink: 0;">' +
                '<span style="display: block; font-size: 8.5px; font-weight: 800; color: #64748b; letter-spacing: 0.05em; text-transform: uppercase;">REF NUMBER</span>' +
                '<span style="font-size: 11.5px; font-weight: 800; color: #1e40af; font-family: monospace; white-space: nowrap; line-height: 1.2;" title="' + escapeHtml(refNo || '-') + '">' +
                    escapeHtml(refNo || '-') +
                '</span>' +
            '</div>' +
        '</div>';

    var ngValColor = (ngCount >= rejNum) ? '#be123c' : ((ngCount > 0) ? '#b45309' : '#059669');
    var ngBgColor = (ngCount >= rejNum) ? '#fff1f2' : ((ngCount > 0) ? '#fef3c7' : '#f8fafc');
    var ngBorderColor = (ngCount >= rejNum) ? '#fecdd3' : ((ngCount > 0) ? '#fde68a' : '#e2e8f0');
    var ngTitleColor = (ngCount >= rejNum) ? '#9f1239' : ((ngCount > 0) ? '#92400e' : '#64748b');

    var aqlStandardText = '(' + escapeHtml(aqlLevel) + (splCode ? (' · Kode ' + escapeHtml(splCode)) : '') + ')';

    var statsGridHtml = 
        '<div style="display: grid; grid-template-columns: 1fr 1fr 1.2fr; gap: 5px; margin-top: 6px;">' +
            '<div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 6px 6px; text-align: center; display: flex; flex-direction: column; justify-content: space-between;">' +
                '<span style="display: block; font-size: 9.5px; font-weight: 800; color: #64748b; letter-spacing: 0.03em;">QTY LOT</span>' +
                '<span style="font-size: 14px; font-weight: 900; color: #0f172a; font-family: monospace;">' + qty.toLocaleString() + ' pcs</span>' +
            '</div>' +
            '<div style="background-color: #eff6ff; border: 1.5px solid #bfdbfe; border-radius: 8px; padding: 6px 6px; text-align: center; display: flex; flex-direction: column; justify-content: space-between;" title="Standar Uji: AQL ' + escapeHtml(aqlLevel) + (splCode ? (', Kode Sampel ' + escapeHtml(splCode)) : '') + '">' +
                '<div style="line-height: 1.25;">' +
                    '<span style="display: block; font-size: 9.5px; font-weight: 800; color: #1e40af; letter-spacing: 0.03em;">SAMPEL CEK</span>' +
                    '<span style="display: block; font-size: 10.5px; font-weight: 800; color: #1d4ed8; margin-top: 1px;">' + aqlStandardText + '</span>' +
                '</div>' +
                '<span style="font-size: 15px; font-weight: 900; color: #1d4ed8; font-family: monospace; display: block; margin-top: 3px;">' + splSize + ' pcs</span>' +
            '</div>' +
            '<div style="background-color: ' + ngBgColor + '; border: 1px solid ' + ngBorderColor + '; border-radius: 8px; padding: 6px 6px; text-align: center; display: flex; flex-direction: column; justify-content: space-between;">' +
                '<span style="display: block; font-size: 9.5px; font-weight: 800; color: ' + ngTitleColor + '; letter-spacing: 0.03em;">TEMUAN / BATAS</span>' +
                '<span style="font-size: 14px; font-weight: 900; color: ' + ngValColor + '; font-family: monospace;">' + ngCount + ' / ' + rejNum + ' pcs</span>' +
            '</div>' +
        '</div>';

    var prevNgHistoryHtml = '';
    if (isPrevRej) {
        var roundsMap = {};
        var roundOrder = [];

        if (Array.isArray(prevDefectsList) && prevDefectsList.length > 0) {
            prevDefectsList.forEach(function(pd) {
                var sid = pd.prev_session_id || 'default';
                if (!roundsMap[sid]) {
                    roundsMap[sid] = {
                        sessionId: sid,
                        dateRaw: pd.prev_closed_at || pd.prev_started_at || '',
                        inspector: pd.prev_inspector_name || '',
                        defects: []
                    };
                    roundOrder.push(sid);
                }
                if (pd.defect_name || pd.remark) {
                    roundsMap[sid].defects.push(pd);
                }
            });
        }

        var months = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
        var roundsHtml = '';

        if (roundOrder.length > 0) {
            roundOrder.forEach(function(sid, idx) {
                var rData = roundsMap[sid];
                var roundNumber = idx + 1;
                var roundTitle = (idx === 0) ? 'Inspeksi Awal' : ('Re-Inspeksi Ke-' + idx);

                var dateDisp = '-';
                if (rData.dateRaw && rData.dateRaw !== '-') {
                    try {
                        var dObj = new Date(rData.dateRaw);
                        if (!isNaN(dObj.getTime())) {
                            dateDisp = dObj.getDate() + ' ' + months[dObj.getMonth()] + ' ' + dObj.getFullYear() + ', ' + String(dObj.getHours()).padStart(2, '0') + ':' + String(dObj.getMinutes()).padStart(2, '0') + ' WIB';
                        } else {
                            dateDisp = rData.dateRaw;
                        }
                    } catch(e) {
                        dateDisp = rData.dateRaw;
                    }
                }

                var defectBadges = '';
                if (rData.defects && rData.defects.length > 0) {
                    rData.defects.forEach(function(df) {
                        var dName = escapeHtml(df.defect_name || df.remark || 'Defect');
                        var dCode = df.defect_code ? ('[' + escapeHtml(df.defect_code) + '] ') : '';
                        var dQty = (parseInt(df.qty_ng, 10) || 1) + ' pcs';
                        defectBadges += '<span style="background-color: #ffffff; color: #9f1239; border: 1px solid #fecdd3; border-radius: 6px; padding: 2px 7px; font-size: 10px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 2px rgba(159,18,57,0.05);">' +
                            '<span style="color: #e11d48; font-weight: 900;">•</span> ' + dCode + dName + ' <b style="color: #be123c; font-family: monospace;">(' + dQty + ')</b>' +
                        '</span>';
                    });
                } else {
                    defectBadges = '<span style="font-size: 9.5px; color: #9f1239; font-style: italic;">Defect tercatat pada sesi ini</span>';
                }

                var inspTag  = rData.inspector ? escapeHtml(rData.inspector) : '-';
                var shiftTag = rData.dateRaw   ? getShiftLabel(rData.dateRaw) : '';

                roundsHtml += 
                    '<div style="background-color: #ffffff; border: 1px solid #fecdd3; border-radius: 8px; padding: 6px 8px; display: flex; flex-direction: column; gap: 4px; box-shadow: 0 1px 2px rgba(225,29,72,0.03);">' +
                        '<div style="display: flex; align-items: center; justify-content: space-between; gap: 6px; font-size: 9px; flex-wrap: wrap;">' +
                            '<div style="display: flex; align-items: center; gap: 4px;">' +
                                '<span style="background-color: #ffe4e6; color: #9f1239; border: 1px solid #fecdd3; padding: 1px 6px; border-radius: 4px; font-weight: 900; font-size: 9px; letter-spacing: 0.2px; white-space: nowrap;">' + roundTitle + '</span>' +
                                '<span style="color: #64748b; font-weight: 600;">QC: <b style="color: #334155;">' + inspTag + '</b></span>' +
                                (shiftTag ? '<span style="background-color: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; padding: 1px 5px; border-radius: 4px; font-weight: 700; font-size: 8.5px;">' + shiftTag + '</span>' : '') +
                            '</div>' +
                            '<span style="font-family: monospace; font-size: 8.5px; font-weight: 700; color: #64748b; white-space: nowrap;">' +
                                escapeHtml(dateDisp) +
                            '</span>' +
                        '</div>' +
                        '<div style="display: flex; flex-wrap: wrap; gap: 4px;">' + defectBadges + '</div>' +
                    '</div>';
            });
        } else if (lot.remarks) {
            roundsHtml = '<div style="background-color: #ffffff; border: 1px solid #fecdd3; border-radius: 8px; padding: 6px 8px; font-size: 10px; color: #9f1239; font-weight: 600;">' + escapeHtml(lot.remarks) + '</div>';
        } else {
            roundsHtml = '<div style="background-color: #ffffff; border: 1px solid #fecdd3; border-radius: 8px; padding: 6px 8px; font-size: 10px; color: #9f1239; font-style: italic;">Riwayat Rejection Terdahulu</div>';
        }

        // Data sesi yang PASSED sebelumnya (dari API: prev_passed_session)
        var prevPassedSess   = lot.prev_passed_session || null;
        var passedInspector  = prevPassedSess ? (prevPassedSess.passed_inspector_name || '-') : '-';
        var passedDateRaw    = prevPassedSess ? (prevPassedSess.passed_closed_at || prevPassedSess.passed_started_at || '') : '';
        var passedDateDisp   = passedDateRaw ? formatDateWib(passedDateRaw) : '-';
        var passedShift      = passedDateRaw ? getShiftLabel(passedDateRaw) : '';

        // Tentukan label "lulus di inspeksi ke-berapa":
        // roundOrder.length = jumlah sesi yang NG → lulus di inspeksi berikutnya
        var passedAtLabel = (roundOrder.length === 0) ? 'Inspeksi Awal' : ('Re-Inspeksi Ke-' + roundOrder.length);

        var reinspectionStatusCard = '';
        if (prevPassedSess) {
            // Skenario: Lot telah dinyatakan PASSED pada re-inspeksi terdahulu
            reinspectionStatusCard = 
                '<div style="margin-top: 6px; padding: 7px 10px; background-color: #f0fdf4; border: 1.5px solid #86efac; border-radius: 8px; display: flex; flex-direction: column; gap: 4px;">' +
                    '<div style="display: flex; align-items: center; justify-content: space-between; gap: 6px; flex-wrap: wrap;">' +
                        '<div style="display: flex; align-items: center; gap: 5px; flex-wrap: wrap;">' +
                            '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="color: #16a34a; flex-shrink: 0;"><polyline points="20 6 9 17 4 12"/></svg>' +
                            '<span style="font-size: 9.5px; font-weight: 900; color: #14532d; text-transform: uppercase; letter-spacing: 0.3px;">Lulus pada:</span>' +
                            '<span style="background-color: #dcfce7; color: #166534; border: 1px solid #86efac; padding: 1px 7px; border-radius: 4px; font-size: 9px; font-weight: 800;">' + passedAtLabel + '</span>' +
                            '<span style="color: #166534; font-size: 9px; font-weight: 600;">QC: <b style="color: #14532d;">' + escapeHtml(passedInspector) + '</b></span>' +
                            (passedShift ? '<span style="background-color: #dcfce7; color: #166534; border: 1px solid #86efac; padding: 1px 5px; border-radius: 4px; font-weight: 800; font-size: 8.5px;">' + passedShift + '</span>' : '') +
                        '</div>' +
                        '<span style="font-family: monospace; font-size: 8.5px; font-weight: 700; color: #166534; white-space: nowrap;">' +
                            escapeHtml(passedDateDisp) +
                        '</span>' +
                    '</div>' +
                    '<div style="font-size: 9px; color: #15803d; font-weight: 600; line-height: 1.3;">' +
                        'Lot ini telah dinyatakan lulus pada pemeriksaan ulang sebelumnya sebelum dialokasikan untuk pemeriksaan Kanban ini.' +
                    '</div>' +
                '</div>';
        } else {
            // Skenario: Belum pernah ada re-inspeksi yang passed (baru kali ini di-reinspeksi lagi)
            var dInfo = lot.disposition_info || (lot.rejected_info && lot.rejected_info.disposition) || null;
            var dispNotes = '';
            var dispBy = '';
            var dispAt = '';

            if (dInfo && dInfo.has_disposition && dInfo.notes) {
                dispNotes = dInfo.notes;
                dispBy = dInfo.closed_by || 'Admin / Supervisor';
                dispAt = dInfo.closed_at || '-';
            }

            if (dispNotes) {
                reinspectionStatusCard = 
                    '<div style="margin-top: 6px; padding: 7px 10px; background-color: #f0f9ff; border: 1.5px solid #7dd3fc; border-radius: 8px; display: flex; flex-direction: column; gap: 4px;">' +
                        '<div style="display: flex; align-items: center; justify-content: space-between; gap: 6px; flex-wrap: wrap;">' +
                            '<div style="display: flex; align-items: center; gap: 5px;">' +
                                '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="color: #0284c7; flex-shrink: 0;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>' +
                                '<span style="font-size: 9.5px; font-weight: 900; color: #0369a1; text-transform: uppercase;">Catatan Action Admin / Divisi:</span>' +
                            '</div>' +
                            '<span style="background-color: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; padding: 1px 6px; border-radius: 4px; font-size: 8.5px; font-weight: 800;">Action Selesai</span>' +
                        '</div>' +
                        '<div style="font-size: 9.5px; color: #0c4a6e; font-weight: 700; line-height: 1.35; background: #ffffff; padding: 5px 8px; border-radius: 6px; border: 1px solid #e0f2fe;">' +
                            '"' + escapeHtml(dispNotes) + '"' +
                        '</div>' +
                        '<div style="display: flex; align-items: center; justify-content: space-between; font-size: 8.5px; color: #0369a1; margin-top: 2px;">' +
                            '<span>Oleh: <b>' + escapeHtml(dispBy) + '</b></span>' +
                            '<span>Waktu: <b>' + escapeHtml(dispAt) + '</b></span>' +
                        '</div>' +
                        '<div style="background-color: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 5px; padding: 3px 6px; display: flex; align-items: center; gap: 5px; margin-top: 2px;">' +
                            '<span style="background-color: #059669; color: #ffffff; font-size: 8px; font-weight: 900; padding: 1px 5px; border-radius: 3px;">✓ SIAP RE-INSPEKSI</span>' +
                            '<span style="font-size: 8.5px; font-weight: 700; color: #065f46;">Box telah disortir/ditindaklanjuti. Fokus uji ulang pada titik defect sebelumnya.</span>' +
                        '</div>' +
                    '</div>';
            } else {
                reinspectionStatusCard = 
                    '<div style="margin-top: 6px; padding: 7px 10px; background-color: #fffbeb; border: 1.5px solid #fde68a; border-radius: 8px; display: flex; flex-direction: column; gap: 3px;">' +
                        '<div style="display: flex; align-items: center; justify-content: space-between; gap: 6px; flex-wrap: wrap;">' +
                            '<div style="display: flex; align-items: center; gap: 5px;">' +
                                '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="color: #d97706; flex-shrink: 0;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>' +
                                '<span style="font-size: 9.5px; font-weight: 800; color: #92400e;">Status Action & Re-Inspeksi</span>' +
                            '</div>' +
                            '<span style="background-color: #fef9c3; color: #a16207; border: 1px solid #fef08a; padding: 1px 5px; border-radius: 3px; font-size: 8px; font-weight: 800;">Pending Action</span>' +
                        '</div>' +
                        '<div style="font-size: 9px; color: #b45309; font-weight: 600; line-height: 1.3;">' +
                            'Belum ada catatan action resmi dari Admin/Divisi. Lot tetap dapat diperiksa ulang pada sesi ini.' +
                        '</div>' +
                        '<div style="background-color: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 5px; padding: 3px 6px; display: flex; align-items: center; gap: 5px; margin-top: 2px;">' +
                            '<span style="background-color: #059669; color: #ffffff; font-size: 8px; font-weight: 900; padding: 1px 5px; border-radius: 3px;">✓ BISA RE-INSPEKSI</span>' +
                            '<span style="font-size: 8.5px; font-weight: 700; color: #065f46;">Lakukan pemeriksaan ulang dengan teliti sebelum memutuskan PASSED/REJECTED.</span>' +
                        '</div>' +
                    '</div>';
            }
        }

        prevNgHistoryHtml = 
            '<div style="margin-top: 7px; padding: 8px 10px; background-color: #fff8f1; border: 1.5px solid #fdba74; border-radius: 10px; font-size: 10.5px;">' +
                '<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px; padding-bottom: 4px; border-bottom: 1px dashed #fed7aa;">' +
                    '<span style="font-weight: 900; color: #92400e; font-size: 9.5px; text-transform: uppercase; letter-spacing: 0.4px; display: inline-flex; align-items: center; gap: 5px;">' +
                        '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="color: #d97706; flex-shrink: 0;"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>' +
                        'RIWAYAT NG SEBELUMNYA:' +
                    '</span>' +
                    '<span style="font-size: 9px; font-weight: 800; color: #92400e; background-color: #fef3c7; border: 1px solid #fde68a; padding: 1px 6px; border-radius: 4px;">' +
                        (roundOrder.length > 0 ? (roundOrder.length + 'x Pernah NG') : 'Pernah NG') +
                    '</span>' +
                '</div>' +
                '<div style="display: flex; flex-direction: column; gap: 5px;">' + roundsHtml + '</div>' +
                reinspectionStatusCard +
            '</div>';

    } else if (isSs && !isPrevRej && s.inspection_type === 'kanban' && (lot.is_safety_stock || lot.is_safety_stock_alloc || (lot.remarks && lot.remarks.toUpperCase().indexOf('ALOKASI SAFETY STOCK') !== -1))) {
        // Safety Stock item tanpa riwayat NG yang dialokasikan ke Kanban → tampilkan notice formal
        prevNgHistoryHtml =
            '<div style="margin-top: 7px; padding: 7px 10px; background-color: #f8fafc; border: 1.5px solid #cbd5e1; border-radius: 10px; display: flex; align-items: flex-start; gap: 7px;">' +
                '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="color: #64748b; flex-shrink: 0; margin-top: 1px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>' +
                '<div>' +
                    '<div style="font-size: 9.5px; font-weight: 800; color: #334155; margin-bottom: 2px;">Tidak Terdapat Riwayat Penolakan</div>' +
                    '<div style="font-size: 9px; color: #64748b; font-weight: 600;">Lot ini tidak memiliki riwayat penolakan sebelumnya. Pemeriksaan ini merupakan pemeriksaan ulang pertama untuk keperluan alokasi Kanban.</div>' +
                '</div>' +
            '</div>';
    }

    var shadowStyle = isFocusMode ? 'box-shadow: 0 4px 10px rgba(0,0,0,0.05);' : 'box-shadow: 0 1px 3px rgba(0,0,0,0.03);';
    var borderStyle = isFocusMode ? 'border: 2px solid ' + cardBorder + ';' : 'border: 1.5px solid ' + cardBorder + ';';

    return '<div style="background-color: ' + cardBg + '; ' + borderStyle + ' border-left: 4px solid ' + accentColor + '; border-radius: 14px; padding: 9px 11px; ' + shadowStyle + ' transition: all 0.15s;">' +
        '<div style="display: flex; align-items: center; justify-content: space-between; gap: 8px;">' +
            '<div style="display: flex; align-items: center; gap: 5px; flex-wrap: wrap; min-width: 0;">' +
                boxBadge +
                removeLotBtn +
                ssBadge +
                repBadge +
            '</div>' +
            '<div style="flex-shrink: 0;">' + statusBadgeHtml + '</div>' +
        '</div>' +
        identityGridHtml +
        statsGridHtml +
        sortedHistoryHtml +
        prevNgHistoryHtml +
        actionBlockHtml +
    '</div>';
}

function switchLotFilterTab(tabName) {
    currentLotFilterTab = tabName || 'all';
    if (window.lastWorkbenchSessionData) {
        renderWorkbenchLotFlow(window.lastWorkbenchSessionData);
    }
}

/**
 * Alur Otomatis Saat Sesi Inspeksi PASSED / Selesai:
 * Langsung buka Modal Step 1 (Pilih Planning / Scan Barcode) tanpa menampilkan dialog interupsi.
 */
function handleSessionFinishedModalFlow(data) {
    if (!data || !data.session) return;
    var s = data.session;
    if (typeof updateWorkbenchLiveBadge === 'function') { updateWorkbenchLiveBadge(s); }
    if (s.status !== 'passed') return;
    if (typeof isReadOnlyView !== 'undefined' && isReadOnlyView) return;

    // Pastikan loading spinner Swal sebelumnya segera ditutup bersih
    if (typeof Swal !== 'undefined' && Swal.isVisible()) {
        Swal.close();
    }

    // Langsung buka Modal Step 1 (Pilih Item Planning Aktif) secara instan agar smart scanner dapat mendeteksi apakah lot berikutnya Kanban atau Safety Stock
    setTimeout(function() {
        openScanModal(0, 'kanban');
    }, 60);
}

function renderWorkbenchLotFlow(data) {
    if (!data || !data.session) return;
    window.lastWorkbenchSessionData = data;
    var s = data.session;
    if (typeof updateWorkbenchLiveBadge === 'function') { updateWorkbenchLiveBadge(s); }
    if (s && s.part_code && isPartNgHistoryExpanded) {
        loadPartNgHistory(s.part_code, true);
    }
    var lots = data.session_lots || [];
    var ngRecordsByLot = data.ng_records_by_lot || {};

    if (data.ng_records && (!ngRecordsByLot || Object.keys(ngRecordsByLot).length === 0)) {
        ngRecordsByLot = {};
        data.ng_records.forEach(function(rec) {
            var lid = rec.session_lot_id || 0;
            if (!ngRecordsByLot[lid]) ngRecordsByLot[lid] = [];
            ngRecordsByLot[lid].push(rec);
        });
    }

    // 1. Calculate lot stats
    var totalLots = lots.length;
    var finishedCount = 0;
    var passedCount = 0;
    var rejectedCount = 0;
    var replacedCount = 0;
    var reinspectedCount = 0;
    var skippedCount = 0;
    var inProgressCount = 0;
    var totalPhysicalSamples = 0;

    // Hitung total kuantitas temuan defect (NG) fisik pada sesi ini (aktif maupun riwayat sortir)
    var totalNgPcs = 0;
    var ngRecsList = data.ng_records || [];
    if (Array.isArray(ngRecsList)) {
        ngRecsList.forEach(function(nr) {
            totalNgPcs += (parseInt(nr.qty_ng) || 1);
        });
    }

    var activeLotsCount = 0;
    lots.forEach(function(lot) {
        var res = lot.lot_result || 'in_progress';
        var status = lot.lot_status || 'ok';
        var sSize = parseInt(lot.sample_size) || 0;
        var lotNg = parseInt(lot.ng_count) || 0;
        var hasNgRec = ngRecordsByLot[lot.id] && ngRecordsByLot[lot.id].length > 0;

        // Lot yang digantikan (karena NG): sampel fisiknya SUDAH diperiksa oleh operator!
        if (status === 'replaced') {
            replacedCount++;
            totalPhysicalSamples += sSize;
            return;
        }

        activeLotsCount++;
        if (res === 'passed') {
            finishedCount++;
            passedCount++;
            totalPhysicalSamples += sSize;
        } else if (res === 'rejected') {
            finishedCount++;
            rejectedCount++;
            totalPhysicalSamples += sSize;
        } else if (res === 'skipped') {
            finishedCount++;
            skippedCount++;
        } else if (status === 'reinspected') {
            reinspectedCount++;
            totalPhysicalSamples += sSize;
            inProgressCount++;
        } else if (status === 'ng_found' || lotNg > 0 || hasNgRec) {
            totalPhysicalSamples += sSize;
            inProgressCount++;
        } else {
            inProgressCount++;
        }
    });

    // 2. Update Card 2: Ringkasan Sesi
    var lotProgEl = document.getElementById('summary-lot-progress');
    if (lotProgEl) {
        lotProgEl.textContent = finishedCount + ' / ' + activeLotsCount + ' Lot';
    }
    var lotSubEl = document.getElementById('summary-lot-subtext');
    if (lotSubEl) {
        var subParts = [];
        if (passedCount > 0) subParts.push(passedCount + ' Lolos (OK)');
        if (rejectedCount > 0) subParts.push(rejectedCount + ' Ditolak (NG)');
        if (replacedCount > 0) subParts.push(replacedCount + ' Diganti (NG)');
        if (reinspectedCount > 0) subParts.push(reinspectedCount + ' Disortir (NG)');
        if (skippedCount > 0) subParts.push(skippedCount + ' Safety Stock');
        
        var pendingWaiting = activeLotsCount - finishedCount - reinspectedCount;
        if (pendingWaiting > 0) {
            subParts.push(pendingWaiting + ' Sedang Diuji');
        }
        lotSubEl.innerHTML = subParts.length > 0 ? subParts.join(' &bull; ') : 'Menunggu pemeriksaan';
    }

    var splTotalEl = document.getElementById('summary-sample-total');
    if (splTotalEl) {
        splTotalEl.textContent = totalPhysicalSamples.toLocaleString() + ' pcs';
    }

    var splSubEl = document.getElementById('summary-sample-subtext');
    if (splSubEl) {
        if (totalNgPcs > 0) {
            splSubEl.innerHTML = 'Akumulasi sampel fisik AQL &bull; <span style="color: #e11d48; font-weight: 700;">' + totalNgPcs + ' pcs NG</span>';
        } else {
            splSubEl.textContent = 'Akumulasi sampel fisik AQL';
        }
    }

    var isSessReinspect = (parseInt(s.is_reinspection || 0) === 1) ||
                          (parseInt(s.parent_session_id || 0) > 0) ||
                          ((s.reinspection_type || '') !== '') ||
                          ((s.remarks || '').toUpperCase().indexOf('RE-INSPEKSI') !== -1) ||
                          ((s.remarks || '').toUpperCase().indexOf('REINSPEKSI') !== -1) ||
                          (lots && lots.some(function(l) {
                              var r = (l.remarks || '').toUpperCase();
                              return l.lot_status === 'reinspected' || r.indexOf('RE-INSPEKSI') !== -1 || r.indexOf('REINSPEKSI') !== -1;
                          }));

    var badgeSessEl = document.getElementById('badge-session-status');
    if (badgeSessEl) {
        if (s.status === 'passed') {
            badgeSessEl.className = 'px-2.5 py-0.5 rounded-full text-[10px] font-extrabold shadow-2xs bg-emerald-100 text-emerald-800 border border-emerald-300';
            badgeSessEl.textContent = isSessReinspect ? 'PASSED (RE-INSPEKSI)' : 'PASSED';
        } else if (s.status === 'rejected') {
            badgeSessEl.className = 'px-2.5 py-0.5 rounded-full text-[10px] font-extrabold shadow-2xs bg-rose-100 text-rose-800 border border-rose-300';
            badgeSessEl.textContent = isSessReinspect ? 'REJECTED (RE-INSPEKSI)' : 'REJECTED';
        } else if (s.status === 'cancelled') {
            badgeSessEl.className = 'px-2.5 py-0.5 rounded-full text-[10px] font-extrabold shadow-2xs bg-slate-200 text-slate-700 border border-slate-300';
            badgeSessEl.textContent = 'DIBATALKAN';
        } else {
            badgeSessEl.className = 'px-2.5 py-0.5 rounded-full text-[10px] font-extrabold shadow-2xs bg-amber-100 text-amber-800 border border-amber-300';
            badgeSessEl.textContent = isSessReinspect ? 'IN PROGRESS (RE-INSPEKSI)' : 'IN PROGRESS';
        }
    }

    // 3. Update Card 3: Lot Count Badge, Tab Counts, Sorting & Render Focus Box / Cards
    var lotCountBadge = document.getElementById('lot-count-badge');
    if (lotCountBadge) {
        lotCountBadge.textContent = activeLotsCount + ' Lot';
    }

    // Tetapkan penomoran fisik box kardus asli (urutan scan)
    lots.forEach(function(lot, idx) {
        lot._origBoxNum = idx + 1;
    });

    // Hitung jumlah lot untuk masing-masing status
    var countAll = lots.length;
    var countPending = 0;
    var countPassed = 0;
    var countRejected = 0;

    lots.forEach(function(lot) {
        var res = lot.lot_result || 'in_progress';
        var status = lot.lot_status || 'ok';
        var lotNg = parseInt(lot.ng_count) || 0;
        var hasNgRec = ngRecordsByLot[lot.id] && ngRecordsByLot[lot.id].length > 0;

        if (res === 'in_progress' && status !== 'replaced') {
            countPending++;
        }
        if (res === 'passed') {
            countPassed++;
        }
        if (res === 'rejected' || status === 'replaced' || status === 'ng_found' || lotNg > 0 || hasNgRec) {
            countRejected++;
        }
    });

    // Perbarui tombol "Tambah Box" & "Tandai Semua Box PASSED"
    var isReadOnlyMode = (window.OQC_CONFIG && window.OQC_CONFIG.isReadOnlyView);
    var btnAddLot = document.getElementById('btn-add-lot');
    if (btnAddLot) {
        btnAddLot.style.display = (!isReadOnlyMode && (s.status === 'in_progress' || s.status === 'passed' || s.status === 'rejected')) ? 'inline-flex' : 'none';
    }

    var btnPassAll = document.getElementById('btn-pass-all-lots');
    if (btnPassAll) {
        if (!isReadOnlyMode && countPending > 0 && s.status === 'in_progress') {
            btnPassAll.style.display = 'inline-flex';
            btnPassAll.querySelector('span').textContent = 'Tandai Semua Box PASSED (' + countPending + ')';
            btnPassAll.title = 'Loloskan seluruh ' + countPending + ' sisa box yang belum dicek sekaligus';
        } else {
            btnPassAll.style.display = 'none';
        }
    }

    // Tentukan box yang sedang difokuskan (Active Focus Box)
    var activeFocusLot = null;
    if (activeFocusLotId) {
        activeFocusLot = lots.find(function(l) { return l.id === activeFocusLotId; });
    }
    // Jika belum ada pilihan atau box yang dipilih sudah tidak ada, default ke pending pertama
    if (!activeFocusLot) {
        activeFocusLot = lots.find(function(l) {
            return (l.lot_result === 'in_progress' && l.lot_status !== 'replaced');
        });
    }
    // Jika semua sudah selesai, pilih box pertama
    if (!activeFocusLot && lots.length > 0) {
        activeFocusLot = lots[0];
    }
    activeFocusLotId = activeFocusLot ? activeFocusLot.id : null;

    // Perbarui Dropdown Selector Box Aktif
    var focusSelect = document.getElementById('focus-lot-select');
    if (focusSelect) {
        var optHtml = '';
        lots.forEach(function(lot) {
            var boxNum = lot._origBoxNum || 1;
            var lotNo = lot.lot_number || '-';
            var refNo = lot.ref_number || '';
            var qty = parseInt(lot.qty) || 0;
            var res = lot.lot_result || 'in_progress';
            var lotStatus = lot.lot_status || 'ok';

            var optStatusText = 'Belum Dicek';
            if (res === 'passed') optStatusText = 'PASSED';
            else if (res === 'rejected') optStatusText = 'REJECTED';
            else if (lotStatus === 'replaced') optStatusText = 'DIGANTI';
            else if (res === 'skipped') optStatusText = 'Safety Stock';

            var optRefStr = refNo ? ' | Ref: ' + refNo : '';
            var optText = 'Box #' + boxNum + ' — Lot: ' + lotNo + optRefStr + ' (' + qty + ' pcs) | ' + optStatusText;

            var isSel = (activeFocusLot && lot.id === activeFocusLot.id) ? 'selected' : '';
            optHtml += '<option value="' + lot.id + '" ' + isSel + '>' + escapeHtml(optText) + '</option>';
        });
        focusSelect.innerHTML = optHtml;
    }

    // Perbarui Navigasi Tombol Prev / Next
    var btnPrev = document.getElementById('btn-focus-prev');
    var btnNext = document.getElementById('btn-focus-next');
    var activeIdx = -1;
    if (activeFocusLot) {
        activeIdx = lots.findIndex(function(l) { return l.id === activeFocusLot.id; });
    }
    if (btnPrev) {
        btnPrev.disabled = (activeIdx <= 0);
        btnPrev.style.opacity = (activeIdx <= 0) ? '0.45' : '1';
        btnPrev.style.cursor = (activeIdx <= 0) ? 'not-allowed' : 'pointer';
    }
    if (btnNext) {
        btnNext.disabled = (activeIdx === -1 || activeIdx >= lots.length - 1);
        btnNext.style.opacity = (activeIdx === -1 || activeIdx >= lots.length - 1) ? '0.45' : '1';
        btnNext.style.cursor = (activeIdx === -1 || activeIdx >= lots.length - 1) ? 'not-allowed' : 'pointer';
    }

    // Perbarui Mini Chips Bar ([1] [2] ... [21])
    var chipsContainer = document.getElementById('focus-box-chips-container');
    if (chipsContainer) {
        var chipsHtml = '';
        lots.forEach(function(lot) {
            var boxNum = lot._origBoxNum || 1;
            var lotNo = lot.lot_number || '-';
            var res = lot.lot_result || 'in_progress';
            var lotStatus = lot.lot_status || 'ok';
            var lotNg = parseInt(lot.ng_count) || 0;

            var chipBg = '#f1f5f9';
            var chipColor = '#475569';
            var chipBorder = '#cbd5e1';
            var chipTitleStatus = 'Belum Dicek';

            if (res === 'in_progress' && lotStatus !== 'replaced') {
                chipBg = '#fef3c7';
                chipColor = '#92400e';
                chipBorder = '#fde68a';
                chipTitleStatus = 'Belum Dicek';
            } else if (res === 'passed') {
                chipBg = '#d1fae5';
                chipColor = '#065f46';
                chipBorder = '#a7f3d0';
                chipTitleStatus = 'PASSED';
            } else if (res === 'rejected' || lotStatus === 'replaced' || lotNg > 0) {
                chipBg = '#fee2e2';
                chipColor = '#991b1b';
                chipBorder = '#fecaca';
                chipTitleStatus = 'REJECTED';
            } else if (res === 'skipped') {
                chipBg = '#f3e8ff';
                chipColor = '#6b21a8';
                chipBorder = '#e9d5ff';
                chipTitleStatus = 'Safety Stock';
            }

            var isThisFocused = (activeFocusLot && lot.id === activeFocusLot.id);
            var focusStyle = isThisFocused ? 'box-shadow: 0 0 0 2px #2563eb; font-weight: 900; transform: scale(1.08); z-index: 1;' : 'font-weight: 700;';

            chipsHtml += '<button type="button" onclick="changeFocusBox(' + lot.id + ')" ' +
                'style="flex-shrink: 0; min-width: 28px; height: 26px; padding: 0 6px; font-size: 10px; border-radius: 6px; border: 1.5px solid ' + chipBorder + '; background-color: ' + chipBg + '; color: ' + chipColor + '; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; transition: all 0.15s; ' + focusStyle + '" ' +
                'title="Box #' + boxNum + ' (Lot: ' + escapeHtml(lotNo) + ') - ' + chipTitleStatus + '">' +
                boxNum +
            '</button>';
        });
        chipsContainer.innerHTML = chipsHtml;
    }

    // Render Card Body Box Aktif
    var activeCardBody = document.getElementById('active-focus-card-body');
    if (activeCardBody) {
        if (activeFocusLot) {
            activeCardBody.innerHTML = generateSingleLotCardHtml(activeFocusLot, ngRecordsByLot, s, true);
        } else {
            activeCardBody.innerHTML = '<div class="text-center py-6 text-slate-400 text-xs font-semibold">Tidak ada box yang dipilih.</div>';
        }
    }

    // Perbarui judul toggle ringkasan seluruh box
    var allBoxesToggleTitle = document.getElementById('all-boxes-toggle-title');
    if (allBoxesToggleTitle) {
        allBoxesToggleTitle.textContent = isAllBoxesExpanded ? 
            'Sembunyikan Ringkasan Seluruh Box (' + lots.length + ' Box)' : 
            'Lihat Ringkasan Seluruh Box (' + lots.length + ' Box)';
    }

    // Perbarui angka badge counter pada setiap tab ringkasan
    var cntAllEl = document.getElementById('tab-count-lot-all');
    if (cntAllEl) cntAllEl.textContent = countAll;
    var cntPenEl = document.getElementById('tab-count-lot-pending');
    if (cntPenEl) cntPenEl.textContent = countPending;
    var cntPasEl = document.getElementById('tab-count-lot-passed');
    if (cntPasEl) cntPasEl.textContent = countPassed;
    var cntRejEl = document.getElementById('tab-count-lot-rejected');
    if (cntRejEl) cntRejEl.textContent = countRejected;

    // Perbarui gaya tombol tab yang sedang aktif
    var tabKeys = ['all', 'pending', 'passed', 'rejected'];
    tabKeys.forEach(function(tk) {
        var btn = document.getElementById('tab-lot-filter-' + tk);
        if (!btn) return;
        if (tk === currentLotFilterTab) {
            if (tk === 'all') {
                btn.style.backgroundColor = '#0f172a';
                btn.style.color = '#ffffff';
                btn.style.borderColor = '#0f172a';
            } else if (tk === 'pending') {
                btn.style.backgroundColor = '#d97706';
                btn.style.color = '#ffffff';
                btn.style.borderColor = '#b45309';
            } else if (tk === 'passed') {
                btn.style.backgroundColor = '#059669';
                btn.style.color = '#ffffff';
                btn.style.borderColor = '#047857';
            } else if (tk === 'rejected') {
                btn.style.backgroundColor = '#e11d48';
                btn.style.color = '#ffffff';
                btn.style.borderColor = '#be123c';
            }
            btn.style.fontWeight = '800';
            btn.style.boxShadow = '0 1px 2px rgba(0,0,0,0.1)';
        } else {
            btn.style.backgroundColor = '#ffffff';
            btn.style.color = '#64748b';
            btn.style.borderColor = '#e2e8f0';
            btn.style.fontWeight = '700';
            btn.style.boxShadow = 'none';
        }
    });

    // Auto-Sort Prioritas untuk Ringkasan Seluruh Box:
    // Lot yang belum dicek (in_progress) otomatis berada di PALING ATAS!
    // Lot yang sudah selesai dicek otomatis TURUN KE BAWAH!
    var sortedLots = lots.slice(0);
    sortedLots.sort(function(a, b) {
        var aPending = (a.lot_result === 'in_progress' && a.lot_status !== 'replaced') ? 0 : 1;
        var bPending = (b.lot_result === 'in_progress' && b.lot_status !== 'replaced') ? 0 : 1;
        if (aPending !== bPending) {
            return aPending - bPending;
        }
        return a.id - b.id;
    });

    // Filter sesuai tab aktif
    var filteredLots = sortedLots.filter(function(lot) {
        var res = lot.lot_result || 'in_progress';
        var status = lot.lot_status || 'ok';
        var lotNg = parseInt(lot.ng_count) || 0;
        var hasNgRec = ngRecordsByLot[lot.id] && ngRecordsByLot[lot.id].length > 0;

        if (currentLotFilterTab === 'pending') {
            return (res === 'in_progress' && status !== 'replaced');
        } else if (currentLotFilterTab === 'passed') {
            return (res === 'passed');
        } else if (currentLotFilterTab === 'rejected') {
            return (res === 'rejected' || status === 'replaced' || status === 'ng_found' || lotNg > 0 || hasNgRec);
        }
        return true; // 'all'
    });

    var cardsContainer = document.getElementById('lot-cards-container');
    if (cardsContainer) {
        if (lots.length === 0) {
            cardsContainer.innerHTML = '<div class="text-center py-8 text-slate-400 text-xs">Belum ada label / lot yang discan pada sesi ini.</div>';
        } else if (filteredLots.length === 0) {
            var emptyMsg = 'Tidak ada lot pada filter ini.';
            if (currentLotFilterTab === 'pending') emptyMsg = 'Semua lot telah selesai diperiksa!';
            else if (currentLotFilterTab === 'passed') emptyMsg = 'Belum ada lot yang dinyatakan lolos (Passed).';
            else if (currentLotFilterTab === 'rejected') emptyMsg = 'Tidak ada lot yang ditolak (Zero Defect).';

            cardsContainer.innerHTML = '<div class="text-center py-8 text-slate-400 text-xs font-semibold">' + emptyMsg + '</div>';
        } else {
            var cardsHtml = '';
            filteredLots.forEach(function(lot) {
                cardsHtml += generateSingleLotCardHtml(lot, ngRecordsByLot, s, false);
            });
            cardsContainer.innerHTML = cardsHtml;
        }
    }

    // 4. Update Card 4: Finished Session Banner
    var finishedBanner = document.getElementById('session-finished-banner-v2');
    if (finishedBanner) {
        if (s.status !== 'in_progress') {
            finishedBanner.classList.remove('hidden');
            finishedBanner.style.display = 'block';

            var v2Badge = document.getElementById('finished-v2-status-badge');
            if (v2Badge) {
                if (s.status === 'passed') {
                    v2Badge.textContent = isSessReinspect ? 'STATUS: RE-INSPEKSI SEMUA LOT PASSED' : 'STATUS: SEMUA LOT PASSED';
                    v2Badge.className = 'text-[11px] font-black uppercase text-white px-3 py-1 rounded-full inline-block shadow-2xs bg-emerald-600';
                } else if (s.status === 'rejected') {
                    v2Badge.textContent = isSessReinspect ? 'STATUS: RE-INSPEKSI DITEMUKAN LOT REJECTED' : 'STATUS: DITEMUKAN LOT REJECTED';
                    v2Badge.className = 'text-[11px] font-black uppercase text-white px-3 py-1 rounded-full inline-block shadow-2xs bg-rose-600';
                } else {
                    v2Badge.textContent = 'STATUS: ' + s.status.toUpperCase();
                    v2Badge.className = 'text-[11px] font-black uppercase text-white px-3 py-1 rounded-full inline-block shadow-2xs bg-slate-600';
                }
            }

            var nextActionsWrap = document.getElementById('v2-finished-next-actions');
            if (nextActionsWrap) {
                if (window.OQC_CONFIG && window.OQC_CONFIG.isReadOnlyView) {
                    nextActionsWrap.classList.add('hidden');
                    nextActionsWrap.style.display = 'none';
                    nextActionsWrap.innerHTML = '';
                } else if (s.status === 'passed') {
                    var isKb = (s.inspection_type || 'kanban') === 'kanban';
                    var totSc = parseInt(s.total_scanned_qty || 0);
                    var kbTg = parseInt(s.kanban_qty || 0);
                    var prevKb = parseInt(data.prev_kanban_qty || s.prev_kanban_qty || 0);
                    var accum = parseInt(data.accumulated_kanban_qty || (totSc + prevKb));
                    var isPartial = isKb && (kbTg > 0) && (accum < kbTg);

                    if (isPartial) {
                        var sisa = kbTg - accum;
                        nextActionsWrap.innerHTML = 
                            '<div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:10px; padding:6px 10px; font-size:10px; color:#1e40af; text-align:left;">' +
                                '<div style="display:flex; justify-content:space-between; align-items:center;">' +
                                    '<span><b>Target Kanban:</b> ' + accum.toLocaleString() + ' / ' + kbTg.toLocaleString() + ' pcs</span>' +
                                    '<span style="background:#fef3c7; color:#92400e; border:1px solid #fde68a; font-size:9px; font-weight:800; padding:1px 5px; border-radius:4px;">KURANG ' + sisa.toLocaleString() + ' PCS</span>' +
                                '</div>' +
                            '</div>' +
                            '<div style="display:flex; gap:6px;">' +
                                '<button type="button" onclick="openAddLotModal()" style="flex:1; padding:7px 10px; background:#2563eb; color:#ffffff; font-size:11px; font-weight:800; border:none; border-radius:8px; cursor:pointer; box-shadow:0 2px 4px rgba(37,99,235,0.25);" title="Tambah / Scan box berikutnya untuk Kanban ini">' +
                                    '📦 Lanjut Kanban Ini' +
                                '</button>' +
                                '<button type="button" onclick="openScanModal()" style="flex:1; padding:7px 10px; background:#475569; color:#ffffff; font-size:11px; font-weight:800; border:none; border-radius:8px; cursor:pointer; box-shadow:0 2px 4px rgba(71,85,105,0.25);" title="Pilih Kanban atau Safety Stock lain">' +
                                    '📋 Ganti Kanban Lain' +
                                '</button>' +
                            '</div>';
                        nextActionsWrap.classList.remove('hidden');
                        nextActionsWrap.style.display = 'block';
                    } else {
                        nextActionsWrap.innerHTML = 
                            '<button type="button" onclick="openScanModal()" style="width:100%; padding:8px 12px; background:linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color:#ffffff; font-size:11px; font-weight:800; border:none; border-radius:10px; cursor:pointer; box-shadow:0 2px 5px rgba(37,99,235,0.28); display:flex; align-items:center; justify-content:center; gap:6px;">' +
                                '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 4v16m8-8H4"/></svg>' +
                                '<span>Scan / Pilih Planning Berikutnya</span>' +
                            '</button>';
                        nextActionsWrap.classList.remove('hidden');
                        nextActionsWrap.style.display = 'block';
                    }
                } else {
                    nextActionsWrap.classList.add('hidden');
                    nextActionsWrap.style.display = 'none';
                    nextActionsWrap.innerHTML = '';
                }
            }

            var chiefWrap = document.getElementById('v2-chief-acc-wrap');
            if (s.status === 'rejected') {
                if (chiefWrap) {
                    chiefWrap.classList.remove('hidden');
                    chiefWrap.style.display = 'flex';
                }
                var rejectedLots = lots.filter(function(l) {
                    return l.lot_result === 'rejected' || l.lot_status === 'replaced';
                });
                var totalRej = rejectedLots.length;
                var approvedCount = rejectedLots.filter(function(l) {
                    return parseInt(l.is_chief_approved || 0) === 1;
                }).length;
                var unapprovedCount = totalRej - approvedCount;

                var chiefStatusTxt = document.getElementById('v2-chief-status-text');
                var chiefBtnContainer = document.getElementById('v2-chief-btn-container');

                if (chiefStatusTxt) {
                    if (totalRej === 0) {
                        chiefStatusTxt.innerHTML = '<span class="text-slate-500 font-semibold">Tidak ada lembar penolakan aktif</span>';
                    } else if (unapprovedCount === 0) {
                        chiefStatusTxt.innerHTML = '<span class="text-emerald-700 font-extrabold flex items-center gap-1.5"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>Semua lembar penolakan (' + totalRej + ' box) telah di-ACC Chief QC</span>';
                    } else {
                        chiefStatusTxt.innerHTML = '<span class="text-amber-800 font-extrabold flex items-center gap-1.5"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>' + unapprovedCount + ' dari ' + totalRej + ' box reject belum di-ACC Chief (Otorisasi via lembar Rejection)</span>';
                    }
                }
                if (chiefBtnContainer) {
                    chiefBtnContainer.innerHTML = '';
                }
            } else {
                if (chiefWrap) {
                    chiefWrap.classList.add('hidden');
                    chiefWrap.style.display = 'none';
                }
            }
        } else {
            finishedBanner.classList.add('hidden');
            finishedBanner.style.display = 'none';
        }
    }
}

