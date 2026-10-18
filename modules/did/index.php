<?php
$breadcrumbCategory = "DATA REFERENSI";
$pageTitle = "Riwayat Upload & Entry DID (Daily Inspection Data)";
$pageSubtitle = "Daftar histori sesi pengunggahan & penginputan status pengecekan dimensi lot produksi per tanggal";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';

require_menu_access('did');

$pdo = getDB();
$batches = [];
$filterMode = sanitize($_GET['filter_mode'] ?? '');
$monthPeriod = sanitize($_GET['month_period'] ?? '');
$startDate = sanitize($_GET['start_date'] ?? '');
$endDate = sanitize($_GET['end_date'] ?? '');

// Auto-determine filter_mode if not explicitly specified
if (empty($filterMode)) {
    if (!empty($startDate) || !empty($endDate)) {
        $filterMode = 'custom';
    } elseif (!empty($monthPeriod)) {
        $filterMode = 'month';
    } else {
        $filterMode = 'month'; // Default mode
    }
}

// Dynamic Limit
$limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT) ?: 10;
if (!in_array($limit, [5, 10, 25, 50, 100])) {
    $limit = 10;
}

$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;
$totalItems = 0;
$totalPages = 1;
$availableMonths = [];

// Indo Month translation helper
$indoMonths = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Maret',
    '04' => 'April',   '05' => 'Mei',      '06' => 'Juni',
    '07' => 'Juli',    '08' => 'Agustus',  '09' => 'September',
    '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
];

