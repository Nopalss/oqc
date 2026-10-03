<?php
$breadcrumbCategory = "MASTER DATA";
$pageTitle = "Import Master Part";
$pageSubtitle = "Unggah referensi Part Code dan Part Name massal via file Excel / CSV dengan pratinjau sebelum disimpan";

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';

require_menu_access('master_parts');
?>

<div id="main-content-wrapper" class="flex-1 md:pl-64 flex flex-col min-h-screen transition-all duration-300 bg-slate-100/70">
    
    <?php require_once __DIR__ . '/../../layouts/navbar.php'; ?>

    <main class="flex-1 p-3 md:p-6 space-y-4 max-w-5xl w-full mx-auto">
        
        <?= render_flash() ?>

        <!-- ── Top Header & Stepper Card ────────────────────────────────────────── -->
        <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:14px;padding:16px 20px;box-shadow:0 1px 3px rgba(0,0,0,0.03);display:flex;flex-direction:column;gap:14px;">
            
            <!-- Row 1: Title & Download Buttons -->
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                <div style="display:flex;flex-direction:column;gap:4px;">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <a href="<?= base_url('modules/master_parts/index.php') ?>" 
                           style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;background:#f1f5f9;color:#475569;font-size:11px;font-weight:700;border-radius:7px;text-decoration:none;border:1px solid #e2e8f0;transition:all 0.15s;"
                           onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='#f1f5f9'">
                            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                            Kembali ke Master Part
                        </a>
                        <span style="font-size:10px;font-weight:800;color:#2563eb;background:#eff6ff;border:1px solid #dbeafe;padding:3px 8px;border-radius:6px;text-transform:uppercase;letter-spacing:0.5px;">
                            Batch Importer
                        </span>
                    </div>
                    <h1 style="margin:0;font-size:18px;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:6px;">
                        Import Master Part Code
                        <span style="font-size:12px;font-weight:600;color:#64748b;">(Excel / CSV)</span>
                    </h1>
                </div>

                <!-- Template Download Buttons (Solid, Clear & Visible) -->
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <a href="<?= base_url('modules/master_parts/template_master_part.xlsx') ?>" download="template_master_part.xlsx"
                       style="display:inline-flex;align-items:center;gap:7px;padding:8px 14px;background:#059669;color:#ffffff;font-size:12px;font-weight:700;border-radius:9px;text-decoration:none;box-shadow:0 1px 3px rgba(5,150,105,0.3);transition:all 0.15s;"
                       onmouseover="this.style.background='#047857'" onmouseout="this.style.background='#059669'">
                        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        Download Template .XLSX
                    </a>
                    <a href="<?= base_url('modules/master_parts/template_master_part.csv') ?>" download="template_master_part.csv"
                       style="display:inline-flex;align-items:center;gap:7px;padding:8px 14px;background:#0f172a;color:#ffffff;font-size:12px;font-weight:700;border-radius:9px;text-decoration:none;box-shadow:0 1px 3px rgba(15,23,42,0.25);transition:all 0.15s;"
                       onmouseover="this.style.background='#1e293b'" onmouseout="this.style.background='#0f172a'">
                        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                        </svg>
                        Template .CSV
                    </a>
                </div>
            </div>

            <!-- Row 2: Stepper in 1 Strict Horizontal Row -->
            <div style="display:flex;align-items:center;gap:8px;border-top:1px solid #f1f5f9;padding-top:12px;">
                <!-- Step 1 -->
                <div id="step-pill-1" style="flex:1;min-width:0;display:flex;align-items:center;gap:10px;padding:8px 12px;border-radius:10px;background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a;">
                    <span id="step-num-1" style="width:24px;height:24px;border-radius:50%;background:#2563eb;color:#ffffff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:900;flex-shrink:0;">1</span>
                    <div style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <div style="font-size:11px;font-weight:800;line-height:1.2;">Langkah 1</div>
                        <div style="font-size:10px;color:#3b82f6;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">Siapkan File Excel / CSV</div>
                    </div>
                </div>
                <!-- Step 2 -->
                <div id="step-pill-2" style="flex:1;min-width:0;display:flex;align-items:center;gap:10px;padding:8px 12px;border-radius:10px;background:#f8fafc;border:1px solid #e2e8f0;color:#64748b;">
                    <span id="step-num-2" style="width:24px;height:24px;border-radius:50%;background:#cbd5e1;color:#475569;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:900;flex-shrink:0;">2</span>
                    <div style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <div style="font-size:11px;font-weight:800;line-height:1.2;">Langkah 2</div>
                        <div style="font-size:10px;color:#94a3b8;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">Unggah & Validasi Duplikat</div>
                    </div>
                </div>
                <!-- Step 3 -->
                <div id="step-pill-3" style="flex:1;min-width:0;display:flex;align-items:center;gap:10px;padding:8px 12px;border-radius:10px;background:#f8fafc;border:1px solid #e2e8f0;color:#64748b;">
                    <span id="step-num-3" style="width:24px;height:24px;border-radius:50%;background:#cbd5e1;color:#475569;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:900;flex-shrink:0;">3</span>
                    <div style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <div style="font-size:11px;font-weight:800;line-height:1.2;">Langkah 3</div>
                        <div style="font-size:10px;color:#94a3b8;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">Review Hasil & Simpan DB</div>
                    </div>
                </div>
            </div>

        </div>

        <!-- ── Section 1: Upload Card (Clean, Centered, Solid) ───────────────────── -->
        <div id="section-upload" style="background:#ffffff;border:1px solid #e2e8f0;border-radius:14px;padding:24px;box-shadow:0 1px 3px rgba(0,0,0,0.03);display:flex;flex-direction:column;gap:16px;">
            
            <div style="display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #f1f5f9;padding-bottom:12px;flex-wrap:wrap;gap:8px;">
                <div>
                    <h2 style="margin:0;font-size:14px;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:6px;">
                        <svg width="18" height="18" fill="none" stroke="#2563eb" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                        Unggah File Spreadsheet
                    </h2>
                    <p style="margin:2px 0 0 0;font-size:11px;color:#64748b;">
                        Format kolom baris 1: <strong>model</strong>, <strong>part code</strong>, <strong>part name</strong>
                    </p>
                </div>
                <span style="font-size:11px;font-weight:700;color:#64748b;background:#f1f5f9;padding:4px 10px;border-radius:7px;border:1px solid #e2e8f0;">
                    Maksimal 10 MB (.xlsx / .csv)
                </span>
            </div>

            <!-- Dropzone Box -->
            <div id="dropzone" 
                 style="border:2px dashed #cbd5e1;border-radius:14px;padding:36px 20px;text-align:center;cursor:pointer;background:#f8fafc;transition:all 0.2s;"
                 onmouseover="this.style.borderColor='#2563eb';this.style.background='#eff6ff'"
                 onmouseout="this.style.borderColor='#cbd5e1';this.style.background='#f8fafc'"
                 onclick="document.getElementById('excel-file-input').click()">
                
                <input type="file" id="excel-file-input" accept=".xlsx, .xls, .csv" style="display:none;" onchange="onFileInputChange(this)">

                <!-- Idle State Content -->
                <div id="dropzone-idle-content" style="max-width:400px;margin:0 auto;display:flex;flex-direction:column;align-items:center;gap:8px;">
                    <div style="width:52px;height:52px;border-radius:50%;background:#dbeafe;color:#2563eb;display:flex;align-items:center;justify-content:center;">
                        <svg width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                    </div>
                    <div style="font-size:14px;font-weight:800;color:#0f172a;">
                        Tarik & Lepas File di Sini, atau <span style="color:#2563eb;text-decoration:underline;">Pilih File</span>
                    </div>
                    <div style="font-size:11px;color:#64748b;">
                        Mendukung format file <strong>.xlsx</strong>, <strong>.xls</strong>, dan <strong>.csv</strong>
                    </div>
                </div>

                <!-- Selected State Content -->
                <div id="dropzone-selected-content" style="display:none;max-width:440px;margin:0 auto;background:#ffffff;border:1px solid #bfdbfe;border-radius:12px;padding:12px 16px;text-align:left;box-shadow:0 1px 3px rgba(37,99,235,0.1);">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
                        <div style="display:flex;align-items:center;gap:12px;min-width:0;">
                            <div id="badge-file-icon" style="width:38px;height:38px;border-radius:8px;background:#059669;color:#ffffff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:900;flex-shrink:0;">
                                XLS
                            </div>
                            <div style="min-width:0;">
                                <div id="label-file-name" style="font-size:13px;font-weight:800;color:#0f172a;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">-</div>
                                <div id="label-file-size" style="font-size:11px;color:#64748b;font-weight:600;">- KB</div>
                            </div>
                        </div>
                        <button type="button" onclick="event.stopPropagation(); clearSelectedFile();" 
                                style="background:#fff1f2;color:#e11d48;border:1px solid #fecdd3;padding:4px 8px;border-radius:7px;font-size:11px;font-weight:700;cursor:pointer;">
                            Ganti
                        </button>
                    </div>
                </div>

            </div>

            <!-- Trigger Button -->
            <div style="display:flex;align-items:center;justify-content:flex-end;">
                <button type="button" id="btn-parse-trigger" onclick="submitForPreview()" disabled
                        style="display:inline-flex;align-items:center;gap:8px;padding:10px 24px;border-radius:9px;font-size:12px;font-weight:800;background:#cbd5e1;color:#64748b;border:none;cursor:not-allowed;transition:all 0.15s;">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                    <span>Pratinjau & Review File</span>
                </button>
            </div>

        </div>

        <!-- ── Section 2: Loading Indicator ──────────────────────────────────────── -->
        <div id="section-loading" style="display:none;background:#ffffff;border:1px solid #e2e8f0;border-radius:14px;padding:40px 20px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,0.03);">
            <div style="width:40px;height:40px;border:4px solid #e2e8f0;border-top-color:#2563eb;border-radius:50%;animation:spin 0.8s linear infinite;margin:0 auto 14px auto;"></div>
            <div style="font-size:14px;font-weight:900;color:#0f172a;">Membaca & Memvalidasi File...</div>
            <p style="margin:4px 0 0 0;font-size:11px;color:#64748b;">
                Sistem sedang mengecek duplikasi di database dan menyiapkan tabel pratinjau.
            </p>
        </div>

        <!-- ── Section 3: Review Container ───────────────────────────────────────── -->
        <div id="section-review" style="display:none;flex-direction:column;gap:14px;">
            
            <!-- 5 KPI Cards -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:10px;">
                
                <!-- Total -->
                <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:12px 14px;">
                    <span style="font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;">Total Baris File</span>
                    <div id="kpi-total" style="font-size:22px;font-weight:900;color:#0f172a;font-family:monospace;margin-top:2px;">0</div>
                    <span id="kpi-filename" style="font-size:10px;color:#94a3b8;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">-</span>
                </div>

                <!-- Valid -->
                <div style="background:#ffffff;border:1px solid #a7f3d0;border-radius:12px;padding:12px 14px;">
                    <span style="font-size:10px;font-weight:800;color:#065f46;text-transform:uppercase;">✓ Siap Disimpan</span>
                    <div id="kpi-valid" style="font-size:22px;font-weight:900;color:#059669;font-family:monospace;margin-top:2px;">0</div>
                    <span style="font-size:10px;color:#059669;font-weight:700;">Data Part Baru</span>
                </div>

                <!-- DB Duplicates -->
                <div style="background:#ffffff;border:1px solid #fde68a;border-radius:12px;padding:12px 14px;">
                    <span style="font-size:10px;font-weight:800;color:#92400e;text-transform:uppercase;">⚠️ Duplikat di DB</span>
                    <div id="kpi-dup-db" style="font-size:22px;font-weight:900;color:#d97706;font-family:monospace;margin-top:2px;">0</div>
                    <span style="font-size:10px;color:#d97706;font-weight:700;">Dilewati</span>
                </div>

                <!-- File Duplicates -->
                <div style="background:#ffffff;border:1px solid #fed7aa;border-radius:12px;padding:12px 14px;">
                    <span style="font-size:10px;font-weight:800;color:#9a3412;text-transform:uppercase;">⚠️ Kembar di File</span>
                    <div id="kpi-dup-file" style="font-size:22px;font-weight:900;color:#ea580c;font-family:monospace;margin-top:2px;">0</div>
                    <span style="font-size:10px;color:#ea580c;font-weight:700;">Dilewati</span>
                </div>

                <!-- New Models -->
                <div style="background:#ffffff;border:1px solid #c7d2fe;border-radius:12px;padding:12px 14px;">
                    <span style="font-size:10px;font-weight:800;color:#3730a3;text-transform:uppercase;">✨ Model Baru</span>
                    <div id="kpi-new-models" style="font-size:22px;font-weight:900;color:#4f46e5;font-family:monospace;margin-top:2px;">0</div>
                    <span style="font-size:10px;color:#4f46e5;font-weight:700;">Otomatis Didaftarkan</span>
                </div>

            </div>

            <!-- Toolbar: Search & Filter Tabs -->
            <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:12px 16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <!-- Search Input -->
                    <input type="text" id="review-search-box" onkeyup="filterReviewData()" placeholder="Cari Part Code, Nama, Model..." 
                           style="padding:6px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:11px;min-width:220px;">
                    
                    <!-- Tabs -->
                    <div style="display:flex;align-items:center;gap:3px;background:#f1f5f9;padding:3px;border-radius:8px;border:1px solid #e2e8f0;">
                        <button type="button" onclick="changeReviewFilter('all')" id="tab-filter-all" 
                                style="padding:4px 10px;border-radius:6px;font-size:11px;font-weight:800;border:none;cursor:pointer;background:#0f172a;color:#ffffff;">
                            Semua (<span id="count-pill-all">0</span>)
                        </button>
                        <button type="button" onclick="changeReviewFilter('valid')" id="tab-filter-valid" 
                                style="padding:4px 10px;border-radius:6px;font-size:11px;font-weight:700;border:none;cursor:pointer;background:transparent;color:#64748b;">
                            ✓ Siap Simpan (<span id="count-pill-valid">0</span>)
                        </button>
                        <button type="button" onclick="changeReviewFilter('dup')" id="tab-filter-dup" 
                                style="padding:4px 10px;border-radius:6px;font-size:11px;font-weight:700;border:none;cursor:pointer;background:transparent;color:#64748b;">
                            ⚠️ Duplikat (<span id="count-pill-dup">0</span>)
                        </button>
                        <button type="button" onclick="changeReviewFilter('invalid')" id="tab-filter-invalid" 
                                style="padding:4px 10px;border-radius:6px;font-size:11px;font-weight:700;border:none;cursor:pointer;background:transparent;color:#64748b;">
                            ✕ Data Kurang (<span id="count-pill-invalid">0</span>)
                        </button>
                    </div>
                </div>

                <span style="font-size:11px;color:#64748b;">
                    Menampilkan <strong id="lbl-visible-count" style="color:#2563eb;font-family:monospace;">0</strong> baris
                </span>
            </div>

            <!-- Table Card -->
            <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.03);">
                <div style="max-height:460px;overflow:auto;">
                    <table style="width:100%;border-collapse:collapse;font-size:11px;text-align:left;">
                        <thead>
                            <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;color:#475569;font-weight:800;position:sticky;top:0;z-index:2;">
                                <th style="padding:10px 14px;width:50px;text-align:center;">No</th>
                                <th style="padding:10px 14px;width:180px;">Model Produk</th>
                                <th style="padding:10px 14px;width:190px;">Part Code</th>
                                <th style="padding:10px 14px;">Part Name</th>
                                <th style="padding:10px 14px;width:110px;">Level AQL</th>
                                <th style="padding:10px 14px;width:200px;">Status Validasi</th>
                            </tr>
                        </thead>
                        <tbody id="table-review-body">
                            <!-- Injected by JS -->
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Footer Bar -->
                <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 16px;background:#f8fafc;border-top:1px solid #e2e8f0;flex-wrap:wrap;gap:10px;font-size:11px;">
                    <div style="display:flex;align-items:center;gap:10px;color:#64748b;">
                        <span>Menampilkan baris ke <strong id="lbl-page-range" style="color:#0f172a;font-family:monospace;">0 - 0</strong> dari <strong id="lbl-total-filtered" style="color:#2563eb;font-family:monospace;">0</strong></span>
                        <span style="color:#cbd5e1;">|</span>
                        <div style="display:flex;align-items:center;gap:6px;">
                            <span>Baris per halaman:</span>
                            <select id="select-page-size" onchange="changePageSize(this.value)" style="padding:2px 8px;border:1px solid #cbd5e1;border-radius:6px;font-size:11px;background:#ffffff;color:#1e293b;font-weight:700;cursor:pointer;">
                                <option value="50" selected>50</option>
                                <option value="100">100</option>
                                <option value="200">200</option>
                                <option value="all">Semua</option>
                            </select>
                        </div>
                    </div>

                    <div style="display:flex;align-items:center;gap:6px;">
                        <button type="button" id="btn-page-prev" onclick="prevReviewPage()" 
                                style="padding:4px 12px;background:#ffffff;border:1px solid #cbd5e1;border-radius:6px;cursor:pointer;font-weight:700;color:#475569;font-size:11px;box-shadow:0 1px 2px rgba(0,0,0,0.02);transition:all 0.15s;">
                            &larr; Prev
                        </button>
                        <span id="lbl-page-info" style="font-weight:800;color:#0f172a;padding:0 8px;font-family:monospace;font-size:11px;">
                            Hal 1 / 1
                        </span>
                        <button type="button" id="btn-page-next" onclick="nextReviewPage()" 
                                style="padding:4px 12px;background:#ffffff;border:1px solid #cbd5e1;border-radius:6px;cursor:pointer;font-weight:700;color:#475569;font-size:11px;box-shadow:0 1px 2px rgba(0,0,0,0.02);transition:all 0.15s;">
                            Next &rarr;
                        </button>
                    </div>
                </div>
            </div>

            <!-- Bottom Action Bar -->
            <form id="form-submit-import" action="<?= base_url('modules/master_parts/store_import.php') ?>" method="POST" onsubmit="return handleFormSubmit(event)">
                <input type="hidden" name="import_data_json" id="import_data_json" value="">

                <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:14px 18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                    <button type="button" onclick="cancelAndUploadNewFile()" 
                            style="padding:7px 14px;background:#f1f5f9;color:#475569;border:1px solid #cbd5e1;border-radius:8px;font-size:11px;font-weight:700;cursor:pointer;">
                        &larr; Ganti File / Upload Ulang
                    </button>

                    <div style="display:flex;align-items:center;gap:14px;">
                        <div style="text-align:right;">
                            <div style="font-size:12px;font-weight:900;color:#0f172a;">
                                <span id="lbl-action-valid-count" style="color:#059669;font-family:monospace;">0</span> Part Baru Akan Di-insert
                            </div>
                            <div id="lbl-action-skip-info" style="font-size:10px;color:#94a3b8;">
                                0 baris duplikat/invalid akan dilewati secara otomatis
                            </div>
                        </div>

                        <button type="submit" id="btn-confirm-save"
                                style="padding:9px 22px;background:#2563eb;color:#ffffff;font-size:12px;font-weight:800;border-radius:9px;border:none;cursor:pointer;box-shadow:0 1px 3px rgba(37,99,235,0.25);">
                            <span id="btn-confirm-text">Konfirmasi & Simpan ke Database</span>
                        </button>
                    </div>
                </div>
            </form>

        </div>

    </main>

