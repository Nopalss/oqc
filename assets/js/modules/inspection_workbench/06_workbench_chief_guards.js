function workbenchChiefApprove() {
    if (!currentSessionId) return;

    var savedNames = getCombinedSavedChiefNamesWb();
    var selectOptionsHtml = '<option value="">-- Pilih Nama Chief QC (Tersimpan) --</option>';
    savedNames.forEach(function(n) {
        selectOptionsHtml += '<option value="' + escapeHtml(n) + '">' + escapeHtml(n) + '</option>';
    });

    Swal.fire({
        title: 'Persetujuan (ACC) Chief QC',
        html: '<div style="font-size: 13px; text-align: left; line-height: 1.5; color: #334155;">' +
              '<p style="margin-bottom: 12px;">Konfirmasi persetujuan lembar penolakan (Rejection Sheet) untuk sesi inspeksi ini:</p>' +
              '<div style="margin-bottom: 10px;">' +
              '  <label style="font-size: 11px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">Pilih Nama Tersimpan:</label>' +
              '  <select id="wb-chief-select" onchange="document.getElementById(\'wb-chief-name-input\').value = this.value;" style="width: 100%; box-sizing: border-box; font-size: 13px; font-weight: 600; padding: 8px 10px; border: 1.5px solid #cbd5e1; border-radius: 6px; background-color: #fff; outline: none; margin-bottom: 8px;">' +
                 selectOptionsHtml +
              '  </select>' +
              '</div>' +
              '<div style="margin-bottom: 10px;">' +
              '  <label style="font-size: 11px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">Ketik Nama Chief QC (Manual):</label>' +
              '  <input id="wb-chief-name-input" type="text" placeholder="Contoh: Budi / Agus..." style="width: 100%; box-sizing: border-box; font-size: 13px; font-weight: 700; padding: 8px 10px; border: 1.5px solid #2563eb; border-radius: 6px; outline: none; background-color: #f8fafc;" autocomplete="off">' +
              '</div>' +
              '<p style="font-size: 11px; color: #64748b; margin-top: 6px; line-height: 1.4;">💡 Nama yang Anda ketik (misal: <b>Budi</b>) akan otomatis tersimpan di daftar pilihan untuk ACC berikutnya.</p>' +
              '</div>',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#16a34a',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Verifikasi & ACC',
        cancelButtonText: 'Batal',
        focusConfirm: false,
        didOpen: function() {
            var inputEl = document.getElementById('wb-chief-name-input');
            if (inputEl) {
                setTimeout(function() { inputEl.focus(); }, 100);
            }
        },
        preConfirm: function() {
            var inputEl = document.getElementById('wb-chief-name-input');
            var chiefName = inputEl ? inputEl.value.trim() : '';

            if (!chiefName) {
                Swal.showValidationMessage('Silakan ketik atau pilih Nama Chief QC terlebih dahulu!');
                return false;
            }
            saveChiefNameLocallyWb(chiefName);
            return { chief_name: chiefName };
        }
    }).then(function(result) {
        if (!result || !result.isConfirmed || !result.value) return;

        var finalChiefName = result.value.chief_name;

        Swal.fire({
            title: 'Menyimpan ACC...',
            allowOutsideClick: false,
            didOpen: function() { Swal.showLoading(); }
        });

        var payload = new FormData();
        payload.append('session_id', currentSessionId);
        payload.append('action', 'approve');
        payload.append('chief_name', finalChiefName);

        fetch(window.OQC_CONFIG.baseUrl + "/modules/inspection/api/chief_approve.php", {
            method: 'POST',
            body: payload
        })
        .then(function(r) {
            return r.text().then(function(text) {
                try {
                    return JSON.parse(text);
                } catch(e) {
                    console.error('Server output:', text);
                    return { success: false, message: 'Respon server tidak valid JSON.' };
                }
            });
        })
        .then(function(data) {
            if (!data.success) {
                Swal.fire({ icon: 'error', title: 'Verifikasi Gagal', text: data.message || 'Terjadi kesalahan' });
                return;
            }
            Swal.fire({
                icon: 'success',
                title: 'Disetujui Chief QC',
                text: 'Sesi berhasil di-ACC atas nama ' + escapeHtml(finalChiefName) + '! Lembar Rejection Sheet kini siap dicetak.',
                timer: 1500,
                showConfirmButton: false
            });
            loadWorkbenchSessionData(currentSessionId);
        })
        .catch(function() {
            Swal.fire({ icon: 'error', title: 'Koneksi Error', text: 'Gagal menghubungi server' });
        });
    });
}

