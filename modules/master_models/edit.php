<?php
$breadcrumbCategory = "MASTER DATA";
$pageTitle          = "Edit Master Model";
$pageSubtitle       = "Ubah nama model produk — PT. Surya Technology Industri";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    set_flash('error', 'ID Model tidak valid.');
    redirect('modules/master_models/index.php');
}

$pdo   = getDB();
$model = null;

if ($pdo) {
    try {
        $stmt  = $pdo->prepare("SELECT * FROM master_models WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $model = $stmt->fetch();
    } catch (PDOException $e) {}
}

if (!$model) {
    set_flash('error', 'Data Master Model tidak ditemukan.');
    redirect('modules/master_models/index.php');
}
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col transition-all duration-300 min-h-screen bg-slate-100">
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <div class="p-6 space-y-6 flex-1">
        
        <?= render_flash() ?>

        <div class="card p-6 max-w-2xl mx-auto space-y-6">
            <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                <div>
                    <h1 class="text-base font-extrabold text-slate-800 tracking-tight">Edit Master Model</h1>
                    <p class="text-xs text-slate-500 mt-0.5">Ubah nama model produk #<?= $model['id'] ?></p>
                </div>
                <a href="<?= base_url('modules/master_models/index.php') ?>" class="btn-secondary py-1.5 px-3 text-xs">
                    &larr; Kembali
                </a>
            </div>

            <form action="<?= base_url('modules/master_models/update.php') ?>" method="POST" class="space-y-5">
                <input type="hidden" name="id" value="<?= $model['id'] ?>">

                <div>
                    <label for="name" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                        Nama Model Produk <span class="text-red-500">*</span>
                    </label>
                    <input type="text" id="name" name="name" value="<?= htmlspecialchars($model['name']) ?>" required
                           class="form-input text-xs w-full rounded-xl border-slate-300 font-bold uppercase">
                </div>

                <div class="flex items-center justify-end space-x-3 pt-4 border-t border-slate-100">
                    <a href="<?= base_url('modules/master_models/index.php') ?>" class="btn-secondary py-2 px-4 text-xs font-semibold">Batal</a>
                    <button type="submit" class="btn-primary py-2 px-5 text-xs font-bold shadow-md">
                        Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>

    </div>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
