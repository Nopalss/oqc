<?php
/**
 * Halaman Tutup Kanban (Multi-Lot Manual & Scan)
 * Modul Outgoing Quality Control (OQC)
 */

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

require_menu_access('inspection');

$pdo = getDB();
$kanbanId = isset($_GET['kanban_id']) ? (int)$_GET['kanban_id'] : 0;

if ($kanbanId <= 0 || !$pdo) {
    redirect(base_url('modules/inspection/index.php'));
    exit;
}

// Ambil data kanban
$stmt = $pdo->prepare("
    SELECT k.*, 
           COALESCE(NULLIF(mp.part_name, ''), k.item_description) as display_part_name,
           COALESCE(mp.part_code, k.item_code) as resolved_part_code
    FROM kanban_items k
    LEFT JOIN master_parts mp ON mp.part_code = k.item_code
    WHERE k.id = :kid
    LIMIT 1
");
$stmt->execute([':kid' => $kanbanId]);
$kanban = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$kanban) {
    set_flash('error', 'Data Kanban tidak ditemukan.');
    redirect(base_url('modules/inspection/index.php'));
    exit;
}

// Cek jumlah kuota yang sudah lulus sebelumnya
$stmtSess = $pdo->prepare("SELECT id, total_scanned_qty, excess_qty, status FROM inspection_sessions WHERE kanban_item_id = :kid");
$stmtSess->execute([':kid' => $kanbanId]);
$sessions = $stmtSess->fetchAll(PDO::FETCH_ASSOC);

$alreadyPassed = 0;
foreach ($sessions as $s) {
    if ($s['status'] === 'passed') {
        $effective = (int)$s['total_scanned_qty'] - (int)($s['excess_qty'] ?? 0);
        $alreadyPassed += max(0, $effective);
    }
}

$targetQty = (int)$kanban['qty'];
$remainingQty = max(0, $targetQty - $alreadyPassed);

