<?php
$breadcrumbCategory = "DATA REFERENSI";
$pageTitle = "Tambah Daily Inspection Data (DID)";
$pageSubtitle = "Input beberapa baris data pengecekan dimensi lot produksi sekaligus atau via Upload File Excel/CSV";


require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';

$pdo = getDB();
$master_parts = [];

if ($pdo) {
    try {
        $stmt = $pdo->query("SELECT id, part_code, part_name FROM master_parts ORDER BY part_code ASC");
        $master_parts = $stmt->fetchAll();
    } catch (PDOException $e) {
        $master_parts = [];
    }
}
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-5 space-y-4">
        
        <?= render_flash() ?>

        <!-- Action Card Header -->
        <div class="card p-3 md:p-4 bg-white border border-slate-200/80 rounded-xl shadow-xs">
            <div style="display: flex; align-items: center; justify-content: space-between;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <a href="<?= base_url('modules/did/index.php') ?>" class="btn-secondary py-1.5 px-3 text-xs">
                        &larr; Kembali ke Daftar DID
                    </a>
                    <h2 class="text-sm font-bold text-slate-800">Form Input Data DID Baru</h2>
                </div>
            </div>
        </div>

        <!-- Input Mode Selection Container -->
        <div class="card p-4 bg-white border border-slate-200/80 rounded-xl shadow-xs space-y-4">
            
            <!-- Modern Clickable Pill Tabs -->
            <div class="flex items-center space-x-2 bg-slate-100/80 p-1.5 rounded-xl max-w-fit border border-slate-200/60">
                <button type="button" id="tab-btn-manual" onclick="switchTab('manual')" 
                        class="px-4 py-2 text-xs font-bold rounded-lg transition-all duration-200 bg-blue-600 text-white shadow-xs cursor-pointer">
                    📝 Input Manual Multi-Baris
                </button>
                <button type="button" id="tab-btn-excel" onclick="switchTab('excel')" 
                        class="px-4 py-2 text-xs font-bold rounded-lg transition-all duration-200 text-slate-600 hover:text-slate-900 hover:bg-slate-200/70 cursor-pointer">
                    📊 Import File Excel / CSV Batch
                </button>
            </div>

            <!-- TAB 1: Multi-Row Dynamic Manual Form -->
            <div id="tab-content-manual">
                <form action="<?= base_url('modules/did/store.php') ?>" method="POST" class="space-y-4">
                    <input type="hidden" name="entry_type" value="manual">

                    <!-- Shared Metadata Fields Header -->
                    <div class="p-3 bg-slate-50 border border-slate-200 rounded-xl grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                        <div>
                            <label class="form-label">Tanggal Cek Dimensi (Inspecting Date) <span class="text-rose-500">*</span></label>
                            <input type="date" name="inspecting_date" id="inspecting_date" value="<?= date('Y-m-d') ?>" onchange="onInspectingDateChange(); checkDateTaken(this.value)" class="form-input text-xs" required>
                            <span class="text-[10px] text-slate-400">Lot Number otomatis ter-generate berdasarkan tanggal ini (Format: <code class="font-mono text-blue-600">064 YY M DD D010000</code>).</span>
                            <!-- Warning: tanggal sudah ada batch -->
                            <div id="date-taken-warning" style="display:none;" class="mt-1.5 p-2.5 bg-amber-50 border border-amber-300 rounded-lg flex items-center justify-between gap-3">
                                <div class="flex items-center gap-2">
                                    <svg class="w-4 h-4 flex-shrink-0 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                    <span id="date-taken-msg" class="text-[11px] font-semibold text-amber-800"></span>
                                </div>
                                <a id="date-taken-link" href="#" class="flex-shrink-0 bg-blue-600 hover:bg-blue-700 text-white text-[11px] font-bold px-3 py-1.5 rounded-lg transition-colors whitespace-nowrap">
                                    📂 Buka Batch Ini &rarr;
                                </a>
                            </div>
                        </div>
                        <div>
                            <label class="form-label">PIC / Petugas Inspector <span class="text-rose-500">*</span></label>
                            <input type="text" name="pic" value="Budi QC" placeholder="Nama Petugas" class="form-input text-xs" required>
                        </div>
                    </div>

                    <!-- Dynamic Multi-Row Table -->
                    <div class="overflow-x-auto border border-slate-200 rounded-xl">
                        <table class="w-full text-left text-xs text-slate-700" id="did-items-table">
                            <thead class="bg-slate-100 text-slate-700 font-bold border-b border-slate-200 text-[11px] uppercase">
                                <tr>
                                    <th class="px-2 py-2.5 text-center w-8">#</th>
                                    <th class="px-2 py-2.5 min-w-[150px]">Part Code</th>
                                    <th class="px-2 py-2.5 min-w-[160px]">Nama Part</th>
                                    <th class="px-2 py-2.5 min-w-[170px]">Lot Number</th>
                                    <th class="px-2 py-2.5 w-16 text-center">Cavity</th>
                                    <th class="px-2 py-2.5 w-32">Status Inspect</th>
                                    <th class="px-2 py-2.5 min-w-[260px]">Remark</th>
                                    <th class="px-2 py-2.5 text-center w-12">Hapus</th>
                                </tr>
                            </thead>
                            <tbody id="did-rows-body" class="divide-y divide-slate-100">
                                <!-- Dynamic rows will be inserted here via JS -->
                            </tbody>
                        </table>
                    </div>

                    <!-- Add Row Action Controls (Clean Icon without duplicate '+' text) -->
                    <div class="flex items-center justify-between pt-1">
                        <div class="flex items-center space-x-2">
                            <button type="button" onclick="addDIDRow()" class="btn-secondary py-1.5 px-3 text-xs flex items-center">
                                <svg class="w-3.5 h-3.5 mr-1 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                                </svg>
                                Tambah 1 Baris
                            </button>
                            <button type="button" onclick="addMultipleDIDRows(5)" class="btn-secondary py-1.5 px-3 text-xs flex items-center">
                                <svg class="w-3.5 h-3.5 mr-1 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                                </svg>
                                Tambah 5 Baris
                            </button>
                        </div>
                        <span class="text-xs text-slate-500">Total: <b id="row-counter" class="text-slate-800">0</b> baris data</span>
                    </div>

                    <div class="pt-3 border-t border-slate-100 flex justify-end space-x-2">
                        <a href="<?= base_url('modules/did/index.php') ?>" class="btn-secondary py-1.5 px-4 text-xs">Batal</a>
                        <button type="submit" class="btn-primary py-1.5 px-4 text-xs font-bold">
                            Simpan Semua Data DID
                        </button>
                    </div>
                </form>
            </div>

            <!-- TAB 2: Batch Excel Multi-Tab (.xlsx) Import Form -->
            <div id="tab-content-excel" class="hidden space-y-4">
                <form action="<?= base_url('modules/did/store.php') ?>" method="POST" enctype="multipart/form-data" class="space-y-4" id="xlsx-upload-form">
                    <input type="hidden" name="entry_type" value="xlsx">

                    <div class="p-4 bg-slate-50 border border-slate-200 rounded-xl space-y-3">
                        <div class="flex items-center justify-between">
                            <h3 class="text-xs font-bold text-slate-800">📌 Petunjuk Format File Excel Multi-Tab (.xlsx)</h3>
                            <a href="<?= base_url('did.xlsx') ?>" download class="text-xs font-semibold text-blue-600 hover:underline">
                                📥 Sample File DID (did.xlsx)
                            </a>
                        </div>
                        <p class="text-xs text-slate-600 leading-relaxed">
                            - <b>Setiap Tab/Sheet = 1 Hari Inspeksi</b> (Nama tab berformat tanggal: <code class="bg-white px-1.5 py-0.5 border border-slate-200 rounded text-blue-700 font-mono text-[11px]">01-08-2026</code>, <code class="bg-white px-1.5 py-0.5 border border-slate-200 rounded text-blue-700 font-mono text-[11px]">02-08-2026</code>, dst).<br>
                            - Data item inspeksi dimulai dari <b>Baris ke-6</b> (Header kolom di baris ke-5).<br>
                            - Kolom data: <code class="bg-white px-1.5 py-0.5 border border-slate-200 rounded text-blue-700 font-mono text-[11px]">B=Part Code</code>, <code class="bg-white px-1.5 py-0.5 border border-slate-200 rounded text-blue-700 font-mono text-[11px]">C=Part Name</code>, <code class="bg-white px-1.5 py-0.5 border border-slate-200 rounded text-blue-700 font-mono text-[11px]">D=Lot No</code>, <code class="bg-white px-1.5 py-0.5 border border-slate-200 rounded text-blue-700 font-mono text-[11px]">E=Cavity</code>, <code class="bg-white px-1.5 py-0.5 border border-slate-200 rounded text-blue-700 font-mono text-[11px]">F=Date</code>, <code class="bg-white px-1.5 py-0.5 border border-slate-200 rounded text-blue-700 font-mono text-[11px]">G=Status</code>, <code class="bg-white px-1.5 py-0.5 border border-slate-200 rounded text-blue-700 font-mono text-[11px]">H=PIC</code>, <code class="bg-white px-1.5 py-0.5 border border-slate-200 rounded text-blue-700 font-mono text-[11px]">I=Remark</code>.
                        </p>
                    </div>

                    <div>
                        <label class="form-label">Pilih File Excel (.xlsx) Multi-Tab <span class="text-rose-500">*</span></label>
                        <input type="file" name="xlsx_file" id="xlsx_file_input" accept=".xlsx" onchange="previewXlsxFile(this)" class="form-input text-xs" required>
                        <span class="text-[10px] text-slate-400">Pilih file <code>.xlsx</code> yang berisi tab-tab tanggal inspeksi. Sistem akan menganalisis tab secara otomatis.</span>
                    </div>

                    <!-- Live Preview Container -->
                    <div id="xlsx-preview-card" style="display:none;" class="card p-3 bg-white border border-slate-200 rounded-xl space-y-2">
                        <div class="flex items-center justify-between">
                            <h4 class="text-xs font-bold text-slate-800 flex items-center gap-1.5">
                                <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                Hasil Analisis Sheet File Excel
                            </h4>
                            <span id="preview-summary-badge" class="badge badge-blue">Analisis...</span>
                        </div>
                        <div class="overflow-x-auto border border-slate-100 rounded-lg">
                            <table class="w-full text-left text-xs">
                                <thead class="bg-slate-50 text-slate-600 font-bold border-b border-slate-200 text-[10px] uppercase">
                                    <tr>
                                        <th class="px-3 py-2">Nama Tab</th>
                                        <th class="px-3 py-2">Tanggal Inspeksi</th>
                                        <th class="px-3 py-2 text-center">Jumlah Baris</th>
                                        <th class="px-3 py-2 text-right">Status Batch</th>
                                    </tr>
                                </thead>
                                <tbody id="xlsx-sheet-list-body" class="divide-y divide-slate-100 text-xs">
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="pt-3 border-t border-slate-100 flex justify-end space-x-2">
                        <a href="<?= base_url('modules/did/index.php') ?>" class="btn-secondary py-1.5 px-4 text-xs">Batal</a>
                        <button type="submit" id="btn-submit-excel" class="btn-primary py-1.5 px-4 text-xs font-bold">
                            🚀 Import Semua Tab Excel per Tanggal
                        </button>
                    </div>
                </form>
            </div>

        </div>

    </main>

    <script>
    var masterPartsData = <?= json_encode($master_parts) ?>;
    var rowIndex = 0;

    document.addEventListener("DOMContentLoaded", function() {
        addMultipleDIDRows(3);
    });

    // Generate Lot Number based on inspecting_date (Format: 064 + YY + M + DD + D010000)
    function generateLotNumber(dateStr) {
        if (!dateStr) dateStr = document.getElementById('inspecting_date').value;
        if (!dateStr) return '06426817D010000';
        
        var parts = dateStr.split('-');
        if (parts.length === 3) {
            var yearYY = parts[0].slice(-2);            // e.g. "26"
            var monthM = String(parseInt(parts[1], 10)); // e.g. "8" (without leading zero)
            var dayDD  = parts[2];                       // e.g. "01" or "17"
            return '064' + yearYY + monthM + dayDD + 'D010000';
        }
        return '06426817D010000';
    }

    function onInspectingDateChange() {
        var newDate = document.getElementById('inspecting_date').value;
        var defaultLot = generateLotNumber(newDate);
        var lotInputs = document.querySelectorAll('.lot-number-input');
        lotInputs.forEach(function(input) {
            if (!input.value || input.value.startsWith('064')) {
                input.value = defaultLot;
            }
        });
    }

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

    function addMultipleDIDRows(count) {
        for (var i = 0; i < count; i++) {
            addDIDRow();
        }
    }

    function addDIDRow() {
        rowIndex++;
        var tbody = document.getElementById('did-rows-body');
        var tr = document.createElement('tr');
        tr.id = 'row-' + rowIndex;
        tr.className = 'hover:bg-slate-50/80 transition-colors';

        var defaultLot = generateLotNumber(document.getElementById('inspecting_date').value);

        var partOptions = '<option value="">-- Pilih Part --</option>';
        for (var j = 0; j < masterPartsData.length; j++) {
            var p = masterPartsData[j];
            partOptions += '<option value="' + escapeHtml(p.part_code) + '" data-name="' + escapeHtml(p.part_name) + '">' + escapeHtml(p.part_code) + '</option>';
        }
        partOptions += '<option value="custom">Input Custom Code</option>';

        tr.innerHTML = `
            <td class="px-2 py-2 text-center font-bold text-slate-400 row-number">${tbody.children.length + 1}</td>
            <td class="px-2 py-2 relative" style="overflow: visible;">
                <input type="hidden" name="rows[${rowIndex}][part_code_select]" id="part-select-value-${rowIndex}" value="" required>
                
                <div class="relative part-search-container" id="part-search-container-${rowIndex}">
                    <div class="relative flex items-center">
                        <input type="text" 
                               id="part-search-input-${rowIndex}"
                               placeholder="🔍 Cari Part..." 
                               autocomplete="off"
                               onfocus="openPartDropdown(${rowIndex})"
                               oninput="filterPartDropdown(${rowIndex})"
                               onblur="onPartSearchBlur(${rowIndex})"
                               class="form-input py-1 text-xs font-mono font-bold pr-6" required>
                        <svg class="w-3.5 h-3.5 absolute right-2 text-slate-400 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>

                    <div id="part-dropdown-menu-${rowIndex}" 
                         class="part-dropdown-menu absolute left-0 right-0 top-full mt-1 bg-white border border-slate-200 rounded-xl shadow-xl z-50 hidden divide-y divide-slate-100 text-xs"
                         style="max-height: 200px; overflow-y: auto;">
                    </div>
                </div>

                <input type="text" name="rows[${rowIndex}][part_code_custom]" id="custom-code-${rowIndex}" placeholder="Part Code Baru" class="form-input py-1 text-xs font-mono mt-1 hidden">
            </td>
            <td class="px-2 py-2">
                <input type="text" name="rows[${rowIndex}][part_name]" id="part-name-${rowIndex}" placeholder="Nama Part" class="form-input py-1 text-xs" required>
            </td>
            <td class="px-2 py-2">
                <input type="text" name="rows[${rowIndex}][lot_number]" value="${defaultLot}" placeholder="06426801D010000" class="form-input py-1 text-xs font-mono font-bold lot-number-input" required>
            </td>
            <td class="px-2 py-2 text-center" style="width: 60px;">
                <input type="text" name="rows[${rowIndex}][cavity]" value="1" placeholder="1" class="form-input py-1 text-xs text-center font-bold" style="width: 48px; margin: 0 auto;" required>
            </td>
            <td class="px-2 py-2">
                <select name="rows[${rowIndex}][status_inspect]" class="form-input py-1 text-xs font-bold">
                    <option value="OK" class="text-emerald-600 font-bold">✓ OK</option>
                    <option value="NG" class="text-rose-600 font-bold">⚠ NG</option>
                </select>
            </td>
            <td class="px-2 py-2">
                <textarea name="rows[${rowIndex}][remark]" rows="2" placeholder="Catatan hasil dimensi (opsional)" class="form-input py-1 text-xs w-full resize-y"></textarea>
            </td>
            <td class="px-2 py-2 text-center">
                <button type="button" onclick="removeDIDRow(${rowIndex})" class="p-1 text-rose-500 hover:text-rose-700 hover:bg-rose-50 rounded" title="Hapus Baris">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                    </svg>
                </button>
            </td>
        `;

        tbody.appendChild(tr);
        updateRowCounter();
    }

    function removeDIDRow(idx) {
        var row = document.getElementById('row-' + idx);
        if (row) {
            row.parentNode.removeChild(row);
            updateRowNumbers();
            updateRowCounter();
        }
    }

    // === SEARCHABLE PART DROPDOWN FUNCTIONS ===
    function openPartDropdown(idx) {
        document.querySelectorAll('.part-dropdown-menu').forEach(function(el) {
            if (el.id !== 'part-dropdown-menu-' + idx) el.classList.add('hidden');
        });
        
        filterPartDropdown(idx);
        var menu = document.getElementById('part-dropdown-menu-' + idx);
        if (menu) menu.classList.remove('hidden');
    }

    function filterPartDropdown(idx) {
        var input = document.getElementById('part-search-input-' + idx);
        var menu = document.getElementById('part-dropdown-menu-' + idx);
        if (!input || !menu) return;

        var query = input.value.toLowerCase().trim();
        if (query.startsWith('➕')) query = '';

        var html = '';
        var matchCount = 0;

        for (var j = 0; j < masterPartsData.length; j++) {
            var p = masterPartsData[j];
            var code = (p.part_code || '').toString();
            var name = (p.part_name || '').toString();

            if (query === '' || code.toLowerCase().indexOf(query) !== -1 || name.toLowerCase().indexOf(query) !== -1) {
                matchCount++;
                html += `<div onmousedown="selectPartOption(${idx}, '${escapeHtml(code)}', '${escapeHtml(name)}')" 
                             class="px-2.5 py-1.5 hover:bg-blue-50 cursor-pointer flex items-center justify-between gap-2 border-b border-slate-50 last:border-0 transition-colors">
                            <span class="font-mono font-bold text-blue-700 text-xs">${escapeHtml(code)}</span>
                            <span class="text-[10px] text-slate-500 truncate max-w-[130px] text-right">${escapeHtml(name)}</span>
                         </div>`;
            }
        }

        html += `<div onmousedown="selectPartOption(${idx}, 'custom', '')" 
                     class="px-2.5 py-1.5 hover:bg-amber-50 text-amber-700 font-bold cursor-pointer flex items-center gap-1.5 text-xs bg-amber-50/50 transition-colors">
                    <span>➕</span> <span>Input Custom Code Baru...</span>
                 </div>`;

        if (matchCount === 0 && query !== '') {
            html = `<div class="px-2.5 py-2 text-slate-400 text-[11px] italic text-center">Part "${escapeHtml(query)}" tidak ada</div>` + html;
        }

        menu.innerHTML = html;
    }

    function selectPartOption(idx, code, name) {
        var valInput = document.getElementById('part-select-value-' + idx);
        var searchInput = document.getElementById('part-search-input-' + idx);
        var customInput = document.getElementById('custom-code-' + idx);
        var nameInput = document.getElementById('part-name-' + idx);
        var menu = document.getElementById('part-dropdown-menu-' + idx);

        if (code === 'custom') {
            valInput.value = 'custom';
            searchInput.value = '➕ Custom Code';
            customInput.classList.remove('hidden');
            customInput.required = true;
            customInput.focus();
            if (nameInput) nameInput.value = '';
        } else {
            valInput.value = code;
            searchInput.value = code;
            customInput.classList.add('hidden');
            customInput.required = false;
            customInput.value = '';
            if (nameInput && name) nameInput.value = name;
        }

        if (menu) menu.classList.add('hidden');
    }

    function onPartSearchBlur(idx) {
        setTimeout(function() {
            var menu = document.getElementById('part-dropdown-menu-' + idx);
            if (menu) menu.classList.add('hidden');

            var searchInput = document.getElementById('part-search-input-' + idx);
            var valInput = document.getElementById('part-select-value-' + idx);
            if (!searchInput || !valInput) return;

            var typed = searchInput.value.trim();
            if (typed === '' || typed.startsWith('➕')) return;

            if (!valInput.value || valInput.value === 'custom') {
                var match = masterPartsData.find(function(p) {
                    return p.part_code.toLowerCase() === typed.toLowerCase();
                });
                if (match) {
                    selectPartOption(idx, match.part_code, match.part_name);
                }
            }
        }, 150);
    }

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.part-search-container')) {
            document.querySelectorAll('.part-dropdown-menu').forEach(function(menu) {
                menu.classList.add('hidden');
            });
        }
    });

    function updateRowNumbers() {
        var tbody = document.getElementById('did-rows-body');
        var rows = tbody.querySelectorAll('tr');
        for (var i = 0; i < rows.length; i++) {
            var numCell = rows[i].querySelector('.row-number');
            if (numCell) numCell.textContent = i + 1;
        }
    }

    function updateRowCounter() {
        var tbody = document.getElementById('did-rows-body');
        var count = tbody.querySelectorAll('tr').length;
        document.getElementById('row-counter').textContent = count;
    }

    function escapeHtml(text) {
        if (!text) return '';
        return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }

    // === CEK TANGGAL SUDAH ADA BATCH ===
    function checkDateTaken(dateVal) {
        var warn = document.getElementById('date-taken-warning');
        var msg  = document.getElementById('date-taken-msg');
        if (!dateVal) { warn.style.display = 'none'; return; }

        fetch('<?= base_url('modules/did/check_date.php') ?>?date=' + encodeURIComponent(dateVal))
            .then(function(r){ return r.json(); })
            .then(function(data) {
                if (data.taken) {
                    var detailUrl = '<?= base_url('modules/did/detail_batch.php') ?>?batch_id=' + data.batch_id;
                    msg.innerHTML = 'Tanggal ini sudah ada batch: <b>' + escapeHtml(data.batch_name) + '</b>';
                    document.getElementById('date-taken-link').href = detailUrl;
                    warn.style.display = 'flex';
                } else {
                    warn.style.display = 'none';
                }
            })
            .catch(function(){ warn.style.display = 'none'; });
    }

    // === LIVE PREVIEW FILE EXCEL MULTI-TAB ===
    function previewXlsxFile(input) {
        var card = document.getElementById('xlsx-preview-card');
        var badge = document.getElementById('preview-summary-badge');
        var tbody = document.getElementById('xlsx-sheet-list-body');

        if (!input.files || !input.files[0]) {
            card.style.display = 'none';
            return;
        }

        var formData = new FormData();
        formData.append('xlsx_file', input.files[0]);

        card.style.display = 'block';
        badge.className = 'badge badge-blue';
        badge.textContent = 'Menganalisis file...';
        tbody.innerHTML = '<tr><td colspan="4" class="px-3 py-4 text-center text-slate-400">Sedang membaca tab & memeriksa tanggal batch...</td></tr>';

        fetch('<?= base_url('modules/did/preview_excel.php') ?>', {
            method: 'POST',
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.error) {
                badge.className = 'badge badge-danger';
                badge.textContent = 'Gagal Analisis';
                tbody.innerHTML = '<tr><td colspan="4" class="px-3 py-3 text-rose-600 font-semibold">' + escapeHtml(res.error) + '</td></tr>';
                return;
            }

            var sheets = res.sheets || [];
            if (sheets.length === 0) {
                badge.className = 'badge badge-warning';
                badge.textContent = 'Tidak Ada Tab Valid';
                tbody.innerHTML = '<tr><td colspan="4" class="px-3 py-3 text-amber-600">Tidak ada tab berformat DD-MM-YYYY ditemukan di file ini.</td></tr>';
                return;
            }

            var html = '';
            var newCount = 0;
            var skipCount = 0;

            sheets.forEach(function(s) {
                if (s.taken) {
                    skipCount++;
                    html += '<tr class="bg-amber-50/50">';
                    html += '<td class="px-3 py-2 font-mono font-bold text-slate-700">' + escapeHtml(s.tab) + '</td>';
                    html += '<td class="px-3 py-2 text-slate-600">' + escapeHtml(s.date) + '</td>';
                    html += '<td class="px-3 py-2 text-center font-bold text-slate-700">' + s.rows + ' row</td>';
                    html += '<td class="px-3 py-2 text-right"><span class="badge badge-warning">⚠ Batch Sudah Ada (Dilewati)</span></td>';
                    html += '</tr>';
                } else {
                    newCount++;
                    html += '<tr class="hover:bg-slate-50">';
                    html += '<td class="px-3 py-2 font-mono font-bold text-blue-700">' + escapeHtml(s.tab) + '</td>';
                    html += '<td class="px-3 py-2 text-slate-800 font-semibold">' + escapeHtml(s.date) + '</td>';
                    html += '<td class="px-3 py-2 text-center font-extrabold text-blue-600">' + s.rows + ' row</td>';
                    html += '<td class="px-3 py-2 text-right"><span class="badge badge-success">✓ Siap Import (Batch Baru)</span></td>';
                    html += '</tr>';
                }
            });

            tbody.innerHTML = html;
            badge.className = 'badge badge-success';
            badge.textContent = newCount + ' Batch Baru Siap Import (' + skipCount + ' Dilewati)';
        })
        .catch(function(err) {
            badge.className = 'badge badge-danger';
            badge.textContent = 'Error Server';
            tbody.innerHTML = '<tr><td colspan="4" class="px-3 py-3 text-rose-600">Gagal terhubung ke server untuk pratinjau file.</td></tr>';
        });
    }

    // Cek tanggal hari ini saat halaman pertama kali load
    window.addEventListener('DOMContentLoaded', function() {
        var dateEl = document.getElementById('inspecting_date');
        if (dateEl && dateEl.value) checkDateTaken(dateEl.value);
    });
    </script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
