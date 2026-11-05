<?php
/**
 * Dedicated Page: Rincian Hasil Checklist Monitoring OQC (SOP 5S)
 * Menampilkan detail lengkap sesi checklist inspeksi per inspektur & shift
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

// Auth Guard
require_login();

$checklistId = (int)($_GET['id'] ?? 0);
$isJson = (isset($_GET['format']) && $_GET['format'] === 'json') || 
          (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

if ($checklistId <= 0) {
    if ($isJson) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'ID checklist tidak valid.']);
        exit;
    }
    set_flash('danger', 'ID checklist tidak valid.');
    redirect('modules/monitoring_oqc/audit.php');
}

$pdo = getDB();
if (!$pdo) {
    if ($isJson) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Koneksi database gagal.']);
        exit;
    }
    set_flash('danger', 'Koneksi database gagal.');
    redirect('modules/monitoring_oqc/audit.php');
}

try {
    // 1. Ambil Data Header Sesi Checklist
    $sqlH = "SELECT c.*, u.name as inspector_name, u.role as inspector_role, u.username as inspector_username,
                    p.name as phase_name, p.trigger_event, p.description as phase_desc
             FROM oqc_inspector_checklists c
             JOIN users u ON c.user_id = u.id
             JOIN oqc_monitoring_phases p ON c.phase_id = p.id
             WHERE c.id = :id LIMIT 1";
    $stmtH = $pdo->prepare($sqlH);
    $stmtH->execute([':id' => $checklistId]);
    $header = $stmtH->fetch(PDO::FETCH_ASSOC);

    if (!$header) {
        if ($isJson) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Data sesi checklist tidak ditemukan.']);
            exit;
        }
        set_flash('danger', 'Data sesi checklist tidak ditemukan.');
        redirect('modules/monitoring_oqc/audit.php');
    }

    // RBAC: Jika bukan Admin/Chief, hanya boleh melihat rincian miliknya sendiri
    $currentUser = current_user();
    $isAdminOrChief = in_array(strtolower($currentUser['role']), ['admin', 'administrator', 'chief', 'supervisor', 'leader'], true);
    if (!$isAdminOrChief && (int)$header['user_id'] !== (int)$currentUser['id']) {
        if ($isJson) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Akses ditolak: Anda hanya dapat melihat rincian checklist milik Anda sendiri.']);
            exit;
        }
        set_flash('danger', 'Akses ditolak: Anda hanya dapat melihat rincian checklist milik Anda sendiri.');
        redirect('modules/monitoring_oqc/audit.php');
    }

    $phaseId = (int)$header['phase_id'];

    // 2. Ambil Grup Kategori dalam Tahap Ini
    $stmtG = $pdo->prepare("SELECT * FROM oqc_monitoring_groups WHERE phase_id = :pid ORDER BY sort_order ASC, id ASC");
    $stmtG->execute([':pid' => $phaseId]);
    $groups = $stmtG->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // 3. Ambil Item Detail yang Dicentang
    $stmtC = $pdo->prepare("SELECT * FROM oqc_inspector_checklist_items WHERE checklist_id = :cid");
    $stmtC->execute([':cid' => $checklistId]);
    $checksRaw = $stmtC->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $checksMap = [];
    foreach ($checksRaw as $cr) {
        $checksMap[(int)$cr['item_id']] = $cr;
    }

    // 4. Strukturkan Items dalam Masing-Masing Grup
    $stmtI = $pdo->prepare("SELECT * FROM oqc_monitoring_items WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
    $stmtI->execute();
    $allItems = $stmtI->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $itemsByGroup = [];
    foreach ($allItems as $it) {
        $itemsByGroup[(int)$it['group_id']][] = $it;
    }

    // Hitung kepatuhan real-time
    $compliance = calculate_monitoring_compliance($pdo, $checklistId);

    foreach ($groups as &$grp) {
        $gId = (int)$grp['id'];
        $rawItms = $itemsByGroup[$gId] ?? [];
        $grp['items'] = [];
        $grpCheckedCount = 0;

        foreach ($rawItms as $itm) {
            $itemId = (int)$itm['id'];
            $chkData = $checksMap[$itemId] ?? null;
            $isChecked = !empty($chkData['is_checked']);
            if ($isChecked) {
                $grpCheckedCount++;
            }

            $grp['items'][] = [
                'id'           => $itemId,
                'item_text'    => $itm['item_text'],
                'hint_guide'   => $itm['hint_guide'],
                'is_required'  => (bool)$itm['is_required'],
                'is_checked'   => $isChecked ? 1 : 0,
                'checked_at'   => $chkData['checked_at'] ?? null,
                'unchecked_at' => $chkData['unchecked_at'] ?? null
            ];
        }

        $isMissing = in_array($grp['title'], $compliance['missing_groups'], true);
        $grp['is_passed'] = !$isMissing;
        $grp['checked_count'] = $grpCheckedCount;
        $grp['total_items'] = count($grp['items']);
    }
    unset($grp);

    // Jika dipanggil via JSON/API
    if ($isJson) {
        header('Content-Type: application/json');
        echo json_encode([
            'success'         => true,
            'checklist_id'    => $checklistId,
            'inspector_name'  => $header['inspector_name'],
            'check_date'      => date('d M Y', strtotime($header['check_date'])),
            'shift'           => $header['shift'],
            'phase_name'      => $header['phase_name'],
            'status'          => $header['status'],
            'compliance_rate' => (float)$compliance['compliance_rate'],
            'is_compliant'    => $compliance['is_compliant'],
            'checked_items'   => $compliance['checked_items'],
            'total_items'     => $compliance['total_items'],
            'notes'           => $header['notes'],
            'groups'          => $groups
        ]);
        exit;
    }

} catch (Exception $e) {
    error_log('[OQC Monitoring] Error detail.php: ' . $e->getMessage());
    if ($isJson) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()]);
        exit;
    }
    set_flash('danger', 'Terjadi kesalahan sistem: ' . $e->getMessage());
    redirect('modules/monitoring_oqc/audit.php');
}

// ── Page Layout Setup ────────────────────────────────────────────────────────
$pageTitle          = "Rincian Monitoring OQC";
$breadcrumbCategory = "OPERASIONAL";
$pageSubtitle       = "Rincian Butir Checklist & Log Kepatuhan SOP 5S Inspektur";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-6 space-y-6 max-w-7xl w-full mx-auto">
        
        <?= render_flash() ?>

        <!-- Action Header Bar & Navigation -->
        <div class="bg-white border border-slate-200 rounded-2xl p-4 shadow-2xs flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="<?= base_url('modules/monitoring_oqc/audit.php') ?>" 
                   class="p-2.5 rounded-xl border border-slate-200 bg-slate-50 hover:bg-slate-100 text-slate-600 transition-all flex items-center justify-center shadow-2xs"
                   title="Kembali ke Log Audit">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                </a>
                <div>
                    <div class="flex items-center gap-2">
                        <h2 class="text-base font-black text-slate-800">Rincian Checklist SOP 5S</h2>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold <?= $header['status'] === 'completed' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-amber-50 text-amber-700 border border-amber-200' ?>">
                            <?= $header['status'] === 'completed' ? '✓ Selesai' : 'Sedang Berjalan' ?>
                        </span>
                    </div>
                    <p class="text-xs text-slate-500 mt-0.5">
                        ID Sesi #<?= $checklistId ?> &bull; Dicatat pada <?= date('d F Y, H:i', strtotime($header['created_at'])) ?> WIB
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-2 self-end sm:self-auto">
                <button type="button" 
                        onclick="window.print()" 
                        class="px-3.5 py-2 rounded-xl text-xs font-bold border border-slate-200 bg-slate-50 hover:bg-slate-100 text-slate-700 transition flex items-center gap-1.5 shadow-2xs cursor-pointer">
                    <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
                    <span>Cetak Rincian</span>
                </button>
                <a href="<?= base_url('modules/monitoring_oqc/audit.php') ?>" 
                   class="px-4 py-2 rounded-xl text-xs font-bold bg-blue-600 hover:bg-blue-700 text-white transition flex items-center gap-1.5 shadow-2xs">
                    <span>Lihat Semua Audit</span>
                </a>
            </div>
        </div>

        <!-- 4 KPI Summary Cards (Sejejer 1 Baris) -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4" style="display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1rem;">
            <!-- KPI 1: Kepatuhan SOP -->
            <div class="bg-white border border-slate-200 rounded-2xl p-4 shadow-2xs flex items-center justify-between">
                <div>
                    <p class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider">Tingkat Kepatuhan</p>
                    <h3 class="text-2xl font-black <?= $compliance['compliance_rate'] >= 100 ? 'text-emerald-600' : 'text-blue-600' ?> mt-1">
                        <?= number_format($compliance['compliance_rate'], 1) ?>%
                    </h3>
                    <p class="text-[11px] font-semibold <?= $compliance['is_compliant'] ? 'text-emerald-600' : 'text-amber-600' ?> mt-0.5">
                        <?= $compliance['is_compliant'] ? '✓ Memenuhi Standar' : 'Belum Memenuhi' ?>
                    </p>
                </div>
                <div class="w-12 h-12 rounded-xl <?= $compliance['compliance_rate'] >= 100 ? 'bg-emerald-50 border border-emerald-200 text-emerald-600' : 'bg-blue-50 border border-blue-200 text-blue-600' ?> flex items-center justify-center">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                </div>
            </div>

            <!-- KPI 2: Inspektur -->
            <div class="bg-white border border-slate-200 rounded-2xl p-4 shadow-2xs flex items-center justify-between">
                <div>
                    <p class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider">Inspektur Bertugas</p>
                    <h3 class="text-base font-bold text-slate-800 mt-1 truncate max-w-[150px]" title="<?= htmlspecialchars($header['inspector_name']) ?>">
                        <?= htmlspecialchars($header['inspector_name']) ?>
                    </h3>
                    <span class="inline-flex items-center px-1.5 py-0.2 rounded text-[10px] font-bold bg-slate-100 text-slate-600 mt-0.5 uppercase">
                        <?= htmlspecialchars($header['inspector_role']) ?>
                    </span>
                </div>
                <div class="w-12 h-12 rounded-xl bg-purple-50 border border-purple-200 flex items-center justify-center text-purple-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                </div>
            </div>

            <!-- KPI 3: Tanggal & Shift -->
            <div class="bg-white border border-slate-200 rounded-2xl p-4 shadow-2xs flex items-center justify-between">
                <div>
                    <p class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider">Jadwal Pemeriksaan</p>
                    <h3 class="text-base font-bold text-slate-800 mt-1">
                        <?= date('d M Y', strtotime($header['check_date'])) ?>
                    </h3>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold bg-blue-50 text-blue-700 border border-blue-200 mt-0.5">
                        <?= htmlspecialchars($header['shift']) ?>
                    </span>
                </div>
                <div class="w-12 h-12 rounded-xl bg-amber-50 border border-amber-200 flex items-center justify-center text-amber-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                </div>
            </div>

            <!-- KPI 4: Tahap Monitoring -->
            <div class="bg-white border border-slate-200 rounded-2xl p-4 shadow-2xs flex items-center justify-between">
                <div>
                    <p class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider">Tahap Monitoring</p>
                    <h3 class="text-base font-bold text-slate-800 mt-1 truncate max-w-[150px]" title="<?= htmlspecialchars($header['phase_name']) ?>">
                        <?= htmlspecialchars($header['phase_name']) ?>
                    </h3>
                    <p class="text-[11px] text-slate-500 mt-0.5">
                        <?= $compliance['checked_items'] ?> dari <?= $compliance['total_items'] ?> butir terpenuhi
                    </p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-indigo-50 border border-indigo-200 flex items-center justify-center text-indigo-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path></svg>
                </div>
            </div>
        </div>

        <!-- Hierarki Detail Grup & Butir Checklist 5S -->
        <div class="space-y-5">
            <?php foreach ($groups as $grp): ?>
                <div class="bg-white border border-slate-200 rounded-2xl shadow-2xs overflow-hidden">
                    <!-- Group Header -->
                    <div class="p-4 bg-slate-50/70 border-b border-slate-200 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                        <div class="flex items-center gap-2.5">
                            <span class="w-7 h-7 rounded-lg bg-blue-100 text-blue-700 flex items-center justify-center font-bold text-xs flex-shrink-0">
                                <?= (int)$grp['sort_order'] ?>
                            </span>
                            <div>
                                <div class="flex items-center gap-2">
                                    <h4 class="text-sm font-bold text-slate-800"><?= htmlspecialchars($grp['title']) ?></h4>
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

                        <!-- Status Group Badge -->
                        <div>
                            <?php if ($grp['is_passed']): ?>
                                <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                    Memenuhi Syarat (<?= $grp['checked_count'] ?>/<?= $grp['total_items'] ?>)
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                    Belum Lengkap (<?= $grp['checked_count'] ?>/<?= $grp['total_items'] ?>)
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Items Checklist List -->
                    <div class="divide-y divide-slate-100">
                        <?php foreach ($grp['items'] as $item): ?>
                            <div class="p-4 flex items-start gap-4 hover:bg-slate-50/50 transition-colors" style="gap: 1rem;">
                                <!-- Checkbox Icon Visual -->
                                <div class="pt-0.5 flex-shrink-0 mr-3" style="margin-right: 0.75rem;">
                                    <?php if ($item['is_checked']): ?>
                                        <div class="w-5 h-5 rounded bg-emerald-600 text-white flex items-center justify-center shadow-2xs">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                                        </div>
                                    <?php else: ?>
                                        <div class="w-5 h-5 rounded border-2 border-slate-300 bg-white flex items-center justify-center"></div>
                                    <?php endif; ?>
                                </div>

                                <div class="flex-1 min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-xs font-bold text-slate-800">
                                            <?= htmlspecialchars($item['item_text']) ?>
                                        </span>
                                        <?php if ($item['is_required']): ?>
                                            <span class="inline-flex items-center px-1.5 py-0.2 rounded text-[9px] font-bold bg-rose-50 text-rose-700 border border-rose-200">
                                                Wajib
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-1.5 py-0.2 rounded text-[9px] font-bold bg-slate-50 text-slate-600 border border-slate-200">
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
                                    <?php if ($item['is_checked']): ?>
                                        <span class="inline-flex items-center gap-1 text-[11px] text-emerald-600 font-bold bg-emerald-50 px-2 py-0.5 rounded-md border border-emerald-200">
                                            <span>✓ Diceklis</span>
                                            <?php if (!empty($item['checked_at'])): ?>
                                                <span class="text-emerald-700 font-mono">(<?= date('H:i', strtotime($item['checked_at'])) ?>)</span>
                                            <?php endif; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-[11px] text-slate-400 font-medium">
                                            Belum diceklis
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Notes / Field Findings Card -->
        <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-2xs space-y-2">
            <h4 class="text-xs font-bold text-slate-700 uppercase tracking-wider">Catatan Tambahan / Temuan Lapangan</h4>
            <div class="p-3.5 rounded-xl bg-slate-50 border border-slate-200 text-xs text-slate-700 font-medium leading-relaxed">
                <?= !empty($header['notes']) ? nl2br(htmlspecialchars($header['notes'])) : '<span class="italic text-slate-400">Tidak ada catatan lapangan yang ditinggalkan oleh inspektur.</span>' ?>
            </div>
        </div>

    </main>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
