<script>
    /**
     * Hard / Force Refresh: Bersihkan cache browser & memori, kirim sinyal force_refresh ke backend
     */
    function executeHardRefresh() {
        var btn = document.getElementById('btn-force-refresh');
        var icon = document.getElementById('refresh-spinner-icon');
        var txt = document.getElementById('refresh-btn-text');

        if (btn) {
            btn.disabled = true;
            btn.classList.add('opacity-75');
        }
        if (icon) {
            icon.classList.add('animate-spin');
        }
        if (txt) {
            txt.innerText = 'Memperbarui...';
        }

        // Susun URL saat ini dengan menyisipkan parameter force_refresh=1 dan timestamp anti-cache
        var currentUrl = new URL(window.location.href);
        currentUrl.searchParams.set('force_refresh', '1');
        currentUrl.searchParams.set('_nocache', Date.now());

        // Navigasikan paksa ke URL baru (bypass disk cache browser)
        window.location.href = currentUrl.toString();
    }

    /**
     * Horizontal Scroll Helper for My Lots Flat Table
     */
    function scrollMyLotsTable(offset) {
        var wrapper = document.getElementById('my-lots-table-wrapper');
        if (wrapper) {
            wrapper.scrollBy({ left: offset, behavior: 'smooth' });
        }
    }

    // Attach horizontal scroll on mousewheel when hovering over table
    document.addEventListener('DOMContentLoaded', function() {
        var wrapper = document.getElementById('my-lots-table-wrapper');
        if (wrapper) {
            wrapper.addEventListener('wheel', function(e) {
                if (e.deltaY !== 0 && wrapper.scrollWidth > wrapper.clientWidth) {
                    var canScrollLeft = wrapper.scrollLeft > 0 && e.deltaY < 0;
                    var canScrollRight = (wrapper.scrollLeft < wrapper.scrollWidth - wrapper.clientWidth - 1) && e.deltaY > 0;
                    if (canScrollLeft || canScrollRight) {
                        e.preventDefault();
                        wrapper.scrollLeft += (e.deltaY * 1.2);
                    }
                }
            }, { passive: false });
        }
    });

    /**
     * Set Preset Period and Submit Filter Form (Kuat & Bersih)
     */
    function setKanbanPeriod(p) {
        var input = document.getElementById('kanbanPeriodInput');
        if (input) input.value = p;

        // Reset rentang custom date
        var s = document.querySelector('input[name="start_date"]');
        var e = document.querySelector('input[name="end_date"]');
        if (s) s.value = '';
        if (e) e.value = '';

        // Reset tanggal gelombang & batch agar tidak terkunci di tanggal lampau
        var selDate = document.getElementById('kanbanSelectedDateInput') || document.querySelector('input[name="selected_date"]');
        if (selDate) selDate.value = '';

        var batchInput = document.getElementById('kanbanBatchIdInput') || document.querySelector('input[name="batch_id"]');
        if (batchInput) batchInput.value = 'all';

        // Reset scope (Wajib: lepaskan pending_yesterday/remain saat ganti periode)
        var scopeInput = document.getElementById('kanbanScopeInput') || document.querySelector('input[name="scope"]');
        if (scopeInput) scopeInput.value = 'today';

        // Reset pagination ke page 1
        var pageInput = document.querySelector('input[name="page"]');
        if (pageInput) pageInput.value = '1';

        var form = document.getElementById('kanbanFilterForm');
        if (form) form.submit();
    }

    /**
     * Smooth Accordion Toggle for My Inspection Session Details
     */
    function toggleMyDetail(sid) {
        var row = document.getElementById('my-detail-' + sid);
        var icon = document.getElementById('my-chevron-' + sid);
        if (!row) return;

        if (row.classList.contains('hidden')) {
            row.classList.remove('hidden');
            if (icon) icon.style.transform = 'rotate(180deg)';
        } else {
            row.classList.add('hidden');
            if (icon) icon.style.transform = 'rotate(0deg)';
        }
    }

    /**
     * Smooth Accordion Toggle for Kanban Session Details
     */
    function toggleKanbanDetail(kid) {
        var row = document.getElementById('kanban-detail-' + kid);
        var icon = document.getElementById('chevron-icon-' + kid);
        if (!row) return;

        if (row.classList.contains('hidden')) {
            row.classList.remove('hidden');
            if (icon) icon.style.transform = 'rotate(180deg)';
        } else {
            row.classList.add('hidden');
            if (icon) icon.style.transform = 'rotate(0deg)';
        }
    }

    /**
     * Smooth Accordion Toggle for Safety Stock Session Details & Part Groups
     */
    function toggleSsPartDetail(id) {
        var row = document.getElementById(id);
        if (!row) return;
        row.classList.toggle('hidden');
    }

    function toggleSsDetail(sid) {
        var row = document.getElementById('ss-detail-' + sid);
        var icon = document.getElementById('ss-chevron-' + sid);
        if (!row) return;

        if (row.classList.contains('hidden')) {
            row.classList.remove('hidden');
            if (icon) icon.style.transform = 'rotate(180deg)';
        } else {
            row.classList.add('hidden');
            if (icon) icon.style.transform = 'rotate(0deg)';
        }
    }

    /**
     * Confirmation when entering a session active by another inspector
     */
    function confirmOpenSpectatorMode(inspectorName) {
        return confirm("Kanban ini sedang aktif diinspeksi oleh " + inspectorName + ".\n\nAnda akan membuka sesi ini dalam MODE PANTAU (Read-Only) agar tidak mengganggu pengerjaan operator utama.\n\nLanjutkan membuka?");
    }

    /**
     * Auto-Focus & Barcode Scanner Integration for Search Input
     */
    (function initSearchScanner() {
        var searchInput = document.getElementById('kanbanSearchInput');
        if (!searchInput) return;

        // Function to focus and select all text (so scanning immediately overwrites previous value)
        function focusAndSelect() {
            try {
                searchInput.focus();
                if (searchInput.value.length > 0) {
                    searchInput.select();
                }
            } catch (e) {}
        }

        // 1. Initial focus on DOM ready & window load
        setTimeout(focusAndSelect, 80);
        window.addEventListener('load', function() {
            setTimeout(focusAndSelect, 150);
        });

        // 2. Parser for QR Code format (Z1<part_code>|Z2<lot>|...)
        function parseQrScanJs(val) {
            var obj = { Z1: '', Z2: '', Z3: '', Z4: '', Z5: '', is_qr: false };
            if (!val) return obj;
            var trimmed = val.trim();
            if (trimmed.indexOf('|') !== -1 || trimmed.indexOf('Z1') !== -1 || trimmed.indexOf('Z2') !== -1 || trimmed.indexOf('Z5') !== -1) {
                obj.is_qr = true;
                var parts = trimmed.split('|');
                for (var i = 0; i < parts.length; i++) {
                    var p = parts[i].trim();
                    if (p.indexOf('Z1') === 0) obj.Z1 = p.substring(2).trim();
                    else if (p.indexOf('Z2') === 0) obj.Z2 = p.substring(2).trim();
                    else if (p.indexOf('Z3') === 0) obj.Z3 = p.substring(2).trim();
                    else if (p.indexOf('Z4') === 0) obj.Z4 = p.substring(2).trim();
                    else if (p.indexOf('Z5') === 0) obj.Z5 = p.substring(2).trim();
                }
            }
            return obj;
        }

        // 3. Submit HANYA saat Enter (scanner keyboard-wedge mengetik karakter satu per satu
        //    lalu mengirim Enter di akhir). Jangan auto-submit saat mengetik, karena payload
        //    QR akan terpotong (misal baru "Z1" sudah ter-submit).
        var form = document.getElementById('kanbanFilterForm');
        var isSubmitting = false;

        function submitSearchForm() {
            if (!form || isSubmitting) return;
            isSubmitting = true;
            // Bersihkan karakter CR/LF/TAB sisa scanner sebelum dikirim ke backend
            searchInput.value = searchInput.value.replace(/[\r\n\t]+/g, '').trim();
            form.submit();
        }

        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.keyCode === 13) {
                e.preventDefault();
                submitSearchForm();
            }
        });

        // 4. Paste manual (Ctrl+V) payload QR lengkap -> auto-submit setelah teks tertempel
        searchInput.addEventListener('paste', function() {
            setTimeout(function() {
                var qr = parseQrScanJs(searchInput.value);
                if (qr.is_qr && (qr.Z5 || qr.Z2)) {
                    submitSearchForm();
                }
            }, 50);
        });

        // 5. Smart Keyboard / Scanner listener:
        // - Escape key: focus and select
        // - '/' key outside of inputs: focus search
        // - If scanner fires while focus is on table/body, redirect keystroke to searchInput
        document.addEventListener('keydown', function(e) {
            var activeEl = document.activeElement;
            var isInputActive = activeEl && (
                activeEl.tagName === 'INPUT' ||
                activeEl.tagName === 'TEXTAREA' ||
                activeEl.tagName === 'SELECT' ||
                activeEl.isContentEditable
            );

            if (e.key === 'Escape') {
                focusAndSelect();
                return;
            }

            if (e.key === '/' && !isInputActive) {
                e.preventDefault();
                focusAndSelect();
                return;
            }

            if (!isInputActive && !e.ctrlKey && !e.altKey && !e.metaKey && e.key.length === 1) {
                searchInput.focus();
            }
        });

        // 6. When clicking empty space or table background, re-focus search input
        document.addEventListener('click', function(e) {
            var target = e.target;
            if (target && !target.closest('a, button, input, select, textarea, label, [role="button"], details, summary')) {
                focusAndSelect();
            }
        });
    })();

    /**
     * High-Scale Real-Time Workboard Poller (Multi-Laptop Live Sync)
     * Queries lightweight state hash every 4 seconds.
     * Uses O(1) B-Tree point-lookups on server; zero reload or UI disruptions.
     */
    (function initRealTimeWorkboard() {
        var activeTab = '<?= $activeTab ?>';
        if (activeTab !== 'kanban') return;

        var lastLiveHash = '';
        var isPolling = false;
        var pollIntervalMs = 12000;
        var syncTimeElem = document.getElementById('live-sync-time');
        var syncIndicator = document.getElementById('live-sync-indicator');
        var baseUrl = '<?= base_url('modules/inspection/') ?>';

        function pollLiveStatus() {
            if (document.hidden) return;
            if (isPolling) return;

            var rows = document.querySelectorAll('[data-kanban-id]');
            if (!rows || rows.length === 0) return;

            var visibleIds = [];
            rows.forEach(function(r) {
                var kid = r.getAttribute('data-kanban-id');
                if (kid) visibleIds.push(kid);
            });

            if (visibleIds.length === 0) return;

            isPolling = true;
            var url = baseUrl + 'api/get_live_status.php?kanban_ids=' + encodeURIComponent(visibleIds.join(',')) + '&last_hash=' + encodeURIComponent(lastLiveHash);

            var controller = (typeof AbortController !== 'undefined') ? new AbortController() : null;
            var fetchTimeout = null;
            if (controller) {
                fetchTimeout = setTimeout(function() {
                    controller.abort();
                }, 5000); // Batalkan request jika server tidak merespons dalam 5 detik
            }

            fetch(url, { 
                credentials: 'same-origin',
                signal: controller ? controller.signal : undefined
            })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (!data || !data.success) return;

                    if (syncTimeElem && data.server_time) {
                        syncTimeElem.innerText = data.server_time;
                    }

                    // If server state hash is identical, skip DOM rendering (O(0) client work)
                    if (data.changed === false) {
                        return;
                    }

                    lastLiveHash = data.hash || '';
                    var updates = data.kanban_updates || {};

                    // Update each visible row
                    Object.keys(updates).forEach(function(kidStr) {
                        var info = updates[kidStr];
                        var kid = info.id;
                        var rowElem = document.getElementById('kanban-row-' + kid);
                        var statusContainer = document.getElementById('status-container-' + kid);
                        var actionContainer = document.getElementById('action-container-' + kid);
                        var progressContainer = document.getElementById('progress-container-' + kid);

                        if (!rowElem) return;

                        // 1. Row highlight & status border
                        var statusClasses = [
                            'row-status-overdue',
                            'row-status-active',
                            'row-status-partial',
                            'row-status-completed',
                            'row-status-rejected',
                            'row-status-uninspected'
                        ];
                        statusClasses.forEach(function(cls) { rowElem.classList.remove(cls); });

                        var isOverdueRow = rowElem.getAttribute('data-is-overdue') === '1';
                        if (isOverdueRow && !info.has_active_session && !info.is_fully_passed && info.status_type !== 'completed') {
                            rowElem.classList.add('row-status-overdue');
                        } else if (info.has_active_session) {
                            rowElem.classList.add('row-status-active');
                        } else if (info.status_type === 'completed' || info.is_fully_passed) {
                            rowElem.classList.add('row-status-completed');
                        } else if (info.status_type === 'partial' || (info.passed_qty > 0 && info.passed_qty < info.target_qty)) {
                            rowElem.classList.add('row-status-partial');
                        } else if (info.status_type === 'rejected') {
                            rowElem.classList.add('row-status-rejected');
                        } else {
                            rowElem.classList.add('row-status-uninspected');
                        }

                        // 2. Status Badge & Active Inspector Attribution
                        if (statusContainer) {
                            if (info.has_active_session) {
                                var inspName = info.active_inspector_name || 'QC Inspector';
                                var dur = info.duration_minutes || 0;
                                statusContainer.innerHTML = 
                                    '<span style="background-color: #d97706; color: #ffffff; font-weight: 800; font-size: 10.5px; padding: 3.5px 11px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 3px rgba(217, 119, 6, 0.4);">' +
                                        '<span style="width: 6px; height: 6px; border-radius: 9999px; background-color: #ffffff; display: inline-block;" class="animate-pulse"></span>' +
                                        'Sedang Dikerjakan' +
                                    '</span>' +
                                    '<span class="block text-[9px] text-amber-800 font-bold mt-0.5 truncate max-w-[135px] mx-auto" title="Dikerjakan oleh ' + escapeHtml(inspName) + ' (sejak ' + dur + ' mnt lalu)">' +
                                        'Oleh: ' + escapeHtml(inspName) +
                                    '</span>';
                            } else if (info.status_type === 'completed' || info.is_fully_passed) {
                                statusContainer.innerHTML = 
                                    '<span style="background-color: #16a34a; color: #ffffff; font-weight: 800; font-size: 11px; padding: 3.5px 11px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 3px rgba(22, 163, 74, 0.4);">' +
                                        '<svg style="width: 11px; height: 11px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>' +
                                        'Selesai' +
                                    '</span>';
                            } else if (info.status_type === 'partial' || (info.passed_qty > 0 && info.passed_qty < info.target_qty)) {
                                var rejectBadgeHtml = (info.has_rejected_session && !info.is_fully_passed && info.status_type !== 'completed')
                                    ? '<span style="background-color: #dc2626; color: #ffffff; font-weight: 800; font-size: 9.5px; padding: 2px 8px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 3px; box-shadow: 0 1px 2px rgba(220, 38, 38, 0.35); margin-top: 4px;"><span style="width: 5px; height: 5px; border-radius: 9999px; background-color: #ffffff; display: inline-block;" class="animate-pulse"></span>Ada Reject</span>'
                                    : '';
                                statusContainer.innerHTML = 
                                    '<div class="flex flex-col items-center gap-1">' +
                                        '<span style="background-color: #d97706; color: #ffffff; font-weight: 800; font-size: 11px; padding: 3px 10px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 3px rgba(217, 119, 6, 0.35);">' +
                                            '<svg style="width: 11px; height: 11px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>' +
                                            'Parsial' +
                                        '</span>' +
                                        rejectBadgeHtml +
                                    '</div>';
                            } else if (info.status_type === 'rejected') {
                                statusContainer.innerHTML = 
                                    '<span style="background-color: #dc2626; color: #ffffff; font-weight: 800; font-size: 10.5px; padding: 3.5px 11px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 3px rgba(220, 38, 38, 0.4);">' +
                                        '<svg style="width: 11px; height: 11px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"/></svg>' +
                                        'Reject' +
                                    '</span>';
                            } else {
                                statusContainer.innerHTML = 
                                    '<span class="px-2.5 py-1 rounded-full text-[10.5px] font-bold bg-slate-100 text-slate-600 border border-slate-200 inline-flex items-center gap-1">' +
                                        '<span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span>' +
                                        'Belum Mulai' +
                                    '</span>';
                            }
                        }

                        // 3. Action Buttons (Own session vs Spectator vs Start)
                        if (actionContainer) {
                            if (info.has_active_session) {
                                var actSid = info.active_session_id;
                                var inspName = info.active_inspector_name || 'QC Inspector';
                                if (info.is_own_session) {
                                    actionContainer.innerHTML = 
                                        '<a href="' + baseUrl + 'session.php?id=' + actSid + '" ' +
                                           'style="background-color: #d97706; color: #ffffff;" ' +
                                           'class="px-3 py-1.5 bg-amber-600 hover:bg-amber-700 active:bg-amber-800 text-white font-bold text-xs rounded-md shadow-xs hover:shadow transition-all inline-flex items-center gap-1.5 whitespace-nowrap flex-nowrap" ' +
                                           'title="Lanjutkan sesi inspeksi Anda">' +
                                            '<svg class="w-3 h-3 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor" style="fill: #ffffff;">' +
                                                '<path d="M6.3 2.841A1.5 1.5 0 004 4.11V15.89a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z"/>' +
                                            '</svg>' +
                                            '<span>Lanjutkan</span>' +
                                        '</a>';
                                } else {
                                    var safeName = inspName.replace(/'/g, "\\'");
                                    actionContainer.innerHTML = 
                                        '<a href="' + baseUrl + 'session.php?id=' + actSid + '&view_mode=readonly" ' +
                                           'onclick="return confirmOpenSpectatorMode(\'' + safeName + '\')" ' +
                                           'class="px-3 py-1.5 bg-slate-700 hover:bg-slate-800 text-white font-bold text-xs rounded-md shadow-xs hover:shadow transition-all inline-flex items-center gap-1.5 whitespace-nowrap flex-nowrap" ' +
                                           'title="Buka sesi dalam Mode Pantau (Read-Only) tanpa mengganggu sesi aktif">' +
                                            '<svg class="w-3.5 h-3.5 text-amber-300 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>' +
                                            '<span>Lihat Sesi</span>' +
                                        '</a>';
                                }
                            } else if (!info.is_fully_passed && info.status_type !== 'completed') {
                                var kNo = rowElem.getAttribute('data-kanban-no') || ('#' + kid);
                                var iCode = rowElem.getAttribute('data-item-code') || '';
                                var pName = rowElem.getAttribute('data-part-name') || '';
                                var cName = rowElem.getAttribute('data-customer') || 'PT. Indonesia Epson Industry';
                                var tQty = parseInt(rowElem.getAttribute('data-target-qty')) || 0;
                                var pQty = parseInt(info.passed_qty) || 0;
                                var remQty = Math.max(0, tQty - pQty);
                                var isAdminUser = <?= $isAdminOrSupervisor ? 'true' : 'false' ?>;

                                var closeBtnHtml = '';
                                if (isAdminUser) {
                                    var safeKNo = kNo.replace(/'/g, "\\'");
                                    var safeICode = iCode.replace(/'/g, "\\'");
                                    var safePName = pName.replace(/'/g, "\\'");
                                    var safeCName = cName.replace(/'/g, "\\'");
                                    closeBtnHtml = 
                                        '<button type="button" ' +
                                           'onclick="openDirectCloseModal(' + kid + ', \'' + safeKNo + '\', \'' + safeICode + '\', \'' + safePName + '\', \'' + safeCName + '\', ' + remQty + ', ' + tQty + ')" ' +
                                           'style="background-color: #ffffff; color: #475569; border: 1.5px solid #cbd5e1;" ' +
                                           'onmouseover="this.style.borderColor=\'#86efac\'; this.style.backgroundColor=\'#f0fdf4\'; this.style.color=\'#15803d\';" ' +
                                           'onmouseout="this.style.borderColor=\'#cbd5e1\'; this.style.backgroundColor=\'#ffffff\'; this.style.color=\'#475569\';" ' +
                                           'class="px-2.5 py-1.5 font-extrabold text-xs rounded-md shadow-2xs transition-all inline-flex items-center gap-1 whitespace-nowrap flex-nowrap cursor-pointer" ' +
                                           'title="Selesaikan Kanban langsung (Admin Direct Close)">' +
                                            '<svg class="w-3.5 h-3.5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>' +
                                            '<span>Close</span>' +
                                        '</button>';
                                } else {
                                    closeBtnHtml = 
                                        '<a href="' + baseUrl + 'close_kanban.php?kanban_id=' + kid + '" ' +
                                           'style="background-color: #ffffff; color: #475569; border: 1.5px solid #cbd5e1;" ' +
                                           'onmouseover="this.style.borderColor=\'#86efac\'; this.style.backgroundColor=\'#f0fdf4\'; this.style.color=\'#15803d\';" ' +
                                           'onmouseout="this.style.borderColor=\'#cbd5e1\'; this.style.backgroundColor=\'#ffffff\'; this.style.color=\'#475569\';" ' +
                                           'class="px-2.5 py-1.5 font-extrabold text-xs rounded-md shadow-2xs transition-all inline-flex items-center gap-1 whitespace-nowrap flex-nowrap cursor-pointer" ' +
                                           'title="Tutup Kanban dengan input Lot fisik / scan">' +
                                            '<svg class="w-3.5 h-3.5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>' +
                                            '<span>Close</span>' +
                                        '</a>';
                                }

                                actionContainer.innerHTML = 
                                    '<div class="inline-flex items-center gap-2 flex-nowrap">' +
                                        closeBtnHtml +
                                        '<a href="' + baseUrl + 'session.php?scan_new=1&kanban_id=' + kid + '" ' +
                                           'style="background-color: #2563eb; color: #ffffff;" ' +
                                           'class="px-3.5 py-1.5 hover:bg-blue-700 active:bg-blue-800 text-white font-extrabold text-xs rounded-md shadow-xs hover:shadow transition-all inline-flex items-center gap-1.5 whitespace-nowrap flex-nowrap" ' +
                                           'title="Mulai inspeksi pengerjaan Kanban ini">' +
                                            '<svg class="w-3 h-3 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor" style="fill: #ffffff;">' +
                                                '<path d="M6.3 2.841A1.5 1.5 0 004 4.11V15.89a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z"/>' +
                                            '</svg>' +
                                            '<span>Inspeksi</span>' +
                                        '</a>' +
                                    '</div>';
                            } else {
                                actionContainer.innerHTML = '';
                            }
                        }

                        // 4. Progress Bar update
                        if (progressContainer) {
                            var passedSpan = progressContainer.querySelector('.passed-qty-val');
                            if (passedSpan) passedSpan.innerText = Number(info.passed_qty).toLocaleString();
                            var barFill = progressContainer.querySelector('.progress-bar-fill');
                            var barLbl = progressContainer.querySelector('.progress-label');
                            if (barFill && barLbl) {
                                if (info.is_fully_passed || info.progress_pct >= 100) {
                                    barFill.style.width = '100%';
                                    barFill.style.background = 'linear-gradient(90deg, #10b981 0%, #059669 100%)';
                                    barLbl.className = 'text-[10px] text-emerald-700 font-extrabold block leading-none progress-label';
                                    barLbl.innerText = '100% selesai';
                                } else if (info.has_active_session) {
                                    barFill.style.width = Math.max(8, info.progress_pct) + '%';
                                    barFill.style.background = 'linear-gradient(90deg, #f59e0b 0%, #d97706 100%)';
                                    barLbl.className = 'text-[10px] text-amber-800 font-black block leading-none progress-label';
                                    barLbl.innerText = (info.progress_pct > 0) ? (info.progress_pct + '% berjalan') : 'Mulai berjalan';
                                } else if (info.progress_pct > 0) {
                                    barFill.style.width = info.progress_pct + '%';
                                    barFill.style.background = 'linear-gradient(90deg, #f59e0b 0%, #d97706 100%)';
                                    barLbl.className = 'text-[10px] text-amber-800 font-extrabold block leading-none progress-label';
                                    barLbl.innerText = info.progress_pct + '% parsial';
                                } else {
                                    barFill.style.width = '0%';
                                    barFill.style.background = '#cbd5e1';
                                    barLbl.className = 'text-[10px] text-slate-400 font-medium block leading-none progress-label';
                                    barLbl.innerText = 'Belum mulai';
                                }
                            }
                        }

                        // 5. Completed row stays in its exact original paper sequence (do not relocate to bottom)
                    });
                })
                .catch(function(err) {
                    // Timeout atau network error tertangani di finally
                })
                .finally(function() {
                    if (fetchTimeout) clearTimeout(fetchTimeout);
                    isPolling = false;
                });
        }

        function escapeHtml(text) {
            var div = document.createElement('div');
            div.textContent = text || '';
            return div.innerHTML;
        }

        // Initial poller run and recurrent interval
        setTimeout(pollLiveStatus, 1500);
        setInterval(pollLiveStatus, pollIntervalMs);
    })();

function estimateAqlSampleSize(qty) {
        qty = parseInt(qty) || 0;
        if (qty <= 0) return 0;
        if (qty <= 8) return 2;
        if (qty <= 15) return 3;
        if (qty <= 25) return 5;
        if (qty <= 50) return 8;
        if (qty <= 90) return 13;
        if (qty <= 150) return 20;
        if (qty <= 280) return 32;
        if (qty <= 500) return 50;
        if (qty <= 1200) return 80;
        if (qty <= 3200) return 125;
        if (qty <= 10000) return 200;
        if (qty <= 35000) return 315;
        return 500;
    }

    function openDirectCloseModal(kid, kanbanNo, itemCode, partName, customer, remainingQty, targetQty) {
        document.getElementById('dc_kanban_id').value = kid;
        document.getElementById('dc_kanban_no').innerText = '#' + (kanbanNo || kid);
        document.getElementById('dc_customer').innerText = customer || '-';
        document.getElementById('dc_part_code').innerText = itemCode || '-';
        document.getElementById('dc_part_name').innerText = partName || '-';
        
        remainingQty = parseInt(remainingQty) || 0;
        targetQty = parseInt(targetQty) || 0;
        
        document.getElementById('dc_close_qty').innerText = Number(remainingQty).toLocaleString();
        if (remainingQty < targetQty && targetQty > 0) {
            document.getElementById('dc_qty_note').innerText = 'Sisa Kuota (Target: ' + Number(targetQty).toLocaleString() + ' pcs)';
        } else {
            document.getElementById('dc_qty_note').innerText = 'Full Qty Target Kanban';
        }
        
        var sampleEst = estimateAqlSampleSize(remainingQty);
        document.getElementById('dc_sample_size').innerText = Number(sampleEst).toLocaleString();
        document.getElementById('dc_remarks').value = '';
        
        var modal = document.getElementById('modalDirectCloseKanban');
        modal.style.display = 'flex';
    }

    function closeDirectCloseModal() {
        var modal = document.getElementById('modalDirectCloseKanban');
        modal.style.display = 'none';
    }

    function submitDirectClose(e) {
        e.preventDefault();
        var kid = document.getElementById('dc_kanban_id').value;
        var remarks = document.getElementById('dc_remarks').value;
        var btn = document.getElementById('btnSubmitDirectClose');
        
        if (!kid) return;
        
        var origBtnHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path></svg> <span>Menyimpan...</span>';
        
        var formData = new FormData();
        formData.append('kanban_id', kid);
        formData.append('remarks', remarks);
        
        fetch('<?= base_url('modules/inspection/api/close_kanban_direct.php') ?>', {
            method: 'POST',
            body: formData
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            btn.disabled = false;
            btn.innerHTML = origBtnHtml;
            
            if (data && data.success) {
                closeDirectCloseModal();
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Berhasil!',
                        text: data.message,
                        timer: 1600,
                        showConfirmButton: false
                    }).then(function() {
                        window.location.reload();
                    });
                } else {
                    alert(data.message);
                    window.location.reload();
                }
            } else {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'error',
                        title: 'Gagal',
                        text: (data && data.message) ? data.message : 'Terjadi kesalahan sistem.'
                    });
                } else {
                    alert((data && data.message) ? data.message : 'Terjadi kesalahan sistem.');
                }
            }
        })
        .catch(function(err) {
            btn.disabled = false;
            btn.innerHTML = origBtnHtml;
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'error',
                    title: 'Error Jaringan',
                    text: 'Gagal menghubungi server. Periksa koneksi Anda.'
                });
            } else {
                alert('Gagal menghubungi server.');
            }
        });
    }

    <?php if ($isAdminOrSupervisor): ?>
    /* ═════════════════════════════════════════════════════════════════════════
     * MULTI-KANBAN BATCH DIRECT CLOSE (BULK ADMIN FEATURE)
     * ═════════════════════════════════════════════════════════════════════════ */
    function getSelectedKanbanCheckboxes() {
        return Array.from(document.querySelectorAll('.bulk-kanban-cb:checked:not(:disabled)'));
    }

    function toggleSelectAllKanban(masterChecked) {
        var checkboxes = document.querySelectorAll('.bulk-kanban-cb:not(:disabled)');
        checkboxes.forEach(function(cb) {
            // Hanya centang row yang tidak tersembunyi oleh Excel filter
            var row = cb.closest('tr');
            if (!row || row.style.display !== 'none') {
                cb.checked = masterChecked;
            }
        });
        onKanbanCheckChange();
    }

    function clearBulkKanbanSelection() {
        var checkboxes = document.querySelectorAll('.bulk-kanban-cb');
        checkboxes.forEach(function(cb) { cb.checked = false; });
        var masterCb = document.getElementById('bulk-kanban-check-all');
        if (masterCb) masterCb.checked = false;
        onKanbanCheckChange();
    }

    function onKanbanCheckChange() {
        var selected = getSelectedKanbanCheckboxes();
        var bar = document.getElementById('bulk-kanban-action-bar');
        var masterCb = document.getElementById('bulk-kanban-check-all');
        var allEnabled = document.querySelectorAll('.bulk-kanban-cb:not(:disabled)');

        if (masterCb && allEnabled.length > 0) {
            masterCb.checked = (selected.length === allEnabled.length);
        }

        if (!bar) return;

        if (selected.length > 0) {
            var totalQty = selected.reduce(function(acc, cb) {
                return acc + (parseInt(cb.getAttribute('data-qty')) || 0);
            }, 0);

            document.getElementById('bulk-selected-count').innerText = selected.length;
            document.getElementById('bulk-selected-qty').innerText = Number(totalQty).toLocaleString() + ' pcs';
            bar.style.display = 'flex';
        } else {
            bar.style.display = 'none';
        }
    }

    function openBulkDirectCloseModal() {
        var selected = getSelectedKanbanCheckboxes();
        if (selected.length === 0) {
            if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'warning', title: 'Belum Ada Kanban Dipilih', text: 'Silakan centang minimal 1 kartu Kanban terlebih dahulu.' });
            } else {
                alert('Pilih minimal 1 kartu Kanban.');
            }
            return;
        }

        var totalQty = 0;
        var totalSample = 0;
        var listContainer = document.getElementById('bulk-modal-items-list');
        listContainer.innerHTML = '';

        selected.forEach(function(cb) {
            var kNo = cb.getAttribute('data-kanban-no') || cb.value;
            var pCode = cb.getAttribute('data-part') || '-';
            var qty = parseInt(cb.getAttribute('data-qty')) || 0;
            var sample = parseInt(cb.getAttribute('data-sample')) || estimateAqlSampleSize(qty);
            totalQty += qty;
            totalSample += sample;

            var itemDiv = document.createElement('div');
            itemDiv.style.backgroundColor = '#ffffff';
            itemDiv.style.border = '1px solid #cbd5e1';
            itemDiv.style.borderRadius = '8px';
            itemDiv.style.padding = '8px 12px';
            itemDiv.className = 'flex items-center justify-between shadow-sm';
            itemDiv.innerHTML = 
                '<div class="flex items-center space-x-2.5">' +
                    '<span class="font-mono font-black text-slate-900 text-xs">#' + escapeHtml(kNo) + '</span>' +
                    '<span style="background-color: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; font-family: monospace; font-size: 11px; padding: 1px 6px; border-radius: 4px; font-weight: 700;">' + escapeHtml(pCode) + '</span>' +
                '</div>' +
                '<div class="flex items-center space-x-3 text-right">' +
                    '<div>' +
                        '<span class="block text-[9px] text-amber-800 font-bold uppercase leading-none">Kuota</span>' +
                        '<span class="font-mono font-black text-xs text-amber-900">' + Number(qty).toLocaleString() + ' pcs</span>' +
                    '</div>' +
                    '<div>' +
                        '<span class="block text-[9px] text-emerald-700 font-bold uppercase leading-none">Sample AQL</span>' +
                        '<span class="font-mono font-extrabold text-xs text-emerald-700">' + Number(sample).toLocaleString() + ' pcs</span>' +
                    '</div>' +
                '</div>';
            listContainer.appendChild(itemDiv);
        });

        document.getElementById('bulk-modal-total-count').innerText = selected.length;
        document.getElementById('bulk-modal-total-qty').innerText = Number(totalQty).toLocaleString();
        var totalSampleElem = document.getElementById('bulk-modal-total-sample');
        if (totalSampleElem) totalSampleElem.innerText = Number(totalSample).toLocaleString();
        document.getElementById('bulk_dc_remarks').value = '';

        var modal = document.getElementById('modalBulkDirectCloseKanban');
        if (modal) modal.style.display = 'flex';
    }

    function closeBulkDirectCloseModal() {
        var modal = document.getElementById('modalBulkDirectCloseKanban');
        if (modal) modal.style.display = 'none';
    }

    function submitBulkDirectClose(e) {
        e.preventDefault();
        var selected = getSelectedKanbanCheckboxes();
        if (selected.length === 0) return;

        var kanbanIds = selected.map(function(cb) { return cb.value; });
        var remarks = document.getElementById('bulk_dc_remarks').value;
        var btn = document.getElementById('btnSubmitBulkDirectClose');

        var origBtnHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path></svg> <span>Menyelesaikan ' + kanbanIds.length + ' Kanban...</span>';

        var formData = new FormData();
        kanbanIds.forEach(function(id) {
            formData.append('kanban_ids[]', id);
        });
        formData.append('remarks', remarks);

        fetch('<?= base_url('modules/inspection/api/bulk_close_kanban_direct.php') ?>', {
            method: 'POST',
            body: formData
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            btn.disabled = false;
            btn.innerHTML = origBtnHtml;

            if (data && data.success) {
                closeBulkDirectCloseModal();
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Berhasil Batch Close!',
                        text: data.message,
                        timer: 2000,
                        showConfirmButton: false
                    }).then(function() {
                        window.location.reload();
                    });
                } else {
                    alert(data.message);
                    window.location.reload();
                }
            } else {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'error',
                        title: 'Gagal Batch Close',
                        text: (data && data.message) ? data.message : 'Terjadi kesalahan sistem.'
                    });
                } else {
                    alert((data && data.message) ? data.message : 'Terjadi kesalahan sistem.');
                }
            }
        })
        .catch(function(err) {
            btn.disabled = false;
            btn.innerHTML = origBtnHtml;
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'error',
                    title: 'Error Jaringan',
                    text: 'Gagal menghubungi server saat batch close.'
                });
            } else {
                alert('Gagal menghubungi server saat batch close.');
            }
        });
    }
    <?php endif; ?>

    function scrollMyLotsTable(amount) {
        var wrapper = document.getElementById('my-lots-table-wrapper') || document.getElementById('ss-table-wrapper');
        if (wrapper) {
            wrapper.scrollBy({ left: amount, behavior: 'smooth' });
        }
    }

    function scrollSsUsedTable(amount) {
        var wrapper = document.getElementById('ss-used-table-wrapper');
        if (wrapper) {
            wrapper.scrollBy({ left: amount, behavior: 'smooth' });
        }
    }

    function toggleSsPartDetail(rowId) {
        var row = document.getElementById(rowId);
        if (row) {
            row.classList.toggle('hidden');
        }
    }

    /* ═════════════════════════════════════════════════════════════════════════
     * EXCEL-STYLE COLUMN AUTOFILTER ENGINE (CLIENT-SIDE)
     * ═════════════════════════════════════════════════════════════════════════ */
    var ExcelFilterState = {
        activeCol: null,
        filters: {
            kanban: new Set(),
            part: new Set(),
            schedule: new Set(),
            progress: new Set(),
            status: new Set()
        },
        allOptions: {
            kanban: new Set(),
            part: new Set(),
            schedule: new Set(),
            progress: new Set(),
            status: new Set()
        }
    };

    function initExcelFilters() {
        var rows = document.querySelectorAll('tr[id^="kanban-row-"]');
        if (!rows || rows.length === 0) return;

        // Reset options
        ['kanban', 'part', 'schedule', 'progress', 'status'].forEach(function(col) {
            ExcelFilterState.allOptions[col] = new Set();
        });

        // Scan values
        rows.forEach(function(r) {
            var kb = r.getAttribute('data-filter-kanban') || '';
            var pt = r.getAttribute('data-filter-part') || '';
            var sc = r.getAttribute('data-filter-schedule') || '';
            var pr = r.getAttribute('data-filter-progress') || '';
            var st = r.getAttribute('data-filter-status') || '';

            if (kb) ExcelFilterState.allOptions.kanban.add(kb);
            if (pt) ExcelFilterState.allOptions.part.add(pt);
            if (sc) ExcelFilterState.allOptions.schedule.add(sc);
            if (pr) ExcelFilterState.allOptions.progress.add(pr);
            if (st) ExcelFilterState.allOptions.status.add(st);
        });

        // Populate lists
        ['kanban', 'part', 'schedule', 'progress', 'status'].forEach(function(col) {
            renderFilterCheckboxes(col);
        });
    }

    function renderFilterCheckboxes(col) {
        var container = document.getElementById('filter-list-' + col);
        if (!container) return;

        var options = Array.from(ExcelFilterState.allOptions[col]).sort();
        var selected = ExcelFilterState.filters[col];
        var isFilterActive = (selected.size > 0);

        var html = '';
        if (options.length === 0) {
            html = '<div class="text-[10px] text-slate-400 p-2 text-center">Tidak ada opsi</div>';
        } else {
            options.forEach(function(val, idx) {
                var checked = (!isFilterActive || selected.has(val)) ? 'checked' : '';
                var safeVal = val.replace(/"/g, '&quot;');
                html += '<label class="excel-filter-item" data-val="' + safeVal.toLowerCase() + '">' +
                        '<input type="checkbox" value="' + safeVal + '" ' + checked + ' onchange="onFilterCheckboxChange(\'' + col + '\')">' +
                        '<span class="truncate" title="' + safeVal + '">' + safeVal + '</span>' +
                        '</label>';
            });
        }
        container.innerHTML = html;
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
            // Hanya toggle checkbox yang sedang terlihat (sesuai search)
            if (cb.closest('.excel-filter-item').style.display !== 'none') {
                cb.checked = selectAll;
            }
        });
    }

    function onFilterCheckboxChange(col) {
        // Callback opsional jika diperlukan
    }

    function applyExcelFilters() {
        var cols = ['kanban', 'part', 'schedule', 'progress', 'status'];

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
                // Semua terpilih atau tidak ada yang terpilih = jangan batasi (kosongkan filter)
                ExcelFilterState.filters[col].clear();
                if (btn) btn.classList.remove('active');
            } else {
                ExcelFilterState.filters[col] = new Set(checkedValues);
                if (btn) btn.classList.add('active');
            }
        });

        closeAllExcelFilters();
        executeRowFiltering();
    }

    function resetSingleColFilter(col) {
        ExcelFilterState.filters[col].clear();
        var btn = document.querySelector('.th-filter-btn[data-col="' + col + '"]');
        if (btn) btn.classList.remove('active');
        renderFilterCheckboxes(col);
        closeAllExcelFilters();
        executeRowFiltering();
    }

    function resetAllExcelFilters() {
        ['kanban', 'part', 'schedule', 'progress', 'status'].forEach(function(col) {
            ExcelFilterState.filters[col].clear();
            var btn = document.querySelector('.th-filter-btn[data-col="' + col + '"]');
            if (btn) btn.classList.remove('active');
            renderFilterCheckboxes(col);
        });
        closeAllExcelFilters();
        executeRowFiltering();
    }

    function executeRowFiltering() {
        var rows = document.querySelectorAll('tr[id^="kanban-row-"]');
        if (!rows || rows.length === 0) return;

        var activeFilterCols = [];
        ['kanban', 'part', 'schedule', 'progress', 'status'].forEach(function(c) {
            if (ExcelFilterState.filters[c].size > 0) activeFilterCols.push(c);
        });

        var visibleCount = 0;
        var totalRows = rows.length;

        rows.forEach(function(r) {
            var kid = r.getAttribute('data-kanban-id');
            var detailRow = document.getElementById('kanban-detail-' + kid);

            var kb = r.getAttribute('data-filter-kanban') || '';
            var pt = r.getAttribute('data-filter-part') || '';
            var sc = r.getAttribute('data-filter-schedule') || '';
            var pr = r.getAttribute('data-filter-progress') || '';
            var st = r.getAttribute('data-filter-status') || '';

            var match = true;
            if (ExcelFilterState.filters.kanban.size > 0 && !ExcelFilterState.filters.kanban.has(kb)) match = false;
            if (match && ExcelFilterState.filters.part.size > 0 && !ExcelFilterState.filters.part.has(pt)) match = false;
            if (match && ExcelFilterState.filters.schedule.size > 0 && !ExcelFilterState.filters.schedule.has(sc)) match = false;
            if (match && ExcelFilterState.filters.progress.size > 0 && !ExcelFilterState.filters.progress.has(pr)) match = false;
            if (match && ExcelFilterState.filters.status.size > 0 && !ExcelFilterState.filters.status.has(st)) match = false;

            if (match) {
                r.style.display = '';
                visibleCount++;
            } else {
                r.style.display = 'none';
                if (detailRow) detailRow.classList.add('hidden');
            }
        });

        // Update indikator bar filter
        var indicatorBar = document.getElementById('excel-filter-active-bar');
        var emptyRow = document.getElementById('excel-filter-empty-row');
        var summaryText = document.getElementById('excel-filter-summary-text');

        if (activeFilterCols.length > 0) {
            if (indicatorBar) indicatorBar.style.display = '';
            if (summaryText) {
                summaryText.innerText = 'Menyaring ' + visibleCount + ' dari ' + totalRows + ' baris (' + activeFilterCols.join(', ') + ')';
            }
        } else {
            if (indicatorBar) indicatorBar.style.display = 'none';
        }

        if (visibleCount === 0 && totalRows > 0) {
            if (emptyRow) emptyRow.style.display = '';
        } else {
            if (emptyRow) emptyRow.style.display = 'none';
        }

        // Sinkronisasi checkbox batch selection
        if (typeof onKanbanCheckChange === 'function') {
            onKanbanCheckChange();
        }
    }

    // Event listener: Tutup filter jika klik di luar
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.excel-filter-dropdown') && !e.target.closest('.th-filter-btn')) {
            closeAllExcelFilters();
            if (typeof closeAllSsFilters === 'function') closeAllSsFilters();
        }
    });

    // Event listener: Escape key untuk menutup filter
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeAllExcelFilters();
            if (typeof closeAllSsFilters === 'function') closeAllSsFilters();
        }
    });

    // Inisialisasi saat DOM siap
    document.addEventListener('DOMContentLoaded', function() {
        initExcelFilters();
        if (typeof initSsExcelFilters === 'function') initSsExcelFilters();
    });

    // ═══════════════════════════════════════════════════════════════════════
    // SAFETY STOCK TAB: Excel-Style Column Filters (Tabel 1 & Tabel 2)
    // State terpisah dari Kanban tab ExcelFilterState
    // ═══════════════════════════════════════════════════════════════════════

    var SsFilterState = {
        activeCol: null,
        // Table 1 filters
        filters: {
            ss_part: new Set(),
            ss_model: new Set(),
            ss_status: new Set(),
            // Table 2 filters
            used_kanban: new Set(),
            used_part: new Set(),
            used_model: new Set(),
            used_status: new Set()
        },
        allOptions: {
            ss_part: new Set(),
            ss_model: new Set(),
            ss_status: new Set(),
            used_kanban: new Set(),
            used_part: new Set(),
            used_model: new Set(),
            used_status: new Set()
        }
    };

    var ssTable1Cols = ['ss_part', 'ss_model', 'ss_status'];
    var ssTable2Cols = ['used_kanban', 'used_part', 'used_model', 'used_status'];
    var ssAllCols = ssTable1Cols.concat(ssTable2Cols);

    function initSsExcelFilters() {
        // Scan Table 1 values
        var t1Rows = document.querySelectorAll('tr.ss-master-row');
        ssTable1Cols.forEach(function(c) { SsFilterState.allOptions[c] = new Set(); });
        t1Rows.forEach(function(r) {
            var p = (r.getAttribute('data-ss-part') || '').trim();
            var m = (r.getAttribute('data-ss-model') || '').trim();
            var s = (r.getAttribute('data-ss-status') || '').trim();
            if (p) SsFilterState.allOptions.ss_part.add(p);
            if (m) SsFilterState.allOptions.ss_model.add(m);
            if (s) SsFilterState.allOptions.ss_status.add(s);
        });

        // Scan Table 2 values
        var t2Rows = document.querySelectorAll('tr.ss-used-row');
        ssTable2Cols.forEach(function(c) { SsFilterState.allOptions[c] = new Set(); });
        t2Rows.forEach(function(r) {
            var k = (r.getAttribute('data-used-kanban') || '').trim();
            var p = (r.getAttribute('data-used-part') || '').trim();
            var m = (r.getAttribute('data-used-model') || '').trim();
            var s = (r.getAttribute('data-used-status') || '').trim();
            if (k) SsFilterState.allOptions.used_kanban.add(k);
            if (p) SsFilterState.allOptions.used_part.add(p);
            if (m) SsFilterState.allOptions.used_model.add(m);
            if (s) SsFilterState.allOptions.used_status.add(s);
        });

        // Render checkboxes
        ssAllCols.forEach(function(col) {
            renderSsFilterCheckboxes(col);
        });
    }

    function renderSsFilterCheckboxes(col) {
        var container = document.getElementById('filter-list-' + col);
        if (!container) return;

        var options = Array.from(SsFilterState.allOptions[col]).sort();
        var selected = SsFilterState.filters[col];
        var isFilterActive = (selected.size > 0);

        var html = '';
        if (options.length === 0) {
            html = '<div class="text-[10px] text-slate-400 p-2 text-center">Tidak ada opsi</div>';
        } else {
            options.forEach(function(val) {
                var checked = (!isFilterActive || selected.has(val)) ? 'checked' : '';
                var safeVal = val.replace(/"/g, '&quot;');
                html += '<label class="excel-filter-item" data-val="' + safeVal.toLowerCase() + '">' +
                        '<input type="checkbox" value="' + safeVal + '" ' + checked + '>' +
                        '<span class="truncate" title="' + safeVal + '">' + safeVal + '</span>' +
                        '</label>';
            });
        }
        container.innerHTML = html;
    }

    function toggleSsFilter(event, col) {
        event.stopPropagation();
        var dropdown = document.getElementById('filter-dropdown-' + col);
        if (!dropdown) return;

        var isVisible = (dropdown.style.display === 'block');
        closeAllSsFilters();
        // Also close Kanban filters to avoid dual-open
        if (typeof closeAllExcelFilters === 'function') closeAllExcelFilters();

        if (!isVisible) {
            dropdown.style.display = 'block';
            SsFilterState.activeCol = col;

            var parentTh = dropdown.closest('th');
            if (parentTh) parentTh.classList.add('ss-filter-th-active');

            var searchInput = dropdown.querySelector('.excel-filter-search');
            if (searchInput) {
                searchInput.value = '';
                onSearchSsFilterOptions(col, '');
                setTimeout(function() { searchInput.focus(); }, 50);
            }
        }
    }

    function closeAllSsFilters() {
        // Close all SS dropdowns
        ssAllCols.forEach(function(col) {
            var dd = document.getElementById('filter-dropdown-' + col);
            if (dd) dd.style.display = 'none';
        });
        document.querySelectorAll('th.ss-filter-th-active').forEach(function(th) {
            th.classList.remove('ss-filter-th-active');
        });
        SsFilterState.activeCol = null;
    }

    function onSearchSsFilterOptions(col, keyword) {
        var container = document.getElementById('filter-list-' + col);
        if (!container) return;
        var kw = (keyword || '').toLowerCase().trim();
        var items = container.querySelectorAll('.excel-filter-item');
        items.forEach(function(item) {
            var val = item.getAttribute('data-val') || '';
            item.style.display = (!kw || val.indexOf(kw) !== -1) ? 'flex' : 'none';
        });
    }

    function toggleSelectAllSsFilter(col, selectAll) {
        var container = document.getElementById('filter-list-' + col);
        if (!container) return;
        container.querySelectorAll('input[type="checkbox"]').forEach(function(cb) {
            if (cb.closest('.excel-filter-item').style.display !== 'none') {
                cb.checked = selectAll;
            }
        });
    }

    function applySsFilters(tableType) {
        var cols = (tableType === 'table1') ? ssTable1Cols : ssTable2Cols;

        cols.forEach(function(col) {
            var container = document.getElementById('filter-list-' + col);
            if (!container) return;

            var checkboxes = container.querySelectorAll('input[type="checkbox"]');
            var allCount = checkboxes.length;
            var checkedValues = [];
            checkboxes.forEach(function(cb) {
                if (cb.checked) checkedValues.push(cb.value);
            });

            var btn = document.querySelector('.th-filter-btn[data-ss-col="' + col + '"]');

            if (checkedValues.length === 0 || checkedValues.length === allCount) {
                SsFilterState.filters[col].clear();
                if (btn) btn.classList.remove('active');
            } else {
                SsFilterState.filters[col] = new Set(checkedValues);
                if (btn) btn.classList.add('active');
            }
        });

        closeAllSsFilters();

        if (tableType === 'table1') {
            executeSsTable1Filtering();
        } else {
            executeSsTable2Filtering();
        }
    }

    function resetSingleSsFilter(col) {
        SsFilterState.filters[col].clear();
        var btn = document.querySelector('.th-filter-btn[data-ss-col="' + col + '"]');
        if (btn) btn.classList.remove('active');
        renderSsFilterCheckboxes(col);
        closeAllSsFilters();

        // Determine which table this col belongs to
        if (ssTable1Cols.indexOf(col) !== -1) {
            executeSsTable1Filtering();
        } else {
            executeSsTable2Filtering();
        }
    }

    function executeSsTable1Filtering() {
        var rows = document.querySelectorAll('tr.ss-master-row');
        if (!rows || rows.length === 0) return;

        var activeFilterCols = [];
        ssTable1Cols.forEach(function(c) {
            if (SsFilterState.filters[c].size > 0) activeFilterCols.push(c);
        });

        rows.forEach(function(r) {
            if (activeFilterCols.length === 0) {
                r.style.display = '';
                // Show detail row if it was previously visible
                return;
            }

            var visible = true;
            activeFilterCols.forEach(function(c) {
                var attrName = 'data-' + c.replace('_', '-');
                var val = (r.getAttribute(attrName) || '').trim();
                if (!SsFilterState.filters[c].has(val)) {
                    visible = false;
                }
            });

            if (visible) {
                r.style.display = '';
            } else {
                r.style.display = 'none';
                // Also hide child accordion row
                var detailId = r.getAttribute('data-ss-detail-id');
                if (detailId) {
                    var detailRow = document.getElementById(detailId);
                    if (detailRow) detailRow.classList.add('hidden');
                }
            }
        });
    }

    function executeSsTable2Filtering() {
        var rows = document.querySelectorAll('tr.ss-used-row');
        if (!rows || rows.length === 0) return;

        var activeFilterCols = [];
        ssTable2Cols.forEach(function(c) {
            if (SsFilterState.filters[c].size > 0) activeFilterCols.push(c);
        });

        rows.forEach(function(r) {
            if (activeFilterCols.length === 0) {
                r.style.display = '';
                return;
            }

            var visible = true;
            activeFilterCols.forEach(function(c) {
                var attrName = 'data-' + c.replace('_', '-');
                var val = (r.getAttribute(attrName) || '').trim();
                if (!SsFilterState.filters[c].has(val)) {
                    visible = false;
                }
            });

            r.style.display = visible ? '' : 'none';
        });
    }
</script>
