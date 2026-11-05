<?php
/**
 * Modul Monitoring OQC - Workspace Checklist 5S & SOP Inspektur
 * Memandu inspektur menjalankan pemeriksaan kepatuhan 5S dan SOP kesiapan kerja.
 */
$pageTitle = "Monitoring OQC & 5S";
$breadcrumbCategory = "OPERASIONAL";
$pageSubtitle = "Pemeriksaan Kesiapan Workstation, Kepatuhan 5S, dan Standar Operasional OQC";

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

// Enforce permission
require_menu_access('monitoring_oqc');

$user = current_user();
$userRoleNorm = strtolower(trim($user['role'] ?? ''));
$isInspectorRole = in_array($userRoleNorm, ['inspektur', 'inspektor', 'operator'], true);

// Role Admin, Chief, Supervisor, Leader hanya monitoring/audit (tidak mengisi checklist workstation)
if (!$isInspectorRole) {
    redirect('modules/monitoring_oqc/audit.php');
}

$pdo = getDB();
$today = date('Y-m-d');

// Parameter Filter & Gatekeeper
$isGatekeeper = isset($_GET['gatekeeper']) && $_GET['gatekeeper'] == '1';
$requestedPhaseId = isset($_GET['phase_id']) ? (int)$_GET['phase_id'] : 0;
$isClosingShift = ($requestedPhaseId === 3);

// Fetch all phases
$phases = [];
if ($pdo) {
    try {
        $stmtP = $pdo->query("SELECT * FROM oqc_monitoring_phases ORDER BY sort_order ASC, id ASC");
        $phases = $stmtP->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        $phases = [];
    }
}

// ── Inisialisasi Data Sesi & Kepatuhan ───────────────────────────────────────
$activePhasesData = [];
$combinedChecklistIds = [];
$checkedItems = [];
$complianceInfo = ['is_compliant' => false, 'compliance_rate' => 0.00, 'total_items' => 0, 'checked_items' => 0, 'missing_groups' => []];
$shiftLabel = 'Shift 1';

if ($pdo) {
    // Sinkronkan kategori tanpa anak agar otomatis menjadi poin pemeriksaan mandiri
    sync_all_standalone_groups($pdo);

    // Tentukan tahap yang dimasukkan ke view:
    // Jika requestedPhaseId === 3: Tampilkan Tahap Akhir (Penutupan Shift)
    // Selain itu: Tampilkan Halaman Gabungan (All-in-One View: Tahap Awal id=1 + Tahap Proses id=2)
    if ($isClosingShift) {
        $targetPhases = array_values(array_filter($phases, fn($p) => (int)$p['id'] === 3));
    } else {
        $targetPhases = array_values(array_filter($phases, fn($p) => in_array((int)$p['id'], [1, 2], true)));
    }

    foreach ($targetPhases as $ph) {
        $phId = (int)$ph['id'];
        $chkHeader = get_or_create_inspector_checklist($pdo, $user['id'], $phId, $today);
        if (!$chkHeader) continue;

        $chkId = (int)$chkHeader['id'];
        $combinedChecklistIds[] = $chkId;
        $shiftLabel = $chkHeader['shift'] ?? $shiftLabel;

        // Ambil grup aktif di fase ini
        $stmtG = $pdo->prepare("SELECT * FROM oqc_monitoring_groups WHERE phase_id = :pid AND is_active = 1 ORDER BY sort_order ASC, id ASC");
        $stmtG->execute([':pid' => $phId]);
        $grpRows = $stmtG->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Ambil butir item untuk masing-masing grup
        foreach ($grpRows as &$grp) {
            $stmtI = $pdo->prepare("SELECT * FROM oqc_monitoring_items WHERE group_id = :gid AND is_active = 1 ORDER BY sort_order ASC, id ASC");
            $stmtI->execute([':gid' => $grp['id']]);
            $grp['items'] = $stmtI->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        unset($grp);

        // Ambil catatan centang user
        $stmtC = $pdo->prepare("SELECT item_id, is_checked, checked_at, unchecked_at FROM oqc_inspector_checklist_items WHERE checklist_id = :cid");
        $stmtC->execute([':cid' => $chkId]);
        $rowsC = $stmtC->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rowsC as $rc) {
            $checkedItems[(int)$rc['item_id']] = $rc;
        }

        $activePhasesData[] = [
            'phase'     => $ph,
            'checklist' => $chkHeader,
            'groups'    => $grpRows
        ];
    }

    // Hitung kepatuhan terpadu
    if (!empty($combinedChecklistIds)) {
        if (count($combinedChecklistIds) > 1) {
            $complianceInfo = calculate_combined_monitoring_compliance($pdo, $combinedChecklistIds);
        } else {
            $complianceInfo = calculate_monitoring_compliance($pdo, $combinedChecklistIds[0]);
        }
    }
}

