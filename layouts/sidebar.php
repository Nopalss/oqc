<?php
$currentUri = $_SERVER['REQUEST_URI'] ?? '';

// Helper active state link
function nav_active($keyword, $currentUri) {
    if (strpos($currentUri, $keyword) !== false) {
        return 'bg-blue-600 text-white font-semibold shadow-md shadow-blue-600/20';
    }
    return 'text-slate-300 hover:bg-slate-800 hover:text-white';
}
?>
<!-- Sidebar Navigation (Matching Client Mockup Visuals & Collapsible) -->
<aside id="sidebar" class="bg-slate-900 text-slate-200 flex flex-col border-r border-slate-800 shadow-xl">
    
    <!-- Sidebar Header / Logo -->
    <div class="h-16 flex items-center justify-between px-4 border-b border-slate-800">
        <a href="<?= base_url('modules/dashboard/index.php') ?>" class="flex items-center space-x-3 overflow-hidden">
            <div class="w-8 h-8 rounded-lg bg-blue-600 flex items-center justify-center font-extrabold text-white text-xs shadow-md flex-shrink-0">
                ST
            </div>
            <div class="sidebar-logo-text min-w-0">
                <h2 class="font-bold text-sm text-white tracking-tight leading-none truncate">OQC Inspection</h2>
                <span class="text-[10px] text-slate-400 font-normal leading-tight block truncate mt-0.5">PT. Surya Technology Industri</span>
            </div>
        </a>
        
        <!-- Close Button (Mobile) -->
        <button type="button" id="sidebar-close-mobile" class="md:hidden p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 focus:outline-none" title="Tutup Navigasi">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
        </button>

        <!-- Toggle Collapse Button (Desktop) -->
        <button type="button" id="sidebar-collapse-btn" class="hidden md:flex p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 focus:outline-none" title="Tutup / Buka Sidebar">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"></path>
            </svg>
        </button>
    </div>

    <!-- Navigation Links -->
    <nav class="flex-1 px-3 py-4 space-y-4 overflow-y-auto custom-scrollbar text-xs">
        
        <!-- Group 1: Data Referensi -->
           <!-- Group 2: Operasional -->
        <div>
            <div class="sidebar-group-label px-3 mb-1.5 text-[10px] font-bold text-slate-400 uppercase tracking-wider">
                Operasional
            </div>
   <a href="<?= base_url('modules/dashboard/index.php') ?>" 
               class="sidebar-nav-item flex items-center px-3 py-2 rounded-xl transition-all duration-150 mb-1 <?= nav_active('dashboard', $currentUri) ?>"
               title="Dashboard Performance OQC">
                <svg class="w-4 h-4 mr-2.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                </svg>
                <span class="sidebar-text truncate">Dashboard Laporan</span>
            </a>
            <!-- Scan Inspeksi -->
            <a href="<?= base_url('modules/inspection/index.php') ?>" 
               class="sidebar-nav-item flex items-center px-3 py-2 rounded-xl transition-all duration-150 mb-1 <?= nav_active('inspection', $currentUri) ?>"
               title="Scan & Inspeksi OQC">
                <svg class="w-4 h-4 mr-2.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"></path>
                </svg>
                <span class="sidebar-text truncate">Scan Inspeksi</span>
            </a>

            <!-- Monitoring Pekerjaan Supervisor -->
            <a href="<?= base_url('modules/monitoring/index.php') ?>" 
               class="sidebar-nav-item flex items-center px-3 py-2 rounded-xl transition-all duration-150 mb-1 <?= nav_active('monitoring', $currentUri) ?>"
               title="Monitoring Realisasi Planning Harian">
                <svg class="w-4 h-4 mr-2.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                <span class="sidebar-text truncate">Monitoring Pekerjaan</span>
            </a>

            <!-- Safety Stock (Monitoring Stock Realisasi) -->
            <a href="<?= base_url('modules/safety_stock/index.php') ?>" 
               class="sidebar-nav-item flex items-center px-3 py-2 rounded-xl transition-all duration-150 mb-1 <?= nav_active('safety_stock', $currentUri) ?>"
               title="Stock Safety Stock (Barang Realisasi Inspeksi)">
                <svg class="w-4 h-4 mr-2.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                </svg>
                <span class="sidebar-text truncate">Safety Stock</span>
            </a>

            <!-- Dashboard Laporan -->
         

            <!-- STI Survival (OQC Data) -->
            <!-- <a href="<?= base_url('modules/performance_report/index.php') ?>" 
               class="sidebar-nav-item flex items-center px-3 py-2 rounded-xl transition-all duration-150 <?= nav_active('performance_report', $currentUri) ?>"
               title="STI Survival & PPM Performance Report">
                <svg class="w-4 h-4 mr-2.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"></path>
                </svg>
                <span class="sidebar-text truncate">STI Survival (OQC Data)</span>
            </a> -->
        </div>
        <div>
            <div class="sidebar-group-label px-3 mb-1.5 text-[10px] font-bold text-slate-400 uppercase tracking-wider">
                Data Referensi
            </div>

            <!-- Upload DID (dulu Upload SPARQ) -->
            <a href="<?= base_url('modules/did/index.php') ?>" 
               class="sidebar-nav-item flex items-center px-3 py-2 rounded-xl transition-all duration-150 mb-1 <?= nav_active('did', $currentUri) ?>"
               title="Upload DID (Status Cek Dimensi)">
                <svg class="w-4 h-4 mr-2.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                </svg>
                <span class="sidebar-text truncate">Upload DID</span>
            </a>

            <!-- Planning Inspeksi (Kanban & Safety Stock) -->
            <a href="<?= base_url('modules/kanban/index.php') ?>" 
               class="sidebar-nav-item flex items-center px-3 py-2 rounded-xl transition-all duration-150 <?= nav_active('kanban', $currentUri) ?>"
               title="Planning Inspeksi (Kanban & Safety Stock)">
                <svg class="w-4 h-4 mr-2.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                </svg>
                <span class="sidebar-text truncate">Planning Inspeksi</span>
            </a>
        </div>

      


        <!-- Group 3: Master Data (Tahap 2) -->
        <div>
            <div class="sidebar-group-label px-3 mb-1.5 text-[10px] font-bold text-slate-400 uppercase tracking-wider">
                Master Data
            </div>

            <!-- Master Data Part -->
            <a href="<?= base_url('modules/master_parts/index.php') ?>" 
               class="sidebar-nav-item flex items-center px-3 py-2 rounded-xl transition-all duration-150 mb-1 <?= nav_active('master_parts', $currentUri) ?>"
               title="Master Data Part (FR-4)">
                <svg class="w-4 h-4 mr-2.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                </svg>
                <span class="sidebar-text truncate">Master Data Part</span>
            </a>

            <!-- Master Data Model -->
            <a href="<?= base_url('modules/master_models/index.php') ?>" 
               class="sidebar-nav-item flex items-center px-3 py-2 rounded-xl transition-all duration-150 mb-1 <?= nav_active('master_models', $currentUri) ?>"
               title="Master Data Model Produk">
                <svg class="w-4 h-4 mr-2.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                </svg>
                <span class="sidebar-text truncate">Master Data Model</span>
            </a>

            <!-- Master Data Customer -->
            <a href="<?= base_url('modules/master_customers/index.php') ?>" 
               class="sidebar-nav-item flex items-center px-3 py-2 rounded-xl transition-all duration-150 mb-1 <?= nav_active('master_customers', $currentUri) ?>"
               title="Master Data Customer / PT Tujuan">
                <svg class="w-4 h-4 mr-2.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5m0 0h4m-4 0a2 2 0 012-2h2a2 2 0 012 2m-6 0v-4a2 2 0 012-2h2a2 2 0 012 2v4"></path>
                </svg>
                <span class="sidebar-text truncate">Master Data Customer</span>
            </a>

            <!-- Master Data Drawing -->
            <a href="<?= base_url('modules/master_drawings/index.php') ?>" 
               class="sidebar-nav-item flex items-center px-3 py-2 rounded-xl transition-all duration-150 <?= nav_active('master_drawings', $currentUri) ?>"
               title="Master Data Drawing 2D/3D (FR-5)">
                <svg class="w-4 h-4 mr-2.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                <span class="sidebar-text truncate">Master Data Drawing</span>
            </a>
        </div>

        <!-- Group 4: Pengaturan -->
        <div>
            <div class="sidebar-group-label px-3 mb-1.5 text-[10px] font-bold text-slate-400 uppercase tracking-wider">
                Pengaturan
            </div>

            <!-- User Management -->
            <a href="<?= base_url('modules/users/index.php') ?>" 
               class="sidebar-nav-item flex items-center px-3 py-2 rounded-xl transition-all duration-150 <?= nav_active('users', $currentUri) ?>"
               title="User Management & Role Access (FR-8)">
                <svg class="w-4 h-4 mr-2.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                </svg>
                <span class="sidebar-text truncate">User Management</span>
            </a>
        </div>

    </nav>
</aside>