function workbenchChiefUnapprove() {
    if (!currentSessionId) return;
    Swal.fire({
        title: 'Batalkan ACC Chief QC?',
        text: 'Format cetak lembar penolakan akan dikembalikan ke tanda tangan basah manual.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Ya, Batalkan ACC',
        cancelButtonText: 'Batal'
    }).then(function(res) {
        if (!res.isConfirmed) return;

        Swal.fire({
            title: 'Membatalkan...',
            allowOutsideClick: false,
            didOpen: function() { Swal.showLoading(); }
        });

        var payload = new FormData();
        payload.append('session_id', currentSessionId);
        payload.append('action', 'unapprove');

        fetch(window.OQC_CONFIG.baseUrl + "/modules/inspection/api/chief_approve.php", {
            method: 'POST',
            body: payload
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) {
                Swal.fire({ icon: 'error', title: 'Gagal', text: data.message });
                return;
            }
            Swal.fire({
                icon: 'info',
                title: 'ACC Dibatalkan',
                text: 'Format cetak kembali ke tanda tangan basah manual.',
                timer: 1400,
                showConfirmButton: false
            });
            loadWorkbenchSessionData(currentSessionId);
        })
        .catch(function() {
            Swal.fire({ icon: 'error', title: 'Koneksi Error', text: 'Gagal menghubungi server' });
        });
    });
}

// High-Scale Debounced Master Parts Autocomplete
(function() {
    var partInput = document.getElementById('scan-part-code');
    var dataList = document.getElementById('modal-parts-list');
    if (!partInput || !dataList) return;

    var timer = null;
    partInput.addEventListener('input', function() {
        var q = (this.value || '').trim();
        if (q.length < 1) return;
        clearTimeout(timer);
        timer = setTimeout(function() {
            fetch(window.OQC_CONFIG.baseUrl + "/modules/inspection/session.php?action=search_parts&q=" + encodeURIComponent(q))
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (data && data.success && Array.isArray(data.parts)) {
                        dataList.innerHTML = '';
                        data.parts.forEach(function(p) {
                            var opt = document.createElement('option');
                            opt.value = p.part_code;
                            opt.textContent = p.part_code + (p.part_name ? (' - ' + p.part_name) : '');
                            dataList.appendChild(opt);
                        });
                    }
                })
                .catch(function(e) {});
        }, 200);
    });
})();

/**
 * ── SMART SCANNER FOCUS & ROUTING MANAGER ─────────────────────────
 * 1. Selalu memprioritaskan fokus ke kotak scan barcode aktif (Step 2, Re-inspeksi, atau Batch Modal).
 * 2. User tetap bebas mengetik manual di field-field lain (Part, Lot, Qty, Ref, dll) tanpa terganggu.
 * 3. Jika scanner ditembak (terdeteksi format barcode Z1/pipe atau saat kursor di luar field manual),
 *    data scan otomatis dialihkan ke kotak scan aktif dan diproses.
 * 4. Saat alert Swal selesai/ditutup atau background modal diklik, fokus otomatis kembali ke kotak scan.
 */