$pageTitle = "Tutup Kanban #" . ($kanban['kanban_no'] ?: $kanban['id']);
require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen transition-all duration-300">
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-5 space-y-4">
        
        <?= render_flash() ?>

        <!-- Action Card Header -->
        <div class="card p-3 md:p-4 bg-white border border-slate-200/80 rounded-xl shadow-xs">
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <a href="<?= base_url('modules/inspection/index.php') ?>" class="btn-secondary py-1.5 px-3 text-xs">
                        &larr; Kembali
                    </a>
                    <div>
                        <h2 class="text-sm font-bold text-slate-800">Tutup Kanban (Input Lot Fisik)</h2>
                        <p class="text-[11px] text-slate-400">Scan barcode atau pilih tanggal lot. Qty otomatis dibagi rata.</p>
                    </div>
                </div>

                <!-- Info Ringkas Kanban & Inspector -->
                <div class="flex items-center gap-2 flex-wrap">
                    <?php $currentUser = current_user(); ?>
                    <span class="px-2.5 py-1 bg-purple-50 text-purple-700 font-bold text-xs rounded-lg border border-purple-200 flex items-center gap-1.5" title="Inspector QC yang menutup Kanban">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                        <span>Inspector: <b><?= htmlspecialchars($currentUser['name'] ?? 'Inspector QC') ?></b></span>
                    </span>
                    <span class="px-2.5 py-1 bg-slate-100 text-slate-700 font-mono font-bold text-xs rounded-lg border border-slate-200">
                        #<?= htmlspecialchars($kanban['kanban_no'] ?: $kanban['id']) ?>
                    </span>
                    <span class="px-2.5 py-1 bg-blue-50 text-blue-700 font-mono font-bold text-xs rounded-lg border border-blue-200">
                        <?= htmlspecialchars($kanban['item_code']) ?>
                    </span>
                    <span class="px-2.5 py-1 bg-emerald-50 text-emerald-700 font-bold text-xs rounded-lg border border-emerald-200">
                        Target: <b id="display-remaining-qty" data-remaining="<?= $remainingQty ?>"><?= number_format($remainingQty) ?></b> pcs
                    </span>
                </div>
            </div>
        </div>

        <!-- Single Card: Barcode Scanner & Table Lot -->
        <div class="card p-4 bg-white border border-slate-200/80 rounded-xl shadow-xs space-y-4">
            
            <!-- Area Cepat Scanner Barcode & Tanggal Default -->
            <div class="p-3 bg-slate-50 border border-slate-200 rounded-xl space-y-3 text-xs">
                <div>
                    <label class="form-label font-bold text-slate-700">
                        Scan Barcode / QR Lot <span class="text-slate-400 font-normal">(Arahkan Scanner / Enter)</span>
                    </label>
                    <input type="text" id="scan_lot_input" 
                           placeholder="Scan Barcode / Paste payload QR lot di sini..." 
                           class="form-input text-xs font-mono font-bold focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                           autofocus autocomplete="off" autocorrect="off" spellcheck="false">
                </div>
                <div>
                    <label class="form-label font-bold text-slate-700">Tanggal Lot Default</label>
                    <div class="flex items-center gap-2">
                        <input type="date" id="default_lot_date" value="<?= date('Y-m-d') ?>" class="form-input text-xs font-bold max-w-xs flex-1 sm:flex-none sm:w-64">
                        <button type="button" onclick="addManualLotRowFromDate()" class="btn-primary py-2 px-4 text-xs font-bold whitespace-nowrap bg-blue-600 hover:bg-blue-700 text-white border border-blue-600 rounded-lg flex items-center gap-1.5 shadow-xs" title="Tambah 1 baris lot manual dengan tanggal ini">
                            <svg class="w-3.5 h-3.5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
                            </svg>
                            <span>Tambah Baris</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Tabel Dinamis Multi-Baris Lot -->
            <div class="overflow-x-auto border border-slate-200 rounded-xl">
                <table class="w-full text-left text-xs text-slate-700" id="lots-table">
                    <thead class="bg-slate-100 text-slate-700 font-bold border-b border-slate-200 text-[11px] uppercase">
                        <tr>
                            <th class="px-2 py-2.5 text-center w-8">#</th>
                            <th class="px-2 py-2.5 w-36">Tanggal Lot</th>
                            <th class="px-2 py-2.5 min-w-[180px]">Lot Number</th>
                            <th class="px-2 py-2.5 min-w-[140px]">Ref Number <span class="text-slate-400 font-normal normal-case">(Acak Otomatis)</span></th>
                            <th class="px-2 py-2.5 w-32 text-center">Qty Pcs <span class="text-slate-400 font-normal normal-case">(Bagi Rata)</span></th>
                            <th class="px-2 py-2.5 w-28 text-center">Status</th>
                            <th class="px-2 py-2.5 text-center w-12">Hapus</th>
                        </tr>
                    </thead>
                    <tbody id="lots-rows-body" class="divide-y divide-slate-100">
                        <tr id="empty-lots-state">
                            <td colspan="7" class="px-4 py-8 text-center text-slate-400">
                                <div class="flex flex-col items-center justify-center space-y-1.5">
                                    <svg class="w-8 h-8 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                                    </svg>
                                    <span class="text-xs font-medium text-slate-500">Belum ada baris lot fisik.</span>
                                    <span class="text-[11px] text-slate-400">Silakan arahkan scanner barcode/QR di atas atau klik tombol <b>+ Tambah 1 Baris</b>.</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Tombol Tambah Baris & Info Counter -->
            <div class="flex items-center justify-between pt-1 flex-wrap gap-2">
                <div class="flex items-center space-x-2">
                    <button type="button" onclick="addLotRow()" class="btn-secondary py-1.5 px-3 text-xs flex items-center font-semibold text-blue-700 bg-blue-50 hover:bg-blue-100 border-blue-200">
                        <svg class="w-3.5 h-3.5 mr-1 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                        </svg>
                        Tambah 1 Baris
                    </button>
                    <button type="button" onclick="clearAllLotRows()" class="btn-secondary py-1.5 px-2.5 text-xs text-rose-600 hover:bg-rose-50 border-rose-200" title="Kosongkan seluruh baris di tabel">
                        Kosongkan Tabel
                    </button>
                </div>
                <div class="text-xs text-slate-500 flex items-center gap-2 flex-wrap">
                    <span>Total: <b id="row-counter" class="text-slate-800">0</b> Lot</span>
                    <span>|</span>
                    <span>Akumulasi Qty: <b id="sum-qty-counter" class="font-mono text-blue-700">0</b> / <?= number_format($remainingQty) ?> pcs</span>
                    <span id="excess-indicator-badge" style="display:none;" class="px-2 py-0.5 rounded text-[11px] font-bold bg-amber-100 text-amber-800 border border-amber-300">
                        <!-- Auto-filled by JS -->
                    </span>
                </div>
            </div>

            <!-- Catatan Selesai -->
            <div class="pt-2">
                <label for="close_remarks" class="form-label font-bold text-slate-700">
                    Alasan / Keterangan Penutupan <span class="text-slate-400 font-normal">(Opsional)</span>
                </label>
                <input type="text" id="close_remarks" placeholder="Misal: Urgent shipment / instruksi chief QC" class="form-input text-xs">
            </div>

            <!-- Tombol Aksi Simpan -->
            <div class="pt-3 border-t border-slate-100 flex justify-end space-x-2">
                <a href="<?= base_url('modules/inspection/index.php') ?>" class="btn-secondary py-1.5 px-4 text-xs">Batal</a>
                <button type="button" id="btn-submit-close" onclick="submitCloseKanban()" class="btn-primary py-1.5 px-4 text-xs font-bold flex items-center gap-1.5 bg-emerald-600 hover:bg-emerald-700">
                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                    <span>Simpan & Tutup Kanban</span>
                </button>
            </div>

        </div>

    </main>