<style>
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
</style>

<script>
var parsedMasterRows   = [];
var currentFilteredRows = [];
var currentFilterType   = 'all';
var currentPage         = 1;
var pageSize            = 50;

// Drag & Drop
var dropzone = document.getElementById('dropzone');

['dragenter', 'dragover'].forEach(function(evt) {
    dropzone.addEventListener(evt, function(e) {
        e.preventDefault();
        e.stopPropagation();
        dropzone.style.borderColor = '#2563eb';
        dropzone.style.background = '#eff6ff';
    }, false);
});

['dragleave', 'drop'].forEach(function(evt) {
    dropzone.addEventListener(evt, function(e) {
        e.preventDefault();
        e.stopPropagation();
        dropzone.style.borderColor = '#cbd5e1';
        dropzone.style.background = '#f8fafc';
    }, false);
});

dropzone.addEventListener('drop', function(e) {
    var dt = e.dataTransfer;
    if (dt && dt.files && dt.files.length > 0) {
        var input = document.getElementById('excel-file-input');
        input.files = dt.files;
        onFileInputChange(input);
    }
}, false);

function onFileInputChange(input) {
    if (input.files && input.files[0]) {
        var file = input.files[0];
        var ext = file.name.split('.').pop().toLowerCase();
        
        if (['xlsx', 'xls', 'csv'].indexOf(ext) === -1) {
            alert('Format file tidak didukung! Pilih file .xlsx, .xls, atau .csv');
            input.value = '';
            clearSelectedFile();
            return;
        }

        document.getElementById('label-file-name').textContent = file.name;
        document.getElementById('label-file-size').textContent = (file.size / 1024).toFixed(1) + ' KB';
        
        var iconEl = document.getElementById('badge-file-icon');
        if (ext === 'csv') {
            iconEl.style.background = '#0f172a';
            iconEl.textContent = 'CSV';
        } else {
            iconEl.style.background = '#059669';
            iconEl.textContent = 'XLS';
        }

        document.getElementById('dropzone-idle-content').style.display = 'none';
        document.getElementById('dropzone-selected-content').style.display = 'block';

        // Enable trigger button
        var btn = document.getElementById('btn-parse-trigger');
        btn.disabled = false;
        btn.style.background = '#2563eb';
        btn.style.color = '#ffffff';
        btn.style.cursor = 'pointer';
        btn.style.boxShadow = '0 1px 3px rgba(37,99,235,0.25)';
    }
}

