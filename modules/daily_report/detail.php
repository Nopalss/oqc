<?php
/**
 * Laporan Inspeksi Harian - Detail
 * Rincian label/box yang dipakai pada satu hari, dipisah Safety Stock vs Kanban.
 * Tiap label yang ada NG akan menampilkan detail defect inline.
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

$pdo = getDB();

if (isset($_GET['action']) && $_GET['action'] === 'get_lot_history' && $pdo) {
    header('Content-Type: application/json');
    $pCode   = sanitize($_GET['part_code'] ?? '');
    $lNum    = sanitize($_GET['lot_number'] ?? '');
    $kbId    = (int)($_GET['kanban_item_id'] ?? 0);
    $currSid = (int)($_GET['session_id'] ?? 0);

    $history = [];
    try {
        if ($kbId > 0) {
            $stmtH = $pdo->prepare("
                SELECT s.id, s.started_at, s.status, s.samples_checked, s.sample_size, s.ng_count, s.reject_number,
                       s.inspection_type, s.total_scanned_qty, s.parent_session_id, s.reinspection_notes, s.is_reinspection,
                       did.lot_number, did.cavity, did.pic as did_pic,
                       k.kanban_no, k.customer,
                       COALESCE(NULLIF(mp.part_name, ''), did.part_name) as display_part_name,
                       u.name as inspector_name
                FROM inspection_sessions s
                JOIN daily_inspection_data did ON did.id = s.did_id
                LEFT JOIN kanban_items k ON k.id = s.kanban_item_id
                LEFT JOIN master_parts mp ON (mp.id = s.part_id OR UPPER(mp.part_code) = UPPER(did.part_code))
                LEFT JOIN users u ON u.id = s.inspector_id
                WHERE (s.kanban_item_id = :kb_id OR s.id = :curr_sid1 OR s.parent_session_id = :curr_sid2)
                  AND NOT EXISTS (
                      SELECT 1 FROM inspection_session_lots isl_chk 
                      WHERE isl_chk.inspection_session_id = s.id 
                        AND isl_chk.remarks LIKE '%Sisa Split Safety Stock%'
                  )
                ORDER BY s.id DESC
            ");
            $stmtH->execute([
                ':kb_id'     => $kbId,
                ':curr_sid1' => $currSid,
                ':curr_sid2' => $currSid
            ]);
            $history = $stmtH->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmtH = $pdo->prepare("
                SELECT s.id, s.started_at, s.status, s.samples_checked, s.sample_size, s.ng_count, s.reject_number,
                       s.inspection_type, s.total_scanned_qty, s.parent_session_id, s.reinspection_notes, s.is_reinspection,
                       did.lot_number, did.cavity, did.pic as did_pic,
                       k.kanban_no, k.customer,
                       COALESCE(NULLIF(mp.part_name, ''), did.part_name) as display_part_name,
                       u.name as inspector_name
                FROM inspection_sessions s
                JOIN daily_inspection_data did ON did.id = s.did_id
                LEFT JOIN kanban_items k ON k.id = s.kanban_item_id
                LEFT JOIN master_parts mp ON (mp.id = s.part_id OR UPPER(mp.part_code) = UPPER(did.part_code))
                LEFT JOIN users u ON u.id = s.inspector_id
                WHERE UPPER(did.part_code) = UPPER(:pcode)
                  AND (did.lot_number = :lnum OR s.id = :curr_sid1 OR s.parent_session_id = :curr_sid2)
                  AND NOT EXISTS (
                      SELECT 1 FROM inspection_session_lots isl_chk 
                      WHERE isl_chk.inspection_session_id = s.id 
                        AND isl_chk.remarks LIKE '%Sisa Split Safety Stock%'
                  )
                ORDER BY s.id DESC
            ");
            $stmtH->execute([
                ':pcode'     => $pCode,
                ':lnum'      => $lNum,
                ':curr_sid1' => $currSid,
                ':curr_sid2' => $currSid
            ]);
            $history = $stmtH->fetchAll(PDO::FETCH_ASSOC);
        }

        foreach ($history as &$item) {
            $stmtDef = $pdo->prepare("
                SELECT dt.name as defect_name, SUM(n.qty_ng) as total_qty_ng
                FROM inspection_ng_records n
                JOIN inspection_samples sp ON sp.id = n.inspection_sample_id
                JOIN defect_types dt ON dt.id = n.defect_type_id
                WHERE sp.inspection_session_id = :sid AND (n.is_cancelled IS NULL OR n.is_cancelled = 0)
                GROUP BY n.defect_type_id, dt.name
                ORDER BY total_qty_ng DESC
            ");
            $stmtDef->execute([':sid' => $item['id']]);
            $item['defects'] = $stmtDef->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($item);

    } catch (PDOException $e) {}

    echo json_encode(['success' => true, 'history' => $history]);
    exit;
}

$dateParam = sanitize($_GET['date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateParam)) {
    $dateParam = date('Y-m-d');
}

$rowsSS     = []; // Safety Stock rows
$rowsKanban = []; // Kanban rows
$ngMap      = []; // session_id => [ng records]
$daySummary = ['total_label' => 0, 'total_qty' => 0, 'total_ng' => 0, 'total_sesi' => 0];

if ($pdo) {
    try {
        // ── Fetch all labels (session_lots) for this date ──────────────────────
        $stmtLots = $pdo->prepare("
            SELECT
                isl.id        AS lot_id,
                isl.ref_number,
                isl.lot_number,
                isl.qty,
                isl.created_at AS scanned_at,
                ss.id          AS session_id,
                ss.inspection_type,
                ss.ng_count,
                ss.reject_number,
                ss.status      AS session_status,
                ss.started_at,
                ss.closed_at,
                ss.is_reinspection,
                ss.parent_session_id,
                ss.kanban_item_id,
                COALESCE(did.part_code, ki.item_code, '-')         AS part_code,
                COALESCE(did.part_name, ki.item_description, '-')  AS part_name,
                COALESCE(ki.kanban_no, '-')                        AS kanban_no,
                COALESCE(ki.customer, '-')                         AS customer,
                COALESCE(u.name, did.pic, 'Inspector QC')          AS inspector_name
            FROM inspection_session_lots isl
            JOIN inspection_sessions ss ON ss.id = isl.inspection_session_id
            LEFT JOIN daily_inspection_data did ON did.id = ss.did_id
            LEFT JOIN kanban_items ki ON ki.id = ss.kanban_item_id
            LEFT JOIN users u ON u.id = ss.inspector_id
            WHERE DATE(ss.started_at) = :tgl
              AND (isl.remarks IS NULL OR isl.remarks NOT LIKE '%Sisa Split Safety Stock%')
            ORDER BY ss.inspection_type DESC, isl.id ASC
        ");
        $stmtLots->execute([':tgl' => $dateParam]);
        $allLots = $stmtLots->fetchAll(PDO::FETCH_ASSOC);

        // Separate into two groups & collect session IDs with NG
        $sessionIdsWithNg = [];
        $uniqueSessions   = [];
        $ssGrouped        = [];

        foreach ($allLots as $lot) {
            $daySummary['total_label']++;
            $daySummary['total_ng']  += (int)$lot['ng_count'];
            $uniqueSessions[$lot['session_id']] = true;
            if ((int)$lot['ng_count'] > 0) {
                $sessionIdsWithNg[$lot['session_id']] = true;
            }
            if ($lot['inspection_type'] === 'safety_stock') {
                $groupKey = strtoupper($lot['part_code']) . '___' . strtoupper($lot['lot_number']) . '___' . strtoupper($lot['ref_number'] ?? '');
                if (!isset($ssGrouped[$groupKey])) {
                    $ssGrouped[$groupKey] = $lot;
                    $ssGrouped[$groupKey]['box_count'] = 1;
                    $ssGrouped[$groupKey]['ref_numbers'] = !empty($lot['ref_number']) ? [$lot['ref_number']] : [];
                    $ssGrouped[$groupKey]['session_ids'] = [$lot['session_id']];
                    $ssGrouped[$groupKey]['session_qtys'] = [$lot['session_id'] => (int)$lot['qty']];
                } else {
                    if (!in_array($lot['session_id'], $ssGrouped[$groupKey]['session_ids'])) {
                        $ssGrouped[$groupKey]['session_ids'][] = $lot['session_id'];
                        $ssGrouped[$groupKey]['session_qtys'][$lot['session_id']] = (int)$lot['qty'];
                    } else {
                        $ssGrouped[$groupKey]['session_qtys'][$lot['session_id']] += (int)$lot['qty'];
                    }
                    $ssGrouped[$groupKey]['box_count']++;
                    $ssGrouped[$groupKey]['ng_count'] += (int)$lot['ng_count'];
                    if (!empty($lot['ref_number']) && !in_array($lot['ref_number'], $ssGrouped[$groupKey]['ref_numbers'])) {
                        $ssGrouped[$groupKey]['ref_numbers'][] = $lot['ref_number'];
                    }
                    // Update latest session details if this lot has higher session ID
                    if ($lot['session_id'] > $ssGrouped[$groupKey]['session_id']) {
                        $ssGrouped[$groupKey]['session_id']     = $lot['session_id'];
                        $ssGrouped[$groupKey]['session_status'] = $lot['session_status'];
                        $ssGrouped[$groupKey]['inspector_name'] = $lot['inspector_name'];
                        $ssGrouped[$groupKey]['started_at']     = $lot['started_at'];
                    }
                }
            } else {
                $rowsKanban[] = $lot;
            }
        }

        foreach ($ssGrouped as $sGroup) {
            $sGroup['ref_number'] = implode(', ', array_filter($sGroup['ref_numbers']));
            if (empty($sGroup['ref_number'])) {
                $sGroup['ref_number'] = '-';
            }
            
            // If multiple sessions exist for this lot (re-inspections), use latest session's Qty as physical Qty
            if (count($sGroup['session_ids']) > 1) {
                $latestSessionId = max($sGroup['session_ids']);
                $sGroup['qty'] = $sGroup['session_qtys'][$latestSessionId] ?? reset($sGroup['session_qtys']);
                $sGroup['is_reinspection'] = 1;
                $sGroup['reinspection_round'] = count($sGroup['session_ids']) - 1;
            } else {
                $sGroup['qty'] = reset($sGroup['session_qtys']);
            }

            $rowsSS[] = $sGroup;
        }

        // Calculate total_qty from deduplicated physical lot Qty
        $calculatedTotalQty = 0;
        foreach ($rowsSS as $ssR) {
            $calculatedTotalQty += (int)$ssR['qty'];
        }
        foreach ($rowsKanban as $kbR) {
            $calculatedTotalQty += (int)$kbR['qty'];
        }
        $daySummary['total_qty']  = $calculatedTotalQty;
        $daySummary['total_sesi'] = count($uniqueSessions);

        // ── Fetch NG records for sessions that have NG ─────────────────────────
        if (!empty($sessionIdsWithNg)) {
            $ngIds    = array_keys($sessionIdsWithNg);
            $inQuery  = implode(',', array_map('intval', $ngIds));
            $stmtNg   = $pdo->prepare("
                SELECT
                    ng.id,
                    ng.qty_ng,
                    ng.remark,
                    sm.inspection_session_id,
                    dt.name AS defect_type_name
                FROM inspection_ng_records ng
                JOIN inspection_samples sm ON sm.id = ng.inspection_sample_id
                LEFT JOIN defect_types dt ON dt.id = ng.defect_type_id
                WHERE sm.inspection_session_id IN ({$inQuery})
                ORDER BY ng.id ASC
            ");
            $stmtNg->execute();
            foreach ($stmtNg->fetchAll(PDO::FETCH_ASSOC) as $ng) {
                $ngMap[(int)$ng['inspection_session_id']][] = $ng;
            }
        }

    } catch (PDOException $e) {
        set_flash('error', 'Gagal memuat rincian: ' . $e->getMessage());
    }
}

$pageTitle = "Detail Laporan " . date('d M Y', strtotime($dateParam));

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen min-w-0 w-full overflow-x-hidden transition-all duration-300">

    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-4 space-y-4 min-w-0 w-full overflow-x-hidden">

        <?= render_flash() ?>

        <!-- 1. HEADER BANNER -->
        <div style="background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%); border-radius: 14px; padding: 14px 18px; color: #fff; box-shadow: 0 4px 16px rgba(15,23,42,0.18);">
            <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
                <div style="display: flex; align-items: center; gap: 14px;">
                    <div style="background: rgba(59,130,246,0.22); border: 1px solid rgba(59,130,246,0.35); padding: 10px; border-radius: 12px; flex-shrink: 0;">
                        <svg style="width: 22px; height: 22px; color: #93c5fd;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                        </svg>
                    </div>
                    <div>
                        <h1 style="font-size: 15px; font-weight: 900; margin: 0; line-height: 1.2;">Rincian Inspeksi</h1>
                        <p style="font-size: 12px; color: #93c5fd; font-weight: 700; margin-top: 2px;"><?= date('l, d F Y', strtotime($dateParam)) ?></p>
                    </div>
                </div>
                <a href="<?= base_url('modules/daily_report/index.php') ?>" style="background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.18); color: #e2e8f0; border-radius: 9px; padding: 7px 14px; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; text-decoration: none;">
                    <svg style="width: 13px; height: 13px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                    </svg>
                    Kembali
                </a>
            </div>

            <!-- Day Summary Stats -->
            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; margin-top: 14px;">
                <div style="background: rgba(255,255,255,0.08); border-radius: 10px; padding: 10px 12px;">
                    <div style="font-size: 10px; color: #94a3b8; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px;">Total Sesi</div>
                    <div style="font-size: 20px; font-weight: 900; color: #fff; margin-top: 2px;"><?= $daySummary['total_sesi'] ?></div>
                </div>
                <div style="background: rgba(255,255,255,0.08); border-radius: 10px; padding: 10px 12px;">
                    <div style="font-size: 10px; color: #94a3b8; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px;">Total Label</div>
                    <div style="font-size: 20px; font-weight: 900; color: #fff; margin-top: 2px;"><?= number_format($daySummary['total_label']) ?></div>
                </div>
                <div style="background: rgba(255,255,255,0.08); border-radius: 10px; padding: 10px 12px;">
                    <div style="font-size: 10px; color: #94a3b8; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px;">Total Qty</div>
                    <div style="font-size: 20px; font-weight: 900; color: #fff; margin-top: 2px;"><?= number_format($daySummary['total_qty']) ?> <span style="font-size: 11px; font-weight: 600; color: #94a3b8;">pcs</span></div>
                </div>
                <div style="background: <?= $daySummary['total_ng'] > 0 ? 'rgba(239,68,68,0.2)' : 'rgba(255,255,255,0.08)' ?>; border: 1px solid <?= $daySummary['total_ng'] > 0 ? 'rgba(239,68,68,0.35)' : 'transparent' ?>; border-radius: 10px; padding: 10px 12px;">
                    <div style="font-size: 10px; color: <?= $daySummary['total_ng'] > 0 ? '#fca5a5' : '#94a3b8' ?>; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px;">Total NG</div>
                    <div style="font-size: 20px; font-weight: 900; color: <?= $daySummary['total_ng'] > 0 ? '#fca5a5' : '#fff' ?>; margin-top: 2px;"><?= $daySummary['total_ng'] ?> <?php if ($daySummary['total_ng'] === 0): ?><span style="font-size: 11px; font-weight: 600; color: #4ade80;">OK</span><?php endif; ?></div>
                </div>
            </div>
        </div>

        <!-- 2. SEARCH BAR -->
        <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 12px 14px; box-shadow: 0 1px 4px rgba(0,0,0,0.03);">
            <div style="position: relative; max-width: 440px;">
                <svg style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); width: 14px; height: 14px; color: #94a3b8; pointer-events: none;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0"/>
                </svg>
                <input id="dr-search" type="text" placeholder="Cari Ref Number, Lot No, Part Code, Kanban No, Customer..."
                       style="width: 100%; padding: 8px 12px 8px 32px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 9px; font-size: 12px; color: #1e293b; font-weight: 500; outline: none; box-sizing: border-box;">
            </div>
            <p style="font-size: 10px; color: #94a3b8; font-weight: 500; margin-top: 6px;">Filter realtime di kedua tabel sekaligus</p>
        </div>

        <?php if (empty($rowsSS) && empty($rowsKanban)): ?>
            <!-- EMPTY STATE -->
            <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 52px 24px; text-align: center; box-shadow: 0 1px 4px rgba(0,0,0,0.04);">
                <div style="display: flex; flex-direction: column; align-items: center; gap: 10px;">
                    <svg style="width: 48px; height: 48px; color: #cbd5e1;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                    </svg>
                    <span style="font-weight: 800; color: #334155; font-size: 15px;">Tidak Ada Aktivitas Inspeksi</span>
                    <span style="font-size: 12px; color: #64748b; max-width: 380px; line-height: 1.5;">Tidak ditemukan data inspeksi pada tanggal <b><?= date('d M Y', strtotime($dateParam)) ?></b>.</span>
                    <a href="<?= base_url('modules/daily_report/index.php') ?>" style="padding: 8px 18px; background: #2563eb; color: #fff; font-weight: 700; font-size: 12px; border-radius: 9px; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; margin-top: 4px;">
                        <svg style="width: 13px; height: 13px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                        Kembali ke Laporan
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <?php
        // ═══════════════════════════════════════════════════════════════
        // HELPER: Render a label table (used for both SS and Kanban)
        // ═══════════════════════════════════════════════════════════════
        function renderLabelTable(array $rows, array $ngMap, bool $isKanban, string $tableId): void
        {
            if (empty($rows)) return;
            $accentColor  = $isKanban ? '#7c3aed' : '#0369a1';
            $accentBg     = $isKanban ? '#ede9fe' : '#e0f2fe';
            $accentBorder = $isKanban ? '#c4b5fd' : '#bae6fd';
            $headerBg     = $isKanban ? '#faf5ff' : '#f0f9ff';
            $typeLabel    = $isKanban ? 'Kanban' : 'Safety Stock';
            $typeSvg      = $isKanban
                ? '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z"/>'
                : '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>';

            $totalQty   = array_sum(array_column($rows, 'qty'));
            $totalNg    = array_sum(array_column($rows, 'ng_count'));
            $totalBoxes = array_sum(array_map(fn($r) => $r['box_count'] ?? 1, $rows));
            $ngCount    = count(array_filter($rows, fn($r) => (int)$r['ng_count'] > 0));
        ?>
        <div id="<?= $tableId ?>-wrapper" style="background: #fff; border: 1px solid <?= $accentBorder ?>; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
            <!-- Section Header -->
            <div style="padding: 12px 16px; background: <?= $headerBg ?>; border-bottom: 1px solid <?= $accentBorder ?>; display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div style="background: <?= $accentBg ?>; border: 1px solid <?= $accentBorder ?>; padding: 7px; border-radius: 9999px;">
                        <svg style="width: 15px; height: 15px; color: <?= $accentColor ?>;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <?= $typeSvg ?>
                        </svg>
                    </div>
                    <div>
                        <h2 style="font-size: 13px; font-weight: 900; color: <?= $accentColor ?>; margin: 0;"><?= $typeLabel ?> Labels</h2>
                        <p style="font-size: 10px; color: #64748b; margin: 0; margin-top: 1px;">Label box yang diinspeksi untuk tipe <?= $typeLabel ?></p>
                    </div>
                </div>
                <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                    <span style="font-size: 11px; font-weight: 700; color: <?= $accentColor ?>; background: <?= $accentBg ?>; border: 1px solid <?= $accentBorder ?>; padding: 3px 10px; border-radius: 9999px;"><?= $totalBoxes ?> Label</span>
                    <span style="font-size: 11px; font-weight: 700; color: #0f172a; background: #f1f5f9; border: 1px solid #e2e8f0; padding: 3px 10px; border-radius: 9999px; font-family: monospace;"><?= number_format($totalQty) ?> pcs</span>
                    <?php if ($totalNg > 0): ?>
                        <span style="font-size: 11px; font-weight: 700; color: #be123c; background: #ffe4e6; border: 1px solid #fecdd3; padding: 3px 10px; border-radius: 9999px;"><?= $totalNg ?> NG</span>
                    <?php else: ?>
                        <span style="font-size: 11px; font-weight: 700; color: #166534; background: #dcfce7; border: 1px solid #bbf7d0; padding: 3px 10px; border-radius: 9999px;">All OK</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Table -->
            <div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                <table id="<?= $tableId ?>" style="width: 100%; border-collapse: collapse; font-size: 12px; color: #334155; min-width: <?= $isKanban ? '780' : '640' ?>px;">
                    <thead style="background: #fff; border-bottom: 2px solid <?= $accentBorder ?>; font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.4px;">
                        <tr>
                            <th style="padding: 10px 14px; width: 40px; text-align: center;">No</th>
                            <th style="padding: 10px 14px; min-width: 120px;">Ref Number</th>
                            <th style="padding: 10px 14px; min-width: 110px;">Lot Number</th>
                            <th style="padding: 10px 14px; min-width: 130px;">Part Code</th>
                            <?php if ($isKanban): ?>
                                <th style="padding: 10px 14px; min-width: 110px;">Kanban No</th>
                                <th style="padding: 10px 14px; min-width: 130px;">Customer</th>
                            <?php endif; ?>
                            <th style="padding: 10px 14px; text-align: center; width: 90px;">Qty</th>
                            <th style="padding: 10px 14px; text-align: center; width: 80px;">Status</th>
                            <th style="padding: 10px 14px; min-width: 120px;">Inspektur</th>
                            <th style="padding: 10px 14px; min-width: 130px;">Waktu Scan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $rIdx => $lot): ?>
                            <?php
                                $sessionId  = (int)$lot['session_id'];
                                $lotNg      = (int)$lot['ng_count'];
                                $ngRecords  = [];
                                if (!empty($lot['session_ids'])) {
                                    foreach ($lot['session_ids'] as $sId) {
                                        if (!empty($ngMap[$sId])) {
                                            foreach ($ngMap[$sId] as $ngItem) {
                                                $ngRecords[] = $ngItem;
                                            }
                                        }
                                    }
                                } else {
                                    $ngRecords = $ngMap[$sessionId] ?? [];
                                }
                                $hasNg      = $lotNg > 0 && !empty($ngRecords);
                                $rowBg      = $hasNg ? '#fffbfb' : '';
                                $rowBorder  = $hasNg ? '#fecdd3' : '#f1f5f9';
                            ?>
                            <!-- Main Label Row -->
                            <tr class="label-row" style="border-bottom: 1px solid <?= $rowBorder ?>; background: <?= $rowBg ?>;"
                                onmouseover="this.style.background='<?= $hasNg ? '#fff1f2' : '#f8fafc' ?>'"
                                onmouseout="this.style.background='<?= $rowBg ?>'">
                                <td style="padding: 10px 14px; text-align: center; font-weight: 700; color: #94a3b8; font-size: 11px;"><?= $rIdx + 1 ?></td>

                                <!-- Ref Number -->
                                <td style="padding: 10px 14px;">
                                    <span style="display: inline-block; font-family: monospace; font-weight: 800; font-size: 12px; color: <?= $accentColor ?>; background: <?= $accentBg ?>; border: 1px solid <?= $accentBorder ?>; padding: 2px 9px; border-radius: 6px;">
                                        <?= htmlspecialchars(!empty($lot['ref_number']) ? $lot['ref_number'] : '-') ?>
                                    </span>
                                </td>

                                <!-- Lot Number -->
                                <td style="padding: 10px 14px;">
                                    <span style="font-family: monospace; font-weight: 700; color: #334155; background: #f1f5f9; padding: 2px 8px; border-radius: 5px; border: 1px solid #e2e8f0; display: inline-block; font-size: 11px;">
                                        <?= htmlspecialchars($lot['lot_number'] ?? '-') ?>
                                    </span>
                                </td>

                                <!-- Part Code + Name -->
                                <td style="padding: 10px 14px;">
                                    <div style="font-weight: 800; color: #1d4ed8; font-family: monospace; font-size: 11px;"><?= htmlspecialchars($lot['part_code'] ?? '-') ?></div>
                                    <div style="font-size: 10px; color: #64748b; font-weight: 500; margin-top: 1px; max-width: 160px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($lot['part_name'] ?? '') ?>">
                                        <?= htmlspecialchars($lot['part_name'] ?? '-') ?>
                                    </div>
                                </td>

                                <?php if ($isKanban): ?>
                                <!-- Kanban No -->
                                <td style="padding: 10px 14px;">
                                    <?php if (!empty($lot['kanban_no']) && $lot['kanban_no'] !== '-'): ?>
                                        <span style="display: inline-block; font-family: monospace; font-weight: 700; font-size: 11px; color: #7c3aed; background: #ede9fe; border: 1px solid #c4b5fd; padding: 2px 8px; border-radius: 6px;">
                                            <?= htmlspecialchars($lot['kanban_no']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #94a3b8; font-size: 11px;">—</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Customer -->
                                <td style="padding: 10px 14px;">
                                    <div style="font-weight: 700; color: #1e293b; font-size: 11px; max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($lot['customer'] ?? '') ?>">
                                        <?= htmlspecialchars(!empty($lot['customer']) && $lot['customer'] !== '-' ? $lot['customer'] : '—') ?>
                                    </div>
                                </td>
                                <?php endif; ?>

                                <!-- Qty -->
                                <td style="padding: 10px 14px; text-align: center;">
                                    <span style="font-family: monospace; font-weight: 900; color: #0f172a; font-size: 13px;"><?= number_format((int)$lot['qty']) ?></span>
                                    <div style="font-size: 10px; color: #94a3b8; font-weight: 600;">pcs</div>
                                </td>

                                <!-- Status NG -->
                                <td style="padding: 10px 14px; text-align: center;">
                                    <?php
                                        $rejectNum = max(1, (int)($lot['reject_number'] ?? 1));
                                    ?>
                                    <?php if ($lotNg > 0): ?>
                                        <?php if ($lotNg >= $rejectNum): ?>
                                            <span style="display: inline-flex; align-items: center; gap: 4px; background: #ffe4e6; color: #be123c; border: 1px solid #fecdd3; font-weight: 800; font-size: 11px; padding: 3px 9px; border-radius: 9999px;" title="Total NG: <?= $lotNg ?> (Telah Mencapai/Melebihi Batas Reject: <?= $rejectNum ?>)">
                                                <svg style="width: 10px; height: 10px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 9v2m0 4h.01"/></svg>
                                                <?= $lotNg ?> / <?= $rejectNum ?> NG
                                            </span>
                                        <?php else: ?>
                                            <span style="display: inline-flex; align-items: center; gap: 4px; background: #fffbe6; color: #b45309; border: 1px solid #fde68a; font-weight: 800; font-size: 11px; padding: 3px 9px; border-radius: 9999px;" title="Total NG: <?= $lotNg ?> (Di Bawah Batas Reject: <?= $rejectNum ?>)">
                                                <svg style="width: 10px; height: 10px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 9v2m0 4h.01"/></svg>
                                                <?= $lotNg ?> / <?= $rejectNum ?> NG
                                            </span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="display: inline-flex; align-items: center; gap: 3px; background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; font-weight: 700; font-size: 11px; padding: 3px 9px; border-radius: 9999px;">
                                            <svg style="width: 10px; height: 10px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                            OK
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Inspector -->
                                <td style="padding: 10px 14px;">
                                    <div style="display: flex; align-items: center; gap: 6px;">
                                        <span style="width: 22px; height: 22px; border-radius: 9999px; background: <?= $accentBg ?>; color: <?= $accentColor ?>; font-weight: 800; font-size: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; border: 1px solid <?= $accentBorder ?>;">
                                            <?= strtoupper(substr($lot['inspector_name'] ?? 'Q', 0, 1)) ?>
                                        </span>
                                        <span style="font-weight: 600; color: #1e293b; font-size: 11px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 110px;" title="<?= htmlspecialchars($lot['inspector_name'] ?? '') ?>">
                                            <?= htmlspecialchars($lot['inspector_name'] ?? '-') ?>
                                        </span>
                                    </div>
                                </td>

                                <!-- Waktu -->
                                <td style="padding: 10px 14px;">
                                    <div style="font-size: 11px; color: #334155; font-weight: 600;"><?= date('d M Y', strtotime($lot['started_at'])) ?></div>
                                    <div style="font-size: 10px; color: #94a3b8; font-weight: 600; margin-top: 1px;"><?= date('H:i', strtotime($lot['started_at'])) ?> WIB</div>
                                </td>
                            </tr>

                            <?php if ($hasNg): ?>
                            <!-- NG Detail Sub-Rows -->
                            <?php foreach ($ngRecords as $ngIdx => $ng): ?>
                            <tr class="ng-detail-row" style="background: #fff8f8; border-bottom: 1px solid #ffe4e6;">
                                <td style="padding: 7px 14px; text-align: center;">
                                    <div style="width: 6px; height: 6px; border-radius: 9999px; background: #e11d48; margin: auto;"></div>
                                </td>
                                <td colspan="<?= $isKanban ? 8 : 6 ?>" style="padding: 7px 14px;">
                                    <div style="display: flex; align-items: center; flex-wrap: wrap; gap: 8px; padding-left: 8px; border-left: 3px solid #fca5a5;">
                                        <div style="display: flex; align-items: center; gap: 5px;">
                                            <span style="font-size: 10px; font-weight: 800; color: #9f1239; text-transform: uppercase; letter-spacing: 0.4px;">Defect <?= $ngIdx + 1 ?>:</span>
                                            <span style="font-size: 12px; font-weight: 700; color: #be123c;"><?= htmlspecialchars($ng['defect_type_name'] ?? '-') ?></span>
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 4px;">
                                            <span style="font-size: 10px; font-weight: 600; color: #64748b;">Qty NG:</span>
                                            <span style="font-family: monospace; font-size: 12px; font-weight: 900; color: #dc2626;"><?= (int)($ng['qty_ng'] ?? 1) ?> pcs</span>
                                        </div>
                                        <?php if (!empty($ng['remark'])): ?>
                                        <div style="display: flex; align-items: center; gap: 4px;">
                                            <span style="font-size: 10px; font-weight: 600; color: #64748b;">Catatan:</span>
                                            <span style="font-size: 11px; font-weight: 500; color: #475569; font-style: italic;"><?= htmlspecialchars($ng['remark']) ?></span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td style="padding: 7px 14px;"></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>

                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
        }
        ?>

        <!-- TABLE A: SAFETY STOCK -->
        <?php if (!empty($rowsSS)): ?>
            <?php renderLabelTable($rowsSS, $ngMap, false, 'table-ss'); ?>
        <?php endif; ?>

        <!-- TABLE B: KANBAN -->
        <?php if (!empty($rowsKanban)): ?>
            <?php renderLabelTable($rowsKanban, $ngMap, true, 'table-kanban'); ?>
        <?php endif; ?>

    </main>

<!-- Client-side search filter (both tables) -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    var searchInput = document.getElementById('dr-search');
    if (!searchInput) return;

    searchInput.addEventListener('input', function () {
        var term = this.value.toLowerCase().trim();

        // Query all label rows + their adjacent NG rows
        var allTables = document.querySelectorAll('#table-ss, #table-kanban');
        allTables.forEach(function (tbl) {
            var rows = tbl.querySelectorAll('tbody tr');
            var i = 0;
            while (i < rows.length) {
                var row = rows[i];
                if (row.classList.contains('label-row')) {
                    var text = row.textContent.toLowerCase();
                    var show = (term === '' || text.indexOf(term) !== -1);
                    row.style.display = show ? '' : 'none';
                    // also show/hide adjacent ng-detail rows
                    i++;
                    while (i < rows.length && rows[i].classList.contains('ng-detail-row')) {
                        rows[i].style.display = show ? '' : 'none';
                        i++;
                    }
                } else {
                    i++;
                }
            }
        });
    });
});
</script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
