<!-- Scan & Validation Dead-Center Modal Overlay (2-Step Wizard: Full Screen Workbench Edition) -->
<div id="scan-modal-overlay" style="position: fixed; inset: 0; width: 100vw; height: 100vh; background-color: #0f172a; z-index: 999999; display: flex; flex-direction: column; padding: 0; margin: 0; overflow: hidden;" class="<?= $showScanOverlay ? '' : 'hidden' ?>">
    
    <div style="background-color: #f8fafc; width: 100vw; height: 100vh; display: flex; flex-direction: column; overflow: hidden;" class="animate-fadeIn">
        
        <!-- Header Bar Full-Screen (Option B: Minimalist Modern Enterprise Workbench) -->
        <div style="background-color: #ffffff; border-bottom: 1.5px solid #e2e8f0; padding: 10px 22px; display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-shrink: 0; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
            <!-- Title & Dynamic Step Context -->
            <div class="flex items-center gap-3">
                <div style="width: 32px; height: 32px; border-radius: 8px; background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); color: #ffffff; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.15);" class="flex-shrink-0">
                    <svg class="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                    </svg>
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <h2 class="text-sm font-black text-slate-900 leading-tight tracking-tight">OQC Inspection Workbench</h2>
                        <span id="wizard-step-tag" style="background-color: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; font-size: 10px; font-weight: 800; padding: 1.5px 7px; border-radius: 9999px;">Langkah 1 dari 2</span>
                    </div>
                    <p id="wizard-modal-subtitle" class="text-[11.5px] text-slate-500 font-medium mt-0.5">Pilih Kanban atau Safety Stock yang akan diinspeksi</p>
                </div>
            </div>

            <!-- Compact Minimalist Stepper Navigation -->
            <div class="flex items-center gap-1.5 bg-slate-50 border border-slate-200 rounded-lg p-1">
                <button type="button" id="step-pill-1" onclick="backToStep1()" 
                        style="padding: 4px 11px; border-radius: 6px; font-size: 11px; font-weight: 800; cursor: pointer; transition: all 0.15s ease; border: none; outline: none; background-color: #2563eb; color: #ffffff;" 
                        class="inline-flex items-center gap-1.5 shadow-xs" title="Langkah 1: Pilih Planning">
                    <span style="width: 15px; height: 15px; border-radius: 9999px; background-color: rgba(255,255,255,0.25); display: inline-flex; align-items: center; justify-content: center; font-size: 9.5px; font-weight: 900;">1</span>
                    <span>Pilih Planning</span>
                </button>
                <svg class="w-3.5 h-3.5 text-slate-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/>
                </svg>
                <button type="button" id="step-pill-2" onclick="goToStep2IfSelected()" 
                        style="padding: 4px 11px; border-radius: 6px; font-size: 11px; font-weight: 700; cursor: pointer; transition: all 0.15s ease; border: none; outline: none; background-color: transparent; color: #64748b;" 
                        class="inline-flex items-center gap-1.5 hover:text-slate-900" title="Langkah 2: Scan Barcode Label">
                    <span style="width: 15px; height: 15px; border-radius: 9999px; background-color: #e2e8f0; display: inline-flex; align-items: center; justify-content: center; font-size: 9.5px; font-weight: 800; color: #475569;">2</span>
                    <span>Scan Barcode</span>
                </button>
            </div>

            <!-- Action Buttons Kanan Header -->
            <div style="display: flex; align-items: center; gap: 8px;">
                <!-- Tombol Solid: Ganti Item (Hanya muncul saat berada di Step 2 Scan Barcode) -->
                <button type="button" id="btn-change-item-header" onclick="backToStep1()" 
                        style="display: none; background: linear-gradient(135deg, #1e293b 0%, #334155 100%); color: #ffffff; border: 1.5px solid #0f172a; padding: 6px 14px; border-radius: 8px; font-size: 11.5px; font-weight: 800; cursor: pointer; align-items: center; gap: 6px; box-shadow: 0 2px 5px rgba(15, 23, 42, 0.25); transition: all 0.15s ease;"
                        onmouseover="this.style.filter='brightness(1.15)'; this.style.transform='translateY(-1px)';"
                        onmouseout="this.style.filter='none'; this.style.transform='translateY(0)';"
                        title="Kembali ke Langkah 1 untuk memilih Kanban / Item lain">
                    <svg style="width: 14px; height: 14px; color: #93c5fd; flex-shrink: 0;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                    </svg>
                    <span>Ganti Item</span>
                </button>

                <!-- Tombol Hard Refresh Modal (Bypass Cache & Tarik Data Terbaru DB) -->
                <button type="button" id="btn-modal-force-refresh" onclick="forceRefreshModalPlanning()" 
                        style="background-color: #f1f5f9; border: 1.5px solid #cbd5e1; color: #334155; font-size: 11.5px; font-weight: 800; padding: 6px 12px; border-radius: 8px; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); transition: all 0.15s ease;"
                        onmouseover="this.style.backgroundColor='#e2e8f0'; this.style.borderColor='#94a3b8';"
                        onmouseout="this.style.backgroundColor='#f1f5f9'; this.style.borderColor='#cbd5e1';"
                        title="Tarik ulang data terbaru langsung dari database (bersihkan cache)">
                    <svg id="modal-refresh-spinner-icon" style="width: 14px; height: 14px; color: #475569; transition: transform 0.2s;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                    <span id="modal-refresh-btn-text">Refresh</span>
                </button>

                <!-- Tombol Ungu Eye-Catching: Safety Stock -->
                <button type="button" onclick="toggleSafetyStockTab()" 
                        style="background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%); color: #ffffff; border: 1.5px solid #5b21b6; padding: 6px 14px; border-radius: 8px; font-size: 11.5px; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 2px 6px rgba(109, 40, 217, 0.35); transition: all 0.15s ease;"
                        onmouseover="this.style.filter='brightness(1.1)'; this.style.transform='translateY(-1px)';"
                        onmouseout="this.style.filter='none'; this.style.transform='translateY(0)';"
                        title="Klik untuk inspeksi cek fisik stok gudang (Direct Safety Stock)">
                    <svg style="width: 14px; height: 14px; color: #ede9fe; flex-shrink: 0;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                    </svg>
                    <span>+ Safety Stock</span>
                </button>

                <!-- Tombol Tutup / Kembali -->
                <button type="button" onclick="closeScanModal(false)" 
                        style="background-color: #fef2f2; border: 1.5px solid #fecaca; color: #dc2626; font-size: 12px; font-weight: 800; padding: 6px 14px; border-radius: 8px; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; transition: all 0.15s ease;" 
                        onmouseover="this.style.backgroundColor='#fee2e2'; this.style.borderColor='#fca5a5';" 
                        onmouseout="this.style.backgroundColor='#fef2f2'; this.style.borderColor='#fecaca';" 
                        title="Tutup Modal">
                    <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                    <span>Tutup</span>
                </button>
            </div>
        </div>

        <datalist id="modal-parts-list">
            <?php foreach ($master_parts as $mp): ?>
                <option value="<?= htmlspecialchars($mp['part_code']) ?>"><?= htmlspecialchars($mp['part_code']) ?> - <?= htmlspecialchars($mp['part_name']) ?></option>
            <?php endforeach; ?>
        </datalist>

        <!-- ── STEP 1: PILIH PLANNING ITEM (FULL-SCREEN TABLE ALIGNMENT WITH INDEX.PHP) ── -->
        <div id="wizard-step-1-content" style="display: flex; flex-direction: column; min-height: 0; flex: 1; padding: 12px 20px; overflow: hidden;" class="space-y-2.5">
            
            <!-- ── TOP KPI SUMMARY & PERSONAL WORK TRACKER (STRICT 1 ROW WITH SIGNATURE BACKGROUNDS) ── -->
            <div class="flex items-center gap-2 flex-shrink-0 w-full overflow-x-auto pb-0.5 custom-scrollbar select-none">
                
                <!-- Stat 1: Total Kanban (Khas: Blue Gradient) -->
                <div id="kpi-btn-all" onclick="filterModalByStatus('all')" 
                     class="kpi-interactive-card rounded-lg p-2 shadow-2xs flex-1 min-w-[110px]"
                     style="background: linear-gradient(135deg, #e0f2fe 0%, #dbeafe 100%); border: 1px solid #93c5fd; border-left: 4.5px solid #2563eb;" 
                     title="Klik untuk tampilkan semua Kanban">
                    <div class="flex items-center justify-between gap-1">
                        <span class="text-[8.5px] font-black text-blue-900 uppercase tracking-wider truncate">TOTAL KANBAN</span>
                        <span class="w-4 h-4 rounded bg-blue-200/80 text-blue-700 flex items-center justify-center flex-shrink-0">
                            <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                        </span>
                    </div>
                    <div class="mt-0.5 flex items-baseline gap-1">
                        <span id="kpi-val-total" class="text-sm font-black text-blue-950 font-mono">0</span>
                        <span class="text-[9.5px] font-bold text-blue-700">Kanban</span>
                    </div>
                </div>

                <!-- Stat 2: Belum Selesai (Khas: Slate Gradient) -->
                <div id="kpi-btn-uncompleted" onclick="filterModalByStatus('uncompleted')" 
                     class="kpi-interactive-card rounded-lg p-2 shadow-2xs flex-1 min-w-[110px]"
                     style="background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%); border: 1px solid #cbd5e1; border-left: 4.5px solid #64748b;" 
                     title="Klik untuk filter Belum Selesai (Total Belum Mulai + Parsial)">
                    <div class="flex items-center justify-between gap-1">
                        <span class="text-[8.5px] font-black text-slate-700 uppercase tracking-wider truncate">BELUM SELESAI</span>
                        <span class="w-4 h-4 rounded bg-slate-200/90 text-slate-600 flex items-center justify-center flex-shrink-0">
                            <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </span>
                    </div>
                    <div class="mt-0.5 flex items-baseline gap-1">
                        <span id="kpi-val-uncompleted" class="text-sm font-black text-slate-800 font-mono">0</span>
                        <span class="text-[9.5px] font-bold text-slate-500">Kanban</span>
                    </div>
                    <div id="kpi-sub-uncompleted" class="text-[8px] text-slate-500 font-semibold mt-0.5 leading-tight truncate">
                        0 Belum Mulai • 0 Parsial
                    </div>
                </div>

                <!-- Stat 3: Sedang Dikerjakan (Khas: Amber Gradient) -->
                <div id="kpi-btn-active" onclick="filterModalByStatus('active')" 
                     class="kpi-interactive-card rounded-lg p-2 shadow-2xs flex-1 min-w-[115px]"
                     style="background: linear-gradient(135deg, #fef3c7 0%, #ffedd5 100%); border: 1px solid #fcd34d; border-left: 4.5px solid #f59e0b;" 
                     title="Klik untuk filter Kanban yang sedang aktif dikerjakan oleh operator">
                    <div class="flex items-center justify-between gap-1">
                        <span class="text-[8.5px] font-black text-amber-900 uppercase tracking-wider truncate">SEDANG DIKERJAKAN</span>
                        <span class="w-4 h-4 rounded bg-amber-200/80 text-amber-800 flex items-center justify-center flex-shrink-0 relative">
                            <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-ping absolute"></span>
                            <span class="w-1.5 h-1.5 rounded-full bg-amber-600"></span>
                        </span>
                    </div>
                    <div class="mt-0.5 flex items-baseline gap-1">
                        <span id="kpi-val-active" class="text-sm font-black text-amber-950 font-mono">0</span>
                        <span class="text-[9.5px] font-bold text-amber-700">Kanban</span>
                    </div>
                </div>

                <!-- Stat 4: Selesai (Khas: Emerald Gradient) -->
                <div id="kpi-btn-completed" onclick="filterModalByStatus('completed')" 
                     class="kpi-interactive-card rounded-lg p-2 shadow-2xs flex-1 min-w-[100px]"
                     style="background: linear-gradient(135deg, #dcfce7 0%, #d1fae5 100%); border: 1px solid #86efac; border-left: 4.5px solid #10b981;" 
                     title="Klik untuk filter Kanban Selesai">
                    <div class="flex items-center justify-between gap-1">
                        <span class="text-[8.5px] font-black text-emerald-900 uppercase tracking-wider truncate">SELESAI</span>
                        <span class="w-4 h-4 rounded bg-emerald-200/80 text-emerald-800 flex items-center justify-center flex-shrink-0">
                            <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                        </span>
                    </div>
                    <div class="mt-0.5 flex items-baseline gap-1">
                        <span id="kpi-val-completed" class="text-sm font-black text-emerald-950 font-mono">0</span>
                        <span class="text-[9.5px] font-bold text-emerald-700">Kanban</span>
                    </div>
                </div>

                <!-- Stat 5: Remain (Tunggakan / Sisa Kemarin) (Khas: Indigo Gradient) -->
                <div id="kpi-btn-remain" onclick="filterModalByStatus('remain')" 
                     class="kpi-interactive-card rounded-lg p-2 shadow-2xs flex-1 min-w-[105px]"
                     style="background: linear-gradient(135deg, #ede9fe 0%, #e0e7ff 100%); border: 1px solid #c7d2fe; border-left: 4.5px solid #6366f1;" 
                     title="Klik untuk melihat Kanban jadwal kemarin yang belum selesai / parsial">
                    <div class="flex items-center justify-between gap-1">
                        <span class="text-[8.5px] font-black text-indigo-900 uppercase tracking-wider truncate">REMAIN</span>
                        <span class="w-4 h-4 rounded bg-indigo-200/80 text-indigo-800 flex items-center justify-center flex-shrink-0">
                            <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </span>
                    </div>
                    <div class="mt-0.5 flex items-baseline gap-1">
                        <span id="kpi-val-remain" class="text-sm font-black text-indigo-950 font-mono">0</span>
                        <span class="text-[9.5px] font-bold text-indigo-700">Kanban</span>
                    </div>
                </div>

                <!-- Stat 6: Lot NG / Tindakan (Khas: Rose Gradient) -->
                <div id="kpi-btn-rejected" onclick="filterModalByStatus('rejected')" 
                     class="kpi-interactive-card rounded-lg p-2 shadow-2xs flex-1 min-w-[115px]"
                     style="background: linear-gradient(135deg, #ffe4e6 0%, #fecdd3 100%); border: 1px solid #fda4af; border-left: 4.5px solid #e11d48;" 
                     title="Klik untuk filter Kanban dengan Lot NG / Tindakan">
                    <div class="flex items-center justify-between gap-1">
                        <span class="text-[8.5px] font-black text-rose-900 uppercase tracking-wider truncate">LOT NG / TINDAKAN</span>
                        <span class="w-4 h-4 rounded bg-rose-200/80 text-rose-700 flex items-center justify-center flex-shrink-0">
                            <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        </span>
                    </div>
                    <div class="mt-0.5 flex items-baseline gap-1">
                        <span id="kpi-val-rejected" class="text-sm font-black text-rose-950 font-mono">0</span>
                        <span class="text-[9.5px] font-bold text-rose-700">Kanban</span>
                    </div>
                </div>

                <!-- Work Tracker: Pencapaian Saya Hari Ini (Khas: Indigo-Purple Gradient) -->
                <div id="kpi-btn-my_kanban" onclick="filterModalByStatus('my_kanban')" 
                     class="kpi-interactive-card rounded-lg p-2 shadow-2xs flex-initial min-w-[340px]"
                     style="background: linear-gradient(135deg, #eef2ff 0%, #f5f3ff 100%); border: 1px solid #c7d2fe; border-left: 4.5px solid #6366f1;"
                     title="Klik untuk tampilkan hanya Kanban yang saya kerjakan hari ini">
                    <div class="flex items-center justify-between gap-1 mb-1">
                        <div class="flex items-center gap-1.5">
                            <span class="w-1.5 h-1.5 rounded-full bg-indigo-600 animate-pulse"></span>
                            <span class="text-[8.5px] font-black text-indigo-950 uppercase tracking-wider">PENCAPAIAN SAYA HARI INI</span>
                        </div>
                        <span class="text-[9px] font-black text-indigo-700 bg-indigo-100/90 border border-indigo-200 px-1.5 py-0.2 rounded truncate max-w-[120px]">
                            <?= htmlspecialchars($currentUser['name'] ?? 'Saya') ?>
                        </span>
                    </div>
                    <div class="bg-white/95 border border-indigo-100 rounded-md px-2.5 py-1 text-[11px] font-bold text-slate-700 shadow-3xs flex items-center justify-between gap-2 whitespace-nowrap">
                        <div class="flex items-center gap-1">
                            <span class="text-slate-600 font-semibold">Selesai:</span>
                            <span class="font-mono font-black text-emerald-700"><span id="user-stat-completed-kb">0</span> Kanban</span>
                        </div>
                        <span class="text-slate-300 font-normal">|</span>
                        <div class="flex items-center gap-1">
                            <span class="text-slate-600 font-semibold">Lot:</span>
                            <span class="font-mono font-black text-indigo-900"><span id="user-stat-total-lots">0</span> Box</span>
                            <span class="text-[10px] text-slate-500 font-medium ml-0.5">(<span id="user-stat-passed-lots" class="text-emerald-700 font-black">0</span> P, <span id="user-stat-ng-lots" class="text-rose-600 font-black">0</span> NG)</span>
                        </div>
                    </div>
                </div>

            </div>

            <!-- PITA GELOMBANG / BATCH PADA TANGGAL HARI INI + TUNGGAKAN KEMARIN -->
            <div style="background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 8px 14px; box-shadow: 0 1px 2px rgba(0,0,0,0.03);" class="flex-shrink-0">
                <div class="flex items-center justify-between gap-2 mb-1.5">
                    <div class="flex items-center gap-1.5">
                        <span class="w-2 h-2 rounded-full bg-blue-600 animate-pulse"></span>
                        <span class="text-xs font-extrabold text-slate-800 uppercase tracking-wider">
                            Gelombang / Batch (Hari Ini - <?= date('d M Y') ?>):
                        </span>
                    </div>
                    <span class="text-[11px] text-slate-500 font-medium hidden sm:inline">Pilih batch lembar fisik atau periksa tunggakan kemarin</span>
                </div>

                <!-- Container Tombol Tab Batch Dinamis -->
                <div id="modal-batch-pills-bar" class="flex items-center gap-2 overflow-x-auto pb-1 custom-scrollbar">
                    <!-- Default Spinner / Placeholder saat AJAX memuat batch -->
                    <span class="text-xs text-slate-400 font-medium py-1">Memuat daftar batch hari ini...</span>
                </div>
            </div>

            <!-- Direct Safety Stock Scanning Banner (Tampil HANYA jika tab Safety Stock aktif) -->
            <div id="safety-stock-direct-card" class="p-3 bg-gradient-to-r from-purple-50 to-indigo-50 border-2 border-purple-300 rounded-xl space-y-2 text-xs shadow-xs hidden flex-shrink-0">
                <div class="flex items-center space-x-2">
                    <span class="p-1.5 bg-purple-600 text-white rounded-lg flex items-center justify-center">
                        <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                    </span>
                    <div class="min-w-0 flex-1">
                        <h4 class="font-extrabold text-purple-900 text-xs">Cek Direct Safety Stock Barang Gudang</h4>
                        <p class="text-[10px] text-purple-700 font-medium">Inspeksi barang gudang tanpa planning list Excel. Langsung scan barcode QR (Z1..Z5) atau ketik form manual label barang.</p>
                    </div>
                    <button type="button" onclick="startDirectSafetyStockScan()" class="py-2 px-4 bg-purple-600 hover:bg-purple-700 text-white font-extrabold text-xs rounded-lg shadow-sm flex items-center space-x-1.5 transition-all flex-shrink-0">
                        <span>Mulai Scan Label Safety Stock &rarr;</span>
                    </button>
                </div>
            </div>

            <!-- SEARCH BAR + FILTER STATS & TOGGLE SELESAI -->
            <div class="flex items-center gap-3 flex-shrink-0">
                <div class="relative flex-1">
                    <input type="text" id="planning-search-input" autofocus autocomplete="off" onkeydown="handlePlanningSearchKeydown(event)" oninput="handlePlanningSearchInput()" placeholder="Cari Part Code, Item Description, Customer, atau No. Kanban... (Scan Barcode / Ketik)" class="form-input py-2 px-4 text-xs w-full font-semibold focus:ring-2 focus:ring-blue-500 focus:outline-none rounded-lg border border-slate-300 bg-white">
                </div>
                <!-- Tombol Toggle Tampilkan / Sembunyikan Kanban Selesai -->
                <button type="button" id="btn-toggle-completed-kanban" onclick="toggleCompletedKanbanVisibility()" class="px-3 py-2 text-xs font-bold rounded-lg border border-emerald-300 bg-emerald-50 text-emerald-800 hover:bg-emerald-100 transition-all flex items-center gap-1.5 flex-shrink-0 shadow-xs cursor-pointer" title="Tampilkan atau sembunyikan Kanban yang sudah 100% selesai diinspeksi">
                    <svg class="w-3.5 h-3.5 text-emerald-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                    <span id="btn-toggle-completed-label">Lihat Selesai (<span id="btn-toggle-completed-count">0</span>)</span>
                </button>
                <div id="planning-table-counter" class="text-xs font-bold text-slate-500 bg-slate-200/60 px-3 py-1.5 rounded-lg flex-shrink-0">
                    Total: <span id="planning-visible-count" class="text-blue-700 font-extrabold">0</span> Part
                </div>
            </div>

            <!-- TABEL KANBAN UTAMA (IDENTIK DENGAN INDEX.PHP) -->
            <div style="flex: 1; min-height: 0; background: #ffffff; border: 1.5px solid #cbd5e1; border-radius: 12px; overflow: hidden; display: flex; flex-direction: column;" class="shadow-xs">
                <div style="flex: 1; overflow-y: auto; overflow-x: auto;" class="custom-scrollbar">
                    <table class="w-full text-left text-xs text-slate-700 table-fixed" style="border-collapse: collapse; min-width: 900px;">
                        <colgroup>
                            <col style="width: 45px;">
                            <col style="width: 22%;">
                            <col style="width: 26%;">
                            <col style="width: 17%;">
                            <col style="width: 15%;">
                            <col style="width: 10%;">
                            <col style="width: 100px;">
                        </colgroup>
                        <thead class="bg-slate-100 text-slate-700 font-extrabold border-b border-slate-200 uppercase tracking-wider text-[10.5px] sticky top-0 z-10 select-none">
                            <tr>
                                <th class="text-center px-1.5 py-2.5">#</th>
                                <th class="px-3 py-2.5">Kanban</th>
                                <th class="px-3 py-2.5">Part</th>
                                <th class="px-2.5 py-2.5 whitespace-nowrap">Jadwal &amp; ETA</th>
                                <th class="px-2.5 py-2.5 text-center whitespace-nowrap">Progres</th>
                                <th class="px-2.5 py-2.5 text-center whitespace-nowrap">Status</th>
                                <th class="px-3 py-2.5 text-center whitespace-nowrap">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="planning-table-body" class="divide-y divide-slate-100">
                            <!-- Di-render dinamis oleh JavaScript renderPlanningTableRows() -->
                            <tr>
                                <td colspan="7" class="px-4 py-12 text-center text-slate-400">
                                    <div class="inline-block animate-spin rounded-full h-7 w-7 border-b-2 border-blue-600 mb-2"></div>
                                    <p class="font-bold text-slate-600 text-xs">Memuat data jadwal Kanban...</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Footer Status Bar -->
            <div style="display: flex; align-items: center; justify-content: space-between; padding-top: 6px;" class="flex-shrink-0 text-[11px] text-slate-500">
                <div class="flex items-center gap-2">
                    <span class="inline-flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-slate-400"></span> Abu-abu: Belum Mulai</span>
                    <span class="inline-flex items-center gap-1 ml-2"><span class="w-2 h-2 rounded-full bg-amber-500"></span> Kuning: Berjalan / Parsial</span>
                    <span class="inline-flex items-center gap-1 ml-2"><span class="w-2 h-2 rounded-full bg-emerald-600"></span> Hijau: Selesai</span>
                    <span class="inline-flex items-center gap-1 ml-2"><span class="w-2 h-2 rounded-full bg-rose-600"></span> Merah: Reject / Lot NG</span>
                </div>
                <div>
                    Tekan <b>Enter</b> pada kolom cari untuk auto-select baris pertama yang cocok
                </div>
            </div>
        </div>

        <!-- ── STEP 2: SCAN BARCODE LABEL (SPLIT-SCREEN WORKBENCH: ZERO-SCROLL EDITION) ── -->
        <div id="wizard-step-2-content" style="flex: 1; min-height: 0; padding: 12px 20px; overflow: hidden; display: flex; flex-direction: column;" class="hidden">
            
            <?php
            $initKanbanNo = ($session && !empty($session['kanban_no'])) ? $session['kanban_no'] : '-';
            $rawEta = ($session && !empty($session['kanban_eta'])) ? $session['kanban_eta'] : (($session && !empty($session['kanban_req_date'])) ? $session['kanban_req_date'] : '');
            $initEtaStr = !empty($rawEta) ? format_relative_eta($rawEta) : '-';
            ?>

            <!-- Main 2-Column Split-Screen Workspace (Fills 100% Remaining Height, Zero-Scroll) -->
            <div style="flex: 1; min-height: 0; display: flex; gap: 16px; overflow: hidden;">
                
                <!-- ◀ KOLOM KIRI: SCANNER OPERATIONS & PERSISTENT LIVE ALERT (~42% Width) -->
                <div style="flex: 0 0 42%; min-width: 320px; max-width: 480px; display: flex; flex-direction: column; gap: 10px; min-height: 0; overflow-y: auto;" class="pr-1">
                    
                    <!-- 1. Compact Part & Kanban Identity Card -->
                    <div style="background-color: #ffffff; border: 1.5px solid #cbd5e1; border-radius: 10px; padding: 10px 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.03); flex-shrink: 0;">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
                            <div style="display: flex; align-items: center; gap: 6px;">
                                <span style="background-color: #1e293b; color: #ffffff; font-size: 9.5px; font-weight: 800; padding: 2px 7px; border-radius: 4px; text-transform: uppercase;">
                                    PLANNING
                                </span>
                                <span id="selected-plan-tag" style="background-color: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; font-size: 9.5px; font-weight: 800; padding: 2px 6px; border-radius: 4px; text-transform: uppercase;">
                                    100% Cek
                                </span>
                            </div>
                        </div>

                        <!-- Big Part Code Monospace -->
                        <div style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); border-radius: 8px; padding: 6px 12px; margin-bottom: 6px;">
                            <span style="font-size: 9px; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; display: block;">KODE PART (Z1)</span>
                            <span id="selected-plan-part-code" style="font-family: ui-monospace, SFMono-Regular, monospace; font-size: 17px; font-weight: 900; color: #38bdf8; display: block; line-height: 1.2; word-break: break-all;">
                                <?= htmlspecialchars($session['part_code'] ?? 'PART-CODE') ?>
                            </span>
                            <span id="selected-plan-desc" style="font-size: 11.5px; font-weight: 700; color: #f1f5f9; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; margin-top: 1px;" title="<?= htmlspecialchars($session['display_part_name'] ?? $session['part_name'] ?? '') ?>">
                                <?= htmlspecialchars($session['display_part_name'] ?? $session['part_name'] ?? 'Description') ?>
                            </span>
                        </div>

                        <!-- 2 Sub-data Row: No Kanban & ETA -->
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: 10.5px;">
                            <div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 4px 8px;">
                                <span style="color: #64748b; font-size: 9px; font-weight: 700; display: block;">NO. KANBAN</span>
                                <span id="selected-plan-kanban-no" style="font-family: monospace; font-weight: 900; color: #0f172a; font-size: 11.5px;">#<?= htmlspecialchars($initKanbanNo) ?></span>
                            </div>
                            <div style="background-color: #fefce8; border: 1px solid #fef08a; border-radius: 6px; padding: 4px 8px;">
                                <span style="color: #854d0e; font-size: 9px; font-weight: 700; display: block;">JADWAL (ETA)</span>
                                <span id="selected-plan-eta" style="font-family: monospace; font-weight: 800; color: #a16207; font-size: 11px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block;"><?= htmlspecialchars($initEtaStr) ?></span>
                            </div>
                        </div>
                        <input type="hidden" id="selected-plan-cust" value="<?= htmlspecialchars($session['customer'] ?? '-') ?>">
                        <input type="hidden" id="selected-plan-qty" value="<?= number_format($session['kanban_qty'] ?? 0) ?> pcs">
                    </div>

                    <!-- 2. Fast Scanner Primary Input (Auto-Focus, Eye-Level) -->
                    <div style="background-color: #ffffff; border: 2px solid #3b82f6; border-radius: 10px; padding: 10px 14px; box-shadow: 0 2px 6px rgba(59, 130, 246, 0.12); flex-shrink: 0;">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
                            <label for="scan-qr-raw" style="font-size: 11.5px; font-weight: 900; color: #1e3a8a; display: inline-flex; align-items: center; gap: 5px;">
                                <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.3" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"></path></svg>
                                <span>SCAN BARCODE / QR LABEL</span>
                            </label>
                        </div>
                        <input type="text" id="scan-qr-raw" autofocus oninput="parseBarcodeQRInput(this.value, false)" onkeydown="handleScanInputKeydown(event)" placeholder="Tempel / Scan Barcode Label di sini..." style="width: 100%; padding: 8px 12px; font-family: monospace; font-size: 13px; font-weight: 800; border: 1.5px solid #93c5fd; border-radius: 7px; outline: none; background-color: #f8fafc; color: #0f172a; box-sizing: border-box;" class="focus:bg-white focus:border-blue-600" autocomplete="off">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 4px; font-size: 10px; color: #64748b;">
                            <span>Sistem membaca Ref No, Lot No, Qty otomatis</span>
                            <button type="button" id="btn-toggle-manual-form" onclick="toggleManualFormVisibility()" style="font-size: 10px; font-weight: 700; color: #475569; background-color: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 5px; padding: 2px 7px; cursor: pointer;" title="Klik jika scanner barcode rusak untuk input manual">
                                ⌨️ Input Manual
                            </button>
                        </div>
                    </div>

                    <!-- Collapsible Manual Input Form (Toggled via button if hardware scanner fails) -->
                    <div id="manual-scan-form-container" class="hidden" style="background-color: #f8fafc; border: 1px dashed #94a3b8; border-radius: 8px; padding: 8px 12px; flex-shrink: 0;">
                        <form id="modal-scan-form" onsubmit="event.preventDefault(); handleManualAddLabel();" class="space-y-2 text-xs">
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="block font-bold text-slate-700 text-[9.5px]">Part Code <span class="text-rose-500">*</span></label>
                                    <input type="text" id="scan-part-code" list="modal-parts-list" class="form-input py-1 px-2 text-xs font-mono font-bold uppercase w-full box-border" autocomplete="off">
                                </div>
                                <div>
                                    <label class="block font-bold text-slate-700 text-[9.5px]">Lot Number <span class="text-rose-500">*</span></label>
                                    <input type="text" id="scan-lot-number" value="" placeholder="Nomor Lot..." class="form-input py-1 px-2 text-xs font-mono font-bold uppercase w-full box-border" autocomplete="off">
                                </div>
                                <div>
                                    <label class="block font-bold text-slate-700 text-[9.5px]">Qty Box <span class="text-rose-500">*</span></label>
                                    <input type="number" id="scan-label-qty" min="1" placeholder="20" class="form-input py-1 px-2 text-xs font-mono font-bold w-full box-border" autocomplete="off">
                                </div>
                                <div>
                                    <label class="block font-bold text-blue-700 text-[9.5px]">Ref Number <span class="text-rose-500">*</span></label>
                                    <input type="text" id="scan-ref-number" placeholder="KSKA" class="form-input py-1 px-2 text-xs font-mono font-bold uppercase w-full box-border" autocomplete="off">
                                </div>
                            </div>
                            <input type="hidden" id="scan-remarks" value="">
                            <div class="flex justify-end pt-1">
                                <button type="button" onclick="handleManualAddLabel()" style="background-color: #2563eb; color: #ffffff; border: none; padding: 4px 12px; border-radius: 6px; font-size: 11px; font-weight: 800; cursor: pointer;">
                                    + Tambah Box Manual
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- 3. 🎯 DEDICATED IN-PLACE LIVE SCAN STATUS PANEL (Compact, Auto-Height) -->
                    <div id="scan-live-status-panel" style="flex-shrink: 0; background-color: #ffffff; border: 1.5px solid #e2e8f0; border-radius: 9px; padding: 8px 12px; display: flex; flex-direction: column; justify-content: center; box-shadow: 0 1px 3px rgba(0,0,0,0.02); transition: all 0.2s ease;">
                        <!-- Initial Neutral State -->
                        <div id="live-status-content-neutral" style="text-align: center; color: #64748b; padding: 4px 0;">
                            <div style="display: flex; align-items: center; justify-content: center; gap: 8px;">
                                <div style="width: 24px; height: 24px; border-radius: 50%; background-color: #f1f5f9; display: inline-flex; align-items: center; justify-content: center;">
                                    <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"></path></svg>
                                </div>
                                <span style="font-size: 11px; font-weight: 700; color: #475569;">Siap Menembak Barcode Label Box</span>
                            </div>
                        </div>

                        <!-- Success State Container (Filled via JS) -->
                        <div id="live-status-content-success" class="hidden">
                            <!-- Injected dynamically -->
                        </div>

                        <!-- Scan History Feed Container (Collapsible / Toggleable) -->
                        <div id="live-status-content-history" class="hidden">
                            <!-- Injected dynamically via renderScanHistoryDrawer() -->
                        </div>

                        <!-- Rejected State Container (Filled via JS) -->
                        <div id="live-status-content-rejected" class="hidden">
                            <!-- Injected dynamically -->
                        </div>
                    </div>

                </div>

                <!-- ▶ KOLOM KANAN: ACCUMULATION TARGET, BOX TABLE & ACTION BUTTON (~58% Width) -->
                <div style="flex: 1; display: flex; flex-direction: column; gap: 10px; min-height: 0; overflow: hidden; background-color: #ffffff; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 12px 16px; box-shadow: 0 1px 4px rgba(0,0,0,0.03);">
                    
                    <!-- 1. Mini KPI Dashboard Cards & Progres Akumulasi (3 Compact Cards) -->
                    <div style="background-color: #f8fafc; border: 1.5px solid #cbd5e1; border-radius: 10px; padding: 10px 12px; flex-shrink: 0; display: flex; flex-direction: column; gap: 8px;">
                        <!-- 3 Mini Cards Grid -->
                        <div style="display: grid; grid-template-columns: 1.15fr 1.1fr 1fr; gap: 8px;">
                            <!-- Card 1: Akumulasi Scan vs Target Kanban -->
                            <div style="background-color: #ffffff; border: 1.5px solid #e2e8f0; border-radius: 8px; padding: 6px 10px; display: flex; flex-direction: column; justify-content: space-between;">
                                <div style="display: flex; align-items: center; justify-content: space-between;">
                                    <span style="font-size: 8.5px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.04em;">TOTAL QTY / TARGET KANBAN</span>
                                    <span id="kpi-progress-percent" style="font-family: monospace; font-size: 11px; font-weight: 900; color: #2563eb;">0%</span>
                                </div>
                                <div style="margin-top: 2px;">
                                    <span id="scan-progress-counter" style="font-family: monospace; font-size: 16px; font-weight: 900; color: #0f172a; line-height: 1.2;">
                                        <span id="scanned-total-qty">0</span> / <span id="target-kanban-qty-display">0</span> <span style="font-size: 10px; font-weight: 700; color: #64748b;">pcs</span>
                                    </span>
                                </div>
                                <div style="font-size: 9.5px; font-weight: 700; color: #64748b; margin-top: 2px;" id="kpi-card1-subtext">
                                    Menunggu scan box...
                                </div>
                            </div>

                            <!-- Card 2: Total Box & Komposisi Sumber Box (Box Baru, Safety Stock) -->
                            <div style="background-color: #ffffff; border: 1.5px solid #e2e8f0; border-radius: 8px; padding: 6px 10px; display: flex; flex-direction: column; justify-content: space-between;">
                                <div style="display: flex; align-items: center; justify-content: space-between;">
                                    <span style="font-size: 8.5px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.04em;">TOTAL BOX TERVERIFIKASI</span>
                                    <span style="font-size: 8.5px; font-weight: 800; color: #64748b; text-transform: uppercase;">SUMBER ASAL BOX</span>
                                </div>
                                <div style="margin-top: 2px;">
                                    <span id="kpi-label-count" style="font-family: monospace; font-size: 16px; font-weight: 900; color: #0f172a; line-height: 1.2;">
                                        0 <span style="font-size: 10px; font-weight: 700; color: #64748b;">Box</span>
                                    </span>
                                </div>
                                <div id="kpi-lot-composition-badges" style="display: flex; align-items: center; gap: 4px; flex-wrap: wrap; margin-top: 2px;">
                                    <span style="font-size: 9px; font-weight: 800; color: #047857; background-color: #ecfdf5; border: 1px solid #a7f3d0; padding: 1.5px 6px; border-radius: 4px;">0 Box Baru</span>
                                    <span style="font-size: 9px; font-weight: 800; color: #6d28d9; background-color: #f5f3ff; border: 1px solid #ddd6fe; padding: 1.5px 6px; border-radius: 4px;">0 Box SS</span>
                                </div>
                                <span id="kpi-unique-lots" class="hidden"></span>
                            </div>

                            <!-- Card 3: Status Sesi & Surplus Safety Stock -->
                            <div style="background-color: #ffffff; border: 1.5px solid #e2e8f0; border-radius: 8px; padding: 6px 10px; display: flex; flex-direction: column; justify-content: space-between;">
                                <div style="display: flex; align-items: center; justify-content: space-between;">
                                    <span style="font-size: 8.5px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.04em;">STATUS & SURPLUS</span>
                                    <span id="kpi-status-tag" style="font-size: 9.5px; font-weight: 800; padding: 1px 6px; border-radius: 9999px; background-color: #fef3c7; color: #92400e; border: 1px solid #fde68a;">
                                        Belum Lengkap
                                    </span>
                                </div>
                                <div style="margin-top: 2px;">
                                    <span id="kpi-remain-detail" style="font-family: monospace; font-size: 13.5px; font-weight: 800; color: #0f172a; display: block; line-height: 1.2;">
                                        Sisa 0 pcs lagi
                                    </span>
                                </div>
                                <div style="font-size: 9.5px; font-weight: 700; color: #64748b; margin-top: 2px;" id="scan-excess-msg">
                                    Sesuai Kuota
                                </div>
                            </div>
                        </div>

                        <!-- Progress Bar & Status Text Line -->
                        <div style="display: flex; flex-direction: column; gap: 3px;">
                            <div style="width: 100%; background-color: #e2e8f0; height: 7px; border-radius: 9999px; overflow: hidden;" id="scan-progress-bar-container">
                                <div id="scan-progress-bar" style="height: 100%; width: 0%; background-color: #2563eb; transition: width 0.3s ease;"></div>
                            </div>
                            <div style="display: flex; align-items: center; justify-content: space-between; font-size: 9.5px; color: #64748b;">
                                <span id="scan-status-msg" style="font-weight: 600;">Scan barcode box fisik untuk mengakumulasi kuota</span>
                                <span id="kpi-source-breakdown" style="font-weight: 700; color: #334155;"></span>
                            </div>
                        </div>

                        <!-- Compatibility placeholders -->
                        <div id="kpi-ss-badge-container" class="hidden"><span id="kpi-ss-used-qty">0</span></div>
                    </div>

                    <!-- 2. Scanned Boxes Table with Dedicated Internal Auto-Scroll -->
                    <div style="flex: 1; min-height: 0; display: flex; flex-direction: column; overflow: hidden;">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px; flex-shrink: 0;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span style="font-size: 11.5px; font-weight: 800; color: #1e293b;">
                                    Daftar Box Terverifikasi (<span id="scanned-labels-count" style="color: #2563eb;">0</span> Box):
                                </span>
                                <button type="button" id="btn-reset-box-filters" onclick="resetAllModalBoxExcelFilters()" style="display: none; font-size: 9.5px; font-weight: 800; color: #2563eb; background-color: #eff6ff; border: 1px solid #bfdbfe; padding: 2px 7px; border-radius: 5px; cursor: pointer;" title="Reset filter kolom yang sedang aktif">
                                    <span style="display: inline-flex; align-items: center; gap: 3px;">
                                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                        <span>Reset Filter</span>
                                    </span>
                                </button>
                            </div>
                            <button type="button" onclick="clearAllScannedLabels()" id="btn-clear-labels" style="display: none; font-size: 10px; font-weight: 800; color: #b91c1c; background-color: #fee2e2; border: 1.5px solid #f87171; padding: 3px 9px; border-radius: 6px; cursor: pointer; transition: all 0.15s ease;" onmouseover="this.style.backgroundColor='#fecaca'; this.style.borderColor='#ef4444';" onmouseout="this.style.backgroundColor='#fee2e2'; this.style.borderColor='#f87171';" title="Hapus semua label terverifikasi">
                                <span style="display: inline-flex; align-items: center; gap: 4px;">
                                    <svg style="width: 12px; height: 12px;" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                    Reset Semua
                                </span>
                            </button>
                        </div>

                        <div id="scanned-table-scroll-container" style="flex: 1; min-height: 0; overflow-y: auto; border: 1.5px solid #cbd5e1; border-radius: 8px; background-color: #ffffff; position: relative;">
                            <table class="w-full text-left text-xs" style="border-collapse: collapse;">
                                <thead style="position: sticky; top: 0; background-color: #f1f5f9; z-index: 20; border-bottom: 1.5px solid #cbd5e1;" class="text-[10.5px] font-extrabold text-slate-700 uppercase">
                                    <tr>
                                        <th class="px-2.5 py-2 text-center" style="width: 35px;">#</th>
                                        
                                        <!-- Kolom Lot Number dengan Excel Filter -->
                                        <th class="px-3 py-2" style="position: relative;">
                                            <div class="th-filter-wrapper">
                                                <span>Lot Number</span>
                                                <button type="button" class="th-filter-btn" data-box-col="lot" title="Filter Lot Number" onclick="toggleModalBoxExcelFilter(event, 'lot')">
                                                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                                </button>
                                            </div>
                                            <!-- Dropdown Filter Lot -->
                                            <div id="filter-dropdown-box-lot" class="excel-filter-dropdown align-left">
                                                <div style="display: flex; align-items: center; justify-content: space-between; padding-bottom: 5px; margin-bottom: 6px; border-bottom: 1px solid #e2e8f0;">
                                                    <span style="font-size: 10.5px; font-weight: 800; color: #0f172a;">FILTER LOT NUMBER</span>
                                                    <button type="button" class="excel-reset-btn" onclick="resetSingleModalBoxFilter('lot')">Reset</button>
                                                </div>
                                                <input type="text" class="excel-filter-search" placeholder="Cari Lot..." oninput="onSearchModalBoxFilterOptions('lot', this.value)">
                                                <div style="display: flex; gap: 4px; margin-bottom: 6px;">
                                                    <button type="button" class="excel-quick-btn" onclick="toggleSelectAllModalBoxFilter('lot', true)">✓ Semua</button>
                                                    <button type="button" class="excel-quick-btn" onclick="toggleSelectAllModalBoxFilter('lot', false)">✕ Kosongkan</button>
                                                </div>
                                                <div id="filter-list-box-lot" class="excel-filter-list"></div>
                                                <button type="button" class="excel-apply-btn" onclick="applyModalBoxExcelFilters()">Terapkan</button>
                                            </div>
                                        </th>

                                        <!-- Kolom Ref No dengan Excel Filter -->
                                        <th class="px-3 py-2" style="width: 120px; position: relative;">
                                            <div class="th-filter-wrapper">
                                                <span>Ref No</span>
                                                <button type="button" class="th-filter-btn" data-box-col="ref" title="Filter Ref Number" onclick="toggleModalBoxExcelFilter(event, 'ref')">
                                                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                                </button>
                                            </div>
                                            <!-- Dropdown Filter Ref -->
                                            <div id="filter-dropdown-box-ref" class="excel-filter-dropdown align-left">
                                                <div style="display: flex; align-items: center; justify-content: space-between; padding-bottom: 5px; margin-bottom: 6px; border-bottom: 1px solid #e2e8f0;">
                                                    <span style="font-size: 10.5px; font-weight: 800; color: #0f172a;">FILTER REF NO</span>
                                                    <button type="button" class="excel-reset-btn" onclick="resetSingleModalBoxFilter('ref')">Reset</button>
                                                </div>
                                                <input type="text" class="excel-filter-search" placeholder="Cari Ref..." oninput="onSearchModalBoxFilterOptions('ref', this.value)">
                                                <div style="display: flex; gap: 4px; margin-bottom: 6px;">
                                                    <button type="button" class="excel-quick-btn" onclick="toggleSelectAllModalBoxFilter('ref', true)">✓ Semua</button>
                                                    <button type="button" class="excel-quick-btn" onclick="toggleSelectAllModalBoxFilter('ref', false)">✕ Kosongkan</button>
                                                </div>
                                                <div id="filter-list-box-ref" class="excel-filter-list"></div>
                                                <button type="button" class="excel-apply-btn" onclick="applyModalBoxExcelFilters()">Terapkan</button>
                                            </div>
                                        </th>

                                        <!-- Kolom Asal / Riwayat dengan Excel Filter -->
                                        <th class="px-3 py-2" style="width: 135px; position: relative;">
                                            <div class="th-filter-wrapper">
                                                <span>Asal / Riwayat</span>
                                                <button type="button" class="th-filter-btn" data-box-col="origin" title="Filter Asal / Riwayat" onclick="toggleModalBoxExcelFilter(event, 'origin')">
                                                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                                </button>
                                            </div>
                                            <!-- Dropdown Filter Origin -->
                                            <div id="filter-dropdown-box-origin" class="excel-filter-dropdown align-right">
                                                <div style="display: flex; align-items: center; justify-content: space-between; padding-bottom: 5px; margin-bottom: 6px; border-bottom: 1px solid #e2e8f0;">
                                                    <span style="font-size: 10.5px; font-weight: 800; color: #0f172a;">FILTER ASAL / RIWAYAT</span>
                                                    <button type="button" class="excel-reset-btn" onclick="resetSingleModalBoxFilter('origin')">Reset</button>
                                                </div>
                                                <input type="text" class="excel-filter-search" placeholder="Cari Asal..." oninput="onSearchModalBoxFilterOptions('origin', this.value)">
                                                <div style="display: flex; gap: 4px; margin-bottom: 6px;">
                                                    <button type="button" class="excel-quick-btn" onclick="toggleSelectAllModalBoxFilter('origin', true)">✓ Semua</button>
                                                    <button type="button" class="excel-quick-btn" onclick="toggleSelectAllModalBoxFilter('origin', false)">✕ Kosongkan</button>
                                                </div>
                                                <div id="filter-list-box-origin" class="excel-filter-list"></div>
                                                <button type="button" class="excel-apply-btn" onclick="applyModalBoxExcelFilters()">Terapkan</button>
                                            </div>
                                        </th>

                                        <th class="px-3 py-2 text-right" style="width: 90px;">Qty Box</th>
                                        <th class="px-2.5 py-2 text-center" style="width: 65px;">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody id="scanned-labels-table-body" class="divide-y divide-slate-100 text-slate-700">
                                    <tr>
                                        <td colspan="6" class="px-3 py-8 text-center text-slate-400 italic font-medium">
                                            Belum ada label QR fisik yang discan. Silakan scan barcode box di sebelah kiri.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- 3. Sticky Bottom Action Controls (Always Visible, Zero-Scroll) -->
                    <div style="flex-shrink: 0; display: flex; align-items: center; gap: 10px; padding-top: 4px;">
                        <button type="button" id="btn-submit-scan-modal" onclick="triggerModalValidation()" 
                                style="flex: 1; background-color: #2563eb; color: #ffffff; border: none; padding: 10px 16px; border-radius: 8px; font-size: 13px; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 2px 6px rgba(37,99,235,0.3); transition: all 0.15s ease;"
                                onmouseover="this.style.backgroundColor='#1d4ed8';" onmouseout="this.style.backgroundColor='#2563eb';"
                                disabled>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                            <span>Validasi Matching & Mulai Inspeksi</span>
                        </button>
                    </div>

                    <div id="modal-loading" class="hidden text-center py-2 space-y-1">
                        <div class="inline-block animate-spin rounded-full h-5 w-5 border-2 border-blue-600 border-t-transparent"></div>
                        <p class="text-[11px] font-semibold text-slate-600">Memeriksa ketersediaan status DID & Matching Planning...</p>
                    </div>

                    <div id="modal-error-box" class="hidden p-3 bg-rose-50 border border-rose-200 rounded-xl text-xs text-rose-800 font-medium space-y-1">
                        <span id="modal-error-msg" class="whitespace-pre-line"></span>
                    </div>

                </div>

            </div>
        </div>

    </div>
</div>

