<?php
/**
 * Isolated Component: Multi-Document Workbench Viewer Panel
 * Supports: 2D (PDF), 3D (STP), PI, Q-Point, PAC, Standar Packing with Auto Versioning
 */
require_once __DIR__ . '/../../../config/document_helper.php';

$currPartCode = $session['part_code'] ?? '';
$initialDocs = $currPartCode ? get_part_documents_summary($currPartCode) : [];

// Fetch AQL Standard Document (Global OQC Standard ID = 1)
$aqlDoc = null;
try {
    $stmtAql = $pdo->prepare("SELECT id, title, file_path, file_name_original FROM standard_oqc WHERE id = 1 LIMIT 1");
    $stmtAql->execute();
    $aqlDoc = $stmtAql->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Graceful fallback if table is empty or error occurs
}
$aqlPdfUrl = ($aqlDoc && !empty($aqlDoc['file_path'])) ? (base_url($aqlDoc['file_path']) . '#toolbar=0') : '';
?>

<style>
/* Enforce strict single-line horizontal scroll for Document Tabs */
#doc-tabs-scroll-container {
    display: flex !important;
    flex-wrap: nowrap !important;
    white-space: nowrap !important;
    overflow-x: auto !important;
    overflow-y: hidden !important;
}
#doc-tabs-scroll-container > button {
    flex-shrink: 0 !important;
    white-space: nowrap !important;
}
#doc-tabs-scroll-container span {
    white-space: nowrap !important;
}
</style>

