<!-- ═══════════════════════════════════════════════════════════════ -->
                <!-- TABEL WORKBOARD SAFETY STOCK (GROUPING PER PART & CHILD LOT TABLE) -->
                <!-- ═══════════════════════════════════════════════════════════════ -->
                <div class="w-full mb-8" style="min-width: 0; max-width: 100%; margin-bottom: 32px;">
                    <div id="ss-table-wrapper" class="overflow-visible w-full" style="width: 100%; max-width: 100%;">
                        <table class="w-full text-left text-xs text-slate-700 table-auto" style="width: 100%; border-collapse: collapse;">
                            <thead class="text-white font-bold text-[11px] uppercase tracking-wider select-none" style="background-color: #581c87; color: #ffffff;">
                                <tr>
                                    <th style="padding: 10px 10px; width: 40px; text-align: center; color: #ffffff; background-color: #581c87;">#</th>
                                    <!-- Kolom Part Code & Name dengan Filter Excel -->
                                    <th style="padding: 10px 12px; width: 30%; position: relative;">
                                        <div class="th-filter-wrapper">
                                            <span style="color: #ffffff;">Part Code & Name</span>
                                            <button type="button" class="th-filter-btn" data-ss-col="ss_part" title="Filter Part Code & Name" onclick="toggleSsFilter(event, 'ss_part')">
                                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                            </button>
                                        </div>
                                        <div id="filter-dropdown-ss_part" class="excel-filter-dropdown align-left">
                                            <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                                <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER PART</span>
                                                <button type="button" class="excel-reset-btn" onclick="resetSingleSsFilter('ss_part')" title="Reset filter kolom ini">
                                                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                                    <span>Reset</span>
                                                </button>
                                            </div>
                                            <input type="text" class="excel-filter-search" placeholder="Cari Part..." oninput="onSearchSsFilterOptions('ss_part', this.value)">
                                            <div class="flex items-center gap-1.5 mb-2">
                                                <button type="button" class="excel-quick-btn" onclick="toggleSelectAllSsFilter('ss_part', true)">
                                                    <svg width="11" height="11" class="text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                                    <span>Pilih Semua</span>
                                                </button>
                                                <button type="button" class="excel-quick-btn" onclick="toggleSelectAllSsFilter('ss_part', false)">
                                                    <svg width="11" height="11" class="text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                    <span>Hapus Pilihan</span>
                                                </button>
                                            </div>
                                            <div class="excel-filter-list" id="filter-list-ss_part"></div>
                                            <div class="excel-filter-actions">
                                                <button type="button" class="excel-btn-close" onclick="closeAllSsFilters()">Tutup</button>
                                                <button type="button" class="excel-btn-apply" onclick="applySsFilters('table1')">
                                                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                                    <span>Terapkan</span>
                                                </button>
                                            </div>
                                        </div>
                                    </th>

                                    <!-- Kolom Model & Str Loc dengan Filter Excel -->
                                    <th style="padding: 10px 12px; width: 140px; text-align: center; position: relative;">
                                        <div class="th-filter-wrapper justify-center">
                                            <span style="color: #ffffff;">Model & Str Loc</span>
                                            <button type="button" class="th-filter-btn" data-ss-col="ss_model" title="Filter Model & Str Loc" onclick="toggleSsFilter(event, 'ss_model')">
                                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                            </button>
                                        </div>
                                        <div id="filter-dropdown-ss_model" class="excel-filter-dropdown align-left">
                                            <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                                <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER MODEL</span>
                                                <button type="button" class="excel-reset-btn" onclick="resetSingleSsFilter('ss_model')" title="Reset filter kolom ini">
                                                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                                    <span>Reset</span>
                                                </button>
                                            </div>
                                            <input type="text" class="excel-filter-search" placeholder="Cari Model..." oninput="onSearchSsFilterOptions('ss_model', this.value)">
                                            <div class="flex items-center gap-1.5 mb-2">
                                                <button type="button" class="excel-quick-btn" onclick="toggleSelectAllSsFilter('ss_model', true)">
                                                    <svg width="11" height="11" class="text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                                    <span>Pilih Semua</span>
                                                </button>
                                                <button type="button" class="excel-quick-btn" onclick="toggleSelectAllSsFilter('ss_model', false)">
                                                    <svg width="11" height="11" class="text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                    <span>Hapus Pilihan</span>
                                                </button>
                                            </div>
                                            <div class="excel-filter-list" id="filter-list-ss_model"></div>
                                            <div class="excel-filter-actions">
                                                <button type="button" class="excel-btn-close" onclick="closeAllSsFilters()">Tutup</button>
                                                <button type="button" class="excel-btn-apply" onclick="applySsFilters('table1')">
                                                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                                    <span>Terapkan</span>
                                                </button>
                                            </div>
                                        </div>
                                    </th>

                                    <th style="padding: 10px 12px; width: 110px; text-align: center;">Total Lot</th>
                                    <th style="padding: 10px 12px; width: 110px; text-align: right;">Total Qty</th>

                                    <!-- Kolom Status Akumulasi dengan Filter Excel -->
                                    <th style="padding: 10px 12px; width: 130px; text-align: center; position: relative;">
                                        <div class="th-filter-wrapper justify-center">
                                            <span style="color: #ffffff;">Status Akumulasi</span>
                                            <button type="button" class="th-filter-btn" data-ss-col="ss_status" title="Filter Status Akumulasi" onclick="toggleSsFilter(event, 'ss_status')">
                                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                            </button>
                                        </div>
                                        <div id="filter-dropdown-ss_status" class="excel-filter-dropdown align-right">
                                            <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                                <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER STATUS</span>
                                                <button type="button" class="excel-reset-btn" onclick="resetSingleSsFilter('ss_status')" title="Reset filter kolom ini">
                                                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                                    <span>Reset</span>
                                                </button>
                                            </div>
                                            <input type="text" class="excel-filter-search" placeholder="Cari Status..." oninput="onSearchSsFilterOptions('ss_status', this.value)">
                                            <div class="flex items-center gap-1.5 mb-2">
                                                <button type="button" class="excel-quick-btn" onclick="toggleSelectAllSsFilter('ss_status', true)">
                                                    <svg width="11" height="11" class="text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                                    <span>Pilih Semua</span>
                                                </button>
                                                <button type="button" class="excel-quick-btn" onclick="toggleSelectAllSsFilter('ss_status', false)">
                                                    <svg width="11" height="11" class="text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                    <span>Hapus Pilihan</span>
                                                </button>
                                            </div>
                                            <div class="excel-filter-list" id="filter-list-ss_status"></div>
                                            <div class="excel-filter-actions">
                                                <button type="button" class="excel-btn-close" onclick="closeAllSsFilters()">Tutup</button>
                                                <button type="button" class="excel-btn-apply" onclick="applySsFilters('table1')">
                                                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                                    <span>Terapkan</span>
                                                </button>
                                            </div>
                                        </div>
                                    </th>

                                    <th style="padding: 10px 12px; width: 180px; text-align: right;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200">
                                <?php if (empty($ssKanbans)): ?>
                                    <tr>
                                        <td colspan="7" class="px-4 py-12 text-center text-slate-400">
                                            <svg class="w-10 h-10 text-slate-300 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                            </svg>
                                            <p class="font-bold text-slate-700 text-sm">Tidak ada data item Safety Stock pada filter ini.</p>
                                            <p class="text-xs text-slate-400 mt-1">Ubah filter tanggal di atas atau klik tombol <b>"+ Cek Fisik Safety Stock"</b> untuk memulai pemeriksaan gudang.</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($ssKanbans as $idx => $pg): 
                                        $pCode = $pg['part_code'];
                                        $pLots = $ssPartLots[$pCode] ?? [];
                                        $totalLots = count($pLots);
                                        $totalQty = 0;
                                        $hasActive = false;
                                        $hasNg = false;
                                        $passedCount = 0;
                                        foreach ($pLots as $pl) {
                                            $totalQty += (int)($pl['qty'] ?? 0);
                                            $res = strtolower($pl['lot_result'] ?? '');
                                            $st = strtolower($pl['lot_status'] ?? '');
                                            if ($res === 'in_progress' || $st === 'in_progress' || ($pl['session_status'] ?? '') === 'in_progress') {
                                                $hasActive = true;
                                            }
                                            if ($res === 'rejected' || $st === 'rejected' || !empty($pl['defects'])) {
                                                $hasNg = true;
                                            }
                                            if ($res === 'passed') {
                                                $passedCount++;
                                            }
                                        }
                                    ?>
                                        <!-- Part Group Master Row -->
                                        <?php
                                            $ssStatusLabel = $hasActive ? 'Sedang Dikerjakan' : ($hasNg ? 'Ada NG' : ($passedCount > 0 ? 'PASSED (SS)' : 'Belum Ada Lot'));
                                        ?>
                                        <tr class="hover:bg-purple-50/50 transition-colors bg-white font-semibold ss-master-row"
                                            data-ss-part="<?= htmlspecialchars($pCode . ' ' . $pg['display_part_name']) ?>"
                                            data-ss-model="<?= htmlspecialchars($pg['model_name'] . ' ' . ($pg['str_loc'] ?: 'WH-SS')) ?>"
                                            data-ss-status="<?= htmlspecialchars($ssStatusLabel) ?>"
                                            data-ss-detail-id="ss-detail-<?= $idx ?>">
                                            <td class="text-center py-3.5 font-bold text-slate-400"><?= $offset + $idx + 1 ?></td>
                                            <td class="py-3.5 px-3">
                                                <span class="font-mono font-extrabold text-purple-700 text-sm block"><?= htmlspecialchars($pCode) ?></span>
                                                <span class="text-xs text-slate-800 font-bold block truncate max-w-xs" title="<?= htmlspecialchars($pg['display_part_name']) ?>"><?= htmlspecialchars($pg['display_part_name']) ?></span>
                                                <span class="text-[10px] text-slate-400 font-medium block">Customer: <?= htmlspecialchars($pg['customer'] ?: 'INTERNAL SAFETY STOCK') ?></span>
                                            </td>
                                            <td class="py-3.5 px-3 text-center">
                                                <span class="font-bold text-slate-700 text-xs block"><?= htmlspecialchars($pg['model_name']) ?></span>
                                                <span class="px-1.5 py-0.5 rounded text-[9.5px] font-semibold bg-slate-100 text-slate-600 border border-slate-200 inline-block mt-0.5">
                                                    <?= htmlspecialchars($pg['str_loc'] ?: 'WH-SS') ?>
                                                </span>
                                            </td>
                                            <td class="py-3.5 px-3 text-center">
                                                <span class="px-2.5 py-1 rounded-full text-xs font-black bg-purple-100 text-purple-800 border border-purple-200 inline-flex items-center gap-1">
                                                    📦 <?= $totalLots ?> Lot Inspek
                                                </span>
                                            </td>
                                            <td class="py-3.5 px-3 text-right font-black text-purple-950 text-sm">
                                                <?= number_format($totalQty) ?> <span class="text-xs font-medium text-slate-500">pcs</span>
                                            </td>
                                            <td class="py-3.5 px-3 text-center">
                                                <?php if ($hasActive): ?>
                                                    <span class="px-2.5 py-1 rounded-full text-[10.5px] font-extrabold bg-amber-500 text-white shadow-xs inline-flex items-center gap-1">
                                                        <span class="w-1.5 h-1.5 rounded-full bg-white animate-pulse"></span>
                                                        Sedang Dikerjakan
                                                    </span>
                                                <?php elseif ($hasNg): ?>
                                                    <span class="px-2.5 py-1 rounded-full text-[10.5px] font-extrabold bg-rose-600 text-white shadow-xs inline-flex items-center gap-1">
                                                        ⚠️ Ada NG
                                                    </span>
                                                <?php elseif ($totalLots > 0): ?>
                                                    <span class="px-2.5 py-1 rounded-full text-[10.5px] font-extrabold bg-emerald-600 text-white shadow-xs inline-flex items-center gap-1">
                                                        ✓ PASSED (SS)
                                                    </span>
                                                <?php else: ?>
                                                    <span class="px-2.5 py-1 rounded-full text-[10.5px] font-bold bg-slate-100 text-slate-600 border border-slate-200 inline-flex items-center gap-1">
                                                        Belum Ada Lot
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="py-3.5 px-3 text-right">
                                                <div class="inline-flex items-center gap-1.5">
                                                    <a href="<?= base_url('modules/inspection/session.php?scan_new=1&type=safety_stock&plan_type=safety_stock&part_code=' . urlencode($pCode)) ?>"
                                                       class="px-2.5 py-1.5 bg-purple-600 hover:bg-purple-700 text-white font-extrabold text-xs rounded-lg shadow-xs inline-flex items-center gap-1 transition-all">
                                                        <span>+ Cek Fisik</span>
                                                    </a>
                                                    <button type="button" 
                                                            onclick="toggleSsPartDetail('ss-detail-<?= $idx ?>')"
                                                            class="px-2.5 py-1.5 bg-slate-100 hover:bg-purple-100 text-slate-700 hover:text-purple-800 font-extrabold text-xs rounded-lg border border-slate-300 hover:border-purple-300 inline-flex items-center gap-1 transition-all cursor-pointer">
                                                        <span>Lihat Lot (<?= $totalLots ?>)</span>
                                                        <svg class="w-3.5 h-3.5 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>

                                        <!-- Child Accordion Row (Sub-Tabel Lot Persis Riwayat Saya) -->
                                        <tr id="ss-detail-<?= $idx ?>" class="hidden bg-slate-50/80">
                                            <td colspan="7" class="p-3">
                                                <div class="bg-white rounded-xl border border-purple-200 shadow-sm overflow-hidden">
                                                    <div class="px-4 py-2.5 bg-purple-900 text-white flex items-center justify-between">
                                                        <div class="font-extrabold text-xs flex items-center gap-2">
                                                            <span class="w-2 h-2 rounded-full bg-purple-300 animate-ping"></span>
                                                            <span>RINCIAN LOT INSPEKSI SAFETY STOCK: <?= htmlspecialchars($pCode) ?> - <?= htmlspecialchars($pg['display_part_name']) ?></span>
                                                        </div>
                                                        <span class="text-[10px] font-bold bg-purple-800 px-2 py-0.5 rounded text-purple-100">
                                                            Total <?= $totalLots ?> Lot / Box
                                                        </span>
                                                    </div>

                                                    <div class="overflow-x-auto custom-scrollbar w-full" style="width: 100%; max-width: 100%; -webkit-overflow-scrolling: touch;">
                                                        <table class="w-full text-left text-xs text-slate-700 table-auto" style="width: 100%; min-width: 1450px; border-collapse: collapse;">
                                                            <thead class="bg-slate-900 text-white font-bold text-[10.5px] uppercase tracking-wider select-none">
                                                                <tr>
                                                                    <th style="padding: 9px 10px; width: 40px; text-align: center;">#</th>
                                                                    <th style="padding: 9px 10px; text-align: center; width: 140px;">WAKTU</th>
                                                                    <th style="padding: 9px 10px; width: 120px;">PART CODE</th>
                                                                    <th style="padding: 9px 10px; min-width: 150px;">PART NAME</th>
                                                                    <th style="padding: 9px 10px; text-align: center; width: 95px;">MODEL</th>
                                                                    <th style="padding: 9px 10px; width: 120px;">LOT NO</th>
                                                                    <th style="padding: 9px 10px; width: 110px;">REF NO</th>
                                                                    <th style="padding: 9px 10px; text-align: right; width: 90px;">QTY LOT</th>
                                                                    <th style="padding: 9px 10px; text-align: center; width: 100px;">SAMPLING</th>
                                                                    <th style="padding: 9px 10px; text-align: center; width: 65px;">NG</th>
                                                                    <th style="padding: 9px 10px; min-width: 170px;">DEFECT TEMUAN</th>
                                                                    <th style="padding: 9px 10px; text-align: center; width: 130px;">STATUS LOT</th>
                                                                    <th style="padding: 9px 10px; text-align: center; width: 80px;">SESI</th>
                                                                    <th style="padding: 9px 10px; text-align: center; width: 85px;">SHIFT</th>
                                                                    <th style="padding: 9px 10px; width: 130px;">INSPEKTUR</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody class="divide-y divide-slate-100 text-[11px]">
                                                                <?php if (empty($pLots)): ?>
                                                                    <tr>
                                                                        <td colspan="15" class="py-6 text-center text-slate-400 font-medium">
                                                                            Belum ada lot inspeksi yang dicatat untuk part ini.
                                                                        </td>
                                                                    </tr>
                                                                <?php else: ?>
                                                                    <?php foreach ($pLots as $lIdx => $rLot): 
                                                                        $hasNg = ((int)($rLot['ng_count'] ?? 0) > 0 || !empty($rLot['defects']));
                                                                        $defList = !empty($rLot['defects']) ? $rLot['defects'] : [];
                                                                        if (!empty($defList)) {
                                                                            $defParts = [];
                                                                            foreach ($defList as $df) {
                                                                                $defParts[] = '<span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200/80">' . htmlspecialchars($df['defect_name']) . ' <b class="text-rose-900 font-extrabold">(' . (int)($df['qty_ng'] ?? 1) . ' pcs)</b></span>';
                                                                            }
                                                                            $defectHtml = implode(' ', $defParts);
                                                                        } else {
                                                                            $defectHtml = '<span class="text-slate-400 font-medium">-</span>';
                                                                        }

                                                                        // Waktu calculation
                                                                        $startTimeTs = !empty($rLot['created_at']) ? strtotime($rLot['created_at']) : (!empty($rLot['session_started_at']) ? strtotime($rLot['session_started_at']) : 0);
                                                                        $endTimeTs = !empty($rLot['closed_at']) ? strtotime($rLot['closed_at']) : (!empty($rLot['action_noted_at']) ? strtotime($rLot['action_noted_at']) : (!empty($rLot['session_closed_at']) ? strtotime($rLot['session_closed_at']) : 0));
                                                                        
                                                                        $dateDisplay = $startTimeTs ? date('d/m/Y', $startTimeTs) : '-';
                                                                        $startTimeStr = $startTimeTs ? date('H:i', $startTimeTs) : '';
                                                                        $endTimeStr = $endTimeTs ? date('H:i', $endTimeTs) : '';
                                                                        $timeRangeStr = ($startTimeStr && $endTimeStr) ? ($startTimeStr . ' - ' . $endTimeStr . ' WIB') : ($startTimeStr ? ($startTimeStr . ' WIB') : '-');

                                                                        $durStr = '';
                                                                        if ($startTimeTs > 0 && $endTimeTs >= $startTimeTs) {
                                                                            $diffSec = $endTimeTs - $startTimeTs;
                                                                            $hrs = floor($diffSec / 3600);
                                                                            $mins = floor(($diffSec % 3600) / 60);
                                                                            $secs = $diffSec % 60;
                                                                            if ($hrs > 0) $durStr = "{$hrs}j {$mins}m";
                                                                            elseif ($mins > 0) $durStr = "{$mins}m {$secs}s";
                                                                            else $durStr = "{$secs}s";
                                                                        }
                                                                    ?>
                                                                        <tr class="<?= getLotRowClass($rLot) ?>">
                                                                            <td style="padding: 8px 10px; text-align: center; font-weight: 700; color: #94a3b8;"><?= $lIdx + 1 ?></td>
                                                                            <td style="padding: 8px 10px; text-align: center; font-family: monospace; color: #334155; white-space: nowrap;">
                                                                                <div style="font-weight: 800; color: #0f172a;"><?= $dateDisplay ?></div>
                                                                                <div style="font-size: 9.5px; color: #64748b; font-weight: 600; font-family: sans-serif; margin-top: 1px;"><?= $timeRangeStr ?></div>
                                                                                <?php if ($durStr !== ''): ?>
                                                                                    <div style="margin-top: 2px;">
                                                                                        <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[9.5px] font-extrabold bg-slate-100 text-slate-700 border border-slate-200">
                                                                                            ⏱️ <?= $durStr ?>
                                                                                        </span>
                                                                                    </div>
                                                                                <?php else: ?>
                                                                                    <div style="margin-top: 2px;">
                                                                                        <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[9.5px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                                                                            ⏳ Aktif
                                                                                        </span>
                                                                                    </div>
                                                                                <?php endif; ?>
                                                                            </td>
                                                                            <td style="padding: 8px 10px; font-family: monospace; font-weight: 800; color: #0284c7; white-space: nowrap;">
                                                                                <?= htmlspecialchars($rLot['part_code']) ?>
                                                                            </td>
                                                                            <td style="padding: 8px 10px; font-weight: 700; color: #1e293b;">
                                                                                <?= htmlspecialchars($rLot['part_name']) ?>
                                                                            </td>
                                                                            <td style="padding: 8px 10px; text-align: center; font-weight: 700; color: #64748b;">
                                                                                <?= htmlspecialchars($rLot['model_name']) ?>
                                                                            </td>
                                                                            <td style="padding: 8px 10px; font-family: monospace; font-weight: 700; color: #334155; white-space: nowrap;">
                                                                                <?= htmlspecialchars($rLot['lot_number'] ?: '-') ?>
                                                                            </td>
                                                                            <td style="padding: 8px 10px; font-family: monospace; font-weight: 800; color: #7c3aed; white-space: nowrap;">
                                                                                <?= htmlspecialchars($rLot['ref_number'] ?: '-') ?>
                                                                                <?php if (!empty($rLot['ref_number']) && ($rLot['ref_number'] === 'DIRECT-PASS' || strpos($rLot['ref_number'], 'DIRECT-PASS') !== false)): ?>
                                                                                    <span style="font-size: 8.5px; font-weight: 800; background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; padding: 1px 4px; border-radius: 4px; display: inline-block; margin-left: 3px;">
                                                                                        DIRECT
                                                                                    </span>
                                                                                <?php elseif (!empty($rLot['remarks']) && stripos($rLot['remarks'], 'Tutup Kanban') !== false): ?>
                                                                                    <span style="font-size: 8.5px; font-weight: 800; background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; padding: 1px 4px; border-radius: 4px; display: inline-block; margin-left: 3px;">
                                                                                        CLOSE KB
                                                                                    </span>
                                                                                <?php endif; ?>
                                                                            </td>
                                                                            <td style="padding: 8px 10px; text-align: right; font-weight: 800; color: #0f172a; white-space: nowrap;">
                                                                                <?= number_format((int)($rLot['qty'] ?? 0)) ?> <span class="text-[10px] font-normal text-slate-500">pcs</span>
                                                                            </td>
                                                                            <td style="padding: 8px 10px; text-align: center; font-weight: 800; color: #2563eb; white-space: nowrap;">
                                                                                <?= number_format((int)($rLot['sample_size'] ?? 0)) ?> <span class="text-[10px] font-normal text-slate-500">pcs</span>
                                                                            </td>
                                                                            <td style="padding: 8px 10px; text-align: center; font-weight: 800; white-space: nowrap;">
                                                                                <?php if ($hasNg): ?>
                                                                                    <span class="inline-block px-2 py-0.5 rounded text-[10px] font-black bg-rose-100 text-rose-700 border border-rose-200">
                                                                                        <?= (int)count($rLot['defects']) ?>
                                                                                    </span>
                                                                                <?php else: ?>
                                                                                    <span class="inline-block px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                                                        0
                                                                                    </span>
                                                                                <?php endif; ?>
                                                                            </td>
                                                                            <td style="padding: 8px 10px; font-weight: 600;">
                                                                                <?= $defectHtml ?>
                                                                            </td>
                                                                            <td style="padding: 8px 10px; text-align: center; white-space: nowrap;">
                                                                                <?= renderLotStatusBadge($rLot) ?>
                                                                            </td>
                                                                            <td style="padding: 8px 10px; text-align: center; white-space: nowrap;">
                                                                                <a href="<?= base_url('modules/inspection/session.php?id=' . (int)$rLot['inspection_session_id']) ?>"
                                                                                   class="px-2 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded font-bold text-[10px] inline-flex items-center gap-1 border border-slate-300">
                                                                                    <span>Lihat</span>
                                                                                </a>
                                                                            </td>
                                                                            <td style="padding: 8px 10px; text-align: center; font-family: monospace; font-weight: 700; color: #475569; white-space: nowrap;">
                                                                                <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-slate-100 text-slate-700 border border-slate-200">
                                                                                    <?= htmlspecialchars(getShiftName($rLot['created_at'] ?? $rLot['session_started_at'])) ?>
                                                                                </span>
                                                                            </td>
                                                                            <td style="padding: 8px 10px; font-weight: 700; color: #334155; white-space: nowrap;">
                                                                                <div class="flex items-center gap-1.5" title="QC Inspector: <?= htmlspecialchars($rLot['inspector_name'] ?? 'QC Inspector') ?>">
                                                                                    <div class="w-5 h-5 rounded-full bg-purple-100 text-purple-700 font-extrabold flex items-center justify-center text-[10px] border border-purple-200 uppercase">
                                                                                        <?= mb_substr($rLot['inspector_name'] ?? 'Q', 0, 1) ?>
                                                                                    </div>
                                                                                    <span class="truncate max-w-[110px]"><?= htmlspecialchars($rLot['inspector_name'] ?? 'QC Inspector') ?></span>
                                                                                </div>
                                                                            </td>
                                                                        </tr>
                                                                    <?php endforeach; ?>
                                                                <?php endif; ?>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- ───────────────────────────────────────────────────────────── -->
                <!-- TABEL 2: PART SAFETY STOCK YANG KELUAR / DIPAKAI OLEH KANBAN  -->
                <!-- ───────────────────────────────────────────────────────────── -->
                <div class="w-full mt-6" style="min-width: 0; max-width: 100%;">
                    <div class="bg-white rounded-xl border border-indigo-200 shadow-sm overflow-visible">
                        <!-- Header Bar Tabel 2 -->
                        <div class="px-4 py-3 flex flex-wrap items-center justify-between gap-3 border-b border-indigo-200" style="background-color: #1e1b4b; color: #ffffff;">
                            <div class="flex items-center gap-2.5">
                                <div class="w-8 h-8 rounded-lg flex items-center justify-center shadow-inner" style="background-color: rgba(255,255,255,0.15); color: #ffffff; border: 1px solid rgba(255,255,255,0.25);">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="font-black text-sm tracking-wide text-white flex items-center gap-2">
                                        <span>PART SAFETY STOCK YANG KELUAR / DIPAKAI KANBAN</span>
                                        <span class="text-[10px] font-extrabold px-2 py-0.5 rounded-full" style="background-color: #2563eb; color: #ffffff; border: 1px solid #60a5fa;">
                                            Stock Keluar
                                        </span>
                                    </h3>
                                    <p class="text-[11px] font-medium mt-0.5" style="color: #c7d2fe;">
                                        Riwayat pemakaian / alokasi part Safety Stock untuk pemenuhan Kanban Delivery pelanggan
                                    </p>
                                </div>
                            </div>

                            <div class="flex items-center gap-2 flex-wrap">
                                <!-- Navigasi Cepat Scroll Horizontal Kiri & Kanan -->
                                <div class="inline-flex items-center rounded-lg p-0.5 shadow-2xs" style="background-color: #0f172a; border: 1px solid #3b82f6;">
                                    <button type="button" 
                                            onclick="scrollSsUsedTable(-350)" 
                                            class="px-2.5 py-1 text-[11px] font-extrabold rounded transition-all inline-flex items-center gap-1 cursor-pointer select-none" 
                                            style="color: #e0e7ff;"
                                            title="Geser tabel ke kiri">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/></svg>
                                        <span>Scroll Kiri</span>
                                    </button>
                                    <div class="h-3.5 w-px" style="background-color: #312e81;"></div>
                                    <button type="button" 
                                            onclick="scrollSsUsedTable(350)" 
                                            class="px-2.5 py-1 text-[11px] font-extrabold rounded transition-all inline-flex items-center gap-1 cursor-pointer select-none" 
                                            style="color: #e0e7ff;"
                                            title="Geser tabel ke kanan">
                                        <span>Scroll Kanan</span>
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                                    </button>
                                </div>

                                <span class="text-[11px] font-extrabold px-2.5 py-1 rounded-full whitespace-nowrap shadow-xs" style="background-color: #312e81; color: #ffffff; border: 1px solid #4f46e5;">
                                    Total Terpakai: <b class="text-white"><?= number_format($stats['total_used_qty'] ?? 0) ?> pcs</b> (<?= count($ssUsedLots ?? []) ?> Alokasi)
                                </span>
                            </div>
                        </div>

                        <!-- Table Wrapper 2 -->
                        <div id="ss-used-table-wrapper" class="overflow-visible w-full" style="width: 100%; max-width: 100%;">
                            <table class="w-full text-left text-xs text-slate-700 table-auto" style="width: 100%; border-collapse: collapse;">
                                <thead class="bg-slate-900 text-white font-bold text-[11px] uppercase tracking-wider select-none border-b border-indigo-900" style="background-color: #0f172a; color: #ffffff;">
                                    <tr>
                                        <th style="padding: 10px 8px; width: 35px; text-align: center;">#</th>
                                        <th style="padding: 10px 10px; width: 110px; text-align: center;">Waktu Alokasi</th>

                                        <!-- Kanban & Customer dengan Filter Excel -->
                                        <th style="padding: 10px 10px; width: 180px; position: relative;">
                                            <div class="th-filter-wrapper">
                                                <span>Kanban & Customer</span>
                                                <button type="button" class="th-filter-btn" data-ss-col="used_kanban" title="Filter Kanban" onclick="toggleSsFilter(event, 'used_kanban')">
                                                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                                </button>
                                            </div>
                                            <div id="filter-dropdown-used_kanban" class="excel-filter-dropdown align-left">
                                                <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                                    <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER KANBAN</span>
                                                    <button type="button" class="excel-reset-btn" onclick="resetSingleSsFilter('used_kanban')" title="Reset">
                                                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                                        <span>Reset</span>
                                                    </button>
                                                </div>
                                                <input type="text" class="excel-filter-search" placeholder="Cari Kanban..." oninput="onSearchSsFilterOptions('used_kanban', this.value)">
                                                <div class="flex items-center gap-1.5 mb-2">
                                                    <button type="button" class="excel-quick-btn" onclick="toggleSelectAllSsFilter('used_kanban', true)">
                                                        <svg width="11" height="11" class="text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                                        <span>Pilih Semua</span>
                                                    </button>
                                                    <button type="button" class="excel-quick-btn" onclick="toggleSelectAllSsFilter('used_kanban', false)">
                                                        <svg width="11" height="11" class="text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                        <span>Hapus Pilihan</span>
                                                    </button>
                                                </div>
                                                <div class="excel-filter-list" id="filter-list-used_kanban"></div>
                                                <div class="excel-filter-actions">
                                                    <button type="button" class="excel-btn-close" onclick="closeAllSsFilters()">Tutup</button>
                                                    <button type="button" class="excel-btn-apply" onclick="applySsFilters('table2')">
                                                        <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                                        <span>Terapkan</span>
                                                    </button>
                                                </div>
                                            </div>
                                        </th>

                                        <!-- Part Code & Part Name dengan Filter Excel -->
                                        <th style="padding: 10px 10px; min-width: 190px; position: relative;">
                                            <div class="th-filter-wrapper">
                                                <span>Part Code & Part Name</span>
                                                <button type="button" class="th-filter-btn" data-ss-col="used_part" title="Filter Part" onclick="toggleSsFilter(event, 'used_part')">
                                                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                                </button>
                                            </div>
                                            <div id="filter-dropdown-used_part" class="excel-filter-dropdown align-left">
                                                <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                                    <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER PART</span>
                                                    <button type="button" class="excel-reset-btn" onclick="resetSingleSsFilter('used_part')" title="Reset">
                                                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                                        <span>Reset</span>
                                                    </button>
                                                </div>
                                                <input type="text" class="excel-filter-search" placeholder="Cari Part..." oninput="onSearchSsFilterOptions('used_part', this.value)">
                                                <div class="flex items-center gap-1.5 mb-2">
                                                    <button type="button" class="excel-quick-btn" onclick="toggleSelectAllSsFilter('used_part', true)">
                                                        <svg width="11" height="11" class="text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                                        <span>Pilih Semua</span>
                                                    </button>
                                                    <button type="button" class="excel-quick-btn" onclick="toggleSelectAllSsFilter('used_part', false)">
                                                        <svg width="11" height="11" class="text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                        <span>Hapus Pilihan</span>
                                                    </button>
                                                </div>
                                                <div class="excel-filter-list" id="filter-list-used_part"></div>
                                                <div class="excel-filter-actions">
                                                    <button type="button" class="excel-btn-close" onclick="closeAllSsFilters()">Tutup</button>
                                                    <button type="button" class="excel-btn-apply" onclick="applySsFilters('table2')">
                                                        <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                                        <span>Terapkan</span>
                                                    </button>
                                                </div>
                                            </div>
                                        </th>

                                        <!-- Model dengan Filter Excel -->
                                        <th style="padding: 10px 10px; width: 90px; text-align: center; position: relative;">
                                            <div class="th-filter-wrapper justify-center">
                                                <span>Model</span>
                                                <button type="button" class="th-filter-btn" data-ss-col="used_model" title="Filter Model" onclick="toggleSsFilter(event, 'used_model')">
                                                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                                </button>
                                            </div>
                                            <div id="filter-dropdown-used_model" class="excel-filter-dropdown align-left">
                                                <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                                    <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER MODEL</span>
                                                    <button type="button" class="excel-reset-btn" onclick="resetSingleSsFilter('used_model')" title="Reset">
                                                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                                        <span>Reset</span>
                                                    </button>
                                                </div>
                                                <input type="text" class="excel-filter-search" placeholder="Cari Model..." oninput="onSearchSsFilterOptions('used_model', this.value)">
                                                <div class="flex items-center gap-1.5 mb-2">
                                                    <button type="button" class="excel-quick-btn" onclick="toggleSelectAllSsFilter('used_model', true)">
                                                        <svg width="11" height="11" class="text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                                        <span>Pilih Semua</span>
                                                    </button>
                                                    <button type="button" class="excel-quick-btn" onclick="toggleSelectAllSsFilter('used_model', false)">
                                                        <svg width="11" height="11" class="text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                        <span>Hapus Pilihan</span>
                                                    </button>
                                                </div>
                                                <div class="excel-filter-list" id="filter-list-used_model"></div>
                                                <div class="excel-filter-actions">
                                                    <button type="button" class="excel-btn-close" onclick="closeAllSsFilters()">Tutup</button>
                                                    <button type="button" class="excel-btn-apply" onclick="applySsFilters('table2')">
                                                        <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                                        <span>Terapkan</span>
                                                    </button>
                                                </div>
                                            </div>
                                        </th>

                                        <th style="padding: 10px 10px; width: 150px;">Lot No & Ref No</th>
                                        <th style="padding: 10px 10px; width: 100px; text-align: right;">QTY Terpakai</th>

                                        <!-- Status Kanban dengan Filter Excel -->
                                        <th style="padding: 10px 10px; width: 100px; text-align: center; position: relative;">
                                            <div class="th-filter-wrapper justify-center">
                                                <span>Status Kanban</span>
                                                <button type="button" class="th-filter-btn" data-ss-col="used_status" title="Filter Status" onclick="toggleSsFilter(event, 'used_status')">
                                                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                                </button>
                                            </div>
                                            <div id="filter-dropdown-used_status" class="excel-filter-dropdown align-right">
                                                <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                                    <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER STATUS</span>
                                                    <button type="button" class="excel-reset-btn" onclick="resetSingleSsFilter('used_status')" title="Reset">
                                                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                                        <span>Reset</span>
                                                    </button>
                                                </div>
                                                <input type="text" class="excel-filter-search" placeholder="Cari Status..." oninput="onSearchSsFilterOptions('used_status', this.value)">
                                                <div class="flex items-center gap-1.5 mb-2">
                                                    <button type="button" class="excel-quick-btn" onclick="toggleSelectAllSsFilter('used_status', true)">
                                                        <svg width="11" height="11" class="text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                                        <span>Pilih Semua</span>
                                                    </button>
                                                    <button type="button" class="excel-quick-btn" onclick="toggleSelectAllSsFilter('used_status', false)">
                                                        <svg width="11" height="11" class="text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                        <span>Hapus Pilihan</span>
                                                    </button>
                                                </div>
                                                <div class="excel-filter-list" id="filter-list-used_status"></div>
                                                <div class="excel-filter-actions">
                                                    <button type="button" class="excel-btn-close" onclick="closeAllSsFilters()">Tutup</button>
                                                    <button type="button" class="excel-btn-apply" onclick="applySsFilters('table2')">
                                                        <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                                        <span>Terapkan</span>
                                                    </button>
                                                </div>
                                            </div>
                                        </th>

                                        <th style="padding: 10px 10px; width: 130px;">Inspektur</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 text-[11px]">
                                    <?php if (empty($ssUsedLots)): ?>
                                        <tr>
                                            <td colspan="9" class="py-14 text-center text-slate-400 bg-white">
                                                <div class="flex flex-col items-center justify-center gap-2">
                                                    <svg class="w-12 h-12 text-indigo-200 stroke-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                                                    </svg>
                                                    <span class="font-bold text-slate-700 text-sm">Belum ada Safety Stock yang dipakai untuk Kanban</span>
                                                    <span class="text-xs text-slate-400 max-w-sm">Ketika lot Safety Stock dialokasikan atau dipakai untuk menutup kekurangan Kanban, rincian pengeluaran akan otomatis tercatat di sini.</span>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php 
                                        foreach ($ssUsedLots as $uIdx => $uLot): 
                                            $allocTs = !empty($uLot['allocated_at']) ? strtotime($uLot['allocated_at']) : (!empty($uLot['session_started_at']) ? strtotime($uLot['session_started_at']) : 0);
                                            $allocDate = $allocTs ? date('d/m/Y', $allocTs) : '-';
                                            $allocTime = $allocTs ? date('H:i', $allocTs) . ' WIB' : '-';
                                        ?>
                                            <?php
                                                $usedKStat = strtolower($uLot['kanban_session_status'] ?? '');
                                                $usedStatusLabel = ($usedKStat === 'completed' || $usedKStat === 'passed') ? 'Selesai' : ($usedKStat === 'in_progress' ? 'Aktif' : strtoupper($usedKStat ?: 'OK'));
                                            ?>
                                            <tr class="hover:bg-indigo-50/40 transition-colors bg-white ss-used-row"
                                                data-used-kanban="<?= htmlspecialchars(($uLot['kanban_no'] ?? '') . ' ' . ($uLot['customer'] ?? '')) ?>"
                                                data-used-part="<?= htmlspecialchars($uLot['part_code'] . ' ' . $uLot['part_name']) ?>"
                                                data-used-model="<?= htmlspecialchars($uLot['model_name']) ?>"
                                                data-used-status="<?= htmlspecialchars($usedStatusLabel) ?>">
                                                <td style="padding: 8px 8px; text-align: center; font-weight: 700; color: #64748b;"><?= $uIdx + 1 ?></td>
                                                <td style="padding: 8px 10px; text-align: center; font-family: monospace; color: #334155; white-space: nowrap;">
                                                    <div style="font-weight: 800; color: #0f172a;"><?= $allocDate ?></div>
                                                    <div style="font-size: 9.5px; color: #64748b; font-weight: 600; font-family: sans-serif; margin-top: 1px;"><?= $allocTime ?></div>
                                                </td>
                                                <td style="padding: 8px 10px;">
                                                    <?php if (!empty($uLot['kanban_no'])): ?>
                                                        <span class="font-mono font-extrabold text-blue-700 block text-xs">
                                                            #<?= htmlspecialchars($uLot['kanban_no']) ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="font-mono font-bold text-slate-400 block text-xs">-</span>
                                                    <?php endif; ?>
                                                    <span class="text-[10px] text-slate-500 font-medium block truncate max-w-[170px]" title="<?= htmlspecialchars($uLot['customer'] ?? 'PT. Indonesia Epson Industry') ?>">
                                                        <?= htmlspecialchars($uLot['customer'] ?? 'PT. Indonesia Epson Industry') ?>
                                                    </span>
                                                </td>
                                                <td style="padding: 8px 10px;">
                                                    <span class="font-mono font-extrabold text-sky-700 block text-xs">
                                                        <?= htmlspecialchars($uLot['part_code']) ?>
                                                    </span>
                                                    <span class="text-[11px] font-bold text-slate-800 block truncate max-w-[220px]" title="<?= htmlspecialchars($uLot['part_name']) ?>">
                                                        <?= htmlspecialchars($uLot['part_name']) ?>
                                                    </span>
                                                </td>
                                                <td style="padding: 8px 10px; text-align: center; font-weight: 700; color: #64748b;">
                                                    <?= htmlspecialchars($uLot['model_name']) ?>
                                                </td>
                                                <td style="padding: 8px 10px; white-space: nowrap;">
                                                    <div class="flex items-center gap-1 flex-wrap">
                                                        <span class="px-1.5 py-0.5 rounded bg-purple-50 text-purple-800 border border-purple-200 font-mono font-bold text-[10.5px]">
                                                            <?= htmlspecialchars($uLot['lot_number'] ?: '-') ?>
                                                        </span>
                                                        <?php if (!empty($uLot['ref_number'])): ?>
                                                            <span class="px-1.5 py-0.5 rounded bg-slate-100 text-slate-700 border border-slate-200 font-mono font-bold text-[10px]" title="Ref No">
                                                                Ref: <?= htmlspecialchars($uLot['ref_number']) ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td style="padding: 8px 10px; text-align: right; font-family: monospace; font-weight: 900; color: #1e1b4b; white-space: nowrap;">
                                                    <span class="px-2 py-0.5 rounded bg-indigo-50 text-indigo-900 border border-indigo-200">
                                                        <?= number_format((int)$uLot['qty_used']) ?> pcs
                                                    </span>
                                                </td>
                                                <td style="padding: 8px 10px; text-align: center; white-space: nowrap;">
                                                    <?php 
                                                    $kStat = strtolower($uLot['kanban_session_status'] ?? '');
                                                    if ($kStat === 'completed' || $kStat === 'passed'): ?>
                                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-emerald-100 text-emerald-800 border border-emerald-200 inline-flex items-center gap-1">
                                                            ✓ Selesai
                                                        </span>
                                                    <?php elseif ($kStat === 'in_progress'): ?>
                                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-amber-100 text-amber-800 border border-amber-200 inline-flex items-center gap-1">
                                                            ⏳ Aktif
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-600 border border-slate-200">
                                                            <?= strtoupper($kStat ?: 'OK') ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="padding: 8px 10px; font-weight: 700; color: #334155; white-space: nowrap;">
                                                    <div class="flex items-center gap-1.5" title="User: <?= htmlspecialchars($uLot['operator_name'] ?? 'Operator') ?>">
                                                        <div class="w-5 h-5 rounded-full bg-indigo-100 text-indigo-700 font-extrabold flex items-center justify-center text-[10px] border border-indigo-200 uppercase">
                                                            <?= mb_substr($uLot['operator_name'] ?? 'O', 0, 1) ?>
                                                        </div>
                                                        <span class="truncate max-w-[100px]"><?= htmlspecialchars($uLot['operator_name'] ?? 'Operator') ?></span>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
