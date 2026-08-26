<?php
$pageTitle = "Tambah User Baru";
require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';
?>

<div class="flex-1 md:pl-64 flex flex-col min-h-screen">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-4 md:p-8 space-y-6 max-w-4xl">
        
        <?= render_flash() ?>

        <div class="flex items-center space-x-4 mb-2">
            <a href="<?= base_url('modules/users/index.php') ?>" class="btn-secondary py-2 px-3">
                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                </svg>
                Kembali
            </a>
            <h2 class="text-xl font-bold text-slate-800">Form Tambah User Baru</h2>
        </div>

        <div class="card">
            <form action="<?= base_url('modules/users/store.php') ?>" method="POST" class="space-y-6">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    
                    <!-- Nama Lengkap -->
                    <div>
                        <label for="name" class="form-label">Nama Lengkap <span class="text-rose-500">*</span></label>
                        <input type="text" id="name" name="name" required class="form-input" placeholder="Masukkan nama lengkap">
                    </div>

                    <!-- Email -->
                    <div>
                        <label for="email" class="form-label">Alamat Email <span class="text-rose-500">*</span></label>
                        <input type="email" id="email" name="email" required class="form-input" placeholder="contoh@oqc.com">
                    </div>

                    <!-- Role -->
                    <div>
                        <label for="role" class="form-label">Role Akses <span class="text-rose-500">*</span></label>
                        <select id="role" name="role" required class="form-input">
                            <option value="Administrator">Administrator</option>
                            <option value="Operator">Operator</option>
                            <option value="Viewer">Viewer</option>
                        </select>
                    </div>

                    <!-- Status -->
                    <div>
                        <label for="status" class="form-label">Status Akun <span class="text-rose-500">*</span></label>
                        <select id="status" name="status" required class="form-input">
                            <option value="Aktif">Aktif</option>
                            <option value="Non-Aktif">Non-Aktif</option>
                        </select>
                    </div>

                </div>

                <!-- Submit Button Bar -->
                <div class="flex items-center justify-end space-x-3 pt-4 border-t border-slate-200">
                    <a href="<?= base_url('modules/users/index.php') ?>" class="btn-secondary">Batal</a>
                    <button type="submit" class="btn-primary">Simpan User</button>
                </div>

            </form>
        </div>

    </main>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