<!-- Modal Konfirmasi Lot Pernah NG -->
<div id="modalConfirmNg" style="display:none;" class="fixed inset-0 bg-slate-900/60 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl max-w-md w-full p-5 space-y-3 shadow-xl border border-slate-200">
        <div class="flex items-center space-x-2.5 text-rose-600">
            <svg class="w-6 h-6 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
            <h3 class="text-sm font-bold text-slate-800">Perhatian: Riwayat Lot NG</h3>
        </div>

        <div class="bg-rose-50 border border-rose-200 rounded-lg p-2.5 text-xs space-y-1 text-rose-900">
            <div><b>Lot:</b> <span id="modal-ng-lot" class="font-mono font-bold"></span></div>
            <div id="modal-ng-desc" class="text-[11px] leading-relaxed"></div>
        </div>

        <p class="text-xs text-slate-700 leading-normal">
            Apakah masalah mutu pada lot ini <b>sudah fix / selesai diperbaiki</b> dan siap diterima untuk menutup Kanban?
        </p>

        <div class="flex items-center justify-end space-x-2 pt-2 border-t border-slate-100">
            <button type="button" onclick="cancelNgConfirmation()" class="btn-secondary py-1 px-3 text-xs">
                Batal
            </button>
            <button type="button" onclick="acceptNgConfirmation()" class="btn-primary py-1 px-3 text-xs font-bold bg-rose-600 hover:bg-rose-700">
                Ya, Sudah Fix (Diterima)
            </button>
        </div>
    </div>
</div>

<script>
var currentPartCode = <?= json_encode($kanban['item_code']) ?>;
var kanbanId = <?= (int)$kanban['id'] ?>;
var remainingTargetQty = <?= (int)$remainingQty ?>;
var rowCounterIdx = 0;
var pendingNgRowId = null;

// Helper fokus otomatis khusus scanner tanpa mengganggu interaksi form lain
function focusScanner(force) {
    var scanInp = document.getElementById('scan_lot_input');
    if (!scanInp) return;
    
    // Jangan bajak fokus jika user sedang sengaja mengetik/klik di input, textarea, atau select lain
    if (!force) {
        var active = document.activeElement;
        if (active && active !== scanInp && active !== document.body) {
            var tag = active.tagName ? active.tagName.toUpperCase() : '';
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') {
                return;
            }
        }
    }
    
    setTimeout(function() {
        if (scanInp && document.activeElement !== scanInp) {
            scanInp.focus();
        }
    }, 60);
}

// Format Lot standar persis seperti di modules/did/create.php
function generateLotNumber(dateStr) {
    if (!dateStr) dateStr = document.getElementById('default_lot_date').value;
    if (!dateStr) return '06426817D010000';
    
    var parts = dateStr.split('-');
    if (parts.length === 3) {
        var yearYY = parts[0].slice(-2);            // misal "26"
        var monthM = String(parseInt(parts[1], 10)); // misal "8" tanpa leading zero
        var dayDD  = parts[2];                       // misal "17"
        return '064' + yearYY + monthM + dayDD + 'D010000';
    }
    return '06426817D010000';
}

