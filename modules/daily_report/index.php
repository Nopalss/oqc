<?php
/**
 * Laporan Inspeksi Harian - Index
 * Ringkasan aktivitas inspeksi dikelompokkan per hari.
 */
$breadcrumbCategory = "OPERASIONAL";
$pageTitle = "Laporan Inspeksi Harian";
$pageSubtitle = "Ringkasan aktivitas inspeksi per hari: label yang dipakai, total qty, dan temuan NG";

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

$pdo = getDB();

$startDate = sanitize($_GET['start_date'] ?? date('Y-m-01'));
$endDate   = sanitize($_GET['end_date']   ?? date('Y-m-d'));
$search    = sanitize($_GET['search']     ?? '');
$inspType  = sanitize($_GET['insp_type']  ?? '');

$limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT) ?: 15;
if (!in_array($limit, [10, 15, 25, 50, 100])) $limit = 15;
$page = max(1, filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1);

$rows       = [];
$totalItems = 0;
$totalPages = 1;
$summary    = ['hari_aktif' => 0, 'total_label' => 0, 'total_qty' => 0, 'total_ng' => 0];

if ($pdo) {
    try {
        $where  = ["DATE(ss.started_at) BETWEEN :sd AND :ed"];
        $params = [':sd' => $startDate, ':ed' => $endDate];

        if (!empty($search)) {
            $where[] = "(did.part_code LIKE :s1 OR did.lot_number LIKE :s2 OR isl.ref_number LIKE :s3 OR did.part_name LIKE :s4 OR ki.item_code LIKE :s5)";
            $like = '%' . $search . '%';
            $params[':s1'] = $like; $params[':s2'] = $like; $params[':s3'] = $like;
            $params[':s4'] = $like; $params[':s5'] = $like;
        }
        if (!empty($inspType)) {
            $where[] = "ss.inspection_type = :insp_type";
            $params[':insp_type'] = $inspType;
        }

        $whereStr = implode(' AND ', $where);

        $sql = "SELECT
                    DATE(ss.started_at) AS tanggal,
                    COUNT(DISTINCT ss.id) AS total_sesi,
                    COUNT(DISTINCT isl.id) AS total_label,
                    COALESCE(SUM(isl.qty), 0) AS total_qty,
                    COALESCE(SUM(ss.ng_count), 0) AS total_ng,
                    GROUP_CONCAT(DISTINCT ss.inspection_type ORDER BY ss.inspection_type SEPARATOR ',') AS jenis_inspeksi
                FROM inspection_sessions ss
                LEFT JOIN inspection_session_lots isl ON isl.inspection_session_id = ss.id
                LEFT JOIN daily_inspection_data did ON did.id = ss.did_id
                LEFT JOIN kanban_items ki ON ki.id = ss.kanban_item_id
                WHERE {$whereStr}
                  AND (isl.remarks IS NULL OR isl.remarks NOT LIKE '%Sisa Split Safety Stock%')
                GROUP BY DATE(ss.started_at)
                ORDER BY DATE(ss.started_at) DESC";

        $stAll = $pdo->prepare($sql);
        $stAll->execute($params);
        $allRows = $stAll->fetchAll(PDO::FETCH_ASSOC);

        foreach ($allRows as $r) {
            $summary['hari_aktif']++;
            $summary['total_label'] += (int)$r['total_label'];
            $summary['total_qty']   += (int)$r['total_qty'];
            $summary['total_ng']    += (int)$r['total_ng'];
        }

        $totalItems = count($allRows);
        $totalPages = max(1, ceil($totalItems / $limit));
        if ($page > $totalPages) $page = $totalPages;
        $rows = array_slice($allRows, ($page - 1) * $limit, $limit);

    } catch (PDOException $e) {
        set_flash('error', 'Gagal memuat laporan: ' . $e->getMessage());
    }
}

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen min-w-0 w-full overflow-x-hidden transition-all duration-300">

    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-4 space-y-3 min-w-0 w-full overflow-x-hidden">

        <?= render_flash() ?>

        <!-- 1. HEADER BANNER -->
        <div style="background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%); border-radius: 14px; padding: 14px 18px; color: #fff; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; box-shadow: 0 4px 16px rgba(15,23,42,0.18);">
            <div style="display: flex; align-items: center; gap: 14px;">
                <div style="background: rgba(59,130,246,0.22); border: 1px solid rgba(59,130,246,0.35); padding: 10px; border-radius: 12px; flex-shrink: 0;">
                    <svg style="width: 22px; height: 22px; color: #93c5fd;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                </div>
                <div>
                    <h1 style="font-size: 15px; font-weight: 900; margin: 0; line-height: 1.2; letter-spacing: -0.2px;">Laporan Inspeksi Harian</h1>
                    <p style="font-size: 11px; color: #94a3b8; font-weight: 500; margin-top: 3px;">Ringkasan aktivitas inspeksi per hari &bull; Label dipakai, total qty &amp; temuan NG</p>
                </div>
            </div>
            <a href="<?= base_url('modules/daily_report/index.php') ?>" style="background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.18); color: #e2e8f0; border-radius: 9px; padding: 7px 14px; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; text-decoration: none;">
                <svg style="width: 13px; height: 13px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                Refresh
            </a>
        </div>

        <!-- 2. KPI CARDS (Compact 1-row layout) -->
        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px;">

            <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
                    <span style="font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.4px;">Hari Aktif</span>
                    <div style="background: #eff6ff; padding: 4px; border-radius: 6px;">
                        <svg style="width: 13px; height: 13px; color: #2563eb;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    </div>
                </div>
                <div style="font-size: 20px; font-weight: 900; color: #0f172a; line-height: 1.1;"><?= number_format($summary['hari_aktif']) ?></div>
                <div style="font-size: 10px; color: #64748b; font-weight: 600; margin-top: 2px;">hari inspeksi</div>
            </div>

            <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
                    <span style="font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.4px;">Total Label</span>
                    <div style="background: #f0fdf4; padding: 4px; border-radius: 6px;">
                        <svg style="width: 13px; height: 13px; color: #16a34a;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A2 2 0 013 12V7a2 2 0 012-2z"/></svg>
                    </div>
                </div>
                <div style="font-size: 20px; font-weight: 900; color: #0f172a; line-height: 1.1;"><?= number_format($summary['total_label']) ?></div>
                <div style="font-size: 10px; color: #64748b; font-weight: 600; margin-top: 2px;">label / box</div>
            </div>

            <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
                    <span style="font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.4px;">Total Qty</span>
                    <div style="background: #f0f9ff; padding: 4px; border-radius: 6px;">
                        <svg style="width: 13px; height: 13px; color: #0284c7;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                    </div>
                </div>
                <div style="font-size: 20px; font-weight: 900; color: #0f172a; line-height: 1.1;"><?= number_format($summary['total_qty']) ?></div>
                <div style="font-size: 10px; color: #64748b; font-weight: 600; margin-top: 2px;">pcs diinspeksi</div>
            </div>

            <div style="background: <?= $summary['total_ng'] > 0 ? '#fff1f2' : '#fff' ?>; border: 1px solid <?= $summary['total_ng'] > 0 ? '#fecdd3' : '#e2e8f0' ?>; border-radius: 12px; padding: 10px 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
                    <span style="font-size: 10px; font-weight: 800; color: <?= $summary['total_ng'] > 0 ? '#9f1239' : '#64748b' ?>; text-transform: uppercase; letter-spacing: 0.4px;">Total NG</span>
                    <div style="background: <?= $summary['total_ng'] > 0 ? '#ffe4e6' : '#f8fafc' ?>; padding: 4px; border-radius: 6px;">
                        <svg style="width: 13px; height: 13px; color: <?= $summary['total_ng'] > 0 ? '#e11d48' : '#94a3b8' ?>;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    </div>
                </div>
                <div style="font-size: 20px; font-weight: 900; color: <?= $summary['total_ng'] > 0 ? '#9f1239' : '#0f172a' ?>; line-height: 1.1;"><?= number_format($summary['total_ng']) ?></div>
                <div style="font-size: 10px; color: <?= $summary['total_ng'] > 0 ? '#be123c' : '#64748b' ?>; font-weight: 600; margin-top: 2px;">temuan defect</div>
            </div>

        </div>

        <!-- 3. FILTER BAR -->
        <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px 14px; box-shadow: 0 1px 4px rgba(0,0,0,0.03);">
            <form action="<?= base_url('modules/daily_report/index.php') ?>" method="GET">
                <div style="display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-end;">
                    <div style="flex: 1 1 200px; min-width: 160px;">
                        <label style="font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.4px; display: block; margin-bottom: 4px;">Cari</label>
                        <div style="position: relative;">
                            <svg style="position: absolute; left: 9px; top: 50%; transform: translateY(-50%); width: 13px; height: 13px; color: #94a3b8;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0"/></svg>
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Part Code, Lot No, Ref No..."
                                   style="width: 100%; padding: 7px 10px 7px 30px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 12px; color: #1e293b; font-weight: 500; outline: none; box-sizing: border-box;">
                        </div>
                    </div>
                    <div style="flex: 0 1 150px; min-width: 130px;">
                        <label style="font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.4px; display: block; margin-bottom: 4px;">Jenis</label>
                        <select name="insp_type" onchange="this.form.submit()" style="width: 100%; padding: 7px 10px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 12px; color: #334155; font-weight: 600; outline: none;">
                            <option value="">Semua Tipe</option>
                            <option value="safety_stock" <?= $inspType === 'safety_stock' ? 'selected' : '' ?>>Safety Stock</option>
                            <option value="kanban" <?= $inspType === 'kanban' ? 'selected' : '' ?>>Kanban</option>
                        </select>
                    </div>
                    <div style="flex: 0 1 130px; min-width: 120px;">
                        <label style="font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.4px; display: block; margin-bottom: 4px;">Dari</label>
                        <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>" style="width: 100%; padding: 7px 8px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 11px; color: #334155; font-weight: 600; outline: none;">
                    </div>
                    <div style="flex: 0 1 130px; min-width: 120px;">
                        <label style="font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.4px; display: block; margin-bottom: 4px;">Sampai</label>
                        <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>" style="width: 100%; padding: 7px 8px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 11px; color: #334155; font-weight: 600; outline: none;">
                    </div>
                    <div style="display: flex; gap: 6px; align-items: flex-end; flex-shrink: 0;">
                        <button type="submit" style="padding: 7px 16px; background: #2563eb; color: #fff; font-weight: 700; font-size: 12px; border-radius: 8px; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 5px;">
                            <svg style="width: 13px; height: 13px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0"/></svg>
                            Cari
                        </button>
                        <a href="<?= base_url('modules/daily_report/index.php') ?>" style="padding: 7px 12px; background: #f1f5f9; color: #64748b; font-weight: 700; font-size: 12px; border-radius: 8px; border: 1px solid #cbd5e1; text-decoration: none;">Reset</a>
                    </div>
                </div>
            </form>
        </div>

        <!-- 4. MAIN TABLE -->
        <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,0.04);">
            <div style="padding: 12px 16px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <h2 style="font-size: 12px; font-weight: 800; color: #334155; text-transform: uppercase; letter-spacing: 0.5px; margin: 0;">Ringkasan Per Hari</h2>
                    <span style="padding: 2px 9px; background: #dbeafe; color: #1d4ed8; font-size: 11px; font-weight: 800; border-radius: 9999px;"><?= number_format($totalItems) ?> Hari</span>
                </div>
                <form method="GET" action="<?= base_url('modules/daily_report/index.php') ?>" style="display: flex; align-items: center; gap: 6px; font-size: 12px;">
                    <input type="hidden" name="start_date" value="<?= htmlspecialchars($startDate) ?>">
                    <input type="hidden" name="end_date" value="<?= htmlspecialchars($endDate) ?>">
                    <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                    <input type="hidden" name="insp_type" value="<?= htmlspecialchars($inspType) ?>">
                    <span style="color: #64748b; font-weight: 500;">Tampilkan:</span>
                    <select name="limit" onchange="this.form.submit()" style="padding: 4px 8px; background: #fff; border: 1px solid #cbd5e1; border-radius: 6px; font-weight: 700; color: #334155; outline: none;">
                        <?php foreach ([10, 15, 25, 50, 100] as $lv): ?>
                            <option value="<?= $lv ?>" <?= ($limit == $lv) ? 'selected' : '' ?>><?= $lv ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>

            <div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                <table style="width: 100%; border-collapse: collapse; font-size: 12px; color: #334155; min-width: 600px;">
                    <thead style="background: #fff; border-bottom: 2px solid #e2e8f0; font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.4px;">
                        <tr>
                            <th style="padding: 10px 14px; width: 40px; text-align: center;">No</th>
                            <th style="padding: 10px 14px; min-width: 130px;">Tanggal</th>
                            <th style="padding: 10px 14px; text-align: center; width: 80px;">Sesi</th>
                            <th style="padding: 10px 14px; text-align: center; width: 100px;">Total Label</th>
                            <th style="padding: 10px 14px; text-align: center; width: 110px;">Total Qty</th>
                            <th style="padding: 10px 14px; text-align: center; width: 90px;">Total NG</th>
                            <th style="padding: 10px 14px; min-width: 150px;">Jenis Inspeksi</th>
                            <th style="padding: 10px 14px; text-align: right; width: 80px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr>
                                <td colspan="8" style="padding: 52px 24px; text-align: center;">
                                    <div style="display: flex; flex-direction: column; align-items: center; gap: 8px;">
                                        <svg style="width: 44px; height: 44px; color: #cbd5e1;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                        </svg>
                                        <span style="font-weight: 800; color: #334155; font-size: 14px;">Belum Ada Data Inspeksi</span>
                                        <span style="font-size: 12px; color: #64748b; max-width: 360px; line-height: 1.5; text-align: center;">Tidak ada aktivitas inspeksi pada rentang tanggal yang dipilih.</span>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rows as $idx => $row): ?>
                                <?php
                                    $rowNum  = (($page - 1) * $limit) + $idx + 1;
                                    $tgl     = $row['tanggal'];
                                    $totalNg = (int)$row['total_ng'];
                                    $types   = array_filter(explode(',', $row['jenis_inspeksi'] ?? ''));
                                    $isWeek  = in_array(date('N', strtotime($tgl)), [6, 7]);
                                ?>
                                <tr style="border-bottom: 1px solid #f1f5f9;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                                    <td style="padding: 11px 14px; text-align: center; font-weight: 700; color: #94a3b8; font-size: 11px;"><?= $rowNum ?></td>
                                    <td style="padding: 11px 14px;">
                                        <div style="font-weight: 800; color: #1e293b; font-size: 13px;"><?= date('d M Y', strtotime($tgl)) ?></div>
                                        <div style="font-size: 10px; color: <?= $isWeek ? '#dc2626' : '#64748b' ?>; font-weight: 600; margin-top: 1px;"><?= date('l', strtotime($tgl)) ?></div>
                                    </td>
                                    <td style="padding: 11px 14px; text-align: center;">
                                        <span style="font-weight: 800; color: #0f172a; font-size: 13px;"><?= (int)$row['total_sesi'] ?></span>
                                        <div style="font-size: 10px; color: #94a3b8; font-weight: 600;">sesi</div>
                                    </td>
                                    <td style="padding: 11px 14px; text-align: center;">
                                        <span style="display: inline-block; background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; font-weight: 800; font-size: 13px; padding: 2px 12px; border-radius: 9999px;"><?= number_format((int)$row['total_label']) ?></span>
                                        <div style="font-size: 10px; color: #94a3b8; font-weight: 600; margin-top: 2px;">label</div>
                                    </td>
                                    <td style="padding: 11px 14px; text-align: center;">
                                        <span style="font-family: monospace; font-weight: 900; font-size: 13px; color: #0f172a;"><?= number_format((int)$row['total_qty']) ?></span>
                                        <div style="font-size: 10px; color: #94a3b8; font-weight: 600;">pcs</div>
                                    </td>
                                    <td style="padding: 11px 14px; text-align: center;">
                                        <?php if ($totalNg > 0): ?>
                                            <span style="display: inline-block; background: #ffe4e6; color: #be123c; border: 1px solid #fecdd3; font-weight: 900; font-size: 13px; padding: 2px 12px; border-radius: 9999px;"><?= $totalNg ?></span>
                                            <div style="font-size: 10px; color: #be123c; font-weight: 600; margin-top: 2px;">defect</div>
                                        <?php else: ?>
                                            <span style="font-size: 12px; color: #22c55e; font-weight: 700;">OK</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 11px 14px;">
                                        <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                                            <?php foreach ($types as $t): ?>
                                                <?php if ($t === 'safety_stock'): ?>
                                                    <span style="font-size: 10px; font-weight: 700; color: #0369a1; background: #e0f2fe; border: 1px solid #bae6fd; padding: 2px 8px; border-radius: 9999px;">Safety Stock</span>
                                                <?php elseif ($t === 'kanban'): ?>
                                                    <span style="font-size: 10px; font-weight: 700; color: #7c3aed; background: #ede9fe; border: 1px solid #c4b5fd; padding: 2px 8px; border-radius: 9999px;">Kanban</span>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                    <td style="padding: 11px 14px; text-align: right;">
                                        <a href="<?= base_url('modules/daily_report/detail.php?date=' . urlencode($tgl)) ?>"
                                           style="display: inline-flex; align-items: center; gap: 5px; padding: 6px 12px; background: #eff6ff; color: #1d4ed8; font-weight: 700; font-size: 11px; border-radius: 8px; border: 1px solid #bfdbfe; text-decoration: none; white-space: nowrap;"
                                           onmouseover="this.style.background='#dbeafe'" onmouseout="this.style.background='#eff6ff'">
                                            <svg style="width: 13px; height: 13px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                            </svg>
                                            Detail
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): echo render_pagination($page, $totalPages, $totalItems, $limit); endif; ?>
        </div>

    </main>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>

