<?php
$breadcrumbCategory = "DATA REFERENSI";
$pageTitle = "Tambah Kanban (Jadwal Kirim)";
$pageSubtitle = "Input beberapa baris data jadwal pengiriman Kanban sekaligus atau via Upload File Excel/CSV";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';

$pdo = getDB();
$master_parts = [];
$master_customers = [];

if ($pdo) {
    try {
        $stmt = $pdo->query("SELECT id, part_code, part_name FROM master_parts ORDER BY part_code ASC");
        $master_parts = $stmt->fetchAll();
        $stmtCust = $pdo->query("SELECT id, name FROM master_customers ORDER BY name ASC");
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
                    <input type="hidden" name="entry_type" value="manual">

                    <!-- Shared Metadata Fields Header -->
                    <div class="p-3 bg-slate-50 border border-slate-200 rounded-xl flex items-center justify-between text-xs flex-wrap gap-2">
                        <div class="flex items-center space-x-3">
                            <div class="flex items-center space-x-1.5">
                                <span class="font-bold text-slate-700">Tipe Planning:</span>
                                <select name="plan_type" id="manual-plan-type" onchange="onPlanTypeChange(this.value)" class="form-input py-1 text-xs font-bold text-blue-800 bg-blue-50 border-blue-200">
                                    <option value="kanban">🚚 Kanban (Pengiriman Customer)</option>
                                    <option value="safety_stock">📦 Safety Stock (Restock Internal)</option>
                                </select>
                            </div>
                            <div class="flex items-center space-x-1.5">
                                <span class="font-bold text-slate-700">No. Dokumen Batch:</span>
                                <input type="text" name="document_number" id="manual-doc-num" value="KANBAN-<?= date('Ymd-His') ?>" placeholder="No. Dokumen" class="form-input py-1 text-xs font-mono max-w-xs">
                            </div>
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
                        <input type="hidden" name="entry_type" value="manual">
                        <input type="hidden" name="kanban_json_data" id="kanban_json_data">

                        <!-- Summary Banner Card -->
                        <div class="p-3 bg-blue-50/80 border border-blue-200/80 rounded-xl flex items-center justify-between flex-wrap gap-2 text-xs">
                            <div class="flex items-center space-x-3 text-slate-700">
                                <span class="font-bold text-blue-900" id="preview-filename">kanban.xlsx</span>
                                <span class="badge badge-blue" id="preview-sheetname">Tab: kanban</span>
                                <span class="text-emerald-700 font-bold">✓ <span id="preview-total-rows">0</span> baris valid dibaca</span>
                                <span class="text-slate-400 text-[11px]">(<span id="preview-skipped-rows">0</span> baris kosong dilewati)</span>
                            </div>
                            <div class="flex items-center space-x-2">
                                <span class="font-bold text-slate-700">No. Dokumen Batch:</span>
                                <input type="text" name="document_number" id="preview-doc-num" value="KANBAN-<?= date('Ymd-His') ?>" class="form-input py-1 text-xs font-mono font-bold max-w-[180px]">
                            </div>
                        </div>

                        <!-- Real-Time Search & Filter Bar -->
                        <div class="flex items-center justify-between gap-3 flex-wrap">
                            <div class="flex-1 min-w-[260px]">
                                <input type="text" id="preview-search-input" onkeyup="filterPreviewRows()" placeholder="Cari cepat Kanban No, Item Code, Part Description, Customer, Str Loc..." class="form-input py-1.5 px-3 text-xs w-full">
                            </div>
                            <div class="text-xs text-slate-500 font-semibold">
                                Menampilkan <b id="preview-visible-count" class="text-blue-700">0</b> / <b id="preview-total-count">0</b> baris item
                            </div>
                        </div>

                        <!-- Interactive Review Matrix Table (Strictly Table Scrollable) -->
                        <div class="w-full max-w-full overflow-x-auto border border-slate-200 rounded-xl shadow-xs" style="width: 100%; max-width: 100%; overflow-x: auto; display: block;">
                            <table class="w-full text-left text-xs text-slate-700" id="preview-kanban-table" style="min-width: 1650px; table-layout: auto;">
                                <thead class="bg-slate-100 text-slate-700 font-bold border-b border-slate-200 text-[11px] uppercase whitespace-nowrap">
                                    <tr>
                                        <th class="px-3 py-2.5 text-center" style="width: 40px; min-width: 40px;">#</th>
                                        <th class="px-3 py-2.5" style="min-width: 170px;">Kanban No <span class="text-rose-500">*</span></th>
                                        <th class="px-3 py-2.5" style="min-width: 190px;">Item Code <span class="text-rose-500">*</span></th>
                                        <th class="px-3 py-2.5" style="min-width: 220px;">Item Description <span class="text-rose-500">*</span></th>
                                        <th class="px-3 py-2.5" style="min-width: 240px;">Customer / Tujuan PT <span class="text-rose-500">*</span></th>
                                        <th class="px-3 py-2.5" style="min-width: 150px;">Req. Date <span class="text-rose-500">*</span></th>
                                        <th class="px-3 py-2.5" style="min-width: 150px;">ETA <span class="text-rose-500">*</span></th>
                                        <th class="px-3 py-2.5" style="min-width: 100px;">Qty <span class="text-rose-500">*</span></th>
                                        <th class="px-3 py-2.5" style="min-width: 120px;">Str. Loc</th>
                                        <th class="px-3 py-2.5" style="min-width: 110px;">Status Cek</th>
                                        <th class="px-3 py-2.5" style="min-width: 230px;">Remark</th>
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
                                + Tambah Baris Baru ke Review
                            </button>

                            <div class="flex items-center space-x-2">
                                <a href="<?= base_url('modules/kanban/index.php') ?>" class="btn-secondary py-1.5 px-4 text-xs">Batal</a>
                                <button type="submit" class="btn-primary py-1.5 px-5 text-xs font-bold shadow-md shadow-blue-600/20">
                                    ✓ Simpan Semua Batch Kanban
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

        var planTypeEl = document.getElementById('manual-plan-type');
        var isSafetyStock = planTypeEl ? (planTypeEl.value === 'safety_stock') : false;
        var displayStyle = isSafetyStock ? 'display: none;' : '';

        // Parts options
        var partOptions = '<option value="">-- Pilih Part --</option>';
        for (var j = 0; j < masterPartsData.length; j++) {
            var p = masterPartsData[j];
            partOptions += '<option value="' + escapeHtml(p.part_code) + '" data-name="' + escapeHtml(p.part_name) + '">' + escapeHtml(p.part_code) + '</option>';
        }
        partOptions += '<option value="custom">Input Custom Item Code</option>';

        // Customers options
        var custOptions = '';
        if (isSafetyStock) {
            custOptions = '<option value="INTERNAL STOCK" selected>-- Belum Ada PT (Safety Stock) --</option>';
        } else {
            custOptions = '<option value="">-- Pilih Customer --</option>';
            for (var k = 0; k < masterCustomersData.length; k++) {
                var c = masterCustomersData[k];
                custOptions += '<option value="' + escapeHtml(c.name) + '">' + escapeHtml(c.name) + '</option>';
            }
            custOptions += '<option value="custom">Input Custom PT</option>';
        }

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
                <input type="text" name="rows[${rowIndex}][str_loc]" value="WH-A01" placeholder="WH-A01" class="form-input py-1 text-xs font-mono">
            </td>
            <td class="px-3 py-2.5" style="min-width: 110px;">
                <input type="text" name="rows[${rowIndex}][check_type]"  class="form-input py-1 text-xs font-bold text-center">
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
        document.getElementById('preview-filename').textContent = data.filename || 'kanban.xlsx';
        document.getElementById('preview-sheetname').textContent = 'Tab: ' + (data.sheet_name || 'kanban');
        document.getElementById('preview-total-rows').textContent = data.total_parsed || 0;
        document.getElementById('preview-skipped-rows').textContent = data.total_skipped || 0;

        var tbody = document.getElementById('preview-kanban-rows-body');
        tbody.innerHTML = '';
        previewRowIndex = 0;

        var custOptionsHtml = '<option value="">-- Pilih Customer --</option>';
        for (var k = 0; k < masterCustomersData.length; k++) {
            var c = masterCustomersData[k];
            custOptionsHtml += '<option value="' + escapeHtml(c.name) + '">' + escapeHtml(c.name) + '</option>';
        }
        custOptionsHtml += '<option value="custom">Input Custom PT</option>';

        var defaultCust = (masterCustomersData.length > 0) ? masterCustomersData[0].name : '';

        for (var i = 0; i < data.rows.length; i++) {
            var r = data.rows[i];
            previewRowIndex++;

            var tr = document.createElement('tr');
            tr.id = 'preview-row-' + previewRowIndex;
            tr.className = 'hover:bg-slate-50/80 transition-colors';

            tr.innerHTML = `
                <td class="px-3 py-2.5 text-center font-bold text-slate-400 preview-row-number" style="width: 40px; min-width: 40px;">${previewRowIndex}</td>
                <td class="px-3 py-2.5" style="min-width: 170px;">
                    <input type="text" name="rows[${previewRowIndex}][kanban_no]" value="${escapeHtml(r.kanban_no)}" class="form-input py-1 text-xs font-mono font-bold" required>
                </td>
                <td class="px-3 py-2.5" style="min-width: 190px;">
                    <input type="text" name="rows[${previewRowIndex}][item_code]" value="${escapeHtml(r.item_code)}" list="master-parts-list" oninput="onItemCodeInput(this, 'prev-${previewRowIndex}')" class="form-input py-1 text-xs font-mono font-bold" required autocomplete="off">
                </td>
                <td class="px-3 py-2.5" style="min-width: 220px;">
                    <input type="text" name="rows[${previewRowIndex}][item_description]" id="item-desc-prev-${previewRowIndex}" value="${escapeHtml(r.item_description)}" class="form-input py-1 text-xs" required>
                </td>
                <td class="px-3 py-2.5" style="min-width: 240px;">
                    <select name="rows[${previewRowIndex}][customer_select]" id="cust-select-prev-${previewRowIndex}" onchange="onCustomerSelectChange(this, 'prev-${previewRowIndex}')" class="form-input py-1 text-xs font-semibold" required>
                        ${custOptionsHtml}
                    </select>
                    <input type="text" name="rows[${previewRowIndex}][customer_custom]" id="custom-cust-prev-${previewRowIndex}" placeholder="Nama PT Customer Baru" class="form-input py-1 text-xs font-semibold mt-1 hidden">
                </td>
                <td class="px-3 py-2.5" style="min-width: 160px;">
                    <input type="text" name="rows[${previewRowIndex}][req_date]" value="${escapeHtml(r.req_date)}" placeholder="YYYY-MM-DD HH:MM:SS" class="form-input py-1 text-xs font-semibold" required>
                </td>
                <td class="px-3 py-2.5" style="min-width: 160px;">
                    <input type="text" name="rows[${previewRowIndex}][eta]" value="${escapeHtml(r.eta)}" placeholder="YYYY-MM-DD HH:MM:SS" class="form-input py-1 text-xs font-semibold" required>
                </td>
                <td class="px-3 py-2.5" style="min-width: 100px;">
                    <input type="number" name="rows[${previewRowIndex}][qty]" value="${r.qty}" min="1" class="form-input py-1 text-xs font-bold text-center" required>
                </td>
                <td class="px-3 py-2.5" style="min-width: 120px;">
                    <input type="text" name="rows[${previewRowIndex}][str_loc]" value="${escapeHtml(r.str_loc)}" class="form-input py-1 text-xs font-mono">
                </td>
                <td class="px-3 py-2.5" style="min-width: 110px;">
                    <input type="text" name="rows[${previewRowIndex}][check_type]" value="${escapeHtml(r.check_type || '')}" class="form-input py-1 text-xs font-bold text-center">
                </td>
                <td class="px-3 py-2.5" style="min-width: 230px;">
                    <textarea name="rows[${previewRowIndex}][remark]" rows="2" placeholder="Catatan" class="form-input py-1 text-xs resize-y" style="min-height: 52px;">${escapeHtml(r.remark)}</textarea>
                </td>
                <td class="px-3 py-2.5 text-center" style="width: 60px; min-width: 60px;">
                    <button type="button" onclick="removePreviewKanbanRow(${previewRowIndex})" class="p-1 text-rose-500 hover:text-rose-700 hover:bg-rose-50 rounded" title="Hapus Baris">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                        </svg>
                    </button>
                </td>
            `;

            tbody.appendChild(tr);

            var sel = document.getElementById('cust-select-prev-' + previewRowIndex);
            if (sel) {
                var epsonOpt = Array.from(sel.options).find(function(o) { return o.value.toUpperCase().includes('EPSON'); });
                if (epsonOpt) {
                    sel.value = epsonOpt.value;
                } else if (defaultCust) {
                    sel.value = defaultCust;
                }
            }
        }

        document.getElementById('excel-preview-container').classList.remove('hidden');
        document.getElementById('preview-search-input').value = '';
        filterPreviewRows();
    }

    function filterPreviewRows() {
        var query = document.getElementById('preview-search-input').value.toLowerCase().trim();
        var tbody = document.getElementById('preview-kanban-rows-body');
        var rows = tbody.querySelectorAll('tr');
        var visibleCount = 0;

        for (var i = 0; i < rows.length; i++) {
            var text = rows[i].textContent.toLowerCase();
            var inputs = rows[i].querySelectorAll('input, select, textarea');
            var inputValsStr = '';
            for (var k = 0; k < inputs.length; k++) {
                inputValsStr += ' ' + inputs[k].value.toLowerCase();
            }
            var fullText = text + inputValsStr;

            if (query === '' || fullText.indexOf(query) !== -1) {
                rows[i].style.display = '';
                visibleCount++;
            } else {
                rows[i].style.display = 'none';
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
        tr.className = 'hover:bg-slate-50/80 transition-colors';

        var custOptionsHtml = '<option value="">-- Pilih Customer --</option>';
        for (var k = 0; k < masterCustomersData.length; k++) {
            var c = masterCustomersData[k];
            custOptionsHtml += '<option value="' + escapeHtml(c.name) + '">' + escapeHtml(c.name) + '</option>';
        }
        custOptionsHtml += '<option value="custom">Input Custom PT</option>';

        var todayDate = new Date().toISOString().slice(0, 19).replace('T', ' ');

        tr.innerHTML = `
            <td class="px-3 py-2.5 text-center font-bold text-slate-400 preview-row-number" style="width: 40px; min-width: 40px;">${tbody.children.length + 1}</td>
            <td class="px-3 py-2.5" style="min-width: 170px;">
                <input type="text" name="rows[${previewRowIndex}][kanban_no]" placeholder="0004573892" class="form-input py-1 text-xs font-mono font-bold" required>
            </td>
            <td class="px-3 py-2.5" style="min-width: 190px;">
                <input type="text" name="rows[${previewRowIndex}][item_code]" list="master-parts-list" oninput="onItemCodeInput(this, 'prev-${previewRowIndex}')" placeholder="Ketik / Cari Item Code..." class="form-input py-1 text-xs font-mono font-bold" required autocomplete="off">
            </td>
            <td class="px-3 py-2.5" style="min-width: 220px;">
                <input type="text" name="rows[${previewRowIndex}][item_description]" id="item-desc-prev-${previewRowIndex}" placeholder="Item Description" class="form-input py-1 text-xs" required>
            </td>
            <td class="px-3 py-2.5" style="min-width: 240px;">
                <select name="rows[${previewRowIndex}][customer_select]" id="cust-select-prev-${previewRowIndex}" onchange="onCustomerSelectChange(this, 'prev-${previewRowIndex}')" class="form-input py-1 text-xs font-semibold" required>
                    ${custOptionsHtml}
                </select>
                <input type="text" name="rows[${previewRowIndex}][customer_custom]" id="custom-cust-prev-${previewRowIndex}" placeholder="Nama PT Customer Baru" class="form-input py-1 text-xs font-semibold mt-1 hidden">
            </td>
            <td class="px-3 py-2.5" style="min-width: 160px;">
                <input type="text" name="rows[${previewRowIndex}][req_date]" value="${todayDate}" placeholder="YYYY-MM-DD HH:MM:SS" class="form-input py-1 text-xs font-semibold" required>
            </td>
            <td class="px-3 py-2.5" style="min-width: 160px;">
                <input type="text" name="rows[${previewRowIndex}][eta]" value="${todayDate}" placeholder="YYYY-MM-DD HH:MM:SS" class="form-input py-1 text-xs font-semibold" required>
            </td>
            <td class="px-3 py-2.5" style="min-width: 100px;">
                <input type="number" name="rows[${previewRowIndex}][qty]" value="500" min="1" placeholder="Qty" class="form-input py-1 text-xs font-bold text-center" required>
            </td>
            <td class="px-3 py-2.5" style="min-width: 120px;">
                <input type="text" name="rows[${previewRowIndex}][str_loc]" value="WH-A01" placeholder="WH-A01" class="form-input py-1 text-xs font-mono">
            </td>
            <td class="px-3 py-2.5" style="min-width: 230px;">
                <textarea name="rows[${previewRowIndex}][remark]" rows="2" placeholder="Catatan (opsional)" class="form-input py-1 text-xs resize-y" style="min-height: 52px;"></textarea>
            </td>
            <td class="px-3 py-2.5 text-center" style="width: 60px; min-width: 60px;">
                <button type="button" onclick="removePreviewKanbanRow(${previewRowIndex})" class="p-1 text-rose-500 hover:text-rose-700 hover:bg-rose-50 rounded" title="Hapus Baris">
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

        for (var i = 0; i < rows.length; i++) {
            var tr = rows[i];
            var kanbanNoInput = tr.querySelector('input[name*="[kanban_no]"]');
            var itemCodeInput = tr.querySelector('input[name*="[item_code]"]');
            var itemDescInput = tr.querySelector('input[name*="[item_description]"]');
            var custSelect    = tr.querySelector('select[name*="[customer_select]"]');
            var custCustom    = tr.querySelector('input[name*="[customer_custom]"]');
            var reqDateInput  = tr.querySelector('input[name*="[req_date]"]');
            var etaInput      = tr.querySelector('input[name*="[eta]"]');
            var qtyInput      = tr.querySelector('input[name*="[qty]"]');
            var strLocInput   = tr.querySelector('input[name*="[str_loc]"]');
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

            data.push({
                kanban_no: kNo,
                item_code: iCode,
                item_description: itemDescInput ? itemDescInput.value.trim() : iCode,
                customer: custVal,
                req_date: reqDateInput ? reqDateInput.value : '',
                eta: etaInput ? etaInput.value : '',
                qty: qtyInput ? parseInt(qtyInput.value) || 1 : 1,
                str_loc: strLocInput ? strLocInput.value.trim() : '',
                remark: remarkInput ? remarkInput.value.trim() : ''
            });
        }

        if (data.length === 0) {
            if (e) e.preventDefault();
            alert('Tidak ada baris data valid untuk disimpan!');
            return false;
        }

        document.getElementById('kanban_json_data').value = JSON.stringify(data);
        return true;
    }

    function escapeHtml(text) {
        if (!text) return '';
        return String(text).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }
    </script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
