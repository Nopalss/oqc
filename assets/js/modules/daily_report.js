/**
 * OQC Daily Report - Client Logic & Engine
 * PT. Surya Technology Industri — Outgoing Quality Control Division
 */

function getAppBaseUrl() {
    return (window.DAILY_REPORT_CONFIG && window.DAILY_REPORT_CONFIG.baseUrl) ? window.DAILY_REPORT_CONFIG.baseUrl : '';
}
function getCsrfToken() {
    return (window.DAILY_REPORT_CONFIG && window.DAILY_REPORT_CONFIG.csrfToken) ? window.DAILY_REPORT_CONFIG.csrfToken : '';
}
    // Tab Switcher Handler
    function switchReportTab(tabKey) {
        const paneSession   = document.getElementById('pane-tab-session');
        const paneDaily     = document.getElementById('pane-tab-daily');
        const paneInspector = document.getElementById('pane-tab-inspector');

        const btnSession    = document.getElementById('tab-btn-session');
        const btnDaily      = document.getElementById('tab-btn-daily');
        const btnInspector  = document.getElementById('tab-btn-inspector');

        const inputTab      = document.getElementById('inputTab');
        const descText      = document.getElementById('tab-desc-text');

        inputTab.value = tabKey;

        // Reset all buttons style
        const activeClass   = 'bg-blue-600 text-white shadow-xs';
        const inactiveClass = 'text-slate-600 hover:text-slate-900 hover:bg-slate-200/70';

        if (btnSession) btnSession.className = inactiveClass;
        if (btnDaily) btnDaily.className = inactiveClass;
        if (btnInspector) btnInspector.className = inactiveClass;

        if (paneSession) paneSession.style.display = 'none';
        if (paneDaily) paneDaily.style.display = 'none';
        if (paneInspector) paneInspector.style.display = 'none';

        const badgeSession   = document.getElementById('tab-badge-session');
        const badgeDaily     = document.getElementById('tab-badge-daily');
        const badgeInspector = document.getElementById('tab-badge-inspector');

        // Reset badge styles to inactive state
        const resetBadge = function(badge) {
            if (!badge) return;
            badge.style.background = '#e2e8f0';
            badge.style.color = '#334155';
        };
        const activateBadge = function(badge) {
            if (!badge) return;
            badge.style.background = 'rgba(255,255,255,0.25)';
            badge.style.color = '#ffffff';
        };

        resetBadge(badgeSession);
        resetBadge(badgeDaily);
        resetBadge(badgeInspector);

        if (tabKey === 'session') {
            if (paneSession) paneSession.style.display = 'block';
            if (btnSession) btnSession.className = activeClass;
            activateBadge(badgeSession);
            if (descText) descText.textContent = 'Menampilkan kronologi seluruh sesi inspeksi secara langsung';
        } else if (tabKey === 'daily') {
            if (paneDaily) paneDaily.style.display = 'block';
            if (btnDaily) btnDaily.className = activeClass;
            activateBadge(badgeDaily);
            if (descText) descText.textContent = 'Menampilkan rekapitulasi performa inspeksi dikelompokkan per hari';
        } else if (tabKey === 'inspector') {
            if (paneInspector) paneInspector.style.display = 'block';
            if (btnInspector) btnInspector.className = activeClass;
            activateBadge(badgeInspector);
            if (descText) descText.textContent = 'Daftar aktivitas dan performa kerja per inspektur QC (klik untuk melihat rincian per lot)';
        }

        // Update URL state without full page reload
        try {
            const url = new URL(window.location);
            url.searchParams.set('tab', tabKey);
            window.history.replaceState({}, '', url);
        } catch (e) {}
    }

    // Cache lokal data lot per inspektur agar tidak request berulang jika data belum berubah
    var InspectorLotsCache = {};

    function toggleInspectorAccordion(inspIdSafe, inspName) {
        const acc = document.getElementById('acc-insp-' + inspIdSafe);
        const caret = document.querySelector('.caret-insp-' + inspIdSafe);
        if (!acc) return;

        const isHidden = acc.classList.contains('hidden');
        if (isHidden) {
            acc.classList.remove('hidden');
            if (caret) caret.style.transform = 'rotate(180deg)';
            // Jika belum pernah dimuat, load data secara on-demand (Anti-Lag)
            if (!InspectorLotsCache[inspIdSafe]) {
                loadInspectorLots(inspIdSafe, inspName, 1, '');
            }
        } else {
            acc.classList.add('hidden');
            if (caret) caret.style.transform = 'rotate(0deg)';
        }
    }

    // ── Helper: Parser QR Code Scanner Label Pabrik ─────────────────────────
    function parseDailyReportQR(raw) {
        var obj = {};
        if (!raw) return obj;
        var parts = raw.split('|');
        parts.forEach(function(p) {
            p = p.trim();
            if (p.startsWith('Z1')) obj.Z1 = p.substring(2);
            if (p.startsWith('Z2')) obj.Z2 = p.substring(2);
            if (p.startsWith('Z3')) {
                var valZ3 = parseInt(p.substring(2), 10);
                if (!isNaN(valZ3) && valZ3 > 0) obj.Z3 = valZ3;
            }
            if (p.startsWith('Z4')) obj.Z4 = p.substring(2);
            if (p.startsWith('Z5')) obj.Z5 = p.substring(2);
        });
        return obj;
    }

    // Handler untuk Scanner Barcode pada Kotak Pencarian Utama
    function handleMainSearchScan(e) {
        if (e.key === 'Enter') {
            var input = document.getElementById('mainSearchInput');
            if (!input) return;
            var val = input.value.trim();
            if (val.includes('|') || val.startsWith('Z1') || val.startsWith('Z2') || val.startsWith('Z5')) {
                var qr = parseDailyReportQR(val);
                // Utamakan Ref No (Z5), jika tidak ada pakai Lot No (Z2), atau Part Code (Z1)
                if (qr.Z5) {
                    input.value = qr.Z5;
                } else if (qr.Z2) {
                    input.value = qr.Z2;
                } else if (qr.Z1) {
                    input.value = qr.Z1;
                }
            }
            // Biarkan browser men-submit formDailyFilter secara natural
        }
    }

    function searchInspectorLots(inspIdSafe, inspName) {
        const searchInput = document.getElementById('search-insp-' + inspIdSafe);
        let query = searchInput ? searchInput.value.trim() : '';
        if (query.includes('|') || query.startsWith('Z1') || query.startsWith('Z2') || query.startsWith('Z5')) {
            var qr = parseDailyReportQR(query);
            if (qr.Z5) {
                query = qr.Z5;
            } else if (qr.Z2) {
                query = qr.Z2;
            } else if (qr.Z1) {
                query = qr.Z1;
            }
            if (searchInput) searchInput.value = query;
        }
        loadInspectorLots(inspIdSafe, inspName, 1, query);
    }

    function loadInspectorLots(inspIdSafe, inspName, page, search) {
        const tbody = document.getElementById('tbody-insp-' + inspIdSafe);
        const pagi = document.getElementById('pagi-insp-' + inspIdSafe);
        const countSpan = document.getElementById('info-count-' + inspIdSafe);
        if (!tbody) return;

        page = page || 1;
        search = search || '';

        // Tampilkan indikator loading halus
        tbody.innerHTML = '<tr><td colspan="14" style="padding: 24px; text-align: center; color: #64748b; font-weight: 600;">' +
            '<div style="display: flex; align-items: center; justify-content: center; gap: 8px;">' +
            '<svg style="width: 16px; height: 16px; animation: spin 1s linear infinite; color: #2563eb;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke-width="3" stroke="currentColor" stroke-dasharray="31.4 31.4" fill="none"/></svg>' +
            '<span>Mengambil data rincian lot...</span>' +
            '</div></td></tr>';

        const sDate = (window.DAILY_REPORT_CONFIG ? window.DAILY_REPORT_CONFIG.todayDate : "");
        const eDate = (window.DAILY_REPORT_CONFIG ? window.DAILY_REPORT_CONFIG.todayDate : "");

        const url = getAppBaseUrl() + '/modules/daily_report/api/get_inspector_lots.php' +
            '?inspector_name=' + encodeURIComponent(inspName) +
            '&start_date=' + encodeURIComponent(sDate) +
            '&end_date=' + encodeURIComponent(eDate) +
            '&page=' + encodeURIComponent(page) +
            '&limit=50' +
            '&search=' + encodeURIComponent(search);

        fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (!data || !data.success) {
                tbody.innerHTML = '<tr><td colspan="14" style="padding: 20px; text-align: center; color: #dc2626; font-weight: 700;">Gagal memuat rincian lot.</td></tr>';
                return;
            }

            tbody.innerHTML = data.html;
            InspectorLotsCache[inspIdSafe] = true;

            if (countSpan && data.total_lots !== undefined) {
                countSpan.textContent = 'Ditemukan: ' + Number(data.total_lots).toLocaleString('id-ID') + ' Lot';
            }

            // Render Pagination Footer Bar
            if (pagi) {
                if (data.total_pages <= 1) {
                    pagi.innerHTML = '<span style="color: #64748b;">Menampilkan seluruh (' + data.total_lots + ') lot</span>' +
                                     '<span style="color: #94a3b8; font-weight: 600;">Halaman 1 dari 1</span>';
                } else {
                    var safeNameStr = inspName.replace(/'/g, "\\'");
                    var prevDisabled = (data.page <= 1) ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : 'onclick="loadInspectorLots(\'' + inspIdSafe + '\', \'' + safeNameStr + '\', ' + (data.page - 1) + ', \'' + search.replace(/'/g, "\\'") + '\')"';
                    var nextDisabled = (data.page >= data.total_pages) ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : 'onclick="loadInspectorLots(\'' + inspIdSafe + '\', \'' + safeNameStr + '\', ' + (data.page + 1) + ', \'' + search.replace(/'/g, "\\'") + '\')"';

                    pagi.innerHTML = '<div style="color: #475569; font-weight: 600;">' +
                        'Halaman <b>' + data.page + '</b> dari <b>' + data.total_pages + '</b> (Total ' + Number(data.total_lots).toLocaleString('id-ID') + ' Lot)' +
                        '</div>' +
                        '<div style="display: flex; align-items: center; gap: 6px;">' +
                        '<button type="button" ' + prevDisabled + ' class="excel-quick-btn" style="padding: 4px 10px;">&larr; Sebelumnya</button>' +
                        '<button type="button" ' + nextDisabled + ' class="excel-quick-btn" style="padding: 4px 10px;">Berikutnya &rarr;</button>' +
                        '</div>';
                }
            }
        })
        .catch(function(err) {
            tbody.innerHTML = '<tr><td colspan="14" style="padding: 20px; text-align: center; color: #dc2626; font-weight: 700;">Gangguan jaringan saat memuat data lot.</td></tr>';
        });
    }

    // Filter Approved Sessions (Hide/Show Sesi yang Sudah di-ACC via URL / Server-Side)
    function onToggleShowApprovedChange(isChecked) {
        try {
            localStorage.setItem('oqc_show_approved_sessions', isChecked ? '1' : '0');
        } catch (e) {}

        const url = new URL(window.location.href);
        url.searchParams.set('show_approved', isChecked ? '1' : '0');
        url.searchParams.set('session_page', '1'); // Reset ke halaman 1 agar selalu relevan
        url.searchParams.set('tab', 'session');
        window.location.href = url.toString();
    }

    function filterApprovedSessions(showApproved) {
        const approvedRows = document.querySelectorAll('.row-approved-session');
        approvedRows.forEach(function(row) {
            row.style.display = showApproved ? '' : 'none';
        });
    }

    // Accordion Toggle Handler
    // Cache lokal detail lot per sesi (On-Demand Lazy Load - Zero Lag)
    var SessionLotsCache = {};

    // Accordion Toggle Handler with On-Demand AJAX Loading
    function toggleAccordion(rowId) {
        const subRow = document.getElementById(rowId);
        if (!subRow) return;

        const sessionId = subRow.getAttribute('data-session-id') || rowId.replace('acc-timeline-', '');
        const contentBox = document.getElementById('acc-content-' + sessionId);
        const parentRow = subRow.previousElementSibling;
        const caret = parentRow ? parentRow.querySelector('.caret-icon') : null;
        const btnText = parentRow ? parentRow.querySelector('.btn-text') : null;

        const isHidden = subRow.classList.contains('hidden');
        if (isHidden) {
            subRow.classList.remove('hidden');
            if (caret) caret.style.transform = 'rotate(180deg)';
            if (btnText) btnText.textContent = 'Tutup';

            // Muat detail lot via AJAX jika belum pernah dimuat
            if (!SessionLotsCache[sessionId] && contentBox) {
                contentBox.innerHTML = '<div style="padding: 18px; text-align: center; color: #64748b; font-weight: 600;">' +
                    '<div style="display: flex; align-items: center; justify-content: center; gap: 8px;">' +
                    '<svg style="width: 16px; height: 16px; animation: spin 1s linear infinite; color: #2563eb;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke-width="3" stroke="currentColor" stroke-dasharray="31.4 31.4" fill="none"/></svg>' +
                    '<span>Memuat rincian box & temuan cacat sesi #' + sessionId + '...</span>' +
                    '</div></div>';

                const baseUrl = (window.DAILY_REPORT_CONFIG ? window.DAILY_REPORT_CONFIG.baseUrl : '');
                fetch(baseUrl + '/modules/daily_report/api/get_session_lots.php?session_id=' + encodeURIComponent(sessionId), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (data && data.success && data.html) {
                        contentBox.innerHTML = data.html;
                        SessionLotsCache[sessionId] = true;
                    } else {
                        contentBox.innerHTML = '<div style="padding: 14px; text-align: center; color: #dc2626; font-weight: 700;">' +
                            ((data && data.message) ? data.message : 'Gagal memuat rincian sesi.') + '</div>';
                    }
                })
                .catch(function(err) {
                    contentBox.innerHTML = '<div style="padding: 14px; text-align: center; color: #dc2626; font-weight: 700;">Gangguan jaringan saat memuat rincian sesi.</div>';
                });
            }
        } else {
            subRow.classList.add('hidden');
            if (caret) caret.style.transform = 'rotate(0deg)';
            if (btnText) btnText.textContent = 'Detail';
        }
    }

    // Preset Date Filters Handler
    function handlePresetClick(presetKey) {
        const inputPreset = document.getElementById('inputPreset');
        const containerSubFilter = document.getElementById('containerSubFilter');
        const boxMonthYear = document.getElementById('boxFilterMonthYear');
        const boxSpecificYear = document.getElementById('boxFilterSpecificYear');
        const inputStartDate = document.getElementById('inputStartDate');
        const inputEndDate = document.getElementById('inputEndDate');

        inputPreset.value = presetKey;

        if (presetKey === 'month_year') {
            containerSubFilter.style.display = 'flex';
            boxMonthYear.style.display = 'flex';
            boxSpecificYear.style.display = 'none';
            return;
        } else if (presetKey === 'specific_year') {
            containerSubFilter.style.display = 'flex';
            boxMonthYear.style.display = 'none';
            boxSpecificYear.style.display = 'flex';
            return;
        } else {
            containerSubFilter.style.display = 'none';
            boxMonthYear.style.display = 'none';
            boxSpecificYear.style.display = 'none';
        }

        // Clear start/end dates so backend calculates based on preset
        if (presetKey !== 'custom') {
            inputStartDate.value = '';
            inputEndDate.value = '';
        }

        document.getElementById('formDailyFilter').submit();
    }

    function applyMonthYearFilter() {
        const m = document.getElementById('subSelectMonth').value;
        const y = document.getElementById('subSelectMonthYear').value;
        document.getElementById('inputMonth').value = m;
        document.getElementById('inputYear').value = y;
        document.getElementById('inputPreset').value = 'month_year';
        document.getElementById('inputStartDate').value = '';
        document.getElementById('inputEndDate').value = '';
        document.getElementById('formDailyFilter').submit();
    }

    function applySpecificYearFilter() {
        const y = document.getElementById('subSelectYear').value;
        document.getElementById('inputYear').value = y;
        document.getElementById('inputPreset').value = 'specific_year';
        document.getElementById('inputStartDate').value = '';
        document.getElementById('inputEndDate').value = '';
        document.getElementById('formDailyFilter').submit();
    }

    // ═══════════════════════════════════════════════════════════════════
    // SUPERVISOR APPROVAL (ACC) HANDLERS
    // ═══════════════════════════════════════════════════════════════════
    function submitApproval(payload) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: 'Memproses Persetujuan...',
                text: 'Mohon tunggu, sistem sedang memperbarui basis data.',
                allowOutsideClick: false,
                didOpen: function() {
                    Swal.showLoading();
                }
            });
        }

        var formData = new FormData();
        for (var key in payload) {
            formData.append(key, payload[key]);
        }
        formData.append('ajax', '1');
        formData.append('csrf_token', (window.DAILY_REPORT_CONFIG ? window.DAILY_REPORT_CONFIG.csrfToken : ""));

        fetch(getAppBaseUrl() + '/modules/daily_report/approve.php', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': (window.DAILY_REPORT_CONFIG ? window.DAILY_REPORT_CONFIG.csrfToken : "")
            },
            body: formData
        })
        .then(function(res) {
            return res.json();
        })
        .then(function(res) {
            if (res.success) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Berhasil',
                        text: res.message,
                        confirmButtonColor: '#2563eb'
                    }).then(function() {
                        window.location.reload();
                    });
                } else {
                    alert(res.message);
                    window.location.reload();
                }
            } else {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'error',
                        title: 'Gagal',
                        text: res.message || 'Terjadi kesalahan sistem saat memperbarui status persetujuan.',
                        confirmButtonColor: '#2563eb'
                    });
                } else {
                    alert(res.message || 'Gagal memproses persetujuan.');
                }
            }
        })
        .catch(function(err) {
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'error',
                    title: 'Gangguan Jaringan',
                    text: 'Tidak dapat menghubungi server. Silakan coba beberapa saat lagi.',
                    confirmButtonColor: '#2563eb'
                });
            } else {
                alert('Tidak dapat menghubungi server.');
            }
        });
    }

    function confirmApproveSingle(sessionId) {
        if (typeof Swal === 'undefined') {
            if (confirm('Apakah Anda yakin ingin menyetujui (ACC) Sesi #' + sessionId + '?')) {
                submitApproval({
                    action: 'approve_single',
                    session_id: sessionId,
                    notes: 'Disetujui oleh supervisor',
                    date: (window.DAILY_REPORT_CONFIG ? window.DAILY_REPORT_CONFIG.startDate : "")
                });
            }
            return;
        }

        Swal.fire({
            title: 'Persetujuan Sesi Inspeksi',
            text: 'Apakah Anda yakin ingin memberikan persetujuan (ACC) atas hasil pemeriksaan Sesi #' + sessionId + '?',
            icon: 'question',
            input: 'text',
            inputLabel: 'Catatan Persetujuan (Opsional)',
            inputPlaceholder: 'Tuliskan catatan supervisor bila diperlukan...',
            showCancelButton: true,
            confirmButtonColor: '#2563eb',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Ya, Setujui Sesi',
            cancelButtonText: 'Batal'
        }).then(function(result) {
            if (result.isConfirmed) {
                submitApproval({
                    action: 'approve_single',
                    session_id: sessionId,
                    notes: result.value || 'Disetujui oleh supervisor',
                    date: (window.DAILY_REPORT_CONFIG ? window.DAILY_REPORT_CONFIG.startDate : "")
                });
            }
        });
    }

    function confirmUnapprove(sessionId) {
        if (typeof Swal === 'undefined') {
            if (confirm('Apakah Anda yakin ingin membatalkan persetujuan atas Sesi #' + sessionId + '?')) {
                submitApproval({
                    action: 'unapprove_single',
                    session_id: sessionId,
                    date: (window.DAILY_REPORT_CONFIG ? window.DAILY_REPORT_CONFIG.startDate : "")
                });
            }
            return;
        }

        Swal.fire({
            title: 'Batalkan Persetujuan',
            text: 'Apakah Anda yakin ingin membatalkan persetujuan atas Sesi #' + sessionId + '? Status akan kembali menjadi belum di-ACC.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Ya, Batalkan Persetujuan',
            cancelButtonText: 'Kembali'
        }).then(function(result) {
            if (result.isConfirmed) {
                submitApproval({
                    action: 'unapprove_single',
                    session_id: sessionId,
                    date: (window.DAILY_REPORT_CONFIG ? window.DAILY_REPORT_CONFIG.startDate : "")
                });
            }
        });
    }

    function confirmApproveAllVisible() {
        const pendingRows = document.querySelectorAll('.row-pending-session');
        const pendingCount = pendingRows.length;

        if (pendingCount === 0) {
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'info',
                    title: 'Tidak Ada Sesi Pending',
                    text: 'Seluruh sesi pada filter saat ini telah disetujui (ACC).'
                });
            } else {
                alert('Tidak ada sesi yang memerlukan persetujuan.');
            }
            return;
        }

        // Kumpulkan session_id dari seluruh baris yang masih pending
        const pendingIds = [];
        pendingRows.forEach(function(r) {
            const sid = r.getAttribute('data-session-id');
            if (sid) pendingIds.push(sid);
        });

        const filterContext = (window.DAILY_REPORT_CONFIG ? window.DAILY_REPORT_CONFIG.filterContext : "");

        if (typeof Swal === 'undefined') {
            if (confirm('Apakah Anda yakin ingin menyetujui seluruh (' + pendingCount + ') sesi inspeksi yang tampil?')) {
                submitApproval({
                    action: 'approve_all_filtered',
                    session_ids: pendingIds.join(','),
                    start_date: (window.DAILY_REPORT_CONFIG ? window.DAILY_REPORT_CONFIG.startDate : ""),
                    end_date: (window.DAILY_REPORT_CONFIG ? window.DAILY_REPORT_CONFIG.endDate : ""),
                    inspector_id: (window.DAILY_REPORT_CONFIG ? window.DAILY_REPORT_CONFIG.inspectorId : 0),
                    notes: 'Persetujuan massal oleh supervisor'
                });
            }
            return;
        }

        Swal.fire({
            title: 'Setujui Semua Sesi (ACC All)',
            html: 'Anda akan memberikan persetujuan (ACC) sekaligus untuk <b>' + pendingCount + ' sesi inspeksi</b> yang belum di-ACC' + (filterContext ? ' pada periode <b>' + filterContext + '</b>' : '') + '.<br><br><span style="font-size: 12px; color: #64748b;">Pastikan seluruh hasil pemeriksaan dan temuan NG telah ditinjau dengan seksama.</span>',
            icon: 'question',
            input: 'text',
            inputLabel: 'Catatan Persetujuan Massal (Opsional)',
            inputPlaceholder: 'Tuliskan catatan supervisor bila diperlukan...',
            showCancelButton: true,
            confirmButtonColor: '#16a34a',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Ya, Setujui Semua (' + pendingCount + ' Sesi)',
            cancelButtonText: 'Batal'
        }).then(function(result) {
            if (result.isConfirmed) {
                submitApproval({
                    action: 'approve_all_filtered',
                    session_ids: pendingIds.join(','),
                    start_date: (window.DAILY_REPORT_CONFIG ? window.DAILY_REPORT_CONFIG.startDate : ""),
                    end_date: (window.DAILY_REPORT_CONFIG ? window.DAILY_REPORT_CONFIG.endDate : ""),
                    inspector_id: (window.DAILY_REPORT_CONFIG ? window.DAILY_REPORT_CONFIG.inspectorId : 0),
                    notes: result.value || 'Persetujuan massal oleh supervisor'
                });
            }
        });
    }

    // ═══════════════════════════════════════════════════════════════════
    // BULK CHECKBOX APPROVAL HANDLERS (FLOATING BOTTOM BAR)
    // ═══════════════════════════════════════════════════════════════════
    function onSessionCheckChange() {
        const checkboxes = document.querySelectorAll('.bulk-session-cb');
        const checkedBoxes = document.querySelectorAll('.bulk-session-cb:checked');
        const count = checkedBoxes.length;

        let totalQty = 0;
        checkedBoxes.forEach(function(cb) {
            totalQty += parseInt(cb.getAttribute('data-qty') || 0, 10);
        });

        const actionBar = document.getElementById('bulk-session-action-bar');
        const countSpan = document.getElementById('bulk-session-selected-count');
        const qtySpan   = document.getElementById('bulk-session-selected-qty');
        const checkAll  = document.getElementById('checkAllSessions');

        if (countSpan) countSpan.textContent = count;
        if (qtySpan) qtySpan.textContent = totalQty.toLocaleString('id-ID') + ' pcs';

        if (actionBar) {
            actionBar.style.display = (count > 0) ? 'inline-flex' : 'none';
        }

        if (checkAll) {
            // Cek hanya checkbox yang sedang tampak (visible)
            const visibleCheckboxes = Array.from(checkboxes).filter(function(cb) {
                const tr = cb.closest('tr.session-data-row');
                return tr && tr.style.display !== 'none';
            });
            const visibleChecked = visibleCheckboxes.filter(function(cb) { return cb.checked; });
            
            checkAll.checked = (visibleCheckboxes.length > 0 && visibleChecked.length === visibleCheckboxes.length);
            checkAll.indeterminate = (visibleChecked.length > 0 && visibleChecked.length < visibleCheckboxes.length);
        }
    }

    function clearBulkSessionSelection() {
        const checkboxes = document.querySelectorAll('.bulk-session-cb');
        checkboxes.forEach(function(cb) {
            cb.checked = false;
        });
        const checkAll = document.getElementById('checkAllSessions');
        if (checkAll) {
            checkAll.checked = false;
            checkAll.indeterminate = false;
        }
        onSessionCheckChange();
    }

    function toggleSelectAllSessionRows(checkState) {
        const checkboxes = document.querySelectorAll('.bulk-session-cb');
        checkboxes.forEach(function(cb) {
            const tr = cb.closest('tr.session-data-row');
            // Hanya centang baris yang saat ini terlihat (lolos filter)
            if (tr && tr.style.display !== 'none') {
                cb.checked = checkState;
            }
        });
        onSessionCheckChange();
    }

    function confirmApproveSelected() {
        const checkedBoxes = document.querySelectorAll('.bulk-session-cb:checked');
        const selectedIds = [];
        let totalQty = 0;
        checkedBoxes.forEach(function(cb) {
            if (cb.value) {
                selectedIds.push(cb.value);
                totalQty += parseInt(cb.getAttribute('data-qty') || 0, 10);
            }
        });

        if (selectedIds.length === 0) {
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'info',
                    title: 'Pilih Sesi Terlebih Dahulu',
                    text: 'Silakan beri centang pada kotak ceklis sesi yang ingin disetujui (ACC).'
                });
            } else {
                alert('Pilih sesi terlebih dahulu.');
            }
            return;
        }

        const count = selectedIds.length;

        if (typeof Swal === 'undefined') {
            if (confirm('Apakah Anda yakin ingin menyetujui (' + count + ') sesi inspeksi terpilih (Total Qty: ' + totalQty + ' pcs)?')) {
                submitApproval({
                    action: 'approve_all_filtered',
                    session_ids: selectedIds.join(','),
                    start_date: (window.DAILY_REPORT_CONFIG ? window.DAILY_REPORT_CONFIG.startDate : ""),
                    end_date: (window.DAILY_REPORT_CONFIG ? window.DAILY_REPORT_CONFIG.endDate : ""),
                    notes: 'Disetujui secara bersamaan oleh supervisor'
                });
            }
            return;
        }

        Swal.fire({
            title: 'Setujui Sesi Terpilih (ACC Bulk)',
            html: 'Anda akan memberikan persetujuan (ACC) sekaligus untuk <b>' + count + ' sesi inspeksi</b> yang Anda centang.<br>' +
                  '<div style="margin-top: 8px; font-size: 13px; font-weight: 700; color: #1e40af; background: #eff6ff; padding: 6px 12px; border-radius: 8px; border: 1px solid #bfdbfe;">Total Barang: ' + totalQty.toLocaleString('id-ID') + ' pcs</div>' +
                  '<br><span style="font-size: 12px; color: #64748b;">Pastikan seluruh hasil pemeriksaan dan lot dari sesi terpilih telah Anda tinjau.</span>',
            icon: 'question',
            input: 'text',
            inputLabel: 'Catatan Persetujuan (Opsional)',
            inputPlaceholder: 'Tuliskan catatan supervisor bila diperlukan...',
            showCancelButton: true,
            confirmButtonColor: '#059669',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Ya, Setujui (' + count + ' Sesi)',
            cancelButtonText: 'Batal'
        }).then(function(result) {
            if (result.isConfirmed) {
                submitApproval({
                    action: 'approve_all_filtered',
                    session_ids: selectedIds.join(','),
                    start_date: (window.DAILY_REPORT_CONFIG ? window.DAILY_REPORT_CONFIG.startDate : ""),
                    end_date: (window.DAILY_REPORT_CONFIG ? window.DAILY_REPORT_CONFIG.endDate : ""),
                    notes: result.value || 'Disetujui secara bersamaan oleh supervisor'
                });
            }
        });
    }
    // EXCEL-STYLE COLUMN HEADER FILTERS ENGINE (DAILY REPORT SESSION TIMELINE)
    // ══════════════════════════════════════════════════════════════════════════
    var ExcelFilterState = {
        activeCol: null,
        filters: {
            date: new Set(),
            jalur: new Set(),
            item: new Set(),
            inspector: new Set(),
            status: new Set(),
            approval: new Set()
        },
        options: {
            date: [],
            jalur: [],
            item: [],
            inspector: [],
            status: [],
            approval: []
        }
    };

    function initExcelFilters() {
        var rows = document.querySelectorAll('tr.session-data-row');
        if (!rows || rows.length === 0) return;

        var sets = {
            date: new Set(),
            jalur: new Set(),
            item: new Set(),
            inspector: new Set(),
            status: new Set(),
            approval: new Set()
        };

        rows.forEach(function(r) {
            var d = (r.getAttribute('data-filter-date') || '').trim();
            var j = (r.getAttribute('data-filter-jalur') || '').trim();
            var it = (r.getAttribute('data-filter-item') || '').trim();
            var ins = (r.getAttribute('data-filter-inspector') || '').trim();
            var st = (r.getAttribute('data-filter-status') || '').trim();
            var ap = (r.getAttribute('data-filter-approval') || '').trim();

            if (d) sets.date.add(d);
            if (j) sets.jalur.add(j);
            if (it) sets.item.add(it);
            if (ins) sets.inspector.add(ins);
            if (st) sets.status.add(st);
            if (ap) sets.approval.add(ap);
        });

        ['date', 'jalur', 'item', 'inspector', 'status', 'approval'].forEach(function(col) {
            ExcelFilterState.options[col] = Array.from(sets[col]).sort();
            renderFilterCheckboxes(col);
        });
    }

    function renderFilterCheckboxes(col) {
        var container = document.getElementById('filter-list-' + col);
        if (!container) return;

        var options = ExcelFilterState.options[col] || [];
        var activeSet = ExcelFilterState.filters[col];
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

        updateFilterCountInfo(col);
    }

    function updateFilterCountInfo(col) {
        var dropdown = document.getElementById('filter-dropdown-' + col);
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

    function toggleExcelFilter(event, col) {
        event.stopPropagation();
        var dropdown = document.getElementById('filter-dropdown-' + col);
        if (!dropdown) return;

        var isVisible = (dropdown.style.display === 'block');
        closeAllExcelFilters();

        if (!isVisible) {
            dropdown.style.display = 'block';
            ExcelFilterState.activeCol = col;
            var searchInput = dropdown.querySelector('.excel-filter-search');
            if (searchInput) {
                searchInput.value = '';
                onSearchFilterOptions(col, '');
                setTimeout(function() { searchInput.focus(); }, 50);
            }
        }
    }

    function closeAllExcelFilters() {
        document.querySelectorAll('.excel-filter-dropdown').forEach(function(dd) {
            dd.style.display = 'none';
        });
        ExcelFilterState.activeCol = null;
    }

    function onSearchFilterOptions(col, keyword) {
        var container = document.getElementById('filter-list-' + col);
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

    function toggleSelectAllFilter(col, selectAll) {
        var container = document.getElementById('filter-list-' + col);
        if (!container) return;
        var checkboxes = container.querySelectorAll('input[type="checkbox"]');
        checkboxes.forEach(function(cb) {
            if (cb.closest('.excel-filter-item').style.display !== 'none') {
                cb.checked = selectAll;
            }
        });
        updateFilterCountInfo(col);
    }

    function applyExcelFilters() {
        var cols = ['date', 'jalur', 'item', 'inspector', 'status', 'approval'];

        cols.forEach(function(col) {
            var container = document.getElementById('filter-list-' + col);
            if (!container) return;

            var checkboxes = container.querySelectorAll('input[type="checkbox"]');
            var allCount = checkboxes.length;
            var checkedValues = [];

            checkboxes.forEach(function(cb) {
                if (cb.checked) {
                    checkedValues.push(cb.value);
                }
            });

            var btn = document.querySelector('.th-filter-btn[data-col="' + col + '"]');

            if (checkedValues.length === 0 || checkedValues.length === allCount) {
                ExcelFilterState.filters[col].clear();
                if (btn) btn.classList.remove('active');
            } else {
                ExcelFilterState.filters[col] = new Set(checkedValues);
                if (btn) btn.classList.add('active');
            }
        });

        closeAllExcelFilters();
        executeDailyReportFiltering();
    }

    function resetSingleColFilter(col) {
        ExcelFilterState.filters[col].clear();
        var btn = document.querySelector('.th-filter-btn[data-col="' + col + '"]');
        if (btn) btn.classList.remove('active');
        renderFilterCheckboxes(col);
        closeAllExcelFilters();
        executeDailyReportFiltering();
    }

    function resetAllExcelFilters() {
        ['date', 'jalur', 'item', 'inspector', 'status', 'approval'].forEach(function(col) {
            ExcelFilterState.filters[col].clear();
            var btn = document.querySelector('.th-filter-btn[data-col="' + col + '"]');
            if (btn) btn.classList.remove('active');
            renderFilterCheckboxes(col);
        });
        closeAllExcelFilters();
        executeDailyReportFiltering();
    }

    function executeDailyReportFiltering() {
        var rows = document.querySelectorAll('tr.session-data-row');
        if (!rows || rows.length === 0) return;

        var showApprovedToggle = document.getElementById('toggleShowApproved');
        var allowApproved = showApprovedToggle ? showApprovedToggle.checked : true;

        var activeFilterCols = [];
        ['date', 'jalur', 'item', 'inspector', 'status', 'approval'].forEach(function(c) {
            if (ExcelFilterState.filters[c].size > 0) activeFilterCols.push(c);
        });

        var visibleCount = 0;
        var totalRows = rows.length;

        rows.forEach(function(r) {
            var sId = r.getAttribute('data-session-id');
            var subRow = document.getElementById('acc-timeline-' + sId);
            var isApproved = (r.getAttribute('data-approved') === '1');

            // 1. Cek toggle approval
            if (!allowApproved && isApproved) {
                r.style.display = 'none';
                if (subRow) subRow.classList.add('hidden');
                return;
            }

            // 2. Cek Excel Filter per kolom
            var d = (r.getAttribute('data-filter-date') || '').trim();
            var j = (r.getAttribute('data-filter-jalur') || '').trim();
            var it = (r.getAttribute('data-filter-item') || '').trim();
            var ins = (r.getAttribute('data-filter-inspector') || '').trim();
            var st = (r.getAttribute('data-filter-status') || '').trim();
            var ap = (r.getAttribute('data-filter-approval') || '').trim();

            var match = true;
            if (ExcelFilterState.filters.date.size > 0 && !ExcelFilterState.filters.date.has(d)) match = false;
            if (match && ExcelFilterState.filters.jalur.size > 0 && !ExcelFilterState.filters.jalur.has(j)) match = false;
            if (match && ExcelFilterState.filters.item.size > 0 && !ExcelFilterState.filters.item.has(it)) match = false;
            if (match && ExcelFilterState.filters.inspector.size > 0 && !ExcelFilterState.filters.inspector.has(ins)) match = false;
            if (match && ExcelFilterState.filters.status.size > 0 && !ExcelFilterState.filters.status.has(st)) match = false;
            if (match && ExcelFilterState.filters.approval.size > 0 && !ExcelFilterState.filters.approval.has(ap)) match = false;

            if (match) {
                r.style.display = '';
                visibleCount++;
            } else {
                r.style.display = 'none';
                if (subRow) subRow.classList.add('hidden');
            }
        });

        // Update indikator bar filter
        var indicatorBar = document.getElementById('excel-filter-active-bar');
        var summaryText = document.getElementById('excel-filter-summary-text');
        var badgeCount = document.getElementById('badgeTotalSessionCount');

        if (activeFilterCols.length > 0) {
            if (indicatorBar) indicatorBar.style.display = 'flex';
            if (summaryText) {
                summaryText.innerText = 'Menyaring ' + visibleCount + ' dari ' + totalRows + ' sesi (' + activeFilterCols.join(', ') + ')';
            }
            if (badgeCount) {
                badgeCount.innerText = visibleCount + ' Sesi Tersaring (' + totalRows + ' Total)';
            }
        } else {
            if (indicatorBar) indicatorBar.style.display = 'none';
            if (badgeCount) {
                if (!allowApproved) {
                    var pendingCount = document.querySelectorAll('.row-pending-session').length;
                    badgeCount.innerText = pendingCount + ' Sesi Menunggu ACC (' + totalRows + ' Total)';
                } else {
                    badgeCount.innerText = 'Total ' + totalRows + ' Sesi';
                }
            }
        }

        // Sinkronisasi status checkbox dan tombol approve terpilih saat tabel disaring
        if (typeof onSessionCheckChange === 'function') {
            onSessionCheckChange();
        }
    }

    // Inisialisasi Excel Filters saat halaman selesai dimuat
    document.addEventListener('DOMContentLoaded', function() {
        initExcelFilters();

        // Integrasikan dengan toggleShowApproved
        var toggle = document.getElementById('toggleShowApproved');
        if (toggle) {
            toggle.addEventListener('change', function() {
                executeDailyReportFiltering();
            });
        }
    });

    // Event listener: Klik di luar untuk menutup dropdown
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.excel-filter-dropdown') && !e.target.closest('.th-filter-btn')) {
            closeAllExcelFilters();
        }
    });

    // Event listener: Tombol Escape untuk menutup dropdown
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeAllExcelFilters();
        }
    });

    // ═══════════════════════════════════════════════════════════════════
    // REAL-TIME LIVE SESSION MONITOR (ZERO-OVERHEAD POLLING)
    // ═══════════════════════════════════════════════════════════════════
    var LiveSessionMonitor = (function() {
        var pollTimer = null;
        var isPollingActive = false;
        var inFlight = false;
        var pollInterval = 12000; // 12 detik: responsif dan sangat ramah server/multi-PC

        function getLiveSessionIds() {
            var liveRows = document.querySelectorAll('tr.session-data-row.session-row-live');
            var ids = [];
            liveRows.forEach(function(row) {
                var sid = row.getAttribute('data-session-id');
                if (sid && ids.indexOf(sid) === -1) {
                    ids.push(sid);
                }
            });
            return ids;
        }

        function checkLiveStatus() {
            var ids = getLiveSessionIds();
            if (ids.length === 0) {
                stop();
                return;
            }

            if (inFlight) return; // Mencegah tumpukan request jika koneksi lambat
            if (document.hidden) return; // Page Visibility: Jangan polling jika tab sedang di-minimize/background

            inFlight = true;
            var endpoint = getAppBaseUrl() + '/modules/daily_report/api/check_live_status.php?ids=' + encodeURIComponent(ids.join(','));

            var controller = (typeof AbortController !== 'undefined') ? new AbortController() : null;
            var fetchTimeout = null;
            if (controller) {
                fetchTimeout = setTimeout(function() {
                    controller.abort();
                }, 5000); // 5 detik max timeout
            }

            fetch(endpoint, {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                signal: controller ? controller.signal : undefined
            })
            .then(function(res) {
                return res.json();
            })
            .then(function(data) {
                if (!data || !data.success || !data.sessions) return;

                var sessions = data.sessions;
                var anyStatusChanged = false;

                for (var sid in sessions) {
                    var sInfo = sessions[sid];
                    var row = document.querySelector('tr.session-data-row[data-session-id="' + sid + '"]');
                    if (!row) continue;

                    // Update jumlah sampling bila bertambah di DB
                    var sampleSpan = document.querySelector('.session-sample-size-' + sid);
                    if (sampleSpan && sInfo.effective_sample) {
                        sampleSpan.textContent = Number(sInfo.effective_sample).toLocaleString('id-ID') + ' pcs';
                    }

                    // Cek apakah status sudah bukan in_progress lagi (selesai di line)
                    if (!sInfo.is_in_progress && sInfo.status !== 'in_progress') {
                        anyStatusChanged = true;
                        row.classList.remove('session-row-live', 'row-live-session');
                        row.classList.add('row-pending-session');
                        row.setAttribute('data-is-live', '0');
                        row.setAttribute('data-filter-status', sInfo.status.toUpperCase());

                        // 1. Update Badge Status
                        var statusCell = document.querySelector('.session-status-cell-' + sid);
                        if (statusCell) {
                            if (sInfo.status === 'passed') {
                                statusCell.innerHTML = '<span style="display: inline-flex; align-items: center; gap: 3px; background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; font-size: 11px; font-weight: 900; padding: 3px 8px; border-radius: 9999px;">PASS</span>';
                            } else if (sInfo.status === 'rejected') {
                                statusCell.innerHTML = '<span style="display: inline-flex; align-items: center; gap: 3px; background: #ffe4e6; color: #be123c; border: 1px solid #fecdd3; font-size: 11px; font-weight: 900; padding: 3px 8px; border-radius: 9999px;">REJECT</span>';
                            }
                        }

                        // 2. Update Waktu Selesai & Hilangkan Tag LIVE
                        var timeContainer = document.querySelector('.session-time-text-' + sid);
                        if (timeContainer) {
                            timeContainer.innerHTML = '<span>' + (sInfo.time_text || '') + '</span>';
                        }
                    }

                    // ═══════════════════════════════════════════════════════════════
                    // 3. Update Real-Time Pada Kartu Tab Inspektur (#pane-tab-inspector)
                    // ═══════════════════════════════════════════════════════════════
                    if (!sInfo.is_in_progress && sInfo.status !== 'in_progress') {
                        var inspCards = document.querySelectorAll('.inspector-card');
                        inspCards.forEach(function(card) {
                            var liveIdsAttr = card.getAttribute('data-live-session-ids') || '';
                            var arrIds = liveIdsAttr.split(',').filter(function(x) { return x.trim() !== ''; });
                            var idxSid = arrIds.indexOf(String(sid));
                            if (idxSid !== -1) {
                                // Hapus sid ini dari daftar sesi live inspektur
                                arrIds.splice(idxSid, 1);
                                card.setAttribute('data-live-session-ids', arrIds.join(','));

                                var safeId = card.getAttribute('data-inspector-safe');
                                
                                // Update hitungan sesi di teks info inspektur
                                var totalSess = parseInt(card.getAttribute('data-total-sessions') || '0', 10);
                                var passSess = parseInt(card.getAttribute('data-passed-sessions') || '0', 10);
                                var rejSess = parseInt(card.getAttribute('data-rejected-sessions') || '0', 10);
                                var inprogSess = Math.max(0, parseInt(card.getAttribute('data-inprog-sessions') || '0', 10) - 1);
                                if (sInfo.status === 'passed') {
                                    passSess++;
                                } else if (sInfo.status === 'rejected') {
                                    rejSess++;
                                }
                                card.setAttribute('data-passed-sessions', passSess);
                                card.setAttribute('data-rejected-sessions', rejSess);
                                card.setAttribute('data-inprog-sessions', inprogSess);

                                var summaryText = document.getElementById('summary-sess-' + safeId);
                                if (summaryText) {
                                    summaryText.innerHTML = totalSess + ' Sesi Inspeksi &bull; ' + passSess + ' Passed &bull; ' + rejSess + ' Rejected &bull; ' + inprogSess + ' In Progress';
                                }

                                // Jika inspektur ini sudah TIDAK memiliki sesi live lain
                                if (arrIds.length === 0) {
                                    // 1. Ubah badge LIVE menjadi STANDBY / SELESAI
                                    var badgeBox = document.getElementById('badge-container-' + safeId);
                                    if (badgeBox) {
                                        badgeBox.innerHTML = '<span style="font-size: 10px; font-weight: 800; background: #f1f5f9; color: #64748b; border: 1px solid #e2e8f0; padding: 2px 7px; border-radius: 9999px;">STANDBY / SELESAI</span>';
                                    }

                                    // 2. Kembalikan Border Card dari Merah ke Netral
                                    card.classList.remove('border-red-300', 'ring-1', 'ring-red-200');
                                    card.classList.add('border-slate-200/90');

                                    // 3. Kembalikan Header Card Background
                                    var cardHeader = document.getElementById('card-header-' + safeId);
                                    if (cardHeader) {
                                        cardHeader.style.background = '#fafafa';
                                        cardHeader.style.borderBottomColor = '#e2e8f0';
                                    }

                                    // 4. Kembalikan Avatar Warna
                                    var avatarBox = document.getElementById('avatar-' + safeId);
                                    if (avatarBox) {
                                        avatarBox.style.background = '#e2e8f0';
                                        avatarBox.style.color = '#1e293b';
                                        avatarBox.style.borderColor = '#cbd5e1';
                                    }
                                }
                            }
                        });

                        // Periksa apakah masih ada inspektur yang LIVE sama sekali
                        var stillAnyInspLive = false;
                        document.querySelectorAll('.inspector-card').forEach(function(c) {
                            var idsLeft = (c.getAttribute('data-live-session-ids') || '').trim();
                            if (idsLeft !== '') stillAnyInspLive = true;
                        });
                        var tabLiveDot = document.getElementById('tab-inspector-live-dot');
                        if (tabLiveDot) {
                            tabLiveDot.style.display = stillAnyInspLive ? 'inline-block' : 'none';
                        }
                    }
                }

                // Jika ada status yang berubah, cek apakah masih ada sesi live tersisa
                if (anyStatusChanged) {
                    var remaining = getLiveSessionIds();
                    if (remaining.length === 0) {
                        stop();
                    }
                    if (typeof executeDailyReportFiltering === 'function') {
                        executeDailyReportFiltering();
                    }
                }
            })
            .catch(function(err) {
                // Silent fail agar tidak mengganggu UX pengguna saat ada fluktuasi jaringan / timeout
            })
            .finally(function() {
                if (fetchTimeout) clearTimeout(fetchTimeout);
                inFlight = false;
            });
        }

        function start() {
            var ids = getLiveSessionIds();
            if (ids.length === 0) {
                return;
            }
            if (!isPollingActive) {
                isPollingActive = true;
                pollTimer = setInterval(checkLiveStatus, pollInterval);
            }
        }

        function stop() {
            if (pollTimer) {
                clearInterval(pollTimer);
                pollTimer = null;
            }
            isPollingActive = false;
            inFlight = false;
        }

        return {
            init: function() {
                start();
                // Page Visibility API: hemat baterai dan resource saat tab browser tidak aktif
                document.addEventListener('visibilitychange', function() {
                    if (document.hidden) {
                        stop();
                    } else {
                        start();
                    }
                });
            },
            stop: stop
        };
    })();

    // Inisialisasi Real-Time Live Monitor saat DOM siap
    document.addEventListener('DOMContentLoaded', function() {
        LiveSessionMonitor.init();

        // ═══════════════════════════════════════════════════════════════════
        // FLOATING STICKY MINI KPI RIBBON SCROLL TRIGGER
        // ═══════════════════════════════════════════════════════════════════
        var origBanner = document.getElementById('executive-kpi-banner');
        var floatRibbon = document.getElementById('sticky-kpi-floating-ribbon');

        if (origBanner && floatRibbon) {
            var isRibbonVisible = false;

            function updateFloatingRibbon() {
                var rect = origBanner.getBoundingClientRect();
                // Muncul ketika banner utama sudah ter-scroll melewati puncak layar (rect.bottom < 0)
                if (rect.bottom < 0) {
                    if (!isRibbonVisible) {
                        isRibbonVisible = true;
                        floatRibbon.style.display = 'block';
                        // RequestAnimationFrame untuk animasi fade-in & slide-down yang halus
                        requestAnimationFrame(function() {
                            floatRibbon.style.opacity = '1';
                            floatRibbon.style.transform = 'translateY(0)';
                        });
                    }
                } else {
                    if (isRibbonVisible) {
                        isRibbonVisible = false;
                        floatRibbon.style.opacity = '0';
                        floatRibbon.style.transform = 'translateY(-8px)';
                        setTimeout(function() {
                            if (!isRibbonVisible) {
                                floatRibbon.style.display = 'none';
                            }
                        }, 200);
                    }
                }
            }

            window.addEventListener('scroll', updateFloatingRibbon, { passive: true });
            // Initial check on load
            updateFloatingRibbon();
        }
    });
    