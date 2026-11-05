<?php
/**
 * Form Tambah Master Template Monitoring OQC
 * Didesain sederhana, jelas, dan ramah pengguna non-teknis / senior
 */
$pageTitle = "Tambah Form Pemeriksaan OQC";
$breadcrumbCategory = "MASTER DATA";
$pageSubtitle = "Tambah Kategori & Poin Pemeriksaan SOP Baru";

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/helper.php';

require_menu_access('monitoring_master');

$pdo = getDB();

$type = sanitize($_GET['type'] ?? 'group');
if (!in_array($type, ['phase', 'group', 'item'])) {
    $type = 'group';
}

$selectedPhaseId = (int)($_GET['phase_id'] ?? 0);
$selectedGroupId = (int)($_GET['group_id'] ?? 0);

// Data dropdown
$phases = [];
$groups = [];
$selectedPhaseName = '';
$selectedGroupName = '';

if ($pdo) {
    $phases = $pdo->query("SELECT id, name, sort_order FROM oqc_monitoring_phases ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $groups = $pdo->query("SELECT g.id, g.title, g.phase_id, p.name as phase_name 
                           FROM oqc_monitoring_groups g 
                           JOIN oqc_monitoring_phases p ON g.phase_id = p.id 
                           ORDER BY p.sort_order ASC, g.sort_order ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    
    // Default phase jika hanya ada 1 tahap atau belum terpilih
    if ($selectedPhaseId <= 0 && !empty($phases)) {
        $selectedPhaseId = (int)$phases[0]['id'];
    }

    foreach ($phases as $p) {
        if ((int)$p['id'] === $selectedPhaseId) {
            $selectedPhaseName = $p['name'];
            break;
        }
    }

    foreach ($groups as $g) {
        if ((int)$g['id'] === $selectedGroupId) {
            $selectedGroupName = $g['title'];
            break;
        }
    }
}

// Generate CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';
?>

<style>
/* Pastikan seluruh input memiliki border & warna tegas yang selalu tampil */
input[type="text"],
input[type="number"],
select,
textarea {
    border: 1.5px solid #94a3b8 !important; /* Abu-abu tegas */
    background-color: #ffffff !important;
    color: #0f172a !important;
}
input[type="text"]:focus,
input[type="number"]:focus,
select:focus,
textarea:focus {
    border-color: #2563eb !important;
    outline: none !important;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.25) !important;
}

/* Badge Nomor Poin yang Jelas Berwarna Biru Tajam */
.row-number-badge {
    background-color: #2563eb !important;
    color: #ffffff !important;
    font-weight: 800 !important;
    font-size: 12px !important;
    padding: 4px 10px !important;
    border-radius: 8px !important;
    display: inline-flex !important;
    align-items: center !important;
    box-shadow: 0 1px 2px rgba(0,0,0,0.1) !important;
}

/* Card Baris Poin Pemeriksaan */
.item-dynamic-row {
    background-color: #f8fafc !important;
    border: 1.5px solid #cbd5e1 !important;
    border-radius: 14px !important;
}
.item-dynamic-row:hover {
    background-color: #f1f5f9 !important;
}

