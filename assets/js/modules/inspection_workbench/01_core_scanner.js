var currentSessionId = window.OQC_CONFIG.sessionId;
var currentSessionPartCode = window.OQC_CONFIG.sessionPartCode;
var currentSessionInspectionType = window.OQC_CONFIG.inspectionType;
var autoKanbanId = window.OQC_CONFIG.autoKanbanId;
var initialAutoKanbanItem = window.OQC_CONFIG.initialAutoKanbanItem;
var autoScanType = window.OQC_CONFIG.autoScanType;
var prefilledScannedLot = window.OQC_CONFIG.prefilledScannedLot;
var prefilledScannedRef = window.OQC_CONFIG.prefilledScannedRef;
var prefilledPartCode   = window.OQC_CONFIG.prefilledPartCode;
var currentZoom = 1.0;
var DEFECT_TYPES_LIST = window.OQC_CONFIG.defectTypesList;

if (window.OQC_CONFIG.showScanOverlay) {
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(function() {
            openScanModal(autoKanbanId, autoScanType);

            // Jika ada pre-fetched kanban item dari server, langsung lompat ke Langkah 2 (Scan Barcode Box)
            if (initialAutoKanbanItem && parseInt(initialAutoKanbanItem.id) > 0) {
                var targetTotalQty = parseInt(initialAutoKanbanItem.qty) || 0;
                var totalPassedQty = parseInt(initialAutoKanbanItem.total_passed_qty) || 0;
                var remainingQty = Math.max(0, targetTotalQty - totalPassedQty);
                var isSafetyStock = (initialAutoKanbanItem.plan_type === 'safety_stock' || (initialAutoKanbanItem.batch_plan_type || '') === 'safety_stock' || (initialAutoKanbanItem.kanban_no || '').toUpperCase().indexOf('SS') === 0);
                var planTypeAttr = isSafetyStock ? 'safety_stock' : 'kanban';

                selectPlanningItem(
                    parseInt(initialAutoKanbanItem.id),
                    initialAutoKanbanItem.item_code,
                    initialAutoKanbanItem.display_part_name || initialAutoKanbanItem.item_description,
                    initialAutoKanbanItem.customer || 'PT. Indonesia Epson Industry',
                    remainingQty,
                    initialAutoKanbanItem.check_type || '',
                    planTypeAttr,
                    0,
                    totalPassedQty,
                    targetTotalQty,
                    initialAutoKanbanItem.kanban_no || '',
                    initialAutoKanbanItem.eta || initialAutoKanbanItem.req_date || ''
                );
            }

            if (prefilledPartCode && document.getElementById('scan-part-code')) {
                document.getElementById('scan-part-code').value = prefilledPartCode;
            }
            if (prefilledScannedLot && document.getElementById('scan-lot-number')) {
                document.getElementById('scan-lot-number').value = prefilledScannedLot;
            }
            if (prefilledScannedRef && document.getElementById('scan-ref-number')) {
                document.getElementById('scan-ref-number').value = prefilledScannedRef;
            }
        }, 100);
    });
} else {
    document.addEventListener('DOMContentLoaded', function() {
        if (prefilledScannedLot) {
            setTimeout(function() {
                if (typeof openAddLotModal === 'function') {
                    openAddLotModal();
                    var rawInput = document.getElementById('add-lot-qr-raw');
                    if (rawInput) {
                        var payload = "Z1" + (currentSessionPartCode || prefilledPartCode) + "|Z2" + prefilledScannedLot + (prefilledScannedRef ? ("|Z5" + prefilledScannedRef) : "");
                        rawInput.value = payload;
                        handleModalAddLotQrInput(rawInput);
                    }
                }
            }, 300);
        }
    });
}

// Shortcut F2 listener to open scan modal & F11 for Fullscreen
window.addEventListener('keydown', function(e) {
    if (e.key === 'F11') {
        e.preventDefault();
        toggleNativeFullscreen();
    }
    if (e.key === 'F2') {
        e.preventDefault();
        openScanModal();
    }
});



var scannedLabelsList = [];

var autoStartSingleLabel = (function() {
    try {
        var stored = localStorage.getItem('oqc_autostart_single_label');
        if (stored !== null) return stored === '1';
    } catch(e) {}
    return true; // Default ON (Auto-Start Single Label)
})();

function toggleAutoStartMode() {
    if (selectedPlanningType === 'safety_stock') {
        if (typeof Toast !== 'undefined' && typeof Toast.fire === 'function') {
            Toast.fire({
                icon: 'info',
                title: 'Safety Stock dalam Mode Multi-Lot (scan seluruh box lalu klik Mulai Inspeksi).'
            });
        }
        return;
    }
    autoStartSingleLabel = !autoStartSingleLabel;
    updateAutoStartToggleUI();
    try {
        localStorage.setItem('oqc_autostart_single_label', autoStartSingleLabel ? '1' : '0');
    } catch(e) {}
    if (typeof Toast !== 'undefined' && typeof Toast.fire === 'function') {
        Toast.fire({
            icon: 'info',
            title: autoStartSingleLabel ? 'Mode Auto-Start Aktif (1 Label Auto Inspeksi)' : 'Mode Multi-Label Batch Aktif (Kumpulkan Label)'
        });
    }
}

function updateAutoStartToggleUI() {
    var btn = document.getElementById('btn-toggle-autostart');
    var helpText = document.getElementById('scan-help-text');
    if (!btn) return;
    if (selectedPlanningType === 'safety_stock') {
        btn.className = "px-2.5 py-1 rounded-lg text-[10px] font-extrabold bg-purple-600 text-white border border-purple-700 shadow-2xs cursor-default flex items-center space-x-1";
        btn.innerHTML = '<span>📦 Mode Multi-Lot (Safety Stock)</span>';
        btn.title = "Mode Multi-Lot Safety Stock: scan box fisik satu per satu lalu klik tombol Mulai Inspeksi.";
        if (helpText) {
            helpText.innerHTML = 'Scan label barcode box Safety Stock bertahap (Multi-Lot). Klik tombol Mulai Inspeksi jika seluruh box selesai discan.';
        }
    } else if (selectedPlanningType === 'kanban' || selectedPlanningType === 'partial') {
        btn.className = "px-2.5 py-1 rounded-lg text-[10px] font-extrabold bg-slate-200 text-slate-700 border border-slate-300 shadow-2xs flex items-center space-x-1 cursor-default";
        btn.innerHTML = '<span>Mode Multi-Label Batch (Kanban)</span>';
        btn.title = "Mode Kanban wajib kumpulkan seluruh label box sebelum memulai inspeksi workbench.";
        if (helpText) {
            helpText.innerHTML = 'Sistem membaca Ref No, Lot No, Qty secara otomatis. Scan bertahap hingga target Qty terpenuhi. Shortcut Mode: <kbd class="px-1 py-0.5 bg-slate-200 text-slate-700 rounded text-[9px] font-mono">Alt + M</kbd>';
        }
    } else if (autoStartSingleLabel) {
        btn.className = "px-2.5 py-1 rounded-lg text-[10px] font-extrabold bg-purple-600 text-white border border-purple-700 shadow-2xs cursor-pointer flex items-center space-x-1";
        btn.innerHTML = '<span>Mode Auto-Start (1 Label)</span>';
        btn.title = "Scan 1 label box langsung otomatis memulai inspeksi workbench. Tekan Alt+M untuk ubah.";
        if (helpText) {
            helpText.innerHTML = 'Scan 1 label box untuk langsung mulai inspeksi workbench. Shortcut Mode: <kbd class="px-1 py-0.5 bg-slate-200 text-slate-700 rounded text-[9px] font-mono">Alt + M</kbd>';
        }
    } else {
        btn.className = "px-2.5 py-1 rounded-lg text-[10px] font-extrabold bg-slate-200 text-slate-700 border border-slate-300 shadow-2xs cursor-pointer flex items-center space-x-1";
        btn.innerHTML = '<span>Mode Multi-Label (Batch)</span>';
        btn.title = "Kumpulkan beberapa label QR terlebih dahulu sebelum mulai inspeksi. Tekan Alt+M untuk ubah.";
        if (helpText) {
            helpText.innerHTML = 'Sistem membaca Ref No, Lot No, Qty secara otomatis. Scan bertahap hingga target Qty terpenuhi. Shortcut Mode: <kbd class="px-1 py-0.5 bg-slate-200 text-slate-700 rounded text-[9px] font-mono">Alt + M</kbd>';
        }
    }
}

function getActiveWorkbenchLot() {
    var lots = (window.lastWorkbenchSessionData && Array.isArray(window.lastWorkbenchSessionData.session_lots)) 
        ? window.lastWorkbenchSessionData.session_lots 
        : (typeof currentSessionLots !== 'undefined' && Array.isArray(currentSessionLots) ? currentSessionLots : []);
        
    if (!Array.isArray(lots) || lots.length === 0) return null;

    // 1. Prioritaskan lot yang sedang dibuka/dipilih pada tab (activeFocusLotId)
    if (typeof activeFocusLotId !== 'undefined' && activeFocusLotId > 0) {
        var selectedLot = lots.find(function(l) { return parseInt(l.id) === parseInt(activeFocusLotId); });
        if (selectedLot) return selectedLot;
    }

    // 2. Fallback: Cari lot pertama yang ber-status in_progress
    for (var i = 0; i < lots.length; i++) {
        var lot = lots[i];
        var res = lot.lot_result || 'in_progress';
        var status = lot.lot_status || 'ok';
        if (res === 'in_progress' && status !== 'replaced') {
            return lot;
        }
    }
    return lots[0];
}

/**
 * QR Code Barcode Format Parser (formatqr.md)
 * Format: Z1inicodePart|Z2LotPart|Z3Qty|Z4line|Z5ref_number
 */
function parseBarcodeQR(code) {
    let obj = {};
    if (!code) return obj;
    let parts = code.split('|');
    parts.forEach(function(p) {
        p = p.trim();
        if (p.startsWith('Z1')) obj.Z1 = p.substring(2).trim().toUpperCase();
        if (p.startsWith('Z2')) obj.Z2 = p.substring(2).trim().toUpperCase();
        if (p.startsWith('Z3')) {
            let valZ3 = parseInt(p.substring(2), 10);
            if (!isNaN(valZ3) && valZ3 > 0) {
                obj.Z3 = valZ3;
            }
        }
        if (p.startsWith('Z4')) obj.Z4 = p.substring(2).trim().toUpperCase();
        if (p.startsWith('Z5')) obj.Z5 = p.substring(2).trim().toUpperCase();
    });
    return obj;
}

function closeSwalSafely() {
    try {
        if (typeof Swal !== 'undefined') {
            if (typeof Swal.close === 'function') Swal.close();
            else if (typeof Swal.closePopup === 'function') Swal.closePopup();
            else if (typeof Swal.closeModal === 'function') Swal.closeModal();
            else if (typeof Swal.clickConfirm === 'function') Swal.clickConfirm();
        }
        if (typeof swal !== 'undefined' && typeof swal.close === 'function') {
            swal.close();
        }
    } catch (e) {}
    if (typeof window.refocusActiveScanner === 'function') {
        setTimeout(window.refocusActiveScanner, 60);
    }
}

function setLotFromModal(lotNum) {
    if (lotNum) {
        document.getElementById('scan-lot-number').value = lotNum;
        closeSwalSafely();
    }
}

function handleScanInputKeydown(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        e.stopPropagation();
        var val = e.target.value.trim();
        if (val) {
            parseBarcodeQRInput(val, true);
        } else if (typeof scannedLabelsList !== 'undefined' && scannedLabelsList.length > 0) {
            triggerModalValidation();
        }
        return false;
    }
}

function parseBarcodeQRInput(val, forceProcess) {
    if (!val) return;
    var parsed = parseBarcodeQR(val);

    // 1. EARLY PART CODE MISMATCH CHECK:
    // Jika planning sudah dipilih dan barcode yang discan memiliki Z1 berbeda, jangan biarkan input form tertimpa!
    // Khusus Safety Stock Gudang: Jika BELUM ada label box yang masuk (scannedLabelsList.length === 0), izinkan ganti Part Code fleksibel!
    var isSafetyStockEmpty = (selectedPlanningType === 'safety_stock' && typeof scannedLabelsList !== 'undefined' && scannedLabelsList.length === 0);

    if (parsed.Z1 && selectedPlanningPartCode && parsed.Z1.toUpperCase() !== selectedPlanningPartCode.toUpperCase()) {
        if (isSafetyStockEmpty) {
            // Flexible switch Part Code in Safety Stock when no label boxes have been added yet
            selectedPlanningPartCode = parsed.Z1;
            if (document.getElementById('selected-plan-part-code')) document.getElementById('selected-plan-part-code').textContent = parsed.Z1;
            if (document.getElementById('scan-part-code')) document.getElementById('scan-part-code').value = parsed.Z1;
        } else if (forceProcess || val.includes('\n') || val.includes('\r')) {
            Swal.fire({
                icon: 'error',
                title: '⚠️ PART CODE TIDAK COCOK!',
                html: '<div style="font-size: 12px; text-align: left; line-height: 1.6; color: #334155;">' +
                      'Part Code pada label (<b style="color:#be123c;">"' + escapeHtml(parsed.Z1) + '"</b>) tidak cocok dengan Part Code Kanban yang sedang diinspeksi (<b style="color:#0284c7;">"' + escapeHtml(selectedPlanningPartCode) + '"</b>)!<br><br>' +
                      '<b style="color:#e11d48;">Pastikan Anda men-scan label box yang sesuai dengan Kanban ini!</b>' +
                      '</div>'
            });
            // Rollback & bersihkan field form seketika
            if (document.getElementById('scan-part-code')) document.getElementById('scan-part-code').value = selectedPlanningPartCode;
            if (document.getElementById('scan-lot-number')) document.getElementById('scan-lot-number').value = '';
            if (document.getElementById('scan-label-qty')) document.getElementById('scan-label-qty').value = '';
            if (document.getElementById('scan-ref-number')) document.getElementById('scan-ref-number').value = '';
            if (document.getElementById('scan-remarks')) document.getElementById('scan-remarks').value = '';
            if (document.getElementById('scan-qr-raw')) document.getElementById('scan-qr-raw').value = '';
            setTimeout(function() {
                var rawInp = document.getElementById('scan-qr-raw');
                if (rawInp) rawInp.focus();
            }, 100);
            return;
        } else {
            return;
        }
    }

    var effectivePartCode = parsed.Z1 || (document.getElementById('scan-part-code') ? document.getElementById('scan-part-code').value.trim() : '') || selectedPlanningPartCode;

    // Live update form fields for visual feedback without triggering DID check
    if (parsed.Z1 && document.getElementById('scan-part-code')) document.getElementById('scan-part-code').value = parsed.Z1;
    if (parsed.Z2 && document.getElementById('scan-lot-number')) document.getElementById('scan-lot-number').value = parsed.Z2;
    if (parsed.Z3 && parsed.Z3 > 0 && document.getElementById('scan-label-qty')) document.getElementById('scan-label-qty').value = parsed.Z3;
    if (parsed.Z4 && document.getElementById('scan-remarks')) document.getElementById('scan-remarks').value = parsed.Z4;
    if (parsed.Z5 && document.getElementById('scan-ref-number')) document.getElementById('scan-ref-number').value = parsed.Z5;

    // HANYA SUBMIT jika user/scanner menekan ENTER (forceProcess === true) atau terdapat Newline (\n / \r) dari hardware scanner
    var hasNewline = (val.includes('\n') || val.includes('\r'));

    if (forceProcess || hasNewline) {
        var lotToUse = parsed.Z2 || (document.getElementById('scan-lot-number') ? document.getElementById('scan-lot-number').value.trim() : '') || val.toUpperCase();
        var rawInputQty = document.getElementById('scan-label-qty') ? parseInt(document.getElementById('scan-label-qty').value) : 0;
        var qtyToUse = parsed.Z3 || (rawInputQty > 0 ? rawInputQty : 0);
        var remToUse = parsed.Z4 || (document.getElementById('scan-remarks') ? document.getElementById('scan-remarks').value.trim() : '');
        var refToUse = parsed.Z5 || (document.getElementById('scan-ref-number') ? document.getElementById('scan-ref-number').value.trim() : '');

        if (!qtyToUse || qtyToUse <= 0) {
            Swal.fire({ icon: 'warning', title: 'Qty Box Wajib Diisi', text: 'Qty Box tidak terbaca dari barcode dan belum diisi di form. Silakan isi field Qty Box secara manual!' });
            return;
        }

        if (!refToUse) {
            Swal.fire({ icon: 'warning', title: 'Ref Number Wajib Diisi', text: 'Ref Number tidak terbaca dari barcode. Silakan isi field Ref Number secara manual sebelum menambah label.' });
            return;
        }

        if (!effectivePartCode || !lotToUse) return;

        addScannedLabelFromObj({
            Z1: effectivePartCode,
            Z2: lotToUse,
            Z3: qtyToUse,
            Z4: remToUse,
            Z5: refToUse,
            raw: val
        });
        if (document.getElementById('scan-qr-raw')) document.getElementById('scan-qr-raw').value = '';
    }
}

