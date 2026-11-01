        <!-- ── 1. FILTER CONTROLS TOOLBAR (Stable & Non-Wrapping 2-Row Layout) ───────────── -->
        <div id="filterControlsToolbar" style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:12px 16px;box-shadow:0 1px 3px rgba(0,0,0,0.03);display:flex;flex-direction:column;gap:12px;">
            
            <!-- ROW 1: Perspective Tabs (Left) & Primary Action Buttons (Right) -->
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                <!-- Left: Mode Switcher -->
                <div style="display:flex;align-items:center;gap:6px;">
                    <div id="groupModeSwitcher" style="display:inline-flex;align-items:center;gap:3px;background:#f1f5f9;padding:3.5px;border-radius:9px;border:1px solid #e2e8f0;" title="Pilih Perspektif Analisis">
                        <button type="button" id="btnModeOverview" onclick="switchAnalysisMode('overview')"
                                style="padding:6px 14px;border-radius:6px;font-size:11.5px;font-weight:800;border:none;cursor:pointer;transition:all 0.2s;background:#0f172a;color:#ffffff;box-shadow:0 1px 3px rgba(15,23,42,0.25);">
                            🎯 Analisis &amp; Tren Defect
                        </button>
                        <button type="button" id="btnModePartOverview" onclick="switchAnalysisMode('part_overview')"
                                style="padding:6px 14px;border-radius:6px;font-size:11.5px;font-weight:800;border:none;cursor:pointer;transition:all 0.2s;background:transparent;color:#64748b;">
                            📦 Analisis &amp; Tren Part
                        </button>
                    </div>
                </div>

                <!-- Right: Action Buttons (Export Excel, Print, Reset) -->
                <div style="display:flex;align-items:center;gap:8px;">
                    <button type="button" onclick="exportDefectExcel()" 
                            style="display:inline-flex;align-items:center;gap:6px;padding:6px 14px;background:#059669;color:#ffffff;font-size:11px;font-weight:700;border-radius:8px;border:none;cursor:pointer;box-shadow:0 1px 3px rgba(5,150,105,0.25);transition:all 0.2s;"
                            onmouseover="this.style.background='#047857'" onmouseout="this.style.background='#059669'"
                            title="Ekspor data analisis dan riwayat temuan ke Excel (.xlsx)">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        Export Excel
                    </button>
                    <button type="button" onclick="printAnalysisReport()" 
                            style="display:inline-flex;align-items:center;gap:6px;padding:6px 14px;background:#1e293b;color:#ffffff;font-size:11px;font-weight:700;border-radius:8px;border:none;cursor:pointer;box-shadow:0 1px 3px rgba(0,0,0,0.15);transition:all 0.2s;"
                            onmouseover="this.style.background='#0f172a'" onmouseout="this.style.background='#1e293b'"
                            title="Cetak laporan analisis 1:1 sesuai tab yang sedang aktif">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                        </svg>
                        Cetak Laporan
                    </button>
                    <button type="button" onclick="resetAllFilters()" 
                            style="display:inline-flex;align-items:center;gap:4px;padding:6px 12px;background:#fff1f2;color:#e11d48;border:1px solid #fecdd3;border-radius:8px;font-size:11px;font-weight:700;cursor:pointer;transition:all 0.2s;"
                            title="Kembalikan semua filter ke pengaturan standar">
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        Reset
                    </button>
                </div>
            </div>

            <!-- ROW 2: Date Range Presets + Pickers (Left) & Customer + Satuan (Right) -->
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;border-top:1px solid #f1f5f9;padding-top:10px;">
                <!-- Left: Preset Date Pills & Dynamic Sub-Filters (Extremely Spacious) -->
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;flex:1;min-width:0;">
                    <!-- Shortcut Bulan Ini & Dropdown Langsung Bulan - Tahun -->
                    <div style="display:inline-flex;align-items:center;gap:6px;flex-wrap:wrap;">
                        <button type="button" id="btnFilterBulanIni" onclick="applyCurrentMonthFilter()"
                                style="padding:5px 13px;border-radius:7px;font-size:11px;font-weight:700;border:1px solid #bfdbfe;cursor:pointer;transition:all 0.2s;background:#2563eb;color:#ffffff;box-shadow:0 1px 3px rgba(37,99,235,0.25);">
                            Bulan Ini
                        </button>

                        <div style="display:inline-flex;align-items:center;gap:6px;background:#f8fafc;padding:3px 9px;border-radius:8px;border:1px solid #cbd5e1;">
                            <span style="font-size:11px;font-weight:700;color:#64748b;">Bulan:</span>
                            <select id="directSelectMonth" onchange="onMonthYearChanged()" class="form-input text-xs" style="padding:3px 7px;border-radius:6px;border:1px solid #cbd5e1;font-size:11px;font-weight:600;background:#ffffff;cursor:pointer;">
                                <?php foreach ($monthNames as $mNum => $mName): ?>
                                    <option value="<?= $mNum ?>" <?= $selectedMonth === $mNum ? 'selected' : '' ?>><?= $mName ?></option>
                                <?php endforeach; ?>
                            </select>

                            <span style="font-size:11px;font-weight:700;color:#64748b;margin-left:4px;">Tahun:</span>
                            <select id="directSelectYear" onchange="onMonthYearChanged()" class="form-input text-xs" style="padding:3px 7px;border-radius:6px;border:1px solid #cbd5e1;font-size:11px;font-weight:600;background:#ffffff;cursor:pointer;">
                                <?php foreach ($availableYears as $y): ?>
                                    <option value="<?= $y ?>" <?= $selectedYear === (int)$y ? 'selected' : '' ?>><?= $y ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Right: Customer Filter & Satuan Toggle -->
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;flex-shrink:0;">
                    <!-- Customer Selector -->
                    <div style="display:flex;align-items:center;gap:5px;">
                        <span style="font-size:11px;font-weight:700;color:#475569;">Customer:</span>
                        <select id="selectCustomer" onchange="onCustomerChange(this.value)" class="form-input text-xs" style="padding:5px 9px;border-radius:7px;font-weight:600;min-width:150px;border:1px solid #cbd5e1;background:#ffffff;">
                            <option value="">-- Semua Customer --</option>
                            <?php foreach ($customerOptions as $cOpt): ?>
                                <option value="<?= htmlspecialchars($cOpt) ?>" <?= $selectedCustomer === $cOpt ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cOpt) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Unit Segmented Toggle (PCS vs PPM vs LOT) -->
                    <div style="display:flex;align-items:center;gap:3px;background:#f1f5f9;padding:3px;border-radius:9px;border:1px solid #e2e8f0;" title="Pilih Satuan Analisis">
                        <button type="button" id="btnUnitPcs" onclick="switchUnit('pcs')"
                                style="padding:5px 12px;border-radius:6px;font-size:11px;font-weight:800;border:none;cursor:pointer;transition:all 0.2s;background:#0f172a;color:#ffffff;box-shadow:0 1px 3px rgba(15,23,42,0.25);">
                            PCS
                        </button>
                        <button type="button" id="btnUnitPpm" onclick="switchUnit('ppm')"
                                style="padding:5px 12px;border-radius:6px;font-size:11px;font-weight:800;border:none;cursor:pointer;transition:all 0.2s;background:transparent;color:#64748b;">
                            PPM
                        </button>
                        <button type="button" id="btnUnitLot" onclick="switchUnit('lot')"
                                style="padding:5px 12px;border-radius:6px;font-size:11px;font-weight:800;border:none;cursor:pointer;transition:all 0.2s;background:transparent;color:#64748b;">
                            LOT
                        </button>
                    </div>
                </div>
            </div>
        </div>

