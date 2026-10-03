<?php
$pageTitle = "Edit Role & Hak Akses";
$breadcrumbCategory = "PENGATURAN";
$pageSubtitle = "Perbarui konfigurasi peran dan hak akses menu sistem";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';

// Enforce RBAC guard
require_menu_access('roles');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    set_flash('danger', 'ID Role tidak valid!');
    redirect('modules/roles/index.php');
}

$pdo = getDB();
$role = null;
$currentPermissions = [];

if ($pdo) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM roles WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $role = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($role) {
            $stmtPerms = $pdo->prepare("SELECT menu_key FROM role_permissions WHERE role_id = :id AND can_view = 1");
            $stmtPerms->execute([':id' => $id]);
            $currentPermissions = $stmtPerms->fetchAll(PDO::FETCH_COLUMN) ?: [];
        }
    } catch (PDOException $e) {
        set_flash('danger', 'Gagal memuat role: ' . $e->getMessage());
        redirect('modules/roles/index.php');
    }
}

if (!$role) {
    set_flash('danger', 'Data role tidak ditemukan!');
    redirect('modules/roles/index.php');
}

$systemMenus = get_system_menus();
$isAdmin = ($role['role_name'] === 'admin');
$isSystem = ((int)$role['is_system'] === 1);
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-6 space-y-4 max-w-5xl">
        
        <?= render_flash() ?>

        <div class="flex items-center space-x-3 mb-2">
            <a href="<?= base_url('modules/roles/index.php') ?>" class="btn-secondary py-1.5 px-3 text-xs">
                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                </svg>
                Kembali
            </a>
            <div>
                <h2 class="text-lg font-bold text-slate-800 leading-tight">
                    Edit Role: <?= htmlspecialchars($role['display_name']) ?>
                </h2>
                <p class="text-xs text-slate-500">Perbarui informasi peran atau centang hak akses menu yang diperbolehkan.</p>
            </div>
        </div>

        <?php if ($isAdmin): ?>
            <div class="p-3 bg-amber-50 border border-amber-200 rounded-xl text-amber-800 text-xs flex items-center space-x-2">
                <svg class="w-5 h-5 text-amber-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <span><strong>Catatan:</strong> Role <strong>Administrator</strong> adalah role bawaan sistem utama yang secara permanen memiliki hak akses penuh ke seluruh modul sistem.</span>
            </div>
        <?php endif; ?>

        <form action="<?= base_url('modules/roles/update.php') ?>" method="POST" class="space-y-4">
            <input type="hidden" name="id" value="<?= (int)$role['id'] ?>">
            
            <!-- Card 1: Nama Role -->
            <div class="card p-4 bg-white border border-slate-200/80 rounded-xl shadow-xs space-y-3">
                <div>
                    <label for="display_name" class="form-label text-xs font-semibold text-slate-700">
                        Nama Role <span class="text-rose-500">*</span>
                    </label>
                    <input type="text" id="display_name" name="display_name" value="<?= htmlspecialchars($role['display_name']) ?>" 
                           <?= $isAdmin ? 'readonly class="form-input text-xs bg-slate-100 text-slate-500 cursor-not-allowed max-w-md"' : 'required class="form-input text-xs max-w-md"' ?>>
                    <p class="text-[11px] text-slate-400 mt-1">Nama role yang digunakan pada sistem.</p>
                </div>
            </div>

            <!-- Card 2: Matriks Hak Akses Menu -->
            <div class="card p-5 bg-white border border-slate-200/80 rounded-xl shadow-xs space-y-4">
                <div class="flex items-center justify-between border-b border-slate-100 pb-2 flex-wrap gap-2">
                    <div>
                        <h3 class="text-xs font-bold text-slate-700 uppercase tracking-wider">
                            2. Matriks Izin Akses Menu (can_view)
                        </h3>
                        <p class="text-[11px] text-slate-500">Pilih menu apa saja yang dapat dilihat dan dibuka oleh pengguna dengan role ini.</p>
                    </div>
                    <?php if (!$isAdmin): ?>
                        <div class="flex items-center space-x-2">
                            <button type="button" onclick="toggleAllPermissions(true)" class="text-[11px] font-semibold text-blue-600 hover:underline">
                                Pilih Semua
                            </button>
                            <span class="text-slate-300">|</span>
                            <button type="button" onclick="toggleAllPermissions(false)" class="text-[11px] font-semibold text-slate-500 hover:underline">
                                Kosongkan
                            </button>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php foreach ($systemMenus as $category => $menus): ?>
                        <div class="border border-slate-200 rounded-lg p-3 bg-slate-50/50">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-[11px] font-bold text-slate-700 uppercase tracking-wider">
                                    <?= htmlspecialchars($category) ?>
                                </span>
                                <?php if (!$isAdmin): ?>
                                    <button type="button" onclick="toggleGroup('grp_<?= md5($category) ?>', true)" class="text-[10px] text-blue-600 hover:underline">
                                        Pilih Grup
                                    </button>
                                <?php endif; ?>
                            </div>
                            <div class="space-y-2 grp_<?= md5($category) ?>">
                                <?php foreach ($menus as $key => $meta): 
                                    $isChecked = $isAdmin || in_array($key, $currentPermissions, true);
                                ?>
                                    <label class="flex items-start space-x-2.5 p-2 rounded-lg bg-white border border-slate-200/80 hover:border-blue-300 cursor-pointer transition-all">
                                        <input type="checkbox" name="permissions[]" value="<?= htmlspecialchars($key) ?>" 
                                               class="perm-checkbox mt-0.5 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                                               <?= $isChecked ? 'checked' : '' ?>
                                               <?= $isAdmin ? 'disabled' : '' ?>>
                                        <div class="min-w-0 flex-1">
                                            <div class="text-xs font-semibold text-slate-800">
                                                <?= htmlspecialchars($meta['label']) ?>
                                                <span class="text-[10px] font-mono text-slate-400 font-normal ml-1">(<?= htmlspecialchars($key) ?>)</span>
                                            </div>
                                            <div class="text-[11px] text-slate-500 leading-tight">
                                                <?= htmlspecialchars($meta['desc']) ?>
                                            </div>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Submit Button Bar -->
            <div class="flex items-center justify-end space-x-3 pt-2">
                <a href="<?= base_url('modules/roles/index.php') ?>" class="btn-secondary text-xs py-2 px-4">Batal</a>
                <button type="submit" class="btn-primary text-xs py-2 px-5">Perbarui Role</button>
            </div>

        </form>

    </main>

    <script>
    function toggleAllPermissions(checked) {
        document.querySelectorAll('.perm-checkbox:not(:disabled)').forEach(cb => {
            cb.checked = checked;
        });
    }

    function toggleGroup(groupClass, checked) {
        document.querySelectorAll('.' + groupClass + ' .perm-checkbox:not(:disabled)').forEach(cb => {
            cb.checked = checked;
        });
    }
    </script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
