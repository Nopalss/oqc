<style>
        /* ── KPI Highlight Cards (Compact & Single Row) ── */
        .kpi-grid-container {
            display: grid !important;
            grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
            gap: 8px !important;
            margin-top: 2px !important;
            margin-bottom: 2px !important;
        }
        .kpi-grid-3cols {
            display: grid !important;
            grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
            gap: 8px !important;
            margin-top: 2px !important;
            margin-bottom: 2px !important;
        }
        .kpi-grid-5cols {
            display: grid !important;
            grid-template-columns: repeat(5, minmax(0, 1fr)) !important;
            gap: 8px !important;
            margin-top: 2px !important;
            margin-bottom: 2px !important;
        }
        .kpi-grid-6cols {
            display: grid !important;
            grid-template-columns: repeat(6, minmax(0, 1fr)) !important;
            gap: 8px !important;
            margin-top: 2px !important;
            margin-bottom: 2px !important;
        }
        @media (max-width: 1280px) {
            .kpi-grid-6cols {
                grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
            }
        }
        @media (max-width: 1024px) {
            .kpi-grid-5cols {
                grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)) !important;
            }
        }
        @media (max-width: 640px) {
            .kpi-grid-6cols {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            }
        }
        .kpi-card {
            border-radius: 8px !important;
            padding: 8px 12px !important;
            display: block !important;
            transition: all 0.2s ease !important;
            box-shadow: 0 1px 2px rgba(0,0,0,0.04) !important;
            text-decoration: none !important;
        }
        .kpi-card:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.06) !important;
        }
        .kpi-card-total {
            background: linear-gradient(135deg, #e0f2fe 0%, #dbeafe 100%) !important;
            border: 1px solid #93c5fd !important;
            border-left: 4.5px solid #2563eb !important;
        }
        .kpi-card-uninspected {
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%) !important;
            border: 1px solid #cbd5e1 !important;
            border-left: 4.5px solid #64748b !important;
        }
        .kpi-card-progress {
            background: linear-gradient(135deg, #fef3c7 0%, #ffedd5 100%) !important;
            border: 1px solid #fcd34d !important;
            border-left: 4.5px solid #f59e0b !important;
        }
        .kpi-card-completed {
            background: linear-gradient(135deg, #dcfce7 0%, #d1fae5 100%) !important;
            border: 1px solid #86efac !important;
            border-left: 4.5px solid #10b981 !important;
        }
        .kpi-card-remain {
            background: linear-gradient(135deg, #ede9fe 0%, #e0e7ff 100%) !important;
            border: 1px solid #c7d2fe !important;
            border-left: 4.5px solid #6366f1 !important;
        }
        .kpi-card-rejected {
            background: linear-gradient(135deg, #ffe4e6 0%, #fecdd3 100%) !important;
            border: 1px solid #fda4af !important;
            border-left: 4.5px solid #e11d48 !important;
        }
        .kpi-card-ss {
            background: linear-gradient(135deg, #f3e8ff 0%, #ede9fe 100%) !important;
            border: 1px solid #d8b4fe !important;
            border-left: 4.5px solid #9333ea !important;
        }

        /* ── Workboard Table Row Status Colors ── */
        .row-status-overdue > td {
            background-color: #fff1f2 !important;
        }
        .row-status-overdue:hover > td {
            background-color: #ffe4e6 !important;
        }
        .row-status-overdue > td:first-child {
            border-left: 5px solid #e11d48 !important;
        }

        .row-status-active > td {
            background-color: #fffbeb !important;
        }
        .row-status-active:hover > td {
            background-color: #fef3c7 !important;
        }
        .row-status-active > td:first-child {
            border-left: 5px solid #d97706 !important;
        }

        .row-status-partial > td {
            background-color: #fef3c7 !important;
        }
        .row-status-partial:hover > td {
            background-color: #fde68a !important;
        }
        .row-status-partial > td:first-child {
            border-left: 5px solid #f59e0b !important;
        }

        .row-status-completed > td {
            background-color: #dcfce7 !important;
        }
        .row-status-completed:hover > td {
            background-color: #bbf7d0 !important;
        }
        .row-status-completed > td:first-child {
            border-left: 5px solid #10b981 !important;
        }

        .row-status-rejected > td {
            background-color: #fff1f2 !important;
        }
        .row-status-rejected:hover > td {
            background-color: #ffe4e6 !important;
        }
        .row-status-rejected > td:first-child {
            border-left: 5px solid #be123c !important;
        }

        .row-status-uninspected > td {
            background-color: #ffffff !important;
        }
        .row-status-uninspected:hover > td {
            background-color: #f8fafc !important;
        }
        .row-status-uninspected > td:first-child {
            border-left: 5px solid #cbd5e1 !important;
        }

        /* ── Sub-Table Session Row Status Colors (Accordion Detail Sesi) ── */
        .sub-row-passed > td {
            background-color: #f0fdf4 !important;
        }
        .sub-row-passed:hover > td {
            background-color: #dcfce7 !important;
        }
        .sub-row-passed > td:first-child {
            border-left: 4px solid #16a34a !important;
        }

        .sub-row-progress > td {
            background-color: #fffbeb !important;
        }
        .sub-row-progress:hover > td {
            background-color: #fef3c7 !important;
        }
        .sub-row-progress > td:first-child {
            border-left: 4px solid #d97706 !important;
        }

        .sub-row-rejected > td {
            background-color: #fff1f2 !important;
        }
        .sub-row-rejected:hover > td {
            background-color: #ffe4e6 !important;
        }
        .sub-row-rejected > td:first-child {
            border-left: 4px solid #dc2626 !important;
        }

        /* KPI numbers and label spacing */
        .kpi-stat-value {
            font-size: 20px !important;
            font-weight: 900 !important;
            line-height: 1 !important;
            display: inline-block !important;
        }
        .kpi-stat-unit {
            font-size: 11px !important;
            font-weight: 700 !important;
            margin-left: 4px !important;
            display: inline-block !important;
        }
        .kpi-card-my {
            background: linear-gradient(135deg, #e0e7ff 0%, #ede9fe 100%) !important;
            border: 1px solid #c7d2fe !important;
            border-left: 4.5px solid #4f46e5 !important;
        }

        /* ── Theme Background Fallback for Offline Tailwind (Purple & Indigo) ── */
        .bg-purple-600 {
            background-color: #9333ea !important;
        }
        .bg-purple-700 {
            background-color: #7e22ce !important;
        }
        .hover\:bg-purple-700:hover {
            background-color: #7e22ce !important;
        }
        .active\:bg-purple-800:active {
            background-color: #6b21a8 !important;
        }
        .bg-indigo-600 {
            background-color: #4f46e5 !important;
        }
        .bg-indigo-700 {
            background-color: #4338ca !important;
        }
        .hover\:bg-indigo-700:hover {
            background-color: #4338ca !important;
        }
        .active\:bg-indigo-800:active {
            background-color: #3730a3 !important;
        }

        /* ── My Lots Horizontal Scroll Styling ── */
        #my-lots-table-wrapper {
            scrollbar-width: thin;
            scrollbar-color: #94a3b8 #f1f5f9;
        }
        #my-lots-table-wrapper::-webkit-scrollbar {
            height: 10px;
        }
        #my-lots-table-wrapper::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 6px;
        }
        #my-lots-table-wrapper::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 6px;
            border: 2px solid #f1f5f9;
        }
        #my-lots-table-wrapper::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        /* ── Segmented Control Pill Tabs ── */
        .oqc-tab-container {
            display: inline-flex;
            align-items: center;
            background-color: #f1f5f9;
            padding: 4px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            gap: 4px;
            flex-wrap: wrap;
        }
        .oqc-tab-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 14px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 800;
            text-decoration: none;
            transition: all 0.15s ease-in-out;
            cursor: pointer;
            border: none;
            outline: none;
            line-height: 1.25;
            user-select: none;
        }
        .oqc-tab-btn.inactive {
            background-color: transparent;
            color: #64748b;
        }
        .oqc-tab-btn.inactive:hover {
            background-color: #e2e8f0;
            color: #0f172a;
        }
        .oqc-tab-btn.inactive .oqc-tab-badge {
            background-color: #e2e8f0;
            color: #475569;
        }
        .oqc-tab-btn.inactive:hover .oqc-tab-badge {
            background-color: #cbd5e1;
            color: #0f172a;
        }
        .oqc-tab-btn.active-kanban {
            background-color: #2563eb;
            color: #ffffff;
            box-shadow: 0 1px 3px rgba(37, 99, 235, 0.35);
        }
        .oqc-tab-btn.active-safety {
            background-color: #7c3aed;
            color: #ffffff;
            box-shadow: 0 1px 3px rgba(124, 58, 237, 0.35);
        }
        .oqc-tab-btn.active-mylots {
            background-color: #4f46e5;
            color: #ffffff;
            box-shadow: 0 1px 3px rgba(79, 70, 229, 0.35);
        }
        .oqc-tab-badge {
            padding: 2px 7.5px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 800;
            line-height: 1.2;
            transition: all 0.15s ease-in-out;
        }
        .oqc-tab-btn[class*="active-"] .oqc-tab-badge {
            background-color: rgba(255, 255, 255, 0.22);
            color: #ffffff;
        }

        /* ── Preset Period Buttons ── */
        .oqc-period-container {
            display: inline-flex;
            align-items: center;
            background-color: #f1f5f9;
            padding: 4px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            gap: 3px;
            flex-shrink: 0;
        }
        .oqc-period-btn {
            padding: 5px 12px;
            border-radius: 7px;
            font-size: 12px;
            font-weight: 800;
            cursor: pointer;
            border: none;
            outline: none;
            transition: all 0.15s ease-in-out;
            line-height: 1.25;
            user-select: none;
        }
        .oqc-period-btn.inactive {
            background-color: transparent;
            color: #64748b;
        }
        .oqc-period-btn.inactive:hover {
            background-color: #e2e8f0;
            color: #0f172a;
        }

        /* ── Lot Row Status Backgrounds & Accents ── */
        tr.lot-row-passed {
            background-color: #f0fdf4 !important;
            border-left: 3.5px solid #22c55e !important;
            transition: background-color 0.15s ease-in-out;
        }
        tr.lot-row-passed:hover {
            background-color: #dcfce7 !important;
        }
        tr.lot-row-rejected {
            background-color: #fff1f2 !important;
            border-left: 3.5px solid #ef4444 !important;
            transition: background-color 0.15s ease-in-out;
        }
        tr.lot-row-rejected:hover {
            background-color: #ffe4e6 !important;
        }
        tr.lot-row-in_progress {
            background-color: #fffbeb !important;
            border-left: 3.5px solid #f59e0b !important;
            transition: background-color 0.15s ease-in-out;
        }
        tr.lot-row-in_progress:hover {
            background-color: #fef3c7 !important;
        }

        /* ── Excel-Style Column Header Filters (100% Solid & High-Contrast) ── */
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
            width: 20px;
            height: 20px;
            border-radius: 4px;
            color: #334155 !important;
            cursor: pointer;
            transition: all 0.15s ease;
            background-color: #ffffff !important;
            border: 1.5px solid #cbd5e1 !important;
            flex-shrink: 0;
            padding: 0;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.08);
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
            top: calc(100% + 5px);
            z-index: 999999 !important;
            background-color: #ffffff !important;
            border: 1.5px solid #94a3b8 !important;
            border-radius: 10px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.25), 0 8px 10px -6px rgba(0, 0, 0, 0.2) !important;
            width: 250px;
            padding: 10px;
            text-transform: none;
            letter-spacing: normal;
            font-weight: normal;
            color: #0f172a;
            display: none;
            text-align: left;
        }
        th.ss-filter-th-active {
            z-index: 99999 !important;
            position: relative !important;
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
            padding: 6px 10px;
            border: 1.5px solid #cbd5e1;
            border-radius: 6px;
            font-size: 11px;
            outline: none;
            margin-bottom: 8px;
            background-color: #ffffff;
            color: #0f172a;
        }
        .excel-filter-search:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 2px #bfdbfe;
        }
        /* Tombol Cepat: Pilih Semua & Hapus Pilihan (Solid Pill Buttons) */
        .excel-quick-btn {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3.5px 8px;
            border-radius: 5px;
            font-size: 10.5px;
            font-weight: 800;
            cursor: pointer;
            border: 1px solid #cbd5e1;
            background-color: #f1f5f9;
            color: #1e293b;
            line-height: 1.2;
            transition: all 0.15s ease;
        }
        .excel-quick-btn:hover {
            background-color: #e2e8f0;
            border-color: #94a3b8;
            color: #0f172a;
        }
        /* Tombol Reset Header Mini (Solid Red Pill) */
        .excel-reset-btn {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 2.5px 7px;
            border-radius: 5px;
            font-size: 10px;
            font-weight: 800;
            cursor: pointer;
            border: 1px solid #fecdd3;
            background-color: #ffe4e6;
            color: #9f1239;
            line-height: 1.2;
            transition: all 0.15s ease;
        }
        .excel-reset-btn:hover {
            background-color: #fecdd3;
            border-color: #fda4af;
            color: #881337;
        }
        .excel-filter-list {
            max-height: 170px;
            overflow-y: auto;
            border: 1.5px solid #e2e8f0;
            background-color: #f8fafc;
            border-radius: 6px;
            padding: 4px;
            font-size: 11px;
        }
        .excel-filter-item {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 4px 6px;
            border-radius: 4px;
            cursor: pointer;
            user-select: none;
            font-weight: 600;
            color: #1e293b;
        }
        .excel-filter-item:hover {
            background-color: #e2e8f0;
        }
        .excel-filter-item input[type="checkbox"] {
            cursor: pointer;
            width: 14px;
            height: 14px;
            border-radius: 3px;
            accent-color: #2563eb;
        }
        .excel-filter-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 10px;
            padding-top: 8px;
            border-top: 1.5px solid #f1f5f9;
        }
        /* Tombol Footer: Tutup (Solid Grey Button) & Terapkan (Solid Blue Button) */
        .excel-btn-close {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 5px 12px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 800;
            cursor: pointer;
            border: 1.5px solid #cbd5e1;
            background-color: #f1f5f9;
            color: #334155;
            transition: all 0.15s ease;
        }
        .excel-btn-close:hover {
            background-color: #e2e8f0;
            border-color: #94a3b8;
            color: #0f172a;
        }
        .excel-btn-apply {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            justify-content: center;
            padding: 5px 14px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 800;
            cursor: pointer;
            border: 1.5px solid #1d4ed8;
            background-color: #2563eb;
            color: #ffffff;
            box-shadow: 0 1px 2px rgba(37, 99, 235, 0.4);
            transition: all 0.15s ease;
        }
        .excel-btn-apply:hover {
            background-color: #1d4ed8;
            border-color: #1e40af;
        }
        .excel-btn-apply:active {
            transform: scale(0.97);
        }
        </style>

        <?php
        $tabThemeBg = '#2563eb';
        $tabThemeBorder = '#1d4ed8';
        $tabFocusRing = 'focus:ring-blue-500';
        if ($activeTab === 'safety_stock') {
            $tabThemeBg = '#7c3aed';
            $tabThemeBorder = '#6d28d9';
            $tabFocusRing = 'focus:ring-purple-500';
        } elseif ($activeTab === 'my_lots') {
            $tabThemeBg = '#4f46e5';
            $tabThemeBorder = '#4338ca';
            $tabFocusRing = 'focus:ring-indigo-500';
        }
        ?>

        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <!-- TAB SWITCHER: KANBAN DELIVERY vs SAFETY STOCK vs RIWAYAT SAYA      -->
        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <div class="flex items-center justify-between gap-3 border-b border-slate-200 pb-3">
            <div class="oqc-tab-container">
                <!-- Tab 1: Kanban Delivery (Biru Khas) -->
                <?php $isKanban = ($activeTab === 'kanban'); ?>
                <a href="?tab=kanban&period=<?= urlencode($period) ?>" 
                   class="oqc-tab-btn <?= $isKanban ? 'active-kanban' : 'inactive' ?>">
                    <svg class="w-4 h-4 <?= $isKanban ? 'text-white' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path>
                    </svg>
                    <span>Kanban Delivery</span>
                    <span class="oqc-tab-badge">
                        <?= number_format($kanbanTabCount) ?>
                    </span>
                </a>

                <!-- Tab 2: Safety Stock (Ungu Khas) -->
                <?php $isSafety = ($activeTab === 'safety_stock'); ?>
                <a href="?tab=safety_stock&period=<?= urlencode($period) ?>" 
                   class="oqc-tab-btn <?= $isSafety ? 'active-safety' : 'inactive' ?>">
                    <svg class="w-4 h-4 <?= $isSafety ? 'text-white' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                    </svg>
                    <span>Safety Stock (Cek Fisik)</span>
                    <span class="oqc-tab-badge">
                        <?= number_format($safetyStockTabCount) ?>
                    </span>
                </a>

                <!-- Tab 3: Riwayat Saya (Indigo Khas) -->
                <?php $isMyLots = ($activeTab === 'my_lots'); ?>
                <a href="?tab=my_lots&period=<?= urlencode($period) ?>" 
                   class="oqc-tab-btn <?= $isMyLots ? 'active-mylots' : 'inactive' ?>">
                    <svg class="w-4 h-4 <?= $isMyLots ? 'text-white' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                    </svg>
                    <span>Riwayat Saya</span>
                    <span class="oqc-tab-badge">
                        <?= number_format($myLotsTabCount) ?>
                    </span>
                </a>
            </div>

            <div class="hidden sm:flex items-center gap-1.5 text-[11px] text-slate-500 font-medium">
                <?php if ($activeTab === 'safety_stock'): ?>
                    <span class="w-2 h-2 rounded-full bg-purple-500"></span>
                    <span>Hanya pemeriksaan fisik gudang & AQL (tanpa sisa box/overflow)</span>
                <?php elseif ($activeTab === 'my_inspections'): ?>
                    <span class="w-2 h-2 rounded-full bg-indigo-500"></span>
                    <span>Riwayat seluruh sesi inspeksi oleh <?= htmlspecialchars($currentUser['name'] ?? 'Inspektur') ?></span>
                <?php elseif ($activeTab === 'my_lots'): ?>
                    <span class="w-2 h-2 rounded-full" style="background-color: #4f46e5;"></span>
                    <span>Daftar seluruh rincian box / lot yang diinspeksi oleh <?= htmlspecialchars($currentUser['name'] ?? 'Inspektur') ?> (Sheet 1 View)</span>
                <?php else: ?>
                    <span class="w-2 h-2 rounded-full bg-blue-500"></span>
                    <span>Jadwal pengiriman Kanban & Workboard harian</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <!-- TOOLBAR: PRESET PERIODE + SEARCH + FILTER + TOMBOL MULAI           -->
        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <div class="bg-white border border-slate-200/90 rounded-xl p-3 shadow-xs space-y-2.5">
            <!-- Baris 1: Preset Periode + Tombol Mulai Inspeksi Baru -->
            <div class="flex items-center justify-between gap-2.5 flex-wrap">
                <!-- Period Preset Pills -->
                <div class="oqc-period-container">
                    <?php
                    $periodButtons = [
                        'today' => 'Hari Ini',
                        'this_week' => 'Minggu Ini',
                        'this_month' => 'Bulan Ini',
                        'all' => 'Semua'
                    ];
                    foreach ($periodButtons as $pKey => $pLabel):
                        $isActivePeriod = ($period === $pKey);
                        $activePeriodStyle = $isActivePeriod 
                            ? "background-color: {$tabThemeBg}; color: #ffffff; border: 1px solid {$tabThemeBorder}; box-shadow: 0 1px 3px rgba(0,0,0,0.18);" 
                            : "";
                    ?>
                        <button type="button" onclick="setKanbanPeriod('<?= $pKey ?>')" 
                                style="<?= $activePeriodStyle ?>"
                                class="oqc-period-btn <?= $isActivePeriod ? 'active' : 'inactive' ?>">
                            <?= $pLabel ?>
                        </button>
                    <?php endforeach; ?>
                </div>

                <div class="flex items-center gap-2 flex-wrap">
                    <!-- Tombol Hard Refresh (Bypass Semua Cache Database & Browser) -->
                    <button type="button" 
                            id="btn-force-refresh"
                            onclick="executeHardRefresh()" 
                            class="px-2.5 py-1.5 bg-slate-100 hover:bg-slate-200 active:bg-slate-300 text-slate-700 font-extrabold text-xs rounded-lg border border-slate-300 shadow-2xs transition-all inline-flex items-center gap-1.5 cursor-pointer whitespace-nowrap"
                            title="Force Refresh: Bersihkan semua cache (session & browser) dan tarik ulang data langsung dari database">
                        <svg id="refresh-spinner-icon" class="w-3.5 h-3.5 text-slate-600 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                        </svg>
                        <span id="refresh-btn-text">Refresh</span>
                    </button>

                    <?php if ($activeTab === 'kanban'): ?>
                        <!-- Real-Time Live Sync Indicator (Khusus Kanban Workboard) -->
                        <div id="live-sync-indicator" class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-emerald-50 border border-emerald-200 rounded-lg text-emerald-700 text-[11px] font-bold shadow-2xs" title="Sinkronisasi status otomatis antar-laptop setiap 4 detik">
                            <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                            <span id="live-sync-text">Live Sync</span>
                            <span id="live-sync-time" class="text-[9.5px] text-emerald-600 font-semibold"></span>
                        </div>

                        <!-- Tombol Action: Mulai Inspeksi Baru (Kanban) -->
                        <a href="<?= base_url('modules/inspection/session.php?scan_new=1') ?>" 
                            class="px-3.5 py-1.5 bg-blue-600 hover:bg-blue-700 active:bg-blue-800 text-white font-extrabold text-xs rounded-lg shadow-xs transition-all inline-flex items-center gap-1.5 whitespace-nowrap">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"></path>
                            </svg>
                            <span>Mulai Inspeksi Baru</span>
                        </a>
                    <?php elseif ($activeTab === 'safety_stock'): ?>
                        <!-- Tombol Action: Cek Fisik Safety Stock -->
                        <a href="<?= base_url('modules/inspection/session.php?scan_new=1&type=safety_stock&plan_type=safety_stock') ?>" 
                           class="px-3.5 py-1.5 bg-purple-600 hover:bg-purple-700 active:bg-purple-800 text-white font-extrabold text-xs rounded-lg shadow-xs transition-all inline-flex items-center gap-1.5 whitespace-nowrap">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"></path>
                            </svg>
                            <span>Cek Fisik Safety Stock</span>
                        </a>
                    <?php else: ?>
                        <!-- Tombol Action: Export Excel (Riwayat Saya / Rincian Per Lot) -->
                        <?php
                        $exportParams = [
                            'tab'        => ($activeTab === 'my_lots' ? 'my_lots' : 'my_inspections'),
                            'period'     => $period,
                            'status'     => $statusFilter,
                            'search'     => $search,
                            'start_date' => $startDate,
                            'end_date'   => $endDate,
                            'sort'       => ($sort === 'ASC' ? 'asc' : 'desc')
                        ];
                        if (!empty($inspectorFilterId)) {
                            $exportParams['inspector_id'] = $inspectorFilterId;
                        }
                        $exportUrl = base_url('modules/inspection/export_excel.php?' . http_build_query(array_filter($exportParams, fn($v) => $v !== '' && $v !== null)));
                        ?>
                        <a href="<?= $exportUrl ?>" target="_blank"
                           class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white font-extrabold text-xs rounded-lg shadow-xs transition-all inline-flex items-center gap-1.5 whitespace-nowrap"
                           title="Export data ke file Excel format detail lot">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                            <span>Export Excel</span>
                        </a>

                        <!-- Tombol Action: Mulai Inspeksi Baru (Riwayat Saya / Lot) -->
                        <a href="<?= base_url('modules/inspection/session.php?scan_new=1') ?>" 
                           style="background-color: <?= $tabThemeBg ?>;"
                           class="px-3.5 py-1.5 hover:opacity-90 active:opacity-100 text-white font-extrabold text-xs rounded-lg shadow-xs transition-all inline-flex items-center gap-1.5 whitespace-nowrap">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"></path>
                            </svg>
                            <span>Mulai Inspeksi Baru</span>
                        </a>
                    <?php endif; ?>
                </div>

            </div>

            <!-- Baris 2: Search Input, Status Dropdown, Limit, Date Range, Filter & Reset -->
            <!-- Baris 2: Search Input & Structured Controls with Micro-Labels -->
            <form id="kanbanFilterForm" action="" method="GET" class="border-t border-slate-100 pt-3">
                <input type="hidden" name="tab" id="kanbanTabInput" value="<?= htmlspecialchars($activeTab) ?>">
                <input type="hidden" name="period" id="kanbanPeriodInput" value="<?= htmlspecialchars($period) ?>">

                <!-- TIER A: Search Bar Utama (Area Kerja / Scan QR) -->
                <div class="mb-3.5">
                    <div class="relative w-full">
                        <div style="position: absolute; left: 14px; top: 0; bottom: 0; display: flex; align-items: center; pointer-events: none;">
                            <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                        </div>
                        <input type="text" name="search" id="kanbanSearchInput" autofocus autocomplete="off" value="<?= htmlspecialchars($search) ?>" 
                               placeholder="<?= ($activeTab === 'safety_stock') ? 'Scan QR / Cari Sesi, Part, Inspektur...' : (($activeTab === 'my_inspections') ? 'Scan QR / Cari Sesi, Kanban, Part, Lot...' : (($activeTab === 'my_lots') ? 'Scan QR / Cari Lot No, Ref No, Part...' : 'Scan QR Kanban atau Ketik Part Code / Customer...')) ?>" 
                               style="padding-left: 42px; padding-right: 14px; height: 42px; width: 100%; font-size: 13px;" 
                               class="w-full bg-slate-50 border border-slate-300 rounded-xl font-semibold text-slate-800 placeholder-slate-400 focus:bg-white focus:outline-none focus:ring-2 <?= $tabFocusRing ?> focus:border-transparent transition-all shadow-2xs">
                    </div>
                </div>

                <!-- TIER B: Filter Controls Terstruktur dengan Spasi Lega & Label Presisi -->
                <div class="flex items-end gap-3 flex-wrap">

                    <?php if ($activeTab === 'kanban'): ?>
                    <!-- State Tanggal, Batch & Scope disimpan sebagai hidden input untuk form search & filter -->
                    <input type="hidden" name="selected_date" id="kanbanSelectedDateInput" value="<?= htmlspecialchars((string)$selectedDate) ?>">
                    <input type="hidden" name="batch_id" id="kanbanBatchIdInput" value="<?= htmlspecialchars((string)$batchFilter) ?>">
                    <input type="hidden" name="scope" id="kanbanScopeInput" value="<?= htmlspecialchars((string)$scopeFilter) ?>">

                    <!-- KOTAK URUTAN TAMPILAN (URUTAN DOKUMEN CETAK VS PRIORITAS) -->
                    <div class="w-48 flex-shrink-0">
                        <label class="block text-[10px] font-extrabold text-slate-600 uppercase tracking-wider mb-1.5">
                            Urutan Baris:
                        </label>
                        <select name="order_by" onchange="this.form.submit()" 
                                style="height: 38px;" 
                                class="w-full px-2.5 bg-white border border-slate-300 rounded-lg text-xs font-bold text-slate-800 focus:outline-none focus:ring-2 <?= $tabFocusRing ?> hover:border-slate-400 transition-all cursor-pointer shadow-2xs">
                            <option value="paper" <?= ($orderFilter === 'paper') ? 'selected' : '' ?>>Urutan Cetak (Standar)</option>
                            <option value="priority" <?= ($orderFilter === 'priority') ? 'selected' : '' ?>>Prioritas Status/Urgent</option>
                        </select>
                    </div>
                    <?php endif; ?>

                    <?php if ($activeTab === 'my_lots'): ?>
                    <!-- KOTAK JENIS INSPEKSI (LOT) -->
                    <div class="w-36 flex-shrink-0">
                        <label class="block text-[10px] font-extrabold text-slate-600 uppercase tracking-wider mb-1.5">
                            Jenis Inspeksi:
                        </label>
                        <select name="inspection_type" onchange="this.form.submit()" 
                                style="height: 38px;" 
                                class="w-full px-2.5 bg-white border border-slate-300 rounded-lg text-xs font-bold text-slate-800 focus:outline-none focus:ring-2 <?= $tabFocusRing ?> hover:border-slate-400 transition-all cursor-pointer shadow-2xs">
                            <option value="all" <?= ($typeFilter === 'all') ? 'selected' : '' ?>>Semua Jenis</option>
                            <option value="kanban" <?= ($typeFilter === 'kanban') ? 'selected' : '' ?>>Kanban</option>
                            <option value="safety_stock" <?= ($typeFilter === 'safety_stock') ? 'selected' : '' ?>>Safety Stock</option>
                        </select>
                    </div>
                    <?php endif; ?>

                    <!-- KOTAK 4: STATUS PEKERJAAN -->
                    <div class="w-40 flex-shrink-0">
                        <label class="block text-[10px] font-extrabold text-slate-600 uppercase tracking-wider mb-1.5">
                            Status:
                        </label>
                        <select name="status" onchange="this.form.submit()" 
                                style="height: 38px;" 
                                class="w-full px-2.5 bg-white border border-slate-300 rounded-lg text-xs font-bold text-slate-800 focus:outline-none focus:ring-2 <?= $tabFocusRing ?> hover:border-slate-400 transition-all cursor-pointer shadow-2xs">
                            <?php if ($activeTab === 'safety_stock' || $activeTab === 'my_inspections' || $activeTab === 'my_lots'): ?>
                                <option value="all" <?= ($statusFilter === 'all') ? 'selected' : '' ?>>Semua Status</option>
                                <option value="in_progress" <?= ($statusFilter === 'in_progress') ? 'selected' : '' ?>>Sedang Dikerjakan</option>
                                <option value="completed" <?= ($statusFilter === 'completed' || $statusFilter === 'passed') ? 'selected' : '' ?>>Selesai (Passed)</option>
                                <option value="rejected" <?= ($statusFilter === 'rejected') ? 'selected' : '' ?>>Ada Reject</option>
                            <?php else: ?>
                                <option value="all" <?= ($statusFilter === 'all') ? 'selected' : '' ?>>Semua Status</option>
                                <option value="uninspected" <?= ($statusFilter === 'uninspected') ? 'selected' : '' ?>>Antrean (Belum Mulai)</option>
                                <option value="in_progress" <?= ($statusFilter === 'in_progress') ? 'selected' : '' ?>>Sedang Dikerjakan</option>
                                <option value="partial" <?= ($statusFilter === 'partial') ? 'selected' : '' ?>>Parsial (Belum Penuh)</option>
                                <option value="completed" <?= ($statusFilter === 'completed') ? 'selected' : '' ?>>Selesai</option>
                                <option value="rejected" <?= ($statusFilter === 'rejected') ? 'selected' : '' ?>>Ada Reject</option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <?php if ($activeTab === 'my_inspections' || $activeTab === 'my_lots'): ?>
                    <!-- KOTAK URUTAN WAKTU RIWAYAT -->
                    <div class="w-32 flex-shrink-0">
                        <label class="block text-[10px] font-extrabold text-slate-600 uppercase tracking-wider mb-1.5">
                            Waktu:
                        </label>
                        <select name="sort" onchange="this.form.submit()" 
                                style="height: 38px;" 
                                class="w-full px-2.5 bg-white border border-slate-300 rounded-lg text-xs font-bold text-slate-800 focus:outline-none focus:ring-2 <?= $tabFocusRing ?> hover:border-slate-400 transition-all cursor-pointer shadow-2xs">
                            <option value="desc" <?= ($sort === 'DESC') ? 'selected' : '' ?>>Terbaru</option>
                            <option value="asc" <?= ($sort === 'ASC') ? 'selected' : '' ?>>Terlama</option>
                        </select>
                    </div>
                    <?php endif; ?>

                    <!-- TOMBOL FILTER TAMBAHAN (OPSI LANJUTAN) -->
                    <div class="flex-shrink-0">
                        <button type="button" 
                                onclick="document.getElementById('advancedFilterBox').classList.toggle('hidden')" 
                                style="height: 38px;" 
                                class="px-3 bg-slate-100 hover:bg-slate-200 border border-slate-300 text-slate-700 font-bold text-xs rounded-lg transition-all inline-flex items-center gap-1.5 shadow-2xs cursor-pointer"
                                title="Klik untuk membuka filter lanjutan (sumber, jumlah baris, custom tanggal)">
                            <svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"></path>
                            </svg>
                            <span>Opsi Lainnya</span>
                            <svg class="w-3 h-3 text-slate-400 ml-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </button>
                    </div>

                    <!-- TOMBOL SUBMIT & RESET -->
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <button type="submit" style="height: 38px;" class="px-4 bg-slate-800 hover:bg-slate-900 active:bg-black text-white font-bold text-xs rounded-lg shadow-xs transition-all flex items-center gap-1.5 cursor-pointer">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"></path>
                            </svg>
                            <span>Terapkan</span>
                        </button>

                        <?php if (!empty($search) || $statusFilter !== 'all' || $sourceFilter !== 'all' || $batchFilter !== 'all' || $orderFilter !== 'paper' || $scopeFilter !== 'today' || ($typeFilter ?? 'all') !== 'all' || $period !== 'today' || !empty($startDate) || !empty($endDate) || (($activeTab === 'my_inspections' || $activeTab === 'my_lots') && $sort === 'ASC')): ?>
                            <a href="index.php?tab=<?= $activeTab ?>" style="height: 38px; display: inline-flex; align-items: center;" class="px-3.5 bg-rose-50 hover:bg-rose-100 text-rose-600 font-bold text-xs rounded-lg border border-rose-200 transition-all" title="Kembalikan semua filter ke pengaturan awal">
                                Reset
                            </a>
                        <?php endif; ?>
                    </div>

                </div>

                <!-- PANEL FILTER LANJUTAN (COLLAPSIBLE / OPSIONAL) -->
                <?php 
                $hasAdvancedFilterActive = ($sourceFilter !== 'all' || $limit != 50 || !empty($startDate) || !empty($endDate));
                ?>
                <div id="advancedFilterBox" class="<?= $hasAdvancedFilterActive ? '' : 'hidden' ?> mt-3 pt-3 border-t border-dashed border-slate-200 bg-slate-50/80 p-3 rounded-xl flex items-center gap-3 text-xs flex-wrap">
                    
                    <?php if ($activeTab === 'kanban'): ?>
                    <!-- Sumber Data -->
                    <div class="w-36 flex-shrink-0">
                        <span class="block text-[9.5px] font-bold text-slate-500 uppercase tracking-wider mb-1">Sumber Pembuatan:</span>
                        <select name="source" onchange="this.form.submit()" 
                                style="height: 34px;" 
                                class="w-full px-2.5 bg-white border border-slate-300 rounded-lg text-xs font-semibold text-slate-800 focus:outline-none">
                            <option value="all" <?= ($sourceFilter === 'all') ? 'selected' : '' ?>>Semua Sumber</option>
                            <option value="manual" <?= ($sourceFilter === 'manual') ? 'selected' : '' ?>>Input Manual</option>
                            <option value="import" <?= ($sourceFilter === 'import') ? 'selected' : '' ?>>Import Excel</option>
                        </select>
                    </div>
                    <?php endif; ?>

                    <!-- Jumlah Baris -->
                    <div class="w-32 flex-shrink-0">
                        <span class="block text-[9.5px] font-bold text-slate-500 uppercase tracking-wider mb-1">Jumlah Baris:</span>
                        <select name="limit" onchange="this.form.submit()" 
                                style="height: 34px;" 
                                class="w-full px-2.5 bg-white border border-slate-300 rounded-lg text-xs font-semibold text-slate-800 focus:outline-none">
                            <option value="10" <?= ($limit == 10) ? 'selected' : '' ?>>10 baris</option>
                            <option value="25" <?= ($limit == 25) ? 'selected' : '' ?>>25 baris</option>
                            <option value="50" <?= ($limit == 50) ? 'selected' : '' ?>>50 baris (Standar Cepat)</option>
                            <option value="100" <?= ($limit == 100) ? 'selected' : '' ?>>100 baris</option>
                            <option value="200" <?= ($limit == 200) ? 'selected' : '' ?>>200 baris</option>
                            <option value="500" <?= ($limit == 500) ? 'selected' : '' ?>>500 baris</option>
                        </select>
                    </div>

                    <!-- Rentang Tanggal Kustom -->
                    <div class="flex-shrink-0">
                        <span class="block text-[9.5px] font-bold text-slate-500 uppercase tracking-wider mb-1">Rentang Tanggal Khusus:</span>
                        <div style="height: 34px;" class="flex items-center space-x-1.5 bg-white border border-slate-300 rounded-lg px-2.5 text-xs text-slate-600">
                            <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>" class="bg-transparent border-0 p-0 text-xs text-slate-800 focus:outline-none font-semibold">
                            <span class="text-slate-400 font-bold text-[10px]">s/d</span>
                            <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>" class="bg-transparent border-0 p-0 text-xs text-slate-800 focus:outline-none font-semibold">
                        </div>
                    </div>

                    <div class="ml-auto text-[11px] text-slate-400">
                        *Filter lanjutan ini otomatis tersimpan saat Anda menekan tombol Terapkan.
                    </div>

                </div>

            </form>

        </div>

        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <!-- KPI BAR: STATISTIK RINGKAS                                          -->
        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <!-- KPI BAR: STATISTIK RINGKAS                                          -->
        <!-- ═══════════════════════════════════════════════════════════════════ -->
        <?php if ($activeTab === 'safety_stock'): ?>
            <!-- KPI SAFETY STOCK (CEK FISIK) -->
            <div class="kpi-grid-3cols" style="display: grid !important; grid-template-columns: repeat(3, minmax(0, 1fr)) !important; gap: 8px !important; margin-bottom: 8px !important;">
                <!-- Stat 1: Total Sesi Cek Fisik -->
                <a href="?tab=safety_stock&status=all&period=<?= urlencode($period) ?>" 
                   class="kpi-card kpi-card-ss"
                   title="Klik untuk tampilkan semua item Safety Stock">
                    <div class="flex items-center justify-between gap-1">
                        <div class="text-[9px] font-extrabold text-purple-800 uppercase tracking-wider">TOTAL SAFETY STOCK</div>
                        <span class="w-5 h-5 rounded bg-purple-200/80 text-purple-700 flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                        </span>
                    </div>
                    <div class="mt-1 flex items-baseline flex-wrap gap-1">
                        <span class="kpi-stat-value text-purple-950"><?= number_format($stats['total_count']) ?></span>
                        <span class="kpi-stat-unit text-purple-700">Item</span>
                        <span class="text-[9.5px] text-purple-900 font-extrabold bg-purple-200/70 border border-purple-300/80 px-1.5 py-0.5 rounded ml-1"><?= number_format($stats['total_lots'] ?? 0) ?> Lot (<?= number_format($stats['total_qty_pcs'] ?? 0) ?> pcs)</span>
                    </div>
                </a>

                <!-- Stat 2: Passed (Selesai) -->
                <a href="?tab=safety_stock&status=completed&period=<?= urlencode($period) ?>" 
                   class="kpi-card kpi-card-completed"
                   title="Klik untuk filter Selesai">
                    <div class="flex items-center justify-between gap-1">
                        <div class="text-[9px] font-extrabold text-emerald-900 uppercase tracking-wider">SELESAI (PASSED)</div>
                        <span class="w-5 h-5 rounded bg-emerald-200/80 text-emerald-800 flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                        </span>
                    </div>
                    <div class="mt-1 flex items-baseline flex-wrap gap-1">
                        <span class="kpi-stat-value text-emerald-950"><?= number_format($stats['passed_count']) ?></span>
                        <span class="kpi-stat-unit text-emerald-700">Item</span>
                        <span class="text-[9.5px] text-emerald-900 font-extrabold bg-emerald-200/70 border border-emerald-300/80 px-1.5 py-0.5 rounded ml-1"><?= number_format($stats['passed_lots'] ?? 0) ?> Lot (<?= number_format($stats['passed_qty_pcs'] ?? 0) ?> pcs)</span>
                    </div>
                </a>

                <!-- Stat 3: Reject -->
                <a href="?tab=safety_stock&status=rejected&period=<?= urlencode($period) ?>" 
                   class="kpi-card kpi-card-rejected"
                   title="Klik untuk filter Rejected">
                    <div class="flex items-center justify-between gap-1">
                        <div class="text-[9px] font-extrabold text-rose-800 uppercase tracking-wider">REJECTED</div>
                        <span class="w-5 h-5 rounded bg-rose-200/80 text-rose-700 flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </span>
                    </div>
                    <div class="mt-1 flex items-baseline flex-wrap gap-1">
                        <span class="kpi-stat-value text-rose-950"><?= number_format($stats['rejected_count']) ?></span>
                        <span class="kpi-stat-unit text-rose-700">Item</span>
                        <span class="text-[9.5px] text-rose-900 font-extrabold bg-rose-200/70 border border-rose-300/80 px-1.5 py-0.5 rounded ml-1"><?= number_format($stats['rejected_lots'] ?? 0) ?> Lot (<?= number_format($stats['rejected_qty_pcs'] ?? 0) ?> pcs)</span>
                    </div>
                </a>
            </div>

        <?php elseif ($activeTab === 'my_lots'): ?>
            <!-- KPI RINCIAN PER LOT SAYA -->
            <div class="kpi-grid-container">
                <!-- Stat 1: Total Lot Saya -->
                <a href="?tab=my_lots&status=all&period=<?= urlencode($period) ?>" 
                   class="kpi-card" style="background: linear-gradient(135deg, #e0f2fe 0%, #e0e7ff 100%) !important; border: 1px solid #bae6fd !important; border-left: 4.5px solid #0284c7 !important;"
                   title="Klik untuk tampilkan semua lot inspeksi Anda">
                    <div class="flex items-center justify-between gap-1">
                        <div class="text-[9px] font-extrabold text-sky-900 uppercase tracking-wider">TOTAL LOT SAYA</div>
                        <span class="w-5 h-5 rounded bg-sky-200/80 text-sky-700 flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                        </span>
                    </div>
                    <div class="mt-1 flex items-baseline flex-wrap">
                        <span class="kpi-stat-value text-sky-950"><?= number_format($stats['total_count']) ?></span>
                        <span class="kpi-stat-unit text-sky-700">Lot/Box</span>
                        <span class="text-[9px] text-sky-800 font-extrabold bg-sky-200/60 px-1 py-0.2 rounded ml-1">(<?= number_format($stats['total_qty_pcs']) ?> pcs)</span>
                    </div>
                </a>

                <!-- Stat 2: Passed (OK) -->
                <a href="?tab=my_lots&status=completed&period=<?= urlencode($period) ?>" 
                   class="kpi-card kpi-card-completed"
                   title="Klik untuk filter Lot Passed">
                    <div class="flex items-center justify-between gap-1">
                        <div class="text-[9px] font-extrabold text-emerald-900 uppercase tracking-wider">LOT PASSED</div>
                        <span class="w-5 h-5 rounded bg-emerald-200/80 text-emerald-800 flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                        </span>
                    </div>
                    <div class="mt-1 flex items-baseline">
                        <span class="kpi-stat-value text-emerald-950"><?= number_format($stats['passed_count']) ?></span>
                        <span class="kpi-stat-unit text-emerald-700">Lot</span>
                    </div>
                </a>

                <!-- Stat 3: Rejected (NG) -->
                <a href="?tab=my_lots&status=rejected&period=<?= urlencode($period) ?>" 
                   class="kpi-card kpi-card-rejected"
                   title="Klik untuk filter Lot Rejected">
                    <div class="flex items-center justify-between gap-1">
                        <div class="text-[9px] font-extrabold text-rose-800 uppercase tracking-wider">LOT REJECTED</div>
                        <span class="w-5 h-5 rounded bg-rose-200/80 text-rose-700 flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </span>
                    </div>
                    <div class="mt-1 flex items-baseline flex-wrap">
                        <span class="kpi-stat-value text-rose-950"><?= number_format($stats['rejected_count']) ?></span>
                        <span class="kpi-stat-unit text-rose-700">Lot</span>
                        <?php if ($stats['total_ng_pcs'] > 0): ?>
                            <span class="text-[9px] text-rose-800 font-extrabold bg-rose-200/60 px-1 py-0.2 rounded ml-1">(<?= number_format($stats['total_ng_pcs']) ?> pcs NG)</span>
                        <?php endif; ?>
                    </div>
                </a>

                <!-- Stat 4: Total Sampling QTY -->
                <div class="kpi-card" style="background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%) !important; border: 1px solid #e2e8f0 !important; border-left: 4.5px solid #64748b !important;">
                    <div class="flex items-center justify-between gap-1">
                        <div class="text-[9px] font-extrabold text-slate-800 uppercase tracking-wider">TOTAL SAMPLING (AQL)</div>
                        <span class="w-5 h-5 rounded bg-slate-200 text-slate-700 flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                        </span>
                    </div>
                    <div class="mt-1 flex items-baseline">
                        <span class="kpi-stat-value text-slate-900"><?= number_format($stats['total_sample_pcs']) ?></span>
                        <span class="kpi-stat-unit text-slate-600">pcs</span>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- KPI KANBAN DELIVERY (RICH CARDS) -->
            <?php
            $buildKpiUrl = function($targetStatus, $customScope = null) use ($activeTab, $period, $selectedDate, $scopeFilter, $batchFilter, $orderFilter, $sourceFilter, $search) {
                // Default scope untuk kartu status aktif (Total, Belum Selesai, Sedang Dikerjakan, Selesai, NG)
                // selalu diarahkan ke 'today' agar tidak terjebak di filter remain/pending_yesterday
                $effectiveScope = ($customScope !== null) ? $customScope : 'today';
                $params = [
                    'tab'           => 'kanban',
                    'status'        => $targetStatus,
                    'period'        => $period,
                    'selected_date' => $selectedDate,
                    'scope'         => $effectiveScope,
                    'batch_id'      => $batchFilter,
                    'order_by'      => $orderFilter,
                ];
                if ($effectiveScope === 'pending_yesterday') {
                    $params['batch_id'] = 'all';
                }
                if ($sourceFilter !== 'all') $params['source'] = $sourceFilter;
                if (!empty($search)) $params['search'] = $search;
                return '?' . http_build_query($params);
            };
            ?>
            <div class="kpi-grid-6cols">
                <!-- Stat 1: Total Kanban (Blue Gradient) -->
                <a href="<?= $buildKpiUrl('all') ?>" 
                   class="kpi-card kpi-card-total"
                   title="Klik untuk tampilkan semua status">
                    <div class="flex items-center justify-between gap-1">
                        <div class="text-[9px] font-extrabold text-blue-800 uppercase tracking-wider">TOTAL KANBAN</div>
                        <span class="w-5 h-5 rounded bg-blue-200/70 text-blue-700 flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                        </span>
                    </div>
                    <div class="mt-1 flex items-baseline">
                        <span class="kpi-stat-value text-blue-900"><?= number_format($stats['total_kanban']) ?></span>
                        <span class="kpi-stat-unit text-blue-700">Kanban</span>
                    </div>
                </a>

                <!-- Stat 2: Belum Selesai (Slate Gradient) -->
                <a href="<?= $buildKpiUrl('uncompleted') ?>" 
                   class="kpi-card kpi-card-uninspected"
                   title="Klik untuk filter Belum Selesai (Total Belum Mulai + Parsial)">
                    <div class="flex items-center justify-between gap-1">
                        <div class="text-[9px] font-extrabold text-slate-700 uppercase tracking-wider">BELUM SELESAI</div>
                        <span class="w-5 h-5 rounded bg-slate-200 text-slate-700 flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </span>
                    </div>
                    <div class="mt-1 flex items-baseline">
                        <span class="kpi-stat-value text-slate-800"><?= number_format($stats['uncompleted_count']) ?></span>
                        <span class="kpi-stat-unit text-slate-600">Kanban</span>
                    </div>
                    <div class="text-[8.5px] text-slate-500 font-semibold mt-0.5 leading-tight truncate">
                        <?= number_format($stats['uninspected_count']) ?> Belum Mulai &bull; <?= number_format($stats['partial_count']) ?> Parsial
                    </div>
                </a>

                <!-- Stat 3: Sedang Dikerjakan (Amber Gradient) -->
                <a href="<?= $buildKpiUrl('in_progress') ?>" 
                   class="kpi-card kpi-card-progress"
                   title="Klik untuk filter Sedang Dikerjakan">
                    <div class="flex items-center justify-between gap-1">
                        <div class="text-[9px] font-extrabold text-amber-900 uppercase tracking-wider">SEDANG DIKERJAKAN</div>
                        <span class="w-5 h-5 rounded bg-amber-200/80 text-amber-800 flex items-center justify-center flex-shrink-0 relative">
                            <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-ping absolute"></span>
                            <span class="w-1.5 h-1.5 rounded-full bg-amber-600"></span>
                        </span>
                    </div>
                    <div class="mt-1 flex items-baseline">
                        <span class="kpi-stat-value text-amber-950"><?= number_format($stats['in_progress_count']) ?></span>
                        <span class="kpi-stat-unit text-amber-700">Kanban</span>
                    </div>
                </a>

                <!-- Stat 4: Selesai (Emerald Gradient) -->
                <a href="<?= $buildKpiUrl('completed') ?>" 
                   class="kpi-card kpi-card-completed"
                   title="Klik untuk filter Selesai">
                    <div class="flex items-center justify-between gap-1">
                        <div class="text-[9px] font-extrabold text-emerald-900 uppercase tracking-wider">SELESAI</div>
                        <span class="w-5 h-5 rounded bg-emerald-200/80 text-emerald-800 flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                        </span>
                    </div>
                    <div class="mt-1 flex items-baseline">
                        <span class="kpi-stat-value text-emerald-950"><?= number_format($stats['completed_count']) ?></span>
                        <span class="kpi-stat-unit text-emerald-700">Kanban</span>
                    </div>
                </a>

                <!-- Stat 5: Remain (Tunggakan / Sisa Kemarin - Indigo Gradient) -->
                <a href="<?= $buildKpiUrl('all', 'pending_yesterday') ?>" 
                   class="kpi-card kpi-card-remain"
                   title="Klik untuk melihat Kanban jadwal kemarin yang belum selesai / parsial">
                    <div class="flex items-center justify-between gap-1">
                        <div class="text-[9px] font-extrabold text-indigo-900 uppercase tracking-wider">REMAIN</div>
                        <span class="w-5 h-5 rounded bg-indigo-200/80 text-indigo-800 flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </span>
                    </div>
                    <div class="mt-1 flex items-baseline">
                        <span class="kpi-stat-value text-indigo-950"><?= number_format($pendingYesterdayCount) ?></span>
                        <span class="kpi-stat-unit text-indigo-700">Kanban</span>
                    </div>
                </a>

                <!-- Stat 6: Lot NG / Butuh Tindakan (Rose Gradient) -->
                <a href="<?= $buildKpiUrl('rejected') ?>" 
                   class="kpi-card kpi-card-rejected"
                   title="Klik untuk filter Kanban yang terdapat Lot NG / Butuh Tindakan">
                    <div class="flex items-center justify-between gap-1">
                        <div class="text-[9px] font-extrabold text-rose-800 uppercase tracking-wider">LOT NG / TINDAKAN</div>
                        <span class="w-5 h-5 rounded bg-rose-200/80 text-rose-700 flex items-center justify-center flex-shrink-0">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        </span>
                    </div>
                    <div class="mt-1 flex items-baseline flex-wrap gap-1">
                        <span class="kpi-stat-value text-rose-950"><?= number_format($stats['rejected_count'] ?? 0) ?></span>
                        <span class="kpi-stat-unit text-rose-700">Kanban</span>
                        <?php if (!empty($stats['ng_lots_count'])): ?>
                            <span class="text-[9.5px] text-rose-900 font-extrabold bg-rose-200/80 border border-rose-300 px-1.5 py-0.5 rounded ml-1"><?= number_format($stats['ng_lots_count']) ?> Lot NG</span>
                        <?php endif; ?>
                    </div>
                </a>
            </div>

            <!-- ═══════════════════════════════════════════════════════════════════ -->
            <!-- SUB-NAVIGASI BERJENJANG: PILIH TANGGAL -> PILIH BATCH (OPERATOR)   -->
            <!-- ═══════════════════════════════════════════════════════════════════ -->
            <div class="bg-white border border-slate-200/90 rounded-xl p-3 shadow-xs space-y-2.5">
                
                <?php if ($period !== 'today' && !empty($periodDates)): ?>
                    <!-- LEVEL 1: PITA PILIHAN TANGGAL DALAM PERIODE INI -->
                    <div>
                        <div class="flex items-center justify-between gap-2 mb-1.5 px-0.5">
                            <div class="flex items-center gap-1.5">
                                <span class="w-2 h-2 rounded-full bg-indigo-600"></span>
                                <span class="text-xs font-extrabold text-slate-800 uppercase tracking-wider">Pilih Tanggal:</span>
                            </div>
                            <span class="text-[11px] text-slate-500 font-medium">Klik tanggal untuk melihat gelombang unggahan hari tersebut</span>
                        </div>

                        <div class="flex items-center gap-2 overflow-x-auto pb-1 custom-scrollbar">
                            <?php foreach ($periodDates as $dItem): 
                                $dDate = $dItem['work_date'];
                                $isToday = ($dDate === date('Y-m-d'));
                                $dLabel = date('d M Y', strtotime($dDate)) . ($isToday ? ' (Hari Ini)' : '');
                                $dCount = (int)$dItem['item_count'];
                                $dActive = ($selectedDate === $dDate);

                                $buildDateUrl = function($targetDate) use ($activeTab, $period, $statusFilter, $orderFilter, $search) {
                                    $params = [
                                        'tab'           => $activeTab,
                                        'period'        => $period,
                                        'selected_date' => $targetDate,
                                        'scope'         => 'today',
                                        'order_by'      => $orderFilter,
                                    ];
                                    if ($statusFilter !== 'all') $params['status'] = $statusFilter;
                                    if (!empty($search)) $params['search'] = $search;
                                    return '?' . http_build_query($params);
                                };
                            ?>
                                <a href="<?= $buildDateUrl($dDate) ?>" 
                                   class="flex-shrink-0 px-3 py-1.5 rounded-lg border text-xs font-bold transition-all flex items-center gap-2 <?= $dActive ? 'bg-indigo-700 text-white border-indigo-700 shadow-sm ring-2 ring-indigo-500/30' : 'bg-slate-50 hover:bg-slate-100 text-slate-700 border-slate-200 hover:border-slate-300' ?>">
                                    <svg class="w-3.5 h-3.5 <?= $dActive ? 'text-indigo-200' : 'text-slate-400' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                    </svg>
                                    <span><?= $dLabel ?></span>
                                    <span class="text-[10px] <?= $dActive ? 'bg-white/20 text-white' : 'bg-white text-slate-600 border border-slate-200' ?> px-1.5 py-0.2 rounded-md font-semibold">
                                        <?= $dCount ?> Part
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="border-t border-slate-100 pt-2"></div>
                <?php endif; ?>

                <!-- LEVEL 2: PITA PILIHAN BATCH PADA TANGGAL TERPILIH -->
                <div>
                    <div class="flex items-center justify-between gap-2 mb-1.5 px-0.5">
                        <div class="flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-blue-600"></span>
                            <span class="text-xs font-extrabold text-slate-800 uppercase tracking-wider">
                                Gelombang / Batch (<?= date('d M Y', strtotime($selectedDate)) ?>):
                            </span>
                        </div>
                        <span class="text-[11px] text-slate-500 font-medium">Klik batch untuk menyaring daftar part sesuai lembar fisik</span>
                    </div>

                    <div class="flex items-center gap-2 overflow-x-auto pb-1 custom-scrollbar">
                        <?php if (!empty($todayBatches)): ?>
                            <?php foreach ($todayBatches as $bIdx => $bItem): 
                                $bId = (string)$bItem['id'];
                                $bNum = $bIdx + 1;
                                $bTime = !empty($bItem['imported_at']) ? date('H:i', strtotime($bItem['imported_at'])) : '-';
                                $bTotal = (int)$bItem['item_count'];
                                $bDone = (int)$bItem['completed_count'];
                                $bProg = (int)($bItem['in_progress_count'] ?? 0);
                                $bActive = ($scopeFilter !== 'pending_yesterday' && $batchFilter === $bId);

                                $buildBatchUrl = function($targetBatchId) use ($activeTab, $period, $selectedDate, $statusFilter, $orderFilter, $search) {
                                    $params = [
                                        'tab'           => $activeTab,
                                        'period'        => $period,
                                        'selected_date' => $selectedDate,
                                        'batch_id'      => $targetBatchId,
                                        'scope'         => 'today',
                                        'order_by'      => $orderFilter,
                                    ];
                                    if ($statusFilter !== 'all') $params['status'] = $statusFilter;
                                    if (!empty($search)) $params['search'] = $search;
                                    return '?' . http_build_query($params);
                                };
                            ?>
                                <a href="<?= $buildBatchUrl($bId) ?>" 
                                   class="flex-shrink-0 px-3.5 py-2 rounded-lg border text-xs font-bold transition-all flex items-center gap-2.5 <?= $bActive ? 'bg-slate-900 text-white border-slate-900 shadow-sm ring-2 ring-blue-500/30' : 'bg-slate-50 hover:bg-slate-100 text-slate-700 border-slate-200 hover:border-slate-300' ?>">
                                    <div class="flex items-center gap-1.5">
                                        <span class="w-1.5 h-1.5 rounded-full <?= $bActive ? 'bg-emerald-400' : 'bg-slate-400' ?>"></span>
                                        <span>Batch <?= $bNum ?></span>
                                        <span class="text-[10.5px] <?= $bActive ? 'text-slate-300' : 'text-slate-500' ?> font-semibold">(<?= $bTime ?>)</span>
                                    </div>
                                    <div class="flex items-center gap-1 text-[10.5px] <?= $bActive ? 'bg-white/15 text-white' : 'bg-white text-slate-700 border border-slate-200' ?> px-2 py-0.5 rounded-md">
                                        <span><?= $bDone ?>/<?= $bTotal ?> Selesai</span>
                                        <?php if ($bProg > 0): ?>
                                            <span class="inline-block w-1.5 h-1.5 rounded-full bg-amber-400" title="Ada sesi berjalan"></span>
                                        <?php endif; ?>
                                    </div>
                                </a>
                            <?php endforeach; ?>

                            <!-- Tombol Pill: Semua Batch di Tanggal Ini -->
                            <?php
                            $isAllTodayActive = ($scopeFilter !== 'pending_yesterday' && $batchFilter === 'all');
                            $buildAllTodayUrl = function() use ($activeTab, $period, $selectedDate, $statusFilter, $orderFilter, $search) {
                                $params = [
                                    'tab'           => $activeTab,
                                    'period'        => $period,
                                    'selected_date' => $selectedDate,
                                    'batch_id'      => 'all',
                                    'scope'         => 'today',
                                    'order_by'      => $orderFilter,
                                ];
                                if ($statusFilter !== 'all') $params['status'] = $statusFilter;
                                if (!empty($search)) $params['search'] = $search;
                                return '?' . http_build_query($params);
                            };
                            ?>
                            <a href="<?= $buildAllTodayUrl() ?>" 
                               class="flex-shrink-0 px-3 py-2 rounded-lg border text-xs font-bold transition-all flex items-center gap-2 <?= $isAllTodayActive ? 'bg-slate-900 text-white border-slate-900 shadow-sm ring-2 ring-blue-500/30' : 'bg-slate-50 hover:bg-slate-100 text-slate-700 border-slate-200 hover:border-slate-300' ?>">
                                <span>Semua Batch Tanggal Ini</span>
                                <span class="text-[10.5px] <?= $isAllTodayActive ? 'bg-white/15 text-white' : 'bg-white text-slate-600 border border-slate-200' ?> px-2 py-0.5 rounded-md font-semibold">
                                    <?= number_format(array_sum(array_column($todayBatches, 'item_count'))) ?> Part
                                </span>
                            </a>

                        <?php else: ?>
                            <div class="text-xs text-slate-500 italic py-1 px-2">
                                Belum ada dokumen batch Kanban yang diunggah pada tanggal <?= date('d M Y', strtotime($selectedDate)) ?>.
                            </div>
                        <?php endif; ?>

                        <!-- Tab Khusus: Sisa / Tunggakan Kemarin (Highlight Kuning/Amber) -->
                        <?php if ($pendingYesterdayCount > 0): 
                            $isYesterdayActive = ($scopeFilter === 'pending_yesterday');
                            $buildYesterdayUrl = function() use ($activeTab, $period, $selectedDate, $statusFilter, $orderFilter, $search) {
                                $params = [
                                    'tab'           => $activeTab,
                                    'period'        => $period,
                                    'selected_date' => $selectedDate,
                                    'batch_id'      => 'all',
                                    'scope'         => 'pending_yesterday',
                                    'order_by'      => $orderFilter,
                                ];
                                if ($statusFilter !== 'all') $params['status'] = $statusFilter;
                                if (!empty($search)) $params['search'] = $search;
                                return '?' . http_build_query($params);
                            };
                        ?>
                            <div class="h-6 w-px bg-slate-200 mx-1 flex-shrink-0"></div>
                            <a href="<?= $buildYesterdayUrl() ?>" 
                               class="flex-shrink-0 px-3 py-2 rounded-lg border text-xs font-bold transition-all flex items-center gap-2 <?= $isYesterdayActive ? 'bg-amber-600 text-white border-amber-600 shadow-sm ring-2 ring-amber-400/40' : 'bg-amber-50 hover:bg-amber-100 text-amber-900 border-amber-200 hover:border-amber-300' ?>">
                                <span class="w-2 h-2 rounded-full <?= $isYesterdayActive ? 'bg-white' : 'bg-amber-500' ?>"></span>
                                <span>Tunggakan Kemarin</span>
                                <span class="text-[10.5px] <?= $isYesterdayActive ? 'bg-white/20 text-white' : 'bg-white text-amber-900 border border-amber-200' ?> px-2 py-0.5 rounded-md font-extrabold">
                                    <?= number_format($pendingYesterdayCount) ?> Belum Selesai
                                </span>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        <?php endif; ?>
