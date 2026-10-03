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
<aside id="sidebar" class="bg-slate-900 text-slate-200 flex flex-col border-r border-slate-800 shadow-xl h-screen max-h-screen overflow-hidden" style="height:100vh;max-height:100vh;overflow:hidden;">
    
    <!-- Sidebar Header / Logo -->
    <div class="h-16 flex items-center justify-between px-4 border-b border-slate-800 flex-shrink-0" style="flex-shrink:0;">
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

    <!-- Navigation Links (Scrollable Container) -->
    <nav class="flex-1 min-h-0 px-3 py-4 space-y-4 overflow-y-auto overflow-x-hidden text-xs" style="flex:1 1 0%;min-height:0;overflow-y:auto;overflow-x:hidden;-webkit-overflow-scrolling:touch;">
        
        <!-- Group 1: Operasional -->
        <?php if (can_access('dashboard') || can_access('defect_analysis') || can_access('inspection') || can_access('safety_stock') || can_access('daily_report')): ?>
        <div>
            <div class="sidebar-group-label px-3 mb-1.5 text-[10px] font-bold text-slate-400 uppercase tracking-wider">
                Operasional
            </div>
            <?php if (can_access('dashboard')): ?>
            <a href="<?= base_url('modules/dashboard/index.php') ?>" 
               class="sidebar-nav-item flex items-center px-3 py-2 rounded-xl transition-all duration-150 mb-1 <?= nav_active('dashboard', $currentUri) ?>"
               title="Dashboard Performance OQC">
                <svg class="w-4 h-4 mr-2.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                </svg>
                <span class="sidebar-text truncate">Dashboard Laporan</span>
            </a>
            <?php endif; ?>

            <?php if (can_access('defect_analysis')): ?>
            <!-- Analisis Defect -->
            <a href="<?= base_url('modules/defect_analysis/index.php') ?>" 
               class="sidebar-nav-item flex items-center px-3 py-2 rounded-xl transition-all duration-150 mb-1 <?= nav_active('defect_analysis', $currentUri) ?>"
               title="Analisis & Leaderboard Defect">
                <svg class="w-4 h-4 mr-2.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 8v8m-4-5v5m-4-2v2m-2 4h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                </svg>
                <span class="sidebar-text truncate">Analisis Defect</span>
            </a>
            <?php endif; ?>

            <?php if (can_access('inspection')): ?>
            <!-- Scan Inspeksi -->
            <a href="<?= base_url('modules/inspection/index.php') ?>" 
               class="sidebar-nav-item flex items-center px-3 py-2 rounded-xl transition-all duration-150 mb-1 <?= nav_active('inspection', $currentUri) ?>"
               title="Scan & Inspeksi OQC">
                <svg class="w-4 h-4 mr-2.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"></path>
                </svg>
                <span class="sidebar-text truncate">Scan Inspeksi</span>
            </a>
            <?php endif; ?>

            <?php if (can_access('safety_stock')): ?>
            <!-- Safety Stock (Monitoring Stock Realisasi) -->
            <a href="<?= base_url('modules/safety_stock/index.php') ?>" 
               class="sidebar-nav-item flex items-center px-3 py-2 rounded-xl transition-all duration-150 mb-1 <?= nav_active('safety_stock', $currentUri) ?>"
               title="Stock Safety Stock (Barang Realisasi Inspeksi)">
                <svg class="w-4 h-4 mr-2.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                </svg>
                <span class="sidebar-text truncate">Safety Stock</span>
            </a>
            <?php endif; ?>

            <?php if (can_access('daily_report')): ?>
            <!-- Laporan Inspeksi Harian -->
            <a href="<?= base_url('modules/daily_report/index.php') ?>"
               class="sidebar-nav-item flex items-center px-3 py-2 rounded-xl transition-all duration-150 mb-1 <?= nav_active('daily_report', $currentUri) ?>"
               title="Laporan Inspeksi Harian per Label">
                <svg class="w-4 h-4 mr-2.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
                <span class="sidebar-text truncate">Laporan Harian</span>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Group 2: Data Referensi -->
        <?php if (can_access('did') || can_access('kanban')): ?>
        <div>
            <div class="sidebar-group-label px-3 mb-1.5 text-[10px] font-bold text-slate-400 uppercase tracking-wider">
                Data Referensi
            </div>

            <?php if (can_access('did')): ?>
            <!-- Upload DID -->
            <a href="<?= base_url('modules/did/index.php') ?>" 
               class="sidebar-nav-item flex items-center px-3 py-2 rounded-xl transition-all duration-150 mb-1 <?= nav_active('did', $currentUri) ?>"
               title="Upload DID (Status Cek Dimensi)">
                <svg class="w-4 h-4 mr-2.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                </svg>
                <span class="sidebar-text truncate">Upload DID</span>
            </a>
            <?php endif; ?>

            <?php if (can_access('kanban')): ?>
            <!-- Planning Inspeksi (Kanban & Safety Stock) -->
            <a href="<?= base_url('modules/kanban/index.php') ?>" 
               class="sidebar-nav-item flex items-center px-3 py-2 rounded-xl transition-all duration-150 <?= nav_active('kanban', $currentUri) ?>"
               title="Planning Inspeksi (Kanban & Safety Stock)">
                <svg class="w-4 h-4 mr-2.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                </svg>
                <span class="sidebar-text truncate">Planning Inspeksi</span>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Group 3: Master Data -->
        <?php if (can_access('master_parts') || can_access('master_models') || can_access('master_customers') || can_access('master_drawings') || can_access('master_defects')): ?>
        <div>
            <div class="sidebar-group-label px-3 mb-1.5 text-[10px] font-bold text-slate-400 uppercase tracking-wider">
                Master Data
            </div>

            <?php if (can_access('master_parts')): ?>
            <!-- Master Data Part -->
            <a href="<?= base_url('modules/master_parts/index.php') ?>" 
               class="sidebar-nav-item flex items-center px-3 py-2 rounded-xl transition-all duration-150 mb-1 <?= nav_active('master_parts', $currentUri) ?>"
               title="Master Data Part (FR-4)">
                <svg class="w-4 h-4 mr-2.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                </svg>
                <span class="sidebar-text truncate">Master Data Part</span>
            </a>
            <?php endif; ?>

            <?php if (can_access('master_models')): ?>
            <!-- Master Data Model -->
            <a href="<?= base_url('modules/master_models/index.php') ?>" 
               class="sidebar-nav-item flex items-center px-3 py-2 rounded-xl transition-all duration-150 mb-1 <?= nav_active('master_models', $currentUri) ?>"
               title="Master Data Model Produk">
                <svg class="w-4 h-4 mr-2.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                </svg>
                <span class="sidebar-text truncate">Master Data Model</span>
            </a>
            <?php endif; ?>

            <?php if (can_access('master_customers')): ?>
            <!-- Master Data Customer -->
            <a href="<?= base_url('modules/master_customers/index.php') ?>" 
               class="sidebar-nav-item flex items-center px-3 py-2 rounded-xl transition-all duration-150 mb-1 <?= nav_active('master_customers', $currentUri) ?>"
               title="Master Data Customer / PT Tujuan">
                <svg class="w-4 h-4 mr-2.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5m0 0h4m-4 0a2 2 0 012-2h2a2 2 0 012 2m-6 0v-4a2 2 0 012-2h2a2 2 0 012 2v4"></path>
                </svg>
                <span class="sidebar-text truncate">Master Data Customer</span>
            </a>
            <?php endif; ?>

            <?php if (can_access('master_drawings')): ?>
            <!-- Master Data Drawing -->
            <a href="<?= base_url('modules/master_drawings/index.php') ?>" 
               class="sidebar-nav-item flex items-center px-3 py-2 rounded-xl transition-all duration-150 mb-1 <?= nav_active('master_drawings', $currentUri) ?>"
               title="Master Data Drawing 2D/3D (FR-5)">
                <svg class="w-4 h-4 mr-2.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                <span class="sidebar-text truncate">Master Data Drawing</span>
            </a>
            <?php endif; ?>

            <?php if (can_access('master_defects')): ?>
            <!-- Master Jenis Defect -->
            <a href="<?= base_url('modules/master_defects/index.php') ?>" 
               class="sidebar-nav-item flex items-center px-3 py-2 rounded-xl transition-all duration-150 <?= nav_active('master_defects', $currentUri) ?>"
               title="Master Data Jenis Defect / Cacat Inspeksi">
                <svg class="w-4 h-4 mr-2.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                </svg>
                <span class="sidebar-text truncate">Master Jenis Defect</span>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Group 4: Pengaturan -->
        <?php if (can_access('users') || can_access('roles')): ?>
        <div>
            <div class="sidebar-group-label px-3 mb-1.5 text-[10px] font-bold text-slate-400 uppercase tracking-wider">
                Pengaturan
            </div>

            <?php if (can_access('users')): ?>
            <!-- User Management -->
            <a href="<?= base_url('modules/users/index.php') ?>" 
               class="sidebar-nav-item flex items-center px-3 py-2 rounded-xl transition-all duration-150 mb-1 <?= nav_active('users', $currentUri) ?>"
               title="User Management & Role Access (FR-8)">
                <svg class="w-4 h-4 mr-2.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                </svg>
                <span class="sidebar-text truncate">User Management</span>
            </a>
            <?php endif; ?>

            <?php if (can_access('roles')): ?>
            <!-- Role Management -->
            <a href="<?= base_url('modules/roles/index.php') ?>" 
               class="sidebar-nav-item flex items-center px-3 py-2 rounded-xl transition-all duration-150 <?= nav_active('roles', $currentUri) ?>"
               title="Manajemen Role & Hak Akses">
                <svg class="w-4 h-4 mr-2.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                </svg>
                <span class="sidebar-text truncate">Manajemen Role</span>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </nav>
</aside>
