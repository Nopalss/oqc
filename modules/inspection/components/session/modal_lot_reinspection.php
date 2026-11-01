<!-- Modal: Tindakan Re-Inspeksi Per-Lot (Opsi A) -->
<div id="modal-lot-reinspection" style="position: fixed; inset: 0; background-color: rgba(15, 23, 42, 0.75); z-index: 9999; display: none; align-items: center; justify-content: center; padding: 16px;">
    <div style="background-color: #ffffff; border-radius: 20px; max-width: 560px; width: 100%; border: 1px solid #cbd5e1; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); overflow: hidden; display: flex; flex-direction: column;">
        <!-- Header -->
        <div style="background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); color: #ffffff; padding: 14px 18px; display: flex; align-items: center; justify-content: space-between;">
            <div>
                <h3 style="font-size: 13px; font-weight: 800; color: #ffffff; margin: 0; text-transform: uppercase; letter-spacing: 0.03em;">TINDAKAN RE-INSPEKSI LOT</h3>
                <p id="modal-reinsp-lot-subtitle" style="font-size: 11px; color: #94a3b8; margin: 2px 0 0 0; font-weight: 600;">Lot # - | Ref: -</p>
            </div>
            <button type="button" onclick="closeLotReinspectionModal()" style="color: #94a3b8; font-size: 20px; font-weight: bold; background: none; border: none; cursor: pointer;">&times;</button>
        </div>

        <!-- Hidden Inputs -->
        <input type="hidden" id="modal-reinsp-lot-id" value="">
        <input type="hidden" id="modal-reinsp-part-code" value="">
        <input type="hidden" id="modal-reinsp-target-lot" value="">
        <input type="hidden" id="modal-reinsp-target-ref" value="">

        <!-- Tab Selector: Opsi 1 (Ganti Box) vs Opsi 2 (Sortir) -->
        <div style="display: flex; border-bottom: 1px solid #e2e8f0; background-color: #f8fafc;">
            <button type="button" id="tab-reinsp-replace" onclick="switchLotReinspTab('replace')" style="flex: 1; padding: 10px 12px; font-size: 11px; font-weight: 800; border: none; border-bottom: 2.5px solid #2563eb; background-color: #ffffff; color: #2563eb; cursor: pointer; text-align: center;">
                Ganti Box Baru (Scan QR)
            </button>
            <button type="button" id="tab-reinsp-sort" onclick="switchLotReinspTab('sort')" style="flex: 1; padding: 10px 12px; font-size: 11px; font-weight: 700; border: none; border-bottom: 2.5px solid transparent; background-color: transparent; color: #64748b; cursor: pointer; text-align: center;">
                Ganti Part NG Saja (Sortir)
            </button>
        </div>

        <!-- Body Content -->
        <div style="padding: 16px 18px; max-height: 60vh; overflow-y: auto;" class="space-y-3 custom-scrollbar">
            <!-- TAB 1: GANTI BOX BARU -->
            <div id="content-reinsp-replace" class="space-y-3">
                <div style="background-color: #eff6ff; border: 1px solid #bfdbfe; border-radius: 10px; padding: 10px 12px; font-size: 11px; color: #1e40af;">
                    Box lama akan ditandai sebagai <b>DIGANTI</b> (Rejection Sheet tetap dapat dicetak). Box pengganti baru akan didaftarkan ke sesi ini dan wajib diperiksa sampel fisiknya.
                </div>

                <!-- QR Barcode Scanner Input -->
                <div style="background-color: #f0fdf4; border: 1.5px dashed #10b981; border-radius: 10px; padding: 10px 12px;">
                    <label style="display: block; font-size: 10px; font-weight: 800; color: #065f46; margin-bottom: 4px;">
                        SCAN BARCODE / QR LABEL BOX PENGGANTI:
                    </label>
                    <input type="text" id="reinsp-scan-qr-raw" oninput="handleLotReinspQrInput(this, false)" onkeydown="if(event.key==='Enter'){event.preventDefault();handleLotReinspQrInput(this, true);}" placeholder="Tempel atau Scan QR Code Box Pengganti..." style="width: 100%; box-sizing: border-box; padding: 8px 12px; font-family: monospace; font-size: 11px; font-weight: 700; border: 1.5px solid #10b981; border-radius: 8px; outline: none; background: #ffffff;">
                </div>

                <!-- Form Fields Manual/Preview -->
                <div class="grid grid-cols-2 gap-2 text-xs">
                    <div>
                        <label style="font-size: 9.5px; font-weight: 700; color: #64748b; display: block; margin-bottom: 2px;">PART CODE</label>
                        <input type="text" id="reinsp-part-code" readonly style="width: 100%; box-sizing: border-box; padding: 6px 10px; font-family: monospace; font-size: 11px; font-weight: 700; border: 1px solid #cbd5e1; border-radius: 6px; background-color: #f1f5f9; color: #334155;">
                    </div>
                    <div>
                        <label style="font-size: 9.5px; font-weight: 700; color: #64748b; display: block; margin-bottom: 2px;">LOT NUMBER PENGGANTI <span style="color: #e11d48;">*</span></label>
                        <input type="text" id="reinsp-lot-number" value="" placeholder="Contoh: 06426817" onkeydown="if(event.key==='Enter'){event.preventDefault();executeLotReinspectionSubmit();}" style="width: 100%; box-sizing: border-box; padding: 6px 10px; font-family: monospace; font-size: 11px; font-weight: 700; border: 1px solid #cbd5e1; border-radius: 6px; outline: none;">
                    </div>
                    <div>
                        <label style="font-size: 9.5px; font-weight: 700; color: #64748b; display: block; margin-bottom: 2px;">QTY BOX (PCS) <span style="color: #e11d48;">*</span></label>
                        <input type="number" id="reinsp-qty" min="1" placeholder="Contoh: 50" onkeydown="if(event.key==='Enter'){event.preventDefault();executeLotReinspectionSubmit();}" style="width: 100%; box-sizing: border-box; padding: 6px 10px; font-family: monospace; font-size: 11px; font-weight: 700; border: 1px solid #cbd5e1; border-radius: 6px; outline: none;">
                    </div>
                    <div>
                        <label style="font-size: 9.5px; font-weight: 700; color: #64748b; display: block; margin-bottom: 2px;">REF NUMBER (BOX NO)</label>
                        <input type="text" id="reinsp-ref-number" placeholder="Contoh: BOX-02B" onkeydown="if(event.key==='Enter'){event.preventDefault();executeLotReinspectionSubmit();}" style="width: 100%; box-sizing: border-box; padding: 6px 10px; font-family: monospace; font-size: 11px; font-weight: 700; border: 1px solid #cbd5e1; border-radius: 6px; outline: none;">
                    </div>
                </div>

                <div>
                    <label style="font-size: 9.5px; font-weight: 700; color: #64748b; display: block; margin-bottom: 2px;">CATATAN PENGGANTIAN (OPSIONAL)</label>
                    <input type="text" id="reinsp-replace-remarks" placeholder="Contoh: Box pengganti dari jalur produksi Line 2" onkeydown="if(event.key==='Enter'){event.preventDefault();executeLotReinspectionSubmit();}" style="width: 100%; box-sizing: border-box; padding: 6px 10px; font-size: 11px; border: 1px solid #cbd5e1; border-radius: 6px; outline: none;">
                </div>
            </div>

            <!-- TAB 2: GANTI PART NG SAJA (SORTIR) -->
            <div id="content-reinsp-sort" class="space-y-3" style="display: none;">
                <div style="background-color: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: 10px 12px; font-size: 11px; color: #92400e; line-height: 1.5;">
                    Label box (Lot No &amp; Ref No) tetap sama. Gunakan opsi ini jika seluruh part NG di dalam box ini telah disortir dan ditukar dengan part bagus.<br>
                    Status box akan dikembalikan ke <b>Sedang Diinspeksi</b> untuk diuji ulang sampel fisiknya dari awal.
                </div>

                <div>
                    <label style="font-size: 9.5px; font-weight: 700; color: #64748b; display: block; margin-bottom: 2px;">CATATAN SORTIR (OPSIONAL)</label>
                    <textarea id="reinsp-sort-notes" rows="2" placeholder="Contoh: Part NG sudah ditukar dengan part bagus dari stock line" style="width: 100%; box-sizing: border-box; padding: 7px 10px; font-size: 11px; border: 1px solid #cbd5e1; border-radius: 6px; outline: none;"></textarea>
                </div>
            </div>
        </div>

        <!-- Footer Actions -->
        <div style="background-color: #f8fafc; border-top: 1px solid #e2e8f0; padding: 12px 18px; display: flex; align-items: center; justify-content: flex-end; gap: 8px;">
            <button type="button" onclick="closeLotReinspectionModal()" style="padding: 7px 16px; border: 1px solid #cbd5e1; background-color: #ffffff; color: #475569; font-size: 12px; font-weight: 700; border-radius: 10px; cursor: pointer;">
                Batal
            </button>
            <button id="btn-submit-lot-reinsp" type="button" onclick="executeLotReinspectionSubmit()" style="padding: 7px 20px; background-color: #2563eb; color: #ffffff; font-size: 12px; font-weight: 800; border: none; border-radius: 10px; cursor: pointer; box-shadow: 0 1px 3px rgba(37,99,235,0.3);">
                Konfirmasi &amp; Muat Box Pengganti
            </button>
        </div>
    </div>
