<?php
/**
 * Database Table Reset & Truncate Utility
 * PT. Surya Technology Industri — OQC System
 * URL: http://localhost/oqc/truncate.php
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/helper.php';

$pdo = getDB();

// Daftar tabel operasional/transaksional yang biasa di-reset saat testing
$targetTables = [
    'inspection_sessions'       => 'Sesi Pemeriksaan OQC',
    'inspection_session_lots'   => 'Rincian Multi-Lot Sesi',
    'inspection_samples'        => 'Data Sampel Uji (OK/NG)',
    'inspection_ng_records'     => 'Temuan Cacat Mutu (Defect)',
    'kanban_items'              => 'Daftar Item Kanban',
    'kanban_batches'            => 'Batch Dokumen Kanban',
    'lot_substitution_log'      => 'Log Substitusi Lot',
    'oqc_daily_summary'         => 'Ringkasan Harian OQC',
    'oqc_daily_defect_summary'  => 'Ringkasan Cacat Harian',
    'rejection_sheet_prints'    => 'Log Cetak Lembar Penolakan',
    'daily_inspection_data'     => 'Data DID Harian',
    'did_batches'               => 'Batch Impor DID',
    'defect_types'              => 'Master Jenis Cacat (Defect Types)'
];

$message = '';
$messageType = '';

// Proses Truncate POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $selectedTables = [];

    if ($action === 'truncate_all') {
        $selectedTables = array_keys($targetTables);
    } elseif ($action === 'truncate_selected' && !empty($_POST['tables']) && is_array($_POST['tables'])) {
        foreach ($_POST['tables'] as $t) {
            if (array_key_exists($t, $targetTables)) {
                $selectedTables[] = $t;
            }
        }
    }

    if ($action === 'seed_defects') {
        try {
            $defaultDefects = [
                'Flash / Burr / Excess Plastic',
                'Dimension Out of Spec',
                'Sink Mark / Shrinkage',
                'Flow Line / Silver Streak',
                'Short Shot / Incomplete Fill',
                'Scratch / Dirty Mark / Contamination'
            ];
            $stmtIns = $pdo->prepare("INSERT INTO defect_types (name, created_at) VALUES (:name, NOW())");
            foreach ($defaultDefects as $d) {
                // Check if already exists
                $chk = $pdo->prepare("SELECT id FROM defect_types WHERE name = :name LIMIT 1");
                $chk->execute([':name' => $d]);
                if (!$chk->fetchColumn()) {
                    $stmtIns->execute([':name' => $d]);
                }
            }
            $message = "Berhasil memulihkan daftar jenis cacat (Defect Types) standar pabrik.";
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = "Gagal memulihkan defect types: " . $e->getMessage();
            $messageType = 'error';
        }
    } elseif (!empty($selectedTables)) {
        try {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
            $clearedCount = 0;
            foreach ($selectedTables as $tbl) {
                $pdo->exec("TRUNCATE TABLE `{$tbl}`;");
                $clearedCount++;
            }
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

            $message = "Berhasil mengosongkan {$clearedCount} tabel data (" . implode(', ', $selectedTables) . "). Auto-increment telah di-reset ke 1.";
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = "Gagal mengosongkan tabel: " . $e->getMessage();
            $messageType = 'error';
        }
    } else {
        $message = "Tidak ada tabel yang dipilih.";
        $messageType = 'warning';
    }
}

// Ambil jumlah baris data terbaru untuk setiap tabel
$tableCounts = [];
foreach ($targetTables as $tbl => $label) {
    try {
        $tableCounts[$tbl] = (int)$pdo->query("SELECT COUNT(*) FROM `{$tbl}`")->fetchColumn();
    } catch (PDOException $e) {
        $tableCounts[$tbl] = 0;
    }
}

// Master Data counts (hanya untuk informasi bahwa data master aman)
$masterCounts = [
    'users'            => (int)$pdo->query("SELECT COUNT(*) FROM `users`")->fetchColumn(),
    'master_parts'     => (int)$pdo->query("SELECT COUNT(*) FROM `master_parts`")->fetchColumn(),
    'master_models'    => (int)$pdo->query("SELECT COUNT(*) FROM `master_models`")->fetchColumn(),
    'master_customers' => (int)$pdo->query("SELECT COUNT(*) FROM `master_customers`")->fetchColumn(),
    'master_drawings'  => (int)$pdo->query("SELECT COUNT(*) FROM `master_drawings`")->fetchColumn(),
    'aql_standards'    => (int)$pdo->query("SELECT COUNT(*) FROM `aql_standards`")->fetchColumn()
];

$totalOperationalRows = array_sum($tableCounts);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Truncate Utility — OQC System</title>
    <link rel="stylesheet" href="<?= base_url('assets/css/tailwind.css') ?>">
    <script src="<?= base_url('assets/js/vendor/sweetalert2.all.min.js') ?>"></script>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: #f8fafc;
            color: #0f172a;
        }
    </style>
</head>
<body class="min-h-screen py-8 px-4 sm:px-6">

    <div class="max-w-4xl mx-auto space-y-6">

        <!-- HEADER -->
        <div class="bg-white border border-slate-300 rounded-xl p-6 shadow-sm flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <div class="text-xs font-bold text-rose-700 tracking-wider uppercase">Database Testing Tool</div>
                <h1 class="text-xl font-black text-slate-900 mt-0.5">Pembersihan / Truncate Data Testing OQC</h1>
                <p class="text-xs text-slate-500 mt-1">
                    Gunakan halaman ini untuk mereset riwayat pengujian Kanban, Sesi Inspeksi, dan Laporan Harian ke kondisi awal bersih.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <a href="<?= base_url('modules/inspection/session.php') ?>" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs rounded-lg transition">
                    &larr; Ke Halaman Inspeksi
                </a>
            </div>
        </div>

        <!-- NOTIFIKASI HASIL -->
        <?php if (!empty($message)): ?>
            <div class="p-4 rounded-xl border text-sm font-semibold flex items-center justify-between <?= $messageType === 'success' ? 'bg-emerald-50 border-emerald-300 text-emerald-900' : 'bg-rose-50 border-rose-300 text-rose-900' ?>">
                <div><?= htmlspecialchars($message) ?></div>
                <a href="<?= base_url('truncate.php') ?>" class="text-xs underline hover:opacity-80">Tutup</a>
            </div>
        <?php endif; ?>

        <!-- KARTU UTAMA DENGAN FORM TRUNCATE -->
        <div class="bg-white border border-slate-300 rounded-xl p-6 shadow-sm space-y-5">

            <!-- TOMBOL CEPAT BERSIHKAN SEMUA -->
            <div class="p-5 bg-rose-50 border border-rose-200 rounded-xl flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h2 class="text-sm font-extrabold text-rose-900 uppercase tracking-wide">
                        Kosongkan Seluruh Data Testing (One-Click Reset)
                    </h2>
                    <p class="text-xs text-rose-700 mt-1">
                        Akan mereset total <strong><?= number_format($totalOperationalRows) ?> baris data</strong> pada seluruh tabel sesi, kanban, dan laporan ke 0.
                    </p>
                </div>
                <form method="POST" id="form-truncate-all" onsubmit="return confirmTruncateAll(event)">
                    <input type="hidden" name="action" value="truncate_all">
                    <button type="submit" class="w-full sm:w-auto px-5 py-2.5 bg-rose-600 hover:bg-rose-700 text-white font-bold text-xs rounded-lg shadow-sm transition flex items-center justify-center gap-2">
                        <span>Hapus &amp; Reset Semua Data</span>
                    </button>
                </form>
            </div>

            <!-- FORM SELEKSI TABEL SPESIFIK -->
            <form method="POST" id="form-truncate-selected">
                <input type="hidden" name="action" value="truncate_selected">

                <div class="flex items-center justify-between border-b border-slate-200 pb-3 mb-4">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-slate-700">
                        Pilih Tabel Spesifik yang Ingin Dikosongkan
                    </h3>
                    <div class="flex items-center gap-3 text-xs">
                        <button type="button" onclick="selectAll(true)" class="text-blue-700 font-bold hover:underline">Pilih Semua</button>
                        <span class="text-slate-300">|</span>
                        <button type="button" onclick="selectAll(false)" class="text-slate-600 font-medium hover:underline">Batalkan Pilihan</button>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-5">
                    <?php foreach ($targetTables as $tbl => $desc): 
                        $rowCount = $tableCounts[$tbl] ?? 0;
                    ?>
                        <label class="flex items-center justify-between p-3 border rounded-lg cursor-pointer transition hover:bg-slate-50 <?= $rowCount > 0 ? 'border-slate-300 bg-white' : 'border-slate-200 bg-slate-50/60 opacity-80' ?>">
                            <div class="flex items-center space-x-3">
                                <input type="checkbox" name="tables[]" value="<?= $tbl ?>" class="tbl-checkbox w-4 h-4 text-rose-600 rounded border-slate-300 focus:ring-rose-500" <?= $rowCount > 0 ? 'checked' : '' ?>>
                                <div>
                                    <div class="text-xs font-bold font-mono text-slate-900"><?= $tbl ?></div>
                                    <div class="text-[11px] text-slate-500"><?= $desc ?></div>
                                </div>
                            </div>
                            <div>
                                <span class="px-2 py-0.5 rounded text-xs font-mono font-bold <?= $rowCount > 0 ? 'bg-amber-100 text-amber-900 border border-amber-300' : 'bg-slate-200 text-slate-600' ?>">
                                    <?= number_format($rowCount) ?> baris
                                </span>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="pt-2 text-right">
                    <button type="button" onclick="confirmTruncateSelected()" class="px-5 py-2.5 bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs rounded-lg shadow-sm transition">
                        Kosongkan Tabel Terpilih Saja
                    </button>
                </div>
            </form>
        </div>

        <!-- STATUS MASTER DATA (AMAN) -->
        <div class="bg-white border border-slate-300 rounded-xl p-6 shadow-sm">
            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-700 border-b border-slate-200 pb-2 mb-3">
                Status Master Data &amp; Konfigurasi (Tetap Aman &amp; Tidak Dihapus)
            </h3>
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-3 text-center">
                <div class="p-3 bg-slate-50 rounded-lg border border-slate-200">
                    <div class="text-[10px] text-slate-500 font-bold uppercase">Master Parts</div>
                    <div class="text-sm font-mono font-extrabold text-slate-900 mt-0.5"><?= number_format($masterCounts['master_parts']) ?></div>
                </div>
                <div class="p-3 bg-slate-50 rounded-lg border border-slate-200">
                    <div class="text-[10px] text-slate-500 font-bold uppercase">Master Models</div>
                    <div class="text-sm font-mono font-extrabold text-slate-900 mt-0.5"><?= number_format($masterCounts['master_models']) ?></div>
                </div>
                <div class="p-3 bg-slate-50 rounded-lg border border-slate-200">
                    <div class="text-[10px] text-slate-500 font-bold uppercase">Customers</div>
                    <div class="text-sm font-mono font-extrabold text-slate-900 mt-0.5"><?= number_format($masterCounts['master_customers']) ?></div>
                </div>
                <div class="p-3 bg-slate-50 rounded-lg border border-slate-200">
                    <div class="text-[10px] text-slate-500 font-bold uppercase">Drawing 2D/3D</div>
                    <div class="text-sm font-mono font-extrabold text-slate-900 mt-0.5"><?= number_format($masterCounts['master_drawings']) ?></div>
                </div>
                <div class="p-3 bg-slate-50 rounded-lg border border-slate-200">
                    <div class="text-[10px] text-slate-500 font-bold uppercase">Standar AQL</div>
                    <div class="text-sm font-mono font-extrabold text-slate-900 mt-0.5"><?= number_format($masterCounts['aql_standards']) ?></div>
                </div>
                <div class="p-3 bg-slate-50 rounded-lg border border-slate-200">
                    <div class="text-[10px] text-slate-500 font-bold uppercase">Users / Akun</div>
                    <div class="text-sm font-mono font-extrabold text-slate-900 mt-0.5"><?= number_format($masterCounts['users']) ?></div>
                </div>
            </div>
            <?php if (($tableCounts['defect_types'] ?? 0) === 0): ?>
                <div class="mt-4 p-3 bg-amber-50 border border-amber-200 rounded-lg flex items-center justify-between text-xs">
                    <span class="text-amber-800 font-medium">Tabel jenis cacat (Defect Types) kosong. Ingin mengisinya kembali dengan daftar standar pabrik?</span>
                    <form method="POST" class="inline">
                        <input type="hidden" name="action" value="seed_defects">
                        <button type="submit" class="px-3 py-1 bg-amber-600 hover:bg-amber-700 text-white font-bold rounded">
                            Pulihkan Defect Types Standar
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <!-- FOOTER -->
        <div class="text-center text-xs text-slate-500">
            &copy; <?= date('Y') ?> PT. Surya Technology Industri &bull; OQC Database Management Utility
        </div>

    </div>

    <script>
        function selectAll(checked) {
            document.querySelectorAll('.tbl-checkbox').forEach(function(cb) {
                cb.checked = checked;
            });
        }

        function confirmTruncateAll(e) {
            e.preventDefault();
            Swal.fire({
                title: 'Kosongkan Semua Data Testing?',
                text: 'Seluruh riwayat sesi inspeksi, multi-lot, data sampel, defect, dan kanban akan dihapus permanen serta ID direset ke 1.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#e11d48',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Ya, Kosongkan Semua',
                cancelButtonText: 'Batal'
            }).then(function(res) {
                if (res.isConfirmed) {
                    document.getElementById('form-truncate-all').submit();
                }
            });
            return false;
        }

        function confirmTruncateSelected() {
            var selected = document.querySelectorAll('.tbl-checkbox:checked');
            if (selected.length === 0) {
                Swal.fire({
                    icon: 'info',
                    title: 'Pilih Tabel',
                    text: 'Silakan centang setidaknya satu tabel yang ingin dikosongkan.'
                });
                return;
            }

            Swal.fire({
                title: 'Kosongkan ' + selected.length + ' Tabel Terpilih?',
                text: 'Data pada tabel yang dicentang akan dihapus dan auto-increment direset ke 1.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#0f172a',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Ya, Kosongkan',
                cancelButtonText: 'Batal'
            }).then(function(res) {
                if (res.isConfirmed) {
                    document.getElementById('form-truncate-selected').submit();
                }
            });
        }
    </script>
</body>
</html>