// Generate CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-6 space-y-5 max-w-7xl w-full mx-auto">
        
        <?= render_flash() ?>

    <!-- Top Nav: Tab Switcher (Formulir vs Audit) -->
    <div class="bg-white p-1.5 border border-slate-200 rounded-2xl flex items-center justify-between shadow-sm">
        <div class="flex items-center gap-1 w-full sm:w-auto">
            <a href="<?= base_url('modules/monitoring_oqc/index.php') ?>" 
               class="px-4 py-2 text-xs font-bold bg-blue-600 text-white rounded-xl transition-all shadow-sm">
                Formulir Checklist Harian
            </a>
            <a href="<?= base_url('modules/monitoring_oqc/audit.php') ?>" 
               class="px-4 py-2 text-xs font-bold text-slate-600 hover:text-slate-900 hover:bg-slate-100 rounded-xl transition-all">
                Audit & Rekap Kepatuhan Tim &rarr;
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

    <!-- Gatekeeper Notification Banner -->
    <?php if ($isGatekeeper): ?>
        <div class="p-4 rounded-2xl bg-amber-50 border border-amber-200 flex items-start gap-3.5 shadow-sm">
            <div class="w-10 h-10 rounded-xl bg-amber-100 text-amber-700 flex items-center justify-center flex-shrink-0 font-bold">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
            </div>
            <div>
                <h4 class="text-sm font-bold text-amber-900">Quality Gatekeeper Aktif</h4>
                <p class="text-xs text-amber-800 mt-0.5">
                    Sebelum memulai scan inspeksi hari ini, mohon selesaikan checklist kesiapan 5S & SOP di bawah ini untuk memastikan workstation dalam kondisi standar.
                </p>
            </div>
        </div>
    <?php endif; ?>

    <!-- User & Compliance Hero Card -->
    <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-5">
            <!-- Left: Inspector Meta -->
            <div class="flex items-center gap-4">
                <div class="w-14 h-14 rounded-2xl bg-blue-50 border border-blue-200 flex items-center justify-center text-blue-600 font-bold text-xl shadow-sm flex-shrink-0">
                    <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <h2 class="text-lg font-black text-slate-800"><?= htmlspecialchars($user['name']) ?></h2>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wider bg-slate-100 text-slate-700 border border-slate-200">
                            <?= htmlspecialchars($user['role']) ?>
                        </span>
                    </div>
                    <p class="text-xs text-slate-500 mt-0.5">
                        <span class="font-semibold text-slate-700"><?= date('l, d F Y') ?></span> &bull; Shift: <span class="font-bold text-slate-700"><?= htmlspecialchars($shiftLabel) ?></span>
                    </p>
                </div>
            </div>

            <!-- Right: Live Compliance Metric & Progress -->
            <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 min-w-[280px]">
                <div class="flex items-center justify-between text-xs mb-1.5">
                    <span class="font-bold text-slate-600 uppercase tracking-wider text-[10px]">Tingkat Kepatuhan SOP</span>
                    <span id="compliancePercentageText" class="font-black text-sm <?= $complianceInfo['compliance_rate'] >= 100 ? 'text-emerald-600' : 'text-blue-600' ?>">
                        <?= number_format($complianceInfo['compliance_rate'], 1) ?>%
                    </span>
                </div>
                <!-- Progress Bar -->
                <div class="w-full h-2.5 bg-slate-200 rounded-full overflow-hidden">
                    <div id="complianceProgressBar" 
                         class="h-full rounded-full transition-all duration-300 <?= $complianceInfo['compliance_rate'] >= 100 ? 'bg-emerald-500' : 'bg-blue-600' ?>" 
                         style="width: <?= min(100, (float)$complianceInfo['compliance_rate']) ?>%"></div>
                </div>
                <div class="flex items-center justify-between mt-2 text-[11px] text-slate-500">
                    <span id="itemsCountText"><?= $complianceInfo['checked_items'] ?> dari <?= $complianceInfo['total_items'] ?> butir diceklis</span>
                    <span id="complianceStatusBadge" class="font-bold <?= $complianceInfo['is_compliant'] ? 'text-emerald-600' : 'text-amber-600' ?>">
                        <?= $complianceInfo['is_compliant'] ? '✓ Memenuhi Standar' : 'Belum Memenuhi' ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Simplified 2-Tab Navigation (Operasional Utama) -->
    <div class="bg-white p-1.5 border border-slate-200 rounded-2xl flex flex-wrap items-center gap-1.5 shadow-sm">
        <!-- Tab 1: Kesiapan Awal & Proses Kerja (All-in-One Gabungan) -->
        <a href="<?= base_url('modules/monitoring_oqc/index.php') ?><?= $isGatekeeper ? '?gatekeeper=1' : '' ?>" 
           class="flex-1 min-w-[200px] text-center py-2.5 px-4 rounded-xl text-xs md:text-sm font-black transition-all duration-150 <?= !$isClosingShift ? 'bg-blue-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' ?>">
            <span>📋 Kesiapan Awal & Proses Kerja</span>
            <span class="block text-[10px] font-normal mt-0.5 <?= !$isClosingShift ? 'text-blue-100' : 'text-slate-400' ?>">
                Tahap Awal & Proses (Gabungan Sekaligus)
            </span>
        </a>

        <!-- Tab 2: Penutupan Shift (Phase 3) -->
        <a href="<?= base_url('modules/monitoring_oqc/index.php?phase_id=3') ?>" 
           class="flex-1 min-w-[200px] text-center py-2.5 px-4 rounded-xl text-xs md:text-sm font-black transition-all duration-150 <?= $isClosingShift ? 'bg-blue-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' ?>">
            <span>🏁 Penutupan Shift & 5S Akhir</span>
            <span class="block text-[10px] font-normal mt-0.5 <?= $isClosingShift ? 'text-blue-100' : 'text-slate-400' ?>">
                Tahap Akhir (Selesai Kerja / Pre-Logout)
            </span>
        </a>
    </div>

    <!-- Main Checklist Workspace -->
    <?php if (empty($activePhasesData)): ?>
        <div class="bg-white border border-slate-200 rounded-2xl p-12 text-center shadow-sm">
            <div class="w-14 h-14 rounded-full bg-slate-100 flex items-center justify-center mx-auto text-slate-400 mb-3">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path></svg>
            </div>
            <h4 class="text-sm font-bold text-slate-700">Belum Ada Checklist Dikonfigurasi</h4>
            <p class="text-xs text-slate-500 mt-1">Admin belum menambahkan grup atau item checklist untuk tahap ini.</p>
        </div>
    <?php else: ?>
        <form id="monitoringForm" action="<?= base_url('modules/monitoring_oqc/submit_phase.php') ?>" method="POST" class="space-y-6">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="checklist_ids" id="activeChecklistIds" value="<?= implode(',', $combinedChecklistIds) ?>">
            <input type="hidden" name="is_gatekeeper" value="<?= $isGatekeeper ? '1' : '0' ?>">

            <!-- Quick Action Toolbar (Check All / Reset All) -->
            <div class="bg-white border border-slate-200 rounded-2xl p-3.5 px-4 shadow-sm flex flex-col sm:flex-row items-center justify-between gap-3">
                <div class="flex items-center gap-2.5">
                    <span class="w-2.5 h-2.5 rounded-full bg-blue-600 animate-pulse"></span>
                    <span class="text-xs font-bold text-slate-700">
                        <?= !$isClosingShift ? 'Aksi Cepat: Checklist Awal & Proses Sekaligus' : 'Aksi Cepat: Checklist Penutupan Shift' ?>
                    </span>
                    <span class="text-[11px] text-slate-400 hidden md:inline">• Mempercepat inspeksi harian tanpa klik satu per satu</span>
                </div>
                <div class="flex items-center gap-2 w-full sm:w-auto">
                    <button type="button" 
                            id="btnCheckAllPhase"
                            onclick="toggleAllCombinedChecklist(1)"
                            class="flex-1 sm:flex-none px-4 py-2 rounded-xl text-xs font-bold bg-blue-600 hover:bg-blue-700 text-white transition-all flex items-center justify-center gap-1.5 shadow-sm cursor-pointer active:scale-95">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        <span>✓ Ceklis Semua Butir</span>
                    </button>
                    <button type="button" 
                            id="btnResetAllPhase"
                            onclick="toggleAllCombinedChecklist(0)"
                            class="flex-1 sm:flex-none px-3.5 py-2 rounded-xl text-xs font-semibold bg-slate-100 hover:bg-slate-200 text-slate-600 transition-all flex items-center justify-center gap-1.5 cursor-pointer active:scale-95">
                        <svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                        <span>Reset Semua</span>
                    </button>
                </div>
            </div>

            <!-- Sections per Phase (All-in-One Sequential Layout) -->
            <?php foreach ($activePhasesData as $pIdx => $pData): ?>
                <?php 
                    $currPhase = $pData['phase'];
                    $currChecklist = $pData['checklist'];
                    $currGroups = $pData['groups'];
                ?>
                <div class="space-y-4">
                    <!-- Phase Section Header (Hanya di mode gabungan awal + proses) -->
                    <?php if (!$isClosingShift): ?>
                        <div class="flex items-center justify-between p-3.5 bg-blue-50/90 border border-blue-200 rounded-2xl shadow-sm">
                            <div class="flex items-center gap-3">
                                <span class="w-7 h-7 rounded-xl bg-blue-600 text-white font-extrabold text-xs flex items-center justify-center shadow-sm">
                                    <?= $pIdx + 1 ?>
                                </span>
                                <div>
                                    <h3 class="text-sm font-extrabold text-blue-950">
                                        <?= htmlspecialchars($currPhase['name']) ?>
                                    </h3>
                                    <p class="text-[11px] text-blue-700">
                                        <?= !empty($currPhase['description']) ? htmlspecialchars($currPhase['description']) : 'Pemeriksaan kepatuhan standar kesiapan kerja' ?>
                                    </p>
                                </div>
                            </div>
                            <span class="text-xs font-bold text-blue-700 bg-white px-3 py-1 rounded-xl border border-blue-200 shadow-xs">
                                <?= count($currGroups) ?> Kategori
                            </span>
                        </div>
                    <?php endif; ?>

                    <?php if (empty($currGroups)): ?>
                        <div class="bg-white border border-slate-200 rounded-2xl p-6 text-center shadow-sm">
                            <p class="text-xs text-slate-400 font-semibold italic">
                                Belum ada kategori checklist aktif untuk <?= htmlspecialchars($currPhase['name']) ?>.
                            </p>
                        </div>
                    <?php else: ?>
                        <!-- Group Cards inside this phase -->
                        <?php foreach ($currGroups as $grp): ?>
                            <?php 
                                $isGroupMissing = in_array($grp['title'], $complianceInfo['missing_groups'], true);
                                $isStandalone = (count($grp['items']) === 1 && $grp['items'][0]['item_text'] === $grp['title']);
                            ?>
                            <?php if ($isStandalone): ?>
                                <?php 
                                    $item = $grp['items'][0];
                                    $chkData = $checkedItems[(int)$item['id']] ?? null;
                                    $isChecked = !empty($chkData['is_checked']);
                                    $timeStr = '';
                                    if ($isChecked && !empty($chkData['checked_at'])) {
                                        $timeStr = date('H:i', strtotime($chkData['checked_at']));
                                    }
                                ?>
                                <!-- Kategori Poin Mandiri (Langsung Memiliki Kotak Centang) -->
                                <div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-4 hover:bg-slate-50/70 transition-colors cursor-pointer item-row flex flex-col sm:flex-row sm:items-center justify-between gap-3" 
                                     id="group-card-<?= $grp['id'] ?>"
                                     onclick="toggleItemCheckbox(<?= (int)$item['id'] ?>, <?= (int)$currChecklist['id'] ?>, event)">
                                    <div class="flex items-start sm:items-center gap-3 min-w-0 flex-1">
                                        <div class="pt-0.5 flex-shrink-0 mr-1">
                                            <input type="checkbox" 
                                                   id="item-chk-<?= $item['id'] ?>" 
                                                   data-item-id="<?= $item['id'] ?>"
                                                   data-group-id="<?= $grp['id'] ?>"
                                                   data-checklist-id="<?= (int)$currChecklist['id'] ?>"
                                                   <?= $isChecked ? 'checked' : '' ?>
                                                   class="w-5 h-5 rounded border-slate-300 text-blue-600 focus:ring-blue-500 cursor-pointer">
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="w-6 h-6 rounded-lg bg-blue-100 text-blue-700 flex items-center justify-center font-bold text-xs flex-shrink-0">
                                                    <?= (int)$grp['sort_order'] ?>
                                                </span>
                                                <span class="text-sm font-bold text-slate-800">
                                                    <?= htmlspecialchars($grp['title']) ?>
                                                </span>
                                                <?php if ($item['is_required']): ?>
                                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-rose-50 text-rose-700 border border-rose-200 shadow-sm ml-1 select-none">
                                                        <span class="w-1.5 h-1.5 rounded-full bg-rose-500 flex-shrink-0"></span>
                                                        Wajib
                                                    </span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-slate-100 text-slate-500 border border-slate-200 ml-1 select-none">
                                                        Opsional
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (!empty($grp['description']) || !empty($item['hint_guide'])): ?>
                                                <p class="text-xs text-slate-500 mt-1 sm:ml-9">
                                                    <?= htmlspecialchars(!empty($grp['description']) ? $grp['description'] : $item['hint_guide']) ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="text-right flex-shrink-0 self-end sm:self-center">
                                        <span id="time-badge-<?= $item['id'] ?>" class="text-[11px] <?= $isChecked ? 'text-emerald-600 font-semibold' : 'text-slate-400' ?>">
                                            <?= $isChecked ? "✓ {$timeStr}" : 'Belum diceklis' ?>
                                        </span>
                                    </div>
                                </div>
                            <?php else: ?>
                                <!-- Kategori Standar dengan Sub-Poin -->
                                <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden transition-all duration-200" id="group-card-<?= $grp['id'] ?>">
                                    <!-- Group Header -->
                                    <div class="p-4 bg-slate-50/80 border-b border-slate-200 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                                        <div class="flex items-center gap-2.5">
                                            <span class="w-7 h-7 rounded-lg bg-blue-100 text-blue-700 flex items-center justify-center font-bold text-xs flex-shrink-0">
                                                <?= (int)$grp['sort_order'] ?>
                                            </span>
                                            <div>
                                                <div class="flex items-center gap-2">
                                                    <h3 class="text-sm font-bold text-slate-800"><?= htmlspecialchars($grp['title']) ?></h3>
                                                    <?php if ($grp['rule_type'] === 'all_required'): ?>
                                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200">
                                                            Wajib Semua
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                                            Perwakilan (Min <?= (int)$grp['min_required'] ?>)
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if (!empty($grp['description'])): ?>
                                                    <p class="text-xs text-slate-500 mt-0.5"><?= htmlspecialchars($grp['description']) ?></p>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <!-- Right Actions: Per-Group Check All & Status Badge -->
                                        <div class="flex items-center gap-2 self-end sm:self-auto">
                                            <button type="button" 
                                                    onclick="toggleGroupCheck(<?= $grp['id'] ?>, <?= (int)$currChecklist['id'] ?>, 1, event)"
                                                    title="Ceklis semua butir di grup ini"
                                                    class="px-2.5 py-1 text-[11px] font-bold rounded-lg border border-blue-200 text-blue-700 bg-blue-50/70 hover:bg-blue-100 transition-colors flex items-center gap-1 cursor-pointer shadow-sm">
                                                <svg class="w-3.5 h-3.5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                                <span>Ceklis Grup</span>
                                            </button>
                                            <div id="group-badge-<?= $grp['id'] ?>">
                                                <?php if (!$isGroupMissing): ?>
                                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                                        Memenuhi Syarat
                                                    </span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                                        Belum Lengkap
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Items Checklist List -->
                                    <div class="divide-y divide-slate-100">
                                        <?php foreach ($grp['items'] as $item): ?>
                                            <?php 
                                                $chkData = $checkedItems[(int)$item['id']] ?? null;
                                                $isChecked = !empty($chkData['is_checked']);
                                                $timeStr = '';
                                                if ($isChecked && !empty($chkData['checked_at'])) {
                                                    $timeStr = date('H:i', strtotime($chkData['checked_at']));
                                                }
                                            ?>
                                            <div class="p-4 flex items-start gap-4 hover:bg-slate-50/70 transition-colors cursor-pointer item-row"
                                                 style="gap: 1rem;"
                                                 onclick="toggleItemCheckbox(<?= (int)$item['id'] ?>, <?= (int)$currChecklist['id'] ?>, event)">
                                                <div class="pt-0.5 flex-shrink-0 mr-3" style="margin-right: 0.75rem;">
                                                    <input type="checkbox" 
                                                           id="item-chk-<?= $item['id'] ?>" 
                                                           data-item-id="<?= $item['id'] ?>"
                                                           data-group-id="<?= $grp['id'] ?>"
                                                           data-checklist-id="<?= (int)$currChecklist['id'] ?>"
                                                           <?= $isChecked ? 'checked' : '' ?>
                                                           class="w-5 h-5 rounded border-slate-300 text-blue-600 focus:ring-blue-500 cursor-pointer">
                                                </div>
                                                <div class="flex-1 min-w-0">
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        <span class="text-xs font-bold text-slate-800 select-none">
                                                            <?= htmlspecialchars($item['item_text']) ?>
                                                        </span>
                                                        <?php if ($item['is_required']): ?>
                                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-rose-50 text-rose-700 border border-rose-200 shadow-sm ml-2 select-none" style="margin-left: 0.5rem;">
                                                                <span class="w-1.5 h-1.5 rounded-full bg-rose-500 flex-shrink-0"></span>
                                                                Wajib
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-slate-100 text-slate-500 border border-slate-200 ml-2 select-none" style="margin-left: 0.5rem;">
                                                                Opsional
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if (!empty($item['hint_guide'])): ?>
                                                        <p class="text-[11px] text-slate-500 mt-0.5 flex items-center gap-1.5">
                                                            <svg class="w-3.5 h-3.5 text-slate-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                                            <span class="italic"><?= htmlspecialchars($item['hint_guide']) ?></span>
                                                        </p>
                                                    <?php endif; ?>
                                                </div>

                                                <div class="text-right flex-shrink-0">
                                                    <span id="time-badge-<?= $item['id'] ?>" class="text-[11px] <?= $isChecked ? 'text-emerald-600 font-semibold' : 'text-slate-400' ?>">
                                                        <?= $isChecked ? "✓ {$timeStr}" : 'Belum diceklis' ?>
                                                    </span>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <!-- Notes & Confirmation Bar -->
            <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm space-y-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Catatan Tambahan / Temuan Lapangan (Opsional)</label>
                    <textarea name="notes" rows="2" placeholder="Tuliskan kendala 5S, kotoran berlebih, atau kondisi khusus workstation..." 
                              class="w-full text-xs font-medium border border-slate-200 rounded-xl p-3 focus:outline-none focus:ring-2 focus:ring-blue-500"><?= htmlspecialchars($activePhasesData[0]['checklist']['notes'] ?? '') ?></textarea>
                </div>

                <div class="pt-3 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-3">
                    <p class="text-xs text-slate-500">
                        Checklist dapat di-uncheck atau diedit kembali kapan saja selama shift berjalan.
                    </p>
                    <div class="flex items-center gap-2 w-full sm:w-auto">
                        <button type="submit" 
                                id="btnSubmitChecklist"
                                class="w-full sm:w-auto px-6 py-2.5 text-xs font-bold text-white rounded-xl transition-all shadow-sm flex items-center justify-center gap-2 <?= $complianceInfo['is_compliant'] ? 'bg-emerald-600 hover:bg-emerald-700 cursor-pointer' : 'bg-slate-400 cursor-not-allowed' ?>">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                            <?= $isGatekeeper ? 'Konfirmasi Selesai & Lanjut Scan Inspeksi' : 'Simpan Status Checklist' ?>
                        </button>
                    </div>
                </div>
            </div>
        </form>
    <?php endif; ?>
    </main>

