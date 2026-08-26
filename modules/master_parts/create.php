<?php
$breadcrumbCategory = "MASTER DATA";
$pageTitle = "Tambah Master Part Baru";
$pageSubtitle = "Tambahkan referensi Part Code dan Part Name baru ke dalam sistem";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-4 md:p-6 space-y-4 max-w-2xl">
        
        <?= render_flash() ?>

        <div class="flex items-center space-x-3 mb-2">
            <a href="<?= base_url('modules/master_parts/index.php') ?>" class="btn-secondary py-1 px-2.5 text-xs">
                &larr; Kembali
            </a>
            <h2 class="text-sm font-bold text-slate-800">Form Tambah Master Part</h2>
        </div>

        <div class="card">
            <form action="<?= base_url('modules/master_parts/store.php') ?>" method="POST" class="space-y-4">
                
                <!-- Part Code -->
                <div>
                    <label for="part_code" class="form-label">Part Code <span class="text-rose-500">*</span></label>
                    <input type="text" id="part_code" name="part_code" required class="form-input uppercase tracking-wider font-mono" placeholder="Contoh: PART-X100">
                    <p class="text-[10px] text-slate-400 mt-1">Part Code harus unik dan tidak boleh duplikat.</p>
                </div>

                <!-- Part Name -->
                <div>
                    <label for="part_name" class="form-label">Part Name / Description <span class="text-rose-500">*</span></label>
                    <input type="text" id="part_name" name="part_name" required class="form-input" placeholder="Contoh: Cover Hinge Bracket">
                </div>

                <!-- Model Part -->
                <div>
                    <label for="model" class="form-label">Model Part</label>
                    <input type="text" id="model" name="model" class="form-input uppercase font-mono" placeholder="Contoh: K1AA / K59 / BEAT">
                    <p class="text-[10px] text-slate-400 mt-1">Digunakan otomatis saat mencetak Rejection Sheet resmi (STQC-F-167 REV.00).</p>
                </div>

                <!-- SA Route -->
                <div>
                    <label for="sa_route" class="form-label">SA Route (Sub-Assembly Route)</label>
                    <input type="text" id="sa_route" name="sa_route" class="form-input" placeholder="Contoh: LINE-1 / ASSY-02">
                </div>

                <!-- Submit Bar -->
                <div class="flex items-center justify-end space-x-2 pt-4 border-t border-slate-200">
                    <a href="<?= base_url('modules/master_parts/index.php') ?>" class="btn-secondary">Batal</a>
                    <button type="submit" class="btn-primary">Simpan Master Part</button>
                </div>

            </form>
        </div>

    </main>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
