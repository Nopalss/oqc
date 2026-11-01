function openLotReinspectionModal(lotId, lotNumber, refNumber, qty) {
    var modal = document.getElementById('modal-lot-reinspection');
    if (!modal) return;

    document.getElementById('modal-reinsp-lot-id').value = lotId || '';
    
    // Determine part code from session
    var effectivePartCode = '';
    if (typeof activeSessionData !== 'undefined' && activeSessionData && activeSessionData.part_code) {
        effectivePartCode = activeSessionData.part_code;
    } else if (typeof currentSessionPartCode !== 'undefined' && currentSessionPartCode) {
        effectivePartCode = currentSessionPartCode;
    } else if (typeof currentSessionObj !== 'undefined' && currentSessionObj && currentSessionObj.part_code) {
        effectivePartCode = currentSessionObj.part_code;
    }
    
    document.getElementById('modal-reinsp-part-code').value = effectivePartCode;
    var partCodeInput = document.getElementById('reinsp-part-code');
    if (partCodeInput) partCodeInput.value = effectivePartCode;

    var targetLotEl = document.getElementById('modal-reinsp-target-lot');
    if (targetLotEl) targetLotEl.value = (lotNumber || '').trim();
    var targetRefEl = document.getElementById('modal-reinsp-target-ref');
    if (targetRefEl) targetRefEl.value = (refNumber || '').trim();

    var subtitle = document.getElementById('modal-reinsp-lot-subtitle');
    if (subtitle) {
        var subText = 'Lot #' + (lotNumber || '-');
        if (refNumber) subText += ' | Ref: ' + refNumber;
        if (qty) subText += ' | Qty: ' + Number(qty).toLocaleString() + ' pcs';
        subtitle.textContent = subText;
    }

    // Reset inputs
    var qrInput = document.getElementById('reinsp-scan-qr-raw');
    if (qrInput) qrInput.value = '';
    var lotNumInput = document.getElementById('reinsp-lot-number');
    if (lotNumInput) lotNumInput.value = '';
    var qtyInput = document.getElementById('reinsp-qty');
    if (qtyInput) qtyInput.value = '';
    var refInput = document.getElementById('reinsp-ref-number');
    if (refInput) refInput.value = '';
    var remInput = document.getElementById('reinsp-replace-remarks');
    if (remInput) remInput.value = '';
    var sortNotes = document.getElementById('reinsp-sort-notes');
    if (sortNotes) sortNotes.value = '';

    switchLotReinspTab('replace');

    modal.style.display = 'flex';

    setTimeout(function() {
        if (qrInput) qrInput.focus();
    }, 100);
}

function closeLotReinspectionModal() {
    var modal = document.getElementById('modal-lot-reinspection');
    if (modal) modal.style.display = 'none';
}

function switchLotReinspTab(tabName) {
    currentLotReinspTab = tabName;
    var tabRep = document.getElementById('tab-reinsp-replace');
    var tabSort = document.getElementById('tab-reinsp-sort');
    var contentRep = document.getElementById('content-reinsp-replace');
    var contentSort = document.getElementById('content-reinsp-sort');
    var btnSubmit = document.getElementById('btn-submit-lot-reinsp');

    if (tabName === 'replace') {
        if (tabRep) {
            tabRep.style.borderBottom = '2.5px solid #2563eb';
            tabRep.style.backgroundColor = '#ffffff';
            tabRep.style.color = '#2563eb';
            tabRep.style.fontWeight = '800';
        }
        if (tabSort) {
            tabSort.style.borderBottom = '2.5px solid transparent';
            tabSort.style.backgroundColor = 'transparent';
            tabSort.style.color = '#64748b';
            tabSort.style.fontWeight = '700';
        }
        if (contentRep) contentRep.style.display = 'block';
        if (contentSort) contentSort.style.display = 'none';
        if (btnSubmit) {
            btnSubmit.innerHTML = 'Konfirmasi & Muat Box Pengganti';
            btnSubmit.style.backgroundColor = '#2563eb';
        }
        var qrInput = document.getElementById('reinsp-scan-qr-raw');
        if (qrInput) qrInput.focus();
    } else {
        if (tabSort) {
            tabSort.style.borderBottom = '2.5px solid #d97706';
            tabSort.style.backgroundColor = '#ffffff';
            tabSort.style.color = '#d97706';
            tabSort.style.fontWeight = '800';
        }
        if (tabRep) {
            tabRep.style.borderBottom = '2.5px solid transparent';
            tabRep.style.backgroundColor = 'transparent';
            tabRep.style.color = '#64748b';
            tabRep.style.fontWeight = '700';
        }
        if (contentRep) contentRep.style.display = 'none';
        if (contentSort) contentSort.style.display = 'block';
        if (btnSubmit) {
            btnSubmit.innerHTML = 'Mulai Uji Ulang Box Ini';
            btnSubmit.style.backgroundColor = '#d97706';
        }
        var sortNotes = document.getElementById('reinsp-sort-notes');
        if (sortNotes) sortNotes.focus();
    }
}

