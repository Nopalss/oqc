<?php
$breadcrumbCategory = "DATA MASTER";
$pageTitle = "Edit Master Customer";
$pageSubtitle = "Ubah data nama perusahaan pelanggan / PT tujuan pengiriman";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';

$pdo = getDB();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$pdo || !$id) {
    set_flash('error', 'ID Customer tidak valid!');
    redirect('modules/master_customers/index.php');
}

$customer = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM master_customers WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $customer = $stmt->fetch();
} catch (PDOException $e) {
    $customer = null;
}

if (!$customer) {
    set_flash('error', 'Data Customer tidak ditemukan!');
    redirect('modules/master_customers/index.php');
}
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
                    <h2 class="text-sm font-bold text-slate-800">Form Edit Master Customer</h2>
                </div>
            </div>
        </div>

        <div class="card p-5 bg-white border border-slate-200/80 rounded-xl shadow-xs max-w-xl">
            <form action="<?= base_url('modules/master_customers/update.php') ?>" method="POST" class="space-y-4">
                <input type="hidden" name="id" value="<?= $customer['id'] ?>">

                <div>
                    <label class="form-label">Nama Customer / Perusahaan PT <span class="text-rose-500">*</span></label>
                    <input type="text" name="name" value="<?= htmlspecialchars($customer['name']) ?>" class="form-input text-xs" required>
                    <span class="text-[10px] text-slate-400">Ubah nama perusahaan/PT jika terdapat kekeliruan penulisan.</span>
                </div>

                <div class="pt-3 border-t border-slate-100 flex justify-end space-x-2">
                    <a href="<?= base_url('modules/master_customers/index.php') ?>" class="btn-secondary py-1.5 px-4 text-xs">Batal</a>
                    <button type="submit" class="btn-primary py-1.5 px-4 text-xs font-bold">
                        Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>

    </main>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