</div>

<style>
/* ── EXCEL-STYLE COLUMN FILTERS FOR MODAL BOX TABLE (MATCHING INSPECTION/INDEX.PHP) ── */
.th-filter-wrapper {
    display: inline-flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    position: relative;
    gap: 4px;
}
.th-filter-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 18px;
    height: 18px;
    border-radius: 4px;
    color: #475569 !important;
    cursor: pointer;
    transition: all 0.15s ease;
    background-color: #ffffff !important;
    border: 1.5px solid #cbd5e1 !important;
    flex-shrink: 0;
    padding: 0;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
}
.th-filter-btn:hover {
    color: #1d4ed8 !important;
    background-color: #dbeafe !important;
    border-color: #3b82f6 !important;
}
.th-filter-btn.active {
    color: #ffffff !important;
    background-color: #2563eb !important;
    border-color: #1d4ed8 !important;
    box-shadow: 0 1px 3px rgba(37, 99, 235, 0.4);
}
.excel-filter-dropdown {
    position: absolute;
    top: calc(100% + 4px);
    z-index: 99999 !important;
    background-color: #ffffff !important;
    border: 1.5px solid #94a3b8 !important;
    border-radius: 9px;
    box-shadow: 0 15px 25px -4px rgba(0, 0, 0, 0.25), 0 8px 10px -6px rgba(0, 0, 0, 0.15) !important;
    width: 220px;
    padding: 8px 10px;
    text-transform: none;
    letter-spacing: normal;
    font-weight: normal;
    color: #0f172a;
    display: none;
    text-align: left;
}
.excel-filter-dropdown.align-right {
    right: 0;
    left: auto;
}
.excel-filter-dropdown.align-left {
    left: 0;
    right: auto;
}
.excel-filter-search {
    width: 100%;
    padding: 5px 8px;
    border: 1.5px solid #cbd5e1;
    border-radius: 6px;
    font-size: 11px;
    outline: none;
    margin-bottom: 6px;
    background-color: #ffffff;
    color: #0f172a;
    box-sizing: border-box;
}
.excel-filter-search:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 2px #bfdbfe;
}
.excel-quick-btn {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    padding: 2.5px 7px;
    border-radius: 4px;
    font-size: 9.5px;
    font-weight: 800;
    cursor: pointer;
    background-color: #f1f5f9;
    border: 1px solid #cbd5e1;
    color: #334155;
    transition: all 0.15s ease;
}
.excel-quick-btn:hover {
    background-color: #e2e8f0;
    border-color: #94a3b8;
}
.excel-reset-btn {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 9.5px;
    font-weight: 800;
    cursor: pointer;
    background-color: #fee2e2;
    border: 1px solid #fca5a5;
    color: #b91c1c;
    transition: all 0.15s ease;
}
.excel-reset-btn:hover {
    background-color: #fecaca;
    border-color: #f87171;
}
.excel-apply-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    width: 100%;
    padding: 4px 8px;
    border-radius: 5px;
    font-size: 10.5px;
    font-weight: 800;
    cursor: pointer;
    background-color: #2563eb;
    border: 1px solid #1d4ed8;
    color: #ffffff;
    box-shadow: 0 1px 2px rgba(37,99,235,0.3);
    transition: all 0.15s ease;
}
.excel-apply-btn:hover {
    background-color: #1d4ed8;
}
.excel-filter-list {
    max-height: 120px;
    overflow-y: auto;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 3px 5px;
    margin-bottom: 6px;
    background-color: #f8fafc;
}
.excel-filter-item {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 2.5px 4px;
    border-radius: 4px;
    font-size: 10.5px;
    font-weight: 600;
    color: #1e293b;
    cursor: pointer;
    user-select: none;
}
.excel-filter-item:hover {
    background-color: #e2e8f0;
}
.excel-filter-item input[type="checkbox"] {
    width: 12px;
    height: 12px;
    border-radius: 3px;
    accent-color: #2563eb;
    cursor: pointer;
}

/* ── KPI INTERACTIVE CARD (HOVER & ACTIVE PRESS / MENCET EFFECT) ── */
.kpi-interactive-card {
    cursor: pointer !important;
    user-select: none;
    transition: transform 0.12s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.12s ease, filter 0.12s ease !important;
}
.kpi-interactive-card:hover {
    transform: translateY(-2.5px) !important;
    box-shadow: 0 6px 14px -2px rgba(0, 0, 0, 0.12) !important;
    filter: brightness(1.03);
}
.kpi-interactive-card:active {
    transform: translateY(1.5px) scale(0.97) !important;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2) inset !important;
    filter: brightness(0.95);
}
.kpi-interactive-card.kpi-active-selected {
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25) !important;
}
</style>

