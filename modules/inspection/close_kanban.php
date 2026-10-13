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

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen transition-all duration-300 bg-slate-50">
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-4 md:p-6 max-w-5xl w-full mx-auto space-y-5">
        
        <!-- Header & Navigasi Kembali -->
        <div class="flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <a href="<?= base_url('modules/inspection/index.php') ?>" class="px-3 py-1.5 bg-white border border-slate-300 text-slate-700 hover:bg-slate-100 rounded-lg text-xs font-bold transition-colors">
                    &larr; Kembali
                </a>
                <div>
                    <h1 class="text-lg font-black text-slate-900 leading-tight">Tutup Kanban (Input Lot)</h1>
                    <p class="text-xs text-slate-500">Daftarkan lot fisik melalui scan barcode atau pilih tanggal manual</p>
                </div>
            </div>
            <span class="px-3 py-1 bg-blue-50 border border-blue-200 text-blue-700 text-xs font-bold rounded-lg font-mono">
                Kanban #<?= htmlspecialchars($kanban['kanban_no'] ?: $kanban['id']) ?>
            </span>
        </div>

        <!-- Ringkasan Singkat Kanban -->
        <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-xs grid grid-cols-2 sm:grid-cols-4 gap-4 text-xs">
            <div>
                <span class="text-slate-400 font-bold block uppercase text-[10px]">Part Code</span>
                <span class="text-sm font-black text-blue-700 font-mono mt-0.5 block"><?= htmlspecialchars($kanban['item_code']) ?></span>
            </div>
            <div>
                <span class="text-slate-400 font-bold block uppercase text-[10px]">Nama Part</span>
                <span class="text-xs font-bold text-slate-800 mt-0.5 block truncate" title="<?= htmlspecialchars($kanban['display_part_name']) ?>"><?= htmlspecialchars($kanban['display_part_name']) ?></span>
            </div>
            <div>
                <span class="text-slate-400 font-bold block uppercase text-[10px]">Customer</span>
                <span class="text-xs font-bold text-slate-700 mt-0.5 block"><?= htmlspecialchars($kanban['customer'] ?: '-') ?></span>
            </div>
            <div class="bg-emerald-50 border border-emerald-200 p-2.5 rounded-lg -m-1">
                <span class="text-emerald-800 font-bold block uppercase text-[10px]">Sisa Target Qty</span>
                <span class="text-base font-black text-emerald-700 font-mono mt-0.5 block" id="display-remaining-qty" data-remaining="<?= $remainingQty ?>">
                    <?= number_format($remainingQty) ?> <span class="text-xs font-semibold text-emerald-600">pcs</span>
                </span>
            </div>
        </div>

        <!-- Form Input Lot: Scan atau Manual Tanggal -->
        <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-xs space-y-4">
            <h2 class="text-xs font-black text-slate-800 uppercase tracking-wide border-b border-slate-100 pb-2">
                Tambah Lot Baru
            </h2>

            <!-- Dua Opsi Input -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                
                <!-- Opsi 1: Scan Barcode Langsung -->
                <div class="bg-blue-50/50 border border-blue-200/80 rounded-xl p-3.5 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-blue-900">Cara 1: Scan Barcode / QR Lot</span>
                        <span class="text-[10px] text-blue-600 bg-blue-100 px-2 py-0.5 rounded font-semibold">Cepat</span>
                    </div>
                    <input type="text" id="scan_lot_input" 
                           placeholder="Arahkan scanner ke QR/Barcode lalu tekan Enter..."
                           class="w-full text-xs font-mono font-bold p-2.5 bg-white border border-blue-300 rounded-lg focus:outline-hidden focus:ring-2 focus:ring-blue-500">
                    <p class="text-[11px] text-slate-500">Mendukung format QR lengkap (Z1..Z5) atau nomor lot langsung.</p>
                </div>

                <!-- Opsi 2: Pilih Tanggal Manual -->
                <div class="bg-slate-50 border border-slate-200 rounded-xl p-3.5 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-slate-800">Cara 2: Pilih Tanggal (Otomatis Format)</span>
                        <span class="text-[10px] text-slate-600 bg-slate-200 px-2 py-0.5 rounded font-semibold">Manual</span>
                    </div>
                    <div class="flex items-center space-x-2">
                        <input type="date" id="manual_date_input" value="<?= date('Y-m-d') ?>" 
                               class="text-xs font-bold p-2 bg-white border border-slate-300 rounded-lg flex-1">
                        <button type="button" onclick="generateFromDate()" 
                                class="px-3 py-2 bg-slate-800 hover:bg-slate-900 text-white font-bold text-xs rounded-lg transition-colors whitespace-nowrap">
                            Buat Lot
                        </button>
                    </div>
                    <p class="text-[11px] text-slate-500">Format standar otomatis: <code class="font-mono text-blue-700 bg-white px-1 py-0.5 rounded border border-slate-200" id="preview_format_lot">064...</code></p>
                </div>

            </div>

            <!-- Field Tambahan (Opsional) Sebelum Dimasukkan ke Tabel -->
            <div class="bg-slate-50/70 border border-slate-200 rounded-xl p-3 grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
                <div>
                    <label class="font-bold text-slate-700 block mb-1">Nomor Lot Siap Ditambahkan</label>
                    <input type="text" id="ready_lot_number" placeholder="Nomor Lot..." class="w-full font-mono font-bold p-2 bg-white border border-slate-300 rounded-lg">
                </div>
                <div>
                    <label class="font-bold text-slate-700 block mb-1">Ref Number <span class="text-slate-400 font-normal">(Opsional)</span></label>
                    <input type="text" id="ready_ref_number" placeholder="Boleh dikosongkan..." class="w-full font-mono p-2 bg-white border border-slate-300 rounded-lg">
                </div>
                <div>
                    <label class="font-bold text-slate-700 block mb-1">Qty Pcs <span class="text-slate-400 font-normal">(Opsional - Bagi Rata)</span></label>
                    <div class="flex items-center space-x-2">
                        <input type="number" id="ready_qty" min="1" placeholder="Kosong = Bagi rata" class="w-full font-mono font-bold p-2 bg-white border border-slate-300 rounded-lg">
                        <button type="button" onclick="addLotToTable()" 
                                class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs rounded-lg transition-colors whitespace-nowrap">
                            + Tambah
                        </button>
                    </div>
                </div>
            </div>

        </div>

        <!-- Tabel Daftar Lot yang Akan Digunakan -->
        <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-xs space-y-3">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-xs font-black text-slate-800 uppercase tracking-wide">
                        Daftar Lot Terpilih (<span id="lot-count-badge">0</span> Lot)
                    </h2>
                    <p class="text-[11px] text-slate-500">Qty yang dikosongkan otomatis dibagi rata untuk memenuhi target Kanban</p>
                </div>
                <div class="text-xs font-bold text-slate-700">
                    Total Qty Lot: <span id="sum-qty-badge" class="font-mono text-blue-700 font-black">0</span> / <?= number_format($remainingQty) ?> pcs
                </div>
            </div>

            <!-- Tabel Baris Lot -->
            <div class="overflow-x-auto border border-slate-200 rounded-lg">
                <table class="w-full text-left text-xs" id="table-lots">
                    <thead class="bg-slate-100 text-slate-700 font-bold border-b border-slate-200 text-[11px] uppercase">
                        <tr>
                            <th class="px-3 py-2.5 text-center w-10">No</th>
                            <th class="px-3 py-2.5">Lot Number</th>
                            <th class="px-3 py-2.5">Ref Number</th>
                            <th class="px-3 py-2.5 w-36">Qty (Pcs)</th>
                            <th class="px-3 py-2.5">Status Riwayat</th>
                            <th class="px-3 py-2.5 text-center w-14">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="lot-tbody" class="divide-y divide-slate-100 font-medium">
                        <tr id="empty-lot-row">
                            <td colspan="6" class="px-3 py-8 text-center text-slate-400">
                                Belum ada lot yang ditambahkan. Silakan scan barcode atau pilih tanggal di atas.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Catatan Selesai -->
            <div class="pt-2">
                <label for="close_remarks" class="text-xs font-bold text-slate-700 block mb-1">
                    Catatan Penutupan <span class="text-slate-400 font-normal">(Opsional)</span>
                </label>
                <input type="text" id="close_remarks" placeholder="Contoh: Urgent shipment / instruksi chief QC..." 
                       class="w-full text-xs p-2.5 bg-white border border-slate-300 rounded-lg">
            </div>

            <!-- Tombol Simpan & Tutup Kanban -->
            <div class="pt-3 border-t border-slate-100 flex items-center justify-end space-x-3">
                <a href="<?= base_url('modules/inspection/index.php') ?>" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs rounded-lg transition-colors">
                    Batal
                </a>
                <button type="button" id="btn-submit-close" onclick="submitCloseKanban()" 
                        class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white font-extrabold text-xs rounded-lg shadow-sm transition-all flex items-center space-x-2">
                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                    <span>Selesaikan & Tutup Kanban</span>
                </button>
            </div>
        </div>

    </main>
