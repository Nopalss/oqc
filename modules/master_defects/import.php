<?php
$breadcrumbCategory = "DATA MASTER";
$pageTitle = "Import Jenis Defect";
$pageSubtitle = "Unggah daftar jenis defect massal via file Excel atau CSV";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';

require_menu_access('master_defects');
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen transition-all duration-300 bg-slate-100/70">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-6 space-y-4 max-w-4xl w-full mx-auto">
        
        <?= render_flash() ?>

        <!-- Header Card -->
        <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:16px 20px;box-shadow:0 1px 2px rgba(0,0,0,0.03);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
            <div>
                <a href="<?= base_url('modules/master_defects/index.php') ?>" 
                   style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;background:#f1f5f9;color:#475569;font-size:11px;font-weight:700;border-radius:6px;text-decoration:none;border:1px solid #e2e8f0;margin-bottom:6px;">
                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                    Kembali ke Daftar Defect
                </a>
                <h1 style="margin:0;font-size:17px;font-weight:800;color:#0f172a;">
                    Import Jenis Defect (Excel / CSV)
                </h1>
            </div>

            <!-- Download Template Buttons -->
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <a href="<?= base_url('modules/master_defects/download_template.php?format=xlsx') ?>"
                   style="display:inline-flex;align-items:center;gap:6px;padding:7px 12px;background:#059669;color:#ffffff;font-size:11px;font-weight:700;border-radius:8px;text-decoration:none;">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    Download Template Excel
                </a>
                <a href="<?= base_url('modules/master_defects/download_template.php?format=csv') ?>"
                   style="display:inline-flex;align-items:center;gap:6px;padding:7px 12px;background:#1e293b;color:#ffffff;font-size:11px;font-weight:700;border-radius:8px;text-decoration:none;">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                    </svg>
                    Download Template CSV
                </a>
            </div>
        </div>

        <!-- Section 1: Upload Card -->
        <div id="section-upload" style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;box-shadow:0 1px 2px rgba(0,0,0,0.03);display:flex;flex-direction:column;gap:14px;">
            
            <div style="border-bottom:1px solid #f1f5f9;padding-bottom:8px;">
                <h2 style="margin:0;font-size:13px;font-weight:800;color:#0f172a;">
                    Pilih File Excel atau CSV
                </h2>
                <p style="margin:3px 0 0 0;font-size:11px;color:#64748b;">
                    Format kolom baris pertama: <strong>code</strong> dan <strong>defect name</strong>
                </p>
            </div>

            <!-- Dropzone -->
            <div id="dropzone" 
                 style="border:2px dashed #cbd5e1;border-radius:10px;padding:30px 16px;text-align:center;cursor:pointer;background:#f8fafc;transition:all 0.2s;"
                 onmouseover="this.style.borderColor='#2563eb';this.style.background='#eff6ff'"
                 onmouseout="this.style.borderColor='#cbd5e1';this.style.background='#f8fafc'"
                 onclick="document.getElementById('file-input').click()">
                
                <input type="file" id="file-input" accept=".xlsx, .xls, .csv" style="display:none;" onchange="onFileInputChange(this)">

                <!-- Idle State -->
                <div id="dropzone-idle" style="display:flex;flex-direction:column;align-items:center;gap:6px;">
                    <div style="width:44px;height:44px;border-radius:50%;background:#e0e7ff;color:#4338ca;display:flex;align-items:center;justify-content:center;">
                        <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                    </div>
                    <div style="font-size:13px;font-weight:700;color:#0f172a;">
                        Klik untuk memilih file, atau seret file ke sini
                    </div>
                    <div style="font-size:11px;color:#64748b;">
                        Format yang didukung: .xlsx, .xls, .csv (Maksimal 10 MB)
                    </div>
                </div>

                <!-- Selected State -->
                <div id="dropzone-selected" style="display:none;background:#ffffff;border:1px solid #bfdbfe;border-radius:8px;padding:10px 14px;max-width:400px;margin:0 auto;text-align:left;">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
                        <div style="min-width:0;">
                            <div id="file-name-label" style="font-size:12px;font-weight:800;color:#0f172a;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">-</div>
                            <div id="file-size-label" style="font-size:11px;color:#64748b;">- KB</div>
                        </div>
                        <button type="button" onclick="event.stopPropagation(); resetFile();" 
                                style="background:#fee2e2;color:#b91c1c;border:1px solid #fca5a5;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:700;cursor:pointer;">
                            Ganti File
                        </button>
                    </div>
                </div>

            </div>

            <!-- Preview Button -->
            <div style="display:flex;align-items:center;justify-content:flex-end;">
                <button type="button" id="btn-preview" onclick="processPreview()" disabled
                        style="display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:8px;font-size:12px;font-weight:800;background:#cbd5e1;color:#64748b;border:none;cursor:not-allowed;">
                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                    <span>Pratinjau File</span>
                </button>
            </div>

        </div>

        <!-- Section 2: Loading State -->
        <div id="section-loading" style="display:none;background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:30px 16px;text-align:center;">
            <div style="width:36px;height:36px;border:3px solid #e2e8f0;border-top-color:#2563eb;border-radius:50%;animation:spin 0.8s linear infinite;margin:0 auto 10px auto;"></div>
            <div style="font-size:13px;font-weight:800;color:#0f172a;">Sedang Membaca & Memeriksa File...</div>
            <div style="font-size:11px;color:#64748b;margin-top:2px;">Sistem sedang memeriksa kesesuaian data dan duplikat.</div>
        </div>

        <!-- Section 3: Review & Confirmation -->
        <div id="section-review" style="display:none;flex-direction:column;gap:12px;">
            
            <!-- Summary KPI Cards -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(160px, 1fr));gap:10px;">
                <!-- Total -->
                <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:10px;padding:10px 14px;">
                    <span style="font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;">Total Baris File</span>
                    <div id="kpi-total" style="font-size:20px;font-weight:900;color:#0f172a;font-family:monospace;margin-top:2px;">0</div>
                </div>
                <!-- Valid -->
                <div style="background:#ffffff;border:1px solid #86efac;border-radius:10px;padding:10px 14px;">
                    <span style="font-size:10px;font-weight:800;color:#15803d;text-transform:uppercase;">Siap Disimpan</span>
                    <div id="kpi-valid" style="font-size:20px;font-weight:900;color:#16a34a;font-family:monospace;margin-top:2px;">0</div>
                    <span style="font-size:10px;color:#16a34a;font-weight:600;">Data baru</span>
                </div>
                <!-- Duplicate DB -->
                <div style="background:#ffffff;border:1px solid #fde68a;border-radius:10px;padding:10px 14px;">
                    <span style="font-size:10px;font-weight:800;color:#b45309;text-transform:uppercase;">Duplikat di Database</span>
                    <div id="kpi-dup-db" style="font-size:20px;font-weight:900;color:#d97706;font-family:monospace;margin-top:2px;">0</div>
                    <span style="font-size:10px;color:#d97706;font-weight:600;">Akan dilewati</span>
                </div>
                <!-- Duplicate File -->
                <div style="background:#ffffff;border:1px solid #fed7aa;border-radius:10px;padding:10px 14px;">
                    <span style="font-size:10px;font-weight:800;color:#c2410c;text-transform:uppercase;">Duplikat di File</span>
                    <div id="kpi-dup-file" style="font-size:20px;font-weight:900;color:#ea580c;font-family:monospace;margin-top:2px;">0</div>
                    <span style="font-size:10px;color:#ea580c;font-weight:600;">Akan dilewati</span>
                </div>
            </div>

            <!-- Preview Table Card -->
            <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;box-shadow:0 1px 2px rgba(0,0,0,0.03);">
                <div style="padding:10px 14px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;background:#f8fafc;">
                    <div style="font-size:12px;font-weight:800;color:#0f172a;">
                        Daftar Baris Hasil Pembacaan File
                    </div>
                    <div style="display:flex;align-items:center;gap:6px;">
                        <button type="button" onclick="setFilter('all')" id="tab-all"
                                style="padding:3px 10px;font-size:11px;font-weight:700;border-radius:6px;border:1px solid #2563eb;background:#2563eb;color:#ffffff;cursor:pointer;">
                            Semua (<span id="count-tab-all">0</span>)
                        </button>
                        <button type="button" onclick="setFilter('valid')" id="tab-valid"
                                style="padding:3px 10px;font-size:11px;font-weight:700;border-radius:6px;border:1px solid #cbd5e1;background:#ffffff;color:#475569;cursor:pointer;">
                            Siap Disimpan (<span id="count-tab-valid">0</span>)
                        </button>
                        <button type="button" onclick="setFilter('duplicate')" id="tab-dup"
                                style="padding:3px 10px;font-size:11px;font-weight:700;border-radius:6px;border:1px solid #cbd5e1;background:#ffffff;color:#475569;cursor:pointer;">
                            Duplikat (<span id="count-tab-dup">0</span>)
                        </button>
                    </div>
                </div>

                <div style="max-height:360px;overflow-y:auto;">
                    <table style="width:100%;border-collapse:collapse;font-size:11px;text-align:left;">
                        <thead style="background:#f1f5f9;color:#475569;font-weight:800;position:sticky;top:0;z-index:1;border-bottom:1px solid #e2e8f0;">
                            <tr>
                                <th style="padding:8px 12px;width:48px;text-align:center;">No</th>
                                <th style="padding:8px 12px;width:120px;">Kode Defect</th>
                                <th style="padding:8px 12px;">Nama Jenis Defect</th>
                                <th style="padding:8px 12px;width:130px;text-align:center;">Status</th>
                                <th style="padding:8px 12px;">Keterangan</th>
                            </tr>
                        </thead>
                        <tbody id="table-preview-body">
                            <!-- Populated by JavaScript -->
                        </tbody>
                    </table>
                </div>

                <!-- Footer Action -->
                <div style="padding:12px 16px;border-top:1px solid #e2e8f0;background:#f8fafc;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                    <button type="button" onclick="resetToUpload()" 
                            style="padding:7px 14px;background:#e2e8f0;color:#1e293b;border:none;border-radius:8px;font-size:11px;font-weight:700;cursor:pointer;">
                        Ganti File
                    </button>

                    <form id="form-store-import" action="<?= base_url('modules/master_defects/store_import.php') ?>" method="POST" style="margin:0;">
                        <input type="hidden" name="import_data_json" id="input-import-data-json" value="">
                        <button type="submit" id="btn-save-db"
                                style="display:inline-flex;align-items:center;gap:6px;padding:8px 20px;background:#16a34a;color:#ffffff;border:none;border-radius:8px;font-size:12px;font-weight:800;cursor:pointer;box-shadow:0 1px 2px rgba(22,163,74,0.3);">
                            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                            <span id="btn-save-label">Simpan ke Database</span>
                        </button>
                    </form>
                </div>

            </div>

        </div>

    </main>

