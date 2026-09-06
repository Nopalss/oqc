<?php
/**
 * Modul Safety Stock - Detail Realisasi Safety Stock
 * Halaman rincian hasil inspeksi OQC, Lot Number, shift handover, dan catatan NG untuk barang Safety Stock.
 */
$breadcrumbCategory = "OPERASIONAL";
$pageTitle = "Detail Realisasi Safety Stock";
$pageSubtitle = "Rincian hasil inspeksi OQC, Lot Number, shift handover tracking, dan catatan NG";

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

$itemId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($itemId <= 0) {
    set_flash('error', 'ID Item Safety Stock tidak valid.');
    redirect('modules/safety_stock/index.php');
}

$pdo = getDB();
$item = null;
$sessions = [];
$defects = [];

if ($pdo) {
    try {
        // Fetch Safety Stock Planning Details
        $stmtItem = $pdo->prepare("
            SELECT ki.*, 
                   COALESCE(b.document_number, 'DOC-SAFETY-STOCK') AS document_number, 
                   COALESCE(b.vendor, 'Safety Stock Storage') AS vendor, 
                   COALESCE(b.imported_at, ki.created_at, NOW()) AS plan_date
            FROM kanban_items ki
            LEFT JOIN kanban_batches b ON ki.batch_id = b.id
            WHERE ki.id = :id
        ");
        $stmtItem->execute([':id' => $itemId]);
        $item = $stmtItem->fetch(PDO::FETCH_ASSOC);

        if ($item) {
            // Fetch All Inspection Sessions for this item
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
    set_flash('error', 'Data Item Safety Stock tidak ditemukan.');
    redirect('modules/safety_stock/index.php');
}

// Compute Summary Metrics across Sessions
$sessionCount  = count($sessions);
$hasRejected   = false;
$hasInProgress = false;
$hasPassed     = false;
$totalChecked  = 0;
$totalNg       = 0;
$maxSampleSize = 0;
$minStartedAt  = null;
$maxClosedAt   = null;

foreach ($sessions as $s) {
    if ($s['status'] === 'rejected') $hasRejected = true;
    if ($s['status'] === 'in_progress') $hasInProgress = true;
    if ($s['status'] === 'passed') $hasPassed = true;

    $totalChecked = max($totalChecked, (int)$s['samples_checked']);
    $totalNg += (int)$s['ng_count'];
    $maxSampleSize = max($maxSampleSize, (int)$s['sample_size']);

    if (!empty($s['started_at'])) {
        if ($minStartedAt === null || strtotime($s['started_at']) < strtotime($minStartedAt)) {
            $minStartedAt = $s['started_at'];
        }
    }

    if (!empty($s['closed_at'])) {
        if ($maxClosedAt === null || strtotime($s['closed_at']) > strtotime($maxClosedAt)) {
            $maxClosedAt = $s['closed_at'];
        }
    }
}

// Overall Item Status Determination
if ($sessionCount == 0) {
    $overallStatus = 'not_started';
    $overallBadgeHtml = '<span style="background-color: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; padding: 4px 12px; border-radius: 9999px; font-weight: 800; font-size: 11px;">BELUM MULAI</span>';
} elseif ($hasRejected) {
    $overallStatus = 'rejected';
    $overallBadgeHtml = '<span style="background-color: #ffe4e6; color: #9f1239; border: 1px solid #fecdd3; padding: 4px 12px; border-radius: 9999px; font-weight: 800; font-size: 11px;">REJECTED (NG)</span>';
} elseif ($hasInProgress) {
    $overallStatus = 'in_progress';
    $overallBadgeHtml = '<span style="background-color: #fffbe6; color: #92400e; border: 1px solid #ffe58f; padding: 4px 12px; border-radius: 9999px; font-weight: 800; font-size: 11px;">IN PROGRESS</span>';
} elseif ($hasPassed) {
    $overallStatus = 'passed';
    $overallBadgeHtml = '<span style="background-color: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; padding: 4px 12px; border-radius: 9999px; font-weight: 800; font-size: 11px;">PASSED (LOLOS)</span>';
} else {
    $overallStatus = 'not_started';
    $overallBadgeHtml = '<span style="background-color: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; padding: 4px 12px; border-radius: 9999px; font-weight: 800; font-size: 11px;">BELUM MULAI</span>';
}

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';
?>

<!-- Main Content Wrapper with Sidebar Offset (md:pl-64) -->
<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen min-w-0 w-full overflow-x-hidden transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-4 space-y-3 min-w-0 w-full overflow-x-hidden">
        
        <?= get_flash() ?>

        <!-- 1. Header Banner Card -->
        <div style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); border-radius: 12px; padding: 12px 16px; color: #ffffff; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <div style="display: flex; align-items: center; gap: 12px;">
                <a href="<?= base_url('modules/safety_stock/index.php') ?>" style="background-color: #334155; color: #ffffff; border-radius: 8px; padding: 6px 10px; font-size: 11px; font-weight: 700; border: none; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;" title="Kembali ke Monitoring Safety Stock">
                    <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                    </svg>
                    <span>Kembali</span>
                </a>
                <div>
                    <h2 style="font-size: 14px; font-weight: 800; margin: 0; line-height: 1.2; display: flex; align-items: center; gap: 8px;">
                        <span><?= htmlspecialchars($item['item_code']) ?></span>
                        <span style="color: #64748b;">|</span>
                        <span style="color: #cbd5e1; font-weight: 500; font-size: 13px;"><?= htmlspecialchars($item['item_description']) ?></span>
                    </h2>
                    <p style="font-size: 11px; color: #94a3b8; margin-top: 2px;">
                        Lot Number: <b style="color: #93c5fd; font-family: monospace;"><?= htmlspecialchars($sessions[0]['did_lot'] ?? '-') ?></b> | Customer: <b style="color: #ffffff;"><?= htmlspecialchars($item['customer'] ?? '-') ?></b>
                    </p>
                </div>
            </div>

            <div>
                <?= $overallBadgeHtml ?>
            </div>
        </div>

        <!-- 2. GRID 2 COLUMN INFORMASI HEADER REALISASI SAFETY STOCK -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
            
            <!-- Box Left: Informasi Barang Safety Stock -->
            <div style="background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px 16px; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
                <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #f1f5f9; padding-bottom: 8px; margin-bottom: 10px;">
                    <h3 style="font-size: 12px; font-weight: 800; color: #0f172a; text-transform: uppercase; letter-spacing: 0.5px; margin: 0; display: flex; align-items: center; gap: 6px;">
                        <svg style="width: 15px; height: 15px; color: #2563eb;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                        </svg>
                        <span>Informasi Barang Safety Stock</span>
                    </h3>
                    <span style="font-size: 10px; font-weight: 700; background-color: #eff6ff; color: #1d4ed8; padding: 2px 8px; border-radius: 6px;">
                        SAFETY STOCK
                    </span>
                </div>

                <div style="font-size: 12px; display: grid; grid-template-columns: 130px 1fr; row-gap: 6px; align-items: center;">
                    <span style="color: #64748b; font-weight: 600;">Kode Part:</span>
                    <b style="color: #1d4ed8; font-family: monospace; font-size: 13px;"><?= htmlspecialchars($item['item_code']) ?></b>

                    <span style="color: #64748b; font-weight: 600;">Nama Part:</span>
                    <b style="color: #1e293b;"><?= htmlspecialchars($item['item_description']) ?></b>

                    <span style="color: #64748b; font-weight: 600;">Lot Number:</span>
                    <b style="color: #334155; font-family: monospace; font-size: 12px; background-color: #f1f5f9; padding: 2px 6px; border-radius: 4px; display: inline-block;">
                        <?= htmlspecialchars($sessions[0]['did_lot'] ?? '-') ?>
                    </b>

                    <span style="color: #64748b; font-weight: 600;">Customer:</span>
                    <b style="color: #1e293b;"><?= htmlspecialchars($item['customer'] ?? '-') ?></b>

                    <span style="color: #64748b; font-weight: 600;">Qty Stock:</span>
                    <b style="color: #0f172a; font-family: monospace; font-size: 13px;"><?= number_format($item['qty']) ?> pcs</b>

                    <span style="color: #64748b; font-weight: 600;">Lokasi Gudang:</span>
                    <b style="color: #475569; font-family: monospace;"><?= htmlspecialchars($item['str_loc'] ?? 'WH-SAFETY') ?></b>
                </div>
            </div>

            <!-- Box Right: Ringkasan Hasil Inspeksi OQC -->
            <div style="background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px 16px; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
                <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #f1f5f9; padding-bottom: 8px; margin-bottom: 10px;">
                    <h3 style="font-size: 12px; font-weight: 800; color: #0f172a; text-transform: uppercase; letter-spacing: 0.5px; margin: 0; display: flex; align-items: center; gap: 6px;">
                        <svg style="width: 15px; height: 15px; color: #059669;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <span>Ringkasan Hasil Inspeksi OQC</span>
                    </h3>
                    <span style="font-size: 11px; color: #64748b; font-weight: 600;">
                        Total <?= $sessionCount ?> Sesi Pengerjaan
                    </span>
                </div>

                <div style="font-size: 12px; display: grid; grid-template-columns: 140px 1fr; row-gap: 6px; align-items: center;">
                    <span style="color: #64748b; font-weight: 600;">Sampel Checked:</span>
                    <b style="color: #0f172a; font-family: monospace; font-size: 13px;">
                        <?= $totalChecked ?> / <?= $maxSampleSize ?> pcs
                    </b>

                    <span style="color: #64748b; font-weight: 600;">Temuan NG (Defect):</span>
                    <b style="color: <?= ($totalNg > 0) ? '#e11d48' : '#059669' ?>; font-family: monospace; font-size: 13px;">
                        <?= $totalNg ?> pcs NG
                    </b>

                    <span style="color: #64748b; font-weight: 600;">Inspektur Terakhir:</span>
                    <b style="color: #1e293b;"><?= htmlspecialchars($sessions[0]['inspector_user_name'] ?? $sessions[0]['did_pic'] ?? '-') ?></b>

                    <span style="color: #64748b; font-weight: 600;">Waktu Mulai:</span>
                    <b style="color: #334155;">
                        <?= !empty($minStartedAt) ? date('d M Y, H:i', strtotime($minStartedAt)) : '-' ?> WIB
                    </b>

                    <span style="color: #64748b; font-weight: 600;">Waktu Selesai:</span>
                    <b style="color: #334155;">
                        <?= !empty($maxClosedAt) ? date('d M Y, H:i', strtotime($maxClosedAt)) : '-' ?> WIB
                    </b>
                </div>
            </div>

        </div>

        <!-- 3. TABEL RIWAYAT SESI PEKERJAAN & SHIFT HANDOVER TRACKING -->
        <div style="background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
            <div style="padding: 12px 16px; background-color: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between;">
                <h3 style="font-size: 12px; font-weight: 800; color: #334155; text-transform: uppercase; letter-spacing: 0.5px; margin: 0; display: flex; align-items: center; gap: 6px;">
                    <svg style="width: 15px; height: 15px; color: #475569;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                    </svg>
                    <span>Riwayat Sesi Pekerjaan & Shift (Handover Tracking)</span>
                </h3>
                <span style="font-size: 11px; color: #64748b; font-weight: 600;">Total: <?= $sessionCount ?> Sesi Inspeksi</span>
            </div>

            <div style="overflow-x-auto;">
                <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 12px; color: #334155;">
                    <thead style="background-color: #ffffff; border-bottom: 1px solid #e2e8f0; font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase;">
                        <tr>
                            <th style="padding: 10px 14px; width: 60px;">Sesi #</th>
                            <th style="padding: 10px 14px;">Inspektur / PIC QC</th>
                            <th style="padding: 10px 14px; width: 120px;">Status Sesi</th>
                            <th style="padding: 10px 14px; text-align: center; width: 120px;">Sampel Dicek</th>
                            <th style="padding: 10px 14px; text-align: center; width: 100px;">Temuan NG</th>
                            <th style="padding: 10px 14px; width: 130px;">Jam Mulai</th>
                            <th style="padding: 10px 14px; width: 130px;">Jam Selesai</th>
                            <th style="padding: 10px 14px; text-align: right; width: 100px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($sessions)): ?>
                            <tr>
                                <td colspan="8" style="padding: 24px; text-align: center; color: #94a3b8; font-weight: 500;">
                                    Belum ada riwayat sesi pengerjaan inspeksi untuk item Safety Stock ini.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($sessions as $sIdx => $s): ?>
                                <?php 
                                    $inspectorName = !empty($s['inspector_user_name']) ? $s['inspector_user_name'] : (!empty($s['did_pic']) ? $s['did_pic'] : 'Inspektur QC');
                                ?>
                                <tr style="border-bottom: 1px solid #f1f5f9;">
                                    <td style="padding: 10px 14px; font-weight: 800; color: #0f172a;">
                                        Sesi #<?= $sIdx + 1 ?>
                                    </td>
                                    <td style="padding: 10px 14px; font-weight: 600; color: #1e293b;">
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <span style="width: 24px; height: 24px; border-radius: 9999px; background-color: #dbeafe; color: #1d4ed8; font-weight: 800; font-size: 11px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
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

        <!-- 4. TABEL RINCIAN TEMUAN DEFECT / NG -->
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
