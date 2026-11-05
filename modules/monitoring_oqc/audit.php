<?php
/**
 * Modul Monitoring OQC - Audit & Rekap Kepatuhan Inspektur (Admin & Leader View)
 * Menampilkan ringkasan kepatuhan SOP 5S, drill-down per inspektur, dan histori centang.
 */
$pageTitle = "Audit Kepatuhan Monitoring OQC";
$breadcrumbCategory = "OPERASIONAL";
$pageSubtitle = "Rekapitulasi Kepatuhan SOP 5S & Kesiapan Kerja Tim Inspektur OQC";

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

// Enforce permission
require_menu_access('monitoring_oqc');

$currentUser = current_user();
$isAdminOrChief = in_array(strtolower($currentUser['role']), ['admin', 'administrator', 'chief', 'supervisor', 'leader'], true);
$isRestrictedInspector = !$isAdminOrChief;

$pdo = getDB();

// ── Filter Parameters ────────────────────────────────────────────────────────
$filterDateStart = sanitize($_GET['date_start'] ?? date('Y-m-d'));
$filterDateEnd   = sanitize($_GET['date_end'] ?? date('Y-m-d'));
$filterInspector = $isRestrictedInspector ? (int)$currentUser['id'] : (int)($_GET['inspector_id'] ?? 0);
$filterPhase     = (int)($_GET['phase_id'] ?? 0);
$filterStatus    = sanitize($_GET['status'] ?? 'semua');

// Quick period helper
$period = sanitize($_GET['period'] ?? 'hari_ini');
if ($period === 'hari_ini') {
    $filterDateStart = date('Y-m-d');
    $filterDateEnd   = date('Y-m-d');
} elseif ($period === '7_hari') {
    $filterDateStart = date('Y-m-d', strtotime('-6 days'));
    $filterDateEnd   = date('Y-m-d');
} elseif ($period === 'bulan_ini') {
    $filterDateStart = date('Y-m-01');
    $filterDateEnd   = date('Y-m-d');
} elseif ($period === 'semua') {
    $filterDateStart = '';
    $filterDateEnd   = '';
}

// ── Data Dropdown Filter ────────────────────────────────────────────────────
$inspectors = [];
$phases = [];
if ($pdo) {
    try {
        $stmtIns = $pdo->query("SELECT id, name, username, role FROM users WHERE status = 'active' ORDER BY name ASC");
        $inspectors = $stmtIns->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stmtP = $pdo->query("SELECT id, name, sort_order FROM oqc_monitoring_phases ORDER BY sort_order ASC");
        $phases = $stmtP->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        $inspectors = [];
        $phases = [];
    }
}

// ── Query Rekap Data Audit ──────────────────────────────────────────────────
$where = [];
$params = [];

if (!empty($filterDateStart)) {
    $where[] = "c.check_date >= :d_start";
    $params[':d_start'] = $filterDateStart;
}
if (!empty($filterDateEnd)) {
    $where[] = "c.check_date <= :d_end";
    $params[':d_end'] = $filterDateEnd;
}
if ($isRestrictedInspector) {
    $where[] = "c.user_id = :uid";
    $params[':uid'] = (int)$currentUser['id'];
} elseif ($filterInspector > 0) {
    $where[] = "c.user_id = :uid";
    $params[':uid'] = $filterInspector;
}
if ($filterPhase > 0) {
    $where[] = "c.phase_id = :pid";
    $params[':pid'] = $filterPhase;
}
if ($filterStatus === 'completed') {
    $where[] = "c.status = 'completed'";
} elseif ($filterStatus === 'in_progress') {
    $where[] = "c.status = 'in_progress'";
}

$whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

$records = [];