<!-- Left Main Panel: Unified Multi-Document Viewer (Flex-1) -->
<div id="drawing-viewer-panel" style="flex: 1; min-width: 0; height: 100%; display: flex; flex-direction: column; overflow: hidden;" class="bg-white rounded-2xl border border-slate-200/80 shadow-xs">
    
    <!-- Drawing & Document Header Toolbar -->
    <div class="px-3 py-1.5 bg-slate-50/90 border-b border-slate-200 flex items-center justify-between flex-shrink-0 gap-2">
        
        <!-- Tab Switcher (Scrollable horizontally with mouse wheel & drag) -->
        <div id="doc-tabs-scroll-container" class="flex items-center space-x-1.5 overflow-x-auto slim-scrollbar py-0.5" style="flex: 1; min-width: 0; scroll-behavior: smooth;">
            
            <!-- 1. Tab 2D PDF (Signature: Royal Blue) -->
            <button type="button" id="tab-btn-2d" onclick="switchDocumentTab('2d')" style="flex-shrink: 0;" class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all border shadow-xs flex items-center whitespace-nowrap cursor-pointer doc-tab-active-2d">
                <svg class="w-3.5 h-3.5 mr-1.5 flex-shrink-0 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                </svg>
                <span class="whitespace-nowrap">Gambar 2D</span>
            </button>

            <!-- 2. Tab 3D STP (Signature: Cyan / Sky Blue) -->
            <button type="button" id="tab-btn-3d" onclick="switchDocumentTab('3d')" style="flex-shrink: 0;" class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all bg-slate-100 text-slate-700 border border-slate-300 shadow-2xs flex items-center whitespace-nowrap cursor-pointer doc-tab-hover-3d">
                <svg class="w-3.5 h-3.5 mr-1.5 flex-shrink-0 doc-icon-3d" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                </svg>
                <span class="whitespace-nowrap">Tampilan 3D</span>
            </button>

            <span class="text-slate-300 mx-0.5 select-none flex-shrink-0">|</span>

            <!-- Tab AQL (Signature: Purple / Violet - Standard OQC ID 1) -->
            <button type="button" id="tab-btn-aql" onclick="switchDocumentTab('aql')" style="flex-shrink: 0;" class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all bg-slate-100 text-slate-700 border border-slate-300 shadow-2xs flex items-center whitespace-nowrap cursor-pointer relative doc-tab-hover-aql">
                <svg class="w-3.5 h-3.5 mr-1.5 flex-shrink-0 doc-icon-aql" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path>
                </svg>
                <span class="whitespace-nowrap">AQL</span>
                <span id="tab-dot-aql" class="w-2 h-2 rounded-full ml-1.5 <?= $aqlDoc ? '' : 'hidden' ?> shadow-2xs flex-shrink-0" style="background-color: #7c3aed;" title="Dokumen AQL Tersedia"></span>
            </button>

            <span class="text-slate-300 mx-0.5 select-none flex-shrink-0">|</span>

            <!-- 3. Tab PI (Signature: Indigo) -->
            <button type="button" id="tab-btn-pi" onclick="switchDocumentTab('pi')" style="flex-shrink: 0;" class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all bg-slate-100 text-slate-700 border border-slate-300 shadow-2xs flex items-center whitespace-nowrap cursor-pointer relative doc-tab-hover-pi">
                <svg class="w-3.5 h-3.5 mr-1.5 flex-shrink-0 doc-icon-pi" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                <span class="whitespace-nowrap">PI</span>
                <span id="tab-dot-pi" class="w-2 h-2 rounded-full ml-1.5 hidden shadow-2xs flex-shrink-0" style="background-color: #4f46e5;" title="File tersedia"></span>
            </button>

            <!-- 4. Tab Q-Point (Signature: Amber / Gold QC Warning) -->
            <button type="button" id="tab-btn-qpoint" onclick="switchDocumentTab('qpoint')" style="flex-shrink: 0;" class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all bg-slate-100 text-slate-700 border border-slate-300 shadow-2xs flex items-center whitespace-nowrap cursor-pointer relative doc-tab-hover-qpoint">
                <svg class="w-3.5 h-3.5 mr-1.5 flex-shrink-0 doc-icon-qpoint" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                <span class="whitespace-nowrap">Q-Point</span>
                <span id="tab-dot-qpoint" class="w-2 h-2 rounded-full ml-1.5 hidden shadow-2xs flex-shrink-0" style="background-color: #d97706;" title="File tersedia"></span>
            </button>

            <!-- 5. Tab PAC (Signature: Emerald Green Approval) -->
            <button type="button" id="tab-btn-pac" onclick="switchDocumentTab('pac')" style="flex-shrink: 0;" class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all bg-slate-100 text-slate-700 border border-slate-300 shadow-2xs flex items-center whitespace-nowrap cursor-pointer relative doc-tab-hover-pac">
                <svg class="w-3.5 h-3.5 mr-1.5 flex-shrink-0 doc-icon-pac" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
                <span class="whitespace-nowrap">PAC</span>
                <span id="tab-dot-pac" class="w-2 h-2 rounded-full ml-1.5 hidden shadow-2xs flex-shrink-0" style="background-color: #059669;" title="File tersedia"></span>
            </button>

            <!-- 6. Tab Standar Packing (Signature: Warm Orange / Bronze Packaging) -->
            <button type="button" id="tab-btn-standar_packing" onclick="switchDocumentTab('standar_packing')" style="flex-shrink: 0;" class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all bg-slate-100 text-slate-700 border border-slate-300 shadow-2xs flex items-center whitespace-nowrap cursor-pointer relative doc-tab-hover-standar_packing">
                <svg class="w-3.5 h-3.5 mr-1.5 flex-shrink-0 doc-icon-standar_packing" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>
                <span class="whitespace-nowrap">Standar Packing</span>
                <span id="tab-dot-standar_packing" class="w-2 h-2 rounded-full ml-1.5 hidden shadow-2xs flex-shrink-0" style="background-color: #c2410c;" title="File tersedia"></span>
            </button>

            <!-- 7. Tab Monitoring Model (Signature: Cyan / Sky Blue) -->
            <button type="button" id="tab-btn-mi" onclick="switchDocumentTab('mi')" style="flex-shrink: 0;" class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all bg-slate-100 text-slate-700 border border-slate-300 shadow-2xs flex items-center whitespace-nowrap cursor-pointer relative doc-tab-hover-mi">
                <svg class="w-3.5 h-3.5 mr-1.5 flex-shrink-0 doc-icon-mi" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
                <span class="whitespace-nowrap">Monitoring Model</span>
                <span id="tab-dot-mi" class="w-2 h-2 rounded-full ml-1.5 hidden shadow-2xs flex-shrink-0" style="background-color: #0891b2;" title="File tersedia"></span>
            </button>

        </div>

        <!-- Right Side: Version Selector & Zoom Controls -->
        <div class="flex items-center space-x-1.5 flex-shrink-0">
            
            <!-- Quick Link: Upload / Kelola Berkas Dokumen (Icon only, no text, no +) -->
            <a id="btn-toolbar-upload-doc" 
               href="<?= base_url('modules/master_drawings/upload.php?part_code=' . urlencode($currPartCode)) ?>" 
               target="_blank" 
               class="p-1.5 bg-blue-50 hover:bg-blue-100 text-blue-600 border border-blue-200 hover:border-blue-300 rounded-lg transition-all shadow-2xs flex items-center justify-center cursor-pointer flex-shrink-0" 
               title="Kelola & Upload Berkas Dokumen untuk part ini">
                <svg class="w-4 h-4 text-blue-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path>
                </svg>
            </a>

            <!-- Version Selector Dropdown (Dynamic, shown when viewing PI, Q-Point, PAC, Standar Packing, or MI) -->
            <div id="doc-version-selector-container" class="hidden items-center space-x-1.5 bg-slate-100 border border-slate-300 rounded-lg px-2 py-0.5">
                <label for="doc-version-select" class="text-[10px] font-extrabold text-slate-500 uppercase tracking-wider">Versi:</label>
                <select id="doc-version-select" onchange="onDocumentVersionChange(this.value)" class="text-xs font-bold text-slate-800 bg-transparent border-0 outline-none cursor-pointer pr-1 py-0.5">
                    <option value="">-</option>
                </select>
            </div>

            <!-- Zoom & Viewport Controls Toolbar -->
            <div class="flex items-center space-x-1 text-slate-600 bg-slate-100 rounded-lg p-0.5 border border-slate-200">
                <button type="button" onclick="adjustZoom(0.1)" class="p-1 hover:bg-white rounded-md text-slate-600 hover:text-slate-900 transition-colors" title="Perbesar (Zoom In)">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v6m3-3H7"></path></svg>
                </button>
                <button type="button" onclick="adjustZoom(-0.1)" class="p-1 hover:bg-white rounded-md text-slate-600 hover:text-slate-900 transition-colors" title="Perkecil (Zoom Out)">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM13 10H7"></path></svg>
                </button>
                <button type="button" id="btn-fullscreen-toggle" onclick="toggleDrawingFullscreen()" class="p-1 hover:bg-white rounded-md text-slate-600 hover:text-slate-900 transition-colors" title="Layar Penuh (Fullscreen)">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 4l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"></path></svg>
                </button>
                <button type="button" onclick="reloadDocViewer()" class="p-1 hover:bg-white rounded-md text-slate-600 hover:text-blue-600 transition-colors" title="Cek Berkas Terbaru (Tanpa Refresh Halaman)">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                </button>
            </div>

        </div>

    </div>

    <!-- Viewport Container for 2D, 3D & New Documents -->
    <div style="flex: 1; min-height: 0; position: relative; overflow: hidden; background-color: #ffffff;">
        
        <!-- 1. 2D PDF Viewport -->
        <div id="viewport-2d" class="w-full h-full flex items-center justify-center overflow-auto p-4 bg-slate-50/60" style="background-image: radial-gradient(rgba(148, 163, 184, 0.15) 1px, transparent 1px); background-size: 20px 20px;">
            <iframe id="pdf-frame" src="<?= ($session && !empty($session['drawing_2d_path'])) ? (base_url($session['drawing_2d_path']) . '#toolbar=0') : '' ?>" class="w-full h-full border-0 rounded-xl transition-transform duration-200" style="display: <?= ($session && !empty($session['drawing_2d_path'])) ? 'block' : 'none' ?>;"></iframe>
            <div id="drawing-placeholder-box" class="w-full h-full flex flex-col items-center justify-center text-center p-6 select-none relative" style="display: <?= ($session && !empty($session['drawing_2d_path'])) ? 'none' : 'flex' ?>;">
                
                <!-- Modern Clean Light Card for 2D -->
                <div class="relative max-w-sm w-full bg-white border border-slate-200/90 rounded-2xl p-6 shadow-sm flex flex-col items-center">
                    
                    <!-- Icon with Subtle Accent Ring -->
                    <div class="w-12 h-12 rounded-2xl bg-blue-50 text-blue-600 border border-blue-200/70 flex items-center justify-center mb-3 shadow-2xs">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414A1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                    </div>

                    <!-- Type Tag Badge -->
                    <div class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase tracking-wider bg-blue-50 text-blue-700 border border-blue-200/80 mb-2">
                        <span class="w-1.5 h-1.5 rounded-full bg-blue-600"></span>
                        <span>Gambar Kerja 2D (PDF)</span>
                    </div>

                    <!-- Title & Subtitle -->
                    <h3 class="text-sm font-extrabold text-slate-900 tracking-tight mb-1">
                        Dokumen Belum Tersedia
                    </h3>
                    <p class="text-xs text-slate-500 leading-relaxed mb-3">
                        File technical drawing untuk part <span class="text-blue-700 font-bold font-mono px-1 py-0.5 rounded bg-blue-50 border border-blue-200" id="placeholder-part-code-text"><?= $session ? htmlspecialchars($session['part_code']) : '-' ?></span> belum diunggah.
                    </p>

                    <!-- Target Directory Pill -->
                    <div class="w-full bg-slate-50 border border-slate-200/80 rounded-xl px-3 py-1.5 flex items-center justify-between text-left mb-4">
                        <span class="font-mono text-[11px] text-slate-500 truncate">
                            uploads/parts/<strong id="placeholder-part-folder" class="text-slate-700"><?= $session ? htmlspecialchars($session['part_code']) : '-' ?></strong>/
                        </span>
                        <span class="text-[9px] font-extrabold uppercase text-slate-500 bg-white px-2 py-0.5 rounded border border-slate-200 flex-shrink-0">
                            Folder
                        </span>
                    </div>

                    <!-- Modern Clean Upload Button -->
                    <a id="btn-upload-redirect-2d" href="<?= base_url('modules/master_drawings/upload.php?part_code=' . urlencode($currPartCode)) ?>" target="_blank" style="background-color: #2563eb !important; color: #ffffff !important;" class="w-full py-2 px-4 rounded-xl text-xs font-bold text-white shadow-xs inline-flex items-center justify-center gap-2 transition-all hover:opacity-90">
                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                        <span>+ Upload Berkas Gambar 2D</span>
                    </a>

                </div>

            </div>
        </div>

        <!-- 2. 3D STP Model Viewport -->
        <div id="viewport-3d" class="hidden w-full h-full relative p-4 bg-slate-50/60" style="background-image: radial-gradient(rgba(148, 163, 184, 0.15) 1px, transparent 1px); background-size: 20px 20px;">
            <div id="cad-3d-viewport" class="w-full h-full" data-stp-url="<?= ($session && !empty($session['drawing_3d_path'])) ? htmlspecialchars(base_url($session['drawing_3d_path'])) : '' ?>" style="display: <?= ($session && !empty($session['drawing_3d_path'])) ? 'block' : 'none' ?>;"></div>
            <div id="cad-3d-placeholder-box" class="w-full h-full flex flex-col items-center justify-center text-center p-6 select-none relative" style="display: <?= ($session && !empty($session['drawing_3d_path'])) ? 'none' : 'flex' ?>;">
                
                <!-- Modern Clean Light Card for 3D -->
                <div class="relative max-w-sm w-full bg-white border border-slate-200/90 rounded-2xl p-6 shadow-sm flex flex-col items-center">
                    
                    <!-- Icon with Subtle Cyan Ring -->
                    <div class="w-12 h-12 rounded-2xl bg-cyan-50 text-cyan-700 border border-cyan-200/70 flex items-center justify-center mb-3 shadow-2xs">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                        </svg>
                    </div>

                    <!-- Type Tag Badge -->
                    <div class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase tracking-wider bg-cyan-50 text-cyan-800 border border-cyan-200/80 mb-2">
                        <span class="w-1.5 h-1.5 rounded-full bg-cyan-600"></span>
                        <span>Model CAD 3D (.STP / .STEP)</span>
                    </div>

                    <!-- Title & Subtitle -->
                    <h3 class="text-sm font-extrabold text-slate-900 tracking-tight mb-1">
                        Model 3D Belum Tersedia
                    </h3>
                    <p class="text-xs text-slate-500 leading-relaxed mb-3">
                        File solid CAD 3D untuk part <span class="text-cyan-800 font-bold font-mono px-1 py-0.5 rounded bg-cyan-50 border border-cyan-200" id="placeholder-3d-part-code-text"><?= $session ? htmlspecialchars($session['part_code']) : '-' ?></span> belum diunggah.
                    </p>

                    <!-- Target Directory Pill -->
                    <div class="w-full bg-slate-50 border border-slate-200/80 rounded-xl px-3 py-1.5 flex items-center justify-between text-left mb-4">
                        <span class="font-mono text-[11px] text-slate-500 truncate">
                            uploads/parts/<strong id="placeholder-3d-part-folder" class="text-slate-700"><?= $session ? htmlspecialchars($session['part_code']) : '-' ?></strong>/
                        </span>
                        <span class="text-[9px] font-extrabold uppercase text-slate-500 bg-white px-2 py-0.5 rounded border border-slate-200 flex-shrink-0">
                            Folder
                        </span>
                    </div>

                    <!-- Modern Clean Upload Button -->
                    <a id="btn-upload-redirect-3d" href="<?= base_url('modules/master_drawings/upload.php?part_code=' . urlencode($currPartCode)) ?>" target="_blank" style="background-color: #0891b2 !important; color: #ffffff !important;" class="w-full py-2 px-4 rounded-xl text-xs font-bold text-white shadow-xs inline-flex items-center justify-center gap-2 transition-all hover:opacity-90">
                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                        <span>+ Upload Berkas Model 3D</span>
                    </a>

                </div>

            </div>
        </div>

        <!-- 3. Dynamic Multi-Document Viewport (PI, Q-Point, PAC, Standar Packing, Monitoring Model) -->
        <div id="viewport-custom-doc" class="hidden w-full h-full bg-white relative flex flex-col overflow-hidden">
            <!-- Loading Indicator Overlay -->
            <div id="custom-doc-loading" class="absolute inset-0 bg-white/85 backdrop-blur-2xs z-30 flex flex-col items-center justify-center hidden">
                <div class="inline-block animate-spin rounded-full h-8 w-8 border-3 border-blue-600 border-t-transparent mb-2"></div>
                <span class="text-xs font-bold text-slate-700" id="custom-doc-loading-text">Memuat dokumen...</span>
            </div>

            <!-- Document Iframe Viewer (Fast & Isolated) -->
            <iframe id="custom-doc-frame" src="about:blank" class="w-full h-full border-0 transition-transform duration-200" style="display: none;"></iframe>

            <!-- Empty / Not Found Placeholder -->
            <div id="custom-doc-placeholder" class="w-full h-full bg-slate-50/60 p-6 flex flex-col items-center justify-center text-center" style="background-image: radial-gradient(rgba(148, 163, 184, 0.15) 1px, transparent 1px); background-size: 20px 20px;">
                <div class="relative max-w-sm w-full bg-white border border-slate-200/90 rounded-2xl p-6 shadow-sm flex flex-col items-center">
                    
                    <div class="w-12 h-12 rounded-2xl bg-slate-100 text-slate-500 border border-slate-200 flex items-center justify-center mb-3 shadow-2xs">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414A1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    </div>
                    
                    <h4 class="text-sm font-extrabold text-slate-900 tracking-tight mb-1" id="custom-doc-placeholder-title">DOKUMEN BELUM TERSEDIA</h4>
                    <p class="text-xs text-slate-500 leading-relaxed mb-3" id="custom-doc-placeholder-desc">File dokumen belum diunggah untuk Part Code ini.</p>
                    
                    <div class="w-full bg-slate-50 border border-slate-200/80 rounded-xl px-3 py-1.5 flex items-center justify-between text-left mb-4">
                        <span class="font-mono text-[11px] text-slate-500 truncate">
                            uploads/parts/<strong id="custom-doc-placeholder-folder" class="text-slate-700"><?= htmlspecialchars($currPartCode ?: '-') ?></strong>/
                        </span>
                        <span class="text-[9px] font-extrabold uppercase text-slate-500 bg-white px-2 py-0.5 rounded border border-slate-200 flex-shrink-0">
                            Folder
                        </span>
                    </div>

                    <!-- Direct Upload Action Button for QC Docs -->
                    <a id="btn-upload-redirect-custom" href="<?= base_url('modules/master_drawings/upload.php?part_code=' . urlencode($currPartCode)) ?>" target="_blank" style="background-color: #2563eb !important; color: #ffffff !important;" class="w-full py-2 px-4 rounded-xl text-xs font-bold text-white shadow-xs inline-flex items-center justify-center gap-2 transition-all hover:opacity-90">
                        <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                        <span id="btn-upload-redirect-text">+ Upload Berkas Dokumen</span>
                    </a>

                </div>
            </div>
        </div>

        <!-- 4. Dedicated AQL Standard Document Viewport (table standard_oqc id = 1) -->
        <div id="viewport-aql" class="hidden w-full h-full bg-white relative flex flex-col overflow-hidden">
            <?php if (!empty($aqlPdfUrl)): ?>
                <iframe id="aql-doc-frame" src="<?= htmlspecialchars($aqlPdfUrl) ?>" class="w-full h-full border-0 transition-transform duration-200"></iframe>
            <?php else: ?>
                <div id="aql-placeholder-box" class="w-full h-full bg-slate-50/60 p-6 flex flex-col items-center justify-center text-center" style="background-image: radial-gradient(rgba(148, 163, 184, 0.15) 1px, transparent 1px); background-size: 20px 20px;">
                    <div class="relative max-w-sm w-full bg-white border border-slate-200/90 rounded-2xl p-6 shadow-sm flex flex-col items-center">
                        <div class="w-12 h-12 rounded-2xl bg-purple-50 text-purple-700 border border-purple-200/70 flex items-center justify-center mb-3 shadow-2xs">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path>
                            </svg>
                        </div>
                        <div class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase tracking-wider bg-purple-50 text-purple-800 border border-purple-200/80 mb-2">
                            <span class="w-1.5 h-1.5 rounded-full bg-purple-600"></span>
                            <span>Standar OQC (AQL)</span>
                        </div>
                        <h4 class="text-sm font-extrabold text-slate-900 tracking-tight mb-1">DOKUMEN AQL BELUM TERSEDIA</h4>
                        <p class="text-xs text-slate-500 leading-relaxed mb-4">
                            Dokumen standar AQL belum diunggah di modul Standard OQC (ID 1).
                        </p>
                        <a href="<?= base_url('modules/standard_oqc/index.php') ?>" target="_blank" style="background-color: #7c3aed !important; color: #ffffff !important;" class="w-full py-2 px-4 rounded-xl text-xs font-bold text-white shadow-xs inline-flex items-center justify-center gap-2 transition-all hover:opacity-90">
                            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                            <span>Buka Modul Standard OQC</span>
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    </div>

</div>

<!-- Initial Document Metadata for Current Session -->
<script>
window.__INITIAL_PART_DOCS__ = <?= json_encode($initialDocs) ?>;

// Enable smooth horizontal scrolling with mouse wheel over document tabs
document.addEventListener('DOMContentLoaded', function() {
    var tabsContainer = document.getElementById('doc-tabs-scroll-container');
    if (tabsContainer) {
        tabsContainer.addEventListener('wheel', function(e) {
            if (e.deltaY !== 0) {
                e.preventDefault();
                tabsContainer.scrollLeft += e.deltaY;
            }
        }, { passive: false });
    }
});
</script>
