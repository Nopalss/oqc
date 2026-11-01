<!-- Modal: Catat NG per Lot (Per-Lot AQL) -->
<div id="modal-lot-defect" style="position: fixed; inset: 0; background-color: rgba(15, 23, 42, 0.75); z-index: 9999; display: none; align-items: center; justify-content: center; padding: 16px;">
    <div style="background-color: #ffffff; border-radius: 18px; max-width: 580px; width: 100%; border: 1px solid #cbd5e1; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.2); overflow: visible; display: flex; flex-direction: column;">
        <!-- Header -->
        <div style="background-color: #fff1f2; border-bottom: 1px solid #fecdd3; padding: 14px 20px; border-top-left-radius: 18px; border-top-right-radius: 18px; display: flex; align-items: center; justify-content: space-between;">
            <div>
                <h3 style="font-size: 13.5px; font-weight: 800; color: #9f1239; margin: 0; letter-spacing: 0.02em;">CATAT TEMUAN NG PER LOT</h3>
                <p id="modal-defect-lot-subtitle" style="font-size: 11.5px; color: #be123c; margin: 2px 0 0 0; font-weight: 600;">Lot # - | Ref: -</p>
            </div>
            <button type="button" onclick="closeLotNgModal()" style="color: #9f1239; font-size: 22px; font-weight: bold; background: none; border: none; cursor: pointer; line-height: 1;">&times;</button>
        </div>

        <!-- AQL Notice -->
        <div style="background-color: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 8px 20px; font-size: 11.5px; display: flex; align-items: center; justify-content: space-between;">
            <span id="modal-defect-aql-text" style="color: #475569; font-weight: 600;">AQL: G-II | Wajib Sample: 20 pcs | Batas NG: 1</span>
            <span style="color: #e11d48; font-weight: 800; font-size: 10.5px; background-color: #ffe4e6; padding: 2px 8px; border-radius: 5px; border: 1px solid #fecdd3;">REJECTED jika NG &ge; Batas</span>
        </div>

        <!-- Body / Units Container -->
        <div id="modal-defect-body" style="padding: 16px 20px; max-height: 58vh; overflow-y: auto;" class="space-y-3 custom-scrollbar">
            <input type="hidden" id="modal-defect-lot-id" value="">
            <input type="hidden" id="modal-defect-reject-num" value="1">
            <input type="hidden" id="modal-defect-sample-size" value="0">
            
            <div id="modal-defect-units-container" class="space-y-2.5">
                <!-- Defect rows rendered dynamically by addLotDefectRow() -->
            </div>

            <button type="button" onclick="addLotDefectRow()" style="width: 100%; padding: 9px 14px; font-size: 11.5px; font-weight: 800; color: #1d4ed8; background-color: #eff6ff; border: 1.5px dashed #93c5fd; border-radius: 12px; cursor: pointer; transition: all 0.15s; display: flex; align-items: center; justify-content: center; gap: 6px;" onmouseover="this.style.backgroundColor='#dbeafe'" onmouseout="this.style.backgroundColor='#eff6ff'">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                <span>+ Tambah Jenis Defect / NG Baru</span>
            </button>
        </div>

        <!-- Live Impact Notice -->
        <div id="modal-defect-live-alert" style="padding: 10px 20px; background-color: #fff1f2; border-top: 1px solid #fecdd3; font-size: 11.5px; font-weight: 700; color: #9f1239; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <span>Total: <b id="modal-defect-units-count">0</b> Pcs Fisik NG</span>
                <span style="color: #fca5a5;">&middot;</span>
                <span style="color: #be123c;"><b id="modal-defect-defects-count">0</b> Jenis Defect</span>
            </div>
            <span id="modal-defect-verdict-display" style="background-color: #be123c; color: #ffffff; padding: 2.5px 10px; border-radius: 5px; font-size: 10px; font-weight: 800;">STATUS: REJECTED</span>
        </div>

        <!-- Footer Actions -->
        <div style="background-color: #f8fafc; border-top: 1px solid #e2e8f0; padding: 12px 20px; border-bottom-left-radius: 18px; border-bottom-right-radius: 18px; display: flex; align-items: center; justify-content: flex-end; gap: 10px;">
            <button type="button" onclick="closeLotNgModal()" style="padding: 8px 18px; border: 1px solid #cbd5e1; background-color: #ffffff; color: #475569; font-size: 12px; font-weight: 700; border-radius: 10px; cursor: pointer;">
                Batal
            </button>
            <button id="btn-submit-lot-ng" type="button" onclick="submitLotDefectForm()" style="padding: 8px 20px; background-color: #e11d48; color: #ffffff; font-size: 12px; font-weight: 800; border: none; border-radius: 10px; cursor: pointer; box-shadow: 0 1px 3px rgba(225,29,72,0.3);">
                Simpan &amp; Tandai REJECTED
            </button>
        </div>
    </div>
</div>

