<!-- ═══════════════════════════════════════════════════════════════ -->
                <!-- TABEL JADWAL KANBAN DELIVERY                                    -->
                <!-- ═══════════════════════════════════════════════════════════════ -->
                <div class="w-full">
                    <table class="w-full text-left text-xs text-slate-700 table-fixed" style="border-collapse: collapse;">
                        <colgroup>
                            <col style="width: <?= $isAdminOrSupervisor ? '54px' : '38px' ?>;">
                            <col style="width: 19%">
                            <col style="width: 25%">
                            <col style="width: 16%">
                            <col style="width: 15%">
                            <col style="width: 11%">
                            <col>
                        </colgroup>
                        <thead class="bg-slate-50 text-slate-600 font-bold border-b border-slate-200 uppercase tracking-wider text-[10px]">
                            <tr>
                                <th class="text-center px-1.5 py-2.5">
                                    <?php if ($isAdminOrSupervisor): ?>
                                        <div class="flex items-center justify-center gap-1">
                                            <input type="checkbox" id="bulk-kanban-check-all" onchange="toggleSelectAllKanban(this.checked)" title="Pilih Semua Kanban Terbuka" class="w-4 h-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500 cursor-pointer shadow-sm">
                                            <span class="text-[9px] text-slate-400">All</span>
                                        </div>
                                    <?php else: ?>
                                        No
                                    <?php endif; ?>
                                </th>

                                <!-- Kolom KANBAN dengan Excel Filter -->
                                <th class="px-3 py-2.5" style="position: relative;">
                                    <div class="th-filter-wrapper">
                                        <span>Kanban</span>
                                        <button type="button" class="th-filter-btn" data-col="kanban" title="Klik untuk menyaring Kanban" onclick="toggleExcelFilter(event, 'kanban')">
                                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                        </button>
                                    </div>
                                    <!-- Dropdown Filter Kanban -->
                                    <div id="filter-dropdown-kanban" class="excel-filter-dropdown align-left">
                                        <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                            <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER KANBAN</span>
                                            <button type="button" class="excel-reset-btn" onclick="resetSingleColFilter('kanban')" title="Reset filter kolom ini">
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                                <span>Reset</span>
                                            </button>
                                        </div>
                                        <input type="text" class="excel-filter-search" placeholder="Cari..." oninput="onSearchFilterOptions('kanban', this.value)">
                                        <div class="flex items-center gap-1.5 mb-2">
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllFilter('kanban', true)">
                                                <svg width="11" height="11" class="text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                                <span>Pilih Semua</span>
                                            </button>
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllFilter('kanban', false)">
                                                <svg width="11" height="11" class="text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                <span>Hapus Pilihan</span>
                                            </button>
                                        </div>
                                        <div class="excel-filter-list" id="filter-list-kanban">
                                            <!-- Checkbox list dinamis -->
                                        </div>
                                        <div class="excel-filter-actions">
                                            <span class="text-[10px] text-slate-500 font-bold filter-count-info"></span>
                                            <div class="flex items-center gap-2">
                                                <button type="button" class="excel-btn-close" onclick="closeAllExcelFilters()">
                                                    Tutup
                                                </button>
                                                <button type="button" class="excel-btn-apply" onclick="applyExcelFilters()">
                                                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                                    <span>Terapkan</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </th>

                                <!-- Kolom PART dengan Excel Filter -->
                                <th class="px-3 py-2.5" style="position: relative;">
                                    <div class="th-filter-wrapper">
                                        <span>Part</span>
                                        <button type="button" class="th-filter-btn" data-col="part" title="Klik untuk menyaring Part" onclick="toggleExcelFilter(event, 'part')">
                                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                        </button>
                                    </div>
                                    <!-- Dropdown Filter Part -->
                                    <div id="filter-dropdown-part" class="excel-filter-dropdown align-left">
                                        <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                            <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER PART</span>
                                            <button type="button" class="excel-reset-btn" onclick="resetSingleColFilter('part')" title="Reset filter kolom ini">
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                                <span>Reset</span>
                                            </button>
                                        </div>
                                        <input type="text" class="excel-filter-search" placeholder="Cari Part..." oninput="onSearchFilterOptions('part', this.value)">
                                        <div class="flex items-center gap-1.5 mb-2">
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllFilter('part', true)">
                                                <svg width="11" height="11" class="text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                                <span>Pilih Semua</span>
                                            </button>
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllFilter('part', false)">
                                                <svg width="11" height="11" class="text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                <span>Hapus Pilihan</span>
                                            </button>
                                        </div>
                                        <div class="excel-filter-list" id="filter-list-part">
                                            <!-- Checkbox list dinamis -->
                                        </div>
                                        <div class="excel-filter-actions">
                                            <span class="text-[10px] text-slate-500 font-bold filter-count-info"></span>
                                            <div class="flex items-center gap-2">
                                                <button type="button" class="excel-btn-close" onclick="closeAllExcelFilters()">
                                                    Tutup
                                                </button>
                                                <button type="button" class="excel-btn-apply" onclick="applyExcelFilters()">
                                                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                                    <span>Terapkan</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </th>

                                <!-- Kolom JADWAL & ETA dengan Excel Filter -->
                                <th class="px-2.5 py-2.5 whitespace-nowrap" style="position: relative;">
                                    <div class="th-filter-wrapper">
                                        <span>Jadwal & ETA</span>
                                        <button type="button" class="th-filter-btn" data-col="schedule" title="Klik untuk menyaring Jadwal & ETA" onclick="toggleExcelFilter(event, 'schedule')">
                                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                        </button>
                                    </div>
                                    <!-- Dropdown Filter Jadwal & ETA -->
                                    <div id="filter-dropdown-schedule" class="excel-filter-dropdown align-left">
                                        <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                            <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER JADWAL & ETA</span>
                                            <button type="button" class="excel-reset-btn" onclick="resetSingleColFilter('schedule')" title="Reset filter kolom ini">
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                                <span>Reset</span>
                                            </button>
                                        </div>
                                        <input type="text" class="excel-filter-search" placeholder="Cari tanggal / status..." oninput="onSearchFilterOptions('schedule', this.value)">
                                        <div class="flex items-center gap-1.5 mb-2">
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllFilter('schedule', true)">
                                                <svg width="11" height="11" class="text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                                <span>Pilih Semua</span>
                                            </button>
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllFilter('schedule', false)">
                                                <svg width="11" height="11" class="text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                <span>Hapus Pilihan</span>
                                            </button>
                                        </div>
                                        <div class="excel-filter-list" id="filter-list-schedule">
                                            <!-- Checkbox list dinamis -->
                                        </div>
                                        <div class="excel-filter-actions">
                                            <span class="text-[10px] text-slate-500 font-bold filter-count-info"></span>
                                            <div class="flex items-center gap-2">
                                                <button type="button" class="excel-btn-close" onclick="closeAllExcelFilters()">
                                                    Tutup
                                                </button>
                                                <button type="button" class="excel-btn-apply" onclick="applyExcelFilters()">
                                                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                                    <span>Terapkan</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </th>

                                <!-- Kolom PROGRES dengan Excel Filter -->
                                <th class="px-2.5 py-2.5 text-center whitespace-nowrap" style="position: relative;">
                                    <div class="th-filter-wrapper">
                                        <span class="w-full text-center">Progres</span>
                                        <button type="button" class="th-filter-btn" data-col="progress" title="Klik untuk menyaring Progres" onclick="toggleExcelFilter(event, 'progress')">
                                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                        </button>
                                    </div>
                                    <!-- Dropdown Filter Progres -->
                                    <div id="filter-dropdown-progress" class="excel-filter-dropdown align-right">
                                        <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                            <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER PROGRES</span>
                                            <button type="button" class="excel-reset-btn" onclick="resetSingleColFilter('progress')" title="Reset filter kolom ini">
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                                <span>Reset</span>
                                            </button>
                                        </div>
                                        <input type="text" class="excel-filter-search" placeholder="Cari progres..." oninput="onSearchFilterOptions('progress', this.value)">
                                        <div class="flex items-center gap-1.5 mb-2">
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllFilter('progress', true)">
                                                <svg width="11" height="11" class="text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                                <span>Pilih Semua</span>
                                            </button>
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllFilter('progress', false)">
                                                <svg width="11" height="11" class="text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                <span>Hapus Pilihan</span>
                                            </button>
                                        </div>
                                        <div class="excel-filter-list" id="filter-list-progress">
                                            <!-- Checkbox list dinamis -->
                                        </div>
                                        <div class="excel-filter-actions">
                                            <span class="text-[10px] text-slate-500 font-bold filter-count-info"></span>
                                            <div class="flex items-center gap-2">
                                                <button type="button" class="excel-btn-close" onclick="closeAllExcelFilters()">
                                                    Tutup
                                                </button>
                                                <button type="button" class="excel-btn-apply" onclick="applyExcelFilters()">
                                                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                                    <span>Terapkan</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </th>

                                <!-- Kolom STATUS dengan Excel Filter -->
                                <th class="px-2.5 py-2.5 text-center whitespace-nowrap" style="position: relative;">
                                    <div class="th-filter-wrapper">
                                        <span class="w-full text-center">Status</span>
                                        <button type="button" class="th-filter-btn" data-col="status" title="Klik untuk menyaring Status" onclick="toggleExcelFilter(event, 'status')">
                                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                        </button>
                                    </div>
                                    <!-- Dropdown Filter Status -->
                                    <div id="filter-dropdown-status" class="excel-filter-dropdown align-right">
                                        <div class="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-100">
                                            <span class="text-[11px] font-extrabold text-slate-900 tracking-wide">FILTER STATUS</span>
                                            <button type="button" class="excel-reset-btn" onclick="resetSingleColFilter('status')" title="Reset filter kolom ini">
                                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                                <span>Reset</span>
                                            </button>
                                        </div>
                                        <input type="text" class="excel-filter-search" placeholder="Cari status..." oninput="onSearchFilterOptions('status', this.value)">
                                        <div class="flex items-center gap-1.5 mb-2">
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllFilter('status', true)">
                                                <svg width="11" height="11" class="text-emerald-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                                <span>Pilih Semua</span>
                                            </button>
                                            <button type="button" class="excel-quick-btn" onclick="toggleSelectAllFilter('status', false)">
                                                <svg width="11" height="11" class="text-rose-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                <span>Hapus Pilihan</span>
                                            </button>
                                        </div>
                                        <div class="excel-filter-list" id="filter-list-status">
                                            <!-- Checkbox list dinamis -->
                                        </div>
                                        <div class="excel-filter-actions">
                                            <span class="text-[10px] text-slate-500 font-bold filter-count-info"></span>
                                            <div class="flex items-center gap-2">
                                                <button type="button" class="excel-btn-close" onclick="closeAllExcelFilters()">
                                                    Tutup
                                                </button>
                                                <button type="button" class="excel-btn-apply" onclick="applyExcelFilters()">
                                                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                                    <span>Terapkan</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </th>

                                <th class="px-3 py-2.5 text-right whitespace-nowrap">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="kanban-table-body" class="divide-y divide-slate-100">
                            <!-- Baris Indikator Filter Aktif Client-Side -->
                            <tr id="excel-filter-active-bar" style="display: none; background-color: #eff6ff;">
                                <td colspan="7" class="px-3 py-1.5 text-xs text-blue-900 border-b border-blue-200">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center gap-1.5 font-bold text-[11px]">
                                            <svg class="w-3.5 h-3.5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                            <span>Filter Kolom Excel Aktif:</span>
                                            <span id="excel-filter-summary-text" class="font-normal text-blue-700"></span>
                                        </div>
                                        <button type="button" onclick="resetAllExcelFilters()" class="text-[10.5px] font-bold text-rose-600 hover:text-rose-800 hover:underline">
                                            Reset Semua Filter Kolom
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <!-- Baris Jika Hasil Filter Kosong -->
                            <tr id="excel-filter-empty-row" style="display: none;">
                                <td colspan="7" class="px-4 py-8 text-center text-slate-400">
                                    <svg class="w-7 h-7 text-slate-300 mx-auto mb-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"></path>
                                    </svg>
                                    <p class="font-bold text-slate-600 text-xs">Tidak ada data yang sesuai dengan filter kolom yang dipilih.</p>
                                    <button type="button" onclick="resetAllExcelFilters()" class="mt-1.5 px-2.5 py-1 bg-blue-50 text-blue-600 hover:bg-blue-100 rounded text-[11px] font-bold border border-blue-200">
                                        Hapus Filter Kolom
                                    </button>
                                </td>
                            </tr>
                            <?php if (empty($kanbans)): ?>
                                <tr>
                                    <td colspan="7" class="px-4 py-12 text-center text-slate-400">
                                        <svg class="w-9 h-9 text-slate-300 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                                        </svg>
                                        <?php if ($scopeFilter === 'today'): ?>
                                            <p class="font-bold text-slate-700 text-sm">Belum ada dokumen / jadwal Kanban untuk hari ini.</p>
                                            <p class="text-xs text-slate-400 mt-1">Dokumen batch Kanban hari ini belum diunggah oleh admin.</p>
                                            <?php if ($pendingYesterdayCount > 0): ?>
                                                <div class="mt-3.5 inline-flex items-center gap-2">
                                                    <a href="?tab=kanban&scope=pending_yesterday" class="px-3 py-1.5 bg-amber-500 hover:bg-amber-600 text-white font-bold text-xs rounded-lg shadow-xs transition-all inline-flex items-center gap-1.5">
                                                        <span>Periksa Tunggakan Kemarin (<?= number_format($pendingYesterdayCount) ?> Item)</span>
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                        <?php elseif ($scopeFilter === 'pending_yesterday'): ?>
                                            <p class="font-bold text-slate-700 text-sm">Semua Kanban hari kemarin telah selesai diinspeksi.</p>
                                            <p class="text-xs text-slate-400 mt-1">Tidak ada tunggakan atau sisa inspeksi dari hari kemarin.</p>
                                            <div class="mt-3.5">
                                                <a href="?tab=kanban&scope=today" class="px-3 py-1.5 bg-slate-800 hover:bg-slate-900 text-white font-bold text-xs rounded-lg transition-all">
                                                    Kembali ke Hari Ini
                                                </a>
                                            </div>
                                        <?php else: ?>
                                            <p class="font-bold text-slate-700 text-sm">Tidak ada data jadwal Kanban pada filter ini.</p>
                                            <p class="text-xs text-slate-400 mt-1">Ubah kata kunci pencarian atau reset filter di atas.</p>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($kanbans as $idx => $k): 
                                    $kid = (int)$k['id'];
                                    $kSessions = $sessionsByKanban[$kid] ?? [];
                                    $totalSessions = count($kSessions);
                                    $targetQty = (int)$k['qty'];
                                    $passedQty = max(0, (int)$k['total_passed_qty']);
                                    
                                    // Hitung passedQty dan deteksi reject dari data lot riil
                                    // Catatan: $passedQty dari SQL (total_passed_qty) sudah menghitung
                                    // lot passed+skipped tanpa excess, dan selaras dengan live status API.
                                    // Loop ini hanya untuk mendeteksi lot rejected.
                                    $calcPassedQty = 0;
                                    $hasAnyRejLot = false;
                                    foreach ($kSessions as $ks) {
                                        $ksId = (int)$ks['id'];
                                        $sLotsList = $lotsBySession[$ksId] ?? [];
                                        if (!empty($sLotsList)) {
                                            $sessLotPass = 0;
                                            foreach ($sLotsList as $sl) {
                                                $slRes = $sl['lot_result'] ?? '';
                                                $slSt = $sl['lot_status'] ?? '';
                                                // Sama persis dengan query SQL: passed OR skipped, bukan replaced
                                                if (($slRes === 'passed' || $slRes === 'skipped') && $slSt !== 'replaced') {
                                                    $sessLotPass += (int)($sl['qty'] ?? 0);
                                                }
                                                if ($slRes === 'rejected' || $slSt === 'rejected' || $slSt === 'ng_found') {
                                                    $hasAnyRejLot = true;
                                                }
                                            }
                                            $calcPassedQty += $sessLotPass;
                                        } elseif (($ks['status'] ?? '') === 'passed') {
                                            $calcPassedQty += (int)$ks['total_scanned_qty'];
                                        }
                                        if (($ks['status'] ?? '') === 'rejected') {
                                            $hasAnyRejLot = true;
                                        }
                                    }
                                    // Gunakan nilai terbesar antara SQL query (akurat) dan loop PHP (backup)
                                    // SQL query (total_passed_qty) adalah primary source of truth
                                    if ($calcPassedQty > 0 || !empty($kSessions)) {
                                        $passedQty = max($passedQty, $calcPassedQty);
                                    }

                                    $progressPct = ($targetQty > 0) ? min(100, round(($passedQty / $targetQty) * 100)) : 0;
                                    $isFullyPassed = ($targetQty > 0 && $passedQty >= $targetQty);
                                    $hasRejectedSession = $hasAnyRejLot;
                                    
                                    $actSess = $activeSessionByKanban[$kid] ?? null;
                                    $hasActiveSession = !empty($actSess);
                                    $isOwnSession = ($hasActiveSession && (int)$actSess['inspector_id'] === $currentUserId);
                                    
                                    $is100Check = (strcasecmp($k['check_type'], '100%') === 0 || strpos($k['check_type'], '100') !== false);
                                    
                                    $schedDate = !empty($k['eta']) ? $k['eta'] : (!empty($k['req_date']) ? $k['req_date'] : $k['created_at']);
                                    $isOverdue = (!$isFullyPassed && $k['status'] !== 'completed' && !empty($schedDate) && date('Y-m-d', strtotime($schedDate)) < date('Y-m-d'));
                                    $isPartial = (!$isFullyPassed && $k['status'] !== 'completed' && ($k['status'] === 'partial' || ($passedQty > 0 && $passedQty < $targetQty)));
                                    $isCompleted = ($isFullyPassed || $k['status'] === 'completed');
                                    $isRejected = ($k['status'] === 'rejected' && $passedQty <= 0);
                                    $remainingQuotaToClose = max(0, $targetQty - $passedQty);

                                    // Estimasi Sample Size AQL (ISO 2859-1 Level II)
                                    $sampleSizeAql = 0;
                                    if ($remainingQuotaToClose > 0) {
                                        if ($remainingQuotaToClose <= 8) $sampleSizeAql = 2;
                                        elseif ($remainingQuotaToClose <= 15) $sampleSizeAql = 3;
                                        elseif ($remainingQuotaToClose <= 25) $sampleSizeAql = 5;
                                        elseif ($remainingQuotaToClose <= 50) $sampleSizeAql = 8;
                                        elseif ($remainingQuotaToClose <= 90) $sampleSizeAql = 13;
                                        elseif ($remainingQuotaToClose <= 150) $sampleSizeAql = 20;
                                        elseif ($remainingQuotaToClose <= 280) $sampleSizeAql = 32;
                                        elseif ($remainingQuotaToClose <= 500) $sampleSizeAql = 50;
                                        elseif ($remainingQuotaToClose <= 1200) $sampleSizeAql = 80;
                                        elseif ($remainingQuotaToClose <= 3200) $sampleSizeAql = 125;
                                        elseif ($remainingQuotaToClose <= 10000) $sampleSizeAql = 200;
                                        elseif ($remainingQuotaToClose <= 35000) $sampleSizeAql = 315;
                                        else $sampleSizeAql = 500;
                                    }

                                    // Klasifikasi Nilai Filter
                                    $filterStatusVal = 'Belum Mulai';
                                    if ($hasActiveSession) {
                                        $filterStatusVal = 'Sedang Dikerjakan';
                                    } elseif ($isCompleted) {
                                        $filterStatusVal = 'Selesai';
                                    } elseif ($isPartial) {
                                        $filterStatusVal = $hasRejectedSession ? 'Parsial (Ada Reject)' : 'Parsial';
                                    } elseif ($isRejected) {
                                        $filterStatusVal = 'Reject';
                                    }

                                    $filterProgressVal = '0% (Belum Mulai)';
                                    if ($progressPct >= 100) {
                                        $filterProgressVal = '100% Selesai';
                                    } elseif ($progressPct >= 75) {
                                        $filterProgressVal = '75% - 99%';
                                    } elseif ($progressPct >= 50) {
                                        $filterProgressVal = '50% - 74%';
                                    } elseif ($progressPct > 0) {
                                        $filterProgressVal = '1% - 49%';
                                    }

                                    $filterScheduleVal = !empty($schedDate) ? date('d/m/Y', strtotime($schedDate)) : '-';
                                    if ($isOverdue) {
                                        $filterScheduleVal .= ' (Overdue)';
                                    }

                                    $filterKanbanVal = '#' . ($k['kanban_no'] ?? '') . ' - ' . ($k['customer'] ?? 'PT. Indonesia Epson Industry');
                                    $filterPartVal = ($k['item_code'] ?? '') . ' - ' . ($k['display_part_name'] ?? '');

                                    if ($isOverdue) {
                                        $rowColorClass = 'row-status-overdue';
                                    } elseif ($hasActiveSession) {
                                        $rowColorClass = 'row-status-active';
                                    } elseif ($isPartial) {
                                        $rowColorClass = 'row-status-partial';
                                    } elseif ($isCompleted) {
                                        $rowColorClass = 'row-status-completed';
                                    } elseif ($isRejected) {
                                        $rowColorClass = 'row-status-rejected';
                                    } else {
                                        $rowColorClass = 'row-status-uninspected';
                                    }
                                ?>
                                    <!-- Kanban Master Row -->
                                    <tr id="kanban-row-<?= $kid ?>" 
                                        data-kanban-id="<?= $kid ?>" 
                                        data-kanban-no="<?= htmlspecialchars($k['kanban_no'] ?? '', ENT_QUOTES) ?>"
                                        data-item-code="<?= htmlspecialchars($k['item_code'] ?? '', ENT_QUOTES) ?>"
                                        data-part-name="<?= htmlspecialchars($k['display_part_name'] ?? '', ENT_QUOTES) ?>"
                                        data-customer="<?= htmlspecialchars($k['customer'] ?? 'PT. Indonesia Epson Industry', ENT_QUOTES) ?>"
                                        data-target-qty="<?= (int)$targetQty ?>"
                                        data-passed-qty="<?= (int)$passedQty ?>"
                                        data-filter-kanban="<?= htmlspecialchars($filterKanbanVal, ENT_QUOTES) ?>"
                                        data-filter-part="<?= htmlspecialchars($filterPartVal, ENT_QUOTES) ?>"
                                        data-filter-schedule="<?= htmlspecialchars($filterScheduleVal, ENT_QUOTES) ?>"
                                        data-filter-progress="<?= htmlspecialchars($filterProgressVal, ENT_QUOTES) ?>"
                                        data-filter-status="<?= htmlspecialchars($filterStatusVal, ENT_QUOTES) ?>"
                                        data-is-overdue="<?= $isOverdue ? '1' : '0' ?>"
                                        class="transition-colors <?= $rowColorClass ?>" style="vertical-align: middle;">
                                        
                                        <!-- No & Select Checkbox (Admin) -->
                                        <td class="text-center px-1.5 py-3 font-semibold text-slate-400">
                                            <?php 
                                            $posInPaper = $itemPositionInBatch[$kid] ?? ($offset + $idx + 1);
                                            ?>
                                            <?php if ($isAdminOrSupervisor): ?>
                                                <div class="flex items-center justify-center gap-1.5">
                                                    <?php $canBulkClose = (!$isCompleted && !$hasActiveSession); ?>
                                                    <input type="checkbox" 
                                                           class="bulk-kanban-cb w-4 h-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500 cursor-pointer shadow-sm disabled:opacity-30 disabled:cursor-not-allowed" 
                                                           value="<?= $kid ?>" 
                                                           data-kanban-no="<?= htmlspecialchars($k['kanban_no']) ?>" 
                                                           data-part="<?= htmlspecialchars($k['item_code']) ?>" 
                                                           data-qty="<?= (int)$remainingQuotaToClose ?>" 
                                                           data-sample="<?= (int)$sampleSizeAql ?>" 
                                                           <?= !$canBulkClose ? 'disabled title="Sudah selesai atau sedang diinspeksi"' : 'title="Pilih untuk Direct Close"' ?> 
                                                           onchange="onKanbanCheckChange()">
                                                    <span class="text-xs font-bold text-slate-700 font-mono"><?= $posInPaper ?></span>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-xs font-bold text-slate-700 font-mono"><?= $posInPaper ?></span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- No Kanban & Customer -->
                                        <td class="px-3 py-3">
                                            <div class="flex items-center space-x-1.5">
                                                <span class="font-mono font-black text-slate-900 text-xs">
                                                    #<?= htmlspecialchars($k['kanban_no']) ?>
                                                </span>
                                                <?php if ($k['plan_type'] === 'safety_stock'): ?>
                                                    <span class="px-1.5 py-0.2 rounded text-[8.5px] font-bold bg-purple-100 text-purple-800 border border-purple-200">
                                                        SS
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-[11px] text-slate-500 font-medium truncate max-w-[160px]" title="<?= htmlspecialchars($k['customer'] ?? 'PT. Indonesia Epson Industry') ?>">
                                                <?= htmlspecialchars($k['customer'] ?? 'PT. Indonesia Epson Industry') ?>
                                            </div>
                                            <div class="mt-1 flex items-center gap-1 flex-wrap">
                                                <?php 
                                                $importMethod = strtolower($k['import_method'] ?? 'manual');
                                                $isImport = ($importMethod === 'excel_import' || $importMethod === 'excel' || $importMethod === 'csv');
                                                $isFromYesterday = (!empty($k['created_at']) && date('Y-m-d', strtotime($k['created_at'])) < date('Y-m-d'));
                                                ?>
                                                <?php if ($isFromYesterday): ?>
                                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-extrabold bg-amber-100 text-amber-800 border border-amber-300 uppercase tracking-wider leading-none" title="Kanban sisa dari tanggal <?= date('d/m/Y', strtotime($k['created_at'])) ?>">
                                                        Kemarin
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($isImport): ?>
                                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-extrabold bg-teal-50 text-teal-700 border border-teal-200 uppercase tracking-wider leading-none" title="Dibuat melalui Import File Batch">
                                                        Import
                                                    </span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-extrabold bg-slate-100 text-slate-600 border border-slate-200 uppercase tracking-wider leading-none" title="Dibuat melalui Input Form Manual">
                                                        Manual
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>

                                        <!-- Part Code & Name -->
                                        <td class="px-3 py-3">
                                            <span class="font-mono font-extrabold text-blue-700 text-xs block">
                                                <?= htmlspecialchars($k['item_code']) ?>
                                            </span>
                                            <span class="text-[11px] text-slate-600 font-medium block truncate max-w-[210px]" title="<?= htmlspecialchars($k['display_part_name']) ?>">
                                                <?= htmlspecialchars($k['display_part_name']) ?>
                                            </span>
                                            <?php if ($is100Check): ?>
                                                <div class="mt-0.5">
                                                    <span class="px-1.5 py-0.2 rounded text-[8.5px] font-bold bg-amber-50 text-amber-800 border border-amber-200 inline-block leading-tight">
                                                        100% Cek
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Jadwal & ETA -->
                                        <td class="px-2.5 py-3 whitespace-nowrap">
                                            <div class="font-medium text-slate-700 text-[10px] flex items-center gap-1.5" title="Tanggal Request (Req Date)">
                                                <svg width="12" height="12" style="width: 12px; height: 12px; min-width: 12px; min-height: 12px;" class="text-slate-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                                <span class="<?= ($isOverdue && empty($k['eta'])) ? 'text-rose-700 font-bold' : '' ?>"><?= !empty($k['req_date']) ? date('d/m/Y H:i', strtotime($k['req_date'])) : '-' ?></span>
                                            </div>
                                            <?php if (!empty($k['eta'])): ?>
                                                <div class="text-[9.5px] font-semibold <?= $isOverdue ? 'text-rose-700 font-bold' : 'text-amber-700' ?> flex items-center gap-1.5 mt-0.5" title="Estimasi Kedatangan (ETA)">
                                                    <svg width="12" height="12" style="width: 12px; height: 12px; min-width: 12px; min-height: 12px;" class="<?= $isOverdue ? 'text-rose-500' : 'text-amber-500' ?> flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                                    <span><?= date('d/m/Y H:i', strtotime($k['eta'])) ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <?php 
                                                $isCreatedToday = (!empty($k['created_at']) && date('Y-m-d', strtotime($k['created_at'])) === date('Y-m-d'));
                                                $targetDeliveryDate = !empty($k['eta']) ? $k['eta'] : (!empty($k['req_date']) ? $k['req_date'] : null);
                                                $isDeliveryFuture = (!empty($targetDeliveryDate) && date('Y-m-d', strtotime($targetDeliveryDate)) > date('Y-m-d'));
                                            ?>
                                            <?php if ($isOverdue): ?>
                                                <?php
                                                    $schedTs = strtotime($schedDate);
                                                    $daysOver = max(1, (int)floor((strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', $schedTs))) / 86400));
                                                ?>
                                                <div class="mt-1 flex items-center gap-1 flex-wrap">
                                                    <span class="px-1.5 py-0.5 rounded text-[8.5px] font-extrabold bg-rose-100 text-rose-800 border border-rose-300 inline-flex items-center gap-1 shadow-2xs" title="Perhatian: Jadwal pengiriman/ETA sudah terlewat! Prioritas Utama!">
                                                        <span class="w-1.5 h-1.5 rounded-full bg-rose-600 animate-pulse"></span> OVERDUE
                                                    </span>
                                                    <span class="px-1.5 py-0.5 rounded text-[8.5px] font-bold bg-rose-50 text-rose-700 border border-rose-200 inline-block" title="Terlambat <?= $daysOver ?> hari dari target ETA">
                                                        Lewat <?= $daysOver ?> hari
                                                    </span>
                                                </div>
                                            <?php elseif ($isCreatedToday && $isDeliveryFuture): ?>
                                                <div class="mt-1 flex items-center gap-1 flex-wrap">
                                                    <span class="px-1.5 py-0.5 rounded text-[8.5px] font-extrabold bg-blue-50 text-blue-700 border border-blue-200 inline-flex items-center gap-1" title="Dibuat hari ini: <?= date('d/m/Y H:i', strtotime($k['created_at'])) ?>">
                                                        <span class="w-1.5 h-1.5 rounded-full bg-blue-500"></span> Dibuat Hari Ini
                                                    </span>
                                                    <span class="px-1.5 py-0.5 rounded text-[8.5px] font-extrabold bg-amber-50 text-amber-800 border border-amber-200 inline-block" title="Target Pengiriman">
                                                        Kirim: <?= (date('Y-m-d', strtotime($targetDeliveryDate)) === date('Y-m-d', strtotime('+1 day'))) ? 'Besok' : date('d/m', strtotime($targetDeliveryDate)) ?>
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Target & Progres Scan -->
                                        <td class="px-2 py-3 text-center whitespace-nowrap">
                                            <div id="progress-container-<?= $kid ?>">
                                                <div class="font-mono font-black text-xs text-slate-800">
                                                    <span class="passed-qty-val"><?= number_format($passedQty) ?></span> <span class="text-slate-400 font-normal">/</span> <?= number_format($targetQty) ?> <span class="text-[10px] text-slate-500 font-medium">pcs</span>
                                                </div>
                                                <?php
                                                    if ($isFullyPassed || $progressPct >= 100) {
                                                        $barBg = 'background: linear-gradient(90deg, #10b981 0%, #059669 100%);';
                                                        $barLabel = '100% selesai';
                                                        $labelClass = 'text-emerald-700 font-extrabold';
                                                        $barFillWidth = 100;
                                                    } elseif ($hasActiveSession) {
                                                        $barBg = 'background: linear-gradient(90deg, #f59e0b 0%, #d97706 100%);';
                                                        $barLabel = ($progressPct > 0) ? ($progressPct . '% berjalan') : 'Mulai berjalan';
                                                        $labelClass = 'text-amber-800 font-black';
                                                        $barFillWidth = max(8, $progressPct);
                                                    } elseif ($progressPct > 0) {
                                                        $barBg = 'background: linear-gradient(90deg, #f59e0b 0%, #d97706 100%);';
                                                        $barLabel = $progressPct . '% parsial';
                                                        $labelClass = 'text-amber-800 font-extrabold';
                                                        $barFillWidth = $progressPct;
                                                    } else {
                                                        $barBg = 'background-color: #cbd5e1;';
                                                        $barLabel = 'Belum mulai';
                                                        $labelClass = 'text-slate-400 font-medium';
                                                        $barFillWidth = 0;
                                                    }
                                                ?>
                                                <div class="w-20 rounded-full overflow-hidden mx-auto my-1 bg-slate-200" style="height: 7px; border: 1px solid #cbd5e1;">
                                                    <div class="progress-bar-fill" style="height: 100%; width: <?= $barFillWidth ?>%; <?= $barBg ?> border-radius: 9999px; transition: width 0.3s ease;"></div>
                                                </div>
                                                <span class="text-[10px] <?= $labelClass ?> block leading-none progress-label">
                                                    <?= $barLabel ?>
                                                </span>
                                            </div>
                                        </td>

                                        <!-- Status Kanban (Real-Time Inspector Aware) -->
                                        <td class="px-2.5 py-3 text-center whitespace-nowrap">
                                            <div id="status-container-<?= $kid ?>">
                                                <?php if ($hasActiveSession): 
                                                    $actInspName = htmlspecialchars($actSess['inspector_name']);
                                                    $actDur = (int)($actSess['duration_minutes'] ?? 0);
                                                ?>
                                                    <span style="background-color: #d97706; color: #ffffff; font-weight: 800; font-size: 10.5px; padding: 3.5px 11px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 3px rgba(217, 119, 6, 0.4);">
                                                        <span style="width: 6px; height: 6px; border-radius: 9999px; background-color: #ffffff; display: inline-block;" class="animate-pulse"></span>
                                                        Sedang Dikerjakan
                                                    </span>
                                                    <span class="block text-[9px] text-amber-800 font-bold mt-0.5 truncate max-w-[135px] mx-auto" title="Dikerjakan oleh <?= $actInspName ?> (sejak <?= $actDur ?> mnt lalu)">
                                                        Oleh: <?= $actInspName ?>
                                                    </span>
                                                <?php elseif ($k['status'] === 'completed' || $isFullyPassed): ?>
                                                    <span style="background-color: #16a34a; color: #ffffff; font-weight: 800; font-size: 11px; padding: 3.5px 11px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 3px rgba(22, 163, 74, 0.4);">
                                                        <svg style="width: 11px; height: 11px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                                        Selesai
                                                    </span>
                                                <?php elseif ($isPartial || $k['status'] === 'partial' || ($passedQty > 0 && $passedQty < $targetQty)): ?>
                                                    <div class="flex flex-col items-center gap-1">
                                                        <span style="background-color: #d97706; color: #ffffff; font-weight: 800; font-size: 11px; padding: 3px 10px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 3px rgba(217, 119, 6, 0.35);">
                                                            <svg style="width: 11px; height: 11px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                            Parsial
                                                        </span>
                                                        <?php if ($hasRejectedSession): ?>
                                                            <span style="background-color: #dc2626; color: #ffffff; font-weight: 800; font-size: 9.5px; padding: 2px 8px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 3px; box-shadow: 0 1px 2px rgba(220, 38, 38, 0.35);">
                                                                <span style="width: 5px; height: 5px; border-radius: 9999px; background-color: #ffffff; display: inline-block;" class="animate-pulse"></span>
                                                                Ada Reject
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php elseif ($k['status'] === 'rejected'): ?>
                                                    <span style="background-color: #dc2626; color: #ffffff; font-weight: 800; font-size: 10.5px; padding: 3.5px 11px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 3px rgba(220, 38, 38, 0.4);">
                                                        <svg style="width: 11px; height: 11px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"/></svg>
                                                        Reject
                                                    </span>
                                                <?php else: ?>
                                                    <span class="px-2.5 py-1 rounded-full text-[10.5px] font-bold bg-slate-100 text-slate-600 border border-slate-200 inline-flex items-center gap-1">
                                                        <span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span>
                                                        Belum Mulai
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>

                                        <!-- Aksi Kanban Row (Contextual: Pemilik vs Laptop Lain vs Mulai Baru) -->
                                        <td class="px-3 py-3 text-right whitespace-nowrap">
                                            <div class="inline-flex items-center space-x-1.5 justify-end">
                                                
                                                <div id="action-container-<?= $kid ?>">
                                                    <?php if ($hasActiveSession): 
                                                        $actSid = (int)$actSess['id'];
                                                        $actInspName = htmlspecialchars($actSess['inspector_name']);
                                                        if ($isOwnSession): ?>
                                                            <a href="<?= base_url('modules/inspection/session.php?id=' . $actSid) ?>" 
                                                               style="background-color: #d97706; color: #ffffff;"
                                                               class="px-3 py-1.5 bg-amber-600 hover:bg-amber-700 active:bg-amber-800 text-white font-bold text-xs rounded-md shadow-xs hover:shadow transition-all inline-flex items-center gap-1.5 whitespace-nowrap flex-nowrap"
                                                               title="Lanjutkan sesi inspeksi Anda">
                                                                <svg class="w-3 h-3 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor" style="fill: #ffffff;">
                                                                    <path d="M6.3 2.841A1.5 1.5 0 004 4.11V15.89a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z"/>
                                                                </svg>
                                                                <span>Lanjutkan</span>
                                                            </a>
                                                        <?php else: ?>
                                                            <a href="<?= base_url('modules/inspection/session.php?id=' . $actSid . '&view_mode=readonly') ?>" 
                                                               onclick="return confirmOpenSpectatorMode('<?= addslashes($actInspName) ?>')"
                                                               class="px-3 py-1.5 bg-slate-700 hover:bg-slate-800 text-white font-bold text-xs rounded-md shadow-xs hover:shadow transition-all inline-flex items-center gap-1.5 whitespace-nowrap flex-nowrap"
                                                               title="Buka sesi dalam Mode Pantau (Read-Only) tanpa mengganggu sesi yang sedang dikerjakan">
                                                                <svg class="w-3.5 h-3.5 text-amber-300 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                                                                <span>Lihat Sesi</span>
                                                            </a>
                                                        <?php endif; ?>
                                                    <?php elseif (!$isFullyPassed && $k['status'] !== 'completed'): ?>
                                                        <?php $remainingQuotaToClose = max(0, $targetQty - $passedQty); ?>
                                                        <div class="inline-flex items-center gap-2 flex-nowrap">
                                                            <?php if ($isAdminOrSupervisor): ?>
                                                                <!-- Tombol Close Khusus Admin / Supervisor: Langsung Modal Direct Close -->
                                                                <button type="button" 
                                                                        onclick="openDirectCloseModal(<?= $kid ?>, '<?= addslashes($k['kanban_no']) ?>', '<?= addslashes($k['item_code']) ?>', '<?= addslashes($k['display_part_name']) ?>', '<?= addslashes($k['customer'] ?? 'PT. Indonesia Epson Industry') ?>', <?= (int)$remainingQuotaToClose ?>, <?= (int)$targetQty ?>)"
                                                                        style="background-color: #ffffff; color: #475569; border: 1.5px solid #cbd5e1;"
                                                                        onmouseover="this.style.borderColor='#86efac'; this.style.backgroundColor='#f0fdf4'; this.style.color='#15803d';"
                                                                        onmouseout="this.style.borderColor='#cbd5e1'; this.style.backgroundColor='#ffffff'; this.style.color='#475569';"
                                                                        class="px-2.5 py-1.5 font-extrabold text-xs rounded-md shadow-2xs transition-all inline-flex items-center gap-1 whitespace-nowrap flex-nowrap cursor-pointer" 
                                                                        title="Selesaikan Kanban langsung (Admin Direct Close)">
                                                                    <svg class="w-3.5 h-3.5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                                                    <span>Close</span>
                                                                </button>
                                                            <?php else: ?>
                                                                <!-- Tombol Close Non-Admin (Operator/Inspector): Halaman Form Input Lot Fisik -->
                                                                <a href="<?= base_url('modules/inspection/close_kanban.php?kanban_id=' . $kid) ?>"
                                                                   style="background-color: #ffffff; color: #475569; border: 1.5px solid #cbd5e1;"
                                                                   onmouseover="this.style.borderColor='#86efac'; this.style.backgroundColor='#f0fdf4'; this.style.color='#15803d';"
                                                                   onmouseout="this.style.borderColor='#cbd5e1'; this.style.backgroundColor='#ffffff'; this.style.color='#475569';"
                                                                   class="px-2.5 py-1.5 font-extrabold text-xs rounded-md shadow-2xs transition-all inline-flex items-center gap-1 whitespace-nowrap flex-nowrap cursor-pointer" 
                                                                   title="Tutup Kanban dengan input Lot fisik / scan">
                                                                    <svg class="w-3.5 h-3.5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                                                    <span>Close</span>
                                                                </a>
                                                            <?php endif; ?>

                                                            <a href="<?= base_url('modules/inspection/session.php?scan_new=1&kanban_id=' . $kid) ?>" 
                                                               style="background-color: #2563eb; color: #ffffff;"
                                                               class="px-3.5 py-1.5 hover:bg-blue-700 active:bg-blue-800 text-white font-extrabold text-xs rounded-md shadow-xs hover:shadow transition-all inline-flex items-center gap-1.5 whitespace-nowrap flex-nowrap" 
                                                               title="Mulai inspeksi pengerjaan Kanban ini">
                                                                <svg class="w-3 h-3 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor" style="fill: #ffffff;">
                                                                    <path d="M6.3 2.841A1.5 1.5 0 004 4.11V15.89a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z"/>
                                                                </svg>
                                                                <span>Inspeksi</span>
                                                            </a>
                                                        </div>
                                                    <?php else: ?>
                                                        <!-- Selesai (tanpa label redundant) -->
                                                    <?php endif; ?>
                                                </div>

                                                <!-- Tombol Accordion Detail Sesi -->
                                                <button type="button" onclick="toggleKanbanDetail(<?= $kid ?>)" 
                                                        class="px-2 py-1 bg-slate-100 hover:bg-slate-200 active:bg-slate-300 text-slate-700 font-bold text-[11px] rounded-lg border border-slate-200 transition-all inline-flex items-center gap-0.5 cursor-pointer"
                                                        title="Buka / Tutup Rincian Sesi">
                                                    <span>Detail</span>
                                                    <span class="text-[10px] font-bold text-slate-500">(<?= $totalSessions ?>)</span>
                                                    <svg id="chevron-icon-<?= $kid ?>" class="w-3 h-3 transition-transform duration-200 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                                    </svg>
                                                </button>

                                            </div>
                                        </td>
                                    </tr>

                                    <!-- ═══════════════════════════════════════════════════════════ -->
                                    <!-- EXPANDABLE SUB-ROW: DETAIL SESI PER KANBAN (Mulai Sesi 1) -->
                                    <!-- ═══════════════════════════════════════════════════════════ -->
                                    <tr id="kanban-detail-<?= $kid ?>" class="hidden bg-slate-50/70 border-b border-slate-200">
                                        <td colspan="7" class="p-2.5 sm:p-3">
                                            <div class="bg-white border border-slate-200 rounded-lg p-3 shadow-2xs space-y-2.5">
                                                
                                                <div class="flex items-center justify-between border-b border-slate-100 pb-1.5">
                                                    <div class="flex items-center space-x-1.5">
                                                        <span class="w-2 h-2 rounded-full bg-blue-600"></span>
                                                        <h5 class="font-extrabold text-[11px] text-slate-900 uppercase tracking-wider">
                                                            Riwayat Sesi Kanban #<?= htmlspecialchars($k['kanban_no']) ?>
                                                        </h5>
                                                        <span class="text-[10.5px] text-slate-400 font-medium">
                                                            (Total <?= $totalSessions ?> Sesi)
                                                        </span>
                                                    </div>
                                                    <span class="text-[11px] font-semibold text-slate-500">
                                                        Target: <b class="text-slate-800"><?= number_format($targetQty) ?> pcs</b>
                                                    </span>
                                                </div>

                                                <?php if (empty($kSessions)): ?>
                                                    <div class="py-4 text-center text-slate-400 text-xs">
                                                        <p class="font-semibold text-slate-600">Belum ada sesi inspeksi untuk Kanban ini.</p>
                                                        <p class="text-[11px] text-slate-400 mt-0.5">Klik tombol <b>Mulai Inspeksi</b> di atas untuk memulai pemeriksaan pertama.</p>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="w-full overflow-x-auto">
                                                        <table class="w-full text-left text-xs table-fixed" style="border-collapse: collapse; min-width: 560px;">
                                                            <colgroup>
                                                                <col style="width: 64px">
                                                                <col style="width: 26%">
                                                                <col style="width: 18%">
                                                                <col style="width: 22%">
                                                                <col style="width: 12%">
                                                                <col>
                                                            </colgroup>
                                                            <thead class="bg-slate-50 text-slate-500 font-bold border-b border-slate-200 uppercase text-[9px]">
                                                                <tr>
                                                                    <th class="px-2.5 py-1.5 text-center">Sesi</th>
                                                                    <th class="px-2.5 py-1.5">Waktu & Shift</th>
                                                                    <th class="px-2.5 py-1.5">Inspektur</th>
                                                                    <th class="px-2.5 py-1.5 text-center">Qty & Lot</th>
                                                                    <th class="px-2.5 py-1.5 text-center">Status</th>
                                                                    <th class="px-2.5 py-1.5 text-right">Aksi</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody class="divide-y divide-slate-100">
                                                                <?php foreach ($kSessions as $sIdx => $s): 
                                                                    $sid = (int)$s['id'];
                                                                    $sessionNumber = $sIdx + 1;
                                                                    $sLots = $lotsBySession[$sid] ?? [];
                                                                    $shiftName = getShiftName($s['started_at']);
                                                                    $isClosed = !empty($s['closed_at']);
                                                                    $durationText = $isClosed ? (($s['duration_minutes'] > 0) ? ($s['duration_minutes'] . ' mnt') : '< 1 mnt') : 'Berjalan';
                                                                    $isReinsp = !empty($s['is_reinspection']);

                                                                    $subRowClass = 'sub-row-passed';
                                                                    if ($s['status'] === 'rejected') {
                                                                        $subRowClass = 'sub-row-rejected';
                                                                    } elseif ($s['status'] === 'in_progress') {
                                                                        $subRowClass = 'sub-row-progress';
                                                                    }
                                                                ?>
                                                                    <tr class="transition-colors <?= $subRowClass ?>" style="vertical-align: middle;">
                                                                        
                                                                        <!-- Sesi -->
                                                                        <td class="px-2.5 py-2 text-center whitespace-nowrap">
                                                                            <span class="font-extrabold text-blue-700 text-xs">Sesi <?= $sessionNumber ?></span>
                                                                            <?php if ($isReinsp): ?>
                                                                                <span class="block text-[8px] font-extrabold text-amber-700 bg-amber-100 rounded px-1 mt-0.5">RE-INSP</span>
                                                                            <?php endif; ?>
                                                                        </td>

                                                                        <!-- Waktu & Shift (Merged) -->
                                                                        <td class="px-2.5 py-2">
                                                                            <div class="font-bold text-slate-800 text-[11px] leading-tight">
                                                                                <?= date('d M Y, H:i', strtotime($s['started_at'])) ?><?php if (!empty($s['closed_at'])): ?> &ndash; <?= date('H:i', strtotime($s['closed_at'])) ?><?php endif; ?>
                                                                            </div>
                                                                            <div class="text-[9px] text-slate-400 mt-0.5"><?= htmlspecialchars($shiftName) ?> &middot; <?= $durationText ?></div>
                                                                        </td>

                                                                        <!-- Inspektur -->
                                                                        <td class="px-2.5 py-2 font-semibold text-slate-800 text-[11px] truncate" title="<?= htmlspecialchars($s['inspector_name']) ?>">
                                                                            <?= htmlspecialchars($s['inspector_name']) ?>
                                                                        </td>

                                                                        <!-- Qty & Lot -->
                                                                        <td class="px-2.5 py-2 text-center">
                                                                            <div class="font-mono font-black text-slate-900 text-xs"><?= number_format($s['total_scanned_qty']) ?> pcs</div>
                                                                            <div class="text-[9px] text-slate-400 font-mono">Sampel <?= $s['samples_checked'] ?>/<?= $s['sample_size'] ?></div>
                                                                            <?php if (!empty($sLots)): 
                                                                                $passedLotsCount = 0;
                                                                                $passedLotsQty = 0;
                                                                                $rejectedLotsCount = 0;
                                                                                $rejectedLotsQty = 0;
                                                                                $inProgLotsCount = 0;
                                                                                $inProgLotsQty = 0;
                                                                                $lotDetails = [];

                                                                                foreach ($sLots as $lIdx => $lItem) {
                                                                                    $lr = $lItem['lot_result'] ?? '';
                                                                                    $ls = $lItem['lot_status'] ?? 'ok';
                                                                                    $lQty = (int)($lItem['qty'] ?? 0);
                                                                                    $lNo = $lItem['lot_number'] ?? '-';
                                                                                    $lRef = $lItem['ref_number'] ?? ($lIdx + 1);

                                                                                    if ($lr === 'passed' && $ls !== 'replaced') {
                                                                                        $passedLotsCount++;
                                                                                        $passedLotsQty += $lQty;
                                                                                    } elseif ($lr === 'rejected' || $ls === 'rejected' || $ls === 'ng_found') {
                                                                                        $rejectedLotsCount++;
                                                                                        $rejectedLotsQty += $lQty;
                                                                                    } elseif ($lr === 'in_progress') {
                                                                                        $inProgLotsCount++;
                                                                                        $inProgLotsQty += $lQty;
                                                                                    }
                                                                                    $lRes = strtoupper($lr ?: $ls);
                                                                                    $lotDetails[] = "Ref #{$lRef}: Lot {$lNo} ({$lQty} pcs) - {$lRes}";
                                                                                }
                                                                                $lotTooltip = implode("\n", $lotDetails);
                                                                            ?>
                                                                                <div class="mt-1 flex flex-wrap items-center justify-center gap-1" title="<?= htmlspecialchars($lotTooltip) ?>">
                                                                                    <?php if ($passedLotsCount > 0): ?>
                                                                                        <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[9.5px] font-extrabold border border-emerald-300 bg-emerald-50 text-emerald-700 shadow-2xs">
                                                                                            <span class="text-[9px]">✓</span> <?= $passedLotsCount ?> Pass (<?= number_format($passedLotsQty) ?> pcs)
                                                                                        </span>
                                                                                    <?php endif; ?>
                                                                                    <?php if ($rejectedLotsCount > 0): ?>
                                                                                        <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[9.5px] font-extrabold border border-rose-300 bg-rose-50 text-rose-700 shadow-2xs">
                                                                                            <span class="text-[9px]">✕</span> <?= $rejectedLotsCount ?> NG (<?= number_format($rejectedLotsQty) ?> pcs)
                                                                                        </span>
                                                                                    <?php endif; ?>
                                                                                    <?php if ($inProgLotsCount > 0): ?>
                                                                                        <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[9.5px] font-extrabold border border-amber-300 bg-amber-50 text-amber-700 shadow-2xs">
                                                                                            <svg class="w-2.5 h-2.5 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg> <?= $inProgLotsCount ?> Draft
                                                                                        </span>
                                                                                    <?php endif; ?>
                                                                                </div>
                                                                            <?php endif; ?>
                                                                        </td>

                                                                        <!-- Status -->
                                                                        <td class="px-2.5 py-2 text-center whitespace-nowrap">
                                                                            <?php if ($s['status'] === 'passed'): ?>
                                                                                <span style="background-color: #16a34a; color: #ffffff; font-weight: 800; font-size: 10px; padding: 2.5px 9px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 3.5px; box-shadow: 0 1px 3px rgba(22, 163, 74, 0.4);">
                                                                                    <svg style="width: 10px; height: 10px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                                                                    Passed
                                                                                </span>
                                                                            <?php elseif ($s['status'] === 'rejected'): ?>
                                                                                <span style="background-color: #dc2626; color: #ffffff; font-weight: 800; font-size: 10px; padding: 2.5px 9px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 3.5px; box-shadow: 0 1px 3px rgba(220, 38, 38, 0.4);">
                                                                                    <svg style="width: 10px; height: 10px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"/></svg>
                                                                                    Rejected
                                                                                </span>
                                                                            <?php else: ?>
                                                                                <span style="background-color: #d97706; color: #ffffff; font-weight: 800; font-size: 10px; padding: 2.5px 9px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 3.5px; box-shadow: 0 1px 3px rgba(217, 119, 6, 0.4);">
                                                                                    <span style="width: 6px; height: 6px; border-radius: 9999px; background-color: #ffffff; display: inline-block;" class="animate-pulse"></span>
                                                                                    In Progress
                                                                                </span>
                                                                            <?php endif; ?>
                                                                        </td>

                                                                        <!-- Aksi -->
                                                                        <td class="px-2.5 py-2 text-right whitespace-nowrap">
                                                                            <div class="inline-flex items-center gap-1 justify-end">
                                                                                <?php if ($s['status'] === 'in_progress'): ?>
                                                                                    <a href="<?= base_url('modules/inspection/session.php?id=' . $sid) ?>"
                                                                                       style="background-color: #d97706; color: #ffffff;"
                                                                                       class="px-2.5 py-1 bg-amber-600 hover:bg-amber-700 text-white font-bold text-[10.5px] rounded shadow-xs transition-all inline-flex items-center gap-1">
                                                                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path></svg>
                                                                                        Lanjutkan
                                                                                    </a>
                                                                                <?php else: ?>
                                                                                    <a href="<?= base_url('modules/inspection/session.php?id=' . $sid) ?>"
                                                                                       class="px-2 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-[10.5px] rounded border border-slate-200 transition-all inline-flex items-center gap-1">
                                                                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                                                                                        Detail
                                                                                    </a>
                                                                                    <?php if ($s['status'] === 'rejected'): ?>
                                                                                        <a href="<?= base_url('modules/inspection/print_rejection.php?session_id=' . $sid) ?>"
                                                                                           target="_blank" rel="noopener"
                                                                                           class="px-2 py-1 bg-rose-50 hover:bg-rose-100 text-rose-700 font-bold text-[10.5px] rounded border border-rose-200 transition-all inline-flex items-center gap-1">
                                                                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
                                                                                            Cetak
                                                                                        </a>
                                                                                    <?php endif; ?>
                                                                                <?php endif; ?>
                                                                            </div>
                                                                        </td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                <?php endif; ?>

                                            </div>
                                        </td>
                                    </tr>

                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            <!-- ═══════════════════════════════════════════════════════════════ -->
            <!-- PAGINATION BAR                                                -->
            <!-- ═══════════════════════════════════════════════════════════════ -->
            <?php if ($totalPages > 1): ?>
                <div class="px-4 py-2.5 bg-slate-50 border-t border-slate-200 flex items-center justify-between text-xs text-slate-600 flex-wrap gap-2">
                    <div>
                        Menampilkan <b><?= min($totalItems, $offset + 1) ?></b> s/d <b><?= min($totalItems, $offset + ($activeTab === 'safety_stock' ? count($ssKanbans) : ($activeTab === 'my_inspections' ? count($mySessions) : ($activeTab === 'my_lots' ? count($myLots) : count($kanbans))))) ?></b> dari total <b><?= $totalItems ?></b> <?= ($activeTab === 'safety_stock') ? 'Item Safety Stock' : (($activeTab === 'my_inspections') ? 'Sesi Riwayat Saya' : (($activeTab === 'my_lots') ? 'Lot / Box Saya' : 'Kanban')) ?>
                    </div>
                    <div class="inline-flex space-x-1">
                        <?php 
                        $queryParams = $_GET;
                        ?>
                        <?php if ($page > 1): 
                            $queryParams['page'] = $page - 1;
                        ?>
                            <a href="?<?= http_build_query($queryParams) ?>" class="px-2 py-0.5 bg-white border border-slate-200 rounded hover:bg-slate-100 font-bold text-[11px]">&laquo; Prev</a>
                        <?php endif; ?>

                        <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): 
                            $queryParams['page'] = $p;
                        ?>
                            <a href="?<?= http_build_query($queryParams) ?>" class="px-2 py-0.5 rounded font-bold text-[11px] <?= ($p === $page) ? ($activeTab === 'safety_stock' ? 'bg-purple-600 text-white' : ($activeTab === 'my_inspections' ? 'bg-indigo-600 text-white' : ($activeTab === 'my_lots' ? 'bg-sky-600 text-white' : 'bg-blue-600 text-white'))) : 'bg-white border border-slate-200 hover:bg-slate-100 text-slate-700' ?>">
                                <?= $p ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): 
                            $queryParams['page'] = $page + 1;
                        ?>
                            <a href="?<?= http_build_query($queryParams) ?>" class="px-2 py-0.5 bg-white border border-slate-200 rounded hover:bg-slate-100 font-bold text-[11px]">Next &raquo;</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