function handleLotReinspQrInput(inputEl, force) {
    if (!inputEl) return;
    var val = inputEl.value;
    if (!val) return;

    var parsed = parseBarcodeQR(val);

    var expectedPartCode = (document.getElementById('modal-reinsp-part-code') ? document.getElementById('modal-reinsp-part-code').value.trim() : '') || (typeof activeSessionData !== 'undefined' && activeSessionData ? activeSessionData.part_code : '') || (typeof currentSessionPartCode !== 'undefined' ? currentSessionPartCode : '');

    // Early Part Code Mismatch check
    if (parsed.Z1 && expectedPartCode && parsed.Z1.toUpperCase() !== expectedPartCode.toUpperCase()) {
        if (force || val.includes('\n') || val.includes('\r')) {
            Swal.fire({
                icon: 'error',
                title: '⚠️ PART CODE BOX PENGGANTI TIDAK COCOK!',
                html: '<div style="font-size: 12px; text-align: left; line-height: 1.6; color: #334155;">' +
                      'Part Code pada label box (<b style="color:#be123c;">"' + escapeHtml(parsed.Z1) + '"</b>) tidak cocok dengan Part Sesi ini (<b style="color:#0284c7;">"' + escapeHtml(expectedPartCode) + '"</b>)!<br><br>' +
                      '<b style="color:#e11d48;">Pastikan Anda men-scan label box pengganti yang sesuai dengan part sesi!</b>' +
                      '</div>'
            });
            // Rollback & bersihkan field
            if (document.getElementById('reinsp-part-code')) document.getElementById('reinsp-part-code').value = expectedPartCode;
            if (document.getElementById('reinsp-lot-number')) document.getElementById('reinsp-lot-number').value = '';
            if (document.getElementById('reinsp-qty')) document.getElementById('reinsp-qty').value = '';
            if (document.getElementById('reinsp-ref-number')) document.getElementById('reinsp-ref-number').value = '';
            inputEl.value = '';
            setTimeout(function() { if (inputEl) inputEl.focus(); }, 100);
        }
        return;
    }

    if (parsed.Z1 && document.getElementById('reinsp-part-code')) {
        document.getElementById('reinsp-part-code').value = parsed.Z1;
    }
    if (parsed.Z2 && document.getElementById('reinsp-lot-number')) {
        document.getElementById('reinsp-lot-number').value = parsed.Z2;
    }
    if (parsed.Z3 && parsed.Z3 > 0 && document.getElementById('reinsp-qty')) {
        document.getElementById('reinsp-qty').value = parsed.Z3;
    }
    if (parsed.Z5 && document.getElementById('reinsp-ref-number')) {
        document.getElementById('reinsp-ref-number').value = parsed.Z5;
    }

    var hasNewline = (val.includes('\n') || val.includes('\r'));
    if (force || hasNewline) {
        inputEl.value = '';

        // ══════════════════════════════════════════════════════════════════════
        // SMART DETEKSI: KASUS LABEL SAMA PERSIS DENGAN LOT YANG SEDANG DIREINSPEKSI
        // Jika Lot No dan Ref No yang discan sama dengan lot yang sedang NG:
        // Ini adalah alur SORTIR / GANTI PART NG SAJA (Box fisiknya sama)!
        // ══════════════════════════════════════════════════════════════════════
        var targetLot = (document.getElementById('modal-reinsp-target-lot') ? document.getElementById('modal-reinsp-target-lot').value.trim() : '').toUpperCase();
        var targetRef = (document.getElementById('modal-reinsp-target-ref') ? document.getElementById('modal-reinsp-target-ref').value.trim() : '').toUpperCase();
        var scannedLot = (parsed.Z2 || '').trim().toUpperCase();
        var scannedRef = (parsed.Z5 || '').trim().toUpperCase();

        var isSameLotBox = (scannedLot && scannedLot === targetLot) && (!targetRef || scannedRef === targetRef);

        if (isSameLotBox) {
            // Otomatis beralih ke tab Sortir (Ganti Part NG Saja)
            switchLotReinspTab('sort');

            Swal.fire({
                title: 'BOX HASIL SORTIR TERDETEKSI',
                html: '<div style="font-size: 12px; text-align: left; line-height: 1.6; color: #334155;">' +
                      '<p style="margin-bottom: 8px; color: #047857; font-weight: 700;">✅ Label yang Anda scan sama dengan box yang sedang di-reinspeksi (Lot #' + escapeHtml(parsed.Z2) + (parsed.Z5 ? ' | Ref #' + escapeHtml(parsed.Z5) : '') + ').</p>' +
                      '<div style="background-color: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 10px; font-size: 11.5px; color: #166534; line-height: 1.5;">' +
                      'Sistem mengarahkan ke alur <b>Ganti Part NG Saja (Sortir)</b>.<br>' +
                      'Temuan NG pada box ini akan direset ke arsip sortir dan status box dikembalikan ke <b>Sedang Diinspeksi</b> untuk diuji ulang sampelnya.' +
                      '</div>' +
                      '</div>',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#059669',
                cancelButtonColor: '#64748b',
                confirmButtonText: '✅ Ya, Mulai Uji Ulang Box Ini',
                cancelButtonText: 'Batal'
            }).then(function(res) {
                if (res.isConfirmed) {
                    executeLotReinspectionSubmit(true); // skip redundant confirm dialog
                } else {
                    switchLotReinspTab('replace');
                    if (inputEl) inputEl.focus();
                }
            });
            return;
        }

        // Jika label berbeda (Box Pengganti Baru): Lanjutkan ke validasi check_did_only & API replace_session_lot
        if (parsed.Z1 && parsed.Z2) {
            var reinspCheckUrl = (window.OQC_CONFIG.baseUrl || '') + '/modules/inspection/scan_validate.php?mode=check_did_only' +
                '&part_code=' + encodeURIComponent(parsed.Z1) +
                '&lot_number=' + encodeURIComponent(parsed.Z2) +
                '&ref_number=' + encodeURIComponent(parsed.Z5 || '') +
                '&current_session_id=' + encodeURIComponent(typeof currentSessionId !== 'undefined' ? currentSessionId : 0);
            
            fetch(reinspCheckUrl)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.success) {
                        // Skenario 1: Duplikat Label yang sudah PASSED di Kanban lain / Safety Stock habis
                        if (data.error_type === 'already_passed_duplicate') {
                            Swal.fire({
                                icon: 'error',
                                title: '⛔ BOX SUDAH PERNAH LOLOS (PASSED)!',
                                html: '<div style="font-size: 12px; text-align: left; line-height: 1.6; color: #334155;">' +
                                      '<b style="color: #be123c;">' + escapeHtml(data.message || 'Box ini sudah pernah diinspeksi & dinyatakan PASSED pada sesi lain.') + '</b><br><br>' +
                                      '<p style="font-size: 11px; color: #64748b; background-color: #f8fafc; padding: 8px 10px; border-radius: 6px; border: 1px solid #e2e8f0;">' +
                                      'Label box yang sudah Passed tidak dapat digunakan kembali untuk menghindari data ganda pada Kanban.' +
                                      '</p>' +
                                      '</div>',
                                confirmButtonColor: '#ef4444'
                            });
                            if (document.getElementById('reinsp-lot-number')) document.getElementById('reinsp-lot-number').value = '';
                            if (document.getElementById('reinsp-qty')) document.getElementById('reinsp-qty').value = '';
                            if (document.getElementById('reinsp-ref-number')) document.getElementById('reinsp-ref-number').value = '';
                            if (inputEl) inputEl.focus();
                            return;
                        }

                        if (data.error_type === 'safety_stock_exhausted') {
                            Swal.fire({
                                icon: 'error',
                                title: '⛔ SAFETY STOCK SUDAH HABIS!',
                                html: '<div style="font-size: 12px; text-align: left; line-height: 1.6; color: #334155;">' +
                                      '<b style="color: #be123c;">' + escapeHtml(data.message || 'Safety Stock sudah habis terpakai.') + '</b><br><br>' +
                                      '<p style="font-size: 11px; color: #64748b; background-color: #f8fafc; padding: 8px 10px; border-radius: 6px; border: 1px solid #e2e8f0;">' +
                                      'Box ini sudah tidak memiliki sisa stok yang tersedia di gudang Safety Stock.' +
                                      '</p>' +
                                      '</div>',
                                confirmButtonColor: '#ef4444'
                            });
                            if (document.getElementById('reinsp-lot-number')) document.getElementById('reinsp-lot-number').value = '';
                            if (document.getElementById('reinsp-qty')) document.getElementById('reinsp-qty').value = '';
                            if (document.getElementById('reinsp-ref-number')) document.getElementById('reinsp-ref-number').value = '';
                            if (inputEl) inputEl.focus();
                            return;
                        }

                        // Error umum lainnya (DID missing / DID NG / part mismatch)
                        Swal.fire({
                            icon: 'error',
                            title: 'Pemeriksaan Box Pengganti Ditolak',
                            html: '<div style="font-size: 12px; text-align: left; line-height: 1.6; color: #334155;">' +
                                  escapeHtml(data.message || 'Gagal memvalidasi box pengganti.') +
                                  '</div>',
                            confirmButtonColor: '#ef4444'
                        });
                        if (document.getElementById('reinsp-lot-number')) document.getElementById('reinsp-lot-number').value = '';
                        if (document.getElementById('reinsp-qty')) document.getElementById('reinsp-qty').value = '';
                        if (document.getElementById('reinsp-ref-number')) document.getElementById('reinsp-ref-number').value = '';
                        if (inputEl) inputEl.focus();
                        return;
                    }

                    // Skenario 2: Safety Stock Terdeteksi
                    if (data.is_safety_stock && data.ss_lot && parseInt(data.ss_lot.available_qty, 10) > 0) {
                        var realStockQty = parseInt(data.ss_lot.available_qty, 10);
                        var prevQty = parseInt(parsed.Z3, 10) || 0;
                        if (document.getElementById('reinsp-qty')) {
                            document.getElementById('reinsp-qty').value = realStockQty;
                        }
                        if (prevQty !== realStockQty) {
                            Swal.fire({
                                toast: true,
                                position: 'top-end',
                                icon: 'info',
                                title: 'Safety Stock Terdeteksi',
                                text: 'Qty disesuaikan ke sisa stok: ' + realStockQty + ' pcs (Label: ' + prevQty + ' pcs)',
                                timer: 3000,
                                showConfirmButton: false
                            });
                        }
                    }

                    // Skenario 3: Box Tercatat Pernah REJECTED / BEKAS DEFECT
                    if (data.was_rejected_before && data.rejected_info) {
                        var info = data.rejected_info;
                        var originText = (info.inspection_type === 'safety_stock') ? 'Safety Stock Gudang' : ('Sesi Kanban #' + info.session_id + (info.kanban_no && info.kanban_no !== '-' ? ' (' + info.kanban_no + ')' : ''));
                        var refNoDisplay = escapeHtml(parsed.Z5 || info.ref_number || '-');
                        var lotNoDisplay = escapeHtml(parsed.Z2 || info.lot_number || '-');
                        var qtyDisplay = (parseInt(parsed.Z3 || info.qty || 0, 10) || 0) + ' pcs';

                        var defectsHtml = '';
                        if (Array.isArray(info.ng_defects) && info.ng_defects.length > 0) {
                            defectsHtml = info.ng_defects.map(function(d) {
                                var dName = escapeHtml(d.defect_name || 'Defect');
                                var dCode = d.defect_code ? ('[' + escapeHtml(d.defect_code) + '] ') : '';
                                var dQty = (parseInt(d.qty_ng, 10) || 1) + ' pcs';
                                return '<div style="display: flex; align-items: center; justify-content: space-between; padding: 4px 8px; background-color: #fff1f2; border: 1px solid #fecdd3; border-radius: 6px; margin-top: 4px; font-size: 11px;">' +
                                       '  <span style="font-weight: 700; color: #9f1239;">• ' + dCode + dName + '</span>' +
                                       '  <span style="font-weight: 800; color: #be123c; font-family: monospace;">' + dQty + '</span>' +
                                       '</div>';
                            }).join('');
                        } else if (info.remarks && info.remarks !== '-') {
                            defectsHtml = '<div style="padding: 6px 8px; background-color: #fff1f2; border: 1px solid #fecdd3; border-radius: 6px; margin-top: 4px; font-size: 11px; color: #9f1239; font-weight: 600;">' +
                                          '  Catatan: ' + escapeHtml(info.remarks) +
                                          '</div>';
                        } else {
                            defectsHtml = '<div style="font-size: 11px; color: #64748b; font-style: italic;">Rincian defect spesifik tidak tercatat</div>';
                        }

                        var alertHtml = 
                        '<div style="font-size: 12px; text-align: left; line-height: 1.5; color: #334155;">' +
                        '  <p style="margin-bottom: 8px; color: #e11d48; font-weight: 700;">⚠️ PERINGATAN: Box pengganti ini sebelumnya tercatat REJECTED / BEKAS DEFECT!</p>' +
                        '  <div style="background-color: #f8fafc; border: 1.5px solid #cbd5e1; border-radius: 10px; padding: 10px; margin-bottom: 10px;">' +
                        '    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; margin-bottom: 6px; font-size: 11px;">' +
                        '      <div><span style="color: #64748b;">Lot No:</span><br><b>' + lotNoDisplay + '</b></div>' +
                        '      <div><span style="color: #64748b;">Ref (Box):</span><br><b>' + refNoDisplay + '</b></div>' +
                        '      <div><span style="color: #64748b;">Qty:</span><br><b>' + qtyDisplay + '</b></div>' +
                        '    </div>' +
                        '    <div style="font-size: 10.5px; color: #475569; border-top: 1px dashed #cbd5e1; padding-top: 6px;">' +
                        '      Ditolak pada: <b>' + escapeHtml(originText) + '</b> oleh <b>' + escapeHtml(info.inspector_name) + '</b> (' + escapeHtml(info.closed_at) + ')' +
                        '    </div>' +
                        '  </div>' +
                        '  <label style="display: block; font-size: 10.5px; font-weight: 700; color: #64748b; margin-bottom: 4px;">RINCIAN DEFECT SEBELUMNYA:</label>' +
                        '  <div style="max-height: 120px; overflow-y: auto; margin-bottom: 12px;">' + defectsHtml + '</div>' +
                        '  <div style="background-color: #fef2f2; border: 1px solid #fecdd3; border-radius: 8px; padding: 8px 10px; font-size: 11.5px; color: #991b1b; font-weight: 600;">' +
                        '    Apakah box ini sudah diperbaiki / disortir dan siap untuk di-reinspeksi?' +
                        '  </div>' +
                        '</div>';

                        Swal.fire({
                            title: 'BOX BEKAS DEFECT TERDETEKSI',
                            html: alertHtml,
                            icon: 'warning',
                            showCancelButton: true,
                            confirmButtonColor: '#2563eb',
                            cancelButtonColor: '#64748b',
                            confirmButtonText: '✅ Ya, Sudah Diperbaiki & Muat',
                            cancelButtonText: 'Batal'
                        }).then(function(result) {
                            if (result.isConfirmed) {
                                executeLotReinspectionSubmit();
                            } else {
                                if (document.getElementById('reinsp-lot-number')) document.getElementById('reinsp-lot-number').value = '';
                                if (document.getElementById('reinsp-qty')) document.getElementById('reinsp-qty').value = '';
                                if (document.getElementById('reinsp-ref-number')) document.getElementById('reinsp-ref-number').value = '';
                                if (inputEl) inputEl.focus();
                            }
                        });
                        return;
                    }

                    // Skenario 4: Lot Normal & Valid -> Langsung Auto-Submit!
                    executeLotReinspectionSubmit();
                })
                .catch(function(e) {
                    console.warn('Reinspection validation fetch error', e);
                    // Jika fetch gagal koneksi, tetap jalankan executeLotReinspectionSubmit agar ditangani backend
                    executeLotReinspectionSubmit();
                });
        }
    }
}

