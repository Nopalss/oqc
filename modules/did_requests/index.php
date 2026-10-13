<?php
$breadcrumbCategory = "OPERASIONAL";
$pageTitle = "Request Approval DID";
$pageSubtitle = "Persetujuan permintaan pembukaan lot inspeksi yang belum terdaftar pada Daily Inspection Data";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';

$currentUser = current_user();
$pdo = getDB();

$statusFilter = sanitize($_GET['status'] ?? 'pending');
if (!in_array($statusFilter, ['pending', 'approved', 'rejected', 'all'], true)) {
    $statusFilter = 'pending';
}

$searchQuery = trim(sanitize($_GET['q'] ?? ''));

$requests = [];
$counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'all' => 0];
$loadError = null;

if ($pdo) {
    try {
        // Count per status
        $stmtC = $pdo->query("SELECT status, COUNT(*) as cnt FROM did_approval_requests GROUP BY status");
        while ($r = $stmtC->fetch(PDO::FETCH_ASSOC)) {
            $counts[$r['status']] = (int)$r['cnt'];
            $counts['all'] += (int)$r['cnt'];
        }

        // Main Query: Query did_approval_requests directly with users (100% immune to master_parts collation mismatch)
        $sql = "
            SELECT r.*, 
                   u_req.name AS requester_name,
                   u_rev.name AS reviewer_name,
                   COALESCE(NULLIF(r.part_name, ''), '-') AS display_part_name,
                   r.part_code AS display_part_code
            FROM did_approval_requests r
            LEFT JOIN users u_req ON u_req.id = r.requested_by
            LEFT JOIN users u_rev ON u_rev.id = r.reviewed_by
            WHERE 1=1
        ";
        $params = [];

        if ($statusFilter !== 'all') {
            $sql .= " AND r.status = :st";
            $params[':st'] = $statusFilter;
        }

        if (!empty($searchQuery)) {
            $sql .= " AND (r.part_code LIKE :sq OR r.lot_number LIKE :sq OR r.part_name LIKE :sq)";
            $params[':sq'] = '%' . $searchQuery . '%';
        }

        $sql .= " ORDER BY CASE WHEN r.status = 'pending' THEN 1 ELSE 2 END, r.requested_at DESC LIMIT 100";

        $stmtMain = $pdo->prepare($sql);
        $stmtMain->execute($params);
        $requests = $stmtMain->fetchAll(PDO::FETCH_ASSOC);

        // Enrich display_part_name from master_parts safely via memory lookup (zero collation mismatch)
        $partCodes = array_filter(array_unique(array_column($requests, 'part_code')));
        if (!empty($partCodes)) {
            $inPlaceholders = implode(',', array_fill(0, count($partCodes), '?'));
            $stmtMp = $pdo->prepare("SELECT part_code, part_name FROM master_parts WHERE part_code IN ($inPlaceholders)");
            $stmtMp->execute(array_values($partCodes));
            $mpMap = $stmtMp->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($requests as &$reqItem) {
                if (!empty($mpMap[$reqItem['part_code']])) {
                    $reqItem['display_part_name'] = $mpMap[$reqItem['part_code']];
                }
            }
            unset($reqItem);
        }

    } catch (Exception $e) {
        $loadError = 'Gagal memuat data request: ' . $e->getMessage();
    }
}
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-4 md:p-6 space-y-6">
        
        <?= render_flash() ?>
        <?php if (!empty($loadError)): ?>
            <div class="p-4 rounded-xl border border-rose-200 bg-rose-50 text-rose-800 text-xs font-semibold flex items-center gap-2">
                <svg class="w-4 h-4 flex-shrink-0 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <span><?= htmlspecialchars($loadError) ?></span>
            </div>
        <?php endif; ?>

    <!-- Header Title Bar -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs">
        <div>
            <h2 class="text-lg font-extrabold text-slate-900 tracking-tight flex items-center gap-2">
                <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                Request Approval DID
            </h2>
            <p class="text-xs text-slate-500 mt-0.5">
                Daftar pengajuan persetujuan lot yang belum terdaftar di Daily Inspection Data
            </p>
        </div>
        
        <!-- Live Status Counter Pills -->
        <div class="flex items-center gap-2 text-xs font-bold">
            <span class="px-3 py-1 bg-amber-50 text-amber-800 border border-amber-200 rounded-full">
                Pending: <?= number_format($counts['pending']) ?>
            </span>
            <span class="px-3 py-1 bg-emerald-50 text-emerald-800 border border-emerald-200 rounded-full">
                Disetujui: <?= number_format($counts['approved']) ?>
            </span>
        </div>
    </div>

    <!-- Filter Tabs & Search Bar -->
    <div class="bg-white p-4 rounded-2xl border border-slate-200/80 shadow-xs flex flex-col md:flex-row md:items-center justify-between gap-4">
        
        <!-- Status Tabs -->
        <div class="flex items-center space-x-1 bg-slate-100 p-1 rounded-xl text-xs font-bold">
            <a href="?status=pending<?= !empty($searchQuery) ? '&q=' . urlencode($searchQuery) : '' ?>" class="px-3.5 py-1.5 rounded-lg transition-all <?= $statusFilter === 'pending' ? 'bg-white text-blue-700 shadow-2xs font-extrabold' : 'text-slate-600 hover:text-slate-900' ?>">
                Pending (<?= number_format($counts['pending']) ?>)
            </a>
            <a href="?status=approved<?= !empty($searchQuery) ? '&q=' . urlencode($searchQuery) : '' ?>" class="px-3.5 py-1.5 rounded-lg transition-all <?= $statusFilter === 'approved' ? 'bg-white text-emerald-700 shadow-2xs font-extrabold' : 'text-slate-600 hover:text-slate-900' ?>">
                Disetujui (<?= number_format($counts['approved']) ?>)
            </a>
            <a href="?status=rejected<?= !empty($searchQuery) ? '&q=' . urlencode($searchQuery) : '' ?>" class="px-3.5 py-1.5 rounded-lg transition-all <?= $statusFilter === 'rejected' ? 'bg-white text-rose-700 shadow-2xs font-extrabold' : 'text-slate-600 hover:text-slate-900' ?>">
                Ditolak (<?= number_format($counts['rejected']) ?>)
            </a>
            <a href="?status=all<?= !empty($searchQuery) ? '&q=' . urlencode($searchQuery) : '' ?>" class="px-3.5 py-1.5 rounded-lg transition-all <?= $statusFilter === 'all' ? 'bg-white text-slate-900 shadow-2xs font-extrabold' : 'text-slate-600 hover:text-slate-900' ?>">
                Semua (<?= number_format($counts['all']) ?>)
            </a>
        </div>

        <!-- Search Form -->
        <form method="GET" action="" style="display: flex; align-items: center; gap: 8px;">
            <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
            <div style="position: relative; width: 220px; flex-shrink: 0;">
                <svg style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); width: 14px; height: 14px; color: #94a3b8; pointer-events: none;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                </svg>
                <input type="text" name="q" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="Cari Part Code / Lot No..." style="width: 100%; padding-left: 32px; padding-right: 12px; padding-top: 6px; padding-bottom: 6px; font-size: 12px; font-weight: 500; border: 1px solid #cbd5e1; border-radius: 10px; outline: none; box-sizing: border-box;">
            </div>
            <button type="submit" class="px-3.5 py-1.5 bg-slate-900 hover:bg-slate-800 text-white rounded-xl text-xs font-bold transition-all flex-shrink-0 cursor-pointer">
                Cari
            </button>
            <?php if (!empty($searchQuery)): ?>
                <a href="?status=<?= htmlspecialchars($statusFilter) ?>" class="px-2.5 py-1.5 bg-slate-200 hover:bg-slate-300 text-slate-700 rounded-xl text-xs font-bold transition-all flex-shrink-0">
                    Reset
                </a>
            <?php endif; ?>
        </form>

    </div>

    <!-- Main Data Table -->
    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-xs text-left text-slate-700">
                <thead class="bg-slate-50 text-slate-600 font-extrabold uppercase text-[10px] tracking-wider border-b border-slate-200">
                    <tr>
                        <th class="px-4 py-3 text-center w-12">No</th>
                        <th class="px-4 py-3">Waktu Request</th>
                        <th class="px-4 py-3">Pemohon</th>
                        <th class="px-4 py-3">Part Code & Nama</th>
                        <th class="px-4 py-3">Lot Number</th>
                        <th class="px-4 py-3">Alasan</th>
                        <th class="px-4 py-3 text-center">Status</th>
                        <th class="px-4 py-3">Penyetujui & Waktu</th>
                        <th class="px-4 py-3 text-center w-36">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($requests)): ?>
                        <tr>
                            <td colspan="9" class="px-4 py-12 text-center text-slate-400 font-medium">
                                Tidak ada data request persetujuan DID yang ditemukan.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($requests as $idx => $r): ?>
                            <?php
                            $reqTimeFormatted = date('d M Y H:i:s', strtotime($r['requested_at']));
                            $revTimeFormatted = !empty($r['reviewed_at']) ? date('d M Y H:i:s', strtotime($r['reviewed_at'])) : '-';
                            ?>
                            <tr class="hover:bg-slate-50/80 transition-colors">
                                <td class="px-4 py-3 text-center font-mono font-bold text-slate-400"><?= $idx + 1 ?></td>
                                <td class="px-4 py-3 whitespace-nowrap font-mono text-[11px] font-semibold text-slate-600">
                                    <?= htmlspecialchars($reqTimeFormatted) ?>
                                </td>
                                <td class="px-4 py-3 font-bold text-slate-800">
                                    <?= htmlspecialchars($r['requester_name'] ?: 'Inspector') ?>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="font-mono font-extrabold text-blue-700"><?= htmlspecialchars($r['display_part_code'] ?? $r['part_code']) ?></div>
                                    <div class="text-[10px] text-slate-500 truncate max-w-xs font-medium" title="<?= htmlspecialchars($r['display_part_name'] ?? $r['part_name']) ?>"><?= htmlspecialchars($r['display_part_name'] ?? $r['part_name'] ?: '-') ?></div>
                                </td>
                                <td class="px-4 py-3 font-mono font-bold text-slate-900 bg-slate-50/50 rounded-lg">
                                    <?= htmlspecialchars($r['lot_number']) ?>
                                </td>
                                <td class="px-4 py-3 text-slate-600 max-w-xs truncate" title="<?= htmlspecialchars($r['request_reason'] ?: '-') ?>">
                                    <?= htmlspecialchars($r['request_reason'] ?: '-') ?>
                                </td>
                                <td class="px-4 py-3 text-center whitespace-nowrap">
                                    <?php if ($r['status'] === 'pending'): ?>
                                        <span class="px-2.5 py-1 text-[10px] font-extrabold rounded-md bg-amber-100 text-amber-800 border border-amber-300">
                                            PENDING
                                        </span>
                                    <?php elseif ($r['status'] === 'approved'): ?>
                                        <span class="px-2.5 py-1 text-[10px] font-extrabold rounded-md bg-emerald-100 text-emerald-800 border border-emerald-300">
                                            DISETUJUI
                                        </span>
                                    <?php else: ?>
                                        <span class="px-2.5 py-1 text-[10px] font-extrabold rounded-md bg-rose-100 text-rose-800 border border-rose-300">
                                            DITOLAK
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3">
                                    <?php if (!empty($r['reviewer_name'])): ?>
                                        <div class="font-bold text-slate-800"><?= htmlspecialchars($r['reviewer_name']) ?></div>
                                        <div class="font-mono text-[10px] text-slate-400"><?= htmlspecialchars($revTimeFormatted) ?></div>
                                        <?php if (!empty($r['rejection_reason'])): ?>
                                            <div class="text-[10px] text-slate-500 italic mt-0.5" title="<?= htmlspecialchars($r['rejection_reason']) ?>">
                                                Catatan: <?= htmlspecialchars($r['rejection_reason']) ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-slate-400 font-mono text-[11px]">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-center whitespace-nowrap">
                                    <?php if ($r['status'] === 'pending'): ?>
                                        <div class="flex items-center justify-center space-x-1.5">
                                            <button type="button" onclick="approveDidRequest(<?= $r['id'] ?>, '<?= htmlspecialchars($r['display_part_code'] ?? $r['part_code'], ENT_QUOTES) ?>', '<?= htmlspecialchars($r['display_part_name'] ?? $r['part_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($r['lot_number'], ENT_QUOTES) ?>')" class="px-2.5 py-1 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg font-extrabold text-[10px] shadow-2xs transition-all cursor-pointer">
                                                Setujui
                                            </button>
                                            <button type="button" onclick="rejectDidRequest(<?= $r['id'] ?>, '<?= htmlspecialchars($r['display_part_code'] ?? $r['part_code'], ENT_QUOTES) ?>', '<?= htmlspecialchars($r['display_part_name'] ?? $r['part_name'], ENT_QUOTES) ?>', '<?= htmlspecialchars($r['lot_number'], ENT_QUOTES) ?>')" class="px-2.5 py-1 bg-rose-600 hover:bg-rose-700 text-white rounded-lg font-extrabold text-[10px] shadow-2xs transition-all cursor-pointer">
                                                Tolak
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-slate-400 font-semibold text-[11px]">Selesai</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function approveDidRequest(id, partCode, partName, lotNumber) {
    var partDisplay = partName && partName !== '-' ? escapeHtml(partCode) + ' (' + escapeHtml(partName) + ')' : escapeHtml(partCode);
    Swal.fire({
        title: 'Konfirmasi Persetujuan DID',
        html: '<div style="font-size: 12px; text-align: left; line-height: 1.6; color: #334155;">' +
              'Apakah Anda yakin ingin <b>menyetujui</b> pengajuan persetujuan DID untuk:<br>' +
              'Part: <b class="text-blue-600">' + partDisplay + '</b> | Lot Number: <b class="font-mono text-blue-600">' + escapeHtml(lotNumber) + '</b>?<br><br>' +
              '<p style="font-size: 11px; color: #047857; background-color: #ecfdf5; padding: 8px 10px; border-radius: 6px; border: 1px solid #a7f3d0;">' +
              'Data akan otomatis di-insert ke tabel Daily Inspection Data (DID) dengan status OK.' +
              '</p>' +
              '</div>',
        showCancelButton: true,
        confirmButtonColor: '#059669',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Ya, Setujui',
        cancelButtonText: 'Batal'
    }).then(function(result) {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Memproses...',
                text: 'Menyetujui request dan mengupdate Daily Inspection Data...',
                allowOutsideClick: false,
                didOpen: function() { Swal.showLoading(); }
            });

            var formData = new FormData();
            formData.append('id', id);

            fetch('approve.php', {
                method: 'POST',
                body: formData
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Disetujui!',
                        text: data.message || 'Request DID berhasil disetujui.',
                        confirmButtonColor: '#059669'
                    }).then(function() {
                        window.location.reload();
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Gagal',
                        text: data.message || 'Terjadi kesalahan saat menyetujui request.'
                    });
                }
            })
            .catch(function(err) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error Server',
                    text: 'Gagal terhubung ke server.'
                });
            });
        }
    });
}