</div>

<!-- Modal Konfirmasi Lot Pernah NG -->
<div id="modalConfirmNg" style="display:none;" class="fixed inset-0 bg-slate-900/60 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-md w-full p-5 space-y-4 shadow-2xl border border-slate-200">
        <div class="flex items-center space-x-3 text-rose-600">
            <div class="w-10 h-10 rounded-full bg-rose-100 flex items-center justify-center font-bold text-lg">!</div>
            <div>
                <h3 class="text-sm font-black text-slate-900">Perhatian: Riwayat Lot NG</h3>
                <p class="text-xs text-slate-500">Ditemukan catatan reject sebelumnya pada lot ini</p>
            </div>
        </div>

        <div class="bg-rose-50 border border-rose-200 rounded-xl p-3 text-xs space-y-1 text-rose-900">
            <div><b>Lot:</b> <span id="modal-ng-lot" class="font-mono font-bold"></span></div>
            <div id="modal-ng-desc" class="text-[11px] leading-relaxed"></div>
        </div>

        <p class="text-xs text-slate-700 leading-normal">
            Apakah masalah mutu pada lot ini <b>sudah selesai diperbaiki / fix</b> dan siap digunakan untuk menutup Kanban?
        </p>

        <div class="flex items-center justify-end space-x-2 pt-2 border-t border-slate-100">
            <button type="button" onclick="cancelNgLot()" class="px-3.5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-lg transition-colors">
                Batal
            </button>
            <button type="button" onclick="confirmNgLot()" class="px-4 py-2 bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold rounded-lg transition-colors">
                Ya, Sudah Fix (Gunakan)
            </button>
        </div>
    </div>
