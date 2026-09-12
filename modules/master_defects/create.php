<?php
$breadcrumbCategory = "DATA MASTER";
$pageTitle = "Tambah Jenis Defect Baru";
$pageSubtitle = "Tambahkan kategori jenis defect / temuan cacat barang OQC";

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-5 space-y-4 max-w-3xl">
        
        <?= render_flash() ?>

        <div class="flex items-center justify-between">
            <a href="<?= base_url('modules/master_defects/index.php') ?>" class="btn-secondary py-1.5 px-3 text-xs font-bold flex items-center shadow-xs">
                &larr; Kembali ke Daftar Defect
            </a>
        </div>

        <div class="card p-5 bg-white border border-slate-200/80 rounded-xl shadow-xs space-y-4">
            <div class="border-b border-slate-100 pb-3">
                <h3 class="font-extrabold text-sm text-slate-900 flex items-center gap-2">
                    <span class="w-6 h-6 rounded-lg bg-rose-50 text-rose-600 flex items-center justify-center text-xs border border-rose-100">⚠️</span>
                    <span>Form Tambah Jenis Defect Baru</span>
                </h3>
                <p class="text-xs text-slate-500 mt-1">Masukkan nama jenis defect / cacat yang ingin ditambahkan ke sistem.</p>
            </div>

            <form action="<?= base_url('modules/master_defects/store.php') ?>" method="POST" class="space-y-4 text-xs">
                
                <div>
                    <label for="name" class="block font-bold text-slate-700 mb-1">
                        Nama Jenis Defect / Cacat <span class="text-rose-600">*</span>
                    </label>
                    <input type="text" id="name" name="name" required autofocus
                           placeholder="Contoh: Baret Halus / Scratch, Dimensional Out, Pin Bengkok, DLL..."
                           class="form-input w-full text-xs font-semibold py-2 px-3 border-slate-300 focus:border-blue-500 rounded-lg">
                    <p class="text-[11px] text-slate-500 mt-1">Pastikan nama jenis defect unik dan jelas untuk dipilih inspector QC saat menemukan barang NG.</p>
                </div>

                <div class="flex items-center justify-end space-x-2 pt-3 border-t border-slate-100">
                    <a href="<?= base_url('modules/master_defects/index.php') ?>" class="btn-secondary py-2 px-4 text-xs font-bold">
                        Batal
                    </a>
                    <button type="submit" class="btn-primary py-2 px-4 text-xs font-extrabold shadow-xs flex items-center">
                        <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                        Simpan Jenis Defect
                    </button>
                </div>

            </form>
        </div>

    </main>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
