        <!-- ── 2B. VIEW MODE PART OVERVIEW: ANALISIS & TREN PART (FAST EXECUTIVE VIEW) ──── -->
        <div id="viewContainerPartOverviewMode" style="display:none;flex-direction:column;gap:12px;">
            <!-- CARD 1: PART SELECTOR TOOLBAR -->
            <div id="partOverviewPartSelectorCard" class="no-print" style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:12px 16px;box-shadow:0 1px 3px rgba(0,0,0,0.03);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <span style="font-size:12px;font-weight:900;color:#0f172a;text-transform:uppercase;letter-spacing:0.5px;display:flex;align-items:center;gap:6px;">
                            <svg width="16" height="16" fill="none" stroke="#2563eb" stroke-width="2.5" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                            </svg>
                            Part Terpilih:
                        </span>
                        <div id="partOverviewSelectedBadge" style="display:inline-flex;align-items:center;gap:6px;padding:3.5px 10px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:7px;font-size:12px;font-weight:800;">
                            <span id="lblPartOverviewPartName">Memuat...</span>
                        </div>
                    </div>

                    <!-- Top 3 Part Pills Menjejer (Mode Halaman Web) -->
                    <div id="partOverviewTop3PillsContainer" style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;border-left:1.5px solid #e2e8f0;padding-left:10px;">
                        <!-- Dinamis via renderPartTop3SummaryPills -->
                    </div>
                </div>

                <!-- Executive Styled Part Custom Dropdown with Colored Rank Badges -->
                <div style="display:flex;align-items:center;gap:8px;position:relative;">
                    <span style="font-size:11px;font-weight:800;color:#475569;">Peringkat &amp; Daftar Part:</span>
                    <div style="position:relative;" id="partOverviewPartDropdownContainer">
                        <button type="button" id="btnPartOverviewDropdownToggle" onclick="togglePartOverviewMenu(event)"
                                style="display:inline-flex;align-items:center;justify-content:space-between;gap:12px;padding:6px 14px;border-radius:9px;font-size:12px;font-weight:800;min-width:320px;background:#f0f7ff;color:#0f172a;border:1.5px solid #3b82f6;outline:none;cursor:pointer;box-shadow:0 1px 3px rgba(37,99,235,0.15);transition:all 0.15s;">
                            <div id="partOverviewDropdownSelectedDisplay" style="display:inline-flex;align-items:center;gap:8px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                <span style="font-size:11px;color:#64748b;">Memuat daftar part...</span>
                            </div>
                            <svg width="14" height="14" fill="none" stroke="#2563eb" stroke-width="2.5" viewBox="0 0 24 24" style="flex-shrink:0;">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>

                        <!-- Floating Custom Menu -->
                        <div id="partOverviewMenuDropdown" class="thin-scrollbar"
                             style="display:none;position:absolute;right:0;top:calc(100% + 6px);z-index:100;background:#ffffff;border:1.5px solid #cbd5e1;border-radius:10px;box-shadow:0 12px 28px -4px rgba(15,23,42,0.18),0 4px 10px -2px rgba(15,23,42,0.08);width:380px;max-height:280px;overflow-y:auto;padding:5px;">
                            <!-- Items populated dynamically via renderPartOverviewRankPills -->
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── TIER 1: GRAFIK TREN KOMPARASI MULTI-HORIZON PART (LEBAR PENUH 100%) ── -->
            <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:14px;box-shadow:0 1px 3px rgba(0,0,0,0.03);display:flex;flex-direction:column;gap:10px;width:100%;">
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                    <div>
                        <h3 style="margin:0;font-size:13px;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                            <svg width="15" height="15" fill="none" stroke="#2563eb" stroke-width="2.5" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                            </svg>
                            <span>Tren &amp; Komparasi Kejadian Part</span>
                            <span class="partOverviewCardPartBadge" style="display:inline-flex;align-items:center;padding:1.5px 7px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:5px;font-size:11px;font-weight:800;font-family:monospace;">-</span>
                            <span class="partOverviewCardUnitBadge" style="display:inline-flex;align-items:center;padding:1.5px 8px;background:#f8fafc;color:#1e293b;border:1.5px solid #cbd5e1;border-radius:6px;font-size:10.5px;font-weight:900;letter-spacing:0.3px;box-shadow:0 1px 2px rgba(0,0,0,0.04);">PCS</span>
                        </h3>
                        <p id="partOverviewTrendIntervalDesc" style="margin:1px 0 0 21px;font-size:10px;color:#64748b;">
                            Komparasi makro-ke-mikro: Tahunan (Tahun dengan Data) &rarr; Bulanan (Tahun Berjalan) &rarr; Mingguan/Harian (Bulan Berjalan).
                        </p>
                    </div>
                    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                        <button type="button" id="btnPartOverviewThreshold" onclick="openThresholdModal('part_overview')"
                                style="display:inline-flex;align-items:center;gap:4px;padding:3px 8px;font-size:10px;font-weight:800;border:1px solid #fecaca;border-radius:5px;cursor:pointer;background:#fef2f2;color:#b91c1c;transition:all 0.15s;"
                                title="Atur Batas Ambang / Target Maksimal untuk Tren Part Ini">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                            <span>Target: <strong id="lblPartOverviewThreshold" style="font-weight:900;">-</strong></span>
                        </button>
                        <!-- Selector Resolusi Periode Mikro: Mingguan vs Harian -->
                        <div style="display:flex;align-items:center;background:#f1f5f9;padding:2px;border-radius:6px;border:1px solid #e2e8f0;">
                            <button type="button" id="btnPartOverviewResWeekly" onclick="switchPartOverviewResolution('weekly')"
                                    style="padding:3px 9px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:#0f172a;color:#ffffff;box-shadow:0 1px 2px rgba(15,23,42,0.2);"
                                    title="Tampilkan komparasi Tahunan, Bulanan, dan Mingguan (W1-W4)">
                                Resolusi Mingguan
                            </button>
                            <button type="button" id="btnPartOverviewResDaily" onclick="switchPartOverviewResolution('daily')"
                                    style="padding:3px 9px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:transparent;color:#64748b;"
                                    title="Tampilkan komparasi Tahunan, Bulanan, dan Harian per tanggal siklus kerja">
                                Resolusi Harian
                            </button>
                        </div>
                        <!-- Switcher Garis vs Batang -->
                        <div style="display:flex;align-items:center;background:#f1f5f9;padding:2px;border-radius:6px;border:1px solid #e2e8f0;">
                            <button type="button" id="btnPartOverviewTrendBar" onclick="switchPartOverviewTrendType('bar')"
                                    style="padding:3px 8px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:#0f172a;color:#ffffff;box-shadow:0 1px 2px rgba(15,23,42,0.2);">
                                Batang
                            </button>
                            <button type="button" id="btnPartOverviewTrendLine" onclick="switchPartOverviewTrendType('line')"
                                    style="padding:3px 8px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:transparent;color:#64748b;">
                                Garis
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Canvas Tren Full Width -->
                <div style="position:relative;height:240px;width:100%;">
                    <canvas id="chartPartOverviewTrend"></canvas>
                </div>
            </div>

            <!-- ── TIER 2: TABEL SEBARAN JENIS DEFECT TERDAMPAK (LEBAR PENUH 100%) ── -->
            <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:14px;box-shadow:0 1px 3px rgba(0,0,0,0.03);display:flex;flex-direction:column;gap:10px;width:100%;">
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                    <div>
                        <h3 style="margin:0;font-size:13px;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                            <svg width="15" height="15" fill="none" stroke="#059669" stroke-width="2.5" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z" />
                            </svg>
                            <span>Sebaran Jenis Defect</span>
                            <span class="partOverviewCardPartBadge" style="display:inline-flex;align-items:center;padding:1.5px 7px;background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;border-radius:5px;font-size:11px;font-weight:800;font-family:monospace;">-</span>
                        </h3>
                        <p style="margin:1px 0 0 21px;font-size:10px;color:#64748b;">
                            Komposisi defect yang paling sering terjadi pada part terpilih ini.
                        </p>
                    </div>
                    <div style="display:flex;align-items:center;gap:6px;">
                        <!-- Filter Rank Top Defect: 3 | 5 | Semua (Hanya aktif saat Tren Garis / Tren Batang) -->
                        <div id="partOverviewDefectRankGroup" style="display:none;align-items:center;background:#e2e8f0;padding:2px;border-radius:6px;gap:2px;">
                            <span style="font-size:9px;font-weight:800;color:#64748b;padding:0 4px;user-select:none;">Top:</span>
                            <button type="button" id="btnPartDefectRank3" onclick="switchPartDefectTrendRank(3)"
                                    style="padding:2.5px 7px;font-size:9.5px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:transparent;color:#64748b;">
                                3
                            </button>
                            <button type="button" id="btnPartDefectRank5" onclick="switchPartDefectTrendRank(5)"
                                    style="padding:2.5px 7px;font-size:9.5px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:#0f172a;color:#ffffff;box-shadow:0 1px 2px rgba(15,23,42,0.2);">
                                5
                            </button>
                            <button type="button" id="btnPartDefectRankAll" onclick="switchPartDefectTrendRank('all')"
                                    style="padding:2.5px 7px;font-size:9.5px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:transparent;color:#64748b;">
                                Semua
                            </button>
                        </div>

                        <!-- Sub-Toggle Resolusi Tren Defect (Week vs Harian) -->
                        <div id="partOverviewDefectSubResGroup" style="display:none;align-items:center;background:#e2e8f0;padding:2px;border-radius:6px;gap:2px;">
                            <button type="button" id="btnPartDefectResWeekly" onclick="switchPartDefectTrendResolution('weekly')"
                                    style="padding:2.5px 7px;font-size:9.5px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:#0f172a;color:#ffffff;box-shadow:0 1px 2px rgba(15,23,42,0.2);">
                                Week (W1-W4)
                            </button>
                            <button type="button" id="btnPartDefectResDaily" onclick="switchPartDefectTrendResolution('daily')"
                                    style="padding:2.5px 7px;font-size:9.5px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:transparent;color:#64748b;">
                                Harian
                            </button>
                        </div>

                        <div style="display:flex;align-items:center;background:#f1f5f9;padding:2px;border-radius:6px;border:1px solid #e2e8f0;flex-wrap:gap:2px;">
                            <button type="button" id="btnPartOverviewDefectDonut" onclick="switchPartOverviewDefectChartType('table')"
                                    style="padding:3px 9px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:#0f172a;color:#ffffff;box-shadow:0 1px 2px rgba(15,23,42,0.2);">
                                Tabel
                            </button>
                            <button type="button" id="btnPartOverviewDefectTotal" onclick="switchPartOverviewDefectChartType('bar_total')"
                                    style="padding:3px 9px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:transparent;color:#64748b;">
                                Total
                            </button>
                            <button type="button" id="btnPartOverviewDefectLine" onclick="switchPartOverviewDefectChartType('line_trend')"
                                    style="padding:3px 9px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:transparent;color:#64748b;"
                                    title="Grafik tren garis rentang tanggal per jenis defect">
                                Tren Garis
                            </button>
                            <button type="button" id="btnPartOverviewDefectBar" onclick="switchPartOverviewDefectChartType('bar_trend')"
                                    style="padding:3px 9px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:transparent;color:#64748b;"
                                    title="Grafik tren batang bertingkat rentang tanggal per jenis defect">
                                Tren Batang
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Container Grafik Defect (Table Layout OR Full Width Timeline/Bar) -->
                <div id="partOverviewDefectChartContainer" style="min-height:245px;display:flex;align-items:stretch;">
                    <!-- Mode 1: Table Layout (Tabel Sebaran Ramping Responsif) -->
                    <div id="partOverviewDefectDonutWrapper" style="display:flex;flex-direction:column;width:100%;max-height:280px;overflow-y:auto;" class="thin-scrollbar">
                        <table style="width:100%;border-collapse:collapse;font-size:11px;text-align:left;">
                            <thead style="position:sticky;top:0;background:#f8fafc;z-index:5;border-bottom:1.5px solid #e2e8f0;box-shadow:0 1px 2px rgba(0,0,0,0.02);">
                                <tr style="color:#64748b;font-weight:800;text-transform:uppercase;font-size:9.5px;letter-spacing:0.4px;">
                                    <th style="padding:6px 8px;width:32px;text-align:center;">No</th>
                                    <th style="padding:6px 10px;">Jenis Defect</th>
                                    <th id="thPartOverviewDefectVal" style="padding:6px 10px;text-align:right;width:140px;">Total Temuan</th>
                                    <th id="thPartOverviewDefectTarget" style="padding:6px 10px;text-align:center;width:160px;">Batas Target</th>
                                </tr>
                            </thead>
                            <tbody id="partOverviewDefectTableBody">
                                <tr>
                                    <td colspan="4" style="padding:20px;text-align:center;color:#94a3b8;font-size:11px;">Memuat sebaran defect...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Mode 2: Full Width Canvas untuk Total Bar / Tren Garis / Tren Batang -->
                    <div id="partOverviewDefectTimelineWrapper" style="display:none;position:relative;height:245px;width:100%;">
                        <canvas id="chartPartOverviewDefects"></canvas>
                    </div>
                </div>
            </div>

            <!-- ROW 3: TABEL RIWAYAT TEMUAN INSPEKSI TERPERINCI PART (FIT-TO-SCREEN INTERNAL SCROLL) -->
            <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,0.03);overflow:hidden;display:flex;flex-direction:column;">
                <div style="padding:10px 16px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;background:#f8fafc;">
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <svg width="16" height="16" fill="none" stroke="#2563eb" stroke-width="2.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                        </svg>
                        <h3 style="margin:0;font-size:13px;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                            <span>Riwayat Temuan Cacat Inspeksi Part Terperinci</span>
                            <span class="partOverviewCardPartBadge" style="display:inline-flex;align-items:center;padding:1.5px 7px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:5px;font-size:11px;font-weight:800;font-family:monospace;">-</span>
                        </h3>
                        <span id="partOverviewTableCountBadge" style="padding:2px 7px;border-radius:5px;font-size:10px;font-weight:800;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;">
                            0 Temuan
                        </span>
                    </div>

                    <div style="display:flex;align-items:center;gap:8px;">
                        <div style="position:relative;">
                            <input type="text" id="partOverviewTableSearchInput" placeholder="Cari defect / model / customer / lot..."
                                   style="padding:4px 8px 4px 26px;font-size:11px;border-radius:6px;border:1px solid #cbd5e1;outline:none;width:220px;background:#ffffff;">
                            <svg width="12" height="12" fill="none" stroke="#94a3b8" stroke-width="2" viewBox="0 0 24 24"
                                 style="position:absolute;left:8px;top:50%;transform:translateY(-50%);pointer-events:none;">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                        </div>
                    </div>
                </div>

                <!-- Indikator Filter Excel Aktif Bar -->
                <div id="part-overview-excel-filter-active-bar" style="display: none; background: #eff6ff; border-bottom: 1.5px solid #bfdbfe; padding: 7px 16px; align-items: center; justify-content: space-between; font-size: 11px;">
                    <div style="display: flex; align-items: center; gap: 6px; color: #1e40af; font-weight: 700;">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                        <span id="part-overview-excel-filter-summary-text">Menyaring data tabel...</span>
                    </div>
                    <button type="button" onclick="resetAllPartOverviewExcelFilters()" style="background: #ffffff; border: 1px solid #bfdbfe; color: #1d4ed8; font-weight: 800; font-size: 10.5px; padding: 2.5px 9px; border-radius: 5px; cursor: pointer; transition: all 0.15s;" onmouseover="this.style.background='#dbeafe'" onmouseout="this.style.background='#ffffff'">
                        Reset Semua Filter
                    </button>
                </div>

                <!-- Table Body Container (Full view, horizontal scrollable if needed) -->
                <div style="overflow-x:auto;">
                    <table style="width:100%;border-collapse:collapse;text-align:left;font-size:11px;">
                        <thead style="position:sticky;top:0;background:#f8fafc;z-index:10;box-shadow:0 1px 2px rgba(0,0,0,0.05);">
                            <tr style="color:#475569;border-bottom:1px solid #e2e8f0;font-weight:800;text-transform:uppercase;font-size:10px;letter-spacing:0.5px;">
                                <th style="padding:8px 12px;text-align:center;width:40px;">No</th>

                                <!-- Tanggal & Jam -->
                                <th style="padding:8px 12px;width:130px;position:relative;">
                                    <div class="th-filter-wrapper">
                                        <span>Tanggal &amp; Jam</span>
                                        <button type="button" class="th-filter-btn" data-col="date" title="Filter Tanggal" onclick="togglePartOverviewExcelFilter(event, 'date')">
                                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                        </button>
                                    </div>
                                    <div id="part-overview-filter-dropdown-date" class="excel-filter-dropdown align-left">
                                        <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                            <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER TANGGAL</span>
                                            <button type="button" class="excel-reset-btn" onclick="resetSinglePartOverviewColFilter('date')" title="Reset filter">
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                                <span>Reset</span>
                                            </button>
                                        </div>
                                        <input type="text" class="excel-filter-search" placeholder="Cari tanggal..." oninput="onSearchPartOverviewFilterOptions('date', this.value)">
                                        <div class="flex items-center gap-1.5 mb-2">
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllPartOverviewFilter('date', true)">
                                                <svg width="11" height="11" class="text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                                <span>Pilih Semua</span>
                                            </button>
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllPartOverviewFilter('date', false)">
                                                <svg width="11" height="11" class="text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                <span>Hapus Pilihan</span>
                                            </button>
                                        </div>
                                        <div class="excel-filter-list" id="part-overview-filter-list-date"></div>
                                        <div class="excel-filter-actions">
                                            <span class="text-[10px] text-slate-500 font-bold filter-count-info"></span>
                                            <div class="flex items-center gap-2">
                                                <button type="button" class="excel-btn-close" onclick="closeAllPartOverviewExcelFilters()">Tutup</button>
                                                <button type="button" class="excel-btn-apply" onclick="applyPartOverviewExcelFilters()">
                                                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                                    <span>Terapkan</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </th>

                                <!-- Model -->

                                <!-- Model -->
                                <th style="padding:8px 12px;min-width:110px;position:relative;">
                                    <div class="th-filter-wrapper">
                                        <span>Model</span>
                                        <button type="button" class="th-filter-btn" data-col="model" title="Filter Model" onclick="togglePartOverviewExcelFilter(event, 'model')">
                                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                        </button>
                                    </div>
                                    <div id="part-overview-filter-dropdown-model" class="excel-filter-dropdown align-left">
                                        <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                            <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER MODEL</span>
                                            <button type="button" class="excel-reset-btn" onclick="resetSinglePartOverviewColFilter('model')" title="Reset filter">
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                                <span>Reset</span>
                                            </button>
                                        </div>
                                        <input type="text" class="excel-filter-search" placeholder="Cari model..." oninput="onSearchPartOverviewFilterOptions('model', this.value)">
                                        <div class="flex items-center gap-1.5 mb-2">
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllPartOverviewFilter('model', true)">
                                                <svg width="11" height="11" class="text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                                <span>Pilih Semua</span>
                                            </button>
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllPartOverviewFilter('model', false)">
                                                <svg width="11" height="11" class="text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                <span>Hapus Pilihan</span>
                                            </button>
                                        </div>
                                        <div class="excel-filter-list" id="part-overview-filter-list-model"></div>
                                        <div class="excel-filter-actions">
                                            <span class="text-[10px] text-slate-500 font-bold filter-count-info"></span>
                                            <div class="flex items-center gap-2">
                                                <button type="button" class="excel-btn-close" onclick="closeAllPartOverviewExcelFilters()">Tutup</button>
                                                <button type="button" class="excel-btn-apply" onclick="applyPartOverviewExcelFilters()">
                                                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                                    <span>Terapkan</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </th>

                                <!-- Jenis Defect -->
                                <th style="padding:8px 12px;min-width:130px;position:relative;">
                                    <div class="th-filter-wrapper">
                                        <span>Jenis Defect</span>
                                        <button type="button" class="th-filter-btn" data-col="defect" title="Filter Defect" onclick="togglePartOverviewExcelFilter(event, 'defect')">
                                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                        </button>
                                    </div>
                                    <div id="part-overview-filter-dropdown-defect" class="excel-filter-dropdown align-left">
                                        <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                            <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER DEFECT</span>
                                            <button type="button" class="excel-reset-btn" onclick="resetSinglePartOverviewColFilter('defect')" title="Reset filter">
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                                <span>Reset</span>
                                            </button>
                                        </div>
                                        <input type="text" class="excel-filter-search" placeholder="Cari defect..." oninput="onSearchPartOverviewFilterOptions('defect', this.value)">
                                        <div class="flex items-center gap-1.5 mb-2">
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllPartOverviewFilter('defect', true)">
                                                <svg width="11" height="11" class="text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                                <span>Pilih Semua</span>
                                            </button>
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllPartOverviewFilter('defect', false)">
                                                <svg width="11" height="11" class="text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                <span>Hapus Pilihan</span>
                                            </button>
                                        </div>
                                        <div class="excel-filter-list" id="part-overview-filter-list-defect"></div>
                                        <div class="excel-filter-actions">
                                            <span class="text-[10px] text-slate-500 font-bold filter-count-info"></span>
                                            <div class="flex items-center gap-2">
                                                <button type="button" class="excel-btn-close" onclick="closeAllPartOverviewExcelFilters()">Tutup</button>
                                                <button type="button" class="excel-btn-apply" onclick="applyPartOverviewExcelFilters()">
                                                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                                    <span>Terapkan</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </th>

                                <!-- Tipe Inspeksi -->
                                <th style="padding:8px 12px;text-align:center;width:95px;position:relative;">
                                    <div class="th-filter-wrapper">
                                        <span>Inspeksi</span>
                                        <button type="button" class="th-filter-btn" data-col="type" title="Filter Tipe" onclick="togglePartOverviewExcelFilter(event, 'type')">
                                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                        </button>
                                    </div>
                                    <div id="part-overview-filter-dropdown-type" class="excel-filter-dropdown align-left">
                                        <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                            <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER INSPEKSI</span>
                                            <button type="button" class="excel-reset-btn" onclick="resetSinglePartOverviewColFilter('type')" title="Reset filter">
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                                <span>Reset</span>
                                            </button>
                                        </div>
                                        <input type="text" class="excel-filter-search" placeholder="Cari tipe..." oninput="onSearchPartOverviewFilterOptions('type', this.value)">
                                        <div class="flex items-center gap-1.5 mb-2">
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllPartOverviewFilter('type', true)">
                                                <svg width="11" height="11" class="text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                                <span>Pilih Semua</span>
                                            </button>
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllPartOverviewFilter('type', false)">
                                                <svg width="11" height="11" class="text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                <span>Hapus Pilihan</span>
                                            </button>
                                        </div>
                                        <div class="excel-filter-list" id="part-overview-filter-list-type"></div>
                                        <div class="excel-filter-actions">
                                            <span class="text-[10px] text-slate-500 font-bold filter-count-info"></span>
                                            <div class="flex items-center gap-2">
                                                <button type="button" class="excel-btn-close" onclick="closeAllPartOverviewExcelFilters()">Tutup</button>
                                                <button type="button" class="excel-btn-apply" onclick="applyPartOverviewExcelFilters()">
                                                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                                    <span>Terapkan</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </th>

                                <!-- Kanban & Customer -->
                                <th style="padding:8px 12px;min-width:130px;position:relative;">
                                    <div class="th-filter-wrapper">
                                        <span>No Kanban / Cust</span>
                                        <button type="button" class="th-filter-btn" data-col="customer" title="Filter Customer" onclick="togglePartOverviewExcelFilter(event, 'customer')">
                                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                        </button>
                                    </div>
                                    <div id="part-overview-filter-dropdown-customer" class="excel-filter-dropdown align-left">
                                        <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                            <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER CUSTOMER</span>
                                            <button type="button" class="excel-reset-btn" onclick="resetSinglePartOverviewColFilter('customer')" title="Reset filter">
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                                <span>Reset</span>
                                            </button>
                                        </div>
                                        <input type="text" class="excel-filter-search" placeholder="Cari customer..." oninput="onSearchPartOverviewFilterOptions('customer', this.value)">
                                        <div class="flex items-center gap-1.5 mb-2">
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllPartOverviewFilter('customer', true)">
                                                <svg width="11" height="11" class="text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                                <span>Pilih Semua</span>
                                            </button>
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllPartOverviewFilter('customer', false)">
                                                <svg width="11" height="11" class="text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                <span>Hapus Pilihan</span>
                                            </button>
                                        </div>
                                        <div class="excel-filter-list" id="part-overview-filter-list-customer"></div>
                                        <div class="excel-filter-actions">
                                            <span class="text-[10px] text-slate-500 font-bold filter-count-info"></span>
                                            <div class="flex items-center gap-2">
                                                <button type="button" class="excel-btn-close" onclick="closeAllPartOverviewExcelFilters()">Tutup</button>
                                                <button type="button" class="excel-btn-apply" onclick="applyPartOverviewExcelFilters()">
                                                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                                    <span>Terapkan</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </th>

                                <th style="padding:8px 12px;min-width:100px;">Lot No / Ref</th>

                                <!-- QC Inspector -->
                                <th style="padding:8px 12px;min-width:110px;position:relative;">
                                    <div class="th-filter-wrapper">
                                        <span>QC Inspector</span>
                                        <button type="button" class="th-filter-btn" data-col="inspector" title="Filter Inspector" onclick="togglePartOverviewExcelFilter(event, 'inspector')">
                                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                        </button>
                                    </div>
                                    <div id="part-overview-filter-dropdown-inspector" class="excel-filter-dropdown align-right">
                                        <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                            <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER INSPECTOR</span>
                                            <button type="button" class="excel-reset-btn" onclick="resetSinglePartOverviewColFilter('inspector')" title="Reset filter">
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                                <span>Reset</span>
                                            </button>
                                        </div>
                                        <input type="text" class="excel-filter-search" placeholder="Cari inspector..." oninput="onSearchPartOverviewFilterOptions('inspector', this.value)">
                                        <div class="flex items-center gap-1.5 mb-2">
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllPartOverviewFilter('inspector', true)">
                                                <svg width="11" height="11" class="text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                                <span>Pilih Semua</span>
                                            </button>
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllPartOverviewFilter('inspector', false)">
                                                <svg width="11" height="11" class="text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                <span>Hapus Pilihan</span>
                                            </button>
                                        </div>
                                        <div class="excel-filter-list" id="part-overview-filter-list-inspector"></div>
                                        <div class="excel-filter-actions">
                                            <span class="text-[10px] text-slate-500 font-bold filter-count-info"></span>
                                            <div class="flex items-center gap-2">
                                                <button type="button" class="excel-btn-close" onclick="closeAllPartOverviewExcelFilters()">Tutup</button>
                                                <button type="button" class="excel-btn-apply" onclick="applyPartOverviewExcelFilters()">
                                                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                                    <span>Terapkan</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </th>

                                <th style="padding:8px 12px;text-align:right;width:100px;">Jumlah Temuan</th>
                            </tr>
                        </thead>
                        <tbody id="partOverviewHistoryTableBody">
                            <tr>
                                <td colspan="10" style="padding:24px;text-align:center;color:#94a3b8;font-weight:600;">
                                    Memuat catatan riwayat temuan inspeksi...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div style="padding:8px 16px;background:#f8fafc;border-top:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;font-size:11px;color:#64748b;">
                    <span id="partOverviewTableFooterNote">
                        Data riwayat temuan inspeksi terperinci untuk part terpilih.
                    </span>
                    <span style="color:#94a3b8;font-size:10px;">
                        Urutan inspeksi terbaru di atas.
                    </span>
                </div>
            </div>
        </div>

