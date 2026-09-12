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
        $stmtBatch = $pdo->prepare("SELECT * FROM kanban_batches WHERE id = :id");
        $stmtBatch->execute([':id' => $batch_id]);
        $batch = $stmtBatch->fetch();

        if ($batch) {
            $stmtItems = $pdo->prepare("SELECT * FROM kanban_items WHERE batch_id = :batch_id AND (plan_type IS NULL OR plan_type = 'kanban') AND (check_type IS NULL OR check_type != 'Safety Stock') AND (kanban_no IS NULL OR kanban_no NOT LIKE 'SS-%') ORDER BY id ASC");
            $stmtItems->execute([':batch_id' => $batch_id]);
            $items = $stmtItems->fetchAll();

            $stmtParts = $pdo->query("SELECT id, part_code, part_name FROM master_parts ORDER BY part_code ASC");
            $master_parts = $stmtParts->fetchAll();

            $stmtCust = $pdo->query("SELECT id, name FROM master_customers WHERE UPPER(name) NOT LIKE '%SAFETY STOCK%' AND UPPER(name) NOT LIKE '%INTERNAL STOCK%' ORDER BY name ASC");
            $master_customers = $stmtCust->fetchAll();
        }
    } catch (PDOException $e) {
        $batch = null;
    }
}

if (!$batch) {
    set_flash('error', 'Dokumen Batch Kanban tidak ditemukan!');
    redirect('modules/kanban/index.php');
}