</div>

<script>
var currentPartCode = <?= json_encode($kanban['item_code']) ?>;
var kanbanId = <?= (int)$kanban['id'] ?>;
var remainingTargetQty = <?= (int)$remainingQty ?>;
var lotList = [];
var pendingNgCandidate = null;

// Generate format lot otomatis berdasarkan tanggal (064 + YY + M + DD + D010000)
function formatLotNumberFromDate(dateStr) {
    if (!dateStr) return '';
    var p = dateStr.split('-');
    if (p.length === 3) {
        var yy = p[0].slice(-2);
        var m  = String(parseInt(p[1], 10)); // tanpa leading zero
        var dd = p[2];
        return '064' + yy + m + dd + 'D010000';
    }
    return '';
}

function updateFormatPreview() {
    var dVal = document.getElementById('manual_date_input').value;
    var res = formatLotNumberFromDate(dVal);
    document.getElementById('preview_format_lot').textContent = res || '-';
}

function generateFromDate() {
    var dVal = document.getElementById('manual_date_input').value;
    var formatted = formatLotNumberFromDate(dVal);
    if (formatted) {
        document.getElementById('ready_lot_number').value = formatted;
        document.getElementById('ready_lot_number').focus();
    }
}

// Parse scan barcode/QR
function parseScan(raw) {
    var res = { lot: '', ref: '', qty: 0 };
    if (!raw) return res;
    raw = raw.trim();
    if (raw.indexOf('|') !== -1 || raw.indexOf('Z2') !== -1 || raw.indexOf('Z5') !== -1) {
        var parts = raw.split('|');
        parts.forEach(function(p) {
            p = p.trim();
            if (p.indexOf('Z2') === 0) res.lot = p.substring(2).trim();
            else if (p.indexOf('Z3') === 0) res.qty = parseInt(p.substring(2)) || 0;
            else if (p.indexOf('Z5') === 0) res.ref = p.substring(2).trim();
        });
    } else {
        res.lot = raw;
    }
    return res;
}

// Event scanner enter
document.getElementById('scan_lot_input').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        var val = this.value.trim();
        if (!val) return;
        var parsed = parseScan(val);
        this.value = '';
        
        document.getElementById('ready_lot_number').value = parsed.lot;
        if (parsed.ref) document.getElementById('ready_ref_number').value = parsed.ref;
        if (parsed.qty > 0) document.getElementById('ready_qty').value = parsed.qty;
        
        addLotToTable();
    }
});

