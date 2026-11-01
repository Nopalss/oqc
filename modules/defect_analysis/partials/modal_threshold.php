        <!-- ── MODAL PENGATURAN BATAS TARGET / AMBANG (THRESHOLD MODAL) ── -->
        <div id="modalDefectThreshold" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.6);backdrop-filter:blur(3px);z-index:9999;align-items:center;justify-content:center;padding:16px;">
            <div style="background:#ffffff;border-radius:14px;box-shadow:0 20px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.1);border:1px solid #e2e8f0;width:100%;max-width:480px;max-height:90vh;display:flex;flex-direction:column;overflow:hidden;">
                <!-- Modal Header -->
                <div style="padding:14px 18px;border-bottom:1px solid #f1f5f9;background:#f8fafc;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <div style="width:30px;height:30px;background:#fee2e2;border:1px solid #fecaca;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#dc2626;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                        </div>
                        <div>
                            <h4 style="margin:0;font-size:13px;font-weight:900;color:#0f172a;">Pengaturan Batas Maksimal (Target)</h4>
                            <p id="modalThresholdSubtitle" style="margin:1px 0 0 0;font-size:10px;color:#64748b;">Batas ambang garis merah pada grafik tren</p>
                        </div>
                    </div>
                    <button type="button" onclick="closeThresholdModal()" style="border:none;background:transparent;cursor:pointer;color:#94a3b8;padding:4px;border-radius:6px;display:flex;align-items:center;justify-content:center;" onmouseover="this.style.color='#0f172a'" onmouseout="this.style.color='#94a3b8'">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>

                <!-- Modal Body -->
                <div style="padding:16px 18px;display:flex;flex-direction:column;gap:14px;overflow-y:auto;flex:1;" class="thin-scrollbar">
                    <!-- Active Item Info Banner -->
                    <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:9px 12px;display:flex;align-items:center;justify-content:space-between;gap:10px;">
                        <span id="modalThresholdItemType" style="font-size:10.5px;font-weight:700;color:#1e40af;text-transform:uppercase;">Item Terpilih:</span>
                        <strong id="modalThresholdItemName" style="font-size:11.5px;font-weight:900;color:#1e3a8a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:240px;">-</strong>
                    </div>

                    <!-- Input Nilai Batas Target -->
                    <div>
                        <label style="display:block;font-size:11px;font-weight:800;color:#334155;margin-bottom:5px;">
                            Batas Ambang / Nilai Target Maksimal (<span id="modalThresholdUnitLabel">PCS</span>) <span style="color:#ef4444;">*</span>
                        </label>
                        <div style="position:relative;">
                            <input type="number" step="any" min="0" id="inputThresholdValue" placeholder="Misal: 10 atau 0.5"
                                   style="width:100%;box-sizing:border-box;padding:8px 45px 8px 12px;font-size:13px;font-weight:800;border:1.5px solid #cbd5e1;border-radius:8px;outline:none;background:#ffffff;color:#0f172a;">
                            <span id="inputThresholdUnitSuffix" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);font-size:11px;font-weight:800;color:#64748b;pointer-events:none;">
                                PCS
                            </span>
                        </div>
                        <p style="margin:4px 0 0 0;font-size:10px;color:#64748b;">
                            Garis putus-putus merah akan muncul horizontal di grafik tren pada nilai ini.
                        </p>
                    </div>

                    <!-- Radio Pilihan Cakupan Target (Scope) -->
                    <div>
                        <label style="display:block;font-size:11px;font-weight:800;color:#334155;margin-bottom:6px;">
                            Cakupan Penerapan Target:
                        </label>
                        <div style="display:flex;flex-direction:column;gap:7px;">
                            <label style="display:flex;align-items:flex-start;gap:8px;padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:8px;background:#ffffff;cursor:pointer;transition:all 0.15s;" id="labelScopeSpecific">
                                <input type="radio" name="thresholdScope" value="specific" checked onchange="onThresholdScopeChange(this.value)" style="margin-top:2px;">
                                <div style="font-size:11px;">
                                    <strong style="color:#0f172a;display:block;">Terapkan HANYA untuk item ini</strong>
                                    <span style="color:#64748b;font-size:10px;">Hanya berlaku untuk defect / part yang sedang aktif dipilih.</span>
                                </div>
                            </label>

                            <label style="display:flex;align-items:flex-start;gap:8px;padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:8px;background:#ffffff;cursor:pointer;transition:all 0.15s;" id="labelScopeBatch">
                                <input type="radio" name="thresholdScope" value="batch" onchange="onThresholdScopeChange(this.value)" style="margin-top:2px;">
                                <div style="font-size:11px;">
                                    <strong style="color:#0f172a;display:block;">Pilih beberapa item (Centang / Checklist)</strong>
                                    <span style="color:#64748b;font-size:10px;">Centang item tertentu untuk menerapkan nilai target secara bersamaan.</span>
                                </div>
                            </label>

                            <!-- Container Checklist Items (Defect / Part) -->
                            <div id="containerThresholdBatchList" style="display:none;background:#f8fafc;border:1.5px solid #cbd5e1;border-radius:8px;padding:10px;margin-top:2px;">
                                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;gap:6px;flex-wrap:wrap;">
                                    <input type="text" id="thresholdBatchSearch" placeholder="Cari nama item / part..." oninput="filterThresholdBatchList(this.value)"
                                           style="flex:1;padding:4px 8px;font-size:11px;border-radius:6px;border:1px solid #cbd5e1;outline:none;background:#ffffff;">
                                    <div style="display:flex;align-items:center;gap:4px;">
                                        <button type="button" onclick="toggleAllThresholdBatchItems(true)" style="padding:3px 7px;font-size:10px;font-weight:800;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:5px;cursor:pointer;">
                                            Pilih Semua
                                        </button>
                                        <button type="button" onclick="toggleAllThresholdBatchItems(false)" style="padding:3px 7px;font-size:10px;font-weight:800;background:#ffffff;color:#64748b;border:1px solid #cbd5e1;border-radius:5px;cursor:pointer;">
                                            Bersihkan
                                        </button>
                                    </div>
                                </div>
                                <div id="thresholdBatchItemsWrapper" style="max-height:160px;overflow-y:auto;display:flex;flex-direction:column;gap:4px;padding-right:2px;" class="thin-scrollbar">
                                    <div style="font-size:11px;color:#94a3b8;text-align:center;padding:10px;">Memuat daftar item...</div>
                                </div>
                                <div style="margin-top:6px;display:flex;align-items:center;justify-content:space-between;font-size:10px;color:#64748b;">
                                    <span>Dipilih: <strong id="lblThresholdBatchSelectedCount" style="color:#0f172a;">0</strong> item</span>
                                    <span style="font-style:italic;">*Badge warna menunjukkan target saat ini</span>
                                </div>
                            </div>

                            <label style="display:flex;align-items:flex-start;gap:8px;padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:8px;background:#ffffff;cursor:pointer;transition:all 0.15s;" id="labelScopeAll">
                                <input type="radio" name="thresholdScope" value="all" onchange="onThresholdScopeChange(this.value)" style="margin-top:2px;">
                                <div style="font-size:11px;">
                                    <strong style="color:#0f172a;display:block;">Terapkan ke SEMUA defect / part (Global)</strong>
                                    <span style="color:#64748b;font-size:10px;">Batas ini menjadi acuan default untuk seluruh item yang belum memiliki batas khusus.</span>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Status Target Saat Ini -->
                    <div id="modalThresholdCurrentStatus" style="font-size:10.5px;color:#64748b;background:#f8fafc;padding:7px 10px;border-radius:6px;border:1px solid #f1f5f9;">
                        Status saat ini: <span id="modalThresholdCurrentText" style="font-weight:700;color:#0f172a;">Belum ada target</span>
                    </div>
                </div>

                <!-- Modal Footer Actions -->
                <div style="padding:12px 18px;border-top:1px solid #f1f5f9;background:#f8fafc;display:flex;align-items:center;justify-content:space-between;gap:8px;flex-shrink:0;">
                    <button type="button" id="btnThresholdClear" onclick="submitThresholdForm(true)"
                            style="padding:6px 12px;font-size:11px;font-weight:800;color:#dc2626;background:#fee2e2;border:1px solid #fecaca;border-radius:6px;cursor:pointer;transition:all 0.15s;"
                            title="Hapus konfigurasi target untuk item/lingkup ini">
                        Hapus Batas
                    </button>
                    <div style="display:flex;align-items:center;gap:6px;">
                        <button type="button" onclick="closeThresholdModal()"
                                style="padding:6px 12px;font-size:11px;font-weight:700;color:#475569;background:#ffffff;border:1px solid #cbd5e1;border-radius:6px;cursor:pointer;">
                            Batal
                        </button>
                        <button type="button" id="btnThresholdSave" onclick="submitThresholdForm(false)"
                                style="padding:6px 16px;font-size:11px;font-weight:800;color:#ffffff;background:#2563eb;border:none;border-radius:6px;cursor:pointer;box-shadow:0 1px 2px rgba(37,99,235,0.25);">
                            Simpan Target
                        </button>
                    </div>
                </div>
            </div>
        </div>




        <!-- Responsive Fallback CSS -->
    <style>
        @media (max-width: 1024px) {
            #deepDiveKpiGrid { grid-template-columns: repeat(2, 1fr) !important; }
            .side-by-side-grid { grid-template-columns: 1fr !important; }
            .part-donut-grid { grid-template-columns: 1fr !important; }
        }
        @media (max-width: 768px) {
            .funnel-grid-layout { grid-template-columns: 1fr !important; }
            .side-by-side-grid { grid-template-columns: 1fr !important; }
            .part-donut-grid { grid-template-columns: 1fr !important; }
        }
        @media (max-width: 640px) {
            #deepDiveKpiGrid { grid-template-columns: 1fr !important; }
        }
        .funnel-item-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 8px 12px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            background: #ffffff;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .funnel-item-card:hover {
            border-color: #94a3b8;
            background: #f8fafc;
            transform: translateX(2px);
        }
        .funnel-card-active-1 {
            border-color: #2563eb !important;
            background: #eff6ff !important;
            box-shadow: 0 0 0 1px #2563eb;
        }
        .funnel-card-active-2 {
            border-color: #4f46e5 !important;
            background: #eef2ff !important;
            box-shadow: 0 0 0 1px #4f46e5;
        }
        .funnel-card-active-3 {
            border-color: #059669 !important;
            background: #ecfdf5 !important;
            box-shadow: 0 0 0 1px #059669;
        }
        /* ── Donut Centerpiece Widget ────────────────────────────────────────── */
        .donut-center-badge {
            position: absolute;
            pointer-events: none;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            width: 126px;
            height: 126px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.94);
            backdrop-filter: blur(4px);
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.05);
            border: 1px solid #f1f5f9;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 10;
        }
        .donut-center-sub {
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.5px;
            color: #94a3b8;
            text-transform: uppercase;
            max-width: 112px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: block;
            transition: color 0.15s ease;
        }
        .donut-center-val {
            font-size: 22px;
            font-weight: 900;
            color: #0f172a;
            line-height: 1.1;
            margin: 2px 0;
            font-feature-settings: "tnum";
            font-variant-numeric: tabular-nums;
            transition: all 0.15s ease;
        }
        .donut-center-unit {
            font-size: 10px;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
    </style>