if ($pdo) {
    try {
        // Query Records
        $sqlRecords = "SELECT c.*, u.name as inspector_name, u.role as inspector_role, p.name as phase_name, p.trigger_event
                       FROM oqc_inspector_checklists c
                       JOIN users u ON c.user_id = u.id
                       JOIN oqc_monitoring_phases p ON c.phase_id = p.id
                       {$whereClause}
                       ORDER BY c.check_date DESC, c.updated_at DESC, c.id DESC";
        $stmtRec = $pdo->prepare($sqlRecords);
        $stmtRec->execute($params);
        $rawRecords = $stmtRec->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Agregasikan / Gabungkan Sesi per (Tanggal + Inspektur + Shift)
        $sessions = [];
        foreach ($rawRecords as $r) {
            $key = $r['check_date'] . '_' . $r['user_id'] . '_' . ($r['shift'] ?? 'Shift 1');
            if (!isset($sessions[$key])) {
                $sessions[$key] = [
                    'session_key'    => md5($key),
                    'check_date'     => $r['check_date'],
                    'shift'          => $r['shift'] ?? 'Shift 1',
                    'user_id'        => (int)$r['user_id'],
                    'inspector_name' => $r['inspector_name'],
                    'inspector_role' => $r['inspector_role'],
                    'checklist_ids'  => [],
                    'phases'         => [],
                    'total_items'    => 0,
                    'checked_items'  => 0,
                    'all_completed'  => true,
                    'notes'          => []
                ];
            }

            $sessions[$key]['checklist_ids'][] = (int)$r['id'];
            $sessions[$key]['total_items'] += (int)$r['total_items'];
            $sessions[$key]['checked_items'] += (int)$r['checked_items'];
            if (!empty($r['notes'])) {
                $sessions[$key]['notes'][] = $r['phase_name'] . ': ' . $r['notes'];
            }
            $isPhaseDone = ($r['status'] === 'completed' || (float)$r['compliance_rate'] >= 100);
            if (!$isPhaseDone) {
                $sessions[$key]['all_completed'] = false;
            }

            $sessions[$key]['phases'][] = [
                'id'              => (int)$r['id'],
                'phase_name'      => $r['phase_name'],
                'compliance_rate' => (float)$r['compliance_rate'],
                'checked_items'   => (int)$r['checked_items'],
                'total_items'     => (int)$r['total_items'],
                'status'          => $r['status']
            ];
        }

        foreach ($sessions as &$sess) {
            $sess['compliance_rate'] = $sess['total_items'] > 0 
                ? round(($sess['checked_items'] / $sess['total_items']) * 100, 1) 
                : 0.0;
            $sess['checklist_ids_str'] = implode(',', $sess['checklist_ids']);
        }
        unset($sess);

        $records = array_values($sessions);
    } catch (PDOException $e) {
        set_flash('danger', 'Gagal memuat rekap audit kepatuhan: ' . $e->getMessage());
    }
}

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-6 space-y-5 max-w-7xl w-full mx-auto">
        
        <?= render_flash() ?>

    <!-- Top Nav: Tab Switcher (Formulir vs Audit) -->
    <div class="bg-white p-1.5 border border-slate-200 rounded-2xl flex items-center justify-between shadow-2xs">
        <div class="flex items-center gap-1 w-full sm:w-auto">
            <?php if ($isRestrictedInspector): ?>
            <a href="<?= base_url('modules/monitoring_oqc/index.php') ?>" 
               class="px-4 py-2 text-xs font-bold text-slate-600 hover:text-slate-900 hover:bg-slate-100 rounded-xl transition-all">
                &larr; Formulir Checklist Harian
            </a>
            <?php endif; ?>
            <a href="<?= base_url('modules/monitoring_oqc/audit.php') ?>" 
               class="px-4 py-2 text-xs font-bold bg-blue-600 text-white rounded-xl transition-all shadow-2xs">
                <?= $isRestrictedInspector ? 'Riwayat Checklist Saya' : 'Audit & Rekap Kepatuhan Tim' ?>
            </a>
        </div>
        <?php if (can_access('monitoring_master')): ?>
        <div class="hidden sm:flex items-center gap-2 pr-3">
            <a href="<?= base_url('modules/monitoring_master/index.php') ?>" 
               class="text-xs font-semibold text-slate-500 hover:text-blue-600 flex items-center gap-1">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path></svg>
                Kelola Template Master
            </a>
        </div>
        <?php endif; ?>
    </div>

    <!-- Filter Control Bar -->
    <div class="bg-white border border-slate-200 rounded-2xl p-4 shadow-2xs">
        <form method="GET" action="<?= base_url('modules/monitoring_oqc/audit.php') ?>" class="space-y-3">
            <div class="flex flex-wrap items-center gap-2 pb-3 border-b border-slate-100">
                <span class="text-xs font-bold text-slate-600">Periode Cepat:</span>
                <a href="?period=hari_ini" class="px-2.5 py-1 text-xs font-bold rounded-lg transition <?= $period === 'hari_ini' ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">Hari Ini</a>
                <a href="?period=7_hari" class="px-2.5 py-1 text-xs font-bold rounded-lg transition <?= $period === '7_hari' ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">7 Hari Terakhir</a>
                <a href="?period=bulan_ini" class="px-2.5 py-1 text-xs font-bold rounded-lg transition <?= $period === 'bulan_ini' ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">Bulan Ini</a>
                <a href="?period=semua" class="px-2.5 py-1 text-xs font-bold rounded-lg transition <?= $period === 'semua' ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">Semua</a>
            </div>

            <!-- Form Filter Horizontal Sederet 1 Baris -->
            <div class="pt-1" style="display: grid; grid-template-columns: <?= $isRestrictedInspector ? 'repeat(3, minmax(130px, 1fr)) auto' : 'repeat(4, minmax(130px, 1fr)) auto' ?>; gap: 0.75rem; align-items: flex-end;">
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 mb-1">Dari Tanggal</label>
                    <input type="date" name="date_start" value="<?= htmlspecialchars($filterDateStart) ?>" 
                           class="w-full text-xs font-medium border border-slate-200 rounded-xl px-3 py-2 focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 mb-1">Sampai Tanggal</label>
                    <input type="date" name="date_end" value="<?= htmlspecialchars($filterDateEnd) ?>" 
                           class="w-full text-xs font-medium border border-slate-200 rounded-xl px-3 py-2 focus:ring-2 focus:ring-blue-500">
                </div>
                <?php if (!$isRestrictedInspector): ?>
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 mb-1">Nama Inspektur</label>
                    <select name="inspector_id" class="w-full text-xs font-medium border border-slate-200 rounded-xl px-3 py-2 focus:ring-2 focus:ring-blue-500">
                        <option value="0">-- Semua Inspektur --</option>
                        <?php foreach ($inspectors as $ins): ?>
                            <option value="<?= $ins['id'] ?>" <?= $filterInspector === (int)$ins['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($ins['name']) ?> (<?= htmlspecialchars($ins['role']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div>
                    <label class="block text-[11px] font-bold text-slate-600 mb-1">Tahap SOP</label>
                    <select name="phase_id" class="w-full text-xs font-medium border border-slate-200 rounded-xl px-3 py-2 focus:ring-2 focus:ring-blue-500">
                        <option value="0">-- Semua Tahap --</option>
                        <?php foreach ($phases as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= $filterPhase === (int)$p['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex items-center gap-1.5 whitespace-nowrap">
                    <button type="submit" class="py-2 px-4 text-xs font-bold text-white bg-blue-600 hover:bg-blue-700 rounded-xl transition shadow-2xs flex items-center justify-center gap-1.5 cursor-pointer">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"></path></svg>
                        <span>Filter</span>
                    </button>
                    <a href="<?= base_url('modules/monitoring_oqc/audit.php') ?>" class="p-2 text-slate-500 hover:text-slate-800 bg-slate-100 hover:bg-slate-200 rounded-xl transition flex items-center justify-center" title="Reset Filter">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                    </a>
                </div>
            </div>
        </form>
    </div>

    <!-- Table of Audit Logs -->
    <div class="bg-white border border-slate-200 rounded-2xl shadow-2xs overflow-hidden">
        <div class="p-4 border-b border-slate-200 bg-slate-50/50 flex items-center justify-between">
            <h3 class="text-sm font-bold text-slate-800">
                <?= $isRestrictedInspector ? 'Riwayat Pemeriksaan Checklist Saya' : 'Riwayat Pemeriksaan Checklist Inspektur' ?>
            </h3>
            <span class="text-xs font-semibold text-slate-500">Menampilkan <?= count($records) ?> sesi kerja</span>
        </div>

        <?php if (empty($records)): ?>
            <div class="p-12 text-center">
                <div class="w-12 h-12 rounded-full bg-slate-100 flex items-center justify-center mx-auto text-slate-400 mb-3">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path></svg>
                </div>
                <p class="text-xs font-semibold text-slate-600">Tidak ada data checklist sesuai kriteria filter.</p>
                <p class="text-[11px] text-slate-400 mt-0.5">Coba ubah rentang tanggal atau inspektur yang dipilih.</p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs text-slate-600">
                    <thead class="bg-slate-50 border-b border-slate-200 text-slate-500 font-bold uppercase tracking-wider text-[10px]">
                        <tr>
                            <th class="py-3 px-4">Tanggal & Shift</th>
                            <th class="py-3 px-4">Inspektur</th>
                            <th class="py-3 px-4">Pemeriksaan Per Tahap</th>
                            <th class="py-3 px-4">Kepatuhan Total Shift</th>
                            <th class="py-3 px-4">Status</th>
                            <th class="py-3 px-4 text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 font-medium">
                        <?php foreach ($records as $r): ?>
                            <?php 
                                $rate = (float)$r['compliance_rate'];
                                $isFull = ($rate >= 100);
                                $k = $r['session_key'];
                            ?>
                            <!-- Baris Utama (Sesi Kerja) -->
                            <tr class="hover:bg-slate-50/80 transition-colors" id="row-<?= $k ?>">
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <div class="font-bold text-slate-800"><?= date('d M Y', strtotime($r['check_date'])) ?></div>
                                    <div class="text-[11px] text-slate-400 font-semibold"><?= htmlspecialchars($r['shift'] ?? 'Shift 1') ?></div>
                                </td>
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <div class="font-bold text-slate-800"><?= htmlspecialchars($r['inspector_name']) ?></div>
                                    <div class="text-[10px] uppercase font-bold text-slate-400"><?= htmlspecialchars($r['inspector_role']) ?></div>
                                </td>
                                <td class="py-3.5 px-4">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <?php foreach ($r['phases'] as $ph): ?>
                                            <?php 
                                                $phRate = (float)$ph['compliance_rate'];
                                                $phDone = ($ph['status'] === 'completed' || $phRate >= 100);
                                            ?>
                                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-bold <?= $phDone ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-amber-50 text-amber-700 border border-amber-200' ?>">
                                                <span><?= htmlspecialchars($ph['phase_name']) ?>:</span>
                                                <span class="font-black"><?= number_format($phRate, 0) ?>%</span>
                                                <?php if ($phDone): ?>
                                                    <span class="text-emerald-600">✓</span>
                                                <?php else: ?>
                                                    <span class="text-amber-500 text-[10px]">⚠</span>
                                                <?php endif; ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                                <td class="py-3.5 px-4 min-w-[160px]">
                                    <div class="flex items-center justify-between text-[11px] mb-1 font-bold">
                                        <span class="<?= $isFull ? 'text-emerald-600' : 'text-blue-600' ?>"><?= number_format($rate, 1) ?>%</span>
                                        <span class="text-slate-400 font-medium"><?= (int)$r['checked_items'] ?> / <?= (int)$r['total_items'] ?> butir</span>
                                    </div>
                                    <div class="w-full h-2 bg-slate-100 rounded-full overflow-hidden">
                                        <div class="h-full rounded-full transition-all duration-300 <?= $isFull ? 'bg-emerald-500' : 'bg-blue-600' ?>" style="width: <?= min(100, $rate) ?>%"></div>
                                    </div>
                                </td>
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <?php if ($r['all_completed']): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                            ✓ Lengkap Semua
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                            In Progress / Sebagian
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3.5 px-4 text-center whitespace-nowrap">
                                    <button type="button" 
                                            id="btn-toggle-<?= $k ?>"
                                            onclick="toggleInlineDrawer('<?= $k ?>', '<?= $r['checklist_ids_str'] ?>')"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-bold text-blue-700 bg-blue-50 hover:bg-blue-100 border border-blue-200 rounded-xl transition shadow-2xs cursor-pointer active:scale-95">
                                        <svg class="w-3.5 h-3.5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                                        <span>Monitoring OQC</span>
                                        <svg id="icon-arrow-<?= $k ?>" class="w-3.5 h-3.5 text-blue-500 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                        </svg>
                                    </button>
                                </td>
                            </tr>

                            <!-- Sub-Baris Accordion Drawer (Muncul di Bawah Baris) -->
                            <tr id="drawer-<?= $k ?>" class="hidden bg-slate-50/70 border-b-2 border-blue-100 transition-all">
                                <td colspan="6" class="p-3 md:p-4" id="drawer-content-<?= $k ?>">
                                    <div class="flex items-center justify-center p-8 text-slate-500 gap-2.5">
                                        <svg class="w-5 h-5 animate-spin text-blue-600" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                        </svg>
                                        <span class="text-xs font-bold text-slate-600">Memuat rincian Monitoring OQC...</span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    </main>

<script>
window.loadedDrawers = window.loadedDrawers || {};

function toggleInlineDrawer(rowKey, checklistIds) {
    const drawer = document.getElementById('drawer-' + rowKey);
    const icon = document.getElementById('icon-arrow-' + rowKey);
    if (!drawer) return;

    const isVisible = !drawer.classList.contains('hidden');

    if (isVisible) {
        drawer.classList.add('hidden');
        if (icon) icon.classList.remove('rotate-180');
        return;
    }

    // Tampilkan drawer
    drawer.classList.remove('hidden');
    if (icon) icon.classList.add('rotate-180');

    // Jika sudah pernah di-fetch, tidak perlu fetch ulang
    if (window.loadedDrawers[rowKey]) {
        return;
    }

    const contentEl = document.getElementById('drawer-content-' + rowKey);

    fetch('ajax_combined_detail.php?ids=' + encodeURIComponent(checklistIds) + '&row_key=' + encodeURIComponent(rowKey))
        .then(function(res) {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.text();
        })
        .then(function(html) {
            if (contentEl) {
                contentEl.innerHTML = html;
            }
            window.loadedDrawers[rowKey] = true;
        })
        .catch(function(err) {
            if (contentEl) {
                contentEl.innerHTML = `
                    <div class="p-4 bg-rose-50 border border-rose-200 text-rose-700 rounded-xl text-xs font-bold flex items-center justify-between">
                        <span>Gagal memuat rincian checklist. Pastikan koneksi server aktif.</span>
                        <button type="button" onclick="closeInlineDrawer('${rowKey}')" class="text-xs underline font-bold cursor-pointer">Tutup</button>
                    </div>
                `;
            }
        });
}

function closeInlineDrawer(rowKey) {
    const drawer = document.getElementById('drawer-' + rowKey);
    const icon = document.getElementById('icon-arrow-' + rowKey);
    if (drawer) drawer.classList.add('hidden');
    if (icon) icon.classList.remove('rotate-180');
}
</script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