// Tambah Lot ke Tabel dengan Validasi NG
function addLotToTable() {
    var lotNum = document.getElementById('ready_lot_number').value.trim();
    var refNum = document.getElementById('ready_ref_number').value.trim();
    var qtyVal = parseInt(document.getElementById('ready_qty').value) || 0;

    if (!lotNum) {
        alert('Nomor Lot tidak boleh kosong');
        document.getElementById('ready_lot_number').focus();
        return;
    }

    // Cek duplikasi di daftar saat ini
    var exists = lotList.some(function(item) {
        return item.lot_number === lotNum && item.ref_number === refNum;
    });
    if (exists) {
        alert('Lot ini sudah ada di dalam tabel.');
        return;
    }

    // Cek status ke database (apakah pernah NG)
    var checkUrl = '<?= base_url('modules/inspection/api/check_lot_status.php') ?>' + 
                   '?part_code=' + encodeURIComponent(currentPartCode) + 
                   '&lot_number=' + encodeURIComponent(lotNum) + 
                   '&ref_number=' + encodeURIComponent(refNum);

    fetch(checkUrl)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success && data.is_previously_ng) {
                // Tampilkan konfirmasi
                pendingNgCandidate = {
                    lot_number: lotNum,
                    ref_number: refNum,
                    qty: qtyVal,
                    ng_details: data.ng_details
                };
                document.getElementById('modal-ng-lot').textContent = lotNum;
                document.getElementById('modal-ng-desc').textContent = data.ng_details;
                document.getElementById('modalConfirmNg').style.display = 'flex';
            } else {
                // Aman, langsung tambahkan
                pushLotRow(lotNum, refNum, qtyVal, false);
            }
        })
        .catch(function(err) {
            // Jika request gagal, tetap tambahkan sebagai lot normal
            pushLotRow(lotNum, refNum, qtyVal, false);
        });
}

function confirmNgLot() {
    if (pendingNgCandidate) {
        pushLotRow(pendingNgCandidate.lot_number, pendingNgCandidate.ref_number, pendingNgCandidate.qty, true);
    }
    cancelNgLot();
}

function cancelNgLot() {
    pendingNgCandidate = null;
    document.getElementById('modalConfirmNg').style.display = 'none';
}

function pushLotRow(lotNum, refNum, qtyVal, isNgFixed) {
    lotList.push({
        lot_number: lotNum,
        ref_number: refNum,
        qty: qtyVal,
        ng_fixed: isNgFixed ? 1 : 0
    });

    // Reset input siap
    document.getElementById('ready_lot_number').value = '';
    document.getElementById('ready_ref_number').value = '';
    document.getElementById('ready_qty').value = '';

    renderTable();
}

function removeLotRow(index) {
    lotList.splice(index, 1);
    renderTable();
}

function onLotQtyChange(index, newVal) {
    var parsed = parseInt(newVal);
    lotList[index].qty = isNaN(parsed) || parsed < 0 ? 0 : parsed;
    renderTable(false); // update badge tanpa merender ulang baris aktif
}