function clearSelectedFile() {
    document.getElementById('excel-file-input').value = '';
    document.getElementById('dropzone-selected-content').style.display = 'none';
    document.getElementById('dropzone-idle-content').style.display = 'flex';

    var btn = document.getElementById('btn-parse-trigger');
    btn.disabled = true;
    btn.style.background = '#cbd5e1';
    btn.style.color = '#64748b';
    btn.style.cursor = 'not-allowed';
    btn.style.boxShadow = 'none';
}

function submitForPreview() {
    var fileInput = document.getElementById('excel-file-input');
    if (!fileInput.files || fileInput.files.length === 0) {
        alert('Silakan pilih file Excel / CSV terlebih dahulu!');
        return;
    }

    var formData = new FormData();
    formData.append('excel_file', fileInput.files[0]);

    // Stepper to step 2
    document.getElementById('step-pill-2').style.background = '#eff6ff';
    document.getElementById('step-pill-2').style.borderColor = '#bfdbfe';
    document.getElementById('step-pill-2').style.color = '#1e3a8a';
    document.getElementById('step-num-2').style.background = '#2563eb';
    document.getElementById('step-num-2').style.color = '#ffffff';

    document.getElementById('section-upload').style.display = 'none';
    document.getElementById('section-loading').style.display = 'block';
    document.getElementById('section-review').style.display = 'none';

    fetch('<?= base_url("modules/master_parts/preview_import.php") ?>', {
        method: 'POST',
        body: formData
    })
    .then(function(res) {
        if (!res.ok) {
            return res.text().then(function(t) {
                var preview = t ? t.substring(0, 200).replace(/<[^>]*>?/gm, '').trim() : res.statusText;
                throw new Error('Server error (' + res.status + '): ' + preview);
            });
        }
        return res.json();
    })
    .then(function(data) {
        document.getElementById('section-loading').style.display = 'none';
        if (!data.success) {
            alert('Gagal memproses file: ' + data.message);
            document.getElementById('section-upload').style.display = 'flex';
            return;
        }

        renderParsedData(data);
    })
    .catch(function(err) {
        document.getElementById('section-loading').style.display = 'none';
        document.getElementById('section-upload').style.display = 'flex';
        alert('Terjadi kesalahan saat memvalidasi file:\n' + (err.message || 'Koneksi terputus'));
        console.error(err);
    });
}

