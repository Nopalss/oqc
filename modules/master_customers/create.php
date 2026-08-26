<?php
$breadcrumbCategory = "DATA MASTER";
$pageTitle = "Tambah Master Customer Baru";
$pageSubtitle = "Daftarkan nama perusahaan pelanggan / PT tujuan pengiriman baru";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-5 space-y-4">
        
        <?= render_flash() ?>

        <div class="card p-3 md:p-4 bg-white border border-slate-200/80 rounded-xl shadow-xs">
            <div style="display: flex; align-items: center; justify-content: space-between;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <a href="<?= base_url('modules/master_customers/index.php') ?>" class="btn-secondary py-1.5 px-3 text-xs">
                        &larr; Kembali ke Daftar Customer
                    </a>
                    <h2 class="text-sm font-bold text-slate-800">Form Tambah Customer Baru</h2>
                </div>
            </div>
        </div>

        <div class="card p-5 bg-white border border-slate-200/80 rounded-xl shadow-xs max-w-xl">
            <form action="<?= base_url('modules/master_customers/store.php') ?>" method="POST" class="space-y-4">
                
                <div>
                    <label class="form-label">Nama Customer / Perusahaan PT <span class="text-rose-500">*</span></label>
                    <input type="text" name="name" placeholder="Contoh: PT. Astra Honda Motor" class="form-input text-xs" required autofocus>
                    <span class="text-[10px] text-slate-400">Masukkan nama lengkap perusahaan/PT tujuan pengiriman.</span>
                </div>

                <div class="pt-3 border-t border-slate-100 flex justify-end space-x-2">
                    <a href="<?= base_url('modules/master_customers/index.php') ?>" class="btn-secondary py-1.5 px-4 text-xs">Batal</a>
                    <button type="submit" class="btn-primary py-1.5 px-4 text-xs font-bold">
                        Simpan Master Customer
                    </button>
                </div>
            </form>
        </div>

    </main>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
