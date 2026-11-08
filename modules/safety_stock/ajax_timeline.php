<?php
/**
 * Ajax Handler: Part Safety Stock Horizontal Timeline Matrix
 * Mengembalikan tabel matriks horizontal kronologi mutasi per part (Lazy Loaded).
 * Fitur: Dual Sticky Columns + Mouse-Driven Scroll + Horizontal Infinite Scroll & Chunking (50 Transaksi).
 */

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

require_menu_access('safety_stock');

$pdo = getDB();
$partCode  = sanitize($_GET['part_code'] ?? '');
$period    = sanitize($_GET['period'] ?? 'semua');
$startDate = sanitize($_GET['start_date'] ?? '');
$endDate   = sanitize($_GET['end_date'] ?? '');
$chunk     = max(10, (int)($_GET['chunk'] ?? 50));

if (empty($partCode)) {
    echo '<div style="padding: 16px; text-align: center; color: #ef4444; font-size: 12px; font-weight: 700;">Kode part tidak valid.</div>';
    exit;
}

// Preset periode filter tanggal
if ($period === 'tahunan') {
    $periodStartDate = date('Y-01-01 00:00:00');
    $periodEndDate   = date('Y-m-d 23:59:59');
} elseif ($period === 'bulanan') {
    $periodStartDate = date('Y-m-01 00:00:00');
    $periodEndDate   = date('Y-m-d 23:59:59');
} elseif ($period === 'mingguan') {
    $periodStartDate = date('Y-m-d 00:00:00', strtotime('monday this week'));
    $periodEndDate   = date('Y-m-d 23:59:59');
} elseif ($period === 'hari_ini') {
    $periodStartDate = date('Y-m-d 00:00:00');
    $periodEndDate   = date('Y-m-d 23:59:59');
} elseif ($period === 'custom' && !empty($startDate) && !empty($endDate)) {
    $periodStartDate = $startDate . ' 00:00:00';
    $periodEndDate   = $endDate . ' 23:59:59';
} else {
    // Semua (All-Time)
    $periodStartDate = '';
    $periodEndDate   = '';
}