function executeLotReinspectionSubmit(skipConfirm) {
    var lotId = parseInt(document.getElementById('modal-reinsp-lot-id').value) || 0;
    if (!lotId) {
        Swal.fire({ icon: 'error', title: 'Data Tidak Valid', text: 'ID Lot tidak ditemukan.' });
        return;
    }

    if (currentLotReinspTab === 'replace') {
        var partCode = (document.getElementById('reinsp-part-code').value || '').trim();
        var lotNumber = (document.getElementById('reinsp-lot-number').value || '').trim();
        var qty = parseInt(document.getElementById('reinsp-qty').value) || 0;
        var refNumber = (document.getElementById('reinsp-ref-number').value || '').trim();
        var remarks = (document.getElementById('reinsp-replace-remarks').value || '').trim();

        if (!lotNumber) {
            Swal.fire({ icon: 'warning', title: 'Lot Number Pengganti Wajib Diisi', text: 'Silakan scan QR label atau masukkan Lot Number pengganti.' });
            return;
        }

        if (qty <= 0) {
            Swal.fire({ icon: 'warning', title: 'Qty Box Wajib Diisi', text: 'Qty Box pengganti harus lebih besar dari 0.' });
            return;
        }

        Swal.fire({
            title: 'Memproses Box Pengganti...',
            text: 'Memverifikasi status Cek Dimensi (DID) dan mendaftarkan box...',
            allowOutsideClick: false,
            didOpen: function() { Swal.showLoading(); }
        });

        fetch(window.OQC_CONFIG.baseUrl + "/modules/inspection/api/replace_session_lot.php", {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                session_lot_id: lotId,
                new_z1: partCode,
                new_z2: lotNumber,
                new_z3: qty,
                new_z5: refNumber,
                remarks: remarks
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) {
                Swal.fire({
                    icon: 'error',
                    title: 'Gagal Memuat Box Pengganti',
                    html: '<div style="font-size: 12px; text-align: left; line-height: 1.6; color: #334155;">' +
                          escapeHtml(data.message || 'Terjadi kesalahan sistem') +
                          '</div>'
                });
                return;
            }

            closeLotReinspectionModal();
            Swal.fire({
                icon: 'success',
                title: 'Box Pengganti Siap',
                text: data.message || 'Box pengganti berhasil didaftarkan.',
                timer: 1600,
                showConfirmButton: false
            });
            loadWorkbenchSessionData(currentSessionId);
        })
        .catch(function(err) {
            console.error('Re-inspection replace error:', err);
            Swal.fire({ icon: 'error', title: 'Koneksi Error', text: 'Gagal menghubungi server.' });
        });

    } else if (currentLotReinspTab === 'sort') {
        var sortNotes = (document.getElementById('reinsp-sort-notes').value || '').trim();

        var doSubmitSort = function() {
            Swal.fire({
                title: 'Memproses...',
                allowOutsideClick: false,
                didOpen: function() { Swal.showLoading(); }
            });

            fetch(window.OQC_CONFIG.baseUrl + "/modules/inspection/api/recheck_sorted_lot.php", {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    session_lot_id: lotId,
                    notes: sortNotes
                })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) {
                    Swal.fire({ icon: 'error', title: 'Gagal', text: data.message || 'Terjadi kesalahan' });
                    return;
                }

                closeLotReinspectionModal();
                Swal.fire({
                    icon: 'success',
                    title: 'Box Siap Diuji Ulang',
                    text: data.message || 'Status box berhasil dikembalikan ke pemeriksaan.',
                    timer: 1500,
                    showConfirmButton: false
                });
                loadWorkbenchSessionData(currentSessionId);
            })
            .catch(function(err) {
                console.error('Recheck sorted error:', err);
                Swal.fire({ icon: 'error', title: 'Koneksi Error', text: 'Gagal menghubungi server.' });
            });
        };

        if (skipConfirm) {
            doSubmitSort();
        } else {
            Swal.fire({
                title: 'Mulai Uji Ulang Box?',
                html: '<div style="font-size: 12px; text-align: left; line-height: 1.5; color: #334155;">' +
                      'Pastikan semua part cacat/NG dalam box ini sudah disortir dan ditukar dengan part bagus.<br><br>' +
                      'Temuan NG box ini akan direset ke 0 dan sampel fisik wajib diperiksa kembali.' +
                      '</div>',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#d97706',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Ya, Mulai Uji Ulang',
                cancelButtonText: 'Batal'
            }).then(function(res) {
                if (res.isConfirmed) {
                    doSubmitSort();
                }
            });
        }
    }
}