<script>
let isCurrentlyCompliant = <?= $complianceInfo['is_compliant'] ? 'true' : 'false' ?>;

// Helper: Update seluruh elemen metrik kepatuhan & tombol submit
function updateComplianceUI(res) {
    const pct = parseFloat(res.compliance_rate) || 0;
    const pctText = document.getElementById('compliancePercentageText');
    if (pctText) {
        pctText.innerText = pct.toFixed(1) + '%';
        pctText.className = pct >= 100 ? 'font-black text-sm text-emerald-600' : 'font-black text-sm text-blue-600';
    }

    const pBar = document.getElementById('complianceProgressBar');
    if (pBar) {
        pBar.style.width = Math.min(100, pct) + '%';
        pBar.className = pct >= 100 
            ? 'h-full rounded-full transition-all duration-300 bg-emerald-500' 
            : 'h-full rounded-full transition-all duration-300 bg-blue-600';
    }

    const itemsCount = document.getElementById('itemsCountText');
    if (itemsCount) {
        itemsCount.innerText = `${res.checked_items} dari ${res.total_items} butir diceklis`;
    }

    const statBadge = document.getElementById('complianceStatusBadge');
    isCurrentlyCompliant = Boolean(res.is_compliant);
    if (statBadge) {
        if (res.is_compliant) {
            statBadge.className = 'font-bold text-emerald-600';
            statBadge.innerText = '✓ Memenuhi Standar';
        } else {
            statBadge.className = 'font-bold text-amber-600';
            statBadge.innerText = 'Belum Memenuhi';
        }
    }

    const btn = document.getElementById('btnSubmitChecklist');
    if (btn) {
        if (res.is_compliant) {
            btn.className = 'w-full sm:w-auto px-6 py-2.5 text-xs font-bold text-white rounded-xl transition-all shadow-sm flex items-center justify-center gap-2 bg-emerald-600 hover:bg-emerald-700 cursor-pointer';
        } else {
            btn.className = 'w-full sm:w-auto px-6 py-2.5 text-xs font-bold text-white rounded-xl transition-all shadow-sm flex items-center justify-center gap-2 bg-slate-400 cursor-not-allowed';
        }
    }
}