// 1. Ambil Informasi Part
$stPart = $pdo->prepare("
    SELECT 
        UPPER(TRIM(COALESCE(did.part_code, ki.item_code, ''))) AS part_code,
        COALESCE(did.part_name, ki.item_description, 'N/A') AS part_name
    FROM inspection_sessions ss
    LEFT JOIN daily_inspection_data did ON did.id = ss.did_id
    LEFT JOIN kanban_items ki ON ki.id = ss.kanban_item_id
    WHERE ss.inspection_type = 'safety_stock'
      AND UPPER(TRIM(COALESCE(did.part_code, ki.item_code, ''))) = :pcode
    LIMIT 1
");
$stPart->execute([':pcode' => $partCode]);
$partInfo = $stPart->fetch(PDO::FETCH_ASSOC);

// 2. Query Transaksi Masuk (Normal & Sisa Kanban)
$stIn = $pdo->prepare("
    SELECT 
        ss.id AS session_id,
        ss.started_at,
        ss.total_scanned_qty AS qty,
        COALESCE(ss.ss_source, 'direct') AS ss_source,
        'IN' AS flow_type,
        u.name AS inspector_name
    FROM inspection_sessions ss
    LEFT JOIN users u ON u.id = ss.inspector_id
    LEFT JOIN daily_inspection_data did ON did.id = ss.did_id
    LEFT JOIN kanban_items ki ON ki.id = ss.kanban_item_id
    WHERE ss.inspection_type = 'safety_stock'
      AND ss.status = 'passed'
      AND UPPER(TRIM(COALESCE(did.part_code, ki.item_code, ''))) = :pcode
    ORDER BY ss.started_at ASC, ss.id ASC
");
$stIn->execute([':pcode' => $partCode]);
$inRows = $stIn->fetchAll(PDO::FETCH_ASSOC);

// 3. Query Transaksi Keluar (Memotong Kanban)
$stOut = $pdo->prepare("
    SELECT 
        s_k.id AS kanban_session_id,
        s_k.started_at,
        s_ss.total_scanned_qty AS qty,
        'OUT' AS flow_type,
        s_ss.id AS ss_session_id,
        u.name AS inspector_name,
        ki_k.kanban_no
    FROM inspection_sessions s_ss
    JOIN inspection_sessions s_k ON s_k.id = s_ss.auto_fulfilled_by_session_id
    LEFT JOIN users u ON u.id = s_k.inspector_id
    LEFT JOIN daily_inspection_data did ON did.id = s_ss.did_id
    LEFT JOIN kanban_items ki ON ki.id = s_ss.kanban_item_id
    LEFT JOIN kanban_items ki_k ON ki_k.id = s_k.kanban_item_id
    WHERE s_ss.inspection_type = 'safety_stock'
      AND s_ss.auto_fulfilled_by_session_id > 0
      AND UPPER(TRIM(COALESCE(did.part_code, ki.item_code, ''))) = :pcode
    ORDER BY s_k.started_at ASC, s_k.id ASC
");
$stOut->execute([':pcode' => $partCode]);
$outRows = $stOut->fetchAll(PDO::FETCH_ASSOC);

// Gabungkan dan urutkan kronologis
$allEvents = [];
foreach ($inRows as $r) {
    $isDirect = ($r['ss_source'] === 'direct');
    $allEvents[] = [
        'id'     => $r['session_id'],
        'time'   => $r['started_at'],
        'flow'   => 'IN',
        'source' => $r['ss_source'],
        'qty'    => (int)$r['qty'],
        'ref'    => $isDirect ? "OQC Box #{$r['session_id']}" : "Sisa Scan #{$r['session_id']}"
    ];
}
foreach ($outRows as $r) {
    $kNo = !empty($r['kanban_no']) ? " (KBN {$r['kanban_no']})" : "";
    $allEvents[] = [
        'id'     => $r['kanban_session_id'],
        'time'   => $r['started_at'],
        'flow'   => 'OUT',
        'source' => 'kanban_usage',
        'qty'    => (int)$r['qty'],
        'ref'    => "Potong #{$r['kanban_session_id']}{$kNo}"
    ];
}

usort($allEvents, function($a, $b) {
    return strcmp($a['time'], $b['time']);
});

if (empty($allEvents)) {
    echo '<div style="padding: 16px; text-align: center; color: #64748b; font-size: 12px; font-weight: 600;">Belum ada riwayat pergerakan stok untuk part ini.</div>';
    exit;
}

// ── Filter Periode & Brought-Forward Calculation ─────────────────────────────
$priorEvents  = [];
$periodEvents = [];

if (!empty($periodStartDate)) {
    foreach ($allEvents as $ev) {
        if ($ev['time'] < $periodStartDate) {
            $priorEvents[] = $ev;
        } elseif (empty($periodEndDate) || $ev['time'] <= $periodEndDate) {
            $periodEvents[] = $ev;
        }
    }
} else {
    $periodEvents = $allEvents;
}

// Grand Total Akumulasi (untuk Kolom TOTAL / SAAT INI)
$grandNormal = 0; $grandSisa = 0; $grandKeluar = 0;
foreach ($allEvents as $e) {
    if ($e['flow'] === 'IN') {
        if ($e['source'] === 'direct') $grandNormal += $e['qty'];
        else $grandSisa += $e['qty'];
    } else {
        $grandKeluar += $e['qty'];
    }
}
$grandBalance = max(0, ($grandNormal + $grandSisa) - $grandKeluar);

// ── Pengelompokan Data Harian (Daily Grouping: 1 Tanggal = 1 Kolom) ──────────
$dailyMap = [];
foreach ($periodEvents as $ev) {
    $d = date('Y-m-d', strtotime($ev['time']));
    if (!isset($dailyMap[$d])) {
        $dailyMap[$d] = [
            'date'       => $d,
            'time_first' => $ev['time'],
            'mNorm'      => 0,
            'normBoxes'  => 0,
            'normList'   => [],
            'mSisa'      => 0,
            'sisaBoxes'  => 0,
            'sisaList'   => [],
            'mUsed'      => 0,
            'usedOrders' => 0,
            'usedList'   => []
        ];
    }
    
    $timeFormatted = date('H:i', strtotime($ev['time']));
    if ($ev['flow'] === 'IN') {
        if ($ev['source'] === 'direct') {
            $dailyMap[$d]['mNorm'] += $ev['qty'];
            $dailyMap[$d]['normBoxes']++;
            $dailyMap[$d]['normList'][] = "{$timeFormatted} - {$ev['ref']} (+" . number_format($ev['qty']) . " pcs)";
        } else {
            $dailyMap[$d]['mSisa'] += $ev['qty'];
            $dailyMap[$d]['sisaBoxes']++;
            $dailyMap[$d]['sisaList'][] = "{$timeFormatted} - {$ev['ref']} (+" . number_format($ev['qty']) . " pcs)";
        }
    } else {
        $dailyMap[$d]['mUsed'] += $ev['qty'];
        $dailyMap[$d]['usedOrders']++;
        $dailyMap[$d]['usedList'][] = "{$timeFormatted} - {$ev['ref']} (-" . number_format($ev['qty']) . " pcs)";
    }
}

ksort($dailyMap);
$allDays = array_values($dailyMap);
$totalDaysInPeriod = count($allDays);
$totalInPeriod = count($periodEvents);

// ── Chunking per 30 Hari Transaksi ───────────────────────────────────────────
$chunk = max(10, (int)($_GET['chunk'] ?? 30));
$hasMorePast   = false;
$remainingPast = 0;

$openingNormal = 0; $openingSisa = 0; $openingKeluar = 0;

// Hitung saldo awal dari transaksi sebelum periode (jika ada filter tanggal)
foreach ($priorEvents as $pe) {
    if ($pe['flow'] === 'IN') {
        if ($pe['source'] === 'direct') $openingNormal += $pe['qty'];
        else $openingSisa += $pe['qty'];
    } else {
        $openingKeluar += $pe['qty'];
    }
}

if ($totalDaysInPeriod > $chunk) {
    $hasMorePast   = true;
    $remainingPast = $totalDaysInPeriod - $chunk;
    
    // Ambil hari-hari lampau untuk diakumulasi ke Saldo Awal
    $olderDays = array_slice($allDays, 0, $remainingPast);
    $activeDays = array_slice($allDays, $remainingPast);

    foreach ($olderDays as $od) {
        $openingNormal += $od['mNorm'];
        $openingSisa   += $od['mSisa'];
        $openingKeluar += $od['mUsed'];
    }
} else {
    $activeDays = $allDays;
}

$openingBalance = max(0, ($openingNormal + $openingSisa) - $openingKeluar);
$hasOpeningCol  = ($hasMorePast || !empty($priorEvents) || $openingBalance > 0);

// Hitung Running Balance Harian untuk kolom tanggal yang ditampilkan
$runningBalance = $openingBalance;
$timelineCols   = [];

foreach ($activeDays as $idx => $day) {
    $mNorm = $day['mNorm'];
    $mSisa = $day['mSisa'];
    $mUsed = $day['mUsed'];
    $runningBalance = max(0, $runningBalance + $mNorm + $mSisa - $mUsed);

    $normTooltip = !empty($day['normList']) ? implode("\n", $day['normList']) : '';
    $sisaTooltip = !empty($day['sisaList']) ? implode("\n", $day['sisaList']) : '';
    $usedTooltip = !empty($day['usedList']) ? implode("\n", $day['usedList']) : '';

    $timelineCols[] = [
        'idx'         => $idx + 1,
        'date'        => $day['date'],
        'date_label'  => date('d M Y', strtotime($day['date'])),
        'is_today'    => ($day['date'] === date('Y-m-d')),
        'mNorm'       => $mNorm,
        'normBoxes'   => $day['normBoxes'],
        'normTooltip' => $normTooltip,
        'mSisa'       => $mSisa,
        'sisaBoxes'   => $day['sisaBoxes'],
        'sisaTooltip' => $sisaTooltip,
        'mUsed'       => $mUsed,
        'usedOrders'  => $day['usedOrders'],
        'usedTooltip' => $usedTooltip,
        'balance'     => $runningBalance
    ];
}

$partDisplayName = $partInfo['part_name'] ?? 'Part ' . $partCode;
$trackId = 'timeline-track-' . md5($partCode);
$nextChunk = $chunk + 30;
?>

<div style="background-color: #ffffff; border: 1px solid #cbd5e1; border-radius: 10px; padding: 12px 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); width: 100%; max-width: 100%; box-sizing: border-box; overflow: hidden;">
    
    <!-- Header Timeline -->
    <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 10px; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px;">
        <div style="display: flex; align-items: center; gap: 8px;">
            <span style="background-color: #2563eb; color: #ffffff; padding: 3px 8px; border-radius: 6px; font-size: 11px; font-weight: 800;">
                ⏱️ TIMELINE MUTASI
            </span>
            <span style="font-weight: 800; color: #1e293b; font-size: 12px; font-family: monospace;">
                <?= htmlspecialchars($partCode) ?>
            </span>
            <span style="color: #64748b; font-size: 12px; font-weight: 600;">
                (<?= htmlspecialchars($partDisplayName) ?>)
            </span>
        </div>
        
        <div style="display: flex; align-items: center; gap: 10px; font-size: 11px;">
            <span style="background-color: #f1f5f9; color: #475569; padding: 2px 8px; border-radius: 9999px; font-weight: 700; border: 1px solid #cbd5e1;">
                Menampilkan: <b><?= count($timelineCols) ?></b> Hari (<?= $totalInPeriod ?> Mutasi)
                <?php if ($period === 'tahunan'): ?>
                    <span style="color: #2563eb;">(Tahun Ini)</span>
                <?php elseif ($period === 'semua'): ?>
                    <span style="color: #475569;">(Semua Periode)</span>
                <?php endif; ?>
            </span>

            <!-- Tombol Navigasi Scroll Geser Kiri / Kanan -->
            <div style="display: inline-flex; align-items: center; gap: 4px;">
                <button type="button" 
                        onclick="scrollTimelineTrack('<?= $trackId ?>', -260)" 
                        style="padding: 3px 8px; background-color: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; font-weight: 800; font-size: 11px; color: #334155; cursor: pointer; display: inline-flex; align-items: center; gap: 2px; transition: all 0.15s;"
                        title="Geser Kiri (Bisa juga putar roda mouse)">
                    ◄ Geser
                </button>
                <button type="button" 
                        onclick="scrollTimelineTrack('<?= $trackId ?>', 260)" 
                        style="padding: 3px 8px; background-color: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; font-weight: 800; font-size: 11px; color: #334155; cursor: pointer; display: inline-flex; align-items: center; gap: 2px; transition: all 0.15s;"
                        title="Geser Kanan (Bisa juga putar roda mouse)">
                    Geser ►
                </button>
            </div>

            <span style="color: #64748b; font-size: 10px; font-weight: 600;">
                (Putar roda mouse / tarik tabel)
            </span>
        </div>
    </div>

    <style>
    #<?= $trackId ?>::-webkit-scrollbar {
        height: 10px !important;
    }
    #<?= $trackId ?>::-webkit-scrollbar-track {
        background: #f1f5f9 !important;
        border-radius: 6px !important;
    }
    #<?= $trackId ?>::-webkit-scrollbar-thumb {
        background: #94a3b8 !important;
        border-radius: 6px !important;
        border: 2px solid #f1f5f9 !important;
    }
    #<?= $trackId ?>::-webkit-scrollbar-thumb:hover {
        background: #64748b !important;
    }
    </style>

    <!-- Tabel Matriks Horizontal dengan Dual Sticky Columns & Chunking -->
    <div id="<?= $trackId ?>" 
         class="timeline-scroll-track" 
         data-part="<?= htmlspecialchars($partCode) ?>"
         data-chunk="<?= $chunk ?>"
         data-has-more="<?= $hasMorePast ? '1' : '0' ?>"
         data-next-chunk="<?= $nextChunk ?>"
         style="overflow-x: auto; width: 100%; max-width: 100%; box-sizing: border-box; display: block; border: 1px solid #cbd5e1; border-radius: 8px; background-color: #ffffff; cursor: grab; user-select: none; -webkit-overflow-scrolling: touch; scrollbar-width: auto; scrollbar-color: #94a3b8 #f1f5f9;">
        
        <table style="border-collapse: collapse; font-size: 11px; white-space: nowrap; width: max-content; min-width: 100%;">
            <tbody>
                
                <!-- Baris 1: Header (Waktu & Tanggal Transaksi) -->
                <tr style="background-color: #f8fafc; border-bottom: 2px solid #cbd5e1;">
                    <!-- Kolom 1 (Fix: left 0) -->
                    <th style="position: sticky; left: 0; z-index: 35; background-color: #f1f5f9; padding: 10px 14px; text-align: left; font-weight: 800; color: #1e293b; width: 165px; min-width: 165px; max-width: 165px; border-right: 1px solid #cbd5e1;">
                        KATEGORI PERGERAKAN
                    </th>

                    <!-- Kolom 2 (Fix: left 165px - Rekap Total & Stok Saat Ini) -->
                    <th style="position: sticky; left: 165px; z-index: 35; background-color: #dcfce7; padding: 10px 14px; text-align: center; color: #166534; font-weight: 800; width: 145px; min-width: 145px; max-width: 145px; border-right: 2px solid #cbd5e1; box-shadow: 4px 0 8px -2px rgba(0,0,0,0.1);">
                        TOTAL / SAAT INI
                    </th>

                    <!-- Kolom Tombol: Muat Transaksi Lebih Lama (Jika Masih Ada Data Lampau) -->
                    <?php if ($hasMorePast): ?>
                        <th style="padding: 6px 12px; text-align: center; background-color: #fef3c7; border-right: 1px solid #fde68a; min-width: 140px;">
                            <button type="button" 
                                     class="btn-timeline-load-more"
                                     onclick="loadMoreTimelineChunk('<?= htmlspecialchars($partCode, ENT_QUOTES) ?>', <?= $nextChunk ?>, this)"
                                     style="padding: 4px 10px; background-color: #d97706; color: #ffffff; border: none; border-radius: 6px; font-weight: 800; font-size: 10px; cursor: pointer; display: inline-flex; flex-direction: column; align-items: center; gap: 2px; box-shadow: 0 1px 2px rgba(217,119,6,0.3);">
                                <span>◄ Muat 30 Hari Sebelumnya</span>
                                <span style="font-size: 9px; opacity: 0.9; font-weight: 600;">(Sisa <?= number_format($remainingPast) ?> Hari)</span>
                            </button>
                        </th>
                    <?php endif; ?>

                    <!-- Kolom Saldo Awal (Brought Forward dari data sebelumnya) -->
                    <?php if ($hasOpeningCol): ?>
                        <th style="padding: 8px 14px; text-align: center; background-color: #f1f5f9; border-right: 2px solid #cbd5e1; color: #475569; font-weight: 800; min-width: 130px;">
                            <div style="font-size: 11px; font-weight: 800; color: #334155;">SALDO AWAL</div>
                            <div style="font-size: 9px; color: #64748b; font-weight: 600;">(Bawaan Transaksi Lalu)</div>
                        </th>
                    <?php endif; ?>

                    <!-- Kolom Tanggal Harian (Chronological) -->
                    <?php foreach ($timelineCols as $col): ?>
                        <th style="padding: 8px 14px; text-align: center; border-right: 1px solid #e2e8f0; font-weight: 700; color: #334155; min-width: 130px; background-color: <?= $col['is_today'] ? '#eff6ff' : '#f8fafc' ?>;">
                            <div style="font-size: 11px; font-weight: 800; color: #1e293b;"><?= $col['date_label'] ?></div>
                            <?php if ($col['is_today']): ?>
                                <div style="font-size: 9px; color: #2563eb; font-weight: 800; margin-top: 1px;">(Hari Ini)</div>
                            <?php else: ?>
                                <div style="font-size: 9px; color: #64748b; font-weight: 600; margin-top: 1px;">(<?= date('D', strtotime($col['date'])) ?>)</div>
                            <?php endif; ?>
                        </th>
                    <?php endforeach; ?>
                </tr>

                <!-- Baris 2: Masuk Normal -->
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <!-- Kolom 1 (Fix: left 0) -->
                    <td style="position: sticky; left: 0; z-index: 25; background-color: #ffffff; padding: 9px 14px; font-weight: 800; color: #0284c7; width: 165px; min-width: 165px; max-width: 165px; border-right: 1px solid #cbd5e1;">
                        📥 Masuk Normal
                    </td>

                    <!-- Kolom 2 (Fix: left 165px - Grand Total Masuk Normal) -->
                    <td style="position: sticky; left: 165px; z-index: 25; background-color: #f0f9ff; padding: 9px 14px; text-align: right; font-family: monospace; font-weight: 900; color: #0284c7; width: 145px; min-width: 145px; max-width: 145px; border-right: 2px solid #cbd5e1; box-shadow: 4px 0 8px -2px rgba(0,0,0,0.1);">
                        +<?= number_format($grandNormal) ?> pcs
                    </td>

                    <?php if ($hasMorePast): ?>
                        <td style="background-color: #fffbeb; border-right: 1px solid #fde68a;"></td>
                    <?php endif; ?>

                    <?php if ($hasOpeningCol): ?>
                        <td style="padding: 9px 14px; text-align: right; font-family: monospace; font-weight: 700; color: #0284c7; background-color: #f8fafc; border-right: 2px solid #cbd5e1;">
                            <?= ($openingNormal > 0) ? '+' . number_format($openingNormal) . ' pcs' : '-' ?>
                        </td>
                    <?php endif; ?>

                    <!-- Akumulasi Masuk Normal per Tanggal -->
                    <?php foreach ($timelineCols as $col): ?>
                        <?php if ($col['mNorm'] > 0): ?>
                            <td style="padding: 9px 14px; text-align: right; font-family: monospace; font-weight: 800; color: #0284c7; background-color: #f0f9ff; border-right: 1px solid #e2e8f0; cursor: help;"
                                title="<?= htmlspecialchars($col['normTooltip']) ?>">
                                <div>+<?= number_format($col['mNorm']) ?> pcs</div>
                                <div style="font-size: 9px; color: #0369a1; font-weight: 700; font-family: sans-serif; margin-top: 1px;"><?= $col['normBoxes'] ?> Box ℹ️</div>
                            </td>
                        <?php else: ?>
                            <td style="padding: 9px 14px; text-align: center; color: #cbd5e1; border-right: 1px solid #e2e8f0;">-</td>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tr>

                <!-- Baris 3: Sisa Kanban -->
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <!-- Kolom 1 (Fix: left 0) -->
                    <td style="position: sticky; left: 0; z-index: 25; background-color: #ffffff; padding: 9px 14px; font-weight: 800; color: #b45309; width: 165px; min-width: 165px; max-width: 165px; border-right: 1px solid #cbd5e1;">
                        🔄 Sisa Kanban
                    </td>

                    <!-- Kolom 2 (Fix: left 165px - Grand Total Sisa Kanban) -->
                    <td style="position: sticky; left: 165px; z-index: 25; background-color: #fffbeb; padding: 9px 14px; text-align: right; font-family: monospace; font-weight: 900; color: #b45309; width: 145px; min-width: 145px; max-width: 145px; border-right: 2px solid #cbd5e1; box-shadow: 4px 0 8px -2px rgba(0,0,0,0.1);">
                        <?= ($grandSisa > 0) ? '+' . number_format($grandSisa) . ' pcs' : '0 pcs' ?>
                    </td>

                    <?php if ($hasMorePast): ?>
                        <td style="background-color: #fffbeb; border-right: 1px solid #fde68a;"></td>
                    <?php endif; ?>

                    <?php if ($hasOpeningCol): ?>
                        <td style="padding: 9px 14px; text-align: right; font-family: monospace; font-weight: 700; color: #b45309; background-color: #f8fafc; border-right: 2px solid #cbd5e1;">
                            <?= ($openingSisa > 0) ? '+' . number_format($openingSisa) . ' pcs' : '-' ?>
                        </td>
                    <?php endif; ?>

                    <!-- Akumulasi Sisa Kanban per Tanggal -->
                    <?php foreach ($timelineCols as $col): ?>
                        <?php if ($col['mSisa'] > 0): ?>
                            <td style="padding: 9px 14px; text-align: right; font-family: monospace; font-weight: 800; color: #b45309; background-color: #fffbeb; border-right: 1px solid #e2e8f0; cursor: help;"
                                title="<?= htmlspecialchars($col['sisaTooltip']) ?>">
                                <div>+<?= number_format($col['mSisa']) ?> pcs</div>
                                <div style="font-size: 9px; color: #b45309; font-weight: 700; font-family: sans-serif; margin-top: 1px;"><?= $col['sisaBoxes'] ?> Box ℹ️</div>
                            </td>
                        <?php else: ?>
                            <td style="padding: 9px 14px; text-align: center; color: #cbd5e1; border-right: 1px solid #e2e8f0;">-</td>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tr>

                <!-- Baris 4: Terpakai Kanban -->
                <tr style="border-bottom: 2px solid #cbd5e1;">
                    <!-- Kolom 1 (Fix: left 0) -->
                    <td style="position: sticky; left: 0; z-index: 25; background-color: #ffffff; padding: 9px 14px; font-weight: 800; color: #7e22ce; width: 165px; min-width: 165px; max-width: 165px; border-right: 1px solid #cbd5e1;">
                        📤 Terpakai Kanban
                    </td>

                    <!-- Kolom 2 (Fix: left 165px - Grand Total Terpakai Kanban) -->
                    <td style="position: sticky; left: 165px; z-index: 25; background-color: #faf5ff; padding: 9px 14px; text-align: right; font-family: monospace; font-weight: 900; color: #7e22ce; width: 145px; min-width: 145px; max-width: 145px; border-right: 2px solid #cbd5e1; box-shadow: 4px 0 8px -2px rgba(0,0,0,0.1);">
                        <?= ($grandKeluar > 0) ? '-' . number_format($grandKeluar) . ' pcs' : '0 pcs' ?>
                    </td>

                    <?php if ($hasMorePast): ?>
                        <td style="background-color: #fffbeb; border-right: 1px solid #fde68a;"></td>
                    <?php endif; ?>

                    <?php if ($hasOpeningCol): ?>
                        <td style="padding: 9px 14px; text-align: right; font-family: monospace; font-weight: 700; color: #7e22ce; background-color: #f8fafc; border-right: 2px solid #cbd5e1;">
                            <?= ($openingKeluar > 0) ? '-' . number_format($openingKeluar) . ' pcs' : '-' ?>
                        </td>
                    <?php endif; ?>

                    <!-- Akumulasi Pemotongan Kanban per Tanggal -->
                    <?php foreach ($timelineCols as $col): ?>
                        <?php if ($col['mUsed'] > 0): ?>
                            <td style="padding: 9px 14px; text-align: right; font-family: monospace; font-weight: 800; color: #7e22ce; background-color: #faf5ff; border-right: 1px solid #e2e8f0; cursor: help;"
                                title="<?= htmlspecialchars($col['usedTooltip']) ?>">
                                <div>-<?= number_format($col['mUsed']) ?> pcs</div>
                                <div style="font-size: 9px; color: #7e22ce; font-weight: 700; font-family: sans-serif; margin-top: 1px;"><?= $col['usedOrders'] ?> Order ℹ️</div>
                            </td>
                        <?php else: ?>
                            <td style="padding: 9px 14px; text-align: center; color: #cbd5e1; border-right: 1px solid #e2e8f0;">-</td>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tr>

                <!-- Baris 5: Sisa di Rak Saat Itu (Running Balance) -->
                <tr style="background-color: #f0fdf4;">
                    <!-- Kolom 1 (Fix: left 0) -->
                    <td style="position: sticky; left: 0; z-index: 25; background-color: #ecfdf5; padding: 11px 14px; font-weight: 900; color: #166534; width: 165px; min-width: 165px; max-width: 165px; border-right: 1px solid #cbd5e1;">
                        <div>📦 Sisa di Rak Saat Itu</div>
                        <div style="font-size: 9px; font-weight: 600; color: #64748b;">(Saldo Berjalan Fisik)</div>
                    </td>

                    <!-- Kolom 2 (Fix: left 165px - Saldo Akhir Saat Ini di Gudang) -->
                    <td style="position: sticky; left: 165px; z-index: 25; background-color: #dcfce7; padding: 11px 14px; text-align: right; font-family: monospace; font-weight: 900; color: #15803d; width: 145px; min-width: 145px; max-width: 145px; border-right: 2px solid #cbd5e1; box-shadow: 4px 0 8px -2px rgba(0,0,0,0.1);">
                        <div style="font-size: 13px;"><?= number_format($grandBalance) ?> pcs</div>
                        <div style="font-size: 9px; font-weight: 800; color: <?= ($grandBalance > 0) ? '#166534' : '#64748b' ?>;">
                            <?= ($grandBalance > 0) ? '✓ Siap Gudang' : 'Habis' ?>
                        </div>
                    </td>

                    <?php if ($hasMorePast): ?>
                        <td style="background-color: #fffbeb; border-right: 1px solid #fde68a;"></td>
                    <?php endif; ?>

                    <?php if ($hasOpeningCol): ?>
                        <td style="padding: 11px 14px; text-align: right; font-family: monospace; font-weight: 900; color: #334155; background-color: #f1f5f9; border-right: 2px solid #cbd5e1;">
                            <?= number_format($openingBalance) ?> <span style="font-size: 9px; font-weight: 700;">pcs</span>
                        </td>
                    <?php endif; ?>

                    <!-- Saldo Berjalan Harian per Tanggal -->
                    <?php foreach ($timelineCols as $col): ?>
                        <td style="padding: 11px 14px; text-align: right; font-family: monospace; font-weight: 900; color: #15803d; background-color: #f0fdf4; border-right: 1px solid #bbf7d0;">
                            <div style="font-size: 12px;"><?= number_format($col['balance']) ?> <span style="font-size: 9px; font-weight: 700;">pcs</span></div>
                            <div style="font-size: 9px; font-weight: 700; color: <?= ($col['balance'] > 0) ? '#166534' : '#94a3b8' ?>;">
                                <?= ($col['balance'] > 0) ? 'Sisa di Rak' : 'Habis' ?>
                            </div>
                        </td>
                    <?php endforeach; ?>
                </tr>

            </tbody>
        </table>
    </div>

</div>