function handleManualAddLabel() {
    var pCode = document.getElementById('scan-part-code').value.trim();
    var lNum = document.getElementById('scan-lot-number').value.trim();
    var qty = parseInt(document.getElementById('scan-label-qty').value) || 0;
    var rem = document.getElementById('scan-remarks').value.trim();
    var ref = document.getElementById('scan-ref-number').value.trim();

    if (!qty || qty <= 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Qty Box Wajib Diisi',
            text: 'Qty Box wajib diisi dan harus lebih besar dari 0!'
        });
        return;
    }

    if (!ref) {
        Swal.fire({ icon: 'warning', title: 'Ref Number Wajib Diisi', text: 'Silakan isi Ref Number terlebih dahulu. Ref Number harus diisi manual oleh user.' });
        return;
    }

    if (!pCode || !lNum) {
        Swal.fire({
            icon: 'warning',
            title: 'Input Tidak Lengkap',
            text: 'Part Code dan Lot Number wajib diisi!'
        });
        return;
    }

    addScannedLabelFromObj({
        Z1: pCode,
        Z2: lNum,
        Z3: qty,
        Z4: rem,
        Z5: ref,
        raw: 'MANUAL|Z1' + pCode + '|Z2' + lNum + '|Z3' + qty
    });

    document.getElementById('scan-lot-number').value = '';
    document.getElementById('scan-label-qty').value = '';
    document.getElementById('scan-ref-number').value = '';
    document.getElementById('scan-remarks').value = '';
}

/**
 * Modal Khusus: Tampilan Jelas & Premium untuk Label Duplikat yang SUDAH PASSED
 */
function showDuplicatePassedModal(data, fallbackInfo, onClosedCallback) {
    data = data || {};
    fallbackInfo = fallbackInfo || {};
    var details = data.details || data.session || {};
    var sData = window.lastWorkbenchSessionData || {};
    var curSess = (sData && sData.session) ? sData.session : ((typeof currentSessionObj !== 'undefined' && currentSessionObj) ? currentSessionObj : {});

    // 1. Resolve Part Code
    var pCode = details.part_code || fallbackInfo.part_code || (curSess && curSess.part_code) || (typeof currentSessionPartCode !== 'undefined' ? currentSessionPartCode : '');
    if ((!pCode || pCode === '-') && data.message) {
        var mP = data.message.match(/Part\s*["']?([^"'/]+)["']?/i);
        if (mP) pCode = mP[1].trim();
    }
    pCode = pCode || '-';

    // 2. Resolve Part Name
    var pName = details.part_name || fallbackInfo.part_name || (curSess && (curSess.part_name || curSess.item_description)) || '';
    if ((!pName || pName === '-') && typeof MASTER_PARTS_CACHE !== 'undefined' && MASTER_PARTS_CACHE[pCode]) {
        pName = MASTER_PARTS_CACHE[pCode];
    }
    pName = pName || '-';

    // 3. Resolve Lot Number
    var lNum = details.lot_number || fallbackInfo.lot_number || '';
    if ((!lNum || lNum === '-') && data.message) {
        var mL = data.message.match(/Lot\s*["']?([^"'/]+)["']?/i);
        if (mL) lNum = mL[1].trim();
    }
    lNum = lNum || '-';

    // 4. Resolve Ref Number (Z5)
    var rNum = details.ref_number || fallbackInfo.ref_number || '';
    if ((!rNum || rNum === '-') && data.message) {
        var mR = data.message.match(/Ref\s*["']?([^"'/]+)["']?/i);
        if (mR) rNum = mR[1].trim();
    }
    rNum = rNum || '-';

    // 5. Prior Session Metadata
    var origSessId = details.session_id || details.id || '';
    if (!origSessId && data.message) {
        var mS = data.message.match(/Sesi\s*#(\d+)/i);
        if (mS) origSessId = mS[1];
    }
    var kanbanNo = details.kanban_no || '';
    if (!kanbanNo && data.message) {
        var mK = data.message.match(/Kanban\s+([^)]+)/i);
        if (mK) kanbanNo = mK[1].trim();
    }
    var isSafetyStock = (data.is_safety_stock === true) || 
                        (details.inspection_type === 'safety_stock') || 
                        (kanbanNo && kanbanNo.indexOf('SS-') === 0) ||
                        (data.message && data.message.toLowerCase().indexOf('safety stock') !== -1);

    var sessBadgeText = '';
    if (isSafetyStock) {
        sessBadgeText = origSessId ? ('Safety Stock #' + origSessId) : 'Safety Stock Ready';
    } else if (origSessId) {
        sessBadgeText = 'Sesi #' + origSessId;
        if (kanbanNo && kanbanNo !== '-') {
            sessBadgeText += ' (Kanban ' + kanbanNo + ')';
        }
    } else {
        sessBadgeText = (kanbanNo && kanbanNo !== '-') ? ('Kanban ' + kanbanNo) : 'Sesi Inspeksi Sebelumnya';
    }

    var inspector = details.inspector_name || 'Inspector QC';
    var closedAt = details.closed_at || '-';
    var boxQty = parseInt(details.qty || fallbackInfo.qty || 0, 10);

    // Render langsung ke In-Place Live Scan Alert Panel (Bawah Form Scan, Zero-Modal)
    var panel = document.getElementById('scan-live-status-panel');
    var neutralEl = document.getElementById('live-status-content-neutral');
    var successEl = document.getElementById('live-status-content-success');
    var rejectedEl = document.getElementById('live-status-content-rejected');

    if (panel && neutralEl && successEl && rejectedEl) {
        neutralEl.classList.add('hidden');
        successEl.classList.add('hidden');
        panel.style.backgroundColor = isSafetyStock ? '#fdf4ff' : '#fff1f2';
        panel.style.borderColor = isSafetyStock ? '#d946ef' : '#f43f5e';

        var titleText = isSafetyStock ? 'SUDAH SAFETY STOCK (PASSED)!' : 'DUPLIKASI DATA / LABEL SUDAH PASSED!';
        var headerColor = isSafetyStock ? '#86198f' : '#be123c';
        var iconBg = isSafetyStock ? '#a21caf' : '#e11d48';
        var borderBottomColor = isSafetyStock ? '#f5d0fe' : '#fecdd3';
        var descText = isSafetyStock 
            ? 'Label box ini <span style="text-decoration: underline;">sudah Safety Stock (Lulus QC)</span>. Tidak perlu dicek lagi, gunakan langsung pada Jadwal Kanban bila dibutuhkan.'
            : 'Label box ini <span style="text-decoration: underline;">sudah pernah diinspeksi & dinyatakan PASSED</span>. Tidak dapat digunakan/discan ulang.';

        rejectedEl.innerHTML = 
            '<!-- Header Status -->' +
            '<div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; border-bottom: 1.5px solid ' + borderBottomColor + '; padding-bottom: 5px; margin-bottom: 6px;">' +
                '<div style="display: flex; align-items: center; gap: 6px;">' +
                    '<span style="width: 20px; height: 20px; border-radius: 50%; background-color: ' + iconBg + '; color: #ffffff; display: inline-flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 900; flex-shrink: 0; box-shadow: 0 1px 3px rgba(0,0,0,0.2);">' + (isSafetyStock ? '📦' : '✕') + '</span>' +
                    '<span style="font-size: 11.5px; font-weight: 900; color: ' + headerColor + '; text-transform: uppercase; letter-spacing: 0.03em;">' + titleText + '</span>' +
                '</div>' +
                '<span style="background-color: #0f172a; color: #34d399; border: 1px solid #334155; font-size: 9px; font-weight: 900; padding: 2px 7px; border-radius: 4px; display: inline-flex; align-items: center; gap: 3px;">✓ ' + escapeHtml(sessBadgeText) + '</span>' +
            '</div>' +
            '<!-- Keterangan Ringkas & Padat -->' +
            '<div style="font-size: 11px; font-weight: 700; color: ' + (isSafetyStock ? '#701a75' : '#881337') + '; margin-bottom: 6px; line-height: 1.35;">' +
                descText +
            '</div>' +
            '<!-- 3 Parameter Boxes: Lot No -> Ref No -> Qty Box (Urutan Standar OQC) -->' +
            '<div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; margin-bottom: 6px;">' +
                '<div style="background-color: #ffffff; border: 1.5px solid ' + borderBottomColor + '; border-radius: 7px; padding: 4px 7px;">' +
                    '<span style="font-size: 8px; font-weight: 800; color: ' + headerColor + '; text-transform: uppercase; display: block;">LOT NUMBER</span>' +
                    '<span style="font-family: monospace; font-size: 13.5px; font-weight: 900; color: #0f172a; display: block; line-height: 1.2; word-break: break-all;">#' + escapeHtml(lNum) + '</span>' +
                '</div>' +
                '<div style="background-color: #ffffff; border: 1.5px solid ' + borderBottomColor + '; border-radius: 7px; padding: 4px 7px;">' +
                    '<span style="font-size: 8px; font-weight: 800; color: ' + headerColor + '; text-transform: uppercase; display: block;">REF NUMBER</span>' +
                    '<span style="font-family: monospace; font-size: 13.5px; font-weight: 900; color: ' + (isSafetyStock ? '#9333ea' : '#be123c') + '; display: block; line-height: 1.2; word-break: break-all;">' + escapeHtml(rNum) + '</span>' +
                '</div>' +
                '<div style="background-color: #ffffff; border: 1.5px solid ' + borderBottomColor + '; border-radius: 7px; padding: 4px 7px;">' +
                    '<span style="font-size: 8px; font-weight: 800; color: ' + headerColor + '; text-transform: uppercase; display: block;">QTY BOX</span>' +
                    '<span style="font-family: monospace; font-size: 13.5px; font-weight: 900; color: #0f172a; display: block; line-height: 1.2;">' + (boxQty > 0 ? boxQty.toLocaleString() : '-') + ' <span style="font-size: 9.5px; font-weight: 700; color: #64748b;">pcs</span></span>' +
                '</div>' +
            '</div>' +
            '<!-- Audit Info -->' +
            '<div style="display: flex; align-items: center; justify-content: space-between; font-size: 9.5px; color: ' + headerColor + '; background-color: ' + (isSafetyStock ? '#fae8ff' : '#ffe4e6') + '; padding: 3px 8px; border-radius: 5px;">' +
                '<span>Part: <b>' + escapeHtml(pCode) + '</b> (' + escapeHtml(pName) + ')</span>' +
                '<span>Inspector: <b>' + escapeHtml(inspector) + '</b></span>' +
            '</div>';

        rejectedEl.classList.remove('hidden');
    }

    if (typeof onClosedCallback === 'function') {
        onClosedCallback();
    }
}

/**
 * Modal Khusus: Tampilan Jelas & Premium untuk Label yang Sedang IN PROGRESS di Sesi Lain
 */
