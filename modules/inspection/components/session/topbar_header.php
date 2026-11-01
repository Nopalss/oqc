    <!-- Top Bar Navigation Header -->
    <header style="flex-shrink: 0; min-height: 52px;" class="bg-white border-b border-slate-200/80 px-4 py-2 flex items-center justify-between z-20 shadow-xs">
        <div class="flex items-center space-x-3 min-w-0">
            <button type="button" onclick="handleWorkbenchHeaderBack(event)" id="btn-header-back" class="btn-secondary py-1.5 px-3.5 text-xs font-bold flex items-center flex-shrink-0 text-slate-700 hover:bg-slate-100 shadow-2xs" title="Kembali ke Riwayat Inspeksi">
                &larr; Kembali
            </button>

            <div class="min-w-0">
                <div class="flex items-center space-x-2 text-[10px] font-bold text-slate-400 uppercase tracking-wider">
                    <span>OPERASIONAL</span>
                    <span>/</span>
                    <span class="text-blue-600">SCAN INSPEKSI</span>
                </div>
                <h1 class="text-sm font-extrabold text-slate-900 leading-tight truncate flex items-center" id="header-session-title">
                    <span>Inspeksi Barang</span>
                    <span id="header-reinspection-wrapper" class="inline-flex items-center">
                    <?php if ($session && !empty($session['is_reinspection'])): ?>
                        <?php
                        $rTypeLabelsPHP = [
                            'rescan_restart'   => 'Scan Ulang (Awal)',
                            'rescan_continue'  => 'Scan Ulang (Lanjut)',
                            'rescan_same_lot'  => 'Scan Ulang',
                            'replace_ng_only'  => 'Ganti Lot NG',
                            'replace_all_lots' => 'Ganti Semua Lot'
                        ];
                        $rTypeLblPHP = $rTypeLabelsPHP[$session['reinspection_type'] ?? ''] ?? 'Re-Inspeksi';
                        $roundNumPHP = $session['reinspection_round'] ?? 1;
                        ?>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[11px] font-extrabold bg-amber-100 text-amber-800 border border-amber-300 shadow-2xs ml-2 flex-shrink-0">
                            ⚡ RE-INSPEKSI #<?= htmlspecialchars($roundNumPHP) ?> (<?= htmlspecialchars($rTypeLblPHP) ?>)
                        </span>
                        <?php if (!empty($session['parent_session_id'])): ?>
                        <a href="<?= base_url('modules/inspection/session.php?id=' . $session['parent_session_id']) ?>" class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-semibold bg-slate-100 text-slate-600 border border-slate-300 ml-2 flex-shrink-0 hover:bg-slate-200 transition-colors">
                            ← Sesi Original #<?= htmlspecialchars($session['parent_session_id']) ?>
                        </a>
                        <?php endif; ?>
                    <?php endif; ?>
                    </span>
                </h1>
            </div>
        </div>

        <!-- Top Right Inspector Badge & Controls -->
        <div class="flex items-center space-x-2.5 flex-shrink-0">
            
            <button type="button" onclick="toggleNativeFullscreen()" class="btn-secondary py-1.5 px-3 text-xs font-bold flex items-center shadow-xs text-slate-700 hover:bg-slate-100" title="Aktifkan Mode Full Screen Layar Penuh (Sembunyikan Tab & Address Bar Chrome)">
                <svg class="w-3.5 h-3.5 mr-1.5 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 4l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"></path>
                </svg>
                <span>Full Screen <kbd class="hidden sm:inline-block ml-1 px-1 py-0.5 bg-slate-200 text-[9px] rounded text-slate-700 font-mono">F11</kbd></span>
            </button>

            <?php if ($session): ?>
                <button type="button" onclick="openPrevHistoryModal()" class="btn-secondary py-1.5 px-3 text-xs font-bold flex items-center shadow-xs text-slate-700 hover:bg-slate-100" title="Lihat Riwayat Sesi Inspeksi Terdahulu untuk Part Code Ini">
                    <svg class="w-3.5 h-3.5 mr-1.5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <span>Riwayat Inspeksi (<?= count($previousSessions) ?>)</span>
                </button>
                
                <button type="button" id="btn-top-substitution-log" onclick="openSubstitutionLogModal()" class="hidden btn-secondary py-1.5 px-3 text-xs font-bold flex items-center shadow-xs text-emerald-800 bg-emerald-50 hover:bg-emerald-100 border border-emerald-300 transition-all cursor-pointer" title="Lihat Rincian Lineage Penggantian Lot & Ref No">
                    <svg class="w-3.5 h-3.5 mr-1.5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                    </svg>
                    <span>Substitusi Lot (<span id="top-subst-log-count">0</span>)</span>
                </button>
            <?php endif; ?>

            <button type="button" onclick="openScanModal()" class="btn-primary py-1.5 px-3 text-xs font-bold flex items-center shadow-xs">
                <svg class="w-3.5 h-3.5 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                </svg>
                <span>Scan Lot Berikutnya <kbd class="hidden sm:inline-block ml-1 px-1 py-0.5 bg-blue-700 text-[9px] rounded">F2</kbd></span>
            </button>

            <!-- Device Line Indicator in Session Header -->
            <button type="button" onclick="promptDeviceLine(true)" class="flex items-center space-x-1.5 bg-indigo-50 hover:bg-indigo-100 border border-indigo-200 text-indigo-800 rounded-full px-2.5 py-1 text-xs font-bold transition-all shadow-2xs cursor-pointer" title="Klik untuk mengubah Line kerja perangkat ini">
                <svg class="w-3.5 h-3.5 text-indigo-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                </svg>
                <span class="text-[11px] font-extrabold text-indigo-900" id="header-device-line-name">-</span>
                <span class="text-[9px] text-indigo-500 font-normal underline">(ganti)</span>
            </button>

            <?php
            $badgeUser = $currentUser;
            $badgeRole = $badgeUser['role'] ?? 'guest';
            // Lookup display_name dari roles table (sama seperti navbar.php)
            $headerRoleLabel = strtoupper(str_replace('_', ' ', $badgeRole)); // default fallback
            try {
                $pdoSessRole = getDB();
                if ($pdoSessRole) {
                    if (!empty($badgeUser['role_id'])) {
                        $stmtSessRole = $pdoSessRole->prepare("SELECT display_name FROM roles WHERE id = :id LIMIT 1");
                        $stmtSessRole->execute([':id' => (int)$badgeUser['role_id']]);
                    } else {
                        $stmtSessRole = $pdoSessRole->prepare("SELECT display_name FROM roles WHERE role_name = :rn LIMIT 1");
                        $stmtSessRole->execute([':rn' => $badgeRole]);
                    }
                    $sessRoleRow = $stmtSessRole->fetch(PDO::FETCH_ASSOC);
                    if ($sessRoleRow) {
                        $headerRoleLabel = strtoupper($sessRoleRow['display_name']);
                    }
                }
            } catch (Exception $eSessRole) {}
            $headerUserName = $badgeUser['name'] ?? 'QC Inspector';
            $headerInitials = '';
            $hParts = preg_split('/\s+/', trim($headerUserName));
            if (count($hParts) >= 2 && !empty($hParts[0]) && !empty($hParts[1])) {
                $headerInitials = strtoupper(substr($hParts[0], 0, 1) . substr($hParts[1], 0, 1));
            } else {
                $headerInitials = strtoupper(substr($headerUserName, 0, 2));
            }
            ?>
            <!-- User Badge Pill -->
            <div class="flex items-center space-x-2 bg-slate-50 border border-slate-200/80 rounded-full px-3 py-1 shadow-2xs select-none">
                <div class="w-6 h-6 rounded-full bg-blue-600 text-white font-extrabold flex items-center justify-center text-[10px] shadow-xs flex-shrink-0">
                    <?= htmlspecialchars($headerInitials) ?>
                </div>
                <div class="text-left pr-1">
                    <div class="text-[11px] font-bold text-slate-800 leading-tight truncate max-w-[130px] md:max-w-[180px]"><?= htmlspecialchars($headerUserName) ?></div>
                    <div class="text-[9px] font-extrabold text-blue-600 leading-none uppercase"><?= htmlspecialchars($headerRoleLabel) ?></div>
                </div>
            </div>

        </div>
    </header>

    <!-- Main Workspace Area: 2 Main Columns -->
    <div style="flex: 1; min-height: 0; overflow: hidden; display: flex; padding: 12px; gap: 12px;">
        