function escapeHtml(text) {
    if (!text) return '';
    return String(text).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

function getShiftLabel(dateStr) {
    if (!dateStr) return '';
    var h = new Date(dateStr).getHours();
    if (h >= 7 && h < 15)  return 'Shift 1';
    if (h >= 15 && h < 23) return 'Shift 2';
    return 'Shift 3';
}

function formatDateWib(dateStr) {
    if (!dateStr || dateStr === '-') return '-';
    var months = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
    try {
        var d = new Date(dateStr);
        if (isNaN(d.getTime())) return dateStr;
        return d.getDate() + ' ' + months[d.getMonth()] + ' ' + d.getFullYear() +
               ', ' + String(d.getHours()).padStart(2,'0') + ':' + String(d.getMinutes()).padStart(2,'0') + ' WIB';
    } catch(e) { return dateStr; }
}

// ═══════════════════════════════════════════════════════════════════════════
// BATCH RE-INSPECTION MODAL HANDLERS
// ═══════════════════════════════════════════════════════════════════════════
var currentSessionObj = window.OQC_CONFIG.sessionData;
var currentNgLots = [];
var currentSessionLots = [];
var currentSessionPartCode = window.OQC_CONFIG.sessionPartCode || '';
var reinspectApiUrl = (window.OQC_CONFIG.baseUrl || '') + "/modules/inspection/api/reinspect_lot.php";

var batchSelectedStrategy = 'rescan'; // 'rescan' or 'replace'
var batchSelectedSubAction = 'rescan_restart'; // 'rescan_restart', 'rescan_continue', 'replace_ng_only', 'replace_all_lots'
var scannedBatchLabels = []; // Array of { z1, z2, z3, z5, raw }
var currentBatchLotFilter = 'ng'; // 'ng' or 'all'

/**
 * Render Lot List in Modal (NG Lots vs All Session Lots)
 */
function renderBatchLotList(filterMode) {
    if (filterMode) currentBatchLotFilter = filterMode;

    var countNgBadge = document.getElementById('batch-modal-ng-count');
    var countAllBadge = document.getElementById('batch-modal-all-count');
    var listContainer = document.getElementById('batch-modal-lot-list');
    var tabNgBtn = document.getElementById('btn-batch-tab-ng');
    var tabAllBtn = document.getElementById('btn-batch-tab-all');

    var actionableLots = (currentNgLots || []).filter(function(l) {
        return l.lot_status === 'ng_found' || l.lot_status === 'ng_quarantine';
    });

    if (countNgBadge) countNgBadge.textContent = actionableLots.length;
    if (countAllBadge) countAllBadge.textContent = (currentSessionLots || []).length;

    if (tabNgBtn && tabAllBtn) {
        if (currentBatchLotFilter === 'ng') {
            tabNgBtn.style.backgroundColor = '#ffffff';
            tabNgBtn.style.color = '#be123c';
            tabNgBtn.style.boxShadow = '0 1px 3px rgba(0,0,0,0.1)';
            tabAllBtn.style.backgroundColor = 'transparent';
            tabAllBtn.style.color = '#64748b';
            tabAllBtn.style.boxShadow = 'none';
        } else {
            tabAllBtn.style.backgroundColor = '#ffffff';
            tabAllBtn.style.color = '#0284c7';
            tabAllBtn.style.boxShadow = '0 1px 3px rgba(0,0,0,0.1)';
            tabNgBtn.style.backgroundColor = 'transparent';
            tabNgBtn.style.color = '#64748b';
            tabNgBtn.style.boxShadow = 'none';
        }
    }

    if (!listContainer) return;

    var displayLots = (currentBatchLotFilter === 'ng') ? actionableLots : (currentSessionLots || []);

    if (displayLots.length === 0) {
        listContainer.innerHTML = (currentBatchLotFilter === 'ng')
            ? '<p style="font-size:12px; color:#64748b; text-align:center; padding:12px 0;">Tidak ada lot NG dalam sesi ini.</p>'
            : '<p style="font-size:12px; color:#64748b; text-align:center; padding:12px 0;">Tidak ada lot dalam sesi ini.</p>';
        return;
    }

    var ngLotMap = {};
    actionableLots.forEach(function(l) {
        if (l.id) ngLotMap['id_' + l.id] = l;
        if (l.session_lot_id) ngLotMap['id_' + l.session_lot_id] = l;
        if (l.ref_number) ngLotMap['ref_' + l.ref_number] = l;
    });

    var html = '';
    displayLots.forEach(function(lot) {
        var lotNo = lot.lot_number || '-';
        var refNo = lot.ref_number || '';
        var qty = lot.qty || lot.total_qty || 0;

        var ngData = null;
        if (lot.id && ngLotMap['id_' + lot.id]) {
            ngData = ngLotMap['id_' + lot.id];
        } else if (lot.session_lot_id && ngLotMap['id_' + lot.session_lot_id]) {
            ngData = ngLotMap['id_' + lot.session_lot_id];
        } else if (refNo && ngLotMap['ref_' + refNo]) {
            ngData = ngLotMap['ref_' + refNo];
        }

        var isNg = !!ngData || lot.lot_status === 'ng_found' || lot.lot_status === 'ng_quarantine';

        var statusBadge = '';
        var cardStyle = '';

        if (isNg) {
            cardStyle = 'background-color: #fff1f2; border: 1px solid #fecdd3;';
            var defects = (ngData && ngData.ng_defects) ? ngData.ng_defects : (lot.ng_defects || []);
            if (defects && defects.length > 0) {
                statusBadge = defects.map(function(d) {
                    return '<span style="display:inline-block; background-color:#ffe4e6; color:#9f1239; border:1px solid #fca5a5; border-radius:4px; padding:1px 5px; font-size:9px; font-weight:700; margin-right:4px;">' +
                           escapeHtml(d.defect_name) + ' ×' + d.total_qty_ng + '</span>';
                }).join('');
            } else {
                statusBadge = '<span style="display:inline-flex; align-items:center; gap:4px; font-size:9.5px; color:#e11d48; font-weight:800;"><span style="width:6px; height:6px; border-radius:50%; background-color:#e11d48; display:inline-block;"></span> Lot NG</span>';
            }
        } else {
            cardStyle = 'background-color: #ffffff; border: 1px solid #e2e8f0;';
            statusBadge = '<span style="display:inline-flex; align-items:center; gap:3px; background-color:#dcfce7; color:#15803d; border:1px solid #bbf7d0; border-radius:4px; padding:1px 6px; font-size:9px; font-weight:800;"><svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg> OK (Normal)</span>';
        }

        html += '<div style="' + cardStyle + ' border-radius: 8px; padding: 6px 10px; display: flex; align-items: center; justify-content: space-between; font-size: 12px; box-shadow: 0 1px 2px rgba(0,0,0,0.02);">' +
                    '<div>' +
                        '<div style="font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 5px;">' +
                            '<span style="display: inline-flex; align-items: center; gap: 4px;"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="color:#64748b;"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>' + escapeHtml(lotNo) + '</span>' +
                            (refNo ? '<span style="font-size: 10px; color: #64748b; font-family: monospace;">(' + escapeHtml(refNo) + ')</span>' : '') +
                        '</div>' +
                        '<div style="margin-top: 2px; display: flex; align-items: center; gap: 4px;">' + statusBadge + '</div>' +
                    '</div>' +
                    '<span style="font-size: 10px; font-weight: 800; color: #334155; background-color: #f1f5f9; border: 1px solid #cbd5e1; padding: 2px 8px; border-radius: 9999px;">' + qty + ' pcs</span>' +
                '</div>';
    });

    listContainer.innerHTML = html;
}

/**
 * Buka Modal Batch Re-Inspeksi (#batch-reinspection-modal-overlay)
 */
function openBatchReinspectionModal() {
    var modal = document.getElementById('batch-reinspection-modal-overlay');
    if (!modal) return;

    // Dynamically update Header Info Banner (Part Code, Part Name, Kanban No, ETA, Customer, Jenis Inspeksi)
    if (typeof currentSessionObj !== 'undefined' && currentSessionObj) {
        var pCode = currentSessionObj.part_code || currentSessionPartCode || '-';
        var pName = currentSessionObj.part_name || '-';
        var kNo = currentSessionObj.kanban_no || currentSessionObj.doc_no || '-';
        var pCust = currentSessionObj.customer || 'PT. Indonesia Epson Industry';
        var inspType = currentSessionObj.inspection_type || 'kanban';
        var pEta = currentSessionObj.kanban_eta || currentSessionObj.eta || currentSessionObj.kanban_req_date || '';

        var elCode = document.getElementById('batch-modal-part-code');
        if (elCode) elCode.textContent = pCode;

        var elName = document.getElementById('batch-modal-part-name');
        if (elName) elName.textContent = pName;

        var elKNo = document.getElementById('batch-modal-kanban-no');
        if (elKNo) elKNo.textContent = kNo;

        var elEta = document.getElementById('batch-modal-eta');
        if (elEta) {
            var etaFormatted = '-';
            if (pEta && pEta !== '-' && pEta !== '0000-00-00 00:00:00') {
                var d = new Date(String(pEta).replace(/-/g, '/'));
                if (!isNaN(d.getTime())) {
                    var months = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
                    var day = ('0' + d.getDate()).slice(-2);
                    var month = months[d.getMonth()];
                    var year = d.getFullYear();
                    var hours = ('0' + d.getHours()).slice(-2);
                    var mins = ('0' + d.getMinutes()).slice(-2);
                    etaFormatted = day + ' ' + month + ' ' + year + ', ' + hours + ':' + mins + ' WIB';
                } else {
                    etaFormatted = pEta;
                }
            }
            elEta.innerHTML = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg><span>' + escapeHtml(etaFormatted) + '</span>';
        }

        var elCust = document.getElementById('batch-modal-customer');
        if (elCust) elCust.textContent = pCust;

        var elBadge = document.getElementById('batch-modal-type-badge');
        if (elBadge) {
            if (inspType === 'safety_stock') {
                elBadge.innerHTML = '<span style="display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 6px; font-size: 10px; font-weight: 800; background-color: #f3e8ff; color: #7e22ce; border: 1px solid #d8b4fe;">' +
                    '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>' +
                    'SAFETY STOCK</span>';
            } else {
                elBadge.innerHTML = '<span style="display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 6px; font-size: 10px; font-weight: 800; background-color: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe;">' +
                    '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>' +
                    'KANBAN</span>';
            }
        }
    }

    // Render lot list (default 'ng' view)
    renderBatchLotList('ng');

    // Reset State & Form Fields
    scannedBatchLabels = [];
    renderBatchScannedTable();
    
    // Default: rescan_restart
    selectBatchStrategy('rescan');
    selectSubAction('rescan_restart');

    document.getElementById('batch-reinsp-notes').value = '';
    if (document.getElementById('batch-input-z1')) {
        document.getElementById('batch-input-z1').value = currentSessionPartCode || (window.OQC_CONFIG.sessionPartCode || '');
    }
    if (document.getElementById('batch-input-z2')) {
        document.getElementById('batch-input-z2').value = '';
    }

    modal.classList.remove('hidden');
    modal.style.display = 'flex';
}

function closeBatchReinspectionModal() {
    var modal = document.getElementById('batch-reinspection-modal-overlay');
    if (modal) {
        modal.classList.add('hidden');
        modal.style.display = 'none';
    }
}

/**
 * Select Main Strategy (rescan vs replace)
 */
function selectBatchStrategy(strategy) {
    batchSelectedStrategy = strategy;
    var btnRescan = document.getElementById('card-btn-rescan');
    var btnReplace = document.getElementById('card-btn-replace');
    var subRescan = document.getElementById('sub-rescan-buttons');
    var subReplace = document.getElementById('sub-replace-buttons');
    var repForm = document.getElementById('batch-replacement-form-container');
    var btnSubmit = document.getElementById('btn-submit-batch-reinsp');

    if (strategy === 'rescan') {
        if (btnRescan) {
            btnRescan.style.border = '2px solid #f59e0b';
            btnRescan.style.backgroundColor = '#fffbeb';
            btnRescan.style.opacity = '1';
            btnRescan.style.boxShadow = '0 4px 14px rgba(245,158,11,0.15)';
        }
        if (btnReplace) {
            btnReplace.style.border = '2px solid #cbd5e1';
            btnReplace.style.backgroundColor = '#ffffff';
            btnReplace.style.opacity = '0.6';
            btnReplace.style.boxShadow = 'none';
        }
        if (subRescan) {
            subRescan.style.opacity = '1';
            subRescan.style.pointerEvents = 'auto';
        }
        if (subReplace) {
            subReplace.style.opacity = '0.4';
            subReplace.style.pointerEvents = 'none';
        }
        if (repForm) repForm.classList.add('hidden');
        if (btnSubmit) btnSubmit.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polygon points="5 3 19 12 5 21 5 3"/></svg><span>Mulai Sesi Re-Inspeksi Baru</span>';
        
        selectSubAction('rescan_restart');
    } else {
        if (btnReplace) {
            btnReplace.style.border = '2px solid #e11d48';
            btnReplace.style.backgroundColor = '#fff1f2';
            btnReplace.style.opacity = '1';
            btnReplace.style.boxShadow = '0 4px 14px rgba(225,29,72,0.15)';
        }
        if (btnRescan) {
            btnRescan.style.border = '2px solid #cbd5e1';
            btnRescan.style.backgroundColor = '#ffffff';
            btnRescan.style.opacity = '0.6';
            btnRescan.style.boxShadow = 'none';
        }
        if (subReplace) {
            subReplace.style.opacity = '1';
            subReplace.style.pointerEvents = 'auto';
        }
        if (subRescan) {
            subRescan.style.opacity = '0.4';
            subRescan.style.pointerEvents = 'none';
        }
        if (repForm) repForm.classList.remove('hidden');
        if (btnSubmit) btnSubmit.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="20 6 9 17 4 12"></polyline><span>Validasi Matching &amp; Muat Inspeksi</span>';

        selectSubAction('replace_ng_only');
    }
}

/**
 * Select Sub Action Strategy
 */
function selectSubAction(action, evt) {
    if (evt) {
        evt.stopPropagation();
        evt.preventDefault();
    }

    batchSelectedSubAction = action;

    // Reset styles for all sub buttons
    var btnRescanRestart = document.getElementById('btn-sub-rescan-restart');
    var btnRescanContinue = document.getElementById('btn-sub-rescan-continue');
    var btnReplaceNg = document.getElementById('btn-sub-replace-ng');
    var btnReplaceAll = document.getElementById('btn-sub-replace-all');

    if (btnRescanRestart) {
        btnRescanRestart.style.backgroundColor = '#ffffff';
        btnRescanRestart.style.color = '#92400e';
        btnRescanRestart.style.borderColor = '#fcd34d';
        btnRescanRestart.style.fontWeight = '600';
    }
    if (btnRescanContinue) {
        btnRescanContinue.style.backgroundColor = '#ffffff';
        btnRescanContinue.style.color = '#92400e';
        btnRescanContinue.style.borderColor = '#fcd34d';
        btnRescanContinue.style.fontWeight = '600';
    }
    if (btnReplaceNg) {
        btnReplaceNg.style.backgroundColor = '#ffffff';
        btnReplaceNg.style.color = '#475569';
        btnReplaceNg.style.borderColor = '#cbd5e1';
        btnReplaceNg.style.fontWeight = '600';
    }
    if (btnReplaceAll) {
        btnReplaceAll.style.backgroundColor = '#ffffff';
        btnReplaceAll.style.color = '#475569';
        btnReplaceAll.style.borderColor = '#cbd5e1';
        btnReplaceAll.style.fontWeight = '600';
    }

    // Apply active style
    if (action === 'rescan_restart' && btnRescanRestart) {
        btnRescanRestart.style.backgroundColor = '#d97706';
        btnRescanRestart.style.color = '#ffffff';
        btnRescanRestart.style.borderColor = '#d97706';
        btnRescanRestart.style.fontWeight = '700';
    } else if (action === 'rescan_continue' && btnRescanContinue) {
        btnRescanContinue.style.backgroundColor = '#d97706';
        btnRescanContinue.style.color = '#ffffff';
        btnRescanContinue.style.borderColor = '#d97706';
        btnRescanContinue.style.fontWeight = '700';
    } else if (action === 'replace_ng_only' && btnReplaceNg) {
        btnReplaceNg.style.backgroundColor = '#e11d48';
        btnReplaceNg.style.color = '#ffffff';
        btnReplaceNg.style.borderColor = '#e11d48';
        btnReplaceNg.style.fontWeight = '700';
        renderBatchLotList('ng');
    } else if (action === 'replace_all_lots' && btnReplaceAll) {
        btnReplaceAll.style.backgroundColor = '#e11d48';
        btnReplaceAll.style.color = '#ffffff';
        btnReplaceAll.style.borderColor = '#e11d48';
        btnReplaceAll.style.fontWeight = '700';
        renderBatchLotList('all');
    }

    renderBatchScannedTable();

    // Auto focus scan input when entering replace mode
    if (action.indexOf('replace') !== -1) {
        setTimeout(function() {
            var input = document.getElementById('batch-qr-scan-input');
            if (input) input.focus();
        }, 100);
    }
}

/**
 * Main Barcode Scanner Input Handler in Batch Modal
 */
function handleSingleBatchQrInput(inputEl, forceProcess) {
    if (!inputEl) return;
    var raw = inputEl.value.trim();
    if (!raw) return;

    var parsed = {};
    if (typeof parseBarcodeQR === 'function') {
        parsed = parseBarcodeQR(raw);
    }

    // Early Part Code mismatch check:
    var expectedPart = currentSessionPartCode || (document.getElementById('batch-input-z1') ? document.getElementById('batch-input-z1').getAttribute('data-original') : '') || '';
    if (parsed.Z1 && expectedPart && parsed.Z1.toUpperCase() !== expectedPart.toUpperCase()) {
        if (forceProcess || hasNewline) {
            Swal.fire({
                icon: 'error',
                title: '⚠️ PART CODE TIDAK COCOK!',
                html: '<div style="font-size: 12px; text-align: left; line-height: 1.6; color: #334155;">' +
                      'Part Code pada label (<b style="color:#be123c;">"' + escapeHtml(parsed.Z1) + '"</b>) tidak cocok dengan Part Code Sesi Kanban ini (<b style="color:#0284c7;">"' + escapeHtml(expectedPart) + '"</b>)!<br><br>' +
                      '<b style="color:#e11d48;">Pastikan Anda meng-scan label box yang sesuai dengan Kanban ini!</b>' +
                      '</div>'
            });
            // Rollback & bersihkan field
            if (document.getElementById('batch-input-z1')) document.getElementById('batch-input-z1').value = expectedPart;
            if (document.getElementById('batch-input-z2')) document.getElementById('batch-input-z2').value = '';
            if (document.getElementById('batch-input-z3')) document.getElementById('batch-input-z3').value = '';
            if (document.getElementById('batch-input-z5')) document.getElementById('batch-input-z5').value = '';
            inputEl.value = '';
            setTimeout(function() { if (inputEl) inputEl.focus(); }, 100);
        }
        return;
    }

    // Live sync manual input fields for visual feedback
    if (parsed.Z1 && document.getElementById('batch-input-z1')) document.getElementById('batch-input-z1').value = parsed.Z1;
    if (parsed.Z2 && document.getElementById('batch-input-z2')) document.getElementById('batch-input-z2').value = parsed.Z2;
    if (parsed.Z3 && parsed.Z3 > 0 && document.getElementById('batch-input-z3')) document.getElementById('batch-input-z3').value = parsed.Z3;
    if (parsed.Z5 && document.getElementById('batch-input-z5')) document.getElementById('batch-input-z5').value = parsed.Z5;

    var z1 = (parsed && parsed.Z1) ? parsed.Z1 : (currentSessionPartCode || '');
    var z2 = (parsed && parsed.Z2) ? parsed.Z2 : '';
    var z3 = (parsed && parsed.Z3) ? parseInt(parsed.Z3) : (document.getElementById('batch-input-z3') ? parseInt(document.getElementById('batch-input-z3').value) : 0);
    var z5 = (parsed && parsed.Z5) ? parsed.Z5 : '';

    // HANYA SUBMIT jika user/scanner menekan ENTER (forceProcess === true) atau terdapat Newline (\n / \r) dari hardware scanner
    var hasNewline = (raw.includes('\n') || raw.includes('\r'));

    if (forceProcess || hasNewline) {
        if (!z2 || !z3 || z3 <= 0) {
            Swal.fire({ icon: 'warning', title: 'Qty Box Wajib Diisi', text: 'Lot Number & Qty Box (> 0) wajib terisi! Silakan isi field Qty Box secara manual jika tidak ada di barcode.' });
            return;
        }

        if (!z5) {
            Swal.fire({ icon: 'warning', title: 'Ref Number Wajib Diisi', text: 'Ref Number tidak terbaca dari barcode. Silakan isi field Ref Number secara manual sebelum menambah label.' });
            if (inputEl) inputEl.value = '';
            return;
        }

        validateBatchLabelWithDid({
            z1: z1,
            z2: z2,
            z3: z3,
            z5: z5,
            raw: raw,
            inputEl: inputEl
        });
    }
}

/**
 * Add Manual Label via + Input Label Manual button
 */
function addManualBatchLabel() {
    var z1 = document.getElementById('batch-input-z1').value.trim() || currentSessionPartCode || '';
    var z2 = document.getElementById('batch-input-z2').value.trim();
    var z3 = parseInt(document.getElementById('batch-input-z3').value) || 0;
    var z5 = document.getElementById('batch-input-z5').value.trim();

    if (!z2) {
        Swal.fire('Perhatian', 'Lot Number wajib diisi!', 'warning');
        return;
    }
    if (z3 <= 0) {
        Swal.fire('Perhatian', 'Qty Box harus lebih dari 0!', 'warning');
        return;
    }

    if (!z5) {
        Swal.fire({ icon: 'warning', title: 'Ref Number Wajib Diisi', text: 'Silakan isi Ref Number terlebih dahulu. Ref Number harus diisi manual oleh user.' });
        return;
    }

    validateBatchLabelWithDid({
        z1: z1,
        z2: z2,
        z3: z3,
        z5: z5,
        raw: ''
    });
}

/**
 * Validasi Real-Time DID (Cek Dimensi) untuk Batch Re-Inspeksi Label
 */
function validateBatchLabelWithDid(item) {
    var pCode = item.z1 || currentSessionPartCode || '';
    var lNum = item.z2 || '';

    if (!pCode || !lNum) {
        Swal.fire('Error', 'Part Code dan Lot Number wajib diisi untuk verifikasi DID!', 'error');
        return;
    }

    // 1. Validasi Part Code Match dengan Sesi Kanban saat ini
    if (currentSessionPartCode && pCode.toUpperCase() !== currentSessionPartCode.toUpperCase()) {
        Swal.fire({
            icon: 'error',
            title: '⚠️ PART CODE TIDAK COCOK!',
            html: '<div style="font-size: 12px; text-align: left; line-height: 1.6; color: #334155;">' +
                  'Part Code pada label (<b style="color:#be123c;">"' + escapeHtml(pCode) + '"</b>) tidak cocok dengan Part Code Sesi Kanban ini (<b style="color:#0284c7;">"' + escapeHtml(currentSessionPartCode) + '"</b>)!<br><br>' +
                  '<b style="color:#e11d48;">Pastikan Anda meng-scan/memasukkan label barang yang sesuai dengan Part Code Kanban ini!</b>' +
                  '</div>'
        });
        // Rollback form fields
        if (document.getElementById('batch-input-z1')) document.getElementById('batch-input-z1').value = currentSessionPartCode;
        if (document.getElementById('batch-input-z2')) document.getElementById('batch-input-z2').value = '';
        if (document.getElementById('batch-input-z3')) document.getElementById('batch-input-z3').value = '';
        if (document.getElementById('batch-input-z5')) document.getElementById('batch-input-z5').value = '';
        if (item.inputEl) {
            item.inputEl.value = '';
            item.inputEl.focus();
        }
        return;
    }

    // Check duplicate box label in scannedBatchLabels (Part + Lot + Ref match)
    var isDuplicateBox = scannedBatchLabels.some(function(sb) {
        var matchPart = (!sb.z1 || !pCode || sb.z1.toUpperCase() === pCode.toUpperCase());
        var matchLot  = (sb.z2 && lNum && sb.z2.toUpperCase() === lNum.toUpperCase());
        var matchRef  = ((sb.z5 || '').toUpperCase() === (item.z5 || '').toUpperCase());
        return matchPart && matchLot && matchRef;
    });

    if (isDuplicateBox) {
        Swal.fire('Perhatian', 'Label Part ' + pCode + ' Lot #' + lNum + ' (Ref ' + (item.z5 || '-') + ') sudah discan sebelumnya!', 'warning');
        return;
    }

    Swal.fire({
        title: 'Memeriksa DID...',
        text: 'Memverifikasi Cek Dimensi Lot #' + lNum + ' (Part ' + pCode + ')...',
        allowOutsideClick: false,
        didOpen: function() { Swal.showLoading(); }
    });

    var checkUrl = (window.OQC_CONFIG.baseUrl || '') + "/modules/inspection/scan_validate.php?mode=check_did_only&part_code=" + encodeURIComponent(pCode) + 
                   '&lot_number=' + encodeURIComponent(lNum) + 
                   '&ref_number=' + encodeURIComponent(item.z5 || '') +
                   '&barcode_qty=' + encodeURIComponent(item.z3 || 0);

    fetch(checkUrl)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            closeSwalSafely();
            if (!data.success) {
                if (data.error_type === 'safety_stock_exhausted') {
                    Swal.fire({
                        icon: 'error',
                        title: '⛔ SAFETY STOCK SUDAH HABIS!',
                        html: '<div style="font-size: 12px; text-align: left; line-height: 1.6; color: #334155;">' +
                              '<b style="color: #be123c;">' + escapeHtml(data.message || 'Safety Stock sudah habis terpakai.') + '</b><br><br>' +
                              '<p style="font-size: 11px; color: #64748b; background-color: #f8fafc; padding: 8px 10px; border-radius: 6px; border: 1px solid #e2e8f0;">' +
                              'Box ini sudah tidak memiliki sisa stok yang tersedia di gudang Safety Stock.' +
                              '</p>' +
                              '</div>',
                        confirmButtonColor: '#ef4444'
                    });
                    if (item.inputEl) item.inputEl.value = '';
                    return;
                }

                Swal.fire({
                    icon: 'error',
                    title: '⚠️ LOT NUMBER BELUM LOLOS CEK DIMENSI (DID)!',
                    html: '<div style="font-size: 12px; text-align: left; line-height: 1.6; color: #334155;">' +
                          '<b style="color: #be123c;">' + escapeHtml(data.message || 'Lot Number belum lolos cek dimensi!') + '</b><br><br>' +
                          'Part Code: <b>' + escapeHtml(pCode) + '</b><br>' +
                          'Lot Number yang Di-scan/Di-input: <b style="font-family: monospace; color: #1d4ed8;">' + escapeHtml(lNum) + '</b>' +
                          '</div>'
                });
                return;
            }

            // Jika terdeteksi Safety Stock
            if (data.is_safety_stock) {
                if (data.is_replenished_box && data.replenish_info) {
                    item.is_replenished_box = true;
                    item.replenish_info = data.replenish_info;
                    item.z3 = data.replenish_info.original_barcode_qty || item.z3;

                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'info',
                        title: 'Box Eks-Potong (Refill)',
                        text: 'Pernah diambil ' + data.replenish_info.previously_taken_qty + ' pcs. Digenapi kembali ke ' + item.z3 + ' pcs oleh Gudang.',
                        timer: 4500,
                        showConfirmButton: false
                    });
                } else if (data.ss_lot && parseInt(data.ss_lot.available_qty, 10) > 0) {
                    var prevBatchQty = parseInt(item.z3, 10) || 0;
                    var realStockQty = parseInt(data.ss_lot.available_qty, 10);
                    if (prevBatchQty !== realStockQty) {
                        item.z3 = realStockQty;
                        Swal.fire({
                            toast: true,
                            position: 'top-end',
                            icon: 'info',
                            title: 'Safety Stock Terdeteksi',
                            text: 'Qty disesuaikan ke sisa stok: ' + realStockQty + ' pcs (Label: ' + prevBatchQty + ' pcs)',
                            timer: 3500,
                            showConfirmButton: false
                        });
                    }
                }
            }

            // Cek apakah label ini pernah memiliki riwayat NG di masa lalu
            if (data.was_rejected_before && data.rejected_info) {
                item.was_rejected_before = true;
                item.rejected_info = data.rejected_info;
            }

            // DID Passed! Push item to scanned labels
            scannedBatchLabels.push(item);
            renderBatchScannedTable();

            if (item.inputEl) item.inputEl.value = '';

            // Clear manual input fields (reset Part Code to current session Part Code)
            if (document.getElementById('batch-input-z1')) document.getElementById('batch-input-z1').value = currentSessionPartCode || (window.OQC_CONFIG.sessionPartCode || '');
            if (document.getElementById('batch-input-z2')) document.getElementById('batch-input-z2').value = '';
            if (document.getElementById('batch-input-z3')) document.getElementById('batch-input-z3').value = '';
            if (document.getElementById('batch-input-z5')) document.getElementById('batch-input-z5').value = '';

            Swal.fire({
                icon: 'success',
                title: '✓ Terverifikasi DID',
                text: 'Label Lot #' + lNum + ' (' + item.z3 + ' pcs) berhasil ditambahkan!',
                timer: 1500,
                showConfirmButton: false
            });
        })
        .catch(function() {
            closeSwalSafely();
            Swal.fire('Koneksi Error', 'Gagal memverifikasi DID ke server', 'error');
        });
}