// Handler perubahan tanggal pada baris spesifik di tabel
function onRowDateChange(idx) {
    var dInput = document.getElementById('date-' + idx);
    var lInput = document.getElementById('lot-' + idx);
    if (dInput && lInput) {
        lInput.value = generateLotNumber(dInput.value);
        checkRowLotStatus(idx);
    }
}

// Generate unik ref number helper otomatis
function generateUniqueRef(idx) {
    var randomStr = Math.random().toString(36).substring(2, 7).toUpperCase();
    var dVal = document.getElementById('default_lot_date') ? document.getElementById('default_lot_date').value : '';
    var dateStr = dVal ? dVal.replace(/-/g, '').slice(-4) : '1014';
    return 'REF-' + dateStr + '-' + (idx + 1) + randomStr;
}

// Render HTML tampilan kosong (empty state)
function renderEmptyStateHtml() {
    return `
        <tr id="empty-lots-state">
            <td colspan="7" class="px-4 py-8 text-center text-slate-400">
                <div class="flex flex-col items-center justify-center space-y-1.5">
                    <svg class="w-8 h-8 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                    </svg>
                    <span class="text-xs font-medium text-slate-500">Belum ada baris lot fisik.</span>
                    <span class="text-[11px] text-slate-400">Silakan arahkan scanner barcode/QR di atas atau klik tombol <b>+ Tambah 1 Baris</b>.</span>
                </div>
            </td>
        </tr>
    `;
}

// Kosongkan seluruh tabel kembali ke state awal
function clearAllLotRows() {
    var tbody = document.getElementById('lots-rows-body');
    tbody.innerHTML = renderEmptyStateHtml();
    rowCounterIdx = 0;
    updateRowCounters();
    recalculateDistribution();
    focusScanner(true);
}