if ($pdo) {
    try {
        // 1. Fetch available months from database based on existing records
        $stmtMonths = $pdo->query("SELECT 
                DATE_FORMAT(COALESCE(inspecting_date, created_at), '%Y-%m') AS ym,
                COUNT(id) AS total_batches,
                SUM(total_items) AS total_lots
            FROM did_batches 
            WHERE COALESCE(inspecting_date, created_at) IS NOT NULL
            GROUP BY ym 
            ORDER BY ym DESC");

        if ($stmtMonths) {
            while ($mRow = $stmtMonths->fetch(PDO::FETCH_ASSOC)) {
                $ym = $mRow['ym'];
                if (!$ym) continue;
                $parts = explode('-', $ym);
                $year = $parts[0] ?? '';
                $monthNum = $parts[1] ?? '';
                $monthName = $indoMonths[$monthNum] ?? date('F', strtotime($ym . '-01'));

                $availableMonths[] = [
                    'ym'            => $ym,
                    'label'         => $monthName . ' ' . $year,
                    'total_batches' => (int)$mRow['total_batches'],
                    'total_lots'    => (int)$mRow['total_lots']
                ];
            }
        }

        // Count Total Batches with filters
        $countSql = "SELECT COUNT(*) FROM did_batches b WHERE 1=1";
        $countParams = [];

        if ($filterMode === 'month' && !empty($monthPeriod)) {
            $pStart = $monthPeriod . '-01';
            $pEnd   = date('Y-m-t', strtotime($pStart));
            $countSql .= " AND COALESCE(b.inspecting_date, DATE(b.created_at)) >= :p_start AND COALESCE(b.inspecting_date, DATE(b.created_at)) <= :p_end";
            $countParams[':p_start'] = $pStart;
            $countParams[':p_end']   = $pEnd;
        } elseif ($filterMode === 'custom') {
            if (!empty($startDate) && !empty($endDate)) {
                $countSql .= " AND b.inspecting_date >= :start_date AND b.inspecting_date <= :end_date";
                $countParams[':start_date'] = $startDate;
                $countParams[':end_date']   = $endDate;
            } elseif (!empty($startDate)) {
                $countSql .= " AND b.inspecting_date >= :start_date";
                $countParams[':start_date'] = $startDate;
            } elseif (!empty($endDate)) {
                $countSql .= " AND b.inspecting_date <= :end_date";
                $countParams[':end_date']   = $endDate;
            }
        }

        $stmtCount = $pdo->prepare($countSql);
        $stmtCount->execute($countParams);
        $totalItems = (int) $stmtCount->fetchColumn();

        $totalPages = ceil($totalItems / $limit);
        if ($totalPages < 1) $totalPages = 1;
        if ($page > $totalPages) $page = $totalPages;
        $offset = ($page - 1) * $limit;

        // Fetch Batches: O(1) point-lookup utilizing denormalized counters
        $sql = "SELECT b.id, b.batch_name, b.inspecting_date, b.import_method, b.total_items, b.ok_count, b.ng_count, b.created_at
                FROM did_batches b 
                WHERE 1=1";
        $params = [];

        if ($filterMode === 'month' && !empty($monthPeriod)) {
            $pStart = $monthPeriod . '-01';
            $pEnd   = date('Y-m-t', strtotime($pStart));
            $sql .= " AND COALESCE(b.inspecting_date, DATE(b.created_at)) >= :p_start AND COALESCE(b.inspecting_date, DATE(b.created_at)) <= :p_end";
            $params[':p_start'] = $pStart;
            $params[':p_end']   = $pEnd;
        } elseif ($filterMode === 'custom') {
            if (!empty($startDate) && !empty($endDate)) {
                $sql .= " AND b.inspecting_date >= :start_date AND b.inspecting_date <= :end_date";
                $params[':start_date'] = $startDate;
                $params[':end_date']   = $endDate;
            } elseif (!empty($startDate)) {
                $sql .= " AND b.inspecting_date >= :start_date";
                $params[':start_date'] = $startDate;
            } elseif (!empty($endDate)) {
                $sql .= " AND b.inspecting_date <= :end_date";
                $params[':end_date']   = $endDate;
            }
        }

        $sql .= " ORDER BY b.id DESC LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        $stmt->execute();

        $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $batches = [];
    }
}

// Active Filter Info Label
$activeFilterDesc = '';
$isFiltered = false;
if ($filterMode === 'month' && !empty($monthPeriod)) {
    $parts = explode('-', $monthPeriod);
    $monthLbl = ($indoMonths[$parts[1] ?? ''] ?? '') . ' ' . ($parts[0] ?? '');
    $activeFilterDesc = "Bulan <strong>" . htmlspecialchars($monthLbl) . "</strong>";
    $isFiltered = true;
} elseif ($filterMode === 'custom' && (!empty($startDate) || !empty($endDate))) {
    if (!empty($startDate) && !empty($endDate)) {
        $activeFilterDesc = "Rentang Tanggal <strong>" . date('d M Y', strtotime($startDate)) . "</strong> s/d <strong>" . date('d M Y', strtotime($endDate)) . "</strong>";
    } elseif (!empty($startDate)) {
        $activeFilterDesc = "Mulai Tanggal <strong>" . date('d M Y', strtotime($startDate)) . "</strong>";
    } else {
        $activeFilterDesc = "Sampai Tanggal <strong>" . date('d M Y', strtotime($endDate)) . "</strong>";
    }
    $isFiltered = true;
}
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-5 space-y-3">
        
        <?= render_flash() ?>

        <!-- Filter Card: Month Filter + Custom Range Switcher -->
        <div class="card p-3.5 bg-white border border-slate-200/80 rounded-xl shadow-xs space-y-3">
            
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;" class="pb-2.5 border-b border-slate-100">
                <!-- Left: Mode Switcher Tabs -->
                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                    <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">Mode Filter:</span>
                    <div class="inline-flex p-0.5 bg-slate-100 rounded-lg border border-slate-200 text-xs">
                        <button type="button" 
                                id="btn-mode-month"
                                onclick="switchFilterMode('month')"
                                class="px-3 py-1 font-semibold rounded-md transition-all <?= ($filterMode === 'month') ? 'bg-white text-blue-700 shadow-xs' : 'text-slate-600 hover:text-slate-900' ?>">
                            📅 Per Bulan
                        </button>
                        <button type="button" 
                                id="btn-mode-custom"
                                onclick="switchFilterMode('custom')"
                                class="px-3 py-1 font-semibold rounded-md transition-all <?= ($filterMode === 'custom') ? 'bg-white text-blue-700 shadow-xs' : 'text-slate-600 hover:text-slate-900' ?>">
                            📆 Rentang Tanggal
                        </button>
                    </div>

                    <?php if (!empty($availableMonths)): ?>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-blue-50 text-blue-700 border border-blue-100">
                            <?= count($availableMonths) ?> bulan data terdata
                        </span>
                    <?php endif; ?>
                </div>

                <!-- Right: Create/Upload Action Button (Always Pushed to Right) -->
                <div style="margin-left: auto;">
                    <a href="<?= base_url('modules/did/create.php') ?>" class="btn-primary py-1.5 px-3 text-xs inline-flex items-center shadow-xs" style="white-space: nowrap;">
                        <svg class="w-3.5 h-3.5 mr-1.5 inline-block" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                        </svg>
                        Upload / Input DID
                    </a>
                </div>
            </div>

            <!-- Filter Forms Container -->
            <form id="filterForm" action="" method="GET" class="flex flex-wrap items-center justify-between gap-2.5">
                <input type="hidden" name="filter_mode" id="input_filter_mode" value="<?= htmlspecialchars($filterMode) ?>">

                <div class="flex flex-wrap items-center gap-2">
                    <!-- 1. MONTH SELECTOR CONTAINER -->
                    <div id="container-month-filter" class="flex items-center gap-2 <?= ($filterMode !== 'month') ? 'hidden' : '' ?>">
                        <label for="month_period" class="text-xs font-semibold text-slate-700">Pilih Periode:</label>
                        <select name="month_period" id="month_period" class="form-input py-1 text-xs font-medium text-slate-800" style="min-width: 220px;">
                            <option value="">-- Semua Periode Bulan --</option>
                            <?php foreach ($availableMonths as $m): ?>
                                <option value="<?= htmlspecialchars($m['ym']) ?>" <?= ($monthPeriod === $m['ym']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($m['label']) ?> &nbsp;(<?= $m['total_batches'] ?> Batch)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- 2. CUSTOM DATE RANGE CONTAINER -->
                    <div id="container-custom-filter" class="flex items-center gap-2 <?= ($filterMode !== 'custom') ? 'hidden' : '' ?>">
                        <label class="text-xs font-semibold text-slate-700">Rentang:</label>
                        <input type="date" name="start_date" id="start_date" value="<?= htmlspecialchars($startDate) ?>" class="form-input py-1 text-xs" style="width: 130px;">
                        <span class="text-xs text-slate-400">s/d</span>
                        <input type="date" name="end_date" id="end_date" value="<?= htmlspecialchars($endDate) ?>" class="form-input py-1 text-xs" style="width: 130px;">
                    </div>

                    <!-- Limit Selector -->
                    <div class="flex items-center gap-1.5 border-l border-slate-200 pl-2">
                        <label for="limit_select" class="text-xs text-slate-500">Tampil:</label>
                        <select name="limit" id="limit_select" onchange="this.form.submit()" class="form-input py-1 text-xs font-semibold text-slate-700" style="width: 95px;">
                            <option value="5" <?= ($limit == 5) ? 'selected' : '' ?>>5 / hal</option>
                            <option value="10" <?= ($limit == 10) ? 'selected' : '' ?>>10 / hal</option>
                            <option value="25" <?= ($limit == 25) ? 'selected' : '' ?>>25 / hal</option>
                            <option value="50" <?= ($limit == 50) ? 'selected' : '' ?>>50 / hal</option>
                            <option value="100" <?= ($limit == 100) ? 'selected' : '' ?>>100 / hal</option>
                        </select>
                    </div>

                    <!-- Apply & Reset Buttons -->
                    <button type="submit" class="btn-secondary py-1 px-3 text-xs font-semibold inline-flex items-center shadow-xs">
                        <svg class="w-3.5 h-3.5 mr-1 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"></path>
                        </svg>
                        Terapkan
                    </button>

                    <?php if ($isFiltered || $limit != 10): ?>
                        <a href="<?= base_url('modules/did/index.php') ?>" class="text-xs text-rose-600 font-semibold hover:underline inline-flex items-center ml-1">
                            <svg class="w-3.5 h-3.5 mr-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                            Reset Filter
                        </a>
                    <?php endif; ?>
                </div>

            </form>

            <?php if ($isFiltered): ?>
                <div class="pt-2 border-t border-slate-100 flex items-center justify-between text-xs text-slate-600">
                    <div class="flex items-center gap-1.5">
                        <span class="inline-block w-2 h-2 rounded-full bg-blue-500 animate-pulse"></span>
                        <span>Filter aktif: <?= $activeFilterDesc ?></span>
                        <span class="text-slate-400">&bull;</span>
                        <span class="font-semibold text-slate-700"><?= number_format($totalItems) ?> Batch ditemukan</span>
                    </div>
                </div>
            <?php endif; ?>

        </div>

        <!-- History Batches Table Card -->
        <div class="card p-0 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs text-slate-700">
                    <thead class="bg-slate-50 text-slate-600 font-bold border-b border-slate-200/80 uppercase tracking-wider text-[10px]">
                        <tr>
                            <th class="px-4 py-3">No</th>
                            <th class="px-4 py-3">Tanggal Inspeksi</th>
                            <th class="px-4 py-3">Nama Berkas / Referensi Sesi</th>
                            <th class="px-4 py-3">Total Lot Item</th>
                            <th class="px-4 py-3">Ringkasan Status Dimensi</th>
                            <th class="px-4 py-3 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($batches)): ?>
                            <tr>
                                <td colspan="6" class="px-4 py-8 text-center text-slate-400">
                                    Belum ada riwayat penginputan DID. Silakan tambah data baru.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($batches as $index => $b): ?>
                                <tr class="hover:bg-slate-50/80 transition-colors">
                                    <td class="px-4 py-3 font-semibold text-slate-400"><?= $offset + $index + 1 ?></td>
                                    <td class="px-4 py-3 font-bold text-slate-800">
                                        <?= date('d M Y', strtotime(!empty($b['inspecting_date']) ? $b['inspecting_date'] : $b['created_at'])) ?>
                                    </td>
                                    <td class="px-4 py-3 font-medium text-slate-800">
                                        <?= htmlspecialchars($b['batch_name']) ?>
                                    </td>
                                    <td class="px-4 py-3 font-extrabold text-blue-700 font-mono">
                                        <?= number_format($b['total_items']) ?> Lot Item
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center space-x-1.5">
                                            <span class="badge badge-success font-semibold">✓ <?= (int)$b['ok_count'] ?> OK</span>
                                            <?php if ((int)$b['ng_count'] > 0): ?>
                                                 <span class="badge badge-danger font-semibold">⚠ <?= (int)$b['ng_count'] ?> NG</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-right space-x-1">
                                        <!-- Detail Button -->
                                        <a href="<?= base_url('modules/did/detail_batch.php?batch_id=' . $b['id']) ?>" 
                                            class="btn-secondary py-1 px-2.5 text-xs inline-flex items-center" title="Lihat Rincian Item Lot">
                                             <svg class="w-3.5 h-3.5 mr-1 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                             </svg>
                                             Detail Item
                                         </a>

                                        <!-- Delete Batch Button (POST + CSRF Protected) -->
                                        <button type="button" 
                                                onclick="confirmPostDelete('<?= base_url('modules/did/delete_batch.php') ?>', <?= (int)$b['id'] ?>, 'Riwayat Batch <?= htmlspecialchars($b['batch_name'], ENT_QUOTES) ?>', '<?= generate_csrf_token() ?>')"
                                                class="btn-icon text-rose-600 hover:bg-rose-50" title="Hapus Seluruh Sesi Batch Ini">
                                             <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                             </svg>
                                         </button>
                                     </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Single Line Pagination Footer -->
            <?= render_pagination($page, $totalPages, $totalItems, $limit) ?>

        </div>

    </main>

<script>
function switchFilterMode(mode) {
    var inputMode = document.getElementById('input_filter_mode');
    var monthContainer = document.getElementById('container-month-filter');
    var customContainer = document.getElementById('container-custom-filter');
    var btnMonth = document.getElementById('btn-mode-month');
    var btnCustom = document.getElementById('btn-mode-custom');

    inputMode.value = mode;

    if (mode === 'month') {
        monthContainer.classList.remove('hidden');
        customContainer.classList.add('hidden');

        btnMonth.className = 'px-3 py-1 font-semibold rounded-md transition-all bg-white text-blue-700 shadow-xs';
        btnCustom.className = 'px-3 py-1 font-semibold rounded-md transition-all text-slate-600 hover:text-slate-900';
    } else {
        monthContainer.classList.add('hidden');
        customContainer.classList.remove('hidden');

        btnCustom.className = 'px-3 py-1 font-semibold rounded-md transition-all bg-white text-blue-700 shadow-xs';
        btnMonth.className = 'px-3 py-1 font-semibold rounded-md transition-all text-slate-600 hover:text-slate-900';
    }
}

// Auto submit saat memilih bulan agar user lebih cepat tanpa harus klik tombol tambahan
$(document).ready(function() {
    $('#month_period').on('change', function() {
        $('#filterForm').submit();
    });
});
</script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>