/* Card Aturan Pengisian */
.rule-card {
    border: 2px solid #cbd5e1 !important;
    background-color: #ffffff !important;
}
.rule-card.active-rule {
    border-color: #2563eb !important;
    background-color: #eff6ff !important;
}
</style>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-6 space-y-6 max-w-4xl w-full mx-auto">
        
        <?= render_flash() ?>

        <!-- Tombol Kembali yang Jelas -->
        <div class="flex items-center justify-between">
            <a href="<?= base_url('modules/monitoring_master/index.php' . ($selectedPhaseId > 0 ? '?phase_id=' . $selectedPhaseId : '')) ?>" 
               class="inline-flex items-center gap-2 text-sm font-bold text-slate-700 hover:text-slate-900 bg-white border border-slate-300 hover:border-slate-400 px-4 py-2.5 rounded-xl transition shadow-sm">
                <svg class="w-4 h-4 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                <span>&larr; Kembali ke Daftar Master</span>
            </a>
        </div>

        <!-- Main Card Form -->
        <div class="bg-white border-2 border-slate-200 rounded-2xl shadow-sm overflow-hidden">
            
            <!-- Header Kartu -->
            <div class="p-5 md:p-6 border-b border-slate-200 bg-slate-50/70 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg md:text-xl font-black text-slate-800">
                        <?php if ($type === 'phase'): ?>
                            🏷️ Form Tambah Tahap SOP Baru
                        <?php elseif ($type === 'group'): ?>
                            📋 Form Tambah Kategori & Poin Pemeriksaan
                        <?php else: ?>
                            ➕ Form Tambah 1 Poin Pemeriksaan Baru
                        <?php endif; ?>
                    </h2>
                    <p class="text-xs md:text-sm text-slate-500 mt-1">
                        <?php if ($type === 'group'): ?>
                            Tulis nama kategori (contoh: Meja Kerja, Alat Ukur) lalu tambahkan hal-hal apa saja yang harus dicek di bawahnya.
                        <?php elseif ($type === 'phase'): ?>
                            Tahap SOP membagi jadwal pemeriksaan (misal: Tahap Awal, Tahap Proses, atau Tahap Akhir).
                        <?php else: ?>
                            Tambahkan satu poin pemeriksaan baru ke dalam kategori yang sudah terdaftar.
                        <?php endif; ?>
                    </p>
                </div>

                <div class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold bg-indigo-50 border border-indigo-200 text-indigo-700 self-start sm:self-auto">
                    <span>Langkah Mudah & Otomatis</span>
                </div>
            </div>

            <form action="<?= base_url('modules/monitoring_master/store.php') ?>" method="POST" class="p-5 md:p-7 space-y-6">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="type" value="<?= $type ?>">

                <?php if ($type === 'group'): ?>
                    <!-- ================= MODE GRUP + POIN PEMERIKSAAN ================= -->

                    <!-- 1. Informasi Tahap SOP (Jelas & Terpilih) -->
                    <div class="p-4 bg-blue-50 rounded-2xl" style="background-color: #eff6ff; border: 1.5px solid #93c5fd;">
                        <label class="block text-xs md:text-sm font-bold text-blue-900 mb-1.5">
                            📌 1. Masukkan ke Tahap SOP Mana? <span class="text-rose-600">*</span>
                        </label>
                        <select name="phase_id" required 
                                class="w-full text-sm font-semibold text-slate-800 bg-white rounded-xl px-4 py-3 focus:outline-none"
                                style="border: 1.5px solid #94a3b8 !important; background-color: #ffffff !important;">
                            <?php foreach ($phases as $p): ?>
                                <option value="<?= $p['id'] ?>" <?= $selectedPhaseId === (int)$p['id'] ? 'selected' : '' ?>>
                                    Tahap <?= (int)$p['sort_order'] ?> &mdash; <?= htmlspecialchars($p['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-xs text-blue-700 mt-1.5 font-medium">
                            💡 Data baru ini akan langsung muncul di jadwal pemeriksaan tahap di atas.
                        </p>
                    </div>

                    <!-- 2. Nama Kategori Pemeriksaan -->
                    <div class="space-y-1.5" style="margin-top: 1.75rem !important;">
                        <label class="block text-sm md:text-base font-bold text-slate-800">
                            🏷️ 2. Nama Bagian / Kategori yang Dicek <span class="text-rose-600">*</span>
                        </label>
                        <input type="text" name="title" id="inputGroupTitle" required 
                               placeholder="Contoh: Kebersihan Meja Kerja & Alat OQC" 
                               class="w-full text-sm md:text-base font-medium rounded-xl px-4 py-3 text-slate-800 placeholder-slate-400 focus:outline-none transition"
                               style="border: 1.5px solid #94a3b8 !important; background-color: #ffffff !important;">
                        <p class="text-xs text-slate-500">
                            Beri nama yang mudah dikenali oleh tim di lantai produksi.
                        </p>
                    </div>

                    <!-- 3. Aturan Pengisian (Pilihan Kartu Simpel, Tanpa Istilah Bahasa Inggris) -->
                    <div class="space-y-2" style="margin-top: 1.75rem !important;">
                        <label class="block text-sm md:text-base font-bold text-slate-800">
                            ⚙️ 3. Bagaimana Cara Tim Memeriksa Nanti?
                        </label>
                        
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <!-- Pilihan 1: Semua Wajib (Default) -->
                            <label class="relative flex items-start gap-3 p-4 rounded-xl cursor-pointer transition rule-card active-rule" id="cardRuleAll" style="border: 2px solid #2563eb !important; background-color: #eff6ff !important;">
                                <input type="radio" name="rule_type" value="all_required" checked 
                                       class="mt-1 w-4 h-4 text-blue-600 focus:ring-blue-500" 
                                       onchange="handleRuleChange(this.value)">
                                <div>
                                    <span class="block text-sm font-bold text-slate-900">
                                        ✅ Semua Poin Wajib Dicek (Paling Umum)
                                    </span>
                                    <span class="block text-xs text-slate-600 mt-1">
                                        Inspektur harus mencentang seluruh poin pemeriksaan sampai tuntas tanpa terlewat.
                                    </span>
                                </div>
                            </label>

                            <!-- Pilihan 2: Sebagian / Perwakilan -->
                            <label class="relative flex items-start gap-3 p-4 rounded-xl cursor-pointer transition rule-card" id="cardRuleRep" style="border: 2px solid #cbd5e1 !important; background-color: #ffffff !important;">
                                <input type="radio" name="rule_type" value="representative" 
                                       class="mt-1 w-4 h-4 text-blue-600 focus:ring-blue-500" 
                                       onchange="handleRuleChange(this.value)">
                                <div>
                                    <span class="block text-sm font-bold text-slate-900">
                                        🟡 Cukup Centang Sebagian (Sampel)
                                    </span>
                                    <span class="block text-xs text-slate-600 mt-1">
                                        Hanya perlu mencentang minimal beberapa poin saja sebagai perwakilan.
                                    </span>
                                </div>
                            </label>
                        </div>

                        <!-- Input Tambahan jika pilih Cukup Sebagian -->
                        <div id="minReqWrapper" class="hidden p-4 rounded-xl space-y-2 mt-3" style="background-color: #fffbeb; border: 1.5px solid #fde68a;">
                            <label class="block text-xs md:text-sm font-bold text-amber-900">
                                Berapa minimal poin yang wajib dicentang oleh inspektur?
                            </label>
                            <div class="flex items-center gap-3">
                                <input type="number" name="min_required" id="minRequiredInput" value="1" min="1" max="50"
                                       class="w-24 text-sm font-bold rounded-xl px-3 py-2 text-center bg-white focus:outline-none"
                                       style="border: 1.5px solid #d97706 !important;">
                                <span class="text-xs font-semibold text-amber-800">Poin pemeriksaan minimal</span>
                            </div>
                        </div>
                    </div>

                    <!-- 4. Daftar Poin Pemeriksaan (Bukan Butir) -->
                    <div class="space-y-4" style="margin-top: 2rem !important; padding-top: 1.75rem !important; border-top: 2px solid #e2e8f0 !important;">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-4 rounded-2xl" style="background-color: #f1f5f9; border: 1.5px solid #e2e8f0;">
                            <div>
                                <h3 class="text-sm md:text-base font-black text-slate-800 flex items-center gap-2">
                                    <span class="w-6 h-6 rounded-lg bg-blue-600 text-white flex items-center justify-center text-xs font-bold" style="background-color: #2563eb !important;">✓</span>
                                    Daftar Hal yang Harus Dicek (Poin Pemeriksaan)
                                </h3>
                                <p class="text-xs text-slate-500 mt-0.5">
                                    Nomor urutan tersusun otomatis dari atas ke bawah.
                                </p>
                            </div>
                            
                            <!-- Tombol Aksi Tambah Poin -->
                            <div class="flex items-center gap-2">
                                <button type="button" 
                                        onclick="addItemRow('', '', 1)" 
                                        class="inline-flex items-center gap-2 px-4 py-2.5 text-xs md:text-sm font-bold text-white rounded-xl transition shadow-sm cursor-pointer"
                                        style="background-color: #2563eb !important; border-radius: 10px !important;">
                                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"></path></svg>
                                    <span>Tambah Poin Baru</span>
                                </button>
                            </div>
                        </div>

                        <!-- Container Baris Poin Pemeriksaan -->
                        <div id="itemsContainer" class="space-y-3.5">
                            <!-- Baris poin diisi dinamis via JavaScript -->
                        </div>

                        <!-- Tombol Tambah di Bawah Daftar Poin -->
                        <div class="pt-2 text-center sm:text-left">
                            <button type="button" 
                                    onclick="addItemRow('', '', 1)" 
                                    class="w-full sm:w-auto inline-flex items-center justify-center gap-2 text-sm font-bold text-indigo-700 bg-indigo-50 hover:bg-indigo-100 rounded-xl transition cursor-pointer"
                                    style="padding: 12px 24px !important; min-height: 46px !important; border: 2px dashed #818cf8 !important; border-radius: 12px !important;">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"></path></svg>
                                <span>Tambah Baris Poin Berikutnya</span>
                            </button>
                        </div>
                    </div>

                    <!-- Catatan Tambahan (Opsional) -->
                    <div class="pt-4 border-t border-slate-200" style="margin-top: 1.5rem !important;">
                        <label class="block text-xs font-bold text-slate-600 mb-1">
                            Catatan Tambahan untuk Kategori Ini (Boleh Dikosongkan)
                        </label>
                        <input type="text" name="description" placeholder="Catatan singkat jika ada..." 
                               class="w-full text-xs font-medium border border-slate-200 rounded-xl px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    </div>

                <?php elseif ($type === 'phase'): ?>
                    <!-- ================= MODE TAHAP SOP BARU ================= -->
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-bold text-slate-800 mb-1">Nama Tahap SOP <span class="text-rose-500">*</span></label>
                            <input type="text" name="name" required placeholder="Contoh: Tahap Awal (Persiapan & 5S)" 
                                   class="w-full text-sm font-medium border-2 border-slate-300 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-bold text-slate-800 mb-1">Kode Unik Tahap <span class="text-rose-500">*</span></label>
                                <input type="text" name="phase_code" required placeholder="Contoh: early_shift, mid_shift, final" 
                                       class="w-full text-sm font-medium border-2 border-slate-300 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <p class="text-xs text-slate-400 mt-1">Gunakan huruf kecil tanpa spasi.</p>
                            </div>

                            <div>
                                <label class="block text-sm font-bold text-slate-800 mb-1">Waktu Muncul Pemeriksaan <span class="text-rose-500">*</span></label>
                                <select name="trigger_event" class="w-full text-sm font-semibold border-2 border-slate-300 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <option value="post_login">1. Muncul Langsung Setelah Inspektur Login (Gatekeeper)</option>
                                    <option value="in_process">2. Kapan Saja Saat Proses Berjalan (On Demand)</option>
                                    <option value="pre_logout">3. Muncul Sebelum Inspektur Logout</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-bold text-slate-800 mb-1">Keterangan / Tujuan Tahap Ini (Opsional)</label>
                            <textarea name="description" rows="2" placeholder="Jelaskan secara singkat tujuan pemeriksaan tahap ini..." 
                                      class="w-full text-sm font-medium border-2 border-slate-300 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- ================= MODE SINGLE ITEM ================= -->
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-bold text-slate-800 mb-1">Pilih Kategori Pemeriksaan <span class="text-rose-500">*</span></label>
                            <select name="group_id" required class="w-full text-sm font-semibold border-2 border-slate-300 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                                <option value="">-- Pilih Kategori --</option>
                                <?php foreach ($groups as $g): ?>
                                    <option value="<?= $g['id'] ?>" <?= $selectedGroupId === (int)$g['id'] ? 'selected' : '' ?>>
                                        [<?= htmlspecialchars($g['phase_name']) ?>] &rarr; <?= htmlspecialchars($g['title']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-bold text-slate-800 mb-1">Apa yang Harus Dicek? (Teks Poin Pemeriksaan) <span class="text-rose-500">*</span></label>
                            <textarea name="item_text" rows="2" required placeholder="Contoh: Pastikan meja kerja bersih dari sisa part dan debu..." 
                                      class="w-full text-sm font-medium border-2 border-slate-300 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-emerald-500"></textarea>
                        </div>

                        <div>
                            <label class="block text-sm font-bold text-slate-800 mb-1">Petunjuk / Catatan Tambahan (Boleh Dikosongkan)</label>
                            <input type="text" name="hint_guide" placeholder="Contoh: Gunakan lap microfiber bersih sebelum part diletakkan" 
                                   class="w-full text-sm font-medium border-2 border-slate-300 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                        </div>

                        <div class="p-3.5 bg-slate-50 border border-slate-200 rounded-xl flex items-center justify-between">
                            <div>
                                <span class="block text-sm font-bold text-slate-800">Sifat Pemeriksaan</span>
                                <span class="text-xs text-slate-500">Apakah poin ini wajib dicentang oleh inspektur?</span>
                            </div>
                            <select name="is_required" class="text-sm font-bold border border-slate-300 rounded-xl px-3 py-2 bg-white">
                                <option value="1">Wajib Dicek</option>
                                <option value="0">Opsional</option>
                            </select>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Tombol Aksi Simpan (Besar, Jelas, Menonjol) -->
                <div class="flex flex-col sm:flex-row items-center justify-between gap-4" style="margin-top: 2.25rem !important; padding-top: 1.75rem !important; border-top: 2px solid #e2e8f0 !important;">
                    <span class="text-xs text-slate-500 italic order-2 sm:order-1">
                        * Tanda bintang merah wajib diisi
                    </span>
                    <div class="flex items-center gap-3 w-full sm:w-auto order-1 sm:order-2">
                        <a href="<?= base_url('modules/monitoring_master/index.php' . ($selectedPhaseId > 0 ? '?phase_id=' . $selectedPhaseId : '')) ?>" 
                           class="flex-1 sm:flex-initial inline-flex items-center justify-center text-sm font-bold text-slate-700 hover:text-slate-900 bg-slate-100 hover:bg-slate-200 rounded-xl transition cursor-pointer"
                           style="padding: 14px 28px !important; min-height: 52px !important; border-radius: 12px !important; border: 1.5px solid #cbd5e1 !important;">
                            Batal
                        </a>
                        <button type="submit" 
                                class="flex-1 sm:flex-initial inline-flex items-center justify-center gap-2.5 text-base font-black text-white bg-blue-600 hover:bg-blue-700 active:scale-98 rounded-xl transition shadow-md cursor-pointer"
                                style="padding: 14px 36px !important; min-height: 52px !important; font-size: 15px !important; border-radius: 12px !important; background-color: #2563eb !important;">
                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                            <span><?= $type === 'group' ? 'Simpan Pemeriksaan Ini' : 'Simpan Data' ?></span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </main>

<script>
let itemRowCounter = 0;

// Escape HTML aman dari XSS
function escapeHtml(text) {
    if (!text) return '';
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// Pengaturan Aturan Pengisian Radio Card
function handleRuleChange(val) {
    const minWrap = document.getElementById('minReqWrapper');
    const cardAll = document.getElementById('cardRuleAll');
    const cardRep = document.getElementById('cardRuleRep');

    if (val === 'representative') {
        if (minWrap) minWrap.classList.remove('hidden');
        if (cardRep) {
            cardRep.classList.add('active-rule');
            cardRep.style.borderColor = '#4f46e5';
            cardRep.style.backgroundColor = '#f5f3ff';
        }
        if (cardAll) {
            cardAll.classList.remove('active-rule');
            cardAll.style.borderColor = '#cbd5e1';
            cardAll.style.backgroundColor = '#f8fafc';
        }
    } else {
        if (minWrap) minWrap.classList.add('hidden');
        if (cardAll) {
            cardAll.classList.add('active-rule');
            cardAll.style.borderColor = '#4f46e5';
            cardAll.style.backgroundColor = '#f5f3ff';
        }
        if (cardRep) {
            cardRep.classList.remove('active-rule');
            cardRep.style.borderColor = '#cbd5e1';
            cardRep.style.backgroundColor = '#f8fafc';
        }
    }
}

// Tambah Baris Poin Pemeriksaan (Desain Lega & Ramah Lansia)
function addItemRow(initialText = '', initialHint = '', isReq = 1) {
    const container = document.getElementById('itemsContainer');
    if (!container) return;

    itemRowCounter++;
    const rowId = 'item-row-' + itemRowCounter;

    const div = document.createElement('div');
    div.id = rowId;
    div.className = 'p-4 rounded-2xl space-y-3 transition-all duration-150 item-dynamic-row';
    div.style.backgroundColor = '#f8fafc';
    div.style.border = '1.5px solid #cbd5e1';

    div.innerHTML = `
        <div class="flex items-center justify-between pb-2 border-b border-slate-200">
            <div class="flex items-center gap-2">
                <span class="row-number-badge inline-flex items-center px-3 py-1 rounded-lg text-xs font-black text-white shadow-sm" style="background-color: #2563eb !important; color: #ffffff !important; font-weight: 800; border-radius: 8px;">
                    Poin #${itemRowCounter}
                </span>
                <span class="text-xs font-bold text-slate-600">Hal yang harus dicek:</span>
            </div>
            <button type="button" 
                    onclick="removeItemRow('${rowId}')" 
                    class="inline-flex items-center gap-1 text-xs font-bold transition cursor-pointer"
                    style="color: #e11d48 !important; background-color: #ffe4e6 !important; border: 1px solid #fecdd3 !important; padding: 4px 10px; border-radius: 8px;"
                    title="Hapus baris poin ini">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                <span>Hapus</span>
            </button>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-center">
            <!-- Kolom Teks Utama -->
            <div class="md:col-span-6">
                <label class="block text-xs font-bold text-slate-700 mb-1">
                    Apa yang harus diperiksa? <span class="text-rose-600">*</span>
                </label>
                <input type="text" 
                       name="items[${itemRowCounter}][item_text]" 
                       required 
                       placeholder="Contoh: Meja OQC bebas dari kotoran & sisa part..." 
                       value="${escapeHtml(initialText)}"
                       class="w-full text-sm font-semibold rounded-xl px-3.5 py-2.5 bg-white text-slate-800 placeholder-slate-400 focus:outline-none transition"
                       style="border: 1.5px solid #94a3b8 !important; background-color: #ffffff !important;">
            </div>

            <!-- Kolom Petunjuk Tambahan -->
            <div class="md:col-span-4">
                <label class="block text-xs font-bold text-slate-700 mb-1">
                    Petunjuk / SOP (Boleh Kosong)
                </label>
                <input type="text" 
                       name="items[${itemRowCounter}][hint_guide]" 
                       placeholder="Contoh: Bersihkan pakai lap microfiber" 
                       value="${escapeHtml(initialHint)}"
                       class="w-full text-xs md:text-sm font-medium rounded-xl px-3.5 py-2.5 bg-white text-slate-700 placeholder-slate-400 focus:outline-none transition"
                       style="border: 1.5px solid #94a3b8 !important; background-color: #ffffff !important;">
            </div>

            <!-- Kolom Sifat Wajib -->
            <div class="md:col-span-2">
                <label class="block text-xs font-bold text-slate-700 mb-1">
                    Status
                </label>
                <select name="items[${itemRowCounter}][is_required]" 
                        class="w-full text-xs md:text-sm font-bold rounded-xl px-2.5 py-2.5 bg-white text-slate-800 focus:outline-none"
                        style="border: 1.5px solid #94a3b8 !important; background-color: #ffffff !important;">
                    <option value="1" ${isReq == 1 ? 'selected' : ''}>Wajib Dicek</option>
                    <option value="0" ${isReq == 0 ? 'selected' : ''}>Opsional</option>
                </select>
            </div>
        </div>
    `;

    container.appendChild(div);
    reindexRows();
}

// Hapus Baris
function removeItemRow(rowId) {
    const el = document.getElementById(rowId);
    const container = document.getElementById('itemsContainer');
    if (!el || !container) return;

    const totalRows = container.getElementsByClassName('item-dynamic-row').length;
    if (totalRows <= 1) {
        alert('Minimal harus ada 1 poin pemeriksaan di dalam kategori ini.');
        return;
    }

    el.remove();
    reindexRows();
}

// Hitung Ulang Penomoran Baris
function reindexRows() {
    const container = document.getElementById('itemsContainer');
    if (!container) return;
    const rows = container.getElementsByClassName('item-dynamic-row');
    for (let i = 0; i < rows.length; i++) {
        const badge = rows[i].querySelector('.row-number-badge');
        if (badge) {
            badge.innerText = 'Poin #' + (i + 1);
        }
    }
}

// Inisialisasi awal saat halaman dibuka
document.addEventListener('DOMContentLoaded', function() {
    const container = document.getElementById('itemsContainer');
    if (container) {
        // Buat 2 baris poin kosong yang langsung siap diketik
        addItemRow('', '', 1);
        addItemRow('', '', 1);
    }
});
</script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