function renderParsedData(data) {
    parsedMasterRows = data.rows || [];

    // Stepper to step 3
    document.getElementById('step-pill-3').style.background = '#eff6ff';
    document.getElementById('step-pill-3').style.borderColor = '#bfdbfe';
    document.getElementById('step-pill-3').style.color = '#1e3a8a';
    document.getElementById('step-num-3').style.background = '#2563eb';
    document.getElementById('step-num-3').style.color = '#ffffff';

    // KPI Cards
    document.getElementById('kpi-total').textContent = data.total_rows;
    document.getElementById('kpi-filename').textContent = data.filename;
    document.getElementById('kpi-valid').textContent = data.total_valid;

    var fileDupCount = 0;
    var dbDupCount = 0;
    parsedMasterRows.forEach(function(r) {
        if (r.status === 'duplicate_file') fileDupCount++;
        if (r.status === 'duplicate_db') dbDupCount++;
    });
    document.getElementById('kpi-dup-db').textContent = dbDupCount;
    document.getElementById('kpi-dup-file').textContent = fileDupCount;
    document.getElementById('kpi-new-models').textContent = data.total_new_models;

    // Filter Tabs Count
    document.getElementById('count-pill-all').textContent = data.total_rows;
    document.getElementById('count-pill-valid').textContent = data.total_valid;
    document.getElementById('count-pill-dup').textContent = (dbDupCount + fileDupCount);
    document.getElementById('count-pill-invalid').textContent = data.total_invalid;

    // Action Bar Summary
    document.getElementById('lbl-action-valid-count').textContent = data.total_valid;
    var skippedTotal = (dbDupCount + fileDupCount + data.total_invalid);
    document.getElementById('lbl-action-skip-info').textContent = skippedTotal + ' baris duplikat/invalid akan dilewati secara otomatis';

    // Submit button state
    var btnSave = document.getElementById('btn-confirm-save');
    var btnSaveText = document.getElementById('btn-confirm-text');
    if (data.total_valid > 0) {
        btnSave.disabled = false;
        btnSave.style.background = '#2563eb';
        btnSave.style.color = '#ffffff';
        btnSave.style.cursor = 'pointer';
        btnSaveText.textContent = 'Simpan ' + data.total_valid + ' Part ke Database';
    } else {
        btnSave.disabled = true;
        btnSave.style.background = '#cbd5e1';
        btnSave.style.color = '#64748b';
        btnSave.style.cursor = 'not-allowed';
        btnSaveText.textContent = 'Tidak Ada Data Baru (0 Part)';
    }

    var validRows = parsedMasterRows.filter(function(r) { return r.is_valid; });
    document.getElementById('import_data_json').value = JSON.stringify(validRows);

    changeReviewFilter('all');
    document.getElementById('section-review').style.display = 'flex';
}

