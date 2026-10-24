<?php
$breadcrumbCategory = "MASTER DATA";
$pageTitle = "Import Massal Berkas Drawing & Dokumen (ZIP)";
$pageSubtitle = "Unggah ratusan berkas drawing 2D, 3D, dan dokumen kontrol mutu sekaligus dalam 1 berkas ZIP";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';

require_menu_access('master_drawings');
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-6 space-y-5 max-w-5xl">
        
        <?= render_flash() ?>

        <!-- Modern Top Header Action Card -->
        <div class="card p-4 md:p-5 bg-white border border-slate-200/80 rounded-2xl shadow-xs">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div class="flex items-center gap-3">
                    <a href="<?= base_url('modules/master_drawings/index.php') ?>" class="btn-secondary py-1.5 px-3 text-xs inline-flex items-center gap-1.5 rounded-lg border border-slate-300 hover:bg-slate-100 transition-colors">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                        <span>Kembali</span>
                    </a>
                    <div>
                        <h1 class="text-sm font-extrabold text-slate-800">Import Berkas Massal (ZIP)</h1>
                        <p class="text-xs text-slate-400 mt-0.5">Otomatis ekstraksi dan pengelompokan ke folder part <span class="font-mono text-blue-700 font-bold">uploads/parts/{PartCode}/</span></p>
                    </div>
                </div>
                <div class="inline-flex items-center gap-2 px-3 py-1.5 bg-emerald-50 border border-emerald-200/60 rounded-xl text-emerald-800 text-[11px] font-semibold self-start sm:self-auto">
                    <svg class="w-4 h-4 text-emerald-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    <span>Skalabilitas Tinggi (500+ Folder Part Tanpa Lag)</span>
                </div>
            </div>
        </div>

        <!-- Panduan Struktur Folder & Penamaan Interaktif -->
        <div class="card p-5 bg-white border border-slate-200/80 rounded-2xl shadow-xs space-y-4">
            <div class="flex items-center gap-2.5 border-b border-slate-100 pb-3">
                <div class="w-7 h-7 rounded-lg bg-blue-100 text-blue-700 flex items-center justify-center font-bold text-xs flex-shrink-0">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <div>
                    <h2 class="text-xs font-extrabold uppercase tracking-wider text-slate-800">Panduan Penyiapan Berkas ZIP</h2>
                    <p class="text-[11px] text-slate-400">Susun folder part di komputer sebelum dikompresi menjadi satu file .ZIP</p>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                
                <!-- Kolom 1: Format Nama Folder -->
                <div class="p-4 bg-slate-50 border border-slate-200/90 rounded-xl space-y-3">
                    <span class="text-xs font-bold text-slate-800 flex items-center gap-1.5">
                        <span class="w-2 h-2 rounded-full bg-blue-600"></span>
                        <span>1. Format Nama Folder Part (Bebas / Fleksibel)</span>
                    </span>
                    <div class="space-y-2 text-xs">
                        <div class="p-2.5 bg-white border border-slate-200 rounded-lg">
                            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block mb-1">Opsi A: Nomor Part Saja</span>
                            <span class="font-mono text-blue-700 font-extrabold text-[12px]">1768125/</span>
                        </div>
                        <div class="p-2.5 bg-white border border-slate-200 rounded-lg">
                            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block mb-1">Opsi B: Nomor Part + Nama Part</span>
                            <span class="font-mono text-indigo-700 font-extrabold text-[12px]">1768125_COVER SUB-ASSY/</span>
                        </div>
                    </div>
                    <p class="text-[11px] text-slate-500 leading-relaxed">
                        Sistem otomatis mendeteksi kode part sebelum pemisah (<span class="font-mono font-bold text-slate-700">_</span> atau <span class="font-mono font-bold text-slate-700">-</span>) untuk dicocokkan ke database.
                    </p>
                </div>

                <!-- Kolom 2: Format Berkas di Dalamnya -->
                <div class="p-4 bg-slate-50 border border-slate-200/90 rounded-xl space-y-3">
                    <span class="text-xs font-bold text-slate-800 flex items-center gap-1.5">
                        <span class="w-2 h-2 rounded-full bg-emerald-600"></span>
                        <span>2. Pengenalan Berkas di Dalam Folder Part</span>
                    </span>
                    <div class="space-y-1.5 text-xs">
                        <div class="flex items-center justify-between p-1.5 bg-white border border-slate-200/70 rounded-lg">
                            <span class="text-slate-600 font-medium">Gambar 2D (.PDF):</span>
                            <span class="font-mono font-bold text-blue-700 text-[11px]">1768125-00.pdf / 2d.pdf</span>
                        </div>
                        <div class="flex items-center justify-between p-1.5 bg-white border border-slate-200/70 rounded-lg">
                            <span class="text-slate-600 font-medium">Model 3D (.STP):</span>
                            <span class="font-mono font-bold text-slate-700 text-[11px]">1768125-00.stp / 3d.stp</span>
                        </div>
                        <div class="flex items-center justify-between p-1.5 bg-white border border-indigo-100 rounded-lg">
                            <span class="text-slate-600 font-medium">Process Inspection (PI):</span>
                            <span class="font-mono font-bold text-indigo-700 text-[11px]">pi_00.pdf / pi.pdf</span>
                        </div>
                        <div class="flex items-center justify-between p-1.5 bg-white border border-amber-100 rounded-lg">
                            <span class="text-slate-600 font-medium">Quality Point (Q-Point):</span>
                            <span class="font-mono font-bold text-amber-700 text-[11px]">qpoint_00.pdf / qp.pdf</span>
                        </div>
                        <div class="flex items-center justify-between p-1.5 bg-white border border-emerald-100 rounded-lg">
                            <span class="text-slate-600 font-medium">Part Approval (PAC):</span>
                            <span class="font-mono font-bold text-emerald-700 text-[11px]">pac_00.pdf / pac.pdf</span>
                        </div>
                        <div class="flex items-center justify-between p-1.5 bg-white border border-orange-100 rounded-lg">
                            <span class="text-slate-600 font-medium">Standar Packing:</span>
                            <span class="font-mono font-bold text-orange-700 text-[11px]">packing_00.pdf / pack.pdf</span>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- Form Upload ZIP Card -->
        <div class="card p-5 bg-white border border-slate-200/80 rounded-2xl shadow-xs space-y-5">
            <form id="form-import-zip" action="<?= base_url('modules/master_drawings/process_zip.php') ?>" method="POST" enctype="multipart/form-data" class="space-y-5">
                <?= csrf_field() ?>

                <!-- Custom Modern Drag & Drop Zone -->
                <div class="dropzone-zip group relative border-2 border-dashed border-blue-300 hover:border-blue-500 bg-blue-50/20 hover:bg-blue-50/50 rounded-2xl p-8 text-center cursor-pointer transition-all">
                    <input type="file" name="file_zip" id="file_zip" accept=".zip" class="sr-only file-zip-input" required>
                    
                    <div class="zip-empty space-y-2.5">
                        <div class="w-16 h-16 rounded-2xl bg-blue-100 text-blue-600 flex items-center justify-center mx-auto group-hover:scale-105 transition-transform shadow-xs">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                        </div>
                        <div>
                            <p class="text-sm font-bold text-slate-800">Klik di sini untuk memilih atau Drag &amp; Drop berkas .ZIP</p>
                            <p class="text-xs text-slate-400 mt-1">Pilih berkas ZIP yang memuat folder part beserta file 2D, 3D, dan dokumen QC di dalamnya.</p>
                        </div>
                    </div>

                    <div class="zip-selected hidden space-y-2">
                        <div class="w-14 h-14 rounded-2xl bg-emerald-100 text-emerald-600 flex items-center justify-center mx-auto">
                            <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        </div>
                        <div>
                            <span class="inline-block px-3 py-1 rounded-full bg-emerald-50 text-emerald-800 border border-emerald-200 font-mono font-bold text-xs zip-name-label">
                                archive.zip
                            </span>
                            <p class="text-[11px] text-slate-400 mt-1 zip-size-label">0 MB</p>
                        </div>
                    </div>
                </div>

                <!-- Submit Bar -->
                <div class="flex items-center justify-between pt-3 border-t border-slate-100">
                    <a href="<?= base_url('modules/master_drawings/index.php') ?>" class="btn-secondary py-2 px-4 text-xs font-semibold rounded-xl">
                        Batal
                    </a>
                    <button type="submit" id="btn-submit-zip" class="btn-primary py-2 px-6 text-xs font-bold rounded-xl inline-flex items-center gap-2 shadow-xs hover:shadow-sm">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                        <span>Mulai Ekstrak &amp; Simpan Dokumen</span>
                    </button>
                </div>

            </form>
        </div>

    </main>

    <!-- Loading Modal Overlay Saat Ekstraksi -->
    <div id="loading-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-2xs z-50 flex items-center justify-center p-4 hidden">
        <div class="bg-white rounded-2xl shadow-xl border border-slate-200 max-w-md w-full p-6 text-center space-y-4">
            <div class="w-12 h-12 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center mx-auto">
                <svg class="w-6 h-6 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                </svg>
            </div>
            <div>
                <h4 class="text-sm font-extrabold text-slate-900">Mengekstrak &amp; Mengorganisir Berkas...</h4>
                <p class="text-xs text-slate-500 mt-1">Sistem sedang memproses folder part, mengidentifikasi berkas 2D/3D/QC, dan menyimpannya ke server.</p>
            </div>
            <p class="text-[11px] font-mono text-slate-400">Harap jangan menutup halaman ini.</p>
        </div>
    </div>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var dropzone = document.querySelector('.dropzone-zip');
    var input = document.querySelector('.file-zip-input');
    var emptyState = document.querySelector('.zip-empty');
    var selectedState = document.querySelector('.zip-selected');
    var nameLabel = document.querySelector('.zip-name-label');
    var sizeLabel = document.querySelector('.zip-size-label');
    var form = document.getElementById('form-import-zip');
    var btnSubmit = document.getElementById('btn-submit-zip');
    var loadingModal = document.getElementById('loading-modal');

    function formatBytes(bytes) {
        if (!bytes || bytes === 0) return '0 Bytes';
        var k = 1024;
        var sizes = ['Bytes', 'KB', 'MB', 'GB'];
        var i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    dropzone.addEventListener('click', function(e) {
        if (e.target !== input) {
            input.click();
        }
    });

    ['dragenter', 'dragover'].forEach(function(ev) {
        dropzone.addEventListener(ev, function(e) {
            e.preventDefault();
            e.stopPropagation();
            dropzone.classList.add('ring-2', 'ring-blue-500', 'bg-blue-50/60');
        });
    });

    ['dragleave', 'drop'].forEach(function(ev) {
        dropzone.addEventListener(ev, function(e) {
            e.preventDefault();
            e.stopPropagation();
            dropzone.classList.remove('ring-2', 'ring-blue-500', 'bg-blue-50/60');
        });
    });

    dropzone.addEventListener('drop', function(e) {
        if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length > 0) {
            input.files = e.dataTransfer.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });

    input.addEventListener('change', function() {
        if (input.files && input.files[0]) {
            var file = input.files[0];
            nameLabel.textContent = file.name;
            sizeLabel.textContent = formatBytes(file.size);
            emptyState.classList.add('hidden');
            selectedState.classList.remove('hidden');
        } else {
            emptyState.classList.remove('hidden');
            selectedState.classList.add('hidden');
        }
    });

    form.addEventListener('submit', function() {
        if (input.files && input.files[0]) {
            loadingModal.classList.remove('hidden');
            btnSubmit.disabled = true;
        }
    });
});
</script>

