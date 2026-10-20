<?php
/**
 * Isolated Component: Multi-Document Workbench Viewer Panel
 * Supports: 2D (PDF), 3D (STP), PI, Q-Point, PAC, Standar Packing with Auto Versioning
 */
require_once __DIR__ . '/../../../config/document_helper.php';

$currPartCode = $session['part_code'] ?? '';
$initialDocs = $currPartCode ? get_part_documents_summary($currPartCode) : [];
?>

<!-- Left Main Panel: Unified Multi-Document Viewer (Flex-1) -->
<div id="drawing-viewer-panel" style="flex: 1; min-width: 0; height: 100%; display: flex; flex-direction: column; overflow: hidden;" class="bg-white rounded-2xl border border-slate-200/80 shadow-xs">
    
    <!-- Drawing & Document Header Toolbar -->
    <div class="px-3 py-1.5 bg-slate-50/90 border-b border-slate-200 flex items-center justify-between flex-shrink-0 gap-2">
        
        <!-- Tab Switcher (Scrollable if screen is narrow) -->
        <div class="flex items-center space-x-1.5 overflow-x-auto custom-scrollbar flex-shrink-0 max-w-[70vw] py-0.5">
            
            <!-- 1. Tab 2D PDF (Signature: Royal Blue) -->
            <button type="button" id="tab-btn-2d" onclick="switchDocumentTab('2d')" class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all border shadow-xs flex items-center whitespace-nowrap cursor-pointer doc-tab-active-2d">
                <svg class="w-3.5 h-3.5 mr-1.5 flex-shrink-0 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                </svg>
                <span>Gambar 2D</span>
            </button>

            <!-- 2. Tab 3D STP (Signature: Cyan / Sky Blue) -->
            <button type="button" id="tab-btn-3d" onclick="switchDocumentTab('3d')" class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all bg-slate-100 text-slate-700 border border-slate-300 shadow-2xs flex items-center whitespace-nowrap cursor-pointer doc-tab-hover-3d">
                <svg class="w-3.5 h-3.5 mr-1.5 flex-shrink-0 doc-icon-3d" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                </svg>
                <span>Tampilan 3D</span>
            </button>

            <span class="text-slate-300 mx-0.5 select-none">|</span>

            <!-- 3. Tab PI (Signature: Indigo) -->
            <button type="button" id="tab-btn-pi" onclick="switchDocumentTab('pi')" class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all bg-slate-100 text-slate-700 border border-slate-300 shadow-2xs flex items-center whitespace-nowrap cursor-pointer relative doc-tab-hover-pi">
                <svg class="w-3.5 h-3.5 mr-1.5 flex-shrink-0 doc-icon-pi" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                <span>PI</span>
                <span id="tab-dot-pi" class="w-2 h-2 rounded-full ml-1.5 hidden shadow-2xs" style="background-color: #4f46e5;" title="File tersedia"></span>
            </button>

            <!-- 4. Tab Q-Point (Signature: Amber / Gold QC Warning) -->
            <button type="button" id="tab-btn-qpoint" onclick="switchDocumentTab('qpoint')" class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all bg-slate-100 text-slate-700 border border-slate-300 shadow-2xs flex items-center whitespace-nowrap cursor-pointer relative doc-tab-hover-qpoint">
                <svg class="w-3.5 h-3.5 mr-1.5 flex-shrink-0 doc-icon-qpoint" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                <span>Q-Point</span>
                <span id="tab-dot-qpoint" class="w-2 h-2 rounded-full ml-1.5 hidden shadow-2xs" style="background-color: #d97706;" title="File tersedia"></span>
            </button>

            <!-- 5. Tab PAC (Signature: Emerald Green Approval) -->
            <button type="button" id="tab-btn-pac" onclick="switchDocumentTab('pac')" class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all bg-slate-100 text-slate-700 border border-slate-300 shadow-2xs flex items-center whitespace-nowrap cursor-pointer relative doc-tab-hover-pac">
                <svg class="w-3.5 h-3.5 mr-1.5 flex-shrink-0 doc-icon-pac" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
                <span>PAC</span>
                <span id="tab-dot-pac" class="w-2 h-2 rounded-full ml-1.5 hidden shadow-2xs" style="background-color: #059669;" title="File tersedia"></span>
            </button>

            <!-- 6. Tab Standar Packing (Signature: Warm Orange / Bronze Packaging) -->
            <button type="button" id="tab-btn-standar_packing" onclick="switchDocumentTab('standar_packing')" class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all bg-slate-100 text-slate-700 border border-slate-300 shadow-2xs flex items-center whitespace-nowrap cursor-pointer relative doc-tab-hover-standar_packing">
                <svg class="w-3.5 h-3.5 mr-1.5 flex-shrink-0 doc-icon-standar_packing" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>
                <span>Standar Packing</span>
                <span id="tab-dot-standar_packing" class="w-2 h-2 rounded-full ml-1.5 hidden shadow-2xs" style="background-color: #c2410c;" title="File tersedia"></span>
            </button>

        </div>

        <!-- Right Side: Version Selector & Zoom Controls -->
        <div class="flex items-center space-x-2 flex-shrink-0">
            
            <!-- Version Selector Dropdown (Dynamic, shown when viewing PI, Q-Point, PAC, or Standar Packing) -->
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
                <button type="button" onclick="resetZoom()" class="p-1 hover:bg-white rounded-md text-slate-600 hover:text-slate-900 transition-colors" title="Reset Zoom (100%)">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                </button>
            </div>

        </div>

    </div>

    <!-- Viewport Container for 2D, 3D & New Documents -->
    <div style="flex: 1; min-height: 0; position: relative; overflow: hidden; background-color: #020617;">
        
        <!-- 1. 2D PDF Viewport -->
        <div id="viewport-2d" class="w-full h-full flex items-center justify-center overflow-auto p-2">
            <iframe id="pdf-frame" src="<?= ($session && !empty($session['drawing_2d_path'])) ? (base_url($session['drawing_2d_path']) . '#toolbar=0') : '' ?>" class="w-full h-full border-0 rounded-lg transition-transform duration-200" style="display: <?= ($session && !empty($session['drawing_2d_path'])) ? 'block' : 'none' ?>;"></iframe>
            <div id="drawing-placeholder-box" class="w-full h-full bg-[#f8fafc] border border-dashed border-slate-300 rounded-xl p-4 flex flex-col items-center justify-center text-center relative" style="display: <?= ($session && !empty($session['drawing_2d_path'])) ? 'none' : 'flex' ?>;">
                <svg class="w-full h-full max-h-[360px] text-slate-300" viewBox="0 0 600 350" fill="none" stroke="currentColor">
                    <rect x="50" y="40" width="500" height="270" rx="4" stroke="#cbd5e1" stroke-width="2" stroke-dasharray="4 4" />
                    <rect x="150" y="80" width="300" height="180" fill="#f1f5f9" stroke="#94a3b8" stroke-width="2" />
                    <circle cx="200" cy="130" r="20" stroke="#64748b" stroke-width="2" />
                    <circle cx="200" cy="210" r="20" stroke="#64748b" stroke-width="2" />
                    <circle cx="400" cy="130" r="15" stroke="#64748b" stroke-width="2" />
                    <line x1="150" y1="80" x2="450" y2="260" stroke="#cbd5e1" stroke-width="1" />
                    <text x="300" y="170" font-family="monospace" font-size="14" font-weight="bold" fill="#334155" text-anchor="middle" id="placeholder-part-code-text"><?= $session ? htmlspecialchars($session['part_code']) : 'TECHNICAL DRAWING' ?></text>
                </svg>
                <p class="text-xs font-bold text-slate-500 mt-2">Gambar Teknis PDF belum diunggah untuk Part Code ini.</p>
            </div>
        </div>

        <!-- 2. 3D STP Model Viewport -->
        <div id="viewport-3d" class="hidden w-full h-full bg-slate-900 relative">
            <div id="cad-3d-viewport" class="w-full h-full" data-stp-url="<?= ($session && !empty($session['drawing_3d_path'])) ? htmlspecialchars(base_url($session['drawing_3d_path'])) : '' ?>" style="display: <?= ($session && !empty($session['drawing_3d_path'])) ? 'block' : 'none' ?>;"></div>
            <div id="cad-3d-placeholder-box" class="w-full h-full flex flex-col items-center justify-center text-white p-6 text-center" style="display: <?= ($session && !empty($session['drawing_3d_path'])) ? 'none' : 'flex' ?>;">
                <svg class="w-12 h-12 text-slate-600 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                </svg>
                <p class="text-xs font-bold text-slate-400">File 3D (.STP) tidak tersedia untuk Part ini.</p>
            </div>
        </div>

        <!-- 3. Dynamic Multi-Document Viewport (PI, Q-Point, PAC, Standar Packing) -->
        <div id="viewport-custom-doc" class="hidden w-full h-full bg-white relative flex flex-col overflow-hidden">
            <!-- Loading Indicator Overlay -->
            <div id="custom-doc-loading" class="absolute inset-0 bg-white/85 backdrop-blur-2xs z-30 flex flex-col items-center justify-center hidden">
                <div class="inline-block animate-spin rounded-full h-8 w-8 border-3 border-blue-600 border-t-transparent mb-2"></div>
                <span class="text-xs font-bold text-slate-700" id="custom-doc-loading-text">Memuat dokumen...</span>
            </div>

            <!-- Document Iframe Viewer (Fast & Isolated) -->
            <iframe id="custom-doc-frame" src="about:blank" class="w-full h-full border-0 transition-transform duration-200" style="display: none;"></iframe>

            <!-- Empty / Not Found Placeholder -->
            <div id="custom-doc-placeholder" class="w-full h-full bg-[#f8fafc] border border-dashed border-slate-300 rounded-xl p-6 flex flex-col items-center justify-center text-center">
                <div class="w-12 h-12 rounded-full bg-slate-100 text-slate-400 flex items-center justify-center mb-3">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                </div>
                <h4 class="text-xs font-extrabold text-slate-800 uppercase tracking-wider mb-1" id="custom-doc-placeholder-title">DOKUMEN BELUM TERSEDIA</h4>
                <p class="text-xs text-slate-500 max-w-sm mb-3" id="custom-doc-placeholder-desc">File dokumen belum diunggah untuk Part Code ini.</p>
                <div class="inline-flex items-center text-[11px] font-mono font-bold text-slate-400 bg-slate-100 px-3 py-1 rounded-md border border-slate-200">
                    uploads/documents/<span id="custom-doc-placeholder-folder">-</span>/
                </div>
            </div>
        </div>

    </div>

</div>

<!-- Initial Document Metadata for Current Session -->
<script>
window.__INITIAL_PART_DOCS__ = <?= json_encode($initialDocs) ?>;
</script>