<style>
@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
</style>

<script>
var selectedFile = null;
var parsedRows = [];
var currentFilter = 'all';

function onFileInputChange(input) {
    if (input.files && input.files[0]) {
        setFile(input.files[0]);
    }
}

function setFile(file) {
    selectedFile = file;
    document.getElementById('file-name-label').textContent = file.name;
    document.getElementById('file-size-label').textContent = (file.size / 1024).toFixed(1) + ' KB';
    
    document.getElementById('dropzone-idle').style.display = 'none';
    document.getElementById('dropzone-selected').style.display = 'block';

    var btn = document.getElementById('btn-preview');
    btn.disabled = false;
    btn.style.background = '#2563eb';
    btn.style.color = '#ffffff';
    btn.style.cursor = 'pointer';
}

function resetFile() {
    selectedFile = null;
    document.getElementById('file-input').value = '';
    document.getElementById('dropzone-idle').style.display = 'flex';
    document.getElementById('dropzone-selected').style.display = 'none';

    var btn = document.getElementById('btn-preview');
    btn.disabled = true;
    btn.style.background = '#cbd5e1';
    btn.style.color = '#64748b';
    btn.style.cursor = 'not-allowed';
}

function resetToUpload() {
    document.getElementById('section-review').style.display = 'none';
    document.getElementById('section-upload').style.display = 'flex';
    resetFile();
}

