<?php
$pageTitle = "Tambah User Baru";
$breadcrumbCategory = "PENGATURAN";
$pageSubtitle = "Tambah akun pengguna baru dan tetapkan role hak akses";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';

require_menu_access('users');

$pdo = getDB();
$roles = [];

if ($pdo) {
    try {
        $stmtRoles = $pdo->query("SELECT role_name, display_name FROM roles ORDER BY id ASC");
        $roles = $stmtRoles->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $roles = [];
    }
}
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-6 space-y-4 max-w-4xl">
        
        <?= render_flash() ?>

        <div class="flex items-center space-x-3 mb-2">
            <a href="<?= base_url('modules/users/index.php') ?>" class="btn-secondary py-1.5 px-3 text-xs">
                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                </svg>
                Kembali
            </a>
            <div>
                <h2 class="text-lg font-bold text-slate-800 leading-tight">Form Tambah User Baru</h2>
                <p class="text-xs text-slate-500">Buat kredensial akun dan tentukan role hak akses pengguna.</p>
            </div>
        </div>

        <div class="card p-5 bg-white border border-slate-200/80 rounded-xl shadow-xs">
            <form action="<?= base_url('modules/users/store.php') ?>" method="POST" class="space-y-5">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    
                    <!-- Nama Lengkap -->
                    <div>
                        <label for="name" class="form-label text-xs font-semibold text-slate-700">Nama Lengkap <span class="text-rose-500">*</span></label>
                        <input type="text" id="name" name="name" required class="form-input text-xs" placeholder="Contoh: Budi Santoso">
                    </div>

                    <!-- Username -->
                    <div>
                        <label for="username" class="form-label text-xs font-semibold text-slate-700">Username Login <span class="text-rose-500">*</span></label>
                        <input type="text" id="username" name="username" required pattern="^[a-zA-Z0-9_.-]+$" class="form-input text-xs" placeholder="Contoh: budi_qc">
                        <p class="text-[11px] text-slate-400 mt-1">Digunakan saat proses login ke aplikasi.</p>
                    </div>

                    <!-- Password -->
                    <div>
                        <label for="password" class="form-label text-xs font-semibold text-slate-700">Password <span class="text-rose-500">*</span></label>
                        <input type="password" id="password" name="password" required minlength="4" class="form-input text-xs" placeholder="Minimal 4 karakter">
                    </div>

                    <!-- Role Akses (Dynamic from roles table) -->
                    <div>
                        <label for="role" class="form-label text-xs font-semibold text-slate-700">Role Akses <span class="text-rose-500">*</span></label>
                        <select id="role" name="role" required class="form-input text-xs">
                            <?php if (empty($roles)): ?>
                                <option value="admin">Administrator</option>
                            <?php else: ?>
                                <?php foreach ($roles as $r): ?>
                                    <option value="<?= htmlspecialchars($r['role_name']) ?>">
                                        <?= htmlspecialchars($r['display_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                        <p class="text-[11px] text-slate-400 mt-1">Hak akses menu ditentukan berdasarkan role ini.</p>
                    </div>

                    <!-- Status -->
                    <div>
                        <label for="status" class="form-label text-xs font-semibold text-slate-700">Status Akun <span class="text-rose-500">*</span></label>
                        <select id="status" name="status" required class="form-input text-xs">
                            <option value="active">Aktif</option>
                            <option value="inactive">Non-Aktif</option>
                        </select>
                    </div>

                </div>

                <!-- Submit Button Bar -->
                <div class="flex items-center justify-end space-x-3 pt-3 border-t border-slate-100">
                    <a href="<?= base_url('modules/users/index.php') ?>" class="btn-secondary text-xs py-2 px-4">Batal</a>
                    <button type="submit" class="btn-primary text-xs py-2 px-5">Simpan User</button>
                </div>

            </form>
        </div>

    </main>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