/**
 * Remove Item from scanned batch labels array
 */
function removeBatchLabel(index) {
    if (index >= 0 && index < scannedBatchLabels.length) {
        scannedBatchLabels.splice(index, 1);
        renderBatchScannedTable();
    }
}

/**
 * Render Scanned Labels Table in Modal
 */
function renderBatchScannedTable() {
    var tbody = document.getElementById('batch-scanned-table-body');
    var countBadge = document.getElementById('batch-scanned-count');

    if (countBadge) countBadge.textContent = scannedBatchLabels.length;
    if (!tbody) return;

    if (scannedBatchLabels.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" style="padding: 16px; text-align: center; color: #94a3b8;">Belum ada label QR yang discan. Silakan scan barcode label di atas.</td></tr>';
        return;
    }

    var targetList = (batchSelectedSubAction === 'replace_ng_only') 
                     ? currentNgLots.filter(function(l) { return l.lot_status === 'ng_found' || l.lot_status === 'ng_quarantine'; })
                     : currentSessionLots;

    var html = '';
    scannedBatchLabels.forEach(function(item, idx) {
        var targetLot = targetList[idx] || null;
        var targetHtml = '';
        if (targetLot) {
            var targetLotNo = targetLot.lot_number || '-';
            var targetRefNo = targetLot.ref_number || '-';
            targetHtml = '<div style="font-size:10px; font-weight:700; color:#9f1239; background:#ffe4e6; border:1px solid #fecdd3; padding:2px 6px; border-radius:6px; display:inline-block; margin-bottom:3px;">' +
                         '🎯 Diganti dari: <b>#' + escapeHtml(targetLotNo) + '</b> <span style="font-family:monospace; color:#be123c;">(Ref: ' + escapeHtml(targetRefNo) + ')</span>' +
                         '</div>';
        } else {
            targetHtml = '<div style="font-size:10px; font-weight:600; color:#64748b; margin-bottom:3px;">(Lot Tambahan)</div>';
        }

        html += '<tr style="border-bottom: 1px solid #e2e8f0; font-family: monospace;">' +
                    '<td style="padding: 8px 10px; font-weight: 700; color: #64748b;">' + (idx + 1) + '</td>' +
                    '<td style="padding: 8px 10px; font-weight: 700; color: #2563eb;">' + escapeHtml(item.z5 || '-') + '</td>' +
                    '<td style="padding: 8px 10px;">' +
                        targetHtml +
                        '<div style="font-weight:800; color:#166534; background:#dcfce7; border:1px solid #bbf7d0; padding:2px 6px; border-radius:6px; display:inline-block;">' +
                            '📦 Lot Pengganti: <b>#' + escapeHtml(item.z2) + '</b>' +
                        '</div>' +
                    '</td>' +
                    '<td style="padding: 8px 10px; text-align: center; font-weight: 800; color: #059669;">' + item.z3 + ' pcs</td>' +
                    '<td style="padding: 8px 10px; text-align: center;">' +
                        '<button type="button" onclick="removeBatchLabel(' + idx + ')" style="padding: 3px 8px; background-color: #ffe4e6; color: #be123c; border: 1px solid #fecdd3; border-radius: 6px; font-size: 10px; font-weight: 700; cursor: pointer;">Hapus</button>' +
                    '</td>' +
                '</tr>';
    });

    tbody.innerHTML = html;
}

