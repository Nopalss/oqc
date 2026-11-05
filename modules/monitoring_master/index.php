<?php
/**
 * Master Template Monitoring OQC (SOP 5S & Gatekeeper Kesiapan Kerja)
 * Manajemen Hierarki 3-Level Ramah Pengguna: Tahap SOP -> Kategori -> Butir Pemeriksaan
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';
require_once __DIR__ . '/../../config/monitoring_helper.php';

// Enforce RBAC permission
require_menu_access('monitoring_master');

$pdo = getDB();

if ($pdo) {
    // Sinkronkan seluruh kategori mandiri (tanpa anak)
    sync_all_standalone_groups($pdo);
}

// ── 1. Parameter Filter & Search ─────────────────────────────────────────────
$search      = trim(sanitize($_GET['search'] ?? ''));
$filterPhase = isset($_GET['phase_id']) ? (int)$_GET['phase_id'] : 0;

$allPhases     = [];
$displayPhases = [];
$activePhase   = null;

if ($pdo) {
    try {
        // Ambil semua fase untuk tab navigasi
        $stmtAllP = $pdo->query("SELECT p.*, 
                                 (SELECT COUNT(*) FROM oqc_monitoring_groups g WHERE g.phase_id = p.id) as group_count,
                                 (SELECT COUNT(*) FROM oqc_monitoring_items i JOIN oqc_monitoring_groups g ON i.group_id = g.id WHERE g.phase_id = p.id) as item_count
                                 FROM oqc_monitoring_phases p 
                                 ORDER BY p.sort_order ASC, p.id ASC");
        $allPhases = $stmtAllP->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Tentukan tahap yang aktif (default ke tahap pertama jika belum dipilih)
        if ($filterPhase <= 0 && !empty($allPhases)) {
            $filterPhase = (int)$allPhases[0]['id'];
        }

        foreach ($allPhases as $ap) {
            if ((int)$ap['id'] === $filterPhase) {
                $activePhase = $ap;
                break;
            }
        }
        if (!$activePhase && !empty($allPhases)) {
            $activePhase = $allPhases[0];
            $filterPhase = (int)$activePhase['id'];
        }

        // Ambil data tahap terpilih
        $phaseSql = "SELECT * FROM oqc_monitoring_phases WHERE id = :pid ORDER BY sort_order ASC, id ASC";
        $stmtP = $pdo->prepare($phaseSql);
        $stmtP->execute([':pid' => $filterPhase]);
        $displayPhases = $stmtP->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Ambil grup di tahap ini
        $groupSql = "SELECT g.*, 
                     (SELECT COUNT(*) FROM oqc_monitoring_items i WHERE i.group_id = g.id) as item_count
                     FROM oqc_monitoring_groups g 
                     WHERE g.phase_id = :pid
                     ORDER BY g.sort_order ASC, g.id ASC";
        $stmtG = $pdo->prepare($groupSql);
        $stmtG->execute([':pid' => $filterPhase]);
        $rawGroups = $stmtG->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Ambil item checklist (support pencarian teks)
        $itemSql = "SELECT i.* FROM oqc_monitoring_items i 
                    JOIN oqc_monitoring_groups g ON i.group_id = g.id 
                    WHERE g.phase_id = :pid";
        $itemParams = [':pid' => $filterPhase];

        if ($search !== '') {
            $itemSql .= " AND (i.item_text LIKE :s1 OR i.hint_guide LIKE :s2)";
            $itemParams[':s1'] = "%{$search}%";
            $itemParams[':s2'] = "%{$search}%";
        }
        $itemSql .= " ORDER BY i.sort_order ASC, i.id ASC";

        $stmtI = $pdo->prepare($itemSql);
        $stmtI->execute($itemParams);
        $rawItems = $stmtI->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $itemsByGroup = [];
        foreach ($rawItems as $ri) {
            $itemsByGroup[(int)$ri['group_id']][] = $ri;
        }

        foreach ($displayPhases as &$ph) {
            foreach ($rawGroups as &$grp) {
                $gId = (int)$grp['id'];
                $grp['items'] = $itemsByGroup[$gId] ?? [];
            }
            unset($grp);
            $ph['groups'] = $rawGroups;
        }
        unset($ph);

    } catch (PDOException $e) {
        set_flash('danger', 'Gagal memuat template master: ' . $e->getMessage());
    }
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$pageTitle          = "Master Monitoring OQC";
$breadcrumbCategory = "MASTER DATA";
$pageSubtitle       = "Pengaturan Kategori & Poin Pemeriksaan SOP Kesiapan Kerja";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-6 space-y-5 max-w-7xl w-full mx-auto">
        
        <?= render_flash() ?>

        <!-- Top Header & Action Bar -->
        <div class="bg-white border border-slate-200 rounded-2xl p-4 md:p-5 shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <h2 class="text-lg font-black text-slate-800 flex items-center gap-2">
                    <span>Pengaturan Checklist Monitoring OQC</span>
                </h2>
                <p class="text-xs text-slate-500 mt-0.5">
                    Kelola kategori dan butir-butir yang wajib diperiksa inspektur sebelum dan sesudah kerja.
                </p>
            </div>

            <div class="flex items-center gap-3">
                <a href="<?= base_url('modules/monitoring_oqc/audit.php') ?>" 
                   class="px-3.5 py-2 text-xs font-bold text-slate-600 hover:text-slate-900 bg-slate-100 hover:bg-slate-200 rounded-xl transition flex items-center gap-1.5 shadow-sm">
                    &larr; Lihat Hasil Audit Tim
                </a>

                <!-- Prominent Category Creation Button (Direct to create.php) -->
                <a href="<?= base_url('modules/monitoring_master/create.php?type=group' . ($filterPhase > 0 ? '&phase_id=' . $filterPhase : '')) ?>" 
                   class="px-4 py-2 text-xs font-bold text-white bg-blue-600 hover:bg-blue-700 rounded-xl transition flex items-center gap-1.5 shadow-sm cursor-pointer active:scale-95">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                    <span>Buat Kategori Baru</span>
                </a>
            </div>
        </div>

        <!-- Big Friendly Phase Navigation Tabs -->
        <div class="bg-white p-1.5 border border-slate-200 rounded-2xl flex flex-wrap items-center gap-2 shadow-sm">
            <?php foreach ($allPhases as $ap): ?>
                <?php 
                    $isSelected = ($filterPhase === (int)$ap['id']);
                    $url = base_url('modules/monitoring_master/index.php?phase_id=' . $ap['id']);
                ?>
                <a href="<?= $url ?>" 
                   class="flex-1 min-w-[200px] text-center py-3 px-4 rounded-xl text-xs md:text-sm font-black transition-all duration-150 <?= $isSelected ? 'bg-blue-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' ?>">
                    <span><?= htmlspecialchars($ap['name']) ?></span>
                    <span class="block text-[11px] font-normal mt-0.5 <?= $isSelected ? 'text-blue-100' : 'text-slate-400' ?>">
                        <?= (int)$ap['group_count'] ?> Kategori &bull; <?= (int)$ap['item_count'] ?> Poin Pemeriksaan
                    </span>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Search Bar -->
        <div class="bg-white border border-slate-200 rounded-2xl p-3.5 shadow-sm flex items-center justify-between gap-3">
            <form method="GET" action="<?= base_url('modules/monitoring_master/index.php') ?>" class="flex items-center gap-2 flex-1 max-w-md">
                <input type="hidden" name="phase_id" value="<?= $filterPhase ?>">
                <div class="relative w-full">
                    <input type="text" 
                           name="search" 
                           value="<?= htmlspecialchars($search) ?>" 
                           placeholder="Cari kalimat poin pemeriksaan..." 
                           class="w-full text-xs font-medium border border-slate-300 rounded-xl px-3.5 py-2 bg-slate-50 focus:bg-white text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-blue-500 transition">
                </div>
                <button type="submit" class="px-4 py-2 text-xs font-bold text-white bg-slate-800 hover:bg-slate-900 rounded-xl transition shadow-sm cursor-pointer flex-shrink-0">
                    Cari
                </button>
                <?php if ($search !== ''): ?>
                    <a href="<?= base_url('modules/monitoring_master/index.php?phase_id=' . $filterPhase) ?>" 
                       class="px-2.5 py-2 text-xs font-bold text-slate-500 hover:text-slate-800 bg-slate-100 hover:bg-slate-200 rounded-xl transition flex-shrink-0" 
                       title="Reset Pencarian">Reset</a>
                <?php endif; ?>
            </form>

            <span class="text-xs text-slate-400 font-semibold hidden sm:inline">
                Tahap: <b class="text-slate-700"><?= htmlspecialchars($activePhase['name'] ?? '') ?></b>
            </span>
        </div>

        <!-- Category & Items List -->
        <?php 
            $currentGroups = $displayPhases[0]['groups'] ?? [];
        ?>
        <?php if (empty($currentGroups)): ?>
            <div class="bg-white border border-slate-200 rounded-2xl shadow-sm text-center" 
                 style="padding: 48px 24px; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center;">
                <div style="width: 56px; height: 56px; border-radius: 16px; background-color: #eff6ff; color: #2563eb; display: flex; align-items: center; justify-content: center; margin-bottom: 14px; border: 1px solid #bfdbfe;">
                    <svg style="width: 28px; height: 28px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                </div>
                <h4 style="font-size: 16px; font-weight: 800; color: #0f172a; margin: 0 0 6px 0;">Belum Ada Kategori di Tahap Ini</h4>
                <p style="font-size: 13px; color: #64748b; margin: 0 0 20px 0; max-width: 440px; line-height: 1.5; text-align: center;">
                    Mulai dengan membuat kategori baru (contoh: Area Meja Kerja, Alat Ukur, dsb.) lalu tambahkan poin pemeriksaannya.
                </p>
                <a href="<?= base_url('modules/monitoring_master/create.php?type=group' . ($filterPhase > 0 ? '&phase_id=' . $filterPhase : '')) ?>" 
                   class="inline-flex items-center gap-2 px-5 py-2.5 text-xs font-bold text-white bg-blue-600 hover:bg-blue-700 rounded-xl transition shadow-sm cursor-pointer active:scale-95">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                    <span>Buat Kategori Pertama Sekarang</span>
                </a>
            </div>
        <?php else: ?>
            <div class="space-y-5">
                <?php foreach ($currentGroups as $grp): ?>
                    <?php 
                        $isStandalone = (count($grp['items']) === 1 && $grp['items'][0]['item_text'] === $grp['title']);
                    ?>
                    <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden transition-all">
                        
                        <!-- Category Header Bar -->
                        <div class="p-4 bg-slate-50 border-b border-slate-200 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                            <div class="flex items-center gap-3">
                                <span class="w-8 h-8 rounded-xl bg-blue-100 text-blue-700 font-black text-xs flex items-center justify-center flex-shrink-0 shadow-sm">
                                    <?= (int)$grp['sort_order'] ?>
                                </span>
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="text-sm md:text-base font-extrabold text-slate-800"><?= htmlspecialchars($grp['title']) ?></h3>
                                        <?php if ($isStandalone): ?>
                                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-blue-100 text-blue-800 border border-blue-300 shadow-sm" style="margin-left: 0.25rem;">
                                                <span class="w-1.5 h-1.5 rounded-full bg-blue-600 flex-shrink-0"></span>
                                                Poin Mandiri (Langsung Diceklis)
                                            </span>
                                        <?php elseif ($grp['rule_type'] === 'all_required'): ?>
                                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-rose-100 text-rose-800 border border-rose-300 shadow-sm" style="margin-left: 0.25rem;">
                                                <span class="w-1.5 h-1.5 rounded-full bg-rose-600 flex-shrink-0"></span>
                                                Wajib Semua
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800 border border-amber-300 shadow-sm" style="margin-left: 0.25rem;">
                                                <span class="w-1.5 h-1.5 rounded-full bg-amber-600 flex-shrink-0"></span>
                                                Min <?= (int)$grp['min_required'] ?> Butir
                                            </span>
                                        <?php endif; ?>

                                        <?php if (!$grp['is_active']): ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-200 text-slate-600">Nonaktif</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($grp['description'])): ?>
                                        <p class="text-[11px] text-slate-500 mt-0.5"><?= htmlspecialchars($grp['description']) ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Actions for Category & Add Item Button -->
                            <div class="flex flex-wrap items-center gap-2 self-end sm:self-center">
                                <!-- Prominent Button: Add Item to this Category -->
                                <button type="button" 
                                        onclick="openAddItemModal(<?= $grp['id'] ?>, '<?= addslashes(htmlspecialchars($grp['title'])) ?>', <?= $filterPhase ?>)"
                                        class="px-3 py-1.5 text-xs font-bold text-white bg-blue-600 hover:bg-blue-700 rounded-xl transition shadow-sm flex items-center gap-1.5 cursor-pointer active:scale-95">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                                    <span>+ Tambah Poin Pemeriksaan</span>
                                </button>

                                <!-- Edit Category Button -->
                                <button type="button" 
                                        onclick="openEditGroupModal(<?= $grp['id'] ?>, <?= $filterPhase ?>)"
                                        class="px-2.5 py-1.5 text-xs font-bold text-slate-700 bg-white hover:bg-slate-100 border border-slate-300 rounded-xl transition shadow-sm flex items-center gap-1 cursor-pointer"
                                        title="Ubah Nama & Aturan Kategori Ini">
                                    <svg class="w-3.5 h-3.5 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                                    <span>Edit</span>
                                </button>

                                <!-- Delete Category Button -->
                                <button type="button" 
                                        onclick="confirmDelete('group', <?= $grp['id'] ?>, '<?= addslashes(htmlspecialchars($grp['title'])) ?>', <?= $filterPhase ?>)"
                                        class="p-1.5 text-slate-400 hover:text-rose-600 hover:bg-rose-50 border border-slate-200 rounded-xl transition cursor-pointer"
                                        title="Hapus Kategori Ini Beserta Seluruh Poinnya">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                </button>
                            </div>
                        </div>

                        <!-- Items List Inside Category -->
                        <div class="divide-y divide-slate-100">
                            <?php if (empty($grp['items'])): ?>
                                <div class="p-6 text-center bg-slate-50/50">
                                    <p class="text-xs text-slate-400 font-semibold italic">Belum ada poin pemeriksaan pada kategori ini.</p>
                                    <button type="button" 
                                            onclick="openAddItemModal(<?= $grp['id'] ?>, '<?= addslashes(htmlspecialchars($grp['title'])) ?>', <?= $filterPhase ?>)"
                                            class="mt-2 text-xs font-bold text-blue-600 hover:underline cursor-pointer">
                                        + Tambah Poin Pemeriksaan Sekarang
                                    </button>
                                </div>
                            <?php else: ?>
                                <?php foreach ($grp['items'] as $it): ?>
                                    <div class="px-4 py-3 flex items-center justify-between gap-3 text-xs hover:bg-slate-50/80 transition <?= !$it['is_active'] ? 'opacity-60 bg-slate-50/40' : '' ?>">
                                        
                                        <!-- Left: Check Icon + Text + Badge -->
                                        <div class="flex items-center gap-3 min-w-0 flex-1">
                                            <div class="w-5 h-5 rounded-md <?= $it['is_active'] ? 'bg-emerald-600 text-white' : 'bg-slate-200 text-slate-400' ?> flex items-center justify-center flex-shrink-0 shadow-sm font-bold">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                                            </div>

                                            <div class="min-w-0 flex-1">
                                                <div class="flex items-center flex-wrap gap-2 leading-snug">
                                                    <span class="font-bold text-slate-800 text-xs md:text-sm">
                                                        <?= htmlspecialchars($it['item_text']) ?>
                                                    </span>
                                                    <?php if ($it['is_required']): ?>
                                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-rose-50 text-rose-700 border border-rose-200 shadow-sm select-none" style="margin-left: 0.5rem;">
                                                            <span class="w-1.5 h-1.5 rounded-full bg-rose-500 flex-shrink-0"></span>
                                                            Wajib
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-slate-100 text-slate-500 border border-slate-200 select-none" style="margin-left: 0.5rem;">
                                                            Opsional
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if (!empty($it['hint_guide'])): ?>
                                                    <p class="text-[11px] text-slate-400 italic mt-0.5"><?= htmlspecialchars($it['hint_guide']) ?></p>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <!-- Right: Action Buttons (Clear & Readable) -->
                                        <div class="flex items-center gap-2 flex-shrink-0">
                                            <!-- Toggle Active Status Pill -->
                                            <button type="button" 
                                                    onclick="quickToggle('item', <?= $it['id'] ?>, <?= $filterPhase ?>)"
                                                    class="px-2 py-1 rounded-lg text-[10px] font-bold cursor-pointer transition <?= $it['is_active'] ? 'bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100' : 'bg-slate-100 text-slate-500 border border-slate-200 hover:bg-slate-200' ?>"
                                                    title="Klik untuk ubah aktif / nonaktif">
                                                <?= $it['is_active'] ? '✓ Aktif' : 'Nonaktif' ?>
                                            </button>

                                            <!-- Edit Item Button -->
                                            <button type="button" 
                                                    onclick="openEditItemModal(<?= $it['id'] ?>, <?= $filterPhase ?>)"
                                                    class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-bold text-blue-700 bg-blue-50 hover:bg-blue-100 border border-blue-200 rounded-lg transition shadow-sm cursor-pointer">
                                                <svg class="w-3 h-3 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                                                <span>Edit</span>
                                            </button>

                                            <!-- Delete Item Button -->
                                            <button type="button" 
                                                    onclick="confirmDelete('item', <?= $it['id'] ?>, '<?= addslashes(htmlspecialchars($it['item_text'])) ?>', <?= $filterPhase ?>)"
                                                    class="p-1 text-slate-400 hover:text-rose-600 hover:bg-rose-50 border border-slate-200 rounded-lg transition cursor-pointer"
                                                    title="Hapus Butir Ini">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </main>

<!-- Style for smooth modal entrance animation -->
<style>
@keyframes modalCardPopIn {
    0% { opacity: 0; transform: scale(0.96) translateY(-8px); }
    100% { opacity: 1; transform: scale(1) translateY(0); }
}
.modal-window-card {
    animation: modalCardPopIn 0.18s cubic-bezier(0.16, 1, 0.3, 1);
}
</style>

<!-- ============================================================================== -->
<!-- 1. MODAL TAMBAH / EDIT BUTIR PEMERIKSAAN (ITEM)                                 -->
<!-- ============================================================================== -->
<div id="itemModal" 
     onclick="if(event.target === this) closeModal('itemModal')"
     style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; width:100vw; height:100vh; background:rgba(15, 23, 42, 0.75); backdrop-filter:blur(4px); -webkit-backdrop-filter:blur(4px); z-index:99999; align-items:center; justify-content:center; padding:16px; box-sizing:border-box;">
    
    <div class="modal-window-card" style="background:#ffffff; border-radius:18px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.4); width:100%; max-width:540px; border:1px solid #cbd5e1; overflow:hidden; margin:auto; display:flex; flex-direction:column;">
        
        <!-- Modal Header -->
        <div style="padding:16px 20px; background:#f8fafc; border-bottom:1px solid #e2e8f0; display:flex; align-items:center; justify-content:space-between;">
            <div style="display:flex; align-items:center; gap:10px;">
                <span style="width:4px; height:20px; background:#2563eb; border-radius:2px; display:inline-block;"></span>
                <div>
                    <h3 id="itemModalTitle" style="font-size:15px; font-weight:800; color:#0f172a; margin:0; line-height:1.2;">Tambah Poin Pemeriksaan</h3>
                    <p id="itemModalSubtitle" style="font-size:11px; font-weight:700; color:#2563eb; margin:2px 0 0 0;">Kategori: -</p>
                </div>
            </div>
            <button type="button" onclick="closeModal('itemModal')" 
                    style="background:#f1f5f9; border:none; border-radius:8px; width:32px; height:32px; display:flex; align-items:center; justify-content:center; cursor:pointer; color:#64748b; font-size:16px; font-weight:bold; transition:all 0.15s;"
                    onmouseover="this.style.background='#e2e8f0'; this.style.color='#0f172a'"
                    onmouseout="this.style.background='#f1f5f9'; this.style.color='#64748b'"
                    title="Tutup (Esc)">
                ✕
            </button>
        </div>

        <!-- Modal Form -->
        <form id="itemForm" method="POST" action="<?= base_url('modules/monitoring_master/store.php') ?>" style="padding:20px; display:flex; flex-direction:column; gap:16px; margin:0;">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="type" value="item">
            <input type="hidden" name="id" id="inputItemId" value="0">
            <input type="hidden" name="group_id" id="inputItemGroupId" value="0">
            <input type="hidden" name="redirect_phase" id="inputItemRedirectPhase" value="<?= $filterPhase ?>">

            <!-- Teks Poin Pemeriksaan -->
            <div>
                <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:6px;">
                    Kalimat Poin Pemeriksaan <span style="color:#e11d48;">*</span>
                </label>
                <textarea name="item_text" id="inputItemText" rows="2" required 
                          placeholder="Contoh: Cek kebersihan permukaan meja kerja dari debu & minyak..."
                          style="width:100%; box-sizing:border-box; font-size:13px; font-weight:500; border:1.5px solid #cbd5e1; border-radius:10px; padding:10px 12px; outline:none; font-family:inherit; transition:border 0.15s;"
                          onfocus="this.style.borderColor='#2563eb'" onblur="this.style.borderColor='#cbd5e1'"></textarea>
            </div>

            <!-- Petunjuk / Hint -->
            <div>
                <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:6px;">
                    Petunjuk / Panduan Cek <span style="font-weight:400; color:#64748b;">(Opsional)</span>
                </label>
                <input type="text" name="hint_guide" id="inputItemHint" 
                       placeholder="Contoh: Lap bersih permukaan sebelum part diletakkan" 
                       style="width:100%; box-sizing:border-box; font-size:13px; font-weight:500; border:1.5px solid #cbd5e1; border-radius:10px; padding:10px 12px; outline:none; font-family:inherit; transition:border 0.15s;"
                       onfocus="this.style.borderColor='#2563eb'" onblur="this.style.borderColor='#cbd5e1'">
            </div>

            <!-- Pilihan Wajib / Opsional -->
            <div>
                <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:6px;">
                    Sifat Pemeriksaan
                </label>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                    <label style="display:flex; align-items:center; gap:10px; padding:12px; border:1.5px solid #e2e8f0; border-radius:12px; cursor:pointer; background:#ffffff; transition:all 0.15s;"
                           onmouseover="this.style.borderColor='#93c5fd'" onmouseout="this.style.borderColor='#e2e8f0'">
                        <input type="radio" name="is_required" id="itemReq1" value="1" checked style="width:16px; height:16px; accent-color:#2563eb; cursor:pointer;">
                        <div>
                            <div style="font-size:13px; font-weight:700; color:#0f172a;">Wajib Diceklis</div>
                            <div style="font-size:11px; color:#64748b;">Harus centang agar lolos</div>
                        </div>
                    </label>
                    <label style="display:flex; align-items:center; gap:10px; padding:12px; border:1.5px solid #e2e8f0; border-radius:12px; cursor:pointer; background:#ffffff; transition:all 0.15s;"
                           onmouseover="this.style.borderColor='#93c5fd'" onmouseout="this.style.borderColor='#e2e8f0'">
                        <input type="radio" name="is_required" id="itemReq0" value="0" style="width:16px; height:16px; accent-color:#2563eb; cursor:pointer;">
                        <div>
                            <div style="font-size:13px; font-weight:700; color:#0f172a;">Opsional</div>
                            <div style="font-size:11px; color:#64748b;">Poin pelengkap tambahan</div>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Status Aktif -->
            <div style="padding-top:2px;">
                <label style="display:inline-flex; align-items:center; gap:10px; cursor:pointer;">
                    <input type="checkbox" name="is_active" id="inputItemActive" value="1" checked style="width:18px; height:18px; accent-color:#2563eb; cursor:pointer; border-radius:4px;">
                    <span style="font-size:13px; font-weight:700; color:#334155;">Aktifkan butir checklist ini sekarang</span>
                </label>
            </div>

            <!-- Modal Footer Buttons -->
            <div style="padding-top:16px; margin-top:8px; border-top:1px solid #e2e8f0; display:flex; align-items:center; justify-content:flex-end; gap:12px;">
                <button type="button" onclick="closeModal('itemModal')" 
                        style="padding:9px 18px; font-size:13px; font-weight:700; color:#475569; background:#f1f5f9; border:1px solid #cbd5e1; border-radius:10px; cursor:pointer; transition:all 0.15s;"
                        onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='#f1f5f9'">
                    Batal
                </button>
                <button type="submit" 
                        style="padding:10px 22px; font-size:13px; font-weight:800; color:#ffffff; background:#2563eb; border:none; border-radius:10px; cursor:pointer; box-shadow:0 2px 4px rgba(37,99,235,0.3); transition:all 0.15s;"
                        onmouseover="this.style.background='#1d4ed8'" onmouseout="this.style.background='#2563eb'">
                    Simpan Poin Pemeriksaan
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================================== -->
<!-- 2. MODAL BUAT / EDIT KATEGORI (GROUP)                                           -->
<!-- ============================================================================== -->
<div id="groupModal" 
     onclick="if(event.target === this) closeModal('groupModal')"
     style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; width:100vw; height:100vh; background:rgba(15, 23, 42, 0.75); backdrop-filter:blur(4px); -webkit-backdrop-filter:blur(4px); z-index:99999; align-items:center; justify-content:center; padding:16px; box-sizing:border-box;">
    
    <div class="modal-window-card" style="background:#ffffff; border-radius:18px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.4); width:100%; max-width:540px; border:1px solid #cbd5e1; overflow:hidden; margin:auto; display:flex; flex-direction:column;">
        
        <!-- Modal Header -->
        <div style="padding:16px 20px; background:#f8fafc; border-bottom:1px solid #e2e8f0; display:flex; align-items:center; justify-content:space-between;">
            <div style="display:flex; align-items:center; gap:10px;">
                <span style="width:4px; height:20px; background:#2563eb; border-radius:2px; display:inline-block;"></span>
                <div>
                    <h3 id="groupModalTitle" style="font-size:15px; font-weight:800; color:#0f172a; margin:0; line-height:1.2;">Buat Kategori Baru</h3>
                    <p id="groupModalSubtitle" style="font-size:11px; font-weight:700; color:#2563eb; margin:2px 0 0 0;">Tahap: -</p>
                </div>
            </div>
            <button type="button" onclick="closeModal('groupModal')" 
                    style="background:#f1f5f9; border:none; border-radius:8px; width:32px; height:32px; display:flex; align-items:center; justify-content:center; cursor:pointer; color:#64748b; font-size:16px; font-weight:bold; transition:all 0.15s;"
                    onmouseover="this.style.background='#e2e8f0'; this.style.color='#0f172a'"
                    onmouseout="this.style.background='#f1f5f9'; this.style.color='#64748b'"
                    title="Tutup (Esc)">
                ✕
            </button>
        </div>

        <!-- Modal Form -->
        <form id="groupForm" method="POST" action="<?= base_url('modules/monitoring_master/store.php') ?>" style="padding:20px; display:flex; flex-direction:column; gap:16px; margin:0;">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="type" value="group">
            <input type="hidden" name="id" id="inputGroupId" value="0">
            <input type="hidden" name="phase_id" id="inputGroupPhaseId" value="<?= $filterPhase ?>">
            <input type="hidden" name="redirect_phase" id="inputGroupRedirectPhase" value="<?= $filterPhase ?>">

            <!-- Nama Kategori -->
            <div>
                <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:6px;">
                    Nama Kategori Pemeriksaan <span style="color:#e11d48;">*</span>
                </label>
                <input type="text" name="title" id="inputGroupTitle" required 
                       placeholder="Contoh: Kebersihan Meja & Workstation OQC"
                       style="width:100%; box-sizing:border-box; font-size:13px; font-weight:500; border:1.5px solid #cbd5e1; border-radius:10px; padding:10px 12px; outline:none; font-family:inherit; transition:border 0.15s;"
                       onfocus="this.style.borderColor='#2563eb'" onblur="this.style.borderColor='#cbd5e1'">
            </div>

            <!-- Deskripsi Singkat -->
            <div>
                <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:6px;">
                    Deskripsi / Lokasi Pemeriksaan <span style="font-weight:400; color:#64748b;">(Opsional)</span>
                </label>
                <input type="text" name="description" id="inputGroupDesc" 
                       placeholder="Contoh: Pemeriksaan fisik lingkungan kerja inspektur" 
                       style="width:100%; box-sizing:border-box; font-size:13px; font-weight:500; border:1.5px solid #cbd5e1; border-radius:10px; padding:10px 12px; outline:none; font-family:inherit; transition:border 0.15s;"
                       onfocus="this.style.borderColor='#2563eb'" onblur="this.style.borderColor='#cbd5e1'">
            </div>

            <!-- Aturan Pengisian -->
            <div>
                <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:6px;">
                    Aturan Pengisian untuk Tim
                </label>
                <div style="display:flex; flex-direction:column; gap:10px;">
                    <label style="display:flex; align-items:flex-start; gap:10px; padding:12px; border:1.5px solid #e2e8f0; border-radius:12px; cursor:pointer; background:#ffffff; transition:all 0.15s;"
                           onmouseover="this.style.borderColor='#93c5fd'" onmouseout="this.style.borderColor='#e2e8f0'">
                        <input type="radio" name="rule_type" id="ruleAll" value="all_required" checked 
                               onchange="toggleMinReqBox(false)" style="margin-top:2px; width:16px; height:16px; accent-color:#2563eb; cursor:pointer;">
                        <div>
                            <span style="display:block; font-size:13px; font-weight:700; color:#0f172a;">✅ Semua Poin Wajib Dicek (Paling Umum)</span>
                            <span style="display:block; font-size:11px; color:#64748b; margin-top:2px;">Inspektur harus menyelesaikan seluruh poin di kategori ini</span>
                        </div>
                    </label>

                    <label style="display:flex; align-items:flex-start; gap:10px; padding:12px; border:1.5px solid #e2e8f0; border-radius:12px; cursor:pointer; background:#ffffff; transition:all 0.15s;"
                           onmouseover="this.style.borderColor='#93c5fd'" onmouseout="this.style.borderColor='#e2e8f0'">
                        <input type="radio" name="rule_type" id="ruleRep" value="representative" 
                               onchange="toggleMinReqBox(true)" style="margin-top:2px; width:16px; height:16px; accent-color:#2563eb; cursor:pointer;">
                        <div style="flex:1;">
                            <span style="display:block; font-size:13px; font-weight:700; color:#0f172a;">🎯 Cukup Perwakilan (Minimal Tertentu)</span>
                            <span style="display:block; font-size:11px; color:#64748b; margin-top:2px;">Hanya perlu mencentang minimal beberapa poin</span>
                            
                            <div id="minReqBox" style="margin-top:8px; display:none;">
                                <label style="font-size:11px; font-weight:700; color:#475569; display:block; margin-bottom:4px;">Minimal Poin yang Harus Dicek:</label>
                                <input type="number" name="min_required" id="inputGroupMinReq" value="1" min="1" 
                                       style="width:90px; box-sizing:border-box; font-size:13px; font-weight:700; border:1.5px solid #cbd5e1; border-radius:8px; padding:6px 10px; outline:none;">
                            </div>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Status Aktif -->
            <div style="padding-top:2px;">
                <label style="display:inline-flex; align-items:center; gap:10px; cursor:pointer;">
                    <input type="checkbox" name="is_active" id="inputGroupActive" value="1" checked style="width:18px; height:18px; accent-color:#2563eb; cursor:pointer; border-radius:4px;">
                    <span style="font-size:13px; font-weight:700; color:#334155;">Aktifkan kategori ini sekarang</span>
                </label>
            </div>

            <!-- Modal Footer Buttons -->
            <div style="padding-top:16px; margin-top:8px; border-top:1px solid #e2e8f0; display:flex; align-items:center; justify-content:flex-end; gap:12px;">
                <button type="button" onclick="closeModal('groupModal')" 
                        style="padding:9px 18px; font-size:13px; font-weight:700; color:#475569; background:#f1f5f9; border:1px solid #cbd5e1; border-radius:10px; cursor:pointer; transition:all 0.15s;"
                        onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='#f1f5f9'">
                    Batal
                </button>
                <button type="submit" 
                        style="padding:10px 22px; font-size:13px; font-weight:800; color:#ffffff; background:#2563eb; border:none; border-radius:10px; cursor:pointer; box-shadow:0 2px 4px rgba(37,99,235,0.3); transition:all 0.15s;"
                        onmouseover="this.style.background='#1d4ed8'" onmouseout="this.style.background='#2563eb'">
                    Simpan Kategori
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Hidden Form for Safe Deletion -->
<form id="deleteMasterForm" method="POST" action="<?= base_url('modules/monitoring_master/delete.php') ?>" class="hidden">
    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
    <input type="hidden" name="target_type" id="deleteTargetType" value="">
    <input type="hidden" name="target_id" id="deleteTargetId" value="">
    <input type="hidden" name="redirect_phase" id="deleteRedirectPhase" value="<?= $filterPhase ?>">
</form>

<!-- Hidden Form for Quick Toggle Active Status -->
<form id="quickToggleForm" method="POST" action="<?= base_url('modules/monitoring_master/toggle.php') ?>" class="hidden">
    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
    <input type="hidden" name="type" id="toggleType" value="">
    <input type="hidden" name="id" id="toggleId" value="">
    <input type="hidden" name="redirect_phase" id="toggleRedirectPhase" value="<?= $filterPhase ?>">
</form>

<script>
// Quick Status Toggle
function quickToggle(type, id, redirectPhase) {
    document.getElementById('toggleType').value = type;
    document.getElementById('toggleId').value = id;
    document.getElementById('toggleRedirectPhase').value = redirectPhase || 0;
    document.getElementById('quickToggleForm').submit();
}

// Modal Helpers
function closeModal(modalId) {
    const el = document.getElementById(modalId);
    if (el) {
        el.style.display = 'none';
        document.body.style.overflow = '';
    }
}

function openModal(modalId) {
    const el = document.getElementById(modalId);
    if (el) {
        el.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
}

function toggleMinReqBox(show) {
    const box = document.getElementById('minReqBox');
    if (!box) return;
    box.style.display = show ? 'block' : 'none';
}

// Global Escape listener to close modals
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' || e.keyCode === 27) {
        closeModal('itemModal');
        closeModal('groupModal');
    }
});

// ==========================================
// 1. TAMBAH / EDIT BUTIR PEMERIKSAAN (ITEM)
// ==========================================
function openAddItemModal(groupId, groupTitle, phaseId) {
    document.getElementById('itemForm').action = "<?= base_url('modules/monitoring_master/store.php') ?>";
    document.getElementById('itemModalTitle').innerText = "+ Tambah Poin Pemeriksaan Baru";
    document.getElementById('itemModalSubtitle').innerText = "Kategori: " + groupTitle;

    document.getElementById('inputItemId').value = 0;
    document.getElementById('inputItemGroupId').value = groupId;
    document.getElementById('inputItemRedirectPhase').value = phaseId;
    document.getElementById('inputItemText').value = "";
    document.getElementById('inputItemHint').value = "";
    document.getElementById('itemReq1').checked = true;
    document.getElementById('inputItemActive').checked = true;

    openModal('itemModal');
    setTimeout(() => document.getElementById('inputItemText').focus(), 100);
}

function openEditItemModal(itemId, phaseId) {
    fetch('ajax_get_data.php?type=item&id=' + itemId)
        .then(res => res.json())
        .then(res => {
            if (res.success && res.data) {
                const it = res.data;
                document.getElementById('itemForm').action = "<?= base_url('modules/monitoring_master/update.php') ?>";
                document.getElementById('itemModalTitle').innerText = "Edit Poin Pemeriksaan";
                document.getElementById('itemModalSubtitle').innerText = "Kategori: " + (it.group_title || '-');

                document.getElementById('inputItemId').value = it.id;
                document.getElementById('inputItemGroupId').value = it.group_id;
                document.getElementById('inputItemRedirectPhase').value = phaseId;
                document.getElementById('inputItemText').value = it.item_text || "";
                document.getElementById('inputItemHint').value = it.hint_guide || "";

                if (parseInt(it.is_required) === 1) {
                    document.getElementById('itemReq1').checked = true;
                } else {
                    document.getElementById('itemReq0').checked = true;
                }

                document.getElementById('inputItemActive').checked = (parseInt(it.is_active) === 1);

                openModal('itemModal');
                setTimeout(() => document.getElementById('inputItemText').focus(), 100);
            } else {
                alert('Gagal mengambil data butir pemeriksaan.');
            }
        })
        .catch(err => {
            alert('Gangguan koneksi ke server.');
        });
}

// ==========================================
// 2. TAMBAH / EDIT KATEGORI (GROUP)
// ==========================================
function openAddGroupModal(phaseId, phaseName) {
    document.getElementById('groupForm').action = "<?= base_url('modules/monitoring_master/store.php') ?>";
    document.getElementById('groupModalTitle').innerText = "+ Buat Kategori Baru";
    document.getElementById('groupModalSubtitle').innerText = "Untuk: " + phaseName;

    document.getElementById('inputGroupId').value = 0;
    document.getElementById('inputGroupPhaseId').value = phaseId;
    document.getElementById('inputGroupRedirectPhase').value = phaseId;
    document.getElementById('inputGroupTitle').value = "";
    document.getElementById('inputGroupDesc').value = "";
    document.getElementById('ruleAll').checked = true;
    toggleMinReqBox(false);
    document.getElementById('inputGroupMinReq').value = 1;
    document.getElementById('inputGroupActive').checked = true;

    openModal('groupModal');
    setTimeout(() => document.getElementById('inputGroupTitle').focus(), 100);
}

function openEditGroupModal(groupId, phaseId) {
    fetch('ajax_get_data.php?type=group&id=' + groupId)
        .then(res => res.json())
        .then(res => {
            if (res.success && res.data) {
                const g = res.data;
                document.getElementById('groupForm').action = "<?= base_url('modules/monitoring_master/update.php') ?>";
                document.getElementById('groupModalTitle').innerText = "Edit Kategori Pemeriksaan";
                document.getElementById('groupModalSubtitle').innerText = "Tahap: " + (g.phase_name || '-');

                document.getElementById('inputGroupId').value = g.id;
                document.getElementById('inputGroupPhaseId').value = g.phase_id;
                document.getElementById('inputGroupRedirectPhase').value = phaseId;
                document.getElementById('inputGroupTitle').value = g.title || "";
                document.getElementById('inputGroupDesc').value = g.description || "";

                if (g.rule_type === 'representative') {
                    document.getElementById('ruleRep').checked = true;
                    toggleMinReqBox(true);
                    document.getElementById('inputGroupMinReq').value = g.min_required || 1;
                } else {
                    document.getElementById('ruleAll').checked = true;
                    toggleMinReqBox(false);
                }

                document.getElementById('inputGroupActive').checked = (parseInt(g.is_active) === 1);

                openModal('groupModal');
                setTimeout(() => document.getElementById('inputGroupTitle').focus(), 100);
            } else {
                alert('Gagal mengambil data kategori.');
            }
        })
        .catch(err => {
            alert('Gangguan koneksi ke server.');
        });
}

// Konfirmasi Hapus Aman
function confirmDelete(type, id, name, redirectPhase) {
    const typeLabels = {
        'group': 'Kategori (seluruh butir checklist di dalamnya akan ikut terhapus)',
        'item': 'Poin Pemeriksaan'
    };
    const label = typeLabels[type] || type;

    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Hapus Data?',
            html: `Apakah Anda yakin ingin menghapus <b>${name}</b>?<br><span class="text-xs text-rose-600 font-semibold">Tipe: ${label}</span>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#e11d48',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Ya, Hapus!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('deleteTargetType').value = type;
                document.getElementById('deleteTargetId').value = id;
                document.getElementById('deleteRedirectPhase').value = redirectPhase || 0;
                document.getElementById('deleteMasterForm').submit();
            }
        });
    } else {
        if (confirm(`Apakah Anda yakin ingin menghapus "${name}"?\nTipe: ${label}`)) {
            document.getElementById('deleteTargetType').value = type;
            document.getElementById('deleteTargetId').value = id;
            document.getElementById('deleteRedirectPhase').value = redirectPhase || 0;
            document.getElementById('deleteMasterForm').submit();
        }
    }
}
</script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