function rejectDidRequest(id, partCode, partName, lotNumber) {
    var partDisplay = partName && partName !== '-' ? escapeHtml(partCode) + ' (' + escapeHtml(partName) + ')' : escapeHtml(partCode);
    Swal.fire({
        title: 'Penolakan Request DID',
        html: '<div style="font-size: 12px; text-align: left; line-height: 1.6; color: #334155; margin-bottom: 10px;">' +
              'Menolak pengajuan DID untuk:<br>' +
              'Part: <b>' + partDisplay + '</b> | Lot: <b class="font-mono">' + escapeHtml(lotNumber) + '</b>' +
              '</div>' +
              '<div style="text-align: left;">' +
              '<label style="font-size: 11px; font-weight: 700; color: #475569; display: block; margin-bottom: 4px;">Alasan Penolakan <span style="color: #ef4444;">*</span>:</label>' +
              '<input type="text" id="swal-reject-reason" placeholder="Contoh: Barang belum teruji fisik di lapangan" style="width: 100%; padding: 8px 12px; font-size: 12px; border: 1.5px solid #cbd5e1; border-radius: 6px; outline: none; box-sizing: border-box;" autocomplete="off">' +
              '</div>',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Tolak Request',
        cancelButtonText: 'Batal',
        preConfirm: function() {
            var input = document.getElementById('swal-reject-reason');
            var val = input ? input.value.trim() : '';
            if (!val) {
                Swal.showValidationMessage('Alasan penolakan wajib diisi!');
                return false;
            }
            return val;
        }
    }).then(function(result) {
        if (result.isConfirmed) {
            var reason = result.value;
            Swal.fire({
                title: 'Memproses...',
                text: 'Menolak request DID...',
                allowOutsideClick: false,
                didOpen: function() { Swal.showLoading(); }
            });

            var formData = new FormData();
            formData.append('id', id);
            formData.append('reason', reason);

            fetch('reject.php', {
                method: 'POST',
                body: formData
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Ditolak',
                        text: data.message || 'Request DID telah ditolak.',
                        confirmButtonColor: '#dc2626'
                    }).then(function() {
                        window.location.reload();
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Gagal',
                        text: data.message || 'Terjadi kesalahan saat menolak request.'
                    });
                }
            })
            .catch(function(err) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error Server',
                    text: 'Gagal terhubung ke server.'
                });
            });
        }
    });
}
</script>

    </main>
</div>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
