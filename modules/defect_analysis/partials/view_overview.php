        <!-- ── 2. VIEW MODE OVERVIEW: ANALISIS & TREN DEFECT (DEFAULT - FAST EXECUTIVE VIEW) ──── -->
        <div id="viewContainerOverviewMode" style="display:flex;flex-direction:column;gap:12px;">
            <!-- CARD 1: DEFECT SELECTOR TOOLBAR -->
            <div id="overviewDefectSelectorCard" class="no-print" style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:12px 16px;box-shadow:0 1px 3px rgba(0,0,0,0.03);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <span style="font-size:12px;font-weight:900;color:#0f172a;text-transform:uppercase;letter-spacing:0.5px;display:flex;align-items:center;gap:6px;">
                            <svg width="16" height="16" fill="none" stroke="#2563eb" stroke-width="2.5" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z" />
                            </svg>
                            Defect Terpilih:
                        </span>
                        <div id="overviewSelectedBadge" style="display:inline-flex;align-items:center;gap:6px;padding:3.5px 10px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:7px;font-size:12px;font-weight:800;">
                            <span id="lblOverviewDefectName">Memuat...</span>
                        </div>
                    </div>

                    <!-- Top 3 Defect Pills Menjejer (Mode Halaman Web) -->
                    <div id="overviewTop3PillsContainer" style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;border-left:1.5px solid #e2e8f0;padding-left:10px;">
                        <!-- Dinamis via renderTop3SummaryPills -->
                    </div>
                </div>

                <!-- Executive Styled Defect Custom Dropdown with Colored Rank Badges -->
                <div style="display:flex;align-items:center;gap:8px;position:relative;">
                    <span style="font-size:11px;font-weight:800;color:#475569;">Peringkat &amp; Daftar Defect:</span>
                    <div style="position:relative;" id="overviewDefectDropdownContainer">
                        <button type="button" id="btnOverviewDefectDropdownToggle" onclick="toggleOverviewDefectMenu(event)"
                                style="display:inline-flex;align-items:center;justify-content:space-between;gap:12px;padding:6px 14px;border-radius:9px;font-size:12px;font-weight:800;min-width:320px;background:#f0f7ff;color:#0f172a;border:1.5px solid #3b82f6;outline:none;cursor:pointer;box-shadow:0 1px 3px rgba(37,99,235,0.15);transition:all 0.15s;">
                            <div id="overviewDropdownSelectedDisplay" style="display:inline-flex;align-items:center;gap:8px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                <span style="font-size:11px;color:#64748b;">Memuat daftar defect...</span>
                            </div>
                            <svg width="14" height="14" fill="none" stroke="#2563eb" stroke-width="2.5" viewBox="0 0 24 24" style="flex-shrink:0;">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>

                        <!-- Floating Custom Menu -->
                        <div id="overviewDefectMenuDropdown" class="thin-scrollbar"
                             style="display:none;position:absolute;right:0;top:calc(100% + 6px);z-index:100;background:#ffffff;border:1.5px solid #cbd5e1;border-radius:10px;box-shadow:0 12px 28px -4px rgba(15,23,42,0.18),0 4px 10px -2px rgba(15,23,42,0.08);width:360px;max-height:280px;overflow-y:auto;padding:5px;">
                            <!-- Items populated dynamically via renderOverviewRankPills -->
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── TIER 1: GRAFIK TREN KOMPARASI MULTI-HORIZON DEFECT (LEBAR PENUH 100%) ── -->
            <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:14px;box-shadow:0 1px 3px rgba(0,0,0,0.03);display:flex;flex-direction:column;gap:10px;width:100%;">
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                    <div>
                        <h3 style="margin:0;font-size:13px;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                            <svg width="15" height="15" fill="none" stroke="#2563eb" stroke-width="2.5" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                            </svg>
                            <span>Tren &amp; Komparasi Kejadian Defect</span>
                            <span class="overviewCardDefectBadge" style="display:inline-flex;align-items:center;padding:1.5px 7px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:5px;font-size:11px;font-weight:800;">-</span>
                            <span class="overviewCardUnitBadge" style="display:inline-flex;align-items:center;padding:1.5px 8px;background:#f8fafc;color:#1e293b;border:1.5px solid #cbd5e1;border-radius:6px;font-size:10.5px;font-weight:900;letter-spacing:0.3px;box-shadow:0 1px 2px rgba(0,0,0,0.04);">PCS</span>
                        </h3>
                        <p id="overviewTrendIntervalDesc" style="margin:1px 0 0 21px;font-size:10px;color:#64748b;">
                            Komparasi makro-ke-mikro: Tahunan (Tahun dengan Data) &rarr; Bulanan (Tahun Berjalan) &rarr; Harian Siklus Kerja (Bulan Berjalan).
                        </p>
                    </div>
                    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                        <button type="button" id="btnOverviewThreshold" onclick="openThresholdModal('overview')"
                                style="display:inline-flex;align-items:center;gap:4px;padding:3px 8px;font-size:10px;font-weight:800;border:1px solid #fecaca;border-radius:5px;cursor:pointer;background:#fef2f2;color:#b91c1c;transition:all 0.15s;"
                                title="Atur Batas Ambang / Target Maksimal untuk Tren Ini">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                            <span>Target: <strong id="lblOverviewThreshold" style="font-weight:900;">-</strong></span>
                        </button>
                        <!-- Selector Resolusi Periode Mikro: Mingguan vs Harian -->
                        <div style="display:flex;align-items:center;background:#f1f5f9;padding:2px;border-radius:6px;border:1px solid #e2e8f0;">
                            <button type="button" id="btnOverviewResWeekly" onclick="switchOverviewResolution('weekly')"
                                    style="padding:3px 9px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:#0f172a;color:#ffffff;box-shadow:0 1px 2px rgba(15,23,42,0.2);"
                                    title="Tampilkan komparasi Tahunan, Bulanan, dan Mingguan (W1-W4)">
                                Resolusi Mingguan
                            </button>
                            <button type="button" id="btnOverviewResDaily" onclick="switchOverviewResolution('daily')"
                                    style="padding:3px 9px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:transparent;color:#64748b;"
                                    title="Tampilkan komparasi Tahunan, Bulanan, dan Harian per tanggal siklus kerja">
                                Resolusi Harian
                            </button>
                        </div>
                        <!-- Switcher Garis vs Batang -->
                        <div style="display:flex;align-items:center;background:#f1f5f9;padding:2px;border-radius:6px;border:1px solid #e2e8f0;">
                            <button type="button" id="btnOverviewTrendBar" onclick="switchOverviewTrendType('bar')"
                                    style="padding:3px 8px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:#0f172a;color:#ffffff;box-shadow:0 1px 2px rgba(15,23,42,0.2);">
                                Batang
                            </button>
                            <button type="button" id="btnOverviewTrendLine" onclick="switchOverviewTrendType('line')"
                                    style="padding:3px 8px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:transparent;color:#64748b;">
                                Garis
                            </button>
                        </div>
                    </div>
                </div>



                <!-- Canvas Tren Full Width -->
                <div style="position:relative;height:240px;width:100%;">
                    <canvas id="chartOverviewTrend"></canvas>
                </div>
            </div>

            <!-- ── TIER 2: DUA SEBARAN BERDAMPINGAN (MODEL 50% & PART 50%) ── -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(420px, 1fr));gap:12px;">

                <!-- KIRI: SEBARAN DEFECT BY MODEL -->
                <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:14px;box-shadow:0 1px 3px rgba(0,0,0,0.03);display:flex;flex-direction:column;gap:10px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                        <div>
                            <h3 style="margin:0;font-size:13px;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                                <svg width="15" height="15" fill="none" stroke="#4f46e5" stroke-width="2.5" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                </svg>
                                <span>Sebaran Model Terdampak</span>
                                <span class="overviewCardDefectBadge" style="display:inline-flex;align-items:center;padding:1.5px 7px;background:#eef2ff;color:#4338ca;border:1px solid #c7d2fe;border-radius:5px;font-size:11px;font-weight:800;">-</span>
                                <span id="overviewCardModelUnitBadge" style="display:inline-flex;align-items:center;padding:1.5px 8px;background:#f8fafc;color:#4338ca;border:1.5px solid #cbd5e1;border-radius:6px;font-size:10px;font-weight:900;letter-spacing:0.3px;">PCS</span>
                            </h3>
                            <p style="margin:1px 0 0 21px;font-size:10px;color:#64748b;">
                                Komposisi tipe/model produk penyumbang cacat ini.
                            </p>
                        </div>
                        <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                            <!-- Segmented Toggle Satuan Model: PCS vs Frekuensi (x) -->
                            <div id="overviewModelMetricToggleGroup" style="display:inline-flex;align-items:center;background:#f1f5f9;padding:2px;border-radius:6px;border:1px solid #e2e8f0;">
                                <button type="button" id="btnModelMetricPcs" onclick="switchOverviewModelMetric('pcs')"
                                        style="padding:2.5px 8px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:#0f172a;color:#ffffff;box-shadow:0 1px 2px rgba(15,23,42,0.2);">
                                    PCS
                                </button>
                                <button type="button" id="btnModelMetricFreq" onclick="switchOverviewModelMetric('freq')"
                                        style="padding:2.5px 8px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:transparent;color:#64748b;"
                                        title="Frekuensi kali temuan defect pada pemeriksaan model">
                                    Freq (x)
                                </button>
                            </div>

                            <!-- Filter Rank Top Model: 3 | 5 | Semua (Hanya aktif saat Tren Garis / Tren Batang) -->
                            <div id="overviewModelRankGroup" style="display:none;align-items:center;background:#e2e8f0;padding:2px;border-radius:6px;gap:2px;">
                                <span style="font-size:9px;font-weight:800;color:#64748b;padding:0 4px;user-select:none;">Top:</span>
                                <button type="button" id="btnModelRank3" onclick="switchModelTrendRank(3)"
                                        style="padding:2.5px 7px;font-size:9.5px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:transparent;color:#64748b;">
                                    3
                                </button>
                                <button type="button" id="btnModelRank5" onclick="switchModelTrendRank(5)"
                                        style="padding:2.5px 7px;font-size:9.5px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:#0f172a;color:#ffffff;box-shadow:0 1px 2px rgba(15,23,42,0.2);">
                                    5
                                </button>
                                <button type="button" id="btnModelRankAll" onclick="switchModelTrendRank('all')"
                                        style="padding:2.5px 7px;font-size:9.5px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:transparent;color:#64748b;">
                                    Semua
                                </button>
                            </div>

                            <!-- Sub-Toggle Resolusi Tren Model (Week vs Harian) -->
                            <div id="overviewModelSubResGroup" style="display:none;align-items:center;background:#e2e8f0;padding:2px;border-radius:6px;gap:2px;">
                                <button type="button" id="btnModelResWeekly" onclick="switchModelTrendResolution('weekly')"
                                        style="padding:2.5px 7px;font-size:9.5px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:#0f172a;color:#ffffff;box-shadow:0 1px 2px rgba(15,23,42,0.2);">
                                    Week (W1-W4)
                                </button>
                                <button type="button" id="btnModelResDaily" onclick="switchModelTrendResolution('daily')"
                                        style="padding:2.5px 7px;font-size:9.5px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:transparent;color:#64748b;">
                                    Harian
                                </button>
                            </div>

                            <div style="display:flex;align-items:center;background:#f1f5f9;padding:2px;border-radius:6px;border:1px solid #e2e8f0;flex-wrap:wrap;gap:2px;">
                                <button type="button" id="btnOverviewModelDonut" onclick="switchOverviewModelChartType('table')"
                                        style="padding:3px 9px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:#0f172a;color:#ffffff;box-shadow:0 1px 2px rgba(15,23,42,0.2);">
                                    Tabel
                                </button>
                                <button type="button" id="btnOverviewModelTotal" onclick="switchOverviewModelChartType('bar_total')"
                                        style="padding:3px 9px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:transparent;color:#64748b;">
                                    Total
                                </button>
                                <button type="button" id="btnOverviewModelLine" onclick="switchOverviewModelChartType('line_trend')"
                                        style="padding:3px 9px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:transparent;color:#64748b;"
                                        title="Grafik tren garis rentang tanggal per model">
                                    Tren Garis
                                </button>
                                <button type="button" id="btnOverviewModelBar" onclick="switchOverviewModelChartType('bar_trend')"
                                        style="padding:3px 9px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:transparent;color:#64748b;"
                                        title="Grafik tren batang bertingkat rentang tanggal per model">
                                    Tren Batang
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Container Grafik Model (Table Layout OR Full Width Timeline/Bar) -->
                    <div id="overviewModelChartContainer" style="min-height:340px;display:flex;align-items:stretch;">
                        <!-- Mode 1: Table Layout -->
                        <div id="overviewModelDonutWrapper" style="display:flex;flex-direction:column;width:100%;max-height:340px;overflow-y:auto;" class="thin-scrollbar">
                            <table style="width:100%;border-collapse:collapse;font-size:11px;text-align:left;">
                                <thead style="position:sticky;top:0;background:#f8fafc;z-index:5;border-bottom:1.5px solid #e2e8f0;box-shadow:0 1px 2px rgba(0,0,0,0.02);">
                                    <tr style="color:#64748b;font-weight:800;text-transform:uppercase;font-size:9.5px;letter-spacing:0.4px;">
                                        <th style="padding:6px 8px;width:32px;text-align:center;">No</th>
                                        <th style="padding:6px 10px;">Model Produk</th>
                                        <th id="thOverviewModelVal" style="padding:6px 10px;text-align:right;width:110px;">Total Temuan</th>
                                        <th id="thOverviewModelTarget" style="padding:6px 10px;text-align:center;width:130px;">Batas Target</th>
                                    </tr>
                                </thead>
                                <tbody id="overviewModelTableBody">
                                    <tr>
                                        <td colspan="4" style="padding:20px;text-align:center;color:#94a3b8;font-size:11px;">Memuat sebaran model...</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Mode 2: Full Width Canvas untuk Total Bar / Tren Garis / Tren Batang -->
                        <div id="overviewModelTimelineWrapper" style="display:none;position:relative;height:340px;width:100%;flex-direction:column;">
                            <div style="flex:1;min-height:0;position:relative;width:100%;">
                                <canvas id="chartOverviewModels"></canvas>
                            </div>
                            <!-- Scrollable HTML Legend Container untuk Tren Model -->
                            <div id="chartOverviewModelsLegend" class="thin-scrollbar" style="display:none;max-height:32px;overflow-y:auto;flex-wrap:wrap;align-items:center;gap:4px;padding:3px 4px 0 4px;border-top:1px solid #f1f5f9;background:#ffffff;"></div>
                        </div>
                    </div>
                </div>

                <!-- KANAN: SEBARAN DEFECT BY PART -->
                <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:14px;box-shadow:0 1px 3px rgba(0,0,0,0.03);display:flex;flex-direction:column;gap:10px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                        <div>
                            <h3 style="margin:0;font-size:13px;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                                <svg width="15" height="15" fill="none" stroke="#059669" stroke-width="2.5" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z" />
                                </svg>
                                <span>Sebaran Part Terdampak</span>
                                <span class="overviewCardDefectBadge" style="display:inline-flex;align-items:center;padding:1.5px 7px;background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;border-radius:5px;font-size:11px;font-weight:800;">-</span>
                                <span id="overviewCardPartUnitBadge" style="display:inline-flex;align-items:center;padding:1.5px 8px;background:#f8fafc;color:#047857;border:1.5px solid #cbd5e1;border-radius:6px;font-size:10px;font-weight:900;letter-spacing:0.3px;">PCS</span>
                            </h3>
                            <p style="margin:1px 0 0 21px;font-size:10px;color:#64748b;">
                                Komposisi part code penyumbang cacat ini.
                            </p>
                        </div>
                        <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                            <!-- Segmented Toggle Satuan Part: PCS vs PPM (Independen dari filter atas) -->
                            <div id="overviewPartMetricToggleGroup" style="display:inline-flex;align-items:center;background:#f1f5f9;padding:2px;border-radius:6px;border:1px solid #e2e8f0;">
                                <button type="button" id="btnPartMetricPcs" onclick="switchOverviewPartMetric('pcs')"
                                        style="padding:2.5px 8px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:#0f172a;color:#ffffff;box-shadow:0 1px 2px rgba(15,23,42,0.2);">
                                    PCS
                                </button>
                                <button type="button" id="btnPartMetricPpm" onclick="switchOverviewPartMetric('ppm')"
                                        style="padding:2.5px 8px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:transparent;color:#64748b;"
                                        title="PPM spesifik dihitung dari Defect Pcs part dibagi Total Sampel part tersebut">
                                    PPM
                                </button>
                            </div>

                            <!-- Filter Rank Top Part: 3 | 5 | Semua (Hanya aktif saat Tren Garis / Tren Batang) -->
                            <div id="overviewPartRankGroup" style="display:none;align-items:center;background:#e2e8f0;padding:2px;border-radius:6px;gap:2px;">
                                <span style="font-size:9px;font-weight:800;color:#64748b;padding:0 4px;user-select:none;">Top:</span>
                                <button type="button" id="btnPartRank3" onclick="switchPartTrendRank(3)"
                                        style="padding:2.5px 7px;font-size:9.5px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:transparent;color:#64748b;">
                                    3
                                </button>
                                <button type="button" id="btnPartRank5" onclick="switchPartTrendRank(5)"
                                        style="padding:2.5px 7px;font-size:9.5px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:#0f172a;color:#ffffff;box-shadow:0 1px 2px rgba(15,23,42,0.2);">
                                    5
                                </button>
                                <button type="button" id="btnPartRankAll" onclick="switchPartTrendRank('all')"
                                        style="padding:2.5px 7px;font-size:9.5px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:transparent;color:#64748b;">
                                    Semua
                                </button>
                            </div>

                            <!-- Sub-Toggle Resolusi Tren Part (Week vs Harian) -->
                            <div id="overviewPartSubResGroup" style="display:none;align-items:center;background:#e2e8f0;padding:2px;border-radius:6px;gap:2px;">
                                <button type="button" id="btnPartResWeekly" onclick="switchPartTrendResolution('weekly')"
                                        style="padding:2.5px 7px;font-size:9.5px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:#0f172a;color:#ffffff;box-shadow:0 1px 2px rgba(15,23,42,0.2);">
                                    Week (W1-W4)
                                </button>
                                <button type="button" id="btnPartResDaily" onclick="switchPartTrendResolution('daily')"
                                        style="padding:2.5px 7px;font-size:9.5px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:transparent;color:#64748b;">
                                    Harian
                                </button>
                            </div>

                            <div style="display:flex;align-items:center;background:#f1f5f9;padding:2px;border-radius:6px;border:1px solid #e2e8f0;flex-wrap:wrap;gap:2px;">
                                <button type="button" id="btnOverviewPartDonut" onclick="switchOverviewPartChartType('table')"
                                        style="padding:3px 9px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:#0f172a;color:#ffffff;box-shadow:0 1px 2px rgba(15,23,42,0.2);">
                                    Tabel
                                </button>
                                <button type="button" id="btnOverviewPartTotal" onclick="switchOverviewPartChartType('bar_total')"
                                        style="padding:3px 9px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:transparent;color:#64748b;">
                                    Total
                                </button>
                                <button type="button" id="btnOverviewPartLine" onclick="switchOverviewPartChartType('line_trend')"
                                        style="padding:3px 9px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:transparent;color:#64748b;"
                                        title="Grafik tren garis rentang tanggal per part code">
                                    Tren Garis
                                </button>
                                <button type="button" id="btnOverviewPartBar" onclick="switchOverviewPartChartType('bar_trend')"
                                        style="padding:3px 9px;font-size:10px;font-weight:800;border:none;border-radius:4px;cursor:pointer;background:transparent;color:#64748b;"
                                        title="Grafik tren batang bertingkat rentang tanggal per part code">
                                    Tren Batang
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Container Grafik Part (Table Layout OR Full Width Timeline/Bar) -->
                    <div id="overviewPartChartContainer" style="min-height:340px;display:flex;align-items:stretch;">
                        <!-- Mode 1: Table Layout -->
                        <div id="overviewPartDonutWrapper" style="display:flex;flex-direction:column;width:100%;max-height:340px;overflow-y:auto;" class="thin-scrollbar">
                            <table style="width:100%;border-collapse:collapse;font-size:11px;text-align:left;">
                                <thead style="position:sticky;top:0;background:#f8fafc;z-index:5;border-bottom:1.5px solid #e2e8f0;box-shadow:0 1px 2px rgba(0,0,0,0.02);">
                                    <tr style="color:#64748b;font-weight:800;text-transform:uppercase;font-size:9.5px;letter-spacing:0.4px;">
                                        <th style="padding:6px 8px;width:32px;text-align:center;">No</th>
                                        <th style="padding:6px 10px;">Part Code &amp; Part Name</th>
                                        <th id="thOverviewPartVal" style="padding:6px 10px;text-align:right;width:110px;">Total Temuan</th>
                                        <th id="thOverviewPartTarget" style="padding:6px 10px;text-align:center;width:130px;">Batas Target</th>
                                    </tr>
                                </thead>
                                <tbody id="overviewPartTableBody">
                                    <tr>
                                        <td colspan="4" style="padding:20px;text-align:center;color:#94a3b8;font-size:11px;">Memuat sebaran part...</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Mode 2: Full Width Canvas untuk Total Bar / Tren Garis / Tren Batang -->
                        <div id="overviewPartTimelineWrapper" style="display:none;position:relative;height:340px;width:100%;flex-direction:column;">
                            <div style="flex:1;min-height:0;position:relative;width:100%;">
                                <canvas id="chartOverviewParts"></canvas>
                            </div>
                            <!-- Scrollable HTML Legend Container untuk Tren Part -->
                            <div id="chartOverviewPartsLegend" class="thin-scrollbar" style="display:none;max-height:32px;overflow-y:auto;flex-wrap:wrap;align-items:center;gap:4px;padding:3px 4px 0 4px;border-top:1px solid #f1f5f9;background:#ffffff;"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ROW 3: TABEL RIWAYAT TEMUAN INSPEKSI TERPERINCI (FIT-TO-SCREEN INTERNAL SCROLL) -->
            <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,0.03);overflow:hidden;display:flex;flex-direction:column;">
                <div style="padding:10px 16px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;background:#f8fafc;">
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <svg width="16" height="16" fill="none" stroke="#2563eb" stroke-width="2.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                        </svg>
                        <h3 style="margin:0;font-size:13px;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                            <span>Riwayat Temuan Cacat Inspeksi Terperinci</span>
                            <span class="overviewCardDefectBadge" style="display:inline-flex;align-items:center;padding:1.5px 7px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:5px;font-size:11px;font-weight:800;">-</span>
                        </h3>
                        <span id="overviewTableCountBadge" style="padding:2px 7px;border-radius:5px;font-size:10px;font-weight:800;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;">
                            0 Temuan
                        </span>
                    </div>

                    <div style="display:flex;align-items:center;gap:8px;">
                        <div style="position:relative;">
                            <input type="text" id="overviewTableSearchInput" placeholder="Cari part / model / customer / lot..."
                                   style="padding:4px 8px 4px 26px;font-size:11px;border-radius:6px;border:1px solid #cbd5e1;outline:none;width:220px;background:#ffffff;">
                            <svg width="12" height="12" fill="none" stroke="#94a3b8" stroke-width="2" viewBox="0 0 24 24"
                                 style="position:absolute;left:8px;top:50%;transform:translateY(-50%);pointer-events:none;">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                        </div>
                    </div>
                </div>

                <!-- Indikator Filter Excel Aktif Bar -->
                <div id="overview-excel-filter-active-bar" style="display: none; background: #eff6ff; border-bottom: 1.5px solid #bfdbfe; padding: 7px 16px; align-items: center; justify-content: space-between; font-size: 11px;">
                    <div style="display: flex; align-items: center; gap: 6px; color: #1e40af; font-weight: 700;">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                        <span id="overview-excel-filter-summary-text">Menyaring data tabel...</span>
                    </div>
                    <button type="button" onclick="resetAllOverviewExcelFilters()" style="background: #ffffff; border: 1px solid #bfdbfe; color: #1d4ed8; font-weight: 800; font-size: 10.5px; padding: 2.5px 9px; border-radius: 5px; cursor: pointer; transition: all 0.15s;" onmouseover="this.style.background='#dbeafe'" onmouseout="this.style.background='#ffffff'">
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
                                        <button type="button" class="th-filter-btn" data-col="date" title="Filter Tanggal" onclick="toggleOverviewExcelFilter(event, 'date')">
                                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                        </button>
                                    </div>
                                    <div id="overview-filter-dropdown-date" class="excel-filter-dropdown align-left">
                                        <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                            <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER TANGGAL</span>
                                            <button type="button" class="excel-reset-btn" onclick="resetSingleOverviewColFilter('date')" title="Reset filter">
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                                <span>Reset</span>
                                            </button>
                                        </div>
                                        <input type="text" class="excel-filter-search" placeholder="Cari tanggal..." oninput="onSearchOverviewFilterOptions('date', this.value)">
                                        <div class="flex items-center gap-1.5 mb-2">
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllOverviewFilter('date', true)">
                                                <svg width="11" height="11" class="text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                                <span>Pilih Semua</span>
                                            </button>
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllOverviewFilter('date', false)">
                                                <svg width="11" height="11" class="text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                <span>Hapus Pilihan</span>
                                            </button>
                                        </div>
                                        <div class="excel-filter-list" id="overview-filter-list-date"></div>
                                        <div class="excel-filter-actions">
                                            <span class="text-[10px] text-slate-500 font-bold filter-count-info"></span>
                                            <div class="flex items-center gap-2">
                                                <button type="button" class="excel-btn-close" onclick="closeAllOverviewExcelFilters()">Tutup</button>
                                                <button type="button" class="excel-btn-apply" onclick="applyOverviewExcelFilters()">
                                                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                                    <span>Terapkan</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </th>

                                <!-- Part Code & Part Name -->
                                <th style="padding:8px 12px;min-width:180px;position:relative;">
                                    <div class="th-filter-wrapper">
                                        <span>Part Code &amp; Part Name</span>
                                        <button type="button" class="th-filter-btn" data-col="part" title="Filter Part" onclick="toggleOverviewExcelFilter(event, 'part')">
                                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                        </button>
                                    </div>
                                    <div id="overview-filter-dropdown-part" class="excel-filter-dropdown align-left" style="width:270px;">
                                        <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                            <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER PART</span>
                                            <button type="button" class="excel-reset-btn" onclick="resetSingleOverviewColFilter('part')" title="Reset filter">
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                                <span>Reset</span>
                                            </button>
                                        </div>
                                        <input type="text" class="excel-filter-search" placeholder="Cari part..." oninput="onSearchOverviewFilterOptions('part', this.value)">
                                        <div class="flex items-center gap-1.5 mb-2">
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllOverviewFilter('part', true)">
                                                <svg width="11" height="11" class="text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                                <span>Pilih Semua</span>
                                            </button>
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllOverviewFilter('part', false)">
                                                <svg width="11" height="11" class="text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                <span>Hapus Pilihan</span>
                                            </button>
                                        </div>
                                        <div class="excel-filter-list" id="overview-filter-list-part"></div>
                                        <div class="excel-filter-actions">
                                            <span class="text-[10px] text-slate-500 font-bold filter-count-info"></span>
                                            <div class="flex items-center gap-2">
                                                <button type="button" class="excel-btn-close" onclick="closeAllOverviewExcelFilters()">Tutup</button>
                                                <button type="button" class="excel-btn-apply" onclick="applyOverviewExcelFilters()">
                                                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                                    <span>Terapkan</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </th>

                                <!-- Model Produk -->
                                <th style="padding:8px 12px;min-width:110px;position:relative;">
                                    <div class="th-filter-wrapper">
                                        <span>Model Produk</span>
                                        <button type="button" class="th-filter-btn" data-col="model" title="Filter Model" onclick="toggleOverviewExcelFilter(event, 'model')">
                                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                        </button>
                                    </div>
                                    <div id="overview-filter-dropdown-model" class="excel-filter-dropdown align-left">
                                        <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                            <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER MODEL</span>
                                            <button type="button" class="excel-reset-btn" onclick="resetSingleOverviewColFilter('model')" title="Reset filter">
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                                <span>Reset</span>
                                            </button>
                                        </div>
                                        <input type="text" class="excel-filter-search" placeholder="Cari model..." oninput="onSearchOverviewFilterOptions('model', this.value)">
                                        <div class="flex items-center gap-1.5 mb-2">
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllOverviewFilter('model', true)">
                                                <svg width="11" height="11" class="text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                                <span>Pilih Semua</span>
                                            </button>
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllOverviewFilter('model', false)">
                                                <svg width="11" height="11" class="text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                <span>Hapus Pilihan</span>
                                            </button>
                                        </div>
                                        <div class="excel-filter-list" id="overview-filter-list-model"></div>
                                        <div class="excel-filter-actions">
                                            <span class="text-[10px] text-slate-500 font-bold filter-count-info"></span>
                                            <div class="flex items-center gap-2">
                                                <button type="button" class="excel-btn-close" onclick="closeAllOverviewExcelFilters()">Tutup</button>
                                                <button type="button" class="excel-btn-apply" onclick="applyOverviewExcelFilters()">
                                                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                                    <span>Terapkan</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </th>

                                <!-- Model Produk -->

                                <!-- Inspeksi -->
                                <th style="padding:8px 12px;text-align:center;width:95px;position:relative;">
                                    <div class="th-filter-wrapper">
                                        <span>Inspeksi</span>
                                        <button type="button" class="th-filter-btn" data-col="type" title="Filter Jenis Inspeksi" onclick="toggleOverviewExcelFilter(event, 'type')">
                                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                        </button>
                                    </div>
                                    <div id="overview-filter-dropdown-type" class="excel-filter-dropdown align-left">
                                        <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                            <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER INSPEKSI</span>
                                            <button type="button" class="excel-reset-btn" onclick="resetSingleOverviewColFilter('type')" title="Reset filter">
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                                <span>Reset</span>
                                            </button>
                                        </div>
                                        <div class="flex items-center gap-1.5 mb-2">
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllOverviewFilter('type', true)">
                                                <svg width="11" height="11" class="text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                                <span>Pilih Semua</span>
                                            </button>
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllOverviewFilter('type', false)">
                                                <svg width="11" height="11" class="text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                <span>Hapus Pilihan</span>
                                            </button>
                                        </div>
                                        <div class="excel-filter-list" id="overview-filter-list-type"></div>
                                        <div class="excel-filter-actions">
                                            <span class="text-[10px] text-slate-500 font-bold filter-count-info"></span>
                                            <div class="flex items-center gap-2">
                                                <button type="button" class="excel-btn-close" onclick="closeAllOverviewExcelFilters()">Tutup</button>
                                                <button type="button" class="excel-btn-apply" onclick="applyOverviewExcelFilters()">
                                                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                                    <span>Terapkan</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </th>

                                <!-- No Kanban / Customer -->
                                <th style="padding:8px 12px;min-width:140px;position:relative;">
                                    <div class="th-filter-wrapper">
                                        <span>No Kanban / Customer</span>
                                        <button type="button" class="th-filter-btn" data-col="customer" title="Filter Customer" onclick="toggleOverviewExcelFilter(event, 'customer')">
                                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                        </button>
                                    </div>
                                    <div id="overview-filter-dropdown-customer" class="excel-filter-dropdown align-right" style="width:260px;">
                                        <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                            <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER CUSTOMER</span>
                                            <button type="button" class="excel-reset-btn" onclick="resetSingleOverviewColFilter('customer')" title="Reset filter">
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                                <span>Reset</span>
                                            </button>
                                        </div>
                                        <input type="text" class="excel-filter-search" placeholder="Cari customer..." oninput="onSearchOverviewFilterOptions('customer', this.value)">
                                        <div class="flex items-center gap-1.5 mb-2">
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllOverviewFilter('customer', true)">
                                                <svg width="11" height="11" class="text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                                <span>Pilih Semua</span>
                                            </button>
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllOverviewFilter('customer', false)">
                                                <svg width="11" height="11" class="text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                <span>Hapus Pilihan</span>
                                            </button>
                                        </div>
                                        <div class="excel-filter-list" id="overview-filter-list-customer"></div>
                                        <div class="excel-filter-actions">
                                            <span class="text-[10px] text-slate-500 font-bold filter-count-info"></span>
                                            <div class="flex items-center gap-2">
                                                <button type="button" class="excel-btn-close" onclick="closeAllOverviewExcelFilters()">Tutup</button>
                                                <button type="button" class="excel-btn-apply" onclick="applyOverviewExcelFilters()">
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
                                        <button type="button" class="th-filter-btn" data-col="inspector" title="Filter Inspector" onclick="toggleOverviewExcelFilter(event, 'inspector')">
                                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                        </button>
                                    </div>
                                    <div id="overview-filter-dropdown-inspector" class="excel-filter-dropdown align-right">
                                        <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                            <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER INSPECTOR</span>
                                            <button type="button" class="excel-reset-btn" onclick="resetSingleOverviewColFilter('inspector')" title="Reset filter">
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                                <span>Reset</span>
                                            </button>
                                        </div>
                                        <input type="text" class="excel-filter-search" placeholder="Cari inspector..." oninput="onSearchOverviewFilterOptions('inspector', this.value)">
                                        <div class="flex items-center gap-1.5 mb-2">
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllOverviewFilter('inspector', true)">
                                                <svg width="11" height="11" class="text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                                <span>Pilih Semua</span>
                                            </button>
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllOverviewFilter('inspector', false)">
                                                <svg width="11" height="11" class="text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                <span>Hapus Pilihan</span>
                                            </button>
                                        </div>
                                        <div class="excel-filter-list" id="overview-filter-list-inspector"></div>
                                        <div class="excel-filter-actions">
                                            <span class="text-[10px] text-slate-500 font-bold filter-count-info"></span>
                                            <div class="flex items-center gap-2">
                                                <button type="button" class="excel-btn-close" onclick="closeAllOverviewExcelFilters()">Tutup</button>
                                                <button type="button" class="excel-btn-apply" onclick="applyOverviewExcelFilters()">
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
                        <tbody id="overviewHistoryTableBody">
                            <tr>
                                <td colspan="10" style="padding:24px;text-align:center;color:#94a3b8;font-weight:600;">
                                    Memuat catatan riwayat temuan inspeksi...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div style="padding:8px 16px;background:#f8fafc;border-top:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;font-size:11px;color:#64748b;">
                    <span id="overviewTableFooterNote">
                        Data riwayat temuan inspeksi terperinci untuk defect terpilih.
                    </span>
                    <span style="color:#94a3b8;font-size:10px;">
                        Urutan inspeksi terbaru di atas.
                    </span>
                </div>
            </div>
        </div>