(function initSessionScanFocusManager() {
    // Daftar ID field manual yang TIDAK BOLEH diganggu saat user sedang mengetik manual
    var manualFieldIds = [
        'scan-part-code', 'scan-lot-number', 'scan-label-qty', 'scan-ref-number', 'scan-remarks',
        'reinsp-lot-number', 'reinsp-qty', 'reinsp-ref-number', 'reinsp-replace-remarks', 'reinsp-sort-notes',
        'batch-input-z1', 'batch-input-z2', 'batch-input-z3', 'batch-input-z4', 'batch-input-z5',
        'add-lot-number', 'add-lot-qty', 'add-lot-ref-number',
        'ss-lot-search-input'
    ];

    // Mendapatkan input scan yang saat ini sedang aktif & terlihat di layar
    function getActiveScanInput() {

        // 1. Re-inspeksi / Ganti Box Modal
        var reinspModal = document.getElementById('modal-lot-reinspection');
        if (reinspModal && reinspModal.style.display !== 'none' && !reinspModal.classList.contains('hidden')) {
            var reinspInput = document.getElementById('reinsp-scan-qr-raw');
            if (reinspInput && reinspInput.offsetParent !== null) return reinspInput;
        }

        // 2. Batch Replacement / Ganti Lot Container
        var batchContainer = document.getElementById('batch-replacement-form-container');
        if (batchContainer && !batchContainer.classList.contains('hidden') && batchContainer.style.display !== 'none') {
            var batchInput = document.getElementById('batch-qr-scan-input');
            if (batchInput && batchInput.offsetParent !== null) return batchInput;
        }

        // 3. Modal Utama Wizard Step 2 (Scan QR Code Label)
        var scanModal = document.getElementById('scan-modal-overlay');
        var step2 = document.getElementById('wizard-step-2-content');
        if (scanModal && !scanModal.classList.contains('hidden') && scanModal.style.display !== 'none') {
            if (step2 && !step2.classList.contains('hidden') && step2.style.display !== 'none') {
                var rawInput = document.getElementById('scan-qr-raw');
                if (rawInput && rawInput.offsetParent !== null) return rawInput;
            }
            // 4. Modal Utama Wizard Step 1 (Pilih Item Planning Aktif -> Cari Part Code)
            var step1 = document.getElementById('wizard-step-1-content');
            if (step1 && !step1.classList.contains('hidden') && step1.style.display !== 'none') {
                var planInput = document.getElementById('planning-search-input');
                if (planInput && planInput.offsetParent !== null) return planInput;
            }
        }

        return null;
    }

    // Cek apakah user sedang aktif mengetik di salah satu field manual
    function isUserEditingManualField() {
        var activeEl = document.activeElement;
        if (!activeEl) return false;
        if (manualFieldIds.indexOf(activeEl.id) !== -1) return true;
        // Juga jangan ganggu jika user sedang di input/textarea/select lain di luar scanner
        var scanInput = getActiveScanInput();
        if (scanInput && activeEl !== scanInput && (activeEl.tagName === 'INPUT' || activeEl.tagName === 'TEXTAREA' || activeEl.tagName === 'SELECT')) {
            return true;
        }
        return false;
    }

    // Mengembalikan fokus ke scan input yang aktif
    function refocusActiveScanner(force) {
        if (window.isSpectatorMode) return;
        var scanInput = getActiveScanInput();
        if (!scanInput) return;
        if (force || !isUserEditingManualField()) {
            try {
                scanInput.focus();
                if (scanInput.value && scanInput.value.length > 0) {
                    scanInput.select();
                }
            } catch (e) {}
        }
    }

    // Expose global helper agar fungsi lain bisa panggil jika perlu
    window.refocusActiveScanner = refocusActiveScanner;
    window.getActiveScanInput = getActiveScanInput;

    // 1. Hook SweetAlert2: Setiap kali Swal selesai (ditutup / timer habis), kembalikan fokus ke scan input
    if (typeof Swal !== 'undefined' && Swal.fire) {
        var origSwalFire = Swal.fire;
        Swal.fire = function() {
            var promise = origSwalFire.apply(this, arguments);
            if (promise && typeof promise.then === 'function') {
                promise.then(function() {
                    setTimeout(function() { refocusActiveScanner(true); }, 80);
                });
            }
            return promise;
        };
    }

    // 2. Klik pada area kosong modal / tabel -> otomatis kembalikan fokus ke scan input
    document.addEventListener('click', function(e) {
        if (window.isSpectatorMode) return;
        var scanInput = getActiveScanInput();
        if (!scanInput) return;
        var target = e.target;
        // Jika user klik elemen interaktif (input, tombol, link, select, checkbox, dsb), JANGAN rebut fokus
        if (target && target.closest('a, button, input, select, textarea, label, [role="button"], tr[onclick], details, summary')) {
            return;
        }
        // User klik background / area kosong modal -> fokuskan kembali ke input scan
        refocusActiveScanner(false);
    });

    // 3. Global keydown: Shortcut tombol keyboard Zero-Mouse & Focus Manager
    document.addEventListener('keydown', function(e) {
        if (window.isSpectatorMode) return;

        // Alt + M: Toggle Auto-Start vs Multi-Label Batch Mode (dapat dipanggil kapan saja)
        if (e.altKey && (e.key === 'm' || e.key === 'M')) {
            e.preventDefault();
            toggleAutoStartMode();
            return;
        }

        // ── Cek jika ada SweetAlert2 modal terbuka (termasuk Konfirmasi PASSED/Hapus) ──
        var isSwalOpen = (typeof Swal !== 'undefined' && typeof Swal.isVisible === 'function' && Swal.isVisible());
        if (isSwalOpen) {
            // Enter -> Otomatis Klik Tombol Confirm ("Ya, Selesai Inspeksi (PASSED)")
            if (e.key === 'Enter' && !e.shiftKey && !e.ctrlKey && !e.altKey) {
                e.preventDefault();
                if (typeof Swal.clickConfirm === 'function') {
                    Swal.clickConfirm();
                }
                return;
            }
            // Escape -> Batal / Tutup Alert
            if (e.key === 'Escape') {
                e.preventDefault();
                if (typeof Swal.clickCancel === 'function') {
                    Swal.clickCancel();
                } else if (typeof Swal.close === 'function') {
                    Swal.close();
                }
                return;
            }
            return;
        }

        // ── KONDISI KHUSUS: MODAL CATAT NG PER LOT SEDANG TERBUKA ──
        var defectModal = document.getElementById('modal-lot-defect');
        var isDefectModalOpen = defectModal && (defectModal.style.display === 'flex' || defectModal.style.display === 'block');
        if (isDefectModalOpen) {
            // Jika dropdown list combobox sedang terbuka, biarkan navigateLotDefectDropdown menanganinya
            var openDropdown = defectModal.querySelector('.lot-defect-dropdown-list[style*="display: block"]');
            
            // Enter -> Kirim / Submit Temuan NG (REJECTED)
            if (e.key === 'Enter' && !e.shiftKey && !e.ctrlKey && !e.altKey) {
                if (!openDropdown) {
                    e.preventDefault();
                    submitLotDefectForm();
                    return;
                }
            }
            // Escape -> Batal / Tutup Modal NG
            if (e.key === 'Escape') {
                e.preventDefault();
                closeLotNgModal();
                return;
            }
            // Jangan izinkan shortcut workbench lain (seperti Enter PASSED) tembus saat modal NG terbuka!
            return;
        }

        var scanInput = getActiveScanInput();

        // ── KONDISI A: TIDAK ADA MODAL SCAN / REINSPEKSI AKTIF (USER ADA DI WORKBENCH) ──
        if (!scanInput) {
            var activeEl = document.activeElement;
            var isEditing = activeEl && (
                (manualFieldIds && manualFieldIds.indexOf(activeEl.id) !== -1) ||
                activeEl.tagName === 'INPUT' ||
                activeEl.tagName === 'TEXTAREA' ||
                activeEl.tagName === 'SELECT' ||
                activeEl.isContentEditable
            );

            if (!isEditing) {
                var activeLot = getActiveWorkbenchLot();

                // 1. Enter: Selesai Inspeksi PASSED (Lolos 0 Defect)
                if (e.key === 'Enter' && !e.shiftKey && !e.ctrlKey && !e.altKey) {
                    if (activeLot && (activeLot.lot_result === 'in_progress' || !activeLot.lot_result || activeLot.lot_result === 'pending')) {
                        e.preventDefault();
                        confirmLotPassed(activeLot.id, activeLot.lot_number, activeLot.ref_number || '', parseInt(activeLot.sample_size) || 0);
                        return;
                    }
                }

                // 2. Shift + Enter: Catat NG / Buka Modal NG
                if (e.key === 'Enter' && e.shiftKey && !e.ctrlKey && !e.altKey) {
                    if (activeLot) {
                        e.preventDefault();
                        openLotNgModal(activeLot.id, activeLot.lot_number, activeLot.ref_number || '', parseInt(activeLot.qty) || 0, parseInt(activeLot.sample_size) || 0, parseInt(activeLot.reject_number) || 0);
                        return;
                    }
                }

                // 3. N atau F4: Catat NG
                if ((e.key === 'n' || e.key === 'N' || e.key === 'F4') && !e.ctrlKey && !e.altKey) {
                    if (activeLot) {
                        e.preventDefault();
                        openLotNgModal(activeLot.id, activeLot.lot_number, activeLot.ref_number || '', parseInt(activeLot.qty) || 0, parseInt(activeLot.sample_size) || 0, parseInt(activeLot.reject_number) || 0);
                        return;
                    }
                }

                // 4. Alt + X: Batal Pakai / Hapus Box dari Sesi
                if (e.altKey && (e.key === 'x' || e.key === 'X')) {
                    if (activeLot && activeLot.lot_result === 'in_progress') {
                        e.preventDefault();
                        confirmRemoveLot(activeLot.id, activeLot._origBoxNum || 1, activeLot.lot_number, activeLot.ref_number || '', parseInt(activeLot.qty) || 0);
                        return;
                    }
                }
            }
            return;
        }

        // ── KONDISI B: MODAL SCAN AKTIF ──
        var activeEl = document.activeElement;
        var isAnyInputActive = activeEl && (
            activeEl.tagName === 'INPUT' ||
            activeEl.tagName === 'TEXTAREA' ||
            activeEl.tagName === 'SELECT' ||
            activeEl.isContentEditable
        );

        // Escape: refocus scan input
        if (e.key === 'Escape') {
            refocusActiveScanner(true);
            return;
        }

        // Jika tidak ada input yang aktif dan tombol karakter ditekan (scanner mulai menembak)
        if (!isAnyInputActive && !e.ctrlKey && !e.altKey && !e.metaKey && e.key.length === 1) {
            scanInput.focus();
        }
    });

    // 4. Scanner Guard pada Manual Fields:
    // Jika kursor user sedang berada di field manual (misal Lot No atau Qty), lalu scanner ditembakkan
    // (mengandung pipe '|' atau diawali format 'Z1..'), jangan biarkan field manual rusak.
    // Segera alihkan teks scan ke scan input aktif dan proses!
    function setupManualInputScannerGuards() {
        var groupMappings = [
            {
                inputs: ['scan-part-code', 'scan-lot-number', 'scan-label-qty', 'scan-ref-number', 'scan-remarks'],
                targetId: 'scan-qr-raw',
                processor: function(val) { if (typeof parseBarcodeQRInput === 'function') parseBarcodeQRInput(val, true); }
            },
            {
                inputs: ['reinsp-lot-number', 'reinsp-qty', 'reinsp-ref-number', 'reinsp-replace-remarks'],
                targetId: 'reinsp-scan-qr-raw',
                processor: function(val, el) { if (typeof handleLotReinspQrInput === 'function') handleLotReinspQrInput(el, true); }
            },
            {
                inputs: ['batch-input-z1', 'batch-input-z2', 'batch-input-z3', 'batch-input-z4', 'batch-input-z5'],
                targetId: 'batch-qr-scan-input',
                processor: function(val, el) { if (typeof handleSingleBatchQrInput === 'function') handleSingleBatchQrInput(el, true); }
            }
        ];

        groupMappings.forEach(function(grp) {
            grp.inputs.forEach(function(inpId) {
                var manualEl = document.getElementById(inpId);
                if (!manualEl) return;

                manualEl.addEventListener('input', function() {
                    var val = manualEl.value;
                    // Deteksi ciri khas scan barcode QR (ada pemisah pipe '|' atau awalan 'Z1' panjang)
                    if (val && (val.indexOf('|') !== -1 || (val.indexOf('Z1') === 0 && val.length > 5))) {
                        // Bersihkan input manual ini
                        manualEl.value = '';
                        var targetEl = document.getElementById(grp.targetId);
                        // Pindahkan ke target scan input
                        if (targetEl) {
                            targetEl.value = val;
                            targetEl.focus();
                            grp.processor(val, targetEl);
                        }
                    }
                });
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupManualInputScannerGuards);
    } else {
        setupManualInputScannerGuards();
    }

    // Enter key in planning-search-input: selects the top matching planning card automatically
    var planSearchEl = document.getElementById('planning-search-input');
    if (planSearchEl) {
        planSearchEl.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                filterPlanningItems();
                var container = document.getElementById('planning-cards-container');
                if (container) {
                    var cards = container.querySelectorAll('.planning-card');
                    for (var i = 0; i < cards.length; i++) {
                        if (cards[i].style.display !== 'none') {
                            cards[i].click();
                            break;
                        }
                    }
                }
            }
        });
    }
})();