function changeReviewFilter(filterKey) {
    currentFilterType = filterKey;

    var keys = ['all', 'valid', 'dup', 'invalid'];
    keys.forEach(function(k) {
        var el = document.getElementById('tab-filter-' + k);
        if (k === filterKey) {
            el.style.background = '#0f172a';
            el.style.color = '#ffffff';
            el.style.fontWeight = '800';
        } else {
            el.style.background = 'transparent';
            el.style.color = '#64748b';
            el.style.fontWeight = '700';
        }
    });

    filterReviewData();
}

function filterReviewData() {
    var searchVal = (document.getElementById('review-search-box').value || '').toLowerCase().trim();

    currentFilteredRows = parsedMasterRows.filter(function(row) {
        if (currentFilterType === 'valid' && !row.is_valid) return false;
        if (currentFilterType === 'dup' && row.status !== 'duplicate_db' && row.status !== 'duplicate_file') return false;
        if (currentFilterType === 'invalid' && row.status !== 'invalid') return false;

        if (searchVal !== '') {
            var mCode  = (row.part_code || '').toLowerCase().indexOf(searchVal) !== -1;
            var mName  = (row.part_name || '').toLowerCase().indexOf(searchVal) !== -1;
            var mModel = (row.model || '').toLowerCase().indexOf(searchVal) !== -1;
            if (!mCode && !mName && !mModel) return false;
        }

        return true;
    });

    currentPage = 1;
    renderPaginationView();
}

