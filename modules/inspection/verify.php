<?php
/**
 * Halaman Verifikasi Dokumen Mutu Digital (Form STQC-F-167 REV.00)
 * PT. Surya Technology Industri — Outgoing Quality Control System
 * 100% Offline, Aman, Responsif, dan Mematuhi Standar Audit Manufaktur
 */

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';
session_write_close();

$sessionId = (int)($_GET['session_id'] ?? $_GET['id'] ?? 0);
$sessionLotId = (int)($_GET['session_lot_id'] ?? 0);
$roleParam = sanitize($_GET['role'] ?? '');

$pdo = getDB();
$session = null;
$lots = [];
$ngRecords = [];
$latestReinspection = null;
$parentSession = null;

if ($sessionId > 0 && $pdo) {
    try {
        // 1. Data Sesi Inspeksi, Part, dan Kanban
        $stmt = $pdo->prepare("
            SELECT s.*, 
                   did.part_code, did.part_name, did.lot_number as did_lot_number, did.cavity, did.pic as did_pic,
                   k.kanban_no, k.customer, k.qty as kanban_qty, k.status as kanban_status,
                   k.req_date, k.str_loc, k.supply_area,
                   b.document_number as doc_no, b.vendor,
                   COALESCE(m.name, p.model) as model, p.aql_level,
                   u_insp.name as inspector_name,
                   u_chief.name as chief_display_name,
                   u_spv.name as supervisor_display_name
            FROM inspection_sessions s
            JOIN daily_inspection_data did ON did.id = s.did_id
            LEFT JOIN kanban_items k ON k.id = s.kanban_item_id
            LEFT JOIN kanban_batches b ON b.id = k.batch_id
            LEFT JOIN master_parts p ON p.id = COALESCE(
                s.part_id,
                (SELECT mp.id FROM master_parts mp WHERE UPPER(mp.part_code) = UPPER(did.part_code) LIMIT 1)
            )
            LEFT JOIN master_models m ON m.id = p.model_id
            LEFT JOIN users u_insp ON u_insp.id = s.inspector_id
            LEFT JOIN users u_chief ON u_chief.id = s.chief_approved_by
            LEFT JOIN users u_spv ON u_spv.id = s.approved_by
            WHERE s.id = :id
        ");
        $stmt->execute([':id' => $sessionId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($session) {
            // 2. Data Seluruh Multi-Lot yang diperiksa dalam sesi ini
            $stmtLots = $pdo->prepare("
                SELECT * FROM inspection_session_lots 
                WHERE inspection_session_id = :sid 
                ORDER BY id ASC
            ");
            $stmtLots->execute([':sid' => $sessionId]);
            $lots = $stmtLots->fetchAll(PDO::FETCH_ASSOC);

            // 3. Data Rincian Temuan Defect / NG dengan titik nomor sampel
            $stmtNg = $pdo->prepare("
                SELECT n.*, 
                       d.name as defect_name, 
                       sp.sample_number, 
                       sp.checked_at as sample_checked_at,
                       l.lot_number as lot_name,
                       l.ref_number as ref_name
                FROM inspection_ng_records n
                LEFT JOIN inspection_samples sp ON sp.id = n.inspection_sample_id
                LEFT JOIN defect_types d ON d.id = n.defect_type_id
                LEFT JOIN inspection_session_lots l ON l.id = n.session_lot_id
                WHERE n.inspection_session_id = :sid
                ORDER BY n.id ASC
            ");
            $stmtNg->execute([':sid' => $sessionId]);
            $ngRecords = $stmtNg->fetchAll(PDO::FETCH_ASSOC);

            // 4. Data Pelacakan Inspeksi Ulang (Reinspection Tracker)
            if (!empty($session['kanban_item_id'])) {
                // Sesi lanjutan yang lebih baru untuk kanban ini
                $stmtRe = $pdo->prepare("
                    SELECT s.*, u.name as inspector_name
                    FROM inspection_sessions s
                    LEFT JOIN users u ON u.id = s.inspector_id
                    WHERE s.kanban_item_id = :kid AND s.id > :sid
                    ORDER BY s.id DESC
                    LIMIT 1
                ");
                $stmtRe->execute([':kid' => $session['kanban_item_id'], ':sid' => $sessionId]);
                $latestReinspection = $stmtRe->fetch(PDO::FETCH_ASSOC);
            }

            // Jika sesi ini sendiri merupakan hasil inspeksi ulang dari sesi induk
            if (!empty($session['parent_session_id'])) {
                $stmtParent = $pdo->prepare("
                    SELECT id, status, started_at, closed_at 
                    FROM inspection_sessions 
                    WHERE id = :pid
                ");
                $stmtParent->execute([':pid' => $session['parent_session_id']]);
                $parentSession = $stmtParent->fetch(PDO::FETCH_ASSOC);
            }
        }
    } catch (PDOException $e) {
        $session = null;
    }
}

// Fetch target lot details if session_lot_id is provided
$targetLot = null;
if ($sessionLotId > 0 && $pdo) {
    try {
        $stmtTargetLot = $pdo->prepare("
            SELECT isl.*, u_chief.name as chief_name
            FROM inspection_session_lots isl
            LEFT JOIN users u_chief ON u_chief.id = isl.chief_approved_by
            WHERE isl.id = :lid LIMIT 1
        ");
        $stmtTargetLot->execute([':lid' => $sessionLotId]);
        $targetLot = $stmtTargetLot->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}
}

// Data fallbacks
$inspectorDisplayName = !empty($session['inspector_name']) ? $session['inspector_name'] : (!empty($session['did_pic']) ? $session['did_pic'] : 'Inspektor OQC');
if ($targetLot) {
    $isChiefApproved = (!empty($targetLot['is_chief_approved']) && (int)$targetLot['is_chief_approved'] === 1);
    $chiefDisplayName = !empty($targetLot['chief_name']) ? $targetLot['chief_name'] : (!empty($session['chief_display_name']) ? $session['chief_display_name'] : '-');
    $chiefApprovedAt = !empty($targetLot['chief_approved_at']) ? date('d/m/Y H:i', strtotime($targetLot['chief_approved_at'])) . ' WIB' : '-';
} else {
    $isChiefApproved = (!empty($session['is_chief_approved']) && (int)$session['is_chief_approved'] === 1);
    $chiefDisplayName = !empty($session['chief_display_name']) ? $session['chief_display_name'] : '-';
    $chiefApprovedAt = !empty($session['chief_approved_at']) ? date('d/m/Y H:i', strtotime($session['chief_approved_at'])) . ' WIB' : '-';
}
$supervisorDisplayName = !empty($session['supervisor_display_name']) ? $session['supervisor_display_name'] : '-';
$isSupervisorApproved = (!empty($session['is_approved']) && (int)$session['is_approved'] === 1);

// Temuan sampel pertama
$firstNgSample = !empty($ngRecords[0]['sample_number']) ? (int)$ngRecords[0]['sample_number'] : null;
$firstNgTime = !empty($ngRecords[0]['sample_checked_at']) ? date('d/m/Y H:i:s', strtotime($ngRecords[0]['sample_checked_at'])) . ' WIB' : (!empty($session['closed_at']) ? date('d/m/Y H:i:s', strtotime($session['closed_at'])) . ' WIB' : '-');

// Set judul halaman
$pageTitle = $session ? "Verifikasi Otorisasi Mutu — Form STQC-F-167 (Part: " . htmlspecialchars($session['part_code']) . ($targetLot ? " | Lot: " . htmlspecialchars($targetLot['lot_number']) : "") . ")" : "Verifikasi Dokumen Tidak Ditemukan";
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <link rel="stylesheet" href="<?= base_url('assets/css/tailwind.css') ?>">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: #f8fafc;
            color: #0f172a;
        }
        .data-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
            font-weight: 600;
        }
        .data-value {
            font-size: 0.875rem;
            color: #0f172a;
            font-weight: 600;
        }
    </style>
</head>
<body class="min-h-screen py-6 px-3 sm:px-6">

    <div class="max-w-4xl mx-auto space-y-5">

        <?php if (!$session): ?>
            <!-- DOKUMEN TIDAK DITEMUKAN -->
            <div class="bg-white border border-slate-300 rounded-lg p-8 text-center shadow-sm">
                <div class="inline-flex items-center justify-center w-12 h-12 bg-red-100 text-red-700 rounded-full font-bold text-lg mb-4">
                    !
                </div>
                <h1 class="text-xl font-bold text-slate-900 mb-2">Dokumen Mutu Tidak Ditemukan</h1>
                <p class="text-sm text-slate-600 max-w-md mx-auto mb-6">
                    Sistem tidak dapat menemukan catatan inspeksi dengan nomor referensi yang diminta. Mohon periksa kembali QR code atau nomor sesi yang Anda tuju.
                </p>
                <a href="<?= base_url('modules/inspection/session.php') ?>" class="inline-block px-5 py-2.5 bg-slate-800 text-white rounded text-sm font-semibold hover:bg-slate-900 transition">
                    Kembali ke Daftar Pemeriksaan
                </a>
            </div>
        <?php else: ?>

            <!-- KOP RESMI PERUSAHAAN & IDENTITAS SISTEM -->
            <div class="bg-white border border-slate-300 rounded-lg p-5 sm:p-6 shadow-sm">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between border-b border-slate-200 pb-4 gap-3">
                    <div>
                        <div class="text-xs font-bold text-indigo-900 tracking-wider uppercase">PT. Surya Technology Industri</div>
                        <h1 class="text-lg sm:text-xl font-bold text-slate-900 mt-0.5">Sistem Verifikasi Dokumen Mutu Digital</h1>
                        <div class="text-xs text-slate-500 mt-1">
                            Rujukan Dokumen: <span class="font-semibold text-slate-800">STQC-F-167 REV.00 (Reject Information Sheet)</span>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <a href="<?= base_url('modules/inspection/print_rejection.php?id=' . $session['id']) ?>" target="_blank" class="inline-flex items-center px-3.5 py-2 bg-slate-900 text-white text-xs font-semibold rounded hover:bg-slate-800 transition">
                            Buka Lembar Penolakan (PDF / Cetak)
                        </a>
                    </div>
                </div>

                <!-- BANNER STATUS UTAMA -->
                <div class="mt-4 p-4 rounded-md border <?= $session['status'] === 'rejected' ? 'bg-red-50 border-red-200' : 'bg-green-50 border-green-200' ?>">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                        <div>
                            <div class="text-xs font-bold uppercase tracking-wider <?= $session['status'] === 'rejected' ? 'text-red-700' : 'text-green-700' ?>">
                                Status Hasil Pemeriksaan OQC:
                            </div>
                            <div class="text-base sm:text-lg font-extrabold mt-0.5 <?= $session['status'] === 'rejected' ? 'text-red-900' : 'text-green-900' ?>">
                                <?= $session['status'] === 'rejected' ? 'PENOLAKAN LOT PRODUKSI (REJECTED)' : 'LOLOS PEMERIKSAAN (PASSED)' ?>
                            </div>
                            <div class="text-xs text-slate-600 mt-1">
                                Nomor Sesi Pemeriksaan: <span class="font-mono font-semibold text-slate-900">#<?= (int)$session['id'] ?></span>
                                &bull; Selesai Diperiksa: <span class="font-semibold text-slate-800"><?= date('d/m/Y H:i', strtotime($session['closed_at'] ?? $session['started_at'])) ?> WIB</span>
                            </div>
                        </div>
                        <div class="sm:text-right">
                            <span class="inline-block px-3 py-1 bg-white border <?= $isChiefApproved ? 'border-blue-400 text-blue-800' : 'border-slate-300 text-slate-700' ?> rounded text-xs font-bold">
                                <?= $isChiefApproved ? 'ACC CHIEF QC TERVERIFIKASI' : 'MENUNGGU ACC CHIEF QC' ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- KARTU INFORMASI KANBAN & IDENTITAS PRODUK -->
            <div class="bg-white border border-slate-300 rounded-lg p-5 sm:p-6 shadow-sm">
                <h2 class="text-sm font-bold text-slate-900 uppercase tracking-wide border-b border-slate-200 pb-2 mb-4">
                    1. Informasi Kanban & Identitas Part
                </h2>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-left">
                    <div>
                        <div class="data-label">Nomor Kanban</div>
                        <div class="data-value font-mono text-indigo-900"><?= htmlspecialchars($session['kanban_no'] ?: '-') ?></div>
                    </div>
                    <div>
                        <div class="data-label">Status Terkini Kanban</div>
                        <div class="data-value">
                            <?php
                            $kStatus = strtoupper($session['kanban_status'] ?: 'UNINSPECTED');
                            $kBadgeClass = 'text-slate-800 bg-slate-100';
                            if ($kStatus === 'REJECTED') $kBadgeClass = 'text-red-800 bg-red-100';
                            elseif ($kStatus === 'COMPLETED') $kBadgeClass = 'text-green-800 bg-green-100';
                            elseif ($kStatus === 'IN_PROGRESS') $kBadgeClass = 'text-blue-800 bg-blue-100';
                            ?>
                            <span class="inline-block px-2 py-0.5 rounded text-xs font-bold <?= $kBadgeClass ?>">
                                <?= $kStatus ?>
                            </span>
                        </div>
                    </div>
                    <div>
                        <div class="data-label">Kode Part</div>
                        <div class="data-value font-mono"><?= htmlspecialchars($session['part_code']) ?></div>
                    </div>
                    <div>
                        <div class="data-label">Nama Part</div>
                        <div class="data-value"><?= htmlspecialchars($session['part_name']) ?></div>
                    </div>
                    <div>
                        <div class="data-label">Pelanggan / Customer</div>
                        <div class="data-value"><?= htmlspecialchars($session['customer'] ?: '-') ?></div>
                    </div>
                    <div>
                        <div class="data-label">Model Part</div>
                        <div class="data-value"><?= htmlspecialchars($session['model'] ?: '-') ?></div>
                    </div>
                    <div>
                        <div class="data-label">Total Kuantitas Kanban</div>
                        <div class="data-value"><?= number_format((int)($session['kanban_qty'] ?: 0)) ?> Pcs</div>
                    </div>
                    <div>
                        <div class="data-label">Kuantitas Discan / Lot</div>
                        <div class="data-value"><?= number_format((int)($session['total_scanned_qty'] ?: 0)) ?> Pcs</div>
                    </div>
                    <div>
                        <div class="data-label">Rencana Sampling (AQL)</div>
                        <div class="data-value"><?= htmlspecialchars($session['aql_level'] ?: 'G-II') ?> (<?= (int)$session['sample_size'] ?> Sampel)</div>
                    </div>
                    <div>
                        <div class="data-label">Kriteria Penolakan (Re)</div>
                        <div class="data-value text-red-700">&ge; <?= (int)$session['reject_number'] ?> NG</div>
                    </div>
                    <div>
                        <div class="data-label">Nomor Dokumen Batch</div>
                        <div class="data-value font-mono"><?= htmlspecialchars($session['doc_no'] ?: '-') ?></div>
                    </div>
                    <div>
                        <div class="data-label">Pemasok / Vendor</div>
                        <div class="data-value"><?= htmlspecialchars($session['vendor'] ?: 'PT. SURYA TECHNOLOGY INDUSTRI') ?></div>
                    </div>
                </div>
            </div>

            <!-- KARTU STATUS INSPEKSI ULANG (REINSPECTION TRACKER) -->
            <div class="bg-white border border-slate-300 rounded-lg p-5 sm:p-6 shadow-sm">
                <h2 class="text-sm font-bold text-slate-900 uppercase tracking-wide border-b border-slate-200 pb-2 mb-4">
                    2. Status Pelacakan Inspeksi Ulang
                </h2>

                <?php if ($latestReinspection): ?>
                    <!-- KASUS: SUDAH ADA INSPEKSI ULANG LANJUTAN -->
                    <div class="p-4 bg-blue-50 border border-blue-200 rounded-md">
                        <div class="text-xs font-bold uppercase tracking-wider text-blue-900 mb-1">
                            Informasi Riwayat Inspeksi Ulang Lanjutan:
                        </div>
                        <div class="text-sm text-slate-800 space-y-1">
                            <p>
                                Kanban ini <strong>telah diinspeksi ulang</strong> pada Sesi Pemeriksaan <strong>#<?= (int)$latestReinspection['id'] ?></strong>
                                yang dimulai pada <span class="font-semibold"><?= date('d/m/Y H:i', strtotime($latestReinspection['started_at'])) ?> WIB</span>.
                            </p>
                            <p>
                                Hasil Inspeksi Ulang Terkini: 
                                <?php
                                $reStatus = strtoupper($latestReinspection['status']);
                                $reBadge = $reStatus === 'PASSED' ? 'bg-green-100 text-green-900 border-green-300' : ($reStatus === 'REJECTED' ? 'bg-red-100 text-red-900 border-red-300' : 'bg-yellow-100 text-yellow-900 border-yellow-300');
                                ?>
                                <span class="inline-block px-2 py-0.5 text-xs font-bold border rounded <?= $reBadge ?>">
                                    <?= $reStatus === 'PASSED' ? 'LOLOS (PASSED)' : ($reStatus === 'REJECTED' ? 'DITOLAK KEMBALI (REJECTED)' : 'DALAM PROSES') ?>
                                </span>
                            </p>
                            <div class="pt-2">
                                <a href="<?= base_url('modules/inspection/verify.php?id=' . $latestReinspection['id']) ?>" class="inline-block text-xs font-bold text-blue-700 underline hover:text-blue-900">
                                    Lihat Data Verifikasi Inspeksi Ulang Sesi #<?= (int)$latestReinspection['id'] ?> &rarr;
                                </a>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- KASUS: BELUM ADA INSPEKSI ULANG LANJUTAN -->
                    <div class="p-4 bg-slate-50 border border-slate-200 rounded-md text-sm text-slate-700">
                        <div class="text-xs font-bold uppercase tracking-wider text-slate-800 mb-1">
                            Status Tindak Lanjut Kanban Ini:
                        </div>
                        <p>
                            <strong>Belum dilakukan inspeksi ulang lanjutan</strong> untuk kanban ini. Seluruh kuantitas part saat ini berstatus 
                            <span class="font-semibold text-red-800">Karantina / Penahanan Mutu</span>, menunggu penyelesaian proses tindakan korektif (pemilahan sortir 100% atau rework part) sesuai instruksi lembar penolakan.
                        </p>
                    </div>
                <?php endif; ?>

                <?php if ($parentSession): ?>
                    <div class="mt-3 p-3 bg-slate-100 rounded text-xs text-slate-600 border border-slate-200">
                        Catatan Silsilah: Pemeriksaan Sesi #<?= (int)$session['id'] ?> ini merupakan tindak lanjut inspeksi ulang atas penolakan pada Sesi Awal 
                        <a href="<?= base_url('modules/inspection/verify.php?id=' . $parentSession['id']) ?>" class="font-semibold text-indigo-700 underline">
                            #<?= (int)$parentSession['id'] ?>
                        </a>
                        (dicatat tanggal <?= date('d/m/Y', strtotime($parentSession['started_at'])) ?>).
                    </div>
                <?php endif; ?>
            </div>

            <!-- KARTU MULTI-LOT PRODUKSI & PEMETAAN LOT -->
            <div class="bg-white border border-slate-300 rounded-lg p-5 sm:p-6 shadow-sm">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between border-b border-slate-200 pb-2 mb-4 gap-1">
                    <h2 class="text-sm font-bold text-slate-900 uppercase tracking-wide">
                        3. Rincian Multi-Lot Produksi yang Diperiksa
                    </h2>
                    <div class="text-xs text-slate-500">
                        Total Lot Terdaftar: <span class="font-semibold text-slate-800"><?= count($lots) ?> Lot</span>
                    </div>
                </div>

                <?php if (empty($lots)): ?>
                    <div class="p-3 bg-slate-50 border border-slate-200 rounded text-xs text-slate-600 text-center">
                        Data nomor lot tercatat: Lot <strong><?= htmlspecialchars($session['did_lot_number'] ?: '1') ?></strong> (Kavitas: <?= htmlspecialchars($session['cavity'] ?: '1') ?>).
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs border border-slate-200">
                            <thead class="bg-slate-100 text-slate-700 font-bold border-b border-slate-200">
                                <tr>
                                    <th class="py-2.5 px-3 border-r border-slate-200">No.</th>
                                    <th class="py-2.5 px-3 border-r border-slate-200">Nomor Lot Produksi</th>
                                    <th class="py-2.5 px-3 border-r border-slate-200">Nomor Box / Referensi</th>
                                    <th class="py-2.5 px-3 border-r border-slate-200 text-right">Kuantitas Part</th>
                                    <th class="py-2.5 px-3 text-center">Status Pemeriksaan Lot</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200">
                                <?php foreach ($lots as $idx => $lot): 
                                    $isNgLot = ($lot['lot_status'] === 'ng_found' || $lot['lot_status'] === 'ng_quarantine');
                                ?>
                                    <tr class="<?= $isNgLot ? 'bg-red-50' : 'hover:bg-slate-50' ?>">
                                        <td class="py-2 px-3 border-r border-slate-200 text-slate-500 font-mono"><?= $idx + 1 ?></td>
                                        <td class="py-2 px-3 border-r border-slate-200 font-bold font-mono text-slate-900">
                                            <?= htmlspecialchars($lot['lot_number']) ?>
                                        </td>
                                        <td class="py-2 px-3 border-r border-slate-200 font-mono text-slate-700">
                                            Box <?= htmlspecialchars($lot['ref_number']) ?>
                                        </td>
                                        <td class="py-2 px-3 border-r border-slate-200 text-right font-mono font-semibold">
                                            <?= number_format((int)$lot['qty']) ?> Pcs
                                        </td>
                                        <td class="py-2 px-3 text-center">
                                            <?php if ($isNgLot): ?>
                                                <span class="inline-block px-2.5 py-0.5 bg-red-100 text-red-900 border border-red-300 rounded font-bold text-xs">
                                                    Ditemukan Cacat (NG Found)
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-block px-2.5 py-0.5 bg-green-100 text-green-800 border border-green-200 rounded font-semibold text-xs">
                                                    Sesuai Standar (OK)
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- KARTU RINCIAN TEMUAN KETIDAKSESUAIAN (DEFECT SAMPLING) -->
            <div class="bg-white border border-slate-300 rounded-lg p-5 sm:p-6 shadow-sm">
                <h2 class="text-sm font-bold text-slate-900 uppercase tracking-wide border-b border-slate-200 pb-2 mb-4">
                    4. Rincian Temuan Ketidaksesuaian Mutu (Defect)
                </h2>

                <!-- RINGKASAN TITIK PENEMUAN SAMPEL & WAKTU -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4 p-4 bg-slate-50 border border-slate-200 rounded-md">
                    <div>
                        <div class="data-label">Titik Ditemukannya Cacat</div>
                        <div class="data-value text-red-900">
                            <?= $firstNgSample !== null ? "Sampel ke-{$firstNgSample} dari {$session['sample_size']} sampel" : "Tidak ada catatan sampel" ?>
                        </div>
                    </div>
                    <div>
                        <div class="data-label">Waktu Presisi Temuan</div>
                        <div class="data-value text-slate-900"><?= $firstNgTime ?></div>
                    </div>
                    <div>
                        <div class="data-label">Total Cacat Terdata</div>
                        <div class="data-value text-red-700"><?= (int)$session['ng_count'] ?> Temuan Produk Cacat</div>
                    </div>
                </div>

                <!-- TABEL DAFTAR DEFECT -->
                <?php if (empty($ngRecords)): ?>
                    <div class="p-4 text-center text-xs text-slate-500 bg-slate-50 border border-slate-200 rounded">
                        Tidak ditemukan rincian cacat spesifik pada rekaman sistem ini.
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs border border-slate-200">
                            <thead class="bg-slate-100 text-slate-700 font-bold border-b border-slate-200">
                                <tr>
                                    <th class="py-2.5 px-3 border-r border-slate-200">No.</th>
                                    <th class="py-2.5 px-3 border-r border-slate-200">Jenis Cacat Mutu (Defect)</th>
                                    <th class="py-2.5 px-3 border-r border-slate-200">Lot Terdampak</th>
                                    <th class="py-2.5 px-3 border-r border-slate-200">Nomor Box / Ref</th>
                                    <th class="py-2.5 px-3 border-r border-slate-200 text-center">Titik Sampel</th>
                                    <th class="py-2.5 px-3 text-right">Kuantitas Cacat</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200">
                                <?php foreach ($ngRecords as $idx => $ng): ?>
                                    <tr class="hover:bg-slate-50">
                                        <td class="py-2 px-3 border-r border-slate-200 text-slate-500 font-mono"><?= $idx + 1 ?></td>
                                        <td class="py-2 px-3 border-r border-slate-200 font-bold text-red-900">
                                            <?= htmlspecialchars($ng['defect_name']) ?>
                                        </td>
                                        <td class="py-2 px-3 border-r border-slate-200 font-mono font-semibold">
                                            Lot <?= htmlspecialchars($ng['lot_name'] ?: $ng['lot_number']) ?>
                                        </td>
                                        <td class="py-2 px-3 border-r border-slate-200 font-mono text-slate-700">
                                            Box <?= htmlspecialchars($ng['ref_name'] ?: $ng['ref_number']) ?>
                                        </td>
                                        <td class="py-2 px-3 border-r border-slate-200 text-center font-mono">
                                            #<?= (int)$ng['sample_number'] ?>
                                        </td>
                                        <td class="py-2 px-3 text-right font-mono font-bold text-red-800">
                                            <?= (int)$ng['qty_ng'] ?> Pcs
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <div class="mt-3 text-xs text-slate-600 bg-amber-50 border border-amber-200 rounded p-3">
                    <strong>Ketentuan Waktu Penanganan:</strong> Prioritas penanganan sorting maksimal 1x24 jam. Batas target sorting maksimal 3 hari sejak dokumen ini diterbitkan, dan batas maksimal pengerjaan ulang (rework) maksimal 7 hari kerja.
                </div>
            </div>

            <!-- KARTU LOG OTORISASI DIGITAL (INSPEKTOR & CHIEF QC) -->
            <div class="bg-white border border-slate-300 rounded-lg p-5 sm:p-6 shadow-sm">
                <h2 class="text-sm font-bold text-slate-900 uppercase tracking-wide border-b border-slate-200 pb-2 mb-4">
                    5. Log & Jejak Otorisasi Dokumen Digital
                </h2>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <!-- KOLOM 1: INSPEKTOR QC -->
                    <div class="p-4 border border-teal-200 bg-teal-50 rounded-md">
                        <div class="text-xs font-bold uppercase tracking-wider text-teal-900 mb-2 border-b border-teal-200 pb-1">
                            Pemeriksa (OQC Inspector)
                        </div>
                        <div class="space-y-1.5 text-xs">
                            <div>
                                <span class="text-slate-500">Nama Petugas:</span>
                                <div class="font-bold text-slate-900 text-sm"><?= htmlspecialchars($inspectorDisplayName) ?></div>
                            </div>
                            <div>
                                <span class="text-slate-500">Status Otorisasi:</span>
                                <div>
                                    <span class="inline-block px-2 py-0.5 bg-teal-100 text-teal-800 rounded font-bold text-xs">
                                        Terverifikasi Digital
                                    </span>
                                </div>
                            </div>
                            <div>
                                <span class="text-slate-500">Waktu Pelaporan:</span>
                                <div class="font-semibold text-slate-800">
                                    <?= date('d/m/Y H:i', strtotime($session['closed_at'] ?? $session['started_at'])) ?> WIB
                                </div>
                            </div>
                            <div>
                                <span class="text-slate-500">Metode Verifikasi:</span>
                                <div class="text-slate-700">Sistem Inspeksi Terintegrasi OQC</div>
                            </div>
                        </div>
                    </div>

                    <!-- KOLOM 2: CHIEF QC -->
                    <div class="p-4 border <?= $isChiefApproved ? 'border-blue-300 bg-blue-50' : 'border-slate-300 bg-slate-50' ?> rounded-md">
                        <div class="text-xs font-bold uppercase tracking-wider <?= $isChiefApproved ? 'text-blue-900 border-blue-200' : 'text-slate-700 border-slate-200' ?> mb-2 border-b pb-1">
                            Persetujuan (Chief QC)
                        </div>
                        <div class="space-y-1.5 text-xs">
                            <div>
                                <span class="text-slate-500">Pejabat Otorisasi:</span>
                                <div class="font-bold text-slate-900 text-sm"><?= htmlspecialchars($chiefDisplayName) ?></div>
                            </div>
                            <div>
                                <span class="text-slate-500">Status Otorisasi:</span>
                                <div>
                                    <?php if ($isChiefApproved): ?>
                                        <span class="inline-block px-2 py-0.5 bg-blue-100 text-blue-800 rounded font-bold text-xs">
                                            Disetujui (ACC Sah)
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-block px-2 py-0.5 bg-slate-200 text-slate-700 rounded font-semibold text-xs">
                                            Menunggu Persetujuan
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div>
                                <span class="text-slate-500">Waktu Persetujuan:</span>
                                <div class="font-semibold text-slate-800"><?= $chiefApprovedAt ?></div>
                            </div>
                            <div>
                                <span class="text-slate-500">Catatan Disposisi:</span>
                                <div class="text-slate-800 italic"><?= htmlspecialchars($session['chief_notes'] ?: ($isChiefApproved ? 'ACC Resmi' : '-')) ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- KOLOM 3: PENYELIA / SUPERVISOR -->
                    <div class="p-4 border border-slate-200 bg-slate-50 rounded-md">
                        <div class="text-xs font-bold uppercase tracking-wider text-slate-700 mb-2 border-b border-slate-200 pb-1">
                            Penyelia (Leader / Supervisor)
                        </div>
                        <div class="space-y-1.5 text-xs">
                            <div>
                                <span class="text-slate-500">Nama Supervisor:</span>
                                <div class="font-bold text-slate-900 text-sm"><?= htmlspecialchars($supervisorDisplayName) ?></div>
                            </div>
                            <div>
                                <span class="text-slate-500">Status Supervisi:</span>
                                <div>
                                    <?php if ($isSupervisorApproved): ?>
                                        <span class="inline-block px-2 py-0.5 bg-slate-200 text-slate-800 rounded font-bold text-xs">
                                            Disetujui Harian
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-block px-2 py-0.5 bg-slate-100 text-slate-600 rounded text-xs">
                                            Belum Direview
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div>
                                <span class="text-slate-500">Waktu Supervisi:</span>
                                <div class="font-semibold text-slate-800">
                                    <?= !empty($session['approved_at']) ? date('d/m/Y H:i', strtotime($session['approved_at'])) . ' WIB' : '-' ?>
                                </div>
                            </div>
                            <div>
                                <span class="text-slate-500">Catatan Supervisi:</span>
                                <div class="text-slate-800 italic"><?= htmlspecialchars($session['approval_notes'] ?: '-') ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- KARTU INTEGRITAS DATA & HUKUM -->
            <div class="bg-slate-100 border border-slate-300 rounded-lg p-4 text-xs text-slate-600">
                <div class="font-bold text-slate-800 mb-1">Catatan Keabsahan & Integritas Dokumen:</div>
                <p>
                    Data yang ditampilkan pada halaman ini adalah salinan otentik dari pangkalan data sistem manajemen mutu terintegrasi PT. Surya Technology Industri. Dokumen ini diterbitkan secara otomatis dan terlindungi oleh audit trail sistem. Segala bentuk manipulasi data fisik atau digital merupakan pelanggaran prosedur operasional standar penjaminan mutu perusahaan.
                </p>
            </div>

        <?php endif; ?>

        <!-- FOOTER RESMI -->
        <div class="text-center text-xs text-slate-500 py-4">
            &copy; <?= date('Y') ?> PT. Surya Technology Industri &bull; Divisi Penjaminan Mutu (OQC System) &bull; Seluruh hak cipta dilindungi undang-undang.
        </div>

    </div>

</body>
</html>
