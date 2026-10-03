<?php
$pageTitle = "Edit Data User";
$breadcrumbCategory = "PENGATURAN";
$pageSubtitle = "Perbarui profil akun pengguna, kredensial, dan role hak akses";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';

require_menu_access('users');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    set_flash('danger', 'ID User tidak valid!');
    redirect('modules/users/index.php');
}

$user = null;
$roles = [];
$pdo = getDB();

if ($pdo) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmtRoles = $pdo->query("SELECT role_name, display_name FROM roles ORDER BY id ASC");
        $roles = $stmtRoles->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        set_flash('danger', 'Gagal memuat data user: ' . $e->getMessage());
        redirect('modules/users/index.php');
    }
}

if (!$user) {
    set_flash('danger', 'Data User tidak ditemukan!');
    redirect('modules/users/index.php');
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
                <h2 class="text-lg font-bold text-slate-800 leading-tight">Form Edit User #<?= htmlspecialchars($user['id']) ?></h2>
                <p class="text-xs text-slate-500">Perbarui informasi nama, username, role, atau ubah password pengguna.</p>
            </div>
        </div>

        <div class="card p-5 bg-white border border-slate-200/80 rounded-xl shadow-xs">
            <form action="<?= base_url('modules/users/update.php') ?>" method="POST" class="space-y-5">
                
                <input type="hidden" name="id" value="<?= htmlspecialchars($user['id']) ?>">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    
                    <!-- Nama Lengkap -->
                    <div>
                        <label for="name" class="form-label text-xs font-semibold text-slate-700">Nama Lengkap <span class="text-rose-500">*</span></label>
                        <input type="text" id="name" name="name" value="<?= htmlspecialchars($user['name']) ?>" required class="form-input text-xs">
                    </div>

                    <!-- Username -->
                    <div>
                        <label for="username" class="form-label text-xs font-semibold text-slate-700">Username Login <span class="text-rose-500">*</span></label>
                        <input type="text" id="username" name="username" value="<?= htmlspecialchars($user['username'] ?? '') ?>" required pattern="^[a-zA-Z0-9_.-]+$" class="form-input text-xs">
                        <p class="text-[11px] text-slate-400 mt-1">Digunakan untuk login ke aplikasi.</p>
                    </div>

                    <!-- Password Baru (Opsional) -->
                    <div>
                        <label for="password" class="form-label text-xs font-semibold text-slate-700">Password Baru</label>
                        <input type="password" id="password" name="password" minlength="4" class="form-input text-xs" placeholder="Kosongkan jika tidak ingin diubah">
                        <p class="text-[11px] text-slate-400 mt-1">Hanya diisi jika ingin mereset password pengguna.</p>
                    </div>

                    <!-- Role Akses -->
                    <div>
                        <label for="role" class="form-label text-xs font-semibold text-slate-700">Role Akses <span class="text-rose-500">*</span></label>
                        <select id="role" name="role" required class="form-input text-xs">
                            <?php foreach ($roles as $r): ?>
                                <option value="<?= htmlspecialchars($r['role_name']) ?>" <?= ($user['role'] === $r['role_name']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($r['display_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Status -->
                    <div>
                        <label for="status" class="form-label text-xs font-semibold text-slate-700">Status Akun <span class="text-rose-500">*</span></label>
                        <select id="status" name="status" required class="form-input text-xs">
                            <option value="active" <?= (($user['status'] ?? 'active') === 'active') ? 'selected' : '' ?>>Aktif</option>
                            <option value="inactive" <?= (($user['status'] ?? 'active') === 'inactive') ? 'selected' : '' ?>>Non-Aktif</option>
                        </select>
                    </div>

                </div>

                <!-- Submit Button Bar -->
                <div class="flex items-center justify-end space-x-3 pt-3 border-t border-slate-100">
                    <a href="<?= base_url('modules/users/index.php') ?>" class="btn-secondary text-xs py-2 px-4">Batal</a>
                    <button type="submit" class="btn-primary text-xs py-2 px-5">Perbarui Data User</button>
                </div>

            </form>
        </div>

    </main>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
