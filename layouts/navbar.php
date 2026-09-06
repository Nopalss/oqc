<?php
$breadcrumbCategory = $breadcrumbCategory ?? 'OPERASIONAL';
$pageTitle = $pageTitle ?? 'Dashboard Performance OQC';
$pageSubtitle = $pageSubtitle ?? 'Rekap hasil inspeksi outgoing quality control — PT. Surya Technology Industri';
$cUser = current_user();
$roleLabel = strtoupper($cUser['role'] === 'admin' ? 'Admin QC' : ($cUser['role'] === 'qc_inspector' ? 'QC Inspector' : 'Supervisor'));
$initials = strtoupper(substr($cUser['name'], 0, 2));
?>
<!-- Top Navbar Header (Solid Opaque White Background) -->
<header class="sticky top-0 z-30 bg-white border-b border-slate-200/80 px-4 md:px-6 py-2.5 flex items-center justify-between shadow-xs" style="background-color: #ffffff !important;">
    
    <!-- Left: Breadcrumb & Title -->
    <div class="flex items-center space-x-3 min-w-0">
        <!-- Sidebar Toggle Icon Button -->
        <button id="navbar-sidebar-toggle" type="button" class="p-1.5 rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-800 focus:outline-none cursor-pointer flex-shrink-0" title="Buka / Tutup Navigasi">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
            </svg>
        </button>

        <div class="min-w-0">
            <div class="flex items-center space-x-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-400 truncate">
                <span class="truncate"><?= htmlspecialchars($breadcrumbCategory) ?></span>
                <span>/</span>
                <span class="text-blue-600 font-extrabold truncate"><?= htmlspecialchars($pageTitle) ?></span>
            </div>
            <h1 class="text-sm md:text-base font-extrabold text-slate-800 tracking-tight leading-tight truncate"><?= htmlspecialchars($pageTitle) ?></h1>
        </div>
    </div>

    <!-- Right: User Avatar Pill & Logout Button -->
    <div class="flex items-center space-x-3">
        
        <!-- User Badge Pill -->
        <div class="flex items-center space-x-2 bg-slate-50 border border-slate-200/80 rounded-full px-3 py-1 shadow-2xs">
            <div class="w-6 h-6 rounded-full bg-blue-600 text-white font-extrabold flex items-center justify-center text-[10px] shadow-xs">
                <?= htmlspecialchars($initials) ?>
            </div>
            <div class="text-left hidden sm:block pr-1">
                <div class="text-[11px] font-bold text-slate-800 leading-tight"><?= htmlspecialchars($cUser['name']) ?></div>
                <div class="text-[9px] font-extrabold text-blue-600 leading-none uppercase"><?= htmlspecialchars($roleLabel) ?></div>
            </div>
        </div>

        <!-- Logout Button -->
        <a href="<?= base_url('logout.php') ?>" class="p-1.5 text-rose-600 hover:bg-rose-50 border border-rose-200 rounded-xl transition-all duration-150 flex items-center gap-1.5 text-xs font-bold px-2.5 shadow-2xs" title="Keluar / Logout dari Sistem">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
            </svg>
            <span class="hidden md:inline">Logout</span>
        </a>

    </div>
</header>
