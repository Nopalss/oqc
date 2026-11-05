<?php
/**
 * Form Edit Master Template Monitoring OQC
 * Mengubah Tahap (Phase), Grup/Kategori, atau Butir Item Checklist
 */
$pageTitle = "Edit Template Monitoring";
$breadcrumbCategory = "MASTER DATA";
$pageSubtitle = "Edit Data Tahap SOP, Grup Kategori 5S, atau Butir Item Checklist";

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

require_menu_access('monitoring_master');

$pdo = getDB();

$type = sanitize($_GET['type'] ?? 'item');
if (!in_array($type, ['phase', 'group', 'item'])) {
    $type = 'item';
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    set_flash('danger', 'ID data tidak valid.');
    redirect('modules/monitoring_master/index.php');
}

$data = null;
$phases = [];
$groups = [];

if ($pdo) {
    try {
        if ($type === 'phase') {
            $stmt = $pdo->prepare("SELECT * FROM oqc_monitoring_phases WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
        } elseif ($type === 'group') {
            $stmt = $pdo->prepare("SELECT * FROM oqc_monitoring_groups WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            $phases = $pdo->query("SELECT id, name, sort_order FROM oqc_monitoring_phases ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } else {
            $stmt = $pdo->prepare("SELECT * FROM oqc_monitoring_items WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $id]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            $groups = $pdo->query("SELECT g.id, g.title, g.phase_id, p.name as phase_name 
                                   FROM oqc_monitoring_groups g 
                                   JOIN oqc_monitoring_phases p ON g.phase_id = p.id 
                                   ORDER BY p.sort_order ASC, g.sort_order ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (PDOException $e) {
        set_flash('danger', 'Gagal memuat data: ' . $e->getMessage());
        redirect('modules/monitoring_master/index.php');
    }
}

if (!$data) {
    set_flash('danger', 'Data tidak ditemukan.');
    redirect('modules/monitoring_master/index.php');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-6 space-y-5 max-w-4xl w-full mx-auto">
        
        <?= render_flash() ?>

    <div class="flex items-center justify-between">
        <a href="<?= base_url('modules/monitoring_master/index.php') ?>" 
           class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-600 hover:text-slate-900 bg-white border border-slate-200 px-3 py-2 rounded-xl transition shadow-2xs">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
            Kembali ke Daftar Master
        </a>
    </div>

    <div class="bg-white border border-slate-200 rounded-2xl shadow-2xs overflow-hidden">
        <div class="p-5 border-b border-slate-200 bg-slate-50/50 flex items-center justify-between">
            <div>
                <h3 class="text-base font-bold text-slate-800">
                    <?php if ($type === 'phase'): ?>
                        Edit Tahap SOP: <?= htmlspecialchars($data['name']) ?>
                    <?php elseif ($type === 'group'): ?>
                        Edit Grup / Kategori 5S: <?= htmlspecialchars($data['title']) ?>
                    <?php else: ?>
                        Edit Butir Item Checklist
                    <?php endif; ?>
                </h3>
                <p class="text-xs text-slate-500">Perubahan akan langsung berpengaruh pada formulir inspektur</p>
            </div>
            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200">
                Mode: Edit <?= strtoupper($type) ?>
            </span>
        </div>

        <form action="<?= base_url('modules/monitoring_master/update.php') ?>" method="POST" class="p-6 space-y-5">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="type" value="<?= $type ?>">
            <input type="hidden" name="id" value="<?= $data['id'] ?>">

            <?php if ($type === 'phase'): ?>
                <!-- ================= EDIT PHASE ================= -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Nama Tahap <span class="text-rose-500">*</span></label>
                    <input type="text" name="name" required value="<?= htmlspecialchars($data['name']) ?>" 
                           class="w-full text-xs font-medium border border-slate-200 rounded-xl px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Kode Unik Tahap <span class="text-rose-500">*</span></label>
                        <input type="text" name="phase_code" required value="<?= htmlspecialchars($data['phase_code']) ?>" 
                               class="w-full text-xs font-medium border border-slate-200 rounded-xl px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Trigger Event <span class="text-rose-500">*</span></label>
                        <select name="trigger_event" class="w-full text-xs font-medium border border-slate-200 rounded-xl px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="post_login" <?= $data['trigger_event'] === 'post_login' ? 'selected' : '' ?>>Gatekeeper: Muncul Setelah Login</option>
                            <option value="in_process" <?= $data['trigger_event'] === 'in_process' ? 'selected' : '' ?>>Saat Proses Kerja (Draft)</option>
                            <option value="pre_logout" <?= $data['trigger_event'] === 'pre_logout' ? 'selected' : '' ?>>Gatekeeper: Muncul Sebelum Logout</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Deskripsi Tambahan</label>
                    <textarea name="description" rows="2" 
                              class="w-full text-xs font-medium border border-slate-200 rounded-xl px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-blue-500"><?= htmlspecialchars($data['description'] ?? '') ?></textarea>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Urutan Tampilan</label>
                        <input type="number" name="sort_order" value="<?= (int)$data['sort_order'] ?>" min="1" 
                               class="w-full text-xs font-medium border border-slate-200 rounded-xl px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Status Tahap</label>
                        <select name="is_active" class="w-full text-xs font-medium border border-slate-200 rounded-xl px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="1" <?= $data['is_active'] ? 'selected' : '' ?>>Aktif (Live di Sistem)</option>
                            <option value="0" <?= !$data['is_active'] ? 'selected' : '' ?>>Nonaktif / Draft</option>
                        </select>
                    </div>
                </div>

            <?php elseif ($type === 'group'): ?>
                <!-- ================= EDIT GROUP ================= -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Pilih Tahap SOP <span class="text-rose-500">*</span></label>
                    <select name="phase_id" required class="w-full text-xs font-medium border border-slate-200 rounded-xl px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        <?php foreach ($phases as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= (int)$data['phase_id'] === (int)$p['id'] ? 'selected' : '' ?>>
                                [Tahap <?= (int)$p['sort_order'] ?>] <?= htmlspecialchars($p['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Judul Grup / Kategori 5S <span class="text-rose-500">*</span></label>
                    <input type="text" name="title" required value="<?= htmlspecialchars($data['title']) ?>" 
                           class="w-full text-xs font-medium border border-slate-200 rounded-xl px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Aturan Pengisian <span class="text-rose-500">*</span></label>
                        <select name="rule_type" id="ruleTypeSelect" class="w-full text-xs font-medium border border-slate-200 rounded-xl px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-indigo-500" onchange="toggleMinReq(this.value)">
                            <option value="all_required" <?= $data['rule_type'] === 'all_required' ? 'selected' : '' ?>>Wajib Semua Diceklis (Mandatory All)</option>
                            <option value="representative" <?= $data['rule_type'] === 'representative' ? 'selected' : '' ?>>Cukup Perwakilan (Sample / Representatif)</option>
                        </select>
                    </div>

                    <div id="minReqWrapper" class="<?= $data['rule_type'] === 'representative' ? '' : 'hidden' ?>">
                        <label class="block text-xs font-bold text-slate-700 mb-1">Minimal Item yang Wajib Diceklis <span class="text-rose-500">*</span></label>
                        <input type="number" name="min_required" id="minRequiredInput" value="<?= max(1, (int)$data['min_required']) ?>" min="1" 
                               class="w-full text-xs font-medium border border-slate-200 rounded-xl px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Deskripsi Tambahan</label>
                    <textarea name="description" rows="2" 
                              class="w-full text-xs font-medium border border-slate-200 rounded-xl px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-indigo-500"><?= htmlspecialchars($data['description'] ?? '') ?></textarea>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Urutan Tampilan</label>
                        <input type="number" name="sort_order" value="<?= (int)$data['sort_order'] ?>" min="1" 
                               class="w-full text-xs font-medium border border-slate-200 rounded-xl px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Status Kategori</label>
                        <select name="is_active" class="w-full text-xs font-medium border border-slate-200 rounded-xl px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            <option value="1" <?= $data['is_active'] ? 'selected' : '' ?>>Aktif</option>
                            <option value="0" <?= !$data['is_active'] ? 'selected' : '' ?>>Nonaktif</option>
                        </select>
                    </div>
                </div>

            <?php else: ?>
                <!-- ================= EDIT ITEM ================= -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Pilih Kategori 5S <span class="text-rose-500">*</span></label>
                    <select name="group_id" required class="w-full text-xs font-medium border border-slate-200 rounded-xl px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                        <?php foreach ($groups as $g): ?>
                            <option value="<?= $g['id'] ?>" <?= (int)$data['group_id'] === (int)$g['id'] ? 'selected' : '' ?>>
                                [<?= htmlspecialchars($g['phase_name']) ?>] &rarr; <?= htmlspecialchars($g['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Teks Butir Checklist <span class="text-rose-500">*</span></label>
                    <textarea name="item_text" rows="2" required 
                              class="w-full text-xs font-medium border border-slate-200 rounded-xl px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-emerald-500"><?= htmlspecialchars($data['item_text']) ?></textarea>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Panduan Singkat / SOP Reference (Hint)</label>
                    <input type="text" name="hint_guide" value="<?= htmlspecialchars($data['hint_guide'] ?? '') ?>" 
                           class="w-full text-xs font-medium border border-slate-200 rounded-xl px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Sifat Butir <span class="text-rose-500">*</span></label>
                        <select name="is_required" class="w-full text-xs font-medium border border-slate-200 rounded-xl px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                            <option value="1" <?= $data['is_required'] ? 'selected' : '' ?>>Wajib Diceklis</option>
                            <option value="0" <?= !$data['is_required'] ? 'selected' : '' ?>>Opsional</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Urutan Tampilan</label>
                        <input type="number" name="sort_order" value="<?= (int)$data['sort_order'] ?>" min="1" 
                               class="w-full text-xs font-medium border border-slate-200 rounded-xl px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Status Item</label>
                        <select name="is_active" class="w-full text-xs font-medium border border-slate-200 rounded-xl px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                            <option value="1" <?= $data['is_active'] ? 'selected' : '' ?>>Aktif</option>
                            <option value="0" <?= !$data['is_active'] ? 'selected' : '' ?>>Nonaktif</option>
                        </select>
                    </div>
                </div>
            <?php endif; ?>

            <div class="pt-4 border-t border-slate-100 flex items-center justify-end gap-2">
                <a href="<?= base_url('modules/monitoring_master/index.php') ?>" 
                   class="px-4 py-2.5 text-xs font-bold text-slate-600 hover:bg-slate-100 rounded-xl transition">
                    Batal
                </a>
                <button type="submit" 
                        class="px-5 py-2.5 text-xs font-bold text-white bg-blue-600 hover:bg-blue-700 rounded-xl transition shadow-2xs">
                    Perbarui Data Template
                </button>
            </div>
        </form>
    </div>
    </main>

<script>
function toggleMinReq(val) {
    const wrap = document.getElementById('minReqWrapper');
    if (!wrap) return;
    if (val === 'representative') {
        wrap.classList.remove('hidden');
    } else {
        wrap.classList.add('hidden');
    }
}
</script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