function showDuplicateInProgressModal(data, fallbackInfo, onClosedCallback) {
    data = data || {};
    fallbackInfo = fallbackInfo || {};
    var details = data.session || data.details || {};
    var sData = window.lastWorkbenchSessionData || {};
    var curSess = (sData && sData.session) ? sData.session : ((typeof currentSessionObj !== 'undefined' && currentSessionObj) ? currentSessionObj : {});

    var pCode = details.part_code || fallbackInfo.part_code || (curSess && curSess.part_code) || (typeof currentSessionPartCode !== 'undefined' ? currentSessionPartCode : '');
    if ((!pCode || pCode === '-') && data.message) {
        var mP = data.message.match(/Part\s*["']?([^"'/]+)["']?/i);
        if (mP) pCode = mP[1].trim();
    }
    pCode = pCode || '-';

    var pName = details.part_name || fallbackInfo.part_name || (curSess && (curSess.part_name || curSess.item_description)) || '-';
    var lNum = details.lot_number || fallbackInfo.lot_number || '';
    if ((!lNum || lNum === '-') && data.message) {
        var mL = data.message.match(/Lot\s*["']?([^"'/]+)["']?/i);
        if (mL) lNum = mL[1].trim();
    }
    lNum = lNum || '-';

    var rNum = details.ref_number || fallbackInfo.ref_number || '';
    if ((!rNum || rNum === '-') && data.message) {
        var mR = data.message.match(/Ref\s*["']?([^"'/]+)["']?/i);
        if (mR) rNum = mR[1].trim();
    }
    rNum = rNum || '-';

    var origSessId = details.session_id || details.id || '';
    if (!origSessId && data.message) {
        var mS = data.message.match(/Sesi\s*#(\d+)/i);
        if (mS) origSessId = mS[1];
    }
    var kanbanNo = details.kanban_no || '';
    var sessBadgeText = origSessId ? ('Sesi #' + origSessId + (kanbanNo ? ' (Kanban ' + kanbanNo + ')' : '')) : 'Sesi Lain';
    var lineName = details.line_name || '-';
    var inspector = details.inspector_name || 'Inspector QC';

    // Render langsung ke In-Place Live Scan Alert Panel (Bawah Form Scan, Zero-Modal)
    var panel = document.getElementById('scan-live-status-panel');
    var neutralEl = document.getElementById('live-status-content-neutral');
    var successEl = document.getElementById('live-status-content-success');
    var rejectedEl = document.getElementById('live-status-content-rejected');

    if (panel && neutralEl && successEl && rejectedEl) {
        neutralEl.classList.add('hidden');
        successEl.classList.add('hidden');
        panel.style.backgroundColor = '#eff6ff';
        panel.style.borderColor = '#3b82f6';

        rejectedEl.innerHTML = 
            '<!-- Header Status -->' +
            '<div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; border-bottom: 1.5px solid #bfdbfe; padding-bottom: 5px; margin-bottom: 6px;">' +
                '<div style="display: flex; align-items: center; gap: 6px;">' +
                    '<span style="width: 20px; height: 20px; border-radius: 50%; background-color: #2563eb; color: #ffffff; display: inline-flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 900; flex-shrink: 0; box-shadow: 0 1px 3px rgba(37,99,235,0.35);">ℹ</span>' +
                    '<span style="font-size: 11.5px; font-weight: 900; color: #1d4ed8; text-transform: uppercase; letter-spacing: 0.03em;">LABEL SEDANG DIINSPEKSI DI SESI LAIN!</span>' +
                '</div>' +
                '<span style="background-color: #0f172a; color: #60a5fa; border: 1px solid #334155; font-size: 9px; font-weight: 900; padding: 2px 7px; border-radius: 4px; display: inline-flex; align-items: center; gap: 3px;">⚡ IN PROGRESS</span>' +
            '</div>' +
            '<!-- Keterangan -->' +
            '<div style="font-size: 11px; font-weight: 700; color: #1e40af; margin-bottom: 6px; line-height: 1.35;">' +
                'Label box ini sedang <span style="text-decoration: underline;">aktif diinspeksi pada ' + escapeHtml(sessBadgeText) + '</span> (Line: ' + escapeHtml(lineName) + '). Tidak dapat discan ganda.' +
            '</div>' +
            '<!-- 3 Parameter Boxes: Lot No -> Ref No -> Qty Box -->' +
            '<div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; margin-bottom: 6px;">' +
                '<div style="background-color: #ffffff; border: 1.5px solid #bfdbfe; border-radius: 7px; padding: 4px 7px;">' +
                    '<span style="font-size: 8px; font-weight: 800; color: #1d4ed8; text-transform: uppercase; display: block;">LOT NUMBER</span>' +
                    '<span style="font-family: monospace; font-size: 13.5px; font-weight: 900; color: #0f172a; display: block; line-height: 1.2; word-break: break-all;">#' + escapeHtml(lNum) + '</span>' +
                '</div>' +
                '<div style="background-color: #ffffff; border: 1.5px solid #bfdbfe; border-radius: 7px; padding: 4px 7px;">' +
                    '<span style="font-size: 8px; font-weight: 800; color: #1d4ed8; text-transform: uppercase; display: block;">REF NUMBER</span>' +
                    '<span style="font-family: monospace; font-size: 13.5px; font-weight: 900; color: #1d4ed8; display: block; line-height: 1.2; word-break: break-all;">' + escapeHtml(rNum) + '</span>' +
                '</div>' +
                '<div style="background-color: #ffffff; border: 1.5px solid #bfdbfe; border-radius: 7px; padding: 4px 7px;">' +
                    '<span style="font-size: 8px; font-weight: 800; color: #1d4ed8; text-transform: uppercase; display: block;">PART CODE</span>' +
                    '<span style="font-family: monospace; font-size: 13.5px; font-weight: 900; color: #0f172a; display: block; line-height: 1.2;">' + escapeHtml(pCode) + '</span>' +
                '</div>' +
            '</div>' +
            '<!-- Audit Info -->' +
            '<div style="display: flex; align-items: center; justify-content: space-between; font-size: 9.5px; color: #1e3a8a; background-color: #dbeafe; padding: 3px 8px; border-radius: 5px;">' +
                '<span>Part: <b>' + escapeHtml(pName) + '</b></span>' +
                '<span>Inspector: <b>' + escapeHtml(inspector) + '</b></span>' +
            '</div>';

        rejectedEl.classList.remove('hidden');
    }

    if (typeof onClosedCallback === 'function') {
        onClosedCallback();
    }
}

function toggleManualFormVisibility() {
    var c = document.getElementById('manual-scan-form-container');
    var btn = document.getElementById('btn-toggle-manual-form');
    if (!c) return;
    if (c.classList.contains('hidden')) {
        c.classList.remove('hidden');
        if (btn) btn.innerHTML = '✕ Tutup Form Manual';
        var pCodeInp = document.getElementById('scan-part-code');
        if (pCodeInp) setTimeout(function() { pCodeInp.focus(); }, 100);
    } else {
        c.classList.add('hidden');
        if (btn) btn.innerHTML = '⌨️ Input Manual';
        var rawInp = document.getElementById('scan-qr-raw');
        if (rawInp) setTimeout(function() { rawInp.focus(); }, 100);
    }
}

/**
 * Update Dedicated In-Place Live Scan Alert Panel (Zero-Popup, Persistent)
 */
function toggleScanHistoryDrawer() {
    var panel = document.getElementById('scan-live-status-panel');
    var successEl = document.getElementById('live-status-content-success');
    var historyEl = document.getElementById('live-status-content-history');
    var rejectedEl = document.getElementById('live-status-content-rejected');
    var neutralEl = document.getElementById('live-status-content-neutral');

    if (!panel || !historyEl) return;

    if (historyEl.classList.contains('hidden')) {
        // Buka drawer riwayat
        if (successEl) successEl.classList.add('hidden');
        if (rejectedEl) rejectedEl.classList.add('hidden');
        if (neutralEl) neutralEl.classList.add('hidden');

        panel.style.backgroundColor = '#ffffff';
        panel.style.borderColor = '#3b82f6';
        renderScanHistoryDrawer();
        historyEl.classList.remove('hidden');
    } else {
        // Tutup drawer riwayat dan kembali ke tampilan normal
        historyEl.classList.add('hidden');
        if (scannedLabelsList && scannedLabelsList.length > 0) {
            if (successEl) successEl.classList.remove('hidden');
        } else {
            if (neutralEl) neutralEl.classList.remove('hidden');
            panel.style.backgroundColor = '#ffffff';
            panel.style.borderColor = '#e2e8f0';
        }
    }
}

function renderScanHistoryDrawer() {
    var historyEl = document.getElementById('live-status-content-history');
    if (!historyEl) return;

    var count = (typeof scannedLabelsList !== 'undefined') ? scannedLabelsList.length : 0;
    if (count === 0) {
        historyEl.innerHTML = 
            '<div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #e2e8f0; padding-bottom: 5px; margin-bottom: 6px;">' +
                '<span style="font-size: 11px; font-weight: 800; color: #1e293b;">🕒 RIWAYAT SCAN BOX</span>' +
                '<button type="button" onclick="toggleScanHistoryDrawer()" style="font-size: 10px; font-weight: 800; color: #64748b; background: none; border: none; cursor: pointer;">✕ Tutup</button>' +
            '</div>' +
            '<div style="text-align: center; padding: 12px 0; color: #94a3b8; font-size: 11px; font-weight: 600;">Belum ada label sukses discan</div>';
        return;
    }

    var itemsHtml = '';
    // Urutan terbaru di paling atas (slice & reverse)
    scannedLabelsList.slice().reverse().forEach(function(lbl, revIdx) {
        var origIdx = count - revIdx; // Nomor box asli (1-based)
        var refDisp = escapeHtml(lbl.Z5 || '-');
        var lotDisp = escapeHtml(lbl.Z2 || '-');
        var qtyDisp = (parseInt(lbl.Z3) || 0).toLocaleString();

        var isLabelReinspect = lbl.was_rejected_before ||
                              (lbl.remarks && (lbl.remarks.toUpperCase().indexOf('RE-INSPEKSI') !== -1 || lbl.remarks.toUpperCase().indexOf('REINSPEKSI') !== -1)) ||
                              (lbl.reinspection_notes && (lbl.reinspection_notes.toUpperCase().indexOf('RE-INSPEKSI') !== -1 || lbl.reinspection_notes.toUpperCase().indexOf('REINSPEKSI') !== -1));

        var tagHtml = '';
        var defectInfoHtml = '';
        if (lbl.is_safety_stock_alloc) {
            tagHtml = '<span style="font-size: 8.5px; font-weight: 800; color: #6d28d9; background-color: #f5f3ff; border: 1px solid #ddd6fe; padding: 1px 5px; border-radius: 4px;">📦 Safety Stock</span>';
        } else if (isLabelReinspect) {
            tagHtml = '<span style="font-size: 8.5px; font-weight: 800; color: #92400e; background-color: #fffbeb; border: 1px solid #fde68a; padding: 1px 5px; border-radius: 4px;">⚠️ Eks-NG</span>';
            if (lbl.rejected_info && Array.isArray(lbl.rejected_info.ng_defects) && lbl.rejected_info.ng_defects.length > 0) {
                var dStr = lbl.rejected_info.ng_defects.map(function(d){ return (d.defect_code ? '[' + escapeHtml(d.defect_code) + '] ' : '') + escapeHtml(d.defect_name || ''); }).join(', ');
                defectInfoHtml = '<div style="font-size: 9.5px; color: #be123c; font-weight: 700; margin-top: 1px;">Riwayat NG: ' + dStr + '</div>';
            }
        } else {
            tagHtml = '<span style="font-size: 8.5px; font-weight: 800; color: #047857; background-color: #ecfdf5; border: 1px solid #a7f3d0; padding: 1px 5px; border-radius: 4px;">✓ Fresh</span>';
        }

        itemsHtml += 
            '<div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 4px 8px; margin-bottom: 4px; display: flex; flex-direction: column;">' +
                '<div style="display: flex; align-items: center; justify-content: space-between;">' +
                    '<div style="display: flex; align-items: center; gap: 5px;">' +
                        '<span style="font-family: monospace; font-size: 11px; font-weight: 900; color: #2563eb;">#' + origIdx + '</span>' +
                        '<span style="font-family: monospace; font-size: 11px; font-weight: 800; color: #0f172a;">Lot #' + lotDisp + '</span>' +
                        '<span style="font-size: 10px; color: #64748b;">(Ref: ' + refDisp + ')</span>' +
                    '</div>' +
                    '<div style="display: flex; align-items: center; gap: 6px;">' +
                        tagHtml +
                        '<span style="font-family: monospace; font-size: 11.5px; font-weight: 900; color: #0f172a;">+' + qtyDisp + ' pcs</span>' +
                    '</div>' +
                '</div>' +
                defectInfoHtml +
            '</div>';
    });

    historyEl.innerHTML = 
        '<div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1.5px solid #cbd5e1; padding-bottom: 5px; margin-bottom: 6px;">' +
            '<div style="display: flex; align-items: center; gap: 5px;">' +
                '<span style="font-size: 11px; font-weight: 900; color: #1e293b;">🕒 RIWAYAT LABEL TERVERIFIKASI</span>' +
                '<span style="font-size: 9.5px; font-weight: 800; background-color: #dbeafe; color: #1e40af; padding: 1px 6px; border-radius: 9999px;">' + count + ' Box</span>' +
            '</div>' +
            '<button type="button" onclick="toggleScanHistoryDrawer()" style="font-size: 10px; font-weight: 800; color: #2563eb; background-color: #eff6ff; border: 1px solid #bfdbfe; padding: 2px 7px; border-radius: 4px; cursor: pointer;" onmouseover="this.style.backgroundColor=\'#dbeafe\';" onmouseout="this.style.backgroundColor=\'#eff6ff\';">✕ Tutup</button>' +
        '</div>' +
        '<div style="max-height: 115px; overflow-y: auto; padding-right: 2px;">' +
            itemsHtml +
        '</div>';
}

function resetLiveScanAlertPanel() {
    var panel = document.getElementById('scan-live-status-panel');
    var neutralEl = document.getElementById('live-status-content-neutral');
    var successEl = document.getElementById('live-status-content-success');
    var rejectedEl = document.getElementById('live-status-content-rejected');
    var historyEl = document.getElementById('live-status-content-history');

    if (!panel) return;
    panel.style.backgroundColor = '#ffffff';
    panel.style.borderColor = '#e2e8f0';

    if (successEl) {
        successEl.classList.add('hidden');
        successEl.innerHTML = '';
    }
    if (rejectedEl) {
        rejectedEl.classList.add('hidden');
        rejectedEl.innerHTML = '';
    }
    if (historyEl) {
        historyEl.classList.add('hidden');
        historyEl.innerHTML = '';
    }
    if (neutralEl) {
        neutralEl.classList.remove('hidden');
    }
}

function showLiveScanAlert(type, title, message, extraData) {
    var panel = document.getElementById('scan-live-status-panel');
    var neutralEl = document.getElementById('live-status-content-neutral');
    var successEl = document.getElementById('live-status-content-success');
    var rejectedEl = document.getElementById('live-status-content-rejected');
    var historyEl = document.getElementById('live-status-content-history');

    if (!panel || !neutralEl || !successEl || !rejectedEl) return;

    neutralEl.classList.add('hidden');
    successEl.classList.add('hidden');
    rejectedEl.classList.add('hidden');
    if (historyEl) historyEl.classList.add('hidden');

    var historyCount = (typeof scannedLabelsList !== 'undefined') ? scannedLabelsList.length : 0;
    var historyBtnHtml = (historyCount > 0) 
        ? '<button type="button" onclick="toggleScanHistoryDrawer()" style="font-size: 9.5px; font-weight: 800; color: #1e40af; background-color: #eff6ff; border: 1px solid #bfdbfe; padding: 1.5px 6px; border-radius: 4px; cursor: pointer; display: inline-flex; align-items: center; gap: 3px;" onmouseover="this.style.backgroundColor=\'#dbeafe\';" onmouseout="this.style.backgroundColor=\'#eff6ff\';" title="Lihat riwayat box yang berhasil discan">' +
            '🕒 Riwayat (' + historyCount + ') ▾' +
          '</button>'
        : '';

    if (type === 'success') {
        extraData = extraData || {};

        if (extraData.is_reinspect) {
            // Tampilan Khusus Eks-NG / Rework: Kartu Amber dengan riwayat defect lengkap (Otomatis Diterima, Tanpa Tombol)
            panel.style.backgroundColor = '#fffbeb';
            panel.style.borderColor = '#f59e0b';

            var info = extraData.rejected_info || {};
            var defectsHtml = '';
            if (Array.isArray(info.ng_defects) && info.ng_defects.length > 0) {
                defectsHtml = info.ng_defects.map(function(d) {
                    var dName = escapeHtml(d.defect_name || 'Defect');
                    var dCode = d.defect_code ? ('[' + escapeHtml(d.defect_code) + '] ') : '';
                    var dQty = (parseInt(d.qty_ng, 10) || 1) + ' pcs';
                    return '<div style="display: flex; align-items: center; justify-content: space-between; padding: 3px 8px; background-color: #fff1f2; border: 1px solid #fecdd3; border-radius: 5px; margin-top: 3px; font-size: 11px;">' +
                           '  <span style="font-weight: 700; color: #9f1239;">• ' + dCode + dName + '</span>' +
                           '  <span style="font-weight: 800; color: #be123c; font-family: monospace;">' + dQty + '</span>' +
                           '</div>';
                }).join('');
            } else if (info.remarks && info.remarks !== '-') {
                defectsHtml = '<div style="padding: 4px 8px; background-color: #fff1f2; border: 1px solid #fecdd3; border-radius: 5px; margin-top: 3px; font-size: 11px; color: #9f1239; font-weight: 600;">' +
                              '  Catatan: ' + escapeHtml(info.remarks) +
                              '</div>';
            } else {
                defectsHtml = '<div style="font-size: 10.5px; color: #92400e; font-style: italic; padding: 2px 4px;">• Defect Eks-NG / Perbaikan Tercatat</div>';
            }

            // Catatan Disposisi Admin / Divisi jika ada
            var disp = info.disposition || null;
            var dispositionHtml = '';
            if (disp && disp.has_disposition && disp.notes) {
                dispositionHtml = 
                    '<div style="background-color: #f0f9ff; border: 1.5px solid #bae6fd; border-radius: 6px; padding: 6px 9px; margin-top: 5px;">' +
                        '<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 3px;">' +
                            '<span style="font-size: 9.5px; font-weight: 800; color: #0369a1; text-transform: uppercase; letter-spacing: 0.03em; display: inline-flex; align-items: center; gap: 4px;">' +
                                '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>' +
                                'CATATAN DISPOSISI ADMIN / DIVISI:' +
                            '</span>' +
                            '<span style="font-size: 8.5px; font-weight: 800; color: #0284c7; background: #e0f2fe; border: 1px solid #bae6fd; padding: 1px 6px; border-radius: 4px;">Disposisi Selesai</span>' +
                        '</div>' +
                        '<div style="font-size: 11px; font-weight: 700; color: #0c4a6e; line-height: 1.35; background: #ffffff; border: 1px solid #e0f2fe; border-radius: 5px; padding: 5px 8px;">' +
                            '"' + escapeHtml(disp.notes) + '"' +
                        '</div>' +
                        '<div style="font-size: 9px; font-weight: 600; color: #0284c7; margin-top: 3px; display: flex; justify-content: space-between;">' +
                            '<span>Oleh: <b>' + escapeHtml(disp.closed_by || 'Admin') + '</b></span>' +
                            '<span>Waktu: <b>' + escapeHtml(disp.closed_at || '-') + '</b></span>' +
                        '</div>' +
                    '</div>';
            } else {
                dispositionHtml = 
                    '<div style="background-color: #fefce8; border: 1px solid #fef08a; border-radius: 6px; padding: 4px 8px; margin-top: 5px; display: flex; align-items: center; justify-content: space-between;">' +
                        '<span style="font-size: 9.5px; font-weight: 700; color: #854d0e;">' +
                            'ℹ <b>STATUS DISPOSISI:</b> Belum ada catatan disposisi resmi dari Admin / Divisi terkait.' +
                        '</span>' +
                        '<span style="font-size: 8.5px; font-weight: 800; color: #a16207; background: #fef9c3; border: 1px solid #fef08a; padding: 1px 5px; border-radius: 3px;">Pending Disposisi</span>' +
                    '</div>';
            }

            // Panduan & Kepastian untuk Inspektur (Bisa Re-inspeksi)
            var guidanceHtml = 
                '<div style="background-color: #ecfdf5; border: 1.5px solid #a7f3d0; border-radius: 6px; padding: 5px 8px; margin-top: 5px; display: flex; align-items: center; justify-content: space-between; gap: 8px;">' +
                    '<div style="display: flex; align-items: center; gap: 6px;">' +
                        '<span style="background-color: #059669; color: #ffffff; font-size: 9px; font-weight: 900; padding: 2px 6px; border-radius: 4px; text-transform: uppercase; white-space: nowrap;">' +
                            '✓ BISA DI-REINSPEKSI' +
                        '</span>' +
                        '<span style="font-size: 10px; font-weight: 700; color: #065f46; line-height: 1.25;">' +
                            'Box diizinkan masuk ke sesi inspeksi. Harap fokus memeriksa ulang titik defect sebelumnya saat sampling AQL.' +
                        '</span>' +
                    '</div>' +
                '</div>';

            successEl.innerHTML = 
                '<!-- Header Warning Eks-NG -->' +
                '<div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; border-bottom: 1.5px solid #fde68a; padding-bottom: 5px; margin-bottom: 6px;">' +
                    '<div style="display: flex; align-items: center; gap: 6px;">' +
                        '<span style="width: 20px; height: 20px; border-radius: 50%; background-color: #d97706; color: #ffffff; display: inline-flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 900; flex-shrink: 0; box-shadow: 0 1px 3px rgba(217,119,6,0.35);">⚠️</span>' +
                        '<span style="font-size: 11.5px; font-weight: 900; color: #92400e; text-transform: uppercase; letter-spacing: 0.03em;">PERINGATAN: LABEL PERNAH REJECTED!</span>' +
                    '</div>' +
                    '<div style="display: flex; align-items: center; gap: 4px;">' +
                        '<span style="background-color: #fef3c7; color: #92400e; border: 1px solid #fde68a; font-size: 9px; font-weight: 900; padding: 2px 6px; border-radius: 4px; display: inline-flex; align-items: center; gap: 3px;">EKS-NG / REWORK</span>' +
                        historyBtnHtml +
                    '</div>' +
                '</div>' +
                '<!-- 3 Prominent Parameter Boxes: Lot No -> Ref No -> Qty Box -->' +
                '<div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; margin-bottom: 6px;">' +
                    '<div style="background-color: #ffffff; border: 1.5px solid #fde68a; border-radius: 7px; padding: 4px 7px;">' +
                        '<span style="font-size: 8px; font-weight: 800; color: #92400e; text-transform: uppercase; display: block;">LOT NUMBER</span>' +
                        '<span style="font-family: monospace; font-size: 13.5px; font-weight: 900; color: #0f172a; display: block; line-height: 1.2; word-break: break-all;">#' + escapeHtml(extraData.lot || '-') + '</span>' +
                    '</div>' +
                    '<div style="background-color: #ffffff; border: 1.5px solid #fde68a; border-radius: 7px; padding: 4px 7px;">' +
                        '<span style="font-size: 8px; font-weight: 800; color: #92400e; text-transform: uppercase; display: block;">REF NUMBER</span>' +
                        '<span style="font-family: monospace; font-size: 13.5px; font-weight: 900; color: #b45309; display: block; line-height: 1.2; word-break: break-all;">' + escapeHtml(extraData.ref || '-') + '</span>' +
                    '</div>' +
                    '<div style="background-color: #ffffff; border: 1.5px solid #fde68a; border-radius: 7px; padding: 4px 7px;">' +
                        '<span style="font-size: 8px; font-weight: 800; color: #92400e; text-transform: uppercase; display: block;">QTY BOX MASUK</span>' +
                        '<span style="font-family: monospace; font-size: 13.5px; font-weight: 900; color: #0f172a; display: block; line-height: 1.2;">+' + (extraData.qty || 0).toLocaleString() + ' <span style="font-size: 10px; font-weight: 700; color: #92400e;">pcs</span></span>' +
                    '</div>' +
                '</div>' +
                '<!-- Rincian Defect Temuan (Informatif, Tanpa Tombol) -->' +
                '<div style="background-color: #fff1f2; border: 1px solid #fecdd3; border-radius: 6px; padding: 4px 8px;">' +
                    '<span style="font-size: 9px; font-weight: 800; color: #9f1239; text-transform: uppercase; display: block; margin-bottom: 2px;">RIWAYAT DEFECT / NG SEBELUMNYA:</span>' +
                    defectsHtml +
                '</div>' +
                dispositionHtml +
                guidanceHtml;
        } else {
            // Tampilan Standar Normal / Safety Stock
            panel.style.backgroundColor = '#f0fdf4';
            panel.style.borderColor = '#22c55e';

            var historyTagHtml = '';
            var replenishBannerHtml = '';

            if (extraData.is_replenished && extraData.replenish_info) {
                var rep = extraData.replenish_info;
                historyTagHtml = '<span style="background-color: #f0fdf4; color: #15803d; border: 1.5px solid #86efac; font-size: 9.5px; font-weight: 800; padding: 2px 7px; border-radius: 5px; display: inline-flex; align-items: center; gap: 3px;">🔄 Box Refill ' + (rep.original_barcode_qty || 200) + ' pcs</span>';
                
                replenishBannerHtml = 
                    '<div style="background-color: #eff6ff; border: 1.5px solid #bfdbfe; border-radius: 8px; padding: 8px 11px; margin-top: 6px; display: flex; align-items: flex-start; gap: 7px;">' +
                        '<span style="font-size: 15px; line-height: 1.2;">ℹ️</span>' +
                        '<div style="font-size: 11.5px; color: #1e40af; line-height: 1.4; font-weight: 500;">' +
                            '<b>INFO:</b> Box ini sebelumnya pernah diambil <b>' + (rep.previously_taken_qty || 0) + ' pcs</b> pada Kanban sebelumnya (' + (rep.parent_kanban_no || 'Sesi #' + rep.parent_session_id) + ') dan telah <b>digenapi kembali menjadi ' + (rep.original_barcode_qty || 200) + ' pcs</b>. ' +
                            '<br><br><b>PERHATIAN:</b> Tolong selalu cek jumlah stok pada box ini sebelum melakukan pengecekan.' +
                        '</div>' +
                    '</div>';
            } else if (extraData.is_safety_stock) {
                historyTagHtml = '<span style="background-color: #f5f3ff; color: #6d28d9; border: 1px solid #ddd6fe; font-size: 9.5px; font-weight: 800; padding: 2px 7px; border-radius: 5px; display: inline-flex; align-items: center; gap: 3px;">📦 Safety Stock</span>';
            } else {
                historyTagHtml = '<span style="background-color: #ecfdf5; color: #166534; border: 1px solid #bbf7d0; font-size: 9.5px; font-weight: 800; padding: 2px 7px; border-radius: 5px; display: inline-flex; align-items: center; gap: 3px;">✓ Lot Baru Fresh</span>';
            }

            successEl.innerHTML = 
                '<div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; border-bottom: 1.5px solid #bbf7d0; padding-bottom: 5px; margin-bottom: 6px;">' +
                    '<div style="display: flex; align-items: center; gap: 6px;">' +
                        '<span style="width: 20px; height: 20px; border-radius: 50%; background-color: #16a34a; color: #ffffff; display: inline-flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 900; flex-shrink: 0; box-shadow: 0 1px 3px rgba(22,163,74,0.35);">✓</span>' +
                        '<span style="font-size: 12px; font-weight: 900; color: #15803d; text-transform: uppercase; letter-spacing: 0.03em;">' + escapeHtml(title || 'SCAN BERHASIL DITERIMA') + '</span>' +
                    '</div>' +
                    '<div style="display: flex; align-items: center; gap: 4px;">' +
                        historyTagHtml +
                        historyBtnHtml +
                    '</div>' +
                '</div>' +
                '<!-- 3 Prominent Parameter Boxes: Lot No -> Ref No -> Qty Box -->' +
                '<div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px;">' +
                    '<div style="background-color: #ffffff; border: 1.5px solid #bbf7d0; border-radius: 7px; padding: 5px 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.02);">' +
                        '<span style="font-size: 8.5px; font-weight: 800; color: #166534; text-transform: uppercase; letter-spacing: 0.04em; display: block;">LOT NUMBER</span>' +
                        '<span style="font-family: monospace; font-size: 15px; font-weight: 900; color: #0f172a; display: block; line-height: 1.2; word-break: break-all;">#' + escapeHtml(extraData.lot || '-') + '</span>' +
                    '</div>' +
                    '<div style="background-color: #ffffff; border: 1.5px solid #bbf7d0; border-radius: 7px; padding: 5px 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.02);">' +
                        '<span style="font-size: 8.5px; font-weight: 800; color: #166534; text-transform: uppercase; letter-spacing: 0.04em; display: block;">REF NUMBER</span>' +
                        '<span style="font-family: monospace; font-size: 15px; font-weight: 900; color: #0f172a; display: block; line-height: 1.2; word-break: break-all;">' + escapeHtml(extraData.ref || '-') + '</span>' +
                    '</div>' +
                    '<div style="background-color: #ffffff; border: 1.5px solid #86efac; border-radius: 7px; padding: 5px 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.02);">' +
                        '<span style="font-size: 8.5px; font-weight: 800; color: #15803d; text-transform: uppercase; letter-spacing: 0.04em; display: block;">QTY BOX MASUK</span>' +
                        '<span style="font-family: monospace; font-size: 15px; font-weight: 900; color: #15803d; display: block; line-height: 1.2;">+' + (extraData.qty || 0).toLocaleString() + ' <span style="font-size: 10px; font-weight: 700; color: #16a34a;">pcs</span></span>' +
                    '</div>' +
                '</div>' +
                replenishBannerHtml;
        }
        successEl.classList.remove('hidden');
    } else {
        // Rejected / Error
        panel.style.backgroundColor = '#fff1f2';
        panel.style.borderColor = '#f43f5e';

        rejectedEl.innerHTML = 
            '<div style="display: flex; align-items: center; gap: 6px; margin-bottom: 3px;">' +
                '<span style="width: 18px; height: 18px; border-radius: 50%; background-color: #e11d48; color: #ffffff; display: inline-flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 900; flex-shrink: 0;">✕</span>' +
                '<span style="font-size: 11.5px; font-weight: 900; color: #be123c; text-transform: uppercase;">' + escapeHtml(title || 'SCAN DITOLAK') + '</span>' +
            '</div>' +
            '<div style="font-size: 11px; font-weight: 700; color: #881337; line-height: 1.35; padding-left: 24px;">' + (message || '') + '</div>';
        rejectedEl.classList.remove('hidden');
    }
}

function addScannedLabelFromObj(lbl) {
    // 1. Check Part Code matching with selected Planning / Active Session
    // Khusus Safety Stock Gudang: Jika BELUM ada label box yang masuk (scannedLabelsList.length === 0), izinkan ganti Part Code fleksibel!
    var isSafetyStockEmpty = (selectedPlanningType === 'safety_stock' && typeof scannedLabelsList !== 'undefined' && scannedLabelsList.length === 0);

    if ((!selectedPlanningPartCode || isSafetyStockEmpty) && lbl.Z1) {
        selectedPlanningPartCode = lbl.Z1;
        if (document.getElementById('selected-plan-part-code')) document.getElementById('selected-plan-part-code').textContent = lbl.Z1;
        if (document.getElementById('scan-part-code')) document.getElementById('scan-part-code').value = lbl.Z1;
    } else if (selectedPlanningPartCode && lbl.Z1.toUpperCase() !== selectedPlanningPartCode.toUpperCase()) {
        showLiveScanAlert('rejected', 'PART CODE TIDAK COCOK', 'Label part code <b>"' + escapeHtml(lbl.Z1) + '"</b> tidak sesuai dengan part planning aktif <b>"' + escapeHtml(selectedPlanningPartCode) + '"</b>.');
        // Rollback & bersihkan field form
        if (document.getElementById('scan-part-code')) document.getElementById('scan-part-code').value = selectedPlanningPartCode;
        if (document.getElementById('scan-lot-number')) document.getElementById('scan-lot-number').value = '';
        if (document.getElementById('scan-label-qty')) document.getElementById('scan-label-qty').value = '';
        if (document.getElementById('scan-ref-number')) document.getElementById('scan-ref-number').value = '';
        if (document.getElementById('scan-remarks')) document.getElementById('scan-remarks').value = '';
        if (document.getElementById('scan-qr-raw')) document.getElementById('scan-qr-raw').value = '';
        return;
    }

    // 2. Check duplicate box label in active scan list (Part Code + Lot Number + Ref Number match)
    var isDuplicateBox = scannedLabelsList.some(function(item) {
        var matchPart = (!item.Z1 || !lbl.Z1 || item.Z1.toUpperCase() === lbl.Z1.toUpperCase());
        var matchLot  = (item.Z2 && lbl.Z2 && item.Z2.toUpperCase() === lbl.Z2.toUpperCase());
        var matchRef  = ((item.Z5 || '').toUpperCase() === (lbl.Z5 || '').toUpperCase());
        return matchPart && matchLot && matchRef;
    });

    if (isDuplicateBox) {
        var refInfo = lbl.Z5 ? (' (Ref No: <b>' + escapeHtml(lbl.Z5) + '</b>)') : '';
        showLiveScanAlert('rejected', 'DUPLIKASI LABEL DALAM SESI', 'Label Part <b>' + escapeHtml(lbl.Z1) + '</b> dengan Lot <b>' + escapeHtml(lbl.Z2) + '</b>' + refInfo + ' sudah pernah discan dalam daftar di samping.');
        clearScanFormInputs();
        return;
    }

    // 3. Real-Time Validation: DID, Safety Stock, In-Progress Lock, Already Passed, and Rejected Warning
    var statusPanelMsg = document.getElementById('scan-status-msg');
    if (statusPanelMsg) statusPanelMsg.textContent = 'Memverifikasi status label Ref #' + (lbl.Z5 || lbl.Z2) + '...';

    var checkUrl = (window.OQC_CONFIG.baseUrl || '') + "/modules/inspection/scan_validate.php?mode=check_did_only&part_code=" + encodeURIComponent(lbl.Z1) + 
                   '&lot_number=' + encodeURIComponent(lbl.Z2) + 
                   '&ref_number=' + encodeURIComponent(lbl.Z5 || '') + 
                   '&barcode_qty=' + encodeURIComponent(lbl.Z3 || 0) +
                   '&inspection_type=' + encodeURIComponent(selectedPlanningType);

    fetch(checkUrl)
        .then(function(res) { return res.json(); })
        .then(function(data) {
            closeSwalSafely();
            if (!data.success) {
                // Skenario 0: Label / Lot Telah DI-ABOLISH (Scrap / Dimusnahkan Permanen)
                if (data.error_type === 'lot_abolished') {
                    var det = data.details || {};
                    var abNotes = det.notes || 'Karantina Dimusnahkan';
                    var abUser = det.closed_by || 'Admin/Supervisor';
                    var abDate = det.closed_at || '-';

                    Swal.fire({
                        icon: 'error',
                        title: '⛔ LABEL TELAH DI-ABOLISH!',
                        html: '<div style="font-size: 12px; text-align: left; line-height: 1.6; color: #334155;">' +
                              '<div style="background-color: #fee2e2; border-left: 4px solid #ef4444; padding: 10px 12px; border-radius: 6px; margin-bottom: 12px;">' +
                              '  <span style="font-size: 13px; font-weight: 900; color: #991b1b; display: block;">STATUS: PERMANENT SCRAP (ABOLISH)</span>' +
                              '  <span style="font-size: 11px; color: #b91c1c;">Label box ini telah dinyatakan scrap/dimusnahkan dan dilarang keras untuk diinspeksi atau dimasukkan ke jalur produksi!</span>' +
                              '</div>' +
                              '<div style="background-color: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 8px; padding: 10px; display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; margin-bottom: 10px;">' +
                              '  <div><span style="font-size: 9px; font-weight: 800; color: #64748b; text-transform: uppercase;">LOT NUMBER</span><span style="font-family: monospace; font-size: 13px; font-weight: 900; color: #0f172a; display: block;">#' + escapeHtml(lbl.Z2 || det.lot_number || '-') + '</span></div>' +
                              '  <div><span style="font-size: 9px; font-weight: 800; color: #64748b; text-transform: uppercase;">REF NUMBER</span><span style="font-family: monospace; font-size: 13px; font-weight: 900; color: #b91c1c; display: block;">' + escapeHtml(lbl.Z5 || det.ref_number || '-') + '</span></div>' +
                              '  <div><span style="font-size: 9px; font-weight: 800; color: #64748b; text-transform: uppercase;">CLOSED BY</span><span style="font-size: 11px; font-weight: 800; color: #1e293b; display: block;">' + escapeHtml(abUser) + '</span></div>' +
                              '  <div><span style="font-size: 9px; font-weight: 800; color: #64748b; text-transform: uppercase;">TANGGAL PENUTUPAN</span><span style="font-size: 11px; font-weight: 800; color: #1e293b; display: block;">' + escapeHtml(abDate) + '</span></div>' +
                              '</div>' +
                              '<div style="background-color: #f1f5f9; padding: 8px 10px; border-radius: 6px; font-size: 11px; color: #334155; margin-bottom: 10px;">' +
                              '  <b>Alasan / Catatan Penutupan:</b><br><i style="color: #64748b;">"' + escapeHtml(abNotes) + '"</i>' +
                              '</div>' +
                              '<p style="font-size: 11px; font-weight: 700; color: #be123c; margin: 0;">Silakan amankan label fisik ini dan pisahkan dari area kerja OQC.</p>' +
                              '</div>',
                        confirmButtonColor: '#ef4444',
                        confirmButtonText: 'Tutup & Singkirkan Box',
                        allowOutsideClick: false,
                        willClose: function() {
                            clearScanFormInputs();
                        }
                    });
                    showLiveScanAlert('rejected', 'LABEL TELAH DI-ABOLISH', 'Box Ref <b>' + escapeHtml(lbl.Z5 || '') + '</b> telah dinyatakan <b>ABOLISH / SCRAP</b> permanen.');
                    clearScanFormInputs();
                    return;
                }

                // Skenario: Safety Stock Habis (0 pcs)
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
                        confirmButtonColor: '#ef4444',
                        willClose: function() {
                            clearScanFormInputs();
                        }
                    });
                    clearScanFormInputs();
                    return;
                }

                // Skenario 2: Duplikasi Data / Label Sudah PASSED di Sesi Lain
                if (data.error_type === 'already_passed_duplicate') {
                    showDuplicatePassedModal(data, {
                        part_code: lbl.Z1,
                        part_name: lbl.Z4 || '',
                        lot_number: lbl.Z2,
                        ref_number: lbl.Z5
                    }, function() {
                        clearScanFormInputs();
                    });
                    clearScanFormInputs();
                    return;
                }

                // Skenario 4: Sedang In-Progress di Sesi Lain
                if (data.error_type === 'in_progress_duplicate') {
                    showDuplicateInProgressModal(data, {
                        part_code: lbl.Z1,
                        part_name: lbl.Z4 || '',
                        lot_number: lbl.Z2,
                        ref_number: lbl.Z5
                    }, function() {
                        clearScanFormInputs();
                    });
                    clearScanFormInputs();
                    return;
                }

                // Skenario 1.B: Master Part Belum Terdaftar
                if (data.error_type === 'master_part_missing') {
                    Swal.fire({
                        icon: 'error',
                        title: 'PART CODE BELUM TERDAFTAR!',
                        html: '<div style="font-size: 12px; text-align: left; line-height: 1.6; color: #334155;">' +
                              '<b style="color: #be123c;">' + escapeHtml(data.message || 'Part Code belum terdaftar di Master Data Part.') + '</b><br><br>' +
                              '<p style="font-size: 11px; color: #475569; background-color: #f8fafc; padding: 8px 10px; border-radius: 6px; border: 1px solid #e2e8f0;">' +
                              'Silakan daftarkan Part Code ini terlebih dahulu melalui menu <b>Master Data Part</b> sebelum melakukan inspeksi OQC.' +
                              '</p>' +
                              '</div>',
                        confirmButtonColor: '#ef4444',
                        timer: 4000,
                        timerProgressBar: true,
                        willClose: function() {
                            clearScanFormInputs();
                        }
                    });
                    clearScanFormInputs();
                    return;
                }

                // Error DID Missing (Memberikan 2 Opsi: Ganti Lot atau Request DID ke Admin/Supervisor)
                if (data.error_type === 'did_missing') {
                    Swal.fire({
                        icon: 'warning',
                        title: 'VALIDASI LABEL GAGAL!',
                        html: '<div style="font-size: 12px; text-align: left; line-height: 1.6; color: #334155;">' +
                              '<b style="color: #be123c;">' + escapeHtml(data.message || 'Lot Number belum tercatat/lolos di Daily Inspection Data (DID).') + '</b>' +
                              '<div style="margin-top: 10px; margin-bottom: 12px; background-color: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 10px; display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px;">' +
                              '  <div style="background-color: #f0f9ff; border: 1px solid #bae6fd; padding: 8px 10px; border-radius: 8px;">' +
                              '    <span style="display: block; font-size: 9px; font-weight: 800; color: #0369a1; text-transform: uppercase; letter-spacing: 0.05em;">PART CODE</span>' +
                              '    <span style="display: block; font-family: monospace; font-size: 14px; font-weight: 900; color: #0c4a6e; margin-top: 2px;">' + escapeHtml(lbl.Z1) + '</span>' +
                              '  </div>' +
                              '  <div style="background-color: #f5f3ff; border: 1px solid #ddd6fe; padding: 8px 10px; border-radius: 8px;">' +
                              '    <span style="display: block; font-size: 9px; font-weight: 800; color: #6d28d9; text-transform: uppercase; letter-spacing: 0.05em;">LOT NUMBER</span>' +
                              '    <span style="display: block; font-family: monospace; font-size: 14px; font-weight: 900; color: #4c1d95; margin-top: 2px;">' + escapeHtml(lbl.Z2) + '</span>' +
                              '  </div>' +
                              '</div>' +
                              '<p style="font-size: 11px; color: #64748b; background-color: #f8fafc; padding: 8px 10px; border-radius: 6px; border: 1px solid #e2e8f0;">' +
                              'Pilih <b>Request DID</b> untuk mengajukan verifikasi ke Supervisor/Admin, atau <b>Ganti Lot</b> untuk menembak barcode lot lain.' +
                              '</p>' +
                              '</div>',
                        showCancelButton: true,
                        confirmButtonColor: '#2563eb',
                        cancelButtonColor: '#64748b',
                        confirmButtonText: 'Request DID ke SPV',
                        cancelButtonText: 'Ganti Lot',
                        reverseButtons: true,
                        allowOutsideClick: false
                    }).then(function(resChoice) {
                        if (resChoice.isConfirmed) {
                            // Langsung kirim request ke SPV/Admin tanpa modal dialog alasan kedua
                            Swal.fire({
                                title: 'Sending Request...',
                                text: 'Mengirim notifikasi ke Admin & Supervisor...',
                                allowOutsideClick: false,
                                didOpen: function() { Swal.showLoading(); }
                            });

                            var formData = new FormData();
                            formData.append('session_id', currentSessionId || 0);
                            formData.append('part_code', lbl.Z1 || '');
                            formData.append('part_name', lbl.Z4 || '');
                            formData.append('lot_number', lbl.Z2 || '');
                            formData.append('reason', 'Pengajuan persetujuan DID dari Modal Scan QC');

                            fetch(window.OQC_CONFIG.baseUrl + "/modules/inspection/api/create_did_request.php", {
                                method: 'POST',
                                body: formData
                            })
                            .then(function(r) { return r.json(); })
                            .then(function(reqResult) {
                                closeSwalSafely();
                                if (reqResult.success) {
                                    Swal.fire({
                                        icon: 'success',
                                        title: 'Request DID Terkirim',
                                        text: reqResult.message || 'Request berhasil dikirim ke Admin & Supervisor.',
                                        confirmButtonColor: '#2563eb'
                                    });
                                    if (typeof refreshNotificationCount === 'function') {
                                        refreshNotificationCount();
                                    }
                                } else {
                                    Swal.fire({
                                        icon: 'error',
                                        title: 'Gagal Kirim Request',
                                        text: reqResult.message || 'Gagal mengirim request persetujuan DID.'
                                    });
                                }
                                clearScanFormInputs();
                            })
                            .catch(function(errReq) {
                                closeSwalSafely();
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error Server',
                                    text: 'Gagal terhubung ke server saat mengirim request.'
                                });
                                clearScanFormInputs();
                            });
                        } else {
                            clearScanFormInputs();
                        }
                    });
                    return;
                }

                // Error DID NG atau error validasi lainnya
                showLiveScanAlert('rejected', 'VALIDASI LABEL GAGAL', escapeHtml(data.message || 'Lot Number tidak dapat digunakan.') + '<br><span style="font-family:monospace; font-size:11px;">Part: ' + escapeHtml(lbl.Z1) + ' | Lot: ' + escapeHtml(lbl.Z2) + '</span>');
                clearScanFormInputs();
                return;
            }

            // Flag jika label berasal dari Safety Stock Gudang (tetap di-scan fisik ulang)
            if (data.is_safety_stock) {
                lbl.is_safety_stock_alloc = true;

                if (data.is_replenished_box && data.replenish_info) {
                    // Skenario: Box Eks-Potong Kanban yang telah digenapi kembali oleh Gudang ke Qty aslinya (misal 200 pcs)
                    lbl.is_replenished_box = true;
                    lbl.replenish_info = data.replenish_info;
                    lbl.Z3 = data.replenish_info.original_barcode_qty || lbl.Z3;

                    var repQtyInp = document.getElementById('scan-label-qty');
                    if (repQtyInp) repQtyInp.value = lbl.Z3;

                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'info',
                        title: 'Box Eks-Potong (Refill)',
                        text: 'Pernah diambil ' + data.replenish_info.previously_taken_qty + ' pcs. Digenapi kembali ke ' + lbl.Z3 + ' pcs oleh Gudang.',
                        timer: 4500,
                        showConfirmButton: false
                    });
                } else if (data.ss_lot && parseInt(data.ss_lot.available_qty, 10) > 0) {
                    var prevBarcodeQty = parseInt(lbl.Z3, 10) || 0;
                    var realAvailableQty = parseInt(data.ss_lot.available_qty, 10);
                    if (prevBarcodeQty !== realAvailableQty) {
                        lbl.Z3 = realAvailableQty;
                        var qtyInputEl = document.getElementById('scan-label-qty');
                        if (qtyInputEl) qtyInputEl.value = realAvailableQty;
                        Swal.fire({
                            toast: true,
                            position: 'top-end',
                            icon: 'info',
                            title: 'Safety Stock Terdeteksi',
                            text: 'Qty disesuaikan ke sisa stok gudang: ' + realAvailableQty + ' pcs (Barcode: ' + prevBarcodeQty + ' pcs)',
                            timer: 3500,
                            showConfirmButton: false
                        });
                    }
                }
            }

            // Skenario 3: Label Sebelumnya Pernah REJECTED (Eks-NG / Rework) -> Langsung diterima tanpa perlu klik konfirmasi
            if (data.was_rejected_before && data.rejected_info) {
                lbl.was_rejected_before = true;
                lbl.rejected_info = data.rejected_info;
            }

            // Langsung tambahkan box ke list (baik Fresh maupun Eks-NG)
            proceedAddLabelToList(lbl);
        })
        .catch(function(err) {
            console.error('Validation error:', err);
            closeSwalSafely();
            Swal.fire({
                icon: 'error',
                title: 'Gagal Memeriksa Status Label',
                text: 'Terjadi kesalahan sistem saat memverifikasi status label: ' + (err.message || 'Error koneksi server')
            });
        });
}


