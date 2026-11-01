<?php
$pageTitle = "Edit Standard OQC";
$breadcrumbCategory = "DATA REFERENSI";
$pageSubtitle = "Perbarui informasi atau ganti file dokumen standard OQC";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';

// Enforce permission
require_menu_access('standard_oqc');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    set_flash('danger', 'ID Dokumen tidak valid.');
    redirect('modules/standard_oqc/index.php');
}

$pdo = getDB();
$doc = null;

if ($pdo) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM standard_oqc WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        set_flash('danger', 'Gagal mengambil data: ' . $e->getMessage());
        redirect('modules/standard_oqc/index.php');
    }
}

if (!$doc) {
    set_flash('danger', 'Dokumen tidak ditemukan.');
    redirect('modules/standard_oqc/index.php');
}
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen transition-all duration-300">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-6 space-y-5 max-w-4xl w-full mx-auto">
        
        <?= render_flash() ?>

        <div class="flex items-center space-x-3 mb-2">
            <a href="<?= base_url('modules/standard_oqc/index.php') ?>" class="btn-secondary py-1.5 px-3 text-xs inline-flex items-center gap-1.5">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                </svg>
                Kembali ke Daftar
            </a>
        </div>

        <div class="bg-white rounded-2xl shadow-xs border border-slate-200 overflow-hidden">
            <div class="p-5 border-b border-slate-100 flex items-center justify-between">
                <div>
                    <h2 class="text-sm font-bold text-slate-800">Edit Dokumen Standard</h2>
                    <p class="text-xs text-slate-500 mt-0.5">Jika Anda mengunggah file baru, file PDF lama otomatis dihapus dari server.</p>
                </div>
                <div class="w-9 h-9 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                    </svg>
                </div>
            </div>

            <form id="editForm" action="<?= base_url('modules/standard_oqc/update.php') ?>" method="POST" enctype="multipart/form-data" class="p-5 md:p-6 space-y-5">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $doc['id'] ?>">

                <!-- Judul Dokumen -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">
                        Nama / Judul Dokumen <span class="text-rose-500">*</span>
                    </label>
                    <input type="text" name="title" required 
                           value="<?= htmlspecialchars($doc['title'], ENT_QUOTES) ?>"
                           class="w-full text-xs px-3.5 py-2.5 border border-slate-300 rounded-xl focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
                </div>



                <!-- File Saat Ini -->
                <div class="p-4 bg-slate-50 rounded-xl border border-slate-200">
                    <span class="text-[11px] font-bold text-slate-500 uppercase tracking-wider block mb-2">File PDF Saat Ini</span>
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-2.5">
                            <div class="w-8 h-8 rounded-lg bg-rose-100 text-rose-600 flex items-center justify-center font-bold text-xs flex-shrink-0">PDF</div>
                            <div>
                                <p class="text-xs font-bold text-slate-800"><?= htmlspecialchars($doc['file_name_original'], ENT_QUOTES) ?></p>
                                <p class="text-[11px] text-slate-400"><?= round($doc['file_size'] / 1024, 1) ?> KB</p>
                            </div>
                        </div>
                        <a href="<?= base_url($doc['file_path']) ?>" target="_blank" class="btn-secondary py-1.5 px-3 text-xs inline-flex items-center gap-1">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                            </svg>
                            Lihat Dokumen
                        </a>
                    </div>
                </div>

                <!-- Ganti File PDF (Opsional) -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">
                        Ganti File PDF <span class="text-slate-400 font-normal">(Kosongkan jika tidak ingin mengubah file PDF)</span>
                    </label>
                    
                    <div class="relative border-2 border-dashed border-slate-300 hover:border-amber-400 transition-colors rounded-2xl p-5 text-center cursor-pointer bg-slate-50/50" id="dropzone">
                        <input type="file" id="pdfFileInput" name="pdf_file" accept=".pdf,application/pdf" class="hidden">
                        
                        <div id="dropzonePlaceholder">
                            <p class="text-xs font-semibold text-slate-700">Klik untuk upload file PDF pengganti</p>
                            <p class="text-[11px] text-slate-400 mt-1">Jika dipilih, file lama akan dihapus dan digantikan file baru.</p>
                        </div>

                        <div id="fileInfoBox" class="hidden text-left bg-white p-3 rounded-xl border border-slate-200">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-2.5 min-w-0">
                                    <div class="w-8 h-8 rounded-lg bg-rose-100 text-rose-600 flex items-center justify-center font-bold text-xs flex-shrink-0">PDF Baru</div>
                                    <div class="min-w-0">
                                        <p id="selectedFileName" class="text-xs font-bold text-slate-800 truncate">document.pdf</p>
                                        <p id="selectedFileSize" class="text-[11px] text-slate-500">0 KB</p>
                                    </div>
                                </div>
                                <button type="button" id="btnRemoveFile" class="text-slate-400 hover:text-rose-500 p-1">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Compression Progress Bar -->
                <div id="compressionProgressContainer" class="hidden space-y-2 p-4 bg-amber-50 border border-amber-200 rounded-xl">
                    <div class="flex items-center justify-between text-xs font-semibold text-amber-900">
                        <span id="compressionStatusText" class="flex items-center gap-1.5">
                            <svg class="animate-spin w-3.5 h-3.5 text-amber-600" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                            </svg>
                            Mengompresi PDF di client...
                        </span>
                        <span id="compressionPercentText">0%</span>
                    </div>
                    <div class="w-full bg-amber-200 rounded-full h-2 overflow-hidden">
                        <div id="compressionProgressBar" class="bg-amber-600 h-2 rounded-full transition-all duration-200" style="width: 0%"></div>
                    </div>
                    <p id="compressionDetailText" class="text-[10px] text-amber-700">Memproses halaman...</p>
                </div>

                <!-- Submit Button -->
                <div class="pt-2 flex items-center justify-end space-x-3">
                    <a href="<?= base_url('modules/standard_oqc/index.php') ?>" class="btn-secondary py-2 px-4 text-xs font-semibold rounded-xl">
                        Batal
                    </a>
                    <button type="submit" id="btnSubmit" class="btn-primary py-2 px-5 text-xs font-bold rounded-xl inline-flex items-center gap-2 shadow-xs hover:shadow-sm">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                        <span>Simpan Perubahan</span>
                    </button>
                </div>
            </form>
        </div>
    </main>