$breadcrumbCategory = "DATA REFERENSI";
$pageTitle = "Edit Dokumen Batch Kanban";
$pageSubtitle = "Perbarui item atau tambah baris data baru pada dokumen batch ini";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen min-w-0 w-full overflow-x-hidden transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-5 space-y-4 min-w-0 w-full overflow-x-hidden">
        
        <?= render_flash() ?>

        <!-- Action Card Header -->
        <div class="card p-3 md:p-4 bg-white border border-slate-200/80 rounded-xl shadow-xs">
            <div style="display: flex; align-items: center; justify-content: space-between;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <a href="<?= base_url('modules/kanban/detail_batch.php?batch_id=' . $batch['id']) ?>" class="btn-secondary py-1.5 px-3 text-xs">
                        &larr; Batal & Kembali
                    </a>
                    <h2 class="text-sm font-bold text-slate-800">
                        Edit Dokumen Batch Kanban: <span class="font-mono text-blue-700 font-extrabold"><?= htmlspecialchars($batch['document_number'] ?? 'DOC-MNL') ?></span>
                    </h2>
                </div>
            </div>
        </div>

        <!-- Multi-Row Dynamic Matrix Form for Batch Edit -->
        <div class="card p-4 bg-white border border-slate-200/80 rounded-xl shadow-xs space-y-4 max-w-full overflow-hidden">
            <form action="<?= base_url('modules/kanban/update_batch.php') ?>" method="POST" class="space-y-4 max-w-full overflow-hidden">
                <input type="hidden" name="batch_id" value="<?= $batch['id'] ?>">

                <!-- Shared Metadata Fields Header -->
                <div class="p-3 bg-slate-50 border border-slate-200 rounded-xl flex items-center justify-between text-xs">
                    <div class="flex items-center space-x-2">
                        <span class="font-bold text-slate-700">Nomor Dokumen Batch:</span>
                        <input type="text" name="document_number" value="<?= htmlspecialchars($batch['document_number'] ?? 'DOC-MNL') ?>" class="form-input py-1 text-xs font-mono font-bold max-w-xs" required>
                    </div>
                </div>

                <!-- Real-Time Search & Filter Bar for Edit Batch Table -->
                <div class="flex items-center justify-between gap-3 flex-wrap">
                    <div class="flex-1 min-w-[260px]">
                        <input type="text" id="edit-search-input" onkeyup="filterEditBatchRows()" placeholder="Cari cepat Kanban No, Item Code, Part Description, Customer, Str Loc..." class="form-input py-1.5 px-3 text-xs w-full">
                    </div>
                    <div class="text-xs text-slate-500 font-semibold">
                        Menampilkan <b id="edit-visible-count" class="text-blue-700"><?= count($items) ?></b> / <b id="edit-total-count"><?= count($items) ?></b> baris item
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
                            <tr>
                                <th class="px-3 py-2.5 text-center" style="width: 40px; min-width: 40px;">#</th>
                                <th class="px-3 py-2.5" style="min-width: 170px;">Kanban No</th>
                                <th class="px-3 py-2.5" style="min-width: 190px;">Item Code</th>
                                <th class="px-3 py-2.5" style="min-width: 220px;">Item Description</th>
                                <th class="px-3 py-2.5" style="min-width: 240px;">Customer / Tujuan PT</th>
                                <th class="px-3 py-2.5" style="min-width: 150px;">Req. Date</th>
                                <th class="px-3 py-2.5" style="min-width: 150px;">ETA</th>
                                <th class="px-3 py-2.5" style="min-width: 100px;">Qty</th>
                                <th class="px-3 py-2.5" style="min-width: 120px;">Str. Loc</th>
                                <th class="px-3 py-2.5" style="min-width: 110px;">Status Cek</th>
                                <th class="px-3 py-2.5" style="min-width: 230px;">Remark</th>
                                <th class="px-3 py-2.5 text-center" style="width: 60px; min-width: 60px;">Hapus</th>
                            </tr>
                        </thead>
                        <tbody id="kanban-rows-body" class="divide-y divide-slate-100">
                            <?php foreach ($items as $idx => $item): ?>
                                <tr id="row-<?= $idx + 1 ?>" class="hover:bg-slate-50/80 transition-colors">
                                    <input type="hidden" name="rows[<?= $idx + 1 ?>][item_id]" value="<?= $item['id'] ?>">
                                    
                                    <td class="px-3 py-2.5 text-center font-bold text-slate-400 row-number" style="width: 40px; min-width: 40px;"><?= $idx + 1 ?></td>
                                    <td class="px-3 py-2.5" style="min-width: 170px;">
                                        <input type="text" name="rows[<?= $idx + 1 ?>][kanban_no]" value="<?= htmlspecialchars($item['kanban_no']) ?>" class="form-input py-1 text-xs font-mono font-bold" required>
                                    </td>
                                    <td class="px-3 py-2.5" style="min-width: 190px;">
                                        <input type="text" name="rows[<?= $idx + 1 ?>][item_code]" value="<?= htmlspecialchars($item['item_code']) ?>" list="master-parts-list" oninput="onItemCodeInput(this, <?= $idx + 1 ?>)" class="form-input py-1 text-xs font-mono font-bold" required autocomplete="off">
                                    </td>
                                    <td class="px-3 py-2.5" style="min-width: 220px;">
                                        <input type="text" name="rows[<?= $idx + 1 ?>][item_description]" id="item-desc-<?= $idx + 1 ?>" value="<?= htmlspecialchars($item['item_description']) ?>" class="form-input py-1 text-xs" required>
                                    </td>
                                    <td class="px-3 py-2.5" style="min-width: 240px;">
                                        <?php 
                                        $curCust = $item['customer'] ?? '';
                                        $custMatched = false;
                                        ?>
                                        <select name="rows[<?= $idx + 1 ?>][customer_select]" id="cust-select-<?= $idx + 1 ?>" onchange="onCustomerSelectChange(this, <?= $idx + 1 ?>)" class="form-input py-1 text-xs font-semibold" required>
                                            <option value="">-- Pilih Customer --</option>
                                            <?php foreach ($master_customers as $c): ?>
                                                <?php 
                                                $isSelected = (strcasecmp($c['name'], $curCust) === 0);
                                                if ($isSelected) $custMatched = true;
                                                ?>
                                                <option value="<?= htmlspecialchars($c['name']) ?>" <?= $isSelected ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($c['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                            <option value="custom" <?= (!$custMatched && !empty($curCust)) ? 'selected' : '' ?>>Input Custom PT</option>
                                        </select>
                                        <input type="text" name="rows[<?= $idx + 1 ?>][customer_custom]" id="custom-cust-<?= $idx + 1 ?>" value="<?= (!$custMatched) ? htmlspecialchars($curCust) : '' ?>" placeholder="Nama PT Customer Baru" class="form-input py-1 text-xs font-semibold mt-1 <?= (!$custMatched && !empty($curCust)) ? '' : 'hidden' ?>">
                                    </td>
                                    <td class="px-3 py-2.5" style="min-width: 150px;">
                                        <input type="date" name="rows[<?= $idx + 1 ?>][req_date]" value="<?= date('Y-m-d', strtotime($item['req_date'] ?? date('Y-m-d'))) ?>" class="form-input py-1 text-xs font-semibold" required>
                                    </td>
                                    <td class="px-3 py-2.5" style="min-width: 150px;">
                                        <input type="date" name="rows[<?= $idx + 1 ?>][eta]" value="<?= date('Y-m-d', strtotime($item['eta'] ?? $item['req_date'] ?? date('Y-m-d'))) ?>" class="form-input py-1 text-xs font-semibold" required>
                                    </td>
                                    <td class="px-3 py-2.5" style="min-width: 100px;">
                                        <input type="number" name="rows[<?= $idx + 1 ?>][qty]" value="<?= htmlspecialchars($item['qty']) ?>" min="1" class="form-input py-1 text-xs font-bold text-center" required>
                                    </td>
                                    <td class="px-3 py-2.5" style="min-width: 120px;">
                                        <input type="text" name="rows[<?= $idx + 1 ?>][str_loc]" value="<?= htmlspecialchars($item['str_loc'] ?? '') ?>" class="form-input py-1 text-xs font-mono">
                                    </td>
                                    <td class="px-3 py-2.5" style="min-width: 110px;">
                                        <input type="text" name="rows[<?= $idx + 1 ?>][check_type]" value="<?= htmlspecialchars($item['check_type'] ?? '') ?>"  class="form-input py-1 text-xs font-bold text-center">
                                    </td>
                                    <td class="px-3 py-2.5" style="min-width: 230px;">
                                        <textarea name="rows[<?= $idx + 1 ?>][remark]" rows="2" placeholder="Catatan" class="form-input py-1 text-xs resize-y" style="min-height: 52px;"><?= htmlspecialchars($item['remark'] ?? '') ?></textarea>
                                    </td>
                                    <td class="px-3 py-2.5 text-center" style="width: 60px; min-width: 60px;">
                                        <button type="button" onclick="removeKanbanRow(<?= $idx + 1 ?>)" class="p-1 text-rose-500 hover:text-rose-700 hover:bg-rose-50 rounded" title="Hapus Baris">
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
                        <button type="button" onclick="addKanbanRow()" class="btn-secondary py-1.5 px-3 text-xs flex items-center">
                            <svg class="w-3.5 h-3.5 mr-1 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                            </svg>
                            Tambah 1 Baris Baru
                        </button>
                        <button type="button" onclick="addMultipleKanbanRows(5)" class="btn-secondary py-1.5 px-3 text-xs flex items-center">
                            <svg class="w-3.5 h-3.5 mr-1 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                            </svg>
                            Tambah 5 Baris Baru
                        </button>
                    </div>
                    <span class="text-xs text-slate-500">Total: <b id="row-counter" class="text-slate-800"><?= count($items) ?></b> baris data</span>
                </div>

                <div class="pt-3 border-t border-slate-100 flex justify-end space-x-2">
                    <a href="<?= base_url('modules/kanban/detail_batch.php?batch_id=' . $batch['id']) ?>" class="btn-secondary py-1.5 px-4 text-xs">Batal</a>
                    <button type="submit" class="btn-primary py-1.5 px-4 text-xs font-bold">
                        Simpan Perubahan Sesi Batch
                    </button>
                </div>
            </form>
        </div>

    </main>

    <script>
    var masterPartsData = <?= json_encode($master_parts) ?>;
    var masterCustomersData = <?= json_encode($master_customers) ?>;
    var rowIndex = <?= count($items) ?>;

    function addMultipleKanbanRows(count) {
        for (var i = 0; i < count; i++) {
            addKanbanRow();
        }
    }

    function addKanbanRow() {
        rowIndex++;
        var tbody = document.getElementById('kanban-rows-body');
        var tr = document.createElement('tr');
        tr.id = 'row-' + rowIndex;
        tr.className = 'hover:bg-slate-50/80 transition-colors';

        var partOptions = '<option value="">-- Pilih Part --</option>';
        for (var j = 0; j < masterPartsData.length; j++) {
            var p = masterPartsData[j];
            partOptions += '<option value="' + escapeHtml(p.part_code) + '" data-name="' + escapeHtml(p.part_name) + '">' + escapeHtml(p.part_code) + '</option>';
        }
        partOptions += '<option value="custom">Input Custom Item Code</option>';

        var custOptions = '<option value="">-- Pilih Customer --</option>';
        for (var k = 0; k < masterCustomersData.length; k++) {
            var c = masterCustomersData[k];
            custOptions += '<option value="' + escapeHtml(c.name) + '">' + escapeHtml(c.name) + '</option>';
        }
        custOptions += '<option value="custom">Input Custom PT</option>';

        var todayDate = new Date().toISOString().split('T')[0];

        tr.innerHTML = `
            <input type="hidden" name="rows[${rowIndex}][item_id]" value="new">
            <td class="px-3 py-2.5 text-center font-bold text-slate-400 row-number">${tbody.children.length + 1}</td>
            <td class="px-3 py-2.5">
                <input type="text" name="rows[${rowIndex}][kanban_no]" placeholder="0004573892" class="form-input py-1 text-xs font-mono font-bold" required>
            </td>
            <td class="px-3 py-2.5">
                <input type="text" name="rows[${rowIndex}][item_code]" list="master-parts-list" oninput="onItemCodeInput(this, ${rowIndex})" placeholder="Ketik / Cari Item Code..." class="form-input py-1 text-xs font-mono font-bold" required autocomplete="off">
            </td>
            <td class="px-3 py-2.5">
                <input type="text" name="rows[${rowIndex}][item_description]" id="item-desc-${rowIndex}" placeholder="Item Description" class="form-input py-1 text-xs" required>
            </td>
            <td class="px-3 py-2.5">
                <select name="rows[${rowIndex}][customer_select]" id="cust-select-${rowIndex}" onchange="onCustomerSelectChange(this, ${rowIndex})" class="form-input py-1 text-xs font-semibold" required>
                    ${custOptions}
                </select>
                <input type="text" name="rows[${rowIndex}][customer_custom]" id="custom-cust-${rowIndex}" placeholder="Nama PT Customer Baru" class="form-input py-1 text-xs font-semibold mt-1 hidden">
            </td>
            <td class="px-3 py-2.5">
                <input type="date" name="rows[${rowIndex}][req_date]" value="${todayDate}" class="form-input py-1 text-xs font-semibold" required>
            </td>
            <td class="px-3 py-2.5">
                <input type="date" name="rows[${rowIndex}][eta]" value="${todayDate}" class="form-input py-1 text-xs font-semibold" required>
            </td>
            <td class="px-3 py-2.5">
                <input type="number" name="rows[${rowIndex}][qty]" value="500" min="1" placeholder="Qty" class="form-input py-1 text-xs font-bold text-center" required>
            </td>
            <td class="px-3 py-2.5">
                <input type="text" name="rows[${rowIndex}][str_loc]" value="WH-A01" placeholder="WH-A01" class="form-input py-1 text-xs font-mono">
            </td>
            <td class="px-3 py-2.5">
                <input type="text" name="rows[${rowIndex}][check_type]"  class="form-input py-1 text-xs font-bold text-center">
            </td>
            <td class="px-3 py-2.5">
                <textarea name="rows[${rowIndex}][remark]" rows="2" placeholder="Catatan" class="form-input py-1 text-xs resize-y"></textarea>
            </td>
            <td class="px-3 py-2.5 text-center">
                <button type="button" onclick="removeKanbanRow(${rowIndex})" class="p-1 text-rose-500 hover:text-rose-700 hover:bg-rose-50 rounded" title="Hapus Baris">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                    </svg>
                </button>
            </td>
        `;

        tbody.appendChild(tr);

        if (masterCustomersData.length > 0) {
            var custSel = document.getElementById('cust-select-' + rowIndex);
            if (custSel) custSel.value = masterCustomersData[0].name;
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

    function updateRowNumbers() {
        var tbody = document.getElementById('kanban-rows-body');
        var rows = tbody.querySelectorAll('tr');
        for (var i = 0; i < rows.length; i++) {
            var numCell = rows[i].querySelector('.row-number');
            if (numCell) numCell.textContent = i + 1;
        }
    }

    function filterEditBatchRows() {
        var query = document.getElementById('edit-search-input').value.toLowerCase().trim();
        var tbody = document.getElementById('kanban-rows-body');
        if (!tbody) return;
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

        var visibleElem = document.getElementById('edit-visible-count');
        var totalElem = document.getElementById('edit-total-count');
        if (visibleElem) visibleElem.textContent = visibleCount;
        if (totalElem) totalElem.textContent = rows.length;
    }

    function updateRowCounter() {
        var tbody = document.getElementById('kanban-rows-body');
        var count = tbody.querySelectorAll('tr').length;
        var counterElem = document.getElementById('row-counter');
        if (counterElem) counterElem.textContent = count;
        filterEditBatchRows();
    }

    function escapeHtml(text) {
        if (!text) return '';
        return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }
    </script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