function proceedAddLabelToList(lbl) {
    var isSafetyStock = (typeof selectedPlanningType !== 'undefined' && selectedPlanningType === 'safety_stock') || (typeof currentPlanTypeTab !== 'undefined' && currentPlanTypeTab === 'safety_stock');

    scannedLabelsList.push(lbl);

    var physicalScanned = 0;
    scannedLabelsList.forEach(function(l) {
        physicalScanned += parseInt(l.Z3) || 0;
    });

    var totalSsCheckedQty = 0;
    if (typeof availableSsLots !== 'undefined' && Array.isArray(availableSsLots)) {
        availableSsLots.forEach(function(lot) {
            if (typeof selectedSsLotIds !== 'undefined' && selectedSsLotIds.indexOf(String(lot.lot_id)) !== -1) {
                totalSsCheckedQty += parseInt(lot.available_qty) || 0;
            }
        });
    }
    var usedSsQty = (typeof isSafetyStockDeducted !== 'undefined' && isSafetyStockDeducted && typeof selectedSsLotIds !== 'undefined' && selectedSsLotIds.length > 0) ? Math.min(selectedPlanningQty || 0, totalSsCheckedQty) : 0;
    var effectiveTotalScanned = physicalScanned + usedSsQty;

    var targetQty = isSafetyStock ? 0 : (selectedPlanningQty || 0);

    var refDisp = lbl.Z5 ? escapeHtml(lbl.Z5) : '-';
    var lotDisp = escapeHtml(lbl.Z2 || '-');
    var boxQtyDisp = (parseInt(lbl.Z3) || 0).toLocaleString();
    var boxIndex = scannedLabelsList.length;

    var progressHtml = '';
    if (!isSafetyStock && targetQty > 0) {
        var remainingQty = Math.max(0, targetQty - effectiveTotalScanned);
        var pct = Math.min(100, Math.round((effectiveTotalScanned / targetQty) * 100));
        var statusText = '';
        var statusBadgeColor = '#2563eb';
        var statusBgColor = '#eff6ff';

        if (effectiveTotalScanned >= targetQty) {
            statusText = 'Target Qty Terpenuhi!';
            statusBadgeColor = '#166534';
            statusBgColor = '#dcfce7';
        } else {
            statusText = 'Kurang ' + remainingQty.toLocaleString() + ' pcs lagi';
            statusBadgeColor = '#92400e';
            statusBgColor = '#fef3c7';
        }

        progressHtml = 
            '<div style="background:#f8fafc; border:1.5px solid #e2e8f0; border-radius:14px; padding:12px 18px; text-align:left; margin-top:14px;">' +
                '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">' +
                    '<span style="font-size:12px; font-weight:800; color:#334155; text-transform:uppercase; letter-spacing:0.5px;">Akumulasi Target Kanban</span>' +
                    '<span style="font-size:15px; font-weight:900; color:#0f172a; font-family:monospace;">' + effectiveTotalScanned.toLocaleString() + ' / ' + targetQty.toLocaleString() + ' pcs</span>' +
                '</div>' +
                '<div style="width:100%; height:9px; background:#e2e8f0; border-radius:9999px; overflow:hidden; margin-bottom:10px;">' +
                    '<div style="width:' + pct + '%; height:100%; background:linear-gradient(90deg, #2563eb 0%, #16a34a 100%); transition:width 0.3s ease;"></div>' +
                '</div>' +
                '<div style="display:flex; justify-content:space-between; align-items:center; font-size:12px;">' +
                    '<span style="color:#475569; font-weight:700;">' + pct + '% Terpenuhi</span>' +
                    '<span style="padding:4px 12px; border-radius:9999px; font-weight:800; font-size:11px; background:' + statusBgColor + '; color:' + statusBadgeColor + '; border:1px solid ' + (statusBadgeColor === '#166534' ? '#bbf7d0' : '#fde68a') + ';">' + statusText + '</span>' +
                '</div>' +
            '</div>';
    } else {
        progressHtml = 
            '<div style="background:#f8fafc; border:1.5px solid #e2e8f0; border-radius:14px; padding:12px 18px; text-align:left; margin-top:14px;">' +
                '<div style="display:flex; justify-content:space-between; align-items:center;">' +
                    '<span style="font-size:12px; font-weight:800; color:#334155; text-transform:uppercase; letter-spacing:0.5px;">Total Safety Stock Terkumpul</span>' +
                    '<span style="font-size:16px; font-weight:900; color:#15803d; font-family:monospace;">' + physicalScanned.toLocaleString() + ' pcs (' + boxIndex + ' Box)</span>' +
                '</div>' +
            '</div>';
    }

    var ssBannerTag = lbl.is_safety_stock_alloc 
        ? '<div style="background-color: #f3e8ff; border: 1.5px solid #c084fc; padding: 5px 12px; border-radius: 8px; color: #6b21a8; font-weight: 900; font-size: 11px; margin-top: 8px; display: inline-flex; align-items: center; gap: 6px;">' +
          '  <span>📦 ALOKASI SAFETY STOCK GUDANG</span>' +
          '</div>'
        : '';

    var modalHtml = 
        '<div style="text-align:center; padding: 6px 0;">' +
            '<div style="font-size: 21px; font-weight: 900; color: #0f172a; line-height: 1.25;">Label Berhasil Discan</div>' +
            '<div style="font-size: 14px; font-weight: 800; color: #2563eb; margin-top: 4px;">Box ke-' + boxIndex + ' telah masuk ke daftar</div>' +
            ssBannerTag +
            '<div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-top: 16px;">' +
                '<div style="background: #eff6ff; border: 1.5px solid #bfdbfe; border-radius: 12px; padding: 12px 8px; text-align: center;">' +
                    '<span style="display: block; font-size: 11px; font-weight: 800; color: #1e40af; text-transform: uppercase; margin-bottom: 4px; letter-spacing: 0.5px;">Ref Number</span>' +
                    '<span style="display: block; font-family: monospace; font-size: 17px; font-weight: 900; color: #1d4ed8; word-break: break-all;">' + refDisp + '</span>' +
                '</div>' +
                '<div style="background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 12px 8px; text-align: center;">' +
                    '<span style="display: block; font-size: 11px; font-weight: 800; color: #475569; text-transform: uppercase; margin-bottom: 4px; letter-spacing: 0.5px;">Lot Number</span>' +
                    '<span style="display: block; font-family: monospace; font-size: 17px; font-weight: 900; color: #0f172a; word-break: break-all;">#' + lotDisp + '</span>' +
                '</div>' +
                '<div style="background: #f0fdf4; border: 1.5px solid #bbf7d0; border-radius: 12px; padding: 12px 8px; text-align: center;">' +
                    '<span style="display: block; font-size: 11px; font-weight: 800; color: #166534; text-transform: uppercase; margin-bottom: 4px; letter-spacing: 0.5px;">Qty Box</span>' +
                    '<span style="display: block; font-family: monospace; font-size: 18px; font-weight: 900; color: #15803d;">+' + boxQtyDisp + ' pcs</span>' +
                '</div>' +
            '</div>' +
            progressHtml +
        '</div>';

    // Safety Stock sekarang Multi-Lot (tidak auto-start langsung ke workbench, kumpulkan seluruh box terlebih dahulu)
    var isAutoStartActive = !isSafetyStock && (autoStartSingleLabel && selectedPlanningType !== 'kanban');

    // Update in-place persistent live scan status panel (Zero-popup)
    showLiveScanAlert('success', 'BOX #' + boxIndex + ' BERHASIL DISCAN', 'Box fisik berhasil diverifikasi dan ditambahkan ke daftar kuota.', {
        ref: refDisp,
        lot: lotDisp,
        qty: parseInt(lbl.Z3) || 0,
        is_safety_stock: lbl.is_safety_stock_alloc,
        is_replenished: lbl.is_replenished_box,
        replenish_info: lbl.replenish_info,
        is_reinspect: lbl.was_rejected_before,
        rejected_info: lbl.rejected_info
    });

    if (isAutoStartActive) {
        if (typeof Toast !== 'undefined' && typeof Toast.fire === 'function') {
            Toast.fire({
                icon: 'success',
                title: 'Label Discan (1 Box) - Memuat Inspeksi...'
            });
        }
    }

    if (document.getElementById('scan-part-code')) document.getElementById('scan-part-code').value = lbl.Z1;
    if (document.getElementById('scan-lot-number')) document.getElementById('scan-lot-number').value = lbl.Z2;

    clearScanFormInputs();
    renderScannedLabelsTable();

    // Auto-scroll table container to the newly added row
    setTimeout(function() {
        var tableContainer = document.getElementById('scanned-table-scroll-container');
        if (tableContainer) {
            tableContainer.scrollTop = tableContainer.scrollHeight;
        }
    }, 60);

    if (isAutoStartActive) {
        setTimeout(function() {
            triggerModalValidation();
        }, 150);
    }
}

