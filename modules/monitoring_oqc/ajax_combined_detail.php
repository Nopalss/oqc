<?php
/**
 * AJAX Component: In-line Combined Details (Semua Tahap) Monitoring OQC
 * Mengembalikan fragmen HTML untuk ditampilkan tepat di bawah baris tabel audit.
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';
require_once __DIR__ . '/../../config/monitoring_helper.php';

require_login();

$currentUser = current_user();
$isAdminOrChief = in_array(strtolower($currentUser['role']), ['admin', 'administrator', 'chief', 'supervisor', 'leader'], true);

$rawChecklistIds = trim($_GET['ids'] ?? '');
$userId          = (int)($_GET['user_id'] ?? 0);
$checkDate       = sanitize($_GET['date'] ?? '');
$shift           = sanitize($_GET['shift'] ?? '');

$checklistIds = [];
if (!empty($rawChecklistIds)) {
    $parts = explode(',', $rawChecklistIds);
    foreach ($parts as $p) {
        $id = (int)trim($p);
        if ($id > 0) $checklistIds[] = $id;
    }
}

$pdo = getDB();
if (!$pdo) {
    echo '<div class="p-4 bg-rose-50 border border-rose-200 text-rose-700 rounded-xl text-xs font-bold">Koneksi database gagal.</div>';
    exit;
}

// Cari checklist yang dimaksud
$checklists = [];
if (!empty($checklistIds)) {
    $placeholders = implode(',', array_fill(0, count($checklistIds), '?'));
    $stmt = $pdo->prepare("SELECT c.*, p.name as phase_name, p.phase_code, p.trigger_event, p.sort_order as phase_order,
                                  u.name as inspector_name, u.role as inspector_role
                           FROM oqc_inspector_checklists c
                           JOIN oqc_monitoring_phases p ON c.phase_id = p.id
                           JOIN users u ON c.user_id = u.id
                           WHERE c.id IN ($placeholders)
                           ORDER BY p.sort_order ASC, c.id ASC");
    $stmt->execute($checklistIds);
    $checklists = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} elseif ($userId > 0 && !empty($checkDate)) {
    $stmt = $pdo->prepare("SELECT c.*, p.name as phase_name, p.phase_code, p.trigger_event, p.sort_order as phase_order,
                                  u.name as inspector_name, u.role as inspector_role
                           FROM oqc_inspector_checklists c
                           JOIN oqc_monitoring_phases p ON c.phase_id = p.id
                           JOIN users u ON c.user_id = u.id
                           WHERE c.user_id = :u AND c.check_date = :d " . (!empty($shift) ? "AND c.shift = :s" : "") . "
                           ORDER BY p.sort_order ASC, c.id ASC");
    $params = [':u' => $userId, ':d' => $checkDate];
    if (!empty($shift)) $params[':s'] = $shift;
    $stmt->execute($params);
    $checklists = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

if (empty($checklists)) {
    echo '<div class="p-4 bg-slate-100 border border-slate-200 text-slate-600 rounded-xl text-xs font-semibold text-center">Data rincian checklist tidak ditemukan.</div>';
    exit;
}

// RBAC: Inspektur biasa hanya boleh melihat checklist miliknya sendiri
if (!$isAdminOrChief) {
    foreach ($checklists as $chk) {
        if ((int)$chk['user_id'] !== (int)$currentUser['id']) {
            echo '<div class="p-4 bg-rose-50 border border-rose-200 text-rose-700 rounded-xl text-xs font-bold">Akses ditolak: Anda hanya dapat melihat rincian milik Anda sendiri.</div>';
            exit;
        }
    }
}

// Ambil struktur grup, item, dan centangan untuk setiap fase
$phaseData = [];

// Preload seluruh active items
$stmtAllItems = $pdo->query("SELECT * FROM oqc_monitoring_items WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
$allItems = $stmtAllItems->fetchAll(PDO::FETCH_ASSOC) ?: [];
$itemsByGroup = [];
foreach ($allItems as $itm) {
    $itemsByGroup[(int)$itm['group_id']][] = $itm;
}

foreach ($checklists as $chk) {
    $chkId = (int)$chk['id'];
    $phaseId = (int)$chk['phase_id'];

    // Grup
    $stmtG = $pdo->prepare("SELECT * FROM oqc_monitoring_groups WHERE phase_id = :p AND is_active = 1 ORDER BY sort_order ASC, id ASC");
    $stmtG->execute([':p' => $phaseId]);
    $groups = $stmtG->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Centangan user
    $stmtC = $pdo->prepare("SELECT * FROM oqc_inspector_checklist_items WHERE checklist_id = :cid");
    $stmtC->execute([':cid' => $chkId]);
    $checksRaw = $stmtC->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $checksMap = [];
    foreach ($checksRaw as $cr) {
        $checksMap[(int)$cr['item_id']] = $cr;
    }

    // Kepatuhan
    $compliance = calculate_monitoring_compliance($pdo, $chkId);

    // Strukturkan grup & butir
    foreach ($groups as &$grp) {
        $gId = (int)$grp['id'];
        $rawItms = $itemsByGroup[$gId] ?? [];
        $grp['items'] = [];
        $grpCheckedCount = 0;

        foreach ($rawItms as $itm) {
            $itemId = (int)$itm['id'];
            $chkData = $checksMap[$itemId] ?? null;
            $isChecked = !empty($chkData['is_checked']);
            if ($isChecked) $grpCheckedCount++;

            $grp['items'][] = [
                'id'          => $itemId,
                'item_text'   => $itm['item_text'],
                'hint_guide'  => $itm['hint_guide'],
                'is_required' => (bool)$itm['is_required'],
                'is_checked'  => $isChecked,
                'checked_at'  => $chkData['checked_at'] ?? null
            ];
        }

        $isMissing = in_array($grp['title'], $compliance['missing_groups'], true);
        $grp['is_passed'] = !$isMissing;
        $grp['checked_count'] = $grpCheckedCount;
        $grp['total_items'] = count($grp['items']);
    }
    unset($grp);

    $phaseData[] = [
        'header'     => $chk,
        'groups'     => $groups,
        'compliance' => $compliance
    ];
}

$first = $checklists[0];
$inspectorName = htmlspecialchars($first['inspector_name']);
$dateFmt = date('d F Y', strtotime($first['check_date']));
$shiftFmt = htmlspecialchars($first['shift'] ?? 'Shift 1');
?>

<div class="bg-white border-2 border-blue-200 rounded-2xl p-4 md:p-5 shadow-sm space-y-4 text-slate-700">
    <!-- Header Drawer -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-slate-200">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-blue-600 text-white flex items-center justify-center font-black text-xs shadow-sm flex-shrink-0 tracking-wider">
                OQC
            </div>
            <div>
                <h4 class="text-sm font-extrabold text-slate-900 leading-tight">
                    Rincian Monitoring OQC: <span class="text-blue-600"><?= $inspectorName ?></span>
                </h4>
                <p class="text-[11px] font-semibold text-slate-500 mt-0.5">
                    <?= $dateFmt ?> &bull; <span class="text-slate-700 font-bold"><?= $shiftFmt ?></span>
                </p>
            </div>
        </div>

        <div class="flex items-center gap-2 self-end sm:self-auto">
            <span class="text-[11px] font-bold text-slate-400 hidden md:inline">Menampilkan <?= count($phaseData) ?> Tahap Monitoring</span>
            <button type="button" 
                    onclick="closeInlineDrawer('<?= htmlspecialchars($_GET['row_key'] ?? '') ?>')"
                    class="px-3 py-1.5 text-xs font-bold bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl transition flex items-center gap-1.5 cursor-pointer">
                <span>✕ Tutup Rincian</span>
            </button>
        </div>
    </div>

    <!-- Multi-Column Layout: Setiap Tahap dalam 1 Kartu Kolom -->
    <div class="grid grid-cols-1 <?= count($phaseData) > 1 ? 'lg:grid-cols-2' : '' ?> gap-4">
        <?php foreach ($phaseData as $idx => $pInfo): ?>
            <?php 
                $h = $pInfo['header'];
                $comp = $pInfo['compliance'];
                $rate = (float)$comp['compliance_rate'];
                $isCompliant = (bool)$comp['is_compliant'];
            ?>
            <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 flex flex-col justify-between space-y-3">
                
                <!-- Phase Header -->
                <div>
                    <div class="flex items-center justify-between gap-2 mb-2">
                        <div class="flex items-center gap-2">
                            <span class="w-6 h-6 rounded-md bg-blue-100 text-blue-700 font-bold text-xs flex items-center justify-center">
                                <?= $idx + 1 ?>
                            </span>
                            <h5 class="text-xs font-black text-slate-800 uppercase tracking-wide">
                                <?= htmlspecialchars($h['phase_name']) ?>
                            </h5>
                        </div>
                        <?php if ($isCompliant): ?>
                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-emerald-100 text-emerald-800 border border-emerald-300">
                                ✓ Memenuhi Standar (<?= number_format($rate, 1) ?>%)
                            </span>
                        <?php else: ?>
                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-amber-100 text-amber-800 border border-amber-300">
                                ⚠ Belum Lengkap (<?= number_format($rate, 1) ?>%)
                            </span>
                        <?php endif; ?>
                    </div>

                    <!-- Progress mini -->
                    <div class="w-full h-1.5 bg-slate-200 rounded-full overflow-hidden mb-3">
                        <div class="h-full rounded-full <?= $rate >= 100 ? 'bg-emerald-500' : 'bg-blue-600' ?>" style="width: <?= min(100, $rate) ?>%"></div>
                    </div>

                    <!-- Groups & Items -->
                    <div class="space-y-3">
                        <?php if (empty($pInfo['groups'])): ?>
                            <div class="p-6 text-center text-slate-400 bg-white border border-slate-200 rounded-xl">
                                <p class="text-xs font-semibold">Belum ada poin pemeriksaan untuk tahap ini.</p>
                            </div>
                        <?php endif; ?>
                        <?php foreach ($pInfo['groups'] as $grp): ?>
                            <?php 
                                $isStandalone = (count($grp['items']) === 1 && $grp['items'][0]['item_text'] === $grp['title']);
                            ?>
                            <?php if ($isStandalone): ?>
                                <?php $it = $grp['items'][0]; ?>
                                <div class="bg-white border border-slate-200 rounded-xl p-3 flex items-center justify-between gap-3 text-xs shadow-2xs">
                                    <div class="flex items-center gap-3 min-w-0 flex-1">
                                        <?php if ($it['is_checked']): ?>
                                            <div class="w-5 h-5 rounded-md bg-emerald-600 text-white flex items-center justify-center flex-shrink-0 shadow-2xs">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path>
                                                </svg>
                                            </div>
                                        <?php else: ?>
                                            <div class="w-5 h-5 rounded-md border-2 border-dashed border-slate-300 text-slate-400 flex items-center justify-center flex-shrink-0 text-[10px] font-bold">
                                                ✕
                                            </div>
                                        <?php endif; ?>
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center gap-2">
                                                <span class="font-bold text-slate-800"><?= htmlspecialchars($grp['title']) ?></span>
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-rose-50 text-rose-700 border border-rose-200 shadow-2xs select-none">
                                                    <span class="w-1.5 h-1.5 rounded-full bg-rose-500 flex-shrink-0"></span>
                                                    Wajib
                                                </span>
                                            </div>
                                            <?php if (!empty($grp['description']) || !empty($it['hint_guide'])): ?>
                                                <p class="text-[10px] text-slate-400 italic mt-0.5"><?= htmlspecialchars(!empty($grp['description']) ? $grp['description'] : $it['hint_guide']) ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="flex-shrink-0 text-right">
                                        <?php if ($it['is_checked']): ?>
                                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                ✓ <?= !empty($it['checked_at']) ? date('H:i', strtotime($it['checked_at'])) : 'Diceklis' ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-[10px] font-medium bg-slate-100 text-slate-500 border border-slate-200">
                                                Belum diceklis
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-2xs">
                                    <!-- Group Title Bar -->
                                    <div class="p-2.5 px-3 bg-slate-100/70 border-b border-slate-200 flex items-center justify-between text-xs">
                                        <div class="font-bold text-slate-800 flex items-center gap-2">
                                            <span><?= htmlspecialchars($grp['title']) ?></span>
                                            <?php if ($grp['rule_type'] === 'all_required'): ?>
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-100 text-rose-800 border border-rose-300 shadow-2xs ml-2" style="margin-left: 0.5rem;">
                                                    <span class="w-1.5 h-1.5 rounded-full bg-rose-600 flex-shrink-0"></span>
                                                    Wajib Semua
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800 border border-amber-300 shadow-2xs ml-2" style="margin-left: 0.5rem;">
                                                    <span class="w-1.5 h-1.5 rounded-full bg-amber-600 flex-shrink-0"></span>
                                                    Min <?= (int)$grp['min_required'] ?> Butir
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <?php if ($grp['is_passed']): ?>
                                                <span class="text-[10px] font-bold text-emerald-600">✓ <?= $grp['checked_count'] ?>/<?= $grp['total_items'] ?></span>
                                            <?php else: ?>
                                                <span class="text-[10px] font-bold text-amber-600">⚠ <?= $grp['checked_count'] ?>/<?= $grp['total_items'] ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Item List -->
                                    <div class="divide-y divide-slate-100">
                                        <?php foreach ($grp['items'] as $it): ?>
                                            <div class="px-3.5 py-2.5 flex items-center justify-between gap-3 text-xs hover:bg-slate-50/80 transition">
                                                <!-- Checkbox Icon & Text Aligned in 1 Horizontal Flex -->
                                                <div class="flex items-center gap-3 min-w-0 flex-1">
                                                    <?php if ($it['is_checked']): ?>
                                                        <div class="w-5 h-5 rounded-md bg-emerald-600 text-white flex items-center justify-center flex-shrink-0 shadow-2xs">
                                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path>
                                                            </svg>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="w-5 h-5 rounded-md border-2 border-dashed border-slate-300 text-slate-400 flex items-center justify-center flex-shrink-0 text-[10px] font-bold">
                                                            ✕
                                                        </div>
                                                    <?php endif; ?>

                                                    <div class="min-w-0 flex-1">
                                                        <div class="flex items-center flex-wrap gap-2 leading-snug">
                                                            <span class="<?= $it['is_checked'] ? 'text-slate-800 font-bold' : 'text-slate-500 font-medium' ?>">
                                                                <?= htmlspecialchars($it['item_text']) ?>
                                                            </span>
                                                            <?php if ($it['is_required']): ?>
                                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-rose-50 text-rose-700 border border-rose-200 shadow-2xs ml-2 select-none" style="margin-left: 0.5rem;">
                                                                    <span class="w-1.5 h-1.5 rounded-full bg-rose-500 flex-shrink-0"></span>
                                                                    Wajib
                                                                </span>
                                                            <?php else: ?>
                                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-slate-100 text-slate-500 border border-slate-200 ml-2 select-none" style="margin-left: 0.5rem;">
                                                                    Opsional
                                                                </span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <?php if (!empty($it['hint_guide'])): ?>
                                                            <p class="text-[10px] text-slate-400 italic mt-0.5"><?= htmlspecialchars($it['hint_guide']) ?></p>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>

                                                <!-- Timestamp Badge -->
                                                <div class="flex-shrink-0 text-right">
                                                    <?php if ($it['is_checked']): ?>
                                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                            ✓ <?= !empty($it['checked_at']) ? date('H:i', strtotime($it['checked_at'])) : 'Diceklis' ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-[10px] font-medium bg-slate-100 text-slate-500 border border-slate-200">
                                                            Belum diceklis
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Notes Footer -->
                <?php if (!empty($h['notes'])): ?>
                    <div class="pt-2 border-t border-slate-200 text-[11px] bg-amber-50/60 p-2.5 rounded-lg border border-amber-200/60">
                        <span class="font-bold text-amber-900 block mb-0.5">Catatan Inspektur:</span>
                        <p class="text-amber-800 italic"><?= htmlspecialchars($h['notes']) ?></p>
                    </div>
                <?php endif; ?>

            </div>
        <?php endforeach; ?>
    </div>
</div>

