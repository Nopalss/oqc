<?php
$breadcrumbCategory = "DATA REFERENSI";
$pageTitle = "Tambah Kanban (Jadwal Kirim)";
$pageSubtitle = "Input beberapa baris data jadwal pengiriman Kanban sekaligus atau via Upload File Excel/CSV";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';

require_menu_access('kanban');

$pdo = getDB();
$master_parts = [];
$master_customers = [];

if ($pdo) {
    try {
        $stmt = $pdo->query("SELECT id, part_code, part_name FROM master_parts ORDER BY part_code ASC");
        $master_parts = $stmt->fetchAll();
        $stmtCust = $pdo->query("SELECT id, name FROM master_customers WHERE UPPER(name) NOT LIKE '%SAFETY STOCK%' AND UPPER(name) NOT LIKE '%INTERNAL STOCK%' ORDER BY name ASC");
        $master_customers = $stmtCust->fetchAll();
    } catch (PDOException $e) {
        $master_parts = [];
        $master_customers = [];
    }
}
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen min-w-0 w-full overflow-x-hidden transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-5 space-y-4 min-w-0 w-full overflow-x-hidden">
        
        <?= render_flash() ?>

        <!-- Action Card Header -->
        <div class="card p-3 md:p-4 bg-white border border-slate-200/80 rounded-xl shadow-xs">
            <div style="display: flex; align-items: center; justify-content: space-between;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <a href="<?= base_url('modules/kanban/index.php') ?>" class="btn-secondary py-1.5 px-3 text-xs">
                        &larr; Kembali ke Daftar Kanban
                    </a>
                    <h2 class="text-sm font-bold text-slate-800">Form Input Data Kanban Baru</h2>
                </div>
            </div>
        </div>

        <!-- Input Mode Selection Container -->
        <div class="card p-4 bg-white border border-slate-200/80 rounded-xl shadow-xs space-y-4 max-w-full overflow-hidden">
            
            <!-- Modern Clickable Pill Tabs -->
            <div class="flex items-center space-x-2 bg-slate-100/80 p-1.5 rounded-xl max-w-fit border border-slate-200/60">
                <button type="button" id="tab-btn-manual" onclick="switchTab('manual')" 
                        class="px-4 py-2 text-xs font-bold rounded-lg transition-all duration-200 bg-blue-600 text-white shadow-xs cursor-pointer">
                    📝 Input Manual Matrix (Multi-Row)
                </button>
                <button type="button" id="tab-btn-excel" onclick="switchTab('excel')" 
                        class="px-4 py-2 text-xs font-bold rounded-lg transition-all duration-200 text-slate-600 hover:text-slate-900 hover:bg-slate-200/70 cursor-pointer">
                    📊 Import File Excel / CSV Batch
                </button>
            </div>

            <!-- TAB 1: Multi-Row Dynamic Manual Form -->
            <div id="tab-content-manual" class="max-w-full overflow-hidden">
                <form action="<?= base_url('modules/kanban/store.php') ?>" method="POST" class="space-y-4 max-w-full overflow-hidden">
                    <?= csrf_field() ?>
                    <input type="hidden" name="entry_type" value="manual">
                    <input type="hidden" name="import_method" value="manual">

                    <!-- Shared Metadata Fields Header -->
                    <div class="p-3 bg-slate-50 border border-slate-200 rounded-xl flex items-center justify-between text-xs flex-wrap gap-2">
                        <input type="hidden" name="plan_type" value="kanban">
                        <div class="flex items-center space-x-3">
                            <div class="flex items-center space-x-1.5">
                                <span class="font-bold text-slate-700">Tipe Planning:</span>
                                <span class="px-2 py-1 rounded text-xs font-bold bg-blue-100 text-blue-800 border border-blue-200">🚚 Kanban (Pengiriman Customer)</span>
                            </div>
                            <div class="flex items-center space-x-1.5">
                                <span class="font-bold text-slate-700">No. Dokumen Batch:</span>
                                <input type="text" name="document_number" id="manual-doc-num" value="KANBAN-<?= date('Ymd-His') ?>" placeholder="No. Dokumen" class="form-input py-1 text-xs font-mono max-w-xs">
                            </div>
                        </div>
                        <div class="flex items-center space-x-2">
                            <a href="<?= base_url('modules/kanban/index.php') ?>" class="btn-secondary py-1.5 px-3 text-xs">Batal</a>
                            <button type="submit" class="btn-primary py-1.5 px-4 text-xs font-bold">
                                Simpan Semua Data Kanban
                            </button>
                        </div>
                    </div>

                    <!-- Dynamic Multi-Row Table -->
                    <datalist id="master-parts-list">
                        <?php foreach ($master_parts as $p): ?>
                            <option value="<?= htmlspecialchars($p['part_code']) ?>"><?= htmlspecialchars($p['part_code']) ?> - <?= htmlspecialchars($p['part_name']) ?></option>
                        <?php endforeach; ?>
                    </datalist>

                    <div class="w-full max-w-full overflow-x-auto border border-slate-200 rounded-xl shadow-xs" style="width: 100%; max-width: 100%; overflow-x: auto; display: block;">
                        <table class="w-full text-left text-xs text-slate-700" id="kanban-items-table" style="min-width: 1650px; table-layout: auto;">
                            <thead class="bg-slate-100 text-slate-700 font-bold border-b border-slate-200 text-[11px] uppercase whitespace-nowrap">
                                <tr id="kanban-table-header-row">
                                    <th class="px-3 py-2.5 text-center" style="width: 40px; min-width: 40px;">#</th>
                                    <th class="px-3 py-2.5 col-kanban-only" id="th-kanban-no" style="min-width: 170px;">Kanban No <span class="text-rose-500">*</span></th>
                                    <th class="px-3 py-2.5" id="th-item-code" style="min-width: 190px;">Item Code <span class="text-rose-500">*</span></th>
                                    <th class="px-3 py-2.5" id="th-item-desc" style="min-width: 220px;">Item Description <span class="text-rose-500">*</span></th>
                                    <th class="px-3 py-2.5 col-kanban-only" id="th-customer" style="min-width: 240px;">Customer / Tujuan PT <span class="text-rose-500">*</span></th>
                                    <th class="px-3 py-2.5 col-kanban-only" id="th-req-date" style="min-width: 150px;">Req. Date <span class="text-rose-500">*</span></th>
                                    <th class="px-3 py-2.5 col-kanban-only" id="th-eta" style="min-width: 150px;">ETA <span class="text-rose-500">*</span></th>
                                    <th class="px-3 py-2.5" id="th-qty" style="min-width: 110px;">Qty (Total Lot) <span class="text-rose-500">*</span></th>
                                    <th class="px-3 py-2.5 col-kanban-only" id="th-str-loc" style="min-width: 120px;">Str. Loc</th>
                                    <th class="px-3 py-2.5 col-kanban-only" id="th-supply-area" style="min-width: 120px;">Supply Area</th>
                                    <th class="px-3 py-2.5" id="th-check-type" style="min-width: 110px;">Status Cek</th>
                                    <th class="px-3 py-2.5" style="min-width: 230px;">Remark</th>
                                    <th class="px-3 py-2.5 text-center" style="width: 60px; min-width: 60px;">Hapus</th>
                                </tr>
                            </thead>
                            <tbody id="kanban-rows-body" class="divide-y divide-slate-100">
                                <!-- Dynamic rows inserted via JS -->
                            </tbody>
                        </table>
                    </div>

                    <!-- Add Row Action Controls (Clean Icon without duplicate '+' text) -->
                    <div class="flex items-center justify-between pt-1">
                        <div class="flex items-center space-x-2">
                            <button type="button" onclick="addKanbanRow()" class="btn-secondary py-1.5 px-3 text-xs flex items-center">
                                <svg class="w-3.5 h-3.5 mr-1 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                                </svg>
                                Tambah 1 Baris
                            </button>
                            <button type="button" onclick="addMultipleKanbanRows(5)" class="btn-secondary py-1.5 px-3 text-xs flex items-center">
                                <svg class="w-3.5 h-3.5 mr-1 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                                </svg>
                                Tambah 5 Baris
                            </button>
                        </div>
                        <span class="text-xs text-slate-500">Total: <b id="row-counter" class="text-slate-800">0</b> baris data</span>
                    </div>

                    <div class="pt-3 border-t border-slate-100 flex justify-end space-x-2">
                        <a href="<?= base_url('modules/kanban/index.php') ?>" class="btn-secondary py-1.5 px-4 text-xs">Batal</a>
                        <button type="submit" class="btn-primary py-1.5 px-4 text-xs font-bold">
                            Simpan Semua Data Kanban
                        </button>
                    </div>
                </form>
            </div>

            <!-- TAB 2: Batch Excel / CSV Import Form -->
            <div id="tab-content-excel" class="hidden space-y-4 max-w-full overflow-hidden">
                
                <!-- File Uploader & Header Card -->
                <div class="p-4 bg-slate-50 border border-slate-200 rounded-xl space-y-3">
                    <div class="flex items-center justify-between flex-wrap gap-2">
                        <h3 class="text-xs font-bold text-slate-800 flex items-center gap-1.5">
                            📊 Upload & Review File Excel Kanban
                        </h3>
                        <a href="<?= base_url('modules/kanban/sample_template.csv') ?>" class="text-xs font-semibold text-blue-600 hover:underline">
                            📥 Download Template CSV Contoh
                        </a>
                    </div>

                    <div class="flex items-end gap-3 flex-wrap">
                        <div class="flex-1 min-w-[240px]">
                            <label class="form-label">Pilih File Excel / CSV Kanban (`kanban.xlsx`) <span class="text-rose-500">*</span></label>
                            <input type="file" id="excel-file-input" accept=".csv, .xlsx, .xls" class="form-input text-xs">
                        </div>
                        <div>
                            <button type="button" onclick="previewExcelKanbanFile()" class="btn-primary py-2 px-4 text-xs font-bold flex items-center">
                                <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                </svg>
                                Pratinjau & Review File Excel
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Loading Indicator -->
                <div id="excel-loading" class="hidden p-8 text-center bg-white border border-slate-200 rounded-xl shadow-xs space-y-3">
                    <div class="inline-block animate-spin rounded-full h-8 w-8 border-4 border-blue-600 border-t-transparent"></div>
                    <p class="text-xs font-bold text-slate-700">Membaca & Memproses Data File Excel (`kanban.xlsx`)...</p>
                    <p class="text-[11px] text-slate-400">Mendeteksi tab 'kanban', mengabaikan baris kosong & merapikan format data...</p>
                </div>

                <!-- Live Preview & Review Container (Hidden until file parsed) -->
                <div id="excel-preview-container" class="hidden space-y-4 max-w-full overflow-hidden">
                    <form id="preview-kanban-form" action="<?= base_url('modules/kanban/store.php') ?>" method="POST" onsubmit="return prepareReviewFormSubmit(event)" class="space-y-4 max-w-full overflow-hidden">
                        <?= csrf_field() ?>
                        <input type="hidden" name="entry_type" value="manual">
                        <input type="hidden" name="import_method" value="excel_import">
                        <input type="hidden" name="vendor" id="preview-vendor-input" value="PT. SURYA TECHNOLOGY INDUSTRI">
                        <input type="hidden" name="print_datetime" id="preview-printdt-input" value="">
                        <input type="hidden" name="kanban_json_data" id="kanban_json_data">

                        <!-- LEVEL 1: Header Dokumen & Tombol Aksi Utama -->
                        <div class="bg-white border border-slate-200 rounded-2xl p-4 shadow-xs space-y-3">
                            <div class="flex flex-col md:flex-row md:items-center justify-between gap-3">
                                <div class="space-y-1">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="inline-flex items-center justify-center p-2 rounded-xl bg-blue-50 text-blue-600 border border-blue-100">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                            </svg>
                                        </span>
                                        <h3 class="text-sm font-bold text-slate-800 tracking-tight" id="preview-filename">kanban.xlsx</h3>
                                        <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-blue-50 text-blue-700 border border-blue-200" id="preview-sheetname">Tab: kanban</span>
                                    </div>
                                    <div class="flex items-center gap-2 text-xs text-slate-500 flex-wrap">
                                        <span class="inline-flex items-center gap-1 font-medium bg-slate-50 px-2 py-0.5 rounded border border-slate-200">
                                            🏢 Vendor: <strong id="preview-vendor-val" class="text-slate-800 font-mono">228746</strong>
                                        </span>
                                        <span class="inline-flex items-center gap-1 font-medium bg-slate-50 px-2 py-0.5 rounded border border-slate-200">
                                            🕒 Print: <strong id="preview-printdt-val" class="text-slate-800 font-mono">-</strong>
                                        </span>
                                        <span class="text-slate-400 text-[11px]">
                                            (<span id="preview-skipped-rows">0</span> baris kosong diabaikan)
                                        </span>
                                    </div>
                                </div>

                                <div class="flex items-center gap-2 self-end md:self-auto flex-wrap">
                                    <div class="flex items-center gap-1.5 bg-slate-50 px-2.5 py-1 rounded-xl border border-slate-200">
                                        <label for="preview-doc-num" class="text-[11px] font-bold text-slate-500 whitespace-nowrap">No. Batch:</label>
                                        <input type="text" name="document_number" id="preview-doc-num" value="KANBAN-<?= date('Ymd-His') ?>" class="form-input py-0.5 px-2 text-xs font-mono font-bold w-40 bg-white border-slate-300">
                                    </div>
                                    <a href="<?= base_url('modules/kanban/index.php') ?>" class="btn-secondary py-2 px-3 text-xs font-semibold">Batal</a>
                                    <button type="submit" id="btn-submit-preview-top" class="btn-primary py-2 px-4 text-xs font-bold shadow-md shadow-blue-600/20">
                                        ✓ Simpan Data ke Database
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- LEVEL 2: 4 Kartu Filter Status Interaktif (Satu klik untuk filter) -->
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-left">
                            <!-- Card 1: Semua Data -->
                            <button type="button" onclick="setPreviewFilter('all')" id="card-filter-all" 
                                    style="background: linear-gradient(135deg, #e0f2fe 0%, #dbeafe 100%) !important; border: 1.5px solid #93c5fd !important; border-left: 5px solid #2563eb !important; padding: 14px 18px !important; min-height: 105px !important; border-radius: 12px !important;"
                                    class="transition-all cursor-pointer shadow-xs flex flex-col justify-between text-left hover:shadow-md">
                                <div class="flex items-center justify-between gap-2 w-full">
                                    <span class="text-xs font-bold text-blue-900 flex items-center gap-1.5">📋 Semua Data</span>
                                    <span class="text-[10px] font-extrabold px-2 py-0.5 rounded-md bg-blue-200/90 text-blue-800 border border-blue-300">Total File</span>
                                </div>
                                <div class="my-1.5 flex items-baseline">
                                    <span class="text-2xl font-black text-blue-950 font-mono tracking-tight leading-none" id="count-card-all">0</span>
                                    <span class="text-xs font-bold text-blue-700 ml-1.5">Baris</span>
                                </div>
                                <p class="text-[11px] font-semibold text-blue-700/80 leading-tight">Total baris yang terbaca</p>
                            </button>

                            <!-- Card 2: Baris Baru -->
                            <button type="button" onclick="setPreviewFilter('new')" id="card-filter-new" 
                                    style="background: linear-gradient(135deg, #dcfce7 0%, #d1fae5 100%) !important; border: 1.5px solid #86efac !important; border-left: 5px solid #10b981 !important; padding: 14px 18px !important; min-height: 105px !important; border-radius: 12px !important;"
                                    class="transition-all cursor-pointer shadow-xs flex flex-col justify-between text-left hover:shadow-md">
                                <div class="flex items-center justify-between gap-2 w-full">
                                    <span class="text-xs font-bold text-emerald-900 flex items-center gap-1.5">✨ Baris Baru</span>
                                    <span class="text-[10px] font-extrabold px-2 py-0.5 rounded-md bg-emerald-200/90 text-emerald-800 border border-emerald-300">Akan Disimpan</span>
                                </div>
                                <div class="my-1.5 flex items-baseline">
                                    <span class="text-2xl font-black text-emerald-950 font-mono tracking-tight leading-none" id="count-card-new">0</span>
                                    <span class="text-xs font-bold text-emerald-700 ml-1.5">Baris</span>
                                </div>
                                <p class="text-[11px] font-semibold text-emerald-700/80 leading-tight">Data jadwal baru yang valid</p>
                            </button>

                            <!-- Card 3: Perubahan Qty -->
                            <button type="button" onclick="setPreviewFilter('qty_change')" id="card-filter-qty_change" 
                                    style="background: linear-gradient(135deg, #fef3c7 0%, #ffedd5 100%) !important; border: 1.5px solid #fcd34d !important; border-left: 5px solid #f59e0b !important; padding: 14px 18px !important; min-height: 105px !important; border-radius: 12px !important;"
                                    class="transition-all cursor-pointer shadow-xs flex flex-col justify-between text-left hover:shadow-md relative overflow-hidden">
                                <div class="flex items-center justify-between gap-2 w-full">
                                    <span class="text-xs font-bold text-amber-950 flex items-center gap-1.5">🔄 Perubahan Qty</span>
                                    <span class="text-[10px] font-black px-2 py-0.5 rounded-md bg-amber-300/90 text-amber-950 border border-amber-400">Perlu Pilihan</span>
                                </div>
                                <div class="my-1.5 flex items-baseline">
                                    <span class="text-2xl font-black text-amber-950 font-mono tracking-tight leading-none" id="count-card-qty_change">0</span>
                                    <span class="text-xs font-bold text-amber-700 ml-1.5">Baris</span>
                                </div>
                                <p class="text-[11px] font-semibold text-amber-800/80 leading-tight">Kuantitas beda dari database</p>
                            </button>

                            <!-- Card 4: Duplikat -->
                            <button type="button" onclick="setPreviewFilter('dup')" id="card-filter-dup" 
                                    style="background: linear-gradient(135deg, #ffe4e6 0%, #fecdd3 100%) !important; border: 1.5px solid #fda4af !important; border-left: 5px solid #e11d48 !important; padding: 14px 18px !important; min-height: 105px !important; border-radius: 12px !important;"
                                    class="transition-all cursor-pointer shadow-xs flex flex-col justify-between text-left hover:shadow-md">
                                <div class="flex items-center justify-between gap-2 w-full">
                                    <span class="text-xs font-bold text-rose-900 flex items-center gap-1.5">⚠️ Duplikat</span>
                                    <span class="text-[10px] font-extrabold px-2 py-0.5 rounded-md bg-rose-200/90 text-rose-800 border border-rose-300">Dilewati</span>
                                </div>
                                <div class="my-1.5 flex items-baseline">
                                    <span class="text-2xl font-black text-rose-950 font-mono tracking-tight leading-none" id="count-card-dup">0</span>
                                    <span class="text-xs font-bold text-rose-700 ml-1.5">Baris</span>
                                </div>
                                <p class="text-[11px] font-semibold text-rose-700/80 leading-tight">Sama persis dengan data lama</p>
                            </button>
                        </div>

                        <!-- LEVEL 3: Banner Panduan & Aksi Massal (Muncul saat ada baris perubahan Qty) -->
                        <div id="bulk-qty-banner" class="hidden p-3.5 bg-gradient-to-r from-sky-50 to-blue-50 border border-sky-200 rounded-2xl shadow-xs space-y-2">
                            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2.5">
                                <div class="flex items-start gap-2.5">
                                    <span class="inline-flex items-center justify-center p-1.5 rounded-xl bg-sky-500 text-white shadow-xs shrink-0 mt-0.5">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                    </span>
                                    <div>
                                        <h4 class="text-xs font-bold text-sky-950">
                                            Ditemukan <span id="banner-qtychange-count" class="font-extrabold text-blue-700">0</span> item dengan perubahan kuantitas (Qty)
                                        </h4>
                                        <p class="text-[11px] text-sky-800/80">
                                            Pilih aksi massal untuk seluruh baris ini, atau ubah satu-per-satu lewat dropdown di kolom tabel:
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 flex-wrap shrink-0">
                                    <button type="button" onclick="setAllQtyChangeActions('update_qty')" 
                                            style="background-color: #0284c7 !important; color: #ffffff !important; border: 1px solid #0369a1 !important; display: inline-flex !important; align-items: center !important; gap: 6px !important; white-space: nowrap !important;"
                                            class="px-3.5 py-2 rounded-xl text-xs font-bold hover:brightness-110 active:scale-98 transition-all shadow-sm cursor-pointer">
                                        <span style="font-size: 14px;">🔄</span>
                                        <span>Update Target Qty (Rekomendasi)</span>
                                    </button>
                                    <button type="button" onclick="setAllQtyChangeActions('create_new_row')" 
                                            style="background-color: #ffffff !important; color: #0369a1 !important; border: 1.5px solid #7dd3fc !important; display: inline-flex !important; align-items: center !important; gap: 6px !important; white-space: nowrap !important;"
                                            class="px-3.5 py-2 rounded-xl text-xs font-bold hover:bg-sky-50 active:scale-98 transition-all shadow-2xs cursor-pointer">
                                        <span style="font-size: 14px;">➕</span>
                                        <span>Buat Baris Baru</span>
                                    </button>
                                    <button type="button" onclick="setAllQtyChangeActions('skip')" 
                                            style="background-color: #ffffff !important; color: #475569 !important; border: 1.5px solid #cbd5e1 !important; display: inline-flex !important; align-items: center !important; gap: 6px !important; white-space: nowrap !important;"
                                            class="px-3.5 py-2 rounded-xl text-xs font-bold hover:bg-slate-100 active:scale-98 transition-all shadow-2xs cursor-pointer">
                                        <span style="font-size: 14px;">⏭️</span>
                                        <span>Abaikan</span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- LEVEL 4: Toolbar & Kolom Pencarian Lega -->
                        <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3">
                            <div class="relative flex-1">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                    </svg>
                                </span>
                                <input type="text" id="preview-search-input" onkeyup="filterPreviewRows()" placeholder="Ketik untuk mencari cepat Kanban No, Item Code, Part Description, Customer..." class="form-input py-2 pl-9 pr-3 text-xs w-full bg-white border-slate-200 rounded-xl focus:border-blue-500">
                            </div>
                            <div class="flex items-center justify-between sm:justify-end gap-3 text-xs text-slate-500 font-semibold px-1">
                                <span>Menampilkan <b id="preview-visible-count" class="text-blue-700 font-mono">0</b> dari <b id="preview-total-count" class="font-mono">0</b> baris</span>
                            </div>
                        </div>

                        <!-- Interactive Review Matrix Table (Strictly Table Scrollable) -->
                        <div class="w-full max-w-full overflow-x-auto border border-slate-200 rounded-xl shadow-xs" style="width: 100%; max-width: 100%; overflow-x: auto; display: block;">
                            <table class="w-full text-left text-xs text-slate-700" id="preview-kanban-table" style="min-width: 1750px; table-layout: auto;">
                                <thead class="bg-slate-100 text-slate-700 font-bold border-b border-slate-200 text-[11px] uppercase whitespace-nowrap">
                                    <tr>
                                        <th class="px-3 py-2.5 text-center" style="width: 40px; min-width: 40px;">#</th>
                                        <th class="px-3 py-2.5 text-center" style="width: 120px; min-width: 120px;">Status</th>
                                        <th class="px-3 py-2.5" style="min-width: 160px;">Kanban No <span class="text-rose-500">*</span></th>
                                        <th class="px-3 py-2.5" style="min-width: 180px;">Item Code <span class="text-rose-500">*</span></th>
                                        <th class="px-3 py-2.5" style="min-width: 220px;">Item Description <span class="text-rose-500">*</span></th>
                                        <th class="px-3 py-2.5" style="min-width: 240px;">Customer / Tujuan PT <span class="text-rose-500">*</span></th>
                                        <th class="px-3 py-2.5" style="min-width: 150px;">Req. Date <span class="text-rose-500">*</span></th>
                                        <th class="px-3 py-2.5" style="min-width: 150px;">ETA <span class="text-rose-500">*</span></th>
                                        <th class="px-3 py-2.5" style="min-width: 100px;">Qty <span class="text-rose-500">*</span></th>
                                        <th class="px-3 py-2.5" style="min-width: 120px;">Str. Loc</th>
                                        <th class="px-3 py-2.5" style="min-width: 120px;">Supply Area</th>
                                        <th class="px-3 py-2.5" style="min-width: 110px;">Status Cek</th>
                                        <th class="px-3 py-2.5" style="min-width: 210px;">Remark</th>
                                        <th class="px-3 py-2.5 text-center" style="width: 60px; min-width: 60px;">Hapus</th>
                                    </tr>
                                </thead>
                                <tbody id="preview-kanban-rows-body" class="divide-y divide-slate-100">
                                    <!-- Dynamic preview rows inserted via JS -->
                                </tbody>
                            </table>
                        </div>

                        <!-- Action Controls: Add Row & Final Submit -->
                        <div class="flex items-center justify-between pt-1 flex-wrap gap-2">
                            <button type="button" onclick="addPreviewKanbanRow()" class="btn-secondary py-1.5 px-3 text-xs flex items-center">
                                <svg class="w-3.5 h-3.5 mr-1 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                                </svg>
                                + Tambah Baris Manual ke Review
                            </button>

                            <div class="flex items-center space-x-2">
                                <a href="<?= base_url('modules/kanban/index.php') ?>" class="btn-secondary py-1.5 px-4 text-xs">Batal</a>
                                <button type="submit" id="btn-submit-preview" class="btn-primary py-1.5 px-5 text-xs font-bold shadow-md shadow-blue-600/20">
                                    ✓ Simpan Baris Baru ke Database
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

            </div>

        </div>

    </main>

    <script>
    var masterPartsData = <?= json_encode($master_parts) ?>;
    var masterCustomersData = <?= json_encode($master_customers) ?>;
    var rowIndex = 0;

    document.addEventListener("DOMContentLoaded", function() {
        addMultipleKanbanRows(3);
    });

    function switchTab(tab) {
        var btnManual = document.getElementById('tab-btn-manual');
        var btnExcel = document.getElementById('tab-btn-excel');
        var contentManual = document.getElementById('tab-content-manual');
        var contentExcel = document.getElementById('tab-content-excel');

        if (tab === 'manual') {
            btnManual.className = 'px-4 py-2 text-xs font-bold rounded-lg transition-all duration-200 bg-blue-600 text-white shadow-xs cursor-pointer';
            btnExcel.className = 'px-4 py-2 text-xs font-bold rounded-lg transition-all duration-200 text-slate-600 hover:text-slate-900 hover:bg-slate-200/70 cursor-pointer';
            contentManual.classList.remove('hidden');
            contentExcel.classList.add('hidden');
        } else {
            btnExcel.className = 'px-4 py-2 text-xs font-bold rounded-lg transition-all duration-200 bg-blue-600 text-white shadow-xs cursor-pointer';
            btnManual.className = 'px-4 py-2 text-xs font-bold rounded-lg transition-all duration-200 text-slate-600 hover:text-slate-900 hover:bg-slate-200/70 cursor-pointer';
            contentExcel.classList.remove('hidden');
            contentManual.classList.add('hidden');
        }
    }

    function addMultipleKanbanRows(count) {
        for (var i = 0; i < count; i++) {
            addKanbanRow();
        }
    }

    function onPlanTypeChange(val) {
        var docNumInput = document.getElementById('manual-doc-num');
        var isSafetyStock = (val === 'safety_stock');

        if (docNumInput) {
            var dateStr = new Date().toISOString().replace(/[-T:.Z]/g, "").slice(0, 14);
            docNumInput.value = (isSafetyStock ? 'STOCK-' : 'KANBAN-') + dateStr;
        }

        var tableEl = document.getElementById('kanban-items-table');
        if (tableEl) {
            tableEl.style.minWidth = isSafetyStock ? '900px' : '1650px';
        }

        // Toggle column headers and cells visibility
        var kanbanCols = document.querySelectorAll('.col-kanban-only');
        kanbanCols.forEach(function(col) {
            if (isSafetyStock) {
                col.style.display = 'none';
            } else {
                col.style.display = '';
            }
        });

        var tbody = document.getElementById('kanban-rows-body');
        if (tbody) {
            var rows = tbody.querySelectorAll('tr');
            rows.forEach(function(tr) {
                var idx = tr.id.replace('row-', '');
                var knInput = tr.querySelector('input[name="rows[' + idx + '][kanban_no]"]');
                var custSel = document.getElementById('cust-select-' + idx);
                var reqInput = tr.querySelector('input[name="rows[' + idx + '][req_date]"]');
                var etaInput = tr.querySelector('input[name="rows[' + idx + '][eta]"]');

                if (isSafetyStock) {
                    if (knInput) { knInput.required = false; }
                    if (custSel) { custSel.required = false; }
                    if (reqInput) { reqInput.required = false; }
                    if (etaInput) { etaInput.required = false; }
                } else {
                    if (knInput) { knInput.required = true; }
                    if (custSel) { custSel.required = true; }
                    if (reqInput) { reqInput.required = true; }
                    if (etaInput) { etaInput.required = true; }
                }
            });
        }
    }

    function addKanbanRow() {
        rowIndex++;
        var tbody = document.getElementById('kanban-rows-body');
        var tr = document.createElement('tr');
        tr.id = 'row-' + rowIndex;
        tr.className = 'hover:bg-slate-50/80 transition-colors';

        var isSafetyStock = false;
        var displayStyle = '';

        // Parts options
        var partOptions = '<option value="">-- Pilih Part --</option>';
        for (var j = 0; j < masterPartsData.length; j++) {
            var p = masterPartsData[j];
            partOptions += '<option value="' + escapeHtml(p.part_code) + '" data-name="' + escapeHtml(p.part_name) + '">' + escapeHtml(p.part_code) + '</option>';
        }
        partOptions += '<option value="custom">Input Custom Item Code</option>';

        // Customers options
        var custOptions = '<option value="">-- Pilih Customer --</option>';
        for (var k = 0; k < masterCustomersData.length; k++) {
            var c = masterCustomersData[k];
            custOptions += '<option value="' + escapeHtml(c.name) + '">' + escapeHtml(c.name) + '</option>';
        }
        custOptions += '<option value="custom">Input Custom PT</option>';

        // Default req date & time (now)
        var todayDate = new Date();
        todayDate.setMinutes(todayDate.getMinutes() - todayDate.getTimezoneOffset());
        var todayDateTime = todayDate.toISOString().slice(0, 16);

        tr.innerHTML = `
            <td class="px-3 py-2.5 text-center font-bold text-slate-400 row-number" style="width: 40px; min-width: 40px;">${tbody.children.length + 1}</td>
            <td class="px-3 py-2.5 col-kanban-only" style="min-width: 170px; ${displayStyle}">
                <input type="text" name="rows[${rowIndex}][kanban_no]" placeholder="0004573892" class="form-input py-1 text-xs font-mono font-bold" ${isSafetyStock ? '' : 'required'}>
            </td>
            <td class="px-3 py-2.5" style="min-width: 190px;">
                <input type="text" name="rows[${rowIndex}][item_code]" list="master-parts-list" oninput="onItemCodeInput(this, ${rowIndex})" placeholder="Ketik / Cari Item Code..." class="form-input py-1 text-xs font-mono font-bold" required autocomplete="off">
            </td>
            <td class="px-3 py-2.5" style="min-width: 220px;">
                <input type="text" name="rows[${rowIndex}][item_description]" id="item-desc-${rowIndex}" placeholder="Item Description" class="form-input py-1 text-xs" required>
            </td>
            <td class="px-3 py-2.5 col-kanban-only" style="min-width: 240px; ${displayStyle}">
                <select name="rows[${rowIndex}][customer_select]" id="cust-select-${rowIndex}" onchange="onCustomerSelectChange(this, ${rowIndex})" class="form-input py-1 text-xs font-semibold" ${isSafetyStock ? '' : 'required'}>
                    ${custOptions}
                </select>
                <input type="text" name="rows[${rowIndex}][customer_custom]" id="custom-cust-${rowIndex}" placeholder="Nama PT Customer Baru" class="form-input py-1 text-xs font-semibold mt-1 hidden">
            </td>
            <td class="px-3 py-2.5 col-kanban-only" style="min-width: 170px; ${displayStyle}">
                <input type="datetime-local" name="rows[${rowIndex}][req_date]" value="${todayDateTime}" class="form-input py-1 text-xs font-semibold" ${isSafetyStock ? '' : 'required'}>
            </td>
            <td class="px-3 py-2.5 col-kanban-only" style="min-width: 170px; ${displayStyle}">
                <input type="datetime-local" name="rows[${rowIndex}][eta]" value="${todayDateTime}" class="form-input py-1 text-xs font-semibold" ${isSafetyStock ? '' : 'required'}>
            </td>
            <td class="px-3 py-2.5" style="min-width: 110px;">
                <input type="number" name="rows[${rowIndex}][qty]" value="500" min="1" placeholder="Qty" class="form-input py-1 text-xs font-bold text-center" required>
            </td>
            <td class="px-3 py-2.5 col-kanban-only" style="min-width: 120px; ${displayStyle}">
                <input type="text" name="rows[${rowIndex}][str_loc]" value="" placeholder="Str. Loc" class="form-input py-1 text-xs font-mono">
            </td>
            <td class="px-3 py-2.5 col-kanban-only" style="min-width: 120px; ${displayStyle}">
                <input type="text" name="rows[${rowIndex}][supply_area]" value="" placeholder="Supply Area" class="form-input py-1 text-xs font-mono">
            </td>
            <td class="px-3 py-2.5" style="min-width: 110px;">
                <input type="text" name="rows[${rowIndex}][check_type]" placeholder="100% / -" class="form-input py-1 text-xs font-bold text-center">
            </td>
            <td class="px-3 py-2.5" style="min-width: 230px;">
                <textarea name="rows[${rowIndex}][remark]" rows="2" placeholder="Catatan (opsional)" class="form-input py-1 text-xs resize-y" style="min-height: 52px;"></textarea>
            </td>
            <td class="px-3 py-2.5 text-center" style="width: 60px; min-width: 60px;">
                <button type="button" onclick="removeKanbanRow(${rowIndex})" class="p-1 text-rose-500 hover:text-rose-700 hover:bg-rose-50 rounded" title="Hapus Baris">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                    </svg>
                </button>
            </td>
        `;

        tbody.appendChild(tr);

        // Pre-select PT. EPSON INDONESIA if available and not safety stock
        if (!isSafetyStock && masterCustomersData.length > 0) {
            var custSel = document.getElementById('cust-select-' + rowIndex);
            if (custSel) {
                var epsonOpt = Array.from(custSel.options).find(function(o) { return o.value.toUpperCase().includes('EPSON'); });
                if (epsonOpt) {
                    custSel.value = epsonOpt.value;
                } else {
                    custSel.value = masterCustomersData[0].name;
                }
            }
        }

        updateRowCounter();
    }

    function onCustomerSelectChange(select, idx) {
        var customInput = document.getElementById('custom-cust-' + idx);
        if (select.value === 'custom') {
            customInput.classList.remove('hidden');
            customInput.required = true;
        } else {
            customInput.classList.add('hidden');
            customInput.required = false;
        }
    }

    function removeKanbanRow(idx) {
        var row = document.getElementById('row-' + idx);
        if (row) {
            row.parentNode.removeChild(row);
            updateRowNumbers();
            updateRowCounter();
        }
    }

    function onItemCodeInput(input, idx) {
        var val = input.value.trim().toUpperCase();
        var descInput = document.getElementById('item-desc-' + idx);
        if (!descInput) return;

        for (var i = 0; i < masterPartsData.length; i++) {
            if (masterPartsData[i].part_code.toUpperCase() === val) {
                descInput.value = masterPartsData[i].part_name;
                return;
            }
        }
    }

    var previewRowIndex = 0;
    var activePreviewFilter = 'all';

    function previewExcelKanbanFile() {
        var fileInput = document.getElementById('excel-file-input');
        if (!fileInput.files || fileInput.files.length === 0) {
            alert('Silakan pilih file Excel (.xlsx / .csv) terlebih dahulu!');
            return;
        }

        var formData = new FormData();
        formData.append('excel_file', fileInput.files[0]);

        document.getElementById('excel-loading').classList.remove('hidden');
        document.getElementById('excel-preview-container').classList.add('hidden');

        fetch('<?= base_url("modules/kanban/preview_excel.php") ?>', {
            method: 'POST',
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            document.getElementById('excel-loading').classList.add('hidden');
            if (!data.success) {
                alert('Gagal membaca file: ' + data.message);
                return;
            }

            renderExcelPreviewTable(data);
        })
        .catch(function(err) {
            document.getElementById('excel-loading').classList.add('hidden');
            alert('Terjadi kesalahan koneksi server saat membaca file Excel!');
        });
    }

    function renderExcelPreviewTable(data) {
        var sheetInfo = (data.processed_sheets && data.processed_sheets.length > 0) ? data.processed_sheets.length + ' Sheet (' + data.processed_sheets.join(', ') + ')' : (data.sheet_name || 'kanban');
        document.getElementById('preview-sheetname').textContent = 'Tab Diproses: ' + sheetInfo;
        
        var vendorVal = data.vendor || '228746';
        var printDtVal = data.print_datetime || '';
        document.getElementById('preview-vendor-val').textContent = vendorVal;
        document.getElementById('preview-vendor-input').value = vendorVal;
        document.getElementById('preview-printdt-val').textContent = printDtVal ? printDtVal : '-';
        document.getElementById('preview-printdt-input').value = printDtVal;

        var totalRows = data.total_parsed || 0;
        var totalNew  = (typeof data.total_new !== 'undefined') ? data.total_new : totalRows;
        var totalDup  = (typeof data.total_duplicate !== 'undefined') ? data.total_duplicate : 0;
        var totalQtyChange = (typeof data.total_qty_change !== 'undefined') ? data.total_qty_change : 0;

        document.getElementById('count-card-all').textContent = totalRows;
        document.getElementById('count-card-new').textContent = totalNew;
        document.getElementById('count-card-qty_change').textContent = totalQtyChange;
        document.getElementById('count-card-dup').textContent = totalDup;
        document.getElementById('preview-skipped-rows').textContent = data.total_skipped || 0;

        // Toggle bulk action banner visibility & counter
        var bulkBanner = document.getElementById('bulk-qty-banner');
        var bannerQtyCount = document.getElementById('banner-qtychange-count');
        if (bulkBanner) {
            if (totalQtyChange > 0) {
                bulkBanner.classList.remove('hidden');
                if (bannerQtyCount) bannerQtyCount.textContent = totalQtyChange;
            } else {
                bulkBanner.classList.add('hidden');
            }
        }

        var submitBtn = document.getElementById('btn-submit-preview');
        var submitBtnTop = document.getElementById('btn-submit-preview-top');

        var updateSubmitBtnState = function(btn) {
            if (!btn) return;
            var actionableCount = totalNew + totalQtyChange;
            if (actionableCount > 0) {
                btn.disabled = false;
                btn.className = 'btn-primary py-1.5 px-5 text-xs font-bold shadow-md shadow-blue-600/20 cursor-pointer';
                var btnText = '✓ Simpan ' + actionableCount + ' Data ke Database';
                if (totalQtyChange > 0) {
                    btnText = '✓ Simpan (' + totalNew + ' Baru, ' + totalQtyChange + ' Revisi Qty)';
                }
                btn.innerHTML = btnText;
            } else {
                btn.disabled = true;
                btn.className = 'py-1.5 px-5 text-xs font-bold rounded-lg bg-slate-300 text-slate-500 cursor-not-allowed';
                btn.innerHTML = '⚠️ Semua Baris Duplikat (0 Data Baru)';
            }
        };

        updateSubmitBtnState(submitBtn);
        updateSubmitBtnState(submitBtnTop);


        var tbody = document.getElementById('preview-kanban-rows-body');
        previewRowIndex = 0;

        // Pre-build Customer Select Options Template once
        var defaultCust = (masterCustomersData.length > 0) ? masterCustomersData[0].name : '';
        var epsonOptVal = '';
        var custOptionsHtml = '<option value="">-- Pilih Customer --</option>';
        for (var k = 0; k < masterCustomersData.length; k++) {
            var cName = masterCustomersData[k].name;
            var isEpson = cName.toUpperCase().includes('EPSON');
            if (isEpson && !epsonOptVal) epsonOptVal = cName;
            var isSelected = (isEpson || (!epsonOptVal && k === 0)) ? 'selected' : '';
            custOptionsHtml += '<option value="' + escapeHtml(cName) + '" ' + isSelected + '>' + escapeHtml(cName) + '</option>';
        }
        custOptionsHtml += '<option value="custom">Input Custom PT</option>';

        // Single Batch String Injection (Extremely fast, < 30ms for 1000 rows)
        var htmlChunks = [];
        for (var i = 0; i < data.rows.length; i++) {
            var r = data.rows[i];
            previewRowIndex++;

            var isDup = (r.is_duplicate === true);
            var isQtyChange = (r.is_qty_change === true);

            var rowType = isDup ? 'dup' : (isQtyChange ? 'qty_change' : 'new');
            var rowClass = 'hover:bg-slate-50/80 transition-colors';
            if (isDup) {
                rowClass = 'hover:bg-amber-50/80 bg-amber-50/40 is-duplicate-row transition-colors';
            } else if (isQtyChange) {
                rowClass = 'hover:bg-sky-50/90 bg-sky-50/50 is-qtychange-row transition-colors';
            } else {
                rowClass = 'hover:bg-slate-50/80 is-new-row transition-colors';
            }

            var statusBadge = '';
            var actionControlHtml = '';

            if (isDup) {
                statusBadge = '<span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-900 border border-amber-300" title="' + escapeHtml(r.duplicate_source || 'Duplikat') + '">⚠️ Duplikat</span>';
            } else if (isQtyChange) {
                var diffSign = r.qty_diff > 0 ? '+' : '';
                var isDecrease = (r.qty_diff < 0);
                var isBelowPassed = (r.new_qty < r.passed_qty);

                var stColor = (r.current_status === 'completed') ? 'bg-emerald-100 text-emerald-800 border-emerald-300' : 'bg-amber-100 text-amber-800 border-amber-300';
                var stLabel = (r.current_status === 'completed') ? 'Sudah Selesai' : ((r.current_status === 'in_progress') ? 'Sedang Dikerjakan' : ((r.current_status === 'partial') ? 'Parsial' : 'Belum Mulai'));

                // Alert badge if target is below inspected passed items
                var warningBadge = '';
                if (isBelowPassed) {
                    warningBadge = `
                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[8.5px] font-black bg-rose-100 text-rose-800 border border-rose-300 text-center" title="Target baru lebih kecil daripada barang yang sudah lolos QC!">
                            ⚠️ Target (${r.new_qty}) &lt; QC Lolos (${r.passed_qty})
                        </span>
                    `;
                }

                // If decrease, cannot create new row with negative qty
                var createRowOption = '';
                if (!isDecrease) {
                    createRowOption = `<option value="create_new_row">➕ Buat Baris Baru (${diffSign}${r.qty_diff} pcs)</option>`;
                }

                statusBadge = `
                    <div class="flex flex-col items-center gap-1">
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-extrabold bg-sky-100 text-sky-900 border border-sky-300 shadow-2xs whitespace-nowrap">
                            ${isDecrease ? '🔻 Qty Berkurang' : '🔄 Qty Bertambah'} (${r.old_qty} → ${r.new_qty})
                        </span>
                        <span class="inline-flex items-center px-1.5 py-0.2 rounded text-[8.5px] font-bold border ${stColor}">
                            DB: ${stLabel} (${r.passed_qty}/${r.old_qty})
                        </span>
                        ${warningBadge}
                        <div class="mt-0.5">
                            <select class="qty-action-select text-[10.5px] font-bold py-0.5 px-1.5 rounded border border-sky-300 bg-white text-sky-900 focus:ring-1 focus:ring-sky-500 cursor-pointer shadow-2xs" 
                                    data-row-idx="${previewRowIndex}"
                                    data-existing-id="${r.existing_id || 0}"
                                    data-old-qty="${r.old_qty || 0}"
                                    data-new-qty="${r.new_qty || 0}"
                                    data-current-status="${escapeHtml(r.current_status || '')}"
                                    data-passed-qty="${r.passed_qty || 0}"
                                    data-is-decrease="${isDecrease ? '1' : '0'}"
                                    data-is-below-passed="${isBelowPassed ? '1' : '0'}"
                                    onchange="onRowActionChange(this, ${previewRowIndex})">
                                <option value="update_qty" selected>🔄 Update Target Qty (${r.new_qty} pcs)</option>
                                ${createRowOption}
                                <option value="skip">⏭️ Abaikan (Pakai Qty Lama)</option>
                            </select>
                        </div>
                    </div>
                `;
            } else {
                statusBadge = '<span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-900 border border-emerald-300">✓ Baru</span>';
            }

            var searchString = (r.kanban_no + ' ' + r.item_code + ' ' + r.item_description + ' ' + (r.str_loc || '') + ' ' + (r.supply_area || '')).toLowerCase();

            htmlChunks.push(`
                <tr id="preview-row-${previewRowIndex}" class="${rowClass}" data-row-type="${rowType}" data-duplicate="${isDup ? '1' : '0'}" data-qtychange="${isQtyChange ? '1' : '0'}" data-search="${escapeHtml(searchString)}">
                    <td class="px-3 py-2 text-center font-bold text-slate-400 preview-row-number" style="width: 40px; min-width: 40px;">${previewRowIndex}</td>
                    <td class="px-3 py-2 text-center" style="width: 170px; min-width: 170px;">${statusBadge}</td>
                    <td class="px-3 py-2" style="min-width: 160px;">
                        <input type="text" name="rows[${previewRowIndex}][kanban_no]" value="${escapeHtml(r.kanban_no)}" class="form-input py-1 text-xs font-mono font-bold" required>
                    </td>
                    <td class="px-3 py-2" style="min-width: 180px;">
                        <input type="text" name="rows[${previewRowIndex}][item_code]" value="${escapeHtml(r.item_code)}" list="master-parts-list" oninput="onItemCodeInput(this, 'prev-${previewRowIndex}')" class="form-input py-1 text-xs font-mono font-bold" required autocomplete="off">
                    </td>
                    <td class="px-3 py-2" style="min-width: 220px;">
                        <input type="text" name="rows[${previewRowIndex}][item_description]" id="item-desc-prev-${previewRowIndex}" value="${escapeHtml(r.item_description)}" class="form-input py-1 text-xs" required>
                    </td>
                    <td class="px-3 py-2" style="min-width: 240px;">
                        <select name="rows[${previewRowIndex}][customer_select]" id="cust-select-prev-${previewRowIndex}" onchange="onCustomerSelectChange(this, 'prev-${previewRowIndex}')" class="form-input py-1 text-xs font-semibold" required>
                            ${custOptionsHtml}
                        </select>
                        <input type="text" name="rows[${previewRowIndex}][customer_custom]" id="custom-cust-prev-${previewRowIndex}" placeholder="Nama PT Customer Baru" class="form-input py-1 text-xs font-semibold mt-1 hidden">
                    </td>
                    <td class="px-3 py-2" style="min-width: 150px;">
                        <input type="text" name="rows[${previewRowIndex}][req_date]" value="${escapeHtml(r.req_date)}" placeholder="YYYY-MM-DD HH:MM:SS" class="form-input py-1 text-xs font-semibold" required>
                    </td>
                    <td class="px-3 py-2" style="min-width: 150px;">
                        <input type="text" name="rows[${previewRowIndex}][eta]" value="${escapeHtml(r.eta)}" placeholder="YYYY-MM-DD HH:MM:SS" class="form-input py-1 text-xs font-semibold" required>
                    </td>
                    <td class="px-3 py-2" style="min-width: 100px;">
                        <input type="number" name="rows[${previewRowIndex}][qty]" value="${r.qty}" min="1" class="form-input py-1 text-xs font-bold text-center" required>
                    </td>
                    <td class="px-3 py-2" style="min-width: 120px;">
                        <input type="text" name="rows[${previewRowIndex}][str_loc]" value="${escapeHtml(r.str_loc || '')}" placeholder="Str. Loc" class="form-input py-1 text-xs font-mono">
                    </td>
                    <td class="px-3 py-2" style="min-width: 120px;">
                        <input type="text" name="rows[${previewRowIndex}][supply_area]" value="${escapeHtml(r.supply_area || '')}" placeholder="Supply Area" class="form-input py-1 text-xs font-mono">
                    </td>
                    <td class="px-3 py-2" style="min-width: 110px;">
                        <input type="text" name="rows[${previewRowIndex}][check_type]" value="${escapeHtml(r.check_type || '')}" placeholder="100% / -" class="form-input py-1 text-xs font-bold text-center">
                    </td>
                    <td class="px-3 py-2" style="min-width: 210px;">
                        <textarea name="rows[${previewRowIndex}][remark]" rows="1" placeholder="Catatan" class="form-input py-1 text-xs resize-y" style="min-height: 38px;">${escapeHtml(r.remark)}</textarea>
                    </td>
                    <td class="px-3 py-2 text-center" style="width: 60px; min-width: 60px;">
                        <button type="button" onclick="removePreviewKanbanRow(${previewRowIndex})" class="p-1 text-rose-500 hover:text-rose-700 hover:bg-rose-50 rounded cursor-pointer" title="Hapus Baris">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                            </svg>
                        </button>
                    </td>
                </tr>
            `);
        }

        tbody.innerHTML = htmlChunks.join('');
        document.getElementById('excel-preview-container').classList.remove('hidden');
        document.getElementById('preview-search-input').value = '';
        setPreviewFilter('all');
    }

    function setAllQtyChangeActions(action) {
        var selects = document.querySelectorAll('.qty-action-select');
        selects.forEach(function(sel) {
            var isDecrease = (sel.getAttribute('data-is-decrease') === '1');
            // Jika aksi yang dipilih adalah create_new_row, tapi baris ini kuantitasnya berkurang, biarkan tetap update_qty
            if (action === 'create_new_row' && isDecrease) {
                sel.value = 'update_qty';
            } else {
                sel.value = action;
            }
            var rowIdx = sel.getAttribute('data-row-idx');
            onRowActionChange(sel, rowIdx);
        });
    }

    function onRowActionChange(sel, rowIdx) {
        var tr = document.getElementById('preview-row-' + rowIdx);
        if (!tr) return;
        var act = sel.value;
        if (act === 'skip') {
            tr.classList.add('opacity-50', 'line-through');
        } else {
            tr.classList.remove('opacity-50', 'line-through');
        }
    }

    function setPreviewFilter(filterType) {
        activePreviewFilter = filterType;

        ['all', 'new', 'qty_change', 'dup'].forEach(function(f) {
            var card = document.getElementById('card-filter-' + f);
            if (card) {
                if (f === filterType) {
                    card.style.transform = 'translateY(-2px)';
                    card.style.boxShadow = '0 6px 14px rgba(0, 0, 0, 0.08), 0 0 0 2px rgba(37, 99, 235, 0.35)';
                    card.style.opacity = '1';
                } else {
                    card.style.transform = 'translateY(0)';
                    card.style.boxShadow = '0 1px 2px rgba(0, 0, 0, 0.04)';
                    card.style.opacity = '0.75';
                }
            }
        });
        filterPreviewRows();
    }

    function filterPreviewRows() {
        var query = document.getElementById('preview-search-input').value.toLowerCase().trim();
        var tbody = document.getElementById('preview-kanban-rows-body');
        var rows = tbody.children;
        var visibleCount = 0;

        for (var i = 0; i < rows.length; i++) {
            var tr = rows[i];
            var isDup = (tr.getAttribute('data-duplicate') === '1');
            var isQtyChange = (tr.getAttribute('data-qtychange') === '1');

            var matchFilter = true;
            if (activePreviewFilter === 'new' && (isDup || isQtyChange)) matchFilter = false;
            if (activePreviewFilter === 'qty_change' && !isQtyChange) matchFilter = false;
            if (activePreviewFilter === 'dup' && (!isDup || isQtyChange)) matchFilter = false;

            if (!matchFilter) {
                tr.style.display = 'none';
                continue;
            }

            if (query === '') {
                tr.style.display = '';
                visibleCount++;
            } else {
                var searchTarget = tr.getAttribute('data-search') || tr.textContent.toLowerCase();
                if (searchTarget.indexOf(query) !== -1) {
                    tr.style.display = '';
                    visibleCount++;
                } else {
                    tr.style.display = 'none';
                }
            }
        }

        document.getElementById('preview-visible-count').textContent = visibleCount;
        document.getElementById('preview-total-count').textContent = rows.length;
    }

    function addPreviewKanbanRow() {
        previewRowIndex++;
        var tbody = document.getElementById('preview-kanban-rows-body');
        var tr = document.createElement('tr');
        tr.id = 'preview-row-' + previewRowIndex;
        tr.className = 'hover:bg-slate-50/80 is-new-row transition-colors';
        tr.setAttribute('data-duplicate', '0');
        tr.setAttribute('data-qtychange', '0');

        var custOptionsHtml = '<option value="">-- Pilih Customer --</option>';
        for (var k = 0; k < masterCustomersData.length; k++) {
            var c = masterCustomersData[k];
            custOptionsHtml += '<option value="' + escapeHtml(c.name) + '">' + escapeHtml(c.name) + '</option>';
        }
        custOptionsHtml += '<option value="custom">Input Custom PT</option>';

        var todayDate = new Date().toISOString().slice(0, 19).replace('T', ' ');

        tr.innerHTML = `
            <td class="px-3 py-2 text-center font-bold text-slate-400 preview-row-number" style="width: 40px; min-width: 40px;">${tbody.children.length + 1}</td>
            <td class="px-3 py-2 text-center" style="width: 170px; min-width: 170px;">
                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-900 border border-emerald-300">✓ Baru</span>
            </td>
            <td class="px-3 py-2" style="min-width: 160px;">
                <input type="text" name="rows[${previewRowIndex}][kanban_no]" placeholder="0004573892" class="form-input py-1 text-xs font-mono font-bold" required>
            </td>
            <td class="px-3 py-2" style="min-width: 180px;">
                <input type="text" name="rows[${previewRowIndex}][item_code]" list="master-parts-list" oninput="onItemCodeInput(this, 'prev-${previewRowIndex}')" placeholder="Ketik / Cari Item Code..." class="form-input py-1 text-xs font-mono font-bold" required autocomplete="off">
            </td>
            <td class="px-3 py-2" style="min-width: 220px;">
                <input type="text" name="rows[${previewRowIndex}][item_description]" id="item-desc-prev-${previewRowIndex}" placeholder="Item Description" class="form-input py-1 text-xs" required>
            </td>
            <td class="px-3 py-2" style="min-width: 240px;">
                <select name="rows[${previewRowIndex}][customer_select]" id="cust-select-prev-${previewRowIndex}" onchange="onCustomerSelectChange(this, 'prev-${previewRowIndex}')" class="form-input py-1 text-xs font-semibold" required>
                    ${custOptionsHtml}
                </select>
                <input type="text" name="rows[${previewRowIndex}][customer_custom]" id="custom-cust-prev-${previewRowIndex}" placeholder="Nama PT Customer Baru" class="form-input py-1 text-xs font-semibold mt-1 hidden">
            </td>
            <td class="px-3 py-2" style="min-width: 150px;">
                <input type="text" name="rows[${previewRowIndex}][req_date]" value="${todayDate}" placeholder="YYYY-MM-DD HH:MM:SS" class="form-input py-1 text-xs font-semibold" required>
            </td>
            <td class="px-3 py-2" style="min-width: 150px;">
                <input type="text" name="rows[${previewRowIndex}][eta]" value="${todayDate}" placeholder="YYYY-MM-DD HH:MM:SS" class="form-input py-1 text-xs font-semibold" required>
            </td>
            <td class="px-3 py-2" style="min-width: 100px;">
                <input type="number" name="rows[${previewRowIndex}][qty]" value="500" min="1" placeholder="Qty" class="form-input py-1 text-xs font-bold text-center" required>
            </td>
            <td class="px-3 py-2" style="min-width: 120px;">
                <input type="text" name="rows[${previewRowIndex}][str_loc]" value="" placeholder="Str. Loc" class="form-input py-1 text-xs font-mono">
            </td>
            <td class="px-3 py-2" style="min-width: 120px;">
                <input type="text" name="rows[${previewRowIndex}][supply_area]" value="" placeholder="Supply Area" class="form-input py-1 text-xs font-mono">
            </td>
            <td class="px-3 py-2" style="min-width: 110px;">
                <input type="text" name="rows[${previewRowIndex}][check_type]" placeholder="100% / -" class="form-input py-1 text-xs font-bold text-center">
            </td>
            <td class="px-3 py-2" style="min-width: 210px;">
                <textarea name="rows[${previewRowIndex}][remark]" rows="1" placeholder="Catatan (opsional)" class="form-input py-1 text-xs resize-y" style="min-height: 38px;"></textarea>
            </td>
            <td class="px-3 py-2 text-center" style="width: 60px; min-width: 60px;">
                <button type="button" onclick="removePreviewKanbanRow(${previewRowIndex})" class="p-1 text-rose-500 hover:text-rose-700 hover:bg-rose-50 rounded cursor-pointer" title="Hapus Baris">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                    </svg>
                </button>
            </td>
        `;

        tbody.appendChild(tr);

        if (masterCustomersData.length > 0) {
            var sel = document.getElementById('cust-select-prev-' + previewRowIndex);
            if (sel) sel.value = masterCustomersData[0].name;
        }

        filterPreviewRows();
        updatePreviewRowNumbers();
    }

    function removePreviewKanbanRow(idx) {
        var row = document.getElementById('preview-row-' + idx);
        if (row) {
            row.parentNode.removeChild(row);
            updatePreviewRowNumbers();
            filterPreviewRows();
        }
    }

    function updatePreviewRowNumbers() {
        var tbody = document.getElementById('preview-kanban-rows-body');
        var rows = tbody.querySelectorAll('tr');
        for (var i = 0; i < rows.length; i++) {
            var numCell = rows[i].querySelector('.preview-row-number');
            if (numCell) numCell.textContent = i + 1;
        }
    }

    function updateRowNumbers() {
        var tbody = document.getElementById('kanban-rows-body');
        var rows = tbody.querySelectorAll('tr');
        for (var i = 0; i < rows.length; i++) {
            var numCell = rows[i].querySelector('.row-number');
            if (numCell) numCell.textContent = i + 1;
        }
    }

    function updateRowCounter() {
        var tbody = document.getElementById('kanban-rows-body');
        var count = tbody.querySelectorAll('tr').length;
        document.getElementById('row-counter').textContent = count;
    }

    function prepareReviewFormSubmit(e) {
        var tbody = document.getElementById('preview-kanban-rows-body');
        var rows = tbody.querySelectorAll('tr');
        var data = [];
        var skippedDuplicates = 0;
        var skippedQtyChanges = 0;
        var completedToReopenCount = 0;

        for (var i = 0; i < rows.length; i++) {
            var tr = rows[i];
            var isDup = (tr.getAttribute('data-duplicate') === '1');
            var isQtyChange = (tr.getAttribute('data-qtychange') === '1');

            var actSelect = tr.querySelector('.qty-action-select');
            var actionType = 'insert';
            var existingId = 0;
            var oldQty = 0;
            var newQty = 0;
            var currentStatus = '';
            var passedQty = 0;

            if (isQtyChange && actSelect) {
                actionType = actSelect.value; // 'update_qty' | 'create_new_row' | 'skip'
                existingId = parseInt(actSelect.getAttribute('data-existing-id')) || 0;
                oldQty = parseInt(actSelect.getAttribute('data-old-qty')) || 0;
                newQty = parseInt(actSelect.getAttribute('data-new-qty')) || 0;
                currentStatus = actSelect.getAttribute('data-current-status') || '';
                passedQty = parseInt(actSelect.getAttribute('data-passed-qty')) || 0;

                if (actionType === 'skip') {
                    skippedQtyChanges++;
                    continue;
                }

                if (actionType === 'update_qty' && currentStatus === 'completed') {
                    completedToReopenCount++;
                }
            } else if (isDup) {
                skippedDuplicates++;
                continue; // Automatically skip exact duplicate rows from being inserted
            }

            var kanbanNoInput = tr.querySelector('input[name*="[kanban_no]"]');
            var itemCodeInput = tr.querySelector('input[name*="[item_code]"]');
            var itemDescInput = tr.querySelector('input[name*="[item_description]"]');
            var custSelect    = tr.querySelector('select[name*="[customer_select]"]');
            var custCustom    = tr.querySelector('input[name*="[customer_custom]"]');
            var reqDateInput  = tr.querySelector('input[name*="[req_date]"]');
            var etaInput      = tr.querySelector('input[name*="[eta]"]');
            var qtyInput      = tr.querySelector('input[name*="[qty]"]');
            var strLocInput   = tr.querySelector('input[name*="[str_loc]"]');
            var supplyAreaInput = tr.querySelector('input[name*="[supply_area]"]');
            var checkTypeInput = tr.querySelector('input[name*="[check_type]"]');
            var remarkInput   = tr.querySelector('textarea[name*="[remark]"]');

            if (!kanbanNoInput || !itemCodeInput) continue;

            var kNo = kanbanNoInput.value.trim();
            var iCode = itemCodeInput.value.trim();
            if (!kNo || !iCode) continue;

            var custVal = '';
            if (custSelect) {
                custVal = (custSelect.value === 'custom') ? (custCustom ? custCustom.value.trim() : '') : custSelect.value;
            }
            if (!custVal) custVal = 'PT. Astra Honda Motor';

            var submitQty = qtyInput ? (parseInt(qtyInput.value) || 1) : 1;
            if (isQtyChange && actionType === 'create_new_row') {
                // If creating a new split row for qty difference, default to difference if valid
                var diff = submitQty - oldQty;
                if (diff > 0) {
                    submitQty = diff;
                }
            }

            data.push({
                action_type: actionType,
                existing_id: existingId,
                old_qty: oldQty,
                new_qty: newQty,
                current_status: currentStatus,
                passed_qty: passedQty,
                kanban_no: kNo,
                item_code: iCode,
                item_description: itemDescInput ? itemDescInput.value.trim() : iCode,
                customer: custVal,
                req_date: reqDateInput ? reqDateInput.value : '',
                eta: etaInput ? etaInput.value : '',
                qty: submitQty,
                str_loc: strLocInput ? strLocInput.value.trim() : '',
                supply_area: supplyAreaInput ? supplyAreaInput.value.trim() : '',
                check_type: checkTypeInput ? checkTypeInput.value.trim() : '',
                remark: remarkInput ? remarkInput.value.trim() : ''
            });
        }

        if (data.length === 0) {
            if (e) e.preventDefault();
            var skipMsg = [];
            if (skippedDuplicates > 0) skipMsg.push(skippedDuplicates + ' baris duplikat');
            if (skippedQtyChanges > 0) skipMsg.push(skippedQtyChanges + ' baris perubahan qty diabaikan');
            if (skipMsg.length > 0) {
                alert('Semua baris (' + skipMsg.join(', ') + ') dilewati. Tidak ada data baru atau revisi yang perlu disimpan.');
            } else {
                alert('Tidak ada baris data valid untuk disimpan!');
            }
            return false;
        }

        var belowPassedCount = 0;
        for (var i = 0; i < rows.length; i++) {
            var tr = rows[i];
            var actSelect = tr.querySelector('.qty-action-select');
            if (actSelect && actSelect.value === 'update_qty' && actSelect.getAttribute('data-is-below-passed') === '1') {
                belowPassedCount++;
            }
        }

        // Confirmation warning if target is decreased below passed inspected items
        if (belowPassedCount > 0) {
            var warningPassedMsg = 'Peringatan Khusus:\nAda ' + belowPassedCount + ' Kanban yang target Qty-nya DITURUNKAN di bawah jumlah barang yang SUDAH LOLOS QC fisik!\n\nBarang fisik yang sudah selesai diperiksa QC akan melebihi target baru.\n\nApakah Anda yakin ingin tetap memperbarui target ini?';
            if (!confirm(warningPassedMsg)) {
                if (e) e.preventDefault();
                return false;
            }
        }

        // Confirmation warning if any completed kanban is being reopened to partial
        if (completedToReopenCount > 0) {
            var confirmMsg = 'Perhatian: Ada ' + completedToReopenCount + ' Kanban yang sebelumnya SUDAH SELESAI (Completed) akan diupdate target Qty-nya.\n\nStatusnya akan otomatis dibuka kembali menjadi PARSIAL agar operator QC dapat melanjutkan inspeksi sisa kekurangannya.\n\nApakah Anda yakin ingin melanjutkan?';
            if (!confirm(confirmMsg)) {
                if (e) e.preventDefault();
                return false;
            }
        }

        document.getElementById('kanban_json_data').value = JSON.stringify(data);

        // Strip name attributes from table inputs so browser does not flood $_POST and exceed max_input_vars limit (1000)
        var previewInputs = tbody.querySelectorAll('input, select, textarea');
        for (var k = 0; k < previewInputs.length; k++) {
            previewInputs[k].removeAttribute('name');
        }

        return true;
    }

    function escapeHtml(text) {
        if (!text) return '';
        return String(text).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }
    </script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