function clearScanFormInputs() {
    if (document.getElementById('scan-qr-raw')) document.getElementById('scan-qr-raw').value = '';
    if (document.getElementById('scan-lot-number')) document.getElementById('scan-lot-number').value = '';
    if (document.getElementById('scan-label-qty')) document.getElementById('scan-label-qty').value = '';
    if (document.getElementById('scan-ref-number')) document.getElementById('scan-ref-number').value = '';
    if (document.getElementById('scan-remarks')) document.getElementById('scan-remarks').value = '';
    setTimeout(function() {
        var rawInp = document.getElementById('scan-qr-raw');
        if (rawInp) rawInp.focus();
    }, 80);
}

function selectSafetyStockLotFromScan(ssLot) {
    // Stub aman: Seluruh box Safety Stock di-scan ulang secara fisik ke workbench sebagai lot aktif
}

function editScannedLabelQty(index) {
    if (index < 0 || index >= scannedLabelsList.length) return;
    var lbl = scannedLabelsList[index];
    var currentQty = parseInt(lbl.Z3) || 0;
    var refNo = lbl.Z5 || '-';
    var lotNo = lbl.Z2 || '-';

    Swal.fire({
        title: '✏️ Edit Qty Box Riil',
        html: '<div style="font-size: 12px; text-align: left; line-height: 1.6; color: #334155; margin-bottom: 12px;">' +
              'Label Ref: <b style="color: #1d4ed8; font-family: monospace;">' + escapeHtml(refNo) + '</b><br>' +
              'Lot Number: <b>' + escapeHtml(lotNo) + '</b><br>' +
              'Qty Label Barcode: <b>' + currentQty.toLocaleString() + ' pcs</b>' +
              '</div>' +
              '<div style="text-align: left;">' +
              '<label style="font-size: 11px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">Masukkan Qty Fisik Riil (pcs):</label>' +
              '<input type="number" id="swal-edit-qty-input" min="1" step="1" value="' + currentQty + '" style="width: 100%; padding: 8px 12px; font-size: 14px; font-weight: 700; font-family: monospace; border: 2px solid #3b82f6; border-radius: 8px; outline: none; box-sizing: border-box;" autofocus>' +
              '<p style="font-size: 10px; color: #64748b; margin-top: 4px;">Ubah jika isi barang fisik di dalam box berbeda dengan qty pada label barcode.</p>' +
              '</div>',
        showCancelButton: true,
        confirmButtonColor: '#2563eb',
        cancelButtonColor: '#64748b',
        confirmButtonText: '💾 Simpan Perubahan',
        cancelButtonText: 'Batal',
        didOpen: function() {
            var inp = document.getElementById('swal-edit-qty-input');
            if (inp) {
                inp.focus();
                inp.select();
                inp.addEventListener('keydown', function(ev) {
                    if (ev.key === 'Enter') {
                        ev.preventDefault();
                        if (typeof Swal !== 'undefined' && typeof Swal.clickConfirm === 'function') {
                            Swal.clickConfirm();
                        } else {
                            var btn = document.getElementById('swal-confirm-btn');
                            if (btn) btn.click();
                        }
                    }
                });
            }
        },
        preConfirm: function() {
            var inp = document.getElementById('swal-edit-qty-input');
            var val = parseInt(inp ? inp.value : 0);
            if (!val || val <= 0) {
                Swal.showValidationMessage('Qty harus berupa angka bulat dan lebih besar dari 0!');
                return false;
            }
            return val;
        }
    }).then(function(res) {
        if (res.isConfirmed && res.value > 0) {
            var newQty = res.value;
            scannedLabelsList[index].Z3 = newQty;
            renderScannedLabelsTable();

            if (typeof Swal !== 'undefined' && Swal.mixin) {
                var Toast = Swal.mixin({
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 1500,
                    timerProgressBar: true
                });
                Toast.fire({
                    icon: 'success',
                    title: '✓ Qty Box Ref #' + refNo + ' diperbarui jadi ' + newQty.toLocaleString() + ' pcs'
                });
            }
        }
    });
}

