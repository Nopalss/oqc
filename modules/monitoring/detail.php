<?php
/**
 * Detail Monitoring Realisasi Inspeksi Supervisor OQC
 * Halaman khusus atasan untuk memantau rincian pengerjaan per part item.
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

$itemId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($itemId <= 0) {
    set_flash('error', 'ID Item Planning tidak valid.');
    redirect('modules/monitoring/index.php');
}

$pdo = getDB();
$item = null;
$sessions = [];
$defects = [];

if ($pdo) {
    try {
        // Fetch Planning Item Details
        $stmtItem = $pdo->prepare("
            SELECT ki.*, 
                   COALESCE(b.document_number, 'DOC-LEGACY') AS document_number, 
                   COALESCE(b.vendor, 'Legacy Input') AS vendor, 
                   COALESCE(b.imported_at, ki.created_at, NOW()) AS plan_date
            FROM kanban_items ki
            LEFT JOIN kanban_batches b ON ki.batch_id = b.id
            WHERE ki.id = :id
        ");
        $stmtItem->execute([':id' => $itemId]);
        $item = $stmtItem->fetch(PDO::FETCH_ASSOC);

        if ($item) {
            // Fetch All Inspection Sessions for this item (ordered by session ID ASC)
            $stmtSess = $pdo->prepare("
                SELECT ss.*, 
                       u.name AS inspector_user_name, u.username AS inspector_username,
                       did.pic AS did_pic, did.lot_number AS did_lot, did.part_name AS did_part_name, did.part_code AS did_part_code
                FROM inspection_sessions ss
                LEFT JOIN users u ON ss.inspector_id = u.id
                LEFT JOIN daily_inspection_data did ON ss.did_id = did.id
                WHERE ss.kanban_item_id = :item_id OR ss.did_id = :item_id_did
                ORDER BY ss.id ASC
            ");
            $stmtSess->execute([':item_id' => $itemId, ':item_id_did' => $itemId]);
            $sessions = $stmtSess->fetchAll(PDO::FETCH_ASSOC);

            // Fetch Defect / NG Records if any session exists
            if (!empty($sessions)) {
                $sessionIds = array_column($sessions, 'id');
                $inQuery = implode(',', array_fill(0, count($sessionIds), '?'));
                $stmtDef = $pdo->prepare("
                    SELECT ng.*, dt.name AS defect_type_name, sm.sample_number, sm.inspection_session_id, ss.started_at, ss.closed_at,
                           COALESCE(u.name, did.pic, '-') AS inspector_name
                    FROM inspection_ng_records ng
                    JOIN inspection_samples sm ON ng.inspection_sample_id = sm.id
                    JOIN inspection_sessions ss ON sm.inspection_session_id = ss.id
                    LEFT JOIN users u ON ss.inspector_id = u.id
                    LEFT JOIN daily_inspection_data did ON ss.did_id = did.id
                    LEFT JOIN defect_types dt ON ng.defect_type_id = dt.id
                    WHERE sm.inspection_session_id IN ({$inQuery})
                    ORDER BY ng.id ASC
                ");
                $stmtDef->execute($sessionIds);
                $defects = $stmtDef->fetchAll(PDO::FETCH_ASSOC);
            }
        }

    } catch (PDOException $e) {
        $item = null;
    }
}

if (!$item) {
    set_flash('error', 'Data Planning Item tidak ditemukan.');
    redirect('modules/monitoring/index.php');
}

// Compute Summary Metrics across Sessions
$sessionCount = count($sessions);
$hasRejected   = false;
$hasInProgress = false;
$hasPassed     = false;

$totalSamplesChecked = 0;
$maxSampleSize = 0;
$totalNgCount = 0;
$rejectNumber = 0;
$firstStartedAt = null;
$lastClosedAt = null;
$lastSessionId = null;

foreach ($sessions as $s) {
    if ($s['status'] === 'rejected') $hasRejected = true;
    if ($s['status'] === 'in_progress') $hasInProgress = true;
    if ($s['status'] === 'passed') $hasPassed = true;

    $totalSamplesChecked = max($totalSamplesChecked, (int)$s['samples_checked']);
    $maxSampleSize       = max($maxSampleSize, (int)$s['sample_size']);
    $totalNgCount        = max($totalNgCount, (int)$s['ng_count']);
    $rejectNumber        = max($rejectNumber, (int)$s['reject_number']);

    if (empty($firstStartedAt) || $s['started_at'] < $firstStartedAt) {
        $firstStartedAt = $s['started_at'];
    }
    if (empty($lastClosedAt) || $s['closed_at'] > $lastClosedAt) {
        $lastClosedAt = $s['closed_at'];
    }
    $lastSessionId = $s['id'];
}

if ($sessionCount == 0) {
    $finalStatus = 'not_started';
} elseif ($hasRejected) {
    $finalStatus = 'rejected';
} elseif ($hasInProgress) {
    $finalStatus = 'in_progress';
} elseif ($hasPassed) {
    $finalStatus = 'passed';
} else {
    $finalStatus = 'not_started';
}

$breadcrumbCategory = "OPERASIONAL";
$pageTitle = "Detail Realisasi Inspeksi Supervisor";
$pageSubtitle = "Rincian status pengerjaan, shift handover, dan temuan defect per part item";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen min-w-0 w-full overflow-x-hidden transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-5 space-y-4 min-w-0 w-full overflow-x-hidden">
        
        <?= render_flash() ?>

        <!-- Top Navigation Bar: Back Button & Header -->
        <div style="background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px 16px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
            <div style="display: flex; align-items: center; gap: 12px;">
                <a href="<?= base_url('modules/monitoring/index.php') ?>" style="padding: 6px 14px; background-color: #f1f5f9; color: #334155; font-weight: 700; font-size: 12px; border-radius: 8px; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #cbd5e1;">
                    &larr; Kembali ke Monitoring
                </a>
                <div>
                    <h2 style="font-size: 14px; font-weight: 800; color: #0f172a; margin: 0;">Detail Realisasi Inspeksi</h2>
                    <span style="font-size: 11px; color: #64748b; font-weight: 500;">
                        Part: <b style="color: #1d4ed8; font-family: monospace;"><?= htmlspecialchars($item['item_code']) ?></b> &ndash; <?= htmlspecialchars($item['item_description']) ?>
                    </span>
                </div>
            </div>

            <!-- Quick Action Buttons -->
            <div style="display: flex; align-items: center; gap: 8px;">
                <?php if ($finalStatus === 'rejected' && $lastSessionId): ?>
                    <a href="<?= base_url('modules/inspection/print_rejection_sheet.php?session_id=' . $lastSessionId) ?>" target="_blank" rel="noopener noreferrer" style="padding: 6px 14px; background-color: #ffe4e6; color: #be123c; font-weight: 700; font-size: 12px; border-radius: 8px; text-decoration: none; border: 1px solid #fecdd3; display: inline-flex; align-items: center; gap: 6px;">
                        <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
                        </svg>
                        <span>Cetak Rejection Sheet</span>
                    </a>
                <?php endif; ?>
                <?php if ($lastSessionId): ?>
                    <a href="<?= base_url('modules/inspection/session.php?id=' . $lastSessionId) ?>" style="padding: 6px 14px; background-color: #2563eb; color: #ffffff; font-weight: 700; font-size: 12px; border-radius: 8px; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                        <span>Buka Workbench Sesi</span> &rarr;
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- 4 Metric Indicators Grid -->
        <div style="display: flex; align-items: stretch; gap: 12px; width: 100%; overflow-x: auto;">
            <!-- Status Card -->
            <div style="flex: 1; min-width: 180px; background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px 16px;">
                <span style="font-size: 10px; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; block;">STATUS AKHIR REALISASI</span>
                <div style="margin-top: 6px;">
                    <?php if ($finalStatus === 'passed'): ?>
                        <span style="display: inline-flex; align-items: center; background-color: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; padding: 4px 10px; border-radius: 9999px; font-size: 12px; font-weight: 800;">
                            <span style="width: 8px; height: 8px; border-radius: 9999px; background-color: #059669; margin-right: 6px;"></span>
                            PASSED (LULUS OK)
                        </span>
                    <?php elseif ($finalStatus === 'rejected'): ?>
                        <span style="display: inline-flex; align-items: center; background-color: #ffe4e6; color: #9f1239; border: 1px solid #fecdd3; padding: 4px 10px; border-radius: 9999px; font-size: 12px; font-weight: 800;">
                            <span style="width: 8px; height: 8px; border-radius: 9999px; background-color: #e11d48; margin-right: 6px;"></span>
                            REJECTED (NG)
                        </span>
                    <?php elseif ($finalStatus === 'in_progress'): ?>
                        <span style="display: inline-flex; align-items: center; background-color: #fffbe6; color: #92400e; border: 1px solid #ffe58f; padding: 4px 10px; border-radius: 9999px; font-size: 12px; font-weight: 800;">
                            <span style="width: 8px; height: 8px; border-radius: 9999px; background-color: #d97706; margin-right: 6px;" class="animate-ping"></span>
                            IN PROGRESS (OPER SHIFT)
                        </span>
                    <?php else: ?>
                        <span style="display: inline-flex; align-items: center; background-color: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; padding: 4px 10px; border-radius: 9999px; font-size: 12px; font-weight: 800;">
                            <span style="width: 8px; height: 8px; border-radius: 9999px; background-color: #94a3b8; margin-right: 6px;"></span>
                            BELUM DIKERJAKAN
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Progress Sample -->
            <div style="flex: 1; min-width: 180px; background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px 16px;">
                <span style="font-size: 10px; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; block;">PROGRESS SAMPEL AQL</span>
                <div style="font-size: 20px; font-weight: 900; color: #0f172a; margin-top: 4px; font-family: monospace;">
                    <?= $totalSamplesChecked ?> <span style="font-size: 13px; color: #64748b; font-weight: 600;">/ <?= $maxSampleSize ?> pcs</span>
                </div>
            </div>

            <!-- NG Count vs Limit -->
            <div style="flex: 1; min-width: 180px; background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px 16px;">
                <span style="font-size: 10px; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; block;">TEMUAN DEFECT (NG)</span>
                <div style="font-size: 20px; font-weight: 900; color: <?= ($totalNgCount > 0) ? '#e11d48' : '#059669' ?>; margin-top: 4px; font-family: monospace;">
                    <?= $totalNgCount ?> <span style="font-size: 12px; color: #64748b; font-weight: 600;">(Limit Max Reject: <?= $rejectNumber ?>)</span>
                </div>
            </div>

            <!-- Durasi Inspeksi -->
            <div style="flex: 1; min-width: 180px; background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px 16px;">
                <span style="font-size: 10px; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; block;">RENTANG WAKTU INSPEKSI</span>
                <div style="font-size: 12px; font-weight: 700; color: #1e293b; margin-top: 6px;">
                    <?php if ($firstStartedAt): ?>
                        <?= date('H:i', strtotime($firstStartedAt)) ?> 
                        <?= $lastClosedAt ? '&ndash; ' . date('H:i', strtotime($lastClosedAt)) . ' WIB' : '(Sedang Berjalan)' ?>
                    <?php else: ?>
                        <span style="color: #94a3b8; font-weight: 400;">-</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Planning & Part Overview Grid (2 Cards) -->
        <div style="display: flex; gap: 16px; flex-wrap: wrap;">
            
            <!-- Planning Info -->
            <div style="flex: 1; min-width: 320px; background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px;">
                <h3 style="font-size: 12px; font-weight: 800; color: #0f172a; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px; border-bottom: 1px solid #f1f5f9; padding-bottom: 8px;">
                    📋 Informasi Planning Inspeksi
                </h3>
                <div style="font-size: 12px; display: grid; grid-template-columns: 130px 1fr; row-gap: 8px; align-items: center;">
                    <span style="color: #64748b; font-weight: 600;">Tanggal Planning:</span>
                    <b style="color: #1e293b;"><?= date('d F Y', strtotime($item['plan_date'])) ?></b>

                    <span style="color: #64748b; font-weight: 600;">No Dokumen:</span>
                    <b style="color: #1e293b; font-family: monospace;"><?= htmlspecialchars($item['document_number']) ?></b>

                    <span style="color: #64748b; font-weight: 600;">Tipe Planning:</span>
                    <div>
                        <?php if ($item['plan_type'] === 'safety_stock'): ?>
                            <span style="background-color: #f3e8ff; color: #6b21a8; border: 1px solid #e9d5ff; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 700;">Safety Stock</span>
                        <?php else: ?>
                            <span style="background-color: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 700;">Kanban</span>
                        <?php endif; ?>
                    </div>

                    <span style="color: #64748b; font-weight: 600;">Qty:</span>
                    <b style="color: #0f172a; font-family: monospace; font-size: 14px;"><?= number_format($item['qty']) ?> pcs</b>

                    <span style="color: #64748b; font-weight: 600;">No Kanban:</span>
                    <b style="color: #334155; font-family: monospace;"><?= htmlspecialchars($item['kanban_no'] ?? '-') ?></b>
                </div>
            </div>

            <!-- Part Info -->
            <div style="flex: 1; min-width: 320px; background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px;">
                <h3 style="font-size: 12px; font-weight: 800; color: #0f172a; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px; border-bottom: 1px solid #f1f5f9; padding-bottom: 8px;">
                    🔧 Informasi Part & Customer
                </h3>
                <div style="font-size: 12px; display: grid; grid-template-columns: 130px 1fr; row-gap: 8px; align-items: center;">
                    <span style="color: #64748b; font-weight: 600;">Kode Part:</span>
                    <b style="color: #1d4ed8; font-family: monospace; font-size: 13px;"><?= htmlspecialchars($item['item_code']) ?></b>

                    <span style="color: #64748b; font-weight: 600;">Nama Part:</span>
                    <b style="color: #1e293b;"><?= htmlspecialchars($item['item_description']) ?></b>
                    <span style="color: #64748b; font-weight: 600;">Lot Number:</span>
                    <b style="color: #334155; font-family: monospace;"><?= htmlspecialchars($sessions[0]['did_lot'] ?? '-') ?></b>
                    <span style="color: #64748b; font-weight: 600;">Customer:</span>
                    <b style="color: #1e293b;"><?= htmlspecialchars($item['customer'] ?? '-') ?></b>
                </div>
            </div>

        </div>

        <!-- Tabel Riwayat Sesi Inspeksi (Shift Handover Tracking) -->
        <div style="background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
            <div style="padding: 12px 16px; background-color: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between;">
                <h3 style="font-size: 12px; font-weight: 800; color: #0f172a; text-transform: uppercase; letter-spacing: 0.5px; margin: 0;">
                    👥 Riwayat Sesi Pekerjaan & Shift (Handover Tracking)
                </h3>
                <span style="font-size: 11px; color: #64748b; font-weight: 600;">Total: <?= count($sessions) ?> Sesi Inspeksi</span>
            </div>

            <div style="overflow-x-auto;">
                <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 12px; color: #334155;">
                    <thead style="background-color: #ffffff; border-bottom: 1px solid #e2e8f0; font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase;">
                        <tr>
                            <th style="padding: 10px 14px; width: 60px;">Sesi #</th>
                            <th style="padding: 10px 14px;">Inspektur / PIC QC</th>
                            <th style="padding: 10px 14px; width: 120px;">Status Sesi</th>
                            <th style="padding: 10px 14px; text-align: center; width: 110px;">Sampel Dicek</th>
                            <th style="padding: 10px 14px; text-align: center; width: 90px;">Temuan NG</th>
                            <th style="padding: 10px 14px; width: 130px;">Jam Mulai</th>
                            <th style="padding: 10px 14px; width: 130px;">Jam Selesai</th>
                            <th style="padding: 10px 14px; text-align: right; width: 80px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($sessions)): ?>
                            <tr>
                                <td colspan="8" style="padding: 24px; text-align: center; color: #94a3b8; font-size: 12px;">
                                    Belum ada sesi inspeksi yang dibuat untuk part item ini.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($sessions as $sIdx => $s): ?>
                                <?php 
                                $inspectorName = $s['inspector_user_name'] ?: ($s['did_pic'] ?: 'Tidak Terdaftar');
                                ?>
                                <tr style="border-bottom: 1px solid #f1f5f9;">
                                    <td style="padding: 10px 14px; font-weight: 800; color: #1e293b;">
                                        Sesi #<?= $sIdx + 1 ?>
                                    </td>
                                    <td style="padding: 10px 14px; font-weight: 700; color: #0f172a;">
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <span style="width: 24px; height: 24px; border-radius: 9999px; background-color: #dbeafe; color: #1d4ed8; font-weight: 800; font-size: 11px; display: flex; align-items: center; justify-content: center;">
                                                <?= strtoupper(substr($inspectorName, 0, 1)) ?>
                                            </span>
                                            <span><?= htmlspecialchars($inspectorName) ?></span>
                                        </div>
                                    </td>
                                    <td style="padding: 10px 14px;">
                                        <?php if ($s['status'] === 'passed'): ?>
                                            <span style="background-color: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; padding: 2px 8px; border-radius: 9999px; font-size: 10px; font-weight: 700;">PASSED</span>
                                        <?php elseif ($s['status'] === 'rejected'): ?>
                                            <span style="background-color: #ffe4e6; color: #9f1239; border: 1px solid #fecdd3; padding: 2px 8px; border-radius: 9999px; font-size: 10px; font-weight: 700;">REJECTED</span>
                                        <?php else: ?>
                                            <span style="background-color: #fffbe6; color: #92400e; border: 1px solid #ffe58f; padding: 2px 8px; border-radius: 9999px; font-size: 10px; font-weight: 700;">IN PROGRESS</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 10px 14px; text-align: center; font-weight: 800; font-family: monospace;">
                                        <?= (int)$s['samples_checked'] ?> / <?= (int)$s['sample_size'] ?> pcs
                                    </td>
                                    <td style="padding: 10px 14px; text-align: center; font-weight: 900; font-family: monospace; color: <?= ($s['ng_count'] > 0) ? '#e11d48' : '#059669' ?>;">
                                        <?= (int)$s['ng_count'] ?> NG
                                    </td>
                                    <td style="padding: 10px 14px; color: #64748b; font-weight: 500;">
                                        <?= date('d M Y, H:i', strtotime($s['started_at'])) ?>
                                    </td>
                                    <td style="padding: 10px 14px; color: #64748b; font-weight: 500;">
                                        <?= !empty($s['closed_at']) ? date('d M Y, H:i', strtotime($s['closed_at'])) : '-' ?>
                                    </td>
                                    <td style="padding: 10px 14px; text-align: right; white-space: nowrap;">
                                        <a href="<?= base_url('modules/inspection/session.php?id=' . $s['id']) ?>" style="padding: 6px 12px; background-color: #eff6ff; color: #2563eb; font-weight: 700; font-size: 11px; border-radius: 6px; text-decoration: none; border: 1px solid #bfdbfe; display: inline-flex; align-items: center; justify-content: center; white-space: nowrap; line-height: 1.2;">
                                            Buka Sesi
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Tabel Rincian Temuan Defect / NG (Jika ada temuan NG) -->
        <?php if (!empty($defects)): ?>
            <div style="background-color: #ffffff; border: 1px solid #fecdd3; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
                <div style="padding: 12px 16px; background-color: #fff1f2; border-bottom: 1px solid #fecdd3; display: flex; align-items: center; justify-content: space-between;">
                    <h3 style="font-size: 12px; font-weight: 800; color: #9f1239; text-transform: uppercase; letter-spacing: 0.5px; margin: 0;">
                        🚨 Rincian Temuan Defect / NG
                    </h3>
                    <span style="font-size: 11px; color: #be123c; font-weight: 700;"><?= count($defects) ?> Item Catatan NG</span>
                </div>

                <div style="overflow-x-auto;">
                    <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 12px; color: #334155;">
                        <thead style="background-color: #ffffff; border-bottom: 1px solid #fecdd3; font-size: 10px; font-weight: 800; color: #9f1239; text-transform: uppercase;">
                            <tr>
                                <th style="padding: 10px 14px; width: 40px;">No</th>
                                <th style="padding: 10px 14px; width: 160px;">Jenis Defect</th>
                                <th style="padding: 10px 14px; width: 140px;">Inspektur / PIC</th>
                                <th style="padding: 10px 14px; text-align: center; width: 90px;">Qty NG</th>
                                <th style="padding: 10px 14px;">Catatan Inspektur</th>
                                <th style="padding: 10px 14px; width: 130px;">Waktu Temuan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($defects as $dIdx => $df): ?>
                                <tr style="border-bottom: 1px solid #ffe4e6;">
                                    <td style="padding: 10px 14px; font-weight: 700; color: #9f1239;"><?= $dIdx + 1 ?></td>
                                    <td style="padding: 10px 14px; font-weight: 800; color: #9f1239;">
                                        <?= htmlspecialchars($df['defect_type_name'] ?? 'Defect') ?>
                                        <span style="font-size: 10px; color: #64748b; font-weight: 500; display: block;">Sampel #<?= (int)$df['sample_number'] ?></span>
                                    </td>
                                    <td style="padding: 10px 14px; font-weight: 600; color: #1e293b; white-space: nowrap;">
                                        <div style="display: flex; align-items: center; gap: 6px;">
                                            <span style="width: 20px; height: 20px; border-radius: 9999px; background-color: #ffe4e6; color: #be123c; font-weight: 800; font-size: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                                <?= strtoupper(substr($df['inspector_name'] ?? 'Q', 0, 1)) ?>
                                            </span>
                                            <span><?= htmlspecialchars($df['inspector_name'] ?? '-') ?></span>
                                        </div>
                                    </td>
                                    <td style="padding: 10px 14px; text-align: center; font-weight: 900; font-family: monospace; color: #be123c;">
                                        <?= (int)($df['qty_ng'] ?? 1) ?> pcs
                                    </td>
                                    <td style="padding: 10px 14px; color: #475569;">
                                        <?= htmlspecialchars(!empty($df['remark']) ? $df['remark'] : '-') ?>
                                    </td>
                                    <td style="padding: 10px 14px; color: #64748b; font-size: 11px;">
                                        <?= date('d M Y, H:i', strtotime($df['started_at'])) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

    </main>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
