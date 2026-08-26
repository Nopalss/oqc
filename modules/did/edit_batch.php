<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

$batch_id = filter_input(INPUT_GET, 'batch_id', FILTER_VALIDATE_INT);
$pdo = getDB();
$batch = null;
$items = [];
$master_parts = [];

if ($batch_id && $pdo) {
    try {
        $stmtBatch = $pdo->prepare("SELECT * FROM did_batches WHERE id = :id");
        $stmtBatch->execute([':id' => $batch_id]);
        $batch = $stmtBatch->fetch();

        if ($batch) {
            $stmtItems = $pdo->prepare("SELECT * FROM daily_inspection_data WHERE batch_id = :batch_id ORDER BY id ASC");
            $stmtItems->execute([':batch_id' => $batch_id]);
            $items = $stmtItems->fetchAll();

            $stmtParts = $pdo->query("SELECT id, part_code, part_name FROM master_parts ORDER BY part_code ASC");
            $master_parts = $stmtParts->fetchAll();
        }
    } catch (PDOException $e) {
        $batch = null;
    }
}

if (!$batch) {
    set_flash('error', 'Sesi Batch DID tidak ditemukan!');
    redirect('modules/did/index.php');
}

$breadcrumbCategory = "DATA REFERENSI";
$pageTitle = "Edit Sesi Batch DID";
$pageSubtitle = "Perbarui item atau tambah baris data baru pada sesi histori ini";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-5 space-y-4">
        
        <?= render_flash() ?>

        <!-- Action Card Header -->
        <div class="card p-3 md:p-4 bg-white border border-slate-200/80 rounded-xl shadow-xs">
            <div style="display: flex; align-items: center; justify-content: space-between;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <a href="<?= base_url('modules/did/detail_batch.php?batch_id=' . $batch['id']) ?>" class="btn-secondary py-1.5 px-3 text-xs">
                        &larr; Batal & Kembali
                    </a>
                    <h2 class="text-sm font-bold text-slate-800">
                        Edit Sesi Batch DID: <span class="font-mono text-blue-700 font-extrabold"><?= htmlspecialchars($batch['batch_name']) ?></span>
                    </h2>
                </div>
            </div>
        </div>

        <!-- Multi-Row Dynamic Matrix Form for Batch Edit -->
        <div class="card p-4 bg-white border border-slate-200/80 rounded-xl shadow-xs space-y-4">
            <form action="<?= base_url('modules/did/update_batch.php') ?>" method="POST" class="space-y-4">
                <input type="hidden" name="batch_id" value="<?= $batch['id'] ?>">

                <!-- Shared Metadata Fields Header -->
                <div class="p-3 bg-slate-50 border border-slate-200 rounded-xl grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                    <div>
                        <label class="form-label">Nama Referensi Sesi Batch <span class="text-rose-500">*</span></label>
                        <input type="text" name="batch_name" value="<?= htmlspecialchars($batch['batch_name']) ?>" class="form-input text-xs font-bold" required>
                    </div>
                    <div>
                        <label class="form-label">PIC Inspector Default Baru</label>
                        <input type="text" name="pic_default" value="Budi QC" placeholder="Nama Petugas" class="form-input text-xs">
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
                            <!-- Existing item rows pre-loaded via PHP loop -->
                            <?php foreach ($items as $idx => $item): ?>
                                <tr id="row-<?= $idx + 1 ?>" class="hover:bg-slate-50/80 transition-colors">
                                    <input type="hidden" name="rows[<?= $idx + 1 ?>][item_id]" value="<?= $item['id'] ?>">
                                    
                                    <td class="px-2 py-2 text-center font-bold text-slate-400 row-number"><?= $idx + 1 ?></td>
                                    <td class="px-2 py-2">
                                        <input type="text" name="rows[<?= $idx + 1 ?>][part_code]" value="<?= htmlspecialchars($item['part_code']) ?>" class="form-input py-1 text-xs font-mono font-bold" required>
                                    </td>
                                    <td class="px-2 py-2">
                                        <input type="text" name="rows[<?= $idx + 1 ?>][part_name]" value="<?= htmlspecialchars($item['part_name']) ?>" class="form-input py-1 text-xs" required>
                                    </td>
                                    <td class="px-2 py-2">
                                        <input type="text" name="rows[<?= $idx + 1 ?>][lot_number]" value="<?= htmlspecialchars($item['lot_number']) ?>" class="form-input py-1 text-xs font-mono font-bold lot-number-input" required>
                                    </td>
                                    <td class="px-2 py-2 text-center" style="width: 60px;">
                                        <input type="text" name="rows[<?= $idx + 1 ?>][cavity]" value="<?= htmlspecialchars($item['cavity']) ?>" class="form-input py-1 text-xs text-center font-bold" style="width: 48px; margin: 0 auto;" required>
                                    </td>
                                    <td class="px-2 py-2">
                                        <select name="rows[<?= $idx + 1 ?>][status_inspect]" class="form-input py-1 text-xs font-bold">
                                            <option value="OK" <?= ($item['status_inspect'] === 'OK') ? 'selected' : '' ?> class="text-emerald-600 font-bold">✓ OK</option>
                                            <option value="NG" <?= ($item['status_inspect'] === 'NG') ? 'selected' : '' ?> class="text-rose-600 font-bold">⚠ NG</option>
                                        </select>
                                    </td>
                                    <td class="px-2 py-2">
                                        <textarea name="rows[<?= $idx + 1 ?>][remark]" rows="2" placeholder="Catatan hasil dimensi" class="form-input py-1 text-xs w-full resize-y"><?= htmlspecialchars($item['remark'] ?? '') ?></textarea>
                                    </td>
                                    <td class="px-2 py-2 text-center">
                                        <button type="button" onclick="removeDIDRow(<?= $idx + 1 ?>)" class="p-1 text-rose-500 hover:text-rose-700 hover:bg-rose-50 rounded" title="Hapus Baris">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                            </svg>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Add Row Action Controls -->
                <div class="flex items-center justify-between pt-1">
                    <div class="flex items-center space-x-2">
                        <button type="button" onclick="addDIDRow()" class="btn-secondary py-1.5 px-3 text-xs flex items-center">
                            <svg class="w-3.5 h-3.5 mr-1 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                            </svg>
                            Tambah 1 Baris Baru
                        </button>
                        <button type="button" onclick="addMultipleDIDRows(5)" class="btn-secondary py-1.5 px-3 text-xs flex items-center">
                            <svg class="w-3.5 h-3.5 mr-1 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                            </svg>
                            Tambah 5 Baris Baru
                        </button>
                    </div>
                    <span class="text-xs text-slate-500">Total: <b id="row-counter" class="text-slate-800"><?= count($items) ?></b> baris data</span>
                </div>

                <div class="pt-3 border-t border-slate-100 flex justify-end space-x-2">
                    <a href="<?= base_url('modules/did/detail_batch.php?batch_id=' . $batch['id']) ?>" class="btn-secondary py-1.5 px-4 text-xs">Batal</a>
                    <button type="submit" class="btn-primary py-1.5 px-4 text-xs font-bold">
                        Simpan Perubahan Sesi Batch
                    </button>
                </div>
            </form>
        </div>

    </main>

    <script>
    var masterPartsData = <?= json_encode($master_parts) ?>;
    var rowIndex = <?= count($items) ?>;

    function generateLotNumber() {
        var today = new Date();
        var yearYY = String(today.getFullYear()).slice(-2);
        var monthM = String(today.getMonth() + 1);
        var dayDD  = String(today.getDate()).padStart(2, '0');
        return '064' + yearYY + monthM + dayDD + 'D010000';
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

        var defaultLot = generateLotNumber();

        var partOptions = '<option value="">-- Pilih Part --</option>';
        for (var j = 0; j < masterPartsData.length; j++) {
            var p = masterPartsData[j];
            partOptions += '<option value="' + escapeHtml(p.part_code) + '" data-name="' + escapeHtml(p.part_name) + '">' + escapeHtml(p.part_code) + '</option>';
        }
        partOptions += '<option value="custom">Input Custom Code</option>';

        tr.innerHTML = `
            <input type="hidden" name="rows[${rowIndex}][item_id]" value="new">
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

    function onPartSelectChange(select, idx) {
        var customInput = document.getElementById('custom-code-' + idx);
        var nameInput = document.getElementById('part-name-' + idx);
        var selectedOpt = select.options[select.selectedIndex];

        if (select.value === 'custom') {
            customInput.classList.remove('hidden');
            customInput.required = true;
            nameInput.value = '';
        } else if (select.value !== '') {
            customInput.classList.add('hidden');
            customInput.required = false;
            var name = selectedOpt.getAttribute('data-name');
            if (name) nameInput.value = name;
        } else {
            customInput.classList.add('hidden');
            customInput.required = false;
            nameInput.value = '';
        }
    }

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
    </script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
