<?php
$breadcrumbCategory = "MASTER DATA";
$pageTitle          = "Tambah Master Model";
$pageSubtitle       = "Input nama model produk baru — PT. Surya Technology Industri";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col transition-all duration-300 min-h-screen bg-slate-100">
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <div class="p-6 space-y-6 flex-1">
        
        <?= render_flash() ?>

        <div class="card p-6 max-w-2xl mx-auto space-y-6">
            <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                <div>
                    <h1 class="text-base font-extrabold text-slate-800 tracking-tight">Form Tambah Master Model</h1>
                    <p class="text-xs text-slate-500 mt-0.5">Nama model akan dapat dipilih saat membuat atau mengubah Data Part.</p>
                </div>
                <a href="<?= base_url('modules/master_models/index.php') ?>" class="btn-secondary py-1.5 px-3 text-xs">
                    &larr; Kembali
                </a>
            </div>

            <form action="<?= base_url('modules/master_models/store.php') ?>" method="POST" class="space-y-5">
                <div>
                    <label for="name" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                        Nama Model Produk <span class="text-red-500">*</span>
                    </label>
                    <input type="text" id="name" name="name" required placeholder="Contoh: NUSADUA, HIROSHIGE, NIKE, GROW..."
                           class="form-input text-xs w-full rounded-xl border-slate-300 font-bold uppercase">
                    <p class="text-[11px] text-slate-500 mt-1">Nama model harus unik dan mewakili famili/kelompok produk.</p>
                </div>

                <div class="flex items-center justify-end space-x-3 pt-4 border-t border-slate-100">
                    <a href="<?= base_url('modules/master_models/index.php') ?>" class="btn-secondary py-2 px-4 text-xs font-semibold">Batal</a>
                    <button type="submit" class="btn-primary py-2 px-5 text-xs font-bold shadow-md">
                        Simpan Model
                    </button>
                </div>
            </form>
        </div>

    </div>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