// 1. Toggle Single Checklist Item
function toggleItemCheckbox(itemId, specificChecklistId, event) {
    const chk = document.getElementById('item-chk-' + itemId);
    if (!chk) return;

    let nextState;
    if (event && event.target && (event.target.id === ('item-chk-' + itemId) || event.target.tagName.toLowerCase() === 'input')) {
        nextState = chk.checked ? 1 : 0;
    } else {
        chk.checked = !chk.checked;
        nextState = chk.checked ? 1 : 0;
    }

    const chkId = specificChecklistId || chk.getAttribute('data-checklist-id');
    const allIds = document.getElementById('activeChecklistIds')?.value || chkId;

    $.ajax({
        url: 'toggle_item.php',
        type: 'POST',
        method: 'POST',
        data: {
            checklist_id: chkId,
            item_id: itemId,
            state: nextState,
            all_checklist_ids: allIds
        },
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                chk.checked = Boolean(res.is_checked);

                // Update Timestamp Badge
                const timeBadge = document.getElementById('time-badge-' + itemId);
                if (timeBadge) {
                    if (res.is_checked) {
                        timeBadge.className = 'text-[11px] text-emerald-600 font-semibold';
                        timeBadge.innerText = '✓ ' + (res.checked_time || 'Diceklis');
                    } else {
                        timeBadge.className = 'text-[11px] text-slate-400';
                        timeBadge.innerText = 'Belum diceklis';
                    }
                }

                // Update Group Badge
                if (res.group_id > 0) {
                    const groupBadge = document.getElementById('group-badge-' + res.group_id);
                    if (groupBadge) {
                        if (res.group_passed) {
                            groupBadge.innerHTML = `<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                Memenuhi Syarat
                            </span>`;
                        } else {
                            groupBadge.innerHTML = `<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                Belum Lengkap
                            </span>`;
                        }
                    }
                }

                updateComplianceUI(res);
            } else {
                chk.checked = !nextState;
                alert(res.message || 'Gagal mengubah status item.');
            }
        },
        error: function(xhr) {
            chk.checked = !nextState;
            alert('Gangguan koneksi ke server. Perubahan tidak tersimpan.');
        }
    });
}