function changePageSize(val) {
    pageSize = (val === 'all') ? 'all' : parseInt(val, 10);
    currentPage = 1;
    renderPaginationView();
}

function prevReviewPage() {
    if (currentPage > 1) {
        currentPage--;
        renderPaginationView();
    }
}

function nextReviewPage() {
    var totalPages = (pageSize === 'all') ? 1 : Math.ceil(currentFilteredRows.length / pageSize);
    if (currentPage < totalPages) {
        currentPage++;
        renderPaginationView();
    }
}

function renderPaginationView() {
    var total = currentFilteredRows.length;
    var totalPages = (pageSize === 'all' || total === 0) ? 1 : Math.ceil(total / pageSize);

    if (currentPage > totalPages) currentPage = totalPages;
    if (currentPage < 1) currentPage = 1;

    var startIdx = (pageSize === 'all') ? 0 : (currentPage - 1) * pageSize;
    var endIdx   = (pageSize === 'all') ? total : Math.min(startIdx + pageSize, total);
    var pageRows = (total === 0) ? [] : currentFilteredRows.slice(startIdx, endIdx);

    document.getElementById('lbl-visible-count').textContent = total;
    document.getElementById('lbl-total-filtered').textContent = total;
    document.getElementById('lbl-page-range').textContent = (total === 0) ? '0 - 0' : ((startIdx + 1) + ' - ' + endIdx);
    document.getElementById('lbl-page-info').textContent = 'Hal ' + currentPage + ' / ' + totalPages;

    var btnPrev = document.getElementById('btn-page-prev');
    var btnNext = document.getElementById('btn-page-next');
    if (btnPrev) {
        btnPrev.disabled = (currentPage <= 1);
        btnPrev.style.opacity = (currentPage <= 1) ? '0.4' : '1';
        btnPrev.style.cursor = (currentPage <= 1) ? 'not-allowed' : 'pointer';
    }
    if (btnNext) {
        btnNext.disabled = (currentPage >= totalPages);
        btnNext.style.opacity = (currentPage >= totalPages) ? '0.4' : '1';
        btnNext.style.cursor = (currentPage >= totalPages) ? 'not-allowed' : 'pointer';
    }

    renderTableRows(pageRows, startIdx);
}

