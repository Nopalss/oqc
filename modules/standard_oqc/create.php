<?php
$pageTitle = "Upload Standard OQC";
$breadcrumbCategory = "DATA REFERENSI";
$pageSubtitle = "Upload dokumen prosedur kerja, SOP, atau Work Instruction (WI)";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';

// Enforce permission
require_menu_access('standard_oqc');
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
                    <h2 class="text-sm font-bold text-slate-800">Form Upload Dokumen Standard</h2>
                    <p class="text-xs text-slate-500 mt-0.5">File PDF lebih dari 1 MB otomatis dikompresi di browser sebelum diunggah.</p>
                </div>
                <div class="w-9 h-9 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                    </svg>
                </div>
            </div>

            <form id="uploadForm" action="<?= base_url('modules/standard_oqc/store.php') ?>" method="POST" enctype="multipart/form-data" class="p-5 md:p-6 space-y-5">
                <?= csrf_field() ?>

                <!-- Judul Dokumen -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">
                        Nama / Judul Dokumen <span class="text-rose-500">*</span>
                    </label>
                    <input type="text" name="title" required 
                           placeholder="Contoh: Petunjuk Kerja Inspeksi Visual PCB, SOP Sampling AQL" 
                           class="w-full text-xs px-3.5 py-2.5 border border-slate-300 rounded-xl focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
                </div>



                <!-- Upload File PDF -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">
                        Pilih File PDF <span class="text-rose-500">*</span>
                    </label>
                    
                    <div class="relative border-2 border-dashed border-slate-300 hover:border-blue-400 transition-colors rounded-2xl p-6 text-center cursor-pointer bg-slate-50/50" id="dropzone">
                        <input type="file" id="pdfFileInput" name="pdf_file" accept=".pdf,application/pdf" required class="hidden">
                        
                        <div id="dropzonePlaceholder">
                            <div class="w-12 h-12 mx-auto mb-2 text-rose-500 bg-rose-50 rounded-full flex items-center justify-center">
                                <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4zm2 6a1 1 0 011-1h6a1 1 0 110 2H7a1 1 0 01-1-1zm1 3a1 1 0 100 2h6a1 1 0 100-2H7z" clip-rule="evenodd"></path>
                                </svg>
                            </div>
                            <p class="text-xs font-semibold text-slate-700">Klik untuk memilih file PDF atau drag & drop di sini</p>
                            <p class="text-[11px] text-slate-400 mt-1">Hanya format .PDF (Maks. 20MB). File di atas 1MB otomatis dikompresi di browser.</p>
                        </div>

                        <div id="fileInfoBox" class="hidden text-left bg-white p-3.5 rounded-xl border border-slate-200">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-2.5 min-w-0">
                                    <div class="w-8 h-8 rounded-lg bg-rose-100 text-rose-600 flex items-center justify-center font-bold text-xs flex-shrink-0">PDF</div>
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
                <div id="compressionProgressContainer" class="hidden space-y-2 p-4 bg-blue-50 border border-blue-200 rounded-xl">
                    <div class="flex items-center justify-between text-xs font-semibold text-blue-900">
                        <span id="compressionStatusText" class="flex items-center gap-1.5">
                            <svg class="animate-spin w-3.5 h-3.5 text-blue-600" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                            </svg>
                            Mengompresi PDF di client...
                        </span>
                        <span id="compressionPercentText">0%</span>
                    </div>
                    <div class="w-full bg-blue-200 rounded-full h-2 overflow-hidden">
                        <div id="compressionProgressBar" class="bg-blue-600 h-2 rounded-full transition-all duration-200" style="width: 0%"></div>
                    </div>
                    <p id="compressionDetailText" class="text-[10px] text-blue-700">Memproses halaman...</p>
                </div>

                <!-- Submit Button -->
                <div class="pt-2 flex items-center justify-end space-x-3">
                    <a href="<?= base_url('modules/standard_oqc/index.php') ?>" class="btn-secondary py-2 px-4 text-xs font-semibold rounded-xl">
                        Batal
                    </a>
                    <button type="submit" id="btnSubmit" class="btn-primary py-2 px-5 text-xs font-bold rounded-xl inline-flex items-center gap-2 shadow-xs hover:shadow-sm">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                        </svg>
                        <span>Simpan & Upload</span>
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
// Configure local PDF worker
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
const form = document.getElementById('uploadForm');
const btnSubmit = document.getElementById('btnSubmit');

const compContainer = document.getElementById('compressionProgressContainer');
const compStatus = document.getElementById('compressionStatusText');
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
        dropzone.classList.add('border-blue-500', 'bg-blue-50/40');
    });
});

['dragleave', 'drop'].forEach(eventName => {
    dropzone.addEventListener(eventName, (e) => {
        e.preventDefault();
        dropzone.classList.remove('border-blue-500', 'bg-blue-50/40');
    });
});

dropzone.addEventListener('drop', (e) => {
    if (e.dataTransfer.files.length > 0) {
        handleFileSelect(e.dataTransfer.files[0]);
    }
});

// Form Submit with Client-side Compression
form.addEventListener('submit', async function(e) {
    e.preventDefault();

    if (!processedFile) {
        alert('Silakan pilih file PDF terlebih dahulu.');
        return;
    }

    const threshold = 1024 * 1024; // 1 MB
    let fileToUpload = processedFile;

    // Check if compression needed (> 1MB)
    if (processedFile.size > threshold) {
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
            if (result.compressed) {
                fileSize.innerText = `${formatSize(result.newSize)} (Terkompresi dari ${formatSize(result.originalSize)})`;
            }
        } catch (err) {
            console.error('Compression failed:', err);
        }
    }

    // Submit form via standard FormData
    btnSubmit.innerHTML = `
        <svg class="animate-spin w-4 h-4 text-white" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
        </svg>
        <span>Mengunggah...</span>
    `;

    const formData = new FormData(form);
    formData.set('pdf_file', fileToUpload, fileToUpload.name);

    fetch(form.action, {
        method: 'POST',
        body: formData
    })
    .then(res => {
        if (res.redirected) {
            window.location.href = res.url;
        } else {
            return res.text().then(text => {
                // If backend returned HTML (redirect script or reload)
                window.location.href = '<?= base_url('modules/standard_oqc/index.php') ?>';
            });
        }
    })
    .catch(err => {
        alert('Terjadi kesalahan saat mengunggah dokumen.');
        btnSubmit.disabled = false;
        btnSubmit.classList.remove('opacity-50', 'cursor-not-allowed');
        btnSubmit.innerHTML = `<span>Simpan & Upload</span>`;
    });
});
</script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
