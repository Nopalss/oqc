<?php
$breadcrumbCategory = "DATA REFERENSI";
$pageTitle = "Edit Kanban (Jadwal Kirim)";
$pageSubtitle = "Perbarui informasi item jadwal kirim Kanban";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$pdo = getDB();
$item = null;

if ($id && $pdo) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM kanban_items WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $item = $stmt->fetch();
    } catch (PDOException $e) {
        $item = null;
    }
}

if (!$item) {
    set_flash('error', 'Data Kanban tidak ditemukan!');
    redirect('modules/kanban/index.php');
}
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-5 space-y-4">
        
        <?= render_flash() ?>

        <!-- Action Card -->
        <div class="card p-3 md:p-4 bg-white border border-slate-200/80 rounded-xl shadow-xs">
            <div style="display: flex; align-items: center; justify-content: space-between;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <a href="<?= base_url('modules/kanban/index.php') ?>" class="btn-secondary py-1.5 px-3 text-xs">
                        &larr; Batal & Kembali
                    </a>
                    <h2 class="text-sm font-bold text-slate-800">
                        Edit Data Kanban: <span class="font-mono text-blue-700 font-extrabold"><?= htmlspecialchars($item['kanban_no']) ?></span>
                    </h2>
                </div>
            </div>
        </div>

        <!-- Edit Form Card -->
        <div class="card p-4 bg-white border border-slate-200/80 rounded-xl shadow-xs">
            <form action="<?= base_url('modules/kanban/update.php') ?>" method="POST" class="space-y-4">
                <input type="hidden" name="id" value="<?= $item['id'] ?>">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    
                    <!-- Kanban No -->
                    <div>
                        <label class="form-label">Kanban No. <span class="text-rose-500">*</span></label>
                        <input type="text" name="kanban_no" value="<?= htmlspecialchars($item['kanban_no']) ?>" class="form-input text-xs font-mono font-bold" required>
                    </div>

                    <!-- Item Code -->
                    <div>
                        <label class="form-label">Item Code / Part Code <span class="text-rose-500">*</span></label>
                        <input type="text" name="item_code" value="<?= htmlspecialchars($item['item_code']) ?>" class="form-input text-xs font-mono font-bold" required>
                    </div>

                    <!-- Item Description -->
                    <div>
                        <label class="form-label">Item Description / Nama Barang <span class="text-rose-500">*</span></label>
                        <input type="text" name="item_description" value="<?= htmlspecialchars($item['item_description']) ?>" class="form-input text-xs" required>
                    </div>

                    <!-- Customer -->
                    <div>
                        <label class="form-label">Customer / Tujuan Pengiriman <span class="text-rose-500">*</span></label>
                        <input type="text" name="customer" value="<?= htmlspecialchars($item['customer'] ?? '') ?>" class="form-input text-xs font-semibold" required>
                    </div>

                    <!-- Qty -->
                    <div>
                        <label class="form-label">Jumlah Qty (pcs) <span class="text-rose-500">*</span></label>
                        <input type="number" name="qty" min="1" value="<?= htmlspecialchars($item['qty']) ?>" class="form-input text-xs font-bold" required>
                    </div>

                    <!-- Req Date -->
                    <div>
                        <label class="form-label">Req. Date & Time <span class="text-rose-500">*</span></label>
                        <input type="datetime-local" name="req_date" value="<?= date('Y-m-d\TH:i', strtotime($item['req_date'])) ?>" class="form-input text-xs" required>
                    </div>

                    <!-- ETA -->
                    <div>
                        <label class="form-label">ETA (Estimated Time Arrival)</label>
                        <input type="datetime-local" name="eta" value="<?= $item['eta'] ? date('Y-m-d\TH:i', strtotime($item['eta'])) : '' ?>" class="form-input text-xs">
                    </div>

                    <!-- Storage Location -->
                    <div>
                        <label class="form-label">Storage Location (Str.Loc)</label>
                        <input type="text" name="str_loc" value="<?= htmlspecialchars($item['str_loc'] ?? '') ?>" class="form-input text-xs font-mono">
                    </div>

                    <!-- Supply Area -->
                    <div>
                        <label class="form-label">Supply Area</label>
                        <input type="text" name="supply_area" value="<?= htmlspecialchars($item['supply_area'] ?? '') ?>" class="form-input text-xs font-mono">
                    </div>

                    <!-- Remark -->
                    <div class="md:col-span-2">
                        <label class="form-label">Catatan Tambahan (Remark)</label>
                        <textarea name="remark" rows="2" class="form-input text-xs"><?= htmlspecialchars($item['remark'] ?? '') ?></textarea>
                    </div>

                </div>

                <div class="pt-3 border-t border-slate-100 flex justify-end space-x-2">
                    <a href="<?= base_url('modules/kanban/index.php') ?>" class="btn-secondary py-1.5 px-4 text-xs">Batal</a>
                    <button type="submit" class="btn-primary py-1.5 px-4 text-xs">
                        Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>

    </main>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