<!-- Load Local Libraries for PDF Compression Offline -->
<script src="<?= base_url('assets/js/vendor/pdf.min.js') ?>"></script>
<script src="<?= base_url('assets/js/vendor/pdf-lib.min.js') ?>"></script>
<script src="<?= base_url('assets/js/modules/pdf_compressor.js') ?>"></script>

<script>
if (typeof pdfjsLib !== 'undefined') {
    pdfjsLib.GlobalWorkerOptions.workerSrc = '<?= base_url('assets/js/vendor/pdf.worker.min.js') ?>';
}

const dropzone = document.getElementById('dropzone');
const fileInput = document.getElementById('pdfFileInput');
const placeholder = document.getElementById('dropzonePlaceholder');
const infoBox = document.getElementById('fileInfoBox');
const fileName = document.getElementById('selectedFileName');
const fileSize = document.getElementById('selectedFileSize');
const btnRemove = document.getElementById('btnRemoveFile');
const form = document.getElementById('editForm');
const btnSubmit = document.getElementById('btnSubmit');

const compContainer = document.getElementById('compressionProgressContainer');
const compPercent = document.getElementById('compressionPercentText');
const compBar = document.getElementById('compressionProgressBar');
const compDetail = document.getElementById('compressionDetailText');

let processedFile = null;

function formatSize(bytes) {
    if (bytes <= 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return (bytes / Math.pow(1024, i)).toFixed(2) + ' ' + units[i];
}

dropzone.addEventListener('click', (e) => {
    if (e.target !== btnRemove && !btnRemove.contains(e.target)) {
        fileInput.click();
    }
});

fileInput.addEventListener('change', () => {
    if (fileInput.files.length > 0) {
        handleFileSelect(fileInput.files[0]);
    }
});

btnRemove.addEventListener('click', (e) => {
    e.stopPropagation();
    fileInput.value = '';
    processedFile = null;
    infoBox.classList.add('hidden');
    placeholder.classList.remove('hidden');
});

function handleFileSelect(file) {
    if (!file.name.toLowerCase().endsWith('.pdf')) {
        alert('Format file harus berupa .PDF');
        fileInput.value = '';
        return;
    }
    processedFile = file;
    fileName.innerText = file.name;
    fileSize.innerText = formatSize(file.size);
    placeholder.classList.add('hidden');
    infoBox.classList.remove('hidden');
}

// Drag & drop support
['dragenter', 'dragover'].forEach(eventName => {
    dropzone.addEventListener(eventName, (e) => {
        e.preventDefault();
        dropzone.classList.add('border-amber-500', 'bg-amber-50/40');
    });
});

['dragleave', 'drop'].forEach(eventName => {
    dropzone.addEventListener(eventName, (e) => {
        e.preventDefault();
        dropzone.classList.remove('border-amber-500', 'bg-amber-50/40');
    });
});

dropzone.addEventListener('drop', (e) => {
    if (e.dataTransfer.files.length > 0) {
        handleFileSelect(e.dataTransfer.files[0]);
    }
});

form.addEventListener('submit', async function(e) {
    e.preventDefault();

    const threshold = 1024 * 1024; // 1 MB
    let fileToUpload = processedFile;

    if (processedFile && processedFile.size > threshold) {
        btnSubmit.disabled = true;
        btnSubmit.classList.add('opacity-50', 'cursor-not-allowed');
        compContainer.classList.remove('hidden');

        try {
            const result = await window.ClientPDFCompressor.compressIfNeeded(processedFile, {
                threshold: threshold,
                quality: 0.72,
                maxDpiWidth: 1400,
                onProgress: (pct, msg) => {
                    compBar.style.width = pct + '%';
                    compPercent.innerText = pct + '%';
                    compDetail.innerText = msg;
                }
            });

            fileToUpload = result.file;
        } catch (err) {
            console.error('Compression failed:', err);
        }
    }

    btnSubmit.innerHTML = `
        <svg class="animate-spin w-4 h-4 text-white" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
        </svg>
        <span>Menyimpan...</span>
    `;

    const formData = new FormData(form);
    if (fileToUpload) {
        formData.set('pdf_file', fileToUpload, fileToUpload.name);
    } else {
        formData.delete('pdf_file');
    }

    fetch(form.action, {
        method: 'POST',
        body: formData
    })
    .then(res => {
        if (res.redirected) {
            window.location.href = res.url;
        } else {
            return res.text().then(text => {
                window.location.href = '<?= base_url('modules/standard_oqc/index.php') ?>';
            });
        }
    })
    .catch(err => {
        alert('Terjadi kesalahan saat menyimpan perubahan dokumen.');
        btnSubmit.disabled = false;
        btnSubmit.classList.remove('opacity-50', 'cursor-not-allowed');
        btnSubmit.innerHTML = `<span>Simpan Perubahan</span>`;
    });
});
</script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