if (window.OQC_CONFIG && window.OQC_CONFIG.isReadOnlyView) {
/**
 * Non-Disruptive Spectator Mode Client Locking
 * Disables all action triggers, sample buttons, lot scanners, and form inputs
 * while preserving drawing views and progress monitoring.
 */
window.isSpectatorMode = true;
document.addEventListener('DOMContentLoaded', function() {
    var actionableSelectors = [
        '#btn-pass-sample', '#btn-ng-sample', '#btn-finish-inspection',
        '#btn-scan-lot', '#btn-add-lot', '#btn-manual-lot', '#btn-confirm-finish',
        'button[data-action="pass"]', 'button[data-action="fail"]',
        '.btn-lot-action', '.btn-defect-action'
    ];
    actionableSelectors.forEach(function(sel) {
        document.querySelectorAll(sel).forEach(function(el) {
            el.classList.add('spectator-locked');
            el.setAttribute('disabled', 'disabled');
            el.setAttribute('title', 'Terkunci dalam Mode Pantau (Read-Only)');
        });
    });

    // Disable scan input field
    var scanInput = document.getElementById('barcode-input') || document.querySelector('input[name="barcode"]');
    if (scanInput) {
        scanInput.classList.add('spectator-locked');
        scanInput.setAttribute('disabled', 'disabled');
        scanInput.setAttribute('placeholder', 'Mode Pantau Aktif (Input Dikunci)');
    }
});
}

