<?php
$breadcrumbCategory = $breadcrumbCategory ?? 'OPERASIONAL';
$pageTitle = $pageTitle ?? 'Dashboard Performance OQC';
$pageSubtitle = $pageSubtitle ?? 'Rekap hasil inspeksi outgoing quality control — PT. Surya Technology Industri';
$cUser = current_user();
// Ambil display_name dari roles table berdasarkan role_id atau role_name
$roleLabel = strtoupper($cUser['role'] ?? 'user'); // default fallback
try {
    $pdoNavRole = getDB();
    if ($pdoNavRole) {
        if (!empty($cUser['role_id'])) {
            $stmtNavRole = $pdoNavRole->prepare("SELECT display_name FROM roles WHERE id = :id LIMIT 1");
            $stmtNavRole->execute([':id' => (int)$cUser['role_id']]);
        } else {
            $stmtNavRole = $pdoNavRole->prepare("SELECT display_name FROM roles WHERE role_name = :rn LIMIT 1");
            $stmtNavRole->execute([':rn' => $cUser['role']]);
        }
        $navRoleRow = $stmtNavRole->fetch(PDO::FETCH_ASSOC);
        if ($navRoleRow) {
            $roleLabel = strtoupper($navRoleRow['display_name']);
        }
    }
} catch (Exception $eNavRole) {}
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

<?php
$pendingDidReqCount = 0;
if (in_array($cUser['role'], ['admin', 'supervisor', 'chief', 'manager', 'superadmin'], true)) {
    try {
        $pdoNav = getDB();
        if ($pdoNav) {
            $stmtNavCount = $pdoNav->query("SELECT COUNT(*) FROM did_approval_requests WHERE status = 'pending'");
            $pendingDidReqCount = (int)$stmtNavCount->fetchColumn();
        }
    } catch (Exception $eNav) {}
}
?>
    <!-- Right: User Avatar Pill & Logout Button -->
    <div class="flex items-center space-x-3">
        
        <?php if (in_array($cUser['role'], ['admin', 'supervisor', 'chief', 'manager', 'superadmin'], true)): ?>
        <!-- DID Approval Notification Bell -->
        <a href="<?= base_url('modules/did_requests/index.php') ?>" style="position: relative; display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 10px;" class="text-slate-700 hover:text-blue-600 hover:bg-slate-100 transition-all cursor-pointer flex-shrink-0" title="Permintaan Approval DID (Pending: <?= $pendingDidReqCount ?>)">
            <svg style="width: 20px; height: 20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
            </svg>
            <?php if ($pendingDidReqCount > 0): ?>
                <span style="position: absolute; top: 2px; right: 2px; background-color: #e11d48; color: #ffffff; font-size: 9.5px; font-weight: 800; border-radius: 9999px; min-width: 17px; height: 17px; line-height: 1; display: flex; align-items: center; justify-content: center; padding: 0 4px; border: 2px solid #ffffff; box-shadow: 0 1px 2px rgba(0,0,0,0.1); pointer-events: none;" class="animate-pulse">
                    <?= $pendingDidReqCount > 99 ? '99+' : $pendingDidReqCount ?>
                </span>
            <?php endif; ?>
        </a>
        <?php endif; ?>

        <?php if ($cUser['role'] === 'qc_inspector'): ?>
        <!-- Device Line Indicator Pill (Inspector Only) -->
        <button id="btn-navbar-device-line" type="button" onclick="promptDeviceLine(true)" class="flex items-center space-x-1.5 bg-indigo-50 hover:bg-indigo-100 border border-indigo-200 text-indigo-800 rounded-full px-3 py-1 text-xs font-bold transition-all shadow-2xs cursor-pointer" title="Klik untuk mengubah Line kerja perangkat ini">
            <svg class="w-3.5 h-3.5 text-indigo-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
            </svg>
            <span class="hidden sm:inline text-[11px] text-indigo-600 font-semibold">Line:</span>
            <span id="navbar-device-line-name" class="text-[11px] font-extrabold text-indigo-900">-</span>
            <span class="text-[9px] text-indigo-500 font-normal underline ml-0.5">(ganti)</span>
        </button>
        <?php endif; ?>

        <!-- User Badge Pill -->
        <div class="flex items-center space-x-2 bg-slate-50 border border-slate-200/80 rounded-full px-3 py-1 shadow-2xs select-none">
            <div class="w-6 h-6 rounded-full bg-blue-600 text-white font-extrabold flex items-center justify-center text-[10px] shadow-xs flex-shrink-0">
                <?= htmlspecialchars($initials) ?>
            </div>
            <div class="text-left pr-1">
                <div class="text-[11px] font-bold text-slate-800 leading-tight truncate max-w-[130px] md:max-w-[180px]"><?= htmlspecialchars($cUser['name']) ?></div>
                <div class="text-[9px] font-extrabold text-blue-600 leading-none uppercase"><?= htmlspecialchars($roleLabel) ?></div>
            </div>
        </div>

        <!-- Logout Button with Confirmation Modal -->
        <button type="button" onclick="confirmLogout('<?= base_url('logout.php') ?>')" class="p-1.5 text-rose-600 hover:bg-rose-50 border border-rose-200 rounded-xl transition-all duration-150 flex items-center gap-1.5 text-xs font-bold px-2.5 shadow-2xs cursor-pointer" title="Keluar / Logout dari Sistem">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
            </svg>
            <span class="hidden md:inline">Logout</span>
        </button>

    </div>
</header>

<script>
function confirmLogout(logoutUrl) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Konfirmasi Logout',
            text: 'Apakah Anda yakin ingin keluar dari sistem OQC?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#e11d48',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Ya, Keluar',
            cancelButtonText: 'Batal',
            reverseButtons: true
        }).then(function(result) {
            if (result.isConfirmed) {
                window.location.href = logoutUrl;
            }
        });
    } else {
        if (confirm('Apakah Anda yakin ingin keluar dari sistem OQC?')) {
            window.location.href = logoutUrl;
        }
    }
}
</script>