function renderTableRows(rows, offset) {
    offset = offset || 0;
    var tbody = document.getElementById('table-review-body');
    tbody.innerHTML = '';

    if (!rows || rows.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="padding:30px;text-align:center;color:#94a3b8;font-size:12px;">Tidak ada baris yang sesuai dengan filter pencarian.</td></tr>';
        return;
    }

    var html = '';
    rows.forEach(function(r, idx) {
        var trBg = '#ffffff';
        if (r.status === 'valid') {
            trBg = '#ffffff';
        } else if (r.status === 'duplicate_db' || r.status === 'duplicate_file') {
            trBg = '#fffbeb';
        } else {
            trBg = '#fff1f2';
        }

        // Model Badge
        var modelContent = '<span style="color:#94a3b8;font-style:italic;">Tanpa Model</span>';
        if (r.model) {
            if (r.model_status === 'new') {
                modelContent = '<div style="font-weight:900;color:#0f172a;">' + escapeHtml(r.model) + '</div>' +
                               '<span style="display:inline-block;font-size:9px;font-weight:800;color:#4338ca;background:#eef2ff;border:1px solid #c7d2fe;padding:1px 6px;border-radius:4px;margin-top:2px;">+ Model Baru</span>';
            } else {
                modelContent = '<div style="font-weight:900;color:#0f172a;">' + escapeHtml(r.model) + '</div>' +
                               '<span style="display:inline-block;font-size:9px;font-weight:700;color:#475569;background:#f1f5f9;border:1px solid #e2e8f0;padding:1px 6px;border-radius:4px;margin-top:2px;">Model Terdaftar</span>';
            }
        }

        // Status Badge
        var statusBadge = '';
        if (r.status === 'valid') {
            statusBadge = '<span style="display:inline-block;padding:2px 8px;border-radius:6px;font-size:10px;font-weight:800;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;">✓ Siap Simpan</span>';
        } else if (r.status === 'duplicate_db') {
            statusBadge = '<span style="display:inline-block;padding:2px 8px;border-radius:6px;font-size:10px;font-weight:800;background:#fef3c7;color:#92400e;border:1px solid #fde68a;">⚠️ Duplikat di DB</span>';
        } else if (r.status === 'duplicate_file') {
            statusBadge = '<span style="display:inline-block;padding:2px 8px;border-radius:6px;font-size:10px;font-weight:800;background:#ffedd5;color:#9a3412;border:1px solid #fed7aa;">⚠️ Kembar di File</span>';
        } else {
            statusBadge = '<span style="display:inline-block;padding:2px 8px;border-radius:6px;font-size:10px;font-weight:800;background:#ffe4e6;color:#9f1239;border:1px solid #fecdd3;">✕ Data Kurang</span>';
        }

        html += '<tr style="background:' + trBg + ';border-bottom:1px solid #f1f5f9;">';
        html += '<td style="padding:8px 14px;text-align:center;color:#94a3b8;font-family:monospace;font-weight:700;">' + (offset + idx + 1) + '</td>';
        html += '<td style="padding:8px 14px;">' + modelContent + '</td>';
        html += '<td style="padding:8px 14px;font-family:monospace;font-weight:900;color:#1d4ed8;font-size:12px;">' + escapeHtml(r.part_code || '-') + '</td>';
        html += '<td style="padding:8px 14px;font-weight:700;color:#1e293b;">' + escapeHtml(r.part_name || '-') + '</td>';
        html += '<td style="padding:8px 14px;"><span style="display:inline-block;padding:2px 6px;border-radius:5px;font-size:10px;font-weight:800;background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;">G-II (Normal)</span></td>';
        html += '<td style="padding:8px 14px;">' + statusBadge + '<div style="font-size:10px;color:#94a3b8;margin-top:2px;">' + escapeHtml(r.status_desc) + '</div></td>';
        html += '</tr>';
    });

    tbody.innerHTML = html;
}