// 2. Batch: Ceklis / Reset Semua Butir (Mendukung Gabungan All-in-One)
function toggleAllCombinedChecklist(targetState) {
    const allIds = document.getElementById('activeChecklistIds')?.value;
    if (!allIds) return;

    $.ajax({
        url: 'toggle_all.php',
        type: 'POST',
        method: 'POST',
        data: {
            checklist_ids: allIds,
            state: targetState,
            group_id: 0
        },
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                if (Array.isArray(res.item_ids)) {
                    res.item_ids.forEach(function(itemId) {
                        const chk = document.getElementById('item-chk-' + itemId);
                        if (chk) {
                            chk.checked = (targetState === 1);
                        }
                        const timeBadge = document.getElementById('time-badge-' + itemId);
                        if (timeBadge) {
                            if (targetState === 1) {
                                timeBadge.className = 'text-[11px] text-emerald-600 font-semibold';
                                timeBadge.innerText = '✓ ' + (res.checked_time || 'Diceklis');
                            } else {
                                timeBadge.className = 'text-[11px] text-slate-400';
                                timeBadge.innerText = 'Belum diceklis';
                            }
                        }
                    });
                }

                if (res.group_statuses) {
                    for (const gid in res.group_statuses) {
                        const groupBadge = document.getElementById('group-badge-' + gid);
                        if (groupBadge) {
                            if (res.group_statuses[gid]) {
                                groupBadge.innerHTML = `<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                    Memenuhi Syarat
                                </span>`;
                            } else {
                                groupBadge.innerHTML = `<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                    Belum Lengkap
                                </span>`;
                            }
                        }
                    }
                }

                updateComplianceUI(res);
            } else {
                alert(res.message || 'Gagal mengubah checklist.');
            }
        },
        error: function() {
            alert('Gangguan koneksi ke server.');
        }
    });
}