/**
 * Submit Form Batch Re-Inspeksi
 */
function submitBatchReinspection() {
    var mode = batchSelectedSubAction;
    var notes = document.getElementById('batch-reinsp-notes').value.trim();
    var replacementLotsPayload = [];

    if (mode === 'replace_ng_only' || mode === 'replace_all_lots') {
        if (scannedBatchLabels.length === 0) {
            Swal.fire('Perhatian', 'Silakan scan/tambah minimal 1 Label QR Code Lot Pengganti!', 'warning');
            return;
        }

        // Map scannedBatchLabels into replacementLotsPayload
        var actionableLots = currentNgLots.filter(function(l) {
            return l.lot_status === 'ng_found' || l.lot_status === 'ng_quarantine';
        });

        scannedBatchLabels.forEach(function(item, idx) {
            var origId = 0;
            if (mode === 'replace_ng_only' && actionableLots[idx]) {
                origId = actionableLots[idx].id;
            }

            replacementLotsPayload.push({
                ng_lot_id: origId,
                new_z1: item.z1 || currentSessionPartCode || '',
                new_z2: item.z2,
                new_z3: item.z3,
                new_z5: item.z5
            });
        });
    }

    var btnSubmit = document.getElementById('btn-submit-batch-reinsp');
    if (btnSubmit) {
        btnSubmit.disabled = true;
        btnSubmit.innerHTML = '<span>⏳ Memproses...</span>';
    }

    var payload = {
        action: mode,
        original_session_id: currentSessionId,
        reinspection_notes: notes,
        replacement_lots: replacementLotsPayload
    };

    fetch(reinspectApiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (btnSubmit) {
            btnSubmit.disabled = false;
            btnSubmit.innerHTML = '<span>Validasi Matching &amp; Muat Inspeksi</span>';
        }

        closeBatchReinspectionModal();

        if (!data.success) {
            Swal.fire({ icon: 'error', title: 'Gagal', text: data.message || 'Terjadi kesalahan' });
            return;
        }

        if (data.reinspection_session_id) {
            loadWorkbenchSessionData(data.reinspection_session_id);
        }

        Swal.fire({
            icon: 'success',
            title: '✅ Re-Inspeksi Dibuat!',
            html: '<div style="font-size:12px;color:#334155;line-height:1.7;">' +
                  (data.message || 'Sesi Re-Inspeksi baru berhasil dibuat.') + '<br>' +
                  '<b>Sample:</b> ' + data.sample_size + ' pcs &nbsp;|&nbsp; <b>Batas NG:</b> ' + data.reject_number +
                  '</div>',
            confirmButtonColor: '#2563eb',
            confirmButtonText: 'Lanjut Inspeksi 🔄',
            timer: 3000,
            timerProgressBar: true
        });
    })
    .catch(function() {
        if (btnSubmit) {
            btnSubmit.disabled = false;
            btnSubmit.innerHTML = '<span>Validasi Matching &amp; Muat Inspeksi</span>';
        }
        closeBatchReinspectionModal();
        Swal.fire({ icon: 'error', title: 'Koneksi Error', text: 'Gagal terhubung ke server!' });
    });
}

function getCombinedSavedChiefNamesWb() {
    var list = [];
    try {
        var local = JSON.parse(localStorage.getItem('oqc_saved_chief_names') || '[]');
        if (Array.isArray(local)) {
            local.forEach(function(n) {
                if (n && typeof n === 'string') {
                    var trimmed = n.trim();
                    if (trimmed && !list.some(function(x) { return x.toLowerCase() === trimmed.toLowerCase(); })) {
                        list.push(trimmed);
                    }
                }
            });
        }
    } catch(e) {}
    return list;
}

function saveChiefNameLocallyWb(name) {
    if (!name || typeof name !== 'string') return;
    var trimmed = name.trim();
    if (!trimmed) return;
    try {
        var local = JSON.parse(localStorage.getItem('oqc_saved_chief_names') || '[]');
        if (!Array.isArray(local)) local = [];
        if (!local.some(function(x) { return x.toLowerCase() === trimmed.toLowerCase(); })) {
            local.push(trimmed);
            localStorage.setItem('oqc_saved_chief_names', JSON.stringify(local));
        }
    } catch(e) {}
}

/**
 * Chief QC Approval from Workbench (Manual Typing + Saved Select Options)
 */