function cancelAndUploadNewFile() {
    document.getElementById('section-review').style.display = 'none';
    document.getElementById('section-upload').style.display = 'flex';

    // Reset Stepper
    document.getElementById('step-pill-2').style.background = '#f8fafc';
    document.getElementById('step-pill-2').style.borderColor = '#e2e8f0';
    document.getElementById('step-pill-2').style.color = '#64748b';
    document.getElementById('step-num-2').style.background = '#cbd5e1';
    document.getElementById('step-num-2').style.color = '#475569';

    document.getElementById('step-pill-3').style.background = '#f8fafc';
    document.getElementById('step-pill-3').style.borderColor = '#e2e8f0';
    document.getElementById('step-pill-3').style.color = '#64748b';
    document.getElementById('step-num-3').style.background = '#cbd5e1';
    document.getElementById('step-num-3').style.color = '#475569';
}

function handleFormSubmit(e) {
    var rawJson = document.getElementById('import_data_json').value;
    var rows = [];
    try { rows = JSON.parse(rawJson); } catch(err) { rows = []; }

    if (!rows || rows.length === 0) {
        alert('Tidak ada baris data valid yang dapat disimpan ke database!');
        e.preventDefault();
        return false;
    }

    if (!confirm('Simpan ' + rows.length + ' Part baru ke Master Data?')) {
        e.preventDefault();
        return false;
    }

    var btn = document.getElementById('btn-confirm-save');
    btn.disabled = true;
    btn.innerHTML = 'Menyimpan data ke database...';
    return true;
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
</script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
