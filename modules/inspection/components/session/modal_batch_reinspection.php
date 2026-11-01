<!-- Modal Dialog Batch Re-Inspeksi Kanban (REJECTED) -->
<div id="batch-reinspection-modal-overlay" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; width: 100vw; height: 100vh; background-color: rgba(15, 23, 42, 0.85); z-index: 999999; display: flex; align-items: center; justify-content: center; padding: 16px; margin: 0; box-sizing: border-box;" class="hidden">
    <div style="background-color: #ffffff; border-radius: 20px; max-width: 650px; width: 100%; max-height: 92vh; display: flex; flex-direction: column; border: 1px solid #cbd5e1; box-shadow: 0 25px 60px -12px rgba(0, 0, 0, 0.4); margin: auto; overflow: hidden; position: relative; z-index: 1000000;" class="animate-fadeIn">
        
        <!-- Modal Header -->
        <div style="background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 60%, #881337 100%); color: #ffffff; padding: 16px 20px; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; border-bottom: 1px solid rgba(244, 63, 94, 0.3);">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 38px; height: 38px; border-radius: 12px; background-color: rgba(225, 29, 72, 0.25); border: 1.5px solid rgba(244, 63, 94, 0.4); display: flex; align-items: center; justify-content: center; color: #fb7185; flex-shrink: 0;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg>
                </div>
                <div>
                    <h3 style="font-size: 15px; font-weight: 800; letter-spacing: 0.03em; text-transform: uppercase; color: #ffffff; margin: 0; line-height: 1.2;">
                        TANGANI RE-INSPEKSI KANBAN
                    </h3>
                    <p style="font-size: 11px; color: #cbd5e1; margin: 2px 0 0 0; font-weight: 500;">Pilih Strategi Re-Inspeksi &amp; Scope Tindakan Kanban REJECTED</p>
                </div>
            </div>
            <button type="button" onclick="closeBatchReinspectionModal()" style="background: none; border: none; color: #94a3b8; font-size: 24px; font-weight: 700; cursor: pointer; padding: 0 4px; line-height: 1; transition: color 0.15s;" onmouseover="this.style.color='#ffffff'" onmouseout="this.style.color='#94a3b8'">
                &times;
            </button>
        </div>

        <!-- Modal Body -->
        <div style="padding: 18px; overflow-y: auto; flex: 1; background-color: #f8fafc; display: flex; flex-direction: column; gap: 14px;" class="custom-scrollbar">

            <!-- Section 0: Informasi Part & Planning (Header Summary Banner) -->
            <div style="background-color: #ffffff; border: 1.5px solid #e2e8f0; border-radius: 14px; padding: 12px 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.03); display: flex; flex-direction: column; gap: 8px;">
                <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; border-bottom: 1px solid #f1f5f9; padding-bottom: 8px;">
                    <div style="min-width: 0; flex: 1;">
                        <span style="font-size: 9.5px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; display: block;">PART CODE &amp; PART NAME</span>
                        <div style="display: flex; align-items: center; gap: 6px; margin-top: 2px; flex-wrap: wrap;">
                            <span id="batch-modal-part-code" style="font-family: monospace; font-size: 13px; font-weight: 900; color: #0f172a; background-color: #f1f5f9; padding: 2px 8px; border-radius: 6px; border: 1px solid #cbd5e1;">
                                <?= $session ? htmlspecialchars($session['part_code']) : '-' ?>
                            </span>
                            <span id="batch-modal-part-name" style="font-size: 12.5px; font-weight: 800; color: #1e293b; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                <?= $session ? htmlspecialchars($session['part_name']) : '-' ?>
                            </span>
                        </div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 8px; flex-shrink: 0;">
                        <div style="text-align: right;">
                            <span style="font-size: 9.5px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; display: block;">JENIS INSPEKSI</span>
                            <span id="batch-modal-type-badge">
                                <?php if ($session && ($session['inspection_type'] ?? 'kanban') === 'safety_stock'): ?>
                                    <span style="display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 6px; font-size: 10px; font-weight: 800; background-color: #f3e8ff; color: #7e22ce; border: 1px solid #d8b4fe;">
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>
                                        SAFETY STOCK
                                    </span>
                                <?php else: ?>
                                    <span style="display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 6px; font-size: 10px; font-weight: 800; background-color: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe;">
                                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                                        KANBAN
                                    </span>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                </div>

                <div style="display: flex; align-items: center; justify-content: space-between; font-size: 11px; gap: 10px; flex-wrap: wrap;">
                    <div style="display: flex; align-items: center; gap: 4px;">
                        <span style="color: #64748b; font-weight: 600;">No. Kanban: </span>
                        <span id="batch-modal-kanban-no" style="font-family: monospace; font-weight: 800; color: #0f172a; background-color: #f8fafc; padding: 1px 6px; border-radius: 4px; border: 1px solid #e2e8f0;">
                            <?= $session ? htmlspecialchars($session['kanban_no'] ?: ($session['doc_no'] ?: '-')) : '-' ?>
                        </span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 4px;">
                        <span style="color: #64748b; font-weight: 600;">ETA: </span>
                        <?php
                        $etaDisplay = '-';
                        $etaVal = $session['kanban_eta'] ?? ($session['eta'] ?? ($session['kanban_req_date'] ?? ''));
                        if (!empty($etaVal) && $etaVal !== '-' && $etaVal !== '0000-00-00 00:00:00') {
                            $time = strtotime($etaVal);
                            if ($time) {
                                $monthsId = [1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'Mei',6=>'Jun',7=>'Jul',8=>'Agu',9=>'Sep',10=>'Okt',11=>'Nov',12=>'Des'];
                                $m = $monthsId[(int)date('n', $time)] ?? date('M', $time);
                                $etaDisplay = date('d', $time) . ' ' . $m . ' ' . date('Y, H:i', $time) . ' WIB';
                            } else {
                                $etaDisplay = htmlspecialchars($etaVal);
                            }
                        }
                        ?>
                        <span id="batch-modal-eta" style="font-weight: 800; color: #b45309; background-color: #fffbeb; border: 1px solid #fde68a; padding: 1px 6px; border-radius: 4px; display: inline-flex; align-items: center; gap: 4px;">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            <span><?= $etaDisplay ?></span>
                        </span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 4px;">
                        <span style="color: #64748b; font-weight: 600;">Customer: </span>
                        <span id="batch-modal-customer" style="font-weight: 800; color: #1e40af;">
                            <?= $session ? htmlspecialchars($session['customer'] ?? 'PT. Indonesia Epson Industry') : '-' ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Section 1: Ringkasan Lot NG Terdampak & Semua Lot Kanban -->
            <div style="background-color: #f8fafc; border: 1.5px solid #cbd5e1; border-radius: 14px; padding: 12px; display: flex; flex-direction: column; gap: 8px;">
                <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">
                    <span style="font-size: 11px; font-weight: 800; color: #1e293b; text-transform: uppercase; letter-spacing: 0.04em; display: flex; align-items: center; gap: 5px;">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>
                        <span>DAFTAR LOT KANBAN SESI INI</span>
                    </span>
                    <!-- Tab Switcher / Segmented Control -->
                    <div style="display: flex; gap: 4px; background: #e2e8f0; padding: 2px; border-radius: 8px;">
                        <button type="button" id="btn-batch-tab-ng" onclick="renderBatchLotList('ng')" style="font-size: 10px; font-weight: 700; padding: 3px 8px; border-radius: 6px; border: none; cursor: pointer; transition: all 0.15s; background-color: #ffffff; color: #be123c; box-shadow: 0 1px 2px rgba(0,0,0,0.05); display: inline-flex; align-items: center; gap: 4px;">
                            <span style="width: 6px; height: 6px; border-radius: 50%; background-color: #e11d48; display: inline-block;"></span>
                            <span>Lot NG (<span id="batch-modal-ng-count">0</span>)</span>
                        </button>
                        <button type="button" id="btn-batch-tab-all" onclick="renderBatchLotList('all')" style="font-size: 10px; font-weight: 700; padding: 3px 8px; border-radius: 6px; border: none; cursor: pointer; transition: all 0.15s; background-color: transparent; color: #64748b; display: inline-flex; align-items: center; gap: 4px;">
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>
                            <span>Semua Lot (<span id="batch-modal-all-count">0</span>)</span>
                        </button>
                    </div>
                </div>
                <div id="batch-modal-lot-list" style="display: flex; flex-direction: column; gap: 6px; max-height: 125px; overflow-y: auto; padding-right: 2px;" class="slim-scrollbar">
                    <!-- Populated dynamically by JS renderBatchLotList() -->
                </div>
            </div>

            <!-- Section 2: Action Buttons Strategi Utama -->
            <div style="display: flex; flex-direction: column; gap: 8px;">
                <label style="font-size: 11px; font-weight: 800; color: #334155; text-transform: uppercase; letter-spacing: 0.05em; display: block;">
                    Pilih Strategi Re-Inspeksi:
                </label>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <!-- Card Button Strategi 1: Scan Ulang -->
                    <div id="card-btn-rescan" onclick="selectBatchStrategy('rescan')" style="text-align: left; cursor: pointer; border: 2px solid #f59e0b; background-color: #fffbeb; border-radius: 14px; padding: 14px; display: flex; flex-direction: column; justify-content: space-between; transition: all 0.2s; box-shadow: 0 4px 12px rgba(245,158,11,0.12);">
                        <div>
                            <span style="font-size: 12px; font-weight: 900; color: #78350f; display: flex; align-items: center; gap: 5px; line-height: 1.3;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg>
                                <span>INSPEKSI ULANG</span>
                            </span>
                            <span style="font-size: 10px; font-weight: 700; color: #b45309; display: block; margin-top: 2px;">Gunakan Lot Existing</span>
                        </div>

                        <!-- Sub Action Buttons for Rescan -->
                        <div id="sub-rescan-buttons" style="margin-top: 12px; padding-top: 10px; border-top: 1px solid #fde68a; display: flex; flex-direction: column; gap: 6px;">
                            <button type="button" id="btn-sub-rescan-restart" onclick="selectSubAction('rescan_restart', event)" style="padding: 6px 10px; font-size: 11px; font-weight: 700; border-radius: 8px; border: 1.5px solid #d97706; background-color: #d97706; color: #ffffff; cursor: pointer; text-align: left; transition: all 0.15s; display: flex; align-items: center; gap: 6px;">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                <span>Ulang dari Awal (Reset ke Sample #1)</span>
                            </button>
                            <button type="button" id="btn-sub-rescan-continue" onclick="selectSubAction('rescan_continue', event)" style="padding: 6px 10px; font-size: 11px; font-weight: 600; border-radius: 8px; border: 1.5px solid #fcd34d; background-color: #ffffff; color: #92400e; cursor: pointer; text-align: left; transition: all 0.15s; display: flex; align-items: center; gap: 6px;">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polygon points="13 19 22 12 13 5 13 19"/><polygon points="2 19 11 12 2 5 2 19"/></svg>
                                <span>Lanjut Inspeksi (Sisa Sample)</span>
                            </button>
                        </div>
                    </div>

                    <!-- Card Button Strategi 2: Ganti Lot -->
                    <div id="card-btn-replace" onclick="selectBatchStrategy('replace')" style="text-align: left; cursor: pointer; border: 2px solid #cbd5e1; background-color: #ffffff; border-radius: 14px; padding: 14px; display: flex; flex-direction: column; justify-content: space-between; transition: all 0.2s; opacity: 0.65;">
                        <div>
                            <span style="font-size: 12px; font-weight: 900; color: #0f172a; display: flex; align-items: center; gap: 5px; line-height: 1.3;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M16 3h5v5M4 20L21 3M21 16v5h-5M15 15l6 6M4 4l5 5"/></svg>
                                <span>GANTI LOT BARU</span>
                            </span>
                            <span style="font-size: 10px; font-weight: 600; color: #64748b; display: block; margin-top: 2px;">Scan QR Code Lot Pengganti Gudang</span>
                        </div>

                        <!-- Sub Action Buttons for Replace -->
                        <div id="sub-replace-buttons" style="margin-top: 12px; padding-top: 10px; border-top: 1px solid #e2e8f0; display: flex; flex-direction: column; gap: 6px; opacity: 0.5; pointer-events: none;">
                            <button type="button" id="btn-sub-replace-ng" onclick="selectSubAction('replace_ng_only', event)" style="padding: 6px 10px; font-size: 11px; font-weight: 700; border-radius: 8px; border: 1.5px solid #e11d48; background-color: #e11d48; color: #ffffff; cursor: pointer; text-align: left; transition: all 0.15s; display: flex; align-items: center; gap: 6px;">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                                <span>Ganti Lot yang NG Saja</span>
                            </button>
                            <button type="button" id="btn-sub-replace-all" onclick="selectSubAction('replace_all_lots', event)" style="padding: 6px 10px; font-size: 11px; font-weight: 600; border-radius: 8px; border: 1.5px solid #cbd5e1; background-color: #ffffff; color: #475569; cursor: pointer; text-align: left; transition: all 0.15s; display: flex; align-items: center; gap: 6px;">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>
                                <span>Ganti Semua Lot</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section 3: Interface Step 2 QR Scanner Input (Untuk Ganti Lot) -->
            <div id="batch-replacement-form-container" class="hidden" style="background-color: #ffffff; border: 1.5px solid #3b82f6; border-radius: 16px; padding: 16px; display: flex; flex-direction: column; gap: 14px; box-shadow: 0 4px 16px rgba(59,130,246,0.08);">
                
                <!-- Header & Step Pills -->
                <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px;">
                    <div>
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                            <span style="background-color: #dcfce7; color: #166534; font-size: 10px; font-weight: 800; padding: 2px 8px; border-radius: 9999px; border: 1px solid #bbf7d0;">✓ Step 1 Complete</span>
                            <span style="background-color: #2563eb; color: #ffffff; font-size: 10px; font-weight: 800; padding: 2px 10px; border-radius: 9999px;">2. Scan Label QR</span>
                        </div>
                        <h4 style="font-size: 14px; font-weight: 800; color: #0f172a; margin: 0;">Step 2: Scan QR Code Label Barcode</h4>
                        <p style="font-size: 11px; color: #64748b; margin: 2px 0 0 0;">Scan barcode label barang yang mau diuji</p>
                    </div>
                </div>

                <!-- Main QR Barcode Scan Field -->
                <div style="background-color: #f0f9ff; border: 1.5px dashed #3b82f6; border-radius: 12px; padding: 12px;">
                    <label style="font-size: 11px; font-weight: 800; color: #1e40af; display: flex; align-items: center; gap: 6px; margin-bottom: 6px;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><rect x="7" y="7" width="10" height="10" rx="1"/></svg>
                        <span>Scan Barcode / QR Code Label</span>
                    </label>
                    <input id="batch-qr-scan-input" type="text" oninput="handleSingleBatchQrInput(this, false)" onkeydown="if(event.key==='Enter'){event.preventDefault();handleSingleBatchQrInput(this, true);}" placeholder="Tempel atau Scan Barcode / QR Code Label di sini..." style="width: 100%; padding: 10px 14px; font-family: monospace; font-size: 12px; font-weight: 700; border: 2px solid #3b82f6; border-radius: 10px; outline: none; background-color: #ffffff; box-shadow: inset 0 2px 4px rgba(0,0,0,0.03);" autofocus>
                    <span style="font-size: 10px; color: #64748b; margin-top: 4px; display: block;">Sistem membaca Ref No, Lot No, Qty. Scan bertahap hingga target Qty terpenuhi.</span>
                </div>

                <!-- Detail Manual Input Fields -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px;">
                    <div>
                        <label style="font-size: 10px; font-weight: 700; color: #475569; display: block; margin-bottom: 2px;">Part Code <span style="color: #e11d48;">*</span></label>
                        <input id="batch-input-z1" type="text" value="<?= $session ? htmlspecialchars($session['part_code']) : '' ?>" placeholder="Part Code..." style="width: 100%; padding: 7px 10px; font-family: monospace; font-size: 11px; border: 1px solid #cbd5e1; border-radius: 8px; outline: none; background-color: #f1f5f9; font-weight: 700; color: #1e293b;">
                    </div>
                    <div>
                        <label style="font-size: 10px; font-weight: 700; color: #475569; display: block; margin-bottom: 2px;">Lot Number <span style="color: #e11d48;">*</span></label>
                        <input id="batch-input-z2" type="text" value="" placeholder="Lot Number..." style="width: 100%; padding: 7px 10px; font-family: monospace; font-size: 11px; border: 1px solid #cbd5e1; border-radius: 8px; outline: none; background-color: #ffffff;">
                    </div>
                    <div>
                        <label style="font-size: 10px; font-weight: 700; color: #475569; display: block; margin-bottom: 2px;">Qty Box <span style="color: #e11d48;">*</span></label>
                        <input id="batch-input-z3" type="number" placeholder="Qty..." style="width: 100%; padding: 7px 10px; font-family: monospace; font-size: 11px; border: 1px solid #cbd5e1; border-radius: 8px; outline: none; background-color: #ffffff;">
                    </div>
                    <div>
                        <label style="font-size: 10px; font-weight: 700; color: #475569; display: block; margin-bottom: 2px;">Ref Number <span style="color: #e11d48;">*</span></label>
                        <input id="batch-input-z5" type="text" placeholder="Ref Number..." style="width: 100%; padding: 7px 10px; font-family: monospace; font-size: 11px; border: 1px solid #cbd5e1; border-radius: 8px; outline: none; background-color: #ffffff;">
                    </div>
                    <div style="grid-column: span 2;">
                        <button type="button" onclick="addManualBatchLabel()" style="padding: 7px 14px; background-color: #ffffff; border: 1.5px solid #cbd5e1; color: #334155; border-radius: 8px; font-size: 11px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; transition: all 0.15s;" onmouseover="this.style.backgroundColor='#f1f5f9'" onmouseout="this.style.backgroundColor='#ffffff'">
                            + Input Label Manual
                        </button>
                    </div>
                </div>

                <!-- Table Scanned Labels List -->
                <div>
                    <label style="font-size: 11px; font-weight: 800; color: #334155; display: block; margin-bottom: 6px;">
                        Daftar Label / Box yang Discan (<span id="batch-scanned-count">0</span> Label):
                    </label>
                    <div style="border: 1px solid #cbd5e1; border-radius: 10px; overflow: hidden; background-color: #ffffff;">
                        <table style="width: 100%; border-collapse: collapse; font-size: 11px;">
                            <thead>
                                <tr style="background-color: #f1f5f9; color: #475569; font-weight: 800; text-transform: uppercase; border-bottom: 1px solid #cbd5e1;">
                                    <th style="padding: 6px 10px; text-align: left; width: 35px;">NO</th>
                                    <th style="padding: 6px 10px; text-align: left;">REF NO</th>
                                    <th style="padding: 6px 10px; text-align: left;">PEMETAAN LOT PENGGANTIAN</th>
                                    <th style="padding: 6px 10px; text-align: center;">QTY</th>
                                    <th style="padding: 6px 10px; text-align: center; width: 55px;">AKSI</th>
                                </tr>
                            </thead>
                            <tbody id="batch-scanned-table-body">
                                <tr>
                                    <td colspan="5" style="padding: 16px; text-align: center; color: #94a3b8;">
                                        Belum ada label QR yang discan. Silakan scan barcode label di atas.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

            <!-- Section 4: Catatan / Alasan (Opsional) -->
            <div>
                <label style="font-size: 11px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">Catatan Re-Inspeksi (Opsional):</label>
                <input id="batch-reinsp-notes" type="text" placeholder="Contoh: Re-inspect setelah sortir / lot diganti baru dari gudang..." style="width: 100%; padding: 9px 12px; font-size: 12px; border: 1.5px solid #cbd5e1; border-radius: 10px; outline: none; font-family: inherit; box-sizing: border-box;" onfocus="this.style.borderColor='#2563eb'" onblur="this.style.borderColor='#cbd5e1'">
            </div>

        </div>

        <!-- Modal Footer -->
        <div style="padding: 14px 18px; background-color: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0;">
            <button type="button" onclick="closeBatchReinspectionModal()" style="padding: 9px 20px; background-color: #ffffff; border: 1.5px solid #cbd5e1; color: #475569; border-radius: 10px; font-size: 12px; font-weight: 700; cursor: pointer; transition: all 0.15s;" onmouseover="this.style.backgroundColor='#f1f5f9'" onmouseout="this.style.backgroundColor='#ffffff'">
                Batal
            </button>
            <button id="btn-submit-batch-reinsp" type="button" onclick="submitBatchReinspection()" style="padding: 10px 24px; background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color: #ffffff; border: none; border-radius: 10px; font-size: 12px; font-weight: 800; cursor: pointer; box-shadow: 0 4px 14px rgba(37,99,235,0.35); display: flex; align-items: center; gap: 6px; transition: all 0.15s;" onmouseover="this.style.opacity='0.95'" onmouseout="this.style.opacity='1'">
                <span>Validasi Matching &amp; Muat Inspeksi</span>
            </button>
        </div>

    </div>
</div>