// Drag & drop support
var dropzone = document.getElementById('dropzone');
['dragenter', 'dragover'].forEach(function(eventName) {
    dropzone.addEventListener(eventName, function(e) {
        e.preventDefault();
        e.stopPropagation();
        dropzone.style.borderColor = '#2563eb';
        dropzone.style.background = '#eff6ff';
    }, false);
});
['dragleave', 'drop'].forEach(function(eventName) {
    dropzone.addEventListener(eventName, function(e) {
        e.preventDefault();
        e.stopPropagation();
        dropzone.style.borderColor = '#cbd5e1';
        dropzone.style.background = '#f8fafc';
    }, false);
});
dropzone.addEventListener('drop', function(e) {
    var dt = e.dataTransfer;
    if (dt && dt.files && dt.files[0]) {
        setFile(dt.files[0]);
    }
}, false);

function processPreview() {
    if (!selectedFile) return;

    document.getElementById('section-upload').style.display = 'none';
    document.getElementById('section-loading').style.display = 'block';

    var formData = new FormData();
    formData.append('excel_file', selectedFile);

    fetch('<?= base_url("modules/master_defects/preview_import.php") ?>', {
        method: 'POST',
        body: formData
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        document.getElementById('section-loading').style.display = 'none';

        if (!data.success) {
            alert('Gagal memproses file: ' + (data.message || 'Format tidak didukung'));
            document.getElementById('section-upload').style.display = 'flex';
            return;
        }

        parsedRows = data.rows || [];
        renderReview(data.summary, parsedRows);
    })
    .catch(function(err) {
        document.getElementById('section-loading').style.display = 'none';
        alert('Terjadi kesalahan jaringan atau server!');
        document.getElementById('section-upload').style.display = 'flex';
    });
}