// 3. Batch: Ceklis Semua Butir di Grup Tertentu
function toggleGroupCheck(groupId, specificChecklistId, targetState, event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }

    const allIds = document.getElementById('activeChecklistIds')?.value || specificChecklistId;

    $.ajax({
        url: 'toggle_all.php',
        type: 'POST',
        method: 'POST',
        data: {
            checklist_id: specificChecklistId,
            checklist_ids: allIds,
            state: targetState,
            group_id: groupId
        },
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                if (Array.isArray(res.item_ids)) {
                    res.item_ids.forEach(function(itemId) {
                        const chk = document.getElementById('item-chk-' + itemId);
                        if (chk) {
                            chk.checked = (targetState === 1);
                        }
                        const timeBadge = document.getElementById('time-badge-' + itemId);
                        if (timeBadge) {
                            if (targetState === 1) {
                                timeBadge.className = 'text-[11px] text-emerald-600 font-semibold';
                                timeBadge.innerText = '✓ ' + (res.checked_time || 'Diceklis');
                            } else {
                                timeBadge.className = 'text-[11px] text-slate-400';
                                timeBadge.innerText = 'Belum diceklis';
                            }
                        }
                    });
                }

                const groupBadge = document.getElementById('group-badge-' + groupId);
                if (groupBadge && res.group_statuses && res.group_statuses[groupId] !== undefined) {
                    if (res.group_statuses[groupId]) {
                        groupBadge.innerHTML = `<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                            Memenuhi Syarat
                        </span>`;
                    } else {
                        groupBadge.innerHTML = `<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            Belum Lengkap
                        </span>`;
                    }
                }

                updateComplianceUI(res);
            } else {
                alert(res.message || 'Gagal mengubah butir grup.');
            }
        },
        error: function() {
            alert('Gangguan koneksi ke server.');
        }
    });
}

// Intercept form submit jika belum compliant
document.getElementById('monitoringForm')?.addEventListener('submit', function(e) {
    if (!isCurrentlyCompliant) {
        e.preventDefault();
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: 'Checklist Belum Lengkap',
                text: 'Harap lengkapi semua butir checklist wajib atau minimal perwakilan sebelum melanjutkan.',
                icon: 'warning',
                confirmButtonColor: '#2563eb'
            });
        } else {
            alert('Harap lengkapi semua butir checklist wajib atau minimal perwakilan sebelum melanjutkan.');
        }
    }
});

// Intercept form submit jika belum compliant
document.getElementById('monitoringForm')?.addEventListener('submit', function(e) {
    if (!isCurrentlyCompliant) {
        e.preventDefault();
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: 'Checklist Belum Lengkap',
                text: 'Harap lengkapi semua butir checklist wajib atau minimal perwakilan sebelum melanjutkan.',
                icon: 'warning',
                confirmButtonColor: '#2563eb'
            });
        } else {
            alert('Harap lengkapi semua butir checklist wajib atau minimal perwakilan sebelum melanjutkan.');
        }
    }
});
</script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