// Tambah 1 baris lot manual berdasarkan input tanggal di card atas
function addManualLotRowFromDate() {
    var dInput = document.getElementById('default_lot_date');
    var curDate = dInput ? dInput.value : '';
    if (!curDate) {
        curDate = '<?= date('Y-m-d') ?>';
        if (dInput) dInput.value = curDate;
    }
    var lotNum = generateLotNumber(curDate);
    var newIdx = addLotRow({
        date: curDate,
        lot: lotNum,
        ref: '',
        qty: '',
        is_scan: false
    });

    var newRow = document.getElementById('row-' + newIdx);
    if (newRow) {
        newRow.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
}

// Tambah 1 baris lot baru atau render baris
function addLotRow(initData) {
    // Hilangkan empty state jika masih ada di tabel
    var emptyState = document.getElementById('empty-lots-state');
    if (emptyState) {
        emptyState.remove();
    }

    rowCounterIdx++;
    var idx = rowCounterIdx;
    var tbody = document.getElementById('lots-rows-body');
    var tr = document.createElement('tr');
    tr.id = 'row-' + idx;
    tr.className = 'hover:bg-slate-50/80 transition-colors lot-data-row';
    tr.setAttribute('data-row-idx', idx);

    var defaultDate = (initData && initData.date) ? initData.date : document.getElementById('default_lot_date').value;
    var defaultLot  = (initData && initData.lot)  ? initData.lot  : generateLotNumber(defaultDate);
    var defaultRef  = (initData && initData.ref)  ? initData.ref  : '';
    var defaultQty  = (initData && initData.qty)  ? initData.qty  : '';
    var isFromScan  = (initData && initData.is_scan) ? '1' : '0';

    tr.setAttribute('data-from-scan', isFromScan);

    tr.innerHTML = `
        <td class="px-2 py-2 text-center font-bold text-slate-400 row-number">${document.querySelectorAll('#lots-rows-body tr.lot-data-row').length + 1}</td>
        <td class="px-2 py-2">
            <input type="date" id="date-${idx}" value="${defaultDate}" onchange="onRowDateChange(${idx})" class="form-input py-1 text-xs font-bold row-date">
        </td>
        <td class="px-2 py-2">
            <input type="text" id="lot-${idx}" value="${escapeHtml(defaultLot)}" onblur="checkRowLotStatus(${idx})" oninput="markRowModified(${idx})" placeholder="06426817D010000" class="form-input py-1 text-xs font-mono font-bold row-lot-number" required>
        </td>
        <td class="px-2 py-2">
            <input type="text" id="ref-${idx}" value="${escapeHtml(defaultRef)}" onblur="checkRowLotStatus(${idx})" oninput="markRowModified(${idx})" placeholder="Acak jika kosong" class="form-input py-1 text-xs font-mono row-ref-number">
        </td>
        <td class="px-2 py-2 text-center">
            <input type="number" id="qty-${idx}" value="${defaultQty}" min="0" oninput="markRowModified(${idx}); recalculateDistribution()" placeholder="Bagi rata" class="form-input py-1 text-xs font-mono font-bold text-right row-qty" style="max-width: 110px; margin: 0 auto;">
            <div id="ss-note-${idx}" class="text-[10px] text-purple-700 font-semibold mt-0.5" style="display:none;"></div>
        </td>
        <td class="px-2 py-2 text-center" id="status-cell-${idx}">
            <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-slate-100 text-slate-600 border border-slate-200">OK</span>
        </td>
        <td class="px-2 py-2 text-center">
            <input type="hidden" id="ng-fixed-${idx}" value="0" class="row-ng-fixed">
            <input type="hidden" id="is-ss-${idx}" value="0" class="row-is-ss">
            <button type="button" onclick="removeLotRow(${idx})" class="p-1 text-rose-500 hover:text-rose-700 hover:bg-rose-50 rounded" title="Hapus Baris">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                </svg>
            </button>
        </td>
    `;

    tbody.appendChild(tr);
    updateRowCounters();
    recalculateDistribution();

    if (defaultLot) {
        checkRowLotStatus(idx);
    }
    return idx;
}

function markRowModified(idx) {
    var tr = document.getElementById('row-' + idx);
    if (tr) {
        tr.setAttribute('data-user-modified', '1');
    }
}

function removeLotRow(idx) {
    var tr = document.getElementById('row-' + idx);
    if (tr) {
        var tbody = tr.parentNode;
        tr.remove();

        var remainingRows = document.querySelectorAll('#lots-rows-body tr.lot-data-row');
        if (remainingRows.length === 0 && tbody) {
            tbody.innerHTML = renderEmptyStateHtml();
        } else {
            updateRowNumbers();
        }
        updateRowCounters();
        recalculateDistribution();
    }
}

function updateRowNumbers() {
    var rows = document.querySelectorAll('#lots-rows-body tr.lot-data-row');
    rows.forEach(function(row, i) {
        var num = row.querySelector('.row-number');
        if (num) num.textContent = i + 1;
    });
}

function updateRowCounters() {
    var count = document.querySelectorAll('#lots-rows-body tr.lot-data-row').length;
    document.getElementById('row-counter').textContent = count;
}

// Kalkulasi pembagian rata jika kolom Qty dikosongkan & cek status excess
function recalculateDistribution() {
    var rows = document.querySelectorAll('#lots-rows-body tr.lot-data-row');
    var totalSpecified = 0;
    var unspecifiedRows = [];

    rows.forEach(function(row) {
        var qInput = row.querySelector('.row-qty');
        var val = parseInt(qInput.value);
        if (!isNaN(val) && val > 0) {
            totalSpecified += val;
        } else {
            unspecifiedRows.push(qInput);
        }
    });

    var distributedQty = 0;
    var remainderQty = 0;
    if (unspecifiedRows.length > 0) {
        var sisa = Math.max(0, remainingTargetQty - totalSpecified);
        distributedQty = Math.floor(sisa / unspecifiedRows.length);
        remainderQty = sisa % unspecifiedRows.length;
    }

    var totalAccumulated = totalSpecified;
    unspecifiedRows.forEach(function(qInput, idx) {
        var est = distributedQty + (idx < remainderQty ? 1 : 0);
        qInput.placeholder = est > 0 ? est : '0';
        totalAccumulated += est;
    });

    document.getElementById('sum-qty-counter').textContent = Number(totalAccumulated).toLocaleString();

    // Tampilkan notifikasi indikator kelebihan (Excess)
    var excessBadge = document.getElementById('excess-indicator-badge');
    if (excessBadge) {
        if (totalAccumulated > remainingTargetQty && rows.length > 0) {
            var diff = totalAccumulated - remainingTargetQty;
            excessBadge.style.display = 'inline-block';
            excessBadge.innerHTML = '⚡ Lebih +' + diff.toLocaleString() + ' pcs &rarr; Otomatis Masuk Safety Stock';
        } else {
            excessBadge.style.display = 'none';
        }
    }
}

// Cek status lot ke database apakah pernah NG atau merupakan Safety Stock
function checkRowLotStatus(idx) {
    var lotEl = document.getElementById('lot-' + idx);
    var refEl = document.getElementById('ref-' + idx);
    if (!lotEl) return;

    var lotVal = lotEl.value.trim();
    var refVal = refEl ? refEl.value.trim() : '';
    if (!lotVal) return;

    var url = '<?= base_url('modules/inspection/api/check_lot_status.php') ?>' + 
              '?part_code=' + encodeURIComponent(currentPartCode) + 
              '&lot_number=' + encodeURIComponent(lotVal) + 
              '&ref_number=' + encodeURIComponent(refVal) +
              '&current_kanban_id=' + encodeURIComponent(kanbanId);

    fetch(url)
        .then(function(r){ return r.json(); })
        .then(function(data) {
            var statusCell = document.getElementById('status-cell-' + idx);
            var fixedInput = document.getElementById('ng-fixed-' + idx);
            var isSsInput  = document.getElementById('is-ss-' + idx);
            var qtyInput   = document.getElementById('qty-' + idx);
            var ssNote     = document.getElementById('ss-note-' + idx);
            if (!statusCell) return;

            // 1. Validasi Safety Stock
            if (data.success && data.is_safety_stock) {
                if (data.is_exhausted) {
                    // STOK HABIS (0 pcs) -> TOLAK & HAPUS BARIS!
                    Swal.fire({
                        icon: 'error',
                        title: 'Safety Stock Habis',
                        text: data.ss_details || 'Sisa kuota safety stock ini adalah 0 pcs. Box tidak dapat digunakan!'
                    }).then(function() {
                        focusScanner(true);
                    });
                    removeLotRow(idx);
                    return;
                } else {
                    // STOK TERSEDIA -> Gunakan sisa kuota gudang
                    if (isSsInput) isSsInput.value = '1';
                    if (qtyInput) {
                        qtyInput.value = data.ss_available_qty;
                    }
                    if (ssNote) {
                        ssNote.textContent = 'Safety Stock (Sisa: ' + data.ss_available_qty + ' pcs)';
                        ssNote.style.display = 'block';
                    }
                    statusCell.innerHTML = '<span class="px-2 py-0.5 rounded text-[10px] font-bold bg-purple-50 text-purple-700 border border-purple-200" title="' + escapeHtml(data.ss_details) + '">Safety Stock</span>';
                    recalculateDistribution();
                    return;
                }
            } else {
                if (isSsInput) isSsInput.value = '0';
                if (ssNote) ssNote.style.display = 'none';
            }

            // 2. Proteksi Double-Use: Ditolak jika sudah pernah digunakan & passed di Kanban lain
            if (data.success && data.already_used_other_kanban) {
                var uInfo = data.used_info || {};
                var pCode = uInfo.part_code || currentPartCode;
                var lNum  = uInfo.lot_number || lotVal;
                var rNum  = uInfo.ref_number || refVal;
                var kbNo  = uInfo.kanban_no || '-';
                var sessId = uInfo.session_id ? ' (Sesi #' + uInfo.session_id + ')' : '';

                var modalHtml = `
                    <div class="text-left space-y-3 pt-1">
                        <div class="bg-rose-50 border border-rose-200 text-rose-800 text-xs px-3 py-2 rounded-lg font-medium flex items-center gap-1.5">
                            <svg class="w-4 h-4 flex-shrink-0 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                            <span>Label box fisik ini <b>sudah PASSED</b> di Kanban lain!</span>
                        </div>

                        <div class="bg-slate-50 border border-slate-200 rounded-xl p-3 text-xs space-y-2">
                            <div class="flex items-center justify-between border-b border-slate-200/80 pb-1.5">
                                <span class="text-slate-500 font-bold">Part Code:</span>
                                <span class="px-2 py-0.5 rounded bg-blue-100 text-blue-800 font-mono font-bold">${escapeHtml(pCode)}</span>
                            </div>
                            <div class="flex items-center justify-between border-b border-slate-200/80 pb-1.5">
                                <span class="text-slate-500 font-bold">Lot Number:</span>
                                <span class="px-2 py-0.5 rounded bg-slate-200 text-slate-800 font-mono font-bold">${escapeHtml(lNum)}</span>
                            </div>
                            <div class="flex items-center justify-between border-b border-slate-200/80 pb-1.5">
                                <span class="text-slate-500 font-bold">Ref Number:</span>
                                <span class="px-2 py-0.5 rounded bg-purple-100 text-purple-800 font-mono font-bold">${escapeHtml(rNum)}</span>
                            </div>
                            <div class="flex items-center justify-between pt-0.5">
                                <span class="text-slate-500 font-bold">Terpakai Pada:</span>
                                <span class="font-bold text-slate-800 font-mono">Kanban #${escapeHtml(kbNo)}${sessId}</span>
                            </div>
                        </div>

                        <p class="text-[11px] text-slate-500 text-center leading-relaxed">
                            Box fisik dengan identitas label ini tidak dapat digunakan kembali demi integritas data QC.
                        </p>
                    </div>
                `;

                Swal.fire({
                    icon: 'error',
                    title: 'Label Box Fisik Ditolak',
                    html: modalHtml,
                    confirmButtonColor: '#e11d48',
                    confirmButtonText: 'Tutup & Ganti Label'
                }).then(function() {
                    focusScanner(true);
                });
                removeLotRow(idx);
                return;
            }

            // 3. Proteksi NG: Tanyakan konfirmasi apakah sudah fix
            if (data.success && data.is_previously_ng) {
                pendingNgRowId = idx;
                document.getElementById('modal-ng-lot').textContent = lotVal + (refVal ? ' (' + refVal + ')' : '');
                document.getElementById('modal-ng-desc').textContent = data.ng_details;
                document.getElementById('modalConfirmNg').style.display = 'flex';
            } else {
                if (fixedInput) fixedInput.value = '0';
                statusCell.innerHTML = '<span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">OK</span>';
            }
        })
        .catch(function(){});
}

function acceptNgConfirmation() {
    if (pendingNgRowId !== null) {
        var statusCell = document.getElementById('status-cell-' + pendingNgRowId);
        var fixedInput = document.getElementById('ng-fixed-' + pendingNgRowId);
        if (statusCell && fixedInput) {
            fixedInput.value = '1';
            statusCell.innerHTML = '<span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200" title="Riwayat NG sudah fix">Fixed (Eks NG)</span>';
        }
    }
    cancelNgConfirmation();
    focusScanner(true);
}

function cancelNgConfirmation() {
    if (pendingNgRowId !== null) {
        var fixedInput = document.getElementById('ng-fixed-' + pendingNgRowId);
        if (fixedInput && fixedInput.value !== '1') {
            // Kosongkan lot jika dibatalkan
            var lotEl = document.getElementById('lot-' + pendingNgRowId);
            if (lotEl) lotEl.value = '';
        }
    }
    pendingNgRowId = null;
    document.getElementById('modalConfirmNg').style.display = 'none';
    focusScanner(true);
}

// Scanner Input Handler dengan parsing Z1..Z5
function parseScan(raw) {
    var res = { part: '', lot: '', ref: '', qty: 0, is_qr: false };
    if (!raw) return res;
    raw = raw.trim();
    if (raw.indexOf('|') !== -1 || raw.indexOf('Z1') !== -1 || raw.indexOf('Z2') !== -1 || raw.indexOf('Z5') !== -1) {
        res.is_qr = true;
        var parts = raw.split('|');
        parts.forEach(function(p) {
            p = p.trim();
            if (p.indexOf('Z1') === 0) res.part = p.substring(2).trim();
            else if (p.indexOf('Z2') === 0) res.lot = p.substring(2).trim();
            else if (p.indexOf('Z3') === 0) res.qty = parseInt(p.substring(2)) || 0;
            else if (p.indexOf('Z5') === 0) res.ref = p.substring(2).trim();
        });
    } else {
        res.lot = raw;
    }
    return res;
}

document.getElementById('scan_lot_input').addEventListener('keydown', function(e) {
    if (e.key === 'Enter' || e.keyCode === 13) {
        e.preventDefault();
        var val = this.value.trim();
        if (!val) {
            focusScanner(true);
            return;
        }
        var parsed = parseScan(val);
        this.value = '';

        // Validasi kesesuaian Part Code jika QR berisi Z1
        if (parsed.is_qr && parsed.part && parsed.part !== currentPartCode) {
            Swal.fire({
                icon: 'warning',
                title: 'Part Code Tidak Sesuai',
                html: '<div class="text-left text-xs"><p>Part Code di label barcode: <b>' + escapeHtml(parsed.part) + '</b></p><p>Part target Kanban ini: <b>' + escapeHtml(currentPartCode) + '</b></p></div>'
            }).then(function() {
                focusScanner(true);
            });
            return;
        }

        var scanDate = document.getElementById('default_lot_date').value;

        // Tambah baris lot baru secara 1-per-1 dari hasil scan
        addLotRow({
            date: scanDate,
            lot: parsed.lot,
            ref: parsed.ref,
            qty: parsed.qty > 0 ? parsed.qty : '',
            is_scan: true
        });

        // Kembalikan fokus ke kolom scanner untuk scan lot berikutnya
        focusScanner(true);
    }
});

// Submit Form
function submitCloseKanban() {
    var rows = document.querySelectorAll('#lots-rows-body tr.lot-data-row');
    if (rows.length === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Tabel Masih Kosong',
            text: 'Harap scan barcode lot atau tambahkan minimal 1 baris Lot terlebih dahulu.'
        });
        return;
    }

    var lotPayload = [];
    var hasEmptyLot = false;
    var lotOccurrences = {};

    rows.forEach(function(row, rIdx) {
        var lotEl = row.querySelector('.row-lot-number');
        var refEl = row.querySelector('.row-ref-number');
        var qtyEl = row.querySelector('.row-qty');
        var fixEl = row.querySelector('.row-ng-fixed');

        var lNum = lotEl ? lotEl.value.trim() : '';
        var rNum = refEl ? refEl.value.trim() : '';
        var qVal = qtyEl && qtyEl.value ? parseInt(qtyEl.value) : 0;
        var isFix = fixEl ? parseInt(fixEl.value) : 0;

        if (!lNum) {
            hasEmptyLot = true;
            return;
        }

        // Jika Ref Number kosong, buatkan nomor referensi unik otomatis (misal REF-0817-1ABC)
        if (!rNum) {
            rNum = generateUniqueRef(rIdx);
            if (refEl) refEl.value = rNum;
        }

        lotPayload.push({
            lot_number: lNum,
            ref_number: rNum,
            qty: qVal,
            ng_fixed: isFix
        });
    });

    if (hasEmptyLot) {
        Swal.fire({
            icon: 'warning',
            title: 'Nomor Lot Kosong',
            text: 'Ada baris dengan Nomor Lot kosong. Harap isi atau hapus baris tersebut.'
        });
        return;
    }

    if (lotPayload.length === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Data Tidak Valid',
            text: 'Tidak ada data lot yang valid.'
        });
        return;
    }

    var btn = document.getElementById('btn-submit-close');
    var remarks = document.getElementById('close_remarks').value;

    btn.disabled = true;
    btn.innerHTML = '<span>Menyimpan data...</span>';

    var formData = new FormData();
    formData.append('kanban_id', kanbanId);
    formData.append('remarks', remarks);

    lotPayload.forEach(function(item, idx) {
        formData.append('lots[' + idx + '][lot_number]', item.lot_number);
        formData.append('lots[' + idx + '][ref_number]', item.ref_number);
        formData.append('lots[' + idx + '][qty]', item.qty);
        formData.append('lots[' + idx + '][ng_fixed]', item.ng_fixed);
    });

    fetch('<?= base_url('modules/inspection/api/submit_close_lots.php') ?>', {
        method: 'POST',
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.success) {
            Swal.fire({
                icon: 'success',
                title: 'Berhasil',
                text: res.message,
                confirmButtonColor: '#059669'
            }).then(function() {
                window.location.href = '<?= base_url('modules/inspection/index.php') ?>';
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Gagal Menyimpan',
                text: res.message || 'Gagal menyimpan.'
            });
            btn.disabled = false;
            btn.innerHTML = '<span>Simpan & Tutup Kanban</span>';
        }
    })
    .catch(function(err) {
        Swal.fire({
            icon: 'error',
            title: 'Kesalahan Jaringan',
            text: 'Terjadi kesalahan jaringan atau server tidak merespons.'
        });
        btn.disabled = false;
        btn.innerHTML = '<span>Simpan & Tutup Kanban</span>';
    });
}

function escapeHtml(text) {
    if (!text) return '';
    return text.toString().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

// Inisialisasi awal: Default kosong (0 baris) & auto-focus scanner
document.addEventListener('DOMContentLoaded', function() {
    updateRowCounters();
    recalculateDistribution();
    focusScanner(true);
});
</script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