function renderReview(summary, rows) {
    document.getElementById('kpi-total').textContent = summary.total || 0;
    document.getElementById('kpi-valid').textContent = summary.valid || 0;
    document.getElementById('kpi-dup-db').textContent = summary.duplicate_db || 0;
    document.getElementById('kpi-dup-file').textContent = summary.duplicate_file || 0;

    var dupTotal = (summary.duplicate_db || 0) + (summary.duplicate_file || 0);
    document.getElementById('count-tab-all').textContent = summary.total || 0;
    document.getElementById('count-tab-valid').textContent = summary.valid || 0;
    document.getElementById('count-tab-dup').textContent = dupTotal;

    var validRows = rows.filter(function(r) { return r.status === 'valid'; });
    document.getElementById('input-import-data-json').value = JSON.stringify(validRows);

    var saveBtn = document.getElementById('btn-save-db');
    var saveLabel = document.getElementById('btn-save-label');
    if (validRows.length === 0) {
        saveBtn.disabled = true;
        saveBtn.style.background = '#cbd5e1';
        saveBtn.style.cursor = 'not-allowed';
        saveLabel.textContent = 'Tidak Ada Data Baru untuk Disimpan';
    } else {
        saveBtn.disabled = false;
        saveBtn.style.background = '#16a34a';
        saveBtn.style.cursor = 'pointer';
        saveLabel.textContent = 'Simpan ' + validRows.length + ' Data Baru ke Database';
    }

    setFilter('all');
    document.getElementById('section-review').style.display = 'flex';
}

function setFilter(filterType) {
    currentFilter = filterType;

    var tabs = ['all', 'valid', 'dup'];
    tabs.forEach(function(t) {
        var el = document.getElementById('tab-' + t);
        if (t === (filterType === 'duplicate' ? 'dup' : filterType)) {
            el.style.background = '#2563eb';
            el.style.color = '#ffffff';
            el.style.borderColor = '#2563eb';
        } else {
            el.style.background = '#ffffff';
            el.style.color = '#475569';
            el.style.borderColor = '#cbd5e1';
        }
    });

    renderTable();
}

function renderTable() {
    var tbody = document.getElementById('table-preview-body');
    tbody.innerHTML = '';

    var filtered = parsedRows.filter(function(r) {
        if (currentFilter === 'valid') return r.status === 'valid';
        if (currentFilter === 'duplicate') return r.status === 'duplicate_db' || r.status === 'duplicate_file';
        return true;
    });

    if (filtered.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:24px;color:#94a3b8;">Tidak ada data pada kategori ini.</td></tr>';
        return;
    }

    filtered.forEach(function(r, idx) {
        var tr = document.createElement('tr');
        tr.style.borderBottom = '1px solid #f1f5f9';

        var badgeBg = '#f1f5f9';
        var badgeColor = '#475569';
        var badgeText = 'Tidak Valid';

        if (r.status === 'valid') {
            badgeBg = '#dcfce7';
            badgeColor = '#15803d';
            badgeText = 'Siap Disimpan';
        } else if (r.status === 'duplicate_db') {
            badgeBg = '#fef3c7';
            badgeColor = '#b45309';
            badgeText = 'Duplikat di DB';
        } else if (r.status === 'duplicate_file') {
            badgeBg = '#ffedd5';
            badgeColor = '#c2410c';
            badgeText = 'Kembar di File';
        }

        tr.innerHTML = 
            '<td style="padding:7px 12px;text-align:center;color:#64748b;">' + (idx + 1) + '</td>' +
            '<td style="padding:7px 12px;font-family:monospace;font-weight:700;color:#0f172a;">' + escapeHtml(r.code || '-') + '</td>' +
            '<td style="padding:7px 12px;font-weight:600;color:#1e293b;">' + escapeHtml(r.defect_name) + '</td>' +
            '<td style="padding:7px 12px;text-align:center;">' +
                '<span style="display:inline-block;padding:2px 8px;border-radius:6px;font-size:10px;font-weight:700;background:' + badgeBg + ';color:' + badgeColor + ';">' +
                    badgeText +
                '</span>' +
            '</td>' +
            '<td style="padding:7px 12px;color:#64748b;font-size:10.5px;">' + escapeHtml(r.notes || '-') + '</td>';

        tbody.appendChild(tr);
    });
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
</script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