// Render isi tabel & pembagian kuota otomatis jika ada Qty = 0
function renderTable(fullRebuild) {
    if (fullRebuild === undefined) fullRebuild = true;
    var tbody = document.getElementById('lot-tbody');
    var badgeCount = document.getElementById('lot-count-badge');
    var badgeSum = document.getElementById('sum-qty-badge');

    badgeCount.textContent = lotList.length;

    if (lotList.length === 0) {
        tbody.innerHTML = '<tr id="empty-lot-row"><td colspan="6" class="px-3 py-8 text-center text-slate-400">Belum ada lot yang ditambahkan. Silakan scan barcode atau pilih tanggal di atas.</td></tr>';
        badgeSum.textContent = '0';
        return;
    }

    // Kalkulasi pembagian rata jika ada qty yang 0
    var specifiedTotal = 0;
    var zeroIndices = [];
    lotList.forEach(function(item, idx) {
        if (item.qty > 0) {
            specifiedTotal += item.qty;
        } else {
            zeroIndices.push(idx);
        }
    });

    var distributedQtyPerZero = 0;
    var remainderQty = 0;
    if (zeroIndices.length > 0) {
        var sisa = Math.max(0, remainingTargetQty - specifiedTotal);
        distributedQtyPerZero = Math.floor(sisa / zeroIndices.length);
        remainderQty = sisa % zeroIndices.length;
    }

    var totalAccumulated = 0;

    if (fullRebuild) {
        var html = '';
        lotList.forEach(function(item, idx) {
            var displayQty = item.qty;
            var isAuto = false;
            if (displayQty === 0) {
                var posInZero = zeroIndices.indexOf(idx);
                displayQty = distributedQtyPerZero + (posInZero < remainderQty ? 1 : 0);
                isAuto = true;
            }
            totalAccumulated += displayQty;

            var statusBadge = item.ng_fixed === 1
                ? '<span class="px-2 py-0.5 bg-amber-100 text-amber-800 text-[10px] font-bold rounded">Fixed (Eks NG)</span>'
                : '<span class="px-2 py-0.5 bg-emerald-100 text-emerald-800 text-[10px] font-bold rounded">Normal / OK</span>';

            html += `<tr>
                <td class="px-3 py-2 text-center text-slate-400 font-bold">${idx + 1}</td>
                <td class="px-3 py-2 font-mono font-bold text-slate-800">${escapeHtml(item.lot_number)}</td>
                <td class="px-3 py-2 font-mono text-slate-600">${escapeHtml(item.ref_number || '-')}</td>
                <td class="px-3 py-2">
                    <div class="flex items-center space-x-1">
                        <input type="number" min="0" value="${item.qty > 0 ? item.qty : ''}" 
                               placeholder="${displayQty}"
                               onchange="onLotQtyChange(${idx}, this.value)"
                               class="w-24 text-xs font-mono font-bold p-1 bg-white border border-slate-300 rounded text-right">
                        <span class="text-[10px] text-slate-500 font-bold">pcs</span>
                    </div>
                    ${isAuto ? '<span class="text-[9px] text-blue-600 block mt-0.5 font-semibold">Bagi rata otomatis</span>' : ''}
                </td>
                <td class="px-3 py-2">${statusBadge}</td>
                <td class="px-3 py-2 text-center">
                    <button type="button" onclick="removeLotRow(${idx})" class="p-1 text-rose-500 hover:text-rose-700 hover:bg-rose-50 rounded">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    </button>
                </td>
            </tr>`;
        });
        tbody.innerHTML = html;
    } else {
        lotList.forEach(function(item, idx) {
            var displayQty = item.qty;
            if (displayQty === 0) {
                var posInZero = zeroIndices.indexOf(idx);
                displayQty = distributedQtyPerZero + (posInZero < remainderQty ? 1 : 0);
            }
            totalAccumulated += displayQty;
        });
    }

    badgeSum.textContent = Number(totalAccumulated).toLocaleString();
}

// Submit ke Backend
function submitCloseKanban() {
    if (lotList.length === 0) {
        alert('Harap tambahkan minimal 1 Lot sebelum menyelesaikan Kanban.');
        return;
    }

    var btn = document.getElementById('btn-submit-close');
    var remarks = document.getElementById('close_remarks').value;

    btn.disabled = true;
    btn.innerHTML = '<span>Menyimpan data...</span>';

    var formData = new FormData();
    formData.append('kanban_id', kanbanId);
    formData.append('remarks', remarks);

    lotList.forEach(function(item, idx) {
        formData.append('lots[' + idx + '][lot_number]', item.lot_number);
        formData.append('lots[' + idx + '][ref_number]', item.ref_number || '');
        formData.append('lots[' + idx + '][qty]', item.qty || 0);
        formData.append('lots[' + idx + '][ng_fixed]', item.ng_fixed || 0);
    });

    fetch('<?= base_url('modules/inspection/api/submit_close_lots.php') ?>', {
        method: 'POST',
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.success) {
            alert(res.message);
            window.location.href = '<?= base_url('modules/inspection/index.php') ?>';
        } else {
            alert(res.message || 'Gagal menyimpan.');
            btn.disabled = false;
            btn.innerHTML = '<span>Selesaikan & Tutup Kanban</span>';
        }
    })
    .catch(function(err) {
        alert('Terjadi kesalahan jaringan.');
        btn.disabled = false;
        btn.innerHTML = '<span>Selesaikan & Tutup Kanban</span>';
    });
}

function escapeHtml(text) {
    if (!text) return '';
    return text.toString().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

document.addEventListener('DOMContentLoaded', function() {
    updateFormatPreview();
    document.getElementById('manual_date_input').addEventListener('change', updateFormatPreview);
    document.getElementById('scan_lot_input').focus();
});
</script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
