<?php
$breadcrumbCategory = "DATA REFERENSI";
$pageTitle = "Edit Daily Inspection Data (DID)";
$pageSubtitle = "Perbarui informasi status pengecekan dimensi lot produksi";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$pdo = getDB();
$did = null;

if ($id && $pdo) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM daily_inspection_data WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $did = $stmt->fetch();
    } catch (PDOException $e) {
        $did = null;
    }
}

if (!$did) {
    set_flash('error', 'Data DID tidak ditemukan!');
    redirect('modules/did/index.php');
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
                    <a href="<?= base_url('modules/did/index.php') ?>" class="btn-secondary py-1.5 px-3 text-xs">
                        &larr; Batal & Kembali
                    </a>
                    <h2 class="text-sm font-bold text-slate-800">
                        Edit Data DID: <span class="font-mono text-blue-700 font-extrabold"><?= htmlspecialchars($did['part_code']) ?></span> (Lot: <?= htmlspecialchars($did['lot_number']) ?>)
                    </h2>
                </div>
            </div>
        </div>

        <!-- Edit Form Card -->
        <div class="card p-4 bg-white border border-slate-200/80 rounded-xl shadow-xs">
            <form action="<?= base_url('modules/did/update.php') ?>" method="POST" class="space-y-4">
                <input type="hidden" name="id" value="<?= $did['id'] ?>">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    
                    <!-- Part Code -->
                    <div>
                        <label class="form-label">Part Code <span class="text-rose-500">*</span></label>
                        <input type="text" name="part_code" value="<?= htmlspecialchars($did['part_code']) ?>" class="form-input text-xs font-mono font-bold" required>
                    </div>

                    <!-- Part Name -->
                    <div>
                        <label class="form-label">Nama Part <span class="text-rose-500">*</span></label>
                        <input type="text" name="part_name" value="<?= htmlspecialchars($did['part_name']) ?>" class="form-input text-xs" required>
                    </div>

                    <!-- Lot Number -->
                    <div>
                        <label class="form-label">Lot Number Produksi <span class="text-rose-500">*</span></label>
                        <input type="text" name="lot_number" value="<?= htmlspecialchars($did['lot_number']) ?>" class="form-input text-xs font-mono" required>
                    </div>

                    <!-- Cavity -->
                    <div>
                        <label class="form-label">Cavity Mold (Cav) <span class="text-rose-500">*</span></label>
                        <input type="text" name="cavity" value="<?= htmlspecialchars($did['cavity']) ?>" class="form-input text-xs" required>
                    </div>

                    <!-- Inspecting Date -->
                    <div>
                        <label class="form-label">Tanggal Cek Dimensi <span class="text-rose-500">*</span></label>
                        <input type="date" name="inspecting_date" value="<?= htmlspecialchars($did['inspecting_date']) ?>" class="form-input text-xs" required>
                    </div>

                    <!-- Status Inspect -->
                    <div>
                        <label class="form-label">Status Inspection Hasil Cek <span class="text-rose-500">*</span></label>
                        <select name="status_inspect" class="form-input text-xs font-bold" required>
                            <option value="OK" <?= ($did['status_inspect'] === 'OK') ? 'selected' : '' ?> class="text-emerald-600 font-bold">✓ Status OK (Sesuai Standar)</option>
                            <option value="NG" <?= ($did['status_inspect'] === 'NG') ? 'selected' : '' ?> class="text-rose-600 font-bold">⚠ Status NG (Ketidaksesuaian Dimensi)</option>
                        </select>
                    </div>

                    <!-- PIC Inspector -->
                    <div>
                        <label class="form-label">PIC / Petugas Inspector <span class="text-rose-500">*</span></label>
                        <input type="text" name="pic" value="<?= htmlspecialchars($did['pic']) ?>" class="form-input text-xs" required>
                    </div>

                    <!-- Remark -->
                    <div class="md:col-span-2">
                        <label class="form-label">Catatan Tambahan (Remark)</label>
                        <textarea name="remark" rows="2" class="form-input text-xs"><?= htmlspecialchars($did['remark'] ?? '') ?></textarea>
                    </div>

                </div>

                <div class="pt-3 border-t border-slate-100 flex justify-end space-x-2">
                    <a href="<?= base_url('modules/did/index.php') ?>" class="btn-secondary py-1.5 px-4 text-xs">Batal</a>
                    <button type="submit" class="btn-primary py-1.5 px-4 text-xs">
                        Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>

    </main>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