function removeScannedLabel(index) {
    if (index >= 0 && index < scannedLabelsList.length) {
        scannedLabelsList.splice(index, 1);
        renderScannedLabelsTable();
    }
}

function clearAllScannedLabels() {
    if (scannedLabelsList.length === 0) return;
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Hapus Semua Label?',
            text: 'Seluruh ' + scannedLabelsList.length + ' box terverifikasi pada sesi ini akan di-reset.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Ya, Reset Semua',
            cancelButtonText: 'Batal',
            reverseButtons: true
        }).then(function(res) {
            if (res.isConfirmed) {
                scannedLabelsList = [];
                if (typeof resetLiveScanAlertPanel === 'function') resetLiveScanAlertPanel();
                renderScannedLabelsTable();
            }
        });
    } else {
        if (confirm('Yakin ingin mereset semua label terverifikasi?')) {
            scannedLabelsList = [];
            if (typeof resetLiveScanAlertPanel === 'function') resetLiveScanAlertPanel();
            renderScannedLabelsTable();
        }
    }
}

function renderScannedLabelsTable() {
    updateAutoStartToggleUI();
    var tbody = document.getElementById('scanned-labels-table-body');
    var countSpan = document.getElementById('scanned-labels-count');
    var totalQtySpan = document.getElementById('scanned-total-qty');
    var targetQtySpan = document.getElementById('target-kanban-qty-display');
    var progressBar = document.getElementById('scan-progress-bar');
    var btnSubmit = document.getElementById('btn-submit-scan-modal');
    var btnClear = document.getElementById('btn-clear-labels');
    var excessMsg = document.getElementById('scan-excess-msg');
    var statusMsg = document.getElementById('scan-status-msg');

    var isSafetyStock = (selectedPlanningType === 'safety_stock');
    var physicalScanned = 0;
    scannedLabelsList.forEach(function(l) { physicalScanned += parseInt(l.Z3) || 0; });

    // Calculate Safety Stock Qty selected
    var usedSsQty = 0;
    if (!isSafetyStock && isSafetyStockDeducted && availableSsLots && availableSsLots.length > 0 && selectedSsLotIds && selectedSsLotIds.length > 0) {
        availableSsLots.forEach(function(lot) {
            if (selectedSsLotIds.indexOf(String(lot.lot_id)) !== -1) {
                usedSsQty += parseInt(lot.available_qty) || 0;
            }
        });
        usedSsQty = Math.min(selectedPlanningQty, usedSsQty);
    }

    var effectiveTotalScanned = physicalScanned + usedSsQty;
    var targetQty = isSafetyStock ? (physicalScanned > 0 ? physicalScanned : 0) : (selectedPlanningQty || 0);

    if (!tbody) return;

    if (scannedLabelsList.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="px-3 py-8 text-center text-slate-400 font-medium text-xs">Belum ada label QR fisik yang discan. Silakan scan barcode box di sebelah kiri.' + (usedSsQty > 0 ? ' (Menggunakan ' + usedSsQty.toLocaleString() + ' pcs Safety Stock)' : '') + '</td></tr>';
    } else {
        var html = '';
        scannedLabelsList.forEach(function(lbl, idx) {
            var refDisp = lbl.Z5 ? escapeHtml(lbl.Z5) : '-';
            var lotDisp = escapeHtml(lbl.Z2 || '-');
            var qtyDisp = (parseInt(lbl.Z3) || 0).toLocaleString();

            var isLabelReinspect = lbl.was_rejected_before ||
                                  (lbl.remarks && (lbl.remarks.toUpperCase().indexOf('RE-INSPEKSI') !== -1 || lbl.remarks.toUpperCase().indexOf('REINSPEKSI') !== -1)) ||
                                  (lbl.reinspection_notes && (lbl.reinspection_notes.toUpperCase().indexOf('RE-INSPEKSI') !== -1 || lbl.reinspection_notes.toUpperCase().indexOf('REINSPEKSI') !== -1));
            
            var historyBadgeHtml = '';
            if (lbl.is_safety_stock_alloc) {
                var ssTitle = (lbl.is_replenished_box && lbl.replenish_info) 
                    ? ('Safety Stock (Refill Gudang ' + (lbl.replenish_info.previously_taken_qty || 100) + ' pcs)') 
                    : 'Alokasi dari Gudang Safety Stock';
                historyBadgeHtml = '<span style="font-size: 9.5px; font-weight: 800; color: #6d28d9; background-color: #f5f3ff; border: 1px solid #ddd6fe; padding: 2px 7px; border-radius: 4px; display: inline-flex; align-items: center; gap: 3px;" title="' + ssTitle + '">📦 Asal: Safety Stock</span>';
            } else if (lbl.is_replenished_box) {
                var prevTaken = (lbl.replenish_info && lbl.replenish_info.previously_taken_qty) ? lbl.replenish_info.previously_taken_qty : '100';
                historyBadgeHtml = '<span style="font-size: 9.5px; font-weight: 800; color: #15803d; background-color: #f0fdf4; border: 1px solid #86efac; padding: 2px 7px; border-radius: 4px; display: inline-flex; align-items: center; gap: 3px;" title="Box ini sebelumnya pernah diambil ' + prevTaken + ' pcs dan telah digenapi kembali oleh Gudang">🔄 Refill (Eks-Potong ' + prevTaken + ' pcs)</span>';
            } else if (isLabelReinspect) {
                historyBadgeHtml = '<span style="font-size: 9.5px; font-weight: 800; color: #92400e; background-color: #fffbeb; border: 1px solid #fde68a; padding: 2px 7px; border-radius: 4px; display: inline-flex; align-items: center; gap: 3px;">⚠️ Riwayat: Eks-NG</span>';
            } else {
                historyBadgeHtml = '<span style="font-size: 9.5px; font-weight: 700; color: #047857; background-color: #ecfdf5; border: 1px solid #a7f3d0; padding: 2px 7px; border-radius: 4px; display: inline-flex; align-items: center; gap: 3px;">✓ Lot Baru (Fresh)</span>';
            }

            var originVal = 'fresh';
            if (lbl.is_safety_stock_alloc) {
                originVal = 'safety_stock';
            } else if (isLabelReinspect) {
                originVal = 'reinspect';
            }

            html += '<tr class="scanned-box-row hover:bg-blue-50/50 transition-colors border-b border-slate-100" data-box-lot="' + escapeHtml(lbl.Z2 || '-') + '" data-box-ref="' + escapeHtml(lbl.Z5 || '-') + '" data-box-origin="' + originVal + '">' +
                        '<td class="px-2.5 py-2 font-mono font-bold text-slate-400 text-xs text-center">' + (idx + 1) + '</td>' +
                        '<td class="px-3 py-2 font-mono font-bold text-slate-800 text-xs">#' + lotDisp + '</td>' +
                        '<td class="px-3 py-2 font-mono font-black text-blue-700 text-xs tracking-wide">' + refDisp + '</td>' +
                        '<td class="px-3 py-2 whitespace-nowrap">' + historyBadgeHtml + '</td>' +
                        '<td class="px-3 py-2 font-mono font-black text-slate-900 text-right text-xs">' + qtyDisp + ' <span class="text-[10px] font-bold text-slate-400">pcs</span></td>' +
                        '<td class="px-2.5 py-2 text-center whitespace-nowrap">' +
                            '<div class="flex items-center justify-center space-x-1.5">' +
                                '<button type="button" onclick="editScannedLabelQty(' + idx + ')" style="background-color: #f1f5f9; border: 1px solid #cbd5e1; color: #2563eb; width: 26px; height: 26px; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.15s ease;" onmouseover="this.style.backgroundColor=\'#eff6ff\'; this.style.borderColor=\'#3b82f6\';" onmouseout="this.style.backgroundColor=\'#f1f5f9\'; this.style.borderColor=\'#cbd5e1\';" title="Edit Qty Box">' +
                                    '<svg style="width: 13px; height: 13px;" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>' +
                                '</button>' +
                                '<button type="button" onclick="removeScannedLabel(' + idx + ')" style="background-color: #fef2f2; border: 1px solid #fecaca; color: #dc2626; width: 26px; height: 26px; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.15s ease;" onmouseover="this.style.backgroundColor=\'#fee2e2\'; this.style.borderColor=\'#f87171\';" onmouseout="this.style.backgroundColor=\'#fef2f2\'; this.style.borderColor=\'#fecaca\';" title="Hapus Label Ini">' +
                                    '<svg style="width: 13px; height: 13px;" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"></path></svg>' +
                                '</button>' +
                            '</div>' +
                        '</td>' +
                    '</tr>';
        });
        tbody.innerHTML = html;
    }

    if (countSpan) countSpan.textContent = scannedLabelsList.length;

    // Update 3 Mini KPI Dashboard Cards
    var kpiLabelCount = document.getElementById('kpi-label-count');
    var kpiUniqueLots = document.getElementById('kpi-unique-lots');
    var kpiLotBadgesContainer = document.getElementById('kpi-lot-composition-badges');
    var kpiCard1Subtext = document.getElementById('kpi-card1-subtext');
    var kpiSsBadgeContainer = document.getElementById('kpi-ss-badge-container');
    var kpiSsUsedQty = document.getElementById('kpi-ss-used-qty');

    if (kpiLabelCount) {
        kpiLabelCount.innerHTML = scannedLabelsList.length + ' <span style="font-size: 10px; font-weight: 700; color: #64748b;">Box</span>';
    }

    // Hitung rincian komposisi BOX fisik (bukan lot unik) agar 100% klop dengan total box
    var freshBoxCount = 0;
    var ssBoxCount = 0;
    var reinspectBoxCount = 0;

    scannedLabelsList.forEach(function(l) {
        var isReinspect = l.was_rejected_before ||
                          (l.remarks && (l.remarks.toUpperCase().indexOf('RE-INSPEKSI') !== -1 || l.remarks.toUpperCase().indexOf('REINSPEKSI') !== -1)) ||
                          (l.reinspection_notes && (l.reinspection_notes.toUpperCase().indexOf('RE-INSPEKSI') !== -1 || l.reinspection_notes.toUpperCase().indexOf('REINSPEKSI') !== -1));
        
        if (l.is_safety_stock_alloc) {
            ssBoxCount++;
        } else if (isReinspect) {
            reinspectBoxCount++;
        } else {
            freshBoxCount++;
        }
    });

    if (kpiLotBadgesContainer) {
        var badgesHtml = '';
        if (freshBoxCount > 0 || (ssBoxCount === 0 && reinspectBoxCount === 0)) {
            badgesHtml += '<span style="font-size: 9px; font-weight: 800; color: #047857; background-color: #ecfdf5; border: 1px solid #a7f3d0; padding: 1.5px 6px; border-radius: 4px; display: inline-flex; align-items: center; gap: 3px;">' + freshBoxCount + ' Box Baru</span>';
        }
        if (ssBoxCount > 0 || usedSsQty > 0) {
            var ssDisplayCount = ssBoxCount > 0 ? ssBoxCount : 1;
            badgesHtml += '<span style="font-size: 9px; font-weight: 800; color: #6d28d9; background-color: #f5f3ff; border: 1px solid #ddd6fe; padding: 1.5px 6px; border-radius: 4px; display: inline-flex; align-items: center; gap: 3px;">📦 ' + ssDisplayCount + ' Box SS</span>';
        }
        if (reinspectBoxCount > 0) {
            badgesHtml += '<span style="font-size: 9px; font-weight: 800; color: #92400e; background-color: #fffbeb; border: 1px solid #fde68a; padding: 1.5px 6px; border-radius: 4px; display: inline-flex; align-items: center; gap: 3px;">⚠️ ' + reinspectBoxCount + ' Box Eks-NG</span>';
        }
        kpiLotBadgesContainer.innerHTML = badgesHtml;
    }

    if (kpiSsBadgeContainer && kpiSsUsedQty) {
        if (usedSsQty > 0) {
            kpiSsUsedQty.textContent = usedSsQty.toLocaleString();
            kpiSsBadgeContainer.classList.remove('hidden');
        } else {
            kpiSsBadgeContainer.classList.add('hidden');
        }
    }

    var isPartialKanban = (!isSafetyStock && selectedPlanningTotalPassedQty > 0);

    var kpiSourceBreakdown = document.getElementById('kpi-source-breakdown');
    if (kpiSourceBreakdown) {
        if (isPartialKanban) {
            kpiSourceBreakdown.textContent = '⚡ Cicilan: ' + effectiveTotalScanned.toLocaleString() + ' / ' + selectedPlanningQty.toLocaleString() + ' pcs (Passed: ' + selectedPlanningTotalPassedQty.toLocaleString() + ' pcs)';
        } else if (usedSsQty > 0 && physicalScanned > 0) {
            kpiSourceBreakdown.textContent = usedSsQty.toLocaleString() + ' SS + ' + physicalScanned.toLocaleString() + ' Fisik';
        } else if (usedSsQty > 0) {
            kpiSourceBreakdown.textContent = usedSsQty.toLocaleString() + ' pcs dari Safety Stock';
        } else {
            kpiSourceBreakdown.textContent = physicalScanned.toLocaleString() + ' pcs Scan Fisik';
        }
    }

    var kpiProgressPercent = document.getElementById('kpi-progress-percent');
    var kpiStatusTag = document.getElementById('kpi-status-tag');
    var kpiRemainDetail = document.getElementById('kpi-remain-detail');
    var progressContainer = document.getElementById('scan-progress-bar-container');

    // KPI 1: Total Qty Display & Subtext
    var counterEl = document.getElementById('scan-progress-counter');
    if (counterEl) {
        if (isSafetyStock) {
            counterEl.innerHTML = '<span id="scanned-total-qty">' + physicalScanned.toLocaleString() + '</span> <span style="font-size: 10px; font-weight: 700; color: #64748b;">pcs (Lot Bebas)</span>';
        } else if (isPartialKanban) {
            var accumKanban = selectedPlanningTotalPassedQty + effectiveTotalScanned;
            counterEl.innerHTML = '<span id="scanned-total-qty">' + accumKanban.toLocaleString() + '</span> / <span id="target-kanban-qty-display">' + selectedPlanningTargetTotalQty.toLocaleString() + '</span> <span style="font-size: 10px; font-weight: 700; color: #64748b;">pcs</span>';
        } else {
            counterEl.innerHTML = '<span id="scanned-total-qty">' + effectiveTotalScanned.toLocaleString() + '</span> / <span id="target-kanban-qty-display">' + targetQty.toLocaleString() + '</span> <span style="font-size: 10px; font-weight: 700; color: #64748b;">pcs</span>';
        }
    }

    // Persentase Progres
    var pct;
    if (isSafetyStock) {
        pct = 100;
    } else if (isPartialKanban) {
        pct = Math.min(100, Math.round(((selectedPlanningTotalPassedQty + effectiveTotalScanned) / Math.max(1, selectedPlanningTargetTotalQty)) * 100));
    } else {
        pct = Math.min(100, Math.round((effectiveTotalScanned / Math.max(1, targetQty)) * 100));
    }

    var excessQty = isSafetyStock ? 0 : Math.max(0, effectiveTotalScanned - targetQty);

    if (isSafetyStock) {
        if (kpiProgressPercent) {
            kpiProgressPercent.textContent = 'Mode Bebas';
            kpiProgressPercent.style.color = '#7c3aed';
        }
        if (kpiCard1Subtext) {
            kpiCard1Subtext.textContent = '📦 Akumulasi Stok Bebas';
            kpiCard1Subtext.style.color = '#7c3aed';
        }
        if (kpiStatusTag) {
            kpiStatusTag.textContent = 'Safety Stock';
            kpiStatusTag.style.backgroundColor = '#f5f3ff';
            kpiStatusTag.style.color = '#6d28d9';
            kpiStatusTag.style.border = '1px solid #ddd6fe';
        }
        if (kpiRemainDetail) {
            kpiRemainDetail.textContent = physicalScanned.toLocaleString() + ' pcs';
            kpiRemainDetail.style.color = '#6d28d9';
        }
        if (excessMsg) {
            excessMsg.textContent = 'Siap dialokasikan';
            excessMsg.style.color = '#64748b';
            excessMsg.classList.remove('hidden');
        }
        if (progressContainer) {
            progressContainer.style.display = 'none';
        }
    } else {
        if (kpiProgressPercent) {
            kpiProgressPercent.textContent = pct + '%';
            kpiProgressPercent.style.color = (pct >= 100 ? '#16a34a' : '#2563eb');
        }
        if (progressContainer) {
            progressContainer.style.display = 'flex';
        }

        if (kpiCard1Subtext) {
            if (effectiveTotalScanned >= targetQty && targetQty > 0) {
                kpiCard1Subtext.textContent = '🎯 Target Kanban Terpenuhi';
                kpiCard1Subtext.style.color = '#16a34a';
            } else if (effectiveTotalScanned > 0) {
                var remaining = Math.max(0, targetQty - effectiveTotalScanned);
                kpiCard1Subtext.textContent = 'Kurang ' + remaining.toLocaleString() + ' pcs lagi';
                kpiCard1Subtext.style.color = '#d97706';
            } else {
                kpiCard1Subtext.textContent = 'Menunggu scan box...';
                kpiCard1Subtext.style.color = '#64748b';
            }
        }

        if (kpiStatusTag && kpiRemainDetail) {
            if (isPartialKanban) {
                if (effectiveTotalScanned >= selectedPlanningQty) {
                    kpiStatusTag.textContent = 'Cicilan Terpenuhi';
                    kpiStatusTag.style.backgroundColor = '#ecfdf5';
                    kpiStatusTag.style.color = '#047857';
                    kpiStatusTag.style.border = '1px solid #a7f3d0';
                    kpiRemainDetail.textContent = 'Selesai 100%';
                    kpiRemainDetail.style.color = '#047857';
                } else {
                    kpiStatusTag.textContent = 'Inspeksi Parsial';
                    kpiStatusTag.style.backgroundColor = '#eff6ff';
                    kpiStatusTag.style.color = '#2563eb';
                    kpiStatusTag.style.border = '1px solid #bfdbfe';
                    var remP = Math.max(0, selectedPlanningQty - effectiveTotalScanned);
                    kpiRemainDetail.textContent = 'Sisa ' + remP.toLocaleString() + ' pcs';
                    kpiRemainDetail.style.color = '#2563eb';
                }
            } else if (effectiveTotalScanned >= targetQty && targetQty > 0) {
                kpiStatusTag.textContent = (excessQty > 0 ? 'Surplus Terpenuhi' : 'Cicilan Terpenuhi');
                kpiStatusTag.style.backgroundColor = '#ecfdf5';
                kpiStatusTag.style.color = '#047857';
                kpiStatusTag.style.border = '1px solid #a7f3d0';
                
                if (excessQty > 0) {
                    kpiRemainDetail.textContent = '+' + excessQty.toLocaleString() + ' pcs SS';
                    kpiRemainDetail.style.color = '#7c3aed';
                } else {
                    kpiRemainDetail.textContent = 'Pas 100% Target';
                    kpiRemainDetail.style.color = '#047857';
                }
            } else {
                kpiStatusTag.textContent = 'Belum Lengkap';
                kpiStatusTag.style.backgroundColor = '#fffbeb';
                kpiStatusTag.style.color = '#b45309';
                kpiStatusTag.style.border = '1px solid #fde68a';
                var rem = Math.max(0, targetQty - effectiveTotalScanned);
                kpiRemainDetail.textContent = 'Sisa ' + rem.toLocaleString() + ' pcs';
                kpiRemainDetail.style.color = '#0f172a';
            }
        }

        if (excessMsg) {
            if (excessQty > 0) {
                excessMsg.textContent = '✨ Sisa ' + excessQty.toLocaleString() + ' pcs jadi Safety Stock';
                excessMsg.style.color = '#7c3aed';
                excessMsg.classList.remove('hidden');
            } else if (effectiveTotalScanned >= targetQty && targetQty > 0) {
                excessMsg.textContent = '✓ Pas sesuai target planning';
                excessMsg.style.color = '#047857';
                excessMsg.classList.remove('hidden');
            } else {
                excessMsg.textContent = 'Sesuai kuota kanban';
                excessMsg.style.color = '#64748b';
                excessMsg.classList.remove('hidden');
            }
        }
    }

    if (totalQtySpan) {
        if (usedSsQty > 0) {
            totalQtySpan.innerHTML = effectiveTotalScanned.toLocaleString() + ' <span class="text-[10px] font-normal text-blue-600">(' + (physicalScanned > 0 ? physicalScanned.toLocaleString() + ' Fisik + ' : '') + usedSsQty.toLocaleString() + ' SS)</span>';
        } else {
            totalQtySpan.textContent = effectiveTotalScanned.toLocaleString();
        }
    }

    if (targetQtySpan) targetQtySpan.textContent = isSafetyStock ? (physicalScanned.toLocaleString() + ' pcs (Lot Gudang)') : targetQty.toLocaleString();

    if (progressBar) {
        progressBar.style.width = pct + '%';
        if (effectiveTotalScanned >= targetQty || isSafetyStock) {
            progressBar.style.backgroundColor = isSafetyStock ? '#7c3aed' : '#10b981';
        } else {
            progressBar.style.backgroundColor = '#2563eb';
        }
    }

    if (statusMsg) {
        if (isSafetyStock) {
            statusMsg.textContent = '✓ Total Akumulasi: ' + physicalScanned.toLocaleString() + ' pcs (' + scannedLabelsList.length + ' Label Box). Siap diproses!';
            statusMsg.className = 'text-purple-700 font-extrabold';
        } else if (effectiveTotalScanned >= targetQty) {
            var detailText = (usedSsQty > 0 ? (' (' + (physicalScanned > 0 ? physicalScanned.toLocaleString() + ' Hasil Scan Label + ' : '') + usedSsQty.toLocaleString() + ' Alokasi Safety Stock)') : '');
            statusMsg.textContent = '✓ Target Quantity Terpenuhi' + detailText + ' (' + effectiveTotalScanned.toLocaleString() + ' / ' + targetQty.toLocaleString() + ' pcs). Siap diproses.';
            statusMsg.className = 'text-emerald-600 font-extrabold';
        } else if (effectiveTotalScanned > 0) {
            var detailText = usedSsQty > 0 ? (' (' + physicalScanned.toLocaleString() + ' Hasil Scan Label + ' + usedSsQty.toLocaleString() + ' Alokasi Safety Stock)') : '';
            statusMsg.textContent = '⚡ Sesi Inspeksi Parsial: ' + effectiveTotalScanned.toLocaleString() + ' pcs' + detailText + ' (Target Total: ' + targetQty.toLocaleString() + ' pcs, Sisa: ' + (targetQty - effectiveTotalScanned).toLocaleString() + ' pcs).';
            statusMsg.className = 'text-blue-700 font-bold';
        } else {
            statusMsg.textContent = 'Perlu ' + targetQty.toLocaleString() + ' pcs untuk memenuhi target.';
            statusMsg.className = 'text-slate-500 font-medium';
        }
    }

    if (btnSubmit) {
        if (window.isAddingBoxToActiveSession) {
            if (physicalScanned > 0) {
                btnSubmit.disabled = false;
                btnSubmit.style.opacity = '1';
                btnSubmit.innerHTML = 'SIMPAN & DAFTARKAN (' + scannedLabelsList.length + ' Box / ' + physicalScanned.toLocaleString() + ' pcs) KE SESI &rarr;';
            } else {
                btnSubmit.disabled = true;
                btnSubmit.style.opacity = '0.6';
                btnSubmit.innerHTML = 'Scan Box untuk Ditambahkan ke Sesi';
            }
        } else if (isSafetyStock) {
            if (physicalScanned > 0) {
                btnSubmit.disabled = false;
                btnSubmit.style.opacity = '1';
                btnSubmit.innerHTML = 'MULAI INSPEKSI SAFETY STOCK (' + scannedLabelsList.length + ' Label / ' + physicalScanned.toLocaleString() + ' pcs) &rarr;';
            } else {
                btnSubmit.disabled = true;
                btnSubmit.style.opacity = '0.6';
                btnSubmit.innerHTML = 'Mulai Inspeksi Safety Stock';
            }
        } else {
            var labelCountInfo = scannedLabelsList.length > 0 ? (scannedLabelsList.length + ' Label Box') : '';
            var ssCountInfo = usedSsQty > 0 ? (usedSsQty.toLocaleString() + ' pcs Safety Stock') : '';
            var combinedInfo = [ssCountInfo, labelCountInfo].filter(Boolean).join(' + ');

            if (effectiveTotalScanned >= targetQty) {
                btnSubmit.disabled = false;
                btnSubmit.style.opacity = '1';
                btnSubmit.innerHTML = 'MULAI INSPEKSI PENUH (' + (combinedInfo ? combinedInfo + ' / ' : '') + effectiveTotalScanned.toLocaleString() + ' pcs) &rarr;';
            } else if (effectiveTotalScanned > 0) {
                btnSubmit.disabled = false;
                btnSubmit.style.opacity = '1';
                btnSubmit.innerHTML = 'MULAI INSPEKSI PARSIAL (' + (combinedInfo ? combinedInfo + ' / ' : '') + effectiveTotalScanned.toLocaleString() + ' pcs dari ' + targetQty.toLocaleString() + ' pcs) &rarr;';
            } else {
                btnSubmit.disabled = true;
                btnSubmit.style.opacity = '0.6';
                btnSubmit.innerHTML = 'Mulai Inspeksi OQC';
            }
        }
    }

    if (btnClear) btnClear.style.display = (scannedLabelsList.length > 0) ? 'inline' : 'none';

    // ── Update options & apply Excel Filter pada tabel box ──
    populateAndApplyModalBoxExcelFilters();
}

/**
 * ── EXCEL-STYLE COLUMN FILTERS FOR SCANNED BOXES TABLE (MODAL STEP 2) ──
 * Persis seperti di modules/inspection/index.php
 */
var ModalBoxFilterState = {
    filters: {
        lot: new Set(),
        ref: new Set(),
        origin: new Set()
    },
    activeCol: null
};

