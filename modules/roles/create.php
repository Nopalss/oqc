<?php
$pageTitle = "Tambah Role Baru";
$breadcrumbCategory = "PENGATURAN";
$pageSubtitle = "Definisikan peran baru dan tentukan hak akses menu";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';

// Enforce RBAC guard
require_menu_access('roles');

$systemMenus = get_system_menus();
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
                <h2 class="text-lg font-bold text-slate-800 leading-tight">Tambah Role & Hak Akses Baru</h2>
                <p class="text-xs text-slate-500">Tentukan nama peran dan centang menu yang boleh diakses pengguna dengan role ini.</p>
            </div>
        </div>

        <form action="<?= base_url('modules/roles/store.php') ?>" method="POST" class="space-y-4">
            
            <!-- Card 1: Nama Role -->
            <div class="card p-4 bg-white border border-slate-200/80 rounded-xl shadow-xs space-y-3">
                <div>
                    <label for="display_name" class="form-label text-xs font-semibold text-slate-700">
                        Nama Role <span class="text-rose-500">*</span>
                    </label>
                    <input type="text" id="display_name" name="display_name" required autofocus
                           class="form-input text-xs max-w-md" placeholder="Contoh: QC Inspector, Operator Line, Supervisor">
                    <p class="text-[11px] text-slate-400 mt-1">Masukkan nama role yang ingin Anda buat.</p>
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
                    <div class="flex items-center space-x-2">
                        <button type="button" onclick="toggleAllPermissions(true)" class="text-[11px] font-semibold text-blue-600 hover:underline">
                            Pilih Semua
                        </button>
                        <span class="text-slate-300">|</span>
                        <button type="button" onclick="toggleAllPermissions(false)" class="text-[11px] font-semibold text-slate-500 hover:underline">
                            Kosongkan
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php foreach ($systemMenus as $category => $menus): ?>
                        <div class="border border-slate-200 rounded-lg p-3 bg-slate-50/50">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-[11px] font-bold text-slate-700 uppercase tracking-wider">
                                    <?= htmlspecialchars($category) ?>
                                </span>
                                <button type="button" onclick="toggleGroup('grp_<?= md5($category) ?>', true)" class="text-[10px] text-blue-600 hover:underline">
                                    Pilih Grup
                                </button>
                            </div>
                            <div class="space-y-2 grp_<?= md5($category) ?>">
                                <?php foreach ($menus as $key => $meta): ?>
                                    <label class="flex items-start space-x-2.5 p-2 rounded-lg bg-white border border-slate-200/80 hover:border-blue-300 cursor-pointer transition-all">
                                        <input type="checkbox" name="permissions[]" value="<?= htmlspecialchars($key) ?>" 
                                               class="perm-checkbox mt-0.5 rounded border-slate-300 text-blue-600 focus:ring-blue-500">
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
                <button type="submit" class="btn-primary text-xs py-2 px-5">Simpan Role Baru</button>
            </div>

        </form>

    </main>

    <script>
    function toggleAllPermissions(checked) {
        document.querySelectorAll('.perm-checkbox').forEach(cb => {
            cb.checked = checked;
        });
    }

    function toggleGroup(groupClass, checked) {
        document.querySelectorAll('.' + groupClass + ' .perm-checkbox').forEach(cb => {
            cb.checked = checked;
        });
    }
    </script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
